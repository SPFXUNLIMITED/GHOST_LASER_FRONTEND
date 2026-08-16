<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$csrfSession = (string) ($_SESSION['prospects_csrf'] ?? '');
$csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($csrfSession === '' || $csrfHeader === '' || !hash_equals($csrfSession, $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$rawText = trim((string) ($body['raw_text'] ?? ''));
if ($rawText === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Raw text is required']);
    exit;
}

require_once dirname(__DIR__) . '/project/prospect_tools.php';

$parsed = prospectParseRawText($rawText);

// Duplicate detection: if a company name was parsed, check the DB for a similar prospect.
$duplicate = null;
$parsedCompany = trim((string) ($parsed['fields']['company'] ?? ''));
if ($parsedCompany !== '') {
    try {
        require_once dirname(__DIR__) . '/project/db.php';
        $dup = prospectFindSimilarByCompany($pdo, $parsedCompany);
        if ($dup !== null) {
            $duplicate = [
                'id'             => (int) $dup['id'],
                'company'        => (string) ($dup['company'] ?? ''),
                'phone'          => (string) ($dup['phone'] ?? ''),
                'last_called_at' => (string) ($dup['last_called_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        // Non-fatal: duplicate check failure should not block the parse response.
    }
}

echo json_encode([
    'parsed_fields' => $parsed['fields'],
    'confidence' => $parsed['confidence'],
    'provider' => $parsed['provider'],
    'errors' => $parsed['errors'],
    'duplicate' => $duplicate,
], JSON_UNESCAPED_UNICODE);
