<?php

function prospectsColumnExists(PDO $pdo, string $table, string $column): bool
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

function prospectsIndexExists(PDO $pdo, string $table, string $index): bool
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
        ':index_name' => $index,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function prospectsForeignKeyExists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND CONSTRAINT_NAME = :constraint_name
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':constraint_name' => $constraint,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function prospectsEnsureSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prospect_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_prospect_categories_slug (slug),
            UNIQUE KEY uniq_prospect_categories_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prospect_category_keywords (
            category_id INT UNSIGNED NOT NULL,
            keyword VARCHAR(255) NOT NULL,
            PRIMARY KEY (category_id, keyword),
            INDEX idx_prospect_category_keywords_keyword (keyword),
            CONSTRAINT fk_prospect_category_keywords_category
                FOREIGN KEY (category_id) REFERENCES prospect_categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prospects (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company VARCHAR(255) NOT NULL DEFAULT '',
            contact_name VARCHAR(255) NOT NULL DEFAULT '',
            phone VARCHAR(100) NULL,
            email VARCHAR(255) NULL,
            website VARCHAR(255) NULL,
            last_called_at DATETIME NULL,
            last_emailed_at DATETIME NULL,
            status ENUM(
                'new',
                'attempting_contact',
                'contacted',
                'qualified',
                'not_interested',
                'converted',
                'archived'
            ) NOT NULL DEFAULT 'new',
            notes TEXT NULL,
            raw_source LONGTEXT NULL,
            raw_text_dump LONGTEXT NULL,
            parse_preview_json LONGTEXT NULL,
            parse_confidence DECIMAL(5,2) NULL,
            parse_provider VARCHAR(100) NULL,
            parse_errors TEXT NULL,
            converted_customer_id INT UNSIGNED NULL,
            converted_at DATETIME NULL,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            updated_by INT UNSIGNED NULL,
            category_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_prospects_status (status),
            INDEX idx_prospects_company (company),
            INDEX idx_prospects_email (email),
            INDEX idx_prospects_phone (phone),
            INDEX idx_prospects_last_called_at (last_called_at),
            INDEX idx_prospects_archived (is_archived),
            INDEX idx_prospects_category_id (category_id),
            CONSTRAINT fk_prospects_category
                FOREIGN KEY (category_id) REFERENCES prospect_categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!prospectsColumnExists($pdo, 'prospects', 'converted_customer_id')) {
        $pdo->exec("ALTER TABLE prospects ADD COLUMN converted_customer_id INT UNSIGNED NULL AFTER parse_errors");
    }
    if (!prospectsColumnExists($pdo, 'prospects', 'converted_at')) {
        $pdo->exec("ALTER TABLE prospects ADD COLUMN converted_at DATETIME NULL AFTER converted_customer_id");
    }
    if (!prospectsColumnExists($pdo, 'prospects', 'raw_source')) {
        $pdo->exec("ALTER TABLE prospects ADD COLUMN raw_source LONGTEXT NULL AFTER notes");
    }
    if (!prospectsColumnExists($pdo, 'prospects', 'category_id')) {
        $pdo->exec("ALTER TABLE prospects ADD COLUMN category_id INT UNSIGNED NULL AFTER updated_by");
    }
    if (!prospectsIndexExists($pdo, 'prospects', 'idx_prospects_category_id')) {
        $pdo->exec("ALTER TABLE prospects ADD INDEX idx_prospects_category_id (category_id)");
    }
    if (!prospectsForeignKeyExists($pdo, 'prospects', 'fk_prospects_category')) {
        $pdo->exec("
            ALTER TABLE prospects
            ADD CONSTRAINT fk_prospects_category
                FOREIGN KEY (category_id) REFERENCES prospect_categories(id) ON DELETE SET NULL
        ");
    }
    if (prospectsColumnExists($pdo, 'prospects', 'raw_text_dump')) {
        $pdo->exec("
            UPDATE prospects
            SET raw_source = COALESCE(raw_source, raw_text_dump)
            WHERE raw_source IS NULL
              AND raw_text_dump IS NOT NULL
        ");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prospect_interactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prospect_id BIGINT UNSIGNED NOT NULL,
            interaction_type ENUM('call', 'email', 'note', 'status_change', 'conversion') NOT NULL,
            outcome VARCHAR(255) NULL,
            interaction_notes TEXT NULL,
            interacted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            admin_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_prospect_interactions_prospect (prospect_id),
            INDEX idx_prospect_interactions_type (interaction_type),
            INDEX idx_prospect_interactions_at (interacted_at),
            CONSTRAINT fk_prospect_interactions_prospect
                FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prospect_notification_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prospect_conversion_map (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prospect_id BIGINT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            converted_by INT UNSIGNED NULL,
            converted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_prospect_conversion (prospect_id),
            INDEX idx_conversion_customer (customer_id),
            CONSTRAINT fk_conversion_map_prospect
                FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
