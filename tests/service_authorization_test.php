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

function ghostLaserAuthAssertSame(string $expected, string $actual, string $message): void
{
    ghostLaserAuthAssert(
        $expected === $actual,
        sprintf('%s (expected %s, got %s)', $message, var_export($expected, true), var_export($actual, true))
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

// --- 5. Completion certificate work text inserts technician notes after issue summary ---
(function (): void {
    $text = completionCertificateBuildCompletedWorkText([
        'scope_of_work' => "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nJob description: Machine shuts down after five minutes.",
        'technician_notes' => "Bring replacement PSU\nCheck belt wear",
    ]);

    ghostLaserAuthAssertSame(
        "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nTechnician notes: Bring replacement PSU Check belt wear\n\nJob description: Machine shuts down after five minutes.",
        $text,
        'Completion certificate should insert technician notes once and immediately after the issue summary block'
    );
})();

// --- 6. Blank technician notes do not add a completion certificate section ---
(function (): void {
    $text = completionCertificateBuildCompletedWorkText([
        'scope_of_work' => "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nJob description: Machine shuts down after five minutes.",
        'technician_notes' => " \n ",
    ]);

    ghostLaserAuthAssertNotContains(
        'Technician notes:',
        $text,
        'Blank technician notes should be omitted from completion certificate work text'
    );
})();

// --- 7. Existing scope technician notes are replaced instead of duplicated ---
(function (): void {
    $text = completionCertificateBuildCompletedWorkText([
        'scope_of_work' => "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nTechnician notes\nOld saved note\n\nJob description: Machine shuts down after five minutes.",
        'technician_notes' => 'Fresh note from request',
    ]);

    ghostLaserAuthAssertSame(
        "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nTechnician notes: Fresh note from request\n\nJob description: Machine shuts down after five minutes.",
        $text,
        'Completion certificate should replace legacy technician notes blocks instead of duplicating them'
    );
})();

// --- 8. Blank request notes keep unrelated inline scope text intact ---
(function (): void {
    $text = completionCertificateBuildCompletedWorkText([
        'scope_of_work' => "Requested services: Diagnosis.\n\nTechnician notes: Customer requested a follow-up call.\n\nJob description: Machine shuts down after five minutes.",
        'technician_notes' => '',
    ]);

    ghostLaserAuthAssertSame(
        "Requested services: Diagnosis.\n\nTechnician notes: Customer requested a follow-up call.\n\nJob description: Machine shuts down after five minutes.",
        $text,
        'Blank request notes should not strip unrelated inline scope text that merely starts with Technician notes:'
    );
})();

// --- 9. Completion certificate scope storage strips legacy multiline technician notes blocks ---
(function (): void {
    $text = completionCertificateRemoveLegacyTechnicianNotesBlocks(
        "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nTechnician notes\nLegacy saved note\n\nJob description: Machine shuts down after five minutes."
    );

    ghostLaserAuthAssertSame(
        "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nJob description: Machine shuts down after five minutes.",
        $text,
        'Completion certificate scope storage should strip legacy multiline technician notes blocks'
    );
})();

// --- 10. Completion certificate scope storage strips empty technician notes headings ---
(function (): void {
    $text = completionCertificateRemoveLegacyTechnicianNotesBlocks(
        "Requested services: Diagnosis.\n\nTechnician notes\n\nJob description: Machine shuts down after five minutes."
    );

    ghostLaserAuthAssertSame(
        "Requested services: Diagnosis.\n\nJob description: Machine shuts down after five minutes.",
        $text,
        'Completion certificate scope storage should strip empty technician notes headings'
    );
})();

// --- 11. Technician notes still render when scope text is otherwise empty ---
(function (): void {
    $text = completionCertificateBuildCompletedWorkText([
        'scope_of_work' => '',
        'technician_notes' => 'Bring replacement PSU',
    ]);

    ghostLaserAuthAssertSame(
        'Technician notes: Bring replacement PSU',
        $text,
        'Completion certificate should still render technician notes when no other scope text exists'
    );
})();

// --- 12. Legacy scope cleanup preserves inline technician notes content ---
(function (): void {
    $text = completionCertificateRemoveLegacyTechnicianNotesBlocks(
        "Requested services: Diagnosis.\n\nTechnician notes: Customer requested a follow-up call.\n\nJob description: Machine shuts down after five minutes."
    );

    ghostLaserAuthAssertSame(
        "Requested services: Diagnosis.\n\nTechnician notes: Customer requested a follow-up call.\n\nJob description: Machine shuts down after five minutes.",
        $text,
        'Completion certificate scope cleanup should preserve inline technician notes content'
    );
})();

// --- 13. Rendered technician notes deduplicate identical inline scope technician notes ---
(function (): void {
    $text = completionCertificateBuildCompletedWorkText([
        'scope_of_work' => "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nTechnician notes: Bring replacement PSU\n\nJob description: Machine shuts down after five minutes.",
        'technician_notes' => 'Bring replacement PSU',
    ]);

    ghostLaserAuthAssertSame(
        "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nTechnician notes: Bring replacement PSU\n\nJob description: Machine shuts down after five minutes.",
        $text,
        'Completion certificate should deduplicate identical inline technician notes blocks when request notes are present'
    );
})();

// --- 14. Fallback note insertion deduplicates identical inline technician notes without issue summary ---
(function (): void {
    $text = completionCertificateBuildCompletedWorkText([
        'scope_of_work' => "Requested services: Diagnosis.\n\nTechnician notes: Bring replacement PSU\n\nJob description: Machine shuts down after five minutes.",
        'technician_notes' => 'Bring replacement PSU',
    ]);

    ghostLaserAuthAssertSame(
        "Requested services: Diagnosis.\n\nJob description: Machine shuts down after five minutes.\n\nTechnician notes: Bring replacement PSU",
        $text,
        'Completion certificate fallback insertion should deduplicate identical inline technician notes blocks'
    );
})();

// --- 15. Multiline freeform blocks starting with Issue summary are not treated as structured summary blocks ---
(function (): void {
    $text = completionCertificateBuildCompletedWorkText([
        'scope_of_work' => "Requested services: Diagnosis.\n\nIssue summary: Power issue.\nAdditional freeform detail that should stay together.\n\nJob description: Machine shuts down after five minutes.",
        'technician_notes' => 'Bring replacement PSU',
    ]);

    ghostLaserAuthAssertSame(
        "Requested services: Diagnosis.\n\nIssue summary: Power issue.\nAdditional freeform detail that should stay together.\n\nJob description: Machine shuts down after five minutes.\n\nTechnician notes: Bring replacement PSU",
        $text,
        'Completion certificate should only inject after a structured single-block issue summary'
    );
})();

// --- 16. Identical inline technician notes later in scope are de-duplicated when inserting after summary ---
(function (): void {
    $text = completionCertificateBuildCompletedWorkText([
        'scope_of_work' => "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nJob description: Machine shuts down after five minutes.\n\nTechnician notes: Bring replacement PSU",
        'technician_notes' => 'Bring replacement PSU',
    ]);

    ghostLaserAuthAssertSame(
        "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nTechnician notes: Bring replacement PSU\n\nJob description: Machine shuts down after five minutes.",
        $text,
        'Completion certificate should remove later duplicate technician notes blocks before inserting after the summary'
    );
})();

// --- 17. Inline technician note dedupe normalizes spacing and label casing ---
(function (): void {
    $text = completionCertificateBuildCompletedWorkText([
        'scope_of_work' => "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nTECHNICIAN NOTES:  Bring replacement PSU  \n\nJob description: Machine shuts down after five minutes.",
        'technician_notes' => 'Bring replacement PSU',
    ]);

    ghostLaserAuthAssertSame(
        "Requested services: Diagnosis.\n\nIssue summary: Power issue.\n\nTechnician notes: Bring replacement PSU\n\nJob description: Machine shuts down after five minutes.",
        $text,
        'Completion certificate should deduplicate inline technician notes blocks even when formatting differs'
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
