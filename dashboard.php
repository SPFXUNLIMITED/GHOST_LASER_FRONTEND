<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    header('Location: admin-login.php');
    exit;
}

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

$adminUsername = trim((string) ($_SESSION['admin_username'] ?? 'Admin'));

if ($adminUsername === '') {
    $adminUsername = 'Admin';
}
?>
<?php
$pageTitle       = 'Admin Dashboard | Ghost Laser';
$pageDescription = 'Ghost Laser admin dashboard.';
$extraHead       = <<<'HTML'
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#09090b">
    <link rel="apple-touch-icon" href="/assets/img/ghost-laser-icon.png">
    <link rel="icon" type="image/png" href="/assets/img/ghost-laser-icon.png">
    <link rel="manifest" href="/manifest.json">
    <style>
        /* ═══════════════════════════════════════════════════
           GHOST LASER — PSYCHOTIC DASHBOARD SKIN v3
           Refs: MijailVillegas/empty-otter-85 +
                 pharmacist-sabot/blue-otter-17
           ═══════════════════════════════════════════════════ */

        @import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap');

        :root {
            --toxic:  #00fff5;
            --blood:  #ff003c;
            --mag:    #ff00aa;
            --void:   #02010a;
            --mono:   "Share Tech Mono", "Fira Code", Consolas, "Courier New", monospace;
        }

        html, body {
            background-color: var(--void) !important;
            font-family: var(--mono) !important;
        }

        /* ── 1. SCANLINES — MijailVillegas backglitch (94ms hue-rotate) ── */
        .scanlines {
            position: fixed;
            inset: 0;
            z-index: 9999;
            pointer-events: none;
            background: repeating-linear-gradient(
                to bottom,
                transparent              0px,
                transparent              2px,
                rgba(0,242,234,0.03)     2px,
                rgba(0,242,234,0.03)     3px,
                rgba(0,0,0,0.55)         3px,
                rgba(0,0,0,0.55)         4px
            );
            animation: backglitch 94ms linear infinite;
        }
        /* MijailVillegas backglitch keyframe — hue-rotate gives color-shift on the neon scanlines */
        @keyframes backglitch { 100% { filter: hue-rotate(20deg); } }

        /* noise grain on top */
        .scanlines::after {
            content: '';
            position: fixed;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events: none;
            animation: noiseflicker 0.1s steps(1) infinite;
            opacity: 0.08;
        }
        @keyframes noiseflicker {
            0%   { opacity: 0.08; transform: translate(0,0);     }
            25%  { opacity: 0.11; transform: translate(-1px,2px); }
            50%  { opacity: 0.05; transform: translate(2px,-2px); }
            75%  { opacity: 0.10; transform: translate(-2px,0);   }
            100% { opacity: 0.07; transform: translate(1px,1px);  }
        }

        /* ── 2. CYBER GRID ── */
        .cyber-grid {
            background-image:
                linear-gradient(rgba(255,0,60,0.09) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,255,245,0.09) 1px, transparent 1px);
            background-size: 38px 38px;
        }

        /* ── 3. AMBIENT ORBS ── */
        .glow-orb-cyan {
            background: radial-gradient(circle, rgba(0,255,245,0.20) 0%, transparent 70%) !important;
            animation: orbpulse-cyan 3s ease-in-out infinite;
        }
        .glow-orb-blood {
            background: radial-gradient(circle, rgba(255,0,60,0.22) 0%, transparent 70%) !important;
            animation: orbpulse-blood 2.5s ease-in-out infinite alternate;
        }
        @keyframes orbpulse-cyan {
            0%,100% { transform: scale(1);    opacity: 0.70; }
            50%     { transform: scale(1.20); opacity: 1.00; }
        }
        @keyframes orbpulse-blood {
            from { transform: scale(0.85); opacity: 0.50; }
            to   { transform: scale(1.20); opacity: 1.00; }
        }

        /* ── 4. CARD OUTER — spinning neon border + blinkShadowsFilter ──
               Technique: MijailVillegas card-content::before rotating gradient
               The 1px padding reveals the spinning element as a border. ── */
        .card-spin-wrap {
            position: relative;
            overflow: hidden;
            border-radius: 1rem;
            padding: 1px;
            animation: blinkShadowsFilter 6s ease-in infinite;
        }
        .card-spin-wrap::before {
            content: '';
            position: absolute;
            width: 250%; height: 250%;
            top: -75%; left: -75%;
            transform-origin: center;
            background:
                linear-gradient(to bottom,
                    transparent 0%, transparent 35%,
                    var(--toxic) 42%, var(--toxic) 50%,
                    var(--blood) 56%, var(--blood) 64%,
                    transparent 70%, transparent 100%),
                linear-gradient(to left,
                    transparent 0%, transparent 35%,
                    var(--toxic) 42%, var(--toxic) 50%,
                    var(--blood) 56%, var(--blood) 64%,
                    transparent 70%, transparent 100%);
            animation: rotateBorder 4s infinite linear;
            z-index: 0;
        }
        @keyframes rotateBorder { 100% { transform: rotate(1turn); } }

        /* MijailVillegas blinkShadowsFilter — drop-shadow color cycling */
        @keyframes blinkShadowsFilter {
            0%,100% { filter: drop-shadow(0 0 30px rgba(0,255,245,0.45)) drop-shadow(0 0 60px rgba(255,0,60,0.22)); }
            25%     { filter: drop-shadow(0 0 55px rgba(255,0,60,0.70)) drop-shadow(0 0 85px rgba(0,255,245,0.18)); }
            50%     { filter: drop-shadow(0 0 42px rgba(0,255,245,0.48)) drop-shadow(0 0 28px rgba(255,0,170,0.48)); }
            75%     { filter: drop-shadow(0 0 60px rgba(255,0,60,0.60)) drop-shadow(0 0 42px rgba(0,255,245,0.32)); }
        }

        /* ── 5. CARD INNER ── */
        .card-glow {
            position: relative;
            z-index: 1;
            background: linear-gradient(140deg,
                rgba(2,1,10,1.00)  0%,
                rgba(5,2,18,0.98)  50%,
                rgba(20,3,20,0.97) 100%);
            border-radius: calc(1rem - 1px);
            border: none !important;
            box-shadow: none !important;
        }

        /* ── 6. NEON BADGE ── */
        .neon-badge {
            border-color: rgba(255,0,60,0.65) !important;
            background:   rgba(255,0,60,0.14) !important;
            color: #ff003c !important;
            text-shadow: 0 0 10px #ff003c, 0 0 22px rgba(255,0,60,0.55);
            letter-spacing: 0.28em;
            font-family: var(--mono);
            box-shadow:
                0 0 16px rgba(255,0,60,0.55),
                0 0 36px rgba(255,0,60,0.22),
                inset 0 0 12px rgba(255,0,60,0.10);
            animation: badgepulse 2.2s ease-in-out infinite;
        }
        @keyframes badgepulse {
            0%,100% { box-shadow: 0 0 16px rgba(255,0,60,0.55), 0 0 36px rgba(255,0,60,0.22); }
            50%     { box-shadow: 0 0 32px rgba(255,0,60,0.92), 0 0 68px rgba(255,0,60,0.45); }
        }

        /* ── 7. GLITCH TEXT — pharmacist-sabot style, always-on, amplified ──
               Two pseudo-element slices: top = blood red, bottom = toxic cyan.
               Both run constant translateX + skewX animations on different offsets. ── */
        .glitch-title,
        .glitch-subtitle {
            position: relative;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-style: italic;
            font-family: var(--mono);
        }

        .glitch-title {
            color: #ffffff;
            text-shadow:
                0 0 10px var(--toxic),
                0 0 30px rgba(0,255,245,0.40),
                0 0 60px rgba(0,255,245,0.15),
                3px 0 rgba(255,0,60,0.80),
                -3px 0 rgba(0,255,245,0.80);
            animation: glitch-shake 0.35s infinite linear,
                       titleflicker  3.0s steps(1) infinite;
        }
        .glitch-subtitle {
            color: #f0f0f0;
            text-shadow:
                0 0 8px var(--toxic),
                0 0 20px rgba(0,255,245,0.35),
                2px 0 rgba(255,0,60,0.70),
                -2px 0 rgba(0,255,245,0.70);
            animation: glitch-shake 0.50s infinite linear,
                       titleflicker  4.5s steps(1) infinite 0.6s;
        }

        /* whole-element shake — pharmacist-sabot glitch-skew, cranked up */
        @keyframes glitch-shake {
            0%   { transform: translate(0,0)      skewX(0deg);   }
            10%  { transform: translate(-3px,0)   skewX(-3deg);  }
            20%  { transform: translate(3px,0)    skewX(2deg);   }
            30%  { transform: translate(-2px,1px) skewX(4deg);   }
            40%  { transform: translate(2px,-1px) skewX(-2deg);  }
            50%  { transform: translate(0,0)      skewX(0deg);   }
            60%  { transform: translate(4px,0)    skewX(-4deg);  }
            70%  { transform: translate(-4px,0)   skewX(3deg);   }
            80%  { transform: translate(1px,1px)  skewX(-3deg);  }
            90%  { transform: translate(-1px,-1px) skewX(2deg);  }
            100% { transform: translate(0,0)      skewX(0deg);   }
        }

        /* opacity flicker bursts */
        @keyframes titleflicker {
            0%,18%,20%,22%,52%,54%,100% { opacity: 1;    }
            19%,21%                     { opacity: 0.45; }
            53%                         { opacity: 0.62; }
        }

        /* shared pseudo-element base */
        .glitch-title::before,  .glitch-title::after,
        .glitch-subtitle::before, .glitch-subtitle::after {
            content: attr(data-text);
            position: absolute;
            left: 0; top: 0;
            width: 100%;
            pointer-events: none;
            white-space: nowrap;
        }

        /* TOP SLICE — blood red */
        .glitch-title::before,
        .glitch-subtitle::before {
            color: var(--blood);
            clip-path: polygon(0 0, 100% 0, 100% 33%, 0 33%);
            animation: glitch-top 0.28s infinite linear;
            opacity: 0.92;
        }
        /* BOTTOM SLICE — toxic cyan */
        .glitch-title::after,
        .glitch-subtitle::after {
            color: var(--toxic);
            clip-path: polygon(0 67%, 100% 67%, 100% 100%, 0 100%);
            animation: glitch-bot 0.38s infinite linear;
            opacity: 0.92;
        }

        @keyframes glitch-top {
            0%   { transform: translate(0,0)    skewX(0deg);   clip-path: polygon(0 0,   100% 0,   100% 33%, 0 33%); }
            8%   { transform: translate(-7px,0) skewX(-6deg);  clip-path: polygon(0 0,   100% 0,   100% 20%, 0 20%); }
            16%  { transform: translate(6px,0)  skewX(5deg);   clip-path: polygon(0 8%,  100% 5%,  100% 42%, 0 40%); }
            24%  { transform: translate(-5px,0) skewX(-3deg);  clip-path: polygon(0 0,   100% 0,   100% 28%, 0 28%); }
            32%  { transform: translate(8px,0)  skewX(7deg);   clip-path: polygon(0 3%,  100% 0,   100% 36%, 0 40%); }
            40%  { transform: translate(0,0)    skewX(0deg);   clip-path: polygon(0 0,   100% 0,   100% 33%, 0 33%); }
            48%  { transform: translate(-9px,0) skewX(-5deg);  clip-path: polygon(0 12%, 100% 10%, 100% 32%, 0 34%); }
            56%  { transform: translate(5px,0)  skewX(3deg);   clip-path: polygon(0 0,   100% 0,   100% 44%, 0 44%); }
            64%  { transform: translate(-6px,0) skewX(-7deg);  clip-path: polygon(0 5%,  100% 2%,  100% 30%, 0 34%); }
            72%  { transform: translate(7px,0)  skewX(5deg);   clip-path: polygon(0 0,   100% 0,   100% 22%, 0 22%); }
            80%  { transform: translate(-4px,0) skewX(-2deg);  clip-path: polygon(0 10%, 100% 8%,  100% 40%, 0 38%); }
            88%  { transform: translate(6px,0)  skewX(4deg);   clip-path: polygon(0 0,   100% 0,   100% 33%, 0 33%); }
            96%  { transform: translate(-8px,0) skewX(-5deg);  clip-path: polygon(0 2%,  100% 0,   100% 25%, 0 30%); }
            100% { transform: translate(0,0)    skewX(0deg);   clip-path: polygon(0 0,   100% 0,   100% 33%, 0 33%); }
        }

        @keyframes glitch-bot {
            0%   { transform: translate(0,0)    skewX(0deg);   clip-path: polygon(0 67%, 100% 67%, 100% 100%, 0 100%); }
            10%  { transform: translate(8px,0)  skewX(5deg);   clip-path: polygon(0 62%, 100% 65%, 100% 100%, 0 97%);  }
            20%  { transform: translate(-6px,0) skewX(-7deg);  clip-path: polygon(0 70%, 100% 67%, 100% 92%,  0 96%);  }
            30%  { transform: translate(5px,0)  skewX(4deg);   clip-path: polygon(0 67%, 100% 70%, 100% 100%, 0 100%); }
            40%  { transform: translate(-9px,0) skewX(-5deg);  clip-path: polygon(0 60%, 100% 63%, 100% 90%,  0 88%);  }
            50%  { transform: translate(0,0)    skewX(0deg);   clip-path: polygon(0 67%, 100% 67%, 100% 100%, 0 100%); }
            60%  { transform: translate(7px,0)  skewX(6deg);   clip-path: polygon(0 72%, 100% 70%, 100% 95%,  0 98%);  }
            70%  { transform: translate(-5px,0) skewX(-3deg);  clip-path: polygon(0 65%, 100% 67%, 100% 100%, 0 100%); }
            80%  { transform: translate(6px,0)  skewX(-6deg);  clip-path: polygon(0 75%, 100% 72%, 100% 88%,  0 93%);  }
            90%  { transform: translate(-8px,0) skewX(4deg);   clip-path: polygon(0 67%, 100% 70%, 100% 100%, 0 97%);  }
            100% { transform: translate(0,0)    skewX(0deg);   clip-path: polygon(0 67%, 100% 67%, 100% 100%, 0 100%); }
        }

        /* ── 8. BUTTONS ── */
        .btn-glow {
            background: var(--toxic) !important;
            color: var(--void) !important;
            font-weight: 800 !important;
            letter-spacing: 0.08em;
            font-family: var(--mono) !important;
            border: none !important;
            box-shadow:
                0 0 24px rgba(0,255,245,0.72),
                0 0 52px rgba(0,255,245,0.34),
                0 0 10px rgba(255,0,60,0.42);
        }
        .btn-glow:hover {
            box-shadow:
                0 0 44px rgba(0,255,245,0.98),
                0 0 90px rgba(0,255,245,0.58),
                0 0 24px rgba(255,0,60,0.62);
        }

        .link-secondary {
            border-color: rgba(0,255,245,0.38) !important;
            background:   rgba(0,255,245,0.09) !important;
            color: var(--toxic) !important;
            font-family: var(--mono) !important;
            box-shadow: 0 0 14px rgba(0,255,245,0.20);
            transition: all 0.2s;
        }
        .link-secondary:hover {
            border-color: rgba(0,255,245,0.82) !important;
            background:   rgba(0,255,245,0.18) !important;
            color: #fff !important;
            box-shadow: 0 0 32px rgba(0,255,245,0.45);
        }

        .link-ghost {
            border-color: rgba(255,0,60,0.30) !important;
            color: #ff6680 !important;
            font-family: var(--mono) !important;
            transition: all 0.2s;
        }
        .link-ghost:hover {
            border-color: rgba(255,0,60,0.75) !important;
            color: var(--blood) !important;
            background: rgba(255,0,60,0.10) !important;
            box-shadow: 0 0 22px rgba(255,0,60,0.42);
        }

        /* ── 9. SCHEDULE / QUICK CARDS ── */
        .danger-card {
            background: rgba(2,1,10,0.92) !important;
            border-color: rgba(0,255,245,0.28) !important;
            box-shadow:
                0 0 0 1px rgba(0,255,245,0.16),
                0 0 30px rgba(0,255,245,0.08),
                inset 0 0 20px rgba(0,255,245,0.03);
            transition: all 0.2s ease;
        }
        .danger-card:hover {
            border-color: rgba(0,255,245,0.75) !important;
            box-shadow:
                0 0 0 1px rgba(0,255,245,0.52),
                0 0 58px rgba(0,255,245,0.28),
                0 0 88px rgba(255,0,60,0.14),
                inset 0 0 32px rgba(0,255,245,0.07);
            transform: translateY(-4px);
        }

        .quick-card {
            background: rgba(2,1,10,0.88) !important;
            border-color: rgba(255,0,60,0.30) !important;
            box-shadow:
                0 0 0 1px rgba(255,0,60,0.15),
                0 0 24px rgba(255,0,60,0.07),
                inset 0 0 18px rgba(255,0,60,0.04);
        }

        /* ── 10. HEADER ── */
        .back-link { color: #ff6680 !important; transition: all 0.2s; }
        .back-link:hover { color: var(--blood) !important; text-shadow: 0 0 10px var(--blood); }

        .logout-btn {
            border-color: rgba(255,0,60,0.44) !important;
            color: #ff6680 !important;
            background: rgba(255,0,60,0.08) !important;
            font-family: var(--mono) !important;
            transition: all 0.2s;
        }
        .logout-btn:hover {
            border-color: rgba(255,0,60,0.90) !important;
            background: rgba(255,0,60,0.22) !important;
            color: var(--blood) !important;
            box-shadow: 0 0 24px rgba(255,0,60,0.48);
        }

        /* ── 11. MISC ── */
        .welcome-name {
            color: var(--toxic) !important;
            text-shadow: 0 0 14px rgba(0,255,245,0.82), 0 0 32px rgba(0,255,245,0.40);
        }

        .open-schedule-link {
            color: var(--toxic) !important;
            text-shadow: 0 0 10px rgba(0,255,245,0.62);
            font-family: var(--mono);
        }
    </style>
HTML;
$headerRight     = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="/" class="back-link text-sm transition-colors">&larr; Back to Home</a>
                    <form method="POST" action="">
                        <button
                            type="submit"
                            name="logout"
                            value="1"
                            class="logout-btn inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-medium transition-all"
                        >
                            Logout
                        </button>
                    </form>
                </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen flex items-center justify-center hero-grid cyber-grid pt-16 px-4">

        <!-- MijailVillegas-style scanlines overlay with 94ms backglitch hue-rotate -->
        <div class="scanlines"></div>

        <!-- ambient orbs -->
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <div class="glow-orb-cyan w-[580px] h-[580px] rounded-full blur-3xl"></div>
            <div class="glow-orb-blood absolute w-[440px] h-[440px] rounded-full blur-3xl"></div>
        </div>

        <div class="relative w-full max-w-3xl">

            <!-- card-spin-wrap: 1px padding reveals the rotating neon border ::before -->
            <div class="card-spin-wrap">
                <div class="card-glow rounded-2xl p-8 md:p-10">

                    <div class="flex flex-col gap-8 md:flex-row md:items-start md:justify-between">
                        <div class="max-w-xl">
                            <div class="neon-badge inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium uppercase tracking-[0.2em]">
                                &#x2620; Admin Dashboard &#x2620;
                            </div>
                            <h1 class="glitch-title mt-5 text-3xl md:text-4xl font-black" data-text="Technician Dashboard">Technician Dashboard</h1>
                            <p class="mt-3 text-base text-zinc-200 md:text-lg">
                                Welcome back, <span class="welcome-name font-bold"><?= htmlspecialchars($adminUsername, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>.
                            </p>
                            <p class="mt-2 text-sm text-zinc-500 md:text-base">
                                Manage technician scheduling and keep operations moving from one central place.
                            </p>
                        </div>
                    </div>

                    <div class="mt-10 grid gap-6 md:grid-cols-[1.2fr_0.8fr]">
                        <a
                            href="technician/schedule.php"
                            class="danger-card group flex min-h-[220px] flex-col justify-between rounded-2xl border p-6 transition-all"
                        >
                            <div>
                                <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-cyan-500/10 text-cyan-400"
                                      style="box-shadow:0 0 18px rgba(0,255,245,0.25);">
                                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 7V3m8 4V3m-9 8h10m-11 9h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v11a2 2 0 002 2z"/>
                                    </svg>
                                </span>
                                <h2 class="glitch-subtitle mt-5 text-2xl font-semibold" data-text="Technician Schedule">Technician Schedule</h2>
                                <p class="mt-3 text-sm leading-6 text-zinc-500">
                                    Open the scheduling workspace to review bookings, assignments, and upcoming technician availability.
                                </p>
                            </div>

                            <div class="open-schedule-link mt-8 inline-flex items-center gap-2 text-base font-semibold">
                                Open schedule
                                <span class="transition-transform group-hover:translate-x-1">&rarr;</span>
                            </div>
                        </a>

                        <div class="quick-card rounded-2xl border p-6">
                            <h2 class="glitch-subtitle text-lg font-semibold" data-text="Quick Access">Quick Access</h2>
                            <p class="mt-3 text-sm leading-6 text-zinc-500">
                                You are signed in and ready to access the admin tools available in this portal.
                            </p>

                            <div class="mt-6 space-y-3">
                                <a
                                    href="technician/schedule.php"
                                    class="btn-glow inline-flex w-full items-center justify-center gap-2 rounded-md px-4 py-3 text-sm transition-all"
                                >
                                    &gt;&gt; Go to Technician Schedule
                                </a>
                                <a
                                    href="technician-dashboard.php"
                                    class="link-secondary inline-flex w-full items-center justify-center gap-2 rounded-md border px-4 py-3 text-sm transition-all"
                                >
                                    Technician Dashboard
                                </a>
                                <a
                                    href="settings.php"
                                    class="link-ghost inline-flex w-full items-center justify-center gap-2 rounded-md border px-4 py-3 text-sm transition-all"
                                >
                                    Open Admin Settings
                                </a>
                                <a
                                    href="mileage-tracker.php"
                                    class="link-ghost inline-flex w-full items-center justify-center gap-2 rounded-md border px-4 py-3 text-sm transition-all"
                                >
                                    IRS Mileage Tracker
                                </a>
                            </div>
                        </div>
                    </div>

                </div><!-- /card-glow -->
            </div><!-- /card-spin-wrap -->

        </div>
    </main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
