<?php

declare(strict_types=1);

namespace Core\Content\View\Modal\Web;

use Livewire\Component;
use Core\Content\Services\ContentRender;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Services\WorkspaceService;

class HelpArticle extends Component
{
    public array $workspace = [];

    public array $article = [];

    public bool $notFound = false;

    public function mount(string $slug): void
    {
        $workspaceService = app(WorkspaceService::class);

        // Get workspace from request attributes (set by subdomain middleware)
        $workspaceSlug = request()->attributes->get('workspace', 'main');
        $this->workspace = $workspaceService->get($workspaceSlug) ?? $workspaceService->get('main');

        $this->loadArticle($slug);
    }

    protected function loadArticle(string $slug): void
    {
        try {
            $workspaceModel = Workspace::where('slug', $this->workspace['slug'])->first();
            if (! $workspaceModel) {
                $this->notFound = true;

                return;
            }

            $render = app(ContentRender::class);
            $page = $render->getPage($workspaceModel, $slug);

            if ($page) {
                $this->article = $page;
            } else {
                $this->notFound = true;
            }
        } catch (\Exception $e) {
            $this->notFound = true;
        }
    }

    public function render()
    {
        if ($this->notFound) {
            abort(404);
        }

        return view('content::web.help-article', [
            'article' => $this->article,
            'workspace' => $this->workspace,
        ])->layout('shared::layouts.satellite', [
            'title' => ($this->article['title']['rendered'] ?? 'Help').' | '.$this->workspace['name'],
            'workspace' => $this->workspace,
        ]);
    }
}
