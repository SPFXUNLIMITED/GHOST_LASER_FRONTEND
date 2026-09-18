<?php

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 43200);
    session_start();
}

require_once __DIR__ . '/../project/technician_dashboard_auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
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
$action = strtolower(trim((string) ($body['action'] ?? 'upload')));
$csrfToken = (string) ($body['csrf_token'] ?? '');
$hasDashboardAccess = technicianDashboardHasAccess();
$canAccessServiceRequest = $serviceRequestId > 0 && technicianDashboardCanAccessServiceRequest($pdo, $serviceRequestId);
$job = ($hasDashboardAccess && $canAccessServiceRequest && $serviceRequestId > 0)
    ? serviceAuthorizationFetchJob($pdo, $serviceRequestId)
    : null;

$response = serviceAuthorizationHandleJobPhotoApiRequest(
    $pdo,
    $hasDashboardAccess,
    $canAccessServiceRequest,
    $serviceRequestId,
    $action,
    $csrfToken,
    (string) ($_SESSION['technician_dashboard_csrf'] ?? ''),
    $job,
    serviceAuthorizationNormalizeUploadedFilesArray($_FILES['photos'] ?? null),
    isset($body['photo_path']) ? (string) $body['photo_path'] : null
);

http_response_code((int) $response['status']);
echo json_encode($response['body']);
