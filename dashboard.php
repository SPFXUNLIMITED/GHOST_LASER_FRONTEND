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
    <link rel="manifest" href="/admin-manifest.webmanifest">
HTML;
$extraHead       = <<<'HTML'
    <style>
        .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
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
                            class="inline-flex items-center justify-center rounded-md border border-zinc-700 bg-zinc-900 px-4 py-2 text-sm font-medium text-zinc-200 transition-all hover:border-cyan-500/50 hover:text-white"
                        >
                            Logout
                        </button>
                    </form>
                </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen hero-grid flex items-center justify-center px-4 py-24">
        <!-- Ambient glow -->
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none overflow-hidden">
            <div class="w-[600px] h-[600px] rounded-full bg-cyan-500/5 blur-3xl"></div>
        </div>

        <div class="relative w-full max-w-5xl">
            <!-- Header row -->
            <div class="flex flex-col gap-2 mb-10">
                <span class="inline-flex items-center gap-2 rounded-full border border-cyan-500/30 bg-cyan-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-cyan-400 w-fit">
                    Technical Operations
                </span>
                <h1 class="text-3xl font-bold tracking-tight md:text-5xl">Admin Dashboard</h1>
                <p class="text-zinc-400 mt-1">
                    Welcome back, <span class="text-white font-semibold"><?= htmlspecialchars($adminUsername, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>.
                    Keep scheduling, technician access, and admin utilities organized from one control panel.
                </p>
            </div>

            <!-- Cards grid -->
            <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
                <!-- Settings card (primary) -->
                <div class="group bg-zinc-900/80 border border-zinc-800 rounded-xl p-7 card-glow flex flex-col justify-between min-h-[260px] transition-colors hover:border-cyan-500/40">
                    <div>
                        <span class="inline-flex items-center rounded-full border border-zinc-700 bg-zinc-800/60 px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-zinc-400">
                            Admin Configuration
                        </span>
                        <span class="mt-5 inline-flex h-12 w-12 items-center justify-center rounded-xl border border-cyan-500/20 bg-cyan-500/10 text-cyan-400">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 9v6m3-3H9m10 0a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </span>
                        <h2 class="mt-5 text-2xl font-semibold text-white">Settings</h2>
                        <p class="mt-3 text-sm text-zinc-400 leading-7">
                            Manage operational configuration pages for scheduling, services, and other admin tools.
                        </p>
                    </div>
                    <div class="mt-8 space-y-2">
                        <a
                            href="service-settings.php"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-2.5 transition-all btn-glow"
                        >
                            Service Settings
                        </a>
                        <a
                            href="speed-settings.php"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-zinc-700 bg-zinc-800/60 hover:border-cyan-500/40 hover:bg-zinc-800 text-zinc-200 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Speed Settings
                        </a>
                        <a
                            href="travel-settings.php"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-zinc-700 bg-zinc-800/60 hover:border-cyan-500/40 hover:bg-zinc-800 text-zinc-200 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Travel Settings
                        </a>
                        <a
                            href="vehicle-settings.php"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-zinc-700 bg-zinc-800/60 hover:border-cyan-500/40 hover:bg-zinc-800 text-zinc-200 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Vehicle Settings
                        </a>
                        <a
                            href="settings.php"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-zinc-700 bg-zinc-800/60 hover:border-cyan-500/40 hover:bg-zinc-800 text-zinc-200 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Schedule Settings
                        </a>
                        <a
                            href="notifications.php"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-zinc-700 bg-zinc-800/60 hover:border-cyan-500/40 hover:bg-zinc-800 text-zinc-200 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Notifications
                        </a>
                        <a
                            href="maintenance-notifications.php"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-zinc-700 bg-zinc-800/60 hover:border-cyan-500/40 hover:bg-zinc-800 text-zinc-200 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Maintenance Notifications
                        </a>
                        <a
                           href="prospect_notifications.php"
                           class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-zinc-700 bg-zinc-800/60 hover:border-cyan-500/40 hover:bg-zinc-800 text-zinc-200 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                           Prospect Notifications
                        </a>
                    </div>
                </div>

                <!-- Quick access card -->
                <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-7 card-glow">
                    <span class="inline-flex items-center rounded-full border border-zinc-700 bg-zinc-800/60 px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-zinc-400">
                        Utility Links
                    </span>
                    <h2 class="mt-5 text-2xl font-semibold text-white">Quick Access</h2>
                    <p class="mt-3 text-sm text-zinc-400 leading-7">
                        You are signed in and ready to access the admin tools available in this portal.
                    </p>

                    <div class="mt-6 space-y-3">
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 pt-1">Operations</p>
                        <a
                            href="technician/schedule.php"
                            class="inline-flex w-full items-center gap-2 rounded-md bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-2.5 transition-all btn-glow"
                        >
                            Scheduling Dashboard
                        </a>
                        <a
                            href="technician-dashboard.php"
                            class="inline-flex w-full items-center gap-2 rounded-md border border-zinc-700 bg-zinc-800/60 hover:border-cyan-500/40 hover:bg-zinc-800 text-zinc-200 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Technician Dashboard
                        </a>
                        <a
                            href="bookings.php"
                            class="inline-flex w-full items-center gap-2 rounded-md border border-violet-700/60 bg-violet-950/30 hover:border-violet-500/60 hover:bg-violet-950/50 text-violet-300 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            All Bookings
                        </a>
                        <a
                            href="book_internal.php"
                            class="inline-flex w-full items-center gap-2 rounded-md border border-amber-700/60 bg-amber-950/30 hover:border-amber-500/60 hover:bg-amber-950/50 text-amber-300 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Book Customer
                        </a>
                        <a
                            href="book_task.php"
                            class="inline-flex w-full items-center gap-2 rounded-md border border-emerald-700/60 bg-emerald-950/30 hover:border-emerald-500/60 hover:bg-emerald-950/50 text-emerald-300 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Book Task
                        </a>
                        <a
                            href="recurring-services.php"
                            class="inline-flex w-full items-center gap-2 rounded-md border border-cyan-700/60 bg-cyan-950/30 hover:border-cyan-500/60 hover:bg-cyan-950/50 text-cyan-300 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Maintenance Schedule
                        </a>
                        <a
                            href="prospects.php"
                            class="inline-flex w-full items-center gap-2 rounded-md border border-sky-700/60 bg-sky-950/30 hover:border-sky-500/60 hover:bg-sky-950/50 text-sky-300 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Prospects
                        </a>
                        <a
                            href="customer-status.php"
                            class="inline-flex w-full items-center gap-2 rounded-md border border-fuchsia-700/60 bg-fuchsia-950/30 hover:border-fuchsia-500/60 hover:bg-fuchsia-950/50 text-fuchsia-300 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            Customer Status
                        </a>

                        <p class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 pt-3">Tracking &amp; Compliance</p>
                        <a
                            href="mileage-tracker.php"
                            class="inline-flex w-full items-center gap-2 rounded-md border border-zinc-700 bg-zinc-800/60 hover:border-zinc-600 hover:bg-zinc-800 text-zinc-300 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            IRS Mileage Tracker
                        </a>

                        <p class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 pt-3">Communication</p>
                        <a
                            href="sms-tool.php"
                            class="inline-flex w-full items-center gap-2 rounded-md border border-violet-700/60 bg-violet-950/30 hover:border-violet-500/60 hover:bg-violet-950/50 text-violet-300 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            SMS Invite
                        </a>

                        <p class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 pt-3">Admin Tools</p>
                        <a
                            href="install-admin-app.php"
                            class="inline-flex w-full items-center gap-2 rounded-md border border-emerald-700/60 bg-emerald-950/30 hover:border-emerald-500/60 hover:bg-emerald-950/50 text-emerald-300 font-medium text-sm px-4 py-2.5 transition-all"
                        >
                            <svg class="h-4 w-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/>
                            </svg>
                            Install Admin App
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
<script>
    (() => {
        // Register the admin-only service worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/admin-sw.js', { scope: '/' })
                .catch((err) => console.warn('Admin SW registration failed:', err));
        }
    })();
</script>
