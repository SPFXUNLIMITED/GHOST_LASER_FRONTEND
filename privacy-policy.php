<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pageTitle       = 'Privacy Policy | Ghost Laser';
$pageDescription = 'Ghost Laser privacy policy — how we collect, use, and protect your personal information, and our SMS/text message terms.';
$logoHref        = '/';
$extraHead       = <<<'HTML'
    <style>
        .glow-cyan  { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
        .card-glow  {
            box-shadow: 0 0 0 1px rgba(6,182,212,0.12),
                        0 0 60px rgba(6,182,212,0.05),
                        0 24px 48px rgba(0,0,0,0.4);
        }
        .section-divider { border-color: rgba(6,182,212,0.15); }
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

            <!-- Page heading -->
            <div class="mb-12 text-center">
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/30 bg-zinc-900/80 px-5 py-2 mb-6">
                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-400"></span>
                    <span class="text-xs font-semibold tracking-widest text-cyan-400 uppercase">Legal</span>
                </div>
                <h1 class="text-4xl sm:text-5xl font-black tracking-tight text-white mb-3">
                    Privacy <span class="text-cyan-400 glow-cyan">Policy</span>
                </h1>
                <p class="text-sm text-zinc-500">Last updated: <?= date('F j, Y') ?></p>
            </div>

            <!-- Card -->
            <div class="card-glow rounded-2xl border border-zinc-800/70 bg-zinc-900/80 backdrop-blur-sm divide-y divide-zinc-800/70">

                <!-- General Privacy Policy -->
                <section class="px-8 sm:px-10 py-10">
                    <h2 class="text-xl font-bold text-white mb-1 flex items-center gap-3">
                        <span class="flex-shrink-0 w-7 h-7 rounded-lg bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center">
                            <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                        </span>
                        General Privacy Policy
                    </h2>
                    <p class="text-xs text-zinc-500 mb-6">How we collect and use your information</p>

                    <div class="space-y-6 text-sm text-zinc-400 leading-relaxed">
                        <div>
                            <h3 class="text-white font-semibold mb-1">Information We Collect</h3>
                            <p>We collect information you provide directly to us, such as your name, email address, phone number, and service address when you request a repair or create an account. We may also collect device and usage information automatically when you visit our website.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">How We Use Your Information</h3>
                            <p>We use the information we collect to schedule and fulfill repair services, communicate with you about your appointments, send service-related notifications (including SMS messages with your consent), improve our website and services, and comply with legal obligations.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Information Sharing</h3>
                            <p>We do not sell, rent, or trade your personal information to third parties. We may share your information with service providers who assist us in operating our business (such as scheduling and communication tools), only to the extent necessary to perform those services. We may also disclose information as required by law or to protect our rights.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Data Security</h3>
                            <p>We implement reasonable technical and organizational measures to protect your personal information from unauthorized access, loss, or misuse. However, no method of transmission or storage is 100% secure, and we cannot guarantee absolute security.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Cookies &amp; Tracking</h3>
                            <p>Our website may use cookies and similar technologies to enhance your browsing experience and remember your preferences. You can control cookie settings through your browser; disabling cookies may affect certain site functionality.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Your Rights</h3>
                            <p>You may request access to, correction of, or deletion of your personal information at any time by contacting us at the information below. We will respond to your request in accordance with applicable law.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Children's Privacy</h3>
                            <p>Our services are not directed to individuals under the age of 18. We do not knowingly collect personal information from children. If you believe we have inadvertently collected such information, please contact us and we will promptly delete it.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Changes to This Policy</h3>
                            <p>We may update this Privacy Policy from time to time. We will post the revised policy on this page with an updated date. Your continued use of our services after any changes constitutes your acceptance of the revised policy.</p>
                        </div>
                    </div>
                </section>

                <!-- SMS / Text Message Terms -->
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

                        <!-- Highlighted disclosure box -->
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

                <!-- Contact footer -->
                <section class="px-8 sm:px-10 py-8 bg-zinc-900/40 rounded-b-2xl">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-white mb-0.5">Questions about this policy?</p>
                            <p class="text-xs text-zinc-500">We're happy to help clarify anything.</p>
                        </div>
                        <div class="flex flex-col sm:flex-row gap-3 text-sm">
                            <a href="tel:+19495652660" class="inline-flex items-center gap-2 text-zinc-400 hover:text-cyan-400 transition-colors">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                                </svg>
                                (949) 565-2660
                            </a>
                            <a href="mailto:support@ghostlaser.com" class="inline-flex items-center gap-2 text-zinc-400 hover:text-cyan-400 transition-colors">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                                support@ghostlaser.com
                            </a>
                        </div>
                    </div>
                    <div class="mt-6 pt-5 border-t border-zinc-800/70">
                        <p class="text-xs text-zinc-600 text-center">
                            19801 Esperanza Rd &middot; Yorba Linda, CA 92886 &middot;
                            <a href="/" class="hover:text-zinc-400 transition-colors">ghostlaser.com</a>
                        </p>
                    </div>
                </section>

            </div><!-- /card -->
        </div><!-- /max-w-3xl -->
    </main>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
