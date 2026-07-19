<?php
header('Content-Type: application/json');

/**
 * Reads an environment variable from server environment, $_ENV, $_SERVER,
 * or a .env file located alongside this script.
 */
function load_env_value(string $key): string {
    static $dotenv_values = null;

    if ($dotenv_values === null) {
        $dotenv_values = [];
        $dotenv_path = __DIR__ . '/.env';
        if (is_file($dotenv_path) && is_readable($dotenv_path)) {
            $lines = file($dotenv_path, FILE_IGNORE_NEW_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim((string)$line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    $sep = strpos($line, '=');
                    if ($sep === false) {
                        continue;
                    }
                    $name = trim(substr($line, 0, $sep));
                    if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                        continue;
                    }
                    $value = trim(substr($line, $sep + 1));
                    if (strlen($value) >= 2) {
                        $first = $value[0];
                        $last  = $value[strlen($value) - 1];
                        if ($first === '"' && $last === '"') {
                            $value = substr($value, 1, -1);
                            $value = strtr($value, ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n", '\\r' => "\r", '\\t' => "\t"]);
                        } elseif ($first === "'" && $last === "'") {
                            $value = substr($value, 1, -1);
                            $value = strtr($value, ['\\\\' => '\\', "\\'" => "'"]);
                        }
                    } else {
                        $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                        $value = rtrim($value);
                    }
                    $dotenv_values[$name] = $value;
                }
            }
        }
    }

    foreach ([getenv($key), getenv('REDIRECT_' . $key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null, $_SERVER['REDIRECT_' . $key] ?? null, $dotenv_values[$key] ?? null] as $candidate) {
        if ($candidate !== null && trim((string)$candidate) !== '') {
            return trim((string)$candidate);
        }
    }
    return '';
}

$key = load_env_value('GOOGLE_MAPS_API_KEY');

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
    echo json_encode(['success' => true, 'message' => "I'm on my way! I should be there shortly."]);
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

$message = "I'm on my way! I should be there shortly.";

if (
    is_array($data) &&
    isset($data['rows'][0]['elements'][0]['status']) &&
    $data['rows'][0]['elements'][0]['status'] === 'OK'
) {
    $minutes = (int) round($data['rows'][0]['elements'][0]['duration']['value'] / 60);
    $message = "I'm on my way! I should be there in about {$minutes} minutes.";
}

echo json_encode(['success' => true, 'message' => $message]);
