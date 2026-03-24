<?php

declare(strict_types=1);

namespace Core\Mod\Content\Controllers\Api;

use Core\Api\Concerns\HasApiResponses;
use Core\Api\Concerns\ResolvesWorkspace;
use Core\Front\Controller;
use Core\Mod\Content\Models\ContentItem;
use Core\Mod\Content\Models\ContentRevision;
use Core\Mod\Content\Resources\ContentRevisionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Content Revision API Controller
 *
 * List and restore content revisions.
 */
class ContentRevisionController extends Controller
{
    use HasApiResponses;
    use ResolvesWorkspace;

    /**
     * List all revisions for a content item.
     *
     * GET /api/v1/content/items/{item}/revisions
     */
    public function index(Request $request, ContentItem $item): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        // Check user has access to this content item
        if (! $this->canAccessContentItem($item, $workspace, $request)) {
            return $this->accessDeniedResponse();
        }

        $query = $item->revisions();

        // Filter by change type
        if ($request->has('change_type')) {
            $query->where('change_type', $request->input('change_type'));
        }

        // Exclude autosaves by default (can be overridden)
        if (! $request->boolean('include_autosaves')) {
            $query->withoutAutosaves();
        }

        // Pagination
        $perPage = min((int) $request->input('per_page', 20), 100);
        $revisions = $query->with('user')->paginate($perPage);

        return response()->json([
            'data' => ContentRevisionResource::collection($revisions->items()),
            'meta' => [
                'current_page' => $revisions->currentPage(),
                'last_page' => $revisions->lastPage(),
                'per_page' => $revisions->perPage(),
                'total' => $revisions->total(),
            ],
        ]);
    }

    /**
     * Get a specific revision with diff summary.
     *
     * GET /api/v1/content/revisions/{revision}
     */
    public function show(Request $request, ContentRevision $revision): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        // Load the content item to check access
        $revision->load('contentItem', 'user');

        if (! $this->canAccessContentItem($revision->contentItem, $workspace, $request)) {
            return $this->accessDeniedResponse();
        }

        // Always include diff for show endpoint
        $request->merge(['include_diff' => true, 'include_content' => true]);

        // Get full diff data
        $diffData = $revision->getDiff();

        return response()->json([
            'data' => new ContentRevisionResource($revision),
            'diff' => $diffData,
        ]);
    }

    /**
     * Restore a content item to a specific revision.
     *
     * POST /api/v1/content/revisions/{revision}/restore
     */
    public function restore(Request $request, ContentRevision $revision): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        // Load the content item to check access
        $revision->load('contentItem');

        if (! $this->canAccessContentItem($revision->contentItem, $workspace, $request)) {
            return $this->accessDeniedResponse();
        }

        // Restore the content item to this revision's state
        $restoredItem = $revision->restoreToContentItem();

        // Get the new revision that was created during restore
        $newRevision = $restoredItem->latestRevision();

        return response()->json([
            'message' => "Content restored to revision #{$revision->revision_number}.",
            'data' => [
                'content_item_id' => $restoredItem->id,
                'restored_from_revision' => $revision->revision_number,
                'new_revision' => $newRevision ? new ContentRevisionResource($newRevision) : null,
            ],
        ]);
    }

    /**
     * Compare two revisions.
     *
     * GET /api/v1/content/revisions/{revision}/compare/{compareWith}
     */
    public function compare(Request $request, ContentRevision $revision, ContentRevision $compareWith): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        // Load content items for both revisions
        $revision->load('contentItem');
        $compareWith->load('contentItem');

        // Ensure both revisions belong to the same content item
        if ($revision->content_item_id !== $compareWith->content_item_id) {
            return response()->json([
                'error' => 'invalid_comparison',
                'message' => 'Cannot compare revisions from different content items.',
            ], 400);
        }

        if (! $this->canAccessContentItem($revision->contentItem, $workspace, $request)) {
            return $this->accessDeniedResponse();
        }

        // Get diff between the two specified revisions
        $diffData = $revision->getDiff($compareWith);

        return response()->json([
            'data' => [
                'from' => new ContentRevisionResource($compareWith),
                'to' => new ContentRevisionResource($revision),
            ],
            'diff' => $diffData,
        ]);
    }

    /**
     * Check if user can access a content item.
     */
    protected function canAccessContentItem(ContentItem $item, $workspace, Request $request): bool
    {
        // Admin users can access any content
        if ($request->user()?->is_admin) {
            return true;
        }

        // Check workspace ownership
        if ($item->workspace_id && $workspace?->id !== $item->workspace_id) {
            return false;
        }

        // No workspace on item and no workspace context = allow (system content)
        if (! $item->workspace_id && ! $workspace) {
            return true;
        }

        return true;
    }
}
