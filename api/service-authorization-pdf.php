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

$authorizationId = (int) ($_GET['authorization_id'] ?? 0);
$downloadToken   = (string) ($_GET['token'] ?? '');
if ($authorizationId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Missing authorization_id';
    exit;
}

if (!serviceAuthorizationVerifyDownloadToken($authorizationId, $downloadToken)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid download token.';
    exit;
}

if (!technicianDashboardCanAccessAuthorization($pdo, $authorizationId)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'You do not have access to this authorization.';
    exit;
}

try {
    $pdf = serviceAuthorizationGeneratePdf($pdo, $authorizationId);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($pdf['filename']) . '"');
    header('Content-Length: ' . strlen($pdf['content']));
    header('X-Content-Type-Options: nosniff');
    echo $pdf['content'];
} catch (RuntimeException $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to generate PDF.';
}
