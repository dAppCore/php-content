<?php

declare(strict_types=1);

namespace Core\Mod\Content;

use Core\Events\ApiRoutesRegistering;
use Core\Events\ConsoleBooting;
use Core\Events\McpToolsRegistering;
use Core\Events\WebRoutesRegistering;
use Core\Mod\Content\Services\HtmlSanitiser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Content Module Boot
 *
 * WordPress sync/API system for content management.
 * Handles syncing content from WordPress via REST API,
 * content revisions, media, taxonomies, and webhook processing.
 * Also provides public satellite pages (blog, help).
 */
class Boot extends ServiceProvider
{
    /**
     * Events this module listens to for lazy loading.
     *
     * @var array<class-string, string>
     */
    public static array $listens = [
        WebRoutesRegistering::class => 'onWebRoutes',
        ApiRoutesRegistering::class => 'onApiRoutes',
        ConsoleBooting::class => 'onConsole',
        McpToolsRegistering::class => 'onMcpTools',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'content');

        // Register HtmlSanitiser as a singleton for performance
        $this->app->singleton(HtmlSanitiser::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Migrations');
        $this->configureRateLimiting();
        $this->validateSecurityDependencies();
    }

    /**
     * Validate that security-critical dependencies are available.
     *
     * @throws RuntimeException If HTMLPurifier is not installed
     */
    protected function validateSecurityDependencies(): void
    {
        if (! HtmlSanitiser::isAvailable()) {
            throw new RuntimeException(
                'core-content requires HTMLPurifier for secure HTML sanitisation. '.
                'Install it with: composer require ezyang/htmlpurifier'
            );
        }
    }

    /**
     * Configure rate limiters for content generation endpoints.
     *
     * AI generation is expensive, so we apply strict rate limits:
     * - Authenticated users: 10 requests per minute
     * - Unauthenticated: 2 requests per minute (should not happen via API auth)
     */
    protected function configureRateLimiting(): void
    {
        // Rate limit for AI content generation: 10 per minute per user/workspace
        // AI calls are expensive ($0.01-0.10 per generation), so we limit aggressively
        RateLimiter::for('content-generate', function (Request $request) {
            $user = $request->user();

            if ($user) {
                // Use workspace_id if available for workspace-level limiting
                $workspaceId = $request->input('workspace_id') ?? $request->route('workspace_id');

                return $workspaceId
                    ? Limit::perMinute(10)->by('workspace:'.$workspaceId)
                    : Limit::perMinute(10)->by('user:'.$user->id);
            }

            // Unauthenticated - very low limit
            return Limit::perMinute(2)->by($request->ip());
        });

        // Rate limit for brief creation: 30 per minute per user
        // Brief creation is less expensive but still rate limited
        RateLimiter::for('content-briefs', function (Request $request) {
            $user = $request->user();

            return $user
                ? Limit::perMinute(30)->by('user:'.$user->id)
                : Limit::perMinute(5)->by($request->ip());
        });

        // Rate limit for incoming webhooks: 60 per minute per endpoint
        // Webhooks from external CMS systems need reasonable limits
        RateLimiter::for('content-webhooks', function (Request $request) {
            // Use endpoint UUID or IP for rate limiting
            $endpoint = $request->route('endpoint');

            return $endpoint
                ? Limit::perMinute(60)->by('webhook-endpoint:'.$endpoint)
                : Limit::perMinute(30)->by('webhook-ip:'.$request->ip());
        });

        // Rate limit for content search: configurable per minute per user
        // Search queries can be resource-intensive with full-text matching
        RateLimiter::for('content-search', function (Request $request) {
            $user = $request->user();
            $limit = config('content.search.rate_limit', 60);

            return $user
                ? Limit::perMinute($limit)->by('search-user:'.$user->id)
                : Limit::perMinute(20)->by('search-ip:'.$request->ip());
        });
    }

    // -------------------------------------------------------------------------
    // Event-driven handlers (for lazy loading once event system is integrated)
    // -------------------------------------------------------------------------

    /**
     * Handle web routes registration event.
     */
    public function onWebRoutes(WebRoutesRegistering $event): void
    {
        $event->views('content', __DIR__.'/View/Blade');

        // Public web components
        $event->livewire('content.blog', View\Modal\Web\Blog::class);
        $event->livewire('content.post', View\Modal\Web\Post::class);
        $event->livewire('content.help', View\Modal\Web\Help::class);
        $event->livewire('content.help-article', View\Modal\Web\HelpArticle::class);
        $event->livewire('content.preview', View\Modal\Web\Preview::class);

        // Admin components
        $event->livewire('content.admin.webhook-manager', View\Modal\Admin\WebhookManager::class);
        $event->livewire('content.admin.content-search', View\Modal\Admin\ContentSearch::class);

        if (file_exists(__DIR__.'/Routes/web.php')) {
            $event->routes(fn () => require __DIR__.'/Routes/web.php');
        }
    }

    /**
     * Handle API routes registration event.
     */
    public function onApiRoutes(ApiRoutesRegistering $event): void
    {
        if (file_exists(__DIR__.'/Routes/api.php')) {
            $event->routes(fn () => require __DIR__.'/Routes/api.php');
        }
    }

    /**
     * Handle console booting event.
     */
    public function onConsole(ConsoleBooting $event): void
    {
        // Register Content module commands
        $event->command(Console\Commands\PruneContentRevisions::class);
        $event->command(Console\Commands\PublishScheduledContent::class);

        // Note: Some content commands are in app/Console/Commands as they
        // depend on both Content and Agentic modules
    }

    /**
     * Handle MCP tools registration event.
     *
     * Registers Content module MCP tools for:
     * - Listing content items
     * - Reading content by ID/slug
     * - Searching content
     * - Creating new content
     * - Updating existing content
     * - Deleting content (soft delete)
     * - Listing taxonomies (categories/tags)
     */
    public function onMcpTools(McpToolsRegistering $event): void
    {
        $event->handler(Mcp\Handlers\ContentListHandler::class);
        $event->handler(Mcp\Handlers\ContentReadHandler::class);
        $event->handler(Mcp\Handlers\ContentSearchHandler::class);
        $event->handler(Mcp\Handlers\ContentCreateHandler::class);
        $event->handler(Mcp\Handlers\ContentUpdateHandler::class);
        $event->handler(Mcp\Handlers\ContentDeleteHandler::class);
        $event->handler(Mcp\Handlers\ContentTaxonomiesHandler::class);
    }
}
