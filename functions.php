<?php
function asset($file) {
    $filepath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($file, '/');
    if (file_exists($filepath)) {
        $version = filemtime($filepath);
        return '/' . ltrim($file, '/') . '?v=' . $version;
    }
    return '/' . ltrim($file, '/');
}

function ensure_customer_status_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_status (
            customer_id INT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
            status ENUM('VIP','Good','Caution','Banned') NOT NULL DEFAULT 'Good',
            notes TEXT NULL,
            has_outstanding_balance TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by VARCHAR(255) NULL,
            PRIMARY KEY (customer_id),
            CONSTRAINT fk_customer_status_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $pdo->exec("ALTER TABLE customer_status MODIFY COLUMN status ENUM('VIP','Good','Caution','Banned') NOT NULL DEFAULT 'Good'");
    } catch (Throwable $e) {
        // Ignore compatibility errors.
    }
}

/**
 * Whether the services catalog already has the is_premium column.
 *
 * Read-only pages (the booking forms) must keep working on deployments where
 * neither the migration nor service-settings.php has added the column yet, so
 * they use this to decide whether they can select it.
 */
function servicesTableHasPremiumColumn(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $exists = (int) $pdo->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'services'
              AND COLUMN_NAME  = 'is_premium'
        ")->fetchColumn();
        $cache = $exists > 0;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

/**
 * Human-readable duration label for a service, e.g. 60 => "1 hour",
 * 90 => "1 hour 30 min", 45 => "45 min". Returns '' for non-positive values so
 * callers can omit the segment entirely for services with no duration set.
 */
function formatServiceDuration(int $minutes): string {
    if ($minutes <= 0) {
        return '';
    }

    $hours = intdiv($minutes, 60);
    $rest  = $minutes % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
    }
    if ($rest > 0) {
        $parts[] = $rest . ' min';
    }

    return implode(' ', $parts);
}


?>
