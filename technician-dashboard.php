<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Extend session lifetime to 12 hours for technicians using this page while driving.
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params([
    'lifetime' => 43200,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/project/technician_dashboard_auth.php';

if (!technicianDashboardHasAccess()) {
    header('Location: admin-login.php');
    exit;
}

if (empty($_SESSION['technician_dashboard_csrf'])) {
    $_SESSION['technician_dashboard_csrf'] = bin2hex(random_bytes(16));
}
$technicianDashboardCsrf = (string) $_SESSION['technician_dashboard_csrf'];

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/project/service_display.php';
require_once __DIR__ . '/project/service_authorization.php';
require_once __DIR__ . '/project/completion_certificate.php';
require_once __DIR__ . '/scheduling_settings.php';
require_once __DIR__ . '/mileage_schema.php';

ensureMileageVehicleSchema($pdo);

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
$scheduleQueryError = null;
$rawJobs = [];
$setScheduleQueryError = static function (array $errorInfo) use (&$scheduleQueryError): void {
    $scheduleQueryError = [
        'sqlstate' => (string) ($errorInfo[0] ?? 'N/A'),
        'code' => isset($errorInfo[1]) && $errorInfo[1] !== null ? (string) $errorInfo[1] : 'N/A',
        'message' => (string) ($errorInfo[2] ?? 'Unknown database error.'),
    ];

    error_log(sprintf(
        'technician-dashboard schedule query failed [SQLSTATE %s] [Code %s] %s',
        $scheduleQueryError['sqlstate'],
        $scheduleQueryError['code'],
        $scheduleQueryError['message']
    ));
};

try {
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
            sr.laser_brand,
            sr.laser_model,
            sr.laser_watts,
            sr.laser_age,
            sr.problem_summary,
            sr.problem,
            sr.technician_notes,
            sr.services,
            sr.service_speed,
            sr.speed,
            sr.service_total,
            sr.travel_fee,
            sr.grand_total,
            sr.preferred_date_start,
            sr.preferred_date_end,
            sr.destination_street,
            sr.destination_city,
            sr.destination_state,
            sr.destination_zip,
            sr.task_contact,
            COALESCE(c.first_name, '') AS first_name,
            COALESCE(c.last_name,  '') AS last_name,
            COALESCE(c.phone,  '') AS phone,
            COALESCE(c.email,  '') AS email,
            COALESCE(c.company,'') AS company,
            COALESCE(c.address, sr.destination_street) AS address,
            COALESCE(c.city,    sr.destination_city)   AS city,
            COALESCE(c.state,   sr.destination_state)  AS state,
            COALESCE(c.zip,     sr.destination_zip)    AS zip
        FROM scheduled_clusters sc
        JOIN scheduled_cluster_jobs scj ON scj.scheduled_cluster_id = sc.id
        JOIN service_requests sr ON sr.id = scj.service_request_id
        LEFT JOIN customers c ON c.id = sr.customer_id
        WHERE sc.scheduled_date = :date
        ORDER BY
            FIELD(LOWER(sr.priority_level), 'emergency', 'vip', 'standard'),
            sc.cluster_label ASC,
            scj.time_window_start ASC
    ");

    if ($scheduledJobsStmt === false) {
        $setScheduleQueryError($pdo->errorInfo());
    } elseif (!$scheduledJobsStmt->execute([
        ':date' => $dateKey,
    ])) {
        $setScheduleQueryError($scheduledJobsStmt->errorInfo());
    } else {
        $rawJobs = $scheduledJobsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $setScheduleQueryError(
        ($e instanceof PDOException && !empty($e->errorInfo))
            ? $e->errorInfo
            : [(string) $e->getCode(), null, $e->getMessage()]
    );
}

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
$jobIdsForAuthorizations = !empty($rawJobs) ? array_map('intval', array_column($rawJobs, 'service_request_id')) : [];
$serviceAuthorizations   = [];
$completionCertificates  = [];
try {
    $serviceAuthorizations = serviceAuthorizationFetchLatestByJobIds($pdo, $jobIdsForAuthorizations);
    $completionCertificates = completionCertificateFetchLatestByJobIds($pdo, $jobIdsForAuthorizations);
} catch (Throwable $e) {
    $serviceAuthorizations = [];
    $completionCertificates = [];
}

// ── Load scheduling settings (provides shop_address for Returning Home card) ─
$schedSettings = getSchedulingSettings($pdo);
$shopAddress   = $schedSettings['shop_address'];
$homeAddress   = $schedSettings['home_address'];
$activeVehicles = [];
$defaultVehicleId = null;
try {
    $vehicleStmt = $pdo->query("
        SELECT id, name, year, make, model, license_plate, is_default
        FROM vehicles
        WHERE is_active = 1
        ORDER BY is_default DESC, name ASC, id ASC
    ");
    $activeVehicles = $vehicleStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($activeVehicles as $vehicle) {
        if ((int) ($vehicle['is_default'] ?? 0) === 1) {
            $defaultVehicleId = (int) $vehicle['id'];
            break;
        }
    }
} catch (Throwable $e) {
    $activeVehicles = [];
}
$hasActiveVehicles = $activeVehicles !== [];

// ── Load trip states for the selected date ───────────────────────────────────
// Keyed by service_request_id; allows the UI to restore button states after
// a logout/reload without losing "on my way" or "arrived" progress.
// service_request_id = 0 is the reserved sentinel for the return-home trip.
$tripStates = [];
try {
    $jobIds       = !empty($rawJobs) ? array_map('intval', array_column($rawJobs, 'service_request_id')) : [];
    $allIds       = array_merge([0], $jobIds); // always include the return-home sentinel
    $placeholders = implode(',', array_fill(0, count($allIds), '?'));
    $tsStmt       = $pdo->prepare(
        "SELECT service_request_id, status, start_time, end_time, total_miles, start_mileage
           FROM mileage_logs
          WHERE service_request_id IN ($placeholders)
            AND trip_date = ?
          ORDER BY id DESC"
    );
    $tsStmt->execute(array_merge($allIds, [$dateKey]));
    foreach ($tsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int) $row['service_request_id'];
        if (!isset($tripStates[$sid])) {   // keep only the most-recent record
            $tripStates[$sid] = [
                'status'        => $row['status'],
                'start_time'    => $row['start_time'],
                'end_time'      => $row['end_time'],
                'total_miles'   => $row['total_miles'],
                'start_mileage' => $row['start_mileage'],
            ];
        }
    }
} catch (PDOException $e) {
    // mileage_logs table not yet created — states default to empty.
}

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

function techDashFormatBookingServiceAddress(array $job): string
{
    return implode(', ', array_filter([
        trim((string) ($job['destination_street'] ?? '')),
        trim((string) ($job['destination_city'] ?? '')),
        trim((string) ($job['destination_state'] ?? '')),
        trim((string) ($job['destination_zip'] ?? '')),
    ], static fn (string $part): bool => $part !== ''));
}

function techDashFormatServices($services): string
{
    global $pdo;

    if ($services === null) {
        return '';
    }

    $raw = trim((string) $services);
    if ($raw === '') {
        return '';
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        // Numeric service IDs are resolved to names via the shared display
        // helper (cached per request); legacy name strings pass through.
        return formatServicesListForDisplay($pdo, $raw);
    }

    return $raw;
}

function techDashBookingDetailEntries(array $job): array
{
    $preferredDates = implode(' – ', array_filter([
        trim((string) ($job['preferred_date_start'] ?? '')),
        trim((string) ($job['preferred_date_end'] ?? '')),
    ], static fn (string $date): bool => $date !== ''));

    $details = [
        'Laser brand' => $job['laser_brand'] ?? '',
        'Laser model' => $job['laser_model'] ?? '',
        'Laser watts' => $job['laser_watts'] ?? '',
        'Laser age' => $job['laser_age'] ?? '',
        'Services' => techDashFormatServices($job['services'] ?? null),
        'Service speed' => $job['service_speed'] ?? '',
        'Service total' => $job['service_total'] ?? '',
        'Travel fee' => $job['travel_fee'] ?? '',
        'Grand total' => $job['grand_total'] ?? '',
        'Priority' => $job['priority_level'] ?? '',
        'Preferred dates' => $preferredDates,
        'Service address' => techDashFormatBookingServiceAddress($job),
    ];

    $entries = [];
    foreach ($details as $label => $value) {
        $text = trim((string) $value);
        if ($text !== '') {
            $entries[] = [$label, $text];
        }
    }

    return $entries;
}

function techDashWazeUrl(array $job): string
{
    $addr = techDashFormatAddress($job);
    return 'https://waze.com/ul?q=' . rawurlencode($addr) . '&navigate=yes';
}

function techDashGoogleMapsUrl(array $job): string
{
    $addr = techDashFormatAddress($job);
    return 'https://maps.google.com/?q=' . rawurlencode($addr);
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
<?php
$pageTitle       = 'Technician Dashboard | Ghost Laser';
$pageDescription = 'Ghost Laser technician daily job dashboard.';
$pwaHead         = <<<'HTML'
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#09090b">
    <link rel="apple-touch-icon" href="/ghost-logo-250x250.png">
    <link rel="icon" type="image/png" sizes="250x250" href="/ghost-logo-250x250.png">
    <link rel="manifest" href="/manifest.json">
HTML;
$bodyClass       = 'hero-grid';
$extraHead       = <<<'HTML'
    <style>
        :root {
            --dash-bg: #09090b;
            --dash-border: rgba(39, 39, 42, 0.8);
            --dash-border-strong: rgba(6, 182, 212, 0.38);
            --dash-text: #f4f4f5;
            --dash-muted: #a1a1aa;
            --dash-accent: #22d3ee;
        }

        body { -webkit-tap-highlight-color: transparent; }

        .dashboard-shell {
            position: relative;
        }

        .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }

        .back-link {
            color: #a1a1aa !important;
        }

        .back-link:hover {
            color: #ffffff !important;
        }

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
            background: rgba(103, 232, 249, 0.1);
            border: 1px solid rgba(103, 232, 249, 0.25);
            color: var(--dash-accent);
        }

        .job-card {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--dash-border);
            background: linear-gradient(180deg, rgba(24, 24, 27, 0.88), rgba(9, 9, 11, 0.94));
            border-radius: 0.875rem;
            padding: 1rem;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .job-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(103, 232, 249, 0.06), transparent 42%);
            pointer-events: none;
        }
        .job-card > * { position: relative; z-index: 1; }
        .job-card:active {
            border-color: rgba(103, 232, 249, 0.4);
            box-shadow: 0 0 20px rgba(103, 232, 249, 0.08);
        }

        .cluster-heading {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--dash-accent);
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--dash-border);
            margin-bottom: 0.75rem;
        }

        .address-text {
            display: inline-flex;
            align-items: flex-start;
            gap: 0.3rem;
            color: #a1a1aa;
            font-size: 0.875rem;
            word-break: break-word;
        }

        .phone-btns {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            margin-top: 0.45rem;
        }
        .phone-link, .sms-link, .vcf-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.875rem;
            font-weight: 700;
            text-decoration: none;
            padding: 0.25rem 0.6rem;
            border-radius: 0.4rem;
            border: 1px solid transparent;
            background: transparent;
            cursor: pointer;
            font-family: inherit;
            -webkit-tap-highlight-color: transparent;
            transition: background 0.15s, transform 0.1s;
            white-space: nowrap;
        }
        .phone-link {
            color: #4ade80;
            background: rgba(74, 222, 128, 0.22);
            border: 1px solid rgba(74, 222, 128, 0.65);
        }
        .phone-link:active { transform: scale(0.96); background: rgba(74, 222, 128, 0.35); }
        .sms-link {
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.22);
            border: 1px solid rgba(56, 189, 248, 0.65);
        }
        .sms-link:active { transform: scale(0.96); background: rgba(56, 189, 248, 0.35); }
        .sms-link:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .vcf-link {
            color: #c4b5fd;
            background: rgba(167, 139, 250, 0.22);
            border: 1px solid rgba(167, 139, 250, 0.65);
        }
        .vcf-link:active { transform: scale(0.96); background: rgba(167, 139, 250, 0.35); }
        .eta-status {
            font-size: 0.75rem;
            color: var(--dash-muted);
            margin-top: 0.35rem;
            min-height: 1rem;
        }
        .eta-status.ok  { color: #86efac; }
        .eta-status.err { color: #fca5a5; }

        .nav-btns {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.4rem;
        }
        .nav-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.35rem 0.75rem;
            cursor: pointer;
            text-decoration: none;
            transition: opacity 0.15s, transform 0.1s;
            -webkit-tap-highlight-color: transparent;
            white-space: nowrap;
        }
        .nav-btn:active { transform: scale(0.96); }
        .btn-waze {
            background: rgba(0, 190, 240, 0.28);
            border: 1px solid rgba(0, 190, 240, 0.75);
            color: #22d3ee;
        }
        .btn-gmaps {
            background: rgba(52, 211, 153, 0.28);
            border: 1px solid rgba(52, 211, 153, 0.75);
            color: #34d399;
        }

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

        .authorize-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 6.25rem;
            padding: 0.42rem 0.8rem;
            border-radius: 9999px;
            border: 1px solid rgba(34, 211, 238, 0.75);
            background: rgba(34, 211, 238, 0.16);
            color: #67e8f9;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            cursor: pointer;
            transition: transform 0.1s, background 0.15s, border-color 0.15s;
            -webkit-tap-highlight-color: transparent;
        }
        .authorize-btn:active { transform: scale(0.96); }
        .authorize-btn:hover {
            background: rgba(34, 211, 238, 0.24);
            border-color: rgba(103, 232, 249, 0.95);
        }

        .authorization-status {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem 0.75rem;
            align-items: center;
            margin-top: 0.7rem;
            font-size: 0.78rem;
            color: #a1a1aa;
        }
        .authorization-status.is-signed { color: #86efac; }
        .authorization-download {
            color: #67e8f9;
            font-weight: 700;
            text-decoration: none;
        }
        .authorization-download:hover { color: #a5f3fc; }

        .btn-on-way {
            background: rgba(103, 232, 249, 0.25);
            border: 1px solid rgba(103, 232, 249, 0.75);
            color: #22d3ee;
        }
        .btn-on-way.active {
            background: rgba(103, 232, 249, 0.42);
            border-color: rgba(103, 232, 249, 0.95);
        }

        .btn-arrived {
            background: rgba(34,197,94,0.25);
            border: 1px solid rgba(34,197,94,0.75);
            color: #4ade80;
        }
        .btn-arrived.active {
            background: rgba(34,197,94,0.42);
            border-color: rgba(34,197,94,0.95);
        }

        .mileage-status {
            font-size: 0.7rem;
            color: var(--dash-muted);
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
            border: 1px solid rgba(103, 232, 249, 0.55);
            background: rgba(103, 232, 249, 0.14);
            color: #22d3ee;
            font-size: 0.875rem;
            font-weight: 700;
            text-decoration: none;
            transition: border-color 0.15s, background 0.15s;
            -webkit-tap-highlight-color: transparent;
        }
        .nav-btn:active {
            border-color: rgba(103, 232, 249, 0.85);
            background: rgba(103, 232, 249, 0.25);
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
            background: rgba(103, 232, 249, 0.12);
            border: 1px solid rgba(103, 232, 249, 0.3);
            color: var(--dash-accent);
        }

        .time-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: rgba(24, 24, 27, 0.8);
            border: 1px solid var(--dash-border);
            border-radius: 0.5rem;
            padding: 0.2rem 0.55rem;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--dash-muted);
            white-space: nowrap;
        }

        @media (max-width: 480px) {
            .address-text { font-size: 0.82rem; }
        }

        /* ── Mileage Entry Modal ──────────────────────────────────────────── */
        .mileage-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0, 0, 0, 0.97);
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
        .mileage-modal.open { display: flex; }

        .service-auth-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 10000;
            padding: 0.5rem;
            background: rgba(0, 0, 0, 0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
        .service-auth-modal.open { display: block; }
        .service-auth-modal-inner {
            height: calc(100dvh - 1rem);
            width: min(100%, 42rem);
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            border-radius: 1.1rem;
            border: 1px solid rgba(34, 211, 238, 0.24);
            background: linear-gradient(180deg, rgba(12, 14, 18, 0.98), rgba(5, 7, 9, 0.98));
            box-shadow: 0 0 0 1px rgba(34, 211, 238, 0.06), 0 24px 70px rgba(0, 0, 0, 0.45);
            overflow: hidden;
        }
        .service-auth-modal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 1rem 0.75rem;
        }
        .service-auth-modal-kicker {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(103, 232, 249, 0.72);
        }
        .service-auth-modal-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #f4f4f5;
            margin-top: 0.35rem;
        }
        .service-auth-close {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 9999px;
            border: 1px solid rgba(113, 113, 122, 0.55);
            background: rgba(39, 39, 42, 0.5);
            color: #d4d4d8;
            font-size: 1.35rem;
            line-height: 1;
            cursor: pointer;
        }
        .service-auth-summary {
            margin: 0 1rem;
            padding: 0.85rem 0.95rem;
            border-radius: 0.85rem;
            background: rgba(34, 211, 238, 0.09);
            border: 1px solid rgba(34, 211, 238, 0.18);
            color: #e4e4e7;
            font-size: 0.86rem;
            line-height: 1.4;
        }
        .service-auth-layout {
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            padding: 0.9rem 1rem 1rem;
            gap: 0.8rem;
        }
        .service-auth-contract {
            flex: 0 0 auto;
            overflow-y: auto;
            border-radius: 0.9rem;
            border: 1px solid rgba(63, 63, 70, 0.7);
            background: rgba(9, 9, 11, 0.68);
            padding: 1rem;
            color: #e4e4e7;
        }
        .service-auth-contract h3 {
            margin: 0 0 0.6rem;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #67e8f9;
        }
        .service-auth-contract p,
        .service-auth-contract li {
            font-size: 0.88rem;
            line-height: 1.55;
            color: #e4e4e7;
        }
        .service-auth-contract p { white-space: pre-line; }
        .service-auth-contract ol {
            margin: 0;
            padding-left: 1.15rem;
            display: grid;
            gap: 0.7rem;
        }
        .service-auth-signature-panel {
            flex: none;
            padding: 0.95rem;
            border-radius: 0.9rem;
            border: 1px solid rgba(63, 63, 70, 0.78);
            background: rgba(9, 9, 11, 0.9);
        }
        .service-auth-signature-copy {
            font-size: 0.78rem;
            font-weight: 700;
            color: #f4f4f5;
        }
        .service-auth-meta {
            margin-top: 0.3rem;
            font-size: 0.75rem;
            color: #a1a1aa;
        }
        .service-auth-canvas {
            display: block;
            width: 100%;
            height: 11rem;
            margin-top: 0.8rem;
            border-radius: 0.8rem;
            border: 1px solid rgba(103, 232, 249, 0.24);
            background: #ffffff;
            touch-action: none;
        }
        .service-auth-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.7rem;
            margin-top: 0.8rem;
        }
        .service-auth-primary-actions {
            display: flex;
            gap: 0.7rem;
        }
        .service-auth-secondary,
        .service-auth-primary {
            min-height: 2.85rem;
            padding: 0.65rem 1rem;
            border-radius: 0.8rem;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
        }
        .service-auth-secondary {
            border: 1px solid rgba(113, 113, 122, 0.75);
            background: rgba(39, 39, 42, 0.7);
            color: #e4e4e7;
        }
        .service-auth-primary {
            border: 1px solid rgba(34, 211, 238, 0.85);
            background: linear-gradient(135deg, rgba(34, 211, 238, 0.48), rgba(6, 182, 212, 0.28));
            color: #ffffff;
            min-width: 6rem;
        }
        .service-auth-secondary:disabled,
        .service-auth-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .service-auth-status {
            min-height: 1rem;
            margin-top: 0.75rem;
            font-size: 0.78rem;
            color: #a1a1aa;
        }
        .service-auth-status.ok { color: #86efac; }
        .service-auth-status.err { color: #fca5a5; }

        .job-note-row,
        .job-note-edit {
            width: 100%;
            margin-top: 0.75rem;
            padding: 0.8rem 0.9rem;
            border-radius: 0.9rem;
            border: 1px solid rgba(63, 63, 70, 0.72);
            background: rgba(9, 9, 11, 0.58);
        }
        .job-note-row-label,
        .job-note-edit-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #71717a;
        }
        .job-note-row-value,
        .job-note-edit-value {
            margin-top: 0.45rem;
            font-size: 0.86rem;
            line-height: 1.5;
            color: #e4e4e7;
            white-space: pre-wrap;
        }
        .job-note-edit {
            display: block;
            text-align: left;
            cursor: pointer;
            transition: border-color 0.16s ease, background 0.16s ease;
        }
        .job-note-edit:hover,
        .job-note-edit:focus-visible {
            border-color: rgba(103, 232, 249, 0.4);
            background: rgba(9, 9, 11, 0.82);
            outline: none;
        }
        .job-note-edit-hint {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: #a1a1aa;
            letter-spacing: 0.04em;
            text-transform: none;
        }
        .job-note-edit-icon {
            width: 0.9rem;
            height: 0.9rem;
            color: rgba(161, 161, 170, 0.88);
            flex-shrink: 0;
        }
        .job-note-edit-value.is-placeholder {
            color: #a1a1aa;
        }
        .job-note-row-value.is-placeholder {
            color: #a1a1aa;
        }

        .tech-notes-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 10010;
            padding: 0.85rem;
            background: rgba(0, 0, 0, 0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            align-items: center;
            justify-content: center;
        }
        .tech-notes-modal.open { display: flex; }
        .tech-notes-modal-inner {
            width: min(100%, 34rem);
            border-radius: 1rem;
            border: 1px solid rgba(34, 211, 238, 0.24);
            background: linear-gradient(180deg, rgba(12, 14, 18, 0.98), rgba(5, 7, 9, 0.98));
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.45);
            overflow: hidden;
        }
        .tech-notes-modal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 1rem 0.75rem;
        }
        .tech-notes-modal-kicker {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(103, 232, 249, 0.72);
        }
        .tech-notes-modal-title {
            margin-top: 0.35rem;
            font-size: 1.05rem;
            font-weight: 700;
            color: #f4f4f5;
        }
        .tech-notes-modal-body {
            padding: 0 1rem 1rem;
        }
        .tech-notes-textarea {
            width: 100%;
            min-height: 16rem;
            resize: vertical;
            border-radius: 0.9rem;
            border: 1px solid rgba(63, 63, 70, 0.78);
            background: rgba(9, 9, 11, 0.9);
            color: #f4f4f5;
            padding: 0.95rem 1rem;
            font-size: 0.92rem;
            line-height: 1.5;
            outline: none;
        }
        .tech-notes-textarea:focus {
            border-color: rgba(103, 232, 249, 0.55);
            box-shadow: 0 0 0 1px rgba(103, 232, 249, 0.16);
        }
        .tech-notes-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.7rem;
            margin-top: 0.85rem;
        }
        .tech-notes-status {
            min-height: 1rem;
            margin-top: 0.75rem;
            font-size: 0.78rem;
            color: #a1a1aa;
        }
        .tech-notes-status.err { color: #fca5a5; }

        .mileage-modal-inner {
            width: 100%;
            max-width: 360px;
            margin: 0 1rem;
            padding: 2rem 1.5rem 1.75rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
            background: linear-gradient(160deg, rgba(9,9,11,0.99), rgba(15,15,18,0.99));
            border: 1px solid rgba(6,182,212,0.28);
            border-radius: 1.25rem;
            box-shadow:
                0 0 0 1px rgba(6,182,212,0.08),
                0 0 60px rgba(6,182,212,0.1),
                0 0 120px rgba(6,182,212,0.04),
                inset 0 0 40px rgba(0,0,0,0.6);
        }

        .mileage-modal-header { text-align: center; }
        .mileage-modal-title {
            font-size: 0.6rem;
            font-weight: 700;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: rgba(6,182,212,0.65);
        }
        .mileage-modal-sub {
            font-size: 0.68rem;
            color: #52525b;
            margin-top: 0.3rem;
            letter-spacing: 0.06em;
        }

        .nixie-display {
            display: flex;
            gap: 0.2rem;
            padding: 0.85rem 1.1rem;
            background: #040406;
            border: 1px solid rgba(6,182,212,0.18);
            border-radius: 0.75rem;
            box-shadow:
                inset 0 3px 10px rgba(0,0,0,0.85),
                0 0 20px rgba(249,115,22,0.04);
        }
        .nixie-digit {
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 3rem;
            font-weight: 700;
            width: 2.25rem;
            color: #f97316;
            text-shadow:
                0 0 6px rgba(249,115,22,0.95),
                0 0 18px rgba(249,115,22,0.55),
                0 0 38px rgba(249,115,22,0.28);
            line-height: 1;
            letter-spacing: -0.02em;
            transition: color 0.1s, text-shadow 0.1s;
        }
        .nixie-digit.dim {
            color: rgba(249,115,22,0.18);
            text-shadow: none;
        }

        .nixie-error {
            font-size: 0.68rem;
            color: #fca5a5;
            min-height: 1rem;
            text-align: center;
            letter-spacing: 0.04em;
        }

        .keypad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.55rem;
            width: 100%;
        }
        .keypad-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 3.4rem;
            border-radius: 0.625rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: transform 0.08s, background 0.1s, border-color 0.1s;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
        }
        .keypad-btn:active { transform: scale(0.91); }

        .keypad-num {
            font-size: 1.3rem;
            background: rgba(63,63,70,0.85);
            border: 1px solid rgba(113,113,122,0.9);
            color: #ffffff;
        }
        .keypad-num:hover {
            background: rgba(82,82,91,0.9);
            border-color: rgba(6,182,212,0.6);
        }
        .keypad-back {
            font-size: 1.15rem;
            background: rgba(63,63,70,0.85);
            border: 1px solid rgba(113,113,122,0.9);
            color: #d4d4d8;
        }
        .keypad-back:hover {
            background: rgba(82,82,91,0.9);
            border-color: rgba(6,182,212,0.6);
        }
        .keypad-clear {
            font-size: 0.72rem;
            letter-spacing: 0.1em;
            background: rgba(63,63,70,0.85);
            border: 1px solid rgba(113,113,122,0.9);
            color: #d4d4d8;
        }
        .keypad-clear:hover {
            border-color: rgba(239,68,68,0.7);
            color: #fca5a5;
        }

        .keypad-actions {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 0.55rem;
            width: 100%;
        }
        .keypad-cancel {
            height: 3rem;
            background: rgba(63,63,70,0.6);
            border: 1px solid rgba(113,113,122,0.75);
            color: #a1a1aa;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.07em;
            border-radius: 0.625rem;
            cursor: pointer;
            transition: color 0.1s, border-color 0.1s, transform 0.08s;
            -webkit-tap-highlight-color: transparent;
        }
        .keypad-cancel:hover { color: #d4d4d8; border-color: rgba(161,161,170,0.85); }
        .keypad-cancel:active { transform: scale(0.96); }

        .keypad-confirm {
            height: 3rem;
            background: linear-gradient(135deg, rgba(6,182,212,0.42), rgba(6,182,212,0.28));
            border: 1px solid rgba(6,182,212,0.85);
            color: #ffffff;
            font-size: 0.88rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            border-radius: 0.625rem;
            cursor: pointer;
            transition: all 0.1s;
            box-shadow: 0 0 14px rgba(6,182,212,0.12);
            -webkit-tap-highlight-color: transparent;
        }
        .keypad-confirm:hover {
            background: linear-gradient(135deg, rgba(6,182,212,0.58), rgba(6,182,212,0.40));
            box-shadow: 0 0 26px rgba(6,182,212,0.45);
        }
        .keypad-confirm:active { transform: scale(0.97); }
        .keypad-confirm:disabled { opacity: 0.35; cursor: not-allowed; }

        @keyframes nixie-shake {
            0%, 100% { transform: translateX(0); }
            20%       { transform: translateX(-6px); }
            40%       { transform: translateX(6px); }
            60%       { transform: translateX(-4px); }
            80%       { transform: translateX(4px); }
        }
        .nixie-display.shake { animation: nixie-shake 0.32s ease; }

        @media (max-width: 480px) {
            .service-auth-actions,
            .service-auth-primary-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .service-auth-primary,
            .service-auth-secondary,
            .authorize-btn {
                width: 100%;
            }
        }
    </style>
HTML;
$headerRight     = <<<'HTML'
    <a href="dashboard.php" class="back-link text-sm transition-colors">&larr; Back to Dashboard</a>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

<!-- ── Main ─────────────────────────────────────────────────────────────── -->
<main class="dashboard-shell min-h-screen max-w-xl mx-auto px-4 pb-12 pt-24">

    <!-- Ambient glow -->
    <div class="fixed inset-0 flex items-center justify-center pointer-events-none overflow-hidden -z-10">
        <div class="w-[600px] h-[600px] rounded-full bg-cyan-500/5 blur-3xl"></div>
    </div>

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

    <?php if (!$hasActiveVehicles): ?>
        <div class="mb-5 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
            Mileage logging is disabled until an active vehicle is added in Vehicle Settings.
        </div>
    <?php endif; ?>

    <?php if ($scheduleQueryError !== null): ?>
        <div class="mb-5 rounded-xl border border-red-500/70 bg-red-500/15 px-4 py-3 text-sm text-red-100">
            <div class="font-semibold">Schedule query failed.</div>
            <div class="mt-1">SQLSTATE: <?= htmlspecialchars($scheduleQueryError['sqlstate'], ENT_QUOTES, 'UTF-8') ?></div>
            <div>Error code: <?= htmlspecialchars($scheduleQueryError['code'], ENT_QUOTES, 'UTF-8') ?></div>
            <div>Message: <?= htmlspecialchars($scheduleQueryError['message'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <?php
    $serviceAgreementTerms = [
        'The customer authorizes Ghost Laser to inspect, diagnose, and perform the approved service described in the Scope of Work.',
        'The customer agrees to pay for all parts, labor, travel, and related service charges required to complete the authorized work.',
        'The customer acknowledges that the equipment may have pre-existing wear, cosmetic issues, or damage that is unrelated to the authorized service.',
        'The customer waives claims arising solely from normal wear, hidden defects, or conditions discovered during service that are not caused by Ghost Laser negligence.',
    ];
    $completionCertificateTerms = completionCertificateClauses();
    ?>
    <?php if (empty($clusters)): ?>
        <!-- Empty state -->
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <div class="w-14 h-14 rounded-2xl border border-zinc-700/50 bg-zinc-800/40 flex items-center justify-center mb-4">
                <svg class="w-7 h-7 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10m-11 9h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v11a2 2 0 002 2z"/>
                </svg>
            </div>
            <p class="text-zinc-200 font-semibold text-base">No jobs scheduled</p>
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
                        $hasAddress  = $fullAddress !== 'Address unavailable';
                        $wazeUrl     = techDashWazeUrl($job);
                        $gmapsUrl    = techDashGoogleMapsUrl($job);
                        $timeWindow  = techDashTimeWindow($job['time_window_start'] ?? null, $job['time_window_end'] ?? null);
                        $bookingDetailEntries = techDashBookingDetailEntries($job);
                        $serviceRequestId = (int) ($job['service_request_id'] ?? 0);
                        $authorizationScope = serviceAuthorizationBuildScopeOfWork($pdo, $job);
                        $existingAuthorization = $serviceAuthorizations[(int) $job['service_request_id']] ?? null;
                        $existingCompletionCertificate = $completionCertificates[(int) $job['service_request_id']] ?? null;
                        $completionCertificateScope = trim((string) ($existingCompletionCertificate['scope_of_work'] ?? '')) !== ''
                            ? (string) $existingCompletionCertificate['scope_of_work']
                            : $authorizationScope;
                        $customerProblem = str_replace(["\r\n", "\r"], "\n", serviceAuthorizationPrimaryProblemText($job));
                        $technicianNotes = str_replace(["\r\n", "\r"], "\n", (string) ($job['technician_notes'] ?? ''));
                        $customerName = trim((string) ($job['first_name'] ?? '') . ' ' . (string) ($job['last_name'] ?? ''));
                        if ($customerName === '') {
                            // Fall back to task_contact (company or contact name) for task-type rows.
                            $customerName = trim((string) ($job['task_contact'] ?? ''));
                        }
                        if ($customerName === '') {
                            $customerName = 'Internal Task';
                        }
                        $technicianNotesLabelTarget = $customerName !== '' ? $customerName : ('request #' . $serviceRequestId);
                        ?>
                        <div class="job-card">
                            <!-- Row 1: stop number + priority + time -->
                            <div class="flex items-center justify-between gap-2 mb-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="flex-shrink-0 w-6 h-6 rounded-full border border-zinc-600/60 bg-zinc-800/60 flex items-center justify-center text-xs font-bold text-zinc-300">
                                        <?= $jobIndex + 1 ?>
                                    </span>
                                    <?= techDashPriorityBadge($job['priority_level'] ?? 'standard') ?>
                                </div>
                                <span class="time-pill">
                                    <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <?= htmlspecialchars($timeWindow, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>

                            <!-- Row 2: customer name + phone -->
                            <div class="text-sm font-semibold text-zinc-100 mb-1.5">
                                <?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <?php
                                $rawPhone = trim((string) ($job['phone'] ?? ''));
                                // Strip non-digit characters for the tel: href
                                $phoneDigits = preg_replace('/\D/', '', $rawPhone);
                                // Format for display: (555) 123-4567
                                if (strlen($phoneDigits) === 10) {
                                    $phoneDisplay = '(' . substr($phoneDigits, 0, 3) . ') ' . substr($phoneDigits, 3, 3) . '-' . substr($phoneDigits, 6);
                                } elseif (strlen($phoneDigits) === 11 && $phoneDigits[0] === '1') {
                                    $phoneDisplay = '+1 (' . substr($phoneDigits, 1, 3) . ') ' . substr($phoneDigits, 4, 3) . '-' . substr($phoneDigits, 7);
                                } else {
                                    $phoneDisplay = $rawPhone;
                                }
                            ?>
                            <div class="phone-btns mb-1">
                                <?php if ($phoneDigits !== ''): ?>
                                <a href="tel:+<?= htmlspecialchars($phoneDigits, ENT_QUOTES, 'UTF-8') ?>" class="phone-link">
                                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                    <?= htmlspecialchars($phoneDisplay, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                                <button type="button" class="sms-link"
                                    onclick="sendEtaSms(this)"
                                    data-phone="<?= htmlspecialchars($phoneDigits, ENT_QUOTES, 'UTF-8') ?>"
                                    data-destination="<?= htmlspecialchars($fullAddress, ENT_QUOTES, 'UTF-8') ?>"
                                    data-job-id="<?= (int) $job['service_request_id'] ?>"
                                    <?= $hasAddress ? '' : 'disabled title="Customer address unavailable"' ?>
                                >
                                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3v-3z"/></svg>
                                    SMS ETA
                                </button>
                                <button type="button" class="vcf-link"
                                    onclick="saveContact(this)"
                                    data-name="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
                                    data-phone="+<?= htmlspecialchars($phoneDigits, ENT_QUOTES, 'UTF-8') ?>"
                                    data-email="<?= htmlspecialchars(trim((string)($job['email'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                                    data-company="<?= htmlspecialchars(trim((string)($job['company'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    Save Contact
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="eta-status" data-eta-job="<?= (int) $job['service_request_id'] ?>"></div>

                            <!-- Row 3: address + navigation buttons -->
                            <div class="mb-2">
                                <div class="address-text">
                                    <svg class="w-3.5 h-3.5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    <?= htmlspecialchars($fullAddress, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="nav-btns">
                                    <a href="<?= htmlspecialchars($wazeUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="nav-btn btn-waze">
                                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="M20.54 7.28C19.54 3.1 15.82 0 11.36 0 6.17 0 1.96 4.21 1.96 9.4c0 2.78 1.22 5.28 3.16 7.01-.06.34-.31 1.37-.84 1.9-.1.1-.07.27.06.33.85.36 3.46.95 5.87-1.13.76.15 1.54.23 2.35.23.31 0 .62-.01.92-.04 4.16-.37 7.56-3.37 8.24-7.45.15-.91.17-1.36.08-2.97h-.26z"/></svg>
                                        Waze
                                    </a>
                                    <a href="<?= htmlspecialchars($gmapsUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="nav-btn btn-gmaps">
                                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
                                        Google Maps
                                    </a>
                                </div>
                            </div>

                            <!-- Row 4: booking details -->
                            <?php if ($bookingDetailEntries !== []): ?>
                                <div class="mt-2 pt-2 border-t border-zinc-700/40">
                                    <div class="grid gap-2 md:grid-cols-2">
                                        <?php foreach ($bookingDetailEntries as [$label, $value]): ?>
                                            <div class="text-xs text-zinc-300 leading-relaxed">
                                                <span class="text-zinc-500"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>: </span><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (trim($customerProblem) !== ''): ?>
                                <div class="job-note-row">
                                    <div class="job-note-row-label">Customer problem</div>
                                    <div class="job-note-row-value"><?= htmlspecialchars($customerProblem, ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($serviceRequestId > 0): ?>
                                <button
                                    type="button"
                                    class="job-note-edit"
                                    data-tech-notes-job-id="<?= $serviceRequestId ?>"
                                    data-technician-notes-encoded="<?= htmlspecialchars(rawurlencode($technicianNotes), ENT_QUOTES, 'UTF-8') ?>"
                                    aria-haspopup="dialog"
                                    aria-controls="technicianNotesModal"
                                    aria-label="Edit technician notes for <?= htmlspecialchars($technicianNotesLabelTarget, ENT_QUOTES, 'UTF-8') ?>"
                                    title="Edit technician notes"
                                >
                                    <span class="job-note-edit-label">
                                        <span>Technician notes</span>
                                        <span class="job-note-edit-hint">
                                            <svg class="job-note-edit-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536M9 13l6.768-6.768a2.5 2.5 0 113.536 3.536L12.536 16.536A4 4 0 019.707 17.707L7 18l.293-2.707A4 4 0 018.464 12.536z"/></svg>
                                            Edit
                                        </span>
                                    </span>
                                    <span
                                        class="job-note-edit-value<?= trim($technicianNotes) === '' ? ' is-placeholder' : '' ?>"
                                        data-tech-notes-value
                                    ><?= htmlspecialchars(trim($technicianNotes) !== '' ? $technicianNotes : 'Tap to add notes', ENT_QUOTES, 'UTF-8') ?></span>
                                </button>
                            <?php else: ?>
                                <div class="job-note-row">
                                    <div class="job-note-row-label">Technician notes</div>
                                    <div class="job-note-row-value<?= trim($technicianNotes) === '' ? ' is-placeholder' : '' ?>"><?= htmlspecialchars(trim($technicianNotes) !== '' ? $technicianNotes : 'No notes yet', ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            <?php endif; ?>

                            <div class="mt-3 pt-3 border-t border-zinc-700/40">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-[0.68rem] font-semibold uppercase tracking-[0.18em] text-zinc-500">Service Agreement</div>
                                        <div class="mt-1 text-xs text-zinc-400">Customer approval for the listed work before service begins.</div>
                                    </div>
                                    <button
                                        type="button"
                                        class="authorize-btn"
                                        data-authorize-job-id="<?= (int) $job['service_request_id'] ?>"
                                        data-authorize-customer="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
                                        data-authorize-scope="<?= htmlspecialchars($authorizationScope, ENT_QUOTES, 'UTF-8') ?>"
                                        data-authorize-doc-type="service_authorization"
                                    >
                                        Authorize
                                    </button>
                                </div>
                                <div class="authorization-status<?= $existingAuthorization ? ' is-signed' : '' ?>" data-auth-job="<?= (int) $job['service_request_id'] ?>">
                                    <?php if ($existingAuthorization): ?>
                                        <span>Signed <?= htmlspecialchars(serviceAuthorizationFormatSignedAtDisplay((string) $existingAuthorization['signed_at']), ENT_QUOTES, 'UTF-8') ?></span>
                                        <a
                                            href="/api/service-authorization-pdf.php?authorization_id=<?= (int) $existingAuthorization['id'] ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="authorization-download"
                                        >Download PDF</a>
                                    <?php else: ?>
                                        <span>Not signed yet.</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mt-3 pt-3 border-t border-zinc-700/40">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-[0.68rem] font-semibold uppercase tracking-[0.18em] text-zinc-500">Completion Certificate</div>
                                        <div class="mt-1 text-xs text-zinc-400">Confirms completed work and customer approval of final payment.</div>
                                    </div>
                                    <button
                                        type="button"
                                        class="authorize-btn"
                                        data-authorize-job-id="<?= (int) $job['service_request_id'] ?>"
                                        data-authorize-customer="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
                                        data-authorize-scope="<?= htmlspecialchars($completionCertificateScope, ENT_QUOTES, 'UTF-8') ?>"
                                        data-authorize-doc-type="completion_certificate"
                                    >
                                        Generate completion certificate
                                    </button>
                                </div>
                                <div class="authorization-status<?= $existingCompletionCertificate ? ' is-signed' : '' ?>" data-cert-job="<?= (int) $job['service_request_id'] ?>">
                                    <?php if ($existingCompletionCertificate): ?>
                                        <span>Generated <?= htmlspecialchars(serviceAuthorizationFormatSignedAtDisplay((string) $existingCompletionCertificate['signed_at']), ENT_QUOTES, 'UTF-8') ?></span>
                                        <a
                                            href="/api/completion-certificate-pdf.php?service_request_id=<?= (int) $job['service_request_id'] ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="authorization-download"
                                        >Download PDF</a>
                                    <?php else: ?>
                                        <span>Not generated yet.</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Row 5: mileage tracking buttons -->
                            <div class="mt-3 pt-3 border-t border-zinc-700/40">
                                <div class="flex items-center gap-2">
                                    <button
                                        class="mileage-btn btn-on-way"
                                        data-action="on_my_way"
                                        data-job-id="<?= (int) $job['service_request_id'] ?>"
                                        data-client="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
                                        data-problem="<?= htmlspecialchars(trim((string) ($job['problem'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                                        data-address="<?= htmlspecialchars($fullAddress, ENT_QUOTES, 'UTF-8') ?>"
                                        title="<?= $hasActiveVehicles ? 'Record departure time and GPS coordinates' : 'Set up an active vehicle first in Vehicle Settings' ?>"
                                        <?= $hasActiveVehicles ? '' : 'disabled' ?>
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12l7-7m0 0l7 7m-7-7v14"/></svg>
                                        On My Way
                                    </button>
                                    <button
                                        class="mileage-btn btn-arrived"
                                        data-action="arrived"
                                        data-job-id="<?= (int) $job['service_request_id'] ?>"
                                        data-client="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
                                        data-problem="<?= htmlspecialchars(trim((string) ($job['problem'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                                        title="Record arrival time, GPS coordinates, and ending odometer"
                                        disabled
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        Arrived
                                    </button>
                                    <button type="button" class="sms-link"
                                        onclick="notifyCustomerSms(this)"
                                        data-phone="<?= htmlspecialchars($phoneDigits, ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3v-3z"/></svg>
                                        SMS Arrival
                                    </button>
                                </div>
                                <div class="mileage-status" data-status-job="<?= (int) $job['service_request_id'] ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div id="serviceAuthorizationModal" class="service-auth-modal" role="dialog" aria-modal="true" aria-labelledby="serviceAuthorizationModalTitle">
            <div class="service-auth-modal-inner">
                <div class="service-auth-modal-header">
                    <div>
                        <div id="serviceAuthorizationModalKicker" class="service-auth-modal-kicker">Service Authorization</div>
                        <div id="serviceAuthorizationModalTitle" class="service-auth-modal-title">Authorize Work</div>
                    </div>
                    <button type="button" id="serviceAuthorizationClose" class="service-auth-close" aria-label="Close">&times;</button>
                </div>
                <div id="serviceAuthorizationSummary" class="service-auth-summary">The customer authorizes the technician to perform the listed work described below.</div>
                <div class="service-auth-layout">
                    <div class="service-auth-contract">
                        <h3 id="serviceAuthorizationScopeHeading">Scope of Work</h3>
                        <p id="serviceAuthorizationScope"></p>

                        <h3 id="serviceAuthorizationTermsHeading">Terms</h3>
                        <ol id="serviceAuthorizationTermsList">
                            <?php foreach ($serviceAgreementTerms as $serviceAgreementTerm): ?>
                                <li><?= htmlspecialchars($serviceAgreementTerm, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>

                    <div class="service-auth-signature-panel">
                        <div class="service-auth-signature-copy">Customer signature</div>
                        <div id="serviceAuthorizationMeta" class="service-auth-meta">Draw with a finger, then tap Sign to capture the signature, timestamp, and GPS.</div>
                        <canvas id="serviceAuthorizationCanvas" class="service-auth-canvas"></canvas>
                        <form id="serviceAuthorizationForm">
                            <div class="service-auth-actions">
                                <button type="button" id="serviceAuthorizationClear" class="service-auth-secondary">Clear</button>
                                <div class="service-auth-primary-actions">
                                    <button type="button" id="serviceAuthorizationCancel" class="service-auth-secondary">Cancel</button>
                                    <button type="submit" id="serviceAuthorizationSign" class="service-auth-primary">Sign</button>
                                </div>
                            </div>
                        </form>
                        <div id="serviceAuthorizationStatus" class="service-auth-status"></div>
                    </div>
                </div>
            </div>
        </div>

        <div id="technicianNotesModal" class="tech-notes-modal" role="dialog" aria-modal="true" aria-labelledby="technicianNotesModalTitle">
            <div class="tech-notes-modal-inner">
                <div class="tech-notes-modal-header">
                    <div>
                        <div class="tech-notes-modal-kicker">Technician Notes</div>
                        <div id="technicianNotesModalTitle" class="tech-notes-modal-title">Update notes</div>
                    </div>
                    <button type="button" id="technicianNotesClose" class="service-auth-close" aria-label="Close">&times;</button>
                </div>
                <form id="technicianNotesForm" class="tech-notes-modal-body">
                    <textarea id="technicianNotesTextarea" class="tech-notes-textarea" aria-label="Technician notes" placeholder="Add notes, parts, or scope updates here."></textarea>
                    <div class="tech-notes-actions">
                        <button type="button" id="technicianNotesCancel" class="service-auth-secondary">Cancel</button>
                        <button type="submit" id="technicianNotesSave" class="service-auth-primary">Save</button>
                    </div>
                    <div id="technicianNotesStatus" class="tech-notes-status" role="status" aria-live="polite"></div>
                </form>
            </div>
        </div>

        <!-- ── Returning Home card ──────────────────────────────────────────── -->
        <?php
        $hubWazeUrl  = 'https://waze.com/ul?q=' . rawurlencode($shopAddress) . '&navigate=yes';
        $hubGmapsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($shopAddress);
        ?>
        <div class="mb-7">
            <div class="cluster-heading">End of Day — Returning to Base</div>
            <div class="space-y-3">
                <div class="job-card">
                    <!-- Row 1: home icon + label -->
                    <div class="flex items-center justify-between gap-2 mb-2.5">
                        <div class="flex items-center gap-2">
                            <span class="flex-shrink-0 w-6 h-6 rounded-full border border-zinc-600/60 bg-zinc-800/60 flex items-center justify-center text-xs font-bold text-zinc-300">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                            </span>
                        </div>
                    </div>

                    <!-- Row 2: heading -->
                    <div class="text-sm font-semibold text-zinc-100 mb-1.5">Returning Home</div>

                    <!-- Destination selector (shown only when home address is configured) -->
                    <?php if ($homeAddress !== ''): ?>
                    <div class="mb-2.5">
                        <label class="text-xs text-zinc-400 block mb-1">Return destination</label>
                        <select id="returnDestSelect" class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-white focus:border-cyan-400 focus:outline-none"
                            data-shop-address="<?= htmlspecialchars($shopAddress, ENT_QUOTES, 'UTF-8') ?>"
                            data-home-address="<?= htmlspecialchars($homeAddress, ENT_QUOTES, 'UTF-8') ?>">
                            <option value="shop">Shop — <?= htmlspecialchars($shopAddress, ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="home">Home — <?= htmlspecialchars($homeAddress, ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Row 3: address + navigation buttons -->
                    <div class="mb-2">
                        <div class="address-text">
                            <svg class="w-3.5 h-3.5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span id="returnDestAddress"><?= htmlspecialchars($shopAddress, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="nav-btns">
                            <a id="returnWazeLink" href="<?= htmlspecialchars($hubWazeUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="nav-btn btn-waze">
                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="M20.54 7.28C19.54 3.1 15.82 0 11.36 0 6.17 0 1.96 4.21 1.96 9.4c0 2.78 1.22 5.28 3.16 7.01-.06.34-.31 1.37-.84 1.9-.1.1-.07.27.06.33.85.36 3.46.95 5.87-1.13.76.15 1.54.23 2.35.23.31 0 .62-.01.92-.04 4.16-.37 7.56-3.37 8.24-7.45.15-.91.17-1.36.08-2.97h-.26z"/></svg>
                                Waze
                            </a>
                            <a id="returnGmapsLink" href="<?= htmlspecialchars($hubGmapsUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="nav-btn btn-gmaps">
                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
                                Google Maps
                            </a>
                        </div>
                    </div>

                    <!-- Mileage tracking buttons -->
                    <div class="mt-3 pt-3 border-t border-zinc-700/40">
                        <div class="flex items-center gap-2">
                            <button
                                id="returnOnWayBtn"
                                class="mileage-btn btn-on-way"
                                data-action="on_my_way"
                                data-job-id="0"
                                data-client="Returning Home"
                                data-address="<?= htmlspecialchars($shopAddress, ENT_QUOTES, 'UTF-8') ?>"
                                title="<?= $hasActiveVehicles ? 'Record departure time and GPS coordinates' : 'Set up an active vehicle first in Vehicle Settings' ?>"
                                <?= $hasActiveVehicles ? '' : 'disabled' ?>
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12l7-7m0 0l7 7m-7-7v14"/></svg>
                                On My Way
                            </button>
                            <button
                                class="mileage-btn btn-arrived"
                                data-action="arrived"
                                data-job-id="0"
                                title="Record arrival time, GPS coordinates, and ending odometer"
                                disabled
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                Arrived
                            </button>
                        </div>
                        <div class="mileage-status" data-status-job="0"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 text-center">
            <a href="technician/schedule.php" class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors">
                &larr; Back to Scheduling Dashboard
            </a>
        </div>

    <?php endif; ?>
</main>

<!-- ── Trip state data (for restoring button states after logout/reload) ────── -->
<script>
var TRIP_STATES = <?= json_encode($tripStates, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var HAS_ACTIVE_VEHICLES = <?= $hasActiveVehicles ? 'true' : 'false' ?>;
var DEFAULT_VEHICLE_ID = <?= $defaultVehicleId !== null ? (int) $defaultVehicleId : 'null' ?>;
var SERVICE_AUTH_CSRF = <?= json_encode($technicianDashboardCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var SERVICE_AUTH_TERMS = <?= json_encode($serviceAgreementTerms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var COMPLETION_CERTIFICATE_TERMS = <?= json_encode($completionCertificateTerms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<!-- ── Mileage Entry Modal ───────────────────────────────────────────────── -->
<div id="mileageModal" class="mileage-modal" role="dialog" aria-modal="true" aria-label="Enter truck mileage">
    <div class="mileage-modal-inner">
        <div class="mileage-modal-header">
            <div class="mileage-modal-title">Odometer Reading</div>
            <div class="mileage-modal-sub">Enter current truck mileage before departing</div>
        </div>

        <div id="mileageVehicleWrap" class="mb-3">
            <label for="mileageVehicleSelect" class="block text-xs uppercase tracking-wide text-zinc-400 mb-1">Vehicle</label>
            <select id="mileageVehicleSelect" class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-white focus:border-cyan-400 focus:outline-none">
                <option value="">Select a vehicle</option>
                <?php foreach ($activeVehicles as $vehicle): ?>
                    <?php
                    $vehicleLabel = trim((string) $vehicle['name']);
                    $vehicleYmm = trim((string) trim(($vehicle['year'] ?? '') . ' ' . ($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')));
                    $vehiclePlate = trim((string) ($vehicle['license_plate'] ?? ''));
                    if ($vehicleYmm !== '') {
                        $vehicleLabel .= ($vehicleLabel !== '' ? ' — ' : '') . $vehicleYmm;
                    }
                    if ($vehiclePlate !== '') {
                        $vehicleLabel .= ($vehicleLabel !== '' ? ' — ' : '') . $vehiclePlate;
                    }
                    ?>
                    <option value="<?= (int) $vehicle['id'] ?>" <?= $defaultVehicleId !== null && (int) $defaultVehicleId === (int) $vehicle['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($vehicleLabel !== '' ? $vehicleLabel : ('Vehicle #' . (int) $vehicle['id']), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="nixie-display" id="nixieDisplay">
            <span class="nixie-digit dim" id="nd0">0</span>
            <span class="nixie-digit dim" id="nd1">0</span>
            <span class="nixie-digit dim" id="nd2">0</span>
            <span class="nixie-digit dim" id="nd3">0</span>
            <span class="nixie-digit dim" id="nd4">0</span>
            <span class="nixie-digit dim" id="nd5">0</span>
        </div>

        <div class="nixie-error" id="nixieError"></div>

        <div class="keypad" id="mileageKeypad">
            <button class="keypad-btn keypad-num" data-digit="7">7</button>
            <button class="keypad-btn keypad-num" data-digit="8">8</button>
            <button class="keypad-btn keypad-num" data-digit="9">9</button>
            <button class="keypad-btn keypad-num" data-digit="4">4</button>
            <button class="keypad-btn keypad-num" data-digit="5">5</button>
            <button class="keypad-btn keypad-num" data-digit="6">6</button>
            <button class="keypad-btn keypad-num" data-digit="1">1</button>
            <button class="keypad-btn keypad-num" data-digit="2">2</button>
            <button class="keypad-btn keypad-num" data-digit="3">3</button>
            <button class="keypad-btn keypad-clear" id="keypadClear">CLR</button>
            <button class="keypad-btn keypad-num" data-digit="0">0</button>
            <button class="keypad-btn keypad-back" id="keypadBack">&#x232B;</button>
        </div>

        <div class="keypad-actions">
            <button class="keypad-btn keypad-cancel" id="keypadCancel">Cancel</button>
            <button class="keypad-btn keypad-confirm" id="keypadConfirm">Confirm</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    // Starting odometer per job, used to validate the ending reading client-side.
    var _startMileageByJob = {};
    var authModal = document.getElementById('serviceAuthorizationModal');
    var authKicker = document.getElementById('serviceAuthorizationModalKicker');
    var authTitle = document.getElementById('serviceAuthorizationModalTitle');
    var authSummary = document.getElementById('serviceAuthorizationSummary');
    var authScopeHeading = document.getElementById('serviceAuthorizationScopeHeading');
    var authTermsHeading = document.getElementById('serviceAuthorizationTermsHeading');
    var authTermsList = document.getElementById('serviceAuthorizationTermsList');
    var authScope = document.getElementById('serviceAuthorizationScope');
    var authMeta = document.getElementById('serviceAuthorizationMeta');
    var authStatus = document.getElementById('serviceAuthorizationStatus');
    var authClearBtn = document.getElementById('serviceAuthorizationClear');
    var authCancelBtn = document.getElementById('serviceAuthorizationCancel');
    var authCloseBtn = document.getElementById('serviceAuthorizationClose');
    var authSignBtn = document.getElementById('serviceAuthorizationSign');
    var authForm = document.getElementById('serviceAuthorizationForm');
    var authCanvas = document.getElementById('serviceAuthorizationCanvas');
    var authCtx = authCanvas ? authCanvas.getContext('2d') : null;
    var authState = {
        btn: null,
        jobId: 0,
        documentType: 'service_authorization',
        dirty: false,
        drawing: false,
        pointerId: null,
        submitting: false
    };
    var techNotesModal = document.getElementById('technicianNotesModal');
    var techNotesForm = document.getElementById('technicianNotesForm');
    var techNotesTextarea = document.getElementById('technicianNotesTextarea');
    var techNotesStatus = document.getElementById('technicianNotesStatus');
    var techNotesCancelBtn = document.getElementById('technicianNotesCancel');
    var techNotesCloseBtn = document.getElementById('technicianNotesClose');
    var techNotesSaveBtn = document.getElementById('technicianNotesSave');
    var techNotesState = {
        btn: null,
        jobId: 0,
        submitting: false
    };

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

    function setEtaStatus(jobId, msg, type) {
        var el = document.querySelector('[data-eta-job="' + jobId + '"]');
        if (!el) return;
        el.textContent = msg;
        el.className = 'eta-status' + (type ? ' ' + type : '');
    }

    function setTechNotesModalStatus(msg, type) {
        if (!techNotesStatus) return;
        techNotesStatus.textContent = msg;
        techNotesStatus.className = 'tech-notes-status' + (type ? ' ' + type : '');
    }

    function decodeTechnicianNotesValue(value) {
        if (!value) return '';
        try {
            return decodeURIComponent(value);
        } catch (err) {
            return '';
        }
    }

    function modalFocusableElements(container) {
        if (!container) return [];
        return Array.prototype.slice.call(
            container.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')
        ).filter(function (el) {
            return el.offsetParent !== null || el === document.activeElement;
        });
    }

    function trapModalFocus(event, container) {
        if (event.key !== 'Tab') return;
        var focusable = modalFocusableElements(container);
        if (focusable.length === 0) {
            event.preventDefault();
            return;
        }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (event.shiftKey) {
            if (document.activeElement === first || !container.contains(document.activeElement)) {
                event.preventDefault();
                last.focus();
            }
            return;
        }

        if (document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function refreshTechnicianNotesCard(jobId, notes, scopeOfWork) {
        document.querySelectorAll('[data-tech-notes-job-id="' + jobId + '"]').forEach(function (trigger) {
            trigger.dataset.technicianNotesEncoded = encodeURIComponent(notes);
            var valueEl = trigger.querySelector('[data-tech-notes-value]');
            if (valueEl) {
                var hasNotes = notes.trim() !== '';
                valueEl.textContent = hasNotes ? notes : 'Tap to add notes';
                valueEl.classList.toggle('is-placeholder', !hasNotes);
            }
        });

        document.querySelectorAll('[data-authorize-job-id="' + jobId + '"]').forEach(function (authorizeBtn) {
            authorizeBtn.dataset.authorizeScope = scopeOfWork;
        });

        if (authState.jobId === jobId && authScope && authState.documentType === 'service_authorization') {
            authScope.textContent = scopeOfWork || 'Perform the service request currently listed for this visit.';
        }
    }

    function setTripButtons(jobId, state) {
        var onWayBtn   = document.querySelector('[data-action="on_my_way"][data-job-id="' + jobId + '"]');
        var arrivedBtn = document.querySelector('[data-action="arrived"][data-job-id="' + jobId + '"]');

        if (onWayBtn) {
            onWayBtn.classList.toggle('active', state === 'pending');
            onWayBtn.disabled = state === 'pending';
        }
        if (arrivedBtn) {
            arrivedBtn.classList.remove('active');
            arrivedBtn.disabled = state !== 'pending';
        }
    }

    // ── Trip purpose ──────────────────────────────────────────────────────────
    // The job's problem text becomes the mileage log's business purpose. The
    // return-home sentinel (Job #0) uses "Return to base", suffixed with the
    // last completed job's client name when one is known for this session.
    var LAST_CLIENT_KEY = 'glLastCompletedClient';

    function readLastCompletedClient() {
        try {
            return sessionStorage.getItem(LAST_CLIENT_KEY) || '';
        } catch (e) {
            return '';
        }
    }

    function storeLastCompletedClient(name) {
        try {
            sessionStorage.setItem(LAST_CLIENT_KEY, name);
        } catch (e) { /* private mode / storage disabled — purpose falls back */ }
    }

    function tripPurpose(jobId, btn) {
        if (jobId === 0) {
            var last = readLastCompletedClient();
            return last ? 'Return to base (from ' + last + ')' : 'Return to base';
        }
        return (btn.dataset.problem || '').trim();
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
            return res.text().then(function (text) {
                var data = null;
                if (text) {
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        if (!res.ok) {
                            throw new Error('Server error (' + res.status + ')');
                        }
                        throw new Error('Invalid server response');
                    }
                }

                if (!res.ok) {
                    var errorMsg = (data && data.error)
                        ? data.error
                        : ('Server error (' + res.status + ')');
                    throw new Error(errorMsg);
                }

                if (!data) {
                    throw new Error('Empty server response');
                }

                return data;
            });
        }).then(function (data) {
            if (!data.success) {
                setStatus(jobId, '✗ ' + (data.error || 'Error saving'), 'err');
                btn.disabled = false;
                return;
            }

            if (payload.action === 'on_my_way') {
                _startMileageByJob[jobId] = payload.start_mileage;
                setTripButtons(jobId, 'pending');
                setStatus(jobId, '✓ Departed at ' + data.start_time, 'ok');
            } else {
                delete _startMileageByJob[jobId];
                if (jobId !== 0 && payload.client_name) {
                    storeLastCompletedClient(payload.client_name);
                }
                setTripButtons(jobId, 'ready');
                var miles = hasMiles(data.total_miles)
                    ? ' — ' + data.total_miles + ' miles'
                    : '';
                setStatus(jobId, '✓ Arrived at ' + data.end_time + miles, 'ok');
            }
        }).catch(function (err) {
            setStatus(jobId, '✗ ' + err.message, 'err');
            btn.disabled = false;
        });
    }

    // ── Restore trip states on page load ─────────────────────────────────────
    // Runs once at startup; re-applies active/disabled states from the DB so a
    // reload or re-login after a logout does not reset "On My Way" progress.
    function formatDbTime(dt) {
        if (!dt) { return ''; }
        var timePart = dt.length >= 16 ? dt.substring(11, 16) : '';
        if (!timePart) { return dt; }
        var parts  = timePart.split(':');
        var h      = parseInt(parts[0], 10);
        var m      = parseInt(parts[1], 10);
        var period = h >= 12 ? 'PM' : 'AM';
        var disp   = h % 12 || 12;
        return disp + ':' + (m < 10 ? '0' + m : '' + m) + ' ' + period;
    }

    function hasMiles(value) {
        return value !== null && value !== undefined && value !== '';
    }

    function getAuthorizationDocumentConfig(documentType) {
        if (documentType === 'completion_certificate') {
            return {
                kicker: 'Completion Certificate',
                title: 'Generate completion certificate',
                summary: 'This certifies that the work has been satisfactorily completed and the customer approves final payment.',
                scopeHeading: 'Completed Work',
                termsHeading: 'Customer Acknowledgment',
                terms: window.COMPLETION_CERTIFICATE_TERMS || [],
                emptyCardStatus: 'Not generated yet.',
                signedCardPrefix: 'Generated ',
                saveStatusMessage: 'Saving completion certificate…',
                saveError: 'Unable to save completion certificate.',
                apiEndpoint: '/api/completion-certificate-api.php',
                responseKey: 'certificate',
                statusSelectorPrefix: 'data-cert-job'
            };
        }

        return {
            kicker: 'Service Authorization',
            title: 'Authorize Work',
            summary: 'The customer authorizes the technician to perform the listed work described below.',
            scopeHeading: 'Scope of Work',
            termsHeading: 'Terms',
            terms: window.SERVICE_AUTH_TERMS || [],
            emptyCardStatus: 'Not signed yet.',
            signedCardPrefix: 'Signed ',
            saveStatusMessage: 'Saving authorization…',
            saveError: 'Unable to save authorization.',
            apiEndpoint: '/api/service-authorization-api.php',
            responseKey: 'authorization',
            statusSelectorPrefix: 'data-auth-job'
        };
    }

    function renderAuthorizationTerms(terms) {
        if (!authTermsList) return;
        authTermsList.textContent = '';
        (terms || []).forEach(function (term) {
            var li = document.createElement('li');
            li.textContent = term;
            authTermsList.appendChild(li);
        });
    }

    function setAuthorizationCardStatus(jobId, documentType, authorization) {
        var config = getAuthorizationDocumentConfig(documentType);
        var el = document.querySelector('[' + config.statusSelectorPrefix + '="' + jobId + '"]');
        if (!el) return;

        el.textContent = '';
        el.classList.remove('is-signed');

        if (!authorization || !authorization.download_url) {
            var empty = document.createElement('span');
            empty.textContent = config.emptyCardStatus;
            el.appendChild(empty);
            return;
        }

        el.classList.add('is-signed');
        var signedText = document.createElement('span');
        signedText.textContent = config.signedCardPrefix + (authorization.signed_at_display || authorization.signed_at || '');
        el.appendChild(signedText);

        var link = document.createElement('a');
        link.href = authorization.download_url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.className = 'authorization-download';
        link.textContent = 'Download PDF';
        el.appendChild(link);
    }

    function setAuthorizationModalStatus(msg, type) {
        if (!authStatus) return;
        authStatus.textContent = msg;
        authStatus.className = 'service-auth-status' + (type ? ' ' + type : '');
    }

    function resizeAuthorizationCanvas() {
        if (!authCanvas || !authCtx) return;
        var rect = authCanvas.getBoundingClientRect();
        if (!rect.width || !rect.height) return;
        var snapshot = null;
        if (authState.dirty && authCanvas.width && authCanvas.height) {
            snapshot = document.createElement('canvas');
            snapshot.width = authCanvas.width;
            snapshot.height = authCanvas.height;
            var snapshotCtx = snapshot.getContext('2d');
            if (snapshotCtx) {
                snapshotCtx.drawImage(authCanvas, 0, 0);
            } else {
                snapshot = null;
            }
        }
        var dpr = Math.max(window.devicePixelRatio || 1, 1);
        authCanvas.width = Math.round(rect.width * dpr);
        authCanvas.height = Math.round(rect.height * dpr);
        authCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
        authCtx.lineCap = 'round';
        authCtx.lineJoin = 'round';
        authCtx.lineWidth = 2.75;
        authCtx.strokeStyle = '#111827';
        authCtx.clearRect(0, 0, rect.width, rect.height);
        if (snapshot) {
            authCtx.drawImage(snapshot, 0, 0, rect.width, rect.height);
        }
    }

    function clearAuthorizationCanvas() {
        if (!authCanvas || !authCtx) return;
        var rect = authCanvas.getBoundingClientRect();
        authCtx.clearRect(0, 0, rect.width, rect.height);
        authState.dirty = false;
    }

    function openAuthorizationModal(btn) {
        var documentType = btn.dataset.authorizeDocType || 'service_authorization';
        var config = getAuthorizationDocumentConfig(documentType);
        authState.btn = btn;
        authState.jobId = parseInt(btn.dataset.authorizeJobId, 10) || 0;
        authState.documentType = documentType;
        authState.dirty = false;
        authState.drawing = false;
        authState.pointerId = null;
        authState.submitting = false;
        if (authKicker) authKicker.textContent = config.kicker;
        if (authTitle) authTitle.textContent = config.title;
        if (authSummary) authSummary.textContent = config.summary;
        if (authScopeHeading) authScopeHeading.textContent = config.scopeHeading;
        if (authTermsHeading) authTermsHeading.textContent = config.termsHeading;
        renderAuthorizationTerms(config.terms);
        if (authScope) {
            authScope.textContent = btn.dataset.authorizeScope || 'Perform the service request currently listed for this visit.';
        }
        if (authMeta) {
            var customer = btn.dataset.authorizeCustomer || 'Customer';
            authMeta.textContent = customer + ' signs below. Timestamp and GPS are captured when Sign is tapped.';
        }
        setAuthorizationModalStatus('', '');
        if (authSignBtn) authSignBtn.disabled = false;
        if (authClearBtn) authClearBtn.disabled = false;
        if (authCancelBtn) authCancelBtn.disabled = false;
        if (authCloseBtn) authCloseBtn.disabled = false;
        authModal.classList.add('open');
        document.body.style.overflow = 'hidden';
        window.requestAnimationFrame(function () {
            resizeAuthorizationCanvas();
            clearAuthorizationCanvas();
            if (authCloseBtn) {
                authCloseBtn.focus();
            } else if (authSignBtn) {
                authSignBtn.focus();
            }
        });
    }

    function closeAuthorizationModal(force) {
        if (!authModal) return;
        if (authState.submitting && !force) return;
        var restoreFocusTarget = authState.btn;
        authModal.classList.remove('open');
        document.body.style.overflow = '';
        authState.btn = null;
        authState.jobId = 0;
        authState.documentType = 'service_authorization';
        authState.dirty = false;
        authState.drawing = false;
        authState.pointerId = null;
        authState.submitting = false;
        setAuthorizationModalStatus('', '');
        if (restoreFocusTarget && typeof restoreFocusTarget.focus === 'function') {
            restoreFocusTarget.focus();
        }
    }

    function openTechnicianNotesModal(btn) {
        if (!techNotesModal || !techNotesTextarea) return;
        techNotesState.btn = btn;
        techNotesState.jobId = parseInt(btn.dataset.techNotesJobId, 10) || 0;
        techNotesState.submitting = false;
        techNotesTextarea.value = decodeTechnicianNotesValue(btn.dataset.technicianNotesEncoded || '');
        techNotesTextarea.disabled = false;
        if (techNotesSaveBtn) techNotesSaveBtn.disabled = false;
        if (techNotesCancelBtn) techNotesCancelBtn.disabled = false;
        if (techNotesCloseBtn) techNotesCloseBtn.disabled = false;
        setTechNotesModalStatus('', '');
        techNotesModal.classList.add('open');
        document.body.style.overflow = 'hidden';
        window.requestAnimationFrame(function () {
            techNotesTextarea.focus();
            techNotesTextarea.setSelectionRange(techNotesTextarea.value.length, techNotesTextarea.value.length);
        });
    }

    function closeTechnicianNotesModal(force) {
        if (!techNotesModal) return;
        if (techNotesState.submitting && !force) return;
        var restoreFocusTarget = techNotesState.btn;
        techNotesModal.classList.remove('open');
        document.body.style.overflow = '';
        techNotesState.btn = null;
        techNotesState.jobId = 0;
        techNotesState.submitting = false;
        setTechNotesModalStatus('', '');
        if (restoreFocusTarget && typeof restoreFocusTarget.focus === 'function') {
            restoreFocusTarget.focus();
        }
    }

    function authorizationCanvasPoint(event) {
        var rect = authCanvas.getBoundingClientRect();
        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top
        };
    }

    function authorizationStartDrawing(event) {
        if (!authCanvas || !authCtx) return;
        event.preventDefault();
        authState.drawing = true;
        authState.pointerId = event.pointerId;
        authCanvas.setPointerCapture(event.pointerId);
        var point = authorizationCanvasPoint(event);
        authCtx.beginPath();
        authCtx.moveTo(point.x, point.y);
        authCtx.lineTo(point.x + 0.01, point.y + 0.01);
        authCtx.stroke();
        authState.dirty = true;
        setAuthorizationModalStatus('', '');
    }

    function authorizationMoveDrawing(event) {
        if (!authState.drawing || authState.pointerId !== event.pointerId) return;
        event.preventDefault();
        var point = authorizationCanvasPoint(event);
        authCtx.lineTo(point.x, point.y);
        authCtx.stroke();
    }

    function authorizationStopDrawing(event) {
        if (!authState.drawing || authState.pointerId !== event.pointerId) return;
        event.preventDefault();
        authState.drawing = false;
        authState.pointerId = null;
        authCtx.closePath();
        if (authCanvas.hasPointerCapture(event.pointerId)) {
            authCanvas.releasePointerCapture(event.pointerId);
        }
    }

    function authorizationResetDrawingState() {
        authState.drawing = false;
        authState.pointerId = null;
        if (authCtx) {
            authCtx.closePath();
        }
    }

    function initTripStates() {
        var states = window.TRIP_STATES;
        if (!states) { return; }
        Object.keys(states).forEach(function (jobId) {
            var state      = states[jobId];

            if (state.status === 'pending') {
                var startMileage = parseInt(state.start_mileage, 10);
                if (!isNaN(startMileage)) {
                    _startMileageByJob[jobId] = startMileage;
                }
                // Departed — waiting for arrival
                setTripButtons(jobId, 'pending');
                var depTime = formatDbTime(state.start_time);
                setStatus(jobId, '\u2713 Departed' + (depTime ? ' at ' + depTime : ''), 'ok');
            } else if (state.status === 'complete') {
                // Trip completed — allow a new cycle.
                setTripButtons(jobId, 'ready');
                var arrTime = formatDbTime(state.end_time);
                var miles   = hasMiles(state.total_miles) ? ' \u2014 ' + state.total_miles + ' miles' : '';
                setStatus(jobId, '\u2713 Arrived' + (arrTime ? ' at ' + arrTime : '') + miles, 'ok');
            }
        });
    }

    initTripStates();

    if (authCanvas) {
        authCanvas.addEventListener('pointerdown', authorizationStartDrawing);
        authCanvas.addEventListener('pointermove', authorizationMoveDrawing);
        authCanvas.addEventListener('pointerup', authorizationStopDrawing);
        authCanvas.addEventListener('pointercancel', authorizationStopDrawing);
        authCanvas.addEventListener('lostpointercapture', authorizationResetDrawingState);
    }

    function syncAuthorizationCanvasToViewport() {
        if (authModal && authModal.classList.contains('open')) {
            if (authState.dirty) {
                return;
            }
            resizeAuthorizationCanvas();
        }
    }

    window.addEventListener('resize', syncAuthorizationCanvasToViewport);
    window.addEventListener('orientationchange', syncAuthorizationCanvasToViewport);

    if (authClearBtn) {
        authClearBtn.addEventListener('click', function () {
            clearAuthorizationCanvas();
            setAuthorizationModalStatus('', '');
        });
    }

    if (authCancelBtn) {
        authCancelBtn.addEventListener('click', closeAuthorizationModal);
    }

    if (authCloseBtn) {
        authCloseBtn.addEventListener('click', closeAuthorizationModal);
    }

    if (authModal) {
        authModal.addEventListener('click', function (event) {
            if (event.target === authModal) {
                closeAuthorizationModal();
            }
        });
    }

    document.querySelectorAll('[data-tech-notes-job-id]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openTechnicianNotesModal(btn);
        });
    });

    if (techNotesCancelBtn) {
        techNotesCancelBtn.addEventListener('click', function () {
            closeTechnicianNotesModal();
        });
    }

    if (techNotesCloseBtn) {
        techNotesCloseBtn.addEventListener('click', function () {
            closeTechnicianNotesModal();
        });
    }

    if (techNotesModal) {
        techNotesModal.addEventListener('click', function (event) {
            if (event.target === techNotesModal) {
                closeTechnicianNotesModal();
            }
        });
    }

    if (authForm) {
        authForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!authState.jobId || authState.submitting) return;
            if (!authState.dirty || !authCanvas) {
                setAuthorizationModalStatus('Signature required before continuing.', 'err');
                return;
            }

            authState.submitting = true;
            authSignBtn.disabled = true;
            if (authClearBtn) authClearBtn.disabled = true;
            if (authCancelBtn) authCancelBtn.disabled = true;
            if (authCloseBtn) authCloseBtn.disabled = true;
            var docConfig = getAuthorizationDocumentConfig(authState.documentType);
            setAuthorizationModalStatus('Getting GPS location…', '');

            var signedAt = new Date().toISOString();
            var signaturePng = authCanvas.toDataURL('image/png');

            getCoords().then(function (coords) {
                setAuthorizationModalStatus(docConfig.saveStatusMessage, '');
                return fetch(docConfig.apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        service_request_id: authState.jobId,
                        signature_png: signaturePng,
                        scope_of_work: authScope ? authScope.textContent : '',
                        signed_at: signedAt,
                        csrf_token: SERVICE_AUTH_CSRF,
                        latitude: coords.lat,
                        longitude: coords.lng
                    })
                });
            }).then(function (res) {
                return res.text().then(function (text) {
                    var data = null;
                    if (text) {
                        try {
                            data = JSON.parse(text);
                        } catch (err) {
                            if (!res.ok) {
                                throw new Error(text || ('Server error (' + res.status + ')'));
                            }
                            throw new Error('Invalid server response');
                        }
                    }

                    if (!res.ok) {
                        throw new Error((data && data.error) ? data.error : ('Server error (' + res.status + ')'));
                    }

                    if (!data || !data.success || !data[docConfig.responseKey]) {
                        throw new Error((data && data.error) ? data.error : docConfig.saveError);
                    }

                    return data[docConfig.responseKey];
                });
            }).then(function (authorization) {
                setAuthorizationCardStatus(authState.jobId, authState.documentType, authorization);
                authState.submitting = false;
                if (authCloseBtn) authCloseBtn.disabled = false;
                closeAuthorizationModal(true);
            }).catch(function (err) {
                authState.submitting = false;
                authSignBtn.disabled = false;
                if (authClearBtn) authClearBtn.disabled = false;
                if (authCancelBtn) authCancelBtn.disabled = false;
                if (authCloseBtn) authCloseBtn.disabled = false;
                setAuthorizationModalStatus('✗ ' + err.message, 'err');
            });
        });
    }

    if (techNotesForm) {
        techNotesForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!techNotesState.jobId || techNotesState.submitting || !techNotesTextarea) return;

            techNotesState.submitting = true;
            techNotesTextarea.disabled = true;
            if (techNotesSaveBtn) techNotesSaveBtn.disabled = true;
            if (techNotesCancelBtn) techNotesCancelBtn.disabled = true;
            if (techNotesCloseBtn) techNotesCloseBtn.disabled = true;
            setTechNotesModalStatus('Saving notes…', '');

            fetch('/api/technician-notes-api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    service_request_id: techNotesState.jobId,
                    technician_notes: techNotesTextarea.value,
                    csrf_token: SERVICE_AUTH_CSRF
                })
            }).then(function (res) {
                return res.text().then(function (text) {
                    var data = null;
                    if (text) {
                        try {
                            data = JSON.parse(text);
                        } catch (err) {
                            if (!res.ok) {
                                throw new Error(text || ('Server error (' + res.status + ')'));
                            }
                            throw new Error('Invalid server response');
                        }
                    }

                    if (!res.ok) {
                        throw new Error((data && data.error) ? data.error : ('Server error (' + res.status + ')'));
                    }

                    if (!data || !data.success) {
                        throw new Error((data && data.error) ? data.error : 'Unable to save technician notes.');
                    }

                    return data;
                });
            }).then(function (data) {
                refreshTechnicianNotesCard(
                    techNotesState.jobId,
                    data.technician_notes || '',
                    data.scope_of_work || ''
                );
                closeTechnicianNotesModal(true);
            }).catch(function (err) {
                techNotesState.submitting = false;
                techNotesTextarea.disabled = false;
                if (techNotesSaveBtn) techNotesSaveBtn.disabled = false;
                if (techNotesCancelBtn) techNotesCancelBtn.disabled = false;
                if (techNotesCloseBtn) techNotesCloseBtn.disabled = false;
                setTechNotesModalStatus('✗ ' + err.message, 'err');
            });
        });
    }

    // ── Mileage Modal ─────────────────────────────────────────────────────────
    var _modalData   = null; // { btn, jobId, payload }
    var _mileageInput = '';
    var DIGIT_COUNT  = 6;

    function updateNixieDisplay() {
        var padded = _mileageInput.padStart(DIGIT_COUNT, '0');
        var firstNonZero = padded.search(/[1-9]/);
        for (var i = 0; i < DIGIT_COUNT; i++) {
            var el = document.getElementById('nd' + i);
            if (!el) continue;
            el.textContent = padded[i];
            if (firstNonZero === -1 || i < firstNonZero) {
                el.classList.add('dim');
            } else {
                el.classList.remove('dim');
            }
        }
    }

    function openMileageModal(btn, jobId, payload) {
        _modalData    = { btn: btn, jobId: jobId, payload: payload };
        _mileageInput = '';
        updateNixieDisplay();
        document.getElementById('nixieError').textContent = '';
        document.querySelector('.mileage-modal-title').textContent = payload.action === 'on_my_way'
            ? 'Starting Odometer'
            : 'Ending Odometer';
        document.querySelector('.mileage-modal-sub').textContent = payload.action === 'on_my_way'
            ? 'Enter current truck mileage before departing'
            : 'Enter current truck mileage after arriving';
        var vehicleWrap = document.getElementById('mileageVehicleWrap');
        var vehicleSelect = document.getElementById('mileageVehicleSelect');
        var needsVehicle = payload.action === 'on_my_way';
        if (vehicleWrap) {
            vehicleWrap.style.display = needsVehicle ? 'block' : 'none';
        }
        if (vehicleSelect) {
            if (needsVehicle && DEFAULT_VEHICLE_ID !== null && vehicleSelect.value === '') {
                vehicleSelect.value = String(DEFAULT_VEHICLE_ID);
            }
        }
        var modal = document.getElementById('mileageModal');
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeMileageModal() {
        document.getElementById('mileageModal').classList.remove('open');
        document.body.style.overflow = '';
        _modalData    = null;
        _mileageInput = '';
    }

    function shakeDisplay() {
        var display = document.getElementById('nixieDisplay');
        display.classList.remove('shake');
        // Force reflow so the animation re-triggers
        void display.offsetWidth;
        display.classList.add('shake');
    }

    document.getElementById('keypadCancel').addEventListener('click', function () {
        if (_modalData && _modalData.btn) {
            _modalData.btn.disabled = false;
        }
        closeMileageModal();
    });

    document.getElementById('keypadClear').addEventListener('click', function () {
        _mileageInput = '';
        document.getElementById('nixieError').textContent = '';
        updateNixieDisplay();
    });

    document.getElementById('keypadBack').addEventListener('click', function () {
        _mileageInput = _mileageInput.slice(0, -1);
        document.getElementById('nixieError').textContent = '';
        updateNixieDisplay();
    });

    document.getElementById('mileageKeypad').addEventListener('click', function (e) {
        var numBtn = e.target.closest('.keypad-num');
        if (!numBtn) return;
        if (_mileageInput.length >= DIGIT_COUNT) return;
        var digit = numBtn.dataset.digit;
        // Prevent leading zeros
        if (_mileageInput === '' && digit === '0') return;
        _mileageInput += digit;
        document.getElementById('nixieError').textContent = '';
        updateNixieDisplay();
    });

    document.getElementById('keypadConfirm').addEventListener('click', function () {
        if (!_modalData) return;
        var mileage = parseInt(_mileageInput, 10);
        if (!_mileageInput || mileage < 1) {
            document.getElementById('nixieError').textContent = 'Enter a valid mileage reading';
            shakeDisplay();
            return;
        }
        var data = _modalData;
        if (data.payload.action === 'arrived') {
            var startMileage = parseInt(_startMileageByJob[data.jobId], 10);
            if (!isNaN(startMileage) && mileage <= startMileage) {
                document.getElementById('nixieError').textContent =
                    'Ending mileage must be greater than starting mileage (' + startMileage + ').';
                shakeDisplay();
                return;
            }
        }
        if (data.payload.action === 'on_my_way') {
            var vehicleSelect = document.getElementById('mileageVehicleSelect');
            var selectedVehicleId = vehicleSelect ? parseInt(vehicleSelect.value, 10) : NaN;
            if (!HAS_ACTIVE_VEHICLES || !selectedVehicleId) {
                document.getElementById('nixieError').textContent = 'Select a vehicle before logging mileage';
                shakeDisplay();
                return;
            }
            data.payload.vehicle_id = selectedVehicleId;
        }
        closeMileageModal();
        if (data.payload.action === 'on_my_way') {
            data.payload.start_mileage = mileage;
        } else {
            data.payload.end_mileage = mileage;
        }
        callMileageApi(data.payload, data.btn, data.jobId);
    });

    // ── Attach listeners ──────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var authorizeBtn = e.target.closest('.authorize-btn');
        if (authorizeBtn) {
            openAuthorizationModal(authorizeBtn);
            return;
        }

        var btn = e.target.closest('.mileage-btn');
        if (!btn || btn.disabled) return;

        var action  = btn.dataset.action;
        var jobId   = parseInt(btn.dataset.jobId, 10);

        if (action === 'on_my_way') {
            if (!HAS_ACTIVE_VEHICLES) {
                setStatus(jobId, '✗ No active vehicles available. Add one in Vehicle Settings.', 'err');
                return;
            }
            var payload = {
                action:             'on_my_way',
                service_request_id: jobId,
                client_name:        btn.dataset.client  || '',
                address:            btn.dataset.address || '',
                notes:              tripPurpose(jobId, btn)
            };
            btn.disabled = true;
            openMileageModal(btn, jobId, payload);
        } else if (action === 'arrived') {
            var payload = {
                action:             'arrived',
                service_request_id: jobId,
                client_name:        btn.dataset.client || '',
                notes:              tripPurpose(jobId, btn)
            };
            btn.disabled = true;
            openMileageModal(btn, jobId, payload);
        }
    });

	window.sendEtaSms = function sendEtaSms(btn) {
		var phone       = btn.dataset.phone       || '';
		var destination = btn.dataset.destination || '';

		if (!phone) {
			alert('Phone number is missing.');
			return;
		}
		if (!destination) {
			alert('Customer address is unavailable.');
			return;
		}

		btn.disabled = true;

		getCoords().then(function (coords) {
			return fetch('/api/get-eta.php', {
				method:  'POST',
				headers: { 'Content-Type': 'application/json' },
				body:    JSON.stringify({
					origin_lat:  coords.lat,
					origin_lng:  coords.lng,
					destination: destination
				})
			});
		}).then(function (response) {
			return response.json();
		}).then(function (data) {
			btn.disabled = false;
			if (!data.message) {
				throw new Error(data.error || 'Unable to calculate ETA.');
			}
			window.location.href = 'sms:' + phone + '?body=' + encodeURIComponent(data.message);
		}).catch(function (err) {
			btn.disabled = false;
			alert(err.message || 'Error getting ETA. Please try again.');
		});
	};

	window.notifyCustomerSms = function notifyCustomerSms(btn) {
		var phone = btn.dataset.phone || '';
		if (!phone) {
			alert('Phone number is missing.');
			return;
		}
		var message = 'Ghost Laser Technician: I just got here. Let me log into the system and take out my tools and I\'ll be right in.';
		window.location.href = 'sms:' + phone + '?body=' + encodeURIComponent(message);
	};

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && authModal && authModal.classList.contains('open')) {
            closeAuthorizationModal();
        } else if (event.key === 'Escape' && techNotesModal && techNotesModal.classList.contains('open')) {
            closeTechnicianNotesModal();
        } else if (authModal && authModal.classList.contains('open')) {
            trapModalFocus(event, authModal);
        } else if (techNotesModal && techNotesModal.classList.contains('open')) {
            trapModalFocus(event, techNotesModal);
        }
    });
}());

function saveContact(btn) {
    var name    = btn.dataset.name    || '';
    var phone   = btn.dataset.phone   || '';
    var email   = btn.dataset.email   || '';
    var company = btn.dataset.company || '';

    // Split name into first/last for vCard N field
    var parts = name.trim().split(/\s+/);
    var last  = parts.length > 1 ? parts.pop() : '';
    var first = parts.join(' ');

    var lines = [
        'BEGIN:VCARD',
        'VERSION:3.0',
        'N:' + last + ';' + first + ';;;',
        'FN:' + name
    ];
    if (company) lines.push('ORG:' + company);
    if (phone)   lines.push('TEL;TYPE=CELL:' + phone);
    if (email)   lines.push('EMAIL:' + email);
    lines.push('END:VCARD');

    var blob = new Blob([lines.join('\r\n') + '\r\n'], { type: 'text/vcard;charset=utf-8' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = (name || 'contact').replace(/[^a-z0-9_\-]/gi, '_') + '.vcf';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

</script>

<script>
// ── Return destination dropdown ───────────────────────────────────────────────
(function () {
    var sel = document.getElementById('returnDestSelect');
    if (!sel) return; // no dropdown if home address is not configured

    function updateReturnDest() {
        var addr = sel.value === 'home' ? sel.dataset.homeAddress : sel.dataset.shopAddress;

        var addrEl = document.getElementById('returnDestAddress');
        if (addrEl) addrEl.textContent = addr;

        var wazeLink = document.getElementById('returnWazeLink');
        if (wazeLink) wazeLink.href = 'https://waze.com/ul?q=' + encodeURIComponent(addr) + '&navigate=yes';

        var gmapsLink = document.getElementById('returnGmapsLink');
        if (gmapsLink) gmapsLink.href = 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(addr);

        var btn = document.getElementById('returnOnWayBtn');
        if (btn) btn.dataset.address = addr;
    }

    sel.addEventListener('change', updateReturnDest);
}());
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
