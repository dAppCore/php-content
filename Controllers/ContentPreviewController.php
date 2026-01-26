<?php

declare(strict_types=1);

namespace Core\Content\Controllers;

use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Core\Content\Models\ContentItem;

/**
 * ContentPreviewController - Preview draft content before publishing.
 *
 * Provides time-limited shareable preview URLs for draft, scheduled,
 * and private content items.
 */
class ContentPreviewController extends Controller
{
    /**
     * Generate a preview link for a content item.
     *
     * Requires authentication and access to the content's workspace.
     */
    public function generateLink(Request $request, ContentItem $item): JsonResponse
    {
        // Verify user has access to this workspace
        $user = $request->user();
        if (! $user || ! $this->userCanAccessWorkspace($user, $item->workspace_id)) {
            return response()->json([
                'error' => 'Unauthorised access to this content item.',
            ], 403);
        }

        $hours = (int) $request->input('hours', 24);
        $hours = min(max($hours, 1), 168); // Between 1 hour and 7 days

        $token = $item->generatePreviewToken($hours);
        $previewUrl = route('content.preview', [
            'item' => $item->id,
            'token' => $token,
        ]);

        return response()->json([
            'preview_url' => $previewUrl,
            'expires_at' => $item->preview_expires_at->toIso8601String(),
            'expires_in' => $item->getPreviewTokenTimeRemaining(),
        ]);
    }

    /**
     * Revoke the preview token for a content item.
     */
    public function revokeLink(Request $request, ContentItem $item): JsonResponse
    {
        // Verify user has access to this workspace
        $user = $request->user();
        if (! $user || ! $this->userCanAccessWorkspace($user, $item->workspace_id)) {
            return response()->json([
                'error' => 'Unauthorised access to this content item.',
            ], 403);
        }

        $item->revokePreviewToken();

        return response()->json([
            'message' => 'Preview link revoked successfully.',
        ]);
    }

    /**
     * Check if a user can access a workspace.
     */
    protected function userCanAccessWorkspace($user, int $workspaceId): bool
    {
        // Check if user owns the workspace or is a member
        $workspace = Workspace::find($workspaceId);

        if (! $workspace) {
            return false;
        }

        // User owns workspace
        if ($workspace->user_id === $user->id) {
            return true;
        }

        // User is workspace member (if membership system exists)
        if (method_exists($user, 'workspaces')) {
            return $user->workspaces()->where('workspaces.id', $workspaceId)->exists();
        }

        // Fallback: check if user has any content in this workspace
        return ContentItem::where('workspace_id', $workspaceId)
            ->where(function ($query) use ($user) {
                $query->where('author_id', $user->id)
                    ->orWhere('last_edited_by', $user->id);
            })
            ->exists();
    }
}
