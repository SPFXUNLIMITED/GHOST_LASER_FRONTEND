<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Call Now</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #0a0a0a;
            color: #fff;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem;
            text-align: center;
        }

        .label {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: #71717a;
            margin-bottom: 1.25rem;
        }

        #phone-display {
            font-size: clamp(2.5rem, 10vw, 5rem);
            font-weight: 800;
            letter-spacing: 0.04em;
            color: #fff;
            line-height: 1.1;
            word-break: break-all;
            transition: opacity 0.3s ease;
        }

        #phone-display.updating {
            opacity: 0.3;
        }

        #call-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            margin-top: 2.5rem;
            padding: 1.1rem 3rem;
            background: #16a34a;
            color: #fff;
            font-size: 1.35rem;
            font-weight: 700;
            border: none;
            border-radius: 9999px;
            cursor: pointer;
            text-decoration: none;
            width: 100%;
            max-width: 360px;
            transition: background 0.2s ease, transform 0.1s ease;
            -webkit-tap-highlight-color: transparent;
        }

        #call-btn:hover { background: #15803d; }
        #call-btn:active { transform: scale(0.97); background: #166534; }

        #call-btn svg {
            width: 1.4rem;
            height: 1.4rem;
            flex-shrink: 0;
        }

        .hint {
            margin-top: 2.5rem;
            font-size: 0.75rem;
            color: #52525b;
        }

        .no-number {
            color: #52525b;
        }
    </style>
</head>
<body>

    <p class="label">Send to Phone</p>

    <div id="phone-display" class="no-number">—</div>

    <a id="call-btn" href="#">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.15 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.07 1.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.09 9.09a16 16 0 0 0 5.82 5.82l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
        </svg>
        Call Now
    </a>

    <p class="hint" id="status-hint">Waiting for a number…</p>

<script>
(function () {
    const STORAGE_KEY = 'ghost_call_number';

    const display  = document.getElementById('phone-display');
    const btn      = document.getElementById('call-btn');
    const hint     = document.getElementById('status-hint');

    // Format a 10-digit US number as (949) 945-0922
    function formatPhone(raw) {
        const digits = raw.replace(/\D/g, '');
        if (digits.length === 11 && digits[0] === '1') {
            return '(' + digits.slice(1, 4) + ') ' + digits.slice(4, 7) + '-' + digits.slice(7);
        }
        if (digits.length === 10) {
            return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
        }
        return raw; // return as-is if non-standard
    }

    function applyNumber(raw) {
        if (!raw) {
            display.textContent = '—';
            display.classList.add('no-number');
            btn.href = '#';
            hint.textContent = 'Waiting for a number…';
            return;
        }

        const digits = raw.replace(/\D/g, '');
        display.textContent = formatPhone(raw);
        display.classList.remove('no-number');
        btn.href = 'tel:' + digits;
        hint.textContent = 'Tab stays open — number will update automatically';
    }

    function flash() {
        display.classList.add('updating');
        setTimeout(() => display.classList.remove('updating'), 350);
    }

    // ── 1. Read from URL param on load ─────────────────────────────────────
    const params = new URLSearchParams(window.location.search);
    const urlNumber = params.get('number');

    if (urlNumber) {
        localStorage.setItem(STORAGE_KEY, urlNumber);
        applyNumber(urlNumber);
        // Clean the URL so refreshing doesn't re-apply the same number
        history.replaceState({}, '', window.location.pathname);
    } else {
        // Fall back to last stored number
        const stored = localStorage.getItem(STORAGE_KEY);
        applyNumber(stored || '');
    }

    // ── 2. BroadcastChannel — instant update when another tab sends a number ─
    if (typeof BroadcastChannel !== 'undefined') {
        const bc = new BroadcastChannel('ghost_call_channel');
        bc.onmessage = function (e) {
            if (e.data && e.data.number) {
                flash();
                localStorage.setItem(STORAGE_KEY, e.data.number);
                setTimeout(() => applyNumber(e.data.number), 350);
            }
        };
    }

    // ── 3. localStorage polling — fallback for browsers without BroadcastChannel ─
    let lastStored = localStorage.getItem(STORAGE_KEY);
    setInterval(function () {
        const current = localStorage.getItem(STORAGE_KEY);
        if (current !== lastStored) {
            flash();
            lastStored = current;
            setTimeout(() => applyNumber(current || ''), 350);
        }
    }, 1500);

    // ── 4. Broadcast when this page receives a number via URL param ─────────
    // (already handled above; also broadcast so other open tabs update)
    if (urlNumber && typeof BroadcastChannel !== 'undefined') {
        const bc2 = new BroadcastChannel('ghost_call_channel');
        bc2.postMessage({ number: urlNumber });
        bc2.close();
    }
})();
</script>
</body>
</html>
