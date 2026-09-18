<?php

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 43200);
    session_start();
}

require_once __DIR__ . '/../project/technician_dashboard_auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (!technicianDashboardHasAccess()) {
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
require_once __DIR__ . '/../project/service_authorization.php';

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$serviceRequestId = (int) ($body['service_request_id'] ?? 0);
$technicianNotes  = (string) ($body['technician_notes'] ?? '');
$csrfToken        = (string) ($body['csrf_token'] ?? '');

if ($serviceRequestId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing service_request_id']);
    exit;
}

$sessionCsrf = (string) ($_SESSION['technician_dashboard_csrf'] ?? '');
if ($sessionCsrf === '' || $csrfToken === '' || !hash_equals($sessionCsrf, $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Reload the dashboard and try again.']);
    exit;
}

$job = serviceAuthorizationFetchJob($pdo, $serviceRequestId);
if (!$job) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Service request not found.']);
    exit;
}

if (!technicianDashboardCanAccessServiceRequest($pdo, $serviceRequestId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have access to update this job.']);
    exit;
}

try {
    $result = serviceAuthorizationSaveTechnicianNotes($pdo, $serviceRequestId, $technicianNotes, $job);

    echo json_encode([
        'success' => true,
        'service_request_id' => (int) $result['service_request_id'],
        'technician_notes' => (string) $result['technician_notes'],
        'scope_of_work' => (string) $result['scope_of_work'],
    ]);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Service request not found.') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Service request not found.']);
        exit;
    }
    error_log('technician-notes-api runtime error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unable to save technician notes.']);
} catch (Throwable $e) {
    error_log('technician-notes-api error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save technician notes right now.']);
}
