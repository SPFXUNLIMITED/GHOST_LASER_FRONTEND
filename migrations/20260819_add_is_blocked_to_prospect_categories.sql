ALTER TABLE prospect_categories
    ADD COLUMN is_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER slug;
