<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$redirect = isset($_GET['redirect']) ? trim((string) $_GET['redirect']) : '';

$pageTitle       = 'SMS Opt-In | Ghost Laser';
$pageDescription = 'Opt in to receive SMS appointment confirmations and updates from Ghost Laser.';
$logoHref        = '/';
$extraHead       = <<<'HTML'
    <style>
        .glow-cyan  { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
        .btn-glow   { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 35px rgba(6,182,212,0.7); }
        .card-glow  {
            box-shadow: 0 0 0 1px rgba(6,182,212,0.18),
                        0 0 60px rgba(6,182,212,0.07),
                        0 32px 64px rgba(0,0,0,0.5);
        }
        .pulse-dot  { animation: pulse-dot 2s cubic-bezier(0.4,0,0.6,1) infinite; }
        @keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:.4} }
    </style>
HTML;
$headerRight = <<<HTML
    <nav class="hidden md:flex items-center gap-8">
        <a href="/#services" class="text-sm text-zinc-400 hover:text-white transition-colors">Services</a>
        <a href="/#contact"  class="text-sm text-zinc-400 hover:text-white transition-colors">Contact</a>
    </nav>
HTML;
$headerMobileMenu = '';

require_once __DIR__ . '/templates/header.php';
?>

    <!-- Page body -->
    <main class="min-h-screen flex items-center justify-center hero-grid px-4 py-24">

        <!-- Background radial glow -->
        <div class="pointer-events-none fixed inset-0 flex items-center justify-center">
            <div class="w-[700px] h-[700px] rounded-full bg-cyan-500/5 blur-3xl"></div>
        </div>

        <div class="relative w-full max-w-lg">

            <!-- Icon badge -->
            <div class="flex justify-center mb-8">
                <span class="inline-flex items-center gap-2 rounded-full border border-cyan-500/30 bg-zinc-900/80 px-5 py-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 pulse-dot"></span>
                    <span class="text-xs font-semibold tracking-widest text-cyan-400 uppercase">SMS Notification Service</span>
                </span>
            </div>

            <!-- Card -->
            <div class="card-glow rounded-2xl border border-zinc-800/70 bg-zinc-900/80 backdrop-blur-sm p-8 sm:p-10">

                <!-- Heading -->
                <div class="mb-8 text-center">
                    <!-- SMS icon -->
                    <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl border border-cyan-500/30 bg-cyan-500/10">
                        <svg class="h-8 w-8 text-cyan-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.77 9.77 0 01-4-.85L3 20l1.08-3.6A7.8 7.8 0 013 12C3 7.582 7.03 4 12 4s9 3.582 9 8z"/>
                        </svg>
                    </div>

                    <h1 class="text-3xl sm:text-4xl font-black tracking-tight text-white mb-2">
                        Stay <span class="text-cyan-400 glow-cyan">In The Loop</span>
                    </h1>
                    <p class="text-sm text-zinc-400">Enable SMS updates for your Ghost Laser repair</p>
                </div>

                <!-- Divider -->
                <div class="mb-7 h-px bg-gradient-to-r from-transparent via-cyan-500/25 to-transparent"></div>

                <!-- What you'll receive -->
                <ul class="mb-7 space-y-3">
                    <?php
                    $items = [
                        ['icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                         'label' => 'Appointment confirmations & reminders'],
                        ['icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                         'label' => 'Real-time updates on your laser repair'],
                        ['icon' => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
                         'label' => 'Occasional promotions & service offers'],
                    ];
                    foreach ($items as $item): ?>
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full border border-cyan-500/30 bg-cyan-500/10">
                            <svg class="h-3 w-3 text-cyan-400" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="<?= htmlspecialchars($item['icon']) ?>"/>
                            </svg>
                        </span>
                        <span class="text-sm text-zinc-300"><?= htmlspecialchars($item['label']) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Disclosure text -->
                <div class="mb-8 rounded-xl border border-zinc-700/60 bg-zinc-800/40 px-5 py-4">
                    <p class="text-sm leading-relaxed text-zinc-300">
                        We will send you appointment confirmations, updates about your laser repair, and occasional promotions via text message.
                        <span class="text-zinc-400">Message and data rates may apply. Reply <strong class="font-semibold text-white">STOP</strong> to opt out at any time.</span>
                    </p>
                </div>

                <!-- CTA button -->
                <a
                    href="<?= htmlspecialchars($redirect !== '' ? $redirect : '/', ENT_QUOTES, 'UTF-8') ?>"
                    class="btn-glow flex w-full items-center justify-center gap-2.5 rounded-xl bg-cyan-500 px-6 py-4 text-base font-bold text-zinc-950 transition-all hover:bg-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-400 focus:ring-offset-2 focus:ring-offset-zinc-900"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                    I Agree &amp; Continue
                </a>

                <!-- Opt-out note -->
                <p class="mt-5 text-center text-xs text-zinc-500">
                    You may opt out at any time by replying <strong class="font-medium text-zinc-400">STOP</strong> to any message.
                    Standard carrier rates apply.
                </p>

            </div><!-- /card -->

        </div>
    </main>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
