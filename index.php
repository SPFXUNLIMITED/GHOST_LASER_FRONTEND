<?php
// Contact form submission feedback
$contactStatus = isset($_GET['status']) ? $_GET['status'] : '';

$pageTitle       = 'Ghost Laser | Expert Laser Machine Repair';
$pageDescription = 'Ghost Laser — precision laser cutting machine repair, calibration, and maintenance. Fast turnaround, trusted by professionals.';
$logoHref        = '#';
$extraHead       = <<<'HTML'
    <style>
        .glow-cyan { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
        .glow-box { box-shadow: 0 0 0 1px rgba(6,182,212,0.2), 0 0 40px rgba(6,182,212,0.05); }
        .glow-box:hover { box-shadow: 0 0 0 1px rgba(6,182,212,0.5), 0 0 40px rgba(6,182,212,0.15); }
        .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .gradient-fade-bottom {
            background: linear-gradient(to bottom, transparent 60%, rgb(9,9,11) 100%);
        }
    </style>
HTML;
$headerRight     = <<<'HTML'
                <nav class="hidden md:flex items-center gap-8">
                    <a href="#services" class="text-sm text-zinc-400 hover:text-white transition-colors">Services</a>
                    <a href="#why-us" class="text-sm text-zinc-400 hover:text-white transition-colors">Why Us</a>
                    <a href="#process" class="text-sm text-zinc-400 hover:text-white transition-colors">Process</a>
                    <a href="#contact" class="text-sm text-zinc-400 hover:text-white transition-colors">Contact</a>
                </nav>
                <div class="hidden md:flex items-center gap-3">
                    <a href="book-repair.php" class="inline-flex items-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-2 rounded-md transition-colors btn-glow">
                        Book a Repair
                    </a>
                    <a
                        href="admin-login.php"
                        class="inline-flex items-center justify-center rounded-md border border-cyan-500/40 bg-cyan-500/10 px-4 py-2 text-sm font-medium text-cyan-300 transition-colors hover:border-cyan-400 hover:bg-cyan-500/20 hover:text-cyan-200"
                    >
                        Admin
                    </a>
                </div>
                <!-- Mobile menu button -->
                <button id="mobile-menu-btn" class="md:hidden text-zinc-400 hover:text-white p-1" aria-label="Open menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
HTML;
$headerMobileMenu = <<<'HTML'
        <!-- Mobile menu -->
        <div id="mobile-menu" class="hidden md:hidden border-t border-zinc-800/60 bg-zinc-950/95">
            <div class="px-6 py-4 flex flex-col gap-4">
                <a href="#services" class="text-sm text-zinc-400 hover:text-white transition-colors">Services</a>
                <a href="#why-us" class="text-sm text-zinc-400 hover:text-white transition-colors">Why Us</a>
                <a href="#process" class="text-sm text-zinc-400 hover:text-white transition-colors">Process</a>
                <a href="#contact" class="text-sm text-zinc-400 hover:text-white transition-colors">Contact</a>
                <a href="book-repair.php" class="inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-2 rounded-md transition-colors w-full">
                    Book a Repair
                </a>
                <a
                    href="admin-login.php"
                    class="inline-flex items-center justify-center rounded-md border border-cyan-500/40 bg-cyan-500/10 px-4 py-2 text-sm font-medium text-cyan-300 transition-colors hover:border-cyan-400 hover:bg-cyan-500/20 hover:text-cyan-200 w-full"
                >
                    Admin
                </a>
            </div>
        </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <!-- HERO -->
    <section class="relative min-h-screen flex items-center hero-grid overflow-hidden pt-16">
        <!-- Radial glow -->
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <div class="w-[600px] h-[600px] rounded-full bg-cyan-500/5 blur-3xl"></div>
        </div>
        <div class="absolute bottom-0 left-0 right-0 h-48 gradient-fade-bottom pointer-events-none"></div>

        <div class="relative max-w-7xl mx-auto px-6 lg:px-8 py-24 lg:py-32">
            <div class="max-w-4xl">
                <!-- Badge -->
                <div class="inline-flex items-center gap-2 bg-zinc-900 border border-cyan-500/30 rounded-full px-4 py-1.5 mb-8">
                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
                    <span class="text-xs text-cyan-400 font-medium tracking-wider uppercase">Precision Repair Specialists</span>
                </div>

                <h1 class="text-5xl sm:text-6xl lg:text-7xl font-black leading-tight tracking-tight mb-6">
                    Your Laser Is<br>
                    <span class="text-cyan-400 glow-cyan">Down. We Fix It.</span>
                </h1>

                <p class="text-lg sm:text-xl text-zinc-400 max-w-2xl mb-10 leading-relaxed">
                    Expert repair, calibration, and maintenance for all major laser cutting and engraving machines. Fast diagnosis. No guesswork. Back to full power.
                </p>

                <div class="flex flex-col sm:flex-row gap-4">
                    <a href="book-repair.php" class="inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-bold text-base px-7 py-3.5 rounded-md transition-all btn-glow">
                        Book a Repair
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </a>
                    <a href="#services" class="inline-flex items-center justify-center gap-2 bg-transparent border border-zinc-700 hover:border-zinc-500 text-white font-semibold text-base px-7 py-3.5 rounded-md transition-all hover:bg-zinc-900">
                        See Our Services
                    </a>
                </div>

                <!-- Stats row -->
                <div class="mt-16 pt-10 border-t border-zinc-800/60 grid grid-cols-2 sm:grid-cols-3 gap-8 max-w-xl">
                    <div>
                        <p class="text-3xl font-black text-white">500<span class="text-cyan-400">+</span></p>
                        <p class="text-sm text-zinc-500 mt-1">Machines Repaired</p>
                    </div>
                    <div>
                        <p class="text-3xl font-black text-white">48<span class="text-cyan-400">hr</span></p>
                        <p class="text-sm text-zinc-500 mt-1">Avg. Turnaround</p>
                    </div>
                    <div class="col-span-2 sm:col-span-1">
                        <p class="text-3xl font-black text-white">100<span class="text-cyan-400">%</span></p>
                        <p class="text-sm text-zinc-500 mt-1">Satisfaction Rate</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- SERVICES -->
    <section id="services" class="py-24 lg:py-32 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="mb-14">
                <p class="text-xs text-cyan-400 font-semibold tracking-widest uppercase mb-3">What We Do</p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight">Expert Laser Services</h2>
                <p class="mt-4 text-zinc-400 max-w-xl text-base">From tube replacements to full machine overhauls — we cover every aspect of laser machine service.</p>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <?php
                $services = [
                    [
                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>',
                        'title' => 'Laser Tube Replacement',
                        'desc'  => 'CO₂ and fiber tube diagnosis, sourcing, and precision swap with full alignment and power output verification.',
                    ],
                    [
                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>',
                        'title' => 'Beam Alignment & Calibration',
                        'desc'  => 'Mirror and lens alignment, beam path optimization, and focal length calibration for peak cutting accuracy.',
                    ],
                    [
                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v10m0 0h10M9 13H5m0 0v6a2 2 0 002 2h10a2 2 0 002-2v-6m-14 0h14"/>',
                        'title' => 'Controller & Electronics',
                        'desc'  => 'Ruida, Trocen, GRBL, and Lightburn controller repairs, PSU diagnostics, and motion system fault resolution.',
                    ],
                    [
                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>',
                        'title' => 'Cooling System Overhaul',
                        'desc'  => 'Water chiller servicing, CW-3000/5000 repairs, hose and pump replacement to prevent thermal damage.',
                    ],
                    [
                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
                        'title' => 'Preventive Maintenance',
                        'desc'  => 'Scheduled deep-cleans, optics inspection, lubrication, and firmware updates to maximize machine uptime.',
                    ],
                    [
                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>',
                        'title' => 'Emergency On-Site Repair',
                        'desc'  => 'When you can\'t move the machine, we come to you. Fast mobilization for production-critical breakdowns.',
                    ],
                ];
                foreach ($services as $s): ?>
                <div class="group bg-zinc-900/60 border border-zinc-800 rounded-xl p-6 glow-box transition-all duration-300 hover:bg-zinc-900">
                    <div class="w-10 h-10 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center mb-5 group-hover:bg-cyan-500/20 transition-colors">
                        <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <?= $s['icon'] ?>
                        </svg>
                    </div>
                    <h3 class="font-bold text-white text-base mb-2"><?= htmlspecialchars($s['title']) ?></h3>
                    <p class="text-sm text-zinc-400 leading-relaxed"><?= htmlspecialchars($s['desc']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- WHY US -->
    <section id="why-us" class="py-24 lg:py-32 bg-zinc-900/30 border-y border-zinc-800/50">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <div>
                    <p class="text-xs text-cyan-400 font-semibold tracking-widest uppercase mb-3">Why Ghost Laser</p>
                    <h2 class="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight mb-6">We Speak<br>Laser Fluently.</h2>
                    <p class="text-zinc-400 mb-8 leading-relaxed">
                        Other shops see a broken machine. We see a power supply operating at 87% capacity, a mirror with 0.3° drift, and a chiller running 4°C above spec. The difference is everything.
                    </p>
                    <a href="#contact" class="inline-flex items-center gap-2 text-cyan-400 font-semibold text-sm hover:text-cyan-300 transition-colors group">
                        Talk to a technician
                        <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </a>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php
                    $reasons = [
                        ['num' => '01', 'title' => 'Specialists, Not Generalists', 'desc' => 'We only work on laser systems. No side projects. No guesswork.'],
                        ['num' => '02', 'title' => 'Transparent Pricing', 'desc' => 'Fixed-rate diagnostics. Written quotes before any work begins.'],
                        ['num' => '03', 'title' => 'OEM & Aftermarket Parts', 'desc' => 'We stock genuine and high-quality compatible components for fast repairs.'],
                        ['num' => '04', 'title' => '90-Day Repair Warranty', 'desc' => 'Every repair is backed by our warranty. We stand by our work, period.'],
                    ];
                    foreach ($reasons as $r): ?>
                    <div class="bg-zinc-900/60 border border-zinc-800 rounded-xl p-5 glow-box transition-all duration-300">
                        <p class="text-xs text-cyan-500 font-bold tracking-widest mb-3"><?= $r['num'] ?></p>
                        <h3 class="font-bold text-white text-sm mb-1.5"><?= htmlspecialchars($r['title']) ?></h3>
                        <p class="text-xs text-zinc-400 leading-relaxed"><?= htmlspecialchars($r['desc']) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- PROCESS -->
    <section id="process" class="py-24 lg:py-32 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-14">
                <p class="text-xs text-cyan-400 font-semibold tracking-widest uppercase mb-3">How It Works</p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight">From Broken to<br>Back Online</h2>
            </div>
            <div class="relative grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Connector line (desktop only) -->
                <div class="hidden lg:block absolute top-8 left-[12.5%] right-[12.5%] h-px bg-gradient-to-r from-transparent via-cyan-500/30 to-transparent pointer-events-none"></div>

                <?php
                $steps = [
                    ['step' => '1', 'title' => 'Contact Us',       'desc' => 'Describe your issue or book a collection. We respond within 2 hours.'],
                    ['step' => '2', 'title' => 'Diagnosis',         'desc' => 'Full electrical and optical inspection. Written report within 24 hours.'],
                    ['step' => '3', 'title' => 'Quote & Approve',   'desc' => 'No hidden costs. You approve the fix before we touch anything.'],
                    ['step' => '4', 'title' => 'Repair & Return',   'desc' => 'Precision repair, full test-fire, and same-day dispatch or on-site sign-off.'],
                ];
                foreach ($steps as $i => $st): ?>
                <div class="relative text-center">
                    <div class="w-16 h-16 rounded-full bg-zinc-900 border-2 border-cyan-500/40 flex items-center justify-center mx-auto mb-5 text-cyan-400 font-black text-lg">
                        <?= $st['step'] ?>
                    </div>
                    <h3 class="font-bold text-white text-base mb-2"><?= htmlspecialchars($st['title']) ?></h3>
                    <p class="text-sm text-zinc-400 leading-relaxed px-2"><?= htmlspecialchars($st['desc']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- CTA / CONTACT -->
    <section id="contact" class="py-24 lg:py-32 bg-zinc-900/40 border-t border-zinc-800/50">
        <div class="max-w-3xl mx-auto px-6 lg:px-8 text-center">
            <div class="inline-flex items-center gap-2 bg-zinc-900 border border-cyan-500/30 rounded-full px-4 py-1.5 mb-8">
                <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
                <span class="text-xs text-cyan-400 font-medium tracking-wider uppercase">Available Now</span>
            </div>
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight mb-5">
                Ready to Get Your<br>Machine <span class="text-cyan-400">Running Again?</span>
            </h2>
            <p class="text-zinc-400 mb-10 leading-relaxed max-w-xl mx-auto">
                Send us the details. We'll get back to you with a diagnosis plan and quote — fast, transparent, no pressure.
            </p>

            <?php if ($contactStatus === 'success'): ?>
            <div class="mb-8 flex items-start gap-3 rounded-xl border border-green-500/40 bg-green-500/10 px-5 py-4 text-left text-sm text-green-300">
                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Thanks! Your message has been sent. We'll get back to you within 2 hours.</span>
            </div>
            <?php elseif ($contactStatus === 'error'): ?>
            <div class="mb-8 flex items-start gap-3 rounded-xl border border-red-500/40 bg-red-500/10 px-5 py-4 text-left text-sm text-red-300">
                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Something went wrong. Please check all fields and try again, or email us directly.</span>
            </div>
            <?php endif; ?>

            <form action="contact-submit.php" method="POST" class="bg-zinc-900/60 border border-zinc-800 rounded-2xl p-6 sm:p-8 text-left glow-box space-y-5">
                <!-- Honeypot: hidden from real users, bots fill it in -->
                <div style="display:none" aria-hidden="true">
                    <label for="website">Website</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>
                <div class="grid sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="name">Your Name</label>
                        <input type="text" id="name" name="name" placeholder="Jane Smith" required
                            class="w-full bg-zinc-800/60 border border-zinc-700 text-white placeholder-zinc-600 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/50 transition-colors">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="email">Email Address</label>
                        <input type="email" id="email" name="email" placeholder="jane@company.com" required
                            class="w-full bg-zinc-800/60 border border-zinc-700 text-white placeholder-zinc-600 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/50 transition-colors">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="machine">Machine Make &amp; Model</label>
                    <input type="text" id="machine" name="machine" placeholder="e.g. Thunder Laser Nova 35, Epilog Fusion Pro" 
                        class="w-full bg-zinc-800/60 border border-zinc-700 text-white placeholder-zinc-600 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/50 transition-colors">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="issue">Describe the Issue</label>
                    <textarea id="issue" name="issue" rows="4" placeholder="Describe what the machine is doing (or not doing)..." required
                        class="w-full bg-zinc-800/60 border border-zinc-700 text-white placeholder-zinc-600 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/50 transition-colors resize-none"></textarea>
                </div>
                <button type="submit" class="w-full bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-bold text-sm py-3.5 rounded-lg transition-all btn-glow">
                    Send My Request →
                </button>
            </form>
        </div>
    </section>

    <script>
        // Mobile menu toggle
        const btn = document.getElementById('mobile-menu-btn');
        const menu = document.getElementById('mobile-menu');
        btn.addEventListener('click', () => {
            menu.classList.toggle('hidden');
        });
        // Close on nav link click
        menu.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => menu.classList.add('hidden'));
        });
    </script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>