<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

const CLUSTER_DISTANCE_MILES = 20;
const MAX_JOBS_PER_CLUSTER = 3;

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

/**
 * Sort higher-priority jobs first so clusters are anchored by the most urgent work.
 */
function compareJobsByPriority(array $leftJob, array $rightJob)
{
    $priorityComparison = $leftJob['priority_meta']['order'] <=> $rightJob['priority_meta']['order'];

    if ($priorityComparison !== 0) {
        return $priorityComparison;
    }

    return (int) $rightJob['id'] <=> (int) $leftJob['id'];
}

/**
 * Calculate the true max spread (furthest pair distance) for a group of jobs.
 */
function getMaxSpreadMiles(array $jobs)
{
    $jobCount = count($jobs);
    $maxSpreadMiles = 0;

    for ($leftIndex = 0; $leftIndex < $jobCount; $leftIndex++) {
        for ($rightIndex = $leftIndex + 1; $rightIndex < $jobCount; $rightIndex++) {
            $maxSpreadMiles = max(
                $maxSpreadMiles,
                haversineDistanceMiles(
                    $jobs[$leftIndex]['latitude'],
                    $jobs[$leftIndex]['longitude'],
                    $jobs[$rightIndex]['latitude'],
                    $jobs[$rightIndex]['longitude']
                )
            );
        }
    }

    return $maxSpreadMiles;
}

/**
 * Calculate the centroid for a group of jobs with valid coordinates.
 */
function getClusterCentroid(array $jobs): array
{
    $latitudeTotal = 0.0;
    $longitudeTotal = 0.0;
    $coordinateCount = 0;

    foreach ($jobs as $job) {
        if (!hasValidCoordinates($job['latitude'] ?? null, $job['longitude'] ?? null)) {
            continue;
        }

        $latitudeTotal += (float) $job['latitude'];
        $longitudeTotal += (float) $job['longitude'];
        $coordinateCount++;
    }

    if ($coordinateCount === 0) {
        return [
            'latitude' => null,
            'longitude' => null,
        ];
    }

    return [
        'latitude' => $latitudeTotal / $coordinateCount,
        'longitude' => $longitudeTotal / $coordinateCount,
    ];
}

/**
 * Return the name of the closest major Southern California city to the given coordinates.
 */
function getClosestCityName(float $latitude, float $longitude): string
{
    $cities = [
        'Los Angeles'    => [34.0522, -118.2437],
        'Long Beach'     => [33.7701, -118.1937],
        'Anaheim'        => [33.8353, -117.9145],
        'Santa Ana'      => [33.7455, -117.8677],
        'Irvine'         => [33.6846, -117.8265],
        'Riverside'      => [33.9806, -117.3755],
        'San Bernardino' => [34.1083, -117.2898],
        'Ontario'        => [34.0633, -117.6509],
        'Fontana'        => [34.0922, -117.4350],
        'Moreno Valley'  => [33.9375, -117.2306],
        'San Diego'      => [32.7157, -117.1611],
        'Chula Vista'    => [32.6401, -117.0842],
        'Escondido'      => [33.1192, -117.0864],
        'El Monte'       => [34.0686, -118.0276],
        'Pasadena'       => [34.1478, -118.1445],
        'Torrance'       => [33.8358, -118.3406],
        'Pomona'         => [34.0551, -117.7500],
        'Orange'         => [33.7879, -117.8531],
        'Fullerton'      => [33.8704, -117.9242],
        'Garden Grove'   => [33.7743, -117.9378],
    ];

    $closestCity = 'Unknown';
    $closestDistance = PHP_FLOAT_MAX;

    foreach ($cities as $name => $coords) {
        $distance = haversineDistanceMiles($latitude, $longitude, $coords[0], $coords[1]);

        if ($distance < $closestDistance) {
            $closestDistance = $distance;
            $closestCity = $name;
        }
    }

    return $closestCity;
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
 * Build a one-line service address for scheduled job detail views.
 */
function formatCustomerAddress(array $customer): string
{
    $lineOne = trim((string) ($customer['address'] ?? ''));
    $lineTwoParts = array_values(array_filter([
        trim((string) ($customer['city'] ?? '')),
        trim((string) ($customer['state'] ?? '')),
        trim((string) ($customer['zip'] ?? '')),
    ], static fn($value) => $value !== ''));

    $lineTwo = '';
    if ($lineTwoParts !== []) {
        $lineTwo = implode(', ', array_slice($lineTwoParts, 0, 2));
        if (isset($lineTwoParts[2])) {
            $lineTwo .= ' ' . $lineTwoParts[2];
        }
    }

    $parts = array_filter([
        $lineOne,
        $lineTwo,
    ], static fn($value) => $value !== '');

    return $parts === [] ? 'Address unavailable' : implode(' • ', $parts);
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
    usort($jobs, 'compareJobsByPriority');

    $clusters = [];

    foreach ($jobs as $job) {
        $assignedClusterIndex = null;

        foreach ($clusters as $clusterIndex => $cluster) {
            $anchorDistance = haversineDistanceMiles(
                $job['latitude'],
                $job['longitude'],
                $cluster['anchor_latitude'],
                $cluster['anchor_longitude']
            );

            $withinRange = $anchorDistance <= CLUSTER_DISTANCE_MILES;
            $hasCapacity = $cluster['job_count'] < MAX_JOBS_PER_CLUSTER;

            if ($withinRange && $hasCapacity) {
                $assignedClusterIndex = $clusterIndex;
                break;
            }
        }

        if ($assignedClusterIndex === null) {
            $clusters[] = [
                'cluster_label' => 'Cluster ' . str_pad((string) (count($clusters) + 1), 2, '0', STR_PAD_LEFT),
                'anchor_latitude' => (float) $job['latitude'],
                'anchor_longitude' => (float) $job['longitude'],
                'centroid_latitude' => (float) $job['latitude'],
                'centroid_longitude' => (float) $job['longitude'],
                'jobs' => [$job],
                'job_count' => 1,
                'highest_priority_order' => $job['priority_meta']['order'],
                'highest_priority_label' => $job['priority_meta']['label'],
            ];

            continue;
        }

        $cluster = $clusters[$assignedClusterIndex];
        $newJobCount = $cluster['job_count'] + 1;

        $cluster['centroid_latitude'] = (($cluster['centroid_latitude'] * $cluster['job_count']) + (float) $job['latitude']) / $newJobCount;
        $cluster['centroid_longitude'] = (($cluster['centroid_longitude'] * $cluster['job_count']) + (float) $job['longitude']) / $newJobCount;
        $cluster['jobs'][] = $job;
        $cluster['job_count'] = $newJobCount;

        if ($job['priority_meta']['order'] < $cluster['highest_priority_order']) {
            $cluster['highest_priority_order'] = $job['priority_meta']['order'];
            $cluster['highest_priority_label'] = $job['priority_meta']['label'];
        }

        $clusters[$assignedClusterIndex] = $cluster;
    }

    foreach ($clusters as $clusterIndex => $cluster) {
        usort($cluster['jobs'], 'compareJobsByPriority');
        $centroid = getClusterCentroid($cluster['jobs']);
        if ($centroid['latitude'] !== null && $centroid['longitude'] !== null) {
            $cluster['centroid_latitude'] = $centroid['latitude'];
            $cluster['centroid_longitude'] = $centroid['longitude'];
        }

        foreach ($cluster['jobs'] as $jobIndex => $clusterJob) {
            $cluster['jobs'][$jobIndex]['distance_from_center_miles'] = haversineDistanceMiles(
                $clusterJob['latitude'],
                $clusterJob['longitude'],
                $cluster['centroid_latitude'],
                $cluster['centroid_longitude']
            );
        }

        $cluster['max_distance_miles'] = getMaxSpreadMiles($cluster['jobs']);
        $clusters[$clusterIndex] = $cluster;
    }

    usort($clusters, function ($leftCluster, $rightCluster) {
        $priorityComparison = $leftCluster['highest_priority_order'] <=> $rightCluster['highest_priority_order'];

        if ($priorityComparison !== 0) {
            return $priorityComparison;
        }

        return $rightCluster['job_count'] <=> $leftCluster['job_count'];
    });

    error_log("DEBUG: First cluster distance = " . ($clusters[0][0] ?? 'NOT SET'));
    return $clusters;
}

function ensureClusterSchedulingTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scheduled_clusters (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cluster_label VARCHAR(100) NOT NULL,
            scheduled_date DATE NOT NULL,
            centroid_latitude DECIMAL(10,6) NULL,
            centroid_longitude DECIMAL(10,6) NULL,
            created_by_admin_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scheduled_cluster_jobs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scheduled_cluster_id INT UNSIGNED NOT NULL,
            service_request_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_scheduled_cluster_job (scheduled_cluster_id, service_request_id),
            KEY idx_scheduled_cluster_jobs_service_request (service_request_id)
        )
    ");
}

session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: ../admin-login.php');
    exit;
}

require_once '../project/db.php';
ensureClusterSchedulingTables($pdo);

$clusteringRequested = false;
$testDataMessage = null;
$testDataError = null;
$clusterAssignMessage = null;
$clusterAssignError = null;
$currentMonth = new DateTimeImmutable('first day of this month');
$currentMonthEnd = $currentMonth->modify('last day of this month');

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

    $unassignScheduledJobId = filter_input(
        INPUT_POST,
        'unassign_scheduled_job_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $unassignScheduledClusterId = filter_input(
        INPUT_POST,
        'unassign_scheduled_cluster_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($unassignScheduledClusterId) {
        try {
            $scheduledClusterLookupStmt = $pdo->prepare("
                SELECT
                    sc.id,
                    sc.scheduled_date,
                    sc.centroid_latitude,
                    sc.centroid_longitude,
                    COUNT(scj.id) AS job_count
                FROM scheduled_clusters sc
                LEFT JOIN scheduled_cluster_jobs scj ON scj.scheduled_cluster_id = sc.id
                WHERE sc.id = :id
                GROUP BY
                    sc.id,
                    sc.scheduled_date,
                    sc.centroid_latitude,
                    sc.centroid_longitude
                LIMIT 1
            ");
            $scheduledClusterLookupStmt->execute([':id' => $unassignScheduledClusterId]);
            $scheduledCluster = $scheduledClusterLookupStmt->fetch(PDO::FETCH_ASSOC);

            if ($scheduledCluster === false) {
                $clusterAssignError = 'That scheduled cluster could not be found.';
            } else {
                $deleteClusterJobsStmt = $pdo->prepare("
                    DELETE FROM scheduled_cluster_jobs
                    WHERE scheduled_cluster_id = :scheduled_cluster_id
                ");
                $deleteClusterStmt = $pdo->prepare("
                    DELETE FROM scheduled_clusters
                    WHERE id = :id
                ");

                $pdo->beginTransaction();
                $deleteClusterJobsStmt->execute([':scheduled_cluster_id' => $unassignScheduledClusterId]);
                $deleteClusterStmt->execute([':id' => $unassignScheduledClusterId]);
                $pdo->commit();

                $centerCity = 'Unknown';
                if (hasValidCoordinates($scheduledCluster['centroid_latitude'] ?? null, $scheduledCluster['centroid_longitude'] ?? null)) {
                    $centerCity = getClosestCityName(
                        (float) $scheduledCluster['centroid_latitude'],
                        (float) $scheduledCluster['centroid_longitude']
                    );
                }

                $clusterAssignMessage = sprintf(
                    '%s cluster on %s returned to the clustering pool (%d job%s).',
                    $centerCity,
                    (new DateTimeImmutable((string) $scheduledCluster['scheduled_date']))->format('M j, Y'),
                    (int) $scheduledCluster['job_count'],
                    (int) $scheduledCluster['job_count'] === 1 ? '' : 's'
                );
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $clusterAssignError = 'Unable to unassign that cluster right now.';
        }
    }

    if ($unassignScheduledJobId && !$unassignScheduledClusterId) {
        try {
            $scheduledJobLookupStmt = $pdo->prepare("
                SELECT
                    scj.id,
                    scj.scheduled_cluster_id,
                    c.first_name,
                    c.last_name
                FROM scheduled_cluster_jobs scj
                JOIN service_requests sr ON sr.id = scj.service_request_id
                JOIN customers c ON c.id = sr.customer_id
                WHERE scj.id = :id
                LIMIT 1
            ");
            $scheduledJobLookupStmt->execute([':id' => $unassignScheduledJobId]);
            $scheduledJob = $scheduledJobLookupStmt->fetch(PDO::FETCH_ASSOC);

            if ($scheduledJob === false) {
                $clusterAssignError = 'That scheduled job could not be found.';
            } else {
                $deleteScheduledJobStmt = $pdo->prepare("
                    DELETE FROM scheduled_cluster_jobs
                    WHERE id = :id
                ");
                $deleteEmptyClusterStmt = $pdo->prepare("
                    DELETE FROM scheduled_clusters
                    WHERE id = ?
                      AND NOT EXISTS (
                          SELECT 1
                          FROM scheduled_cluster_jobs
                          WHERE scheduled_cluster_id = ?
                      )
                ");

                $pdo->beginTransaction();
                $deleteScheduledJobStmt->execute([':id' => $unassignScheduledJobId]);
                $deleteEmptyClusterStmt->execute([
                    (int) $scheduledJob['scheduled_cluster_id'],
                    (int) $scheduledJob['scheduled_cluster_id'],
                ]);
                $pdo->commit();

                $clusterAssignMessage = sprintf(
                    '%s returned to the clustering pool.',
                    trim((string) $scheduledJob['first_name'] . ' ' . (string) $scheduledJob['last_name'])
                );
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $clusterAssignError = 'Unable to unassign that job right now.';
        }
    }

    if (isset($_POST['insert_test_data']) && !$unassignScheduledJobId && !$unassignScheduledClusterId) {
        $ts = time(); // unique timestamp per button press

        $testCustomers = [
            ['first_name' => 'Anna',  'last_name' => 'Rivera',   'slug' => 'anna',  'priority' => 'emergency', 'address' => '1234 Harbor Blvd',       'city' => 'Anaheim',      'zip' => '92801', 'lat' => 33.8366,  'lng' => -117.9143, 'problem' => 'Laser tube not firing — no beam output on any power setting.'],
            ['first_name' => 'Mike',  'last_name' => 'Chen',     'slug' => 'mike',  'priority' => 'standard',  'address' => '4500 Magnolia Ave',       'city' => 'Riverside',    'zip' => '92506', 'lat' => 33.9533,  'lng' => -117.3961, 'problem' => 'X-axis stepper motor making grinding noise and skipping steps.'],
            ['first_name' => 'Sara',  'last_name' => 'Williams', 'slug' => 'sara',  'priority' => 'vip',       'address' => '850 Pine Ave',            'city' => 'Long Beach',   'zip' => '90813', 'lat' => 33.7844,  'lng' => -118.1894, 'problem' => 'Control panel touchscreen unresponsive, machine will not power up.'],
            ['first_name' => 'Tom',   'last_name' => 'Kim',      'slug' => 'tom',   'priority' => 'standard',  'address' => '2200 Michelson Dr',       'city' => 'Irvine',       'zip' => '92612', 'lat' => 33.6846,  'lng' => -117.8265, 'problem' => 'Laser head crashing into bed; limit switch failure suspected.'],
            ['first_name' => 'Emma',  'last_name' => 'Davis',    'slug' => 'emma',  'priority' => 'emergency', 'address' => '700 W Commonwealth Ave',  'city' => 'Fullerton',    'zip' => '92832', 'lat' => 33.8703,  'lng' => -117.9253, 'problem' => 'Inconsistent cutting depth — beam appears severely misaligned.'],
            ['first_name' => 'Jake',  'last_name' => 'Torres',   'slug' => 'jake',  'priority' => 'vip',       'address' => '12200 Euclid St',         'city' => 'Garden Grove', 'zip' => '92840', 'lat' => 33.7740,  'lng' => -117.9412, 'problem' => 'Water chiller alarm triggering during operation; machine overheating.'],
            ['first_name' => 'Lucy',  'last_name' => 'Johnson',  'slug' => 'lucy',  'priority' => 'standard',  'address' => '3900 Convoy St',          'city' => 'San Diego',    'zip' => '92111', 'lat' => 32.8057,  'lng' => -117.1495, 'problem' => 'Y-axis belt slipping; prints skewing at high speeds.'],
            ['first_name' => 'Dan',   'last_name' => 'Martinez', 'slug' => 'dan',   'priority' => 'standard',  'address' => '1100 S Flower St',        'city' => 'Los Angeles',  'zip' => '90015', 'lat' => 34.0411,  'lng' => -118.2688, 'problem' => 'Exhaust fan failure; smoke backing up into the enclosure.'],
        ];

        $insertCust = $pdo->prepare("
            INSERT INTO customers
                (first_name, last_name, email, phone, address, city, state, zip, hubspot_contact_id)
            VALUES
                (:first_name, :last_name, :email, :phone, :address, :city, :state, :zip, :hubspot_contact_id)
        ");
        $insertReq = $pdo->prepare("
            INSERT INTO service_requests
                (customer_id, priority_level, problem_summary, preferred_date_start, preferred_date_end, request_status, latitude, longitude)
            VALUES
                (:customer_id, :priority_level, :problem_summary, :preferred_date_start, :preferred_date_end, 'new', :latitude, :longitude)
        ");

        $today    = new DateTimeImmutable('today');
        $inserted = 0;

        foreach ($testCustomers as $cust) {
            $email = "test.{$cust['slug']}.{$ts}@ghostlaser.test";
            $phone = "555-{$ts}-" . strtoupper($cust['slug']);

            $insertCust->execute([
                ':first_name'          => $cust['first_name'],
                ':last_name'           => $cust['last_name'],
                ':email'               => $email,
                ':phone'               => $phone,
                ':address'             => $cust['address'],
                ':city'                => $cust['city'],
                ':state'               => 'CA',
                ':zip'                 => $cust['zip'],
                ':hubspot_contact_id'  => "test-{$ts}-{$cust['slug']}",
            ]);
            $customerId = (int) $pdo->lastInsertId();

            switch ($cust['priority']) {
                case 'emergency':
                    $startDate = $today->format('Y-m-d');
                    $endDate   = $today->modify('+1 day')->format('Y-m-d');
                    break;
                case 'vip':
                    $startDate = $today->format('Y-m-d');
                    $endDate   = addBusinessDays($today, 2)->format('Y-m-d');
                    break;
                default:
                    $startDate = addBusinessDays($today, 3)->format('Y-m-d');
                    $endDate   = addBusinessDays($today, 5)->format('Y-m-d');
                    break;
            }

            $insertReq->execute([
                ':customer_id'          => $customerId,
                ':priority_level'       => $cust['priority'],
                ':problem_summary'      => $cust['problem'],
                ':preferred_date_start' => $startDate,
                ':preferred_date_end'   => $endDate,
                ':latitude'             => $cust['lat'],
                ':longitude'            => $cust['lng'],
            ]);

            $inserted++;
        }

        $testDataMessage = "Inserted {$inserted} new customer(s) and service request(s).";
    } elseif (isset($_POST['assign_cluster']) && !$unassignScheduledJobId && !$unassignScheduledClusterId) {
        $clusteringRequested = true;
        $clusterLabel = trim((string) ($_POST['cluster_label'] ?? ''));
        $clusterDate = trim((string) ($_POST['cluster_date'] ?? ''));
        $clusterJobIdsRaw = trim((string) ($_POST['cluster_job_ids'] ?? ''));
        $centroidLatitude = is_numeric($_POST['cluster_centroid_latitude'] ?? null) ? (float) $_POST['cluster_centroid_latitude'] : null;
        $centroidLongitude = is_numeric($_POST['cluster_centroid_longitude'] ?? null) ? (float) $_POST['cluster_centroid_longitude'] : null;

        $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $clusterDate);
        $isValidDate = $parsedDate !== false && $parsedDate->format('Y-m-d') === $clusterDate;

        $clusterJobIds = array_values(array_unique(array_filter(
            array_map('intval', array_map('trim', explode(',', $clusterJobIdsRaw))),
            static fn($id) => $id > 0
        )));

        if ($clusterLabel === '' || !$isValidDate || $clusterJobIds === []) {
            $clusterAssignError = 'Select a valid date and cluster before assigning.';
        } else {
            try {
                ensureClusterSchedulingTables($pdo);

                $placeholders = implode(',', array_fill(0, count($clusterJobIds), '?'));
                $validJobsStmt = $pdo->prepare("
                    SELECT id
                    FROM service_requests
                    WHERE id IN ({$placeholders})
                      AND request_status IN ('new', 'queued')
                ");
                $validJobsStmt->execute($clusterJobIds);
                $validJobIds = array_map('intval', $validJobsStmt->fetchAll(PDO::FETCH_COLUMN));

                if ($validJobIds === []) {
                    $clusterAssignError = 'No assignable jobs were found for this cluster.';
                } else {
                    $insertClusterStmt = $pdo->prepare("
                        INSERT INTO scheduled_clusters
                            (cluster_label, scheduled_date, centroid_latitude, centroid_longitude, created_by_admin_id)
                        VALUES
                            (:cluster_label, :scheduled_date, :centroid_latitude, :centroid_longitude, :created_by_admin_id)
                    ");
                    $insertClusterJobStmt = $pdo->prepare("
                        INSERT INTO scheduled_cluster_jobs
                            (scheduled_cluster_id, service_request_id)
                        VALUES
                            (:scheduled_cluster_id, :service_request_id)
                    ");

                    $pdo->beginTransaction();
                    $insertClusterStmt->execute([
                        ':cluster_label' => $clusterLabel,
                        ':scheduled_date' => $clusterDate,
                        ':centroid_latitude' => $centroidLatitude,
                        ':centroid_longitude' => $centroidLongitude,
                        ':created_by_admin_id' => $_SESSION['admin_id'] ?? null,
                    ]);

                    $scheduledClusterId = (int) $pdo->lastInsertId();
                    foreach ($validJobIds as $serviceRequestId) {
                        $insertClusterJobStmt->execute([
                            ':scheduled_cluster_id' => $scheduledClusterId,
                            ':service_request_id' => $serviceRequestId,
                        ]);
                    }

                    $pdo->commit();
                    $clusterAssignMessage = sprintf(
                        '%s assigned to %s with %d job(s).',
                        $clusterLabel,
                        $parsedDate->format('M j, Y'),
                        count($validJobIds)
                    );
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $clusterAssignError = 'Unable to assign this cluster right now.';
            }
        }
    } elseif (!$unassignScheduledJobId && !$unassignScheduledClusterId) {
        $clusteringRequested = isset($_POST['run_clustering']);
    }

    if (!$clusteringRequested && !$unassignScheduledJobId && !$unassignScheduledClusterId) {
        $clusteringRequested = isset($_POST['run_clustering']);
    }
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
    AND NOT EXISTS (
        SELECT 1 FROM scheduled_cluster_jobs scj WHERE scj.service_request_id = sr.id
    )
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

$scheduledJobsStmt = $pdo->prepare("
    SELECT
        sc.id AS scheduled_cluster_id,
        sc.cluster_label,
        sc.scheduled_date,
        sc.centroid_latitude,
        sc.centroid_longitude,
        scj.id AS scheduled_cluster_job_id,
        sr.id AS service_request_id,
        sr.priority_level,
        sr.problem_summary,
        sr.preferred_date_start,
        sr.preferred_date_end,
        c.first_name,
        c.last_name,
        c.email,
        c.phone,
        c.address,
        c.city,
        c.state,
        c.zip
    FROM scheduled_clusters sc
    JOIN scheduled_cluster_jobs scj ON scj.scheduled_cluster_id = sc.id
    JOIN service_requests sr ON sr.id = scj.service_request_id
    JOIN customers c ON c.id = sr.customer_id
    WHERE sc.scheduled_date BETWEEN :month_start AND :month_end
    ORDER BY
        sc.scheduled_date ASC,
        FIELD(LOWER(sr.priority_level), 'emergency', 'vip', 'standard'),
        sc.cluster_label ASC,
        c.last_name ASC,
        c.first_name ASC
");
$scheduledJobsStmt->execute([
    ':month_start' => $currentMonth->format('Y-m-d'),
    ':month_end' => $currentMonthEnd->format('Y-m-d'),
]);
$scheduledJobs = $scheduledJobsStmt->fetchAll(PDO::FETCH_ASSOC);
$scheduledJobsByDate = [];
$scheduledClustersByDate = [];

foreach ($scheduledJobs as $scheduledJob) {
    $dateKey = (string) $scheduledJob['scheduled_date'];
    $scheduledClusterId = (int) $scheduledJob['scheduled_cluster_id'];
    $customerName = trim((string) $scheduledJob['first_name'] . ' ' . (string) $scheduledJob['last_name']);
    $scheduledJob['customer_name'] = $customerName !== '' ? $customerName : 'Unknown Customer';
    $scheduledJob['priority_meta'] = getPriorityScheduleWindow($scheduledJob['priority_level'] ?? 'standard');
    $scheduledJob['service_address'] = formatCustomerAddress($scheduledJob);
    $scheduledJobsByDate[$dateKey][] = $scheduledJob;

    if (!isset($scheduledClustersByDate[$dateKey][$scheduledClusterId])) {
        $centerCity = 'Unknown';
        if (hasValidCoordinates($scheduledJob['centroid_latitude'] ?? null, $scheduledJob['centroid_longitude'] ?? null)) {
            $centerCity = getClosestCityName(
                (float) $scheduledJob['centroid_latitude'],
                (float) $scheduledJob['centroid_longitude']
            );
        }

        $scheduledClustersByDate[$dateKey][$scheduledClusterId] = [
            'scheduled_cluster_id' => $scheduledClusterId,
            'cluster_label' => $scheduledJob['cluster_label'],
            'scheduled_date' => $scheduledJob['scheduled_date'],
            'center_city' => $centerCity,
            'jobs' => [],
        ];
    }

    $scheduledClustersByDate[$dateKey][$scheduledClusterId]['jobs'][] = $scheduledJob;
}

foreach ($scheduledClustersByDate as $dateKey => $clustersOnDate) {
    $scheduledClustersByDate[$dateKey] = array_values($clustersOnDate);
}

$calendarStart = $currentMonth->modify('-' . $currentMonth->format('w') . ' days');
$calendarEnd = $currentMonthEnd->modify('+' . (6 - (int) $currentMonthEnd->format('w')) . ' days');
$calendarDays = [];
$calendarCursor = $calendarStart;

while ($calendarCursor <= $calendarEnd) {
    $calendarDateKey = $calendarCursor->format('Y-m-d');
    $calendarDays[] = [
        'date' => $calendarCursor,
        'date_key' => $calendarDateKey,
        'is_current_month' => $calendarCursor->format('Y-m') === $currentMonth->format('Y-m'),
        'is_today' => $calendarDateKey === (new DateTimeImmutable('today'))->format('Y-m-d'),
        'clusters' => $scheduledClustersByDate[$calendarDateKey] ?? [],
    ];
    $calendarCursor = $calendarCursor->modify('+1 day');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduling Dashboard | Ghost Laser</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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

            <div class="flex flex-wrap gap-3">
                <form method="POST">
                    <button
                        type="submit"
                        name="insert_test_data"
                        value="1"
                        class="inline-flex items-center justify-center rounded-lg bg-amber-500 px-5 py-3 text-sm font-semibold text-zinc-950 transition hover:bg-amber-400"
                        onclick="return confirm('Insert 8 test customers and service requests?');"
                    >
                        Insert Test Data
                    </button>
                </form>

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
        </div>

        <?php if ($testDataMessage !== null): ?>
            <div class="mb-6 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                <?= htmlspecialchars($testDataMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($testDataError !== null): ?>
            <div class="mb-6 rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                <?= $testDataError ?>
            </div>
        <?php endif; ?>

        <?php if ($clusterAssignMessage !== null): ?>
            <div class="mb-6 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                <?= htmlspecialchars($clusterAssignMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($clusterAssignError !== null): ?>
            <div class="mb-6 rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                <?= htmlspecialchars($clusterAssignError) ?>
            </div>
        <?php endif; ?>

        <section class="mb-8 rounded-3xl border border-zinc-700 bg-zinc-900/80 p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h2 class="text-2xl font-semibold text-white"><?= htmlspecialchars($currentMonth->format('F Y')) ?></h2>
                    <p class="mt-2 text-sm text-zinc-400">
                        Monthly technician calendar for assigned jobs. Click a customer to view full details, or use the X on a cluster card to return that full cluster to the pooling queue.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl border border-zinc-700 bg-zinc-950/80 px-4 py-3">
                        <div class="text-xs uppercase tracking-wide text-zinc-500">Scheduled Jobs</div>
                        <div class="mt-2 text-2xl font-semibold text-white"><?= count($scheduledJobs) ?></div>
                    </div>
                    <div class="rounded-2xl border border-zinc-700 bg-zinc-950/80 px-4 py-3">
                        <div class="text-xs uppercase tracking-wide text-zinc-500">Active Days</div>
                        <div class="mt-2 text-2xl font-semibold text-white"><?= count(array_filter($scheduledJobsByDate)) ?></div>
                    </div>
                </div>
            </div>

            <div class="mt-6 overflow-x-auto pb-2">
                <div class="min-w-[980px]">
                    <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-semibold uppercase tracking-widest text-zinc-500">
                        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
                            <div><?= htmlspecialchars($weekday) ?></div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-2 grid grid-cols-7 gap-1">
                        <?php foreach ($calendarDays as $calendarDay): ?>
                            <?php
                            $dayHasAssignments = $calendarDay['clusters'] !== [];
                            $totalDayAssignments = array_sum(array_map(static fn($cluster) => count($cluster['jobs']), $calendarDay['clusters']));
                            ?>
                            <div class="rounded-lg border px-2 py-1 <?= $calendarDay['is_current_month'] ? 'border-zinc-700 bg-zinc-950/80' : 'border-zinc-800 bg-zinc-950/40 text-zinc-600' ?> <?= $dayHasAssignments ? 'pb-2' : '' ?>">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-semibold <?= $calendarDay['is_today'] ? 'rounded-full bg-cyan-500 px-1.5 py-0.5 text-zinc-950' : ($calendarDay['is_current_month'] ? 'text-white' : 'text-zinc-600') ?>">
                                        <?= htmlspecialchars($calendarDay['date']->format('j')) ?>
                                    </span>
                                    <?php if ($dayHasAssignments): ?>
                                        <span class="rounded-full bg-cyan-500/15 px-1.5 py-0.5 text-[10px] font-medium text-cyan-300">
                                            <?= $totalDayAssignments ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($dayHasAssignments): ?>
                                    <div class="mt-2 space-y-2">
                                        <?php foreach ($calendarDay['clusters'] as $scheduledCluster): ?>
                                            <div class="rounded-lg border border-cyan-400/40 bg-cyan-500/10 px-2 py-1.5 shadow-[inset_0_0_0_1px_rgba(6,182,212,0.12)]">
                                                <div class="flex items-start justify-between gap-2">
                                                    <div class="min-w-0">
                                                        <div class="truncate text-[10px] font-semibold uppercase tracking-wide text-cyan-200"><?= htmlspecialchars($scheduledCluster['center_city']) ?></div>
                                                    </div>
                                                    <form method="POST" onsubmit="return confirm('Unassign this entire cluster? This returns all jobs in the cluster to the clustering pool.');">
                                                        <input type="hidden" name="unassign_scheduled_cluster_id" value="<?= (int) $scheduledCluster['scheduled_cluster_id'] ?>">
                                                        <button
                                                            type="submit"
                                                            class="inline-flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full text-xs text-cyan-100/80 transition hover:bg-cyan-900/50 hover:text-red-200"
                                                            title="Unassign cluster"
                                                            aria-label="Unassign cluster centered in <?= htmlspecialchars($scheduledCluster['center_city']) ?>"
                                                        >&times;</button>
                                                    </form>
                                                </div>
                                                <div class="mt-1 space-y-0.5">
                                                    <?php foreach ($scheduledCluster['jobs'] as $scheduledJob): ?>
                                                        <?php
                                                        $modalPayload = [
                                                            'customer_name' => $scheduledJob['customer_name'],
                                                            'service_address' => $scheduledJob['service_address'],
                                                            'city' => $scheduledJob['city'] ?? 'N/A',
                                                            'email' => $scheduledJob['email'] ?? 'N/A',
                                                            'phone' => $scheduledJob['phone'] ?? 'N/A',
                                                            'priority' => $scheduledJob['priority_meta']['label'],
                                                            'window_summary' => $scheduledJob['priority_meta']['window_summary'],
                                                            'problem_summary' => $scheduledJob['problem_summary'] ?? 'No summary provided',
                                                            'scheduled_date' => $scheduledJob['scheduled_date'],
                                                            'cluster_label' => $scheduledJob['cluster_label'],
                                                        ];
                                                        ?>
                                                        <button
                                                            type="button"
                                                            class="scheduled-job-trigger block w-full truncate text-left text-[11px] text-zinc-100 transition hover:text-cyan-100"
                                                            data-job-details="<?= htmlspecialchars(json_encode($modalPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
                                                        >
                                                            <?= htmlspecialchars($scheduledJob['customer_name']) ?>
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

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
                                        Center: <?= htmlspecialchars(getClosestCityName($cluster['centroid_latitude'], $cluster['centroid_longitude'])) ?> (<?= number_format($cluster['centroid_latitude'], 4) ?>, <?= number_format($cluster['centroid_longitude'], 4) ?>)
                                    </div>
                                </div>

                                <form method="POST" class="mt-4 flex flex-col gap-3 rounded-xl border border-zinc-800 bg-zinc-900/70 p-3 sm:flex-row sm:items-end">
                                    <input type="hidden" name="assign_cluster" value="1">
                                    <input type="hidden" name="run_clustering" value="1">
                                    <input type="hidden" name="cluster_label" value="<?= htmlspecialchars($cluster['cluster_label'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="cluster_centroid_latitude" value="<?= htmlspecialchars((string) $cluster['centroid_latitude'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="cluster_centroid_longitude" value="<?= htmlspecialchars((string) $cluster['centroid_longitude'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input
                                        type="hidden"
                                        name="cluster_job_ids"
                                        value="<?= htmlspecialchars(implode(',', array_map(static fn($job) => (string) ((int) $job['id']), $cluster['jobs'])), ENT_QUOTES, 'UTF-8') ?>"
                                    >

                                    <label class="flex-1 text-sm text-zinc-300">
                                       Schedule for date:
                                        <input
                                           type="text"
                                            name="cluster_date"
                                           required
                                           placeholder="Pick a date…"
                                           autocomplete="off"
                                           class="cluster-date-picker mt-1 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-white focus:border-cyan-400 focus:outline-none"
                                        >
                                    </label>

                                    <button
                                        type="submit"
                                        class="inline-flex items-center justify-center rounded-lg bg-cyan-500 px-5 py-2.5 text-sm font-semibold text-zinc-950 transition hover:bg-cyan-400"
                                    >
                                        Assign to Date
                                    </button>
                                </form>

                                <div class="mt-4 space-y-3">
                                    <?php foreach ($cluster['jobs'] as $clusteredJob): ?>
                                        <div class="rounded-xl border border-zinc-800 bg-zinc-900/80 px-4 py-3 cluster-job-card" data-job-id="<?= (int) $clusteredJob['id'] ?>">
                                            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                                <div>
                                                    <div class="font-medium text-white">
                                                        <?= htmlspecialchars($clusteredJob['first_name'] . ' ' . $clusteredJob['last_name']) ?>
                                                        <span class="ml-2 text-sm font-normal text-zinc-400">&bull; <?= number_format((float) ($clusteredJob['distance_from_center_miles'] ?? 0), 1) ?> miles from center</span>
                                                    </div>
                                                    <div class="mt-1 text-sm text-zinc-400">
                                                        <?= htmlspecialchars($clusteredJob['city'] ?? 'N/A') ?> &bull;
                                                        <?= htmlspecialchars($clusteredJob['problem_summary'] ?? 'No summary') ?>
                                                    </div>
                                                </div>
                                                <div class="flex items-start gap-3">
                                                    <div class="text-sm text-right text-zinc-300">
                                                        <div><?= htmlspecialchars($clusteredJob['priority_meta']['label']) ?></div>
                                                        <div class="text-zinc-500"><?= htmlspecialchars($clusteredJob['priority_meta']['window_summary']) ?></div>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        class="remove-job-btn mt-0.5 flex-shrink-0 rounded-md px-1.5 py-0.5 text-sm text-zinc-500 hover:bg-zinc-700 hover:text-white transition"
                                                        title="Remove from cluster"
                                                    >&times;</button>
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
    <div id="scheduled-job-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-zinc-950/80 px-4">
    <div class="absolute inset-0 modal-overlay"></div>
    <div class="relative z-10 w-full max-w-2xl rounded-3xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl shadow-black/40">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-sm uppercase tracking-[0.2em] text-cyan-300">Scheduled Job</p>
                <h3 id="modal-customer-name" class="mt-2 text-2xl font-semibold text-white">Customer</h3>
            </div>
            <button type="button" id="modal-close-button" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-zinc-400 transition hover:bg-zinc-800 hover:text-white" aria-label="Close modal">&times;</button>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <div class="rounded-2xl border border-zinc-800 bg-zinc-950/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Service address</div>
                <div id="modal-address" class="mt-2 text-sm leading-6 text-zinc-200">Address unavailable</div>
            </div>
            <div class="rounded-2xl border border-zinc-800 bg-zinc-950/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Contact</div>
                <div class="mt-2 space-y-2 text-sm text-zinc-200">
                    <div><span class="text-zinc-500">Phone:</span> <span id="modal-phone">N/A</span></div>
                    <div><span class="text-zinc-500">Email:</span> <span id="modal-email">N/A</span></div>
                    <div><span class="text-zinc-500">City:</span> <span id="modal-city">N/A</span></div>
                </div>
            </div>
            <div class="rounded-2xl border border-zinc-800 bg-zinc-950/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Scheduling</div>
                <div class="mt-2 space-y-2 text-sm text-zinc-200">
                    <div><span class="text-zinc-500">Cluster:</span> <span id="modal-cluster-label">N/A</span></div>
                    <div><span class="text-zinc-500">Scheduled date:</span> <span id="modal-scheduled-date">N/A</span></div>
                    <div><span class="text-zinc-500">Priority:</span> <span id="modal-priority">N/A</span></div>
                    <div><span class="text-zinc-500">Target window:</span> <span id="modal-window-summary">N/A</span></div>
                </div>
            </div>
            <div class="rounded-2xl border border-zinc-800 bg-zinc-950/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Problem summary</div>
                <div id="modal-problem-summary" class="mt-2 text-sm leading-6 text-zinc-200">No summary provided</div>
            </div>
        </div>
    </div>
    </div>
<script>
    flatpickr('.cluster-date-picker', {
        minDate: 'today',
        dateFormat: 'Y-m-d',
        disableMobile: false
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.remove-job-btn');
        if (!btn) return;

        const card = btn.closest('.cluster-job-card');
        const jobId = card ? card.dataset.jobId : null;
        if (!jobId) return;

        const form = card.closest('section').querySelector('form');
        const idsInput = form ? form.querySelector('[name="cluster_job_ids"]') : null;
        if (idsInput) {
            idsInput.value = idsInput.value
                .split(',')
                .map(s => s.trim())
                .filter(s => s !== jobId)
                .join(',');
        }

        card.remove();
    });

    const scheduledJobModal = document.getElementById('scheduled-job-modal');
    const modalOverlay = scheduledJobModal.querySelector('.modal-overlay');
    const modalCloseButton = document.getElementById('modal-close-button');
    const modalFields = {
        customer_name: document.getElementById('modal-customer-name'),
        service_address: document.getElementById('modal-address'),
        city: document.getElementById('modal-city'),
        email: document.getElementById('modal-email'),
        phone: document.getElementById('modal-phone'),
        cluster_label: document.getElementById('modal-cluster-label'),
        scheduled_date: document.getElementById('modal-scheduled-date'),
        priority: document.getElementById('modal-priority'),
        window_summary: document.getElementById('modal-window-summary'),
        problem_summary: document.getElementById('modal-problem-summary')
    };

    function closeScheduledJobModal() {
        scheduledJobModal.classList.add('hidden');
        scheduledJobModal.classList.remove('flex');
    }

    function openScheduledJobModal(payload) {
        Object.entries(modalFields).forEach(([key, element]) => {
            element.textContent = payload[key] || 'N/A';
        });

        scheduledJobModal.classList.remove('hidden');
        scheduledJobModal.classList.add('flex');
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('.scheduled-job-trigger');
        if (!trigger) {
            return;
        }

        try {
            openScheduledJobModal(JSON.parse(trigger.dataset.jobDetails || '{}'));
        } catch (error) {
            console.error('Unable to open scheduled job modal.', error);
        }
    });

    modalOverlay.addEventListener('click', closeScheduledJobModal);
    modalCloseButton.addEventListener('click', closeScheduledJobModal);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !scheduledJobModal.classList.contains('hidden')) {
            closeScheduledJobModal();
        }
    });
</script>
</body>
</html>
