ALTER TABLE prospects
    ADD COLUMN category_id INT UNSIGNED NULL AFTER updated_by,
    ADD INDEX idx_prospects_category_id (category_id),
    ADD CONSTRAINT fk_prospects_category
        FOREIGN KEY (category_id) REFERENCES prospect_categories(id) ON DELETE SET NULL;
