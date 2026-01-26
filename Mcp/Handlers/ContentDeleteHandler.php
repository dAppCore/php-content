<?php

declare(strict_types=1);

namespace Core\Content\Mcp\Handlers;

use Core\Front\Mcp\Contracts\McpToolHandler;
use Core\Front\Mcp\McpContext;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Core\Content\Models\ContentItem;
use Core\Content\Models\ContentRevision;

/**
 * MCP tool handler for deleting content items.
 *
 * Performs soft delete with revision history.
 */
class ContentDeleteHandler implements McpToolHandler
{
    public static function schema(): array
    {
        return [
            'name' => 'content_delete',
            'description' => 'Delete a blog post or page (soft delete). Content can be restored by admins.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'workspace' => [
                        'type' => 'string',
                        'description' => 'Workspace slug or ID (required)',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'Content slug or ID to delete (required)',
                    ],
                ],
                'required' => ['workspace', 'identifier'],
            ],
        ];
    }

    public function handle(array $args, McpContext $context): array
    {
        $workspace = $this->resolveWorkspace($args['workspace'] ?? null);

        if (! $workspace) {
            return ['error' => 'Workspace not found. Provide a valid workspace slug or ID.'];
        }

        $identifier = $args['identifier'] ?? null;

        if (! $identifier) {
            return ['error' => 'identifier (slug or ID) is required'];
        }

        $query = ContentItem::forWorkspace($workspace->id)->native();

        if (is_numeric($identifier)) {
            $item = $query->find($identifier);
        } else {
            $item = $query->where('slug', $identifier)->first();
        }

        if (! $item) {
            return ['error' => 'Content not found'];
        }

        // Store info before delete
        $deletedInfo = [
            'id' => $item->id,
            'slug' => $item->slug,
            'title' => $item->title,
        ];

        // Create final revision before delete
        $item->createRevision(Auth::user(), ContentRevision::CHANGE_EDIT, 'Deleted via MCP');

        // Soft delete
        $item->delete();

        $context->logToSession("Deleted content item: {$deletedInfo['title']} (ID: {$deletedInfo['id']})");

        return [
            'ok' => true,
            'deleted' => $deletedInfo,
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
