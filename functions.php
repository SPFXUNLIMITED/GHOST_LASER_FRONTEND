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


?>
