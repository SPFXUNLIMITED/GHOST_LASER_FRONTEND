CREATE TABLE IF NOT EXISTS prospect_category_keywords (
    category_id INT UNSIGNED NOT NULL,
    keyword VARCHAR(255) NOT NULL,
    PRIMARY KEY (category_id, keyword),
    INDEX idx_prospect_category_keywords_keyword (keyword),
    CONSTRAINT fk_prospect_category_keywords_category
        FOREIGN KEY (category_id) REFERENCES prospect_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
