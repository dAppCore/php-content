<?php

declare(strict_types=1);

namespace Core\Mod\Content\Mcp\Handlers;

use Core\Front\Mcp\Contracts\McpToolHandler;
use Core\Front\Mcp\McpContext;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Content\Models\ContentTaxonomy;

/**
 * MCP tool handler for listing content taxonomies.
 *
 * Lists categories and tags for a workspace.
 */
class ContentTaxonomiesHandler implements McpToolHandler
{
    public static function schema(): array
    {
        return [
            'name' => 'content_taxonomies',
            'description' => 'List categories and tags available for content. Use this to see what categories/tags exist before creating content.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'workspace' => [
                        'type' => 'string',
                        'description' => 'Workspace slug or ID (required)',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['category', 'tag'],
                        'description' => 'Filter by taxonomy type (optional, returns both if not specified)',
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

        $type = $args['type'] ?? null;

        $query = ContentTaxonomy::where('workspace_id', $workspace->id);

        if ($type) {
            $query->where('type', $type);
        }

        $taxonomies = $query->orderBy('type')->orderBy('name')->get();

        $context->logToSession("Listed taxonomies for workspace {$workspace->slug}");

        return [
            'taxonomies' => $taxonomies->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'slug' => $t->slug,
                'name' => $t->name,
                'description' => $t->description,
            ])->all(),
            'total' => $taxonomies->count(),
            'counts' => [
                'categories' => $taxonomies->where('type', 'category')->count(),
                'tags' => $taxonomies->where('type', 'tag')->count(),
            ],
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
