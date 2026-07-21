<?php

function getTravelSettingsDefaults(): array
{
    return [
        'price_per_mile' => '2.00',
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
}

function seedTravelSettings(PDO $pdo): void
{
    $defaults = getTravelSettingsDefaults();
    $stmt = $pdo->prepare("
        INSERT INTO travel_settings (
            id,
            price_per_mile
        ) VALUES (
            1,
            :price_per_mile
        )
    ");
    $stmt->execute([
        ':price_per_mile' => $defaults['price_per_mile'],
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
            price_per_mile = :price_per_mile
        WHERE id = 1
    ");
    $stmt->execute([
        ':price_per_mile' => $normalized['price_per_mile'],
    ]);
}
