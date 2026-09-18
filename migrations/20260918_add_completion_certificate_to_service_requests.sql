SET @ghost_laser_has_completion_certificate := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_requests'
      AND COLUMN_NAME = 'completion_certificate'
);

SET @ghost_laser_add_completion_certificate_sql := IF(
    @ghost_laser_has_completion_certificate = 0,
    'ALTER TABLE service_requests ADD COLUMN completion_certificate VARCHAR(255) NULL AFTER technician_notes',
    'SELECT 1'
);

PREPARE ghost_laser_add_completion_certificate_stmt FROM @ghost_laser_add_completion_certificate_sql;
EXECUTE ghost_laser_add_completion_certificate_stmt;
DEALLOCATE PREPARE ghost_laser_add_completion_certificate_stmt;
