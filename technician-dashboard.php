<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/scheduling_settings.php';

// ── Date navigation ────────────────────────────────────────────────────────
$dateParam = trim((string) ($_GET['date'] ?? ''));
$parsedDate = $dateParam !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $dateParam) : false;
if ($parsedDate !== false && $parsedDate->format('Y-m-d') === $dateParam) {
    $viewDate = $parsedDate;
} else {
    $viewDate = new DateTimeImmutable('today');
}

$prevDate = $viewDate->modify('-1 day');
$nextDate = $viewDate->modify('+1 day');
$dateKey  = $viewDate->format('Y-m-d');

// ── Load scheduled clusters for the selected date ─────────────────────────
$scheduledJobsStmt = $pdo->prepare("
    SELECT
        sc.id AS scheduled_cluster_id,
        sc.cluster_label,
        sc.centroid_latitude,
        sc.centroid_longitude,
        scj.time_window_start,
        scj.time_window_end,
        sr.id AS service_request_id,
        sr.priority_level,
        sr.problem_summary,
        c.first_name,
        c.last_name,
        c.phone,
        c.address,
        c.city,
        c.state,
        c.zip
    FROM scheduled_clusters sc
    JOIN scheduled_cluster_jobs scj ON scj.scheduled_cluster_id = sc.id
    JOIN service_requests sr ON sr.id = scj.service_request_id
    JOIN customers c ON c.id = sr.customer_id
    WHERE sc.scheduled_date = :date
    ORDER BY
        FIELD(LOWER(sr.priority_level), 'emergency', 'vip', 'standard'),
        sc.cluster_label ASC,
        scj.time_window_start ASC
");
$scheduledJobsStmt->execute([':date' => $dateKey]);
$rawJobs = $scheduledJobsStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Group jobs by cluster ─────────────────────────────────────────────────
$clusters = [];
foreach ($rawJobs as $job) {
    $cid = (int) $job['scheduled_cluster_id'];
    if (!isset($clusters[$cid])) {
        $clusters[$cid] = [
            'scheduled_cluster_id' => $cid,
            'cluster_label'        => $job['cluster_label'],
            'jobs'                 => [],
        ];
    }
    $clusters[$cid]['jobs'][] = $job;
}
$clusters = array_values($clusters);

// ── Helpers ───────────────────────────────────────────────────────────────
function techDashFormatAddress(array $job): string
{
    $parts = array_filter([
        trim((string) ($job['address'] ?? '')),
        trim((string) ($job['city'] ?? '')),
        trim((string) ($job['state'] ?? '')),
        trim((string) ($job['zip'] ?? '')),
    ]);
    return $parts ? implode(', ', $parts) : 'Address unavailable';
}

function techDashMapsUrl(array $job): string
{
    $addr = techDashFormatAddress($job);
    return 'https://waze.com/ul?q=' . rawurlencode($addr) . '&navigate=yes';
}

function techDashTimeWindow(?string $start, ?string $end): string
{
    if ($start === null || $end === null) {
        return 'TBD';
    }
    $fmt = static function (string $t): string {
        [$h, $m] = array_map('intval', explode(':', $t));
        $period = $h >= 12 ? 'PM' : 'AM';
        $disp   = $h % 12 ?: 12;
        return $m === 0 ? "{$disp}:00 {$period}" : sprintf('%d:%02d %s', $disp, $m, $period);
    };
    return $fmt($start) . ' – ' . $fmt($end);
}

function techDashPriorityBadge(string $level): string
{
    $level = strtolower(trim($level));
    switch ($level) {
        case 'emergency':
            return '<span class="tech-badge tech-badge-emergency">Emergency</span>';
        case 'vip':
            return '<span class="tech-badge tech-badge-vip">VIP</span>';
        default:
            return '<span class="tech-badge tech-badge-standard">Standard</span>';
    }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Technician Dashboard | Ghost Laser</title>
    <script src="https://cdn.tailwindcss.com?v=1.2"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        cyan: { 400: '#22d3ee', 500: '#06b6d4' }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap&v=1.2" rel="stylesheet">
    <style>
        body { -webkit-tap-highlight-color: transparent; }

        .tech-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.15rem 0.55rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .tech-badge-emergency {
            background: rgba(239,68,68,0.18);
            border: 1px solid rgba(239,68,68,0.35);
            color: #fca5a5;
        }
        .tech-badge-vip {
            background: rgba(168,85,247,0.18);
            border: 1px solid rgba(168,85,247,0.35);
            color: #d8b4fe;
        }
        .tech-badge-standard {
            background: rgba(6,182,212,0.12);
            border: 1px solid rgba(6,182,212,0.25);
            color: #67e8f9;
        }

        .job-card {
            background: rgba(24,24,27,0.85);
            border: 1px solid rgba(63,63,70,0.8);
            border-radius: 0.875rem;
            padding: 1rem;
            transition: border-color 0.15s;
        }
        .job-card:active { border-color: rgba(6,182,212,0.5); }

        .cluster-heading {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #71717a;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid rgba(63,63,70,0.5);
            margin-bottom: 0.75rem;
        }

        .maps-link {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            color: #22d3ee;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.875rem;
            word-break: break-word;
        }
        .maps-link:active { color: #06b6d4; }

        .mileage-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            border-radius: 0.5rem;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 0.45rem 0.9rem;
            border: none;
            cursor: pointer;
            transition: opacity 0.15s, transform 0.1s;
            -webkit-tap-highlight-color: transparent;
            white-space: nowrap;
        }
        .mileage-btn:active { transform: scale(0.96); }
        .mileage-btn:disabled { opacity: 0.45; cursor: not-allowed; }

        .btn-on-way {
            background: rgba(6,182,212,0.15);
            border: 1px solid rgba(6,182,212,0.4);
            color: #22d3ee;
        }
        .btn-on-way.active {
            background: rgba(6,182,212,0.3);
            border-color: rgba(6,182,212,0.7);
        }

        .btn-arrived {
            background: rgba(34,197,94,0.12);
            border: 1px solid rgba(34,197,94,0.35);
            color: #86efac;
        }
        .btn-arrived.active {
            background: rgba(34,197,94,0.25);
            border-color: rgba(34,197,94,0.65);
        }

        .mileage-status {
            font-size: 0.7rem;
            color: #71717a;
            margin-top: 0.35rem;
            min-height: 1rem;
        }
        .mileage-status.ok  { color: #86efac; }
        .mileage-status.err { color: #fca5a5; }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 0.55rem 1.1rem;
            border-radius: 0.625rem;
            border: 1px solid rgba(63,63,70,0.9);
            background: rgba(24,24,27,0.8);
            color: #e4e4e7;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: border-color 0.15s, background 0.15s;
            -webkit-tap-highlight-color: transparent;
        }
        .nav-btn:active {
            border-color: rgba(6,182,212,0.5);
            background: rgba(6,182,212,0.08);
        }

        .today-chip {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            background: rgba(6,182,212,0.15);
            border: 1px solid rgba(6,182,212,0.3);
            color: #22d3ee;
        }

        .time-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: rgba(39,39,42,0.9);
            border: 1px solid rgba(63,63,70,0.7);
            border-radius: 0.5rem;
            padding: 0.2rem 0.55rem;
            font-size: 0.78rem;
            font-weight: 600;
            color: #a1a1aa;
            white-space: nowrap;
        }

        @media (max-width: 480px) {
            .maps-link { font-size: 0.82rem; }
        }
    </style>
</head>
<body class="bg-zinc-950 text-white font-sans antialiased min-h-screen">

<!-- ── Top bar ──────────────────────────────────────────────────────────── -->
<header class="sticky top-0 z-40 bg-zinc-950/90 border-b border-zinc-800/60" style="backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);">
    <div class="max-w-xl mx-auto px-4 flex items-center justify-between h-14">
        <a href="dashboard.php" class="flex items-center gap-2 group">
            <span class="w-6 h-6 rounded bg-cyan-500 flex items-center justify-center flex-shrink-0 group-hover:bg-cyan-400 transition-colors">
                <svg class="w-3.5 h-3.5 text-zinc-950" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10 1C6.13 1 3 4.13 3 8v10l2.5-2 2.5 2 2.5-2 2.5 2 2.5-2 2.5 2V8C17 4.13 13.87 1 10 1z"/>
                </svg>
            </span>
            <span class="text-white font-bold text-base tracking-tight">Ghost<span class="text-cyan-400">Laser</span></span>
        </a>
        <span class="text-xs text-zinc-400 font-medium">Technician Dashboard</span>
    </div>
</header>

<!-- ── Main ─────────────────────────────────────────────────────────────── -->
<main class="max-w-xl mx-auto px-4 pb-12 pt-5">

    <!-- Date navigation -->
    <div class="flex items-center justify-between gap-2 mb-5">
        <a href="technician-dashboard.php?date=<?= urlencode($prevDate->format('Y-m-d')) ?>" class="nav-btn">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Prev
        </a>

        <div class="text-center flex-1 min-w-0">
            <div class="text-base font-bold text-white leading-tight">
                <?= htmlspecialchars($viewDate->format('l'), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="text-sm text-zinc-400 mt-0.5">
                <?= htmlspecialchars($viewDate->format('M j, Y'), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php if ($viewDate->format('Y-m-d') === (new DateTimeImmutable('today'))->format('Y-m-d')): ?>
                <div class="mt-1.5 flex justify-center"><span class="today-chip">Today</span></div>
            <?php endif; ?>
        </div>

        <a href="technician-dashboard.php?date=<?= urlencode($nextDate->format('Y-m-d')) ?>" class="nav-btn">
            Next
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </a>
    </div>

    <?php if (empty($clusters)): ?>
        <!-- Empty state -->
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <div class="w-14 h-14 rounded-2xl bg-zinc-800/80 flex items-center justify-center mb-4">
                <svg class="w-7 h-7 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10m-11 9h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v11a2 2 0 002 2z"/>
                </svg>
            </div>
            <p class="text-zinc-300 font-semibold text-base">No jobs scheduled</p>
            <p class="text-zinc-500 text-sm mt-1">No job clusters assigned for this day.</p>
            <a href="technician/schedule.php" class="mt-5 inline-flex items-center gap-1.5 rounded-lg border border-zinc-700 px-4 py-2.5 text-sm font-semibold text-zinc-200 hover:border-cyan-400 transition-colors">
                Open Scheduling Dashboard
            </a>
        </div>

    <?php else: ?>

        <!-- Job count summary -->
        <?php
        $totalJobs = array_sum(array_map(fn($c) => count($c['jobs']), $clusters));
        $clusterCount = count($clusters);
        ?>
        <div class="flex items-center gap-2 mb-4">
            <span class="text-sm text-zinc-300 font-medium">
                <?= $totalJobs ?> job<?= $totalJobs !== 1 ? 's' : '' ?> across
                <?= $clusterCount ?> cluster<?= $clusterCount !== 1 ? 's' : '' ?>
            </span>
        </div>

        <!-- Clusters -->
        <?php foreach ($clusters as $clusterIndex => $cluster): ?>
            <div class="mb-7">
                <div class="cluster-heading">
                    <?= htmlspecialchars($cluster['cluster_label'], ENT_QUOTES, 'UTF-8') ?>
                    &mdash;
                    <?= count($cluster['jobs']) ?> job<?= count($cluster['jobs']) !== 1 ? 's' : '' ?>
                </div>

                <div class="space-y-3">
                    <?php foreach ($cluster['jobs'] as $jobIndex => $job): ?>
                        <?php
                        $fullAddress = techDashFormatAddress($job);
                        $mapsUrl     = techDashMapsUrl($job);
                        $timeWindow  = techDashTimeWindow($job['time_window_start'] ?? null, $job['time_window_end'] ?? null);
                        $customerName = trim((string) ($job['first_name'] ?? '') . ' ' . (string) ($job['last_name'] ?? ''));
                        if ($customerName === '') {
                            $customerName = 'Unknown Customer';
                        }
                        ?>
                        <div class="job-card">
                            <!-- Row 1: stop number + priority + time -->
                            <div class="flex items-center justify-between gap-2 mb-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-zinc-800 border border-zinc-700 flex items-center justify-center text-xs font-bold text-zinc-300">
                                        <?= $jobIndex + 1 ?>
                                    </span>
                                    <?= techDashPriorityBadge($job['priority_level'] ?? 'standard') ?>
                                </div>
                                <span class="time-pill">
                                    <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <?= htmlspecialchars($timeWindow, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>

                            <!-- Row 2: customer name -->
                            <div class="text-sm font-semibold text-zinc-100 mb-1.5">
                                <?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>
                            </div>

                            <!-- Row 3: address (clickable Maps link) -->
                            <a href="<?= htmlspecialchars($mapsUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="maps-link mb-2 block">
                                <svg class="w-3.5 h-3.5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <?= htmlspecialchars($fullAddress, ENT_QUOTES, 'UTF-8') ?>
                            </a>

                            <!-- Row 4: problem summary (collapsed) -->
                            <?php if (!empty($job['problem_summary'])): ?>
                                <div class="mt-2 pt-2 border-t border-zinc-800/70">
                                    <p class="text-xs text-zinc-400 leading-relaxed line-clamp-2">
                                        <?= htmlspecialchars($job['problem_summary'], ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <!-- Row 5: mileage tracking buttons -->
                            <div class="mt-3 pt-3 border-t border-zinc-800/70">
                                <div class="flex items-center gap-2">
                                    <button
                                        class="mileage-btn btn-on-way"
                                        data-action="on_my_way"
                                        data-job-id="<?= (int) $job['service_request_id'] ?>"
                                        data-client="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
                                        data-address="<?= htmlspecialchars($fullAddress, ENT_QUOTES, 'UTF-8') ?>"
                                        title="Record departure time and GPS coordinates"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12l7-7m0 0l7 7m-7-7v14"/></svg>
                                        On My Way
                                    </button>
                                    <button
                                        class="mileage-btn btn-arrived"
                                        data-action="arrived"
                                        data-job-id="<?= (int) $job['service_request_id'] ?>"
                                        title="Record arrival time, GPS coordinates, and calculate miles"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        Arrived
                                    </button>
                                </div>
                                <div class="mileage-status" data-status-job="<?= (int) $job['service_request_id'] ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="mt-4 text-center">
            <a href="technician/schedule.php" class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors">
                &larr; Back to Scheduling Dashboard
            </a>
        </div>

    <?php endif; ?>
</main>

<script>
(function () {
    'use strict';

    // ── GPS helper ────────────────────────────────────────────────────────────
    function getCoords() {
        return new Promise(function (resolve, reject) {
            if (!navigator.geolocation) {
                reject(new Error('Geolocation is not supported by this browser.'));
                return;
            }
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude });
                },
                function (err) {
                    reject(new Error('Unable to get location: ' + err.message));
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        });
    }

    // ── Status helper ─────────────────────────────────────────────────────────
    function setStatus(jobId, msg, type) {
        var el = document.querySelector('[data-status-job="' + jobId + '"]');
        if (!el) return;
        el.textContent = msg;
        el.className = 'mileage-status' + (type ? ' ' + type : '');
    }

    // ── API call ──────────────────────────────────────────────────────────────
    function callMileageApi(payload, btn, jobId) {
        btn.disabled = true;
        setStatus(jobId, 'Getting GPS location…', '');

        getCoords().then(function (coords) {
            if (payload.action === 'on_my_way') {
                payload.start_lat = coords.lat;
                payload.start_lng = coords.lng;
            } else {
                payload.end_lat = coords.lat;
                payload.end_lng = coords.lng;
            }

            setStatus(jobId, 'Saving…', '');

            return fetch('/api/mileage-api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (!data.success) {
                setStatus(jobId, '✗ ' + (data.error || 'Error saving'), 'err');
                btn.disabled = false;
                return;
            }

            if (payload.action === 'on_my_way') {
                btn.classList.add('active');
                var arrivedBtn = btn.parentElement.querySelector('[data-action="arrived"]');
                if (arrivedBtn) arrivedBtn.disabled = false;
                setStatus(jobId, '✓ Departed at ' + data.start_time, 'ok');
            } else {
                btn.classList.add('active');
                var miles = data.total_miles !== null && data.total_miles !== undefined
                    ? ' — ' + data.total_miles + ' miles'
                    : '';
                setStatus(jobId, '✓ Arrived at ' + data.end_time + miles, 'ok');
            }
        }).catch(function (err) {
            setStatus(jobId, '✗ ' + err.message, 'err');
            btn.disabled = false;
        });
    }

    // ── Attach listeners ──────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.mileage-btn');
        if (!btn || btn.disabled) return;

        var action  = btn.dataset.action;
        var jobId   = parseInt(btn.dataset.jobId, 10);

        if (action === 'on_my_way') {
            var payload = {
                action:             'on_my_way',
                service_request_id: jobId,
                client_name:        btn.dataset.client  || '',
                address:            btn.dataset.address || ''
            };
            callMileageApi(payload, btn, jobId);
        } else if (action === 'arrived') {
            var payload = {
                action:             'arrived',
                service_request_id: jobId
            };
            callMileageApi(payload, btn, jobId);
        }
    });
}());
</script>

</body>
</html>
