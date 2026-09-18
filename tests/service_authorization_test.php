<?php
/**
 * Regression tests for technician notes in the service authorization scope.
 *
 * Run directly:
 *
 *   php tests/service_authorization_test.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../project/service_authorization.php';

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

function ghostLaserAuthAssertContains(string $needle, string $haystack, string $message): void
{
    ghostLaserAuthAssert(
        str_contains($haystack, $needle),
        sprintf('%s (expected to find "%s" in %s)', $message, $needle, var_export($haystack, true))
    );
}

function ghostLaserAuthAssertNotContains(string $needle, string $haystack, string $message): void
{
    ghostLaserAuthAssert(
        !str_contains($haystack, $needle),
        sprintf('%s (did not expect "%s" in %s)', $message, $needle, var_export($haystack, true))
    );
}

// --- 1. Technician notes are appended as their own section after problem text ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 3, 'name' => 'Diagnosis', 'duration' => 60],
    ]);

    $scope = serviceAuthorizationBuildScopeOfWork($pdo, [
        'services' => json_encode([3]),
        'problem_summary' => 'Power issue',
        'problem' => 'Machine shuts down after five minutes.',
        'technician_notes' => "Bring replacement PSU\nCheck belt wear",
    ]);

    ghostLaserAuthAssertContains('Job description: Machine shuts down after five minutes.', $scope, 'Scope should keep the customer problem text');
    ghostLaserAuthAssertContains("Technician notes\nBring replacement PSU\nCheck belt wear", $scope, 'Scope should append technician notes under a dedicated heading');

    $problemPos = strpos($scope, 'Job description: Machine shuts down after five minutes.');
    $notesPos = strpos($scope, "Technician notes\nBring replacement PSU\nCheck belt wear");
    ghostLaserAuthAssert(
        $problemPos !== false && $notesPos !== false && $notesPos > $problemPos,
        'Technician notes should appear after the customer problem text'
    );
})();

// --- 2. Empty technician notes are omitted from scope text ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 3, 'name' => 'Diagnosis', 'duration' => 60],
    ]);

    $scope = serviceAuthorizationBuildScopeOfWork($pdo, [
        'services' => json_encode([3]),
        'problem_summary' => 'Power issue',
        'problem' => 'Machine shuts down after five minutes.',
        'technician_notes' => " \n ",
    ]);

    ghostLaserAuthAssertNotContains('Technician notes', $scope, 'Blank technician notes should not add an empty section');
})();

if ($failures !== []) {
    fwrite(STDERR, sprintf("FAILED %d assertion(s) (%d passed):\n", count($failures), $passCount));
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo sprintf("OK - %d assertions passed.\n", $passCount);
