<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once dirname(__DIR__) . '/project/db.php';
require_once dirname(__DIR__) . '/prospects_schema.php';

prospectsEnsureSchema($pdo);

try {
    $rows = $pdo->query(
        "SELECT id, title, subject, body FROM prospect_notification_templates ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['notifications' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load prospect notifications.']);
}
