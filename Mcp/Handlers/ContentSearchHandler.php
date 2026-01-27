<?php

declare(strict_types=1);

namespace Core\Mod\Content\Mcp\Handlers;

use Core\Front\Mcp\Contracts\McpToolHandler;
use Core\Front\Mcp\McpContext;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Support\Str;
use Core\Mod\Content\Services\ContentSearchService;

/**
 * MCP tool handler for searching content.
 *
 * Full-text search across content items with relevance scoring.
 * Uses ContentSearchService for consistent search behaviour across all interfaces.
 */
class ContentSearchHandler implements McpToolHandler
{
    public static function schema(): array
    {
        return [
            'name' => 'content_search',
            'description' => 'Search content items by keywords. Searches titles, body content, excerpts, and slugs. Returns results sorted by relevance.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'workspace' => [
                        'type' => 'string',
                        'description' => 'Workspace slug or ID (required)',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search query - keywords to search for in content (minimum 2 characters)',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['post', 'page'],
                        'description' => 'Limit search to specific content type',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'publish', 'future', 'private', 'pending'],
                        'description' => 'Limit search to specific status (default: all statuses)',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Filter by category slug',
                    ],
                    'tag' => [
                        'type' => 'string',
                        'description' => 'Filter by tag slug',
                    ],
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'Filter by creation date from (Y-m-d format)',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Filter by creation date to (Y-m-d format)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum results to return (default 20, max 50)',
                        'default' => 20,
                    ],
                ],
                'required' => ['workspace', 'query'],
            ],
        ];
    }

    public function handle(array $args, McpContext $context): array
    {
        $workspace = $this->resolveWorkspace($args['workspace'] ?? null);

        if (! $workspace) {
            return ['error' => 'Workspace not found. Provide a valid workspace slug or ID.'];
        }

        $query = trim($args['query'] ?? '');

        if (strlen($query) < 2) {
            return ['error' => 'Search query must be at least 2 characters'];
        }

        $searchService = app(ContentSearchService::class);

        // Build filters from args
        $filters = array_filter([
            'workspace_id' => $workspace->id,
            'type' => $args['type'] ?? null,
            'status' => $args['status'] ?? null,
            'category' => $args['category'] ?? null,
            'tag' => $args['tag'] ?? null,
            'date_from' => $args['date_from'] ?? null,
            'date_to' => $args['date_to'] ?? null,
            'per_page' => min($args['limit'] ?? 20, 50),
        ], fn ($v) => $v !== null);

        $results = $searchService->search($query, $filters);

        $context->logToSession(
            "Searched for '{$query}' in workspace {$workspace->slug}, found {$results->total()} results (backend: {$searchService->getBackend()})"
        );

        return [
            'query' => $query,
            'results' => $results->map(fn ($item) => [
                'id' => $item->id,
                'slug' => $item->slug,
                'title' => $item->title,
                'type' => $item->type,
                'status' => $item->status,
                'content_type' => $item->content_type?->value,
                'excerpt' => Str::limit($item->excerpt ?? strip_tags($item->content_html ?? $item->content_markdown ?? ''), 200),
                'author' => $item->author?->name,
                'categories' => $item->categories->pluck('name')->all(),
                'tags' => $item->tags->pluck('name')->all(),
                'relevance_score' => $item->getAttribute('relevance_score'),
                'updated_at' => $item->updated_at?->toIso8601String(),
            ])->all(),
            'total' => $results->total(),
            'backend' => $searchService->getBackend(),
        ];
    }

    protected function resolveWorkspace(?string $slug): ?Workspace
    {
        if (! $slug) {
            return null;
        }

        return Workspace::where('slug', $slug)
            ->orWhere('id', $slug)
            ->first();
    }
}
