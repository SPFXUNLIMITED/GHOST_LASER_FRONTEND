<?php
/**
 * api/session-ping.php
 *
 * Lightweight keepalive endpoint. Touching the session resets the server-side
 * idle timer so long-lived pages (e.g. call.php) don't get logged out.
 * Returns 200 {"ok":true} for an authenticated admin, 403 otherwise.
 */
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Writing to the session ensures the session file's mtime is updated,
// which resets the server-side gc_maxlifetime idle clock.
$_SESSION['_ping'] = time();

echo json_encode(['ok' => true]);
