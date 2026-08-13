<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/prospects_schema.php';

$message = '';
$error = '';

try {
    prospectsEnsureSchema($pdo);
    $message = 'Prospect schema migration completed successfully.';
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Prospect Migration | Ghost Laser';
$pageDescription = 'Run the prospects schema migration.';
$headerRight = '<div class="flex items-center gap-3"><a href="prospects.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Prospects</a></div>';
require_once __DIR__ . '/templates/header.php';
?>
<main class="min-h-screen hero-grid pt-24 pb-16 px-4">
    <div class="max-w-3xl mx-auto space-y-6">
        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6">
            <h1 class="text-2xl font-bold">Prospect Migration</h1>
            <p class="mt-2 text-zinc-400">This page runs prospects, interactions, and prospect template table migrations.</p>
        </section>
        <?php if ($message !== ''): ?>
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-400"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <a href="prospects.php" class="inline-flex rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950">Open Prospects</a>
    </div>
</main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
