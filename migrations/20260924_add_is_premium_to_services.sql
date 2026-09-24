SET @ghost_laser_has_is_premium := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'services'
      AND COLUMN_NAME = 'is_premium'
);

SET @ghost_laser_add_is_premium_sql := IF(
    @ghost_laser_has_is_premium = 0,
    'ALTER TABLE services ADD COLUMN is_premium TINYINT(1) NOT NULL DEFAULT 0 AFTER duration_minutes',
    'SELECT 1'
);

PREPARE ghost_laser_add_is_premium_stmt FROM @ghost_laser_add_is_premium_sql;
EXECUTE ghost_laser_add_is_premium_stmt;
DEALLOCATE PREPARE ghost_laser_add_is_premium_stmt;
