<?php

declare(strict_types=1);

namespace Core\Mod\Content\Mcp\Handlers;

use Carbon\Carbon;
use Core\Front\Mcp\Contracts\McpToolHandler;
use Core\Front\Mcp\McpContext;
use Core\Mod\Content\Models\ContentItem;
use Core\Mod\Content\Models\ContentRevision;
use Core\Mod\Content\Models\ContentTaxonomy;
use Core\Tenant\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * MCP tool handler for updating content items.
 *
 * Updates existing blog posts or pages. Creates revision history.
 */
class ContentUpdateHandler implements McpToolHandler
{
    public static function schema(): array
    {
        return [
            'name' => 'content_update',
            'description' => 'Update an existing blog post or page. Creates a revision in the history. Only provided fields are updated.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'workspace' => [
                        'type' => 'string',
                        'description' => 'Workspace slug or ID (required)',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'Content slug or ID to update (required)',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'New title',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'New URL slug',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'publish', 'future', 'private'],
                        'description' => 'New publication status',
                    ],
                    'excerpt' => [
                        'type' => 'string',
                        'description' => 'New excerpt/summary',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'New content body in markdown',
                    ],
                    'content_html' => [
                        'type' => 'string',
                        'description' => 'New content body in HTML (optional)',
                    ],
                    'categories' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Replace categories with this list',
                    ],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Replace tags with this list',
                    ],
                    'seo_meta' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'description' => 'New SEO metadata',
                    ],
                    'publish_at' => [
                        'type' => 'string',
                        'description' => 'New scheduled publish date (ISO format)',
                    ],
                    'change_summary' => [
                        'type' => 'string',
                        'description' => 'Summary of changes for revision history',
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

        // Build update data
        $updateData = [];

        if (array_key_exists('title', $args)) {
            $updateData['title'] = $args['title'];
        }

        if (array_key_exists('excerpt', $args)) {
            $updateData['excerpt'] = $args['excerpt'];
        }

        if (array_key_exists('content', $args) || array_key_exists('content_markdown', $args)) {
            $contentMarkdown = $args['content_markdown'] ?? $args['content'] ?? null;
            if ($contentMarkdown !== null) {
                $updateData['content_markdown'] = $contentMarkdown;
                $updateData['content_html'] = $args['content_html'] ?? Str::markdown($contentMarkdown);
            }
        }

        if (array_key_exists('content_html', $args) && ! array_key_exists('content', $args)) {
            $updateData['content_html'] = $args['content_html'];
        }

        if (array_key_exists('status', $args)) {
            $status = $args['status'];
            if (! in_array($status, ['draft', 'publish', 'future', 'private'])) {
                return ['error' => 'status must be draft, publish, future, or private'];
            }
            $updateData['status'] = $status;

            if ($status === 'future' && array_key_exists('publish_at', $args)) {
                $updateData['publish_at'] = Carbon::parse($args['publish_at']);
            }
        }

        if (array_key_exists('seo_meta', $args)) {
            $updateData['seo_meta'] = $args['seo_meta'];
        }

        if (array_key_exists('slug', $args)) {
            $newSlug = $args['slug'];
            if ($newSlug !== $item->slug) {
                // Check uniqueness
                if (ContentItem::forWorkspace($workspace->id)->where('slug', $newSlug)->where('id', '!=', $item->id)->exists()) {
                    return ['error' => 'Slug already exists'];
                }
                $updateData['slug'] = $newSlug;
            }
        }

        $updateData['last_edited_by'] = Auth::id();

        // Update item
        $item->update($updateData);

        // Handle categories
        if (array_key_exists('categories', $args)) {
            $categoryIds = $this->resolveOrCreateTaxonomies($workspace, $args['categories'] ?? [], 'category');
            $item->categories()->sync($categoryIds);
        }

        // Handle tags
        if (array_key_exists('tags', $args)) {
            $tagIds = $this->resolveOrCreateTaxonomies($workspace, $args['tags'] ?? [], 'tag');
            $item->tags()->sync($tagIds);
        }

        // Create revision
        $changeSummary = $args['change_summary'] ?? 'Updated via MCP';
        $item->createRevision(Auth::user(), ContentRevision::CHANGE_EDIT, $changeSummary);

        $item->refresh()->load(['author', 'taxonomies']);

        $context->logToSession("Updated content item: {$item->title} (ID: {$item->id})");

        return [
            'ok' => true,
            'item' => [
                'id' => $item->id,
                'slug' => $item->slug,
                'title' => $item->title,
                'type' => $item->type,
                'status' => $item->status,
                'revision_count' => $item->revision_count,
                'url' => $this->getContentUrl($workspace, $item),
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

    protected function resolveOrCreateTaxonomies(Workspace $workspace, array $items, string $type): array
    {
        $ids = [];

        foreach ($items as $item) {
            $taxonomy = ContentTaxonomy::where('workspace_id', $workspace->id)
                ->where('type', $type)
                ->where(function ($q) use ($item) {
                    $q->where('slug', $item)
                        ->orWhere('name', $item);
                })
                ->first();

            if (! $taxonomy) {
                // Create new taxonomy
                $taxonomy = ContentTaxonomy::create([
                    'workspace_id' => $workspace->id,
                    'type' => $type,
                    'slug' => Str::slug($item),
                    'name' => $item,
                ]);
            }

            $ids[] = $taxonomy->id;
        }

        return $ids;
    }

    protected function getContentUrl(Workspace $workspace, ContentItem $item): string
    {
        $domain = $workspace->domain ?? config('app.url');
        $path = $item->type === 'post' ? "/blog/{$item->slug}" : "/{$item->slug}";

        return "https://{$domain}{$path}";
    }
}
