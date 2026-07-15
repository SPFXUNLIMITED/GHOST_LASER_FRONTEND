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
    <style>
        /* ═══════════════════════════════════════════════════
           GHOST LASER — PSYCHOTIC DASHBOARD SKIN v2
           ═══════════════════════════════════════════════════ */

        :root {
            --toxic:   #00fff5;
            --blood:   #ff003c;
            --magenta: #ff00aa;
            --red:     #ff1500;
            --void:    #02010a;
        }

        html, body { background-color: var(--void) !important; }

        /* ── SCANLINES ── */
        .scanlines {
            position: fixed;
            inset: 0;
            background: repeating-linear-gradient(
                to bottom,
                transparent 0px,
                transparent 2px,
                rgba(0,0,0,0.55) 2px,
                rgba(0,0,0,0.55) 4px
            );
            pointer-events: none;
            z-index: 9998;
            animation: scanroll 6s linear infinite;
        }
        @keyframes scanroll {
            from { background-position: 0 0; }
            to   { background-position: 0 200px; }
        }

        /* noise flicker on top of scanlines */
        .scanlines::after {
            content: '';
            position: fixed;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events: none;
            animation: noiseflicker 0.12s steps(1) infinite;
        }
        @keyframes noiseflicker {
            0%   { opacity: 0.08; transform: translate(0, 0);    }
            20%  { opacity: 0.11; transform: translate(-1px, 2px); }
            40%  { opacity: 0.06; transform: translate(2px, -1px); }
            60%  { opacity: 0.10; transform: translate(-2px,-1px); }
            80%  { opacity: 0.07; transform: translate(1px,  0);  }
            100% { opacity: 0.09; transform: translate(0,  1px); }
        }

        /* ── CARD WRAP ── */
        .card-glow {
            box-shadow:
                0 0 0 1px rgba(0,255,245,0.30),
                0 0 70px rgba(0,255,245,0.12),
                0 0 140px rgba(255,0,60,0.08),
                inset 0 0 50px rgba(255,0,170,0.05);
            background: linear-gradient(
                140deg,
                rgba(2,1,10,0.99)  0%,
                rgba(5,2,18,0.97)  50%,
                rgba(20,3,20,0.95) 100%
            );
        }

        /* ── AMBIENT ORBS ── */
        .glow-orb-cyan {
            background: radial-gradient(circle, rgba(0,255,245,0.18) 0%, transparent 70%) !important;
            animation: orbpulse-cyan 3.5s ease-in-out infinite;
        }
        .glow-orb-blood {
            background: radial-gradient(circle, rgba(255,0,60,0.20) 0%, transparent 70%) !important;
            animation: orbpulse-blood 2.8s ease-in-out infinite alternate;
        }
        @keyframes orbpulse-cyan {
            0%,100% { transform: scale(1);    opacity: 0.75; }
            50%     { transform: scale(1.15); opacity: 1;    }
        }
        @keyframes orbpulse-blood {
            from { transform: scale(0.88); opacity: 0.55; }
            to   { transform: scale(1.18); opacity: 1.00; }
        }

        /* ── CYBER GRID ── */
        .cyber-grid {
            background-image:
                linear-gradient(rgba(255,0,60,0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,255,245,0.08) 1px, transparent 1px);
            background-size: 38px 38px;
        }

        /* ── NEON BADGE ── */
        .neon-badge {
            border-color: rgba(255,0,60,0.60) !important;
            background:   rgba(255,0,60,0.14) !important;
            color: #ff003c !important;
            box-shadow:
                0 0 16px rgba(255,0,60,0.60),
                0 0 36px rgba(255,0,60,0.25),
                inset 0 0 14px rgba(255,0,60,0.12);
            text-shadow: 0 0 10px #ff003c, 0 0 20px rgba(255,0,60,0.5);
            letter-spacing: 0.28em;
            animation: badgepulse 2s ease-in-out infinite;
        }
        @keyframes badgepulse {
            0%,100% { box-shadow: 0 0 16px rgba(255,0,60,0.60), 0 0 36px rgba(255,0,60,0.25); }
            50%     { box-shadow: 0 0 28px rgba(255,0,60,0.90), 0 0 60px rgba(255,0,60,0.45); }
        }

        /* ── GLITCH HEADINGS ── */
        .glitch-title,
        .glitch-subtitle {
            position: relative;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .glitch-title {
            color: #ffffff;
            text-shadow:
                0 0 12px #00fff5,
                0 0 35px rgba(0,255,245,0.55),
                0 0 70px rgba(0,255,245,0.25),
                3px  0 #ff003c,
                -3px 0 #00fff5;
            animation: titleflicker 4s steps(1) infinite;
        }
        .glitch-subtitle {
            color: #eeeeee;
            text-shadow:
                0 0 9px #00fff5,
                0 0 22px rgba(0,255,245,0.40),
                2px  0 #ff003c,
                -2px 0 #00fff5;
            animation: titleflicker 5s steps(1) infinite 0.7s;
        }

        @keyframes titleflicker {
            0%,18%,20%,22%,52%,54%,100% { opacity: 1; }
            19%,21%,53% { opacity: 0.75; }
        }

        /* pseudo-element glitch layers */
        .glitch-title::before,
        .glitch-title::after,
        .glitch-subtitle::before,
        .glitch-subtitle::after {
            content: attr(data-text);
            position: absolute;
            inset: 0;
            pointer-events: none;
            mix-blend-mode: screen;
        }

        /* LAYER A — TOXIC CYAN */
        .glitch-title::before,
        .glitch-subtitle::before {
            color: #00fff5;
            opacity: 0.92;
            animation: glitch-a 0.85s infinite linear;
        }
        /* LAYER B — BLOOD RED */
        .glitch-title::after,
        .glitch-subtitle::after {
            color: #ff003c;
            opacity: 0.88;
            animation: glitch-b 0.65s infinite linear;
        }

        @keyframes glitch-a {
            0%   { clip-path: polygon(0  2%,100%  4%,100% 16%,0 12%); transform: translate(-7px, 0)     skewX(-2deg); }
            5%   { clip-path: polygon(0 50%,100% 47%,100% 58%,0 55%); transform: translate( 5px,-2px)   skewX( 3deg); }
            10%  { clip-path: polygon(0 80%,100% 78%,100% 95%,0 92%); transform: translate(-4px, 3px)   skewX(-1deg); }
            15%  { clip-path: polygon(0 20%,100% 18%,100% 32%,0 28%); transform: translate( 8px, 0)     skewX( 2deg); }
            20%  { clip-path: polygon(0  0%,100%  0%,100%  8%,0  6%); transform: translate(-6px, 1px)   skewX(-3deg); }
            25%  { clip-path: polygon(0 60%,100% 62%,100% 70%,0 68%); transform: translate( 4px,-4px)   skewX( 1deg); }
            30%  { clip-path: polygon(0 35%,100% 33%,100% 50%,0 45%); transform: translate(-9px, 0)     skewX(-2deg); }
            35%  { clip-path: polygon(0 72%,100% 70%,100% 88%,0 85%); transform: translate( 6px, 3px)   skewX( 3deg); }
            40%  { clip-path: polygon(0  4%,100%  2%,100% 22%,0 18%); transform: translate(-5px,-2px)   skewX(-1deg); }
            45%  { clip-path: polygon(0 90%,100% 88%,100%,0 100%);    transform: translate( 9px, 0)     skewX( 2deg); }
            50%  { clip-path: polygon(0 44%,100% 42%,100% 56%,0 52%); transform: translate(-7px, 4px)   skewX(-2deg); }
            55%  { clip-path: polygon(0 10%,100%  8%,100% 28%,0 24%); transform: translate( 5px,-3px)   skewX( 1deg); }
            60%  { clip-path: polygon(0 66%,100% 64%,100% 78%,0 76%); transform: translate(-4px, 0)     skewX(-3deg); }
            65%  { clip-path: polygon(0 28%,100% 26%,100% 38%,0 35%); transform: translate(10px,-2px)   skewX( 2deg); }
            70%  { clip-path: polygon(0 82%,100% 80%,100% 92%,0 90%); transform: translate(-6px, 3px)   skewX(-1deg); }
            75%  { clip-path: polygon(0  0%,100%  0%,100%  5%,0  4%); transform: translate( 4px, 0)     skewX( 3deg); }
            80%  { clip-path: polygon(0 54%,100% 52%,100% 65%,0 62%); transform: translate(-8px,-3px)   skewX(-2deg); }
            85%  { clip-path: polygon(0 38%,100% 36%,100% 48%,0 46%); transform: translate( 7px, 2px)   skewX( 1deg); }
            90%  { clip-path: polygon(0 76%,100% 74%,100% 85%,0 82%); transform: translate(-5px,-4px)   skewX(-3deg); }
            95%  { clip-path: polygon(0 14%,100% 12%,100% 26%,0 22%); transform: translate( 6px, 3px)   skewX( 2deg); }
            100% { clip-path: polygon(0  2%,100%  4%,100% 16%,0 12%); transform: translate(-7px, 0)     skewX(-2deg); }
        }

        @keyframes glitch-b {
            0%   { clip-path: polygon(0 55%,100% 52%,100% 72%,0 68%); transform: translate( 6px, 0)     skewX( 2deg); }
            7%   { clip-path: polygon(0  8%,100%  6%,100% 20%,0 16%); transform: translate(-8px, 3px)   skewX(-3deg); }
            14%  { clip-path: polygon(0 78%,100% 76%,100% 95%,0 92%); transform: translate(10px,-2px)   skewX( 1deg); }
            21%  { clip-path: polygon(0 30%,100% 28%,100% 44%,0 40%); transform: translate(-5px, 4px)   skewX(-2deg); }
            28%  { clip-path: polygon(0  0%,100%  0%,100% 10%,0  8%); transform: translate( 8px,-3px)   skewX( 3deg); }
            35%  { clip-path: polygon(0 62%,100% 60%,100% 76%,0 72%); transform: translate(-4px, 0)     skewX(-1deg); }
            42%  { clip-path: polygon(0 42%,100% 40%,100% 58%,0 54%); transform: translate( 6px, 3px)   skewX( 2deg); }
            49%  { clip-path: polygon(0 85%,100% 83%,100% 98%,0 96%); transform: translate(-9px,-2px)   skewX(-3deg); }
            56%  { clip-path: polygon(0 18%,100% 16%,100% 32%,0 28%); transform: translate( 5px, 2px)   skewX( 1deg); }
            63%  { clip-path: polygon(0 70%,100% 68%,100% 82%,0 80%); transform: translate(-6px,-3px)   skewX(-2deg); }
            70%  { clip-path: polygon(0  4%,100%  2%,100% 14%,0 12%); transform: translate( 7px, 0)     skewX( 3deg); }
            77%  { clip-path: polygon(0 48%,100% 46%,100% 60%,0 56%); transform: translate(-8px, 4px)   skewX(-1deg); }
            84%  { clip-path: polygon(0 22%,100% 20%,100% 38%,0 34%); transform: translate( 4px,-2px)   skewX( 2deg); }
            91%  { clip-path: polygon(0 88%,100% 86%,100%,0 98%);     transform: translate(-7px, 3px)   skewX(-3deg); }
            100% { clip-path: polygon(0 55%,100% 52%,100% 72%,0 68%); transform: translate( 6px, 0)     skewX( 2deg); }
        }

        /* ── PRIMARY BTN ── */
        .btn-glow {
            background: #00fff5 !important;
            color: #02010a !important;
            font-weight: 800 !important;
            letter-spacing: 0.05em;
            box-shadow:
                0 0 24px rgba(0,255,245,0.65),
                0 0 50px rgba(0,255,245,0.30),
                0 0 10px rgba(255,0,60,0.35);
        }
        .btn-glow:hover {
            box-shadow:
                0 0 40px rgba(0,255,245,0.90),
                0 0 80px rgba(0,255,245,0.50),
                0 0 20px rgba(255,0,60,0.55);
        }

        /* ── SCHEDULE CARD ── */
        .danger-card {
            border-color: rgba(0,255,245,0.32) !important;
            box-shadow:
                0 0 0 1px rgba(0,255,245,0.18),
                0 0 35px rgba(0,255,245,0.10),
                inset 0 0 24px rgba(0,255,245,0.03);
            transition: all 0.2s ease;
        }
        .danger-card:hover {
            border-color: rgba(0,255,245,0.75) !important;
            box-shadow:
                0 0 0 1px rgba(0,255,245,0.55),
                0 0 55px rgba(0,255,245,0.28),
                0 0 90px rgba(255,0,60,0.14),
                inset 0 0 35px rgba(0,255,245,0.06);
            transform: translateY(-4px);
        }

        /* ── QUICK ACCESS CARD ── */
        .quick-card {
            border-color: rgba(255,0,60,0.28) !important;
            background: rgba(2,1,10,0.80) !important;
            box-shadow:
                0 0 0 1px rgba(255,0,60,0.15),
                0 0 24px rgba(255,0,60,0.07),
                inset 0 0 18px rgba(255,0,60,0.04);
        }

        /* ── SECONDARY BTN ── */
        .link-secondary {
            border-color: rgba(0,255,245,0.35) !important;
            background:   rgba(0,255,245,0.08) !important;
            color: #00fff5 !important;
            box-shadow: 0 0 14px rgba(0,255,245,0.18);
            transition: all 0.2s;
        }
        .link-secondary:hover {
            border-color: rgba(0,255,245,0.80) !important;
            background:   rgba(0,255,245,0.18) !important;
            box-shadow: 0 0 28px rgba(0,255,245,0.42);
            color: #ffffff !important;
        }

        /* ── GHOST BTNS ── */
        .link-ghost {
            border-color: rgba(255,0,60,0.25) !important;
            color: #ff6680 !important;
            transition: all 0.2s;
        }
        .link-ghost:hover {
            border-color: rgba(255,0,60,0.70) !important;
            color: #ff003c !important;
            background: rgba(255,0,60,0.10) !important;
            box-shadow: 0 0 18px rgba(255,0,60,0.35);
        }

        /* ── HEADER EXTRAS ── */
        .back-link { color: #ff6680 !important; transition: all 0.2s; }
        .back-link:hover {
            color: #ff003c !important;
            text-shadow: 0 0 10px #ff003c;
        }

        .logout-btn {
            border-color: rgba(255,0,60,0.40) !important;
            color: #ff6680 !important;
            background: rgba(255,0,60,0.08) !important;
            transition: all 0.2s;
        }
        .logout-btn:hover {
            border-color: rgba(255,0,60,0.85) !important;
            background: rgba(255,0,60,0.20) !important;
            color: #ff003c !important;
            box-shadow: 0 0 22px rgba(255,0,60,0.42);
        }

        /* ── WELCOME NAME ── */
        .welcome-name {
            color: #00fff5 !important;
            text-shadow: 0 0 14px rgba(0,255,245,0.75), 0 0 28px rgba(0,255,245,0.35);
        }

        /* ── OPEN SCHEDULE ARROW LINK ── */
        .open-schedule-link {
            color: #00fff5 !important;
            text-shadow: 0 0 10px rgba(0,255,245,0.55);
        }
    </style>
HTML;
$headerRight     = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="/" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Home</a>
                    <form method="POST" action="">
                        <button
                            type="submit"
                            name="logout"
                            value="1"
                            class="inline-flex items-center justify-center rounded-md border border-zinc-700 px-4 py-2 text-sm font-medium text-zinc-200 transition-colors hover:border-red-500/40 hover:bg-red-500/10 hover:text-red-300"
                        >
                            Logout
                        </button>
                    </form>
                </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen flex items-center justify-center hero-grid cyber-grid pt-16 px-4">
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <div class="w-[560px] h-[560px] rounded-full bg-cyan-500/5 blur-3xl"></div>
            <div class="absolute w-[420px] h-[420px] rounded-full bg-pink-500/10 blur-3xl"></div>
        </div>

        <div class="relative w-full max-w-3xl">
            <div class="border border-zinc-800 rounded-2xl p-8 md:p-10 card-glow">
                <div class="flex flex-col gap-8 md:flex-row md:items-start md:justify-between">
                    <div class="max-w-xl">
                        <div class="neon-badge inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium uppercase tracking-[0.2em]">
                            Admin Dashboard
                        </div>
                        <h1 class="glitch-title mt-5 text-3xl md:text-4xl font-black" data-text="Technician Dashboard">Technician Dashboard</h1>
                        <p class="mt-3 text-base text-zinc-200 md:text-lg">
                            Welcome back, <span class="text-cyan-300"><?= htmlspecialchars($adminUsername, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>.
                        </p>
                        <p class="mt-2 text-base text-zinc-400 md:text-lg">
                            Manage technician scheduling and keep operations moving from one central place.
                        </p>
                    </div>

                </div>

                <div class="mt-10 grid gap-6 md:grid-cols-[1.2fr_0.8fr]">
                    <a
                        href="technician/schedule.php"
                        class="group flex min-h-[220px] flex-col justify-between rounded-2xl border border-cyan-500/25 bg-zinc-950/75 p-6 transition-all hover:-translate-y-1 hover:border-cyan-400/60 hover:bg-zinc-950"
                    >
                        <div>
                            <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-cyan-500/10 text-cyan-400">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 7V3m8 4V3m-9 8h10m-11 9h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v11a2 2 0 002 2z"/>
                                </svg>
                            </span>
                            <h2 class="glitch-subtitle mt-5 text-2xl font-semibold text-white" data-text="Technician Schedule">Technician Schedule</h2>
                            <p class="mt-3 text-sm leading-6 text-zinc-400">
                                Open the scheduling workspace to review bookings, assignments, and upcoming technician availability.
                            </p>
                        </div>

                        <div class="mt-8 inline-flex items-center gap-2 text-base font-semibold text-cyan-400">
                            Open schedule
                            <span class="transition-transform group-hover:translate-x-1">&rarr;</span>
                        </div>
                    </a>

                    <div class="rounded-2xl border border-zinc-800 bg-zinc-950/70 p-6">
                        <h2 class="glitch-subtitle text-lg font-semibold text-white" data-text="Quick Access">Quick Access</h2>
                        <p class="mt-3 text-sm leading-6 text-zinc-400">
                            You are signed in and ready to access the admin tools available in this portal.
                        </p>

                        <div class="mt-6 space-y-3">
                            <a
                                href="technician/schedule.php"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-cyan-500 px-4 py-3 text-sm font-semibold text-zinc-950 transition-all btn-glow hover:bg-cyan-400"
                            >
                                Go to Technician Schedule
                            </a>
                            <a
                                href="technician-dashboard.php"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-cyan-500/40 bg-cyan-500/10 px-4 py-3 text-sm font-semibold text-cyan-300 transition-all hover:border-cyan-400 hover:bg-cyan-500/20 hover:text-white"
                            >
                                Technician Dashboard
                            </a>
                            <a
                                href="settings.php"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-zinc-700 px-4 py-3 text-sm font-semibold text-zinc-200 transition-all hover:border-cyan-400 hover:text-white"
                            >
                                Open Admin Settings
                            </a>
                            <a
                                href="mileage-tracker.php"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-zinc-700 px-4 py-3 text-sm font-semibold text-zinc-200 transition-all hover:border-cyan-400 hover:text-white"
                            >
                                IRS Mileage Tracker
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
