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
require_once __DIR__ . '/../mileage_schema.php';
ensureMileageVehicleSchema($pdo);

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

function parseNullableInt($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value)) {
        return (int) $value;
    }
    if (is_numeric($value) && (int) $value == (float) $value) {
        return (int) $value;
    }

    return null;
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

try {
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
    $vehicleId        = parseNullableInt($data['vehicle_id'] ?? null);
    $notes            = trim((string) ($data['notes'] ?? ''));

    if ($serviceRequestId < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing service_request_id']);
        exit;
    }
    if ($vehicleId === null || $vehicleId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'A vehicle selection is required.']);
        exit;
    }

    $vehicleStmt = $pdo->prepare("
        SELECT id
        FROM vehicles
        WHERE id = :id
          AND is_active = 1
        LIMIT 1
    ");
    $vehicleStmt->execute([':id' => $vehicleId]);
    if (!$vehicleStmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Selected vehicle is not active.']);
        exit;
    }

    $startTime = laDateTimeString();
    $tripDate  = laDateString();

    // Upsert: if a pending record already exists for this job today, update it
    $existing = $pdo->prepare(
        "SELECT id FROM mileage_logs
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
                 vehicle_id     = :vehicle_id,
                 notes          = :notes
             WHERE id = :id"
        );
        $stmt->execute([
            ':cn'   => $clientName,
            ':addr' => $address,
            ':td'   => $tripDate,
            ':st'   => $startTime,
            ':slat' => $startLat,
            ':slng' => $startLng,
            ':sm'   => $startMileage,
            ':vehicle_id' => $vehicleId,
            ':notes'=> $notes,
            ':id'   => $row['id'],
        ]);
        $logId = $row['id'];
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO mileage_logs
                (service_request_id, client_name, address, trip_date, start_time, start_lat, start_lng, start_mileage, notes, vehicle_id, status)
             VALUES
                (:sri, :cn, :addr, :td, :st, :slat, :slng, :sm, :notes, :vehicle_id, 'pending')"
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
            ':notes'=> $notes,
            ':vehicle_id' => $vehicleId,
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
    $vehicleId    = parseNullableInt($data['vehicle_id'] ?? null);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid record ID']);
        exit;
    }
    if ($vehicleId !== null && $vehicleId > 0) {
        $vehicleStmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = :id LIMIT 1");
        $vehicleStmt->execute([':id' => $vehicleId]);
        if (!$vehicleStmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Selected vehicle does not exist.']);
            exit;
        }
    } elseif ($vehicleId !== null && $vehicleId <= 0) {
        $vehicleId = null;
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
             vehicle_id    = :vehicle_id,
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
        ':vehicle_id' => $vehicleId,
        ':status' => $status,
        ':id'     => $id,
    ]);

    echo json_encode(['success' => true]);
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
