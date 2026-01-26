<?php

declare(strict_types=1);

namespace Core\Content\Mcp\Handlers;

use Core\Front\Mcp\Contracts\McpToolHandler;
use Core\Front\Mcp\McpContext;
use Core\Mod\Tenant\Models\Workspace;
use Core\Content\Models\ContentItem;

/**
 * MCP tool handler for reading content items.
 *
 * Retrieves full content of a single item by ID or slug.
 * Supports JSON and markdown output formats.
 */
class ContentReadHandler implements McpToolHandler
{
    public static function schema(): array
    {
        return [
            'name' => 'content_read',
            'description' => 'Read full content of a blog post or page by ID or slug. Returns content with metadata, categories, tags, and revision history.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'workspace' => [
                        'type' => 'string',
                        'description' => 'Workspace slug or ID (required)',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'Content slug, ID, or WordPress ID',
                    ],
                    'format' => [
                        'type' => 'string',
                        'enum' => ['json', 'markdown'],
                        'description' => 'Output format: json (default) or markdown with frontmatter',
                        'default' => 'json',
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

        // Find by ID, slug, or wp_id
        if (is_numeric($identifier)) {
            $item = $query->where('id', $identifier)
                ->orWhere('wp_id', $identifier)
                ->first();
        } else {
            $item = $query->where('slug', $identifier)->first();
        }

        if (! $item) {
            return ['error' => 'Content not found'];
        }

        // Load relationships
        $item->load(['author', 'taxonomies', 'revisions' => fn ($q) => $q->latest()->limit(5)]);

        $context->logToSession("Read content item: {$item->title} (ID: {$item->id})");

        // Return as markdown with frontmatter for AI context
        $format = $args['format'] ?? 'json';

        if ($format === 'markdown') {
            return [
                'format' => 'markdown',
                'content' => $this->contentToMarkdown($item),
            ];
        }

        return [
            'id' => $item->id,
            'slug' => $item->slug,
            'title' => $item->title,
            'type' => $item->type,
            'status' => $item->status,
            'excerpt' => $item->excerpt,
            'content_html' => $item->content_html,
            'content_markdown' => $item->content_markdown,
            'author' => [
                'id' => $item->author?->id,
                'name' => $item->author?->name,
            ],
            'categories' => $item->categories->map(fn ($t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'name' => $t->name,
            ])->all(),
            'tags' => $item->tags->map(fn ($t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'name' => $t->name,
            ])->all(),
            'seo_meta' => $item->seo_meta,
            'publish_at' => $item->publish_at?->toIso8601String(),
            'revision_count' => $item->revision_count,
            'recent_revisions' => $item->revisions->map(fn ($r) => [
                'id' => $r->id,
                'revision_number' => $r->revision_number,
                'change_type' => $r->change_type,
                'created_at' => $r->created_at->toIso8601String(),
            ])->all(),
            'created_at' => $item->created_at->toIso8601String(),
            'updated_at' => $item->updated_at->toIso8601String(),
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

    protected function contentToMarkdown(ContentItem $item): string
    {
        $frontmatter = [
            'title' => $item->title,
            'slug' => $item->slug,
            'type' => $item->type,
            'status' => $item->status,
            'author' => $item->author?->name,
            'categories' => $item->categories->pluck('name')->all(),
            'tags' => $item->tags->pluck('name')->all(),
            'created_at' => $item->created_at->toIso8601String(),
            'updated_at' => $item->updated_at->toIso8601String(),
        ];

        if ($item->publish_at) {
            $frontmatter['publish_at'] = $item->publish_at->toIso8601String();
        }

        if ($item->seo_meta) {
            $frontmatter['seo'] = $item->seo_meta;
        }

        $yaml = "---\n";
        foreach ($frontmatter as $key => $value) {
            if (is_array($value)) {
                $yaml .= "{$key}: ".json_encode($value)."\n";
            } else {
                $yaml .= "{$key}: {$value}\n";
            }
        }
        $yaml .= "---\n\n";

        // Prefer markdown content, fall back to stripping HTML
        $content = $item->content_markdown ?? strip_tags($item->content_html ?? '');

        return $yaml.$content;
    }
}
