<x-shop::layouts :has-header="true" :has-feature="false" :has-footer="true">
    <x-slot:title>
        Ghost Laser | Laser Machines & Parts
    </x-slot>

    @push('meta')
        <meta name="description" content="Ghost Laser — precision laser cutting machines, parts, and accessories. Shop the catalog.">
    @endpush

    @push('styles')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <style>
            .glow-cyan { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
            .glow-box { box-shadow: 0 0 0 1px rgba(6,182,212,0.2), 0 0 40px rgba(6,182,212,0.05); }
            .glow-box:hover { box-shadow: 0 0 0 1px rgba(6,182,212,0.5), 0 0 40px rgba(6,182,212,0.15); }
            .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
            .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
            .gradient-fade-bottom { background: linear-gradient(to bottom, transparent 60%, rgb(9,9,11) 100%); }
            .hero-grid {
                background-image: linear-gradient(rgba(6,182,212,0.04) 1px, transparent 1px),
                                  linear-gradient(90deg, rgba(6,182,212,0.04) 1px, transparent 1px);
                background-size: 60px 60px;
            }
        </style>
    @endpush

    {{-- HERO --}}
    <section class="relative min-h-screen flex items-center hero-grid overflow-hidden pt-16">
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <div class="w-96 h-96 rounded-full bg-cyan-500/5 blur-3xl"></div>
        </div>
        <div class="absolute bottom-0 left-0 right-0 h-48 gradient-fade-bottom pointer-events-none"></div>

        <div class="relative max-w-7xl mx-auto px-6 lg:px-8 py-24 lg:py-32">
            <div class="max-w-4xl">
                <div class="inline-flex items-center gap-2 bg-zinc-900 border border-cyan-500/30 rounded-full px-4 py-1.5 mb-8">
                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
                    <span class="text-xs text-cyan-400 font-medium tracking-wider uppercase">Laser Machines & Parts</span>
                </div>

                <h1 class="text-5xl sm:text-6xl lg:text-7xl font-black leading-tight tracking-tight mb-6">
                    Precision Laser<br>
                    <span class="text-cyan-400 glow-cyan">Machines & Parts.</span>
                </h1>

                <p class="text-lg sm:text-xl text-zinc-400 max-w-2xl mb-10 leading-relaxed">
                    Cutters, engravers, tubes, lenses, and accessories — built for shops that run hot. Fast shipping, real specs, no guesswork.
                </p>

                <div class="flex flex-col sm:flex-row gap-4">
                    <a href="/search" class="inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-bold text-base px-7 py-3.5 rounded-md transition-all btn-glow">
                        Shop the Catalog
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </a>
                    <a href="#categories" class="inline-flex items-center justify-center gap-2 bg-transparent border border-zinc-700 hover:border-zinc-500 text-white font-semibold text-base px-7 py-3.5 rounded-md transition-all hover:bg-zinc-900">
                        Browse Categories
                    </a>
                </div>

                <div class="mt-16 pt-10 border-t border-zinc-800/60 grid grid-cols-2 sm:grid-cols-3 gap-8">
                    <div>
                        <div class="text-3xl font-black text-white">500+</div>
                        <div class="text-sm text-zinc-500 mt-1">Parts in stock</div>
                    </div>
                    <div>
                        <div class="text-3xl font-black text-white">24h</div>
                        <div class="text-sm text-zinc-500 mt-1">Avg. ship time</div>
                    </div>
                    <div>
                        <div class="text-3xl font-black text-white">100%</div>
                        <div class="text-sm text-zinc-500 mt-1">Tested before ship</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- CATEGORIES --}}
    <section id="categories" class="py-24 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-14">
                <div class="inline-flex items-center gap-2 bg-zinc-900 border border-cyan-500/30 rounded-full px-4 py-1.5 mb-6">
                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-400"></span>
                    <span class="text-xs text-cyan-400 font-medium tracking-wider uppercase">Shop by Category</span>
                </div>
                <h2 class="text-4xl sm:text-5xl font-black tracking-tight">
                    Everything for the <span class="text-cyan-400 glow-cyan">Laser Shop</span>
                </h2>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($categories ?? [] as $category)
                    <a href="/{{ $category->slug }}" class="glow-box group rounded-xl border border-zinc-800 bg-zinc-900/40 p-8 transition-all hover:-translate-y-1">
                        <div class="w-12 h-12 rounded-lg bg-cyan-500/10 border border-cyan-500/30 flex items-center justify-center mb-5">
                            <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2 group-hover:text-cyan-400 transition-colors">{{ $category->name }}</h3>
                        <p class="text-sm text-zinc-500">{{ $category->products_count ?? 0 }} products</p>
                    </a>
                @endforeach

                @if (empty($categories))
                    <div class="col-span-full text-center py-12 text-zinc-500">
                        Categories will appear here once you add them in the admin.
                    </div>
                @endif
            </div>
        </div>
    </section>


    {{-- FEATURED PRODUCTS --}}
    <section class="py-24 bg-zinc-900/30">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="flex items-end justify-between mb-12">
                <div>
                    <h2 class="text-4xl font-black tracking-tight">
                        Featured <span class="text-cyan-400 glow-cyan">Machines</span>
                    </h2>
                    <p class="text-zinc-500 mt-2">Top sellers, freshly stocked.</p>
                </div>
                <a href="/search" class="hidden sm:inline-flex items-center gap-2 text-sm text-cyan-400 hover:text-cyan-300 transition-colors">
                    View all
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                </a>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                @foreach ($featured_products ?? [] as $product)
                    <x-shop::products.card :product="$product" />
                @endforeach

                @if (empty($featured_products))
                    <div class="col-span-full text-center py-12 text-zinc-500">
                        Add products in the admin and they'll show up here.
                    </div>
                @endif
            </div>
        </div>
    </section>
</x-shop::layouts>