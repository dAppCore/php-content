<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <core:heading size="xl">Content Search</core:heading>
            <core:subheading>
                Search across all your content items with full-text search.
            </core:subheading>
        </div>
    </div>

    {{-- Search Bar --}}
    <div class="flex items-center gap-4">
        <div class="flex-1">
            <core:input
                wire:model.live.debounce.300ms="query"
                type="search"
                placeholder="Search content by title, body, or slug..."
                icon="magnifying-glass"
                autofocus
            />
        </div>

        <core:button
            wire:click="toggleFilters"
            variant="{{ $showFilters ? 'primary' : 'outline' }}"
            icon="funnel"
        >
            Filters
            @if($this->activeFilterCount() > 0)
                <flux:badge color="blue" size="sm" class="ml-1">{{ $this->activeFilterCount() }}</flux:badge>
            @endif
        </core:button>
    </div>

    {{-- Filters Panel --}}
    @if($showFilters)
        <flux:card class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                {{-- Type Filter --}}
                <div>
                    <core:label for="type">Content Type</core:label>
                    <core:select wire:model.live="type" id="type">
                        <option value="">All types</option>
                        <option value="post">Posts</option>
                        <option value="page">Pages</option>
                    </core:select>
                </div>

                {{-- Status Filter --}}
                <div>
                    <core:label for="status">Status</core:label>
                    <core:select wire:model.live="status" id="status">
                        <option value="">All statuses</option>
                        <option value="publish">Published</option>
                        <option value="draft">Draft</option>
                        <option value="pending">Pending</option>
                        <option value="future">Scheduled</option>
                        <option value="private">Private</option>
                    </core:select>
                </div>

                {{-- Category Filter --}}
                <div>
                    <core:label for="category">Category</core:label>
                    <core:select wire:model.live="category" id="category">
                        <option value="">All categories</option>
                        @foreach($this->categories as $cat)
                            <option value="{{ $cat->slug }}">{{ $cat->name }}</option>
                        @endforeach
                    </core:select>
                </div>

                {{-- Date From --}}
                <div>
                    <core:label for="dateFrom">From Date</core:label>
                    <core:input
                        wire:model.live="dateFrom"
                        type="date"
                        id="dateFrom"
                    />
                </div>

                {{-- Date To --}}
                <div>
                    <core:label for="dateTo">To Date</core:label>
                    <core:input
                        wire:model.live="dateTo"
                        type="date"
                        id="dateTo"
                    />
                </div>
            </div>

            @if($this->hasActiveFilters())
                <div class="mt-4 flex justify-end">
                    <core:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">
                        Clear filters
                    </core:button>
                </div>
            @endif
        </flux:card>
    @endif

    {{-- Results --}}
    @if(strlen(trim($query)) >= 2)
        @if($this->results && $this->results->count() > 0)
            {{-- Results Header --}}
            <div class="flex items-center justify-between text-sm text-zinc-500">
                <span>
                    Found {{ $this->results->total() }} result{{ $this->results->total() !== 1 ? 's' : '' }}
                    for "{{ $query }}"
                </span>
                <span class="text-xs">
                    Using: {{ ucfirst(str_replace('_', ' ', $this->searchBackend)) }}
                </span>
            </div>

            {{-- Results List --}}
            <div class="space-y-3">
                @foreach($this->results as $item)
                    <flux:card
                        wire:click="viewContent({{ $item->id }})"
                        class="p-4 cursor-pointer hover:border-blue-300 dark:hover:border-blue-700 transition-colors"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                {{-- Title and Type --}}
                                <div class="flex items-center gap-2 mb-1">
                                    <h3 class="font-medium text-zinc-900 dark:text-white truncate">
                                        {{ $item->title }}
                                    </h3>
                                    <flux:badge color="{{ $item->type_color }}" size="sm">
                                        {{ ucfirst($item->type) }}
                                    </flux:badge>
                                    <flux:badge color="{{ $item->status_color }}" size="sm">
                                        {{ ucfirst($item->status) }}
                                    </flux:badge>
                                </div>

                                {{-- Slug --}}
                                <div class="text-sm text-zinc-500 dark:text-zinc-400 mb-2">
                                    <code class="text-xs bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 rounded">
                                        /{{ $item->slug }}
                                    </code>
                                </div>

                                {{-- Excerpt --}}
                                @if($item->excerpt)
                                    <p class="text-sm text-zinc-600 dark:text-zinc-300 line-clamp-2">
                                        {{ Str::limit(strip_tags($item->excerpt), 200) }}
                                    </p>
                                @elseif($item->content_html || $item->content_markdown)
                                    <p class="text-sm text-zinc-600 dark:text-zinc-300 line-clamp-2">
                                        {{ Str::limit(strip_tags($item->content_html ?? $item->content_markdown), 200) }}
                                    </p>
                                @endif

                                {{-- Meta --}}
                                <div class="flex items-center gap-4 mt-2 text-xs text-zinc-500">
                                    @if($item->author)
                                        <span class="flex items-center gap-1">
                                            <flux:icon name="user" class="size-3" />
                                            {{ $item->author->name }}
                                        </span>
                                    @endif
                                    @if($item->categories->count() > 0)
                                        <span class="flex items-center gap-1">
                                            <flux:icon name="folder" class="size-3" />
                                            {{ $item->categories->pluck('name')->join(', ') }}
                                        </span>
                                    @endif
                                    <span class="flex items-center gap-1">
                                        <flux:icon name="calendar" class="size-3" />
                                        {{ $item->updated_at->diffForHumans() }}
                                    </span>
                                </div>
                            </div>

                            {{-- Relevance Score --}}
                            @if($item->getAttribute('relevance_score'))
                                <div class="flex-shrink-0 text-right">
                                    <div class="text-xs text-zinc-400">Relevance</div>
                                    <div class="text-lg font-semibold text-blue-500">
                                        {{ $item->getAttribute('relevance_score') }}
                                    </div>
                                </div>
                            @endif
                        </div>
                    </flux:card>
                @endforeach
            </div>

            {{-- Pagination --}}
            <div class="mt-6">
                {{ $this->results->links() }}
            </div>
        @elseif($this->results && $this->results->count() === 0)
            {{-- No Results --}}
            <flux:card class="p-12">
                <div class="flex flex-col items-center justify-center text-center">
                    <div class="w-16 h-16 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mb-4">
                        <flux:icon name="magnifying-glass" class="size-8 text-zinc-400" />
                    </div>
                    <flux:heading size="lg">No results found</flux:heading>
                    <flux:subheading class="mt-1">
                        No content matches "{{ $query }}"
                        @if($this->hasActiveFilters())
                            with the current filters
                        @endif
                    </flux:subheading>
                    @if($this->hasActiveFilters())
                        <core:button wire:click="clearFilters" variant="outline" class="mt-4" icon="x-mark">
                            Clear filters
                        </core:button>
                    @endif
                </div>
            </flux:card>
        @endif
    @else
        {{-- Empty State / Recent Content --}}
        <flux:card class="p-12">
            <div class="flex flex-col items-center justify-center text-center">
                <div class="w-16 h-16 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center mb-4">
                    <flux:icon name="magnifying-glass" class="size-8 text-blue-500" />
                </div>
                <flux:heading size="lg">Search your content</flux:heading>
                <flux:subheading class="mt-1">
                    Enter at least 2 characters to search across titles, content, and slugs.
                </flux:subheading>
            </div>
        </flux:card>

        {{-- Recent Content --}}
        @if($this->recentContent->count() > 0)
            <div>
                <core:heading size="sm" class="mb-3">Recent Content</core:heading>
                <div class="space-y-2">
                    @foreach($this->recentContent as $item)
                        <div
                            wire:click="viewContent({{ $item->id }})"
                            class="flex items-center justify-between p-3 rounded-lg border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:border-blue-300 dark:hover:border-blue-700 transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <flux:badge color="{{ $item->type_color }}" size="sm">
                                    {{ ucfirst($item->type) }}
                                </flux:badge>
                                <span class="font-medium text-zinc-900 dark:text-white">
                                    {{ Str::limit($item->title, 60) }}
                                </span>
                            </div>
                            <span class="text-xs text-zinc-500">
                                {{ $item->updated_at->diffForHumans() }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
