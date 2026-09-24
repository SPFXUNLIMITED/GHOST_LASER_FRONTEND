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
         'origins'      => $lat . ',' . $lng,
         'destinations' => $dest,
         'mode'         => 'driving',
         'key'          => $key,
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

$message = "Laser Technician: I'm on my way! I should be there shortly.";

if (
    is_array($data) &&
    isset($data['rows'][0]['elements'][0]['status']) &&
    $data['rows'][0]['elements'][0]['status'] === 'OK'
) {
    $minutes  = (int) round($data['rows'][0]['elements'][0]['duration']['value'] / 60);
    $duration = format_eta_duration($minutes);
    $arrival  = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))
        ->add(new DateInterval('PT' . max(1, $minutes) . 'M'))
        ->format('g:i A');

    $message = "Laser Technician: I'm on my way! I should be there in about {$duration}, by {$arrival}.";
}

echo json_encode(['success' => true, 'message' => $message]);
