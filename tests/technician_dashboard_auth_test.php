<?php

require __DIR__ . '/../project/technician_dashboard_auth.php';

$failures = [];
$passCount = 0;

function ghostLaserAuthAssert(bool $condition, string $message): void
{
    global $failures, $passCount;
    if ($condition) {
        $passCount++;
        return;
    }
    $failures[] = $message;
}

function ghostLaserAuthAssertSame($expected, $actual, string $message): void
{
    ghostLaserAuthAssert(
        $expected === $actual,
        sprintf('%s (expected %s, got %s)', $message, var_export($expected, true), var_export($actual, true))
    );
}

function ghostLaserAuthMakePdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec('CREATE TABLE scheduled_clusters (
        id INTEGER PRIMARY KEY,
        created_by_admin_id INTEGER NULL
    )');
    $pdo->exec('CREATE TABLE scheduled_cluster_jobs (
        scheduled_cluster_id INTEGER NOT NULL,
        service_request_id INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE service_authorizations (
        id INTEGER PRIMARY KEY,
        service_request_id INTEGER NOT NULL
    )');

    return $pdo;
}

// --- 1. Access checks must not be limited by cluster owner admin id ---
(function (): void {
    $_SESSION = ['admin_id' => 1];
    $pdo = ghostLaserAuthMakePdo();
    $pdo->exec('INSERT INTO scheduled_clusters (id, created_by_admin_id) VALUES (1, 3)');
    $pdo->exec('INSERT INTO scheduled_cluster_jobs (scheduled_cluster_id, service_request_id) VALUES (1, 101)');
    $pdo->exec('INSERT INTO service_authorizations (id, service_request_id) VALUES (901, 101)');

    ghostLaserAuthAssertSame(
        true,
        technicianDashboardCanAccessServiceRequest($pdo, 101),
        'Service request access should be granted even when cluster owner admin differs from session admin'
    );
    ghostLaserAuthAssertSame(
        true,
        technicianDashboardCanAccessAuthorization($pdo, 901),
        'Authorization access should be granted even when cluster owner admin differs from session admin'
    );
})();

// --- 2. Session auth requirement still applies ---
(function (): void {
    $_SESSION = [];
    $pdo = ghostLaserAuthMakePdo();
    $pdo->exec('INSERT INTO scheduled_clusters (id, created_by_admin_id) VALUES (1, 3)');
    $pdo->exec('INSERT INTO scheduled_cluster_jobs (scheduled_cluster_id, service_request_id) VALUES (1, 101)');
    $pdo->exec('INSERT INTO service_authorizations (id, service_request_id) VALUES (901, 101)');

    ghostLaserAuthAssertSame(
        false,
        technicianDashboardCanAccessServiceRequest($pdo, 101),
        'Service request access should still require an authenticated admin session'
    );
    ghostLaserAuthAssertSame(
        false,
        technicianDashboardCanAccessAuthorization($pdo, 901),
        'Authorization access should still require an authenticated admin session'
    );
})();

if ($failures !== []) {
    fwrite(STDERR, sprintf("FAILED %d assertion(s) (%d passed):\n", count($failures), $passCount));
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

echo sprintf("OK - %d assertions passed.\n", $passCount);
exit(0);
