<?php
session_start();

// Pre-fill form with logged-in customer data
$sessionCustomer = null;
if (!empty($_SESSION['customer_id'])) {
    require_once __DIR__ . '/project/db.php';
    $stmt = $pdo->prepare('SELECT first_name, last_name, email, phone, street, city, state, zip FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['customer_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $sessionCustomer = $row;
    }
}

function hv($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$isHoneypotSpam = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $website = trim((string) ($_POST['website'] ?? ''));
    if ($website !== '') {
        $isHoneypotSpam = true;
    }
}
if ($isHoneypotSpam) {
    http_response_code(204);
    exit;
}

$pageTitle       = 'Book a Repair | Ghost Laser';
$pageDescription = 'Book a laser machine repair with Ghost Laser. Fast, professional service for all major laser cutting and engraving machines.';
$extraHead       = <<<'HTML'
    <style>
        .glow-cyan { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
        .glow-box { box-shadow: 0 0 0 1px rgba(6,182,212,0.2), 0 0 40px rgba(6,182,212,0.05); }
        .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
        .input-base {
            width: 100%;
            background: rgba(39,39,42,0.6);
            border: 1px solid rgb(63,63,70);
            color: white;
            border-radius: 0.5rem;
            padding: 0.625rem 1rem;
            font-size: 0.875rem;
            transition: border-color 0.15s, box-shadow 0.15s;
            outline: none;
        }
        .input-base::placeholder { color: rgb(82,82,91); }
        .input-base:focus { border-color: #06b6d4; box-shadow: 0 0 0 1px rgba(6,182,212,0.5); }
        .priority-radio input[type="radio"] { display: none; }
        .priority-radio label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid rgb(63,63,70);
            background: rgba(39,39,42,0.6);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }
        .priority-radio input[type="radio"]:checked + label {
            border-color: #06b6d4;
            background: rgba(6,182,212,0.08);
            color: #22d3ee;
        }
        .priority-radio label:hover { border-color: rgb(113,113,122); }
        .priority-dot { width: 0.5rem; height: 0.5rem; border-radius: 9999px; flex-shrink: 0; }

        /* Gate / panel transitions */
        .gate-panel { transition: opacity 0.25s ease, transform 0.25s ease; }
        .gate-panel.hidden { display: none !important; }
    </style>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <!-- PAGE HEADER -->
    <section class="pt-32 pb-12 lg:pb-16 bg-zinc-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8 text-center">
            <div class="inline-flex items-center gap-2 bg-zinc-900 border border-cyan-500/30 rounded-full px-4 py-1.5 mb-8">
                <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
                <span class="text-xs text-cyan-400 font-medium tracking-wider uppercase">Book a Service</span>
            </div>
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight mb-5">
                Book Your <span class="text-cyan-400 glow-cyan">Repair</span>
            </h1>
            <p class="text-zinc-400 text-lg leading-relaxed max-w-xl mx-auto">
                Fill in the details below and our team will follow up within 2 hours with a diagnosis plan and transparent quote.
            </p>
        </div>
    </section>

    <!-- MAIN CONTENT -->
    <section class="pb-24 lg:pb-32 bg-zinc-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">

<?php if (!$sessionCustomer): ?>
            <!-- ── GATE: Choose login or guest ── -->
            <div id="gate-choice" class="gate-panel flex items-center justify-center">
                <div class="w-full max-w-sm">
                    <div class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-8 card-glow">
                        <div class="flex flex-col items-center mb-8">
                            <span class="w-12 h-12 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center mb-4">
                                <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                            </span>
                            <h2 class="text-xl font-bold tracking-tight">How would you like to continue?</h2>
                            <p class="text-sm text-zinc-500 mt-1 text-center">Choose one of the options below to get started.</p>
                        </div>

                        <div class="flex flex-col gap-4">
                            <!-- Login button -->
                            <button id="btn-show-login" type="button"
                                class="w-full inline-flex items-center justify-center gap-2.5 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-white font-semibold text-sm px-4 py-3.5 rounded-lg transition-all">
                                <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                                </svg>
                                I have an account
                            </button>

                            <!-- Guest button -->
                            <button id="btn-guest" type="button"
                                class="w-full inline-flex items-center justify-center gap-2.5 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-3.5 rounded-lg transition-all btn-glow">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                                </svg>
                                I'm a new customer
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── INLINE LOGIN PANEL ── -->
            <div id="gate-login" class="gate-panel hidden flex items-center justify-center">
                <div class="w-full max-w-sm">
                    <div class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-8 card-glow">
                        <div class="flex flex-col items-center mb-8">
                            <span class="w-12 h-12 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center mb-4">
                                <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                            </span>
                            <h2 class="text-xl font-bold tracking-tight">Welcome Back</h2>
                            <p class="text-sm text-zinc-500 mt-1">Log in to pre-fill your booking details</p>
                        </div>

                        <!-- Login error -->
                        <div id="login-error" class="hidden mb-5 flex items-center gap-2 bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg">
                            <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <span id="login-error-text">Invalid email or password.</span>
                        </div>

                        <div class="flex flex-col gap-5">
                            <!-- Email -->
                            <div>
                                <label for="login-email" class="block text-sm font-medium text-zinc-300 mb-1.5">Email Address</label>
                                <input id="login-email" type="email" autocomplete="email" placeholder="you@example.com"
                                    class="input-base" required>
                            </div>

                            <!-- Password -->
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <label for="login-password" class="block text-sm font-medium text-zinc-300">Password</label>
                                    <a href="customer-forgot-password.php" class="text-xs text-cyan-400 hover:text-cyan-300 transition-colors">Forgot password?</a>
                                </div>
                                <div class="relative">
                                    <input id="login-password" type="password" autocomplete="current-password" placeholder="Enter your password"
                                        class="input-base pr-10" required>
                                    <button type="button" id="toggle-login-password"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300 transition-colors"
                                        aria-label="Toggle password visibility">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Submit -->
                            <button id="login-submit" type="button"
                                class="w-full inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-2.5 rounded-md transition-all btn-glow mt-1">
                                <span id="login-submit-label">Sign In &amp; Continue</span>
                                <svg id="login-submit-spinner" class="hidden w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                                </svg>
                            </button>
                        </div>

                        <div class="mt-6 text-center text-sm text-zinc-500">
                            <button type="button" id="btn-back-from-login" class="text-cyan-400 hover:text-cyan-300 transition-colors font-medium">&larr; Back</button>
                        </div>
                    </div>
                </div>
            </div>
<?php endif; ?>

            <!-- ── BOOKING FORM ── -->
            <div id="booking-form-wrap" class="gate-panel<?= $sessionCustomer ? '' : ' hidden' ?>">

                <?php if ($sessionCustomer): ?>
                <!-- Logged-in customer banner -->
                <div class="mb-6 flex items-center justify-between gap-3 rounded-xl border border-cyan-500/20 bg-cyan-500/5 px-4 py-3 text-sm text-cyan-300">
                    <span>
                        <svg class="inline w-4 h-4 mr-1.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        Logged in as <strong class="text-cyan-200"><?= hv($sessionCustomer['email']) ?></strong> — your details have been pre-filled.
                    </span>
                    <a href="customer-logout.php" class="text-xs text-zinc-400 hover:text-white transition-colors whitespace-nowrap">Log out</a>
                </div>
                <?php else: ?>
                <!-- Dynamic logged-in banner (shown after AJAX login) -->
                <div id="logged-in-banner" class="hidden mb-6 flex items-center justify-between gap-3 rounded-xl border border-cyan-500/20 bg-cyan-500/5 px-4 py-3 text-sm text-cyan-300">
                    <span>
                        <svg class="inline w-4 h-4 mr-1.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        Logged in as <strong id="logged-in-email" class="text-cyan-200"></strong> — your details have been pre-filled.
                    </span>
                    <a href="customer-logout.php" class="text-xs text-zinc-400 hover:text-white transition-colors whitespace-nowrap">Log out</a>
                </div>
                <?php endif; ?>

                <!-- Success message (hidden by default, populated dynamically from API response) -->
                <div id="msg-success" class="hidden mb-8 rounded-2xl border border-emerald-500/30 bg-emerald-950/60 px-5 py-5 shadow-lg shadow-emerald-950/20 sm:px-6">
                    <div class="flex items-start gap-3.5">
                        <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-emerald-400/20 bg-emerald-400/10">
                        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        </span>
                        <div>
                            <p id="success-heading" class="text-sm font-semibold text-emerald-300">Thank you! Your repair request has been received.</p>
                            <p id="success-subtext" class="mt-1 text-sm leading-6 text-emerald-100/75">We've got your request and will be in touch shortly to confirm the details.</p>
                        </div>
                    </div>
                    <div class="mt-5 space-y-4 border-t border-emerald-500/20 pt-4">
                        <div class="flex flex-wrap items-center gap-2.5 text-sm">
                            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-100/55">Priority</span>
                            <span id="success-priority" class="inline-flex items-center rounded-full border border-cyan-500/30 bg-cyan-500/15 px-3 py-1 text-xs font-semibold tracking-wide text-cyan-200"></span>
                        </div>
                        <div class="text-sm">
                            <p id="success-dates-label" class="mb-2 text-sm leading-6 text-emerald-100/70">Here are your suggested service dates:</p>
                            <ul id="success-dates" class="space-y-2"></ul>
                        </div>
                    </div>
                </div>

                <!-- Error message (hidden by default) -->
                <div id="msg-error" class="hidden mb-6 flex items-start gap-3 bg-red-950/60 border border-red-500/30 rounded-xl px-5 py-4">
                    <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <p class="font-semibold text-red-400 text-sm">Something went wrong</p>
                        <p id="msg-error-text" class="text-red-300/70 text-sm mt-0.5">Please check your details and try again.</p>
                    </div>
                </div>

                <form id="repair-form" novalidate class="bg-zinc-900/60 border border-zinc-800 rounded-2xl p-6 sm:p-8 text-left glow-box space-y-6">

                    <!-- Section: Contact -->
                    <div>
                        <p class="text-xs text-cyan-400 font-semibold tracking-widest uppercase mb-4">Contact Information</p>
                        <div class="grid sm:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="name">Full Name <span class="text-red-400">*</span></label>
                                <input type="text" id="name" name="name" placeholder="Jane Smith" required
                                    value="<?= $sessionCustomer ? hv(trim($sessionCustomer['first_name'] . ' ' . $sessionCustomer['last_name'])) : '' ?>"
                                    class="input-base">
                            </div>
                            <div>
                                <label class="flex items-center gap-1.5 text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="phone">
                                    Phone Number <span class="text-red-400">*</span>
                                    <span class="relative group inline-flex items-center">
                                        <svg class="w-4 h-4 text-zinc-500 hover:text-cyan-400 cursor-pointer transition-colors duration-150 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd" />
                                        </svg>
                                        <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-72 rounded-lg bg-zinc-800 border border-zinc-700 px-3.5 py-2.5 text-xs font-normal normal-case tracking-normal text-zinc-300 leading-relaxed shadow-xl opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-50">
                                            By providing your phone number, you consent to receive SMS updates about your repair status and scheduling. Message and data rates may apply. Reply STOP to opt out anytime.
                                            <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-zinc-700"></span>
                                        </span>
                                    </span>
                                </label>
                                <input type="tel" id="phone" name="phone" placeholder="+1 (555) 000-0000" required
                                    value="<?= $sessionCustomer ? hv($sessionCustomer['phone']) : '' ?>"
                                    class="input-base">
                            </div>
                        </div>
                        <div class="mt-5">
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="email">Email Address <span class="text-red-400">*</span></label>
                            <input type="email" id="email" name="email" placeholder="jane@company.com" required
                                value="<?= $sessionCustomer ? hv($sessionCustomer['email']) : '' ?>"
                                <?= $sessionCustomer ? 'readonly' : '' ?>
                                class="input-base<?= $sessionCustomer ? ' opacity-70 cursor-not-allowed' : '' ?>">
                        </div>
                    </div>

                    <div class="border-t border-zinc-800"></div>

                    <!-- Section: Machine Details -->
                    <div>
                        <p class="text-xs text-cyan-400 font-semibold tracking-widest uppercase mb-4">Machine Details</p>
                        <div class="grid sm:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="machine_brand">Brand <span class="text-red-400">*</span></label>
                                <input type="text" id="machine_brand" name="machine_brand" placeholder="e.g. Epilog, Thunder Laser, xTool" required
                                    class="input-base">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="machine_model">Model <span class="text-red-400">*</span></label>
                                <input type="text" id="machine_model" name="machine_model" placeholder="e.g. Fusion Pro 48, Nova 35" required
                                    class="input-base">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="watts">Wattage <span class="text-zinc-600 normal-case font-normal">(optional)</span></label>
                                <input type="number" id="watts" name="watts" placeholder="e.g. 60" min="1"
                                    class="input-base">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="age">Machine Age <span class="text-zinc-600 normal-case font-normal">(optional)</span></label>
                                <input type="text" id="age" name="age" placeholder="e.g. 3 years"
                                    class="input-base">
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-zinc-800"></div>

                    <!-- Section: Service Address -->
                    <div>
                        <p class="text-xs text-cyan-400 font-semibold tracking-widest uppercase mb-4">Service Address</p>
                        <div class="space-y-5">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="street">Street Address <span class="text-red-400">*</span></label>
                                <input type="text" id="street" name="street" placeholder="123 Workshop Lane" required
                                    value="<?= $sessionCustomer ? hv($sessionCustomer['street']) : '' ?>"
                                    class="input-base">
                            </div>
                            <div class="grid sm:grid-cols-3 gap-5">
                                <div class="sm:col-span-1">
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="city">City <span class="text-red-400">*</span></label>
                                    <input type="text" id="city" name="city" placeholder="Austin" required
                                        value="<?= $sessionCustomer ? hv($sessionCustomer['city']) : '' ?>"
                                        class="input-base">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="state">State <span class="text-red-400">*</span></label>
                                    <input type="text" id="state" name="state" placeholder="TX" required maxlength="2"
                                        value="<?= $sessionCustomer ? hv($sessionCustomer['state']) : '' ?>"
                                        class="input-base uppercase">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="zip">ZIP Code <span class="text-red-400">*</span></label>
                                    <input type="text" id="zip" name="zip" placeholder="78701" required
                                        value="<?= $sessionCustomer ? hv($sessionCustomer['zip']) : '' ?>"
                                        class="input-base">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-zinc-800"></div>

                    <!-- Section: Problem & Priority -->
                    <div>
                        <p class="text-xs text-cyan-400 font-semibold tracking-widest uppercase mb-4">Problem &amp; Priority</p>
                        <div class="mb-5">
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="problem">Problem Description <span class="text-red-400">*</span></label>
                            <textarea id="problem" name="problem" rows="4" required
                                placeholder="Describe what your machine is doing (or not doing). Include any error messages, sounds, or recent changes..."
                                class="input-base resize-none" style="height:auto"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-3 uppercase tracking-wide">Priority Level <span class="text-red-400">*</span></label>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">

                                <div class="priority-radio">
                                    <input type="radio" id="priority-standard" name="priority" value="standard" checked>
                                    <label for="priority-standard">
                                        <span class="priority-dot bg-zinc-400"></span>
                                        <span>
                                            <span class="block font-semibold text-sm">Standard</span>
                                            <span class="block text-zinc-500 text-xs">3–5 Business Days</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="priority-radio">
                                    <input type="radio" id="priority-vip" name="priority" value="vip">
                                    <label for="priority-vip">
                                        <span class="priority-dot bg-cyan-400"></span>
                                        <span>
                                            <span class="block font-semibold text-sm">VIP</span>
                                            <span class="block text-zinc-500 text-xs">1–2 Business Days</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="priority-radio">
                                    <input type="radio" id="priority-emergency" name="priority" value="emergency">
                                    <label for="priority-emergency">
                                        <span class="priority-dot bg-red-400"></span>
                                        <span>
                                            <span class="block font-semibold text-sm">Emergency</span>
                                            <span class="block text-zinc-500 text-xs">Same or Next day</span>
                                        </span>
                                    </label>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
                        <label for="website">Website</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <!-- Submit -->
                    <button type="submit" id="submit-btn"
                        class="w-full bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-bold text-sm py-3.5 rounded-lg transition-all btn-glow flex items-center justify-center gap-2">
                        <span id="submit-label">Book My Repair →</span>
                        <svg id="submit-spinner" class="hidden w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                        </svg>
                    </button>

                    <p class="text-center text-xs text-zinc-600">
                        By submitting this form you agree to be contacted by our repair team regarding your booking.
                    </p>

                </form>
            </div><!-- /#booking-form-wrap -->

        </div>
    </section>

    <script>
        // ── Helpers ──────────────────────────────────────────────────────────────
        const CUSTOMER_ID_INITIAL = <?= isset($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : 'null' ?>;
        let customerId = CUSTOMER_ID_INITIAL;

        function show(el) { el.classList.remove('hidden'); }
        function hide(el) { el.classList.add('hidden'); }

        // ── Gate panels ──────────────────────────────────────────────────────────
        const gateChoice      = document.getElementById('gate-choice');
        const gateLogin       = document.getElementById('gate-login');
        const bookingWrap     = document.getElementById('booking-form-wrap');
        const loggedInBanner  = document.getElementById('logged-in-banner');
        const loggedInEmail   = document.getElementById('logged-in-email');

        if (gateChoice) {
            document.getElementById('btn-show-login').addEventListener('click', () => {
                hide(gateChoice);
                show(gateLogin);
                document.getElementById('login-email').focus();
            });

            document.getElementById('btn-guest').addEventListener('click', () => {
                hide(gateChoice);
                show(bookingWrap);
                bookingWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }

        if (gateLogin) {
            document.getElementById('btn-back-from-login').addEventListener('click', () => {
                hide(gateLogin);
                show(gateChoice);
                document.getElementById('login-error').classList.add('hidden');
            });

            // Password toggle
            const toggleBtn     = document.getElementById('toggle-login-password');
            const passwordInput = document.getElementById('login-password');
            toggleBtn.addEventListener('click', () => {
                const isPassword = passwordInput.type === 'password';
                passwordInput.type = isPassword ? 'text' : 'password';
                toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });

            // Allow Enter key in password field to submit
            passwordInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') loginSubmit();
            });
            document.getElementById('login-email').addEventListener('keydown', (e) => {
                if (e.key === 'Enter') loginSubmit();
            });

            document.getElementById('login-submit').addEventListener('click', loginSubmit);
        }

        async function loginSubmit() {
            const emailVal    = document.getElementById('login-email').value.trim();
            const passwordVal = document.getElementById('login-password').value;
            const errorBox    = document.getElementById('login-error');
            const errorText   = document.getElementById('login-error-text');
            const submitBtn   = document.getElementById('login-submit');
            const submitLabel = document.getElementById('login-submit-label');
            const spinner     = document.getElementById('login-submit-spinner');

            if (!emailVal || !passwordVal) {
                errorText.textContent = 'Please enter your email and password.';
                show(errorBox);
                return;
            }

            hide(errorBox);
            submitBtn.disabled = true;
            submitLabel.textContent = 'Signing in…';
            show(spinner);

            try {
                const res  = await fetch('/customer-login-ajax.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ email: emailVal, password: passwordVal }),
                });
                const json = await res.json().catch(() => ({}));

                if (res.ok && json.success) {
                    customerId = null; // server session is set, let API pick it up via session
                    prefillForm(json);
                    hide(gateLogin);
                    show(bookingWrap);
                    if (loggedInBanner && loggedInEmail) {
                        loggedInEmail.textContent = json.email;
                        show(loggedInBanner);
                    }
                    bookingWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    errorText.textContent = json.message || 'Invalid email or password.';
                    show(errorBox);
                }
            } catch {
                errorText.textContent = 'Network error — please check your connection.';
                show(errorBox);
            } finally {
                submitBtn.disabled = false;
                submitLabel.textContent = 'Sign In & Continue';
                hide(spinner);
            }
        }

        function prefillForm(data) {
            const fullName = [data.first_name, data.last_name].filter(Boolean).join(' ');
            setField('name',   fullName);
            setField('phone',  data.phone  || '');
            setField('email',  data.email  || '');
            setField('street', data.street || '');
            setField('city',   data.city   || '');
            setField('state',  data.state  || '');
            setField('zip',    data.zip    || '');

            // Lock email for logged-in users
            const emailInput = document.getElementById('email');
            if (emailInput) {
                emailInput.readOnly = true;
                emailInput.classList.add('opacity-70', 'cursor-not-allowed');
            }
        }

        function setField(id, value) {
            const el = document.getElementById(id);
            if (el && value) el.value = value;
        }

        // ── Format date ───────────────────────────────────────────────────────────
        function formatDate(dateStr) {
            const [year, month, day] = dateStr.split('-').map(Number);
            const date = new Date(year, month - 1, day);
            const monthName = date.toLocaleString('en-US', { month: 'long' });
            const suffix = day === 1 || day === 21 || day === 31 ? 'st'
                         : day === 2 || day === 22 ? 'nd'
                         : day === 3 || day === 23 ? 'rd' : 'th';
            return `${monthName} ${day}${suffix}, ${year}`;
        }

        // ── Booking form submission ───────────────────────────────────────────────
        const form         = document.getElementById('repair-form');
        const submitBtn    = document.getElementById('submit-btn');
        const submitLabel  = document.getElementById('submit-label');
        const submitSpinner = document.getElementById('submit-spinner');
        const msgSuccess   = document.getElementById('msg-success');
        const msgError     = document.getElementById('msg-error');
        const msgErrorText = document.getElementById('msg-error-text');

        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                msgSuccess.classList.add('hidden');
                msgError.classList.add('hidden');

                submitBtn.disabled = true;
                submitLabel.textContent = 'Submitting…';
                submitSpinner.classList.remove('hidden');

                const data = {
                    name:          form.name.value.trim(),
                    phone:         form.phone.value.trim(),
                    email:         form.email.value.trim(),
                    machine_brand: document.getElementById('machine_brand').value.trim(),
                    machine_model: document.getElementById('machine_model').value.trim(),
                    watts:         document.getElementById('watts').value.trim() || null,
                    age:           document.getElementById('age').value.trim() || null,
                    street:        form.street.value.trim(),
                    city:          form.city.value.trim(),
                    state:         form.state.value.trim().toUpperCase(),
                    zip:           form.zip.value.trim(),
                    problem:       form.problem.value.trim(),
                    priority:      form.priority.value,
                    website:       form.website.value.trim(),
                };

                if (CUSTOMER_ID_INITIAL) {
                    data.customer_id = CUSTOMER_ID_INITIAL;
                }

                try {
                    const res = await fetch('/project/api/book-repair-api.php', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify(data),
                    });

                    const json = await res.json().catch(() => ({}));

                    if (res.ok) {
                        form.reset();
                        document.getElementById('priority-standard').checked = true;

                        const priorityEl   = document.getElementById('success-priority');
                        const datesEl      = document.getElementById('success-dates');
                        const headingEl    = document.getElementById('success-heading');
                        const subtextEl    = document.getElementById('success-subtext');
                        const datesLabelEl = document.getElementById('success-dates-label');

                        const priority = json.priority || 'standard';
                        const priorityConfig = {
                            standard: { label: 'Standard',  badgeClasses: ['bg-zinc-500/15',  'text-zinc-200',  'border-zinc-400/30']  },
                            vip:      { label: 'VIP',       badgeClasses: ['bg-cyan-500/15',  'text-cyan-200',  'border-cyan-400/30']  },
                            emergency:{ label: 'Emergency', badgeClasses: ['bg-red-500/15',   'text-red-200',   'border-red-400/30']   }
                        };
                        const activePriority = priorityConfig[priority] || priorityConfig.standard;
                        const priorityLabel  = activePriority.label;
                        priorityEl.textContent = priorityLabel;
                        priorityEl.className = 'inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold tracking-wide';
                        priorityEl.classList.add(...activePriority.badgeClasses);

                        headingEl.textContent = 'Thank you. We\'ve received your request.';
                        if (priority === 'emergency') {
                            subtextEl.textContent = 'Your Emergency request has been received and flagged. Our team will contact you as soon as possible — even on weekends.';
                        } else if (priority === 'vip') {
                            subtextEl.textContent = 'Your VIP request has been received. We\'ll contact you shortly to schedule your repair. VIP jobs are typically seen within 1–2 business days.';
                        } else {
                            subtextEl.textContent = 'We\'ll contact you shortly to schedule your repair. Standard priority jobs are typically seen within 3–5 business days.';
                        }

                        datesLabelEl.textContent = `Based on your ${priorityLabel} priority, here are your suggested service dates:`;

                        datesEl.innerHTML = '';
                        const dates = Array.isArray(json.suggested_dates) ? json.suggested_dates : [];
                        if (dates.length > 0) {
                            dates.forEach(d => {
                                const li = document.createElement('li');
                                li.className = 'flex items-center gap-2.5 rounded-xl border border-emerald-500/15 bg-emerald-500/5 px-3 py-2 text-emerald-100';
                                li.innerHTML = `<svg class="w-3.5 h-3.5 text-emerald-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg><span>${formatDate(d)}</span>`;
                                datesEl.appendChild(li);
                            });
                        } else {
                            const li = document.createElement('li');
                            li.className = 'rounded-xl border border-emerald-500/15 bg-emerald-500/5 px-3 py-2 text-emerald-100/75';
                            li.textContent = "We'll be in touch shortly to arrange a date.";
                            datesEl.appendChild(li);
                        }

                        msgSuccess.classList.remove('hidden');
                        msgSuccess.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    } else {
                        msgErrorText.textContent = json.message || 'Please check your details and try again.';
                        msgError.classList.remove('hidden');
                        msgError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                } catch {
                    msgErrorText.textContent = 'Network error — please check your connection and try again.';
                    msgError.classList.remove('hidden');
                    msgError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } finally {
                    submitBtn.disabled = false;
                    submitLabel.textContent = 'Book My Repair →';
                    submitSpinner.classList.add('hidden');
                }
            });
        }
    </script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
