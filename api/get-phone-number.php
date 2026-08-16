<?php
/**
 * api/get-phone-number.php
 *
 * Returns the most recently saved phone number so call.php can poll it.
 *
 * GET response (JSON): { "number": "<phone>", "timestamp": <unix> }
 *                  or: { "number": "", "timestamp": 0 }  when nothing is saved
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$file = sys_get_temp_dir() . '/ghost_call_number.json';

if (!file_exists($file)) {
    echo json_encode(['number' => '', 'timestamp' => 0]);
    exit;
}

$raw = file_get_contents($file);
$data = json_decode($raw, true);

if (!is_array($data) || !isset($data['number'])) {
    echo json_encode(['number' => '', 'timestamp' => 0]);
    exit;
}

echo json_encode([
    'number'    => $data['number'],
    'company'   => $data['company'] ?? '',
    'timestamp' => (int)($data['timestamp'] ?? 0),
]);
