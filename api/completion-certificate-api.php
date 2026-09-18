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
require_once __DIR__ . '/../project/completion_certificate.php';

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$serviceRequestId = (int) ($body['service_request_id'] ?? 0);
$signature        = (string) ($body['signature_png'] ?? '');
$signedAt         = isset($body['signed_at']) ? (string) $body['signed_at'] : null;
$csrfToken        = (string) ($body['csrf_token'] ?? '');
$latitude         = filter_var($body['latitude'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
$longitude        = filter_var($body['longitude'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);

if ($serviceRequestId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing service_request_id']);
    exit;
}

if ($signature === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A signature is required.']);
    exit;
}

$sessionCsrf = (string) ($_SESSION['technician_dashboard_csrf'] ?? '');
if ($sessionCsrf === '' || $csrfToken === '' || !hash_equals($sessionCsrf, $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Reload the dashboard and try again.']);
    exit;
}

if (!technicianDashboardCanAccessServiceRequest($pdo, $serviceRequestId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have access to generate this certificate.']);
    exit;
}

if ($latitude === null || $longitude === null || $latitude === false || $longitude === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid GPS coordinates are required when signing.']);
    exit;
}

if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'GPS coordinates are out of range.']);
    exit;
}

try {
    $certificate = completionCertificateSave(
        $pdo,
        $serviceRequestId,
        $signature,
        (float) $latitude,
        (float) $longitude,
        $signedAt
    );
    completionCertificateGenerateAndStoreByServiceRequest($pdo, $serviceRequestId);

    echo json_encode([
        'success' => true,
        'certificate' => [
            'id' => (int) ($certificate['id'] ?? 0),
            'service_request_id' => (int) ($certificate['service_request_id'] ?? $serviceRequestId),
            'signed_at' => (string) ($certificate['signed_at'] ?? ''),
            'signed_at_display' => serviceAuthorizationFormatSignedAtDisplay((string) ($certificate['signed_at'] ?? '')),
            'latitude' => $certificate['signed_latitude'] !== null ? (float) $certificate['signed_latitude'] : null,
            'longitude' => $certificate['signed_longitude'] !== null ? (float) $certificate['signed_longitude'] : null,
            'download_url' => '/api/completion-certificate-pdf.php?service_request_id=' . $serviceRequestId,
        ],
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save completion certificate right now.']);
}
