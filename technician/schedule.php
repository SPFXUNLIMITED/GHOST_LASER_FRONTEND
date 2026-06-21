<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

const CLUSTER_DISTANCE_MILES = 25;

/**
 * Determine whether the provided coordinates can be used for geographic clustering.
 */
function hasValidCoordinates($latitude, $longitude)
{
    return is_numeric($latitude) && is_numeric($longitude);
}

/**
 * Calculate the distance between two points using the Haversine formula.
 */
function haversineDistanceMiles($latitudeOne, $longitudeOne, $latitudeTwo, $longitudeTwo)
{
    $earthRadiusMiles = 3958.8;

    $latitudeDelta = deg2rad((float) $latitudeTwo - (float) $latitudeOne);
    $longitudeDelta = deg2rad((float) $longitudeTwo - (float) $longitudeOne);

    $latitudeOne = deg2rad((float) $latitudeOne);
    $latitudeTwo = deg2rad((float) $latitudeTwo);

    $haversine = sin($latitudeDelta / 2) ** 2
        + cos($latitudeOne) * cos($latitudeTwo) * sin($longitudeDelta / 2) ** 2;

    return 2 * $earthRadiusMiles * asin(min(1, sqrt($haversine)));
}

function isBusinessDay(DateTimeImmutable $date)
{
    return (int) $date->format('N') < 6;
}

/**
 * Add business days while skipping weekends.
 */
function addBusinessDays(DateTimeImmutable $date, $businessDays)
{
    $currentDate = $date;
    $daysAdded = 0;

    while ($daysAdded < $businessDays) {
        $currentDate = $currentDate->modify('+1 day');

        if (isBusinessDay($currentDate)) {
            $daysAdded++;
        }
    }

    return $currentDate;
}

/**
 * Build the scheduling window each job should follow based on its priority.
 */
function getPriorityScheduleWindow($priorityLevel)
{
    $today = new DateTimeImmutable('today');
    $normalizedPriority = strtolower((string) $priorityLevel);

    switch ($normalizedPriority) {
        case 'emergency':
            $startDate = $today;
            $endDate = $today->modify('+1 day');

            return [
                'label' => 'Emergency',
                'order' => 1,
                'window_summary' => $startDate->format('M j') . ' - ' . $endDate->format('M j') . ' (same/next day)',
            ];

        case 'vip':
            $endDate = addBusinessDays($today, 2);

            return [
                'label' => 'VIP',
                'order' => 2,
                'window_summary' => 'Due by ' . $endDate->format('M j') . ' (within 2 business days)',
            ];

        default:
            $startDate = addBusinessDays($today, 3);
            $endDate = addBusinessDays($today, 5);

            return [
                'label' => 'Standard',
                'order' => 3,
                'window_summary' => $startDate->format('M j') . ' - ' . $endDate->format('M j') . ' (3-5 business days)',
            ];
    }
}

/**
 * Group jobs by proximity so dispatch can review nearby work together.
 */
function buildGeographicClusters(array $jobs)
{
    $clusters = [];

    foreach ($jobs as $job) {
        $bestClusterIndex = null;
        $closestDistance = null;

        foreach ($clusters as $clusterIndex => $cluster) {
            $distance = haversineDistanceMiles(
                $job['latitude'],
                $job['longitude'],
                $cluster['centroid_latitude'],
                $cluster['centroid_longitude']
            );

            if ($distance <= CLUSTER_DISTANCE_MILES && ($closestDistance === null || $distance < $closestDistance)) {
                $closestDistance = $distance;
                $bestClusterIndex = $clusterIndex;
            }
        }

        if ($bestClusterIndex === null) {
            $clusters[] = [
                'cluster_label' => 'Cluster ' . str_pad((string) (count($clusters) + 1), 2, '0', STR_PAD_LEFT),
                'centroid_latitude' => (float) $job['latitude'],
                'centroid_longitude' => (float) $job['longitude'],
                'jobs' => [$job],
                'job_count' => 1,
                'highest_priority_order' => $job['priority_meta']['order'],
                'highest_priority_label' => $job['priority_meta']['label'],
            ];

            continue;
        }

        $cluster = $clusters[$bestClusterIndex];
        $newJobCount = $cluster['job_count'] + 1;

        $cluster['centroid_latitude'] = (($cluster['centroid_latitude'] * $cluster['job_count']) + (float) $job['latitude']) / $newJobCount;
        $cluster['centroid_longitude'] = (($cluster['centroid_longitude'] * $cluster['job_count']) + (float) $job['longitude']) / $newJobCount;
        $cluster['jobs'][] = $job;
        $cluster['job_count'] = $newJobCount;

        if ($job['priority_meta']['order'] < $cluster['highest_priority_order']) {
            $cluster['highest_priority_order'] = $job['priority_meta']['order'];
            $cluster['highest_priority_label'] = $job['priority_meta']['label'];
        }

        $clusters[$bestClusterIndex] = $cluster;
    }

    foreach ($clusters as &$cluster) {
        usort($cluster['jobs'], function ($leftJob, $rightJob) {
            $priorityComparison = $leftJob['priority_meta']['order'] <=> $rightJob['priority_meta']['order'];

            if ($priorityComparison !== 0) {
                return $priorityComparison;
            }

            return (int) $rightJob['id'] <=> (int) $leftJob['id'];
        });

        $furthestJobDistance = 0;

        foreach ($cluster['jobs'] as $clusteredJob) {
            $furthestJobDistance = max(
                $furthestJobDistance,
                haversineDistanceMiles(
                    $clusteredJob['latitude'],
                    $clusteredJob['longitude'],
                    $cluster['centroid_latitude'],
                    $cluster['centroid_longitude']
                )
            );
        }

        $cluster['max_distance_miles'] = $furthestJobDistance;
    }
    unset($cluster);

    usort($clusters, function ($leftCluster, $rightCluster) {
        $priorityComparison = $leftCluster['highest_priority_order'] <=> $rightCluster['highest_priority_order'];

        if ($priorityComparison !== 0) {
            return $priorityComparison;
        }

        return $rightCluster['job_count'] <=> $leftCluster['job_count'];
    });

    return $clusters;
}

session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: ../admin-login.php');
    exit;
}

require_once '../project/db.php';

$clusteringRequested = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deleteId = filter_input(
        INPUT_POST,
        'delete_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($deleteId) {
        $deleteStmt = $pdo->prepare("
            UPDATE service_requests
            SET request_status = :status
            WHERE id = :id
        ");
        $deleteStmt->execute([
            ':status' => 'deleted',
            ':id' => $deleteId
        ]);

        header('Location: schedule.php');
        exit;
    }

    $clusteringRequested = isset($_POST['run_clustering']);
}

$jobs = $pdo->query("
    SELECT 
        sr.id,
        c.first_name, 
        c.last_name, 
        c.city,
        sr.latitude,
        sr.longitude,
        sr.priority_level,
        sr.problem_summary,
        sr.preferred_date_start,
        sr.preferred_date_end
    FROM service_requests sr
    JOIN customers c ON sr.customer_id = c.id
    WHERE sr.request_status IN ('new', 'queued')
    ORDER BY FIELD(LOWER(sr.priority_level), 'emergency', 'vip', 'standard'), sr.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($jobs as &$job) {
    $job['priority_meta'] = getPriorityScheduleWindow($job['priority_level'] ?? 'standard');
}
unset($job);

$clusterableJobs = array_values(array_filter($jobs, function ($job) {
    return hasValidCoordinates($job['latitude'] ?? null, $job['longitude'] ?? null);
}));

$clusters = $clusteringRequested ? buildGeographicClusters($clusterableJobs) : [];
$clusterLookup = [];

foreach ($clusters as $cluster) {
    foreach ($cluster['jobs'] as $job) {
        $clusterLookup[(int) $job['id']] = $cluster['cluster_label'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduling Dashboard | Ghost Laser</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-zinc-950 text-white p-8">
    <div class="max-w-7xl mx-auto">
        <h1 class="text-5xl font-bold mb-2">Scheduling Dashboard</h1>

        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-zinc-400">Pending service requests (<?= count($jobs) ?> found)</p>
                <p class="mt-2 text-sm text-zinc-500">
                    Geographic clustering groups pending jobs with valid coordinates into route-friendly batches within <?= CLUSTER_DISTANCE_MILES ?> miles.
                </p>
            </div>

            <form method="POST">
                <button
                    type="submit"
                    name="run_clustering"
                    value="1"
                    class="inline-flex items-center justify-center rounded-lg bg-cyan-500 px-5 py-3 text-sm font-semibold text-zinc-950 transition hover:bg-cyan-400"
                >
                    Run Geographic Clustering
                </button>
            </form>
        </div>

        <?php if ($clusteringRequested): ?>
            <div class="mb-8 rounded-3xl border border-cyan-500/30 bg-zinc-900/80 p-6">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-2xl font-semibold text-white">Clustering Results</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-400">
                            Jobs are ranked by their priority response window first, then grouped by real-world distance using the Haversine formula.
                            Emergency requests stay at the top of each suggested cluster, followed by VIP and Standard work.
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-zinc-700 bg-zinc-950/80 px-4 py-3">
                            <div class="text-xs uppercase tracking-wide text-zinc-500">Clusterable Jobs</div>
                            <div class="mt-2 text-2xl font-semibold text-white"><?= count($clusterableJobs) ?></div>
                        </div>
                        <div class="rounded-2xl border border-zinc-700 bg-zinc-950/80 px-4 py-3">
                            <div class="text-xs uppercase tracking-wide text-zinc-500">Clusters Built</div>
                            <div class="mt-2 text-2xl font-semibold text-white"><?= count($clusters) ?></div>
                        </div>
                        <div class="rounded-2xl border border-zinc-700 bg-zinc-950/80 px-4 py-3">
                            <div class="text-xs uppercase tracking-wide text-zinc-500">Skipped Jobs</div>
                            <div class="mt-2 text-2xl font-semibold text-white"><?= count($jobs) - count($clusterableJobs) ?></div>
                        </div>
                    </div>
                </div>

                <?php if (empty($clusterableJobs)): ?>
                    <div class="mt-6 rounded-2xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                        No pending jobs currently have valid latitude and longitude values, so no geographic clusters could be created.
                    </div>
                <?php else: ?>
                    <div class="mt-6 grid gap-4 xl:grid-cols-2">
                        <?php foreach ($clusters as $cluster): ?>
                            <section class="rounded-2xl border border-zinc-700 bg-zinc-950/70 p-5">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h3 class="text-lg font-semibold text-white"><?= htmlspecialchars($cluster['cluster_label']) ?></h3>
                                        <p class="text-sm text-zinc-400">
                                            <?= (int) $cluster['job_count'] ?> jobs &bull;
                                            <?= htmlspecialchars($cluster['highest_priority_label']) ?> priority lead &bull;
                                            <?= number_format($cluster['max_distance_miles'], 1) ?> miles max spread
                                        </p>
                                    </div>
                                    <div class="text-sm text-cyan-300">
                                        Center: <?= number_format($cluster['centroid_latitude'], 4) ?>, <?= number_format($cluster['centroid_longitude'], 4) ?>
                                    </div>
                                </div>

                                <div class="mt-4 space-y-3">
                                    <?php foreach ($cluster['jobs'] as $clusteredJob): ?>
                                        <div class="rounded-xl border border-zinc-800 bg-zinc-900/80 px-4 py-3">
                                            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                                <div>
                                                    <div class="font-medium text-white">
                                                        <?= htmlspecialchars($clusteredJob['first_name'] . ' ' . $clusteredJob['last_name']) ?>
                                                    </div>
                                                    <div class="mt-1 text-sm text-zinc-400">
                                                        <?= htmlspecialchars($clusteredJob['city'] ?? 'N/A') ?> &bull;
                                                        <?= htmlspecialchars($clusteredJob['problem_summary'] ?? 'No summary') ?>
                                                    </div>
                                                </div>
                                                <div class="text-sm text-right text-zinc-300">
                                                    <div><?= htmlspecialchars($clusteredJob['priority_meta']['label']) ?></div>
                                                    <div class="text-zinc-500"><?= htmlspecialchars($clusteredJob['priority_meta']['window_summary']) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="bg-zinc-900 border border-zinc-700 rounded-3xl overflow-hidden">
            <table class="w-full">
                <thead class="bg-zinc-800">
                    <tr>
                        <th class="p-6 text-left">Customer</th>
                        <th class="p-6 text-left">City</th>
                        <th class="p-6 text-left">Coordinates</th>
                        <th class="p-6 text-left">Priority</th>
                        <th class="p-6 text-left">Target Window</th>
                        <th class="p-6 text-left">Problem</th>
                        <th class="p-6 text-left">Dates</th>
                        <th class="p-6 text-left">Cluster</th>
                        <th class="p-6 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-700">
                    <?php if (empty($jobs)): ?>
                        <tr>
                            <td colspan="9" class="p-6 text-center text-zinc-400">No pending jobs found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($jobs as $job): ?>
                        <tr class="hover:bg-zinc-800">
                            <td class="p-6"><?= htmlspecialchars($job['first_name'] . ' ' . $job['last_name']) ?></td>
                            <td class="p-6"><?= htmlspecialchars($job['city'] ?? 'N/A') ?></td>
                            <td class="p-6 font-mono text-sm text-cyan-400">
                                <?= hasValidCoordinates($job['latitude'] ?? null, $job['longitude'] ?? null) ? number_format((float) $job['latitude'], 4) . ', ' . number_format((float) $job['longitude'], 4) : 'N/A' ?>
                            </td>
                            <td class="p-6">
                                <?php $priority = strtolower($job['priority_level'] ?? 'standard'); ?>
                                <span class="px-4 py-1 rounded-full text-xs font-semibold <?= $priority === 'emergency' ? 'bg-red-500/20 text-red-300' : ($priority === 'vip' ? 'bg-orange-500/20 text-orange-300' : 'bg-blue-500/20 text-blue-300') ?>">
                                    <?= htmlspecialchars($job['priority_meta']['label']) ?>
                                </span>
                            </td>
                            <td class="p-6 text-sm text-zinc-400"><?= htmlspecialchars($job['priority_meta']['window_summary']) ?></td>
                            <td class="p-6 text-sm text-zinc-400"><?= htmlspecialchars($job['problem_summary'] ?? 'No summary') ?></td>
                            <td class="p-6 text-sm text-zinc-400">
                                <?= htmlspecialchars($job['preferred_date_start'] ?? 'N/A') ?>
                                <?php if (!empty($job['preferred_date_end'])): ?>
                                    &ndash; <?= htmlspecialchars($job['preferred_date_end']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="p-6 text-sm text-cyan-300">
                                <?= htmlspecialchars($clusterLookup[(int) $job['id']] ?? 'Not clustered') ?>
                            </td>
                            <td class="p-6">
                                <form method="POST" onsubmit="return confirm('Delete this request?');">
                                    <input type="hidden" name="delete_id" value="<?= (int) $job['id'] ?>">
                                    <button type="submit" class="text-red-400 hover:text-red-500 text-sm font-medium">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
