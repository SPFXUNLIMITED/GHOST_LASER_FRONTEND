<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/prospects_schema.php';

prospectsEnsureSchema($pdo);

if (empty($_SESSION['prospect_notifications_csrf'])) {
    $_SESSION['prospect_notifications_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['prospect_notifications_csrf'];

function pnTagDefinitions(): array
{
    return [
        '{company}' => 'Prospect company name.',
        '{contact_name}' => 'Prospect contact name.',
        '{phone}' => 'Prospect phone number.',
        '{email}' => 'Prospect email address.',
        '{website}' => 'Prospect website URL.',
        '{status}' => 'Current prospect status.',
        '{last_called}' => 'Most recent call datetime.',
        '{last_emailed}' => 'Most recent email datetime.',
        '{admin_name}' => 'Current logged-in admin name.',
    ];
}

function pnUnsupportedTags(string $body): array
{
    preg_match_all('/\{[a-z0-9_]+\}/i', $body, $matches);
    $found = array_values(array_unique($matches[0] ?? []));
    return array_values(array_diff($found, array_keys(pnTagDefinitions())));
}

function pnGetAll(PDO $pdo): array
{
    return $pdo->query("SELECT id, title, subject, body FROM prospect_notification_templates ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = trim((string) ($_POST['csrf'] ?? ''));
    if ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
        $errorMessage = 'Invalid security token.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'add') {
            $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 255);
            $subject = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 255);
            $body = trim((string) ($_POST['body'] ?? ''));
            $bad = pnUnsupportedTags($body);
            if ($title === '') {
                $errorMessage = 'Title is required.';
            } elseif ($body === '') {
                $errorMessage = 'Body is required.';
            } elseif ($bad !== []) {
                $errorMessage = 'Unsupported tags: ' . implode(', ', $bad);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO prospect_notification_templates (title, subject, body)
                    VALUES (:title, :subject, :body)
                ");
                $stmt->execute([
                    ':title' => $title,
                    ':subject' => $subject,
                    ':body' => $body,
                ]);
                $successMessage = 'Template added.';
            }
        } elseif ($action === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 255);
            $subject = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 255);
            $body = trim((string) ($_POST['body'] ?? ''));
            $bad = pnUnsupportedTags($body);
            if ($id <= 0) {
                $errorMessage = 'Invalid template ID.';
            } elseif ($title === '') {
                $errorMessage = 'Title is required.';
            } elseif ($body === '') {
                $errorMessage = 'Body is required.';
            } elseif ($bad !== []) {
                $errorMessage = 'Unsupported tags: ' . implode(', ', $bad);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE prospect_notification_templates
                    SET title = :title, subject = :subject, body = :body
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':title' => $title,
                    ':subject' => $subject,
                    ':body' => $body,
                    ':id' => $id,
                ]);
                $successMessage = 'Template updated.';
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errorMessage = 'Invalid template ID.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM prospect_notification_templates WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $successMessage = 'Template deleted.';
            }
        }
    }

    if ($successMessage !== null) {
        header('Location: prospect_notifications.php?msg=' . urlencode($successMessage));
        exit;
    }
}

if (isset($_GET['msg'])) {
    $successMessage = htmlspecialchars(trim((string) $_GET['msg']), ENT_QUOTES, 'UTF-8');
}

$templates = pnGetAll($pdo);
$tagDefs = pnTagDefinitions();

$pageTitle = 'Prospect Notifications | Ghost Laser';
$pageDescription = 'Manage prospect-specific notification templates.';
$headerRight = '<div class="flex items-center gap-3"><a href="prospects.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Prospects</a><a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Dashboard</a></div>';
$extraHead = <<<'HTML'
<style>
    .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
    .modal-overlay { background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); }
</style>
HTML;
require_once __DIR__ . '/templates/header.php';
?>
<main class="min-h-screen hero-grid pt-24 pb-16 px-4">
    <div class="max-w-5xl mx-auto space-y-8">
        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
            <h1 class="text-3xl font-bold tracking-tight">Prospect Notifications</h1>
            <p class="mt-2 text-zinc-400">Manage isolated email templates for prospects only.</p>
        </section>

        <?php if ($successMessage !== null): ?>
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-400"><?= $successMessage ?></div>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-4">
            <h2 class="text-xl font-semibold text-white">Supported Merge Tags</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-zinc-800"><th class="pb-3 text-left text-zinc-500">Tag</th><th class="pb-3 text-left text-zinc-500">Description</th></tr></thead>
                    <tbody class="divide-y divide-zinc-800/60">
                    <?php foreach ($tagDefs as $tag => $desc): ?>
                        <tr>
                            <td class="py-3 pr-6 text-cyan-300 font-mono"><?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="py-3 text-zinc-400"><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-white">Templates</h2>
                <button type="button" onclick="openAddModal()" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400">Add Template</button>
            </div>

            <?php if ($templates === []): ?>
                <p class="text-sm text-zinc-500">No templates yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="border-b border-zinc-800"><th class="pb-3 text-left text-zinc-500">ID</th><th class="pb-3 text-left text-zinc-500">Title</th><th class="pb-3 text-left text-zinc-500">Subject</th><th class="pb-3 text-right text-zinc-500">Actions</th></tr></thead>
                        <tbody class="divide-y divide-zinc-800/60">
                        <?php foreach ($templates as $template): ?>
                            <tr>
                                <td class="py-3 pr-3 text-zinc-400"><?= (int) $template['id'] ?></td>
                                <td class="py-3 pr-3 text-white"><?= htmlspecialchars($template['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 pr-3 text-zinc-300"><?= htmlspecialchars($template['subject'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button type="button" onclick="openEditModal(<?= (int) $template['id'] ?>, <?= htmlspecialchars(json_encode($template['title']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($template['subject']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($template['body']), ENT_QUOTES, 'UTF-8') ?>)" class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 hover:text-cyan-400">Edit</button>
                                        <form method="POST" action="prospect_notifications.php" onsubmit="return confirm('Delete this template?')">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $template['id'] ?>">
                                            <button type="submit" class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-400 hover:text-red-400">Delete</button>
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

<div id="add-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="w-full max-w-2xl rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
        <h3 class="mb-5 text-lg font-semibold text-white">Add Prospect Template</h3>
        <form method="POST" action="prospect_notifications.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="add">
            <div class="space-y-4">
                <input type="text" id="add-title" name="title" required maxlength="255" placeholder="Template title" class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white">
                <input type="text" name="subject" maxlength="255" placeholder="Email subject" class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white">
                <textarea name="body" rows="8" required placeholder="Email body with merge tags" class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white"></textarea>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeAddModal()" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300">Cancel</button>
                <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950">Add</button>
            </div>
        </form>
    </div>
</div>

<div id="edit-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="w-full max-w-2xl rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
        <h3 class="mb-5 text-lg font-semibold text-white">Edit Prospect Template</h3>
        <form method="POST" action="prospect_notifications.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit-id">
            <div class="space-y-4">
                <input type="text" id="edit-title" name="title" required maxlength="255" class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white">
                <input type="text" id="edit-subject" name="subject" maxlength="255" class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white">
                <textarea id="edit-body" name="body" rows="8" required class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white"></textarea>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeEditModal()" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300">Cancel</button>
                <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
const addModal = document.getElementById('add-modal');
const editModal = document.getElementById('edit-modal');
function openAddModal(){ addModal.classList.remove('hidden'); document.getElementById('add-title').focus(); }
function closeAddModal(){ addModal.classList.add('hidden'); }
function openEditModal(id, title, subject, body){
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-title').value = title;
    document.getElementById('edit-subject').value = subject;
    document.getElementById('edit-body').value = body;
    editModal.classList.remove('hidden');
}
function closeEditModal(){ editModal.classList.add('hidden'); }
addModal.addEventListener('click', (e) => { if (e.target === addModal) closeAddModal(); });
editModal.addEventListener('click', (e) => { if (e.target === editModal) closeEditModal(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeAddModal(); closeEditModal(); } });
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
