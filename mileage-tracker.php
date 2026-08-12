<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mileage_schema.php';

ensureMileageVehicleSchema($pdo);

// ── Helpers (defined early so CSV export can use them) ────────────────────────
function fmtDateTime(?string $dt): string
{
    if ($dt === null || $dt === '') return '—';
    try {
        $d = new DateTimeImmutable($dt, new DateTimeZone('America/Los_Angeles'));
        return $d->format('m/d/Y g:i A') . ' PT';
    } catch (Exception $e) {
        return $dt;
    }
}

function fmtDate(?string $dt): string
{
    if ($dt === null || $dt === '') return '—';
    try {
        $d = new DateTimeImmutable($dt);
        return $d->format('m/d/Y');
    } catch (Exception $e) {
        return $dt;
    }
}

function fmtOdometer($mileage): string
{
    if ($mileage === null || $mileage === '') return '—';
    return number_format((float) $mileage, 0) . ' mi';
}

function mileageFromOdometer($startMileage, $endMileage): ?float
{
    if ($startMileage === null || $startMileage === '' || $endMileage === null || $endMileage === '') {
        return null;
    }
    return (float) $endMileage - (float) $startMileage;
}

function fmtMiles($mileage): string
{
    if ($mileage === null || $mileage === '') return '—';
    return number_format((float) $mileage, 2) . ' mi';
}

function fmtVehicle(array $row): string
{
    $name = trim((string) ($row['vehicle_name'] ?? ''));
    $ym   = trim((string) trim(($row['vehicle_year'] ?? '') . ' ' . ($row['vehicle_make'] ?? '') . ' ' . ($row['vehicle_model'] ?? '')));
    $plate = trim((string) ($row['vehicle_license_plate'] ?? ''));

    $parts = [];
    if ($name !== '') {
        $parts[] = $name;
    }
    if ($ym !== '') {
        $parts[] = $ym;
    }
    if ($plate !== '') {
        $parts[] = 'Plate: ' . $plate;
    }

    return $parts !== [] ? implode(' • ', $parts) : '—';
}

/**
 * Build an IRS-style business purpose string for a mileage log row.
 * Format: "{Brand} {Model} — {Services} — {Problem}"
 * Any missing piece is simply omitted.
 */
function buildPurpose(array $row): string
{
    static $serviceLabels = [
        'maintenance_alignment' => 'Maintenance & Alignment',
        'tube_change'           => 'Tube Change',
        'diagnosis'             => 'Diagnosis',
        'training'              => 'Training',
        'other'                 => 'Other',
    ];

    $parts = [];

    $brand   = trim((string) ($row['laser_brand']  ?? ''));
    $model   = trim((string) ($row['laser_model']  ?? ''));
    $machine = trim("$brand $model");
    if ($machine !== '') {
        $parts[] = $machine;
    }

    $servicesRaw = $row['services'] ?? null;
    if ($servicesRaw !== null && $servicesRaw !== '') {
        $keys = json_decode((string) $servicesRaw, true);
        if (is_array($keys) && $keys !== []) {
            $labels = array_map(
                static fn($k) => $serviceLabels[$k] ?? ucwords(str_replace('_', ' ', (string) $k)),
                $keys
            );
            $parts[] = implode(', ', $labels);
        }
    }

    $problem = trim((string) ($row['problem_summary'] ?? ''));
    if ($problem !== '') {
        $parts[] = $problem;
    }

    return $parts !== [] ? implode(' — ', $parts) : '—';
}

$adminUsername = trim((string) ($_SESSION['admin_username'] ?? 'Admin'));
if ($adminUsername === '') {
    $adminUsername = 'Admin';
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStart  = trim((string) ($_GET['start']  ?? ''));
$filterEnd    = trim((string) ($_GET['end']    ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));

// Validate date filters
$dateStart = ($filterStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterStart))
    ? $filterStart : null;
$dateEnd   = ($filterEnd   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterEnd))
    ? $filterEnd   : null;

// ── Query ─────────────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($dateStart !== null) {
    $where[]  = 'ml.trip_date >= :ds';
    $params[':ds'] = $dateStart;
}
if ($dateEnd !== null) {
    $where[]  = 'ml.trip_date <= :de';
    $params[':de'] = $dateEnd;
}
if ($filterStatus === 'complete') {
    $where[] = "ml.status = 'complete'";
} elseif ($filterStatus === 'pending') {
    $where[] = "ml.status = 'pending'";
}

$whereClause = implode(' AND ', $where);
$vehicles = [];

// Ensure dependent schema exists before querying
try {
    ensureMileageVehicleSchema($pdo);

    $vehicles = $pdo->query("
        SELECT id, name, year, make, model, license_plate, is_active
        FROM vehicles
        ORDER BY is_active DESC, name ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Ensure the services JSON column exists on service_requests so that
    // purpose data can be stored and displayed for IRS documentation.
    // Use information_schema check for compatibility with MySQL < 8.0.
    $colCheck = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'service_requests'
          AND COLUMN_NAME  = 'services'
    ");
    $colCheck->execute();
    if ((int) $colCheck->fetchColumn() === 0) {
        $pdo->exec("
            ALTER TABLE service_requests
            ADD COLUMN services JSON NULL COMMENT 'Selected services as JSON array'
        ");
    }

    $stmt = $pdo->prepare(
        "SELECT ml.*,
               v.name AS vehicle_name,
               v.year AS vehicle_year,
               v.make AS vehicle_make,
               v.model AS vehicle_model,
               v.license_plate AS vehicle_license_plate,
               sr.laser_brand,
               sr.laser_model,
               sr.problem_summary,
               sr.services
         FROM mileage_logs ml
         LEFT JOIN vehicles v ON v.id = ml.vehicle_id
         LEFT JOIN service_requests sr ON sr.id = ml.service_request_id
         WHERE {$whereClause}
         ORDER BY ml.trip_date DESC, ml.start_time DESC"
    );
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pre-compute the IRS business purpose for each row so it is available
    // both in the HTML table and in the JSON data fed to the detail modal.
    // Admin-entered notes override the auto-generated purpose string.
    foreach ($logs as &$log) {
        $log['purpose'] = !empty($log['notes']) ? $log['notes'] : buildPurpose($log);
    }
    unset($log);
} catch (PDOException $e) {
    $logs = [];
    $dbError = $e->getMessage();
}

// ── Delete handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int) $_POST['delete_id'];
    if ($deleteId > 0) {
        try {
            $delStmt = $pdo->prepare("DELETE FROM mileage_logs WHERE id = :id");
            $delStmt->execute([':id' => $deleteId]);
        } catch (PDOException $e) {
            // silently ignore; redirect regardless
        }
    }
    // PRG: redirect to same page with current filters
    $qs = http_build_query(array_filter([
        'start'  => $filterStart,
        'end'    => $filterEnd,
        'status' => $filterStatus,
    ]));
    header('Location: mileage-tracker.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// ── Totals ────────────────────────────────────────────────────────────────────
$totalMiles = 0.0;
$completedTrips = 0;
foreach ($logs as $row) {
    $tripMiles = mileageFromOdometer($row['start_mileage'] ?? null, $row['end_mileage'] ?? null);
    if ($row['status'] === 'complete' && $tripMiles !== null) {
        $totalMiles   += $tripMiles;
        $completedTrips++;
    }
}

// ── CSV export (must run before any HTML output) ──────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="mileage-log-' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel UTF-8

    fputcsv($out, [
        'Date',
        'Client Name',
        'Vehicle',
        'Address',
        'Purpose',
        'Start Time (LA)',
        'End Time (LA)',
        'Starting Odometer',
        'Ending Odometer',
        'Total Miles',
        'Job ID',
        'Status',
    ]);

    foreach ($logs as $row) {
        fputcsv($out, [
            fmtDate($row['trip_date']),
            $row['client_name'],
            fmtVehicle($row),
            $row['address'],
            $row['purpose'] ?? buildPurpose($row),
            fmtDateTime($row['start_time']),
            fmtDateTime($row['end_time']),
            $row['start_mileage'] ?? '',
            $row['end_mileage'] ?? '',
            (($tripMiles = mileageFromOdometer($row['start_mileage'] ?? null, $row['end_mileage'] ?? null)) !== null) ? number_format($tripMiles, 2) : '',
            '#' . $row['service_request_id'],
            $row['status'],
        ]);
    }

    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mileage Tracker | Ghost Laser</title>
    <link rel="stylesheet" href="<?= asset('assets/css/tailwind.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap&v=1.2" rel="stylesheet">
    <style>
        body { -webkit-tap-highlight-color: transparent; }

        .stat-card {
            background: rgba(24,24,27,0.85);
            border: 1px solid rgba(63,63,70,0.8);
            border-radius: 0.875rem;
            padding: 1.25rem 1.5rem;
        }

        .filter-input {
            background: rgba(24,24,27,0.9);
            border: 1px solid rgba(63,63,70,0.9);
            border-radius: 0.5rem;
            color: #e4e4e7;
            font-size: 0.875rem;
            padding: 0.45rem 0.75rem;
            outline: none;
            transition: border-color 0.15s;
        }
        .filter-input:focus { border-color: rgba(6,182,212,0.55); }

        .filter-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: 0.45rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid rgba(6,182,212,0.4);
            background: rgba(6,182,212,0.1);
            color: #22d3ee;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: border-color 0.15s, background 0.15s;
        }
        .filter-btn:hover { border-color: rgba(6,182,212,0.7); background: rgba(6,182,212,0.18); }

        .reset-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: 0.45rem 0.9rem;
            border-radius: 0.5rem;
            border: 1px solid rgba(63,63,70,0.9);
            background: transparent;
            color: #a1a1aa;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: border-color 0.15s, color 0.15s;
        }
        .reset-btn:hover { border-color: rgba(239,68,68,0.4); color: #fca5a5; }

        .export-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 1.1rem;
            border-radius: 0.5rem;
            border: 1px solid rgba(34,197,94,0.4);
            background: rgba(34,197,94,0.1);
            color: #86efac;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: border-color 0.15s, background 0.15s;
        }
        .export-btn:hover { border-color: rgba(34,197,94,0.7); background: rgba(34,197,94,0.18); }

        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            min-width: 1100px;
            font-size: 0.82rem;
        }

        thead th {
            background: rgba(24,24,27,0.95);
            color: #71717a;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 0.65rem 0.85rem;
            text-align: left;
            border-bottom: 1px solid rgba(63,63,70,0.8);
            white-space: nowrap;
        }

        tbody tr {
            border-bottom: 1px solid rgba(39,39,42,0.7);
            transition: background 0.12s;
        }
        tbody tr:hover { background: rgba(39,39,42,0.55); }
        tbody tr.log-row { cursor: pointer; }
        tbody tr.log-row:focus-visible {
            outline: 2px solid rgba(34,211,238,0.65);
            outline-offset: -2px;
        }

        tbody td {
            padding: 0.65rem 0.85rem;
            color: #d4d4d8;
            vertical-align: top;
        }

        .badge-complete {
            display: inline-flex;
            align-items: center;
            padding: 0.1rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.6rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            background: rgba(34,197,94,0.12);
            border: 1px solid rgba(34,197,94,0.3);
            color: #86efac;
        }

        .badge-pending {
            display: inline-flex;
            align-items: center;
            padding: 0.1rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.6rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            background: rgba(251,191,36,0.12);
            border: 1px solid rgba(251,191,36,0.3);
            color: #fde68a;
        }

        .odometer-cell {
            font-size: 0.72rem;
            color: #71717a;
            font-family: ui-monospace, monospace;
            white-space: nowrap;
        }

        .purpose-cell {
            font-size: 0.78rem;
            color: #a1a1aa;
            line-height: 1.45;
        }

        .miles-cell {
            font-weight: 700;
            color: #22d3ee;
            white-space: nowrap;
        }

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
        }
        .nav-btn:hover { border-color: rgba(6,182,212,0.4); background: rgba(6,182,212,0.06); }

        .delete-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            padding: 0.25rem 0.65rem;
            border-radius: 0.375rem;
            border: 1px solid rgba(239,68,68,0.35);
            background: rgba(239,68,68,0.08);
            color: #fca5a5;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            letter-spacing: 0.02em;
            transition: border-color 0.15s, background 0.15s;
        }
        .delete-btn:hover { border-color: rgba(239,68,68,0.65); background: rgba(239,68,68,0.18); }

        .edit-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            padding: 0.25rem 0.65rem;
            border-radius: 0.375rem;
            border: 1px solid rgba(6,182,212,0.35);
            background: rgba(6,182,212,0.08);
            color: #67e8f9;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            letter-spacing: 0.02em;
            transition: border-color 0.15s, background 0.15s;
        }
        .edit-btn:hover { border-color: rgba(6,182,212,0.65); background: rgba(6,182,212,0.18); }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 70;
            background: rgba(9,9,11,0.7);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
        .modal-backdrop.is-open { display: flex; }

        .detail-modal {
            width: min(780px, 100%);
            max-height: min(84vh, 820px);
            overflow: hidden;
            border-radius: 0.9rem;
            border: 1px solid rgba(63,63,70,0.9);
            background: linear-gradient(180deg, rgba(24,24,27,0.98), rgba(10,10,12,0.98));
            box-shadow: 0 18px 48px rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
        }

        .detail-modal-header {
            padding: 1rem 1.1rem 0.85rem;
            border-bottom: 1px solid rgba(63,63,70,0.8);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .detail-modal-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #f4f4f5;
        }

        .detail-modal-subtitle {
            margin-top: 0.2rem;
            font-size: 0.8rem;
            color: #a1a1aa;
        }

        .detail-close {
            width: 2rem;
            height: 2rem;
            border-radius: 0.5rem;
            border: 1px solid rgba(63,63,70,0.9);
            background: rgba(24,24,27,0.75);
            color: #d4d4d8;
            font-size: 1.1rem;
            line-height: 1;
            cursor: pointer;
        }
        .detail-close:hover { border-color: rgba(6,182,212,0.4); color: #22d3ee; }

        .detail-modal-body {
            padding: 0.95rem 1.1rem 1.15rem;
            overflow: auto;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 0.65rem;
        }

        .detail-item {
            border: 1px solid rgba(63,63,70,0.75);
            border-radius: 0.65rem;
            background: rgba(24,24,27,0.55);
            padding: 0.7rem;
            min-height: 4rem;
        }

        .detail-key {
            color: #71717a;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 0.35rem;
        }

        .detail-value {
            color: #e4e4e7;
            font-size: 0.82rem;
            line-height: 1.4;
            word-break: break-word;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }

        .edit-form-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }
        .edit-form-label {
            color: #71717a;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .edit-form-input {
            background: rgba(24,24,27,0.9);
            border: 1px solid rgba(63,63,70,0.9);
            border-radius: 0.4rem;
            color: #e4e4e7;
            font-size: 0.82rem;
            padding: 0.4rem 0.65rem;
            outline: none;
            transition: border-color 0.15s;
            width: 100%;
        }
        .edit-form-input:focus { border-color: rgba(6,182,212,0.55); }
        .edit-form-input[type="number"] { font-family: ui-monospace, monospace; }
        .edit-form-textarea {
            resize: vertical;
            min-height: 4.5rem;
        }
        .edit-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 0.8rem;
        }
        .edit-form-grid .full-width { grid-column: 1 / -1; }
        .edit-error-msg {
            margin-top: 0.75rem;
            padding: 0.55rem 0.85rem;
            border-radius: 0.5rem;
            border: 1px solid rgba(239,68,68,0.35);
            background: rgba(239,68,68,0.08);
            color: #fca5a5;
            font-size: 0.8rem;
        }
        .edit-actions {
            display: flex;
            gap: 0.65rem;
            justify-content: flex-end;
            margin-top: 1.1rem;
        }
    </style>
</head>
<body class="bg-zinc-950 text-white font-sans antialiased min-h-screen">

<!-- ── Top bar ──────────────────────────────────────────────────────────── -->
<header class="sticky top-0 z-40 bg-zinc-950/90 border-b border-zinc-800/60" style="backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);">
    <div class="max-w-7xl mx-auto px-4 flex items-center justify-between h-14">
        <a href="dashboard.php" class="flex items-center gap-2 group">
            <span class="w-6 h-6 rounded bg-cyan-500 flex items-center justify-center flex-shrink-0 group-hover:bg-cyan-400 transition-colors">
                <svg class="w-3.5 h-3.5 text-zinc-950" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10 1C6.13 1 3 4.13 3 8v10l2.5-2 2.5 2 2.5-2 2.5 2 2.5-2 2.5 2V8C17 4.13 13.87 1 10 1z"/>
                </svg>
            </span>
            <span class="text-white font-bold text-base tracking-tight">Ghost<span class="text-cyan-400">Laser</span></span>
        </a>
        <div class="flex items-center gap-3">
            <span class="text-xs text-zinc-400 font-medium hidden sm:block">Mileage Tracker</span>
            <a href="dashboard.php" class="nav-btn text-sm">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Dashboard
            </a>
        </div>
    </div>
</header>

<!-- ── Main ─────────────────────────────────────────────────────────────── -->
<main class="max-w-7xl mx-auto px-4 pb-16 pt-6">

    <!-- Page heading -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white leading-tight">IRS Mileage Log</h1>
        <p class="text-sm text-zinc-400 mt-1">All trips recorded by technicians &mdash; suitable for IRS mileage deduction documentation.</p>
    </div>

    <?php if (isset($dbError)): ?>
        <div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
            Database error: <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- Stats row -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="stat-card">
            <div class="text-xs text-zinc-500 font-medium uppercase tracking-widest mb-1">Total Trips</div>
            <div class="text-2xl font-bold text-white"><?= count($logs) ?></div>
        </div>
        <div class="stat-card">
            <div class="text-xs text-zinc-500 font-medium uppercase tracking-widest mb-1">Completed</div>
            <div class="text-2xl font-bold text-cyan-400"><?= $completedTrips ?></div>
        </div>
        <div class="stat-card">
            <div class="text-xs text-zinc-500 font-medium uppercase tracking-widest mb-1">Total Miles</div>
            <div class="text-2xl font-bold text-cyan-400"><?= number_format($totalMiles, 2) ?></div>
        </div>
        <div class="stat-card">
            <div class="text-xs text-zinc-500 font-medium uppercase tracking-widest mb-1">Pending</div>
            <div class="text-2xl font-bold text-yellow-300"><?= count($logs) - $completedTrips ?></div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" action="mileage-tracker.php" class="flex flex-wrap items-end gap-3 mb-5">
        <div class="flex flex-col gap-1">
            <label class="text-xs text-zinc-500 font-medium">From Date</label>
            <input
                type="date"
                name="start"
                value="<?= htmlspecialchars($filterStart, ENT_QUOTES, 'UTF-8') ?>"
                class="filter-input"
            >
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs text-zinc-500 font-medium">To Date</label>
            <input
                type="date"
                name="end"
                value="<?= htmlspecialchars($filterEnd, ENT_QUOTES, 'UTF-8') ?>"
                class="filter-input"
            >
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs text-zinc-500 font-medium">Status</label>
            <select name="status" class="filter-input">
                <option value="">All</option>
                <option value="complete" <?= $filterStatus === 'complete' ? 'selected' : '' ?>>Complete</option>
                <option value="pending"  <?= $filterStatus === 'pending'  ? 'selected' : '' ?>>Pending</option>
            </select>
        </div>
        <button type="submit" class="filter-btn">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            Filter
        </button>
        <a href="mileage-tracker.php" class="reset-btn">Reset</a>
        <a href="mileage-tracker.php?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8') ?>" class="export-btn ml-auto">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Export CSV
        </a>
    </form>

    <!-- Table -->
    <?php if (empty($logs)): ?>
        <div class="flex flex-col items-center justify-center py-20 text-center">
            <div class="w-14 h-14 rounded-2xl bg-zinc-800/80 flex items-center justify-center mb-4">
                <svg class="w-7 h-7 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                </svg>
            </div>
            <p class="text-zinc-300 font-semibold text-base">No mileage records found</p>
            <p class="text-zinc-500 text-sm mt-1">Trips will appear here after technicians use the "On My Way" and "Arrived" buttons.</p>
            <a href="technician-dashboard.php" class="mt-5 inline-flex items-center gap-1.5 rounded-lg border border-zinc-700 px-4 py-2.5 text-sm font-semibold text-zinc-200 hover:border-cyan-400 transition-colors">
                Go to Technician Dashboard
            </a>
        </div>
    <?php else: ?>
        <div class="table-wrap rounded-xl border border-zinc-800/70 overflow-hidden">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Client Name</th>
                        <th>Vehicle</th>
                        <th>Address</th>
                        <th>Purpose</th>
                        <th>Time</th>
                        <th>Odometer</th>
                        <th>Job ID</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $row): ?>
                        <?php $tripMiles = mileageFromOdometer($row['start_mileage'] ?? null, $row['end_mileage'] ?? null); ?>
                        <tr class="log-row" data-log-id="<?= (int) $row['id'] ?>" tabindex="0" role="button" aria-label="Open full trip details for record #<?= (int) $row['id'] ?>">
                            <td class="text-zinc-200 font-medium whitespace-nowrap">
                                <?= htmlspecialchars(fmtDate($row['trip_date']), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-zinc-200">
                                <?= htmlspecialchars($row['client_name'] ?: '—', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="max-w-xs text-zinc-300" style="min-width:200px;">
                                <?= htmlspecialchars(fmtVehicle($row), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="max-w-xs text-zinc-300" style="min-width:160px;">
                                <?= htmlspecialchars($row['address'] ?: '—', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="purpose-cell" style="min-width:220px;max-width:320px;">
                                <?= htmlspecialchars($row['purpose'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="whitespace-nowrap">
                                <div class="text-xs text-zinc-400">Start: <?= htmlspecialchars(fmtDateTime($row['start_time']), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-xs text-zinc-400">End: <?= htmlspecialchars(fmtDateTime($row['end_time']), ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td class="odometer-cell whitespace-nowrap">
                                <div class="text-xs text-zinc-400">Starting: <?= htmlspecialchars(fmtOdometer($row['start_mileage'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-xs text-zinc-400">Ending: <?= htmlspecialchars(fmtOdometer($row['end_mileage'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-xs text-zinc-400">Total: <?= htmlspecialchars(fmtMiles($tripMiles), ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td class="text-zinc-400 font-mono text-xs whitespace-nowrap">
                                #<?= (int) $row['service_request_id'] ?>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'complete'): ?>
                                    <span class="badge-complete">Complete</span>
                                <?php else: ?>
                                    <span class="badge-pending">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="whitespace-nowrap">
                                <button type="button" class="edit-btn" data-edit-id="<?= (int) $row['id'] ?>" onclick="event.stopPropagation()">
                                    <svg style="width:0.7rem;height:0.7rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                    Edit
                                </button>
                                <form method="POST" action="mileage-tracker.php<?= $filterStart !== '' || $filterEnd !== '' || $filterStatus !== '' ? '?' . htmlspecialchars(http_build_query(array_filter(['start' => $filterStart, 'end' => $filterEnd, 'status' => $filterStatus])), ENT_QUOTES, 'UTF-8') : '' ?>" style="display:inline;margin-left:0.35rem;">
                                    <input type="hidden" name="delete_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="delete-btn" onclick="event.stopPropagation(); return confirm('Are you sure you want to delete this mileage record? This action cannot be undone.')">
                                        <svg style="width:0.7rem;height:0.7rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Summary footer -->
        <div class="mt-4 flex flex-wrap items-center gap-4 text-sm text-zinc-400">
            <span><?= count($logs) ?> record<?= count($logs) !== 1 ? 's' : '' ?> shown</span>
            <?php if ($completedTrips > 0): ?>
                <span class="text-cyan-400 font-semibold"><?= number_format($totalMiles, 2) ?> total miles (completed trips)</span>
                <?php
                // IRS standard mileage rate note (2024: 67¢/mile)
                $irsRate   = 0.67;
                $deduction = $totalMiles * $irsRate;
                ?>
                <span class="text-zinc-500">IRS deduction est. @ $<?= number_format($irsRate, 2) ?>/mi: <span class="text-green-400 font-semibold">$<?= number_format($deduction, 2) ?></span></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</main>

<?php if (!empty($logs)): ?>
    <div id="trip-detail-modal" class="modal-backdrop" aria-hidden="true">
        <div class="detail-modal" role="dialog" aria-modal="true" aria-labelledby="trip-detail-title">
            <div class="detail-modal-header">
                <div>
                    <h2 id="trip-detail-title" class="detail-modal-title">Trip Record Details</h2>
                    <div class="detail-modal-subtitle" id="trip-detail-subtitle">IRS Business Purpose &amp; Trip Details</div>
                </div>
                <button type="button" class="detail-close" id="trip-detail-close" aria-label="Close details modal">&times;</button>
            </div>
            <div class="detail-modal-body">
                <div id="trip-detail-grid" class="detail-grid"></div>
            </div>
        </div>
    </div>

    <!-- Edit Trip Modal -->
    <div id="edit-modal" class="modal-backdrop" aria-hidden="true">
        <div class="detail-modal" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
            <div class="detail-modal-header">
                <div>
                    <h2 id="edit-modal-title" class="detail-modal-title">Edit Trip Record</h2>
                    <div class="detail-modal-subtitle" id="edit-modal-subtitle">Make corrections and click Save</div>
                </div>
                <button type="button" class="detail-close" id="edit-modal-close" aria-label="Close edit modal">&times;</button>
            </div>
            <div class="detail-modal-body">
                <form id="edit-modal-form" novalidate>
                    <input type="hidden" id="edit-id" name="id">
                    <div class="edit-form-grid">
                        <div class="edit-form-group">
                            <label class="edit-form-label" for="edit-trip-date">Trip Date</label>
                            <input id="edit-trip-date" type="date" class="edit-form-input" name="trip_date">
                        </div>
                        <div class="edit-form-group">
                            <label class="edit-form-label" for="edit-status">Status</label>
                            <select id="edit-status" class="edit-form-input" name="status">
                                <option value="pending">Pending</option>
                                <option value="complete">Complete</option>
                            </select>
                        </div>
                        <div class="edit-form-group">
                            <label class="edit-form-label" for="edit-vehicle-id">Vehicle</label>
                            <select id="edit-vehicle-id" class="edit-form-input" name="vehicle_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($vehicles as $vehicle): ?>
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
                                    if ((int) $vehicle['is_active'] !== 1) {
                                        $vehicleLabel .= ' (Inactive)';
                                    }
                                    ?>
                                    <option value="<?= (int) $vehicle['id'] ?>"><?= htmlspecialchars($vehicleLabel !== '' ? $vehicleLabel : ('Vehicle #' . (int) $vehicle['id']), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="edit-form-group">
                            <label class="edit-form-label" for="edit-start-time">Start Date &amp; Time</label>
                            <input id="edit-start-time" type="datetime-local" class="edit-form-input" name="start_time">
                        </div>
                        <div class="edit-form-group">
                            <label class="edit-form-label" for="edit-end-time">End Date &amp; Time</label>
                            <input id="edit-end-time" type="datetime-local" class="edit-form-input" name="end_time">
                        </div>
                        <div class="edit-form-group">
                            <label class="edit-form-label" for="edit-start-mileage">Starting Odometer (mi)</label>
                            <input id="edit-start-mileage" type="number" min="0" step="1" class="edit-form-input" name="start_mileage" placeholder="e.g. 45200">
                        </div>
                        <div class="edit-form-group">
                            <label class="edit-form-label" for="edit-end-mileage">Ending Odometer (mi)</label>
                            <input id="edit-end-mileage" type="number" min="0" step="1" class="edit-form-input" name="end_mileage" placeholder="e.g. 45247">
                        </div>
                        <div class="edit-form-group full-width">
                            <label class="edit-form-label" for="edit-client-name">Client Name</label>
                            <input id="edit-client-name" type="text" class="edit-form-input" name="client_name" placeholder="Client name">
                        </div>
                        <div class="edit-form-group full-width">
                            <label class="edit-form-label" for="edit-address">Address</label>
                            <input id="edit-address" type="text" class="edit-form-input" name="address" placeholder="Service address">
                        </div>
                        <div class="edit-form-group full-width">
                            <label class="edit-form-label" for="edit-notes">Business Purpose <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#52525b;">(leave blank to auto-generate from job details)</span></label>
                            <textarea id="edit-notes" class="edit-form-input edit-form-textarea" name="notes" placeholder="e.g. Epilog Fusion Pro 48 — Maintenance & Alignment — Beam misalignment after tube change"></textarea>
                        </div>
                    </div>
                    <div id="edit-error" class="edit-error-msg" style="display:none;"></div>
                    <div class="edit-actions">
                        <button type="button" id="edit-cancel-btn" class="reset-btn">Cancel</button>
                        <button type="submit" id="edit-save-btn" class="filter-btn">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const rowsData = <?= json_encode($logs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const rowMap = new Map();
            rowsData.forEach((record, index) => {
                rowMap.set(String(record.id ?? index), record);
            });

            const modal = document.getElementById('trip-detail-modal');
            const modalClose = document.getElementById('trip-detail-close');
            const modalGrid = document.getElementById('trip-detail-grid');
            const modalSubtitle = document.getElementById('trip-detail-subtitle');
            const clickableRows = document.querySelectorAll('tr.log-row[data-log-id]');
            let lastActiveRow = null;

            const escapeHtml = (value) => String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const normalizeValue = (value) => {
                if (value === null || value === '') return '—';
                if (typeof value === 'object') return JSON.stringify(value);
                return String(value);
            };

            const toLabel = (key) => key
                .replace(/_/g, ' ')
                .replace(/\b\w/g, (char) => char.toUpperCase());

            const renderDetails = (record) => {
                // Show Purpose prominently at the top, then all other fields.
                const purpose = record.purpose || '—';
                const purposeHtml = `<div class="detail-item" style="grid-column:1/-1;border-color:rgba(6,182,212,0.35);background:rgba(6,182,212,0.06);">
                    <div class="detail-key" style="color:#22d3ee;">Business Purpose (IRS)</div>
                    <div class="detail-value" style="color:#f4f4f5;font-family:inherit;font-size:0.88rem;font-weight:600;">${escapeHtml(purpose)}</div>
                </div>`;

                // Skip the pre-computed 'purpose' key and raw service-request fields that
                // are already captured in the purpose string; show everything else.
                const skipKeys = new Set(['purpose', 'laser_brand', 'laser_model', 'problem_summary', 'services']);
                const fields = Object.entries(record).filter(([key]) => !skipKeys.has(key));
                const fieldsHtml = fields.map(([key, value]) => (
                    `<div class="detail-item">
                        <div class="detail-key">${escapeHtml(toLabel(key))}</div>
                        <div class="detail-value">${escapeHtml(normalizeValue(value))}</div>
                    </div>`
                )).join('');

                modalGrid.innerHTML = purposeHtml + fieldsHtml;
            };

            const closeModal = () => {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                if (lastActiveRow) {
                    lastActiveRow.focus();
                    lastActiveRow = null;
                }
            };

            const openModal = (rowEl) => {
                const record = rowMap.get(rowEl.dataset.logId);
                if (!record) return;
                lastActiveRow = rowEl;
                renderDetails(record);
                modalSubtitle.textContent = `Record #${record.id ?? '—'} • Job #${record.service_request_id ?? '—'}`;
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                modalClose.focus();
            };

            clickableRows.forEach((rowEl) => {
                rowEl.addEventListener('click', (event) => {
                    if (event.target.closest('button, a, input, select, textarea, form, label')) return;
                    openModal(rowEl);
                });

                rowEl.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        openModal(rowEl);
                    }
                });
            });

            modalClose.addEventListener('click', closeModal);
            modal.addEventListener('click', (event) => {
                if (event.target === modal) closeModal();
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                }
            });

            // ── Edit modal ────────────────────────────────────────────────────
            const editModal        = document.getElementById('edit-modal');
            const editModalClose   = document.getElementById('edit-modal-close');
            const editModalSubtitle = document.getElementById('edit-modal-subtitle');
            const editForm         = document.getElementById('edit-modal-form');
            const editCancelBtn    = document.getElementById('edit-cancel-btn');
            const editSaveBtn      = document.getElementById('edit-save-btn');
            const editError        = document.getElementById('edit-error');

            const toInputDatetime = (dt) => {
                if (!dt) return '';
                return String(dt).replace(' ', 'T').substring(0, 16);
            };

            const closeEditModal = () => {
                editModal.classList.remove('is-open');
                editModal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            };

            const openEditModal = (logId) => {
                const record = rowMap.get(String(logId));
                if (!record) return;

                document.getElementById('edit-id').value           = record.id ?? '';
                document.getElementById('edit-trip-date').value    = (record.trip_date ?? '').substring(0, 10);
                document.getElementById('edit-start-time').value   = toInputDatetime(record.start_time);
                document.getElementById('edit-end-time').value     = toInputDatetime(record.end_time);
                document.getElementById('edit-start-mileage').value = record.start_mileage ?? '';
                document.getElementById('edit-end-mileage').value  = record.end_mileage ?? '';
                document.getElementById('edit-client-name').value  = record.client_name ?? '';
                document.getElementById('edit-address').value      = record.address ?? '';
                document.getElementById('edit-notes').value        = record.notes ?? '';
                document.getElementById('edit-status').value       = record.status ?? 'pending';
                document.getElementById('edit-vehicle-id').value   = record.vehicle_id ?? '';

                editModalSubtitle.textContent = `Record #${record.id ?? '—'} • Job #${record.service_request_id ?? '—'}`;
                editError.style.display = 'none';
                editSaveBtn.disabled    = false;
                editSaveBtn.textContent = 'Save Changes';

                editModal.classList.add('is-open');
                editModal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                editModalClose.focus();
            };

            document.querySelectorAll('.edit-btn[data-edit-id]').forEach((btn) => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    openEditModal(btn.dataset.editId);
                });
            });

            editModalClose.addEventListener('click', closeEditModal);
            editCancelBtn.addEventListener('click', closeEditModal);
            editModal.addEventListener('click', (e) => { if (e.target === editModal) closeEditModal(); });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && editModal.classList.contains('is-open')) closeEditModal();
            });

            editForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                editSaveBtn.disabled    = true;
                editSaveBtn.textContent = 'Saving…';
                editError.style.display = 'none';

                const val = (id) => document.getElementById(id).value.trim();
                const toApiDatetime = (v) => v ? v.replace('T', ' ') + ':00' : null;
                const toInt = (v) => v !== '' ? parseInt(v, 10) : null;
                const toNullableInt = (v) => v !== '' ? parseInt(v, 10) : null;

                const payload = {
                    action:         'update',
                    id:             parseInt(val('edit-id'), 10),
                    trip_date:      val('edit-trip-date') || null,
                    start_time:     toApiDatetime(val('edit-start-time')),
                    end_time:       toApiDatetime(val('edit-end-time')),
                    start_mileage:  toInt(val('edit-start-mileage')),
                    end_mileage:    toInt(val('edit-end-mileage')),
                    client_name:    val('edit-client-name'),
                    address:        val('edit-address'),
                    notes:          val('edit-notes'),
                    vehicle_id:     toNullableInt(val('edit-vehicle-id')),
                    status:         val('edit-status'),
                };

                try {
                    const resp   = await fetch('api/mileage-api.php', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify(payload),
                    });
                    const result = await resp.json();
                    if (result.success) {
                        closeEditModal();
                        window.location.reload();
                    } else {
                        editError.textContent   = result.error || 'Failed to save. Please try again.';
                        editError.style.display = 'block';
                        editSaveBtn.disabled    = false;
                        editSaveBtn.textContent = 'Save Changes';
                    }
                } catch {
                    editError.textContent   = 'Network error. Please try again.';
                    editError.style.display = 'block';
                    editSaveBtn.disabled    = false;
                    editSaveBtn.textContent = 'Save Changes';
                }
            });
        })();
    </script>
<?php endif; ?>

</body>
</html>