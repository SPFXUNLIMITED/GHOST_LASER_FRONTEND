@php
    $channel = core()->getCurrentChannel();

    $hasProductCarousel = collect($sections)
        ->contains(fn ($section) => $section->type === \Webkul\Theme\Enums\SectionTypeEnum::PRODUCT_CAROUSEL->value);
@endphp

<!-- SEO Meta Content -->
@push ('meta')
    <meta
        name="title"
        content="{{ $channel->home_seo['meta_title'] ?? 'Ghost Laser | Laser Cutters, Laser Tubes & Laser Parts' }}"
    />

    <meta
        name="description"
        content="{{ $channel->home_seo['meta_description'] ?? 'Ghost Laser sells Ghost Laser cutting machines and distributes Yongli and Reci laser tubes, CloudRay laser parts, lenses, mirrors, fume extractors and air pumps.' }}"
    />

    <meta
        name="keywords"
        content="{{ $channel->home_seo['meta_keywords'] ?? 'laser cutter, laser engraver, Yongli laser tube, Reci laser tube, CloudRay parts, laser lens, laser mirror, fume extractor, air pump' }}"
    />
@endPush

@push('scripts')
    @if(! empty($categories))
        <script>
            localStorage.setItem('categories', JSON.stringify(@json($categories)));
        </script>
    @endif
@endpush

<x-shop::layouts>
    <!-- Page Title -->
    <x-slot:title>
        {{ $channel->home_seo['meta_title'] ?? 'Ghost Laser | Laser Cutters, Laser Tubes & Laser Parts' }}
    </x-slot>

    <!-- Ghost Laser Hero -->
    <section class="bg-grid-pattern relative overflow-hidden bg-zinc-950">
        <div class="container max-lg:px-8 max-sm:!px-4">
            <div class="max-w-4xl py-24 max-md:py-14 max-sm:py-10">
                <div class="mb-8 inline-flex items-center gap-2 rounded-full border border-cyan-500/30 bg-zinc-900 px-4 py-1.5 max-md:mb-5">
                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-cyan-400"></span>

                    <span class="text-xs font-medium uppercase tracking-wider text-cyan-400">
                        Machines &middot; Tubes &middot; Parts
                    </span>
                </div>

                <h1 class="font-dmserif text-6xl leading-tight text-white max-md:text-4xl max-sm:text-3xl">
                    Ghost Laser sells the machines,
                    <span class="glow-cyan text-cyan-400">tubes and parts</span>
                    your shop runs on.
                </h1>

                <p class="mt-6 max-w-2xl text-lg leading-relaxed text-zinc-400 max-md:mt-4 max-md:text-base max-sm:text-sm">
                    We build and sell Ghost Laser cutting machines, and we distribute Yongli laser tubes,
                    Reci laser tubes and CloudRay laser parts — plus a full catalog of laser lenses,
                    laser mirrors, fume extractors, air pumps and everything else on the bench.
                    Service and repair stays with our sister company, so we can keep our focus on stock,
                    specs and shipping.
                </p>

                <div class="mt-10 flex flex-wrap gap-4 max-md:mt-6">
                    <a
                        href="{{ route('shop.search.index') }}"
                        class="primary-button btn-glow rounded-2xl px-11 py-3 text-base max-md:rounded-lg max-md:px-8 max-md:py-2.5"
                    >
                        Shop the catalog
                    </a>

                    <a
                        href="{{ route('shop.home.index') }}#ghost-laser-brands"
                        class="secondary-button rounded-2xl px-11 py-3 text-base max-md:rounded-lg max-md:px-8 max-md:py-2.5"
                    >
                        Brands we distribute
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Loop over the storefront sections -->
    @foreach ($sections as $section)
        @php ($data = $section->options) @endphp

        {{-- The layout marks the types it draws on every page, so this page marks the rest. --}}
        @php ($marks = ($preview ?? false) && ! $section->getTypeInstance()?->rendersInLayout())

        @if ($marks)
            <div
                data-section-id="{{ $section->id }}"
                data-section-name="{{ $section->name }}"
            >
        @endif

        <!-- Static Content -->
        @switch ($section->type)
            @case (\Webkul\Theme\Enums\SectionTypeEnum::IMAGE_CAROUSEL->value)
                <!-- Image Carousel -->
                <x-shop::carousel
                    :options="$section->getTypeInstance()?->sanitize((array) $data) ?? $data"
                    aria-label="{{ trans('shop::app.home.index.image-carousel') }}"
                />

                @break
            @case (\Webkul\Theme\Enums\SectionTypeEnum::STATIC_CONTENT->value)
                <!-- Push Style -->
                @if (! empty($data['css']))
                    @push ('styles')
                        <style>
                            {!! $data['css'] !!}
                        </style>
                    @endpush
                @endif

                <!-- Render HTML -->
                @if (! empty($data['html']))
                    {!! $data['html'] !!}
                @endif

                @break
            @case (\Webkul\Theme\Enums\SectionTypeEnum::CATEGORY_CAROUSEL->value)
                <!-- Categories carousel -->
                <x-shop::categories.carousel
                    :title="$data['title'] ?? ''"
                    :src="route('shop.api.categories.index', $data['filters'] ?? [])"
                    :navigation-link="route('shop.home.index')"
                    aria-label="{{ trans('shop::app.home.index.categories-carousel') }}"
                />

                @break
            @case (\Webkul\Theme\Enums\SectionTypeEnum::PRODUCT_CAROUSEL->value)
                <!-- Product Carousel -->
                <x-shop::products.carousel
                    :title="$data['title'] ?? ''"
                    :src="route('shop.api.products.index', $data['filters'] ?? [])"
                    :navigation-link="route('shop.search.index', $data['filters'] ?? [])"
                    aria-label="{{ trans('shop::app.home.index.product-carousel') }}"
                />

                @break
        @endswitch

        @if ($marks)
            </div>
        @endif
    @endforeach

    {{--
        Ghost Laser's own carousels. They stand in until the merchant adds product
        carousel sections of their own in the Appearance editor, so the homepage is
        never empty and the editor's sections always win.
    --}}
    @unless ($hasProductCarousel)
        <!-- Featured Ghost Laser Machines -->
        <x-shop::products.carousel
            title="Featured Ghost Laser Machines"
            :src="route('shop.api.products.index', ['featured' => 1, 'limit' => 10])"
            :navigation-link="route('shop.search.index', ['featured' => 1])"
            aria-label="{{ trans('shop::app.home.index.product-carousel') }}"
        />

        <!-- Laser Tubes -->
        <x-shop::products.carousel
            title="Yongli & Reci Laser Tubes"
            :src="route('shop.api.products.index', ['query' => 'laser tube', 'limit' => 10])"
            :navigation-link="route('shop.search.index', ['query' => 'laser tube'])"
            aria-label="{{ trans('shop::app.home.index.product-carousel') }}"
        />

        <!-- Laser Parts -->
        <x-shop::products.carousel
            title="Laser Parts & Accessories"
            :src="route('shop.api.products.index', ['new' => 1, 'limit' => 10])"
            :navigation-link="route('shop.search.index', ['new' => 1])"
            aria-label="{{ trans('shop::app.home.index.product-carousel') }}"
        />
    @endunless

    <!-- Brands we distribute -->
    <section
        id="ghost-laser-brands"
        class="container mt-20 max-lg:px-8 max-md:mt-10 max-sm:!px-4"
    >
        <h2 class="font-dmserif text-3xl text-white max-md:text-2xl max-sm:text-xl">
            Brands we distribute
        </h2>

        <div class="mt-10 grid grid-cols-3 gap-8 max-md:mt-5 max-md:grid-cols-1 max-md:gap-5">
            <div class="glow-box rounded-xl border border-zinc-800 bg-zinc-900/40 p-8 max-md:p-5">
                <h3 class="text-xl font-medium text-cyan-400 max-sm:text-base">Yongli laser tubes</h3>

                <p class="mt-2.5 text-sm text-zinc-400">
                    Authorized distributor for the full Yongli range, from entry-level glass tubes to
                    long-life high-power tubes for production cutting.
                </p>
            </div>

            <div class="glow-box rounded-xl border border-zinc-800 bg-zinc-900/40 p-8 max-md:p-5">
                <h3 class="text-xl font-medium text-cyan-400 max-sm:text-base">Reci laser tubes</h3>

                <p class="mt-2.5 text-sm text-zinc-400">
                    Genuine Reci W-series and S-series tubes, matched to the right power supply and
                    shipped with the wattage and lifetime specs stated up front.
                </p>
            </div>

            <div class="glow-box rounded-xl border border-zinc-800 bg-zinc-900/40 p-8 max-md:p-5">
                <h3 class="text-xl font-medium text-cyan-400 max-sm:text-base">CloudRay laser parts</h3>

                <p class="mt-2.5 text-sm text-zinc-400">
                    CloudRay motion, control and optics components stocked alongside our own catalog so
                    a whole machine build ships from one order.
                </p>
            </div>
        </div>
    </section>

    <!-- What we stock -->
    <section class="container mt-20 max-lg:px-8 max-md:mt-10 max-sm:!px-4">
        <h2 class="font-dmserif text-3xl text-white max-md:text-2xl max-sm:text-xl">
            What we stock
        </h2>

        <p class="mt-2.5 text-sm text-zinc-400">
            Laser lenses, laser mirrors, fume extractors, air pumps and many more parts for every
            machine on your floor.
        </p>

        <div class="mt-10 grid grid-cols-4 gap-8 max-md:mt-5 max-md:grid-cols-2 max-md:gap-5">
            <div class="glow-box rounded-xl border border-zinc-800 bg-zinc-900/40 p-6 max-md:p-4">
                <p class="text-base font-medium text-white">Laser lenses</p>
                <p class="mt-2.5 text-sm text-zinc-400">Focus lenses in every common diameter and focal length.</p>
            </div>

            <div class="glow-box rounded-xl border border-zinc-800 bg-zinc-900/40 p-6 max-md:p-4">
                <p class="text-base font-medium text-white">Laser mirrors</p>
                <p class="mt-2.5 text-sm text-zinc-400">Molybdenum and silicon mirrors, plus complete mirror mounts.</p>
            </div>

            <div class="glow-box rounded-xl border border-zinc-800 bg-zinc-900/40 p-6 max-md:p-4">
                <p class="text-base font-medium text-white">Fume extractors</p>
                <p class="mt-2.5 text-sm text-zinc-400">Extraction units and filters sized to your cutting area.</p>
            </div>

            <div class="glow-box rounded-xl border border-zinc-800 bg-zinc-900/40 p-6 max-md:p-4">
                <p class="text-base font-medium text-white">Air pumps</p>
                <p class="mt-2.5 text-sm text-zinc-400">Air assist pumps, regulators, fittings and hose kits.</p>
            </div>
        </div>
    </section>

    @if ($preview ?? false)
        @include('shop::home.preview-bridge')
    @endif
</x-shop::layouts>
