<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';

function ensureNotificationsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
}

function getNotifications(PDO $pdo): array
{
    return $pdo->query("SELECT id, title, body FROM notifications ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

ensureNotificationsTable($pdo);

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($title === '') {
            $errorMessage = 'Notification title is required.';
        } elseif ($body === '') {
            $errorMessage = 'Notification body is required.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO notifications (title, body) VALUES (:title, :body)");
            $stmt->execute([
                ':title' => $title,
                ':body' => $body,
            ]);
            $successMessage = 'Notification added successfully.';
        }
    } elseif ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($id <= 0) {
            $errorMessage = 'Invalid notification ID.';
        } elseif ($title === '') {
            $errorMessage = 'Notification title is required.';
        } elseif ($body === '') {
            $errorMessage = 'Notification body is required.';
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET title = :title, body = :body WHERE id = :id");
            $stmt->execute([
                ':title' => $title,
                ':body' => $body,
                ':id' => $id,
            ]);
            $successMessage = 'Notification updated successfully.';
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            $errorMessage = 'Invalid notification ID.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $successMessage = 'Notification deleted.';
        }
    }

    if ($successMessage !== null) {
        header('Location: notifications.php?msg=' . urlencode($successMessage));
        exit;
    }
}

if (isset($_GET['msg'])) {
    $successMessage = htmlspecialchars(trim((string) $_GET['msg']), ENT_QUOTES, 'UTF-8');
}

$notifications = getNotifications($pdo);
?>
<?php
$pageTitle = 'Notifications | Ghost Laser';
$pageDescription = 'Ghost Laser notification template management.';
$extraHead = <<<'HTML'
    <style>
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
        .modal-overlay { background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); }
    </style>
HTML;
$headerRight = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                    <a href="settings.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Schedule Settings</a>
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
                <h1 class="mt-4 text-3xl font-bold tracking-tight">Notifications</h1>
                <p class="mt-2 text-zinc-400">
                    Manage reusable notification templates. Click any title below to expand the full message body.
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

            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-6">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-white">Notification Templates</h2>
                        <p class="mt-2 text-sm text-zinc-400">
                            Available placeholders: <span class="text-cyan-300">{client_name}</span>, <span class="text-cyan-300">{service_name}</span>, <span class="text-cyan-300">{client_address}</span>, <span class="text-cyan-300">{appointment_date}</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        onclick="openAddModal()"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/50"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add New
                    </button>
                </div>

                <?php if (empty($notifications)): ?>
                <p class="text-sm text-zinc-500">No notifications found. Add one to start building personalized templates.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-800">
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Title</th>
                                <th class="pb-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60">
                            <?php foreach ($notifications as $notification): ?>
                            <tr class="group">
                                <td class="py-3.5 pr-4">
                                    <button
                                        type="button"
                                        class="notification-toggle flex w-full items-center gap-3 text-left font-medium text-white transition-colors hover:text-cyan-300"
                                        data-target="notification-body-<?= (int) $notification['id'] ?>"
                                        aria-expanded="false"
                                    >
                                        <svg class="h-4 w-4 flex-shrink-0 text-cyan-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                        <span><?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </button>
                                </td>
                                <td class="py-3.5 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            type="button"
                                            onclick="openEditModal(<?= (int) $notification['id'] ?>, <?= htmlspecialchars(json_encode($notification['title']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($notification['body']), ENT_QUOTES, 'UTF-8') ?>)"
                                            class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs font-medium text-zinc-300 transition-colors hover:border-cyan-500/50 hover:text-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/40"
                                        >
                                            Edit
                                        </button>
                                        <form method="POST" action="notifications.php" onsubmit="return confirm('Delete this notification?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
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
                            <tr id="notification-body-<?= (int) $notification['id'] ?>" class="hidden bg-zinc-950/40">
                                <td colspan="2" class="px-4 pb-4 pt-1">
                                    <div class="rounded-xl border border-zinc-800 bg-zinc-950/60 p-4 text-sm leading-7 text-zinc-300 whitespace-pre-line">
                                        <?= htmlspecialchars($notification['body'], ENT_QUOTES, 'UTF-8') ?>
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

    <div id="add-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <div class="w-full max-w-2xl rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
            <h3 class="mb-5 text-lg font-semibold text-white">Add New Notification</h3>
            <form method="POST" action="notifications.php">
                <input type="hidden" name="action" value="add">
                <div class="space-y-4">
                    <div>
                        <label for="add-title" class="mb-1.5 block text-xs font-medium text-zinc-400">Title</label>
                        <input
                            type="text"
                            id="add-title"
                            name="title"
                            required
                            maxlength="255"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                            placeholder="e.g. Appointment Reminder"
                        >
                    </div>
                    <div>
                        <label for="add-body" class="mb-1.5 block text-xs font-medium text-zinc-400">Message Body</label>
                        <textarea
                            id="add-body"
                            name="body"
                            rows="8"
                            required
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                            placeholder="Hello {client_name}, your {service_name} appointment at {client_address} is scheduled for {appointment_date}."
                        ></textarea>
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
                        Add New
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="edit-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <div class="w-full max-w-2xl rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
            <h3 class="mb-5 text-lg font-semibold text-white">Edit Notification</h3>
            <form method="POST" action="notifications.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit-id">
                <div class="space-y-4">
                    <div>
                        <label for="edit-title" class="mb-1.5 block text-xs font-medium text-zinc-400">Title</label>
                        <input
                            type="text"
                            id="edit-title"
                            name="title"
                            required
                            maxlength="255"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                        >
                    </div>
                    <div>
                        <label for="edit-body" class="mb-1.5 block text-xs font-medium text-zinc-400">Message Body</label>
                        <textarea
                            id="edit-body"
                            name="body"
                            rows="8"
                            required
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                        ></textarea>
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
        const addModal = document.getElementById('add-modal');
        const editModal = document.getElementById('edit-modal');

        function openAddModal() {
            addModal.classList.remove('hidden');
            document.getElementById('add-title').focus();
        }

        function closeAddModal() {
            addModal.classList.add('hidden');
        }

        function openEditModal(id, title, body) {
            document.getElementById('edit-id').value = id;
            document.getElementById('edit-title').value = title;
            document.getElementById('edit-body').value = body;
            editModal.classList.remove('hidden');
            document.getElementById('edit-title').focus();
        }

        function closeEditModal() {
            editModal.classList.add('hidden');
        }

        addModal.addEventListener('click', (event) => {
            if (event.target === addModal) {
                closeAddModal();
            }
        });

        editModal.addEventListener('click', (event) => {
            if (event.target === editModal) {
                closeEditModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAddModal();
                closeEditModal();
            }
        });

        document.querySelectorAll('.notification-toggle').forEach((button) => {
            button.addEventListener('click', () => {
                const bodyRow = document.getElementById(button.dataset.target);
                const icon = button.querySelector('svg');
                const isExpanded = button.getAttribute('aria-expanded') === 'true';

                button.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                bodyRow.classList.toggle('hidden', isExpanded);
                icon.classList.toggle('rotate-90', !isExpanded);
            });
        });
    </script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
