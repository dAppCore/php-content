<?php

declare(strict_types=1);

namespace Core\Content\Controllers\Api;

use Core\Front\Controller;
use Core\Mod\Api\Concerns\HasApiResponses;
use Core\Mod\Api\Concerns\ResolvesWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Core\Content\Services\ContentSearchService;

/**
 * Content Search API Controller
 *
 * Provides full-text search endpoints for content items.
 * Supports both session and API key authentication.
 */
class ContentSearchController extends Controller
{
    use HasApiResponses;
    use ResolvesWorkspace;

    public function __construct(
        protected ContentSearchService $searchService
    ) {}

    /**
     * Search content items.
     *
     * GET /api/v1/content/search
     *
     * @queryParam q string required The search query (minimum 2 characters)
     * @queryParam type string Filter by content type (post, page)
     * @queryParam status string|array Filter by status (draft, publish, future, private, pending)
     * @queryParam category string Filter by category slug
     * @queryParam tag string Filter by tag slug
     * @queryParam content_type string Filter by content source type (native, hostuk, satellite, wordpress)
     * @queryParam date_from string Filter by creation date (from)
     * @queryParam date_to string Filter by creation date (to)
     * @queryParam per_page int Results per page (default 20, max 50)
     * @queryParam page int Page number
     */
    public function search(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace && ! $request->user()?->is_admin) {
            return $this->noWorkspaceResponse();
        }

        $validated = $request->validate([
            'q' => 'required|string|min:2|max:500',
            'type' => 'nullable|string|in:post,page',
            'status' => 'nullable',
            'category' => 'nullable|string|max:100',
            'tag' => 'nullable|string|max:100',
            'content_type' => 'nullable|string|in:native,hostuk,satellite,wordpress',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'per_page' => 'nullable|integer|min:1|max:50',
            'page' => 'nullable|integer|min:1',
        ]);

        // Normalise status to array if provided
        $status = $validated['status'] ?? null;
        if (is_string($status) && str_contains($status, ',')) {
            $status = array_map('trim', explode(',', $status));
        }

        $filters = [
            'workspace_id' => $workspace?->id,
            'type' => $validated['type'] ?? null,
            'status' => $status,
            'category' => $validated['category'] ?? null,
            'tag' => $validated['tag'] ?? null,
            'content_type' => $validated['content_type'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'per_page' => $validated['per_page'] ?? 20,
            'page' => $validated['page'] ?? 1,
        ];

        // Remove null filters
        $filters = array_filter($filters, fn ($v) => $v !== null);

        $results = $this->searchService->search($validated['q'], $filters);

        return response()->json(
            $this->searchService->formatForApi($results)
        );
    }

    /**
     * Get search suggestions for autocomplete.
     *
     * GET /api/v1/content/search/suggest
     *
     * @queryParam q string required The partial search query (minimum 2 characters)
     * @queryParam limit int Maximum suggestions to return (default 10, max 20)
     */
    public function suggest(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace) {
            return $this->noWorkspaceResponse();
        }

        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $suggestions = $this->searchService->suggest(
            $validated['q'],
            $workspace->id,
            $validated['limit'] ?? 10
        );

        return response()->json([
            'data' => $suggestions->all(),
            'meta' => [
                'query' => $validated['q'],
                'count' => $suggestions->count(),
            ],
        ]);
    }

    /**
     * Get search backend information.
     *
     * GET /api/v1/content/search/info
     *
     * Returns information about the current search backend and capabilities.
     */
    public function info(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace && ! $request->user()?->is_admin) {
            return $this->noWorkspaceResponse();
        }

        return response()->json([
            'data' => [
                'backend' => $this->searchService->getBackend(),
                'scout_available' => $this->searchService->isScoutAvailable(),
                'meilisearch_available' => $this->searchService->isMeilisearchAvailable(),
                'min_query_length' => 2,
                'max_per_page' => 50,
                'filterable_fields' => [
                    'type' => ['post', 'page'],
                    'status' => ['draft', 'publish', 'future', 'private', 'pending'],
                    'content_type' => ['native', 'hostuk', 'satellite', 'wordpress'],
                    'category' => 'string (slug)',
                    'tag' => 'string (slug)',
                    'date_from' => 'date (Y-m-d)',
                    'date_to' => 'date (Y-m-d)',
                ],
            ],
        ]);
    }

    /**
     * Trigger re-indexing of content (admin only).
     *
     * POST /api/v1/content/search/reindex
     *
     * Re-indexes all content items for the workspace.
     * Only available when using Scout backend.
     */
    public function reindex(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $request->user()?->is_admin && ! $workspace) {
            return $this->accessDeniedResponse();
        }

        if (! $this->searchService->isScoutAvailable()) {
            return response()->json([
                'error' => 'Scout is not available. Re-indexing is only supported with Scout backend.',
            ], 400);
        }

        $count = $this->searchService->reindex($workspace);

        return response()->json([
            'message' => "Re-indexed {$count} content items.",
            'count' => $count,
        ]);
    }
}
