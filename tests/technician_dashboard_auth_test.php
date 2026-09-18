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

function ghostLaserAuthPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE scheduled_clusters (id INTEGER PRIMARY KEY, created_by_admin_id INTEGER NULL)');
    $pdo->exec('CREATE TABLE scheduled_cluster_jobs (id INTEGER PRIMARY KEY, scheduled_cluster_id INTEGER NOT NULL, service_request_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE service_authorizations (id INTEGER PRIMARY KEY, service_request_id INTEGER NOT NULL)');
    return $pdo;
}

// Access should not be blocked when cluster is owned by a different admin id.
(function (): void {
    $_SESSION = ['admin_id' => 7];
    $pdo = ghostLaserAuthPdo();
    $pdo->exec('INSERT INTO scheduled_clusters (id, created_by_admin_id) VALUES (1, 3)');
    $pdo->exec('INSERT INTO scheduled_cluster_jobs (id, scheduled_cluster_id, service_request_id) VALUES (1, 1, 41)');

    ghostLaserAuthAssertSame(
        true,
        technicianDashboardCanAccessServiceRequest($pdo, 41),
        'Logged-in staff should access scheduled jobs regardless of created_by_admin_id ownership'
    );
})();

// Authorization access should also ignore cluster ownership and follow cluster membership only.
(function (): void {
    $_SESSION = ['admin_id' => 12];
    $pdo = ghostLaserAuthPdo();
    $pdo->exec('INSERT INTO scheduled_clusters (id, created_by_admin_id) VALUES (1, 3)');
    $pdo->exec('INSERT INTO scheduled_cluster_jobs (id, scheduled_cluster_id, service_request_id) VALUES (1, 1, 99)');
    $pdo->exec('INSERT INTO service_authorizations (id, service_request_id) VALUES (5, 99)');

    ghostLaserAuthAssertSame(
        true,
        technicianDashboardCanAccessAuthorization($pdo, 5),
        'Logged-in staff should access authorizations tied to scheduled jobs regardless of created_by_admin_id ownership'
    );
})();

// Still requires a logged-in admin session.
(function (): void {
    $_SESSION = [];
    $pdo = ghostLaserAuthPdo();
    $pdo->exec('INSERT INTO scheduled_clusters (id, created_by_admin_id) VALUES (1, 3)');
    $pdo->exec('INSERT INTO scheduled_cluster_jobs (id, scheduled_cluster_id, service_request_id) VALUES (1, 1, 77)');

    ghostLaserAuthAssertSame(
        false,
        technicianDashboardCanAccessServiceRequest($pdo, 77),
        'Unauthenticated requests must still be denied'
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
