<?php

function mileageColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function mileageIndexExists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND INDEX_NAME = :index_name
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':index_name' => $indexName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function ensureVehiclesTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            year SMALLINT UNSIGNED NULL,
            make VARCHAR(100) NOT NULL DEFAULT '',
            model VARCHAR(100) NOT NULL DEFAULT '',
            license_plate VARCHAR(50) NOT NULL DEFAULT '',
            notes VARCHAR(1000) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_vehicles_active (is_active),
            INDEX idx_vehicles_default (is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!mileageColumnExists($pdo, 'vehicles', 'is_default')) {
        $pdo->exec("ALTER TABLE vehicles ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
    }
}

function ensureMileageLogsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mileage_logs (
            id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_request_id INT UNSIGNED NOT NULL,
            client_name        VARCHAR(255)  NOT NULL DEFAULT '',
            address            VARCHAR(500)  NOT NULL DEFAULT '',
            trip_date          DATE          NULL,
            start_time         DATETIME      NULL COMMENT 'LA timezone',
            end_time           DATETIME      NULL COMMENT 'LA timezone',
            start_lat          DECIMAL(10,7) NULL,
            start_lng          DECIMAL(10,7) NULL,
            end_lat            DECIMAL(10,7) NULL,
            end_lng            DECIMAL(10,7) NULL,
            start_mileage      INT UNSIGNED  NULL COMMENT 'Odometer at departure',
            end_mileage        INT UNSIGNED  NULL COMMENT 'Odometer at arrival',
            total_miles        DECIMAL(8,2)  NULL,
            notes              VARCHAR(1000) NULL COMMENT 'Admin override for business purpose',
            vehicle_id         INT UNSIGNED  NULL,
            status             ENUM('pending','complete') NOT NULL DEFAULT 'pending',
            created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_service_request (service_request_id),
            INDEX idx_trip_date (trip_date),
            INDEX idx_vehicle_id (vehicle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = [
        'start_mileage' => "INT UNSIGNED NULL COMMENT 'Odometer at departure' AFTER end_lng",
        'end_mileage'   => "INT UNSIGNED NULL COMMENT 'Odometer at arrival' AFTER start_mileage",
        'notes'         => "VARCHAR(1000) NULL COMMENT 'Admin override for business purpose' AFTER total_miles",
        'vehicle_id'    => "INT UNSIGNED NULL AFTER notes",
    ];

    foreach ($columns as $name => $definition) {
        if (!mileageColumnExists($pdo, 'mileage_logs', $name)) {
            $pdo->exec("ALTER TABLE mileage_logs ADD COLUMN {$name} {$definition}");
        }
    }

    if (!mileageIndexExists($pdo, 'mileage_logs', 'idx_vehicle_id')) {
        $pdo->exec("ALTER TABLE mileage_logs ADD INDEX idx_vehicle_id (vehicle_id)");
    }
}

function mileageBackfillLogsToDefaultVehicle(PDO $pdo): int
{
    $defaultVehicleId = mileageGetDefaultVehicleId($pdo);
    if ($defaultVehicleId === null) {
        return 0;
    }

    $stmt = $pdo->prepare("
        UPDATE mileage_logs
        SET vehicle_id = :vehicle_id
        WHERE vehicle_id IS NULL
    ");
    $stmt->execute([':vehicle_id' => $defaultVehicleId]);

    return $stmt->rowCount();
}

function ensureMileageVehicleSchema(PDO $pdo): void
{
    ensureVehiclesTable($pdo);
    ensureMileageLogsTable($pdo);
    mileageBackfillLogsToDefaultVehicle($pdo);
}

function mileageGetDefaultVehicleId(PDO $pdo): ?int
{
    $stmt = $pdo->query("
        SELECT id
        FROM vehicles
        WHERE is_default = 1
        ORDER BY id ASC
        LIMIT 1
    ");
    $value = $stmt->fetchColumn();

    return $value !== false ? (int) $value : null;
}

function mileageSetDefaultVehicle(PDO $pdo, int $vehicleId): void
{
    $pdo->beginTransaction();
    try {
        $clearStmt = $pdo->prepare("UPDATE vehicles SET is_default = 0 WHERE is_default = 1");
        $clearStmt->execute();

        $setStmt = $pdo->prepare("UPDATE vehicles SET is_default = 1 WHERE id = :id");
        $setStmt->execute([':id' => $vehicleId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

