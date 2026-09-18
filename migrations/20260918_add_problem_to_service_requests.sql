SET @ghost_laser_has_problem := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_requests'
      AND COLUMN_NAME = 'problem'
);

SET @ghost_laser_add_problem_sql := IF(
    @ghost_laser_has_problem = 0,
    'ALTER TABLE service_requests ADD COLUMN problem TEXT NULL AFTER problem_details',
    'SELECT 1'
);

PREPARE ghost_laser_add_problem_stmt FROM @ghost_laser_add_problem_sql;
EXECUTE ghost_laser_add_problem_stmt;
DEALLOCATE PREPARE ghost_laser_add_problem_stmt;
