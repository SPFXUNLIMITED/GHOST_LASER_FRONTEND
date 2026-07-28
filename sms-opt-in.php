<?php
$pageTitle       = 'SMS Terms | Ghost Laser';
$pageDescription = 'Read Ghost Laser SMS/text message terms and communications policy.';
$logoHref        = '/';
$extraHead       = <<<'HTML'
    <style>
        .glow-cyan  { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
        .card-glow  {
            box-shadow: 0 0 0 1px rgba(6,182,212,0.12),
                        0 0 60px rgba(6,182,212,0.05),
                        0 24px 48px rgba(0,0,0,0.4);
        }
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
    <main class="min-h-screen hero-grid px-4 pt-28 pb-20">

        <!-- Background glow -->
        <div class="pointer-events-none fixed inset-0 flex items-start justify-center pt-32 overflow-hidden">
            <div class="w-[800px] h-[400px] rounded-full bg-cyan-500/4 blur-3xl"></div>
        </div>

        <div class="relative max-w-3xl mx-auto">
            <div class="mb-12 text-center">
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/30 bg-zinc-900/80 px-5 py-2 mb-6">
                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-400"></span>
                    <span class="text-xs font-semibold tracking-widest text-cyan-400 uppercase">Legal</span>
                </div>
                <h1 class="text-4xl sm:text-5xl font-black tracking-tight text-white mb-3">
                    SMS / Text Message <span class="text-cyan-400 glow-cyan">Terms</span>
                </h1>
                <p class="text-sm text-zinc-500">Read-only legal terms for text communications.</p>
            </div>

            <div class="card-glow rounded-2xl border border-zinc-800/70 bg-zinc-900/80 backdrop-blur-sm divide-y divide-zinc-800/70">
                <section class="px-8 sm:px-10 py-10">
                    <h2 class="text-xl font-bold text-white mb-1 flex items-center gap-3">
                        <span class="flex-shrink-0 w-7 h-7 rounded-lg bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center">
                            <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.77 9.77 0 01-4-.85L3 20l1.08-3.6A7.8 7.8 0 013 12C3 7.582 7.03 4 12 4s9 3.582 9 8z"/>
                            </svg>
                        </span>
                        SMS / Text Message Terms
                    </h2>
                    <p class="text-xs text-zinc-500 mb-6">Your rights regarding text message communications</p>

                    <div class="space-y-6 text-sm text-zinc-400 leading-relaxed">
                        <div>
                            <h3 class="text-white font-semibold mb-1">Program Description</h3>
                            <p>Ghost Laser sends SMS messages to customers who have opted in to receive appointment confirmations, technician dispatch notices, service status updates, and other repair-related communications.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Consent &amp; Opt-In</h3>
                            <p>By providing your mobile phone number and checking the SMS opt-in box during booking, you expressly consent to receive text messages from Ghost Laser at the number provided. Consent is not a condition of purchase.</p>
                        </div>

                        <div class="rounded-xl border border-cyan-500/25 bg-cyan-500/5 px-5 py-4">
                            <p class="text-cyan-300 font-medium text-sm">
                                Message and data rates may apply. Message frequency varies based on your service appointments and account activity.
                            </p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">How to Opt Out</h3>
                            <p>You may opt out of SMS messages at any time by replying <strong class="text-white">STOP</strong> to any message you receive from us. After opting out, you will receive a single confirmation message and no further SMS communications will be sent unless you opt in again.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Help</h3>
                            <p>Reply <strong class="text-white">HELP</strong> to any message for assistance, or contact us directly at <a href="tel:+19495652660" class="text-cyan-400 hover:text-cyan-300 transition-colors">(949) 565-2660</a> or <a href="mailto:support@ghostlaser.com" class="text-cyan-400 hover:text-cyan-300 transition-colors">support@ghostlaser.com</a>.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Supported Carriers</h3>
                            <p>Supported carriers include, but are not limited to, AT&amp;T, T-Mobile, Verizon, Sprint, Boost Mobile, U.S. Cellular, and MetroPCS. Carrier support is subject to change. Ghost Laser is not liable for delayed or undelivered messages.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Privacy of SMS Data</h3>
                            <p>Your mobile number and SMS consent status are stored securely and used solely to deliver repair-related communications. We do not share your mobile number with third parties for marketing purposes.</p>
                        </div>
                    </div>
                </section>

                <section class="px-8 sm:px-10 py-8 bg-zinc-900/40 rounded-b-2xl">
                    <p class="text-xs text-zinc-500 text-center">
                        This page is provided for reference only and does not collect or update SMS consent.
                    </p>
                </section>
            </div>
        </div>
    </main>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
