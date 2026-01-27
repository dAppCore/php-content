<?php

declare(strict_types=1);

namespace Core\Mod\Content\Mcp\Handlers;

use Core\Front\Mcp\Contracts\McpToolHandler;
use Core\Front\Mcp\McpContext;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Support\Str;
use Core\Mod\Content\Models\ContentItem;

/**
 * MCP tool handler for listing content items.
 *
 * Lists content items with filtering by workspace, type, status, and search.
 */
class ContentListHandler implements McpToolHandler
{
    public static function schema(): array
    {
        return [
            'name' => 'content_list',
            'description' => 'List content items (blog posts and pages) for a workspace. Supports filtering by type, status, and search.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'workspace' => [
                        'type' => 'string',
                        'description' => 'Workspace slug or ID (required)',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['post', 'page'],
                        'description' => 'Filter by content type: post or page',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'publish', 'future', 'private', 'pending', 'scheduled', 'published'],
                        'description' => 'Filter by status. Use "published" or "scheduled" as aliases.',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search term to filter by title, content, or excerpt',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum items to return (default 20, max 100)',
                        'default' => 20,
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'Offset for pagination',
                        'default' => 0,
                    ],
                ],
                'required' => ['workspace'],
            ],
        ];
    }

    public function handle(array $args, McpContext $context): array
    {
        $workspace = $this->resolveWorkspace($args['workspace'] ?? null);

        if (! $workspace) {
            return ['error' => 'Workspace not found. Provide a valid workspace slug or ID.'];
        }

        $query = ContentItem::forWorkspace($workspace->id)
            ->native()
            ->with(['author', 'taxonomies']);

        // Filter by type
        if (! empty($args['type'])) {
            $query->where('type', $args['type']);
        }

        // Filter by status
        if (! empty($args['status'])) {
            $status = $args['status'];
            if ($status === 'published') {
                $query->published();
            } elseif ($status === 'scheduled') {
                $query->scheduled();
            } else {
                $query->where('status', $status);
            }
        }

        // Search
        if (! empty($args['search'])) {
            $search = $args['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content_html', 'like', "%{$search}%")
                    ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        // Pagination
        $limit = min($args['limit'] ?? 20, 100);
        $offset = $args['offset'] ?? 0;

        $total = $query->count();
        $items = $query->orderByDesc('updated_at')
            ->skip($offset)
            ->take($limit)
            ->get();

        $context->logToSession("Listed {$items->count()} content items for workspace {$workspace->slug}");

        return [
            'items' => $items->map(fn (ContentItem $item) => [
                'id' => $item->id,
                'slug' => $item->slug,
                'title' => $item->title,
                'type' => $item->type,
                'status' => $item->status,
                'excerpt' => Str::limit($item->excerpt, 200),
                'author' => $item->author?->name,
                'categories' => $item->categories->pluck('name')->all(),
                'tags' => $item->tags->pluck('name')->all(),
                'word_count' => str_word_count(strip_tags($item->content_html ?? '')),
                'publish_at' => $item->publish_at?->toIso8601String(),
                'created_at' => $item->created_at->toIso8601String(),
                'updated_at' => $item->updated_at->toIso8601String(),
            ])->all(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
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
