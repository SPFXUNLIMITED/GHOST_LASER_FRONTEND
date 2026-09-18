<?php

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 43200);
    session_start();
}

if (empty($_SESSION['admin_id'])) {
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
if ($authorizationId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Missing authorization_id';
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
