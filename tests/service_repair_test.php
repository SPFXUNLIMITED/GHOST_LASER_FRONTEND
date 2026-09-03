<?php
/**
 * Regression tests for the service name / numeric ID mismatch fix in
 * technician/schedule.php (resolveJobDurationsFromServices() and
 * repairServiceRequestsServiceNames()).
 *
 * No PHPUnit is installed in this project, so this is a small
 * self-contained assertion harness. Run it directly:
 *
 *   php tests/service_repair_test.php
 *
 * It exits 0 when every assertion passes and 1 (printing failures) when
 * any assertion fails, so it can also be wired into CI.
 */

require __DIR__ . '/bootstrap.php';

$failures = [];
$passCount = 0;

function ghostLaserAssert(bool $condition, string $message): void
{
    global $failures, $passCount;
    if ($condition) {
        $passCount++;
        return;
    }
    $failures[] = $message;
}

function ghostLaserAssertSame($expected, $actual, string $message): void
{
    ghostLaserAssert(
        $expected === $actual,
        sprintf('%s (expected %s, got %s)', $message, var_export($expected, true), var_export($actual, true))
    );
}

function ghostLaserAssertContains(string $needle, ?string $haystack, string $message): void
{
    ghostLaserAssert(
        $haystack !== null && str_contains($haystack, $needle),
        sprintf('%s (expected to find "%s" in %s)', $message, $needle, var_export($haystack, true))
    );
}

// --- 1. Legacy name entry is resolved via the map (request #223 case) ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 3, 'name' => 'Diagnosis', 'duration' => 60],
        ['id' => 5, 'name' => 'Advanced Diagnosis', 'duration' => 90],
    ]);
    ghostLaserInsertServiceRequest($pdo, 223, json_encode(['Advanced Diagnosis']));

    $jobs = [
        ['id' => 223, 'services' => json_encode(['Advanced Diagnosis']), 'duration_minutes' => 0],
    ];

    $error = resolveJobDurationsFromServices($pdo, $jobs);

    ghostLaserAssertSame(null, $error, 'Legacy name entry should resolve without error');
    ghostLaserAssertSame(90, $jobs[0]['duration_minutes'] ?? null, 'Legacy name entry should resolve to the matching service duration');
    ghostLaserAssertSame(
        json_encode([5]),
        ghostLaserGetServiceRequestServices($pdo, 223),
        'Resolved legacy name should be persisted back to service_requests as a numeric id (best-effort convergence)'
    );
})();

// --- 2. Mixed name + numeric entries in the same job ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 3, 'name' => 'Diagnosis', 'duration' => 60],
        ['id' => 5, 'name' => 'Advanced Diagnosis', 'duration' => 90],
    ]);
    ghostLaserInsertServiceRequest($pdo, 10, json_encode([3, 'Advanced Diagnosis']));

    $jobs = [
        ['id' => 10, 'services' => json_encode([3, 'Advanced Diagnosis']), 'duration_minutes' => 0],
    ];

    $error = resolveJobDurationsFromServices($pdo, $jobs);

    ghostLaserAssertSame(null, $error, 'Mixed numeric+name entries should resolve without error');
    ghostLaserAssertSame(150, $jobs[0]['duration_minutes'] ?? null, 'Mixed entries duration should sum numeric id + resolved name id');
})();

// --- 3. Unmatched name still produces a clear, actionable error ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 3, 'name' => 'Diagnosis', 'duration' => 60],
    ]);

    $jobs = [
        ['id' => 42, 'services' => json_encode(['Totally Unknown Service']), 'duration_minutes' => 0],
    ];

    $error = resolveJobDurationsFromServices($pdo, $jobs);

    ghostLaserAssertContains('#42', $error, 'Unmatched name error should reference the failing request id');
    ghostLaserAssertContains('Totally Unknown Service', $error, 'Unmatched name error should name the unmatched value');
    ghostLaserAssertContains('does not match any service', $error, 'Unmatched name error should explain the failure clearly');
})();

// --- 4. Post-repair numeric entries behave exactly as before (no name lookup) ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 3, 'name' => 'Diagnosis', 'duration' => 60],
    ]);
    ghostLaserInsertServiceRequest($pdo, 77, json_encode([3]));

    $jobs = [
        ['id' => 77, 'services' => json_encode([3]), 'duration_minutes' => 0],
    ];

    $error = resolveJobDurationsFromServices($pdo, $jobs);

    ghostLaserAssertSame(null, $error, 'Already-numeric entries should resolve without error');
    ghostLaserAssertSame(60, $jobs[0]['duration_minutes'] ?? null, 'Already-numeric entries should sum durations as before');
    ghostLaserAssertSame(
        json_encode([3]),
        ghostLaserGetServiceRequestServices($pdo, 77),
        'Rows that never needed name resolution should not be rewritten'
    );
})();

// --- 5. Duplicate normalized service names fail loudly (resolveJobDurationsFromServices) ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 3, 'name' => 'Diagnosis', 'duration' => 60],
        ['id' => 4, 'name' => ' diagnosis ', 'duration' => 60],
    ]);

    $jobs = [
        ['id' => 99, 'services' => json_encode(['Diagnosis']), 'duration_minutes' => 0],
    ];

    $error = resolveJobDurationsFromServices($pdo, $jobs);

    ghostLaserAssertContains('Duplicate service name', $error, 'resolveJobDurationsFromServices should fail loudly on catalog name collisions');
    ghostLaserAssertContains('#3', $error, 'Duplicate collision error should name the first conflicting service id');
    ghostLaserAssertContains('#4', $error, 'Duplicate collision error should name the second conflicting service id');
})();

// --- 5b. Duplicate normalized service names fail loudly (repairServiceRequestsServiceNames) ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 3, 'name' => 'Diagnosis', 'duration' => 60],
        ['id' => 4, 'name' => ' diagnosis ', 'duration' => 60],
    ]);
    ghostLaserInsertServiceRequest($pdo, 5, json_encode(['Diagnosis']));

    $error = repairServiceRequestsServiceNames($pdo);

    ghostLaserAssertContains('Duplicate service name', $error, 'repairServiceRequestsServiceNames should fail loudly on catalog name collisions');
    ghostLaserAssertSame(
        json_encode(['Diagnosis']),
        ghostLaserGetServiceRequestServices($pdo, 5),
        'Repair must not touch any row when the catalog itself is ambiguous'
    );
})();

// --- 6. Repair skips and reports unmatched rows instead of aborting the whole batch ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 3, 'name' => 'Diagnosis', 'duration' => 60],
    ]);
    ghostLaserInsertServiceRequest($pdo, 1, json_encode(['Diagnosis']));
    ghostLaserInsertServiceRequest($pdo, 2, json_encode(['Totally Unknown Service']));

    $error = repairServiceRequestsServiceNames($pdo);

    ghostLaserAssertSame(null, $error, 'Repair should succeed overall even when one row is unmatched');
    ghostLaserAssertSame(
        json_encode([3]),
        ghostLaserGetServiceRequestServices($pdo, 1),
        'Matched legacy-name row should still be rewritten to numeric ids'
    );
    ghostLaserAssertSame(
        json_encode(['Totally Unknown Service']),
        ghostLaserGetServiceRequestServices($pdo, 2),
        'Unmatched row should be left unchanged (skip-and-report), not aborting the whole repair'
    );
})();

// --- 7. Repair leaves already-numeric rows untouched (idempotent / safe to re-run) ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 3, 'name' => 'Diagnosis', 'duration' => 60],
    ]);
    ghostLaserInsertServiceRequest($pdo, 1, json_encode([3]));

    $error = repairServiceRequestsServiceNames($pdo);

    ghostLaserAssertSame(null, $error, 'Repair over already-numeric rows should succeed');
    ghostLaserAssertSame(
        json_encode([3]),
        ghostLaserGetServiceRequestServices($pdo, 1),
        'Already-numeric rows should be left unchanged'
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
