<?php

declare(strict_types=1);

namespace Core\Mod\Content\Services;

use Core\Front\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Core\Mod\Content\Models\ContentItem;
use Core\Tenant\Models\Workspace;

/**
 * ContentRender - Public workspace frontend renderer.
 *
 * Renders public-facing pages for workspace sites (blog, help, pages).
 * Content is sourced from the native ContentItem database.
 */
class ContentRender extends Controller
{
    /**
     * Render the homepage.
     */
    public function home(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace || ! $workspace->is_active) {
            return $this->waitlist($workspace);
        }

        $content = $this->getHomepage($workspace);

        if (! $content) {
            return $this->waitlist($workspace);
        }

        return view('web::home', [
            'workspace' => $workspace,
            'content' => $content,
            'meta' => $this->getMeta($workspace),
        ]);
    }

    /**
     * Render a blog post.
     */
    public function post(Request $request, string $slug): View
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace || ! $workspace->is_active) {
            abort(404);
        }

        $post = $this->getPost($workspace, $slug);

        if (! $post) {
            abort(404);
        }

        return view('web::page', [
            'workspace' => $workspace,
            'post' => $post,
            'meta' => $this->getMeta($workspace, $post),
        ]);
    }

    /**
     * Render the blog listing.
     */
    public function blog(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);
        $page = (int) $request->get('page', 1);

        if (! $workspace || ! $workspace->is_active) {
            return $this->waitlist($workspace);
        }

        $posts = $this->getPosts($workspace, $page);

        return view('web::page', [
            'workspace' => $workspace,
            'posts' => $posts['posts'],
            'pagination' => [
                'current' => $page,
                'total' => $posts['pages'],
                'count' => $posts['total'],
            ],
            'meta' => $this->getMeta($workspace),
        ]);
    }

    /**
     * Render a static page.
     */
    public function page(Request $request, string $slug): View
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace || ! $workspace->is_active) {
            abort(404);
        }

        $page = $this->getPage($workspace, $slug);

        if (! $page) {
            abort(404);
        }

        return view('web::page', [
            'workspace' => $workspace,
            'page' => $page,
            'meta' => $this->getMeta($workspace, $page),
        ]);
    }

    /**
     * Handle waitlist subscription.
     */
    public function subscribe(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $workspace = $this->resolveWorkspace($request);
        $this->addToWaitlist($workspace, $request->email);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'You\'ve been added to the waitlist!',
            ]);
        }

        return back()->with('subscribed', true);
    }

    /**
     * Render the waitlist page.
     */
    public function waitlist(?Workspace $workspace): View
    {
        return view('web::waitlist', [
            'workspace' => $workspace,
            'subscribed' => session('subscribed', false),
        ]);
    }

    // -------------------------------------------------------------------------
    // Content fetching (cached)
    // -------------------------------------------------------------------------

    protected function getCacheTtl(): int
    {
        return config('app.env') === 'production' ? 3600 : 60;
    }

    /**
     * Sanitise a slug for use in cache keys.
     *
     * Removes special characters that could cause cache key collisions
     * or issues with cache backends (Redis, Memcached, etc).
     */
    protected function sanitiseCacheKey(string $slug): string
    {
        // Replace any character that isn't alphanumeric, dash, or underscore
        $sanitised = preg_replace('/[^a-zA-Z0-9_-]/', '_', $slug);

        // Collapse multiple underscores
        $sanitised = preg_replace('/_+/', '_', $sanitised);

        // Limit length to prevent overly long cache keys
        return substr($sanitised, 0, 100);
    }

    public function getHomepage(Workspace $workspace): ?array
    {
        $cacheKey = 'content:render:'.$this->sanitiseCacheKey($workspace->slug).':homepage';

        return Cache::remember($cacheKey, $this->getCacheTtl(), function () use ($workspace) {
            $posts = ContentItem::forWorkspace($workspace->id)
                ->native()
                ->posts()
                ->published()
                ->orderByDesc('created_at')
                ->take(6)
                ->get();

            return [
                'site' => [
                    'name' => $workspace->name,
                    'description' => $workspace->description,
                ],
                'featured_posts' => $posts->isEmpty() ? [] : $this->formatPosts($posts),
            ];
        });
    }

    public function getPosts(Workspace $workspace, int $page = 1, int $perPage = 10): array
    {
        $cacheKey = 'content:render:'.$this->sanitiseCacheKey($workspace->slug).":posts:{$page}:{$perPage}";

        return Cache::remember($cacheKey, $this->getCacheTtl(), function () use ($workspace, $page, $perPage) {
            $query = ContentItem::forWorkspace($workspace->id)
                ->native()
                ->posts()
                ->published()
                ->orderByDesc('created_at');

            $total = $query->count();

            $posts = $query->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return [
                'posts' => $this->formatPosts($posts),
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ];
        });
    }

    public function getPost(Workspace $workspace, string $slug): ?array
    {
        $cacheKey = 'content:render:'.$this->sanitiseCacheKey($workspace->slug).':post:'.$this->sanitiseCacheKey($slug);

        return Cache::remember($cacheKey, $this->getCacheTtl(), function () use ($workspace, $slug) {
            $post = ContentItem::forWorkspace($workspace->id)
                ->native()
                ->posts()
                ->published()
                ->bySlug($slug)
                ->with(['author', 'taxonomies'])
                ->first();

            return $post ? $post->toRenderArray() : null;
        });
    }

    public function getPage(Workspace $workspace, string $slug): ?array
    {
        $cacheKey = 'content:render:'.$this->sanitiseCacheKey($workspace->slug).':page:'.$this->sanitiseCacheKey($slug);

        return Cache::remember($cacheKey, $this->getCacheTtl(), function () use ($workspace, $slug) {
            $page = ContentItem::forWorkspace($workspace->id)
                ->native()
                ->pages()
                ->published()
                ->bySlug($slug)
                ->first();

            return $page ? $page->toRenderArray() : null;
        });
    }

    public function getMeta(Workspace $workspace, ?array $content = null): array
    {
        $meta = [
            'title' => $workspace->name,
            'description' => $workspace->description ?? '',
            'image' => null,
            'url' => 'https://'.$workspace->domain,
        ];

        if ($content) {
            $meta['title'] = strip_tags($content['title']['rendered'] ?? $content['title'] ?? $workspace->name);
            $meta['description'] = strip_tags($content['excerpt']['rendered'] ?? $content['excerpt'] ?? '');

            if (isset($content['_embedded']['wp:featuredmedia'][0]['source_url'])) {
                $meta['image'] = $content['_embedded']['wp:featuredmedia'][0]['source_url'];
            }
        }

        return $meta;
    }

    // -------------------------------------------------------------------------
    // Waitlist
    // -------------------------------------------------------------------------

    /**
     * Add an email to the waitlist.
     *
     * Uses the WaitlistEntry model for persistent storage in the database.
     * The source field tracks which workspace/service the signup came from.
     */
    public function addToWaitlist(?Workspace $workspace, string $email): bool
    {
        // Check if email already exists
        $existing = \Core\Tenant\Models\WaitlistEntry::where('email', $email)->first();

        if ($existing) {
            return false;
        }

        \Core\Tenant\Models\WaitlistEntry::create([
            'email' => $email,
            'source' => $workspace ? "workspace:{$workspace->slug}" : 'content:global',
            'interest' => $workspace ? 'workspace_content' : 'platform',
        ]);

        Log::info('Added '.$email.' to waitlist for workspace: '.($workspace?->slug ?? 'global'));

        return true;
    }

    /**
     * Get waitlist entries for a workspace or globally.
     *
     * Returns array of emails for compatibility with existing code.
     */
    public function getWaitlist(?Workspace $workspace): array
    {
        $query = \Core\Tenant\Models\WaitlistEntry::query();

        if ($workspace) {
            $query->where('source', "workspace:{$workspace->slug}");
        }

        return $query->pluck('email')->toArray();
    }

    // -------------------------------------------------------------------------
    // Cache management
    // -------------------------------------------------------------------------

    public function invalidateCache(Workspace $workspace): void
    {
        $prefix = 'content:render:'.$this->sanitiseCacheKey($workspace->slug).':';

        if (config('cache.default') === 'redis') {
            $keys = Cache::getRedis()->keys(config('cache.prefix').':'.$prefix.'*');
            if ($keys) {
                Cache::getRedis()->del($keys);
            }
        } else {
            Cache::forget($prefix.'homepage');
        }

        Log::info("Content render cache invalidated for workspace: {$workspace->slug}");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function resolveWorkspace(Request $request): ?Workspace
    {
        $workspace = $request->attributes->get('workspace_model');
        if ($workspace instanceof Workspace) {
            return $workspace;
        }

        $workspaceSlug = $request->attributes->get('workspace');
        if ($workspaceSlug) {
            if ($workspaceSlug instanceof Workspace) {
                return $workspaceSlug;
            }

            return Workspace::where('slug', $workspaceSlug)->first();
        }

        return Workspace::where('domain', $request->getHost())->first();
    }

    protected function formatPosts($posts): array
    {
        return $posts->map(fn ($post) => $post->toRenderArray())->toArray();
    }
}
