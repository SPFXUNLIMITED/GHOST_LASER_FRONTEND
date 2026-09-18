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
require __DIR__ . '/../project/completion_certificate.php';

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

// --- 3. Legacy rows still fall back to problem_details when problem is blank ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 3, 'name' => 'Diagnosis', 'duration' => 60],
    ]);

    $scope = serviceAuthorizationBuildScopeOfWork($pdo, [
        'services' => json_encode([3]),
        'problem_summary' => 'Power issue',
        'problem' => '   ',
        'problem_details' => 'Legacy description from older requests.',
    ]);

    ghostLaserAuthAssertContains(
        'Job description: Legacy description from older requests.',
        $scope,
        'Legacy rows should fall back to problem_details when problem is blank'
    );
})();

// --- 4. Completion certificate generation is blocked when already current ---
(function (): void {
    $pdo = ghostLaserMakeTestPdo([
        ['id' => 1, 'name' => 'Inspection', 'duration' => 30],
    ]);
    $pdo->exec('CREATE TABLE service_authorizations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_request_id INTEGER NOT NULL,
        agreement_type TEXT NOT NULL,
        agreement_summary TEXT NOT NULL,
        scope_of_work TEXT NOT NULL,
        signature_path TEXT NOT NULL,
        signature_sha256 TEXT NOT NULL,
        signed_at TEXT NOT NULL,
        signed_latitude REAL NULL,
        signed_longitude REAL NULL
    )');
    $pdo->exec("INSERT INTO service_requests (id, services) VALUES (10, '[1]')");

    $insert = $pdo->prepare(
        'INSERT INTO service_authorizations
            (service_request_id, agreement_type, agreement_summary, scope_of_work, signature_path, signature_sha256, signed_at)
         VALUES
            (:service_request_id, :agreement_type, :agreement_summary, :scope_of_work, :signature_path, :signature_sha256, :signed_at)'
    );

    $insert->execute([
        ':service_request_id' => 10,
        ':agreement_type' => 'service_authorization',
        ':agreement_summary' => 'Authorized',
        ':scope_of_work' => 'Initial scope',
        ':signature_path' => 'uploads/service-authorizations/signatures/a.png',
        ':signature_sha256' => str_repeat('a', 64),
        ':signed_at' => '2026-09-18T01:00:00+00:00',
    ]);
    ghostLaserAuthAssert(
        completionCertificateCanCreateForServiceRequest($pdo, 10),
        'Should allow completion certificate when one does not yet exist'
    );

    $insert->execute([
        ':service_request_id' => 10,
        ':agreement_type' => 'completion_certificate',
        ':agreement_summary' => 'Completed',
        ':scope_of_work' => 'Initial scope',
        ':signature_path' => 'uploads/service-authorizations/signatures/b.png',
        ':signature_sha256' => str_repeat('b', 64),
        ':signed_at' => '2026-09-18T02:00:00+00:00',
    ]);

    ghostLaserAuthAssert(
        !completionCertificateCanCreateForServiceRequest($pdo, 10),
        'Should block duplicate completion certificate while the latest one is current'
    );

    $insert->execute([
        ':service_request_id' => 10,
        ':agreement_type' => 'service_authorization',
        ':agreement_summary' => 'Re-authorized',
        ':scope_of_work' => 'Updated scope',
        ':signature_path' => 'uploads/service-authorizations/signatures/c.png',
        ':signature_sha256' => str_repeat('c', 64),
        ':signed_at' => '2026-09-18T03:00:00+00:00',
    ]);
    ghostLaserAuthAssert(
        completionCertificateCanCreateForServiceRequest($pdo, 10),
        'Should allow a new completion certificate when a newer authorization exists'
    );
})();

if ($failures !== []) {
    fwrite(STDERR, sprintf("FAILED %d assertion(s) (%d passed):\n", count($failures), $passCount));
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo sprintf("OK - %d assertions passed.\n", $passCount);
