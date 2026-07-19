<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 43200);
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

function etaValidCoord($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    $coord = filter_var($value, FILTER_VALIDATE_FLOAT);
    return $coord === false ? null : (float) $coord;
}

function loadTechnicianEtaGoogleMapsApiKey(): string
{
    static $apiKey = null;
    if ($apiKey !== null) {
        return $apiKey;
    }

    $dotenvValues = [];
    $dotenvPath = __DIR__ . '/.env';
    if (is_file($dotenvPath) && is_readable($dotenvPath)) {
        $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || strpos($line, '=') === false) {
                    continue;
                }

                [$key, $value] = array_map('trim', explode('=', $line, 2));
                if ($key === '') {
                    continue;
                }

                if (strlen($value) >= 2) {
                    if (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }

                $dotenvValues[$key] = $value;
            }
        }
    }

    foreach ([
        getenv('GOOGLE_MAPS_API_KEY'),
        getenv('REDIRECT_GOOGLE_MAPS_API_KEY'),
        $_ENV['GOOGLE_MAPS_API_KEY'] ?? null,
        $_SERVER['GOOGLE_MAPS_API_KEY'] ?? null,
        $_SERVER['REDIRECT_GOOGLE_MAPS_API_KEY'] ?? null,
        $dotenvValues['GOOGLE_MAPS_API_KEY'] ?? null,
    ] as $candidate) {
        if ($candidate !== null && trim((string) $candidate) !== '') {
            $apiKey = trim((string) $candidate);
            return $apiKey;
        }
    }

    $cfg = require __DIR__ . '/../config.php';
    $configuredKey = trim((string) (($cfg['google_maps']['api_key'] ?? '')));
    $apiKey = $configuredKey;
    return $apiKey;
}

function fetchDistanceMatrix(array $query): ?array
{
    $url = 'https://maps.googleapis.com/maps/api/distancematrix/json?' . http_build_query($query);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($ch);
        curl_close($ch);

        if ($response === false || $curlError !== 0 || $statusCode >= 400) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function formatEtaLabel(int $seconds): string
{
    $minutes = max(1, (int) round($seconds / 60));
    if ($minutes < 60) {
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' away';
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;
    if ($remainingMinutes === 0) {
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' away';
    }

    return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ' . $remainingMinutes . ' minute' . ($remainingMinutes === 1 ? '' : 's') . ' away';
}

$originLat = etaValidCoord($data['origin_lat'] ?? null);
$originLng = etaValidCoord($data['origin_lng'] ?? null);
$destination = trim((string) ($data['destination'] ?? ''));

if ($originLat === null || $originLng === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing origin coordinates']);
    exit;
}

if ($destination === '' || $destination === 'Address unavailable') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Customer address unavailable']);
    exit;
}

$apiKey = loadTechnicianEtaGoogleMapsApiKey();
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Google Maps API key is not configured']);
    exit;
}

$baseQuery = [
    'origins' => sprintf('%.7F,%.7F', $originLat, $originLng),
    'destinations' => $destination,
    'mode' => 'driving',
    'units' => 'imperial',
    'key' => $apiKey,
];

// Try with traffic-aware parameters first (requires billing); fall back to basic driving time.
$response = fetchDistanceMatrix(array_merge($baseQuery, [
    'departure_time' => 'now',
    'traffic_model' => 'best_guess',
]));

if (!is_array($response) || ($response['status'] ?? '') !== 'OK') {
    $response = fetchDistanceMatrix($baseQuery);
}

if (!is_array($response) || ($response['status'] ?? '') !== 'OK') {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Unable to calculate ETA right now']);
    exit;
}

$element = $response['rows'][0]['elements'][0] ?? null;
if (!is_array($element) || ($element['status'] ?? '') !== 'OK') {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Unable to calculate ETA for this address']);
    exit;
}

$durationSeconds = $element['duration_in_traffic']['value'] ?? $element['duration']['value'] ?? null;
if (!is_numeric($durationSeconds)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Unable to read ETA from Google Maps']);
    exit;
}

$etaLabel = formatEtaLabel((int) $durationSeconds);
$message = 'Your Ghost Laser technician is about ' . $etaLabel . '.';

echo json_encode([
    'success' => true,
    'eta_label' => $etaLabel,
    'message' => $message,
]);
