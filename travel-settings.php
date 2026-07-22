<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/travel-helper.php';

$settings = getTravelSettings($pdo);
$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedPricePerMile = trim((string) ($_POST['price_per_mile'] ?? ''));
    $submittedHourlyTravelRate = trim((string) ($_POST['hourly_travel_rate'] ?? ''));
    $submittedBaseLocation = trim((string) ($_POST['base_location'] ?? ''));

    if ($submittedPricePerMile === '' || !is_numeric($submittedPricePerMile) || (float) $submittedPricePerMile < 0) {
        $errorMessage = 'Price per mile must be a valid non-negative number.';
        $settings = array_merge($settings, ['price_per_mile' => $submittedPricePerMile, 'hourly_travel_rate' => $submittedHourlyTravelRate, 'base_location' => $submittedBaseLocation]);
    } elseif ($submittedHourlyTravelRate === '' || !is_numeric($submittedHourlyTravelRate) || (float) $submittedHourlyTravelRate < 0) {
        $errorMessage = 'Hourly travel rate must be a valid non-negative number.';
        $settings = array_merge($settings, ['price_per_mile' => $submittedPricePerMile, 'hourly_travel_rate' => $submittedHourlyTravelRate, 'base_location' => $submittedBaseLocation]);
    } else {
        try {
            updateTravelSettings($pdo, [
                'price_per_mile' => $submittedPricePerMile,
                'hourly_travel_rate' => $submittedHourlyTravelRate,
                'base_location' => $submittedBaseLocation,
            ]);
            $settings = getTravelSettings($pdo);
            $successMessage = 'Travel settings updated successfully.';
        } catch (Throwable $e) {
            $errorMessage = 'Unable to save travel settings right now.';
        }
    }
}
?>
<?php
$pageTitle = 'Travel Settings | Ghost Laser';
$pageDescription = 'Ghost Laser travel settings management.';
$extraHead = <<<'HTML'
    <style>
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
    </style>
HTML;
$headerRight = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                    <a href="service-settings.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Service Settings</a>
                </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen hero-grid pt-24 pb-16 px-4">
        <div class="max-w-4xl mx-auto space-y-8">
            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/20 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-[0.2em] text-cyan-400">
                    Admin Settings
                </div>
                <h1 class="mt-4 text-3xl font-bold tracking-tight">Travel Settings</h1>
                <p class="mt-2 text-zinc-400">
                    Set the default travel pricing used in booking totals.
                </p>
            </section>

            <?php if ($successMessage !== null): ?>
                <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-400">
                    <?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== null): ?>
                <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-6">
                <div>
                    <h2 class="text-xl font-semibold text-white">Travel Pricing</h2>
                    <p class="mt-2 text-sm text-zinc-400">Travel billing uses the higher of mileage-based cost or hourly drive-time cost for each request.</p>
                </div>

                <label class="block max-w-sm">
                    <span class="text-sm font-medium text-zinc-200">Price per Mile ($)</span>
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        name="price_per_mile"
                        value="<?= htmlspecialchars((string) $settings['price_per_mile'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none"
                        required
                    >
                </label>

                <label class="block max-w-sm">
                    <span class="text-sm font-medium text-zinc-200">Hourly Travel Rate ($/hour)</span>
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        name="hourly_travel_rate"
                        value="<?= htmlspecialchars((string) $settings['hourly_travel_rate'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none"
                        required
                    >
                </label>

                <label class="block max-w-sm">
                    <span class="text-sm font-medium text-zinc-200">Base Location (origin address)</span>
                    <input
                        type="text"
                        name="base_location"
                        value="<?= htmlspecialchars((string) ($settings['base_location'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        placeholder="e.g. 123 Main St, Los Angeles, CA 90001"
                        class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none"
                    >
                    <p class="mt-1 text-xs text-zinc-500">Used as the driving origin when calculating the travel fee. Leave blank to use the default estimate.</p>
                </label>

                <div class="rounded-2xl border border-cyan-500/20 bg-cyan-500/10 p-4 text-sm text-cyan-100">
                    <div>Current price per mile: <span class="font-semibold">$<?= number_format((float) $settings['price_per_mile'], 2) ?></span></div>
                    <div class="mt-1">Current hourly travel rate: <span class="font-semibold">$<?= number_format((float) $settings['hourly_travel_rate'], 2) ?>/hour</span></div>
                </div>

                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-cyan-500 px-5 py-3 text-sm font-semibold text-zinc-950 transition hover:bg-cyan-400">
                    Save Travel Settings
                </button>
            </form>
        </div>
    </main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
