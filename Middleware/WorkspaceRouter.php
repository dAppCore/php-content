<?php

declare(strict_types=1);

namespace Core\Mod\Content\Middleware;

use Core\Mod\Content\Services\ContentRender;
use Core\Mod\Tenant\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route requests to workspace content when workspace context exists.
 *
 * Runs after FindDomainRecord. If workspace_model is set, handles
 * the request via ContentRender. Otherwise passes through.
 */
class WorkspaceRouter
{
    public function __construct(
        protected ContentRender $render
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->attributes->get('workspace_model');

        if (! $workspace instanceof Workspace) {
            return $next($request);
        }

        return $this->routeWorkspaceRequest($request, $workspace);
    }

    protected function routeWorkspaceRequest(Request $request, Workspace $workspace): Response
    {
        $path = trim($request->path(), '/');
        $method = $request->method();

        // Home
        if ($path === '' || $path === '/') {
            return response($this->render->home($request));
        }

        // Blog listing
        if ($path === 'blog') {
            return response($this->render->blog($request));
        }

        // Blog post
        if (str_starts_with($path, 'blog/')) {
            $slug = substr($path, 5);

            return response($this->render->post($request, $slug));
        }

        // Subscribe (waitlist)
        if ($path === 'subscribe' && $method === 'POST') {
            $result = $this->render->subscribe($request);

            return $result instanceof Response ? $result : response($result);
        }

        // Static page (catch-all)
        return response($this->render->page($request, $path));
    }
}
