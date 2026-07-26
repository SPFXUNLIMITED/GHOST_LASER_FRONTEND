<?php
/**
 * api/mileage-api.php – Mileage tracking API for IRS-compliant mileage logs.
 *
 * POST actions:
 *   on_my_way – Creates a pending mileage record with start data.
 *   arrived   – Completes the record with end data and calculates miles.
 *
 * All timestamps are stored in America/Los_Angeles timezone.
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 43200); // 12 hours — matches technician-dashboard.php
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../project/db.php';

// ── Ensure mileage_logs table exists ─────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS mileage_logs (
        id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        service_request_id INT UNSIGNED NOT NULL,
        client_name        VARCHAR(255)  NOT NULL DEFAULT '',
        address            VARCHAR(500)  NOT NULL DEFAULT '',
        trip_date          DATE          NULL,
        start_time         DATETIME      NULL COMMENT 'LA timezone',
        end_time           DATETIME      NULL COMMENT 'LA timezone',
        start_lat          DECIMAL(10,7) NULL,
        start_lng          DECIMAL(10,7) NULL,
        end_lat            DECIMAL(10,7) NULL,
        end_lng            DECIMAL(10,7) NULL,
        start_mileage      INT UNSIGNED  NULL COMMENT 'Odometer at departure',
        end_mileage        INT UNSIGNED  NULL COMMENT 'Odometer at arrival',
        total_miles        DECIMAL(8,2)  NULL,
        status             ENUM('pending','complete') NOT NULL DEFAULT 'pending',
        created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_service_request (service_request_id),
        INDEX idx_trip_date (trip_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Migrate: add mileage columns to existing tables ───────────────────────────
foreach (['start_mileage INT UNSIGNED NULL COMMENT \'Odometer at departure\' AFTER end_lng',
          'end_mileage   INT UNSIGNED NULL COMMENT \'Odometer at arrival\'   AFTER start_mileage',
          'notes         VARCHAR(1000) NULL COMMENT \'Admin override for business purpose\' AFTER end_mileage'] as $colDef) {
    $col = strtok($colDef, ' ');
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'mileage_logs'
           AND COLUMN_NAME  = '$col'"
    )->fetchColumn();
    if (!$exists) {
        $pdo->exec("ALTER TABLE mileage_logs ADD COLUMN $colDef");
    }
}

// ── Parse body ────────────────────────────────────────────────────────────────
$raw    = file_get_contents('php://input');
$data   = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$action = trim((string) ($data['action'] ?? ''));

// ── Helper: get LA datetime ───────────────────────────────────────────────────
function nowLa(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles'));
}

function laDateTimeString(): string
{
    return nowLa()->format('Y-m-d H:i:s');
}

function laDateString(): string
{
    return nowLa()->format('Y-m-d');
}

function buildBusinessPurpose(array $row): string
{
    static $serviceLabels = [
        'maintenance_alignment' => 'Maintenance & Alignment',
        'tube_change'           => 'Tube Change',
        'diagnosis'             => 'Diagnosis',
        'training'              => 'Training',
        'other'                 => 'Other',
    ];

    $parts = [];

    $brand   = trim((string) ($row['laser_brand'] ?? ''));
    $model   = trim((string) ($row['laser_model'] ?? ''));
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

    return $parts !== [] ? implode(' — ', $parts) : '';
}

function generatedPurposeForServiceRequest(PDO $pdo, int $serviceRequestId): ?string
{
    if ($serviceRequestId <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT laser_brand, laser_model, problem_summary, services
             FROM service_requests
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $serviceRequestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }

    if (!$row) {
        return null;
    }

    $purpose = trim(buildBusinessPurpose($row));
    return $purpose !== '' ? $purpose : null;
}

// ── Helper: validate coordinate ───────────────────────────────────────────────
function validCoord(?string $v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    $f = filter_var($v, FILTER_VALIDATE_FLOAT);
    return $f === false ? null : $f;
}

// ═════════════════════════════════════════════════════════════════════════════
// Action: on_my_way
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'on_my_way') {
    $serviceRequestId = (int) ($data['service_request_id'] ?? 0);
    $clientName       = trim((string) ($data['client_name'] ?? ''));
    $address          = trim((string) ($data['address'] ?? ''));
    $startLat         = validCoord((string) ($data['start_lat'] ?? ''));
    $startLng         = validCoord((string) ($data['start_lng'] ?? ''));
    $startMileage     = isset($data['start_mileage']) ? (int) $data['start_mileage'] : null;
    $notes            = trim((string) ($data['notes'] ?? ''));

    if ($serviceRequestId < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing service_request_id']);
        exit;
    }

    $startTime = laDateTimeString();
    $tripDate  = laDateString();
    $autoPurpose = generatedPurposeForServiceRequest($pdo, $serviceRequestId);
    $notesToPersist = $notes !== '' ? $notes : $autoPurpose;

    // Upsert: if a pending record already exists for this job today, update it
    $existing = $pdo->prepare(
        "SELECT id, notes FROM mileage_logs
         WHERE service_request_id = :sri AND status = 'pending'
         ORDER BY id DESC LIMIT 1"
    );
    $existing->execute([':sri' => $serviceRequestId]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $stmt = $pdo->prepare(
            "UPDATE mileage_logs
             SET client_name    = :cn,
                 address        = :addr,
                 trip_date      = :td,
                 start_time     = :st,
                 start_lat      = :slat,
                 start_lng      = :slng,
                 start_mileage  = :sm,
                 notes          = :notes
             WHERE id = :id"
        );
        $existingNotes = trim((string) ($row['notes'] ?? ''));
        if ($existingNotes !== '') {
            $notesToPersist = $existingNotes;
        }
        $stmt->execute([
            ':cn'   => $clientName,
            ':addr' => $address,
            ':td'   => $tripDate,
            ':st'   => $startTime,
            ':slat' => $startLat,
            ':slng' => $startLng,
            ':sm'   => $startMileage,
            ':notes'=> $notesToPersist,
            ':id'   => $row['id'],
        ]);
        $logId = $row['id'];
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO mileage_logs
                (service_request_id, client_name, address, trip_date, start_time, start_lat, start_lng, start_mileage, notes, status)
             VALUES
                (:sri, :cn, :addr, :td, :st, :slat, :slng, :sm, :notes, 'pending')"
        );
        $stmt->execute([
            ':sri'  => $serviceRequestId,
            ':cn'   => $clientName,
            ':addr' => $address,
            ':td'   => $tripDate,
            ':st'   => $startTime,
            ':slat' => $startLat,
            ':slng' => $startLng,
            ':sm'   => $startMileage,
            ':notes'=> $notesToPersist,
        ]);
        $logId = (int) $pdo->lastInsertId();
    }

    echo json_encode([
        'success'    => true,
        'log_id'     => $logId,
        'start_time' => $startTime,
        'trip_date'  => $tripDate,
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// Action: arrived
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'arrived') {
    $serviceRequestId = (int) ($data['service_request_id'] ?? 0);
    $endLat           = validCoord((string) ($data['end_lat'] ?? ''));
    $endLng           = validCoord((string) ($data['end_lng'] ?? ''));
    $endMileage       = isset($data['end_mileage']) ? (int) $data['end_mileage'] : null;

    if ($serviceRequestId < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing service_request_id']);
        exit;
    }

    // Find the most recent pending record for this job
    $stmt = $pdo->prepare(
        "SELECT * FROM mileage_logs
         WHERE service_request_id = :sri AND status = 'pending'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':sri' => $serviceRequestId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No pending on_my_way record found for this job. Please tap "On My Way" first.']);
        exit;
    }

    if ($log['start_mileage'] === null || $endMileage === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Both start and end odometer readings are required.']);
        exit;
    }

    $startMileage = (int) $log['start_mileage'];
    if ($endMileage < $startMileage) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'End mileage cannot be less than start mileage.']);
        exit;
    }

    $endTime    = laDateTimeString();
    $totalMiles = round((float) ($endMileage - $startMileage), 2);

    $update = $pdo->prepare(
        "UPDATE mileage_logs
         SET end_time    = :et,
             end_lat     = :elat,
             end_lng     = :elng,
             end_mileage = :em,
             total_miles = :miles,
             status      = 'complete'
         WHERE id = :id"
    );
    $update->execute([
        ':et'    => $endTime,
        ':elat'  => $endLat,
        ':elng'  => $endLng,
        ':em'    => $endMileage,
        ':miles' => $totalMiles,
        ':id'    => $log['id'],
    ]);

    echo json_encode([
        'success'     => true,
        'log_id'      => $log['id'],
        'end_time'    => $endTime,
        'total_miles' => $totalMiles,
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// Action: update
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'update') {
    $id           = (int) ($data['id'] ?? 0);
    $tripDate     = trim((string) ($data['trip_date']     ?? ''));
    $startTime    = trim((string) ($data['start_time']    ?? ''));
    $endTime      = trim((string) ($data['end_time']      ?? ''));
    $startMileage = ($data['start_mileage'] !== '' && $data['start_mileage'] !== null) ? (int) $data['start_mileage'] : null;
    $endMileage   = ($data['end_mileage']   !== '' && $data['end_mileage']   !== null) ? (int) $data['end_mileage']   : null;
    $clientName   = trim((string) ($data['client_name']   ?? ''));
    $address      = trim((string) ($data['address']       ?? ''));
    $notes        = trim((string) ($data['notes']         ?? ''));
    $status       = trim((string) ($data['status']        ?? 'pending'));

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid record ID']);
        exit;
    }

    if (!in_array($status, ['pending', 'complete'], true)) {
        $status = 'pending';
    }

    $tripDateVal  = ($tripDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tripDate)) ? $tripDate : null;

    // datetime-local sends 'YYYY-MM-DDTHH:MM'; store as 'YYYY-MM-DD HH:MM:SS'
    $toDbDatetime = static function (string $val): ?string {
        if ($val === '') return null;
        $val = str_replace('T', ' ', $val);
        if (strlen($val) === 16) $val .= ':00';
        return $val;
    };
    $startTimeVal = $toDbDatetime($startTime);
    $endTimeVal   = $toDbDatetime($endTime);

    // Recalculate total_miles from odometer readings when both are present
    $totalMiles = null;
    if ($startMileage !== null && $endMileage !== null) {
        if ($endMileage < $startMileage) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'End mileage cannot be less than start mileage.']);
            exit;
        }
        $totalMiles = round((float) ($endMileage - $startMileage), 2);
    }

    // Verify record exists
    $check = $pdo->prepare("SELECT id FROM mileage_logs WHERE id = :id");
    $check->execute([':id' => $id]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Record not found']);
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE mileage_logs
         SET trip_date     = :td,
             start_time    = :st,
             end_time      = :et,
             start_mileage = :sm,
             end_mileage   = :em,
             total_miles   = :miles,
             client_name   = :cn,
             address       = :addr,
             notes         = :notes,
             status        = :status
         WHERE id = :id"
    );
    $stmt->execute([
        ':td'     => $tripDateVal,
        ':st'     => $startTimeVal,
        ':et'     => $endTimeVal,
        ':sm'     => $startMileage,
        ':em'     => $endMileage,
        ':miles'  => $totalMiles,
        ':cn'     => $clientName,
        ':addr'   => $address,
        ':notes'  => $notes !== '' ? $notes : null,
        ':status' => $status,
        ':id'     => $id,
    ]);

    echo json_encode(['success' => true]);
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action']);
