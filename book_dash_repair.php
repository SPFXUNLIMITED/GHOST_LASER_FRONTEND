<?php
session_start();

// Redirect already-logged-in customers to the booking form
if (!empty($_SESSION['customer_id'])) {
    header('Location: book-repair.php');
    exit;
}

require_once __DIR__ . '/project/db.php';

// Ensure password_hash column exists
try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) {
    // Column may already exist — ignore
}

$pageTitle = 'Book a Repair | Ghost Laser';
$pageDescription = 'Book a laser machine repair with Ghost Laser.';
$extraHead = <<<'HTML'
<style>
    .input-base{width:100%;background:rgba(39,39,42,0.6);border:1px solid rgb(63,63,70);color:white;border-radius:.5rem;padding:.625rem 1rem;font-size:.875rem;outline:none}
    .input-base:focus{border-color:#06b6d4;box-shadow:0 0 0 1px rgba(6,182,212,.5)}
    .choice-card input{display:none}
    .choice-card label{display:flex;align-items:center;gap:.6rem;padding:.7rem 1rem;border:1px solid rgb(63,63,70);border-radius:.5rem;background:rgba(39,39,42,.6);cursor:pointer}
    .choice-card input:checked+label{border-color:#06b6d4;background:rgba(6,182,212,.08);color:#22d3ee}
</style>
HTML;

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$serviceLabels = [
    'maintenance_alignment' => 'Maintenance & Alignment',
    'tube_change' => 'Tube Change',
    'diagnosis' => 'Diagnosis',
    'training' => 'Training',
    'other' => 'Other',
];
$serviceBasePrices = [
    'maintenance_alignment' => 150,
    'tube_change' => 320,
    'diagnosis' => 120,
    'training' => 180,
    'other' => 100,
];
$speedOptions = [
    'standard' => ['label' => 'Standard', 'multiplier' => 1.00],
    'rush' => ['label' => 'Rush', 'multiplier' => 1.35],
    'emergency' => ['label' => 'Emergency', 'multiplier' => 1.75],
];

$step = isset($_GET['step']) && $_GET['step'] === '2' ? 2 : 1;
$errors = [];
$success = '';
$emailAlreadyRegistered = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['form_step'] ?? '') === '1')) {
    $services = array_values(array_intersect(array_keys($serviceLabels), (array) ($_POST['services'] ?? [])));
    $otherService = trim((string) ($_POST['other_service'] ?? ''));
    if (in_array('other', $services, true) && $otherService === '') {
        $errors[] = 'Please describe the "Other" service.';
    }
    if (count($services) === 0) {
        $errors[] = 'Please select at least one service.';
    }

    $required = ['name', 'phone', 'email', 'machine_brand', 'machine_model', 'street', 'city', 'state', 'zip', 'problem'];
    foreach ($required as $field) {
        if (trim((string) ($_POST[$field] ?? '')) === '') {
            $errors[] = 'Please complete all required fields.';
            break;
        }
    }

    // Validate password fields
    $password        = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    if (!$errors) {
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }
    }

    // Check for existing email in customers table
    if (!$errors) {
        $emailCheck = trim((string) ($_POST['email'] ?? ''));
        $stmtCheck = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
        $stmtCheck->execute([$emailCheck]);
        if ($stmtCheck->fetch()) {
            $emailAlreadyRegistered = true;
        }
    }

    if (!$errors && !$emailAlreadyRegistered) {
        $_SESSION['book_dash_repair'] = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'machine_brand' => trim((string) ($_POST['machine_brand'] ?? '')),
            'machine_model' => trim((string) ($_POST['machine_model'] ?? '')),
            'watts' => trim((string) ($_POST['watts'] ?? '')),
            'age' => trim((string) ($_POST['age'] ?? '')),
            'street' => trim((string) ($_POST['street'] ?? '')),
            'city' => trim((string) ($_POST['city'] ?? '')),
            'state' => strtoupper(trim((string) ($_POST['state'] ?? ''))),
            'zip' => trim((string) ($_POST['zip'] ?? '')),
            'problem' => trim((string) ($_POST['problem'] ?? '')),
            'services' => $services,
            'other_service' => $otherService,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ];
        header('Location: book_dash_repair.php?step=2');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['form_step'] ?? '') === '2')) {
    $booking = $_SESSION['book_dash_repair'] ?? null;
    if (!$booking) {
        header('Location: book_dash_repair.php');
        exit;
    }
    $speed = (string) ($_POST['service_speed'] ?? 'standard');
    if (!isset($speedOptions[$speed])) {
        $speed = 'standard';
    }
    $baseTotal = 0;
    foreach ($booking['services'] as $serviceKey) {
        $baseTotal += $serviceBasePrices[$serviceKey] ?? 0;
    }
    $_SESSION['book_dash_repair']['service_speed'] = $speed;
    $_SESSION['book_dash_repair']['total_price'] = round($baseTotal * $speedOptions[$speed]['multiplier'], 2);
    $success = 'Service speed selected. Total has been updated.';
}

$booking = $_SESSION['book_dash_repair'] ?? null;
if ($step === 2 && !$booking) {
    header('Location: book_dash_repair.php');
    exit;
}

require_once __DIR__ . '/templates/header.php';
?>
<section class="pt-28 pb-10 bg-zinc-950">
    <div class="max-w-3xl mx-auto px-6 text-center">
        <h1 class="text-4xl font-black tracking-tight">Book Your <span class="text-cyan-400">Repair</span></h1>
    </div>
</section>

<section class="pb-20 bg-zinc-950">
    <div class="max-w-3xl mx-auto px-6">
        <?php if ($errors): ?>
            <div class="mb-6 rounded-xl border border-red-500/30 bg-red-950/40 px-4 py-3 text-sm text-red-200">
                <?= h($errors[0]) ?>
            </div>
        <?php endif; ?>
        <?php if ($emailAlreadyRegistered): ?>
            <div class="mb-6 rounded-xl border border-amber-500/30 bg-amber-950/40 px-4 py-4 text-sm text-amber-200">
                <p class="font-semibold mb-2">This email is already registered. Please log in instead.</p>
                <a href="customer-login.php?mode=login"
                   class="inline-flex items-center gap-1.5 bg-amber-500 hover:bg-amber-400 text-zinc-950 font-semibold text-xs px-3 py-1.5 rounded-md transition-colors">
                    Go to Login Page &rarr;
                </a>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="mb-6 rounded-xl border border-emerald-500/30 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">
                <?= h($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <form method="post" action="book_dash_repair.php" class="space-y-6 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6">
                <input type="hidden" name="form_step" value="1">
                <div>
                    <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Contact Information</p>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <input class="input-base" name="name" placeholder="Full Name *" required value="<?= h($_POST['name'] ?? '') ?>">
                        <input class="input-base" name="phone" placeholder="Phone Number *" required value="<?= h($_POST['phone'] ?? '') ?>">
                    </div>
                    <div class="mt-5">
                        <input class="input-base" type="email" name="email" placeholder="Email Address *" required value="<?= h($_POST['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="border-t border-zinc-800"></div>

                <div>
                    <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Machine Details</p>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <input class="input-base" name="machine_brand" placeholder="Brand *" required value="<?= h($_POST['machine_brand'] ?? '') ?>">
                        <input class="input-base" name="machine_model" placeholder="Model *" required value="<?= h($_POST['machine_model'] ?? '') ?>">
                        <input class="input-base" type="number" min="1" name="watts" placeholder="Wattage (optional)" value="<?= h($_POST['watts'] ?? '') ?>">
                        <input class="input-base" name="age" placeholder="Machine Age (optional)" value="<?= h($_POST['age'] ?? '') ?>">
                    </div>
                </div>

                <div class="border-t border-zinc-800"></div>

                <div>
                    <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Service Address</p>
                    <div class="space-y-5">
                        <input class="input-base" name="street" placeholder="Street Address *" required value="<?= h($_POST['street'] ?? '') ?>">
                        <div class="grid gap-5 sm:grid-cols-3">
                            <input class="input-base" name="city" placeholder="City *" required value="<?= h($_POST['city'] ?? '') ?>">
                            <input class="input-base uppercase" name="state" maxlength="2" placeholder="State *" required value="<?= h($_POST['state'] ?? '') ?>">
                            <input class="input-base" name="zip" placeholder="ZIP Code *" required value="<?= h($_POST['zip'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="border-t border-zinc-800"></div>

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
                                <label for="service-<?= h($serviceKey) ?>"><?= h($serviceLabel) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="other-service-wrap" class="mt-4 <?= in_array('other', (array) ($_POST['services'] ?? []), true) ? '' : 'hidden' ?>">
                        <input class="input-base" id="other-service-input" name="other_service" placeholder="Describe other service" value="<?= h($_POST['other_service'] ?? '') ?>">
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-zinc-400" for="problem">Problem Description *</label>
                    <textarea class="input-base resize-none" id="problem" name="problem" rows="4" required><?= h($_POST['problem'] ?? '') ?></textarea>
                </div>

                <div class="border-t border-zinc-800"></div>

                <div>
                    <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Create Account Password</p>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-400" for="password">Password *</label>
                            <input class="input-base" id="password" type="password" name="password" placeholder="Min. 8 characters" autocomplete="new-password" required minlength="8">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-400" for="password_confirm">Confirm Password *</label>
                            <input class="input-base" id="password_confirm" type="password" name="password_confirm" placeholder="Repeat password" autocomplete="new-password" required minlength="8">
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full rounded-lg bg-cyan-500 py-3.5 text-sm font-bold text-zinc-950 hover:bg-cyan-400">
                    Continue to Service Speed →
                </button>
            </form>
        <?php else: ?>
            <?php
                $selectedServices = $booking['services'];
                $currentSpeed = $booking['service_speed'] ?? 'standard';
                $baseTotal = 0;
                foreach ($selectedServices as $serviceKey) {
                    $baseTotal += $serviceBasePrices[$serviceKey] ?? 0;
                }
                $total = round($baseTotal * ($speedOptions[$currentSpeed]['multiplier'] ?? 1), 2);
            ?>
            <div class="space-y-6 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6">
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-cyan-400">Selected Services</p>
                    <ul class="space-y-2 text-sm text-zinc-200">
                        <?php foreach ($selectedServices as $serviceKey): ?>
                            <li class="flex items-center justify-between rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2">
                                <span><?= h($serviceLabels[$serviceKey] ?? $serviceKey) ?></span>
                                <span>$<?= number_format((float) ($serviceBasePrices[$serviceKey] ?? 0), 2) ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (in_array('other', $selectedServices, true) && !empty($booking['other_service'])): ?>
                            <li class="rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2 text-zinc-400">Other details: <?= h($booking['other_service']) ?></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <form method="post" action="book_dash_repair.php?step=2" class="space-y-4">
                    <input type="hidden" name="form_step" value="2">
                    <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">Choose Service Speed</p>
                    <?php foreach ($speedOptions as $speedKey => $speed): ?>
                        <?php $speedTotal = round($baseTotal * $speed['multiplier'], 2); ?>
                        <label class="flex items-center justify-between rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-3">
                            <span class="flex items-center gap-2">
                                <input type="radio" name="service_speed" value="<?= h($speedKey) ?>" <?= $currentSpeed === $speedKey ? 'checked' : '' ?>>
                                <span><?= h($speed['label']) ?></span>
                            </span>
                            <span class="text-cyan-300">$<?= number_format($speedTotal, 2) ?></span>
                        </label>
                    <?php endforeach; ?>

                    <div class="rounded-lg border border-cyan-500/30 bg-cyan-950/30 px-4 py-3 text-sm">
                        <span class="text-zinc-300">Current Total:</span>
                        <span class="font-semibold text-cyan-300">$<?= number_format($total, 2) ?></span>
                    </div>

                    <div class="flex gap-3">
                        <a href="book_dash_repair.php" class="flex-1 rounded-lg border border-zinc-700 px-4 py-3 text-center text-sm font-semibold text-zinc-200">Start Over</a>
                        <button type="submit" class="flex-1 rounded-lg bg-cyan-500 px-4 py-3 text-sm font-bold text-zinc-950 hover:bg-cyan-400">Update Total</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
    const otherServiceCheckbox = document.getElementById('service-other');
    const otherServiceWrap = document.getElementById('other-service-wrap');
    const otherServiceInput = document.getElementById('other-service-input');
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
</script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
