<?php

declare(strict_types=1);

namespace Core\Mod\Content\Controllers\Api;

use Core\Api\Concerns\HasApiResponses;
use Core\Api\Concerns\ResolvesWorkspace;
use Core\Front\Controller;
use Core\Mod\Content\Models\ContentBrief;
use Core\Mod\Content\Resources\ContentBriefResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Content Brief API Controller
 *
 * CRUD operations for content briefs.
 * Supports both session and API key authentication.
 */
class ContentBriefController extends Controller
{
    use HasApiResponses;
    use ResolvesWorkspace;

    /**
     * List all briefs.
     *
     * GET /api/v1/content/briefs
     */
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $query = ContentBrief::query();

        // Scope to workspace if provided
        if ($workspace) {
            $query->where('workspace_id', $workspace->id);
        } elseif (! $request->user()?->is_admin) {
            // Non-admin users must have a workspace
            return $this->noWorkspaceResponse();
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by content type
        if ($request->has('content_type')) {
            $query->where('content_type', $request->input('content_type'));
        }

        // Filter by service
        if ($request->has('service')) {
            $query->where('service', $request->input('service'));
        }

        // Sorting
        $sortBy = in_array($request->input('sort_by'), ['created_at', 'updated_at', 'priority', 'title'], true)
            ? $request->input('sort_by')
            : 'created_at';
        $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $briefs = $query->orderBy($sortBy, $sortDir)
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => ContentBriefResource::collection($briefs->items()),
            'meta' => [
                'current_page' => $briefs->currentPage(),
                'last_page' => $briefs->lastPage(),
                'per_page' => $briefs->perPage(),
                'total' => $briefs->total(),
            ],
        ]);
    }

    /**
     * Create a new brief.
     *
     * POST /api/v1/content/briefs
     */
    public function store(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $validated = $request->validate([
            'content_type' => 'required|string|in:help_article,blog_post,landing_page,social_post',
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string',
            'category' => 'nullable|string|max:100',
            'difficulty' => 'nullable|string|in:beginner,intermediate,advanced',
            'target_word_count' => 'nullable|integer|min:100|max:10000',
            'service' => 'nullable|string',
            'priority' => 'nullable|integer|min:1|max:100',
            'prompt_variables' => 'nullable|array',
            'scheduled_for' => 'nullable|date',
        ]);

        $brief = ContentBrief::create([
            ...$validated,
            'workspace_id' => $workspace?->id,
            'target_word_count' => $validated['target_word_count'] ?? 1000,
            'priority' => $validated['priority'] ?? 50,
        ]);

        return $this->createdResponse(
            new ContentBriefResource($brief),
            'Content brief created successfully.'
        );
    }

    /**
     * Get a specific brief.
     *
     * GET /api/v1/content/briefs/{brief}
     */
    public function show(Request $request, ContentBrief $brief): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        // Check access
        if ($brief->workspace_id && $workspace?->id !== $brief->workspace_id) {
            return $this->accessDeniedResponse();
        }

        return response()->json([
            'data' => new ContentBriefResource($brief->load('aiUsage')),
        ]);
    }

    /**
     * Update a brief.
     *
     * PUT /api/v1/content/briefs/{brief}
     */
    public function update(Request $request, ContentBrief $brief): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        // Check access
        if ($brief->workspace_id && $workspace?->id !== $brief->workspace_id) {
            return $this->accessDeniedResponse();
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string',
            'category' => 'nullable|string|max:100',
            'difficulty' => 'nullable|string|in:beginner,intermediate,advanced',
            'target_word_count' => 'nullable|integer|min:100|max:10000',
            'service' => 'nullable|string',
            'priority' => 'nullable|integer|min:1|max:100',
            'prompt_variables' => 'nullable|array',
            'scheduled_for' => 'nullable|date',
            'status' => 'sometimes|string|in:pending,queued,review,published',
            'final_content' => 'nullable|string',
        ]);

        $brief->update($validated);

        return response()->json([
            'message' => 'Content brief updated successfully.',
            'data' => new ContentBriefResource($brief),
        ]);
    }

    /**
     * Delete a brief.
     *
     * DELETE /api/v1/content/briefs/{brief}
     */
    public function destroy(Request $request, ContentBrief $brief): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        // Check access
        if ($brief->workspace_id && $workspace?->id !== $brief->workspace_id) {
            return $this->accessDeniedResponse();
        }

        $brief->delete();

        return $this->successResponse('Content brief deleted successfully.');
    }

    /**
     * Create multiple briefs in bulk.
     *
     * POST /api/v1/content/briefs/bulk
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $validated = $request->validate([
            'briefs' => 'required|array|min:1|max:50',
            'briefs.*.content_type' => 'required|string|in:help_article,blog_post,landing_page,social_post',
            'briefs.*.title' => 'required|string|max:255',
            'briefs.*.slug' => 'nullable|string|max:255',
            'briefs.*.description' => 'nullable|string',
            'briefs.*.keywords' => 'nullable|array',
            'briefs.*.category' => 'nullable|string|max:100',
            'briefs.*.difficulty' => 'nullable|string|in:beginner,intermediate,advanced',
            'briefs.*.target_word_count' => 'nullable|integer|min:100|max:10000',
            'briefs.*.service' => 'nullable|string',
            'briefs.*.priority' => 'nullable|integer|min:1|max:100',
        ]);

        $created = [];
        foreach ($validated['briefs'] as $briefData) {
            $created[] = ContentBrief::create([
                ...$briefData,
                'workspace_id' => $workspace?->id,
                'target_word_count' => $briefData['target_word_count'] ?? 1000,
                'priority' => $briefData['priority'] ?? 50,
            ]);
        }

        return $this->createdResponse([
            'briefs' => ContentBriefResource::collection($created),
            'count' => count($created),
        ], count($created).' briefs created successfully.');
    }

    /**
     * Get the next brief ready for processing.
     *
     * GET /api/v1/content/briefs/next
     */
    public function next(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $query = ContentBrief::readyToProcess();

        if ($workspace) {
            $query->where('workspace_id', $workspace->id);
        }

        $brief = $query->first();

        if (! $brief) {
            return response()->json([
                'data' => null,
                'message' => 'No briefs ready for processing.',
            ]);
        }

        return response()->json([
            'data' => new ContentBriefResource($brief),
        ]);
    }
}
