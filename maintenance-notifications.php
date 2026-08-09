<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';

// ── Shared helpers (duplicated from notifications.php for standalone page) ──

function mnGetTagDefinitions(): array
{
    return [
        '{customer_name}'   => 'Customer full name (first + last).',
        '{last_service_date}' => 'Date the customer was last serviced.',
        '{next_service_date}' => 'Date the next service is due.',
        '{admin_name}'      => 'Name of the logged-in admin sending this message.',
        '{company_name}'    => 'Your company name.',
        '{company_phone}'   => 'Your company phone number.',
        '{company_website}' => 'Your company website URL.',
    ];
}

function mnSupportedTags(): array
{
    return array_keys(mnGetTagDefinitions());
}

function mnUnsupportedTags(string $body): array
{
    preg_match_all('/\{[a-z0-9_]+\}/i', $body, $matches);
    $found = array_values(array_unique($matches[0] ?? []));
    return array_values(array_diff($found, mnSupportedTags()));
}

function mnEnsureTable(PDO $pdo): void
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

function mnGetAll(PDO $pdo): array
{
    return $pdo->query("SELECT id, title, body FROM notifications ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Bootstrap ──

mnEnsureTable($pdo);

$adminName = trim((string) ($_SESSION['admin_username'] ?? 'Admin'));
$successMessage = null;
$errorMessage   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body  = trim((string) ($_POST['body']  ?? ''));
        $bad   = mnUnsupportedTags($body);

        if ($title === '') {
            $errorMessage = 'Title is required.';
        } elseif ($body === '') {
            $errorMessage = 'Message body is required.';
        } elseif ($bad !== []) {
            $errorMessage = 'Unsupported tags: ' . implode(', ', $bad);
        } else {
            $stmt = $pdo->prepare("INSERT INTO notifications (title, body) VALUES (:t, :b)");
            $stmt->execute([':t' => $title, ':b' => $body]);
            $successMessage = 'Notification button added.';
        }
    } elseif ($action === 'edit') {
        $id    = (int) ($_POST['id']    ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $body  = trim((string) ($_POST['body']  ?? ''));
        $bad   = mnUnsupportedTags($body);

        if ($id <= 0) {
            $errorMessage = 'Invalid ID.';
        } elseif ($title === '') {
            $errorMessage = 'Title is required.';
        } elseif ($body === '') {
            $errorMessage = 'Message body is required.';
        } elseif ($bad !== []) {
            $errorMessage = 'Unsupported tags: ' . implode(', ', $bad);
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET title = :t, body = :b WHERE id = :id");
            $stmt->execute([':t' => $title, ':b' => $body, ':id' => $id]);
            $successMessage = 'Notification button updated.';
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errorMessage = 'Invalid ID.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $successMessage = 'Notification button deleted.';
        }
    }

    if ($successMessage !== null) {
        header('Location: maintenance-notifications.php?msg=' . urlencode($successMessage));
        exit;
    }
}

if (isset($_GET['msg'])) {
    $successMessage = htmlspecialchars(trim((string) $_GET['msg']), ENT_QUOTES, 'UTF-8');
}

$notifications = mnGetAll($pdo);
$tagDefs       = mnGetTagDefinitions();
?>
<?php
$pageTitle   = 'Maintenance Notifications | Ghost Laser';
$pageDescription = 'Manage maintenance notification button templates.';
$extraHead   = <<<'HTML'
    <style>
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
        .modal-overlay { background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); }
    </style>
HTML;
$headerRight = <<<'HTML'
    <div class="flex items-center gap-3">
        <a href="recurring-services.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Maintenance Schedule</a>
        <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Dashboard</a>
    </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen hero-grid pt-24 pb-16 px-4">
        <div class="max-w-5xl mx-auto space-y-8">

            <!-- Page header -->
            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/20 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-[0.2em] text-cyan-400">
                    Admin Settings
                </div>
                <h1 class="mt-4 text-3xl font-bold tracking-tight">Maintenance Notifications</h1>
                <p class="mt-2 text-zinc-400">
                    Create and manage notification buttons that appear in the <strong class="text-white">Notify</strong> modal
                    on the Maintenance Schedule page. Each button has a title and a message body that supports merge tags.
                </p>
            </section>

            <!-- Flash messages -->
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

            <!-- Merge tags reference -->
            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-4">
                <div>
                    <h2 class="text-xl font-semibold text-white">Available Merge Tags</h2>
                    <p class="mt-2 text-sm text-zinc-400">
                        Insert these tags into message bodies and they will be replaced with real customer data when the Notify modal is opened.
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-800">
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Tag</th>
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60">
                            <?php foreach ($tagDefs as $tag => $desc): ?>
                            <tr>
                                <td class="py-3 pr-6">
                                    <button
                                        type="button"
                                        class="tag-chip font-mono text-xs font-semibold text-cyan-300 bg-cyan-500/10 border border-cyan-500/20 rounded px-2 py-0.5 hover:bg-cyan-500/20 transition-colors cursor-copy"
                                        title="Click to copy"
                                        onclick="copyTag(this, <?= htmlspecialchars(json_encode($tag), ENT_QUOTES, 'UTF-8') ?>)"
                                    ><?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') ?></button>
                                </td>
                                <td class="py-3 text-zinc-400"><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-zinc-500">Click any tag to copy it to your clipboard.</p>
            </section>

            <!-- Notification buttons list -->
            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-6">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-white">Notification Buttons</h2>
                        <p class="mt-2 text-sm text-zinc-400">
                            Each entry below appears as a clickable button inside the Notify modal on the Maintenance Schedule page.
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
                        Add Button
                    </button>
                </div>

                <?php if (empty($notifications)): ?>
                <p class="text-sm text-zinc-500">No notification buttons yet. Add one to get started.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-800">
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">ID</th>
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Title</th>
                                <th class="pb-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60">
                            <?php foreach ($notifications as $n): ?>
                            <tr class="group">
                                <td class="py-3.5 pr-4 align-top font-mono text-xs text-zinc-400"><?= (int) $n['id'] ?></td>
                                <td class="py-3.5 pr-4">
                                    <button
                                        type="button"
                                        class="mn-toggle flex w-full items-center gap-3 text-left font-medium text-white transition-colors hover:text-cyan-300"
                                        data-target="mn-body-<?= (int) $n['id'] ?>"
                                        aria-expanded="false"
                                    >
                                        <svg class="h-4 w-4 flex-shrink-0 text-cyan-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                        <span><?= htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </button>
                                </td>
                                <td class="py-3.5 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            type="button"
                                            onclick="openEditModal(<?= (int) $n['id'] ?>, <?= htmlspecialchars(json_encode($n['title']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($n['body']), ENT_QUOTES, 'UTF-8') ?>)"
                                            class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs font-medium text-zinc-300 transition-colors hover:border-cyan-500/50 hover:text-cyan-400 focus:outline-none"
                                        >Edit</button>
                                        <form method="POST" action="maintenance-notifications.php" onsubmit="return confirm('Delete this notification button?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                            <button type="submit" class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs font-medium text-zinc-400 transition-colors hover:border-red-500/50 hover:text-red-400 focus:outline-none">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr id="mn-body-<?= (int) $n['id'] ?>" class="hidden bg-zinc-950/40">
                                <td colspan="3" class="px-4 pb-4 pt-1">
                                    <div class="rounded-xl border border-zinc-800 bg-zinc-950/60 p-4 text-sm leading-7 text-zinc-300 whitespace-pre-line">
                                        <?= htmlspecialchars($n['body'], ENT_QUOTES, 'UTF-8') ?>
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

    <!-- Add Modal -->
    <div id="add-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <div class="w-full max-w-2xl rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
            <h3 class="mb-5 text-lg font-semibold text-white">Add Notification Button</h3>
            <form method="POST" action="maintenance-notifications.php">
                <input type="hidden" name="action" value="add">
                <div class="space-y-4">
                    <div>
                        <label for="add-title" class="mb-1.5 block text-xs font-medium text-zinc-400">Button Title</label>
                        <input
                            type="text"
                            id="add-title"
                            name="title"
                            required
                            maxlength="255"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                            placeholder="e.g. Maintenance Reminder"
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
                            placeholder="Hi {customer_name}, your last service was on {last_service_date} and your next service is due on {next_service_date}. Please contact us to schedule. – {admin_name}"
                        ></textarea>
                        <p class="mt-1.5 text-xs text-zinc-500">Supported tags: <?= implode(', ', array_map(fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8'), array_keys($tagDefs))) ?></p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" onclick="closeAddModal()" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-300 transition-colors hover:border-zinc-600 hover:text-white focus:outline-none">Cancel</button>
                    <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 focus:outline-none">Add Button</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="edit-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <div class="w-full max-w-2xl rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
            <h3 class="mb-5 text-lg font-semibold text-white">Edit Notification Button</h3>
            <form method="POST" action="maintenance-notifications.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit-id">
                <div class="space-y-4">
                    <div>
                        <label for="edit-title" class="mb-1.5 block text-xs font-medium text-zinc-400">Button Title</label>
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
                        <p class="mt-1.5 text-xs text-zinc-500">Supported tags: <?= implode(', ', array_map(fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8'), array_keys($tagDefs))) ?></p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" onclick="closeEditModal()" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-300 transition-colors hover:border-zinc-600 hover:text-white focus:outline-none">Cancel</button>
                    <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 focus:outline-none">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const addModal  = document.getElementById('add-modal');
        const editModal = document.getElementById('edit-modal');

        function openAddModal() {
            addModal.classList.remove('hidden');
            document.getElementById('add-title').focus();
        }
        function closeAddModal() { addModal.classList.add('hidden'); }

        function openEditModal(id, title, body) {
            document.getElementById('edit-id').value    = id;
            document.getElementById('edit-title').value = title;
            document.getElementById('edit-body').value  = body;
            editModal.classList.remove('hidden');
            document.getElementById('edit-title').focus();
        }
        function closeEditModal() { editModal.classList.add('hidden'); }

        addModal.addEventListener('click', e => { if (e.target === addModal) closeAddModal(); });
        editModal.addEventListener('click', e => { if (e.target === editModal) closeEditModal(); });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') { closeAddModal(); closeEditModal(); }
        });

        document.querySelectorAll('.mn-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const row  = document.getElementById(btn.dataset.target);
                const icon = btn.querySelector('svg');
                const open = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', open ? 'false' : 'true');
                row.classList.toggle('hidden', open);
                icon.classList.toggle('rotate-90', !open);
            });
        });

        function copyTag(el, tag) {
            navigator.clipboard.writeText(tag).then(() => {
                const orig = el.textContent;
                el.textContent = 'Copied!';
                setTimeout(() => { el.textContent = orig; }, 1200);
            }).catch(() => {});
        }
    </script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
