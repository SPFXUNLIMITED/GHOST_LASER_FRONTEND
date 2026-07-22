<?php

function getTravelSettingsDefaults(): array
{
    return [
        'price_per_mile'     => '2.00',
        'hourly_travel_rate' => '50.00',
        'base_location'      => '',
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

    $hourlyTravelRate = (float) $merged['hourly_travel_rate'];
    if ($hourlyTravelRate < 0) {
        $hourlyTravelRate = (float) $defaults['hourly_travel_rate'];
    }

    $merged['price_per_mile'] = number_format($pricePerMile, 2, '.', '');
    $merged['hourly_travel_rate'] = number_format($hourlyTravelRate, 2, '.', '');
    $merged['base_location'] = trim((string) ($merged['base_location'] ?? ''));

    return $merged;
}

function ensureTravelSettingsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS travel_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            price_per_mile DECIMAL(10,2) NOT NULL DEFAULT 2.00,
            hourly_travel_rate DECIMAL(10,2) NOT NULL DEFAULT 50.00,
            base_location VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    // Add missing columns for legacy schemas.
    // Use information_schema check for compatibility with MySQL < 8.0.
    $colCheck = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'travel_settings'
          AND COLUMN_NAME  = :column_name
    ");
    $columnsToEnsure = [
        'hourly_travel_rate' => "ALTER TABLE travel_settings ADD COLUMN hourly_travel_rate DECIMAL(10,2) NOT NULL DEFAULT 50.00",
        'base_location' => "ALTER TABLE travel_settings ADD COLUMN base_location VARCHAR(255) NOT NULL DEFAULT ''",
    ];
    foreach ($columnsToEnsure as $columnName => $sql) {
        $colCheck->execute([':column_name' => $columnName]);
        if ((int) $colCheck->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function seedTravelSettings(PDO $pdo): void
{
    $defaults = getTravelSettingsDefaults();
    $stmt = $pdo->prepare("
        INSERT INTO travel_settings (
            id,
            price_per_mile,
            hourly_travel_rate,
            base_location
        ) VALUES (
            1,
            :price_per_mile,
            :hourly_travel_rate,
            :base_location
        )
    ");
    $stmt->execute([
        ':price_per_mile' => $defaults['price_per_mile'],
        ':hourly_travel_rate' => $defaults['hourly_travel_rate'],
        ':base_location' => $defaults['base_location'],
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
            hourly_travel_rate = :hourly_travel_rate,
            base_location  = :base_location
        WHERE id = 1
    ");
    $stmt->execute([
        ':price_per_mile' => $normalized['price_per_mile'],
        ':hourly_travel_rate' => $normalized['hourly_travel_rate'],
        ':base_location' => $normalized['base_location'],
    ]);
}

/**
 * Calls the Google Maps Distance Matrix API and returns one-way and round-trip
 * driving metrics between $origin and $destination.
 *
 * Returns an array on success, or ['error' => '<code>'] on failure.
 * Error codes: 'api_key_missing', 'base_location_missing', 'invalid_address', 'api_error'.
 */
function calculateDrivingTravelEstimate(string $origin, string $destination, string $apiKey): array
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
    $durationSeconds = (float) ($element['duration']['value'] ?? 0);
    if ($meters <= 0 || $durationSeconds <= 0) {
        return ['error' => 'api_error'];
    }

    $oneWayMiles = $meters / 1609.344;
    $oneWayHours = $durationSeconds / 3600;

    return [
        'one_way_miles' => $oneWayMiles,
        'one_way_hours' => $oneWayHours,
        'round_trip_miles' => $oneWayMiles * 2,
        'round_trip_hours' => $oneWayHours * 2,
    ];
}

/**
 * Backwards-compatible helper that returns only one-way miles.
 */
function calculateDrivingDistanceMiles(string $origin, string $destination, string $apiKey): float|array
{
    $estimate = calculateDrivingTravelEstimate($origin, $destination, $apiKey);
    if (isset($estimate['error'])) {
        return $estimate;
    }

    return (float) ($estimate['one_way_miles'] ?? 0.0);
}

/**
 * Calculates travel fee using whichever is higher:
 * (round-trip miles × per-mile rate) or (round-trip hours × hourly rate).
 */
function calculateTravelCharge(float $roundTripMiles, float $roundTripHours, float $pricePerMile, float $hourlyTravelRate): array
{
    $safeMiles = max(0, $roundTripMiles);
    $safeHours = max(0, $roundTripHours);
    $safePricePerMile = max(0, $pricePerMile);
    $safeHourlyRate = max(0, $hourlyTravelRate);

    $mileageCharge = round($safeMiles * $safePricePerMile, 2);
    $hourlyCharge = round($safeHours * $safeHourlyRate, 2);
    $finalCharge = max($mileageCharge, $hourlyCharge);

    return [
        'round_trip_miles' => round($safeMiles, 1),
        'round_trip_hours' => round($safeHours, 2),
        'mileage_charge' => $mileageCharge,
        'hourly_charge' => $hourlyCharge,
        'final_charge' => $finalCharge,
        'billing_method' => $hourlyCharge > $mileageCharge ? 'hourly' : 'mileage',
    ];
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
