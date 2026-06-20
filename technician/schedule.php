<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: ../admin-login.php');
    exit;
}

require_once '../project/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int) $_POST['delete_id'];
    if ($deleteId > 0) {
        try {
            $deleteStmt = $pdo->prepare('DELETE FROM service_requests WHERE id = ?');
            $deleteStmt->execute([$deleteId]);
        } catch (Throwable $e) {
            // No-op
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$rows = [];
$errorMessage = null;

try {
    $sql = "SELECT
                sr.id,
                c.first_name,
                c.last_name,
                c.city,
                sr.latitude,
                sr.longitude,
                sr.priority_level,
                sr.problem_summary,
                sr.preferred_date_start,
                sr.preferred_date_end
            FROM service_requests sr
            JOIN customers c ON sr.customer_id = c.id
            WHERE sr.request_status IN ('new', 'queued')
            ORDER BY FIELD(LOWER(sr.priority_level), 'emergency', 'vip', 'standard'), sr.preferred_date_start ASC, sr.id DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduling Dashboard | Ghost Laser</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-700">Admin</p>
                    <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-900">Scheduling Dashboard</h1>
                    <p class="mt-2 text-sm text-slate-600">Showing service requests with status <span class="font-semibold">new</span> or <span class="font-semibold">queued</span>.</p>
                </div>
                <a href="../dashboard.php" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($errorMessage !== null): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Customer Name</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">City</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Coordinates</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Priority</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Problem Summary</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Preferred Dates</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Delete</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-sm text-slate-500">No matching service requests found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $customerName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                                if ($customerName === '') {
                                    $customerName = 'Unknown Customer';
                                }

                                $lat = $row['latitude'] ?? '';
                                $lng = $row['longitude'] ?? '';
                                $coordinates = 'N/A';
                                if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)) {
                                    $coordinates = number_format((float) $lat, 6, '.', '') . ', ' . number_format((float) $lng, 6, '.', '');
                                }

                                $startDate = trim((string) ($row['preferred_date_start'] ?? ''));
                                $endDate = trim((string) ($row['preferred_date_end'] ?? ''));
                                $preferredDates = $startDate !== '' && $endDate !== '' ? $startDate . ' to ' . $endDate : ($startDate !== '' ? $startDate : ($endDate !== '' ? $endDate : 'N/A'));
                                ?>
                                <tr class="align-top hover:bg-slate-50">
                                    <td class="px-5 py-4 text-sm font-semibold text-slate-900"><?= htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td class="px-5 py-4 text-sm text-slate-700"><?= htmlspecialchars((string) ($row['city'] ?? 'N/A'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td class="px-5 py-4 text-sm text-slate-700"><?= htmlspecialchars($coordinates, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td class="px-5 py-4 text-sm text-slate-700"><?= htmlspecialchars((string) ($row['priority_level'] ?? 'N/A'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td class="px-5 py-4 text-sm text-slate-700 max-w-md"><?= htmlspecialchars((string) ($row['problem_summary'] ?? 'N/A'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td class="px-5 py-4 text-sm text-slate-700"><?= htmlspecialchars($preferredDates, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td class="px-5 py-4">
                                        <form method="POST" action="" onsubmit="return confirm('Delete this request?');">
                                            <input type="hidden" name="delete_id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                            <button type="submit" class="inline-flex items-center rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
