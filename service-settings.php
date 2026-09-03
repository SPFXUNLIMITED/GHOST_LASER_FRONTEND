<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';

// --- Database helpers ---

function ensureServicesTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS services (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            service_name VARCHAR(255) NOT NULL,
            base_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            duration_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
}

function ensureServicesDurationColumn(PDO $pdo): void
{
    $exists = (int) $pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'services'
          AND COLUMN_NAME  = 'duration_minutes'
    ")->fetchColumn();
    if (!$exists) {
        $pdo->exec("ALTER TABLE services ADD COLUMN duration_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER base_price");
    }
}

function seedServicesIfEmpty(PDO $pdo): void
{
    $count = (int) $pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
    if ($count > 0) {
        return;
    }
    // Keep this list in sync with the live catalog. It only runs once, against
    // an empty table (fresh install, staging, or a schema-only disaster-recovery
    // restore) — any service added later through this page must be reflected
    // here too, or a re-seed will silently omit it.
    $defaults = [
        ['Maintenance & Alignment',                    150.00, 90],
        ['Tube Change',                                 320.00, 120],
        ['Diagnosis',                                    120.00, 60],
        ['Advanced Diagnosis',                          180.00, 90],
        ['Tube Change, Maintenance & Alignment',        420.00, 150],
        ['Training',                                     180.00, 120],
        ['Other',                                         100.00, 60],
    ];
    $stmt = $pdo->prepare("INSERT INTO services (service_name, base_price, duration_minutes) VALUES (:name, :price, :duration)");
    foreach ($defaults as [$name, $price, $duration]) {
        $stmt->execute([':name' => $name, ':price' => $price, ':duration' => $duration]);
    }
}

function getServices(PDO $pdo): array
{
    return $pdo->query("SELECT id, service_name, base_price, duration_minutes FROM services ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check whether another service already has the same name once normalized
 * (trimmed, case-insensitive). Prevents creating duplicate normalized names
 * that would collide in repairServiceRequestsServiceNames()'s and
 * resolveJobDurationsFromServices()'s name => id lookup maps.
 */
function serviceNameExists(PDO $pdo, string $name, ?int $excludeId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM services WHERE LOWER(TRIM(service_name)) = LOWER(TRIM(:name))';
    $params = [':name' => $name];
    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}

ensureServicesTable($pdo);
ensureServicesDurationColumn($pdo);
seedServicesIfEmpty($pdo);

// --- Handle POST actions ---

$successMessage = null;
$errorMessage   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add') {
        $name     = trim((string) ($_POST['service_name'] ?? ''));
        $price    = trim((string) ($_POST['base_price'] ?? ''));
        $duration = trim((string) ($_POST['duration_minutes'] ?? ''));

        if ($name === '') {
            $errorMessage = 'Service name is required.';
        } elseif (!is_numeric($price) || (float) $price < 0) {
            $errorMessage = 'Base price must be a valid non-negative number.';
        } elseif (!ctype_digit($duration) || (int) $duration < 1) {
            $errorMessage = 'Duration must be a whole number of minutes (minimum 1).';
        } elseif (serviceNameExists($pdo, $name)) {
            $errorMessage = 'A service with that name (ignoring case/whitespace) already exists.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO services (service_name, base_price, duration_minutes) VALUES (:name, :price, :duration)");
            $stmt->execute([':name' => $name, ':price' => round((float) $price, 2), ':duration' => (int) $duration]);
            $successMessage = 'Service added successfully.';
        }

    } elseif ($action === 'edit') {
        $id       = (int) ($_POST['id'] ?? 0);
        $name     = trim((string) ($_POST['service_name'] ?? ''));
        $price    = trim((string) ($_POST['base_price'] ?? ''));
        $duration = trim((string) ($_POST['duration_minutes'] ?? ''));

        if ($id <= 0) {
            $errorMessage = 'Invalid service ID.';
        } elseif ($name === '') {
            $errorMessage = 'Service name is required.';
        } elseif (!is_numeric($price) || (float) $price < 0) {
            $errorMessage = 'Base price must be a valid non-negative number.';
        } elseif (!ctype_digit($duration) || (int) $duration < 1) {
            $errorMessage = 'Duration must be a whole number of minutes (minimum 1).';
        } elseif (serviceNameExists($pdo, $name, $id)) {
            $errorMessage = 'A service with that name (ignoring case/whitespace) already exists.';
        } else {
            $stmt = $pdo->prepare("UPDATE services SET service_name = :name, base_price = :price, duration_minutes = :duration WHERE id = :id");
            $stmt->execute([':name' => $name, ':price' => round((float) $price, 2), ':duration' => (int) $duration, ':id' => $id]);
            $successMessage = 'Service updated successfully.';
        }

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            $errorMessage = 'Invalid service ID.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM services WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $successMessage = 'Service deleted.';
        }
    }

    if ($successMessage !== null) {
        header('Location: service-settings.php?msg=' . urlencode($successMessage));
        exit;
    }
}

if (isset($_GET['msg'])) {
    $successMessage = htmlspecialchars(trim((string) $_GET['msg']), ENT_QUOTES, 'UTF-8');
}

$services = getServices($pdo);
?>
<?php
$pageTitle       = 'Service Settings | Ghost Laser';
$pageDescription = 'Ghost Laser service settings management.';
$extraHead       = <<<'HTML'
    <style>
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
        .modal-overlay { background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); }
    </style>
HTML;
$headerRight     = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                    <a href="settings.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Scheduling Settings</a>
                    <a href="vehicle-settings.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Vehicle Settings</a>
                </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen hero-grid pt-24 pb-16 px-4">
        <div class="max-w-4xl mx-auto space-y-8">

            <!-- Page header -->
            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/20 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-[0.2em] text-cyan-400">
                    Admin Settings
                </div>
                <h1 class="mt-4 text-3xl font-bold tracking-tight">Service Settings</h1>
                <p class="mt-2 text-zinc-400">
                    Manage service offerings, display names, and base prices.
                </p>
            </section>

            <?php if ($successMessage !== null): ?>
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-400">
                <?= $successMessage ?>
            </div>
            <?php endif; ?>

            <?php if ($errorMessage !== null): ?>
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <!-- Services table -->
            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-semibold text-white">Services</h2>
                    <button
                        type="button"
                        onclick="openAddModal()"
                        class="inline-flex items-center gap-2 rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/50"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add New Service
                    </button>
                </div>

                <?php if (empty($services)): ?>
                <p class="text-zinc-500 text-sm">No services found. Add one above.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-800">
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Service Name</th>
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Base Price</th>
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Duration</th>
                                <th class="pb-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60">
                            <?php foreach ($services as $svc): ?>
                            <tr class="group">
                                <td class="py-3.5 pr-4 font-medium text-white">
                                    <?= htmlspecialchars($svc['service_name'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="py-3.5 pr-4 text-zinc-300">
                                    $<?= number_format((float) $svc['base_price'], 2) ?>
                                </td>
                                <td class="py-3.5 pr-4 text-zinc-300">
                                    <?= (int) $svc['duration_minutes'] ?> min
                                </td>
                                <td class="py-3.5 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            type="button"
                                            onclick="openEditModal(<?= (int) $svc['id'] ?>, <?= htmlspecialchars(json_encode($svc['service_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($svc['base_price']), ENT_QUOTES, 'UTF-8') ?>, <?= (int) $svc['duration_minutes'] ?>)"
                                            class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs font-medium text-zinc-300 transition-colors hover:border-cyan-500/50 hover:text-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/40"
                                        >
                                            Edit
                                        </button>
                                        <form method="POST" action="service-settings.php" onsubmit="return confirm('Delete this service?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $svc['id'] ?>">
                                            <button
                                                type="submit"
                                                class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs font-medium text-zinc-400 transition-colors hover:border-red-500/50 hover:text-red-400 focus:outline-none focus:ring-2 focus:ring-red-500/40"
                                            >
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </section>

        </div>
    </main>

    <!-- Add Service Modal -->
    <div id="add-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
            <h3 class="text-lg font-semibold text-white mb-5">Add New Service</h3>
            <form method="POST" action="service-settings.php">
                <input type="hidden" name="action" value="add">
                <div class="space-y-4">
                    <div>
                        <label for="add-service-name" class="block text-xs font-medium text-zinc-400 mb-1.5">Service Name</label>
                        <input
                            type="text"
                            id="add-service-name"
                            name="service_name"
                            required
                            maxlength="255"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                            placeholder="e.g. Maintenance &amp; Alignment"
                        >
                    </div>
                    <div>
                        <label for="add-base-price" class="block text-xs font-medium text-zinc-400 mb-1.5">Base Price ($)</label>
                        <input
                            type="number"
                            id="add-base-price"
                            name="base_price"
                            required
                            min="0"
                            step="0.01"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                            placeholder="0.00"
                        >
                    </div>
                    <div>
                        <label for="add-duration-minutes" class="block text-xs font-medium text-zinc-400 mb-1.5">Duration (minutes)</label>
                        <input
                            type="number"
                            id="add-duration-minutes"
                            name="duration_minutes"
                            required
                            min="1"
                            step="1"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                            placeholder="e.g. 90"
                        >
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        onclick="closeAddModal()"
                        class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-300 transition-colors hover:border-zinc-600 hover:text-white focus:outline-none"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/50"
                    >
                        Add Service
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Service Modal -->
    <div id="edit-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
            <h3 class="text-lg font-semibold text-white mb-5">Edit Service</h3>
            <form method="POST" action="service-settings.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit-id">
                <div class="space-y-4">
                    <div>
                        <label for="edit-service-name" class="block text-xs font-medium text-zinc-400 mb-1.5">Service Name</label>
                        <input
                            type="text"
                            id="edit-service-name"
                            name="service_name"
                            required
                            maxlength="255"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                        >
                    </div>
                    <div>
                        <label for="edit-base-price" class="block text-xs font-medium text-zinc-400 mb-1.5">Base Price ($)</label>
                        <input
                            type="number"
                            id="edit-base-price"
                            name="base_price"
                            required
                            min="0"
                            step="0.01"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                        >
                    </div>
                    <div>
                        <label for="edit-duration-minutes" class="block text-xs font-medium text-zinc-400 mb-1.5">Duration (minutes)</label>
                        <input
                            type="number"
                            id="edit-duration-minutes"
                            name="duration_minutes"
                            required
                            min="1"
                            step="1"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                        >
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        onclick="closeEditModal()"
                        class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-300 transition-colors hover:border-zinc-600 hover:text-white focus:outline-none"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/50"
                    >
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const addModal  = document.getElementById('add-modal');
        const editModal = document.getElementById('edit-modal');

        function openAddModal() {
            addModal.classList.remove('hidden');
            document.getElementById('add-service-name').focus();
        }
        function closeAddModal() {
            addModal.classList.add('hidden');
        }
        function openEditModal(id, name, price, duration) {
            document.getElementById('edit-id').value               = id;
            document.getElementById('edit-service-name').value     = name;
            document.getElementById('edit-base-price').value       = parseFloat(price).toFixed(2);
            document.getElementById('edit-duration-minutes').value = parseInt(duration, 10);
            editModal.classList.remove('hidden');
            document.getElementById('edit-service-name').focus();
        }
        function closeEditModal() {
            editModal.classList.add('hidden');
        }

        addModal.addEventListener('click', (e) => { if (e.target === addModal)  closeAddModal();  });
        editModal.addEventListener('click', (e) => { if (e.target === editModal) closeEditModal(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { closeAddModal(); closeEditModal(); }
        });
    </script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
