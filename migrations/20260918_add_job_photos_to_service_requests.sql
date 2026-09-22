SET @ghost_laser_has_job_photos := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_requests'
      AND COLUMN_NAME = 'job_photos'
);

SET @ghost_laser_add_job_photos_sql := IF(
    @ghost_laser_has_job_photos = 0,
    'ALTER TABLE service_requests ADD COLUMN job_photos TEXT NULL AFTER technician_notes',
    'SELECT 1'
);

PREPARE ghost_laser_add_job_photos_stmt FROM @ghost_laser_add_job_photos_sql;
EXECUTE ghost_laser_add_job_photos_stmt;
DEALLOCATE PREPARE ghost_laser_add_job_photos_stmt;
