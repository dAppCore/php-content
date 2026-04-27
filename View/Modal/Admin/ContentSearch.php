<?php

declare(strict_types=1);

namespace Core\Mod\Content\View\Modal\Admin;

use Core\Mod\Content\Models\ContentItem;
use Core\Mod\Content\Models\ContentTaxonomy;
use Core\Mod\Content\Services\ContentSearchService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Content Search Livewire Component
 *
 * Provides a searchable interface for content items with:
 * - Real-time search with debouncing
 * - Filtering by type, status, category, date range
 * - Paginated results with relevance scoring
 */
#[Layout('hub::admin.layouts.app')]
class ContentSearch extends Component
{
    use WithPagination;

    // -------------------------------------------------------------------------
    // Search Query
    // -------------------------------------------------------------------------

    #[Url(as: 'q')]
    public string $query = '';

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $perPage = 20;

    // -------------------------------------------------------------------------
    // UI State
    // -------------------------------------------------------------------------

    public bool $showFilters = false;

    // -------------------------------------------------------------------------
    // Computed Properties
    // -------------------------------------------------------------------------

    #[Computed]
    public function workspace()
    {
        return auth()->user()?->defaultHostWorkspace();
    }

    #[Computed]
    public function results()
    {
        if (! $this->workspace) {
            return collect();
        }

        // Require minimum query length
        if (strlen(trim($this->query)) < 2) {
            return null;
        }

        $searchService = app(ContentSearchService::class);

        $filters = array_filter([
            'workspace_id' => $this->workspace->id,
            'type' => $this->type ?: null,
            'status' => $this->status ?: null,
            'category' => $this->category ?: null,
            'date_from' => $this->dateFrom ?: null,
            'date_to' => $this->dateTo ?: null,
            'per_page' => $this->perPage,
            'page' => $this->getPage(),
        ], fn ($v) => $v !== null);

        return $searchService->search($this->query, $filters);
    }

    #[Computed]
    public function categories()
    {
        if (! $this->workspace) {
            return collect();
        }

        return ContentTaxonomy::where('workspace_id', $this->workspace->id)
            ->where('type', 'category')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function searchBackend()
    {
        return app(ContentSearchService::class)->getBackend();
    }

    #[Computed]
    public function recentContent()
    {
        if (! $this->workspace) {
            return collect();
        }

        // Show recent content when no search query
        return ContentItem::forWorkspace($this->workspace->id)
            ->native()
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    public function updatedQuery(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function toggleFilters(): void
    {
        $this->showFilters = ! $this->showFilters;
    }

    public function clearFilters(): void
    {
        $this->type = '';
        $this->status = '';
        $this->category = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->query = '';
        $this->clearFilters();
    }

    public function viewContent(int $id): void
    {
        if (! $this->workspace) {
            return;
        }

        // Navigate to content editor
        $this->redirect(
            route('hub.content-editor.edit', ['workspace' => $this->workspace->slug, 'id' => $id]),
            navigate: true
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function hasActiveFilters(): bool
    {
        return $this->type !== ''
            || $this->status !== ''
            || $this->category !== ''
            || $this->dateFrom !== ''
            || $this->dateTo !== '';
    }

    public function activeFilterCount(): int
    {
        return count(array_filter([
            $this->type,
            $this->status,
            $this->category,
            $this->dateFrom,
            $this->dateTo,
        ]));
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render()
    {
        return view('content::admin.content-search');
    }
}
