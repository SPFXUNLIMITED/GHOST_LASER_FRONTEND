<?php

function getTravelSettingsDefaults(): array
{
    return [
        'price_per_mile' => '2.00',
        'base_location'  => '',
    ];
}

function normalizeTravelSettings(array $settings): array
{
    $defaults = getTravelSettingsDefaults();
    $merged = array_merge($defaults, $settings);

    $pricePerMile = (float) $merged['price_per_mile'];
    if ($pricePerMile < 0) {
        $pricePerMile = (float) $defaults['price_per_mile'];
    }

    $merged['price_per_mile'] = number_format($pricePerMile, 2, '.', '');
    $merged['base_location']  = trim((string) ($merged['base_location'] ?? ''));

    return $merged;
}

function ensureTravelSettingsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS travel_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            price_per_mile DECIMAL(10,2) NOT NULL DEFAULT 2.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    // Add base_location column if it was not present in the original schema.
    // Use information_schema check for compatibility with MySQL < 8.0.
    $colCheck = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'travel_settings'
          AND COLUMN_NAME  = 'base_location'
    ");
    $colCheck->execute();
    if ((int) $colCheck->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE travel_settings ADD COLUMN base_location VARCHAR(255) NOT NULL DEFAULT ''");
    }
}

function seedTravelSettings(PDO $pdo): void
{
    $defaults = getTravelSettingsDefaults();
    $stmt = $pdo->prepare("
        INSERT INTO travel_settings (
            id,
            price_per_mile,
            base_location
        ) VALUES (
            1,
            :price_per_mile,
            :base_location
        )
    ");
    $stmt->execute([
        ':price_per_mile' => $defaults['price_per_mile'],
        ':base_location'  => $defaults['base_location'],
    ]);
}

function getTravelSettings(PDO $pdo): array
{
    ensureTravelSettingsTable($pdo);
    $row = $pdo->query("SELECT * FROM travel_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        seedTravelSettings($pdo);
        $row = $pdo->query("SELECT * FROM travel_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }

    return normalizeTravelSettings(is_array($row) ? $row : []);
}

function updateTravelSettings(PDO $pdo, array $settings): void
{
    ensureTravelSettingsTable($pdo);
    $normalized = normalizeTravelSettings($settings);

    $exists = (int) $pdo->query("SELECT COUNT(*) FROM travel_settings WHERE id = 1")->fetchColumn();
    if ($exists === 0) {
        seedTravelSettings($pdo);
    }

    $stmt = $pdo->prepare("
        UPDATE travel_settings
        SET
            price_per_mile = :price_per_mile,
            base_location  = :base_location
        WHERE id = 1
    ");
    $stmt->execute([
        ':price_per_mile' => $normalized['price_per_mile'],
        ':base_location'  => $normalized['base_location'],
    ]);
}

/**
 * Calls the Google Maps Distance Matrix API and returns the one-way driving
 * distance in miles between $origin and $destination.
 *
 * Returns a float (miles) on success, or an array ['error' => '<code>'] on failure.
 * Error codes: 'api_key_missing', 'base_location_missing', 'invalid_address', 'api_error'.
 *
 * Multiply a successful result by 2 for a round-trip distance.
 */
function calculateDrivingDistanceMiles(string $origin, string $destination, string $apiKey): float|array
{
    if ($apiKey === '') {
        return ['error' => 'api_key_missing'];
    }
    if ($origin === '') {
        return ['error' => 'base_location_missing'];
    }
    if ($destination === '') {
        return ['error' => 'invalid_address'];
    }

    $url = 'https://maps.googleapis.com/maps/api/distancematrix/json?' . http_build_query([
        'origins'      => $origin,
        'destinations' => $destination,
        'mode'         => 'driving',
        'units'        => 'imperial',
        'key'          => $apiKey,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_errno($ch);
    curl_close($ch);

    if ($curlErr || $response === false) {
        error_log('travel-helper.php calculateDrivingDistanceMiles curl error: ' . $curlErr);
        return ['error' => 'api_error'];
    }

    $data    = json_decode((string) $response, true);
    $element = $data['rows'][0]['elements'][0] ?? null;
    $status  = is_array($element) ? ($element['status'] ?? '') : '';

    if ($status !== 'OK') {
        error_log('travel-helper.php calculateDrivingDistanceMiles API status: ' . ($status ?: ($data['status'] ?? 'unknown')));
        return in_array($status, ['ZERO_RESULTS', 'NOT_FOUND'], true)
            ? ['error' => 'invalid_address']
            : ['error' => 'api_error'];
    }

    $meters = (float) ($element['distance']['value'] ?? 0);
    if ($meters <= 0) {
        return ['error' => 'api_error'];
    }

    return $meters / 1609.344; // metres → miles
}

/**
 * Returns a human-readable error message for a travel distance error code.
 */
function travelDistanceErrorMessage(string $errorCode): string
{
    return match ($errorCode) {
        'base_location_missing' => "Unable to calculate travel distance \u{2014} the shop\u{2019}s base location has not been configured. Please contact us for a quote.",
        'api_key_missing'       => "Unable to calculate travel distance \u{2014} the distance service is not configured. Please contact us for a quote.",
        'invalid_address'       => "Unable to calculate travel distance \u{2014} your address could not be found. Please check your address or contact us for a quote.",
        default                 => 'Unable to calculate travel distance. Please contact us for a quote.',
    };
}
