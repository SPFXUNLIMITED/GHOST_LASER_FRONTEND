SET @ghost_laser_has_technician_notes := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_requests'
      AND COLUMN_NAME = 'technician_notes'
);

SET @ghost_laser_add_technician_notes_sql := IF(
    @ghost_laser_has_technician_notes = 0,
    'ALTER TABLE service_requests ADD COLUMN technician_notes TEXT NULL AFTER problem',
    'SELECT 1'
);

PREPARE ghost_laser_add_technician_notes_stmt FROM @ghost_laser_add_technician_notes_sql;
EXECUTE ghost_laser_add_technician_notes_stmt;
DEALLOCATE PREPARE ghost_laser_add_technician_notes_stmt;
