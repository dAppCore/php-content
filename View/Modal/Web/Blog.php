<?php

declare(strict_types=1);

namespace Core\Mod\Content\View\Modal\Web;

use Livewire\Component;
use Core\Mod\Content\Services\ContentRender;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Services\WorkspaceService;

class Blog extends Component
{
    public array $workspace = [];

    public array $posts = [];

    public bool $loading = true;

    public function mount(): void
    {
        $workspaceService = app(WorkspaceService::class);

        // Get workspace from request attributes (set by subdomain middleware)
        $slug = request()->attributes->get('workspace', 'main');
        $this->workspace = $workspaceService->get($slug) ?? $workspaceService->get('main');

        $this->loadPosts();
    }

    protected function loadPosts(): void
    {
        try {
            $workspaceModel = Workspace::where('slug', $this->workspace['slug'])->first();
            if (! $workspaceModel) {
                $this->posts = [];
                $this->loading = false;

                return;
            }

            $render = app(ContentRender::class);
            $result = $render->getPosts($workspaceModel, page: 1, perPage: 20);
            $this->posts = $result['posts'] ?? [];
        } catch (\Exception $e) {
            $this->posts = [];
        }

        $this->loading = false;
    }

    public function render()
    {
        return view('content::web.blog', [
            'posts' => $this->posts,
            'workspace' => $this->workspace,
        ])->layout('shared::layouts.satellite', [
            'title' => 'Blog | '.$this->workspace['name'],
            'workspace' => $this->workspace,
        ]);
    }
}
