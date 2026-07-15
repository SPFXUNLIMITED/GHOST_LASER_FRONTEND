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
        .btn-glow { box-shadow: 0 0 18px rgba(34,211,238,0.4), 0 0 30px rgba(236,72,153,0.2); }
        .btn-glow:hover { box-shadow: 0 0 28px rgba(34,211,238,0.75), 0 0 44px rgba(236,72,153,0.35); }
        .card-glow {
            box-shadow: 0 0 0 1px rgba(34,211,238,0.18), 0 0 50px rgba(34,211,238,0.08), inset 0 0 32px rgba(236,72,153,0.08);
            background: linear-gradient(140deg, rgba(10, 13, 26, 0.95) 0%, rgba(12, 18, 33, 0.85) 50%, rgba(28, 12, 34, 0.78) 100%);
        }
        .glitch-title,
        .glitch-subtitle {
            position: relative;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .glitch-title {
            color: #f8fafc;
            text-shadow: 0 0 14px rgba(34,211,238,0.35);
        }
        .glitch-subtitle {
            color: #d4d4d8;
            text-shadow: 0 0 12px rgba(236,72,153,0.28);
        }
        .glitch-title::before,
        .glitch-title::after,
        .glitch-subtitle::before,
        .glitch-subtitle::after {
            content: attr(data-text);
            position: absolute;
            inset: 0;
            pointer-events: none;
            mix-blend-mode: screen;
            opacity: 0.8;
        }
        .glitch-title::before,
        .glitch-subtitle::before {
            color: rgba(34,211,238,0.85);
            transform: translate(-2px, 1px);
            clip-path: polygon(0 6%, 100% 3%, 100% 34%, 0 38%);
            animation: glitch-shift-a 1.3s infinite linear alternate-reverse;
        }
        .glitch-title::after,
        .glitch-subtitle::after {
            color: rgba(236,72,153,0.85);
            transform: translate(2px, -1px);
            clip-path: polygon(0 62%, 100% 58%, 100% 98%, 0 95%);
            animation: glitch-shift-b 1.6s infinite linear alternate-reverse;
        }
        .neon-badge {
            border-color: rgba(236,72,153,0.35);
            background: rgba(236,72,153,0.12);
            color: rgb(244 114 182);
            box-shadow: 0 0 20px rgba(236,72,153,0.18);
        }
        .cyber-grid {
            background-image:
                linear-gradient(rgba(236,72,153,0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(34,211,238,0.06) 1px, transparent 1px);
            background-size: 42px 42px;
        }
        @keyframes glitch-shift-a {
            0% { transform: translate(-2px, 0); }
            35% { transform: translate(1px, -1px); }
            70% { transform: translate(-1px, 1px); }
            100% { transform: translate(-2px, -1px); }
        }
        @keyframes glitch-shift-b {
            0% { transform: translate(2px, 0); }
            40% { transform: translate(-1px, 1px); }
            75% { transform: translate(1px, -1px); }
            100% { transform: translate(2px, 1px); }
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
