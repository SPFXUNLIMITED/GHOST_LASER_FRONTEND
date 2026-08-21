<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/functions.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['qualification_builder_csrf'])) {
    $_SESSION['qualification_builder_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['qualification_builder_csrf'];

// ─── Flash messages ─────────────────────────────────────────────────────────
$flashSuccess = (string) ($_SESSION['qb_success'] ?? '');
$flashError   = (string) ($_SESSION['qb_error'] ?? '');
unset($_SESSION['qb_success'], $_SESSION['qb_error']);

// ─── POST handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
        $_SESSION['qb_error'] = 'Invalid request token.';
        header('Location: qualification_builder.php');
        exit;
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        // ── Create questionnaire ──────────────────────────────────────────────
        if ($action === 'create_questionnaire') {
            $name        = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Questionnaire name is required.');
            }

            $stmt = $pdo->prepare('INSERT INTO qualification_questionnaires (name, description) VALUES (:name, :description)');
            $stmt->execute([':name' => $name, ':description' => $description]);
            $newId = (int) $pdo->lastInsertId();

            $_SESSION['qb_success'] = 'Questionnaire created.';
            header('Location: qualification_builder.php?questionnaire_id=' . $newId);
            exit;
        }

        // ── Update questionnaire ──────────────────────────────────────────────
        if ($action === 'update_questionnaire') {
            $qid         = (int) ($_POST['questionnaire_id'] ?? 0);
            $name        = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($qid <= 0) {
                throw new RuntimeException('Invalid questionnaire.');
            }
            if ($name === '') {
                throw new RuntimeException('Questionnaire name is required.');
            }

            $stmt = $pdo->prepare('UPDATE qualification_questionnaires SET name = :name, description = :description WHERE id = :id LIMIT 1');
            $stmt->execute([':name' => $name, ':description' => $description, ':id' => $qid]);

            $_SESSION['qb_success'] = 'Questionnaire updated.';
            header('Location: qualification_builder.php?questionnaire_id=' . $qid);
            exit;
        }

        // ── Delete questionnaire ──────────────────────────────────────────────
        if ($action === 'delete_questionnaire') {
            $qid = (int) ($_POST['questionnaire_id'] ?? 0);

            if ($qid <= 0) {
                throw new RuntimeException('Invalid questionnaire.');
            }

            $stmt = $pdo->prepare('DELETE FROM qualification_questionnaires WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $qid]);

            $_SESSION['qb_success'] = 'Questionnaire deleted.';
            header('Location: qualification_builder.php');
            exit;
        }

        // ── Add question ──────────────────────────────────────────────────────
        if ($action === 'add_question') {
            $qid          = (int) ($_POST['questionnaire_id'] ?? 0);
            $questionText = trim((string) ($_POST['question_text'] ?? ''));
            $questionType = trim((string) ($_POST['question_type'] ?? 'text'));

            if ($qid <= 0) {
                throw new RuntimeException('Invalid questionnaire.');
            }
            if ($questionText === '') {
                throw new RuntimeException('Question text is required.');
            }

            $allowed = ['text', 'single_choice', 'date'];
            if (!in_array($questionType, $allowed, true)) {
                $questionType = 'text';
            }

            $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM qualification_questionnaires WHERE id = :id');
            $checkStmt->execute([':id' => $qid]);
            if ((int) $checkStmt->fetchColumn() === 0) {
                throw new RuntimeException('Questionnaire not found.');
            }

            $stmt = $pdo->prepare('INSERT INTO qualification_questions (questionnaire_id, question_text, question_type) VALUES (:qid, :text, :type)');
            $stmt->execute([':qid' => $qid, ':text' => $questionText, ':type' => $questionType]);

            $_SESSION['qb_success'] = 'Question added.';
            header('Location: qualification_builder.php?questionnaire_id=' . $qid);
            exit;
        }

        // ── Update question ───────────────────────────────────────────────────
        if ($action === 'update_question') {
            $questionId   = (int) ($_POST['question_id'] ?? 0);
            $qid          = (int) ($_POST['questionnaire_id'] ?? 0);
            $questionText = trim((string) ($_POST['question_text'] ?? ''));
            $questionType = trim((string) ($_POST['question_type'] ?? 'text'));

            if ($questionId <= 0 || $qid <= 0) {
                throw new RuntimeException('Invalid question or questionnaire.');
            }
            if ($questionText === '') {
                throw new RuntimeException('Question text is required.');
            }

            $allowed = ['text', 'single_choice', 'date'];
            if (!in_array($questionType, $allowed, true)) {
                $questionType = 'text';
            }

            $stmt = $pdo->prepare('UPDATE qualification_questions SET question_text = :text, question_type = :type WHERE id = :id AND questionnaire_id = :qid LIMIT 1');
            $stmt->execute([':text' => $questionText, ':type' => $questionType, ':id' => $questionId, ':qid' => $qid]);

            $_SESSION['qb_success'] = 'Question updated.';
            header('Location: qualification_builder.php?questionnaire_id=' . $qid);
            exit;
        }

        // ── Delete question ───────────────────────────────────────────────────
        if ($action === 'delete_question') {
            $questionId = (int) ($_POST['question_id'] ?? 0);
            $qid        = (int) ($_POST['questionnaire_id'] ?? 0);

            if ($questionId <= 0 || $qid <= 0) {
                throw new RuntimeException('Invalid question or questionnaire.');
            }

            $stmt = $pdo->prepare('DELETE FROM qualification_questions WHERE id = :id AND questionnaire_id = :qid LIMIT 1');
            $stmt->execute([':id' => $questionId, ':qid' => $qid]);

            $_SESSION['qb_success'] = 'Question deleted.';
            header('Location: qualification_builder.php?questionnaire_id=' . $qid);
            exit;
        }

        // ── Save branch rule ──────────────────────────────────────────────────
        if ($action === 'save_branch') {
            $qid            = (int) ($_POST['questionnaire_id'] ?? 0);
            $fromQuestionId = (int) ($_POST['from_question_id'] ?? 0);
            $answerValue    = trim((string) ($_POST['answer_value'] ?? ''));
            $nextQuestionId = (int) ($_POST['next_question_id'] ?? 0);
            $isTerminal     = ($_POST['is_terminal'] ?? '0') === '1' ? 1 : 0;

            if ($qid <= 0 || $fromQuestionId <= 0) {
                throw new RuntimeException('Invalid branch parameters.');
            }
            if ($answerValue === '') {
                throw new RuntimeException('Answer value is required for a branch rule.');
            }

            $resolvedNext = $nextQuestionId > 0 ? $nextQuestionId : null;
            if ($isTerminal === 1) {
                $resolvedNext = null;
            }

            // Validate that from_question belongs to this questionnaire
            $ownerStmt = $pdo->prepare('SELECT COUNT(*) FROM qualification_questions WHERE id = :id AND questionnaire_id = :qid');
            $ownerStmt->execute([':id' => $fromQuestionId, ':qid' => $qid]);
            if ((int) $ownerStmt->fetchColumn() === 0) {
                throw new RuntimeException('Source question does not belong to this questionnaire.');
            }

            // Validate that next_question (if set) also belongs to this questionnaire
            if ($resolvedNext !== null) {
                $nextOwnerStmt = $pdo->prepare('SELECT COUNT(*) FROM qualification_questions WHERE id = :id AND questionnaire_id = :qid');
                $nextOwnerStmt->execute([':id' => $resolvedNext, ':qid' => $qid]);
                if ((int) $nextOwnerStmt->fetchColumn() === 0) {
                    throw new RuntimeException('Target question does not belong to this questionnaire.');
                }
            }

            // Upsert
            $checkBranchStmt = $pdo->prepare('SELECT id FROM question_branches WHERE question_id = :qid AND answer_value = :av LIMIT 1');
            $checkBranchStmt->execute([':qid' => $fromQuestionId, ':av' => $answerValue]);
            $existingBranchId = (int) ($checkBranchStmt->fetchColumn() ?: 0);

            if ($existingBranchId > 0) {
                $upd = $pdo->prepare('UPDATE question_branches SET next_question_id = :next, is_terminal = :terminal WHERE id = :id LIMIT 1');
                $upd->execute([':next' => $resolvedNext, ':terminal' => $isTerminal, ':id' => $existingBranchId]);
            } else {
                $ins = $pdo->prepare('INSERT INTO question_branches (question_id, answer_value, next_question_id, is_terminal) VALUES (:qid, :av, :next, :terminal)');
                $ins->execute([':qid' => $fromQuestionId, ':av' => $answerValue, ':next' => $resolvedNext, ':terminal' => $isTerminal]);
            }

            $_SESSION['qb_success'] = 'Branch rule saved.';
            header('Location: qualification_builder.php?questionnaire_id=' . $qid);
            exit;
        }

        // ── Delete branch rule ────────────────────────────────────────────────
        if ($action === 'delete_branch') {
            $branchId = (int) ($_POST['branch_id'] ?? 0);
            $qid      = (int) ($_POST['questionnaire_id'] ?? 0);

            if ($branchId <= 0 || $qid <= 0) {
                throw new RuntimeException('Invalid branch.');
            }

            // Confirm branch belongs to a question in this questionnaire
            $ownerStmt = $pdo->prepare('SELECT COUNT(*) FROM question_branches qb JOIN qualification_questions qq ON qq.id = qb.question_id WHERE qb.id = :bid AND qq.questionnaire_id = :qid');
            $ownerStmt->execute([':bid' => $branchId, ':qid' => $qid]);
            if ((int) $ownerStmt->fetchColumn() === 0) {
                throw new RuntimeException('Branch not found.');
            }

            $pdo->prepare('DELETE FROM question_branches WHERE id = :id LIMIT 1')->execute([':id' => $branchId]);

            $_SESSION['qb_success'] = 'Branch rule deleted.';
            header('Location: qualification_builder.php?questionnaire_id=' . $qid);
            exit;
        }

    } catch (Throwable $e) {
        $_SESSION['qb_error'] = $e->getMessage();
        $returnQid = (int) ($_POST['questionnaire_id'] ?? 0);
        $redirect  = 'qualification_builder.php' . ($returnQid > 0 ? '?questionnaire_id=' . $returnQid : '');
        header('Location: ' . $redirect);
        exit;
    }
}

// ─── Load data ───────────────────────────────────────────────────────────────
$questionnaires = $pdo->query('SELECT id, name, description, created_at FROM qualification_questionnaires ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

$selectedQid           = (int) ($_GET['questionnaire_id'] ?? 0);
$selectedQuestionnaire = null;
$questions             = [];
$branches              = [];    // keyed by question_id
$allBranchRows         = [];    // flat rows including branch id

if ($selectedQid > 0) {
    foreach ($questionnaires as $row) {
        if ((int) $row['id'] === $selectedQid) {
            $selectedQuestionnaire = $row;
            break;
        }
    }
}

if ($selectedQuestionnaire !== null) {
    $qStmt = $pdo->prepare('SELECT id, question_text, question_type FROM qualification_questions WHERE questionnaire_id = :qid ORDER BY id ASC');
    $qStmt->execute([':qid' => $selectedQid]);
    $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($questions !== []) {
        $qIds         = array_column($questions, 'id');
        $placeholders = implode(',', array_fill(0, count($qIds), '?'));
        $bStmt        = $pdo->prepare("SELECT id, question_id, answer_value, next_question_id, is_terminal FROM question_branches WHERE question_id IN ($placeholders)");
        $bStmt->execute($qIds);
        $allBranchRows = $bStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allBranchRows as $bRow) {
            $branches[(int) $bRow['question_id']][] = $bRow;
        }
    }
}

// Build a quick lookup: question id → question_text
$questionLabelById = [];
foreach ($questions as $q) {
    $questionLabelById[(int) $q['id']] = (string) $q['question_text'];
}

// ─── Page render ─────────────────────────────────────────────────────────────
$pageTitle       = 'Questionnaire Builder | Ghost Laser';
$pageDescription = 'Admin questionnaire builder — manage questionnaires, questions, and branching logic.';
$extraHead       = <<<'HTML'
<style>
    .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
    .input-base {
        width: 100%;
        background: rgba(39,39,42,0.6);
        border: 1px solid rgb(63,63,70);
        color: white;
        border-radius: 0.5rem;
        padding: 0.625rem 1rem;
        font-size: 0.875rem;
        transition: border-color 0.15s, box-shadow 0.15s;
        outline: none;
    }
    .input-base::placeholder { color: rgb(113,113,122); }
    .input-base:focus { border-color: #06b6d4; box-shadow: 0 0 0 1px rgba(6,182,212,0.5); }
    .modal-overlay { background: rgba(0,0,0,0.7); }
</style>
HTML;
$headerRight = '<a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>';

require_once __DIR__ . '/templates/header.php';
?>
<main class="pt-28 pb-20 bg-zinc-950 min-h-screen">
<div class="max-w-6xl mx-auto px-6 space-y-6">

    <!-- ── Page header ──────────────────────────────────────────────────── -->
    <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 card-glow">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-amber-400">Admin — Settings</p>
                <h1 class="mt-2 text-3xl font-black text-white">Qualification Questionnaires</h1>
                <p class="mt-2 text-sm text-zinc-400">Build and manage questionnaires, questions, and branching logic.</p>
            </div>
            <button
                type="button"
                onclick="document.getElementById('modal-create-questionnaire').classList.remove('hidden')"
                class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400 transition-colors"
            >+ New Questionnaire</button>
        </div>
    </section>

    <!-- ── Flash messages ───────────────────────────────────────────────── -->
    <?php if ($flashSuccess !== ''): ?>
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300"><?= h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300"><?= h($flashError) ?></div>
    <?php endif; ?>

    <!-- ── Questionnaire list ────────────────────────────────────────────── -->
    <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 card-glow">
        <h2 class="text-xs font-semibold uppercase tracking-widest text-cyan-400 mb-4">All Questionnaires</h2>
        <?php if ($questionnaires === []): ?>
            <p class="text-sm text-zinc-400">No questionnaires yet. Click <strong class="text-white">+ New Questionnaire</strong> to create one.</p>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($questionnaires as $q): ?>
                    <?php $isSelected = ((int) $q['id'] === $selectedQid); ?>
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border <?= $isSelected ? 'border-cyan-500/40 bg-cyan-500/5' : 'border-zinc-800 bg-zinc-950/40' ?> px-4 py-3">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-white truncate"><?= h($q['name']) ?></p>
                            <?php if (trim((string) ($q['description'] ?? '')) !== ''): ?>
                                <p class="text-xs text-zinc-400 mt-0.5 truncate"><?= h((string) $q['description']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <a href="qualification_builder.php?questionnaire_id=<?= (int) $q['id'] ?>"
                               class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs text-zinc-200 hover:bg-zinc-800 transition-colors">
                                <?= $isSelected ? 'Viewing' : 'View / Edit' ?>
                            </a>
                            <button
                                type="button"
                                onclick="openEditQuestionnaire(<?= (int) $q['id'] ?>, <?= json_encode($q['name']) ?>, <?= json_encode((string) ($q['description'] ?? '')) ?>)"
                                class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs text-zinc-200 hover:bg-zinc-800 transition-colors"
                            >Rename</button>
                            <button
                                type="button"
                                onclick="openDeleteQuestionnaire(<?= (int) $q['id'] ?>, <?= json_encode($q['name']) ?>)"
                                class="rounded-md border border-red-800/60 bg-red-950/30 px-3 py-1.5 text-xs text-red-300 hover:bg-red-950/60 transition-colors"
                            >Delete</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ── Questions & branching for selected questionnaire ─────────────── -->
    <?php if ($selectedQuestionnaire !== null): ?>
    <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 card-glow">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div>
                <h2 class="text-lg font-bold text-white"><?= h($selectedQuestionnaire['name']) ?></h2>
                <?php if (trim((string) ($selectedQuestionnaire['description'] ?? '')) !== ''): ?>
                    <p class="text-sm text-zinc-400 mt-1"><?= h((string) $selectedQuestionnaire['description']) ?></p>
                <?php endif; ?>
            </div>
            <button
                type="button"
                onclick="document.getElementById('modal-add-question').classList.remove('hidden')"
                class="rounded-lg bg-cyan-500 px-3 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400 transition-colors"
            >+ Add Question</button>
        </div>

        <?php if ($questions === []): ?>
            <p class="text-sm text-zinc-400">No questions in this questionnaire yet. Click <strong class="text-white">+ Add Question</strong> to add one.</p>
        <?php else: ?>
            <div class="space-y-5">
                <?php foreach ($questions as $qRow): ?>
                    <?php
                        $qId       = (int) $qRow['id'];
                        $qText     = (string) $qRow['question_text'];
                        $qType     = (string) $qRow['question_type'];
                        $qBranches = $branches[$qId] ?? [];
                    ?>
                    <div class="rounded-xl border border-zinc-700 bg-zinc-950/50 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-white font-medium leading-snug"><?= h($qText) ?></p>
                                <span class="mt-1 inline-block rounded-full border border-zinc-700 bg-zinc-800 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-400"><?= h($qType) ?></span>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <button
                                    type="button"
                                    onclick="openEditQuestion(<?= $qId ?>, <?= json_encode($qText) ?>, <?= json_encode($qType) ?>)"
                                    class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs text-zinc-200 hover:bg-zinc-800 transition-colors"
                                >Edit</button>
                                <button
                                    type="button"
                                    onclick="openDeleteQuestion(<?= $qId ?>, <?= json_encode(mb_strimwidth($qText, 0, 60, '…')) ?>)"
                                    class="rounded-md border border-red-800/60 bg-red-950/30 px-3 py-1.5 text-xs text-red-300 hover:bg-red-950/60 transition-colors"
                                >Delete</button>
                            </div>
                        </div>

                        <!-- Branch rules for this question -->
                        <div class="mt-4">
                            <div class="flex items-center justify-between mb-2">
                                <p class="text-[11px] font-semibold uppercase tracking-widest text-zinc-500">Branch Rules</p>
                                <button
                                    type="button"
                                    onclick="openAddBranch(<?= $qId ?>)"
                                    class="rounded-md border border-zinc-700 px-2.5 py-1 text-[11px] text-zinc-300 hover:bg-zinc-800 transition-colors"
                                >+ Add Rule</button>
                            </div>
                            <?php if ($qBranches === []): ?>
                                <p class="text-xs text-zinc-600 italic">No branch rules — question always flows to the next in sequence.</p>
                            <?php else: ?>
                                <div class="space-y-1.5">
                                    <?php foreach ($qBranches as $br): ?>
                                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-800 bg-zinc-900/60 px-3 py-2 text-xs">
                                            <div class="flex flex-wrap gap-2 items-center min-w-0">
                                                <span class="text-zinc-300">If answer =</span>
                                                <span class="font-semibold text-cyan-300"><?= h($br['answer_value']) ?></span>
                                                <span class="text-zinc-500">→</span>
                                                <?php if ((int) $br['is_terminal'] === 1): ?>
                                                    <span class="rounded-full border border-red-800/60 bg-red-950/30 px-2 py-0.5 text-red-300 font-semibold">End questionnaire</span>
                                                <?php elseif ($br['next_question_id'] !== null): ?>
                                                    <span class="text-emerald-300 font-medium"><?= h(mb_strimwidth($questionLabelById[(int) $br['next_question_id']] ?? 'Q#' . $br['next_question_id'], 0, 50, '…')) ?></span>
                                                <?php else: ?>
                                                    <span class="text-zinc-500 italic">next in sequence</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex items-center gap-2 flex-shrink-0">
                                                <button
                                                    type="button"
                                                    onclick="openEditBranch(<?= (int) $br['id'] ?>, <?= $qId ?>, <?= json_encode($br['answer_value']) ?>, <?= $br['next_question_id'] !== null ? (int) $br['next_question_id'] : 'null' ?>, <?= (int) $br['is_terminal'] ?>)"
                                                    class="rounded border border-zinc-700 px-2 py-0.5 text-[11px] text-zinc-300 hover:bg-zinc-800 transition-colors"
                                                >Edit</button>
                                                <form method="post" action="qualification_builder.php?questionnaire_id=<?= $selectedQid ?>" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="delete_branch">
                                                    <input type="hidden" name="branch_id" value="<?= (int) $br['id'] ?>">
                                                    <input type="hidden" name="questionnaire_id" value="<?= $selectedQid ?>">
                                                    <button
                                                        type="submit"
                                                        onclick="return confirm('Delete this branch rule?')"
                                                        class="rounded border border-red-800/60 bg-red-950/20 px-2 py-0.5 text-[11px] text-red-400 hover:bg-red-950/50 transition-colors"
                                                    >Delete</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

</div>
</main>

<!-- ═══════════════════════════════ MODALS ══════════════════════════════════ -->

<!-- Create questionnaire -->
<div id="modal-create-questionnaire" class="hidden fixed inset-0 z-50 flex items-center justify-center px-4 modal-overlay">
    <div class="w-full max-w-md rounded-2xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">New Questionnaire</h3>
        <form method="post" action="qualification_builder.php" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="create_questionnaire">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Name <span class="text-red-400">*</span></label>
                <input type="text" name="name" required class="input-base" placeholder="e.g. New Machine — Never Worked Right">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Description</label>
                <textarea name="description" rows="2" class="input-base" placeholder="Optional short description"></textarea>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="submit" class="flex-1 rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400 transition-colors">Create</button>
                <button type="button" onclick="document.getElementById('modal-create-questionnaire').classList.add('hidden')" class="flex-1 rounded-lg border border-zinc-700 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-800 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit questionnaire -->
<div id="modal-edit-questionnaire" class="hidden fixed inset-0 z-50 flex items-center justify-center px-4 modal-overlay">
    <div class="w-full max-w-md rounded-2xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">Edit Questionnaire</h3>
        <form method="post" action="qualification_builder.php" id="form-edit-questionnaire" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="update_questionnaire">
            <input type="hidden" name="questionnaire_id" id="edit-q-id">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Name <span class="text-red-400">*</span></label>
                <input type="text" name="name" id="edit-q-name" required class="input-base">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Description</label>
                <textarea name="description" id="edit-q-desc" rows="2" class="input-base"></textarea>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="submit" class="flex-1 rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400 transition-colors">Save</button>
                <button type="button" onclick="document.getElementById('modal-edit-questionnaire').classList.add('hidden')" class="flex-1 rounded-lg border border-zinc-700 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-800 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete questionnaire -->
<div id="modal-delete-questionnaire" class="hidden fixed inset-0 z-50 flex items-center justify-center px-4 modal-overlay">
    <div class="w-full max-w-md rounded-2xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-red-400 mb-2">Delete Questionnaire</h3>
        <p class="text-sm text-zinc-400 mb-1">Are you sure you want to permanently delete:</p>
        <p id="delete-q-label" class="font-semibold text-white mb-5"></p>
        <p class="text-xs text-red-400 mb-4">This will also delete all its questions, branch rules, and saved responses.</p>
        <form method="post" action="qualification_builder.php" class="flex gap-3">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="delete_questionnaire">
            <input type="hidden" name="questionnaire_id" id="delete-q-id">
            <button type="submit" class="flex-1 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500 transition-colors">Delete</button>
            <button type="button" onclick="document.getElementById('modal-delete-questionnaire').classList.add('hidden')" class="flex-1 rounded-lg border border-zinc-700 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-800 transition-colors">Cancel</button>
        </form>
    </div>
</div>

<!-- Add question -->
<div id="modal-add-question" class="hidden fixed inset-0 z-50 flex items-center justify-center px-4 modal-overlay">
    <div class="w-full max-w-lg rounded-2xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">Add Question</h3>
        <form method="post" action="qualification_builder.php?questionnaire_id=<?= $selectedQid ?>" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="add_question">
            <input type="hidden" name="questionnaire_id" value="<?= $selectedQid ?>">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Question Text <span class="text-red-400">*</span></label>
                <textarea name="question_text" rows="3" required class="input-base" placeholder="e.g. What brand and model is your laser cutter?"></textarea>
                <p class="mt-1 text-[11px] text-zinc-500">For single-choice questions, list options in parentheses at the end, separated by " / " — e.g. <em>(Option A / Option B / Option C)</em></p>
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Question Type <span class="text-red-400">*</span></label>
                <select name="question_type" class="input-base">
                    <option value="text">Text (free response)</option>
                    <option value="single_choice">Single Choice (options from question text)</option>
                    <option value="date">Date</option>
                </select>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="submit" class="flex-1 rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400 transition-colors">Add Question</button>
                <button type="button" onclick="document.getElementById('modal-add-question').classList.add('hidden')" class="flex-1 rounded-lg border border-zinc-700 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-800 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit question -->
<div id="modal-edit-question" class="hidden fixed inset-0 z-50 flex items-center justify-center px-4 modal-overlay">
    <div class="w-full max-w-lg rounded-2xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">Edit Question</h3>
        <form method="post" action="qualification_builder.php?questionnaire_id=<?= $selectedQid ?>" id="form-edit-question" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="update_question">
            <input type="hidden" name="questionnaire_id" value="<?= $selectedQid ?>">
            <input type="hidden" name="question_id" id="edit-question-id">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Question Text <span class="text-red-400">*</span></label>
                <textarea name="question_text" id="edit-question-text" rows="3" required class="input-base"></textarea>
                <p class="mt-1 text-[11px] text-zinc-500">For single-choice questions, list options in parentheses at the end, separated by " / "</p>
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Question Type <span class="text-red-400">*</span></label>
                <select name="question_type" id="edit-question-type" class="input-base">
                    <option value="text">Text (free response)</option>
                    <option value="single_choice">Single Choice</option>
                    <option value="date">Date</option>
                </select>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="submit" class="flex-1 rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400 transition-colors">Save</button>
                <button type="button" onclick="document.getElementById('modal-edit-question').classList.add('hidden')" class="flex-1 rounded-lg border border-zinc-700 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-800 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete question -->
<div id="modal-delete-question" class="hidden fixed inset-0 z-50 flex items-center justify-center px-4 modal-overlay">
    <div class="w-full max-w-md rounded-2xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-red-400 mb-2">Delete Question</h3>
        <p class="text-sm text-zinc-400 mb-1">Delete this question?</p>
        <p id="delete-question-label" class="font-medium text-white text-sm mb-5"></p>
        <p class="text-xs text-red-400 mb-4">All branch rules attached to this question will also be removed.</p>
        <form method="post" action="qualification_builder.php?questionnaire_id=<?= $selectedQid ?>" class="flex gap-3">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="delete_question">
            <input type="hidden" name="questionnaire_id" value="<?= $selectedQid ?>">
            <input type="hidden" name="question_id" id="delete-question-id">
            <button type="submit" class="flex-1 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500 transition-colors">Delete</button>
            <button type="button" onclick="document.getElementById('modal-delete-question').classList.add('hidden')" class="flex-1 rounded-lg border border-zinc-700 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-800 transition-colors">Cancel</button>
        </form>
    </div>
</div>

<!-- Add / Edit branch rule -->
<div id="modal-branch" class="hidden fixed inset-0 z-50 flex items-center justify-center px-4 modal-overlay">
    <div class="w-full max-w-lg rounded-2xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl">
        <h3 id="modal-branch-title" class="text-lg font-bold text-white mb-4">Add Branch Rule</h3>
        <form method="post" action="qualification_builder.php?questionnaire_id=<?= $selectedQid ?>" id="form-branch" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="save_branch">
            <input type="hidden" name="questionnaire_id" value="<?= $selectedQid ?>">
            <input type="hidden" name="from_question_id" id="branch-from-id">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">If answer equals <span class="text-red-400">*</span></label>
                <input type="text" name="answer_value" id="branch-answer-value" required class="input-base" placeholder="Exact answer text, e.g. Yes">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Then go to question</label>
                <select name="next_question_id" id="branch-next-question" class="input-base">
                    <option value="">— Next in sequence —</option>
                    <?php foreach ($questions as $q): ?>
                        <option value="<?= (int) $q['id'] ?>"><?= h(mb_strimwidth($q['question_text'], 0, 70, '…')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_terminal" id="branch-is-terminal" value="1" class="w-4 h-4 rounded border-zinc-600 bg-zinc-800 text-red-500 cursor-pointer">
                <label for="branch-is-terminal" class="text-sm text-zinc-300 cursor-pointer">End the questionnaire (terminal branch)</label>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="submit" class="flex-1 rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400 transition-colors">Save Rule</button>
                <button type="button" onclick="document.getElementById('modal-branch').classList.add('hidden')" class="flex-1 rounded-lg border border-zinc-700 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-800 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>

<script>
(() => {
    // Close modal on backdrop click
    document.querySelectorAll('.modal-overlay').forEach((overlay) => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.classList.add('hidden');
            }
        });
    });

    // Close modal on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay').forEach((overlay) => {
                overlay.classList.add('hidden');
            });
        }
    });
})();

function openEditQuestionnaire(id, name, description) {
    document.getElementById('edit-q-id').value   = id;
    document.getElementById('edit-q-name').value  = name;
    document.getElementById('edit-q-desc').value  = description;
    document.getElementById('modal-edit-questionnaire').classList.remove('hidden');
}

function openDeleteQuestionnaire(id, name) {
    document.getElementById('delete-q-id').value    = id;
    document.getElementById('delete-q-label').textContent = name;
    document.getElementById('modal-delete-questionnaire').classList.remove('hidden');
}

function openEditQuestion(id, text, type) {
    document.getElementById('edit-question-id').value   = id;
    document.getElementById('edit-question-text').value = text;
    document.getElementById('edit-question-type').value = type;
    document.getElementById('modal-edit-question').classList.remove('hidden');
}

function openDeleteQuestion(id, label) {
    document.getElementById('delete-question-id').value    = id;
    document.getElementById('delete-question-label').textContent = label;
    document.getElementById('modal-delete-question').classList.remove('hidden');
}

function openAddBranch(fromQuestionId) {
    document.getElementById('modal-branch-title').textContent = 'Add Branch Rule';
    document.getElementById('branch-from-id').value           = fromQuestionId;
    document.getElementById('branch-answer-value').value      = '';
    document.getElementById('branch-next-question').value     = '';
    document.getElementById('branch-is-terminal').checked     = false;
    document.getElementById('modal-branch').classList.remove('hidden');
}

function openEditBranch(branchId, fromQuestionId, answerValue, nextQuestionId, isTerminal) {
    document.getElementById('modal-branch-title').textContent = 'Edit Branch Rule';
    document.getElementById('branch-from-id').value           = fromQuestionId;
    document.getElementById('branch-answer-value').value      = answerValue;
    document.getElementById('branch-next-question').value     = nextQuestionId || '';
    document.getElementById('branch-is-terminal').checked     = isTerminal === 1;
    document.getElementById('modal-branch').classList.remove('hidden');
}
</script>
