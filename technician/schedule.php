<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (empty($_SESSION )) {
    header('Location: ../admin-login.php');
    exit;
}

require_once '../project/db.php';

$jobs = [];
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
            ORDER BY FIELD(LOWER(sr.priority_level), 'emergency', 'vip', 'standard'), sr.id DESC";

    $jobs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduling Dashboard | Ghost Laser</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }
        .glow { box-shadow: 0 0 25px rgba(34, 211, 238, 0.4); }
    </style>
</head>
<body class="bg-zinc-950 text-white">
    <header class="fixed top-0 left-0 right-0 z-50 bg-zinc-950/80 backdrop-blur-lg border-b border-zinc-800">
        <div class="max-w-7xl mx-auto px-6">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-3">
                    <span class="w-8 h-8 bg-cyan-500 rounded flex items-center justify-center">
                        <span class="text-zinc-950 font-bold text-xl">G</span>
                    </span>
                    <span class="font-bold text-2xl tracking-tighter">Ghost<span class="text-cyan-400">Laser</span></span>
                </div>
                <a href="../dashboard.php" class="text-zinc-400 hover:text-white">← Back to Dashboard</a>
            </div>
        </div>
    </header>

    <main class="pt-28 pb-12 px-6 max-w-7xl mx-auto">
        <div class="flex justify-between items-end mb-10">
            <div>
                <h1 class="text-5xl font-bold tracking-tight">Scheduling Dashboard</h1>
                <p class="text-zinc-400 mt-2">Pending service requests</p>
            </div>
            <button onclick="alert('Clustering feature coming soon')" 
                    class="px-6 py-3 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-bold rounded-xl transition-all">
                Run Geographic Clustering
            </button>
        </div>

        <div class="bg-zinc-900 border border-zinc-700 rounded-3xl overflow-hidden">
            <table class="w-full">
                <thead class="bg-zinc-800">
                    <tr>
                        <th class="px-6 py-5 text-left">Customer</th>
                        <th class="px-6 py-5 text-left">City</th>
                        <th class="px-6 py-5 text-left">Coordinates</th>
                        <th class="px-6 py-5 text-left">Priority</th>
                        <th class="px-6 py-5 text-left">Problem</th>
                        <th class="px-6 py-5 text-left">Preferred Dates</th>
                        <th class="px-6 py-5 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-700">
                    <?php foreach ($jobs as $job): ?>
                    <tr class="hover:bg-zinc-800 transition-colors">
                        <td class="px-6 py-5"><?= htmlspecialchars($job . ' ' . $job ) ?></td>
                        <td class="px-6 py-5"><?= htmlspecialchars($job ) ?></td>
                        <td class="px-6 py-5 font-mono text-sm text-cyan-400">
                            <?= $job && $job['longitude'] 
                                ? number_format($job ,4) . ', ' . number_format($job ,4) 
                                : 'N/A' ?>
                        </td>
                        <td class="px-6 py-5">
                            <span class="px-4 py-1 text-xs font-semibold rounded-full 
                                <?= strtolower($job ?? '') === 'emergency' ? 'bg-red-500/20 text-red-300' : 
                                   (strtolower($job ?? '') === 'vip' ? 'bg-orange-500/20 text-orange-300' : 'bg-blue-500/20 text-blue-300') ?>">
                                <?= htmlspecialchars(ucfirst($job ?? 'Standard')) ?>
                            </span>
                        </td>
                        <td class="px-6 py-5 text-sm text-zinc-300"><?= htmlspecialchars($job ?? 'No summary') ?></td>
                        <td class="px-6 py-5 text-sm text-zinc-400">
                            <?= htmlspecialchars($job ?? 'N/A') ?>
                        </td>
                        <td class="px-6 py-5">
                            <form method="POST" onsubmit="return confirm('Delete this request?');">
                                <input type="hidden" name="delete_id" value="<?= $job ?>">
                                <button type="submit" class="text-red-400 hover:text-red-500 text-sm font-medium">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>