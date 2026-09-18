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

function ghostLaserAuthMakeTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec('CREATE TABLE scheduled_clusters (
        id INTEGER PRIMARY KEY,
        created_by_admin_id INTEGER NULL
    )');
    $pdo->exec('CREATE TABLE scheduled_cluster_jobs (
        id INTEGER PRIMARY KEY,
        scheduled_cluster_id INTEGER NOT NULL,
        service_request_id INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE service_authorizations (
        id INTEGER PRIMARY KEY,
        service_request_id INTEGER NOT NULL
    )');

    return $pdo;
}

(function (): void {
    $_SESSION = ['admin_id' => 7];
    $pdo = ghostLaserAuthMakeTestPdo();
    $pdo->exec('INSERT INTO scheduled_clusters (id, created_by_admin_id) VALUES (1, 3)');
    $pdo->exec('INSERT INTO scheduled_cluster_jobs (id, scheduled_cluster_id, service_request_id) VALUES (1, 1, 100)');
    $pdo->exec('INSERT INTO service_authorizations (id, service_request_id) VALUES (500, 100)');

    ghostLaserAuthAssertSame(
        true,
        technicianDashboardCanAccessServiceRequest($pdo, 100),
        'Backend admins should be able to access scheduled jobs regardless of created_by_admin_id ownership'
    );
    ghostLaserAuthAssertSame(
        true,
        technicianDashboardCanAccessAuthorization($pdo, 500),
        'Backend admins should be able to access service authorizations for scheduled jobs regardless of ownership'
    );
})();

(function (): void {
    $_SESSION = [];
    $pdo = ghostLaserAuthMakeTestPdo();

    ghostLaserAuthAssertSame(
        false,
        technicianDashboardCanAccessServiceRequest($pdo, 100),
        'Unauthenticated sessions should still be denied access'
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
