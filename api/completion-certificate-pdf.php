<?php

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 43200);
    session_start();
}

require_once __DIR__ . '/../project/technician_dashboard_auth.php';

if (!technicianDashboardHasAccess()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unauthorized';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Method not allowed';
    exit;
}

require_once __DIR__ . '/../project/db.php';
require_once __DIR__ . '/../project/service_authorization.php';
require_once __DIR__ . '/../project/completion_certificate.php';

$serviceRequestId = (int) ($_GET['service_request_id'] ?? 0);
if ($serviceRequestId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Missing service_request_id';
    exit;
}

if (!technicianDashboardCanAccessServiceRequest($pdo, $serviceRequestId)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'You do not have access to this completion certificate.';
    exit;
}

try {
    $pdf = completionCertificateLoadOrGenerateByServiceRequest($pdo, $serviceRequestId);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($pdf['filename']) . '"');
    header('Content-Length: ' . strlen($pdf['content']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $pdf['content'];
} catch (RuntimeException $e) {
    $isMissing = $e->getMessage() === 'Completion certificate record not found.';
    http_response_code($isMissing ? 404 : 500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $isMissing ? 'Completion certificate not found.' : 'Unable to generate completion certificate PDF.';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to generate completion certificate PDF.';
}
