<?php

declare(strict_types=1);

namespace Core\Content\View\Modal\Admin;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Core\Content\Models\ContentWebhookEndpoint;
use Core\Content\Models\ContentWebhookLog;

/**
 * Livewire component for managing content webhook endpoints.
 *
 * Allows users to:
 * - Create/edit webhook endpoints
 * - View incoming webhook logs
 * - Copy webhook URLs
 * - Regenerate secrets
 * - Enable/disable endpoints
 */
#[Layout('hub::admin.layouts.app')]
class WebhookManager extends Component
{
    use WithPagination;

    // -------------------------------------------------------------------------
    // Search and Filter
    // -------------------------------------------------------------------------

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $view = 'endpoints'; // endpoints | logs

    // -------------------------------------------------------------------------
    // Endpoint Form
    // -------------------------------------------------------------------------

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public array $allowedTypes = [];

    public bool $isEnabled = true;

    // -------------------------------------------------------------------------
    // Delete Confirmation
    // -------------------------------------------------------------------------

    public ?string $deletingUuid = null;

    // -------------------------------------------------------------------------
    // Secret Display
    // -------------------------------------------------------------------------

    public ?string $showingSecretUuid = null;

    public ?string $revealedSecret = null;

    // -------------------------------------------------------------------------
    // Computed Properties
    // -------------------------------------------------------------------------

    #[Computed]
    public function workspace()
    {
        return auth()->user()?->defaultHostWorkspace();
    }

    #[Computed]
    public function endpoints()
    {
        if (! $this->workspace) {
            return collect();
        }

        $query = ContentWebhookEndpoint::where('workspace_id', $this->workspace->id);

        if ($this->search) {
            $escapedSearch = $this->escapeLikeWildcards($this->search);
            $query->where('name', 'like', "%{$escapedSearch}%");
        }

        if ($this->statusFilter === 'enabled') {
            $query->where('is_enabled', true);
        } elseif ($this->statusFilter === 'disabled') {
            $query->where('is_enabled', false);
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->paginate(15);
    }

    #[Computed]
    public function logs()
    {
        if (! $this->workspace) {
            return collect();
        }

        $query = ContentWebhookLog::where('workspace_id', $this->workspace->id);

        if ($this->search) {
            $escapedSearch = $this->escapeLikeWildcards($this->search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('event_type', 'like', "%{$escapedSearch}%")
                    ->orWhere('source_ip', 'like', "%{$escapedSearch}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->paginate(25);
    }

    #[Computed]
    public function availableTypes(): array
    {
        return ContentWebhookEndpoint::ALLOWED_TYPES;
    }

    // -------------------------------------------------------------------------
    // View Toggle
    // -------------------------------------------------------------------------

    public function switchView(string $view): void
    {
        $this->view = $view;
        $this->resetPage();
    }

    // -------------------------------------------------------------------------
    // Endpoint CRUD
    // -------------------------------------------------------------------------

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
        $this->allowedTypes = ContentWebhookEndpoint::ALLOWED_TYPES;
    }

    public function edit(string $uuid): void
    {
        if (! $this->workspace) {
            return;
        }

        $endpoint = ContentWebhookEndpoint::where('workspace_id', $this->workspace->id)
            ->where('uuid', $uuid)
            ->first();

        if (! $endpoint) {
            return;
        }

        $this->editingId = $endpoint->id;
        $this->name = $endpoint->name;
        $this->allowedTypes = $endpoint->allowed_types ?? [];
        $this->isEnabled = $endpoint->is_enabled;
        $this->showForm = true;
    }

    public function save(): void
    {
        if (! $this->workspace) {
            return;
        }

        $this->validate([
            'name' => 'required|string|max:255',
            'allowedTypes' => 'array',
        ]);

        if ($this->editingId) {
            $endpoint = ContentWebhookEndpoint::where('workspace_id', $this->workspace->id)
                ->where('id', $this->editingId)
                ->first();

            if ($endpoint) {
                $endpoint->update([
                    'name' => $this->name,
                    'allowed_types' => $this->allowedTypes,
                    'is_enabled' => $this->isEnabled,
                ]);
                $this->dispatch('notify', type: 'success', message: 'Webhook endpoint updated.');
            }
        } else {
            ContentWebhookEndpoint::create([
                'workspace_id' => $this->workspace->id,
                'name' => $this->name,
                'allowed_types' => $this->allowedTypes,
                'is_enabled' => $this->isEnabled,
            ]);
            $this->dispatch('notify', type: 'success', message: 'Webhook endpoint created.');
        }

        $this->resetForm();
        unset($this->endpoints);
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->allowedTypes = [];
        $this->isEnabled = true;
    }

    // -------------------------------------------------------------------------
    // Toggle Active
    // -------------------------------------------------------------------------

    public function toggleActive(string $uuid): void
    {
        if (! $this->workspace) {
            return;
        }

        $endpoint = ContentWebhookEndpoint::where('workspace_id', $this->workspace->id)
            ->where('uuid', $uuid)
            ->first();

        if ($endpoint) {
            $endpoint->update(['is_enabled' => ! $endpoint->is_enabled]);
            unset($this->endpoints);
            $this->dispatch(
                'notify',
                type: 'success',
                message: $endpoint->is_enabled ? 'Webhook endpoint enabled.' : 'Webhook endpoint disabled.'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    public function confirmDelete(string $uuid): void
    {
        $this->deletingUuid = $uuid;
    }

    public function delete(): void
    {
        if (! $this->deletingUuid || ! $this->workspace) {
            return;
        }

        $endpoint = ContentWebhookEndpoint::where('workspace_id', $this->workspace->id)
            ->where('uuid', $this->deletingUuid)
            ->first();

        if ($endpoint) {
            $endpoint->delete();
            $this->dispatch('notify', type: 'success', message: 'Webhook endpoint deleted.');
            unset($this->endpoints);
        }

        $this->deletingUuid = null;
    }

    public function cancelDelete(): void
    {
        $this->deletingUuid = null;
    }

    // -------------------------------------------------------------------------
    // Secret Management
    // -------------------------------------------------------------------------

    public function showSecret(string $uuid): void
    {
        if (! $this->workspace) {
            return;
        }

        $endpoint = ContentWebhookEndpoint::where('workspace_id', $this->workspace->id)
            ->where('uuid', $uuid)
            ->first();

        if ($endpoint) {
            $this->showingSecretUuid = $uuid;
            $this->revealedSecret = $endpoint->secret;
        }
    }

    public function hideSecret(): void
    {
        $this->showingSecretUuid = null;
        $this->revealedSecret = null;
    }

    public function regenerateSecret(string $uuid): void
    {
        if (! $this->workspace) {
            return;
        }

        $endpoint = ContentWebhookEndpoint::where('workspace_id', $this->workspace->id)
            ->where('uuid', $uuid)
            ->first();

        if ($endpoint) {
            $newSecret = $endpoint->regenerateSecret();
            $this->showingSecretUuid = $uuid;
            $this->revealedSecret = $newSecret;
            $this->dispatch('notify', type: 'success', message: 'Secret regenerated. Copy it now - it will not be shown again.');
            unset($this->endpoints);
        }
    }

    // -------------------------------------------------------------------------
    // Copy URL
    // -------------------------------------------------------------------------

    public function copyUrl(string $uuid): void
    {
        if (! $this->workspace) {
            return;
        }

        $endpoint = ContentWebhookEndpoint::where('workspace_id', $this->workspace->id)
            ->where('uuid', $uuid)
            ->first();

        if ($endpoint) {
            $this->dispatch('copy-to-clipboard', text: $endpoint->getEndpointUrl());
            $this->dispatch('notify', type: 'success', message: 'Webhook URL copied to clipboard.');
        }
    }

    // -------------------------------------------------------------------------
    // Reset Failures
    // -------------------------------------------------------------------------

    public function resetFailures(string $uuid): void
    {
        if (! $this->workspace) {
            return;
        }

        $endpoint = ContentWebhookEndpoint::where('workspace_id', $this->workspace->id)
            ->where('uuid', $uuid)
            ->first();

        if ($endpoint) {
            $endpoint->update([
                'failure_count' => 0,
                'is_enabled' => true,
            ]);
            $this->dispatch('notify', type: 'success', message: 'Failure count reset and endpoint enabled.');
            unset($this->endpoints);
        }
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render()
    {
        return view('content::admin.webhook-manager');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function escapeLikeWildcards(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
