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
        :root {
            --dash-bg: #09090f;
            --dash-panel: rgba(18, 24, 38, 0.92);
            --dash-border: rgba(148, 163, 184, 0.22);
            --dash-border-strong: rgba(96, 165, 250, 0.38);
            --dash-text: #f8fafc;
            --dash-muted: #94a3b8;
            --dash-accent: #67e8f9;
            --dash-shadow: 0 30px 80px rgba(2, 6, 23, 0.45);
            --dash-clip: polygon(0 0, 84% 0, 100% 14%, 100% 100%, 0 100%);
        }

        html, body {
            background:
                radial-gradient(circle at top, rgba(34, 211, 238, 0.12), transparent 32%),
                radial-gradient(circle at 85% 20%, rgba(59, 130, 246, 0.12), transparent 24%),
                linear-gradient(180deg, #050816 0%, var(--dash-bg) 100%) !important;
        }

        .dashboard-shell {
            position: relative;
        }

        .dashboard-shell::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.04), transparent 18%),
                linear-gradient(90deg, rgba(103, 232, 249, 0.06), transparent 45%);
            opacity: 0.9;
        }

        .dashboard-frame {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--dash-border);
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.95), rgba(8, 15, 28, 0.94));
            clip-path: var(--dash-clip);
            -webkit-clip-path: var(--dash-clip);
            box-shadow: var(--dash-shadow);
        }

        .dashboard-frame::before {
            content: '';
            position: absolute;
            inset: 0;
            border: 1px solid rgba(255, 255, 255, 0.06);
            clip-path: polygon(0.75rem 0.75rem, calc(84% - 0.75rem) 0.75rem, calc(100% - 0.75rem) calc(14% + 0.15rem), calc(100% - 0.75rem) calc(100% - 0.75rem), 0.75rem calc(100% - 0.75rem));
            -webkit-clip-path: polygon(0.75rem 0.75rem, calc(84% - 0.75rem) 0.75rem, calc(100% - 0.75rem) calc(14% + 0.15rem), calc(100% - 0.75rem) calc(100% - 0.75rem), 0.75rem calc(100% - 0.75rem));
            pointer-events: none;
            opacity: 0.9;
        }

        .dashboard-frame > *,
        .dashboard-card > * {
            position: relative;
            z-index: 1;
        }

        .dashboard-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid var(--dash-border-strong);
            background: rgba(15, 23, 42, 0.7);
            color: var(--dash-accent);
            letter-spacing: 0.18em;
        }

        .dashboard-title {
            color: var(--dash-text);
            letter-spacing: -0.03em;
        }

        .dashboard-copy {
            color: var(--dash-muted);
        }

        .dashboard-card {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--dash-border);
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.88), rgba(9, 14, 26, 0.94));
            clip-path: var(--dash-clip);
            -webkit-clip-path: var(--dash-clip);
            box-shadow: 0 22px 50px rgba(2, 6, 23, 0.28);
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-3px);
            border-color: rgba(103, 232, 249, 0.35);
            box-shadow: 0 28px 60px rgba(8, 47, 73, 0.22);
        }

        .dashboard-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(103, 232, 249, 0.08), transparent 42%);
            pointer-events: none;
        }

        .card-tag {
            display: inline-flex;
            align-items: center;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(15, 23, 42, 0.72);
            color: #cbd5e1;
            letter-spacing: 0.14em;
        }

        .card-icon-shell {
            border: 1px solid rgba(103, 232, 249, 0.22);
            background: rgba(14, 116, 144, 0.14);
            color: var(--dash-accent);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }

        .primary-link {
            background: linear-gradient(135deg, #67e8f9 0%, #22d3ee 100%) !important;
            color: #082f49 !important;
            box-shadow: 0 18px 36px rgba(34, 211, 238, 0.18);
        }

        .primary-link:hover {
            filter: brightness(1.04);
            box-shadow: 0 22px 42px rgba(34, 211, 238, 0.24);
        }

        .secondary-link {
            border-color: rgba(103, 232, 249, 0.18) !important;
            background: rgba(15, 23, 42, 0.62) !important;
            color: #e2e8f0 !important;
        }

        .secondary-link:hover {
            border-color: rgba(103, 232, 249, 0.35) !important;
            background: rgba(15, 23, 42, 0.88) !important;
        }

        .subtle-link {
            border-color: rgba(148, 163, 184, 0.16) !important;
            background: rgba(15, 23, 42, 0.48) !important;
            color: #cbd5e1 !important;
        }

        .subtle-link:hover {
            border-color: rgba(148, 163, 184, 0.3) !important;
            background: rgba(15, 23, 42, 0.72) !important;
        }

        .quick-action {
            justify-content: flex-start !important;
            transition: transform 0.2s ease, border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
        }

        .quick-action:hover {
            transform: translateX(3px);
        }

        .back-link {
            color: #cbd5e1 !important;
        }

        .back-link:hover {
            color: #ffffff !important;
        }

        .logout-btn {
            border-color: rgba(148, 163, 184, 0.18) !important;
            background: rgba(15, 23, 42, 0.72) !important;
            color: #e2e8f0 !important;
        }

        .logout-btn:hover {
            border-color: rgba(103, 232, 249, 0.35) !important;
            background: rgba(15, 23, 42, 0.92) !important;
        }

        .welcome-name {
            color: #f8fafc !important;
        }

        @media (max-width: 767px) {
            .dashboard-frame,
            .dashboard-card {
                clip-path: none;
                -webkit-clip-path: none;
                border-radius: 1.5rem;
            }

            .dashboard-frame::before {
                clip-path: none;
                -webkit-clip-path: none;
                border-radius: 1rem;
            }
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

    <main class="dashboard-shell min-h-screen flex items-center justify-center px-4 py-24">
        <div class="relative w-full max-w-5xl">
            <div class="dashboard-frame p-8 md:p-10 lg:p-12">
                <div class="flex flex-col gap-10">
                    <div class="flex flex-col gap-8 md:flex-row md:items-start md:justify-between">
                        <div class="max-w-2xl">
                            <div class="dashboard-pill rounded-full px-4 py-2 text-xs font-semibold uppercase">
                                Admin Dashboard
                            </div>
                            <h1 class="dashboard-title mt-5 text-3xl font-bold md:text-5xl">
                                Technician Operations
                            </h1>
                            <p class="mt-4 text-base text-slate-100 md:text-lg">
                                Welcome back, <span class="welcome-name font-bold"><?= htmlspecialchars($adminUsername, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>.
                            </p>
                            <p class="dashboard-copy mt-3 max-w-xl text-sm leading-7 md:text-base">
                                Keep scheduling, technician access, and admin utilities organized from one clean control panel.
                            </p>
                        </div>

                        <div class="dashboard-card max-w-sm p-6">
                            <div class="card-tag rounded-full px-3 py-1 text-[11px] font-semibold uppercase">
                                Primary Focus
                            </div>
                            <h2 class="mt-5 text-2xl font-semibold text-white">
                                Scheduling first
                            </h2>
                            <p class="dashboard-copy mt-3 text-sm leading-6">
                                Open the scheduling workspace to review bookings, assignments, and upcoming technician availability.
                            </p>
                            <a
                                href="technician/schedule.php"
                                class="primary-link mt-6 inline-flex items-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold transition-all"
                            >
                                Open technician schedule
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
                        <a
                            href="technician/schedule.php"
                            class="dashboard-card group flex min-h-[250px] flex-col justify-between p-6 md:p-7"
                        >
                            <div>
                                <div class="card-tag rounded-full px-3 py-1 text-[11px] font-semibold uppercase">
                                    Primary Access
                                </div>
                                <span class="card-icon-shell mt-5 inline-flex h-12 w-12 items-center justify-center rounded-xl text-cyan-400">
                                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 7V3m8 4V3m-9 8h10m-11 9h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v11a2 2 0 002 2z"/>
                                    </svg>
                                </span>
                                <h2 class="mt-5 text-2xl font-semibold text-white">Technician Schedule</h2>
                                <p class="dashboard-copy mt-3 text-sm leading-7">
                                    Open the scheduling workspace to review bookings, assignments, and upcoming technician availability.
                                </p>
                            </div>

                            <div class="mt-8 inline-flex items-center gap-2 text-base font-semibold text-cyan-300">
                                Open schedule
                                <span class="transition-transform group-hover:translate-x-1">&rarr;</span>
                            </div>
                        </a>

                        <div class="dashboard-card p-6 md:p-7">
                            <div class="card-tag rounded-full px-3 py-1 text-[11px] font-semibold uppercase">
                                Utility Links
                            </div>
                            <h2 class="mt-5 text-2xl font-semibold text-white">Quick Access</h2>
                            <p class="dashboard-copy mt-3 text-sm leading-7">
                                You are signed in and ready to access the admin tools available in this portal.
                            </p>

                            <div class="mt-6 space-y-3">
                                <a
                                    href="technician/schedule.php"
                                    class="primary-link quick-action inline-flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold"
                                >
                                    Go to Technician Schedule
                                </a>
                                <a
                                    href="technician-dashboard.php"
                                    class="secondary-link quick-action inline-flex w-full items-center justify-center gap-2 rounded-xl border px-4 py-3 text-sm font-semibold"
                                >
                                    Technician Dashboard
                                </a>
                                <a
                                    href="settings.php"
                                    class="subtle-link quick-action inline-flex w-full items-center justify-center gap-2 rounded-xl border px-4 py-3 text-sm font-semibold"
                                >
                                    Open Admin Settings
                                </a>
                                <a
                                    href="mileage-tracker.php"
                                    class="subtle-link quick-action inline-flex w-full items-center justify-center gap-2 rounded-xl border px-4 py-3 text-sm font-semibold"
                                >
                                    IRS Mileage Tracker
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
