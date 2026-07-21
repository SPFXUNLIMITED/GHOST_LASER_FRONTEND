<?php
session_start();

// Admin-only: redirect to login if not authenticated
if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';

// Load Google Maps API key for client-side geocoding proxy
function loadGoogleMapsApiKey(): string {
    static $key = null;
    if ($key !== null) return $key;
    // Check OS environment first
    $envKey = getenv('GOOGLE_MAPS_API_KEY');
    if ($envKey !== false && trim($envKey) !== '') {
        $key = trim($envKey);
        return $key;
    }
    // Fall back to api/.env file (same source used by api/book-repair-api.php)
    $dotenvPath = __DIR__ . '/api/.env';
    if (is_file($dotenvPath) && is_readable($dotenvPath)) {
        $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (!str_starts_with($line, 'GOOGLE_MAPS_API_KEY=')) continue;
                $val = substr($line, strlen('GOOGLE_MAPS_API_KEY='));
                $val = trim($val);
                if (strlen($val) >= 2) {
                    if ($val[0] === '"' && $val[-1] === '"') $val = substr($val, 1, -1);
                    elseif ($val[0] === "'" && $val[-1] === "'") $val = substr($val, 1, -1);
                }
                $key = $val;
                return $key;
            }
        }
    }
    $key = '';
    return $key;
}
$googleMapsApiKey = loadGoogleMapsApiKey();

try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS machine_brand VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS machine_model VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS machine_watts VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS machine_age VARCHAR(50) DEFAULT NULL");
} catch (Throwable $e) {
    // Ignore schema sync errors so booking remains available.
}

if (empty($_SESSION['book_internal_customer_search_csrf'])) {
    $_SESSION['book_internal_customer_search_csrf'] = bin2hex(random_bytes(32));
}
$customerSearchCsrfToken = (string) $_SESSION['book_internal_customer_search_csrf'];

// ── AJAX: Live customer search ─────────────────────────────────────────────
$isCustomerSearchRequest = (
    (isset($_GET['action']) && $_GET['action'] === 'customer_search')
    || (isset($_GET['customer_search']) && (string) $_GET['customer_search'] === '1')
);
if ($isCustomerSearchRequest) {
    header('Content-Type: application/json');

    $csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($csrfHeader === '' || !hash_equals($customerSearchCsrfToken, $csrfHeader)) {
        http_response_code(403);
        echo json_encode(['results' => [], 'error' => 'Invalid CSRF token.']);
        exit;
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    $rows = [];
    try {
        $like = '%' . $q . '%';
        $searchColumns = getCustomerSearchSelectColumns($pdo);
        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', $searchColumns) . '
             FROM customers
             WHERE first_name LIKE :first_name_q
                OR last_name LIKE :last_name_q
                OR company LIKE :company_q
                OR email LIKE :email_q
                OR phone LIKE :phone_q
             ORDER BY last_name, first_name
             LIMIT 8'
        );
        $stmt->execute([
            ':first_name_q' => $like,
            ':last_name_q'  => $like,
            ':company_q'    => $like,
            ':email_q'      => $like,
            ':phone_q'      => $like,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['results' => [], 'error' => 'Customer search failed.']);
        exit;
    }

    $results = [];
    foreach ($rows as $row) {
        $customerName = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
        $results[] = [
            'id'            => (int) $row['id'],
            'customer_name' => $customerName,
            'company_name'  => (string) ($row['company'] ?? ''),
            'phone'         => (string) ($row['phone'] ?? ''),
            'email'         => (string) ($row['email'] ?? ''),
            'address'       => (string) ($row['address'] ?? ''),
            'city'          => (string) ($row['city'] ?? ''),
            'state'         => strtoupper((string) ($row['state'] ?? '')),
            'zip'           => (string) ($row['zip'] ?? ''),
            'machine_brand' => (string) ($row['machine_brand'] ?? ''),
            'machine_model' => (string) ($row['machine_model'] ?? ''),
            'machine_watts' => (string) ($row['machine_watts'] ?? ''),
            'machine_age'   => (string) ($row['machine_age'] ?? ''),
        ];
    }
    echo json_encode(['results' => $results]);
    exit;
}

// ── Helpers ────────────────────────────────────────────────────────────────
function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalizeUsPhone($value) {
    $digits = preg_replace('/\D+/', '', (string) $value);
    if (strlen($digits) === 11 && strpos($digits, '1') === 0) {
        $digits = substr($digits, 1);
    }
    return strlen($digits) === 10 ? $digits : null;
}

function formatUsPhoneDisplay($value) {
    $digits = preg_replace('/\D+/', '', (string) $value);
    if (strlen($digits) === 11 && strpos($digits, '1') === 0) {
        $digits = substr($digits, 1);
    }
    $digits = substr($digits, 0, 10);
    if ($digits === '') return '';
    if (strlen($digits) < 4) return '(' . $digits;
    if (strlen($digits) < 7) return sprintf('(%s) %s', substr($digits, 0, 3), substr($digits, 3));
    return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
}

function formatUsPhoneE164($digits) {
    return $digits === null ? null : '+1' . $digits;
}

function getCustomerSearchSelectColumns(PDO $pdo): array {
    $baseColumns = ['id', 'first_name', 'last_name', 'company', 'email', 'phone', 'address', 'city', 'state', 'zip'];
    $optionalColumns = ['machine_brand', 'machine_model', 'machine_watts', 'machine_age'];

    try {
        $availableColumns = $pdo->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($availableColumns) || $availableColumns === []) {
            return $baseColumns;
        }

        $availableLookup = array_fill_keys($availableColumns, true);
        foreach ($optionalColumns as $column) {
            if (isset($availableLookup[$column])) {
                $baseColumns[] = $column;
            }
        }
    } catch (Throwable $e) {
        // Fall back to the guaranteed customer fields when schema inspection is unavailable.
    }

    return $baseColumns;
}

// ── Data ───────────────────────────────────────────────────────────────────
function fetchServicesForBooking(PDO $pdo): array {
    return $pdo->query(
        "SELECT id, service_name, base_price FROM services ORDER BY service_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

$_dbServices = fetchServicesForBooking($pdo);
$serviceLabels = [];
$serviceBasePrices = [];
foreach ($_dbServices as $_svc) {
    $_key = (string) $_svc['id'];
    $serviceLabels[$_key] = $_svc['service_name'];
    $serviceBasePrices[$_key] = (float) $_svc['base_price'];
}
$otherServiceId = '';
foreach ($_dbServices as $_svc) {
    if (strtolower(trim($_svc['service_name'])) === 'other') {
        $otherServiceId = (string) $_svc['id'];
        break;
    }
}
unset($_dbServices, $_svc, $_key);
$speedOptions = [
    'standard'  => ['label' => 'Standard',  'multiplier' => 1.00],
    'rush'      => ['label' => 'VIP',        'multiplier' => 1.35],
    'emergency' => ['label' => 'Emergency', 'multiplier' => 1.75],
];

$step = isset($_GET['step']) ? (int) $_GET['step'] : 1;
if (!in_array($step, [1, 2, 3], true)) {
    $step = 1;
}
$errors    = [];
$phoneError = '';

// ── Step 1 POST: validate & advance ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['form_step'] ?? '') === '1')) {
    $services      = array_values(array_intersect(array_keys($serviceLabels), (array) ($_POST['services'] ?? [])));
    $otherService  = trim((string) ($_POST['other_service'] ?? ''));
    $normalizedPhone = normalizeUsPhone($_POST['phone'] ?? '');

    if ($otherServiceId !== '' && in_array($otherServiceId, $services, true) && $otherService === '') {
        $errors[] = 'Please describe the "Other" service.';
    }
    if (count($services) === 0) {
        $errors[] = 'Please select at least one service.';
    }

    $required = ['first_name', 'last_name', 'phone', 'email', 'machine_brand', 'machine_model', 'address', 'city', 'state', 'zip', 'problem'];
    foreach ($required as $field) {
        if (trim((string) ($_POST[$field] ?? '')) === '') {
            $errors[] = 'Please complete all required fields.';
            break;
        }
    }
    if (!$errors && $normalizedPhone === null) {
        $phoneError = 'Please enter a valid 10-digit US phone number.';
        $errors[] = $phoneError;
    }

    if (!$errors) {
        $_SESSION['book_internal_repair'] = [
            'first_name'   => trim((string) ($_POST['first_name'] ?? '')),
            'last_name'    => trim((string) ($_POST['last_name'] ?? '')),
            'customer_id'  => trim((string) ($_POST['customer_id'] ?? '')),
            'company_name' => trim((string) ($_POST['company_name'] ?? '')),
            'phone'        => formatUsPhoneDisplay($normalizedPhone),
            'phone_e164'   => formatUsPhoneE164($normalizedPhone),
            'email'        => trim((string) ($_POST['email'] ?? '')),
            'machine_brand'=> trim((string) ($_POST['machine_brand'] ?? '')),
            'machine_model'=> trim((string) ($_POST['machine_model'] ?? '')),
            'watts'        => trim((string) ($_POST['watts'] ?? '')),
            'age'          => trim((string) ($_POST['age'] ?? '')),
            'address'      => trim((string) ($_POST['address'] ?? '')),
            'city'         => trim((string) ($_POST['city'] ?? '')),
            'state'        => strtoupper(trim((string) ($_POST['state'] ?? ''))),
            'zip'          => trim((string) ($_POST['zip'] ?? '')),
            'latitude'     => trim((string) ($_POST['latitude'] ?? '')),
            'longitude'    => trim((string) ($_POST['longitude'] ?? '')),
            'problem'      => trim((string) ($_POST['problem'] ?? '')),
            'services'     => $services,
            'other_service'=> $otherService,
        ];
        header('Location: book_internal.php?step=2');
        exit;
    }
}

$booking = $_SESSION['book_internal_repair'] ?? null;
if ($step === 3) {
    unset($_SESSION['book_internal_repair']);
    $booking = null;
}
if ($step === 2 && !$booking) {
    header('Location: book_internal.php?step=1');
    exit;
}

$stepTwoPayload = null;
if ($step === 2 && $booking) {
    $stepTwoPayload = [
        'first_name'   => (string) ($booking['first_name']   ?? ''),
        'last_name'    => (string) ($booking['last_name']    ?? ''),
        'customer_id'  => (string) ($booking['customer_id']  ?? ''),
        'company_name' => (string) ($booking['company_name'] ?? ''),
        'phone'        => (string) ($booking['phone']        ?? ''),
        'phone_e164'   => (string) ($booking['phone_e164']   ?? ''),
        'email'        => (string) ($booking['email']        ?? ''),
        'machine_brand'=> (string) ($booking['machine_brand']?? ''),
        'machine_model'=> (string) ($booking['machine_model']?? ''),
        'watts'        => (string) ($booking['watts']        ?? ''),
        'age'          => (string) ($booking['age']          ?? ''),
        'address'      => (string) ($booking['address']      ?? ''),
        'city'         => (string) ($booking['city']         ?? ''),
        'state'        => (string) ($booking['state']        ?? ''),
        'zip'          => (string) ($booking['zip']          ?? ''),
        'latitude'     => (string) ($booking['latitude']     ?? ''),
        'longitude'    => (string) ($booking['longitude']    ?? ''),
        'problem'      => (string) ($booking['problem']      ?? ''),
        'password'     => '',
        'confirm_password' => '',
        'services'     => array_values(array_intersect(array_keys($serviceLabels), (array) ($booking['services'] ?? []))),
        'other_service'=> (string) ($booking['other_service']?? ''),
        'service_speed'=> isset($speedOptions[$booking['service_speed'] ?? '']) ? (string) $booking['service_speed'] : 'standard',
    ];
}

// ── Page setup ─────────────────────────────────────────────────────────────
$pageTitle       = 'Internal Booking | Ghost Laser';
$pageDescription = 'Internal booking form for Ghost Laser staff.';
$extraHead = <<<'HTML'
<style>
    .glow-cyan { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
    .glow-box { box-shadow: 0 0 0 1px rgba(6,182,212,0.2), 0 0 40px rgba(6,182,212,0.05); }
    .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
    .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
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
    .input-base::placeholder { color: rgb(82,82,91); }
    .input-base:focus { border-color: #06b6d4; box-shadow: 0 0 0 1px rgba(6,182,212,0.5); }
    .input-base.input-invalid { border-color: rgba(248,113,113,.95) !important; box-shadow: 0 0 0 1px rgba(248,113,113,.35) !important; }
    .field-error { margin-top: .5rem; color: rgb(248 113 113); font-size: .75rem; line-height: 1rem; }
    .choice-card input { display: none; }
    .choice-card label { display: flex; align-items: center; gap: .6rem; padding: .7rem 1rem; border: 1px solid rgb(63,63,70); border-radius: .5rem; background: rgba(39,39,42,.6); cursor: pointer; transition: border-color 0.15s, background 0.15s; }
    .choice-card input:checked + label { border-color: #06b6d4; background: rgba(6,182,212,.08); color: #22d3ee; }
    .choice-card label:hover { border-color: rgb(113,113,122); }
    .speed-option { transition: border-color 0.15s, background 0.15s; }
    .speed-option:has(input[type="radio"]:checked) { border-color: #06b6d4; background: rgba(6,182,212,0.08); color: #22d3ee; }
    .speed-option:hover { border-color: rgb(113,113,122); }
    .confirmation-grid {
        background-image:
            linear-gradient(rgba(34,211,238,0.08) 1px, transparent 1px),
            linear-gradient(90deg, rgba(34,211,238,0.08) 1px, transparent 1px);
        background-size: 42px 42px;
        background-position: center;
    }
    .confirmation-orb {
        position: absolute;
        border-radius: 9999px;
        filter: blur(70px);
        pointer-events: none;
    }
    /* Customer search dropdown */
    #customerSuggestions {
        position: absolute;
        z-index: 50;
        left: 0; right: 0;
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
        transition: background 0.1s;
    }
    #customerSuggestions li:last-child { border-bottom: none; }
    #customerSuggestions li:hover, #customerSuggestions li.active { background: rgba(6,182,212,0.12); color: #22d3ee; }
    #customerSuggestions li .result-name { font-weight: 600; color: #f4f4f5; }
    #customerSuggestions li .result-meta { color: #71717a; margin-top: 1px; }
</style>
HTML;

require_once __DIR__ . '/templates/header.php';
?>
<!-- PAGE HEADER -->
<section class="pt-32 pb-12 lg:pb-16 bg-zinc-950">
    <div class="max-w-3xl mx-auto px-6 lg:px-8 text-center">
        <div class="inline-flex items-center gap-2 bg-zinc-900 border border-amber-500/30 rounded-full px-4 py-1.5 mb-8">
            <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
            <span class="text-xs text-amber-400 font-medium tracking-wider uppercase">Internal Use Only</span>
        </div>
        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight mb-5">
            Internal Booking &mdash; <span class="text-cyan-400 glow-cyan">Book a Technician</span>
        </h1>
        <p class="text-zinc-400 text-lg leading-relaxed max-w-xl mx-auto">
            Search for an existing customer to auto-fill their details, or manually enter a new customer&rsquo;s information to create a service request.
        </p>
    </div>
</section>

<section class="pb-24 lg:pb-32 bg-zinc-950">
    <div class="max-w-3xl mx-auto px-6">

        <?php if ($errors): ?>
            <div class="mb-6 rounded-xl border border-red-500/30 bg-red-950/40 px-4 py-3 text-sm text-red-200">
                <?= h($errors[0]) ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
        <!-- ── STEP 1: Booking form ── -->
        <form method="post" action="book_internal.php?step=1" class="space-y-6 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 sm:p-8 glow-box">
            <input type="hidden" name="form_step" value="1">
            <input type="hidden" id="customer_id" name="customer_id" value="<?= h($_POST['customer_id'] ?? '') ?>">

            <!-- Customer Search -->
            <div>
                <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-amber-400">Customer Search</p>
                <div class="relative" id="cust-search-wrap">
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-zinc-500">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z"/>
                            </svg>
                        </span>
                        <input
                            id="customer_name"
                            type="text"
                            autocomplete="off"
                            placeholder="Search by name, company, phone, or email&hellip;"
                            class="input-base pl-9"
                        >
                    </div>
                    <ul id="customerSuggestions" class="hidden" role="listbox" aria-label="Customer search results"></ul>
                </div>
                <p class="mt-2 text-xs text-zinc-500">Select a customer to auto-fill their contact details. Leave blank to enter a new customer manually.</p>
                <div id="cust-selected-banner" class="hidden mt-3 flex items-center justify-between gap-3 rounded-lg border border-emerald-500/30 bg-emerald-950/40 px-4 py-2.5 text-sm">
                    <span class="text-emerald-200" id="cust-selected-label"></span>
                    <button type="button" id="cust-clear-btn" class="text-xs text-zinc-400 hover:text-white transition-colors underline flex-shrink-0">Clear</button>
                </div>
            </div>

            <div class="border-t border-zinc-800"></div>

            <!-- Contact Information -->
            <div>
                <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Contact Information</p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <input class="input-base" id="first_name" name="first_name" placeholder="First Name *" required value="<?= h($_POST['first_name'] ?? '') ?>">
                    </div>
                    <div>
                        <input class="input-base" id="last_name" name="last_name" placeholder="Last Name *" required value="<?= h($_POST['last_name'] ?? '') ?>">
                    </div>
                    <div class="sm:col-span-2">
                        <input class="input-base" id="company_name" name="company_name" placeholder="Company Name (optional)" value="<?= h($_POST['company_name'] ?? '') ?>">
                    </div>
                    <div>
                        <input class="input-base<?= $phoneError !== '' ? ' input-invalid' : '' ?>" type="tel" inputmode="tel" id="phone" name="phone" placeholder="Phone Number *" required value="<?= h(formatUsPhoneDisplay($_POST['phone'] ?? '')) ?>" aria-describedby="phone-error" aria-invalid="<?= $phoneError !== '' ? 'true' : 'false' ?>">
                        <p id="phone-error" class="field-error<?= $phoneError === '' ? ' hidden' : '' ?>"><?= h($phoneError) ?></p>
                    </div>
                    <div>
                        <input class="input-base" type="email" id="email" name="email" placeholder="Email Address *" required value="<?= h($_POST['email'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="border-t border-zinc-800"></div>

            <!-- Service Address -->
            <div>
                <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Service Address</p>
                <div class="space-y-5">
                    <input class="input-base" id="address" name="address" placeholder="Address *" required value="<?= h($_POST['address'] ?? '') ?>">
                    <div class="grid gap-5 sm:grid-cols-3">
                        <input class="input-base" id="city" name="city" placeholder="City *" required value="<?= h($_POST['city'] ?? '') ?>">
                        <input class="input-base uppercase" id="state" name="state" maxlength="2" placeholder="State *" required value="<?= h($_POST['state'] ?? '') ?>">
                        <input class="input-base" id="zip" name="zip" placeholder="ZIP Code *" required value="<?= h($_POST['zip'] ?? '') ?>">
                    </div>
                    <input type="hidden" id="latitude" name="latitude" value="<?= h($_POST['latitude'] ?? '') ?>">
                    <input type="hidden" id="longitude" name="longitude" value="<?= h($_POST['longitude'] ?? '') ?>">
                </div>
            </div>

            <div class="border-t border-zinc-800"></div>

            <!-- Machine Details -->
            <div>
                <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Machine Details</p>
                <div class="grid gap-5 sm:grid-cols-2">
                <input class="input-base" id="machine_brand" name="machine_brand" placeholder="Brand *" required value="<?= h($_POST['machine_brand'] ?? '') ?>">
                <input class="input-base" id="machine_model" name="machine_model" placeholder="Model *" required value="<?= h($_POST['machine_model'] ?? '') ?>">
                <input class="input-base" type="number" min="1" id="watts" name="watts" placeholder="Wattage (optional)" value="<?= h($_POST['watts'] ?? '') ?>">
                <input class="input-base" id="age" name="age" placeholder="Machine Age (optional)" value="<?= h($_POST['age'] ?? '') ?>">
                </div>
            </div>

            <div class="border-t border-zinc-800"></div>

            <!-- Services Needed -->
            <div>
                <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Services Needed</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <?php foreach ($serviceLabels as $serviceKey => $serviceLabel): ?>
                        <div class="choice-card">
                            <input
                                type="checkbox"
                                id="service-<?= h($serviceKey) ?>"
                                name="services[]"
                                value="<?= h($serviceKey) ?>"
                                <?= in_array($serviceKey, (array) ($_POST['services'] ?? []), true) ? 'checked' : '' ?>
                            >
                            <label for="service-<?= h($serviceKey) ?>"><?= h($serviceLabel) ?> &ndash; $<?= number_format($serviceBasePrices[$serviceKey], 2) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="other-service-wrap" class="mt-4 <?= ($otherServiceId !== '' && in_array($otherServiceId, (array) ($_POST['services'] ?? []), true)) ? '' : 'hidden' ?>">
                    <input class="input-base" id="other-service-input" name="other_service" placeholder="Describe other service" value="<?= h($_POST['other_service'] ?? '') ?>">
                </div>
            </div>

            <!-- Problem Description -->
            <div>
                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-zinc-400" for="problem">Problem Description *</label>
                <textarea class="input-base resize-none" id="problem" name="problem" rows="4" required><?= h($_POST['problem'] ?? '') ?></textarea>
            </div>

            <div class="border-t border-zinc-800"></div>

            <button type="submit" class="w-full rounded-lg bg-cyan-500 py-3.5 text-sm font-bold text-zinc-950 hover:bg-cyan-400 btn-glow transition-all flex items-center justify-center gap-2">
                Continue to Service Speed &rarr;
            </button>
        </form>

        <?php elseif ($step === 2): ?>
        <!-- ── STEP 2: Speed selection ── -->
        <?php
            $selectedServices = $booking['services'];
            $currentSpeed     = $booking['service_speed'] ?? 'standard';
            $baseTotal        = 0;
            foreach ($selectedServices as $serviceKey) {
                $baseTotal += $serviceBasePrices[$serviceKey] ?? 0;
            }
            $total = round($baseTotal * ($speedOptions[$currentSpeed]['multiplier'] ?? 1), 2);
        ?>
        <div class="space-y-6 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 sm:p-8 glow-box">
            <div id="step-2-success" class="hidden relative overflow-hidden rounded-[2rem] border border-cyan-400/20 bg-zinc-950 px-6 py-8 shadow-[0_0_0_1px_rgba(34,211,238,0.08),0_0_70px_rgba(8,145,178,0.12)] sm:px-8 sm:py-10">
                <div class="confirmation-orb -top-16 right-0 h-40 w-40 bg-cyan-500/20"></div>
                <div class="confirmation-orb bottom-0 left-0 h-36 w-36 bg-emerald-500/10"></div>
                <div class="confirmation-grid absolute inset-0 opacity-40"></div>
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent"></div>
                <div class="relative">
                    <div class="flex flex-col items-center text-center">
                        <span class="inline-flex items-center gap-2 rounded-full border border-cyan-400/20 bg-cyan-400/10 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-[0.28em] text-cyan-200">
                            <span class="h-2 w-2 rounded-full bg-cyan-300 shadow-[0_0_14px_rgba(103,232,249,0.9)]"></span>
                            Transmission Received
                        </span>
                        <div class="mt-6 flex items-center gap-4 rounded-2xl border border-cyan-400/15 bg-zinc-900/80 px-5 py-4 shadow-[0_0_35px_rgba(6,182,212,0.12)]">
                            <span class="flex h-16 w-16 items-center justify-center rounded-2xl border border-cyan-400/20 bg-cyan-400/10">
                                <img src="<?= h(asset('ghost-logo2-32x32.png')) ?>" alt="Ghost Laser logo" class="h-10 w-10">
                            </span>
                            <div class="text-left">
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-zinc-500">Ghost Laser</p>
                                <p class="text-lg font-black tracking-[0.18em] text-white">REPAIR DESK</p>
                            </div>
                        </div>
                        <h2 id="step-2-success-heading" class="mt-8 text-3xl font-black tracking-tight text-white sm:text-4xl">Booking Created</h2>
                        <p id="step-2-success-text" class="mt-3 max-w-2xl text-sm leading-7 text-zinc-300 sm:text-base">The service request has been submitted and will appear in the technician schedule.</p>
                    </div>

                    <div class="mt-8 grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                        <div class="rounded-2xl border border-zinc-800 bg-zinc-900/80 p-5">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="text-[11px] font-semibold uppercase tracking-[0.24em] text-zinc-500">Chosen Priority</span>
                                <span id="step-2-success-priority" class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold tracking-wide"></span>
                            </div>
                            <p id="step-2-success-priority-text" class="mt-4 text-sm leading-7 text-zinc-300">The booking has been placed in the queue.</p>
                        </div>
                        <div class="rounded-2xl border border-zinc-800 bg-zinc-900/80 p-5">
                            <p id="step-2-success-dates-label" class="text-[11px] font-semibold uppercase tracking-[0.24em] text-zinc-500">Suggested Service Dates</p>
                            <ul id="step-2-success-dates" class="mt-4 space-y-3 text-sm text-zinc-100"></ul>
                        </div>
                    </div>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
                        <a href="book_internal.php?step=1" class="inline-flex items-center justify-center rounded-lg border border-zinc-700 px-5 py-3 text-sm font-semibold text-zinc-100 transition-all hover:border-zinc-500 hover:bg-zinc-900">Book Another Repair</a>
                        <a href="technician/schedule.php" class="inline-flex items-center justify-center rounded-lg bg-cyan-500 px-5 py-3 text-sm font-semibold text-zinc-950 transition-all hover:bg-cyan-400 btn-glow">Scheduling Dashboard</a>
                    </div>
                </div>
            </div>

            <div id="step-2-error" class="hidden rounded-xl border border-red-500/30 bg-red-950/40 px-4 py-4 text-sm text-red-200">
                <p id="step-2-error-heading" class="font-semibold text-red-300">Something went wrong</p>
                <p id="step-2-error-text" class="mt-1 text-red-100/80">Please check the details and try again.</p>
            </div>

            <div id="step-2-booking-shell">
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-cyan-400">Selected Services</p>
                    <ul class="space-y-2 text-sm text-zinc-200">
                        <?php foreach ($selectedServices as $serviceKey): ?>
                            <li class="flex items-center justify-between rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2">
                                <span><?= h($serviceLabels[$serviceKey] ?? $serviceKey) ?></span>
                                <span>$<?= number_format((float) ($serviceBasePrices[$serviceKey] ?? 0), 2) ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($otherServiceId !== '' && in_array($otherServiceId, $selectedServices, true) && !empty($booking['other_service'])): ?>
                            <li class="rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2 text-zinc-400">Other details: <?= h($booking['other_service']) ?></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <form id="step-2-booking-form" class="space-y-4 mt-4" novalidate>
                    <div>
                        <label class="flex items-center gap-1.5 text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide">
                            Phone Number
                        </label>
                        <p class="input-base opacity-70 cursor-not-allowed"><?= h($booking['phone'] ?? '') ?></p>
                    </div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">Choose Service Speed</p>
                    <?php foreach ($speedOptions as $speedKey => $speed): ?>
                        <?php $speedTotal = round($baseTotal * $speed['multiplier'], 2); ?>
                        <label class="speed-option flex items-center justify-between rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-3 cursor-pointer">
                            <span class="flex items-center gap-2">
                                <input type="radio" name="service_speed" value="<?= h($speedKey) ?>" <?= $currentSpeed === $speedKey ? 'checked' : '' ?>>
                                <span><?= h($speed['label']) ?></span>
                            </span>
                            <span class="text-cyan-300">$<?= number_format($speedTotal, 2) ?></span>
                        </label>
                    <?php endforeach; ?>

                    <div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
                        <label for="step-2-website">Website</label>
                        <input type="text" id="step-2-website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="rounded-lg border border-cyan-500/30 bg-cyan-950/30 px-4 py-3 text-sm">
                        <span class="text-zinc-300">Current Total:</span>
                        <span id="current-total" class="font-semibold text-cyan-300">$<?= number_format($total, 2) ?></span>
                    </div>

                    <div class="flex gap-3">
                        <a href="book_internal.php?step=1" class="flex-1 rounded-lg border border-zinc-700 px-4 py-3 text-center text-sm font-semibold text-zinc-200 hover:bg-zinc-800 transition-all">Start Over</a>
                        <button id="step-2-submit-btn" type="submit" class="flex-1 rounded-lg bg-cyan-500 px-4 py-3 text-sm font-bold text-zinc-950 hover:bg-cyan-400 btn-glow transition-all flex items-center justify-center gap-2">
                            <span id="step-2-submit-label">Submit Booking</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- ── STEP 3: Confirmation ── -->
        <div class="relative overflow-hidden rounded-[2rem] border border-cyan-400/20 bg-zinc-950 px-6 py-10 shadow-[0_0_0_1px_rgba(34,211,238,0.08),0_0_70px_rgba(8,145,178,0.12)] sm:px-8 sm:py-12">
            <div class="confirmation-orb -top-16 right-0 h-40 w-40 bg-cyan-500/20"></div>
            <div class="confirmation-orb bottom-0 left-0 h-36 w-36 bg-emerald-500/10"></div>
            <div class="confirmation-grid absolute inset-0 opacity-40"></div>
            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent"></div>
            <div class="relative flex flex-col items-center text-center">
                <span class="inline-flex items-center gap-2 rounded-full border border-cyan-400/20 bg-cyan-400/10 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-[0.28em] text-cyan-200">
                    <span class="h-2 w-2 rounded-full bg-cyan-300 shadow-[0_0_14px_rgba(103,232,249,0.9)]"></span>
                    Booking Confirmed
                </span>
                <div class="mt-6 flex items-center gap-4 rounded-2xl border border-cyan-400/15 bg-zinc-900/80 px-5 py-4 shadow-[0_0_35px_rgba(6,182,212,0.12)]">
                    <span class="flex h-16 w-16 items-center justify-center rounded-2xl border border-cyan-400/20 bg-cyan-400/10">
                        <img src="<?= h(asset('ghost-logo2-32x32.png')) ?>" alt="Ghost Laser logo" class="h-10 w-10">
                    </span>
                    <div class="text-left">
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-zinc-500">Ghost Laser</p>
                        <p class="text-lg font-black tracking-[0.18em] text-white">REPAIR DESK</p>
                    </div>
                </div>
                <h2 class="mt-8 text-3xl font-black tracking-tight text-white sm:text-4xl">Booking has been received</h2>
                <p class="mt-3 max-w-2xl text-sm leading-7 text-zinc-300 sm:text-base">The service request has been created and will appear in the technician schedule.</p>
                <div class="mt-8 flex w-full flex-col gap-3 sm:flex-row sm:justify-center">
                    <a href="book_internal.php?step=1" class="inline-flex items-center justify-center rounded-lg border border-zinc-700 px-5 py-3 text-sm font-semibold text-zinc-100 transition-all hover:border-zinc-500 hover:bg-zinc-900">Book Another Repair</a>
                    <a href="technician/schedule.php" class="inline-flex items-center justify-center rounded-lg bg-cyan-500 px-5 py-3 text-sm font-semibold text-zinc-950 transition-all hover:bg-cyan-400 btn-glow">Scheduling Dashboard</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<script>
    const customerSearchCsrfToken = <?= json_encode($customerSearchCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const googleMapsApiKey = <?= json_encode($googleMapsApiKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // ── Google Maps geocoding ────────────────────────────────────────────────
    const geocodeAddress = async () => {
        const addrEl  = document.getElementById('address');
        const cityEl  = document.getElementById('city');
        const stateEl = document.getElementById('state');
        const zipEl   = document.getElementById('zip');
        const latEl   = document.getElementById('latitude');
        const lngEl   = document.getElementById('longitude');

        if (!latEl || !lngEl || !googleMapsApiKey) return;

        const parts = [
            (addrEl?.value  || '').trim(),
            (cityEl?.value  || '').trim(),
            (stateEl?.value || '').trim(),
            (zipEl?.value   || '').trim(),
        ].filter(Boolean);

        // Need at least a street and one more component to be worth geocoding
        if (parts.length < 2) return;

        const fullAddress = parts.join(', ');
        try {
            const url = 'https://maps.googleapis.com/maps/api/geocode/json?' +
                new URLSearchParams({ address: fullAddress, key: googleMapsApiKey });
            const resp = await fetch(url);
            const data = await resp.json();
            if (data.status === 'OK' && data.results?.[0]?.geometry?.location) {
                latEl.value = data.results[0].geometry.location.lat;
                lngEl.value = data.results[0].geometry.location.lng;
            } else {
                latEl.value = '';
                lngEl.value = '';
            }
        } catch {
            // Non-fatal: coordinates will be geocoded server-side as a fallback
        }
    };

    // Trigger geocoding when any address field loses focus
    ['address', 'city', 'state', 'zip'].forEach(id => {
        document.getElementById(id)?.addEventListener('blur', geocodeAddress);
    });

    // ── Customer Search ──────────────────────────────────────────────────────
    (function () {
        const searchInput   = document.getElementById('customer_name');
        const resultsList   = document.getElementById('customerSuggestions');
        const selectedBanner = document.getElementById('cust-selected-banner');
        const selectedLabel  = document.getElementById('cust-selected-label');
        const clearBtn       = document.getElementById('cust-clear-btn');

        const fields = {
            customer_id: document.getElementById('customer_id'),
            company_name: document.getElementById('company_name'),
            first_name: document.getElementById('first_name'),
            last_name:  document.getElementById('last_name'),
            phone:      document.getElementById('phone'),
            email:      document.getElementById('email'),
            address:    document.getElementById('address'),
            city:       document.getElementById('city'),
            state:      document.getElementById('state'),
            zip:        document.getElementById('zip'),
            machine_brand: document.getElementById('machine_brand'),
            machine_model: document.getElementById('machine_model'),
            watts:      document.getElementById('watts'),
            age:        document.getElementById('age'),
        };

        if (!searchInput) return;

        let debounceTimer = null;
        let activeIndex   = -1;
        let currentResults = [];

        const clearSelection = () => {
            selectedBanner.classList.add('hidden');
            selectedLabel.textContent = '';
            if (fields.customer_id) {
                fields.customer_id.value = '';
            }
        };

        const fillCustomer = (customer) => {
            const fullName = (customer.customer_name || `${customer.first_name || ''} ${customer.last_name || ''}`).trim();
            let firstName = customer.first_name || '';
            let lastName = customer.last_name || '';
            if (!firstName && !lastName && fullName !== '') {
                const parts = fullName.split(/\s+/);
                firstName = parts.shift() || '';
                lastName = parts.join(' ');
            }
            const phoneFormatted = formatUsPhoneDisplay(customer.phone || '');
            if (fields.customer_id) fields.customer_id.value = customer.id || '';
            if (fields.first_name) fields.first_name.value = firstName;
            if (fields.last_name)  fields.last_name.value  = lastName;
            if (fields.company_name) fields.company_name.value = customer.company_name || '';
            if (fields.phone)      { fields.phone.value = phoneFormatted; syncPhoneValidationState(); }
            if (fields.email)      fields.email.value      = customer.email   || '';
            if (fields.address)    fields.address.value    = customer.address || '';
            if (fields.city)       fields.city.value       = customer.city    || '';
            if (fields.state)      fields.state.value      = (customer.state  || '').toUpperCase();
            if (fields.zip)        fields.zip.value        = customer.zip     || '';
            if (fields.machine_brand) fields.machine_brand.value = customer.machine_brand || '';
            if (fields.machine_model) fields.machine_model.value = customer.machine_model || '';
            if (fields.watts)      fields.watts.value      = customer.machine_watts || '';
            if (fields.age)        fields.age.value        = customer.machine_age || '';

            // Geocode the loaded customer's address
            geocodeAddress();

            selectedLabel.textContent = `Customer loaded: ${fullName || 'Unknown'}${customer.company_name ? ' · ' + customer.company_name : ''}`;
            selectedBanner.classList.remove('hidden');
            resultsList.classList.add('hidden');
            resultsList.innerHTML = '';
            searchInput.value = fullName;
            activeIndex = -1;
        };

        const renderResults = (results) => {
            resultsList.innerHTML = '';
            activeIndex = -1;
            if (results.length === 0) {
                resultsList.classList.add('hidden');
                return;
            }
            results.forEach((customer, idx) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.dataset.idx = idx;
                const companyMeta = customer.company_name ? `${escHtml(customer.company_name)} &nbsp;&middot;&nbsp; ` : '';
                li.innerHTML = `<div class="result-name">${escHtml(customer.customer_name || ((customer.first_name || '') + ' ' + (customer.last_name || '')))}</div>`
                    + `<div class="result-meta">${companyMeta}${customer.phone ? escHtml(customer.phone) + ' &nbsp;&middot;&nbsp; ' : ''}${escHtml(customer.email)}</div>`;
                li.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    fillCustomer(customer);
                });
                resultsList.appendChild(li);
            });
            resultsList.classList.remove('hidden');
        };

        const escHtml = (str) => {
            return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        };

        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            const q = searchInput.value.trim();
            if (q.length < 2) {
                resultsList.classList.add('hidden');
                resultsList.innerHTML = '';
                return;
            }
            debounceTimer = setTimeout(async () => {
                try {
                    const res = await fetch(`book_internal.php?customer_search=1&q=${encodeURIComponent(q)}`, {
                        headers: {
                            'X-CSRF-Token': customerSearchCsrfToken
                        }
                    });
                    const data = await res.json();
                    currentResults = data.results || [];
                    renderResults(currentResults);
                } catch (_) { /* silent */ }
            }, 180);
        });

        searchInput.addEventListener('keydown', (e) => {
            const items = resultsList.querySelectorAll('li');
            if (!items.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, items.length - 1);
                items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
            } else if (e.key === 'Enter' && activeIndex >= 0) {
                e.preventDefault();
                fillCustomer(currentResults[activeIndex]);
            } else if (e.key === 'Escape') {
                resultsList.classList.add('hidden');
            }
        });

        document.addEventListener('click', (e) => {
            if (!document.getElementById('cust-search-wrap')?.contains(e.target)) {
                resultsList.classList.add('hidden');
            }
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                clearSelection();
                Object.values(fields).forEach(el => { if (el) el.value = ''; });
                searchInput.focus();
            });
        }
    })();

    // ── Phone formatting ─────────────────────────────────────────────────────
    const formatUsPhoneDisplay = (value) => {
        let digits = String(value || '').replace(/\D/g, '');
        if (digits.startsWith('1') && digits.length > 10) digits = digits.slice(1);
        digits = digits.slice(0, 10);
        if (!digits) return '';
        if (digits.length < 4) return `(${digits}`;
        if (digits.length < 7) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
        return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
    };

    const normalizeUsPhone = (value) => {
        let digits = String(value || '').replace(/\D/g, '');
        if (digits.length === 11 && digits.startsWith('1')) digits = digits.slice(1);
        return digits.length === 10 ? digits : null;
    };

    const phoneInput    = document.getElementById('phone');
    const phoneErrorEl  = document.getElementById('phone-error');

    const syncPhoneValidationState = () => {
        if (!phoneInput || !phoneErrorEl) return true;
        const digits  = normalizeUsPhone(phoneInput.value);
        const hasValue = phoneInput.value.trim() !== '';
        const isValid  = !hasValue || digits !== null;
        phoneInput.classList.toggle('input-invalid', !isValid);
        phoneInput.setAttribute('aria-invalid', isValid ? 'false' : 'true');
        phoneErrorEl.textContent = isValid ? '' : 'Please enter a valid 10-digit US phone number.';
        phoneErrorEl.classList.toggle('hidden', isValid);
        return isValid;
    };

    if (phoneInput) {
        phoneInput.addEventListener('input', () => {
            const cursorAtEnd = phoneInput.selectionStart === phoneInput.value.length;
            phoneInput.value = formatUsPhoneDisplay(phoneInput.value);
            if (cursorAtEnd) phoneInput.setSelectionRange(phoneInput.value.length, phoneInput.value.length);
            syncPhoneValidationState();
        });
        phoneInput.addEventListener('blur', syncPhoneValidationState);
        phoneInput.value = formatUsPhoneDisplay(phoneInput.value);
        syncPhoneValidationState();
    }

    // ── Other-service toggle ─────────────────────────────────────────────────
    const otherServiceCheckbox = document.getElementById('service-<?= h($otherServiceId) ?>');
    const otherServiceWrap     = document.getElementById('other-service-wrap');
    const otherServiceInput    = document.getElementById('other-service-input');

    if (otherServiceCheckbox && otherServiceWrap && otherServiceInput) {
        const syncOtherVisibility = () => {
            const enabled = otherServiceCheckbox.checked;
            otherServiceWrap.classList.toggle('hidden', !enabled);
            otherServiceInput.required = enabled;
            if (!enabled) otherServiceInput.value = '';
        };
        otherServiceCheckbox.addEventListener('change', syncOtherVisibility);
        syncOtherVisibility();
    }

    // ── Step 2: speed selection + AJAX submit ────────────────────────────────
    const stepTwoForm    = document.getElementById('step-2-booking-form');
    const bookingPayload = <?= $stepTwoPayload ? json_encode($stepTwoPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null' ?>;
    const serviceLabels    = <?= json_encode($serviceLabels,    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const serviceBasePrices = <?= json_encode($serviceBasePrices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const speedOptions     = <?= json_encode($speedOptions,     JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const speedPriorityMap = { standard: 'standard', rush: 'vip', emergency: 'emergency' };

    if (stepTwoForm && bookingPayload) {
        const totalEl       = document.getElementById('current-total');
        const submitBtn     = document.getElementById('step-2-submit-btn');
        const submitLabel   = document.getElementById('step-2-submit-label');
        const bookingShell  = document.getElementById('step-2-booking-shell');
        const successBox    = document.getElementById('step-2-success');
        const successHeading = document.getElementById('step-2-success-heading');
        const successText   = document.getElementById('step-2-success-text');
        const successPriority = document.getElementById('step-2-success-priority');
        const successPriorityText = document.getElementById('step-2-success-priority-text');
        const successDatesLabel = document.getElementById('step-2-success-dates-label');
        const successDates  = document.getElementById('step-2-success-dates');
        const errorBox      = document.getElementById('step-2-error');
        const errorHeading  = document.getElementById('step-2-error-heading');
        const errorText     = document.getElementById('step-2-error-text');
        const speedInputs   = stepTwoForm.querySelectorAll('input[name="service_speed"]');

        const priorityConfig = {
            standard: {
                label: 'Standard',
                badgeClasses: ['border-zinc-500/40', 'bg-zinc-500/15', 'text-zinc-100'],
                headline: 'Booking Created',
                message: 'The service request has been submitted. Standard bookings are typically scheduled within 3–5 business days.',
                detail: 'The request is queued under Standard priority.'
            },
            vip: {
                label: 'VIP',
                badgeClasses: ['border-cyan-400/40', 'bg-cyan-400/15', 'text-cyan-100'],
                headline: 'VIP Booking Created',
                message: 'The VIP booking has been submitted with expedited priority.',
                detail: 'The request has been elevated to VIP priority.'
            },
            emergency: {
                label: 'Emergency',
                badgeClasses: ['border-red-400/40', 'bg-red-500/15', 'text-red-100'],
                headline: 'Emergency Booking Created',
                message: 'The emergency request has been flagged for urgent attention.',
                detail: 'The booking is marked Emergency for immediate prioritization.'
            }
        };

        const updateDisplayedTotal = () => {
            const selectedSpeed = stepTwoForm.querySelector('input[name="service_speed"]:checked')?.value || 'standard';
            bookingPayload.service_speed = selectedSpeed;
            const baseTotal = (bookingPayload.services || []).reduce((sum, key) => sum + (serviceBasePrices[key] || 0), 0);
            bookingPayload.total_price = Number((baseTotal * (speedOptions[selectedSpeed]?.multiplier || 1)).toFixed(2));
            totalEl.textContent = `$${bookingPayload.total_price.toFixed(2)}`;
        };

        const formatDate = (dateStr) => {
            const [year, month, day] = dateStr.split('-').map(Number);
            const date = new Date(year, month - 1, day);
            const monthName = date.toLocaleString('en-US', { month: 'long' });
            const suffix = day === 1 || day === 21 || day === 31 ? 'st'
                : day === 2 || day === 22 ? 'nd'
                : day === 3 || day === 23 ? 'rd' : 'th';
            return `${monthName} ${day}${suffix}, ${year}`;
        };

        speedInputs.forEach(input => input.addEventListener('change', updateDisplayedTotal));
        updateDisplayedTotal();

        stepTwoForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            successBox.classList.add('hidden');
            errorBox.classList.add('hidden');
            submitBtn.disabled = true;
            submitLabel.textContent = 'Submitting\u2026';

            updateDisplayedTotal();

            const selectedSpeed      = bookingPayload.service_speed || 'standard';
            const selectedSpeedLabel = speedOptions[selectedSpeed]?.label || 'Standard';
            const selectedServices   = (bookingPayload.services || []).map(k => serviceLabels[k] || k);
            const problemSections    = [
                bookingPayload.problem,
                '--- Booking Summary ---',
                `Selected services: ${selectedServices.join(', ')}`,
                bookingPayload.other_service ? `Other service details: ${bookingPayload.other_service}` : null,
                `Service speed: ${selectedSpeedLabel}`,
                `Quoted total: $${bookingPayload.total_price.toFixed(2)}`,
                '--- Internal Booking (phone-in) ---',
            ].filter(Boolean);

            const requestBody = {
                first_name:    bookingPayload.first_name,
                last_name:     bookingPayload.last_name,
                phone:         bookingPayload.phone_e164 || `+1${(bookingPayload.phone || '').replace(/\D/g, '').slice(-10)}`,
                email:         bookingPayload.email,
                machine_brand: bookingPayload.machine_brand,
                machine_model: bookingPayload.machine_model,
                machine_watts: bookingPayload.watts  || null,
                machine_age:   bookingPayload.age    || null,
                address:       bookingPayload.address,
                city:          bookingPayload.city,
                state:         (bookingPayload.state || '').toUpperCase(),
                zip:           bookingPayload.zip,
                latitude:      bookingPayload.latitude  || null,
                longitude:     bookingPayload.longitude || null,
                problem:       problemSections.join('\n\n'),
			// No password fields for internal bookings
				password:      '',
				confirm_password: '',
                priority:      speedPriorityMap[selectedSpeed] || 'standard',
                website:       stepTwoForm.website.value.trim(),
                services:      bookingPayload.services || [],
                other_service: bookingPayload.other_service || '',
                service_speed: selectedSpeed,
                total_price:   bookingPayload.total_price,
            };

            try {
                const response = await fetch('/api/book-repair-api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestBody),
                });

                const json = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const apiMessage = (Array.isArray(json.errors) && json.errors.length > 0)
                        ? json.errors.join(' ')
                        : (json.message || 'Please check the details and try again.');
                    throw new Error(apiMessage);
                }

                // Show success panel
                const cfg = priorityConfig[speedPriorityMap[selectedSpeed]] || priorityConfig.standard;
                if (successHeading) successHeading.textContent = cfg.headline;
                if (successText)    successText.textContent    = cfg.message;

                if (successPriority) {
                    successPriority.className = 'inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold tracking-wide ' + cfg.badgeClasses.join(' ');
                    successPriority.textContent = cfg.label;
                }
                if (successPriorityText) successPriorityText.textContent = cfg.detail;

                if (successDates && json.suggested_dates?.length) {
                    successDatesLabel.classList.remove('hidden');
                    successDates.innerHTML = json.suggested_dates.map(d =>
                        `<li class="flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-cyan-400 flex-shrink-0"></span>${formatDate(d)}</li>`
                    ).join('');
                } else if (successDatesLabel) {
                    successDatesLabel.classList.add('hidden');
                }

                bookingShell.classList.add('hidden');
                successBox.classList.remove('hidden');
                successBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

            } catch (error) {
                const errorMessage = error instanceof Error ? error.message : 'Network error — please check your connection and try again.';
                if (errorHeading) errorHeading.textContent = errorMessage;
                errorText.textContent = '';
                errorBox.classList.remove('hidden');
                errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } finally {
                submitBtn.disabled = false;
                submitLabel.textContent = 'Submit Booking';
            }
        });
    }
</script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
