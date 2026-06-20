<?php

$priorityStyles = [
    'Emergency' => [
        'badge' => 'border-red-400/30 bg-red-500/15 text-red-200',
        'dot' => 'bg-red-400',
    ],
    'VIP' => [
        'badge' => 'border-orange-400/30 bg-orange-500/15 text-orange-200',
        'dot' => 'bg-orange-400',
    ],
    'Standard' => [
        'badge' => 'border-blue-400/30 bg-blue-500/15 text-blue-200',
        'dot' => 'bg-blue-400',
    ],
];

$statusStyles = [
    'confirmed' => 'border-emerald-400/25 bg-emerald-500/10 text-emerald-200',
    'pending customer approval' => 'border-violet-400/25 bg-violet-500/10 text-violet-200',
    'parts check in progress' => 'border-cyan-400/25 bg-cyan-500/10 text-cyan-200',
    'dispatch ready' => 'border-green-400/25 bg-green-500/10 text-green-200',
    'awaiting technician assignment' => 'border-amber-400/25 bg-amber-500/10 text-amber-200',
    'scheduling review' => 'border-zinc-400/25 bg-zinc-500/10 text-zinc-200',
    'scheduled' => 'border-cyan-400/25 bg-cyan-500/10 text-cyan-200',
    'in progress' => 'border-orange-400/25 bg-orange-500/10 text-orange-200',
];

$serviceRequests = [];
$errorMessage = null;

try {
    // Database connection
    $pdo = new PDO(
        "mysql:host=127.0.0.1;port=3306;dbname=ghost_laser;charset=utf8mb4",
        "root",
        "",
        [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $sql = "SELECT c.first_name, c.last_name, c.city, c.state,
                   sr.priority_level, sr.suggested_dates, sr.request_status
            FROM service_requests sr
            JOIN customers c ON sr.customer_id = c.id
            WHERE sr.request_status NOT IN ('completed', 'cancelled')
            ORDER BY FIELD(LOWER(sr.priority_level), 'emergency', 'vip') DESC, sr.suggested_dates ASC";

    $rows = $pdo->query($sql)->fetchAll();
    $today = strtotime(date('Y-m-d'));

    foreach ($rows as $row) {
        // Parse suggested dates from JSON or comma-separated string
        $raw = trim((string) ($row['suggested_dates'] ?? ''));
        $dateValues = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $dateValues = is_array($decoded) ? $decoded : preg_split('/\s*[,|;]\s*/', $raw);
        }

        // Format dates as "Month Day, Year" and skip fully past requests
        $dates = [];
        $earliest = null;
        foreach ($dateValues as $d) {
            $ts = strtotime(trim((string) $d));
            if ($ts === false) continue;
            $dayTs = strtotime(date('Y-m-d', $ts));
            if ($earliest === null || $dayTs < $earliest) $earliest = $dayTs;
            $label = date('F j, Y', $ts);
            if (!in_array($label, $dates, true)) $dates[] = $label;
        }

        if ($earliest !== null && $earliest < $today) continue;

        $priorityRaw = strtolower(trim((string) ($row['priority_level'] ?? '')));
        $priority = match ($priorityRaw) {
            'emergency' => 'Emergency',
            'vip'       => 'VIP',
            default     => 'Standard',
        };
        $priorityRank = match ($priority) {
            'Emergency' => 0,
            'VIP'       => 1,
            default     => 2,
        };

        $name = trim(trim((string) $row['first_name']) . ' ' . trim((string) $row['last_name']));

        $serviceRequests[] = [
            'customer_name'  => $name !== '' ? $name : 'Unknown Customer',
            'city'           => trim((string) ($row['city'] ?? '')),
            'state'          => strtoupper(trim((string) ($row['state'] ?? ''))),
            'priority_level' => $priority,
            'suggested_dates'=> $dates,
            'status'         => trim((string) ($row['request_status'] ?? '')) ?: 'Pending Review',
            'sort_priority'  => $priorityRank,
            'sort_date'      => $earliest ?? PHP_INT_MAX,
        ];
    }

    usort($serviceRequests, fn($a, $b) =>
        [$a['sort_priority'], $a['sort_date'], $a['customer_name']]
        <=> [$b['sort_priority'], $b['sort_date'], $b['customer_name']]
    );
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

$totalRequests  = count($serviceRequests);
$emergencyCount = count(array_filter($serviceRequests, fn($r) => $r['priority_level'] === 'Emergency'));
$vipCount       = count(array_filter($serviceRequests, fn($r) => $r['priority_level'] === 'VIP'));
$standardCount  = count(array_filter($serviceRequests, fn($r) => $r['priority_level'] === 'Standard'));
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
                            Live schedule data from the repair queue, sorted by priority first and then by the next suggested service date.
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
                <?php if ($errorMessage !== null): ?>
                    <div class="mb-8 rounded-2xl border border-red-500/30 bg-red-950/50 px-5 py-4 text-sm text-red-100">
                        <p class="font-semibold text-red-300">Unable to load live schedule data.</p>
                        <p class="mt-1 text-red-200/80"><?= htmlspecialchars($errorMessage) ?></p>
                    </div>
                <?php endif; ?>

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
                                <?php if ($serviceRequests === []): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-12 text-center">
                                            <p class="text-sm font-semibold text-white">No upcoming service requests found.</p>
                                            <p class="mt-2 text-sm text-zinc-400">Once real requests with upcoming suggested dates are available, they will appear here automatically.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($serviceRequests as $request): ?>
                                        <?php
                                        $priorityStyle = $priorityStyles[$request['priority_level']] ?? $priorityStyles['Standard'];
                                        $statusKey = strtolower(trim($request['status']));
                                        $statusStyle = $statusStyles[$statusKey] ?? 'border-zinc-400/25 bg-zinc-500/10 text-zinc-200';
                                        ?>
                                        <tr class="align-top transition-colors hover:bg-zinc-900/80">
                                            <td class="px-6 py-5">
                                                <p class="text-sm font-semibold text-white"><?= htmlspecialchars($request['customer_name']) ?></p>
                                            </td>
                                            <td class="px-6 py-5">
                                                <p class="text-sm text-zinc-300">
                                                    <?= htmlspecialchars($request['city'] !== '' ? $request['city'] : 'Unknown City') ?>,
                                                    <?= htmlspecialchars($request['state'] !== '' ? $request['state'] : 'N/A') ?>
                                                </p>
                                            </td>
                                            <td class="px-6 py-5">
                                                <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold tracking-wide <?= $priorityStyle['badge'] ?>">
                                                    <span class="h-2 w-2 rounded-full <?= $priorityStyle['dot'] ?>"></span>
                                                    <?= htmlspecialchars($request['priority_level']) ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-5">
                                                <div class="flex flex-wrap gap-2">
                                                    <?php if ($request['suggested_dates'] === []): ?>
                                                        <span class="inline-flex items-center rounded-full border border-zinc-700 bg-zinc-800/70 px-3 py-1 text-xs font-medium text-zinc-300">
                                                            Awaiting scheduling
                                                        </span>
                                                    <?php else: ?>
                                                        <?php foreach ($request['suggested_dates'] as $date): ?>
                                                            <span class="inline-flex items-center rounded-full border border-cyan-500/20 bg-cyan-500/5 px-3 py-1 text-xs font-medium text-cyan-100">
                                                                <?= htmlspecialchars($date) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-5">
                                                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold tracking-wide <?= $statusStyle ?>">
                                                    <?= htmlspecialchars($request['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
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
