<div>
    {{-- Preview Banner --}}
    @unless($isPublished)
        <div class="sticky top-0 z-50 bg-amber-500 text-amber-900">
            <div class="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <i class="fa-solid fa-eye text-lg"></i>
                    <div>
                        <span class="font-semibold">Preview Mode</span>
                        <span class="mx-2">|</span>
                        <span class="text-sm">
                            Status:
                            <span class="font-medium">
                                {{ match($content['preview_status'] ?? 'draft') {
                                    'draft' => 'Draft',
                                    'pending' => 'Pending Review',
                                    'future' => 'Scheduled',
                                    'private' => 'Private',
                                    default => ucfirst($content['preview_status'] ?? 'draft')
                                } }}
                            </span>
                        </span>
                    </div>
                </div>
                @if($expiresIn)
                    <div class="text-sm">
                        <i class="fa-solid fa-clock mr-1"></i>
                        Link expires {{ $expiresIn }}
                    </div>
                @endif
            </div>
        </div>
    @endunless

    <article class="py-16">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            {{-- Back link --}}
            <a href="/blog" class="inline-flex items-center gap-2 text-sm text-slate-400 hover:text-white transition mb-8">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Blog
            </a>

            {{-- Featured Image --}}
            @if(isset($content['_embedded']['wp:featuredmedia'][0]))
                <div class="aspect-video bg-slate-800 rounded-xl overflow-hidden mb-8">
                    <img
                        src="{{ $content['_embedded']['wp:featuredmedia'][0]['source_url'] }}"
                        alt="{{ e($content['title']['rendered'] ?? '') }}"
                        class="w-full h-full object-cover"
                    >
                </div>
            @endif

            {{-- Header --}}
            <header class="mb-8">
                <h1 class="text-3xl md:text-4xl font-bold text-slate-100 mb-4">
                    {{ $content['title']['rendered'] ?? 'Untitled' }}
                </h1>

                <div class="flex items-center gap-4 text-sm text-slate-500">
                    @if(isset($content['date']))
                        <time datetime="{{ $content['date'] }}">
                            {{ \Carbon\Carbon::parse($content['date'])->format('F j, Y') }}
                        </time>
                    @endif
                    @if(isset($content['_embedded']['author'][0]))
                        <span class="text-slate-600">|</span>
                        <span>By {{ $content['_embedded']['author'][0]['name'] }}</span>
                    @endif
                </div>
            </header>

            {{-- Content --}}
            <div class="prose prose-invert prose-slate prose-lg max-w-none
                prose-headings:text-slate-100
                prose-p:text-slate-300
                prose-a:text-violet-400 prose-a:no-underline hover:prose-a:underline
                prose-strong:text-slate-200
                prose-code:text-violet-300 prose-code:bg-slate-800 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded
                prose-pre:bg-slate-800 prose-pre:border prose-pre:border-slate-700
                prose-blockquote:border-violet-500 prose-blockquote:text-slate-400
                prose-ul:text-slate-300 prose-ol:text-slate-300
                prose-li:marker:text-violet-400
            ">
                {!! $content['content']['rendered'] ?? '' !!}
            </div>

            {{-- Preview Footer --}}
            @unless($isPublished)
                <footer class="mt-12 pt-8 border-t border-slate-700/50">
                    <div class="bg-slate-800/50 rounded-lg p-4 text-center">
                        <p class="text-slate-400 text-sm">
                            <i class="fa-solid fa-info-circle mr-2"></i>
                            This is a preview. The content has not been published yet.
                        </p>
                    </div>
                </footer>
            @else
                <footer class="mt-12 pt-8 border-t border-slate-700/50">
                    <a href="/blog" class="inline-flex items-center gap-2 text-violet-400 hover:text-violet-300 transition">
                        <i class="fa-solid fa-arrow-left"></i>
                        Back to all posts
                    </a>
                </footer>
            @endunless
        </div>
    </article>
</div>
