<div>
    <section class="py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <!-- Header -->
            <div class="text-center mb-12">
                <h1 class="text-3xl md:text-4xl font-bold text-slate-100 mb-4">Blog</h1>
                <p class="text-slate-400">
                    Latest posts from {{ $workspace['name'] ?? 'Host UK' }}
                </p>
            </div>

            <!-- Posts Grid -->
            @if(!empty($posts))
                <div class="grid md:grid-cols-2 gap-8 mb-12">
                    @foreach($posts as $post)
                        <article class="group bg-slate-800/30 border border-slate-700/50 rounded-xl overflow-hidden hover:border-slate-600/50 transition">
                            <a href="/blog/{{ $post['slug'] }}" class="block" wire:navigate>
                                <!-- Featured Image -->
                                @if(isset($post['_embedded']['wp:featuredmedia'][0]))
                                    <div class="aspect-video bg-slate-800">
                                        <img
                                            src="{{ $post['_embedded']['wp:featuredmedia'][0]['source_url'] }}"
                                            alt="{{ e($post['title']['rendered'] ?? '') }}"
                                            class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                                        >
                                    </div>
                                @else
                                    <div class="aspect-video bg-slate-800 flex items-center justify-center">
                                        <i class="fa-solid fa-image text-4xl text-slate-600"></i>
                                    </div>
                                @endif

                                <div class="p-6">
                                    <!-- Meta -->
                                    <div class="flex items-center gap-3 text-sm text-slate-500 mb-3">
                                        <time datetime="{{ $post['date'] }}">
                                            {{ \Carbon\Carbon::parse($post['date'])->format('M j, Y') }}
                                        </time>
                                    </div>

                                    <!-- Title -->
                                    <h2 class="font-semibold text-xl text-slate-200 group-hover:text-white transition mb-3">
                                        {{ $post['title']['rendered'] ?? 'Untitled' }}
                                    </h2>

                                    <!-- Excerpt -->
                                    @if(isset($post['excerpt']['rendered']))
                                        <p class="text-slate-400 line-clamp-3">
                                            {!! strip_tags($post['excerpt']['rendered']) !!}
                                        </p>
                                    @endif

                                    <!-- Read More -->
                                    <div class="mt-4 flex items-center gap-2 text-violet-400 text-sm font-medium">
                                        Read More<span class="sr-only">: {{ $post['title']['rendered'] ?? 'Untitled' }}</span>
                                        <i class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </a>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="text-center py-16">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-slate-800 flex items-center justify-center">
                        <i class="fa-solid fa-pen-to-square text-2xl text-slate-500"></i>
                    </div>
                    <p class="text-slate-400">No posts yet. Check back soon.</p>
                </div>
            @endif
        </div>
    </section>
</div>
