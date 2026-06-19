<?php
$serviceRequests = [
    [
        'customer_name' => 'Avery Collins',
        'city' => 'Austin',
        'state' => 'TX',
        'priority' => 'Emergency',
        'suggested_dates' => ['June 20, 2026', 'June 21, 2026'],
        'status' => 'Awaiting Technician Assignment',
    ],
    [
        'customer_name' => 'Mason Patel',
        'city' => 'Phoenix',
        'state' => 'AZ',
        'priority' => 'VIP',
        'suggested_dates' => ['June 22, 2026'],
        'status' => 'Confirmed',
    ],
    [
        'customer_name' => 'Olivia Nguyen',
        'city' => 'Denver',
        'state' => 'CO',
        'priority' => 'Standard',
        'suggested_dates' => ['June 24, 2026', 'June 25, 2026'],
        'status' => 'Pending Customer Approval',
    ],
    [
        'customer_name' => 'Jordan Martinez',
        'city' => 'Tampa',
        'state' => 'FL',
        'priority' => 'VIP',
        'suggested_dates' => ['June 23, 2026'],
        'status' => 'Parts Check In Progress',
    ],
    [
        'customer_name' => 'Harper Brooks',
        'city' => 'Nashville',
        'state' => 'TN',
        'priority' => 'Emergency',
        'suggested_dates' => ['June 20, 2026'],
        'status' => 'Dispatch Ready',
    ],
    [
        'customer_name' => 'Noah Bennett',
        'city' => 'Raleigh',
        'state' => 'NC',
        'priority' => 'Standard',
        'suggested_dates' => ['June 26, 2026', 'June 27, 2026'],
        'status' => 'Scheduling Review',
    ],
];

$priorityStyles = [
    'Emergency' => [
        'badge' => 'border-red-400/30 bg-red-500/15 text-red-200',
        'dot' => 'bg-red-400',
        'accent' => 'text-red-300',
    ],
    'VIP' => [
        'badge' => 'border-orange-400/30 bg-orange-500/15 text-orange-200',
        'dot' => 'bg-orange-400',
        'accent' => 'text-orange-300',
    ],
    'Standard' => [
        'badge' => 'border-blue-400/30 bg-blue-500/15 text-blue-200',
        'dot' => 'bg-blue-400',
        'accent' => 'text-blue-300',
    ],
];

$statusStyles = [
    'Awaiting Technician Assignment' => 'border-amber-400/25 bg-amber-500/10 text-amber-200',
    'Confirmed' => 'border-emerald-400/25 bg-emerald-500/10 text-emerald-200',
    'Pending Customer Approval' => 'border-violet-400/25 bg-violet-500/10 text-violet-200',
    'Parts Check In Progress' => 'border-cyan-400/25 bg-cyan-500/10 text-cyan-200',
    'Dispatch Ready' => 'border-green-400/25 bg-green-500/10 text-green-200',
    'Scheduling Review' => 'border-zinc-400/25 bg-zinc-500/10 text-zinc-200',
];

$totalRequests = count($serviceRequests);
$emergencyCount = count(array_filter($serviceRequests, static fn ($request) => $request['priority'] === 'Emergency'));
$vipCount = count(array_filter($serviceRequests, static fn ($request) => $request['priority'] === 'VIP'));
$standardCount = count(array_filter($serviceRequests, static fn ($request) => $request['priority'] === 'Standard'));
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Schedule | Ghost Laser</title>
    <meta name="description" content="Upcoming Ghost Laser service requests for technician scheduling and dispatch planning.">
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
        .glow-cyan { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
        .glow-box { box-shadow: 0 0 0 1px rgba(6,182,212,0.2), 0 0 40px rgba(6,182,212,0.05); }
        .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .nav-blur {
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
    </style>
</head>
<body class="bg-zinc-950 text-white font-sans antialiased">
    <header class="fixed top-0 left-0 right-0 z-50 nav-blur bg-zinc-950/80 border-b border-zinc-800/60">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="../" class="flex items-center gap-2.5 group">
                    <span class="w-7 h-7 rounded bg-cyan-500 flex items-center justify-center flex-shrink-0 group-hover:bg-cyan-400 transition-colors">
                        <svg class="w-4 h-4 text-zinc-950" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"/>
                        </svg>
                    </span>
                    <span class="text-white font-bold text-lg tracking-tight">Ghost<span class="text-cyan-400">Laser</span></span>
                </a>
                <nav class="hidden md:flex items-center gap-8">
                    <a href="../#services" class="text-sm text-zinc-400 hover:text-white transition-colors">Services</a>
                    <a href="../#why-us" class="text-sm text-zinc-400 hover:text-white transition-colors">Why Us</a>
                    <a href="../#process" class="text-sm text-zinc-400 hover:text-white transition-colors">Process</a>
                    <a href="../book-repair.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Bookings</a>
                </nav>
                <a href="../book-repair.php" class="hidden md:inline-flex items-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-2 rounded-md transition-colors btn-glow">
                    New Repair Request
                </a>
                <button id="mobile-menu-btn" class="md:hidden text-zinc-400 hover:text-white p-1" aria-label="Open menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>
        <div id="mobile-menu" class="hidden md:hidden border-t border-zinc-800/60 bg-zinc-950/95">
            <div class="px-6 py-4 flex flex-col gap-4">
                <a href="../#services" class="text-sm text-zinc-400 hover:text-white transition-colors">Services</a>
                <a href="../#why-us" class="text-sm text-zinc-400 hover:text-white transition-colors">Why Us</a>
                <a href="../#process" class="text-sm text-zinc-400 hover:text-white transition-colors">Process</a>
                <a href="../book-repair.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Bookings</a>
                <a href="../book-repair.php" class="inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-2 rounded-md transition-colors w-full">
                    New Repair Request
                </a>
            </div>
        </div>
    </header>

    <main class="pt-32 pb-24 lg:pb-32">
        <section class="mb-12">
            <div class="max-w-7xl mx-auto px-6 lg:px-8">
                <div class="inline-flex items-center gap-2 bg-zinc-900 border border-cyan-500/30 rounded-full px-4 py-1.5 mb-8">
                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
                    <span class="text-xs text-cyan-400 font-medium tracking-wider uppercase">Technician Dispatch Board</span>
                </div>
                <div class="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight mb-5">
                            Upcoming <span class="text-cyan-400 glow-cyan">Service Requests</span>
                        </h1>
                        <p class="text-zinc-400 text-lg leading-relaxed max-w-2xl">
                            A clear scheduling view for all upcoming customer requests, with priority cues and suggested dates ready for dispatch planning.
                        </p>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 w-full lg:max-w-3xl">
                        <div class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4 glow-box">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Total</p>
                            <p class="mt-3 text-3xl font-black text-white"><?= $totalRequests ?></p>
                        </div>
                        <div class="rounded-2xl border border-red-500/20 bg-red-500/5 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-red-300/80">Emergency</p>
                            <p class="mt-3 text-3xl font-black text-red-200"><?= $emergencyCount ?></p>
                        </div>
                        <div class="rounded-2xl border border-orange-500/20 bg-orange-500/5 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-300/80">VIP</p>
                            <p class="mt-3 text-3xl font-black text-orange-200"><?= $vipCount ?></p>
                        </div>
                        <div class="rounded-2xl border border-blue-500/20 bg-blue-500/5 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-300/80">Standard</p>
                            <p class="mt-3 text-3xl font-black text-blue-200"><?= $standardCount ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section>
            <div class="max-w-7xl mx-auto px-6 lg:px-8">
                <div class="overflow-hidden rounded-3xl border border-zinc-800 bg-zinc-900/60 glow-box">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-800">
                            <thead class="bg-zinc-950/70">
                                <tr>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Customer name</th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Service address</th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Priority level</th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Suggested service date(s)</th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/80">
                                <?php foreach ($serviceRequests as $request): ?>
                                    <?php
                                    $priorityStyle = $priorityStyles[$request['priority']] ?? $priorityStyles['Standard'];
                                    $statusStyle = $statusStyles[$request['status']] ?? 'border-zinc-400/25 bg-zinc-500/10 text-zinc-200';
                                    ?>
                                    <tr class="align-top transition-colors hover:bg-zinc-900/80">
                                        <td class="px-6 py-5">
                                            <p class="text-sm font-semibold text-white"><?= htmlspecialchars($request['customer_name']) ?></p>
                                        </td>
                                        <td class="px-6 py-5">
                                            <p class="text-sm text-zinc-300"><?= htmlspecialchars($request['city']) ?>, <?= htmlspecialchars($request['state']) ?></p>
                                        </td>
                                        <td class="px-6 py-5">
                                            <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold tracking-wide <?= htmlspecialchars($priorityStyle['badge']) ?>">
                                                <span class="h-2 w-2 rounded-full <?= htmlspecialchars($priorityStyle['dot']) ?>"></span>
                                                <?= htmlspecialchars($request['priority']) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-5">
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach ($request['suggested_dates'] as $date): ?>
                                                    <span class="inline-flex items-center rounded-full border border-cyan-500/20 bg-cyan-500/5 px-3 py-1 text-xs font-medium text-cyan-100">
                                                        <?= htmlspecialchars($date) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-5">
                                            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold tracking-wide <?= htmlspecialchars($statusStyle) ?>">
                                                <?= htmlspecialchars($request['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="bg-zinc-950 border-t border-zinc-800/60 py-10">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2.5">
                <span class="w-6 h-6 rounded bg-cyan-500 flex items-center justify-center flex-shrink-0">
                    <svg class="w-3.5 h-3.5 text-zinc-950" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"/>
                    </svg>
                </span>
                <span class="text-white font-bold text-sm">Ghost<span class="text-cyan-400">Laser</span></span>
            </div>
            <p class="text-xs text-zinc-500 text-center">
                &copy; <?= date('Y') ?> Ghost Laser. All rights reserved. Expert laser machine repair.
            </p>
            <div class="flex items-center gap-5">
                <a href="../" class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors">Home</a>
                <a href="../book-repair.php" class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors">Bookings</a>
            </div>
        </div>
    </footer>

    <script>
        const btn = document.getElementById('mobile-menu-btn');
        const menu = document.getElementById('mobile-menu');

        btn.addEventListener('click', () => menu.classList.toggle('hidden'));
        menu.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => menu.classList.add('hidden'));
        });
    </script>
</body>
</html>
