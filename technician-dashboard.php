<?php
// Extend session lifetime to 12 hours for technicians using this page while driving.
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params([
    'lifetime' => 43200,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
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
        "SELECT service_request_id, status, start_time, end_time, total_miles
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
                'status'      => $row['status'],
                'start_time'  => $row['start_time'],
                'end_time'    => $row['end_time'],
                'total_miles' => $row['total_miles'],
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
                        $customerName = trim((string) ($job['first_name'] ?? '') . ' ' . (string) ($job['last_name'] ?? ''));
                        if ($customerName === '') {
                            // Fall back to task_contact (company or contact name) for task-type rows.
                            $customerName = trim((string) ($job['task_contact'] ?? ''));
                        }
                        if ($customerName === '') {
                            $customerName = 'Internal Task';
                        }
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

                            <!-- Row 4: problem summary (collapsed) -->
                            <?php if (!empty($job['problem_summary'])): ?>
                                <div class="mt-2 pt-2 border-t border-zinc-700/40">
                                    <p class="text-xs text-zinc-400 leading-relaxed line-clamp-2">
                                        <?= htmlspecialchars($job['problem_summary'], ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <!-- Row 5: mileage tracking buttons -->
                            <div class="mt-3 pt-3 border-t border-zinc-700/40">
                                <div class="flex items-center gap-2">
                                    <button
                                        class="mileage-btn btn-on-way"
                                        data-action="on_my_way"
                                        data-job-id="<?= (int) $job['service_request_id'] ?>"
                                        data-client="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
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
                setTripButtons(jobId, 'pending');
                setStatus(jobId, '✓ Departed at ' + data.start_time, 'ok');
            } else {
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

    function initTripStates() {
        var states = window.TRIP_STATES;
        if (!states) { return; }
        Object.keys(states).forEach(function (jobId) {
            var state      = states[jobId];

            if (state.status === 'pending') {
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
                address:            btn.dataset.address || ''
            };
            btn.disabled = true;
            openMileageModal(btn, jobId, payload);
        } else if (action === 'arrived') {
            var payload = {
                action:             'arrived',
                service_request_id: jobId
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
