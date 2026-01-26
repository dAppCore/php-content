<div>
    <section class="py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <!-- Header -->
            <div class="text-center mb-12">
                <h1 class="text-3xl md:text-4xl font-bold text-slate-100 mb-4">Help Centre</h1>
                <p class="text-slate-400">
                    Guides and documentation for {{ $workspace['name'] ?? 'Host UK' }}
                </p>
            </div>

            <!-- Articles Grid -->
            @if(!empty($articles))
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($articles as $article)
                        <a href="/help/{{ $article['slug'] }}" class="group block bg-slate-800/30 border border-slate-700/50 rounded-xl p-6 hover:border-slate-600/50 transition" wire:navigate>
                            <div class="w-12 h-12 rounded-lg bg-violet-500/20 flex items-center justify-center mb-4">
                                <i class="fa-solid fa-file-lines text-violet-400"></i>
                            </div>
                            <h3 class="font-semibold text-lg text-slate-200 group-hover:text-white transition mb-2">
                                {{ $article['title']['rendered'] ?? 'Untitled' }}
                            </h3>
                            @if(isset($article['excerpt']['rendered']))
                                <p class="text-sm text-slate-400 line-clamp-2">
                                    {!! strip_tags($article['excerpt']['rendered']) !!}
                                </p>
                            @endif
                        </a>
                    @endforeach
                </div>
            @else
                <div class="text-center py-16">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-slate-800 flex items-center justify-center">
                        <i class="fa-solid fa-book text-2xl text-slate-500"></i>
                    </div>
                    <p class="text-slate-400">Help articles coming soon.</p>
                </div>
            @endif
        </div>
    </section>
</div>
