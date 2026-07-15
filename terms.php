<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pageTitle       = 'Terms of Service | Ghost Laser';
$pageDescription = 'Ghost Laser terms of service — the rules and conditions governing your use of our website and repair services, including SMS/text message terms.';
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
                    Terms of <span class="text-cyan-400 glow-cyan">Service</span>
                </h1>
                <p class="text-sm text-zinc-500">Last updated: <?= date('F j, Y') ?></p>
            </div>

            <!-- Card -->
            <div class="card-glow rounded-2xl border border-zinc-800/70 bg-zinc-900/80 backdrop-blur-sm divide-y divide-zinc-800/70">

                <!-- Acceptance of Terms -->
                <section class="px-8 sm:px-10 py-10">
                    <h2 class="text-xl font-bold text-white mb-1 flex items-center gap-3">
                        <span class="flex-shrink-0 w-7 h-7 rounded-lg bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center">
                            <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </span>
                        Acceptance of Terms
                    </h2>
                    <p class="text-xs text-zinc-500 mb-6">Your agreement to these terms</p>

                    <div class="space-y-6 text-sm text-zinc-400 leading-relaxed">
                        <div>
                            <p>By accessing or using the Ghost Laser website (ghostlaser.com) or any of our repair services, you agree to be bound by these Terms of Service and all applicable laws and regulations. If you do not agree with any of these terms, you are prohibited from using or accessing this site. These terms apply to all visitors, users, and others who access or use our services.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Changes to Terms</h3>
                            <p>Ghost Laser reserves the right to modify or replace these Terms of Service at any time at our sole discretion. We will post the revised terms on this page with an updated date. Your continued use of the website or services after any such changes constitutes your acceptance of the new terms.</p>
                        </div>
                    </div>
                </section>

                <!-- Use of Services -->
                <section class="px-8 sm:px-10 py-10">
                    <h2 class="text-xl font-bold text-white mb-1 flex items-center gap-3">
                        <span class="flex-shrink-0 w-7 h-7 rounded-lg bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center">
                            <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                        </span>
                        Use of Services
                    </h2>
                    <p class="text-xs text-zinc-500 mb-6">Permitted and prohibited uses of our website and services</p>

                    <div class="space-y-6 text-sm text-zinc-400 leading-relaxed">
                        <div>
                            <h3 class="text-white font-semibold mb-1">Eligibility</h3>
                            <p>You must be at least 18 years of age to use our services or enter into any agreement with Ghost Laser. By using our services you represent and warrant that you are 18 years of age or older and have the legal capacity to enter into a binding contract.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Permitted Use</h3>
                            <p>You may use our website and services solely for lawful purposes and in accordance with these Terms. You agree to use the site only to request legitimate repair services for equipment you own or are authorized to have serviced.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Prohibited Conduct</h3>
                            <p>You agree not to: (a) use the site in any way that violates applicable laws or regulations; (b) transmit any unsolicited or unauthorized advertising; (c) attempt to gain unauthorized access to any portion of the site or its related systems; (d) use automated tools to scrape, crawl, or extract data from the site; or (e) interfere with or disrupt the integrity or performance of the site.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Account Responsibility</h3>
                            <p>If you create an account, you are responsible for maintaining the confidentiality of your login credentials and for all activity that occurs under your account. You agree to notify us immediately of any unauthorized use of your account.</p>
                        </div>
                    </div>
                </section>

                <!-- Repair Services -->
                <section class="px-8 sm:px-10 py-10">
                    <h2 class="text-xl font-bold text-white mb-1 flex items-center gap-3">
                        <span class="flex-shrink-0 w-7 h-7 rounded-lg bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center">
                            <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/>
                            </svg>
                        </span>
                        Repair Services
                    </h2>
                    <p class="text-xs text-zinc-500 mb-6">Terms specific to our laser machine repair services</p>

                    <div class="space-y-6 text-sm text-zinc-400 leading-relaxed">
                        <div>
                            <h3 class="text-white font-semibold mb-1">Service Appointments</h3>
                            <p>Booking a service appointment through our website constitutes a request for service and does not guarantee immediate availability. We will confirm your appointment and provide scheduling details via email and/or SMS. Ghost Laser reserves the right to reschedule or cancel appointments due to technician availability, safety concerns, or other circumstances beyond our control.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Service Area</h3>
                            <p>Our on-site repair services are available within our designated service area. Availability may vary. We reserve the right to determine whether a location falls within our service area at our sole discretion.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Estimates &amp; Pricing</h3>
                            <p>Any pricing or estimates provided before or during a service call are estimates only and subject to change based on the actual condition of the equipment. Final pricing will be communicated and agreed upon before any repair work begins. You are not obligated to proceed with repairs if you do not agree with the quoted price.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Customer Responsibilities</h3>
                            <p>You agree to provide accurate information about the equipment, its location, and any known issues. You are responsible for ensuring a safe and accessible work environment for our technicians. Ghost Laser is not liable for damage resulting from inaccurate information or unsafe conditions provided by the customer.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Warranty &amp; Warranty Disclaimer</h3>
                            <p>Unless separately stated in writing, repairs are warranted against defects in parts and workmanship for 30 days from the date of service. This warranty does not cover damage caused by misuse, accidents, unauthorized modifications, or pre-existing conditions unrelated to the repair performed. ALL OTHER WARRANTIES, EXPRESS OR IMPLIED, ARE DISCLAIMED TO THE FULLEST EXTENT PERMITTED BY LAW.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Cancellation Policy</h3>
                            <p>You may cancel or reschedule a scheduled appointment by contacting us at least 24 hours in advance. Late cancellations or no-shows may result in a service call fee. We reserve the right to charge a fee to cover technician travel and time for appointments cancelled with less than 24 hours notice.</p>
                        </div>
                    </div>
                </section>

                <!-- Limitation of Liability -->
                <section class="px-8 sm:px-10 py-10">
                    <h2 class="text-xl font-bold text-white mb-1 flex items-center gap-3">
                        <span class="flex-shrink-0 w-7 h-7 rounded-lg bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center">
                            <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </span>
                        Limitation of Liability
                    </h2>
                    <p class="text-xs text-zinc-500 mb-6">Our liability limits and disclaimers</p>

                    <div class="space-y-6 text-sm text-zinc-400 leading-relaxed">
                        <div>
                            <h3 class="text-white font-semibold mb-1">Disclaimer of Warranties</h3>
                            <p>Our website and services are provided on an "as is" and "as available" basis without warranties of any kind, either express or implied, including but not limited to implied warranties of merchantability, fitness for a particular purpose, or non-infringement.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Limitation of Damages</h3>
                            <p>To the fullest extent permitted by applicable law, Ghost Laser shall not be liable for any indirect, incidental, special, consequential, or punitive damages, including but not limited to loss of profits, data, or goodwill, arising out of or in connection with your use of our website or services, even if we have been advised of the possibility of such damages.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Maximum Liability</h3>
                            <p>In no event shall Ghost Laser's total liability to you for all claims arising out of or relating to these Terms or our services exceed the total amount paid by you to Ghost Laser in the twelve (12) months preceding the claim.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Indemnification</h3>
                            <p>You agree to defend, indemnify, and hold harmless Ghost Laser and its officers, directors, employees, and agents from and against any claims, liabilities, damages, judgments, awards, losses, costs, expenses, or fees (including reasonable attorneys' fees) arising out of or relating to your violation of these Terms or your use of our services.</p>
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
                            <p>Your mobile number and SMS consent status are stored securely and used solely to deliver repair-related communications. We do not share your mobile number with third parties for marketing purposes. For more information, see our <a href="/sms-opt-in.php" class="text-cyan-400 hover:text-cyan-300 transition-colors">SMS Opt-In page</a>.</p>
                        </div>
                    </div>
                </section>

                <!-- Intellectual Property -->
                <section class="px-8 sm:px-10 py-10">
                    <h2 class="text-xl font-bold text-white mb-1 flex items-center gap-3">
                        <span class="flex-shrink-0 w-7 h-7 rounded-lg bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center">
                            <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                            </svg>
                        </span>
                        Intellectual Property
                    </h2>
                    <p class="text-xs text-zinc-500 mb-6">Ownership of content and trademarks</p>

                    <div class="space-y-6 text-sm text-zinc-400 leading-relaxed">
                        <div>
                            <p>The Ghost Laser name, logo, website content, text, graphics, images, and software are the property of Ghost Laser and are protected by applicable intellectual property laws. You may not reproduce, distribute, modify, create derivative works of, publicly display, or exploit any content from this website without our prior written permission.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Limited License</h3>
                            <p>We grant you a limited, non-exclusive, non-transferable, revocable license to access and use our website for personal, non-commercial purposes. This license does not include any right to resell or commercial use of the site or its contents.</p>
                        </div>
                    </div>
                </section>

                <!-- Governing Law -->
                <section class="px-8 sm:px-10 py-10">
                    <h2 class="text-xl font-bold text-white mb-1 flex items-center gap-3">
                        <span class="flex-shrink-0 w-7 h-7 rounded-lg bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center">
                            <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/>
                            </svg>
                        </span>
                        Governing Law &amp; Disputes
                    </h2>
                    <p class="text-xs text-zinc-500 mb-6">Jurisdiction and dispute resolution</p>

                    <div class="space-y-6 text-sm text-zinc-400 leading-relaxed">
                        <div>
                            <h3 class="text-white font-semibold mb-1">Governing Law</h3>
                            <p>These Terms of Service are governed by and construed in accordance with the laws of the State of California, without regard to its conflict of law provisions. You agree to submit to the personal jurisdiction of the courts located in Orange County, California for any disputes arising out of or relating to these Terms or our services.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Informal Resolution</h3>
                            <p>Before filing a formal legal claim, you agree to first contact Ghost Laser and attempt to resolve the dispute informally by providing written notice of your claim. We will attempt to resolve the dispute within 30 days of receiving your notice.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Severability</h3>
                            <p>If any provision of these Terms is found to be unenforceable or invalid under applicable law, that provision shall be modified to the minimum extent necessary to make it enforceable, or if not possible, severed from these Terms, without affecting the validity and enforceability of the remaining provisions.</p>
                        </div>

                        <div>
                            <h3 class="text-white font-semibold mb-1">Entire Agreement</h3>
                            <p>These Terms of Service, together with our Privacy Policy, constitute the entire agreement between you and Ghost Laser with respect to your use of our website and services and supersede all prior agreements and understandings.</p>
                        </div>
                    </div>
                </section>

                <!-- Contact footer -->
                <section class="px-8 sm:px-10 py-8 bg-zinc-900/40 rounded-b-2xl">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-white mb-0.5">Questions about these terms?</p>
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
