<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}
?>
<?php
$pageTitle       = 'Service Settings | Ghost Laser';
$pageDescription = 'Ghost Laser service settings management.';
$extraHead       = <<<'HTML'
    <style>
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
    </style>
HTML;
$headerRight     = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                    <a href="settings.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Scheduling Settings</a>
                </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen hero-grid pt-24 pb-16 px-4">
        <div class="max-w-5xl mx-auto space-y-8">
            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/20 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-[0.2em] text-cyan-400">
                    Admin Settings
                </div>
                <h1 class="mt-4 text-3xl font-bold tracking-tight">Service Settings</h1>
                <p class="mt-2 text-zinc-400">
                    This page will manage service entries, display names, and base prices.
                </p>
            </section>

            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <h2 class="text-xl font-semibold text-white">Services List</h2>
                <p class="mt-2 text-sm text-zinc-400">
                    Service management controls will be added here in a follow-up update.
                </p>
            </section>
        </div>
    </main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
