-- Drop raw_text_dump column from prospects (raw_source is sufficient)
ALTER TABLE prospects
    DROP COLUMN raw_text_dump;

-- Remove DEFAULT CURRENT_TIMESTAMP from interacted_at so it must be set manually
ALTER TABLE prospect_interactions
    MODIFY COLUMN interacted_at DATETIME NOT NULL;
