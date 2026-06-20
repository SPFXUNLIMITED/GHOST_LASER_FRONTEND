<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: ../admin-login.php');
    exit;
}

require_once '../project/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deleteId = filter_input(
        INPUT_POST,
        'delete_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($deleteId) {
        $deleteStmt = $pdo->prepare("
            UPDATE service_requests
            SET request_status = :status
            WHERE id = :id
        ");
        $deleteStmt->execute([
            ':status' => 'deleted',
            ':id' => $deleteId
        ]);
    }

    header('Location: schedule.php');
    exit;
}

$jobs = $pdo->query("
    SELECT 
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
    ORDER BY FIELD(LOWER(sr.priority_level), 'emergency', 'vip', 'standard'), sr.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduling Dashboard | Ghost Laser</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-zinc-950 text-white p-8">
    <div class="max-w-7xl mx-auto">
        <h1 class="text-5xl font-bold mb-2">Scheduling Dashboard</h1>
        <p class="text-zinc-400 mb-8">Pending service requests (<?= count($jobs) ?> found)</p>

        <div class="bg-zinc-900 border border-zinc-700 rounded-3xl overflow-hidden">
            <table class="w-full">
                <thead class="bg-zinc-800">
                    <tr>
                        <th class="p-6 text-left">Customer</th>
                        <th class="p-6 text-left">City</th>
                        <th class="p-6 text-left">Coordinates</th>
                        <th class="p-6 text-left">Priority</th>
                        <th class="p-6 text-left">Problem</th>
                        <th class="p-6 text-left">Dates</th>
                        <th class="p-6 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-700">
                    <?php foreach ($jobs as $job): ?>
                    <tr class="hover:bg-zinc-800">
                        <td class="p-6"><?= htmlspecialchars($job['first_name'] . ' ' . $job['last_name']) ?></td>
                        <td class="p-6"><?= htmlspecialchars($job['city'] ?? 'N/A') ?></td>
                        <td class="p-6 font-mono text-sm text-cyan-400">
                            <?= ($job['latitude'] && $job['longitude']) ? number_format((float)$job['latitude'], 4) . ', ' . number_format((float)$job['longitude'], 4) : 'N/A' ?>
                        </td>
                        <td class="p-6">
                            <?php $priority = strtolower($job['priority_level'] ?? 'standard'); ?>
                            <span class="px-4 py-1 rounded-full text-xs font-semibold <?= $priority === 'emergency' ? 'bg-red-500/20 text-red-300' : ($priority === 'vip' ? 'bg-orange-500/20 text-orange-300' : 'bg-blue-500/20 text-blue-300') ?>">
                                <?= htmlspecialchars(ucfirst($job['priority_level'] ?? 'Standard')) ?>
                            </span>
                        </td>
                        <td class="p-6 text-sm text-zinc-400"><?= htmlspecialchars($job['problem_summary'] ?? 'No summary') ?></td>
                        <td class="p-6 text-sm text-zinc-400">
                            <?= htmlspecialchars($job['preferred_date_start'] ?? 'N/A') ?>
                            <?php if (!empty($job['preferred_date_end'])): ?>
                                &ndash; <?= htmlspecialchars($job['preferred_date_end']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="p-6">
                            <form method="POST" onsubmit="return confirm('Delete this request?');">
                                <input type="hidden" name="delete_id" value="<?= (int)$job['id'] ?>">
                                <button type="submit" class="text-red-400 hover:text-red-500 text-sm font-medium">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>