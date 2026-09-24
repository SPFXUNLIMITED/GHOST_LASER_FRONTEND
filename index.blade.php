@php
    $contactStatus     = request()->get('status', '');
    $contactMessage    = trim((string) request()->get('contact_message', ''));
    $bookTechnicianUrl = 'book_a_technician.php?step=2';
    $pageDescription   = 'Ghost Laser — CO2 laser cutting machines, Reci, Yongli, and Cloudray tubes and parts. Local pickup and nationwide shipping.';
@endphp

<x-shop::layouts :has-header="true" :has-feature="false" :has-footer="true">
    <x-slot:title>Ghost Laser | CO2 Laser Machines & Parts</x-slot>

    @push('meta')
        <meta name="description" content="Ghost Laser — manufacturer of CO2 laser cutting machines and official distributor of Reci, Yongli, and Cloudray laser tubes and parts. Local pickup and nationwide shipping.">
    @endpush

    {{-- HERO --}}
    <section class="relative overflow-hidden bg-zinc-950 bg-grid-pattern text-zinc-100">
        <div class="container mx-auto px-6 py-24 sm:py-32">
            <div class="max-w-3xl">
                <span class="inline-block text-xs font-medium tracking-widest uppercase text-cyan-400 mb-4">
                    Official Distributor — Reci · Yongli · Cloudray
                </span>
                <h1 class="text-4xl sm:text-6xl font-black tracking-tight leading-tight">
                    Ghost Laser machines.<br>
                    <span class="text-cyan-400 glow-cyan">Built to cut.</span>
                </h1>
                <p class="mt-6 text-lg text-zinc-400 max-w-2xl">
                    CO2 laser cutters, high-power tubes, and every part in between.
                    Official distributor for Reci, Yongli, and Cloudray — the tubes the pros run.
                </p>
                <div class="mt-8 flex flex-col sm:flex-row gap-4">
                    <a href="{{ route('shop.product_or_category.index', ['category_slug' => 'machines']) }}"
                       class="inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-bold text-base px-7 py-3.5 rounded-md transition-all btn-glow">
                        Shop Machines
                    </a>
                    <a href="{{ route('shop.product_or_category.index', ) }}"
                       class="inline-flex items-center justify-center gap-2 border border-zinc-700 hover:border-cyan-500 text-zinc-100 font-bold text-base px-7 py-3.5 rounded-md transition-all">
                        Shop Tubes & Parts
                    </a>
                </div>
                <p class="mt-6 text-sm text-zinc-500">
                    Local pickup available · Ships nationwide
                </p>
            </div>
        </div>
    </section>

    {{-- BRAND STRIP --}}
    <section class="bg-zinc-900 border-y border-zinc-800">
        <div class="container mx-auto px-6 py-8">
            <p class="text-center text-xs uppercase tracking-widest text-zinc-500 mb-4">Official Distributor</p>
            <div class="flex flex-wrap items-center justify-center gap-x-10 gap-y-4 text-zinc-300 font-semibold text-lg">
                <span>Reci</span>
                <span class="text-zinc-700">|</span>
                <span>Yongli</span>
                <span class="text-zinc-700">|</span>
                <span>Cloudray</span>
            </div>
        </div>
    </section>

    {{-- FEATURED PRODUCTS --}}
    <section class="bg-zinc-950 py-20">
        <div class="container mx-auto px-6">
            <div class="flex items-end justify-between mb-10">
                <div>
                    <span class="text-xs font-medium tracking-widest uppercase text-cyan-400">In Stock</span>
                    <h2 class="text-3xl sm:text-4xl font-black tracking-tight mt-2">Featured Products</h2>
                </div>
                <a href="{{ route('shop.product_or_category.index') }}" class="text-sm text-cyan-400 hover:text-cyan-300 font-medium">
                    View all →
                </a>
            </div>
            <x-shop::products.carousel :count="8" :title="false" />
        </div>
    </section>

    {{-- WHY US --}}
    <section class="bg-zinc-900 py-20">
        <div class="container mx-auto px-6">
            <h2 class="text-3xl sm:text-4xl font-black tracking-tight text-center mb-12">Why shops run Ghost Laser</h2>
            <div class="grid sm:grid-cols-3 gap-8">
                <div class="glow-box rounded-lg p-8 bg-zinc-950">
                    <div class="w-10 h-10 rounded-md bg-cyan-500/10 flex items-center justify-center mb-4">
                        <span class="text-cyan-400 text-xl">⚡</span>
                    </div>
                    <h3 class="font-bold text-lg mb-2">Real Specs, No Guesswork</h3>
                    <p class="text-zinc-400 text-sm">Tube wattage, lens sizes, and machine ratings listed straight — the numbers that matter for your cut.</p>
                </div>
                <div class="glow-box rounded-lg p-8 bg-zinc-950">
                    <div class="w-10 h-10 rounded-md bg-cyan-500/10 flex items-center justify-center mb-4">
                        <span class="text-cyan-400 text-xl">🚚</span>
                    </div>
                    <h3 class="font-bold text-lg mb-2">Pickup or Ship</h3>
                    <p class="text-zinc-400 text-sm">Local pickup for same-day parts, or we ship nationwide. Your call.</p>
                </div>
                <div class="glow-box rounded-lg p-8 bg-zinc-950">
                    <div class="w-10 h-10 rounded-md bg-cyan-500/10 flex items-center justify-center mb-4">
                        <span class="text-cyan-400 text-xl">🔧</span>
                    </div>
                    <h3 class="font-bold text-lg mb-2">Parts for the Machines You Run</h3>
                    <p class="text-zinc-400 text-sm">Tubes, lenses, mirrors, power supplies — everything between the machine and the cut.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="bg-zinc-950 bg-grid-pattern py-20">
        <div class="container mx-auto px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-black tracking-tight">
                Need a tube or a full machine?
            </h2>
            <p class="mt-4 text-zinc-400 max-w-xl mx-auto">
                Tell us what you're cutting and we'll point you at the right setup.
            </p>
            <a href="{{ route('shop.product_or_category.index') }}"
               class="inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-bold text-base px-7 py-3.5 rounded-md transition-all btn-glow mt-8">
                Browse the Catalog
            </a>
        </div>
    </section>
</x-shop::layouts>