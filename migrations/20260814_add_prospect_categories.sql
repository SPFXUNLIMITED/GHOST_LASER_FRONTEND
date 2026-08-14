CREATE TABLE IF NOT EXISTS prospect_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_prospect_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO prospect_categories (name)
SELECT 'Uncategorized'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM prospect_categories
);

ALTER TABLE prospects
    ADD COLUMN IF NOT EXISTS prospect_category_id BIGINT UNSIGNED NULL AFTER website;

CREATE INDEX idx_prospects_category_id ON prospects (prospect_category_id);

UPDATE prospects p
JOIN (
    SELECT id FROM prospect_categories ORDER BY id ASC LIMIT 1
) c
SET p.prospect_category_id = c.id
WHERE p.prospect_category_id IS NULL;

ALTER TABLE prospects
    ADD CONSTRAINT fk_prospects_category
    FOREIGN KEY (prospect_category_id) REFERENCES prospect_categories(id)
    ON DELETE SET NULL;
