<?php
header('Content-Type: application/json');

$key = '';

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, 'GOOGLE_API_KEY=') === 0) {
            $key = trim(substr($line, 15));
            break;
        }
    }
}

if (empty($key)) {
    echo json_encode(['success' => false, 'message' => 'API key not found']);
    exit;
}

$input = file_get_contents('php://input');
$req = json_decode($input, true);

$lat = isset($req) ? $req : null;
$lng = isset($req) ? $req : null;
$dest = isset($req) ? trim($req) : '';

if (!$lat || !$lng || !$dest) {
    echo '{"success":true,"message":"I\'m on my way! I should be there shortly."}';
    exit;
}

$url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=" . $lat . "," . $lng . "&destinations=" . urlencode($dest) . "&mode=driving&key=" . $key;

$response = @file_get_contents($url);
$data = json_decode($response, true);

$message = "I'm on my way! I should be there shortly.";

if ($data && isset($data [0] [0]['status' 0] [0 0]['elements'][0]  ;
    $minutes = round($seconds / 60);
    $message = "I'm on my way! I should be there in about " . $minutes . " minutes.";
}

echo json_encode([
    'success' => true,
    'message' => $message
]);
?>