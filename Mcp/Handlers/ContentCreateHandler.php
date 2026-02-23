<?php

declare(strict_types=1);

namespace Core\Mod\Content\Mcp\Handlers;

use Carbon\Carbon;
use Core\Front\Mcp\Contracts\McpToolHandler;
use Core\Front\Mcp\McpContext;
use Core\Mod\Content\Enums\ContentType;
use Core\Mod\Content\Models\ContentItem;
use Core\Mod\Content\Models\ContentRevision;
use Core\Mod\Content\Models\ContentTaxonomy;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Services\EntitlementService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * MCP tool handler for creating content items.
 *
 * Creates new blog posts or pages with content, taxonomies, and SEO metadata.
 */
class ContentCreateHandler implements McpToolHandler
{
    public static function schema(): array
    {
        return [
            'name' => 'content_create',
            'description' => 'Create a new blog post or page. Supports markdown content, categories, tags, and SEO metadata.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'workspace' => [
                        'type' => 'string',
                        'description' => 'Workspace slug or ID (required)',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Content title (required)',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['post', 'page'],
                        'description' => 'Content type: post (default) or page',
                        'default' => 'post',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'publish', 'future', 'private'],
                        'description' => 'Publication status (default: draft)',
                        'default' => 'draft',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'URL slug (auto-generated from title if not provided)',
                    ],
                    'excerpt' => [
                        'type' => 'string',
                        'description' => 'Content summary/excerpt',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Content body in markdown format',
                    ],
                    'content_html' => [
                        'type' => 'string',
                        'description' => 'Content body in HTML (optional, auto-generated from markdown)',
                    ],
                    'categories' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Array of category slugs or names (creates if not exists)',
                    ],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Array of tag strings (creates if not exists)',
                    ],
                    'seo_meta' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'description' => 'SEO metadata object',
                    ],
                    'publish_at' => [
                        'type' => 'string',
                        'description' => 'ISO datetime for scheduled publishing (required if status=future)',
                    ],
                ],
                'required' => ['workspace', 'title'],
            ],
        ];
    }

    public function handle(array $args, McpContext $context): array
    {
        $workspace = $this->resolveWorkspace($args['workspace'] ?? null);

        if (! $workspace) {
            return ['error' => 'Workspace not found. Provide a valid workspace slug or ID.'];
        }

        // Check entitlements
        $entitlementError = $this->checkEntitlement($workspace, 'create');
        if ($entitlementError) {
            return $entitlementError;
        }

        // Validate required fields
        $title = $args['title'] ?? null;
        if (! $title) {
            return ['error' => 'title is required'];
        }

        $type = $args['type'] ?? 'post';
        if (! in_array($type, ['post', 'page'])) {
            return ['error' => 'type must be post or page'];
        }

        $status = $args['status'] ?? 'draft';
        if (! in_array($status, ['draft', 'publish', 'future', 'private'])) {
            return ['error' => 'status must be draft, publish, future, or private'];
        }

        // Generate slug
        $slug = $args['slug'] ?? Str::slug($title);
        $baseSlug = $slug;
        $counter = 1;

        // Ensure unique slug within workspace
        while (ContentItem::forWorkspace($workspace->id)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        // Parse markdown content if provided
        $content = $args['content'] ?? '';
        $contentHtml = $args['content_html'] ?? null;
        $contentMarkdown = $content;

        // Convert markdown to HTML if only markdown provided
        if ($contentMarkdown && ! $contentHtml) {
            $contentHtml = Str::markdown($contentMarkdown);
        }

        // Handle scheduling
        $publishAt = null;
        if ($status === 'future') {
            $publishAtArg = $args['publish_at'] ?? null;
            if (! $publishAtArg) {
                return ['error' => 'publish_at is required for scheduled content'];
            }
            $publishAt = Carbon::parse($publishAtArg);
        }

        // Create content item
        $item = ContentItem::create([
            'workspace_id' => $workspace->id,
            'content_type' => ContentType::NATIVE,
            'type' => $type,
            'status' => $status,
            'slug' => $slug,
            'title' => $title,
            'excerpt' => $args['excerpt'] ?? null,
            'content_html' => $contentHtml,
            'content_markdown' => $contentMarkdown,
            'seo_meta' => $args['seo_meta'] ?? null,
            'publish_at' => $publishAt,
            'last_edited_by' => Auth::id(),
        ]);

        // Handle categories
        if (! empty($args['categories'])) {
            $categoryIds = $this->resolveOrCreateTaxonomies($workspace, $args['categories'], 'category');
            $item->taxonomies()->attach($categoryIds);
        }

        // Handle tags
        if (! empty($args['tags'])) {
            $tagIds = $this->resolveOrCreateTaxonomies($workspace, $args['tags'], 'tag');
            $item->taxonomies()->attach($tagIds);
        }

        // Create initial revision
        $item->createRevision(Auth::user(), ContentRevision::CHANGE_EDIT, 'Created via MCP');

        // Record usage
        $entitlements = app(EntitlementService::class);
        $entitlements->recordUsage($workspace, 'content.items', 1, Auth::user(), [
            'source' => 'mcp',
            'content_id' => $item->id,
        ]);

        $context->logToSession("Created content item: {$item->title} (ID: {$item->id})");

        return [
            'ok' => true,
            'item' => [
                'id' => $item->id,
                'slug' => $item->slug,
                'title' => $item->title,
                'type' => $item->type,
                'status' => $item->status,
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

    protected function checkEntitlement(Workspace $workspace, string $action): ?array
    {
        $entitlements = app(EntitlementService::class);

        // Check if workspace has content MCP access
        $result = $entitlements->can($workspace, 'content.mcp_access');

        if ($result->isDenied()) {
            return ['error' => $result->reason ?? 'Content MCP access not available in your plan.'];
        }

        // For create operations, check content limits
        if ($action === 'create') {
            $limitResult = $entitlements->can($workspace, 'content.items');
            if ($limitResult->isDenied()) {
                return ['error' => $limitResult->reason ?? 'Content item limit reached.'];
            }
        }

        return null;
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
