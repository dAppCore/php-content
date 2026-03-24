<?php

declare(strict_types=1);

namespace Core\Mod\Content\View\Modal\Web;

use Core\Mod\Content\Models\ContentItem;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Services\WorkspaceService;
use Livewire\Component;

class Help extends Component
{
    public array $workspace = [];

    public array $articles = [];

    public bool $loading = true;

    public function mount(): void
    {
        $workspaceService = app(WorkspaceService::class);

        // Get workspace from request attributes (set by subdomain middleware)
        $slug = request()->attributes->get('workspace', 'main');
        $this->workspace = $workspaceService->get($slug) ?? $workspaceService->get('main');

        $this->loadArticles();
    }

    protected function loadArticles(): void
    {
        try {
            $workspaceModel = Workspace::where('slug', $this->workspace['slug'])->first();
            if (! $workspaceModel) {
                $this->articles = [];
                $this->loading = false;

                return;
            }

            // Get help articles from native content
            // Help articles are identified by:
            // 1. Pages with 'help/' slug prefix
            // 2. Pages in a 'help' category
            $articles = ContentItem::forWorkspace($workspaceModel->id)
                ->native()
                ->pages()
                ->helpArticles()
                ->published()
                ->orderByDesc('created_at')
                ->take(20)
                ->get();

            // If no help articles found with the scope, fall back to all pages
            // This maintains backwards compatibility for workspaces without
            // proper help article categorisation
            if ($articles->isEmpty()) {
                $articles = ContentItem::forWorkspace($workspaceModel->id)
                    ->native()
                    ->pages()
                    ->published()
                    ->orderByDesc('created_at')
                    ->take(20)
                    ->get();
            }

            $this->articles = $articles->map(fn ($item) => $item->toRenderArray())->toArray();
        } catch (\Exception $e) {
            $this->articles = [];
        }

        $this->loading = false;
    }

    public function render()
    {
        return view('content::web.help', [
            'articles' => $this->articles,
            'workspace' => $this->workspace,
        ])->layout('shared::layouts.satellite', [
            'title' => 'Help | '.$this->workspace['name'],
            'workspace' => $this->workspace,
        ]);
    }
}
