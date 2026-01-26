<div>
    <article class="py-16">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <!-- Back link -->
            <a href="/blog" class="inline-flex items-center gap-2 text-sm text-slate-400 hover:text-white transition mb-8" wire:navigate>
                <i class="fa-solid fa-arrow-left"></i>
                Back to Blog
            </a>

            <!-- Featured Image -->
            @if(isset($post['_embedded']['wp:featuredmedia'][0]))
                <div class="aspect-video bg-slate-800 rounded-xl overflow-hidden mb-8">
                    <img
                        src="{{ $post['_embedded']['wp:featuredmedia'][0]['source_url'] }}"
                        alt="{{ e($post['title']['rendered'] ?? '') }}"
                        class="w-full h-full object-cover"
                    >
                </div>
            @endif

            <!-- Header -->
            <header class="mb-8">
                <h1 class="text-3xl md:text-4xl font-bold text-slate-100 mb-4">
                    {{ $post['title']['rendered'] ?? 'Untitled' }}
                </h1>

                <div class="flex items-center gap-4 text-sm text-slate-500">
                    <time datetime="{{ $post['date'] }}">
                        {{ \Carbon\Carbon::parse($post['date'])->format('F j, Y') }}
                    </time>
                    @if(isset($post['_embedded']['author'][0]))
                        <span class="text-slate-600">|</span>
                        <span>By {{ $post['_embedded']['author'][0]['name'] }}</span>
                    @endif
                </div>
            </header>

            <!-- Content -->
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
                {!! $post['content']['rendered'] ?? '' !!}
            </div>

            <!-- Footer -->
            <footer class="mt-12 pt-8 border-t border-slate-700/50">
                <a href="/blog" class="inline-flex items-center gap-2 text-violet-400 hover:text-violet-300 transition" wire:navigate>
                    <i class="fa-solid fa-arrow-left"></i>
                    Back to all posts
                </a>
            </footer>
        </div>
    </article>
</div>
