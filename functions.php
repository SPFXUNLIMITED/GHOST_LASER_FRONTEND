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
 * Returns true only if the customer has a record in customer_status with status = 'Banned'.
 * Returns false if there is no record OR if the status is anything else (VIP, Good, Caution).
 */
function is_customer_banned(PDO $pdo, ?int $customer_id): bool {
    if ($customer_id === null || $customer_id <= 0) {
        return false;
    }
    try {
        ensure_customer_status_table($pdo);
        $stmt = $pdo->prepare("SELECT status FROM customer_status WHERE customer_id = ? LIMIT 1");
        $stmt->execute([$customer_id]);
        return strcasecmp((string) $stmt->fetchColumn(), 'Banned') === 0;
    } catch (Throwable $e) {
        return false;
    }
}
?>
