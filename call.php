<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Call Now</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            height: 100%;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #09090b;
            color: #fff;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.25rem;
            text-align: center;
            user-select: none;
            -webkit-user-select: none;
        }

        .label {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: #52525b;
            margin-bottom: 2rem;
        }

        #phone-display {
            font-size: clamp(3.5rem, 16vw, 7rem);
            font-weight: 900;
            letter-spacing: -0.01em;
            color: #ffffff;
            line-height: 1;
            word-break: break-all;
            transition: opacity 0.25s ease, transform 0.25s ease;
            min-height: 1em;
        }

        #phone-display.fade-out {
            opacity: 0;
            transform: scale(0.95);
        }

        #phone-display.empty {
            color: #3f3f46;
        }

        #call-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-top: 3rem;
            padding: 1.25rem 0;
            background: #16a34a;
            color: #fff;
            font-size: 1.6rem;
            font-weight: 800;
            border: none;
            border-radius: 9999px;
            cursor: pointer;
            text-decoration: none;
            width: 100%;
            max-width: 420px;
            transition: background 0.15s ease, transform 0.1s ease, opacity 0.15s ease;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
        }

        #call-btn:hover  { background: #15803d; }
        #call-btn:active { transform: scale(0.96); background: #166534; }

        #call-btn.disabled {
            background: #1c1c1e;
            color: #3f3f46;
            pointer-events: none;
            cursor: default;
        }

        #call-btn svg {
            width: 1.6rem;
            height: 1.6rem;
            flex-shrink: 0;
        }

        .hint {
            margin-top: 2.25rem;
            font-size: 0.72rem;
            color: #3f3f46;
            letter-spacing: 0.03em;
        }

        .dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #3f3f46;
            margin-right: 0.4rem;
            vertical-align: middle;
            transition: background 0.3s ease;
        }

        .dot.live { background: #22c55e; }
    </style>
</head>
<body>

    <p class="label">Send to Phone</p>

    <div id="phone-display" class="empty">—</div>

    <a id="call-btn" href="#" class="disabled">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.15 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.07 1.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.09 9.09a16 16 0 0 0 5.82 5.82l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
        </svg>
        Call Now
    </a>

    <p class="hint">
        <span class="dot" id="status-dot"></span>
        <span id="status-text">Waiting for a number…</span>
    </p>

<script>
(function () {
    const STORAGE_KEY  = 'ghost_call_number';
    const CHANNEL_NAME = 'ghost_call_channel';

    const display    = document.getElementById('phone-display');
    const btn        = document.getElementById('call-btn');
    const statusDot  = document.getElementById('status-dot');
    const statusText = document.getElementById('status-text');

    // ── Audio context (created lazily after first user interaction) ────────
    let audioCtx = null;

    function getAudioCtx() {
        if (!audioCtx) {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        // Resume if the browser suspended it (autoplay policy)
        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
        return audioCtx;
    }

    // Play a single short sine-wave beep
    function beep(frequency, startTime, duration) {
        const ctx      = getAudioCtx();
        const osc      = ctx.createOscillator();
        const gain     = ctx.createGain();

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.type      = 'sine';
        osc.frequency.setValueAtTime(frequency, startTime);

        // Soft envelope: quick fade-in, hold, gentle fade-out
        gain.gain.setValueAtTime(0, startTime);
        gain.gain.linearRampToValueAtTime(0.18, startTime + 0.01);
        gain.gain.setValueAtTime(0.18, startTime + duration - 0.04);
        gain.gain.linearRampToValueAtTime(0, startTime + duration);

        osc.start(startTime);
        osc.stop(startTime + duration);
    }

    // Two soft beeps — 880 Hz then 1100 Hz, 120 ms each, 80 ms apart
    function playDoubleBeep() {
        try {
            const ctx   = getAudioCtx();
            const now   = ctx.currentTime;
            beep(880,  now,        0.12);
            beep(1100, now + 0.20, 0.12);
        } catch (e) {
            // Audio not supported or blocked — silently ignore
        }
    }

    // Unlock audio on first touch/click so autoplay policy is satisfied
    function unlockAudio() {
        getAudioCtx();
        document.removeEventListener('touchstart', unlockAudio, true);
        document.removeEventListener('click',      unlockAudio, true);
    }
    document.addEventListener('touchstart', unlockAudio, true);
    document.addEventListener('click',      unlockAudio, true);

    function formatPhone(raw) {
        if (!raw) return raw;
        const digits = raw.replace(/\D/g, '');
        if (digits.length === 11 && digits[0] === '1') {
            return '(' + digits.slice(1,4) + ') ' + digits.slice(4,7) + '-' + digits.slice(7);
        }
        if (digits.length === 10) {
            return '(' + digits.slice(0,3) + ') ' + digits.slice(3,6) + '-' + digits.slice(6);
        }
        return raw;
    }

    function setNumber(raw) {
        const digits = raw ? raw.replace(/\D/g, '') : '';

        playDoubleBeep();

        // Fade out → update → fade in
        display.classList.add('fade-out');
        setTimeout(function () {
            if (digits) {
                display.textContent = formatPhone(raw);
                display.classList.remove('empty');
                btn.href = 'tel:' + digits;
                btn.classList.remove('disabled');
                statusDot.classList.add('live');
                statusText.textContent = 'Number received — tap Call Now to dial';
            } else {
                display.textContent = '—';
                display.classList.add('empty');
                btn.href = '#';
                btn.classList.add('disabled');
                statusDot.classList.remove('live');
                statusText.textContent = 'Waiting for a number…';
            }
            display.classList.remove('fade-out');
        }, 250);
    }

    // ── Seed from last stored number ──────────────────────────────────────
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored) {
        // Apply immediately without animation on initial load
        const digits = stored.replace(/\D/g, '');
        display.textContent = formatPhone(stored);
        display.classList.remove('empty');
        btn.href = 'tel:' + digits;
        btn.classList.remove('disabled');
        statusDot.classList.add('live');
        statusText.textContent = 'Number received — tap Call Now to dial';
    }

    // ── BroadcastChannel (same-origin cross-tab, instant) ─────────────────
    if (typeof BroadcastChannel !== 'undefined') {
        const bc = new BroadcastChannel(CHANNEL_NAME);
        bc.onmessage = function (e) {
            if (e.data && e.data.number !== undefined) {
                localStorage.setItem(STORAGE_KEY, e.data.number);
                setNumber(e.data.number);
            }
        };
    }

    // ── localStorage polling fallback (1.5 s interval) ────────────────────
    let lastValue = localStorage.getItem(STORAGE_KEY) || '';
    setInterval(function () {
        const current = localStorage.getItem(STORAGE_KEY) || '';
        if (current !== lastValue) {
            lastValue = current;
            setNumber(current);
        }
    }, 1500);

})();
</script>
</body>
</html>
