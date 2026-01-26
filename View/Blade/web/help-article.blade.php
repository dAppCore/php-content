<div>
    <article class="py-16">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <!-- Back link -->
            <a href="/help" class="inline-flex items-center gap-2 text-sm text-slate-400 hover:text-white transition mb-8" wire:navigate>
                <i class="fa-solid fa-arrow-left"></i>
                Back to Help Centre
            </a>

            <!-- Header -->
            <header class="mb-8">
                <div class="w-12 h-12 rounded-lg bg-violet-500/20 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-file-lines text-violet-400"></i>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold text-slate-100 mb-4">
                    {{ $article['title']['rendered'] ?? 'Untitled' }}
                </h1>

                @if(isset($article['modified']))
                    <div class="text-sm text-slate-500">
                        Last updated: {{ \Carbon\Carbon::parse($article['modified'])->format('F j, Y') }}
                    </div>
                @endif
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
                {!! $article['content']['rendered'] ?? '' !!}
            </div>

            <!-- Footer -->
            <footer class="mt-12 pt-8 border-t border-slate-700/50">
                <a href="/help" class="inline-flex items-center gap-2 text-violet-400 hover:text-violet-300 transition" wire:navigate>
                    <i class="fa-solid fa-arrow-left"></i>
                    Back to all articles
                </a>
            </footer>
        </div>
    </article>
</div>
