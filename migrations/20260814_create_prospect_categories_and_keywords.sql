CREATE TABLE IF NOT EXISTS prospect_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_prospect_categories_slug (slug),
    UNIQUE KEY uniq_prospect_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prospect_category_keywords (
    category_id INT UNSIGNED NOT NULL,
    keyword VARCHAR(255) NOT NULL,
    PRIMARY KEY (category_id, keyword),
    INDEX idx_prospect_category_keywords_keyword (keyword),
    CONSTRAINT fk_prospect_category_keywords_category
        FOREIGN KEY (category_id) REFERENCES prospect_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
