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

function parseQuestionOptions(string $questionText): array
{
    if (!preg_match('/\(([^)]+)\)\s*$/', $questionText, $matches)) {
        return [];
    }

    $rawOptions = trim((string) $matches[1]);
    if ($rawOptions === '') {
        return [];
    }

    $delimiter = str_contains($rawOptions, ' / ') ? ' / ' : ',';
    $parts = array_filter(array_map(static function ($value) {
        return trim((string) $value);
    }, explode($delimiter, $rawOptions)), static function ($value) {
        return $value !== '';
    });

    return array_values($parts);
}

function defaultQualificationDefinitions(): array
{
    $coreQuestions = [
        ['key' => 'brand_model', 'text' => 'What brand and model is your laser cutter?', 'type' => 'text'],
        ['key' => 'machine_age', 'text' => 'How old is this machine? (New, less than 6 months, 6-18 months, over 18 months)', 'type' => 'single_choice'],
        ['key' => 'purchase_date', 'text' => 'When did you purchase this machine?', 'type' => 'date'],
        ['key' => 'installation_owner', 'text' => 'Who assembled and installed the machine when you received it? (We installed it, Seller/Technician installed it, I installed it myself)', 'type' => 'single_choice'],
        ['key' => 'ever_cut_properly', 'text' => 'Has this machine ever cut properly since you got it? (Yes - it worked great before / No - it has never cut correctly)', 'type' => 'single_choice'],
    ];

    return [
        [
            'name' => 'New Machine - Never Worked Right',
            'description' => 'For new installs that have never cut correctly.',
            'questions' => array_merge($coreQuestions, [
                ['key' => 'alignment_checked', 'text' => 'Have mirror alignment and focus calibration been checked yet? (Yes / No)', 'type' => 'single_choice'],
                ['key' => 'test_cut_result', 'text' => 'What happens during a standard test cut right now?', 'type' => 'text'],
            ]),
            'branches' => [
                ['from' => 'ever_cut_properly', 'answer' => 'No - it has never cut correctly', 'to' => 'alignment_checked', 'terminal' => 0],
                ['from' => 'ever_cut_properly', 'answer' => 'Yes - it worked great before', 'to' => null, 'terminal' => 1],
            ],
        ],
        [
            'name' => 'Machine Suddenly Stopped Working',
            'description' => 'For machines that previously cut correctly and then failed.',
            'questions' => array_merge($coreQuestions, [
                ['key' => 'pre_change', 'text' => 'What changed right before the issue started? (Power event, software/settings change, new material, no known change)', 'type' => 'single_choice'],
                ['key' => 'current_alarm', 'text' => 'Are there any alarms, error codes, or unusual sounds right now?', 'type' => 'text'],
            ]),
            'branches' => [
                ['from' => 'ever_cut_properly', 'answer' => 'Yes - it worked great before', 'to' => 'pre_change', 'terminal' => 0],
                ['from' => 'ever_cut_properly', 'answer' => 'No - it has never cut correctly', 'to' => null, 'terminal' => 1],
            ],
        ],
        [
            'name' => 'Poor Cut Quality',
            'description' => 'For machines that still cut but with poor cut results.',
            'questions' => array_merge($coreQuestions, [
                ['key' => 'quality_symptom', 'text' => 'Which issue best matches the cut quality problem? (Not cutting through, excessive charring, inconsistent depth, rough edges)', 'type' => 'single_choice'],
                ['key' => 'optics_verified', 'text' => 'Have lens, mirrors, and material settings been cleaned/verified recently? (Yes / No)', 'type' => 'single_choice'],
            ]),
            'branches' => [
                ['from' => 'ever_cut_properly', 'answer' => 'Yes - it worked great before', 'to' => 'quality_symptom', 'terminal' => 0],
                ['from' => 'ever_cut_properly', 'answer' => 'No - it has never cut correctly', 'to' => 'quality_symptom', 'terminal' => 0],
            ],
        ],
    ];
}

function importDefaultQuestionnaires(PDO $pdo): array
{
    $definitions = defaultQualificationDefinitions();

    $questionnaireStmt = $pdo->prepare("\n        INSERT INTO qualification_questionnaires (name, description)\n        VALUES (:name, :description)\n        ON DUPLICATE KEY UPDATE\n            description = VALUES(description),\n            id = LAST_INSERT_ID(id)\n    ");

    $questionStmt = $pdo->prepare("\n        INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)\n        VALUES (:questionnaire_id, :question_text, :question_type)\n        ON DUPLICATE KEY UPDATE\n            question_type = VALUES(question_type),\n            id = LAST_INSERT_ID(id)\n    ");

    $selectBranchStmt = $pdo->prepare("\n        SELECT id\n        FROM question_branches\n        WHERE question_id = :question_id\n          AND answer_value = :answer_value\n        LIMIT 1\n    ");

    $insertBranchStmt = $pdo->prepare("\n        INSERT INTO question_branches (question_id, answer_value, next_question_id, is_terminal)\n        VALUES (:question_id, :answer_value, :next_question_id, :is_terminal)\n    ");

    $updateBranchStmt = $pdo->prepare("\n        UPDATE question_branches\n        SET next_question_id = :next_question_id,\n            is_terminal = :is_terminal\n        WHERE id = :id\n        LIMIT 1\n    ");

    $questionnairesTouched = 0;

    $pdo->beginTransaction();
    try {
        foreach ($definitions as $definition) {
            $questionnaireStmt->execute([
                ':name' => $definition['name'],
                ':description' => $definition['description'],
            ]);
            $questionnaireId = (int) $pdo->lastInsertId();
            if ($questionnaireId <= 0) {
                throw new RuntimeException('Failed to import questionnaire.');
            }

            $questionnairesTouched++;
            $questionIdByKey = [];
            foreach ($definition['questions'] as $question) {
                $questionStmt->execute([
                    ':questionnaire_id' => $questionnaireId,
                    ':question_text' => $question['text'],
                    ':question_type' => $question['type'],
                ]);
                $questionIdByKey[$question['key']] = (int) $pdo->lastInsertId();
            }

            foreach ($definition['branches'] as $branch) {
                $fromQuestionId = (int) ($questionIdByKey[$branch['from']] ?? 0);
                if ($fromQuestionId <= 0) {
                    continue;
                }

                $toQuestionId = null;
                if ($branch['to'] !== null) {
                    $resolved = (int) ($questionIdByKey[$branch['to']] ?? 0);
                    $toQuestionId = $resolved > 0 ? $resolved : null;
                }

                $selectBranchStmt->execute([
                    ':question_id' => $fromQuestionId,
                    ':answer_value' => $branch['answer'],
                ]);
                $existingBranchId = (int) ($selectBranchStmt->fetchColumn() ?: 0);

                if ($existingBranchId > 0) {
                    $updateBranchStmt->execute([
                        ':next_question_id' => $toQuestionId,
                        ':is_terminal' => (int) $branch['terminal'],
                        ':id' => $existingBranchId,
                    ]);
                } else {
                    $insertBranchStmt->execute([
                        ':question_id' => $fromQuestionId,
                        ':answer_value' => $branch['answer'],
                        ':next_question_id' => $toQuestionId,
                        ':is_terminal' => (int) $branch['terminal'],
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'imported' => $questionnairesTouched,
        'message' => 'Default questionnaires imported (idempotent).',
    ];
}

if (empty($_SESSION['qualification_questionnaires_csrf'])) {
    $_SESSION['qualification_questionnaires_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['qualification_questionnaires_csrf'];

$isCustomerSearchRequest = (
    (isset($_GET['action']) && $_GET['action'] === 'customer_search')
    || (isset($_GET['customer_search']) && (string) $_GET['customer_search'] === '1')
);

if ($isCustomerSearchRequest) {
    header('Content-Type: application/json');

    $csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($csrfHeader === '' || !hash_equals($csrfToken, $csrfHeader)) {
        http_response_code(403);
        echo json_encode(['results' => [], 'error' => 'Invalid CSRF token.']);
        exit;
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("\n        SELECT id, first_name, last_name, company, email, phone\n        FROM customers\n        WHERE first_name LIKE :q\n           OR last_name LIKE :q\n           OR company LIKE :q\n           OR email LIKE :q\n           OR phone LIKE :q\n        ORDER BY last_name ASC, first_name ASC\n        LIMIT 8\n    ");
    $stmt->execute([':q' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $results[] = [
            'id' => (int) $row['id'],
            'customer_name' => trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))),
            'company_name' => (string) ($row['company'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
        ];
    }

    echo json_encode(['results' => $results]);
    exit;
}

$flashSuccess = (string) ($_SESSION['qualification_questionnaires_success'] ?? '');
$flashError = (string) ($_SESSION['qualification_questionnaires_error'] ?? '');
unset($_SESSION['qualification_questionnaires_success'], $_SESSION['qualification_questionnaires_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
        $_SESSION['qualification_questionnaires_error'] = 'Invalid request token.';
        header('Location: qualification_questionnaires.php');
        exit;
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'import_defaults') {
            $result = importDefaultQuestionnaires($pdo);
            $_SESSION['qualification_questionnaires_success'] = $result['message'];
            header('Location: qualification_questionnaires.php');
            exit;
        }

        if ($action === 'save_response') {
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $questionnaireId = (int) ($_POST['questionnaire_id'] ?? 0);
            $responsesJson = trim((string) ($_POST['responses_json'] ?? ''));

            if ($customerId <= 0) {
                throw new RuntimeException('Please select a customer before saving the questionnaire.');
            }
            if ($questionnaireId <= 0) {
                throw new RuntimeException('Please select a questionnaire.');
            }
            if ($responsesJson === '') {
                throw new RuntimeException('No questionnaire responses were provided.');
            }

            $decoded = json_decode($responsesJson, true);
            if (!is_array($decoded) || ($decoded['answers'] ?? null) === null) {
                throw new RuntimeException('Response payload is invalid.');
            }

            $customerCheckStmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE id = :id');
            $customerCheckStmt->execute([':id' => $customerId]);
            if ((int) $customerCheckStmt->fetchColumn() <= 0) {
                throw new RuntimeException('Selected customer does not exist.');
            }

            $questionnaireCheckStmt = $pdo->prepare('SELECT COUNT(*) FROM qualification_questionnaires WHERE id = :id');
            $questionnaireCheckStmt->execute([':id' => $questionnaireId]);
            if ((int) $questionnaireCheckStmt->fetchColumn() <= 0) {
                throw new RuntimeException('Selected questionnaire does not exist.');
            }

            $saveStmt = $pdo->prepare("\n                INSERT INTO qualification_responses (customer_id, questionnaire_id, responses_json)\n                VALUES (:customer_id, :questionnaire_id, :responses_json)\n            ");
            $saveStmt->execute([
                ':customer_id' => $customerId,
                ':questionnaire_id' => $questionnaireId,
                ':responses_json' => $responsesJson,
            ]);

            $_SESSION['qualification_questionnaires_success'] = 'Qualification questionnaire saved successfully.';
            header('Location: qualification_questionnaires.php?questionnaire_id=' . $questionnaireId);
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['qualification_questionnaires_error'] = $e->getMessage();
        header('Location: qualification_questionnaires.php');
        exit;
    }
}

$questionnaires = $pdo->query("\n    SELECT id, name, description, created_at\n    FROM qualification_questionnaires\n    ORDER BY name ASC\n")->fetchAll(PDO::FETCH_ASSOC);

$selectedQuestionnaireId = (int) ($_GET['questionnaire_id'] ?? 0);
if ($selectedQuestionnaireId <= 0 && $questionnaires !== []) {
    $selectedQuestionnaireId = (int) $questionnaires[0]['id'];
}
$selectedQuestionnaire = null;
foreach ($questionnaires as $questionnaire) {
    if ((int) $questionnaire['id'] === $selectedQuestionnaireId) {
        $selectedQuestionnaire = $questionnaire;
        break;
    }
}

$questions = [];
$branches = [];
if ($selectedQuestionnaireId > 0) {
    $questionStmt = $pdo->prepare("\n        SELECT id, question_text, question_type\n        FROM qualification_questions\n        WHERE questionnaire_id = :questionnaire_id\n        ORDER BY id ASC\n    ");
    $questionStmt->execute([':questionnaire_id' => $selectedQuestionnaireId]);
    $questionRows = $questionStmt->fetchAll(PDO::FETCH_ASSOC);

    $questionIds = [];
    foreach ($questionRows as $row) {
        $qid = (int) $row['id'];
        $questionIds[] = $qid;
        $questions[] = [
            'id' => $qid,
            'text' => (string) $row['question_text'],
            'type' => (string) $row['question_type'],
            'options' => parseQuestionOptions((string) $row['question_text']),
        ];
    }

    if ($questionIds !== []) {
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $branchStmt = $pdo->prepare("\n            SELECT question_id, answer_value, next_question_id, is_terminal\n            FROM question_branches\n            WHERE question_id IN ($placeholders)\n        ");
        $branchStmt->execute($questionIds);
        $branchRows = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($branchRows as $row) {
            $qid = (int) $row['question_id'];
            if (!isset($branches[$qid])) {
                $branches[$qid] = [];
            }
            $branches[$qid][] = [
                'answer_value' => (string) $row['answer_value'],
                'next_question_id' => $row['next_question_id'] !== null ? (int) $row['next_question_id'] : null,
                'is_terminal' => (int) $row['is_terminal'],
            ];
        }
    }
}

$pageTitle = 'Qualification Questionnaires | Ghost Laser';
$pageDescription = 'Internal qualification questionnaire workflow for phone calls.';
$extraHead = <<<'HTML'
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
    #customerSuggestions {
        position: absolute;
        z-index: 50;
        left: 0;
        right: 0;
        top: calc(100% + 4px);
        background: #18181b;
        border: 1px solid rgb(63,63,70);
        border-radius: 0.5rem;
        box-shadow: 0 8px 30px rgba(0,0,0,0.5);
        max-height: 280px;
        overflow-y: auto;
    }
    #customerSuggestions li {
        padding: 0.65rem 1rem;
        font-size: 0.8rem;
        color: #d4d4d8;
        cursor: pointer;
        border-bottom: 1px solid rgba(63,63,70,0.5);
    }
    #customerSuggestions li:last-child { border-bottom: none; }
    #customerSuggestions li:hover, #customerSuggestions li.active { background: rgba(6,182,212,0.12); color: #22d3ee; }
    #customerSuggestions li .result-name { font-weight: 600; color: #f4f4f5; }
    #customerSuggestions li .result-meta { color: #71717a; margin-top: 1px; }
</style>
HTML;

require_once __DIR__ . '/templates/header.php';
?>
<main class="pt-28 pb-20 bg-zinc-950 min-h-screen">
    <div class="max-w-5xl mx-auto px-6 space-y-6">
        <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 card-glow">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-amber-400">Internal Use Only</p>
                    <h1 class="mt-2 text-3xl font-black text-white">Qualification Questionnaires</h1>
                    <p class="mt-2 text-sm text-zinc-400">Select a customer first, then run and save a qualification questionnaire.</p>
                </div>
                <form method="post" action="qualification_questionnaires.php" class="inline-flex">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="import_defaults">
                    <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400 transition-colors">Import Default Questionnaires</button>
                </form>
            </div>
        </section>

        <?php if ($flashSuccess !== ''): ?>
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300"><?= h($flashSuccess) ?></div>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300"><?= h($flashError) ?></div>
        <?php endif; ?>

        <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 card-glow">
            <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400 mb-4">1) Select Customer</p>
            <input type="hidden" id="customer_id" value="">
            <div class="relative" id="customer-search-wrap">
                <input id="customer_name" type="text" autocomplete="off" placeholder="Search by customer name, company, phone, or email" class="input-base">
                <ul id="customerSuggestions" class="hidden" role="listbox" aria-label="Customer search results"></ul>
            </div>
            <div id="selectedCustomerBanner" class="hidden mt-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-sm text-emerald-200"></div>
        </section>

        <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 card-glow">
            <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400 mb-4">2) Select Questionnaire</p>
            <?php if ($questionnaires === []): ?>
                <p class="text-sm text-zinc-400">No questionnaires found yet. Use "Import Default Questionnaires" first.</p>
            <?php else: ?>
                <form method="get" action="qualification_questionnaires.php" class="space-y-3">
                    <select name="questionnaire_id" class="input-base">
                        <?php foreach ($questionnaires as $questionnaire): ?>
                            <option value="<?= (int) $questionnaire['id'] ?>"<?= (int) $questionnaire['id'] === $selectedQuestionnaireId ? ' selected' : '' ?>>
                                <?= h($questionnaire['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="rounded-lg border border-zinc-700 px-4 py-2 text-sm text-zinc-200 hover:bg-zinc-800 transition-colors">Load Questionnaire</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 card-glow">
            <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400 mb-4">3) Run & Save</p>
            <?php if ($questionnaires === [] || $questions === []): ?>
                <p class="text-sm text-zinc-400">Load a questionnaire to start the call workflow.</p>
            <?php else: ?>
                <div class="mb-4">
                    <h2 class="text-xl font-semibold text-white"><?= h((string) ($selectedQuestionnaire['name'] ?? 'Questionnaire')) ?></h2>
                    <p class="text-sm text-zinc-400 mt-1"><?= h((string) ($selectedQuestionnaire['description'] ?? '')) ?></p>
                </div>

                <form method="post" action="qualification_questionnaires.php" id="questionnaireForm" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_response">
                    <input type="hidden" name="customer_id" id="save_customer_id" value="">
                    <input type="hidden" name="questionnaire_id" value="<?= (int) $selectedQuestionnaireId ?>">
                    <input type="hidden" name="responses_json" id="responses_json" value="">

                    <div id="questionRunner" class="rounded-xl border border-zinc-800 bg-zinc-950/50 p-5"></div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" id="startBtn" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400 transition-colors">Start Questionnaire</button>
                        <button type="button" id="nextBtn" class="hidden rounded-lg border border-zinc-700 px-4 py-2 text-sm text-zinc-200 hover:bg-zinc-800 transition-colors">Next</button>
                        <button type="submit" id="saveBtn" class="hidden rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-emerald-400 transition-colors">Save Completed Questionnaire</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php if ($questions !== []): ?>
<script>
(() => {
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const searchInput = document.getElementById('customer_name');
    const customerIdInput = document.getElementById('customer_id');
    const saveCustomerIdInput = document.getElementById('save_customer_id');
    const suggestions = document.getElementById('customerSuggestions');
    const banner = document.getElementById('selectedCustomerBanner');

    let debounceTimer = null;
    let activeIndex = -1;

    const clearSelection = () => {
        customerIdInput.value = '';
        saveCustomerIdInput.value = '';
        banner.classList.add('hidden');
        banner.textContent = '';
    };

    const selectCustomer = (item) => {
        customerIdInput.value = String(item.id || '');
        saveCustomerIdInput.value = String(item.id || '');
        searchInput.value = item.customer_name || '';
        const parts = [item.customer_name || 'Customer', item.company_name || '', item.phone || '', item.email || ''].filter(Boolean);
        banner.textContent = 'Selected: ' + parts.join(' • ');
        banner.classList.remove('hidden');
        suggestions.classList.add('hidden');
        suggestions.innerHTML = '';
    };

    const renderSuggestions = (items) => {
        if (!Array.isArray(items) || items.length === 0) {
            suggestions.classList.add('hidden');
            suggestions.innerHTML = '';
            return;
        }

        activeIndex = -1;
        suggestions.innerHTML = items.map((item, idx) => {
            const name = item.customer_name || 'Unnamed customer';
            const meta = [item.company_name || '', item.phone || '', item.email || ''].filter(Boolean).join(' • ');
            return `<li data-index="${idx}"><div class="result-name">${name}</div><div class="result-meta">${meta}</div></li>`;
        }).join('');
        suggestions.classList.remove('hidden');

        Array.from(suggestions.querySelectorAll('li')).forEach((li, idx) => {
            li.addEventListener('click', () => selectCustomer(items[idx]));
        });
    };

    const runSearch = async (query) => {
        try {
            const url = new URL('qualification_questionnaires.php', window.location.href);
            url.searchParams.set('action', 'customer_search');
            url.searchParams.set('q', query);

            const res = await fetch(url.toString(), {
                headers: {
                    'X-CSRF-Token': csrfToken,
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });
            const data = await res.json();
            renderSuggestions(Array.isArray(data.results) ? data.results : []);
        } catch (error) {
            suggestions.classList.add('hidden');
            suggestions.innerHTML = '';
        }
    };

    searchInput.addEventListener('input', (event) => {
        const value = (event.target.value || '').trim();
        clearSelection();

        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        if (value.length < 2) {
            suggestions.classList.add('hidden');
            suggestions.innerHTML = '';
            return;
        }

        debounceTimer = setTimeout(() => runSearch(value), 220);
    });

    searchInput.addEventListener('keydown', (event) => {
        const items = Array.from(suggestions.querySelectorAll('li'));
        if (items.length === 0 || suggestions.classList.contains('hidden')) return;

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
        } else if (event.key === 'Enter') {
            if (activeIndex >= 0 && items[activeIndex]) {
                event.preventDefault();
                items[activeIndex].click();
            }
            return;
        } else {
            return;
        }

        items.forEach((item, idx) => item.classList.toggle('active', idx === activeIndex));
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('#customer-search-wrap')) {
            suggestions.classList.add('hidden');
        }
    });

    const questions = <?= json_encode($questions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const branches = <?= json_encode($branches, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const startBtn = document.getElementById('startBtn');
    const nextBtn = document.getElementById('nextBtn');
    const saveBtn = document.getElementById('saveBtn');
    const runner = document.getElementById('questionRunner');
    const responsesJsonInput = document.getElementById('responses_json');
    const form = document.getElementById('questionnaireForm');

    let currentQuestionId = null;
    let askedQuestionIds = [];
    let answers = {};

    const questionOrder = questions.map((q) => q.id);
    const questionById = Object.fromEntries(questions.map((q) => [q.id, q]));

    const renderCurrentQuestion = () => {
        const question = questionById[currentQuestionId];
        if (!question) {
            runner.innerHTML = '<p class="text-sm text-zinc-400">No question available.</p>';
            return;
        }

        const existingAnswer = answers[String(currentQuestionId)] || '';
        const options = Array.isArray(question.options) ? question.options : [];

        if (question.type === 'date') {
            runner.innerHTML = `
                <p class="text-white font-semibold mb-3">${question.text}</p>
                <input type="date" id="current_answer" class="input-base" value="${existingAnswer}">
            `;
        } else if (question.type === 'single_choice' && options.length > 0) {
            const optionsMarkup = options.map((opt) => {
                const selected = existingAnswer === opt ? 'selected' : '';
                return `<option value="${opt}" ${selected}>${opt}</option>`;
            }).join('');

            runner.innerHTML = `
                <p class="text-white font-semibold mb-3">${question.text}</p>
                <select id="current_answer" class="input-base">
                    <option value="">Select an answer</option>
                    ${optionsMarkup}
                </select>
            `;
        } else {
            runner.innerHTML = `
                <p class="text-white font-semibold mb-3">${question.text}</p>
                <textarea id="current_answer" rows="4" class="input-base" placeholder="Enter response">${existingAnswer}</textarea>
            `;
        }
    };

    const resolveNextQuestionId = (questionId, answerValue) => {
        const branchSet = branches[String(questionId)] || branches[questionId] || [];
        const branch = branchSet.find((item) => item.answer_value === answerValue);
        if (branch) {
            if (Number(branch.is_terminal) === 1) {
                return null;
            }
            if (branch.next_question_id) {
                return Number(branch.next_question_id);
            }
        }

        const idx = questionOrder.indexOf(questionId);
        if (idx < 0 || idx >= questionOrder.length - 1) {
            return null;
        }
        return questionOrder[idx + 1];
    };

    const completeQuestionnaire = () => {
        startBtn.classList.add('hidden');
        nextBtn.classList.add('hidden');
        saveBtn.classList.remove('hidden');
        runner.innerHTML = '<p class="text-emerald-300 text-sm">Questionnaire complete. Review and save the response.</p>';
        responsesJsonInput.value = JSON.stringify({
            answers,
            asked_question_ids: askedQuestionIds,
            completed_at: new Date().toISOString(),
        });
    };

    const beginQuestionnaire = () => {
        if (!customerIdInput.value) {
            alert('Please select a customer before starting.');
            return;
        }
        if (questions.length === 0) {
            return;
        }

        answers = {};
        askedQuestionIds = [];
        currentQuestionId = questionOrder[0];

        startBtn.classList.add('hidden');
        nextBtn.classList.remove('hidden');
        saveBtn.classList.add('hidden');
        renderCurrentQuestion();
    };

    startBtn.addEventListener('click', beginQuestionnaire);

    nextBtn.addEventListener('click', () => {
        const input = document.getElementById('current_answer');
        if (!input) {
            return;
        }

        const value = String(input.value || '').trim();
        if (value === '') {
            alert('Please provide an answer before continuing.');
            return;
        }

        answers[String(currentQuestionId)] = value;
        if (!askedQuestionIds.includes(currentQuestionId)) {
            askedQuestionIds.push(currentQuestionId);
        }

        const nextQuestionId = resolveNextQuestionId(currentQuestionId, value);
        if (!nextQuestionId) {
            completeQuestionnaire();
            return;
        }

        currentQuestionId = nextQuestionId;
        renderCurrentQuestion();
    });

    form.addEventListener('submit', (event) => {
        if (!customerIdInput.value) {
            event.preventDefault();
            alert('Please select a customer before saving.');
            return;
        }
        if (!responsesJsonInput.value) {
            event.preventDefault();
            alert('Please complete the questionnaire first.');
            return;
        }
        saveCustomerIdInput.value = customerIdInput.value;
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
