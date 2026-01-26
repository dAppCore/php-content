<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <core:heading size="xl">Content Webhooks</core:heading>
            <core:subheading>
                Receive content updates from WordPress, CMS systems, and other sources.
            </core:subheading>
        </div>

        <core:button wire:click="create" variant="primary" icon="plus">
            Create endpoint
        </core:button>
    </div>

    {{-- View Toggle --}}
    <div class="flex items-center gap-4">
        <flux:tabs wire:model.live="view">
            <flux:tab name="endpoints" icon="link">Endpoints</flux:tab>
            <flux:tab name="logs" icon="document-text">Webhook Logs</flux:tab>
        </flux:tabs>
    </div>

    {{-- Filters --}}
    <div class="flex items-center gap-4">
        <div class="flex-1 max-w-md">
            <core:input
                wire:model.live.debounce.300ms="search"
                type="search"
                placeholder="{{ $view === 'endpoints' ? 'Search endpoints...' : 'Search logs...' }}"
                icon="magnifying-glass"
            />
        </div>

        <core:select wire:model.live="statusFilter" class="w-40">
            <option value="">All statuses</option>
            @if($view === 'endpoints')
                <option value="enabled">Enabled</option>
                <option value="disabled">Disabled</option>
            @else
                <option value="pending">Pending</option>
                <option value="processing">Processing</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
            @endif
        </core:select>
    </div>

    @if($view === 'endpoints')
        {{-- Endpoints List --}}
        @if($this->endpoints->isEmpty())
            <flux:card class="p-12">
                <div class="flex flex-col items-center justify-center text-center">
                    <div class="w-16 h-16 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center mb-4">
                        <flux:icon name="link" class="size-8 text-blue-500" />
                    </div>
                    <flux:heading size="lg">No webhook endpoints yet</flux:heading>
                    <flux:subheading class="mt-1">
                        @if($search)
                            No endpoints match your search.
                        @else
                            Create an endpoint to start receiving content webhooks.
                        @endif
                    </flux:subheading>
                    @unless($search)
                        <flux:button wire:click="create" variant="primary" class="mt-4" icon="plus">
                            Create your first endpoint
                        </flux:button>
                    @endunless
                </div>
            </flux:card>
        @else
            <flux:card class="overflow-hidden !p-0">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-900">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                Name
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                Webhook URL
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                Status
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                Last Received
                            </th>
                            <th scope="col" class="relative px-4 py-3">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach($this->endpoints as $endpoint)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                <td class="px-4 py-3">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $endpoint->name }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <code class="text-xs text-zinc-500 dark:text-zinc-400 bg-zinc-100 dark:bg-zinc-800 px-2 py-1 rounded">
                                            {{ Str::limit($endpoint->getEndpointUrl(), 50) }}
                                        </code>
                                        <button
                                            wire:click="copyUrl('{{ $endpoint->uuid }}')"
                                            class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300"
                                            title="Copy URL"
                                        >
                                            <flux:icon name="clipboard-document" class="size-4" />
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <button
                                        wire:click="toggleActive('{{ $endpoint->uuid }}')"
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition
                                            @if($endpoint->is_enabled && !$endpoint->isCircuitBroken())
                                                bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400
                                            @elseif($endpoint->isCircuitBroken())
                                                bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900/30 dark:text-red-400
                                            @else
                                                bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-400
                                            @endif
                                        "
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full
                                            @if($endpoint->is_enabled && !$endpoint->isCircuitBroken()) bg-green-500
                                            @elseif($endpoint->isCircuitBroken()) bg-red-500
                                            @else bg-zinc-400
                                            @endif
                                        "></span>
                                        {{ $endpoint->status_label }}
                                    </button>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-sm text-zinc-500">
                                        {{ $endpoint->last_received_at?->diffForHumans() ?? 'Never' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <core:dropdown>
                                        <core:button variant="ghost" size="sm" icon="ellipsis-vertical" />

                                        <core:menu>
                                            <core:menu.item
                                                wire:click="copyUrl('{{ $endpoint->uuid }}')"
                                                icon="clipboard-document"
                                            >
                                                Copy URL
                                            </core:menu.item>
                                            <core:menu.item
                                                wire:click="showSecret('{{ $endpoint->uuid }}')"
                                                icon="key"
                                            >
                                                View Secret
                                            </core:menu.item>
                                            <core:menu.item
                                                wire:click="edit('{{ $endpoint->uuid }}')"
                                                icon="pencil-square"
                                            >
                                                Edit
                                            </core:menu.item>
                                            @if($endpoint->failure_count > 0)
                                                <core:menu.item
                                                    wire:click="resetFailures('{{ $endpoint->uuid }}')"
                                                    icon="arrow-path"
                                                >
                                                    Reset Failures
                                                </core:menu.item>
                                            @endif
                                            <core:menu.separator />
                                            <core:menu.item
                                                wire:click="confirmDelete('{{ $endpoint->uuid }}')"
                                                icon="trash"
                                                variant="danger"
                                            >
                                                Delete
                                            </core:menu.item>
                                        </core:menu>
                                    </core:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </flux:card>

            {{-- Pagination --}}
            <div class="mt-6">
                {{ $this->endpoints->links() }}
            </div>
        @endif
    @else
        {{-- Webhook Logs --}}
        @if($this->logs->isEmpty())
            <flux:card class="p-12">
                <div class="flex flex-col items-center justify-center text-center">
                    <div class="w-16 h-16 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mb-4">
                        <flux:icon name="document-text" class="size-8 text-zinc-400" />
                    </div>
                    <flux:heading size="lg">No webhook logs yet</flux:heading>
                    <flux:subheading class="mt-1">
                        @if($search || $statusFilter)
                            No logs match your filters.
                        @else
                            Webhook logs will appear here once you start receiving webhooks.
                        @endif
                    </flux:subheading>
                </div>
            </flux:card>
        @else
            <flux:card class="overflow-hidden !p-0">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-900">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                Event Type
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                Content
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                Status
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                Source IP
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                Received
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach($this->logs as $log)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                <td class="px-4 py-3">
                                    <flux:badge color="{{ $log->event_color }}">
                                        {{ $log->event_type }}
                                    </flux:badge>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-sm text-zinc-600 dark:text-zinc-400">
                                        @if($log->content_type)
                                            {{ $log->content_type }}
                                            @if($log->wp_id)
                                                #{{ $log->wp_id }}
                                            @endif
                                        @else
                                            -
                                        @endif
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <flux:badge color="{{ $log->status_color }}" icon="{{ $log->status_icon }}">
                                        {{ ucfirst($log->status) }}
                                    </flux:badge>
                                    @if($log->error_message)
                                        <span class="block text-xs text-red-500 mt-1" title="{{ $log->error_message }}">
                                            {{ Str::limit($log->error_message, 40) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-sm text-zinc-500">{{ $log->source_ip ?? '-' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-sm text-zinc-500" title="{{ $log->created_at }}">
                                        {{ $log->created_at->diffForHumans() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </flux:card>

            {{-- Pagination --}}
            <div class="mt-6">
                {{ $this->logs->links() }}
            </div>
        @endif
    @endif

    {{-- Create/Edit Endpoint Modal --}}
    <core:modal wire:model.live="showForm" class="max-w-lg">
        <div class="space-y-4">
            <core:heading size="lg">{{ $editingId ? 'Edit' : 'Create' }} Webhook Endpoint</core:heading>

            <div class="space-y-4">
                <div>
                    <core:label for="name">Name</core:label>
                    <core:input
                        wire:model="name"
                        id="name"
                        placeholder="WordPress Blog"
                    />
                    @error('name') <span class="text-sm text-red-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <core:label>Allowed Event Types</core:label>
                    <div class="mt-2 space-y-2 max-h-48 overflow-y-auto border rounded-lg p-3 dark:border-zinc-700">
                        @foreach($this->availableTypes as $type)
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    wire:model="allowedTypes"
                                    value="{{ $type }}"
                                    class="rounded border-zinc-300 dark:border-zinc-600"
                                >
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $type }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-zinc-500 mt-1">Leave empty to allow all event types.</p>
                </div>

                <div class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        wire:model="isEnabled"
                        id="isEnabled"
                        class="rounded border-zinc-300 dark:border-zinc-600"
                    >
                    <core:label for="isEnabled" class="!mb-0">Enable this endpoint</core:label>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-4">
                <core:button wire:click="cancelForm" variant="ghost">
                    Cancel
                </core:button>
                <core:button wire:click="save" variant="primary">
                    {{ $editingId ? 'Update' : 'Create' }} Endpoint
                </core:button>
            </div>
        </div>
    </core:modal>

    {{-- Delete Confirmation Modal --}}
    <core:modal wire:model.live="deletingUuid" class="max-w-md">
        <div class="space-y-4">
            <core:heading size="lg">Delete webhook endpoint?</core:heading>
            <core:text>
                This action cannot be undone. The endpoint will be permanently removed and will no longer receive webhooks.
            </core:text>

            <div class="flex justify-end gap-3">
                <core:button wire:click="cancelDelete" variant="ghost">
                    Cancel
                </core:button>
                <core:button wire:click="delete" variant="danger">
                    Delete
                </core:button>
            </div>
        </div>
    </core:modal>

    {{-- Secret Display Modal --}}
    <core:modal wire:model.live="showingSecretUuid" class="max-w-lg">
        <div class="space-y-4">
            <core:heading size="lg">Webhook Secret</core:heading>
            <core:text>
                Use this secret to verify webhook signatures. Keep it safe and do not share it publicly.
            </core:text>

            @if($revealedSecret)
                <div class="bg-zinc-100 dark:bg-zinc-800 rounded-lg p-4">
                    <code class="text-sm break-all text-zinc-700 dark:text-zinc-300">{{ $revealedSecret }}</code>
                </div>
            @endif

            <div class="flex justify-between items-center pt-4">
                <core:button wire:click="regenerateSecret('{{ $showingSecretUuid }}')" variant="outline" icon="arrow-path">
                    Regenerate
                </core:button>
                <core:button wire:click="hideSecret" variant="primary">
                    Done
                </core:button>
            </div>
        </div>
    </core:modal>
</div>

@script
<script>
    $wire.on('copy-to-clipboard', (event) => {
        navigator.clipboard.writeText(event.text);
    });
</script>
@endscript
