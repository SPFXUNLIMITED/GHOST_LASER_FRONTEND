<?php
require_once __DIR__ . '/../bootstrap_env.php';

header('Content-Type: application/json');

/**
 * Reads an environment variable from the server environment, $_ENV or $_SERVER.
 * Values defined in the repository root .env file are loaded by bootstrap_env.php.
 */
function load_env_value(string $key): string {
    foreach ([
        getenv($key),
        getenv('REDIRECT_' . $key),
        $_ENV[$key] ?? null,
        $_SERVER[$key] ?? null,
        $_SERVER['REDIRECT_' . $key] ?? null,
    ] as $candidate) {
        if ($candidate !== null && trim((string) $candidate) !== '') {
            return trim((string) $candidate);
        }
    }

    return '';
}

$key = load_env_value('GOOGLE_MAPS_API_KEY');

if ($key === '') {
    $cfg = require __DIR__ . '/../config.php';
    $key = trim((string) ($cfg['google_maps']['api_key'] ?? ''));
}

if ($key === '') {
    echo json_encode(['success' => false, 'message' => 'API key not configured.']);
    exit;
}

$input = file_get_contents('php://input');
$req   = json_decode($input, true);

$lat  = isset($req['origin_lat'])  ? $req['origin_lat']  : null;
$lng  = isset($req['origin_lng'])  ? $req['origin_lng']  : null;
$dest = isset($req['destination']) ? trim($req['destination']) : '';

if (!$lat || !$lng || !$dest) {
    echo json_encode(['success' => true, 'message' => "Laser Technician: I'm on my way! I should be there shortly."]);
    exit;
}

$url = 'https://maps.googleapis.com/maps/api/distancematrix/json?'
     . http_build_query([
         'origins'        => $lat . ',' . $lng,
         'destinations'   => $dest,
         'mode'           => 'driving',
         'departure_time' => 'now',
         'traffic_model'  => 'best_guess',
         'key'            => $key,
     ]);

$response = @file_get_contents($url);
$data     = $response !== false ? json_decode($response, true) : null;

/**
 * Turns a minute count into a human-readable duration such as
 * "45 minutes", "1 hour" or "2 hours 15 minutes".
 */
function format_eta_duration(int $minutes): string {
    if ($minutes < 1) {
        $minutes = 1;
    }

    $hours     = intdiv($minutes, 60);
    $remainder = $minutes % 60;

    if ($hours === 0) {
        return $remainder . ' ' . ($remainder === 1 ? 'minute' : 'minutes');
    }

    $parts = [$hours . ' ' . ($hours === 1 ? 'hour' : 'hours')];

    if ($remainder > 0) {
        $parts[] = $remainder . ' ' . ($remainder === 1 ? 'minute' : 'minutes');
    }

    return implode(' ', $parts);
}

/**
 * Reports the exact Google Distance Matrix failure (OVER_QUERY_LIMIT,
 * REQUEST_DENIED, INVALID_REQUEST, ...) instead of silently falling back
 * to a static duration.
 */
function fail_with_google_error(string $status, string $errorMessage = ''): void {
    $error = 'Google Distance Matrix error: ' . $status;

    if ($errorMessage !== '') {
        $error .= ' - ' . $errorMessage;
    }

    echo json_encode([
        'success'       => false,
        'error'         => $error,
        'status'        => $status,
        'error_message' => $errorMessage,
    ]);
    exit;
}

if (!is_array($data)) {
    fail_with_google_error('REQUEST_FAILED', 'No valid response from the Google Distance Matrix API.');
}

$topStatus = (string) ($data['status'] ?? '');

if ($topStatus !== 'OK') {
    fail_with_google_error(
        $topStatus !== '' ? $topStatus : 'UNKNOWN_ERROR',
        (string) ($data['error_message'] ?? '')
    );
}

$element       = $data['rows'][0]['elements'][0] ?? null;
$elementStatus = is_array($element) ? (string) ($element['status'] ?? '') : '';

if ($elementStatus !== 'OK') {
    fail_with_google_error(
        $elementStatus !== '' ? $elementStatus : 'UNKNOWN_ERROR',
        (string) ($data['error_message'] ?? '')
    );
}

$durationSeconds = $element['duration_in_traffic']['value'] ?? $element['duration']['value'] ?? null;

if (!is_numeric($durationSeconds)) {
    fail_with_google_error('INVALID_REQUEST', 'Google did not return a travel duration for this route.');
}

$minutes  = (int) round(((float) $durationSeconds) / 60);
$duration = format_eta_duration($minutes);
$arrival  = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))
    ->add(new DateInterval('PT' . max(1, $minutes) . 'M'))
    ->format('g:i A');

$message = "Laser Technician: I'm on my way! I should be there in about {$duration}, by {$arrival}.";

echo json_encode(['success' => true, 'message' => $message]);
