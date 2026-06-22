<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: ../admin-login.php');
    exit;
}

require_once '../project/db.php';

$jobs = [];
$errorMessage = null;

function firstNonEmpty(array $row, array $keys, string $fallback = 'N/A'): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = trim((string) $row[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

try {
    $sql = "SELECT
                sr.*,
                sr.latitude,
                sr.longitude,
                c.first_name,
                c.last_name,
                c.city
            FROM service_requests sr
            JOIN customers c ON sr.customer_id = c.id
            WHERE sr.request_status IN ('new', 'queued')
            ORDER BY FIELD(LOWER(sr.priority_level), 'emergency', 'vip', 'standard'), sr.created_at DESC";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $customerName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        if ($customerName === '') {
            $customerName = 'Unknown Customer';
        }

        $startDate = trim((string) ($row['preferred_date_start'] ?? ''));
        $endDate = trim((string) ($row['preferred_date_end'] ?? ''));
        $preferredDates = 'N/A';

        if ($startDate !== '' && $endDate !== '') {
            $preferredDates = $startDate . ' to ' . $endDate;
        } elseif ($startDate !== '') {
            $preferredDates = $startDate;
        } elseif ($endDate !== '') {
            $preferredDates = $endDate;
        } else {
            $rawSuggested = trim((string) ($row['suggested_dates'] ?? ''));
            if ($rawSuggested !== '') {
                $decoded = json_decode($rawSuggested, true);
                if (is_array($decoded) && $decoded !== []) {
                    $preferredDates = implode(', ', array_map(static fn($v) => trim((string) $v), $decoded));
                } else {
                    $preferredDates = $rawSuggested;
                }
            }
        }

        $priority = firstNonEmpty($row, ['priority_level', 'priority'], 'Standard');
        $latitude = $row['latitude'] ?? null;
        $longitude = $row['longitude'] ?? null;
        $coordinates = 'Not available';

        if ($latitude !== null && $longitude !== null && $latitude !== '' && $longitude !== '' && is_numeric($latitude) && is_numeric($longitude)) {
            $coordinates = number_format((float) $latitude, 4, '.', '') . ', ' . number_format((float) $longitude, 4, '.', '');
        }

        $jobs[] = [
            'customer_name' => $customerName,
            'city' => firstNonEmpty($row, ['city'], 'Unknown City'),
            'coordinates' => $coordinates,
            'priority' => ucfirst(strtolower($priority)),
            'problem_summary' => firstNonEmpty($row, ['problem_summary', 'problem_description', 'problem', 'issue_summary'], 'No summary provided'),
            'preferred_dates' => $preferredDates,
        ];
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduling Dashboard | Ghost Laser</title>
    <link rel="icon" type="image/png" href="/ghost-logo2-32x32.png">
    <link rel="shortcut icon" type="image/png" href="/ghost-logo2-32x32.png">
    <meta name="description" content="Ghost Laser admin scheduling dashboard.">
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
                <a href="../dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
            </div>
        </div>
    </header>

    <main class="min-h-screen hero-grid pt-24 pb-16 px-4">
        <div class="max-w-7xl mx-auto">
            <div class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/20 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-[0.2em] text-cyan-400">
                            Admin Scheduling
                        </div>
                        <h1 class="mt-4 text-3xl font-bold tracking-tight">Scheduling Dashboard</h1>
                        <p class="mt-2 text-zinc-400">Review pending jobs and prepare routing workflows.</p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg bg-cyan-500 px-6 py-3 text-base font-bold text-zinc-950 transition-all hover:bg-cyan-400 btn-glow"
                    >
                        Run Geographic Clustering
                    </button>
                </div>

                <?php if ($errorMessage !== null): ?>
                    <div class="mt-6 rounded-lg border border-red-500/30 bg-red-950/60 px-4 py-3 text-sm text-red-200">
                        <?= htmlspecialchars($errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <div class="mt-8 overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-950/70">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-800">
                            <thead class="bg-zinc-900/90">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Customer Name</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Service address</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Coordinates</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Priority level</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Problem Summary</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Preferred Dates</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/80">
                                <?php if ($jobs === []): ?>
                                    <tr>
                                        <td colspan="6" class="px-4 py-10 text-center">
                                            <p class="text-sm font-semibold text-white">No pending jobs found.</p>
                                            <p class="mt-2 text-sm text-zinc-400">Jobs with status <span class="text-cyan-300">new</span> or <span class="text-cyan-300">queued</span> will appear here.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($jobs as $job): ?>
                                        <tr class="align-top hover:bg-zinc-900/80">
                                            <td class="px-4 py-4 text-sm font-semibold text-white"><?= htmlspecialchars($job['customer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td class="px-4 py-4 text-sm text-zinc-300"><?= htmlspecialchars($job['city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td class="px-4 py-4 text-sm text-zinc-300"><?= htmlspecialchars($job['coordinates'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td class="px-4 py-4 text-sm text-cyan-300"><?= htmlspecialchars($job['priority'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td class="px-4 py-4 text-sm text-zinc-300 max-w-xs"><?= htmlspecialchars($job['problem_summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td class="px-4 py-4 text-sm text-zinc-300"><?= htmlspecialchars($job['preferred_dates'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
