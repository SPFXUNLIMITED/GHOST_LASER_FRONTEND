<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

const CLUSTER_DISTANCE_MILES = 20;

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

function isBusinessDay(DateTimeImmutable $date, array $settings)
{
    return in_array((int) $date->format('N'), getSchedulingWorkDayNumbers((string) $settings['work_days']), true);
}

/**
 * Add business days while skipping weekends.
 */
function addBusinessDays(DateTimeImmutable $date, $businessDays, array $settings)
{
    $currentDate = $date;
    $daysAdded = 0;

    while ($daysAdded < $businessDays) {
        $currentDate = $currentDate->modify('+1 day');

        if (isBusinessDay($currentDate, $settings)) {
            $daysAdded++;
        }
    }

    return $currentDate;
}

/**
 * Return the Y-m-d date string for the nth occurrence of a given weekday
 * (0 = Sunday … 6 = Saturday) within the given month.
 */
function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $n): string
{
    $date = new DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
    while ((int) $date->format('w') !== $weekday) {
        $date = $date->modify('+1 day');
    }
    for ($i = 1; $i < $n; $i++) {
        $date = $date->modify('+7 days');
    }
    return $date->format('Y-m-d');
}

/**
 * Return the Y-m-d date string for the last occurrence of a given weekday
 * (0 = Sunday … 6 = Saturday) within the given month.
 */
function lastWeekdayOfMonth(int $year, int $month, int $weekday): string
{
    $date = new DateTimeImmutable(sprintf('last day of %d-%02d', $year, $month));
    while ((int) $date->format('w') !== $weekday) {
        $date = $date->modify('-1 day');
    }
    return $date->format('Y-m-d');
}

/**
 * Return an array of [ 'Y-m-d' => 'Holiday Name' ] for standard US federal
 * and widely-observed holidays in the given year.
 */
function getUsHolidays(int $year): array
{
    return [
        sprintf('%d-01-01', $year)          => "New Year's Day",
        nthWeekdayOfMonth($year, 1, 1, 3)  => 'MLK Day',
        nthWeekdayOfMonth($year, 2, 1, 3)  => "Presidents' Day",
        lastWeekdayOfMonth($year, 5, 1)    => 'Memorial Day',
        sprintf('%d-07-04', $year)          => 'Independence Day',
        nthWeekdayOfMonth($year, 9, 1, 1)  => 'Labor Day',
        nthWeekdayOfMonth($year, 10, 1, 2) => 'Columbus Day',
        sprintf('%d-11-11', $year)          => 'Veterans Day',
        nthWeekdayOfMonth($year, 11, 4, 4) => 'Thanksgiving',
        sprintf('%d-12-25', $year)          => 'Christmas Day',
    ];
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
function formatVisitDurationSummary(?int $durationMinutes, array $settings): string
{
    $resolvedMinutes = (int) ($durationMinutes ?? 0);
    if ($resolvedMinutes <= 0) {
        $hours = max(1, (int) ($settings['default_time_window_size_hours'] ?? 2));
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' per visit';
    }

    $hours = intdiv($resolvedMinutes, 60);
    $minutes = $resolvedMinutes % 60;
    $parts = [];

    if ($hours > 0) {
        $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
    }

    if ($minutes > 0) {
        $parts[] = $minutes . ' min';
    }

    return implode(' ', $parts) . ' per visit';
}

/**
 * Build the scheduling window each job should follow based on its priority.
 *
 * @param ?DateTimeImmutable $submittedAt The date the job was originally submitted.
 *                                        When provided the window is anchored to that
 *                                        date so the deadline never drifts forward on
 *                                        subsequent page loads.  Falls back to today
 *                                        when null (e.g. legacy rows without created_at).
 */
function getPriorityScheduleWindow($priorityLevel, array $settings, ?int $durationMinutes = null, ?DateTimeImmutable $submittedAt = null)
{
    $anchor = $submittedAt ?? new DateTimeImmutable('today');
    $normalizedPriority = strtolower((string) $priorityLevel);
    $timeWindowSummary = formatVisitDurationSummary($durationMinutes, $settings);

    switch ($normalizedPriority) {
        case 'emergency':
            $startDate = $anchor;
            $endDate = $anchor->modify('+1 day');

            return [
                'label' => 'Emergency',
                'order' => 1,
                'window_summary' => $startDate->format('M j') . ' - ' . $endDate->format('M j') . ' (same/next day, ' . $timeWindowSummary . ')',
            ];

        case 'vip':
            $endDate = addBusinessDays($anchor, 2, $settings);

            return [
                'label' => 'VIP',
                'order' => 2,
                'window_summary' => 'Due by ' . $endDate->format('M j') . ' (within 2 business days, ' . $timeWindowSummary . ')',
            ];

        default:
            $startDate = addBusinessDays($anchor, 3, $settings);
            $endDate = addBusinessDays($anchor, 5, $settings);

            return [
                'label' => 'Standard',
                'order' => 3,
                'window_summary' => $startDate->format('M j') . ' - ' . $endDate->format('M j') . ' (3-5 business days, ' . $timeWindowSummary . ')',
            ];
    }
}

/**
 * Group jobs by proximity so dispatch can review nearby work together.
 *
 * Pass 1 – Assign jobs to clusters.
 *   Jobs are sorted by priority first so high-urgency work seeds new clusters.
 *   Each job is placed in the first existing cluster whose current centroid is
 *   within CLUSTER_DISTANCE_MILES and that still has capacity.  If no such
 *   cluster exists a new one is started.
 *
 * Pass 2 – Finalise each cluster.
 *   Recalculate the centroid from all member jobs, compute every job's
 *   distance_from_center_miles, and record the max_distance_miles spread.
 */
function buildGeographicClusters(array $jobs, array $settings)
{
    usort($jobs, 'compareJobsByPriority');
    $maxJobsPerCluster = calculateTechnicianDailyCapacity($settings);

    // ── Pass 1: greedy cluster assignment ────────────────────────────────────

    $clusters = [];

    foreach ($jobs as $job) {
        $placed = false;

        for ($i = 0; $i < count($clusters); $i++) {
            // Compute the current centroid of the candidate cluster.
            $latSum = 0.0;
            $lngSum = 0.0;
            foreach ($clusters[$i]['jobs'] as $existing) {
                $latSum += (float) $existing['latitude'];
                $lngSum += (float) $existing['longitude'];
            }
            $jobCount      = count($clusters[$i]['jobs']);
            $centroidLat   = $latSum / $jobCount;
            $centroidLng   = $lngSum / $jobCount;

            $distanceToCenter = haversineDistanceMiles(
                $job['latitude'],
                $job['longitude'],
                $centroidLat,
                $centroidLng
            );

            if ($distanceToCenter <= CLUSTER_DISTANCE_MILES && $jobCount < $maxJobsPerCluster) {
                $clusters[$i]['jobs'][] = $job;
                $placed = true;
                break;
            }
        }

        if (!$placed) {
            $clusters[] = [
                'cluster_label' => 'Cluster ' . str_pad((string) (count($clusters) + 1), 2, '0', STR_PAD_LEFT),
                'jobs'          => [$job],
            ];
        }
    }

    // ── Pass 2: finalise each cluster ────────────────────────────────────────

    $processedClusters = [];

    foreach ($clusters as $cluster) {
        $clusterJobs = $cluster['jobs'];
        usort($clusterJobs, 'compareJobsByPriority');

        // Centroid = simple average of all member coordinates.
        $latSum = 0.0;
        $lngSum = 0.0;
        foreach ($clusterJobs as $job) {
            $latSum += (float) $job['latitude'];
            $lngSum += (float) $job['longitude'];
        }
        $jobCount         = count($clusterJobs);
        $centroidLatitude  = $latSum / $jobCount;
        $centroidLongitude = $lngSum / $jobCount;

        error_log(sprintf(
            '[CLUSTER DEBUG] %s centroid calculated: lat=%.6f, lng=%.6f (%d jobs)',
            $cluster['cluster_label'],
            $centroidLatitude,
            $centroidLongitude,
            $jobCount
        ));

        // Stamp each job with its distance from the centroid.
        $jobsWithDistance = [];
        foreach ($clusterJobs as $job) {
            error_log(sprintf(
                '[CLUSTER DEBUG] %s — calculating distance for job id=%s (lat=%.6f, lng=%.6f) to centroid (lat=%.6f, lng=%.6f)',
                $cluster['cluster_label'],
                $job['id'] ?? 'unknown',
                (float) $job['latitude'],
                (float) $job['longitude'],
                $centroidLatitude,
                $centroidLongitude
            ));
            $job['distance_from_center_miles'] = haversineDistanceMiles(
                $job['latitude'],
                $job['longitude'],
                $centroidLatitude,
                $centroidLongitude
            );
            error_log(sprintf(
                '[CLUSTER DEBUG] %s — job id=%s distance_from_center=%.4f miles',
                $cluster['cluster_label'],
                $job['id'] ?? 'unknown',
                $job['distance_from_center_miles']
            ));
            $jobsWithDistance[] = $job;
        }

        // Derive the highest-priority label from the sorted job list.
        $highestPriorityOrder = $jobsWithDistance[0]['priority_meta']['order'];
        $highestPriorityLabel = $jobsWithDistance[0]['priority_meta']['label'];

        $processedClusters[] = [
            'cluster_label'          => $cluster['cluster_label'],
            'centroid_latitude'      => $centroidLatitude,
            'centroid_longitude'     => $centroidLongitude,
            'jobs'                   => $jobsWithDistance,
            'job_count'              => $jobCount,
            'max_distance_miles'     => getMaxSpreadMiles($jobsWithDistance),
            'highest_priority_order' => $highestPriorityOrder,
            'highest_priority_label' => $highestPriorityLabel,
        ];
    }

    // Sort clusters: highest priority first, then larger clusters first.
    usort($processedClusters, function ($a, $b) {
        $priorityComparison = $a['highest_priority_order'] <=> $b['highest_priority_order'];
        if ($priorityComparison !== 0) {
            return $priorityComparison;
        }
        return $b['job_count'] <=> $a['job_count'];
    });

    return $processedClusters;
}

/**
 * Format a minute-offset from midnight (e.g. 540) into a human-readable
 * 12-hour string such as "9:00 AM" or "10:30 AM".
 */
function formatMinutesAsTime(int $minutes): string
{
    $hour        = intdiv($minutes, 60) % 24;
    $minute      = $minutes % 60;
    $period      = $hour >= 12 ? 'PM' : 'AM';
    $displayHour = $hour % 12;

    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return $minute === 0
        ? "{$displayHour}:00 {$period}"
        : sprintf('%d:%02d %s', $displayHour, $minute, $period);
}

/**
 * Convert two stored TIME strings ("HH:MM") into a display label such as
 * "9:00 AM – 11:00 AM".  Returns null when either value is absent.
 */
function formatStoredTimeWindow(?string $start, ?string $end): ?string
{
    if ($start === null || $end === null) {
        return null;
    }

    $toMinutes = static function (string $time): int {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    };

    return formatMinutesAsTime($toMinutes($start)) . ' – ' . formatMinutesAsTime($toMinutes($end));
}

/**
 * Return a status badge array for a job based on how close it is to its
 * target window end date.  Both thresholds are read from $settings so that
 * changes on the settings page take effect immediately.
 *
 * Badge labels:
 *   'Overdue'          – window end date is in the past
 *   'Due Today'        – window end date is today
 *   'Due Tomorrow'     – within max_booking_advance_days (but not today)
 *   'Simmering'        – within simmer_days_threshold but beyond the advance window
 *   'Ready to Cluster' – beyond the simmer threshold
 *
 * @return array{ label: string, classes: string }
 */
function getJobStatusBadge(?string $windowEndDate, array $settings): array
{
    $advanceDays = max(1, (int) ($settings['max_booking_advance_days'] ?? 2));
    $simmerDays  = max(1, (int) ($settings['simmer_days_threshold'] ?? 3));

    if ($windowEndDate === null || $windowEndDate === '') {
        return ['label' => 'Ready to Cluster', 'classes' => 'bg-green-500/20 text-green-300'];
    }

    $windowEnd = DateTimeImmutable::createFromFormat('Y-m-d', $windowEndDate);
    if ($windowEnd === false) {
        return ['label' => 'Ready to Cluster', 'classes' => 'bg-green-500/20 text-green-300'];
    }

    $today = new DateTimeImmutable('today');
    $isPast = $windowEnd < $today;
    $daysUntilDue = (int) $today->diff($windowEnd)->days;

    if ($isPast) {
        return ['label' => 'Overdue', 'classes' => 'bg-red-500/20 text-red-300'];
    }
    if ($daysUntilDue === 0) {
        return ['label' => 'Due Today', 'classes' => 'bg-red-500/20 text-red-300'];
    }
    if ($daysUntilDue <= $advanceDays) {
        return ['label' => 'Due Tomorrow', 'classes' => 'bg-orange-500/20 text-orange-300'];
    }
    if ($daysUntilDue <= $simmerDays) {
        return ['label' => 'Simmering', 'classes' => 'bg-yellow-500/20 text-yellow-300'];
    }

    return ['label' => 'Ready to Cluster', 'classes' => 'bg-green-500/20 text-green-300'];
}

/**
 * Normalize a services.service_name value for case/whitespace-insensitive
 * matching. Used consistently everywhere a service name is looked up by
 * name (the repair function and the name-fallback in
 * resolveJobDurationsFromServices()) so the two never disagree on what
 * counts as a match.
 */
function normalizeServiceName(string $name): string
{
    return mb_strtolower(trim($name));
}

/**
 * Build a normalized service_name => id map from the services table.
 *
 * The services table has no uniqueness constraint on service_name (admins
 * can create two rows whose names normalize identically, e.g. "Diagnosis"
 * and " diagnosis "). Silently letting the later row win would let a
 * name-based lookup resolve to the wrong service id — numeric, so it would
 * pass downstream is_numeric() checks, but wrong. Instead this fails loudly
 * as soon as a collision is detected.
 *
 * @return array{map: array<string,int>, error: string|null}
 */
function buildServiceNameIdMap(PDO $pdo): array
{
    $serviceRows = $pdo->query('SELECT id, service_name FROM services')->fetchAll(PDO::FETCH_ASSOC);
    $idByName    = [];
    $nameById    = [];
    foreach ($serviceRows as $row) {
        $id   = (int) $row['id'];
        $name = normalizeServiceName((string) $row['service_name']);
        if (array_key_exists($name, $idByName)) {
            return [
                'map'   => [],
                'error' => sprintf(
                    'Services #%d and #%d both normalize to the service name "%s". Rename one of them in Service Settings before scheduling.',
                    $nameById[$name],
                    $id,
                    $name
                ),
            ];
        }
        $idByName[$name] = $id;
        $nameById[$name] = $id;
    }

    return ['map' => $idByName, 'error' => null];
}

/**
 * Resolve per-job duration by summing the `duration_minutes` of every service
 * referenced in the job's `services` JSON column.
 *
 * On success each job element receives a `duration_minutes` key holding the
 * summed value and the function returns null.
 *
 * On failure (no services assigned, unknown service ID, or any service that has
 * duration_minutes = 0) the function returns a human-readable error string and
 * leaves $jobs unmodified.
 *
 * @param PDO     $pdo
 * @param array[] $jobs  Each element must carry an 'id' and a 'services' key
 *                        (the raw JSON stored in service_requests.services).
 *
 * @return string|null  null on success, error message on failure.
 */
function resolveJobDurationsFromServices(PDO $pdo, array &$jobs): ?string
{
    // Collect every unique service ID referenced across all jobs. Entries in
    // the services JSON column are normally numeric services.id values, but
    // legacy rows whose services column still contains service_name strings
    // (predating a repair via repairServiceRequestsServiceNames()) are
    // resolved here too, through the same normalized name => id map the
    // repair function uses, so the two never disagree. Task bookings leave
    // services NULL and store duration directly in duration_minutes; skip
    // the service-lookup path for those jobs.
    $nameIdMap      = null; // Lazily built; only needed if a name is encountered.
    $resolveByName  = function (string $entry, int $jobId) use ($pdo, &$nameIdMap): array {
        if ($nameIdMap === null) {
            $built = buildServiceNameIdMap($pdo);
            if ($built['error'] !== null) {
                return ['id' => null, 'error' => $built['error']];
            }
            $nameIdMap = $built['map'];
        }
        $normalized = normalizeServiceName($entry);
        if (!array_key_exists($normalized, $nameIdMap)) {
            return [
                'id' => null,
                'error' => sprintf(
                    'Service request #%d references "%s", which does not match any service in the services table. Run the service data repair before scheduling.',
                    $jobId,
                    $entry
                ),
            ];
        }

        return ['id' => $nameIdMap[$normalized], 'error' => null];
    };

    $allServiceIds  = [];
    $resolvedEntries = []; // job id => list of resolved numeric service ids, in order.
    foreach ($jobs as $job) {
        $jobId        = (int) $job['id'];
        $servicesJson = trim((string) ($job['services'] ?? ''));
        if ($servicesJson === '') {
            // Task booking: duration_minutes must already be set.
            if ((int) ($job['duration_minutes'] ?? 0) > 0) {
                continue;
            }
            return sprintf(
                'Service request #%d has no services assigned. Duration cannot be calculated.',
                $jobId
            );
        }
        $entries = json_decode($servicesJson, true);
        if (!is_array($entries) || $entries === []) {
            return sprintf(
                'Service request #%d has no services assigned. Duration cannot be calculated.',
                $jobId
            );
        }
        $ids = [];
        foreach ($entries as $entry) {
            if (is_numeric($entry)) {
                $ids[] = (int) $entry;
                continue;
            }
            $resolved = $resolveByName((string) $entry, $jobId);
            if ($resolved['error'] !== null) {
                return $resolved['error'];
            }
            $ids[] = $resolved['id'];
        }
        $resolvedEntries[$jobId] = $ids;
        foreach ($ids as $id) {
            $allServiceIds[$id] = true;
        }
    }

    // Fetch duration_minutes and name for every referenced service in one query.
    $serviceRows = [];
    if ($allServiceIds !== []) {
        $uniqueIds    = array_keys($allServiceIds);
        $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));
        $stmt = $pdo->prepare("SELECT id, service_name, duration_minutes FROM services WHERE id IN ({$placeholders})");
        $stmt->execute($uniqueIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $serviceRows[(int) $row['id']] = $row;
        }
    }

    // Resolve each job's total duration.
    $resolved = [];
    foreach ($jobs as $job) {
        $jobId        = (int) $job['id'];
        $servicesJson = trim((string) ($job['services'] ?? ''));
        if ($servicesJson === '') {
            // Task booking: duration_minutes is already set; preserve it.
            $resolved[$jobId] = (int) $job['duration_minutes'];
            continue;
        }
        $total = 0;
        foreach ($resolvedEntries[$jobId] as $id) {
            if (!array_key_exists($id, $serviceRows)) {
                return sprintf(
                    'Service #%d used by request #%d was not found in the services table.',
                    $id,
                    $jobId
                );
            }
            $mins = (int) $serviceRows[$id]['duration_minutes'];
            if ($mins <= 0) {
                return sprintf(
                    '"%s" has no duration set. Please set its duration in Service Settings before scheduling.',
                    $serviceRows[$id]['service_name']
                );
            }
            $total += $mins;
        }
        $resolved[$jobId] = $total;
    }

    // Write resolved durations back into the jobs array.
    foreach ($jobs as &$job) {
        $job['duration_minutes'] = $resolved[(int) $job['id']];
    }
    unset($job);

    // Opportunistically converge the rows that needed name resolution onto
    // numeric ids so data at rest matches what a repair run would write.
    // Best-effort only: failure here must never fail the scheduling
    // operation that triggered this resolution. Running
    // repairServiceRequestsServiceNames() as a periodic/cron job is still
    // recommended as the authoritative fix.
    if ($jobIdsResolvedFromName !== []) {
        try {
            $updateStmt = $pdo->prepare('UPDATE service_requests SET services = :services WHERE id = :id');
            foreach (array_keys($jobIdsResolvedFromName) as $jobId) {
                $updateStmt->execute([
                    ':services' => json_encode(array_values($numericEntriesByJobId[$jobId])),
                    ':id'       => $jobId,
                ]);
            }
        } catch (Throwable $e) {
            error_log(sprintf(
                '[RESOLVE_JOB_DURATIONS] Best-effort persistence of resolved service ids failed: %s',
                $e->getMessage()
            ));
        }
    }

    return null;
}

/**
 * One-time data repair for legacy service_requests rows whose `services`
 * JSON column stores service_name strings (e.g. "Advanced Diagnosis")
 * instead of numeric services.id values. Each affected row is rewritten so
 * every entry is replaced with its matching services.id. This is safe to
 * run repeatedly: rows that already contain only numeric IDs are left
 * untouched.
 *
 * Rows whose name cannot be matched to a row in the services table are
 * skipped and reported (not silently dropped) rather than aborting the
 * whole batch — one bad legacy row no longer blocks the repair of every
 * other request. Detail of every skipped row is included in the returned
 * error string and logged. A duplicate normalized service name in the
 * catalog itself (e.g. "Diagnosis" and " diagnosis ") is a distinct,
 * higher-severity failure: it aborts the entire repair immediately because
 * any resolution made against an ambiguous map could silently pick the
 * wrong id.
 *
 * The read of service_requests and every UPDATE are wrapped in a single
 * transaction (started here unless one is already open) so the repair is
 * atomic and safe to re-run concurrently with other writers.
 *
 * Name matching is case-insensitive and trims whitespace on both the
 * stored service_name and the value found in the services column, using
 * the same normalizeServiceCatalogName()/buildServiceNameIdMap() helpers
 * that resolveJobDurationsFromServices() uses for its own name fallback.
 *
 * Debug output is written via error_log(), prefixed with
 * "[REPAIR_SERVICE_NAMES]". For every scanned row the log shows the raw
 * JSON string, the json_decode() result, each normalized entry, whether
 * that entry matched the services map, the exact UPDATE statement being
 * executed (with its bound values), and the affected row count reported
 * by PDOStatement::rowCount(). A summary of how many rows were scanned
 * and rewritten is logged at the end, plus the exact before/after value
 * of request #223's services column.
 *
 * @param PDO $pdo
 *
 * @return string|null  null on success (including when unmatched rows were
 *                       skipped and reported), error message when the
 *                       catalog itself is ambiguous or the repair failed.
 */
function repairServiceRequestsServiceNames(PDO $pdo): ?string
{
    // Normalize service names the same way on both sides of the lookup, and
    // reuse the same normalized name => id map (and its duplicate-name
    // detection) that resolveJobDurationsFromServices() uses, so the two
    // functions can never disagree about how a name resolves.
    $normalizeName = 'normalizeServiceName';

    $nameMap = buildServiceNameIdMap($pdo);
    if ($nameMap['error'] !== null) {
        return $nameMap['error'];
    }
    $idByName = $nameMap['map'];

    try {
        $requestRows = $pdo->query(
            "SELECT id, services FROM service_requests WHERE services IS NOT NULL AND services <> ''"
        )->fetchAll(PDO::FETCH_ASSOC);

        $updateStmt = $pdo->prepare('UPDATE service_requests SET services = :services WHERE id = :id');

        $scannedCount   = count($requestRows);
        $rewrittenCount = 0;
        $unmatchedByRequestId = []; // requestId => [unmatched name, ...] — skipped, not aborted.

        foreach ($requestRows as $request) {
            $requestId   = (int) $request['id'];
            $beforeValue = (string) $request['services'];
            $isTargetRow = $requestId === 223;

            error_log(sprintf(
                '[REPAIR_SERVICE_NAMES] Request #%d raw services JSON: %s',
                $requestId,
                $beforeValue
            ));

            $entries = json_decode($beforeValue, true);
            error_log(sprintf(
                '[REPAIR_SERVICE_NAMES] Request #%d json_decode result: %s',
                $requestId,
                var_export($entries, true)
            ));
            if (!is_array($entries) || $entries === []) {
                if ($isTargetRow) {
                    error_log(sprintf(
                        '[REPAIR_SERVICE_NAMES] Request #223 skipped (services column is not a non-empty JSON array). Before: %s',
                        $beforeValue
                    ));
                }
                continue;
            }

            $needsRepair = false;
            $resolvedIds = [];
            foreach ($entries as $entry) {
                if (is_numeric($entry)) {
                    error_log(sprintf(
                        '[REPAIR_SERVICE_NAMES] Request #%d entry %s is already numeric; no name lookup needed.',
                        $requestId,
                        var_export($entry, true)
                    ));
                    $resolvedIds[] = (int) $entry;
                    continue;
                }

                $needsRepair = true;
                $name        = normalizeServiceCatalogName((string) $entry);
                $nameMatched = array_key_exists($name, $idByName);
                error_log(sprintf(
                    '[REPAIR_SERVICE_NAMES] Request #%d entry %s normalized to %s; services map match: %s%s',
                    $requestId,
                    var_export((string) $entry, true),
                    var_export($name, true),
                    $nameMatched ? 'YES' : 'NO',
                    $nameMatched ? sprintf(' (id %d)', $idByName[$name]) : ''
                ));
                if (!$nameMatched) {
                    $unmatchedByRequestId[$requestId][] = (string) $entry;
                    continue;
                }
                $resolvedIds[] = $idByName[$name];
            }

            if (isset($unmatchedByRequestId[$requestId])) {
                error_log(sprintf(
                    '[REPAIR_SERVICE_NAMES] Request #%d skipped: unmatched name(s) not found in services table: %s. Row left unchanged; fix or add the missing service and re-run the repair.',
                    $requestId,
                    implode(', ', $unmatchedByRequestId[$requestId])
                ));
                continue;
            }

            if ($needsRepair) {
                $afterValue = json_encode(array_values($resolvedIds));
                error_log(sprintf(
                    '[REPAIR_SERVICE_NAMES] Request #%d executing SQL: UPDATE service_requests SET services = %s WHERE id = %d',
                    $requestId,
                    var_export($afterValue, true),
                    $requestId
                ));
                $updateStmt->execute([
                    ':services' => $afterValue,
                    ':id'       => $requestId,
                ]);
                $affectedRows = $updateStmt->rowCount();
                $rewrittenCount++;

                error_log(sprintf(
                    '[REPAIR_SERVICE_NAMES] Request #%d UPDATE affected row count: %d',
                    $requestId,
                    $affectedRows
                ));
                if ($affectedRows === 0) {
                    error_log(sprintf(
                        '[REPAIR_SERVICE_NAMES] WARNING: Request #%d UPDATE ran but affected 0 rows — the stored value did not change.',
                        $requestId
                    ));
                }

                if ($isTargetRow) {
                    error_log(sprintf(
                        '[REPAIR_SERVICE_NAMES] Request #223 rewritten. Before: %s | After: %s',
                        $beforeValue,
                        $afterValue
                    ));
                }
            } elseif ($isTargetRow) {
                error_log(sprintf(
                    '[REPAIR_SERVICE_NAMES] Request #223 already numeric, no rewrite needed. Value: %s',
                    $beforeValue
                ));
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        error_log(sprintf(
            '[REPAIR_SERVICE_NAMES] Scanned %d row(s), rewrote %d row(s), %d row(s) skipped as unmatched.',
            $scannedCount,
            $rewrittenCount,
            count($unmatchedByRequestId)
        ));

        if ($unmatchedByRequestId !== []) {
            $details = [];
            foreach ($unmatchedByRequestId as $requestId => $names) {
                $details[] = sprintf('#%d (%s)', $requestId, implode(', ', $names));
            }
            error_log(sprintf(
                '[REPAIR_SERVICE_NAMES] %d row(s) skipped, unmatched by request: %s',
                count($unmatchedByRequestId),
                implode('; ', $details)
            ));
        }

        return null;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[REPAIR_SERVICE_NAMES] Repair failed: ' . $e->getMessage());
        return 'Service data repair failed: ' . $e->getMessage();
    }
}

/**
 * Calculate a realistic arrival time window for every job in a cluster.
 *
 * The route starts at the shop location defined in scheduling settings and
 * walks through each job in the supplied order.  Every parameter — shop
 * coordinates, business start time, and buffer between jobs — is read
 * exclusively from $settings.  Job duration is read
 * from $job['duration_minutes'], which must be pre-resolved by
 * resolveJobDurationsFromServices() before calling this function.  Nothing
 * is hard-coded and no fallback to a global average is performed.
 *
 * Driving speed is estimated at 30 mph, a conservative figure for Southern
 * California urban/suburban routes that keeps windows realistic.
 *
 * @param array[] $jobs     Ordered list of jobs.  Each must carry 'id',
 *                          'latitude', 'longitude', and 'duration_minutes'.
 * @param array   $settings Full scheduling-settings array from
 *                          getSchedulingSettings().
 *
 * @return array<int, array{
 *     time_window_start: string,
 *     time_window_end: string,
 *     time_window_label: string,
 *     drive_minutes_from_previous: int,
 *     estimated_arrival: string
 * }>  Keyed by integer job/service-request id.
 */
function calculateClusterTimeWindows(array $jobs, array $settings): array
{
    // ── Origin: shop location from settings (never hard-coded) ───────────────
    $shopLat = (float) $settings['shop_latitude'];
    $shopLng = (float) $settings['shop_longitude'];

    // ── Day start: business_start_time from settings ──────────────────────────
    [$startHour, $startMin] = array_map('intval', explode(':', (string) $settings['business_start_time']));
    $currentMinutes = $startHour * 60 + $startMin;

    // ── Timing parameters from settings ──────────────────────────────────────
    $bufferMinutes     = max(0, (int) $settings['default_buffer_between_jobs_minutes']);

    // Conservative urban/suburban driving speed for SoCal routes.
    $averageDrivingSpeedMph = 30.0;

    $prevLat     = $shopLat;
    $prevLng     = $shopLng;
    $timeWindows = [];

    foreach ($jobs as $job) {
        $jobId    = (int) $job['id'];
        $hasCoords = hasValidCoordinates($job['latitude'] ?? null, $job['longitude'] ?? null);

        if ($hasCoords) {
            $distanceMiles = haversineDistanceMiles(
                $prevLat,
                $prevLng,
                (float) $job['latitude'],
                (float) $job['longitude']
            );
            $driveMinutes = (int) round(($distanceMiles / $averageDrivingSpeedMph) * 60);
        } else {
            // No coordinates — fall back to a sensible default drive segment.
            $driveMinutes = 15;
        }

        $jobDurationMinutes = (int) ($job['duration_minutes'] ?? 0);
        $arrivalMinutes     = $currentMinutes + $driveMinutes;
        $windowStartMinutes = $arrivalMinutes;
        $windowEndMinutes   = $windowStartMinutes + $jobDurationMinutes;

        $timeWindows[$jobId] = [
            'time_window_start'           => sprintf('%02d:%02d', intdiv($windowStartMinutes, 60) % 24, $windowStartMinutes % 60),
            'time_window_end'             => sprintf('%02d:%02d', intdiv($windowEndMinutes, 60) % 24, $windowEndMinutes % 60),
            'time_window_label'           => formatMinutesAsTime($windowStartMinutes) . ' – ' . formatMinutesAsTime($windowEndMinutes),
            'drive_minutes_from_previous' => $driveMinutes,
            'estimated_arrival'           => sprintf('%02d:%02d', intdiv($arrivalMinutes, 60) % 24, $arrivalMinutes % 60),
        ];

        // Advance the clock: complete this job, observe the buffer, then drive.
        $currentMinutes = $arrivalMinutes + $jobDurationMinutes + $bufferMinutes;

        if ($hasCoords) {
            $prevLat = (float) $job['latitude'];
            $prevLng = (float) $job['longitude'];
        }
    }

    return $timeWindows;
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
            time_window_start TIME NULL,
            time_window_end TIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_scheduled_cluster_job (scheduled_cluster_id, service_request_id),
            KEY idx_scheduled_cluster_jobs_service_request (service_request_id)
        )
    ");

    // Idempotent column migration for existing installations that were
    // created before time_window_start/end were added to the schema.
    $colExists = (int) $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'scheduled_cluster_jobs'
          AND COLUMN_NAME = 'time_window_start'
    ")->fetchColumn();

    if ($colExists === 0) {
        $pdo->exec("
            ALTER TABLE scheduled_cluster_jobs
                ADD COLUMN time_window_start TIME NULL AFTER service_request_id,
                ADD COLUMN time_window_end   TIME NULL AFTER time_window_start
        ");
    }
}

session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: ../admin-login.php');
    exit;
}

require_once __DIR__ . '/../project/db.php';
require_once __DIR__ . '/../project/customer_interaction_module.php';
require_once __DIR__ . '/../scheduling_settings.php';
require_once __DIR__ . '/../smtp_config.php';
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

ensureClusterSchedulingTables($pdo);
customerInteractionEnsureSchema($pdo);
$schedulingSettings = getSchedulingSettings($pdo);
$dailyTechnicianCapacity = calculateTechnicianDailyCapacity($schedulingSettings);

$clusteringRequested = false;
$testDataMessage = null;
$testDataError = null;
$clusterAssignMessage = null;
$clusterAssignError = null;
$notifyClusterMessage = null;
$notifyClusterError = null;
$calendarMonthParam = trim((string) ($_GET['month'] ?? $_POST['month'] ?? ''));
$parsedCalendarMonth = $calendarMonthParam !== ''
    ? DateTimeImmutable::createFromFormat('Y-m', $calendarMonthParam)
    : false;
if ($parsedCalendarMonth !== false && $parsedCalendarMonth->format('Y-m') === $calendarMonthParam) {
    $currentMonth = $parsedCalendarMonth->modify('first day of this month');
} else {
    $currentMonth = new DateTimeImmutable('first day of this month');
}
$calendarMonthParam = $currentMonth->format('Y-m');
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

        header('Location: schedule.php?month=' . urlencode($calendarMonthParam));
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
                    COALESCE(c.first_name, sr.task_contact, '') AS first_name,
                    COALESCE(c.last_name,  '') AS last_name
                FROM scheduled_cluster_jobs scj
                JOIN service_requests sr ON sr.id = scj.service_request_id
                LEFT JOIN customers c ON c.id = sr.customer_id
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
                (customer_id, priority_level, problem, preferred_date_start, preferred_date_end, request_status, latitude, longitude)
            VALUES
                (:customer_id, :priority_level, :problem, :preferred_date_start, :preferred_date_end, 'new', :latitude, :longitude)
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
                    $endDate   = addBusinessDays($today, 2, $schedulingSettings)->format('Y-m-d');
                    break;
                default:
                    $startDate = addBusinessDays($today, 3, $schedulingSettings)->format('Y-m-d');
                    $endDate   = addBusinessDays($today, 5, $schedulingSettings)->format('Y-m-d');
                    break;
            }

            // Apply a small random offset (±0.002°, ≈ ±200 m) so that repeated
            // test-data insertions produce distinct coordinates instead of
            // stacking jobs on the exact same point.
            $latJitter = mt_rand(-200, 200) / 100000.0;
            $lngJitter = mt_rand(-200, 200) / 100000.0;

            $insertReq->execute([
                ':customer_id'          => $customerId,
                ':priority_level'       => $cust['priority'],
                ':problem'              => $cust['problem'],
                ':preferred_date_start' => $startDate,
                ':preferred_date_end'   => $endDate,
                ':latitude'             => round($cust['lat'] + $latJitter, 6),
                ':longitude'            => round($cust['lng'] + $lngJitter, 6),
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

                $repairError = repairServiceRequestsServiceNames($pdo);
                if ($repairError !== null) {
                    $clusterAssignError = $repairError;
                } else {

                $placeholders = implode(',', array_fill(0, count($clusterJobIds), '?'));
                $validJobsStmt = $pdo->prepare("
                    SELECT id, latitude, longitude, services, duration_minutes
                    FROM service_requests
                    WHERE id IN ({$placeholders})
                      AND request_status IN ('new', 'queued')
                ");
                $validJobsStmt->execute($clusterJobIds);
                $validJobs = $validJobsStmt->fetchAll(PDO::FETCH_ASSOC);

                // Preserve the order in which the admin submitted the jobs.
                $submittedOrder = array_flip($clusterJobIds);
                usort($validJobs, static function ($a, $b) use ($submittedOrder) {
                    return ($submittedOrder[(int) $a['id']] ?? PHP_INT_MAX)
                        <=> ($submittedOrder[(int) $b['id']] ?? PHP_INT_MAX);
                });

                $validJobIds = array_map(static fn($job) => (int) $job['id'], $validJobs);

                if ($validJobIds === []) {
                    $clusterAssignError = 'No assignable jobs were found for this cluster.';
                } elseif (count($validJobIds) > $dailyTechnicianCapacity) {
                    $clusterAssignError = sprintf(
                        'This cluster has %d jobs, but current technician-day capacity is %d based on your admin settings.',
                        count($validJobIds),
                        $dailyTechnicianCapacity
                    );
                } else {
                    $insertClusterStmt = $pdo->prepare("
                        INSERT INTO scheduled_clusters
                            (cluster_label, scheduled_date, centroid_latitude, centroid_longitude, created_by_admin_id)
                        VALUES
                            (:cluster_label, :scheduled_date, :centroid_latitude, :centroid_longitude, :created_by_admin_id)
                    ");
                    $insertClusterJobStmt = $pdo->prepare("
                        INSERT INTO scheduled_cluster_jobs
                            (scheduled_cluster_id, service_request_id, time_window_start, time_window_end)
                        VALUES
                            (:scheduled_cluster_id, :service_request_id, :time_window_start, :time_window_end)
                    ");

                    // Calculate time windows from shop location + business hours.
                    // Respect per-run start/end overrides when they were submitted.
                    $assignmentSettings = $schedulingSettings;
                    $overrideStart = trim((string) ($_POST['cluster_override_start_time'] ?? ''));
                    $overrideEnd   = trim((string) ($_POST['cluster_override_end_time'] ?? ''));
                    if (preg_match('/^\d{2}:\d{2}$/', $overrideStart)) {
                        $assignmentSettings['business_start_time'] = $overrideStart;
                    }
                    if (preg_match('/^\d{2}:\d{2}$/', $overrideEnd)) {
                        $assignmentSettings['business_end_time'] = $overrideEnd;
                    }

                    // Job duration is the sum of duration_minutes for each selected service.
                    $durationError = resolveJobDurationsFromServices($pdo, $validJobs);
                    if ($durationError !== null) {
                        $clusterAssignError = $durationError;
                    } else {
                    $clusterTimeWindows = calculateClusterTimeWindows($validJobs, $assignmentSettings);

                    $pdo->beginTransaction();
                    $insertClusterStmt->execute([
                        ':cluster_label' => $clusterLabel,
                        ':scheduled_date' => $clusterDate,
                        ':centroid_latitude' => $centroidLatitude,
                        ':centroid_longitude' => $centroidLongitude,
                        ':created_by_admin_id' => $_SESSION['admin_id'] ?? null,
                    ]);

                    $scheduledClusterId = (int) $pdo->lastInsertId();
                    foreach ($validJobs as $validJob) {
                        $validJobId = (int) $validJob['id'];
                        $tw         = $clusterTimeWindows[$validJobId] ?? null;
                        $insertClusterJobStmt->execute([
                            ':scheduled_cluster_id' => $scheduledClusterId,
                            ':service_request_id'   => $validJobId,
                            ':time_window_start'    => $tw['time_window_start'] ?? null,
                            ':time_window_end'      => $tw['time_window_end'] ?? null,
                        ]);
                    }

                    $pdo->commit();
                    $clusterAssignMessage = sprintf(
                        '%s assigned to %s with %d job(s).',
                        $clusterLabel,
                        $parsedDate->format('M j, Y'),
                        count($validJobIds)
                    );
                    } // end if no duration error
                }
                } // end if no repair error
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

    $notifyClusterId = filter_input(
        INPUT_POST,
        'notify_cluster_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($notifyClusterId && !$unassignScheduledJobId && !$unassignScheduledClusterId && !$clusteringRequested) {
        try {
            // Load notification template ID 1.
            $notifStmt = $pdo->prepare("SELECT title, body FROM notifications WHERE id = 1 LIMIT 1");
            $notifStmt->execute();
            $notification = $notifStmt->fetch(PDO::FETCH_ASSOC);

            if (!$notification) {
                $notifyClusterError = 'Notification template #1 was not found. Please add it in the Notifications admin page.';
            } else {
                // Fetch all jobs in the cluster with customer + scheduling data.
                $clusterCustomersStmt = $pdo->prepare("
                    SELECT
                        c.first_name,
                        c.last_name,
                        c.email,
                        c.address,
                        c.city,
                        c.state,
                        c.zip,
                        c.company,
                        c.phone,
                        sc.scheduled_date,
                        scj.time_window_start,
                        scj.time_window_end,
                        sr.services AS services_json
                    FROM scheduled_clusters sc
                    JOIN scheduled_cluster_jobs scj ON scj.scheduled_cluster_id = sc.id
                    JOIN service_requests sr ON sr.id = scj.service_request_id
                    JOIN customers c ON c.id = sr.customer_id
                    WHERE sc.id = :cluster_id
                    ORDER BY c.last_name ASC, c.first_name ASC
                ");
                $clusterCustomersStmt->execute([':cluster_id' => $notifyClusterId]);
                $clusterCustomers = $clusterCustomersStmt->fetchAll(PDO::FETCH_ASSOC);

                // Deduplicate by email so each customer receives exactly one notification
                // even when they have multiple jobs in the cluster.
                $seenEmails = [];
                $clusterCustomers = array_values(array_filter($clusterCustomers, function ($row) use (&$seenEmails) {
                    $email = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($email === '' || isset($seenEmails[$email])) {
                        return false;
                    }
                    $seenEmails[$email] = true;
                    return true;
                }));

                if (empty($clusterCustomers)) {
                    $notifyClusterError = 'No customers found in that cluster.';
                } else {
                    // Load company-level tag values from company_settings table.
                    $notifCompanyName    = '';
                    $notifCompanyPhone   = '';
                    $notifCompanyWebsite = '';
                    try {
                        $csRow = $pdo->query(
                            "SELECT company_name, company_phone, company_website FROM company_settings WHERE id = 1 LIMIT 1"
                        )->fetch(PDO::FETCH_ASSOC);
                        if ($csRow) {
                            $notifCompanyName    = (string) $csRow['company_name'];
                            $notifCompanyPhone   = (string) $csRow['company_phone'];
                            $notifCompanyWebsite = (string) $csRow['company_website'];
                        }
                    } catch (Throwable $csEx) {
                        // company_settings table may not exist; leave values empty.
                    }

                    $CC_EMAIL = 'sales@lasercutterrepair.com';
                    $sent = 0;
                    $skipped = 0;
                    $failed = 0;
                    $failedNames = [];

                    foreach ($clusterCustomers as $customer) {
                        $customerEmail = trim((string) ($customer['email'] ?? ''));
                        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                            $skipped++;
                            continue;
                        }

                        $fullName = trim(implode(' ', array_filter([
                            trim((string) ($customer['first_name'] ?? '')),
                            trim((string) ($customer['last_name'] ?? '')),
                        ])));
                        $fullAddress = implode(', ', array_filter([
                            trim((string) ($customer['address'] ?? '')),
                            trim((string) ($customer['city'] ?? '')),
                            trim((string) ($customer['state'] ?? '')),
                            trim((string) ($customer['zip'] ?? '')),
                        ]));

                        $appointmentDate = trim((string) ($customer['scheduled_date'] ?? ''));
                        if ($appointmentDate !== '') {
                            try {
                                $appointmentDate = (new DateTimeImmutable($appointmentDate))->format('F j, Y');
                            } catch (Throwable $dtEx) {
                                // Keep raw value if parsing fails.
                            }
                        }

                        $timeStart = trim((string) ($customer['time_window_start'] ?? ''));
                        $timeEnd   = trim((string) ($customer['time_window_end'] ?? ''));

                        if ($timeStart !== '') {
                            try {
                                $timeStart = (new DateTimeImmutable($timeStart))->format('g:i A');
                            } catch (Throwable $dtEx) {
                                // Keep raw value.
                            }
                        }
                        if ($timeEnd !== '') {
                            try {
                                $timeEnd = (new DateTimeImmutable($timeEnd))->format('g:i A');
                            } catch (Throwable $dtEx) {
                                // Keep raw value.
                            }
                        }

                        $tagValues = [
                            '{client_name}'          => $fullName,
                            '{client_address}'       => $fullAddress,
                            '{company_name}'         => $notifCompanyName,
                            '{company_phone}'        => $notifCompanyPhone,
                            '{appointment_date}'     => $appointmentDate,
                            '{appointment_time}'     => $timeStart,
                            '{appointment_end_time}' => $timeEnd,
                            '{company_website}'      => $notifCompanyWebsite,
                            '{service_name}'         => '',
                        ];

                        // Resolve {service_name} from the JSON array of service IDs.
                        $servicesJson = trim((string) ($customer['services_json'] ?? ''));
                        if ($servicesJson !== '') {
                            $serviceIds = json_decode($servicesJson, true);
                            if (is_array($serviceIds) && $serviceIds !== []) {
                                try {
                                    $svcPlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
                                    $svcStmt = $pdo->prepare(
                                        "SELECT service_name FROM services WHERE id IN ({$svcPlaceholders}) ORDER BY service_name ASC"
                                    );
                                    $svcStmt->execute($serviceIds);
                                    $svcNames = $svcStmt->fetchAll(PDO::FETCH_COLUMN);
                                    $tagValues['{service_name}'] = implode(', ', $svcNames);
                                } catch (Throwable $svcEx) {
                                    // services table unavailable — leave tag empty.
                                }
                            }
                        }

                        $subject  = strtr((string) $notification['title'], $tagValues);
                        $bodyText = strtr((string) $notification['body'], $tagValues);
                        $bodyHtml = nl2br(htmlspecialchars($bodyText));

                        try {
                            $mailer = new PHPMailer(true);
                            $mailer->isSMTP();
                            $mailer->Host       = $SMTP_HOST;
                            $mailer->SMTPAuth   = true;
                            $mailer->Username   = $SMTP_USERNAME;
                            $mailer->Password   = $SMTP_PASSWORD;
                            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mailer->Port       = $SMTP_PORT;
                            $mailer->SMTPOptions = [
                                'ssl' => [
                                    'verify_peer'       => false,
                                    'verify_peer_name'  => false,
                                    'allow_self_signed' => true,
                                ],
                            ];
                            $mailer->setFrom($SMTP_FROM_EMAIL, $SMTP_FROM_NAME);
                            $mailer->addAddress($customerEmail, $fullName);
                            $mailer->addCC($CC_EMAIL);
                            $mailer->Subject = $subject;
                            $mailer->isHTML(true);
                            $mailer->Body    = $bodyHtml;
                            $mailer->AltBody = $bodyText;
                            $mailer->send();
                            $sent++;
                        } catch (MailerException $e) {
                            $failed++;
                            $failedNames[] = $fullName !== '' ? $fullName : $customerEmail;
                            error_log('[NOTIFY_CLUSTER] PHPMailer error for ' . $customerEmail . ': ' . $e->getMessage());
                        }
                    }

                    if ($sent > 0 && $failed === 0) {
                        $parts = ["Notification sent to {$sent} customer" . ($sent === 1 ? '' : 's') . '.'];
                        if ($skipped > 0) {
                            $parts[] = "{$skipped} skipped (no valid email).";
                        }
                        $notifyClusterMessage = implode(' ', $parts);
                    } elseif ($sent > 0) {
                        $parts = ["Sent to {$sent} customer" . ($sent === 1 ? '' : 's') . '.'];
                        $parts[] = "Failed for: " . implode(', ', $failedNames) . '.';
                        if ($skipped > 0) {
                            $parts[] = "{$skipped} skipped (no valid email).";
                        }
                        $notifyClusterMessage = implode(' ', $parts);
                    } else {
                        if ($skipped > 0 && $failed === 0) {
                            $notifyClusterError = "No emails sent — {$skipped} customer" . ($skipped === 1 ? ' has' : 's have') . " no valid email address.";
                        } else {
                            $failMsg = $failed > 0 ? ' Failed for: ' . implode(', ', $failedNames) . '.' : '';
                            $notifyClusterError = 'No notification emails could be sent.' . $failMsg;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[NOTIFY_CLUSTER] Unexpected error: ' . $e->getMessage());
            $notifyClusterError = 'An unexpected error occurred while sending notifications.';
        }
    }
}

$jobs = $pdo->query("
    SELECT 
        sr.id,
        sr.customer_id,
        COALESCE(c.first_name, sr.task_contact, '') AS first_name,
        COALESCE(c.last_name,  '') AS last_name,
        COALESCE(c.city, sr.destination_city) AS city,
        sr.latitude,
        sr.longitude,
        sr.priority_level,
        sr.laser_brand,
        sr.laser_model,
        sr.laser_watts,
        sr.laser_age,
        sr.problem,
        sr.service_speed,
        sr.service_total,
        sr.travel_fee,
        sr.grand_total,
        sr.services,
        sr.preferred_date_start,
        sr.preferred_date_end,
        sr.destination_street,
        sr.destination_city,
        sr.destination_state,
        sr.destination_zip,
        sr.duration_minutes,
        sr.created_at
    FROM service_requests sr
    LEFT JOIN customers c ON sr.customer_id = c.id
    WHERE sr.request_status IN ('new', 'queued')
    AND NOT EXISTS (
        SELECT 1 FROM scheduled_cluster_jobs scj WHERE scj.service_request_id = sr.id
    )
    ORDER BY FIELD(LOWER(sr.priority_level), 'emergency', 'vip', 'standard'), sr.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$jobsDurationError = resolveJobDurationsFromServices($pdo, $jobs);

foreach ($jobs as &$job) {
    $submittedAt = null;
    if (!empty($job['created_at'])) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $job['created_at']);
        if ($parsed !== false) {
            $submittedAt = $parsed->setTime(0, 0, 0);
        }
    }
    $job['priority_meta'] = getPriorityScheduleWindow(
        $job['priority_level'] ?? 'standard',
        $schedulingSettings,
        isset($job['duration_minutes']) ? (int) $job['duration_minutes'] : null,
        $submittedAt
    );
    $job['booking_service_address'] = implode(', ', array_filter([
        trim((string) ($job['destination_street'] ?? '')),
        trim((string) ($job['destination_city'] ?? '')),
        trim((string) ($job['destination_state'] ?? '')),
        trim((string) ($job['destination_zip'] ?? '')),
    ], static fn (string $part): bool => $part !== ''));
}
unset($job);

$clusterableJobs = array_values(array_filter($jobs, function ($job) {
    return hasValidCoordinates($job['latitude'] ?? null, $job['longitude'] ?? null);
}));

// Build a local settings copy so overrides apply only to this cluster run
// and never mutate $schedulingSettings (which drives all other logic).
$clusteringSettings = $schedulingSettings;
if ($clusteringRequested) {
    $overrideStart = trim((string) ($_POST['cluster_override_start_time'] ?? ''));
    $overrideEnd   = trim((string) ($_POST['cluster_override_end_time']   ?? ''));
    if (preg_match('/^\d{2}:\d{2}$/', $overrideStart)) {
        $clusteringSettings['business_start_time'] = $overrideStart;
    }
    if (preg_match('/^\d{2}:\d{2}$/', $overrideEnd)) {
        $clusteringSettings['business_end_time'] = $overrideEnd;
    }
}

$clusters = $clusteringRequested ? buildGeographicClusters($clusterableJobs, $clusteringSettings) : [];
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
        scj.time_window_start,
        scj.time_window_end,
        sr.id AS service_request_id,
        sr.priority_level,
        sr.laser_brand,
        sr.laser_model,
        sr.laser_watts,
        sr.laser_age,
        sr.problem,
        sr.service_speed,
        sr.service_total,
        sr.travel_fee,
        sr.grand_total,
        sr.services,
        sr.preferred_date_start,
        sr.preferred_date_end,
        sr.destination_street,
        sr.destination_city,
        sr.destination_state,
        sr.destination_zip,
        sr.created_at,
        sr.latitude AS job_latitude,
        sr.longitude AS job_longitude,
        c.id AS customer_id,
        COALESCE(c.first_name, sr.task_contact, '') AS first_name,
        COALESCE(c.last_name,  '') AS last_name,
        COALESCE(c.email,  '') AS email,
        COALESCE(c.phone,  '') AS phone,
        COALESCE(c.address, sr.destination_street) AS address,
        COALESCE(c.city,    sr.destination_city)   AS city,
        COALESCE(c.state,   sr.destination_state)  AS state,
        COALESCE(c.zip,     sr.destination_zip)    AS zip
    FROM scheduled_clusters sc
    JOIN scheduled_cluster_jobs scj ON scj.scheduled_cluster_id = sc.id
    JOIN service_requests sr ON sr.id = scj.service_request_id
    LEFT JOIN customers c ON c.id = sr.customer_id
    WHERE sc.scheduled_date BETWEEN :month_start AND :month_end
    ORDER BY
        sc.scheduled_date ASC,
        FIELD(LOWER(sr.priority_level), 'emergency', 'vip', 'standard'),
        sc.cluster_label ASC,
        first_name ASC
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
    $scheduledJob['customer_name'] = $customerName !== '' ? $customerName : 'Internal Task';
    $durationFromWindow = null;
    $storedStart = trim((string) ($scheduledJob['time_window_start'] ?? ''));
    $storedEnd = trim((string) ($scheduledJob['time_window_end'] ?? ''));
    if ($storedStart !== '' && $storedEnd !== '') {
        $startMinutes = (int) substr($storedStart, 0, 2) * 60 + (int) substr($storedStart, 3, 2);
        $endMinutes = (int) substr($storedEnd, 0, 2) * 60 + (int) substr($storedEnd, 3, 2);
        if ($endMinutes > $startMinutes) {
            $durationFromWindow = $endMinutes - $startMinutes;
        }
    }
    $scheduledSubmittedAt = null;
    if (!empty($scheduledJob['created_at'])) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $scheduledJob['created_at']);
        if ($parsed !== false) {
            $scheduledSubmittedAt = $parsed->setTime(0, 0, 0);
        }
    }
    $scheduledJob['priority_meta'] = getPriorityScheduleWindow(
        $scheduledJob['priority_level'] ?? 'standard',
        $schedulingSettings,
        $durationFromWindow,
        $scheduledSubmittedAt
    );
    $scheduledJob['service_address'] = formatCustomerAddress($scheduledJob);
    $scheduledJob['booking_service_address'] = implode(', ', array_filter([
        trim((string) ($scheduledJob['destination_street'] ?? '')),
        trim((string) ($scheduledJob['destination_city'] ?? '')),
        trim((string) ($scheduledJob['destination_state'] ?? '')),
        trim((string) ($scheduledJob['destination_zip'] ?? '')),
    ], static fn (string $part): bool => $part !== ''));
    $scheduledJob['time_window_label'] = formatStoredTimeWindow(
        $scheduledJob['time_window_start'] ?? null,
        $scheduledJob['time_window_end'] ?? null
    );
    if (
        hasValidCoordinates($scheduledJob['job_latitude'] ?? null, $scheduledJob['job_longitude'] ?? null) &&
        hasValidCoordinates($scheduledJob['centroid_latitude'] ?? null, $scheduledJob['centroid_longitude'] ?? null)
    ) {
        $scheduledJob['distance_from_center_miles'] = haversineDistanceMiles(
            (float) $scheduledJob['job_latitude'],
            (float) $scheduledJob['job_longitude'],
            (float) $scheduledJob['centroid_latitude'],
            (float) $scheduledJob['centroid_longitude']
        );
    } else {
        $scheduledJob['distance_from_center_miles'] = null;
    }
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

// Build a map of US holidays covering every year the visible calendar touches.
$calendarHolidayYears = array_unique([
    (int) $calendarStart->format('Y'),
    (int) $calendarEnd->format('Y'),
]);
$holidaysMap = [];
foreach ($calendarHolidayYears as $holidayYear) {
    $holidaysMap = array_merge($holidaysMap, getUsHolidays($holidayYear));
}

$calendarDays = [];
$calendarCursor = $calendarStart;

while ($calendarCursor <= $calendarEnd) {
    $calendarDateKey = $calendarCursor->format('Y-m-d');
    $calendarDays[] = [
        'date'             => $calendarCursor,
        'date_key'         => $calendarDateKey,
        'is_current_month' => $calendarCursor->format('Y-m') === $currentMonth->format('Y-m'),
        'is_today'         => $calendarDateKey === (new DateTimeImmutable('today'))->format('Y-m-d'),
        'clusters'         => $scheduledClustersByDate[$calendarDateKey] ?? [],
        'holiday_name'     => $holidaysMap[$calendarDateKey] ?? null,
    ];
    $calendarCursor = $calendarCursor->modify('+1 day');
}
?>

<?php
$pageTitle   = 'Scheduling Dashboard | Ghost Laser';
$bodyClass   = 'px-8 pb-8 pt-24';
$extraHead   = <<<'HTML'
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        .flatpickr-day.holiday-date:not(.selected) {
            border-color: rgba(245, 158, 11, 0.55);
            background: rgba(245, 158, 11, 0.18);
            color: #fbbf24;
            font-weight: 600;
        }
    </style>
HTML;
$headerRight = '<a href="../dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>';
require_once __DIR__ . '/../templates/header.php';
?>

    <div class="max-w-7xl mx-auto">
        <h1 class="text-5xl font-bold mb-2">Scheduling Dashboard</h1>


        <div class="mb-8">
            <div>
                <p class="text-zinc-400">Pending service requests (<?= count($jobs) ?> found)</p>
                <p class="mt-2 text-sm text-zinc-500">
                    Geographic clustering groups pending jobs with valid coordinates into route-friendly batches within <?= CLUSTER_DISTANCE_MILES ?> miles.
                    Current work week: <?= htmlspecialchars(getSchedulingWorkDayLabel((string) $schedulingSettings['work_days'])) ?>, <?= htmlspecialchars($schedulingSettings['business_start_time']) ?>-<?= htmlspecialchars($schedulingSettings['business_end_time']) ?>, <?= (int) $schedulingSettings['default_time_window_size_hours'] ?>h windows, max <?= $dailyTechnicianCapacity ?> jobs/day.
                </p>
            </div>
        </div>

        <div class="mb-8 grid gap-4 md:grid-cols-3">
            <div class="rounded-2xl border border-zinc-700 bg-zinc-900/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Service Hub</div>
                <div class="mt-2 text-sm font-medium text-white"><?= htmlspecialchars($schedulingSettings['shop_address']) ?></div>
                <div class="mt-1 text-xs text-zinc-400"><?= htmlspecialchars($schedulingSettings['shop_latitude']) ?>, <?= htmlspecialchars($schedulingSettings['shop_longitude']) ?></div>
            </div>
            <div class="rounded-2xl border border-zinc-700 bg-zinc-900/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Route Defaults</div>
                <div class="mt-2 text-sm font-medium text-white"><?= (int) $schedulingSettings['average_job_duration_minutes'] ?> min/job + <?= (int) $schedulingSettings['default_buffer_between_jobs_minutes'] ?> min buffer</div>
                <div class="mt-1 text-xs text-zinc-400">Capacity auto-calculated from business hours and technician limit.</div>
            </div>
            <div class="rounded-2xl border border-zinc-700 bg-zinc-900/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Dispatch Window</div>
                <div class="mt-2 text-sm font-medium text-white"><?= (int) $schedulingSettings['default_time_window_size_hours'] ?> hour windows</div>
                <div class="mt-1 text-xs text-zinc-400">Work days: <?= htmlspecialchars(getSchedulingWorkDayLabel((string) $schedulingSettings['work_days'])) ?></div>
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

        <?php if ($notifyClusterMessage !== null): ?>
            <div class="mb-6 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                <?= htmlspecialchars($notifyClusterMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($notifyClusterError !== null): ?>
            <div class="mb-6 rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                <?= htmlspecialchars($notifyClusterError) ?>
            </div>
        <?php endif; ?>

        <section class="mb-8 rounded-3xl border border-zinc-700 bg-zinc-900/80 p-6">
            <?php
            $prevMonthParam = $currentMonth->modify('-1 month')->format('Y-m');
            $nextMonthParam = $currentMonth->modify('+1 month')->format('Y-m');
            ?>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <a
                            href="?month=<?= urlencode($prevMonthParam) ?>"
                            class="inline-flex items-center justify-center rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-sm font-medium text-zinc-300 transition hover:bg-zinc-700 hover:text-white"
                            aria-label="Previous month"
                        >&larr; Prev</a>
                        <h2 class="text-2xl font-semibold text-white"><?= htmlspecialchars($currentMonth->format('F Y')) ?></h2>
                        <a
                            href="?month=<?= urlencode($nextMonthParam) ?>"
                            class="inline-flex items-center justify-center rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-sm font-medium text-zinc-300 transition hover:bg-zinc-700 hover:text-white"
                            aria-label="Next month"
                        >Next &rarr;</a>
                    </div>
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
                            $dayIsHoliday = $calendarDay['holiday_name'] !== null;
                            ?>
                            <div class="rounded-lg border px-2 py-1 <?php
                                if ($dayIsHoliday && $calendarDay['is_current_month']) {
                                    echo 'border-amber-600/50 bg-amber-500/10';
                                } elseif ($calendarDay['is_current_month']) {
                                    echo 'border-zinc-700 bg-zinc-950/80';
                                } else {
                                    echo 'border-zinc-800 bg-zinc-950/40 text-zinc-600';
                                }
                            ?> <?= ($dayHasAssignments || $dayIsHoliday) ? 'pb-2' : '' ?>">
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

                                <?php if ($dayIsHoliday): ?>
                                    <div class="mt-1 truncate text-[9px] font-semibold uppercase tracking-wide text-amber-400" title="<?= htmlspecialchars($calendarDay['holiday_name']) ?>">
                                        🇺🇸 <?= htmlspecialchars($calendarDay['holiday_name']) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($dayHasAssignments): ?>
                                    <div class="mt-2 space-y-2">
                                        <?php foreach ($calendarDay['clusters'] as $scheduledCluster): ?>
                                            <?php
                                            $clusterModalJobs = array_map(static function (array $clusterJob): array {
                                                return [
                                                    'customer_id' => $clusterJob['customer_id'] ?? null,
                                                    'customer_name' => $clusterJob['customer_name'] ?? 'Unknown Customer',
                                                    'time_window_label' => $clusterJob['time_window_label'] ?? 'Not assigned',
                                                    'booking_details' => [
                                                        'laser_brand' => $clusterJob['laser_brand'] ?? '',
                                                        'laser_model' => $clusterJob['laser_model'] ?? '',
                                                        'laser_watts' => $clusterJob['laser_watts'] ?? '',
                                                        'laser_age' => $clusterJob['laser_age'] ?? '',
                                                        'problem' => $clusterJob['problem'] ?? '',
                                                        'services' => implode(', ', (array) json_decode((string) ($clusterJob['services'] ?? ''), true)),
                                                        'service_speed' => $clusterJob['service_speed'] ?? '',
                                                        'service_total' => $clusterJob['service_total'] ?? '',
                                                        'travel_fee' => $clusterJob['travel_fee'] ?? '',
                                                        'grand_total' => $clusterJob['grand_total'] ?? '',
                                                        'priority' => $clusterJob['priority_level'] ?? '',
                                                        'preferred_date_start' => $clusterJob['preferred_date_start'] ?? '',
                                                        'preferred_date_end' => $clusterJob['preferred_date_end'] ?? '',
                                                        'service_address' => $clusterJob['booking_service_address'] ?? '',
                                                    ],
                                                ];
                                            }, $scheduledCluster['jobs']);
                                            $clusterModalPayload = [
                                                'scheduled_cluster_id' => $scheduledCluster['scheduled_cluster_id'],
                                                'center_city' => $scheduledCluster['center_city'],
                                                'cluster_label' => $scheduledCluster['cluster_label'],
                                                'scheduled_date' => $scheduledCluster['scheduled_date'],
                                                'jobs' => $clusterModalJobs,
                                            ];
                                            ?>
                                            <div class="rounded-lg border border-cyan-400/40 bg-cyan-500/10 px-2 py-1.5 shadow-[inset_0_0_0_1px_rgba(6,182,212,0.12)]">
                                                <div class="flex items-start justify-between gap-2">
                                                    <div class="min-w-0">
                                                        <button
                                                            type="button"
                                                            class="cluster-header-trigger w-full truncate text-left text-[10px] font-semibold uppercase tracking-wide text-cyan-200 transition hover:text-cyan-100"
                                                            data-cluster-details="<?= htmlspecialchars(json_encode($clusterModalPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
                                                        ><?= htmlspecialchars($scheduledCluster['center_city']) ?></button>
                                                    </div>
                                                    <form method="POST" onsubmit="return confirm('Unassign this entire cluster? This returns all jobs in the cluster to the clustering pool.');">
                                                        <input type="hidden" name="month" value="<?= htmlspecialchars($calendarMonthParam, ENT_QUOTES, 'UTF-8') ?>">
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
                                                            'customer_id' => $scheduledJob['customer_id'] ?? null,
                                                            'customer_name' => $scheduledJob['customer_name'],
                                                            'service_address' => $scheduledJob['service_address'],
                                                            'city' => $scheduledJob['city'] ?? null,
                                                            'email' => $scheduledJob['email'] ?? null,
                                                            'phone' => $scheduledJob['phone'] ?? null,
                                                            'priority' => $scheduledJob['priority_meta']['label'] ?? null,
                                                            'window_summary' => $scheduledJob['priority_meta']['window_summary'] ?? null,
                                                            'time_window_label' => $scheduledJob['time_window_label'] ?? null,
                                                            'problem' => $scheduledJob['problem'] ?? '',
                                                            'services' => implode(', ', (array) json_decode((string) ($scheduledJob['services'] ?? ''), true)),
                                                            'service_speed' => $scheduledJob['service_speed'] ?? '',
                                                            'service_total' => $scheduledJob['service_total'] ?? '',
                                                            'travel_fee' => $scheduledJob['travel_fee'] ?? '',
                                                            'grand_total' => $scheduledJob['grand_total'] ?? '',
                                                            'scheduled_date' => $scheduledJob['scheduled_date'],
                                                            'cluster_label' => $scheduledJob['cluster_label'],
                                                        ];
                                                        $bookingDetails = [
                                                            'laser_brand' => $scheduledJob['laser_brand'] ?? '',
                                                            'laser_model' => $scheduledJob['laser_model'] ?? '',
                                                            'laser_watts' => $scheduledJob['laser_watts'] ?? '',
                                                            'laser_age' => $scheduledJob['laser_age'] ?? '',
                                                            'problem' => $scheduledJob['problem'] ?? '',
                                                            'services' => implode(', ', (array) json_decode((string) ($scheduledJob['services'] ?? ''), true)),
                                                            'service_speed' => $scheduledJob['service_speed'] ?? '',
                                                            'service_total' => $scheduledJob['service_total'] ?? '',
                                                            'travel_fee' => $scheduledJob['travel_fee'] ?? '',
                                                            'grand_total' => $scheduledJob['grand_total'] ?? '',
                                                            'priority' => $scheduledJob['priority_level'] ?? '',
                                                            'preferred_date_start' => $scheduledJob['preferred_date_start'] ?? '',
                                                            'preferred_date_end' => $scheduledJob['preferred_date_end'] ?? '',
                                                            'service_address' => $scheduledJob['booking_service_address'] ?? '',
                                                        ];
                                                        $modalPayload['booking_details'] = $bookingDetails;
                                                        $modalStatusBadge = getJobStatusBadge($scheduledJob['preferred_date_end'] ?? null, $schedulingSettings);
                                                        $modalPayload['status_badge_label'] = $modalStatusBadge['label'];
                                                        $modalPayload['status_badge_classes'] = $modalStatusBadge['classes'];
                                                        ?>
                                                        <div
                                                            class="scheduled-job-trigger block w-full text-left text-[11px] text-zinc-100 transition hover:text-cyan-100 cursor-pointer"
                                                            role="button"
                                                            tabindex="0"
                                                            data-job-details="<?= htmlspecialchars(json_encode($modalPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
                                                        >
                                                            <span class="flex items-baseline justify-between gap-1">
                                                                <?php if (!empty($scheduledJob['customer_id'])): ?>
                                                                    <button type="button" class="customer-name-link truncate text-left hover:underline hover:text-cyan-100" data-customer-id="<?= (int) $scheduledJob['customer_id'] ?>" data-booking-details="<?= htmlspecialchars(json_encode($bookingDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($scheduledJob['customer_name']) ?></button>
                                                                <?php else: ?>
                                                                    <span class="truncate"><?= htmlspecialchars($scheduledJob['customer_name']) ?></span>
                                                                <?php endif; ?>
                                                                <?php if ($scheduledJob['distance_from_center_miles'] !== null): ?>
                                                                    <span class="shrink-0 text-zinc-400">+<?= number_format((float) $scheduledJob['distance_from_center_miles'], 1) ?>mi</span>
                                                                <?php endif; ?>
                                                            </span>
                                                            <?php if ($scheduledJob['time_window_label'] !== null): ?>
                                                                <span class="block text-cyan-400/80"><?= htmlspecialchars($scheduledJob['time_window_label']) ?></span>
                                                            <?php endif; ?>
                                                        </div>
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
            <div class="mt-3 flex items-center gap-3 px-1 text-[10px] text-zinc-500">
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-block h-3 w-3 rounded-sm border border-amber-600/50 bg-amber-500/20"></span>
                    US Holiday
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-block h-3 w-3 rounded-sm border border-cyan-400/40 bg-cyan-500/10"></span>
                    Assigned cluster
                </span>
            </div>
        </section>

        <?php if ($clusteringRequested): ?>
            <div class="mb-8 rounded-3xl border border-cyan-500/30 bg-zinc-900/80 p-6">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-2xl font-semibold text-white">Clustering Results</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-400">
                            Jobs are ranked by their priority response window first, then grouped by real-world distance using the Haversine formula.
                            Emergency requests stay at the top of each suggested cluster, followed by VIP and Standard work, with up to <?= $dailyTechnicianCapacity ?> jobs per scheduled route.
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
                                    <input type="hidden" name="cluster_override_start_time" value="<?= htmlspecialchars((string) ($clusteringSettings['business_start_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="cluster_override_end_time" value="<?= htmlspecialchars((string) ($clusteringSettings['business_end_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="month" value="<?= htmlspecialchars($calendarMonthParam, ENT_QUOTES, 'UTF-8') ?>">
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
                                    <?php
                                    $previewTimeWindows  = [];
                                    $previewDurationError = null;
                                    if ($jobsDurationError !== null) {
                                        $previewDurationError = $jobsDurationError;
                                    } else {
                                        $previewTimeWindows = calculateClusterTimeWindows($cluster['jobs'], $clusteringSettings);
                                    }
                                    ?>
                                    <?php if ($previewDurationError !== null): ?>
                                        <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
                                            <?= htmlspecialchars($previewDurationError, ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php foreach ($cluster['jobs'] as $clusteredJob): ?>
                                        <?php $previewTw = $previewTimeWindows[(int) $clusteredJob['id']] ?? null; ?>
                                        <div class="rounded-xl border border-zinc-800 bg-zinc-900/80 px-4 py-3 cluster-job-card" data-job-id="<?= (int) $clusteredJob['id'] ?>">
                                            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                                <div>
                                                    <div class="font-medium text-white">
                                                        <?= htmlspecialchars($clusteredJob['first_name'] . ' ' . $clusteredJob['last_name']) ?>
                                                        <span class="ml-2 text-sm font-normal text-zinc-400">&bull; <?= number_format((float) ($clusteredJob['distance_from_center_miles'] ?? 0), 1) ?> miles from center</span>
                                                    </div>
                                                    <div class="mt-1 text-sm text-zinc-400">
                                                        <?= htmlspecialchars($clusteredJob['city'] ?? 'N/A') ?> &bull;
                                                        <?= htmlspecialchars($clusteredJob['problem'] ?? '') ?>
                                                    </div>
                                                </div>
                                                <div class="flex items-start gap-3">
                                                    <div class="text-sm text-right text-zinc-300">
                                                        <div><?= htmlspecialchars($clusteredJob['priority_meta']['label']) ?></div>
                                                        <?php if ($previewTw !== null): ?>
                                                            <div class="mt-0.5 font-medium text-cyan-300"><?= htmlspecialchars($previewTw['time_window_label']) ?></div>
                                                            <div class="text-xs text-zinc-500"><?= (int) $previewTw['drive_minutes_from_previous'] ?> min drive</div>
                                                        <?php endif; ?>
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

        <div class="min-w-0">
            <section class="mb-4">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-semibold text-white">Jobs Awaiting Scheduling</h2>
                        <p class="mt-2 text-sm text-zinc-400">Review the current unassigned jobs below before routing them to a technician schedule.</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-3">
                        <a
                            href="../book_internal.php"
                            class="inline-flex items-center justify-center rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-200 transition hover:bg-zinc-700 hover:text-white"
                        >Book a Customer</a>
                        <a
                            href="../book_task.php"
                            class="inline-flex items-center justify-center rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-200 transition hover:bg-zinc-700 hover:text-white"
                        >Book a Task</a>
                        <a
                            href="../recurring-services.php"
                            class="inline-flex items-center justify-center rounded-lg border border-cyan-700/60 bg-cyan-950/30 px-4 py-2 text-sm font-medium text-cyan-300 transition hover:bg-cyan-950/50 hover:border-cyan-500/60"
                        >Recurring Services</a>
                        <form method="POST" id="run-clustering-form">
                            <input type="hidden" name="month" value="<?= htmlspecialchars($calendarMonthParam, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="run_clustering" value="1">
                            <input type="hidden" name="cluster_override_start_time" id="cluster-override-start-time" value="">
                            <input type="hidden" name="cluster_override_end_time" id="cluster-override-end-time" value="">
                            <button
                                type="button"
                                id="open-cluster-time-modal"
                                class="inline-flex items-center justify-center rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-semibold text-zinc-950 transition hover:bg-cyan-400"
                            >
                                Run Clustering
                            </button>
                        </form>
                    </div>
                </div>
            </section>

            <div class="overflow-hidden rounded-3xl border border-zinc-700 bg-zinc-900">
                <div class="overflow-x-auto">
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
                                    <td class="p-6">
                                        <?php if (!empty($job['customer_id'])): ?>
                                            <?php
                                            $bookingDetails = [
                                                'laser_brand' => $job['laser_brand'] ?? '',
                                                'laser_model' => $job['laser_model'] ?? '',
                                                'laser_watts' => $job['laser_watts'] ?? '',
                                                'laser_age' => $job['laser_age'] ?? '',
                                                'problem' => $job['problem'] ?? '',
                                                'services' => implode(', ', (array) json_decode((string) ($job['services'] ?? ''), true)),
                                                'service_speed' => $job['service_speed'] ?? '',
                                                'service_total' => $job['service_total'] ?? '',
                                                'travel_fee' => $job['travel_fee'] ?? '',
                                                'grand_total' => $job['grand_total'] ?? '',
                                                'priority' => $job['priority_level'] ?? '',
                                                'preferred_date_start' => $job['preferred_date_start'] ?? '',
                                                'preferred_date_end' => $job['preferred_date_end'] ?? '',
                                                'service_address' => $job['booking_service_address'] ?? '',
                                            ];
                                            ?>
                                            <button type="button" class="customer-name-link text-left text-cyan-300 hover:text-cyan-100 hover:underline" data-customer-id="<?= (int) $job['customer_id'] ?>" data-booking-details="<?= htmlspecialchars(json_encode($bookingDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($job['first_name'] . ' ' . $job['last_name']) ?></button>
                                        <?php else: ?>
                                            <?= htmlspecialchars($job['first_name'] . ' ' . $job['last_name']) ?>
                                        <?php endif; ?>
                                    </td>
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
                                    <td class="p-6 text-sm text-zinc-400">
                                        <?= htmlspecialchars($job['priority_meta']['window_summary']) ?>
                                        <?php
                                        $tableStatusBadge = getJobStatusBadge($job['preferred_date_end'] ?? null, $schedulingSettings);
                                        ?>
                                        <span class="mt-1 inline-block px-2 py-0.5 rounded-full text-xs font-semibold <?= htmlspecialchars($tableStatusBadge['classes'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($tableStatusBadge['label'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="p-6 text-sm text-zinc-400"><?= htmlspecialchars($job['problem'] ?? '') ?></td>
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
        </div>
    </div>
    <div id="cluster-processing-overlay" class="fixed inset-0 z-[200] hidden items-center justify-center bg-zinc-950/80 backdrop-blur-sm">
        <div class="flex flex-col items-center gap-4">
            <svg class="h-12 w-12 animate-spin text-cyan-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span class="text-base font-medium text-zinc-200">Running clustering — please wait&hellip;</span>
        </div>
    </div>
    <div id="cluster-time-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-zinc-950/80 px-4">
        <div class="absolute inset-0 cluster-time-modal-overlay"></div>
        <div class="relative z-10 w-full max-w-sm rounded-3xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl shadow-black/40">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm uppercase tracking-[0.2em] text-cyan-300">Cluster Run</p>
                    <h3 class="mt-2 text-xl font-semibold text-white">Set Start &amp; End Time</h3>
                    <p class="mt-1 text-sm text-zinc-400">Override the business hours for this clustering run only. Settings are not changed.</p>
                </div>
                <button type="button" id="cluster-time-modal-close" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-zinc-400 transition hover:bg-zinc-800 hover:text-white" aria-label="Close">&times;</button>
            </div>
            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <label class="flex flex-col gap-1 text-sm text-zinc-300">
                    Start time
                    <input
                        type="time"
                        id="cluster-time-modal-start"
                        value="<?= htmlspecialchars($clusteringSettings['business_start_time'], ENT_QUOTES, 'UTF-8') ?>"
                        class="mt-1 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-white focus:border-cyan-400 focus:outline-none"
                        required
                    >
                </label>
                <label class="flex flex-col gap-1 text-sm text-zinc-300">
                    End time
                    <input
                        type="time"
                        id="cluster-time-modal-end"
                        value="<?= htmlspecialchars($clusteringSettings['business_end_time'], ENT_QUOTES, 'UTF-8') ?>"
                        class="mt-1 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-white focus:border-cyan-400 focus:outline-none"
                        required
                    >
                </label>
            </div>
            <div id="cluster-time-modal-error" class="mt-3 hidden rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-400"></div>
            <div class="mt-6 flex gap-3">
                <button
                    type="button"
                    id="cluster-time-modal-confirm"
                    class="flex-1 inline-flex items-center justify-center rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-semibold text-zinc-950 transition hover:bg-cyan-400"
                >
                    Confirm &amp; Run
                </button>
                <button
                    type="button"
                    id="cluster-time-modal-cancel"
                    class="inline-flex items-center justify-center rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2.5 text-sm font-semibold text-zinc-300 transition hover:bg-zinc-700"
                >
                    Cancel
                </button>
            </div>
        </div>
    </div>
    <div id="scheduled-job-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-zinc-950/80 px-4">
    <div class="absolute inset-0 modal-overlay"></div>
    <div class="relative z-10 w-full max-w-2xl rounded-3xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl shadow-black/40">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-sm uppercase tracking-[0.2em] text-cyan-300">Scheduled Job</p>
                <button type="button" id="modal-customer-name" class="mt-2 block text-2xl font-semibold text-white hover:text-cyan-300 hover:underline text-left" onclick="openScheduledJobModalCustomer()">Customer</button>
            </div>
            <button type="button" id="modal-close-button" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-zinc-400 transition hover:bg-zinc-800 hover:text-white" aria-label="Close modal">&times;</button>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <div id="modal-address-row" class="rounded-2xl border border-zinc-800 bg-zinc-950/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Service address</div>
                <div id="modal-address" class="mt-2 text-sm leading-6 text-zinc-200"></div>
            </div>
            <div class="rounded-2xl border border-zinc-800 bg-zinc-950/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Contact</div>
                <div class="mt-2 space-y-2 text-sm text-zinc-200">
                    <div id="modal-phone-row"><span class="text-zinc-500">Phone:</span> <span id="modal-phone"></span></div>
                    <div id="modal-email-row"><span class="text-zinc-500">Email:</span> <span id="modal-email"></span></div>
                    <div id="modal-city-row"><span class="text-zinc-500">City:</span> <span id="modal-city"></span></div>
                </div>
            </div>
            <div class="rounded-2xl border border-zinc-800 bg-zinc-950/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Scheduling</div>
                <div class="mt-2 space-y-2 text-sm text-zinc-200">
                    <div id="modal-cluster-row"><span class="text-zinc-500">Cluster:</span> <span id="modal-cluster-label"></span></div>
                    <div id="modal-scheduled-date-row"><span class="text-zinc-500">Scheduled date:</span> <span id="modal-scheduled-date"></span></div>
                    <div id="modal-priority-row"><span class="text-zinc-500">Priority:</span> <span id="modal-priority"></span></div>
                    <div id="modal-time-window-row"><span class="text-zinc-500">Time window:</span> <span id="modal-time-window-label" class="font-medium text-cyan-300"></span></div>
                    <div id="modal-window-summary-row"><span class="text-zinc-500">Target window:</span> <span id="modal-window-summary"></span></div>
                    <div><span class="text-zinc-500">Status:</span> <span id="modal-status-badge" class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold"></span></div>
                </div>
            </div>
            <div class="rounded-2xl border border-zinc-800 bg-zinc-950/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Job details</div>
                <div class="mt-2 space-y-1 text-sm leading-6 text-zinc-200">
                    <div id="modal-problem" class="hidden"></div>
                    <div id="modal-services" class="hidden"></div>
                    <div id="modal-service-speed" class="hidden"></div>
                    <div id="modal-service-total" class="hidden"></div>
                    <div id="modal-travel-fee" class="hidden"></div>
                    <div id="modal-grand-total" class="hidden"></div>
                </div>
            </div>
        </div>
    </div>
    </div>
    <div id="cluster-summary-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-zinc-950/80 px-4">
        <div class="absolute inset-0 cluster-modal-overlay"></div>
        <div class="relative z-10 w-full max-w-3xl rounded-3xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl shadow-black/40">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm uppercase tracking-[0.2em] text-cyan-300">Scheduled Cluster</p>
                    <h3 id="cluster-modal-name" class="mt-2 text-2xl font-semibold text-white">Cluster</h3>
                    <p id="cluster-modal-location" class="mt-2 text-sm text-zinc-300">Location unavailable</p>
                    <p id="cluster-modal-date" class="mt-1 text-xs uppercase tracking-wide text-zinc-500">Date unavailable</p>
                </div>
                <button type="button" id="cluster-modal-close-button" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-zinc-400 transition hover:bg-zinc-800 hover:text-white" aria-label="Close modal">&times;</button>
            </div>

            <div class="mt-6 rounded-2xl border border-zinc-800 bg-zinc-950/80 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Jobs in this cluster</div>
                <div id="cluster-modal-jobs-empty" class="mt-3 hidden text-sm text-zinc-400">No jobs found for this cluster.</div>
                <ul id="cluster-modal-jobs-list" class="mt-3 space-y-2"></ul>
            </div>

            <form method="POST" id="cluster-notify-form">
                <input type="hidden" name="month" value="<?= htmlspecialchars($calendarMonthParam, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="notify_cluster_id" id="cluster-notify-cluster-id" value="">
                <button
                    type="submit"
                    id="cluster-notify-all-button"
                    class="mt-6 inline-flex w-full items-center justify-center rounded-2xl bg-cyan-500 px-6 py-4 text-base font-semibold text-zinc-950 transition hover:bg-cyan-400"
                >
                    Notify All Customers in This Cluster
                </button>
            </form>
        </div>
    </div>
<script>
    <?php
    // Pre-compute holiday dates for visual display
    $flatpickrHolidayYears = [(int) date('Y'), (int) date('Y') + 1, (int) date('Y') + 2];
    $flatpickrHolidays = [];
    foreach ($flatpickrHolidayYears as $fpYear) {
        $flatpickrHolidays = array_merge($flatpickrHolidays, array_keys(getUsHolidays($fpYear)));
    }
    ?>
    const usHolidayDates = <?= json_encode(array_values($flatpickrHolidays), JSON_UNESCAPED_SLASHES) ?>;

    flatpickr('.cluster-date-picker', {
        minDate: 'today',
        dateFormat: 'Y-m-d',
        disableMobile: false,
        onDayCreate: function(dObj, dStr, fp, dayElem) {
            const dateStr = dayElem.dateObj.toISOString().slice(0, 10);
            if (usHolidayDates.includes(dateStr)) {
                dayElem.title = 'Holiday';
                dayElem.classList.add('holiday-date');
            }
        }
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
    const modalStatusBadge = document.getElementById('modal-status-badge');
    const modalCustomerNameBtn = document.getElementById('modal-customer-name');
    let currentScheduledJobCustomerId = null;
    const modalFields = {
        problem: document.getElementById('modal-problem'),
        services: document.getElementById('modal-services'),
        service_speed: document.getElementById('modal-service-speed'),
        service_total: document.getElementById('modal-service-total'),
        travel_fee: document.getElementById('modal-travel-fee'),
        grand_total: document.getElementById('modal-grand-total')
    };

    const modalRows = {
        service_address: { row: document.getElementById('modal-address-row'), value: document.getElementById('modal-address') },
        phone: { row: document.getElementById('modal-phone-row'), value: document.getElementById('modal-phone') },
        email: { row: document.getElementById('modal-email-row'), value: document.getElementById('modal-email') },
        city: { row: document.getElementById('modal-city-row'), value: document.getElementById('modal-city') },
        cluster_label: { row: document.getElementById('modal-cluster-row'), value: document.getElementById('modal-cluster-label') },
        scheduled_date: { row: document.getElementById('modal-scheduled-date-row'), value: document.getElementById('modal-scheduled-date') },
        priority: { row: document.getElementById('modal-priority-row'), value: document.getElementById('modal-priority') },
        time_window_label: { row: document.getElementById('modal-time-window-row'), value: document.getElementById('modal-time-window-label') },
        window_summary: { row: document.getElementById('modal-window-summary-row'), value: document.getElementById('modal-window-summary') }
    };

    window.openScheduledJobModalCustomer = function () {
        if (currentScheduledJobCustomerId && typeof window.openCustomerDetailsModal === 'function') {
            window.openCustomerDetailsModal(currentScheduledJobCustomerId);
        }
    };

    function closeScheduledJobModal() {
        scheduledJobModal.classList.add('hidden');
        scheduledJobModal.classList.remove('flex');
    }

    function openScheduledJobModal(payload) {
        const detailLabels = {
            problem: 'Problem: ',
            services: 'Services: ',
            service_speed: 'Speed: ',
            service_total: 'Service total: $',
            travel_fee: 'Travel fee: $',
            grand_total: 'Grand total: $'
        };

        Object.entries(modalFields).forEach(([key, element]) => {
            const value = payload[key] === null || payload[key] === undefined ? '' : String(payload[key]).trim();
            element.textContent = value === '' ? '' : detailLabels[key] + value;
            element.classList.toggle('hidden', value === '');
        });

        Object.entries(modalRows).forEach(([key, { row, value }]) => {
            const raw = payload[key];
            const text = raw === null || raw === undefined ? '' : String(raw).trim();
            value.textContent = text;
            if (row) {
                row.classList.toggle('hidden', text === '');
            }
        });

        modalCustomerNameBtn.textContent = payload.customer_name || 'Customer';
        currentScheduledJobCustomerId = payload.customer_id || null;
        modalCustomerNameBtn.classList.toggle('cursor-pointer', Boolean(currentScheduledJobCustomerId));
        modalCustomerNameBtn.title = currentScheduledJobCustomerId ? 'View customer details' : '';

        if (modalStatusBadge) {
            const label = payload.status_badge_label || '';
            const classes = payload.status_badge_classes || '';
            modalStatusBadge.textContent = label || 'N/A';
            modalStatusBadge.className = 'inline-block px-2 py-0.5 rounded-full text-xs font-semibold ' + classes;
        }

        scheduledJobModal.classList.remove('hidden');
        scheduledJobModal.classList.add('flex');
    }

    const clusterSummaryModal = document.getElementById('cluster-summary-modal');
    const clusterModalOverlay = clusterSummaryModal.querySelector('.cluster-modal-overlay');
    const clusterModalCloseButton = document.getElementById('cluster-modal-close-button');
    const clusterModalName = document.getElementById('cluster-modal-name');
    const clusterModalLocation = document.getElementById('cluster-modal-location');
    const clusterModalDate = document.getElementById('cluster-modal-date');
    const clusterModalJobsList = document.getElementById('cluster-modal-jobs-list');
    const clusterModalJobsEmpty = document.getElementById('cluster-modal-jobs-empty');
    const clusterNotifyClusterId = document.getElementById('cluster-notify-cluster-id');

    function closeClusterSummaryModal() {
        clusterSummaryModal.classList.add('hidden');
        clusterSummaryModal.classList.remove('flex');
    }

    function openClusterSummaryModal(payload) {
        clusterModalName.textContent = payload.cluster_label || 'Cluster';
        clusterModalLocation.textContent = payload.center_city
            ? `Center location: ${payload.center_city}`
            : 'Center location unavailable';
        clusterModalDate.textContent = payload.scheduled_date || 'Date unavailable';

        if (clusterNotifyClusterId) {
            clusterNotifyClusterId.value = payload.scheduled_cluster_id || '';
        }

        clusterModalJobsList.innerHTML = '';
        const jobs = Array.isArray(payload.jobs) ? payload.jobs : [];
        clusterModalJobsEmpty.classList.toggle('hidden', jobs.length > 0);

        jobs.forEach((job) => {
            const item = document.createElement('li');
            item.className = 'flex items-start justify-between gap-3 rounded-xl border border-zinc-800 bg-zinc-900/80 px-3 py-2';
            let customerName;
            if (job.customer_id) {
                customerName = document.createElement('button');
                customerName.type = 'button';
                customerName.className = 'customer-name-link text-sm font-medium text-zinc-100 hover:text-cyan-300 hover:underline text-left';
                customerName.dataset.customerId = job.customer_id;
                customerName.dataset.bookingDetails = JSON.stringify(job.booking_details || {});
            } else {
                customerName = document.createElement('span');
                customerName.className = 'text-sm font-medium text-zinc-100';
            }
            customerName.textContent = job.customer_name || 'Unknown Customer';
            const timeWindow = document.createElement('span');
            timeWindow.className = 'shrink-0 text-xs font-medium text-cyan-300';
            timeWindow.textContent = job.time_window_label || 'Not assigned';
            item.appendChild(customerName);
            item.appendChild(timeWindow);
            clusterModalJobsList.appendChild(item);
        });

        clusterSummaryModal.classList.remove('hidden');
        clusterSummaryModal.classList.add('flex');
    }

    function renderCustomerBookingDetails(details) {
        const body = document.getElementById('customerDetailsBody');
        if (!body) return;

        const existing = document.getElementById('customer-booking-details');
        if (existing) existing.remove();

        const labels = {
            laser_brand: 'Laser brand',
            laser_model: 'Laser model',
            laser_watts: 'Laser watts',
            laser_age: 'Laser age',
            problem: 'Problem',
            services: 'Services',
            service_speed: 'Service speed',
            service_total: 'Service total',
            travel_fee: 'Travel fee',
            grand_total: 'Grand total',
            priority: 'Priority',
            preferred_date_start: 'Preferred date start',
            preferred_date_end: 'Preferred date end',
            service_address: 'Service address'
        };
        const entries = Object.entries(labels)
            .map(([key, label]) => [label, details[key] === null || details[key] === undefined ? '' : String(details[key]).trim()])
            .filter(([, value]) => value !== '');
        if (entries.length === 0) return;

        const section = document.createElement('div');
        section.id = 'customer-booking-details';
        section.className = 'space-y-2';
        const heading = document.createElement('p');
        heading.className = 'text-xs uppercase tracking-wider text-zinc-500';
        heading.textContent = 'Booking Details';
        section.appendChild(heading);
        const fields = document.createElement('div');
        fields.className = 'grid gap-2 md:grid-cols-2';
        entries.forEach(([label, value]) => {
            const line = document.createElement('div');
            line.className = 'text-sm text-zinc-200';
            const labelElement = document.createElement('span');
            labelElement.className = 'text-zinc-500';
            labelElement.textContent = label + ': ';
            line.appendChild(labelElement);
            line.appendChild(document.createTextNode(value));
            fields.appendChild(line);
        });
        section.appendChild(fields);
        body.children[0].after(section);
    }

    document.addEventListener('click', function (event) {
        const customerNameLink = event.target.closest('.customer-name-link');
        if (customerNameLink) {
            event.preventDefault();
            event.stopPropagation();
            const customerId = customerNameLink.dataset.customerId;
            if (customerId && typeof window.openCustomerDetailsModal === 'function') {
                try {
                    renderCustomerBookingDetails(JSON.parse(customerNameLink.dataset.bookingDetails || '{}'));
                } catch (error) {
                    console.error('Unable to load booking details.', error);
                }
                window.openCustomerDetailsModal(customerId);
            }
            return;
        }

        const clusterTrigger = event.target.closest('.cluster-header-trigger');
        if (clusterTrigger) {
            try {
                openClusterSummaryModal(JSON.parse(clusterTrigger.dataset.clusterDetails || '{}'));
            } catch (error) {
                console.error('Unable to open cluster modal.', error);
            }
            return;
        }

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

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        const trigger = event.target.closest('.scheduled-job-trigger');
        if (!trigger || event.target.closest('.customer-name-link')) {
            return;
        }
        event.preventDefault();
        trigger.click();
    });

    modalOverlay.addEventListener('click', closeScheduledJobModal);
    modalCloseButton.addEventListener('click', closeScheduledJobModal);
    clusterModalOverlay.addEventListener('click', closeClusterSummaryModal);
    clusterModalCloseButton.addEventListener('click', closeClusterSummaryModal);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !scheduledJobModal.classList.contains('hidden')) {
            closeScheduledJobModal();
        }
        if (event.key === 'Escape' && !clusterSummaryModal.classList.contains('hidden')) {
            closeClusterSummaryModal();
        }
        if (event.key === 'Escape' && !clusterTimeModal.classList.contains('hidden')) {
            closeClusterTimeModal();
        }
    });

    // ── Cluster time-override modal ───────────────────────────────────────────
    const clusterTimeModal         = document.getElementById('cluster-time-modal');
    const clusterTimeModalOverlay  = clusterTimeModal.querySelector('.cluster-time-modal-overlay');
    const clusterTimeModalClose    = document.getElementById('cluster-time-modal-close');
    const clusterTimeModalCancel   = document.getElementById('cluster-time-modal-cancel');
    const clusterTimeModalConfirm  = document.getElementById('cluster-time-modal-confirm');
    const clusterTimeModalStart    = document.getElementById('cluster-time-modal-start');
    const clusterTimeModalEnd      = document.getElementById('cluster-time-modal-end');
    const clusterTimeModalError    = document.getElementById('cluster-time-modal-error');
    const openClusterTimeModalBtn  = document.getElementById('open-cluster-time-modal');
    const runClusteringForm        = document.getElementById('run-clustering-form');
    const overrideStartInput       = document.getElementById('cluster-override-start-time');
    const overrideEndInput         = document.getElementById('cluster-override-end-time');

    function openClusterTimeModal() {
        clusterTimeModalError.classList.add('hidden');
        clusterTimeModalError.textContent = '';
        clusterTimeModal.classList.remove('hidden');
        clusterTimeModal.classList.add('flex');
        clusterTimeModalStart.focus();
    }

    function closeClusterTimeModal() {
        clusterTimeModal.classList.add('hidden');
        clusterTimeModal.classList.remove('flex');
        overrideStartInput.value = '';
        overrideEndInput.value   = '';
    }

    openClusterTimeModalBtn.addEventListener('click', openClusterTimeModal);
    clusterTimeModalClose.addEventListener('click', closeClusterTimeModal);
    clusterTimeModalCancel.addEventListener('click', closeClusterTimeModal);
    clusterTimeModalOverlay.addEventListener('click', closeClusterTimeModal);

    const clusterProcessingOverlay = document.getElementById('cluster-processing-overlay');

    clusterTimeModalConfirm.addEventListener('click', function () {
        const startVal = clusterTimeModalStart.value;
        const endVal   = clusterTimeModalEnd.value;
        const timePattern = /^\d{2}:\d{2}$/;

        if (!timePattern.test(startVal) || !timePattern.test(endVal)) {
            clusterTimeModalError.textContent = 'Please enter valid start and end times.';
            clusterTimeModalError.classList.remove('hidden');
            return;
        }

        if (startVal >= endVal) {
            clusterTimeModalError.textContent = 'Start time must be before end time.';
            clusterTimeModalError.classList.remove('hidden');
            return;
        }

        overrideStartInput.value = startVal;
        overrideEndInput.value   = endVal;

        // Close the modal and show the full-page overlay before the form submits
        // so the user sees loading feedback throughout the entire page reload.
        clusterTimeModal.classList.add('hidden');
        clusterTimeModal.classList.remove('flex');
        clusterProcessingOverlay.classList.remove('hidden');
        clusterProcessingOverlay.classList.add('flex');

        runClusteringForm.submit();
    });
</script>
<?php customerInteractionRenderModal(); ?>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
