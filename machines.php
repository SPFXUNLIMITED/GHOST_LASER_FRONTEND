<?php
$pageTitle       = 'Machines | Ghost Laser';
$pageDescription = 'Browse the Ghost Laser catalog of laser cutting and engraving machines with full specifications and pricing.';
$logoHref        = '/';
$extraHead       = <<<'HTML'
    <style>
        .glow-cyan  { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
        .glow-box   { box-shadow: 0 0 0 1px rgba(6,182,212,0.2), 0 0 40px rgba(6,182,212,0.05); }
        .glow-box:hover { box-shadow: 0 0 0 1px rgba(6,182,212,0.5), 0 0 40px rgba(6,182,212,0.15); }
        .btn-glow   { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .card-glass {
            background: linear-gradient(160deg, rgba(24,24,27,0.85) 0%, rgba(9,9,11,0.85) 100%);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }
    </style>
HTML;
$headerRight = <<<'HTML'
    <nav class="hidden md:flex items-center gap-8">
        <a href="/machines.php" class="text-sm text-white transition-colors">Machines</a>
        <a href="/#services" class="text-sm text-zinc-400 hover:text-white transition-colors">Services</a>
        <a href="/#contact"  class="text-sm text-zinc-400 hover:text-white transition-colors">Contact</a>
    </nav>
HTML;
$headerMobileMenu = '';

require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen hero-grid px-4 pt-28 pb-20">

        <!-- Background glow -->
        <div class="pointer-events-none fixed inset-0 flex items-start justify-center pt-32 overflow-hidden">
            <div class="w-[800px] h-[400px] rounded-full bg-cyan-500/4 blur-3xl"></div>
        </div>

        <div class="relative max-w-7xl mx-auto">
            <div class="mb-12 text-center">
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/30 bg-zinc-900/80 px-5 py-2 mb-6">
                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-400"></span>
                    <span class="text-xs font-semibold tracking-widest text-cyan-400 uppercase">Catalog</span>
                </div>
                <h1 class="text-4xl sm:text-5xl font-black tracking-tight text-white mb-3">
                    Laser <span class="text-cyan-400 glow-cyan">Machines</span>
                </h1>
                <p class="text-sm text-zinc-500">Full specifications, shipping details, and pricing for every machine we sell.</p>
            </div>

            <!-- Loading state -->
            <div id="machines-loading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <?php for ($i = 0; $i < 6; $i++): ?>
                <div class="card-glass glow-box rounded-xl border border-zinc-800 overflow-hidden animate-pulse">
                    <div class="aspect-[4/3] bg-zinc-800/60"></div>
                    <div class="p-6 space-y-3">
                        <div class="h-5 w-2/3 rounded bg-zinc-800/80"></div>
                        <div class="h-3 w-1/3 rounded bg-zinc-800/70"></div>
                        <div class="h-3 w-full rounded bg-zinc-800/60"></div>
                        <div class="h-3 w-5/6 rounded bg-zinc-800/60"></div>
                        <div class="h-10 w-full rounded-md bg-zinc-800/70 mt-4"></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Error state -->
            <div id="machines-error" class="hidden card-glass glow-box rounded-2xl border border-red-500/40 px-8 py-12 text-center">
                <h2 class="text-2xl font-black tracking-tight text-white mb-2">We couldn&rsquo;t load the catalog</h2>
                <p id="machines-error-message" class="text-sm text-zinc-400 mb-6">Please try again in a moment.</p>
                <button type="button" id="machines-retry"
                        class="inline-flex items-center justify-center gap-2 rounded-md bg-cyan-500 px-5 py-2.5 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 btn-glow">
                    Try Again
                </button>
            </div>

            <!-- Empty state -->
            <div id="machines-empty" class="hidden card-glass glow-box rounded-2xl border border-zinc-800 px-8 py-12 text-center">
                <h2 class="text-2xl font-black tracking-tight text-white mb-2">No machines available right now</h2>
                <p class="text-sm text-zinc-400 mb-6">Our catalog is being updated. Contact us and we&rsquo;ll help you find the right machine.</p>
                <a href="/#contact"
                   class="inline-flex items-center justify-center gap-2 rounded-md border border-cyan-500/40 bg-cyan-500/10 px-5 py-2.5 text-sm font-semibold text-cyan-300 transition-colors hover:border-cyan-400 hover:bg-cyan-500/20 hover:text-cyan-200">
                    Contact Us
                </a>
            </div>

            <!-- Catalog -->
            <div id="machines-grid" class="hidden grid gap-6 sm:grid-cols-2 lg:grid-cols-3"></div>

            <!-- Checkout error -->
            <div id="checkout-error" class="hidden mt-8 rounded-xl border border-red-900/60 bg-red-950/40 px-5 py-4 text-sm text-red-200" role="alert"></div>
        </div>
    </main>

    <script>
        (() => {
            const CATALOG_URL  = 'https://ghostlaser.com/api/machines.php';
            const CHECKOUT_URL = 'https://ghostlaser.com/api/checkout.php';

            const loadingEl  = document.getElementById('machines-loading');
            const errorEl    = document.getElementById('machines-error');
            const errorMsgEl = document.getElementById('machines-error-message');
            const emptyEl    = document.getElementById('machines-empty');
            const gridEl     = document.getElementById('machines-grid');
            const retryBtn   = document.getElementById('machines-retry');
            const checkoutErrorEl = document.getElementById('checkout-error');

            const priceFormatter = new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                maximumFractionDigits: 0,
            });

            const show = (el) => el.classList.remove('hidden');
            const hide = (el) => el.classList.add('hidden');

            const showOnly = (el) => {
                [loadingEl, errorEl, emptyEl, gridEl].forEach(hide);
                if (el === gridEl) {
                    gridEl.classList.remove('hidden');
                    gridEl.classList.add('grid');
                } else {
                    show(el);
                }
            };

            const text = (value) => {
                if (value === null || value === undefined) return '';
                return String(value);
            };

            const formatPrice = (price) => {
                const numeric = typeof price === 'string' ? Number(price) : price;
                if (numeric === null || numeric === undefined || Number.isNaN(numeric)) {
                    return null;
                }
                return priceFormatter.format(numeric);
            };

            const specRow = (label, value) => {
                if (!value) return null;
                const row = document.createElement('div');
                row.className = 'flex items-start justify-between gap-4 py-2 border-b border-zinc-800/60 last:border-b-0';

                const dt = document.createElement('span');
                dt.className = 'text-xs uppercase tracking-wide text-zinc-500 flex-shrink-0';
                dt.textContent = label;

                const dd = document.createElement('span');
                dd.className = 'text-xs text-zinc-300 text-right';
                dd.textContent = value;

                row.append(dt, dd);
                return row;
            };

            const combine = (metric, imperial) => {
                const parts = [text(metric).trim(), text(imperial).trim()].filter(Boolean);
                return parts.join(' / ');
            };

            const buildCard = (machine) => {
                const card = document.createElement('article');
                card.className = 'group card-glass glow-box flex flex-col rounded-xl border border-zinc-800 overflow-hidden transition-all duration-300';

                const photoUrl = text(machine.photo_url).trim()
                    || (Array.isArray(machine.photo_urls) && machine.photo_urls.length ? text(machine.photo_urls[0]).trim() : '');

                const media = document.createElement('div');
                media.className = 'relative aspect-[4/3] bg-zinc-950/70 flex items-center justify-center overflow-hidden border-b border-zinc-800/80';
                if (photoUrl) {
                    const img = document.createElement('img');
                    img.src = photoUrl;
                    img.alt = text(machine.name) || 'Ghost Laser machine';
                    img.loading = 'lazy';
                    img.className = 'w-full h-full object-cover transition-transform duration-500 group-hover:scale-105';
                    media.appendChild(img);
                } else {
                    const placeholder = document.createElement('span');
                    placeholder.className = 'text-xs uppercase tracking-widest text-zinc-600';
                    placeholder.textContent = 'Photo coming soon';
                    media.appendChild(placeholder);
                }
                card.appendChild(media);

                const body = document.createElement('div');
                body.className = 'flex flex-col flex-1 p-6';

                const name = document.createElement('h2');
                name.className = 'text-lg font-black tracking-tight text-white';
                name.textContent = text(machine.name);
                body.appendChild(name);

                const model = text(machine.model).trim();
                if (model) {
                    const modelEl = document.createElement('p');
                    modelEl.className = 'mt-0.5 text-xs font-semibold uppercase tracking-widest text-cyan-400';
                    modelEl.textContent = model;
                    body.appendChild(modelEl);
                }

                const description = text(machine.description).trim();
                if (description) {
                    const descEl = document.createElement('p');
                    descEl.className = 'mt-3 text-sm leading-relaxed text-zinc-400';
                    descEl.textContent = description;
                    body.appendChild(descEl);
                }

                const specs = document.createElement('div');
                specs.className = 'mt-5';
                const rows = [
                    specRow('Cutting area', combine(machine.cutting_area_metric, machine.cutting_area_imperial)),
                    specRow('Dimensions', combine(machine.dimensions_metric, machine.dimensions_imperial)),
                    specRow('Weight', combine(
                        machine.weight_kg ? `${machine.weight_kg} kg` : '',
                        machine.weight_lbs ? `${machine.weight_lbs} lbs` : ''
                    )),
                    specRow('Crate size', combine(machine.crate_dimensions_metric, machine.crate_dimensions_imperial)),
                    specRow('Crate weight', combine(
                        machine.crate_weight_kg ? `${machine.crate_weight_kg} kg` : '',
                        machine.crate_weight_lbs ? `${machine.crate_weight_lbs} lbs` : ''
                    )),
                ].filter(Boolean);
                rows.forEach((row) => specs.appendChild(row));
                if (rows.length) {
                    body.appendChild(specs);
                }

                const footer = document.createElement('div');
                footer.className = 'mt-6 pt-5 border-t border-zinc-800/60 flex items-center justify-between gap-4 mt-auto';

                const price = document.createElement('div');
                const formattedPrice = formatPrice(machine.price);
                if (formattedPrice) {
                    price.innerHTML = '';
                    const amount = document.createElement('p');
                    amount.className = 'text-xl font-black tracking-tight text-white';
                    amount.textContent = formattedPrice;
                    const currency = document.createElement('p');
                    currency.className = 'text-[11px] uppercase tracking-widest text-zinc-500';
                    currency.textContent = 'USD';
                    price.append(amount, currency);
                } else {
                    const quote = document.createElement('p');
                    quote.className = 'text-sm font-bold uppercase tracking-widest text-cyan-400 glow-cyan';
                    quote.textContent = 'Request a quote';
                    price.appendChild(quote);
                }
                footer.appendChild(price);

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'inline-flex items-center justify-center gap-2 rounded-md bg-cyan-500 px-5 py-2.5 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 btn-glow disabled:cursor-not-allowed disabled:opacity-60';
                button.textContent = 'Buy';
                button.addEventListener('click', () => startCheckout(machine, button));
                footer.appendChild(button);

                body.appendChild(footer);
                card.appendChild(body);
                return card;
            };

            const showCheckoutError = (message) => {
                checkoutErrorEl.textContent = message;
                show(checkoutErrorEl);
                checkoutErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            };

            const startCheckout = async (machine, button) => {
                hide(checkoutErrorEl);
                const originalLabel = button.textContent;
                button.disabled = true;
                button.textContent = 'Processing…';

                try {
                    const response = await fetch(CHECKOUT_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ machine_id: machine.id }),
                    });

                    let payload = null;
                    try {
                        payload = await response.json();
                    } catch (parseError) {
                        payload = null;
                    }

                    if (!response.ok) {
                        throw new Error((payload && (payload.error || payload.message)) || 'Checkout failed. Please try again.');
                    }
                    if (!payload || !payload.url) {
                        throw new Error((payload && (payload.error || payload.message)) || 'Checkout did not return a payment link.');
                    }

                    window.location.href = payload.url;
                } catch (error) {
                    showCheckoutError(error.message || 'Checkout failed. Please try again.');
                    button.disabled = false;
                    button.textContent = originalLabel;
                }
            };

            const renderMachines = (machines) => {
                gridEl.innerHTML = '';
                machines.forEach((machine) => gridEl.appendChild(buildCard(machine)));
                showOnly(gridEl);
            };

            const loadMachines = async () => {
                hide(checkoutErrorEl);
                showOnly(loadingEl);

                try {
                    const response = await fetch(CATALOG_URL, { headers: { Accept: 'application/json' } });
                    if (!response.ok) {
                        throw new Error(`Request failed with status ${response.status}.`);
                    }

                    const data = await response.json();
                    const machines = Array.isArray(data) ? data : (Array.isArray(data && data.machines) ? data.machines : []);

                    if (!machines.length) {
                        showOnly(emptyEl);
                        return;
                    }

                    renderMachines(machines);
                } catch (error) {
                    errorMsgEl.textContent = error.message || 'Please try again in a moment.';
                    showOnly(errorEl);
                }
            };

            retryBtn.addEventListener('click', loadMachines);
            loadMachines();
        })();
    </script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
