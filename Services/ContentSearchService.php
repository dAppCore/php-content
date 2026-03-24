<?php

declare(strict_types=1);

namespace Core\Mod\Content\Services;

use Carbon\Carbon;
use Core\Mod\Content\Models\ContentItem;
use Core\Tenant\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Content Search Service
 *
 * Provides full-text search capabilities for content items with support for
 * multiple backends:
 * - Database (default): LIKE-based search with relevance scoring
 * - Scout Database: Laravel Scout with database driver
 * - Meilisearch: Laravel Scout with Meilisearch driver (optional)
 *
 * The service automatically uses the best available backend based on
 * configuration and installed packages.
 */
class ContentSearchService
{
    /**
     * Search backend constants.
     */
    public const BACKEND_DATABASE = 'database';

    public const BACKEND_SCOUT_DATABASE = 'scout_database';

    public const BACKEND_MEILISEARCH = 'meilisearch';

    /**
     * Minimum query length for search.
     */
    protected int $minQueryLength = 2;

    /**
     * Maximum results per page.
     */
    protected int $maxPerPage = 50;

    /**
     * Default results per page.
     */
    protected int $defaultPerPage = 20;

    /**
     * Get the current search backend.
     */
    public function getBackend(): string
    {
        $configured = config('content.search.backend', self::BACKEND_DATABASE);

        // Validate Meilisearch is available if configured
        if ($configured === self::BACKEND_MEILISEARCH) {
            if (! $this->isMeilisearchAvailable()) {
                return self::BACKEND_DATABASE;
            }
        }

        // Validate Scout is available if configured
        if ($configured === self::BACKEND_SCOUT_DATABASE) {
            if (! $this->isScoutAvailable()) {
                return self::BACKEND_DATABASE;
            }
        }

        return $configured;
    }

    /**
     * Check if Laravel Scout is available.
     */
    public function isScoutAvailable(): bool
    {
        return class_exists(\Laravel\Scout\Searchable::class);
    }

    /**
     * Check if Meilisearch is available and configured.
     */
    public function isMeilisearchAvailable(): bool
    {
        if (! class_exists(\Meilisearch\Client::class)) {
            return false;
        }

        $host = config('scout.meilisearch.host');

        return ! empty($host);
    }

    /**
     * Search content items.
     *
     * @param  string  $query  Search query
     * @param  array{
     *     workspace_id?: int,
     *     type?: string,
     *     status?: string|array,
     *     category?: string,
     *     tag?: string,
     *     content_type?: string,
     *     date_from?: string|Carbon,
     *     date_to?: string|Carbon,
     *     per_page?: int,
     *     page?: int,
     * }  $filters
     * @return LengthAwarePaginator<ContentItem>
     */
    public function search(string $query, array $filters = []): LengthAwarePaginator
    {
        $query = trim($query);
        $perPage = min($filters['per_page'] ?? $this->defaultPerPage, $this->maxPerPage);
        $page = max($filters['page'] ?? 1, 1);

        // For very short queries, use database search
        if (strlen($query) < $this->minQueryLength) {
            return $this->emptyPaginatedResult($perPage, $page);
        }

        $backend = $this->getBackend();

        return match ($backend) {
            self::BACKEND_MEILISEARCH,
            self::BACKEND_SCOUT_DATABASE => $this->searchWithScout($query, $filters, $perPage, $page),
            default => $this->searchWithDatabase($query, $filters, $perPage, $page),
        };
    }

    /**
     * Search using database LIKE queries with relevance scoring.
     *
     * @return LengthAwarePaginator<ContentItem>
     */
    protected function searchWithDatabase(string $query, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $baseQuery = $this->buildBaseQuery($filters);
        $searchTerms = $this->tokeniseQuery($query);

        // Build search conditions
        $baseQuery->where(function (Builder $q) use ($query, $searchTerms) {
            // Exact phrase match in title
            $q->where('title', 'like', "%{$query}%");

            // Individual term matches
            foreach ($searchTerms as $term) {
                if (strlen($term) >= $this->minQueryLength) {
                    $q->orWhere('title', 'like', "%{$term}%")
                        ->orWhere('excerpt', 'like', "%{$term}%")
                        ->orWhere('content_html', 'like', "%{$term}%")
                        ->orWhere('content_markdown', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%");
                }
            }
        });

        // Get all matching results for scoring
        $allResults = $baseQuery->get();

        // Calculate relevance scores and sort
        $scored = $this->scoreResults($allResults, $query, $searchTerms);

        // Manual pagination of scored results
        $total = $scored->count();
        $offset = ($page - 1) * $perPage;
        $items = $scored->slice($offset, $perPage)->values();

        // Convert to paginator
        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * Search using Laravel Scout.
     *
     * @return LengthAwarePaginator<ContentItem>
     */
    protected function searchWithScout(string $query, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        // Check if ContentItem uses Searchable trait
        if (! in_array(\Laravel\Scout\Searchable::class, class_uses_recursive(ContentItem::class))) {
            // Fall back to database search
            return $this->searchWithDatabase($query, $filters, $perPage, $page);
        }

        $searchBuilder = ContentItem::search($query);

        // Apply workspace filter
        if (isset($filters['workspace_id'])) {
            $searchBuilder->where('workspace_id', $filters['workspace_id']);
        }

        // Apply content type filter (native types)
        $searchBuilder->where('content_type', 'native');

        // Apply filters via query callback for Scout database driver
        $searchBuilder->query(function (Builder $builder) use ($filters) {
            $this->applyFilters($builder, $filters);
        });

        return $searchBuilder->paginate($perPage, 'page', $page);
    }

    /**
     * Get search suggestions based on partial query.
     *
     * @return Collection<int, array{title: string, slug: string, type: string}>
     */
    public function suggest(string $query, int $workspaceId, int $limit = 10): Collection
    {
        $query = trim($query);

        if (strlen($query) < $this->minQueryLength) {
            return collect();
        }

        return ContentItem::forWorkspace($workspaceId)
            ->native()
            ->where(function (Builder $q) use ($query) {
                $q->where('title', 'like', "{$query}%")
                    ->orWhere('title', 'like', "% {$query}%")
                    ->orWhere('slug', 'like', "{$query}%");
            })
            ->select(['id', 'title', 'slug', 'type', 'status'])
            ->orderByRaw('CASE WHEN title LIKE ? THEN 0 ELSE 1 END', ["{$query}%"])
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn (ContentItem $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'slug' => $item->slug,
                'type' => $item->type,
                'status' => $item->status,
            ]);
    }

    /**
     * Build the base query with workspace and content type scope.
     */
    protected function buildBaseQuery(array $filters): Builder
    {
        $query = ContentItem::query()->with(['author', 'taxonomies']);

        // Always scope to native content types
        $query->native();

        // Apply all filters
        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Apply filters to a query builder.
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        // Workspace filter
        if (isset($filters['workspace_id'])) {
            $query->forWorkspace($filters['workspace_id']);
        }

        // Content type (post/page)
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Status filter
        if (! empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('status', $filters['status']);
            } else {
                $query->where('status', $filters['status']);
            }
        }

        // Category filter
        if (! empty($filters['category'])) {
            $query->whereHas('categories', function (Builder $q) use ($filters) {
                $q->where('slug', $filters['category']);
            });
        }

        // Tag filter
        if (! empty($filters['tag'])) {
            $query->whereHas('tags', function (Builder $q) use ($filters) {
                $q->where('slug', $filters['tag']);
            });
        }

        // Content source type filter
        if (! empty($filters['content_type'])) {
            $query->where('content_type', $filters['content_type']);
        }

        // Date range filters
        if (! empty($filters['date_from'])) {
            $dateFrom = $filters['date_from'] instanceof Carbon
                ? $filters['date_from']
                : Carbon::parse($filters['date_from']);
            $query->where('created_at', '>=', $dateFrom->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $dateTo = $filters['date_to'] instanceof Carbon
                ? $filters['date_to']
                : Carbon::parse($filters['date_to']);
            $query->where('created_at', '<=', $dateTo->endOfDay());
        }
    }

    /**
     * Tokenise a search query into individual terms.
     *
     * @return array<string>
     */
    protected function tokeniseQuery(string $query): array
    {
        // Split on whitespace and filter empty/short terms
        return array_values(array_filter(
            preg_split('/\s+/', $query) ?: [],
            fn ($term) => strlen($term) >= $this->minQueryLength
        ));
    }

    /**
     * Calculate relevance scores for search results.
     *
     * @param  Collection<int, ContentItem>  $items
     * @param  array<string>  $searchTerms
     * @return Collection<int, ContentItem>
     */
    protected function scoreResults(Collection $items, string $query, array $searchTerms): Collection
    {
        $queryLower = strtolower($query);

        return $items
            ->map(function (ContentItem $item) use ($queryLower, $searchTerms) {
                $score = $this->calculateRelevanceScore($item, $queryLower, $searchTerms);
                $item->setAttribute('relevance_score', $score);

                return $item;
            })
            ->sortByDesc('relevance_score');
    }

    /**
     * Calculate relevance score for a single content item.
     */
    protected function calculateRelevanceScore(ContentItem $item, string $queryLower, array $searchTerms): int
    {
        $score = 0;

        $titleLower = strtolower($item->title ?? '');
        $slugLower = strtolower($item->slug ?? '');
        $excerptLower = strtolower($item->excerpt ?? '');
        $contentLower = strtolower(strip_tags($item->content_html ?? $item->content_markdown ?? ''));

        // Exact phrase matches (highest weight)
        if ($titleLower === $queryLower) {
            $score += 200; // Exact title match
        } elseif (str_starts_with($titleLower, $queryLower)) {
            $score += 150; // Title starts with query
        } elseif (str_contains($titleLower, $queryLower)) {
            $score += 100; // Title contains query
        }

        if (str_contains($slugLower, $queryLower)) {
            $score += 50; // Slug contains query
        }

        // Individual term matches
        foreach ($searchTerms as $term) {
            $termLower = strtolower($term);

            if (str_contains($titleLower, $termLower)) {
                $score += 30;
            }
            if (str_contains($slugLower, $termLower)) {
                $score += 20;
            }
            if (str_contains($excerptLower, $termLower)) {
                $score += 15;
            }
            if (str_contains($contentLower, $termLower)) {
                $score += 5;
            }
        }

        // Status boost (published content should rank higher)
        if ($item->status === 'publish') {
            $score += 10;
        }

        // Recency boost (content updated within 30 days)
        if ($item->updated_at && $item->updated_at->diffInDays(now()) < 30) {
            $score += 5;
        }

        return $score;
    }

    /**
     * Create an empty paginated result.
     *
     * @return LengthAwarePaginator<ContentItem>
     */
    protected function emptyPaginatedResult(int $perPage, int $page): LengthAwarePaginator
    {
        return new \Illuminate\Pagination\LengthAwarePaginator(
            collect(),
            0,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * Re-index all content items for Scout.
     *
     * Only applicable when using Scout backend.
     */
    public function reindex(?Workspace $workspace = null): int
    {
        if (! $this->isScoutAvailable()) {
            return 0;
        }

        if (! in_array(\Laravel\Scout\Searchable::class, class_uses_recursive(ContentItem::class))) {
            return 0;
        }

        $query = ContentItem::native();

        if ($workspace) {
            $query->forWorkspace($workspace->id);
        }

        $count = 0;
        $query->chunk(100, function ($items) use (&$count) {
            $items->searchable();
            $count += $items->count();
        });

        return $count;
    }

    /**
     * Format search results for API response.
     *
     * @param  LengthAwarePaginator<ContentItem>  $results
     */
    public function formatForApi(LengthAwarePaginator $results): array
    {
        return [
            'data' => $results->map(fn (ContentItem $item) => [
                'id' => $item->id,
                'slug' => $item->slug,
                'title' => $item->title,
                'type' => $item->type,
                'status' => $item->status,
                'content_type' => $item->content_type?->value,
                'excerpt' => Str::limit($item->excerpt ?? strip_tags($item->content_html ?? ''), 200),
                'author' => $item->author?->name,
                'categories' => $item->categories->pluck('name')->all(),
                'tags' => $item->tags->pluck('name')->all(),
                'relevance_score' => $item->getAttribute('relevance_score'),
                'created_at' => $item->created_at?->toIso8601String(),
                'updated_at' => $item->updated_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'backend' => $this->getBackend(),
            ],
        ];
    }
}
