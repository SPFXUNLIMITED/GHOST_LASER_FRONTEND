<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}
?>
<?php
$pageTitle       = 'SMS Invite | Ghost Laser';
$pageDescription = 'Send an SMS invite to a customer.';
$pwaHead         = <<<'HTML'
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#09090b">
    <link rel="apple-touch-icon" href="/ghost-logo-250x250.png">
    <link rel="icon" type="image/png" sizes="250x250" href="/ghost-logo-250x250.png">
    <link rel="manifest" href="/manifest.json">
HTML;
$extraHead       = <<<'HTML'
    <style>
        /* ── Nixie-tube phone display ───────────────────── */
        .phone-display {
            display: flex;
            align-items: center;
            gap: 0;
            padding: 0.85rem 1.1rem;
            background: #040406;
            border: 1px solid rgba(6,182,212,0.18);
            border-radius: 0.75rem;
            box-shadow:
                inset 0 3px 10px rgba(0,0,0,0.85),
                0 0 20px rgba(139,92,246,0.06);
            cursor: pointer;
            width: 100%;
            min-height: 4.4rem;
            justify-content: center;
            user-select: none;
            position: relative;
        }
        .phone-display:focus {
            outline: none;
            border-color: rgba(139,92,246,0.5);
            box-shadow:
                inset 0 3px 10px rgba(0,0,0,0.85),
                0 0 0 2px rgba(139,92,246,0.25),
                0 0 20px rgba(139,92,246,0.12);
        }
        .phone-formatted {
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 2rem;
            font-weight: 700;
            color: #f97316;
            text-shadow:
                0 0 6px rgba(249,115,22,0.95),
                0 0 18px rgba(249,115,22,0.55),
                0 0 38px rgba(249,115,22,0.28);
            letter-spacing: 0.04em;
            line-height: 1;
            transition: color 0.1s, text-shadow 0.1s;
        }
        .phone-formatted.dim {
            color: rgba(249,115,22,0.25);
            text-shadow: none;
        }
        .phone-display-hint {
            font-size: 0.72rem;
            color: #52525b;
            text-align: center;
            margin-top: 0.35rem;
            letter-spacing: 0.05em;
        }

        /* ── Keypad modal overlay ───────────────────────── */
        .numpad-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9000;
            background: rgba(9,9,11,0.82);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            align-items: flex-end;
            justify-content: center;
        }
        .numpad-modal.open {
            display: flex;
        }
        .numpad-inner {
            background: #18181b;
            border: 1px solid rgba(63,63,70,0.9);
            border-bottom: none;
            border-radius: 1.25rem 1.25rem 0 0;
            padding: 1.4rem 1.4rem 2rem;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 -8px 40px rgba(0,0,0,0.7);
        }
        .numpad-title {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #52525b;
            text-align: center;
            margin-bottom: 1rem;
        }

        /* Reuse nixie display inside modal */
        .nixie-display {
            display: flex;
            align-items: center;
            gap: 0;
            padding: 0.75rem 1rem;
            background: #040406;
            border: 1px solid rgba(6,182,212,0.18);
            border-radius: 0.75rem;
            box-shadow: inset 0 3px 10px rgba(0,0,0,0.85);
            justify-content: center;
            margin-bottom: 0.5rem;
        }
        .nixie-phone-text {
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 1.85rem;
            font-weight: 700;
            color: #f97316;
            text-shadow:
                0 0 6px rgba(249,115,22,0.95),
                0 0 18px rgba(249,115,22,0.55),
                0 0 38px rgba(249,115,22,0.28);
            letter-spacing: 0.04em;
            line-height: 1;
        }
        .nixie-phone-text.dim {
            color: rgba(249,115,22,0.22);
            text-shadow: none;
        }

        /* Keypad grid */
        .keypad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.55rem;
            width: 100%;
            margin-top: 1rem;
        }
        .keypad-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 3.4rem;
            border-radius: 0.625rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: transform 0.08s, background 0.1s, border-color 0.1s;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
        }
        .keypad-btn:active { transform: scale(0.91); }
        .keypad-num {
            font-size: 1.3rem;
            background: rgba(39,39,42,0.7);
            border: 1px solid rgba(63,63,70,0.8);
            color: #e4e4e7;
        }
        .keypad-num:hover {
            background: rgba(63,63,70,0.65);
            border-color: rgba(139,92,246,0.35);
        }
        .keypad-back {
            font-size: 1.15rem;
            background: rgba(39,39,42,0.7);
            border: 1px solid rgba(63,63,70,0.8);
            color: #a1a1aa;
        }
        .keypad-back:hover {
            background: rgba(63,63,70,0.65);
            border-color: rgba(139,92,246,0.35);
        }
        .keypad-clear {
            font-size: 0.72rem;
            letter-spacing: 0.1em;
            background: rgba(39,39,42,0.7);
            border: 1px solid rgba(63,63,70,0.8);
            color: #a1a1aa;
        }
        .keypad-clear:hover {
            border-color: rgba(239,68,68,0.4);
            color: #fca5a5;
        }
        .keypad-actions {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 0.55rem;
            width: 100%;
            margin-top: 0.55rem;
        }
        .keypad-cancel {
            height: 3rem;
            background: rgba(39,39,42,0.45);
            border: 1px solid rgba(63,63,70,0.55);
            color: #71717a;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.07em;
            border-radius: 0.625rem;
            cursor: pointer;
            transition: color 0.1s, border-color 0.1s, transform 0.08s;
            -webkit-tap-highlight-color: transparent;
        }
        .keypad-cancel:hover { color: #a1a1aa; border-color: rgba(113,113,122,0.6); }
        .keypad-cancel:active { transform: scale(0.96); }
        .keypad-confirm {
            height: 3rem;
            background: linear-gradient(135deg, rgba(139,92,246,0.28), rgba(139,92,246,0.16));
            border: 1px solid rgba(139,92,246,0.55);
            color: #c4b5fd;
            font-size: 0.88rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            border-radius: 0.625rem;
            cursor: pointer;
            text-transform: uppercase;
            transition: background 0.1s, border-color 0.1s, transform 0.08s;
            -webkit-tap-highlight-color: transparent;
        }
        .keypad-confirm:hover {
            background: linear-gradient(135deg, rgba(139,92,246,0.42), rgba(139,92,246,0.28));
            border-color: rgba(167,139,250,0.7);
        }
        .keypad-confirm:active { transform: scale(0.96); }

        /* ── Form styles ────────────────────────────────── */
        .sms-card {
            box-shadow: 0 0 0 1px rgba(139,92,246,0.12), 0 0 60px rgba(139,92,246,0.05);
        }
        .btn-glow-violet {
            box-shadow: 0 0 20px rgba(139,92,246,0.4);
        }
        .btn-glow-violet:hover {
            box-shadow: 0 0 30px rgba(139,92,246,0.65);
        }
    </style>
HTML;
$headerRight = <<<'HTML'
            <div class="flex items-center gap-3">
                <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Dashboard</a>
            </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen hero-grid flex items-center justify-center px-4 py-24">
        <!-- Ambient glow -->
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none overflow-hidden">
            <div class="w-[500px] h-[500px] rounded-full bg-violet-500/5 blur-3xl"></div>
        </div>

        <div class="relative w-full max-w-md">
            <!-- Header -->
            <div class="flex flex-col gap-2 mb-8">
                <span class="inline-flex items-center gap-2 rounded-full border border-violet-500/30 bg-violet-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-violet-400 w-fit">
                    Customer Outreach
                </span>
                <h1 class="text-3xl font-bold tracking-tight">SMS Invite</h1>
                <p class="text-zinc-400 text-sm leading-relaxed">
                    Send a booking invite directly to a customer's phone.
                </p>
            </div>

            <!-- Card -->
            <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-7 sms-card">

                <!-- Customer Name -->
                <div class="mb-6">
                    <label for="customerName" class="block text-sm font-semibold text-zinc-300 mb-2">
                        Customer Name
                    </label>
                    <input
                        type="text"
                        id="customerName"
                        placeholder="e.g. John"
                        autocomplete="off"
                        class="w-full rounded-md border border-zinc-700 bg-zinc-800/60 px-4 py-3 text-white placeholder-zinc-500 text-sm focus:outline-none focus:border-violet-500/60 focus:ring-2 focus:ring-violet-500/20 transition-all"
                    >
                </div>

                <!-- Phone Number -->
                <div class="mb-8">
                    <label class="block text-sm font-semibold text-zinc-300 mb-2">
                        Customer Phone Number
                    </label>

                    <!-- Nixie display (tap to open keypad) -->
                    <button
                        type="button"
                        id="phoneDisplayBtn"
                        class="phone-display"
                        aria-label="Tap to enter phone number"
                        title="Tap to enter phone number"
                    >
                        <span class="phone-formatted dim" id="phoneFormatted">(_ _ _) _ _ _ - _ _ _ _</span>
                    </button>
                    <p class="phone-display-hint">Tap to enter number</p>

                    <!-- Hidden raw value used for SMS link -->
                    <input type="hidden" id="phoneRaw" value="">
                </div>

                <!-- Send button -->
                <button
                    type="button"
                    id="sendSmsBtn"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-violet-600 hover:bg-violet-500 text-white font-bold text-sm px-4 py-3.5 transition-all btn-glow-violet"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3v-3z"/>
                    </svg>
                    Send SMS Now
                </button>

                <p id="smsError" class="mt-3 text-sm text-red-400 text-center hidden"></p>
            </div>
        </div>
    </main>

<!-- ── Number Pad Modal ──────────────────────────────────────────────────── -->
<div id="numpadModal" class="numpad-modal" role="dialog" aria-modal="true" aria-label="Enter phone number">
    <div class="numpad-inner">
        <div class="numpad-title">Enter Phone Number</div>

        <!-- Nixie-style phone display -->
        <div class="nixie-display">
            <span class="nixie-phone-text dim" id="nixiePhone">(_ _ _) _ _ _ - _ _ _ _</span>
        </div>

        <!-- Keypad -->
        <div class="keypad" id="phoneKeypad">
            <button class="keypad-btn keypad-num" data-digit="7">7</button>
            <button class="keypad-btn keypad-num" data-digit="8">8</button>
            <button class="keypad-btn keypad-num" data-digit="9">9</button>
            <button class="keypad-btn keypad-num" data-digit="4">4</button>
            <button class="keypad-btn keypad-num" data-digit="5">5</button>
            <button class="keypad-btn keypad-num" data-digit="6">6</button>
            <button class="keypad-btn keypad-num" data-digit="1">1</button>
            <button class="keypad-btn keypad-num" data-digit="2">2</button>
            <button class="keypad-btn keypad-num" data-digit="3">3</button>
            <button class="keypad-btn keypad-clear" id="numpadClear">CLR</button>
            <button class="keypad-btn keypad-num" data-digit="0">0</button>
            <button class="keypad-btn keypad-back" id="numpadBack">&#x232B;</button>
        </div>

        <div class="keypad-actions">
            <button class="keypad-btn keypad-cancel" id="numpadCancel">Cancel</button>
            <button class="keypad-btn keypad-confirm" id="numpadConfirm">Done</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    /* ── State ── */
    var _digits = '';          // raw 10 digits (max)
    var _committed = '';       // confirmed raw value

    /* ── Elements ── */
    var modal        = document.getElementById('numpadModal');
    var nixiePhone   = document.getElementById('nixiePhone');
    var phoneRaw     = document.getElementById('phoneRaw');
    var phoneFormatted = document.getElementById('phoneFormatted');
    var phoneDisplayBtn = document.getElementById('phoneDisplayBtn');
    var sendSmsBtn   = document.getElementById('sendSmsBtn');
    var smsError     = document.getElementById('smsError');

    /* ── Helpers ── */
    function formatPhone(digits) {
        // digits: up to 10 numeric characters
        var d = digits.replace(/\D/g, '').slice(0, 10);
        if (d.length === 0) return '(_ _ _) _ _ _ - _ _ _ _';
        if (d.length <= 3)  return '(' + d + ')';
        if (d.length <= 6)  return '(' + d.slice(0,3) + ') ' + d.slice(3);
        return '(' + d.slice(0,3) + ') ' + d.slice(3,6) + '-' + d.slice(6);
    }

    function updateNixie() {
        var formatted = formatPhone(_digits);
        nixiePhone.textContent = formatted;
        nixiePhone.classList.toggle('dim', _digits.length === 0);
    }

    function openModal() {
        _digits = _committed; // restore last committed value
        updateNixie();
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }

    function confirmModal() {
        _committed = _digits;
        phoneRaw.value = _committed;
        var formatted = formatPhone(_committed);
        phoneFormatted.textContent = _committed.length > 0 ? formatted : '(_ _ _) _ _ _ - _ _ _ _';
        phoneFormatted.classList.toggle('dim', _committed.length === 0);
        closeModal();
    }

    /* ── Keypad events ── */
    document.getElementById('phoneKeypad').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-digit]');
        if (!btn) return;
        if (_digits.length >= 10) return;
        _digits += btn.dataset.digit;
        updateNixie();
        // Auto-confirm when 10 digits entered
        if (_digits.length === 10) {
            setTimeout(confirmModal, 180);
        }
    });

    document.getElementById('numpadBack').addEventListener('click', function () {
        _digits = _digits.slice(0, -1);
        updateNixie();
    });

    document.getElementById('numpadClear').addEventListener('click', function () {
        _digits = '';
        updateNixie();
    });

    document.getElementById('numpadCancel').addEventListener('click', closeModal);
    document.getElementById('numpadConfirm').addEventListener('click', confirmModal);

    /* Close on backdrop click */
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    /* Close on Escape */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    /* Open on display tap */
    phoneDisplayBtn.addEventListener('click', openModal);

    /* ── Send SMS ── */
    sendSmsBtn.addEventListener('click', function () {
        smsError.classList.add('hidden');
        smsError.textContent = '';

        var name  = document.getElementById('customerName').value.trim();
        var phone = phoneRaw.value.replace(/\D/g, '');

        if (phone.length !== 10) {
            smsError.textContent = 'Please enter a valid 10-digit phone number.';
            smsError.classList.remove('hidden');
            return;
        }

        var e164 = '+1' + phone;
        var greeting = name !== '' ? 'Hi ' + name + ',' : 'Hi,';
        var message = greeting + ' please book your repair for your CO2 laser cutter at ghostlaser.com to get the best service.';
        var smsLink = 'sms:' + e164 + '?body=' + encodeURIComponent(message);
        window.location.href = smsLink;
    });

})();
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
