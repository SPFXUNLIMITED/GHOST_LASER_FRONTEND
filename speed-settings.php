<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';

// --- Database helpers ---

function ensureServiceSpeedsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS service_speeds (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            speed_key VARCHAR(50) NOT NULL UNIQUE,
            display_name VARCHAR(100) NOT NULL,
            price_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
}

function seedServiceSpeedsIfEmpty(PDO $pdo): void
{
    $count = (int) $pdo->query("SELECT COUNT(*) FROM service_speeds")->fetchColumn();
    if ($count > 0) {
        return;
    }
    $defaults = [
        ['standard',  'Standard',  1.00, 1],
        ['rush',      'VIP',       1.35, 2],
        ['emergency', 'Emergency', 1.75, 3],
    ];
    $stmt = $pdo->prepare("INSERT INTO service_speeds (speed_key, display_name, price_multiplier, sort_order) VALUES (:key, :name, :multiplier, :sort)");
    foreach ($defaults as [$key, $name, $multiplier, $sort]) {
        $stmt->execute([':key' => $key, ':name' => $name, ':multiplier' => $multiplier, ':sort' => $sort]);
    }
}

function getServiceSpeeds(PDO $pdo): array
{
    return $pdo->query("SELECT id, speed_key, display_name, price_multiplier, sort_order FROM service_speeds ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

ensureServiceSpeedsTable($pdo);
seedServiceSpeedsIfEmpty($pdo);

// --- Handle POST actions ---

$successMessage = null;
$errorMessage   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add') {
        $key        = trim((string) ($_POST['speed_key'] ?? ''));
        $name       = trim((string) ($_POST['display_name'] ?? ''));
        $multiplier = trim((string) ($_POST['price_multiplier'] ?? ''));
        $sort       = trim((string) ($_POST['sort_order'] ?? '0'));

        if ($key === '' || !preg_match('/^[a-z0-9_]+$/i', $key)) {
            $errorMessage = 'Speed key is required and may only contain letters, numbers, and underscores.';
        } elseif ($name === '') {
            $errorMessage = 'Display name is required.';
        } elseif (!is_numeric($multiplier) || (float) $multiplier <= 0) {
            $errorMessage = 'Price multiplier must be a positive number.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO service_speeds (speed_key, display_name, price_multiplier, sort_order) VALUES (:key, :name, :multiplier, :sort)");
                $stmt->execute([':key' => $key, ':name' => $name, ':multiplier' => round((float) $multiplier, 2), ':sort' => (int) $sort]);
                $successMessage = 'Speed option added successfully.';
            } catch (PDOException $e) {
                $errorMessage = 'Speed key already exists. Please choose a unique key.';
            }
        }

    } elseif ($action === 'edit') {
        $id         = (int) ($_POST['id'] ?? 0);
        $key        = trim((string) ($_POST['speed_key'] ?? ''));
        $name       = trim((string) ($_POST['display_name'] ?? ''));
        $multiplier = trim((string) ($_POST['price_multiplier'] ?? ''));
        $sort       = trim((string) ($_POST['sort_order'] ?? '0'));

        if ($id <= 0) {
            $errorMessage = 'Invalid speed option ID.';
        } elseif ($key === '' || !preg_match('/^[a-z0-9_]+$/i', $key)) {
            $errorMessage = 'Speed key is required and may only contain letters, numbers, and underscores.';
        } elseif ($name === '') {
            $errorMessage = 'Display name is required.';
        } elseif (!is_numeric($multiplier) || (float) $multiplier <= 0) {
            $errorMessage = 'Price multiplier must be a positive number.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE service_speeds SET speed_key = :key, display_name = :name, price_multiplier = :multiplier, sort_order = :sort WHERE id = :id");
                $stmt->execute([':key' => $key, ':name' => $name, ':multiplier' => round((float) $multiplier, 2), ':sort' => (int) $sort, ':id' => $id]);
                $successMessage = 'Speed option updated successfully.';
            } catch (PDOException $e) {
                $errorMessage = 'Speed key already exists. Please choose a unique key.';
            }
        }

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            $errorMessage = 'Invalid speed option ID.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM service_speeds WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $successMessage = 'Speed option deleted.';
        }
    }

    if ($successMessage !== null) {
        header('Location: speed-settings.php?msg=' . urlencode($successMessage));
        exit;
    }
}

if (isset($_GET['msg'])) {
    $successMessage = htmlspecialchars(trim((string) $_GET['msg']), ENT_QUOTES, 'UTF-8');
}

$speeds = getServiceSpeeds($pdo);
?>
<?php
$pageTitle       = 'Speed Settings | Ghost Laser';
$pageDescription = 'Ghost Laser service speed settings management.';
$extraHead       = <<<'HTML'
    <style>
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
        .modal-overlay { background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); }
    </style>
HTML;
$headerRight     = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                    <a href="service-settings.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Service Settings</a>
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
                <h1 class="mt-4 text-3xl font-bold tracking-tight">Speed Settings</h1>
                <p class="mt-2 text-zinc-400">
                    Manage service speed options, display names, price multipliers, and display order.
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

            <!-- Speeds table -->
            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-semibold text-white">Service Speeds</h2>
                    <button
                        type="button"
                        onclick="openAddModal()"
                        class="inline-flex items-center gap-2 rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/50"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add Speed Option
                    </button>
                </div>

                <?php if (empty($speeds)): ?>
                <p class="text-zinc-500 text-sm">No speed options found. Add one above.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-800">
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Speed Key</th>
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Display Name</th>
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Price Multiplier</th>
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Sort Order</th>
                                <th class="pb-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60">
                            <?php foreach ($speeds as $spd): ?>
                            <tr class="group">
                                <td class="py-3.5 pr-4 font-mono text-cyan-300 text-xs">
                                    <?= htmlspecialchars($spd['speed_key'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="py-3.5 pr-4 font-medium text-white">
                                    <?= htmlspecialchars($spd['display_name'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="py-3.5 pr-4 text-zinc-300">
                                    &times;<?= number_format((float) $spd['price_multiplier'], 2) ?>
                                </td>
                                <td class="py-3.5 pr-4 text-zinc-400">
                                    <?= (int) $spd['sort_order'] ?>
                                </td>
                                <td class="py-3.5 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            type="button"
                                            onclick="openEditModal(<?= (int) $spd['id'] ?>, <?= htmlspecialchars(json_encode($spd['speed_key']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($spd['display_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($spd['price_multiplier']), ENT_QUOTES, 'UTF-8') ?>, <?= (int) $spd['sort_order'] ?>)"
                                            class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs font-medium text-zinc-300 transition-colors hover:border-cyan-500/50 hover:text-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/40"
                                        >
                                            Edit
                                        </button>
                                        <form method="POST" action="speed-settings.php" onsubmit="return confirm('Delete this speed option?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $spd['id'] ?>">
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

    <!-- Add Speed Modal -->
    <div id="add-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
            <h3 class="text-lg font-semibold text-white mb-5">Add Speed Option</h3>
            <form method="POST" action="speed-settings.php">
                <input type="hidden" name="action" value="add">
                <div class="space-y-4">
                    <div>
                        <label for="add-speed-key" class="block text-xs font-medium text-zinc-400 mb-1.5">Speed Key</label>
                        <input
                            type="text"
                            id="add-speed-key"
                            name="speed_key"
                            required
                            maxlength="50"
                            pattern="[a-zA-Z0-9_]+"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                            placeholder="e.g. standard, rush, emergency"
                        >
                        <p class="mt-1 text-xs text-zinc-500">Letters, numbers, and underscores only. Must be unique.</p>
                    </div>
                    <div>
                        <label for="add-display-name" class="block text-xs font-medium text-zinc-400 mb-1.5">Display Name</label>
                        <input
                            type="text"
                            id="add-display-name"
                            name="display_name"
                            required
                            maxlength="100"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                            placeholder="e.g. Standard, VIP, Emergency"
                        >
                    </div>
                    <div>
                        <label for="add-price-multiplier" class="block text-xs font-medium text-zinc-400 mb-1.5">Price Multiplier</label>
                        <input
                            type="number"
                            id="add-price-multiplier"
                            name="price_multiplier"
                            required
                            min="0.01"
                            step="0.01"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                            placeholder="e.g. 1.00, 1.35, 1.75"
                        >
                        <p class="mt-1 text-xs text-zinc-500">Base price is multiplied by this value (1.00 = no change).</p>
                    </div>
                    <div>
                        <label for="add-sort-order" class="block text-xs font-medium text-zinc-400 mb-1.5">Sort Order</label>
                        <input
                            type="number"
                            id="add-sort-order"
                            name="sort_order"
                            required
                            min="0"
                            step="1"
                            value="0"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                        >
                        <p class="mt-1 text-xs text-zinc-500">Lower numbers appear first in the booking form.</p>
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
                        Add Speed Option
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Speed Modal -->
    <div id="edit-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
            <h3 class="text-lg font-semibold text-white mb-5">Edit Speed Option</h3>
            <form method="POST" action="speed-settings.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit-id">
                <div class="space-y-4">
                    <div>
                        <label for="edit-speed-key" class="block text-xs font-medium text-zinc-400 mb-1.5">Speed Key</label>
                        <input
                            type="text"
                            id="edit-speed-key"
                            name="speed_key"
                            required
                            maxlength="50"
                            pattern="[a-zA-Z0-9_]+"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                        >
                        <p class="mt-1 text-xs text-zinc-500">Letters, numbers, and underscores only. Must be unique.</p>
                    </div>
                    <div>
                        <label for="edit-display-name" class="block text-xs font-medium text-zinc-400 mb-1.5">Display Name</label>
                        <input
                            type="text"
                            id="edit-display-name"
                            name="display_name"
                            required
                            maxlength="100"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                        >
                    </div>
                    <div>
                        <label for="edit-price-multiplier" class="block text-xs font-medium text-zinc-400 mb-1.5">Price Multiplier</label>
                        <input
                            type="number"
                            id="edit-price-multiplier"
                            name="price_multiplier"
                            required
                            min="0.01"
                            step="0.01"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                        >
                    </div>
                    <div>
                        <label for="edit-sort-order" class="block text-xs font-medium text-zinc-400 mb-1.5">Sort Order</label>
                        <input
                            type="number"
                            id="edit-sort-order"
                            name="sort_order"
                            required
                            min="0"
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
            document.getElementById('add-speed-key').focus();
        }
        function closeAddModal() {
            addModal.classList.add('hidden');
        }
        function openEditModal(id, key, name, multiplier, sort) {
            document.getElementById('edit-id').value              = id;
            document.getElementById('edit-speed-key').value       = key;
            document.getElementById('edit-display-name').value    = name;
            document.getElementById('edit-price-multiplier').value = parseFloat(multiplier).toFixed(2);
            document.getElementById('edit-sort-order').value      = sort;
            editModal.classList.remove('hidden');
            document.getElementById('edit-speed-key').focus();
        }
        function closeEditModal() {
            editModal.classList.add('hidden');
        }

        addModal.addEventListener('click',  (e) => { if (e.target === addModal)  closeAddModal();  });
        editModal.addEventListener('click', (e) => { if (e.target === editModal) closeEditModal(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { closeAddModal(); closeEditModal(); }
        });
    </script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
