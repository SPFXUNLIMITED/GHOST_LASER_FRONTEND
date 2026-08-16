<?php
/**
 * api/send-to-phone.php
 *
 * Saves a phone number to a shared temp file so call.php can retrieve it
 * on any device by polling api/get-phone-number.php.
 *
 * POST body (JSON): { "number": "<phone>" }
 * Response (JSON):  { "success": true }
 */

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$number = isset($body['number']) ? trim($body['number']) : '';

if ($number === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing number.']);
    exit;
}

// Strip everything except digits, spaces, parens, dashes, and plus
$number = preg_replace('/[^\d\s()\-+]/', '', $number);

$company = isset($body['company']) ? trim((string) $body['company']) : '';
// Strip HTML tags and limit length
$company = strip_tags($company);
$company = mb_substr($company, 0, 200);

$file = sys_get_temp_dir() . '/ghost_call_number.json';

$data = json_encode([
    'number'    => $number,
    'company'   => $company,
    'timestamp' => time(),
]);

if (file_put_contents($file, $data, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save number.']);
    exit;
}

echo json_encode(['success' => true]);
