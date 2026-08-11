<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/mileage_schema.php';

ensureMileageVehicleSchema($pdo);

function vehicleNormalizePlate(string $plate): string
{
    return strtoupper(trim(preg_replace('/\s+/', ' ', $plate)));
}

function vehicleIsValidPlate(string $plate): bool
{
    return (bool) preg_match('/^[A-Z0-9\- ]{2,20}$/', $plate);
}

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'add' || $action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $yearRaw = trim((string) ($_POST['year'] ?? ''));
            $make = trim((string) ($_POST['make'] ?? ''));
            $model = trim((string) ($_POST['model'] ?? ''));
            $licensePlate = vehicleNormalizePlate((string) ($_POST['license_plate'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $setDefault = isset($_POST['is_default']) && $_POST['is_default'] === '1';

            $year = null;
            if ($yearRaw !== '') {
                if (!ctype_digit($yearRaw)) {
                    throw new RuntimeException('Year must be a valid number.');
                }
                $year = (int) $yearRaw;
                if ($year < 1900 || $year > 2100) {
                    throw new RuntimeException('Year must be between 1900 and 2100.');
                }
            }
            if ($name === '') {
                throw new RuntimeException('Vehicle name is required.');
            }
            if ($make === '') {
                throw new RuntimeException('Vehicle make is required.');
            }
            if ($model === '') {
                throw new RuntimeException('Vehicle model is required.');
            }
            if ($licensePlate === '') {
                throw new RuntimeException('License plate is required.');
            }
            if (!vehicleIsValidPlate($licensePlate)) {
                throw new RuntimeException('License plate may only include letters, numbers, spaces, and hyphens (2-20 chars).');
            }

            if ($action === 'add') {
                $stmt = $pdo->prepare("
                    INSERT INTO vehicles (name, year, make, model, license_plate, notes, is_active, is_default)
                    VALUES (:name, :year, :make, :model, :license_plate, :notes, 1, 0)
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':year' => $year,
                    ':make' => $make,
                    ':model' => $model,
                    ':license_plate' => $licensePlate,
                    ':notes' => $notes !== '' ? $notes : null,
                ]);
                $id = (int) $pdo->lastInsertId();
                $successMessage = 'Vehicle added successfully.';
            } else {
                if ($id <= 0) {
                    throw new RuntimeException('Invalid vehicle ID.');
                }
                $stmt = $pdo->prepare("
                    UPDATE vehicles
                    SET name = :name,
                        year = :year,
                        make = :make,
                        model = :model,
                        license_plate = :license_plate,
                        notes = :notes
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':year' => $year,
                    ':make' => $make,
                    ':model' => $model,
                    ':license_plate' => $licensePlate,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':id' => $id,
                ]);
                $successMessage = 'Vehicle updated successfully.';
            }

            if ($setDefault) {
                mileageSetDefaultVehicle($pdo, $id);
                mileageBackfillLogsToDefaultVehicle($pdo);
                $successMessage = 'Vehicle saved and set as default.';
            }
        } elseif ($action === 'toggle_active') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid vehicle ID.');
            }
            $stmt = $pdo->prepare("SELECT is_active, is_default FROM vehicles WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$vehicle) {
                throw new RuntimeException('Vehicle not found.');
            }

            $newState = (int) $vehicle['is_active'] === 1 ? 0 : 1;
            $update = $pdo->prepare("UPDATE vehicles SET is_active = :active WHERE id = :id");
            $update->execute([
                ':active' => $newState,
                ':id' => $id,
            ]);

            if ($newState === 0 && (int) $vehicle['is_default'] === 1) {
                $clear = $pdo->prepare("UPDATE vehicles SET is_default = 0 WHERE id = :id");
                $clear->execute([':id' => $id]);
            }

            $successMessage = $newState === 1 ? 'Vehicle activated.' : 'Vehicle deactivated.';
        } elseif ($action === 'set_default') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid vehicle ID.');
            }
            $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = :id AND is_active = 1 LIMIT 1");
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Only active vehicles can be set as default.');
            }

            mileageSetDefaultVehicle($pdo, $id);
            mileageBackfillLogsToDefaultVehicle($pdo);
            $successMessage = 'Default vehicle updated and existing mileage logs were backfilled.';
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage() ?: 'Unable to save vehicle settings right now.';
    }
}

$vehicles = $pdo->query("
    SELECT
        v.*,
        COUNT(ml.id) AS log_count
    FROM vehicles v
    LEFT JOIN mileage_logs ml ON ml.vehicle_id = v.id
    GROUP BY v.id
    ORDER BY v.is_active DESC, v.is_default DESC, v.name ASC, v.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php
$pageTitle       = 'Vehicle Settings | Ghost Laser';
$pageDescription = 'Ghost Laser vehicle settings management.';
$extraHead       = <<<'HTML'
    <style>
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
    </style>
HTML;
$headerRight     = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                    <a href="mileage-tracker.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Mileage Tracker</a>
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
                <h1 class="mt-4 text-3xl font-bold tracking-tight">Vehicle Settings</h1>
                <p class="mt-2 text-zinc-400">Manage vehicles used for technician mileage logs and set a default vehicle for backfill.</p>
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

            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <h2 class="text-xl font-semibold text-white">Add Vehicle</h2>
                <form method="POST" class="mt-5 grid gap-4 md:grid-cols-2">
                    <input type="hidden" name="action" value="add">
                    <label class="block">
                        <span class="text-sm font-medium text-zinc-200">Vehicle Name</span>
                        <input type="text" name="name" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-zinc-200">Year</span>
                        <input type="number" min="1900" max="2100" name="year" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-zinc-200">Make</span>
                        <input type="text" name="make" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-zinc-200">Model</span>
                        <input type="text" name="model" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-zinc-200">License Plate</span>
                        <input type="text" name="license_plate" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-zinc-200">Default Vehicle</span>
                        <div class="mt-3 flex items-center gap-2 text-sm text-zinc-300">
                            <input id="add-default-vehicle" type="checkbox" name="is_default" value="1" class="h-4 w-4 rounded border-zinc-700 bg-zinc-950 text-cyan-400 focus:ring-cyan-500">
                            <label for="add-default-vehicle">Set as default and backfill existing logs</label>
                        </div>
                    </label>
                    <label class="block md:col-span-2">
                        <span class="text-sm font-medium text-zinc-200">Notes (optional)</span>
                        <textarea name="notes" rows="3" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none"></textarea>
                    </label>
                    <div class="md:col-span-2">
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-cyan-500 px-5 py-3 text-sm font-semibold text-zinc-950 transition hover:bg-cyan-400">
                            Save Vehicle
                        </button>
                    </div>
                </form>
            </section>

            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <h2 class="text-xl font-semibold text-white">Vehicles</h2>
                <?php if ($vehicles === []): ?>
                    <p class="mt-3 text-sm text-zinc-400">No vehicles yet.</p>
                <?php else: ?>
                    <div class="mt-5 space-y-3">
                        <?php foreach ($vehicles as $vehicle): ?>
                            <?php
                            $vehicleSummary = trim((string) $vehicle['name']);
                            $vehicleYmm = trim((string) trim(($vehicle['year'] ?? '') . ' ' . ($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')));
                            if ($vehicleYmm !== '') {
                                $vehicleSummary .= ' — ' . $vehicleYmm;
                            }
                            ?>
                            <details class="rounded-xl border border-zinc-800 bg-zinc-950/70 p-4">
                                <summary class="cursor-pointer list-none">
                                    <div class="flex flex-wrap items-center gap-2 justify-between">
                                        <div>
                                            <div class="text-sm font-semibold text-white"><?= htmlspecialchars($vehicleSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                            <div class="text-xs text-zinc-400 mt-1">
                                                Plate: <?= htmlspecialchars((string) $vehicle['license_plate'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                • Logs: <?= (int) $vehicle['log_count'] ?>
                                                <?php if ((int) $vehicle['is_default'] === 1): ?>
                                                    • <span class="text-cyan-300">Default Vehicle</span>
                                                <?php endif; ?>
                                                <?php if ((int) $vehicle['is_active'] !== 1): ?>
                                                    • <span class="text-amber-300">Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <?php if ((int) $vehicle['is_default'] !== 1 && (int) $vehicle['is_active'] === 1): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="set_default">
                                                    <input type="hidden" name="id" value="<?= (int) $vehicle['id'] ?>">
                                                    <button type="submit" class="rounded-lg border border-cyan-500/40 bg-cyan-500/10 px-3 py-1.5 text-xs font-medium text-cyan-300 hover:bg-cyan-500/20">
                                                        Set Default
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="id" value="<?= (int) $vehicle['id'] ?>">
                                                <button type="submit" class="rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-xs font-medium text-zinc-300 hover:border-cyan-500/40 hover:text-cyan-300">
                                                    <?= (int) $vehicle['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </summary>

                                <form method="POST" class="mt-4 grid gap-3 md:grid-cols-2">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int) $vehicle['id'] ?>">
                                    <label class="block">
                                        <span class="text-xs font-medium uppercase tracking-wide text-zinc-400">Vehicle Name</span>
                                        <input type="text" name="name" value="<?= htmlspecialchars((string) $vehicle['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-1.5 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium uppercase tracking-wide text-zinc-400">Year</span>
                                        <input type="number" min="1900" max="2100" name="year" value="<?= htmlspecialchars((string) ($vehicle['year'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-1.5 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-white focus:border-cyan-400 focus:outline-none">
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium uppercase tracking-wide text-zinc-400">Make</span>
                                        <input type="text" name="make" value="<?= htmlspecialchars((string) $vehicle['make'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-1.5 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium uppercase tracking-wide text-zinc-400">Model</span>
                                        <input type="text" name="model" value="<?= htmlspecialchars((string) $vehicle['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-1.5 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium uppercase tracking-wide text-zinc-400">License Plate</span>
                                        <input type="text" name="license_plate" value="<?= htmlspecialchars((string) $vehicle['license_plate'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-1.5 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium uppercase tracking-wide text-zinc-400">Default Vehicle</span>
                                        <div class="mt-3 flex items-center gap-2 text-sm text-zinc-300">
                                            <input id="vehicle-default-<?= (int) $vehicle['id'] ?>" type="checkbox" name="is_default" value="1" class="h-4 w-4 rounded border-zinc-700 bg-zinc-950 text-cyan-400 focus:ring-cyan-500" <?= (int) $vehicle['is_default'] === 1 ? 'checked' : '' ?> <?= (int) $vehicle['is_active'] === 1 ? '' : 'disabled' ?>>
                                            <label for="vehicle-default-<?= (int) $vehicle['id'] ?>">Set as default and backfill null vehicle logs</label>
                                        </div>
                                    </label>
                                    <label class="block md:col-span-2">
                                        <span class="text-xs font-medium uppercase tracking-wide text-zinc-400">Notes</span>
                                        <textarea name="notes" rows="2" class="mt-1.5 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-white focus:border-cyan-400 focus:outline-none"><?= htmlspecialchars((string) ($vehicle['notes'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                                    </label>
                                    <div class="md:col-span-2">
                                        <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-xs font-semibold text-zinc-950 hover:bg-cyan-400">
                                            Save Changes
                                        </button>
                                    </div>
                                </form>
                            </details>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>

