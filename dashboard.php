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
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Ghost Laser</title>
    <link rel="icon" type="image/png" href="/ghost-logo2-32x32.png">
    <link rel="shortcut icon" type="image/png" href="/ghost-logo2-32x32.png">
    <meta name="description" content="Ghost Laser admin dashboard.">
    <script src="https://cdn.tailwindcss.com?v=1.2"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        cyan: {
                            400: '#22d3ee',
                            500: '#06b6d4',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap&v=1.2" rel="stylesheet">
    <style>
        .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
        .nav-blur {
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
        .hero-grid {
            background-image: linear-gradient(rgba(6,182,212,0.04) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(6,182,212,0.04) 1px, transparent 1px);
            background-size: 60px 60px;
        }
    </style>
</head>
<body class="bg-zinc-950 text-white font-sans antialiased">

    <header class="fixed top-0 left-0 right-0 z-50 nav-blur bg-zinc-950/80 border-b border-zinc-800/60">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="/" class="flex items-center gap-2.5 group">
                    <span class="w-7 h-7 rounded bg-cyan-500 flex items-center justify-center flex-shrink-0 group-hover:bg-cyan-400 transition-colors">
                        <svg class="w-4 h-4 text-zinc-950" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10 1C6.13 1 3 4.13 3 8v10l2.5-2 2.5 2 2.5-2 2.5 2 2.5-2 2.5 2V8C17 4.13 13.87 1 10 1z"/>
                        </svg>
                    </span>
                    <span class="text-white font-bold text-lg tracking-tight">Ghost<span class="text-cyan-400">Laser</span></span>
                </a>
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
            </div>
        </div>
    </header>

    <main class="min-h-screen flex items-center justify-center hero-grid pt-16 px-4">
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <div class="w-[500px] h-[500px] rounded-full bg-cyan-500/5 blur-3xl"></div>
        </div>

        <div class="relative w-full max-w-3xl">
            <div class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-8 md:p-10 card-glow">
                <div class="flex flex-col gap-8 md:flex-row md:items-start md:justify-between">
                    <div class="max-w-xl">
                        <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/20 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-[0.2em] text-cyan-400">
                            Admin Dashboard
                        </div>
                        <h1 class="mt-5 text-3xl md:text-4xl font-bold tracking-tight">
                            Welcome back, <?= htmlspecialchars($adminUsername, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.
                        </h1>
                        <p class="mt-3 text-base text-zinc-400 md:text-lg">
                            Manage technician scheduling and keep operations moving from one central place.
                        </p>
                    </div>

                </div>

                <div class="mt-10 grid gap-6 md:grid-cols-[1.2fr_0.8fr]">
                    <a
                        href="technician/schedule.php"
                        class="group flex min-h-[220px] flex-col justify-between rounded-2xl border border-cyan-500/20 bg-zinc-950/70 p-6 transition-all hover:-translate-y-1 hover:border-cyan-400/60 hover:bg-zinc-950"
                    >
                        <div>
                            <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-cyan-500/10 text-cyan-400">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 7V3m8 4V3m-9 8h10m-11 9h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v11a2 2 0 002 2z"/>
                                </svg>
                            </span>
                            <h2 class="mt-5 text-2xl font-semibold text-white">Technician Schedule</h2>
                            <p class="mt-3 text-sm leading-6 text-zinc-400">
                                Open the scheduling workspace to review bookings, assignments, and upcoming technician availability.
                            </p>
                        </div>

                        <div class="mt-8 inline-flex items-center gap-2 text-base font-semibold text-cyan-400">
                            Open schedule
                            <span class="transition-transform group-hover:translate-x-1">&rarr;</span>
                        </div>
                    </a>

                    <div class="rounded-2xl border border-zinc-800 bg-zinc-950/60 p-6">
                        <h2 class="text-lg font-semibold text-white">Quick Access</h2>
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
                                href="settings.php"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-zinc-700 px-4 py-3 text-sm font-semibold text-zinc-200 transition-all hover:border-cyan-400 hover:text-white"
                            >
                                Open Admin Settings
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
