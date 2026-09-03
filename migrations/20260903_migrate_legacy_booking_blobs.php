<?php
/**
 * One-time migration for booking summaries created before structured fields
 * were submitted separately.
 *
 * Preview ten rows on a copy:
 *   php migrations/20260903_migrate_legacy_booking_blobs.php --copy-table=service_requests_copy
 *
 * Apply to the live table only after reviewing the preview:
 *   php migrations/20260903_migrate_legacy_booking_blobs.php --apply
 */

function migrationError(string $message): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message);
    } else {
        echo $message;
    }
}

if (PHP_SAPI !== 'cli') {
    migrationError("This migration must be run from the command line.\n");
    exit(1);
}

require __DIR__ . '/../project/db.php';

$options = getopt('', ['apply', 'copy-table:']);
$sourceTable = 'service_requests';
$targetTable = isset($options['copy-table']) ? (string) $options['copy-table'] : $sourceTable;
$isApply = isset($options['apply']);

if (!$isApply && !isset($options['copy-table'])) {
    migrationError("Refusing to modify service_requests without --apply or --copy-table.\n");
    exit(1);
}

foreach ([$sourceTable, $targetTable] as $table) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new InvalidArgumentException("Invalid table name: {$table}");
    }
}

if ($targetTable !== $sourceTable) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$targetTable}` LIKE `{$sourceTable}`");
    $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$targetTable}`")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO `{$targetTable}` SELECT * FROM `{$sourceTable}`");
    }
}

$columns = [
    'problem'      => "TEXT NULL COMMENT 'Original customer problem description'",
    'services'     => "JSON NULL COMMENT 'Selected services as JSON array'",
    'speed'        => "VARCHAR(50) NULL COMMENT 'Booking speed/tier key'",
    'service_total'=> "DECIMAL(10,2) NULL COMMENT 'Calculated service total'",
    'travel_fee'   => "DECIMAL(10,2) NULL COMMENT 'Calculated travel fee'",
    'grand_total'  => "DECIMAL(10,2) NULL COMMENT 'Calculated booking total'",
];
foreach ($columns as $column => $definition) {
    try {
        $pdo->exec("ALTER TABLE `{$targetTable}` ADD COLUMN `{$column}` {$definition}");
    } catch (Throwable $e) {
        // Idempotent: the column already exists.
    }
}

function parseLegacyBooking(string $blob): ?array
{
    $separator = '--- Booking Summary ---';
    if (strpos($blob, $separator) === false) {
        return null;
    }

    [$problem, $summary] = array_pad(explode($separator, $blob, 2), 2, '');
    $summary = preg_replace('/\R+--- Internal Booking \(phone-in\) ---\s*$/', '', $summary);
    $result = [
        'problem' => trim($problem),
        'services' => null,
        'speed' => null,
        'service_total' => null,
        'travel_fee' => null,
        'grand_total' => null,
    ];

    if (preg_match('/^Selected services:\s*(.+)$/mi', $summary, $match)) {
        $services = array_values(array_filter(array_map('trim', explode(',', $match[1]))));
        $result['services'] = $services !== [] ? json_encode($services, JSON_UNESCAPED_UNICODE) : null;
    }
    if (preg_match('/^Service speed:\s*(.+)$/mi', $summary, $match)) {
        $speed = strtolower(trim($match[1]));
        $result['speed'] = ['standard' => 'standard', 'vip' => 'rush', 'rush' => 'rush', 'emergency' => 'emergency'][$speed] ?? $speed;
    }
    foreach (['service_total' => 'Service total', 'travel_fee' => 'Travel fee', 'grand_total' => 'Grand total'] as $field => $label) {
        if (preg_match('/^' . preg_quote($label, '/') . '.*?:\s*\$?([0-9]+(?:\.[0-9]{1,2})?)/mi', $summary, $match)) {
            $result[$field] = number_format((float) $match[1], 2, '.', '');
        }
    }
    return $result;
}

$select = $pdo->query("SELECT id, problem_summary, problem_details FROM `{$targetTable}` ORDER BY id");
$rows = $select->fetchAll(PDO::FETCH_ASSOC);
$update = $pdo->prepare("
    UPDATE `{$targetTable}`
    SET problem = ?, services = ?, speed = ?, service_total = ?, travel_fee = ?, grand_total = ?
    WHERE id = ?
");
$samples = [];
$changed = 0;

$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        $parsed = parseLegacyBooking((string) ($row['problem_details'] ?: $row['problem_summary']));
        if ($parsed === null || $parsed['problem'] === '') {
            continue;
        }
        $update->execute([
            $parsed['problem'], $parsed['services'], $parsed['speed'],
            $parsed['service_total'], $parsed['travel_fee'], $parsed['grand_total'], $row['id'],
        ]);
        $changed++;
        if (count($samples) < 10) {
            $samples[] = [
                'id' => $row['id'],
                'before' => ['problem_summary' => $row['problem_summary'], 'problem_details' => $row['problem_details']],
                'after' => $parsed,
            ];
        }
    }
    if ($isApply) {
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

echo ($isApply ? "Applied" : "Preview (rolled back)") . " {$changed} row(s) on {$targetTable}.\n";
echo json_encode($samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
