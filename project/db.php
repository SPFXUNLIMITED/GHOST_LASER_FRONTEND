<?php
/**
 * project/db.php – Bootstrap PDO database connection.
 *
 * Exposes a $pdo variable to the including file.
 * Credentials are read from config.php at the repository root.
 */

$_dbConfigPath = dirname(__DIR__) . '/config.php';
$_dbConfig     = require $_dbConfigPath;

$_dbCfg = $_dbConfig['db'];

$_dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $_dbCfg['host'],
    $_dbCfg['name'],
    $_dbCfg['charset']
);

try {
    $pdo = new PDO($_dsn, $_dbCfg['user'], $_dbCfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    // Avoid leaking credentials in error output
    error_log('Database connection failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit;
}

unset($_dbConfigPath, $_dbConfig, $_dbCfg, $_dsn);
