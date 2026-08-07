<?php
session_start();

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/travel-helper.php';

// Ensure password_hash column exists
try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) {
    // Column may already exist — ignore
}
try {
    $pdo->exec("ALTER TABLE service_requests MODIFY COLUMN request_status ENUM('abandoned','new','queued','completed','cancelled','deleted') NOT NULL DEFAULT 'new'");
} catch (Throwable $e) {
    // Non-fatal if request_status is already compatible or table is not available yet.
}

function loadGoogleMapsApiKey(): string {
    static $key = null;
    if ($key !== null) return $key;
    $envKey = getenv('GOOGLE_MAPS_API_KEY');
    if ($envKey !== false && trim($envKey) !== '') {
        $key = trim($envKey);
        return $key;
    }
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

// Handle "I have an account" login POST
$loginError = '';
$inlineLoginError = '';
$showInlineStepOneLogin = false;
$inlineLoginEmail = '';
$pendingStepOneSessionKey = 'book_a_repair_pending_booking';
$customerPrefillSessionKey = 'book_a_repair_customer_prefill';
$hasLoggedInCustomer = !empty($_SESSION['customer_id']) || !empty($_SESSION['customer_email']);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['step'])) {
    if (isset($_GET['mode']) && $_GET['mode'] === 'login') {
        header('Location: customer-login.php?step=1&mode=login');
        exit;
    }
    if (isset($_GET['type']) && $_GET['type'] === 'new') {
        header('Location: book_a_technician.php?step=2');
        exit;
    }
    if (!$hasLoggedInCustomer) {
        header('Location: customer-login.php?step=1');
        exit;
    }
    header('Location: book_a_technician.php?step=2');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_step'] ?? '') === 'login') {
    $loginContext = trim((string) ($_POST['login_context'] ?? ''));
    $loginEmail    = trim($_POST['login_email'] ?? '');
    $loginPassword = $_POST['login_password'] ?? '';

    if ($loginContext === 'step1_existing_account') {
        $showInlineStepOneLogin = true;
        $inlineLoginEmail = $loginEmail;
        $pendingStepOne = $_SESSION[$pendingStepOneSessionKey] ?? null;
        if (is_array($pendingStepOne)) {
            foreach ($pendingStepOne as $pendingKey => $pendingValue) {
                $_POST[$pendingKey] = $pendingValue;
            }
        }
    }

    if ($loginEmail === '' || $loginPassword === '') {
        if ($loginContext === 'step1_existing_account') {
            $inlineLoginError = 'Please enter your email and password.';
        } else {
            $loginError = 'Please enter your email and password.';
        }
    } else {
        $stmtLogin = $pdo->prepare(
            'SELECT id, first_name, last_name, email, password_hash, phone, address, city, state, zip FROM customers WHERE email = ? LIMIT 1'
        );
        $stmtLogin->execute([$loginEmail]);
        $customerLogin = $stmtLogin->fetch(PDO::FETCH_ASSOC);
        if ($customerLogin && !empty($customerLogin['password_hash']) && password_verify($loginPassword, $customerLogin['password_hash'])) {
            session_regenerate_id(true);
            $customerPhoneDigits = normalizeUsPhone($customerLogin['phone'] ?? '');
            $customerProfile = [
                'first_name' => trim((string) ($customerLogin['first_name'] ?? '')),
                'last_name' => trim((string) ($customerLogin['last_name'] ?? '')),
                'phone' => formatUsPhoneDisplay($customerPhoneDigits ?? ($customerLogin['phone'] ?? '')),
                'phone_e164' => formatUsPhoneE164($customerPhoneDigits),
                'email' => trim((string) ($customerLogin['email'] ?? '')),
                'address' => trim((string) ($customerLogin['address'] ?? '')),
                'city' => trim((string) ($customerLogin['city'] ?? '')),
                'state' => strtoupper(trim((string) ($customerLogin['state'] ?? ''))),
                'zip' => trim((string) ($customerLogin['zip'] ?? '')),
            ];
            $_SESSION['customer_id']         = (int) $customerLogin['id'];
            $_SESSION['customer_first_name'] = $customerProfile['first_name'];
            $_SESSION['customer_last_name']  = $customerProfile['last_name'];
            $_SESSION['customer_email']      = $customerProfile['email'];
            $_SESSION['customer_phone']      = $customerProfile['phone'];
            $_SESSION['customer_phone_e164'] = $customerProfile['phone_e164'];
            $_SESSION['customer_address']    = $customerProfile['address'];
            $_SESSION['customer_city']       = $customerProfile['city'];
            $_SESSION['customer_state']      = $customerProfile['state'];
            $_SESSION['customer_zip']        = $customerProfile['zip'];
            $_SESSION[$customerPrefillSessionKey] = $customerProfile;

            if ($loginContext === 'step1_existing_account') {
                $pendingStepOne = $_SESSION[$pendingStepOneSessionKey] ?? null;
                if (is_array($pendingStepOne)) {
                    foreach ($customerProfile as $profileKey => $profileValue) {
                        if (trim((string) ($pendingStepOne[$profileKey] ?? '')) === '' && $profileValue !== '') {
                            $pendingStepOne[$profileKey] = $profileValue;
                        }
                    }
                    $pendingStepOne['password']         = $loginPassword;
                    $pendingStepOne['confirm_password'] = $loginPassword;
                    $_SESSION['book_dash_repair']       = $pendingStepOne;
                }
                unset($_SESSION[$pendingStepOneSessionKey]);
                header('Location: book_a_technician.php?step=3');
            } else {
                // Returning-customer login from the dedicated login page (?mode=login).
                // Always send the customer to step 1 so they can fill in their
                // machine and service details. Only the step1_existing_account
                // path (inline login from step 1) has booking data in the session
                // already and is allowed to skip straight to step 2.
                header('Location: book_a_technician.php?step=2');
            }
            exit;
        } else {
            if ($loginContext === 'step1_existing_account') {
                $inlineLoginError = 'Invalid email or password. Please try again.';
            } else {
                $loginError = 'Invalid email or password. Please try again.';
            }
        }
    }
}

$pageTitle = 'Book a Technician | Ghost Laser';
$pageDescription = 'Book a laser technician with Ghost Laser.';
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
</style>
HTML;

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatPrice($value) {
    $amount = (float) $value;

    return number_format($amount, floor($amount) == $amount ? 0 : 2);
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

    if ($digits === '') {
        return '';
    }
    if (strlen($digits) < 4) {
        return '(' . $digits;
    }
    if (strlen($digits) < 7) {
        return sprintf('(%s) %s', substr($digits, 0, 3), substr($digits, 3));
    }

    return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
}

function formatUsPhoneE164($digits) {
    return $digits === null ? null : '+1' . $digits;
}

function resolveBookingCustomerId(PDO $pdo, array $bookingData): int {
    if (!empty($_SESSION['customer_id'])) {
        return (int) $_SESSION['customer_id'];
    }

    $email = trim((string) ($bookingData['email'] ?? ''));
    $phone = trim((string) ($bookingData['phone_e164'] ?? ($bookingData['phone'] ?? '')));
    $password = (string) ($bookingData['password'] ?? '');

    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT id, password_hash FROM customers WHERE email = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$email]);
        $existingByEmail = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = (int) ($existingByEmail['id'] ?? 0);
        if ($id > 0) {
            if (($existingByEmail['password_hash'] ?? '') === '' && $password !== '') {
                $updatePassword = $pdo->prepare("UPDATE customers SET password_hash = ? WHERE id = ? AND (password_hash IS NULL OR password_hash = '')");
                $updatePassword->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            }
            return $id;
        }
    }

    if ($phone !== '') {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$phone]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    }

    $hubspotContactId = 'service_api_' . bin2hex(random_bytes(10));
    $insert = $pdo->prepare("
        INSERT INTO customers (
            hubspot_contact_id, first_name, last_name, company, phone, email,
            address, city, state, zip, country, password_hash, last_updated
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $hubspotContactId,
        trim((string) ($bookingData['first_name'] ?? '')),
        trim((string) ($bookingData['last_name'] ?? '')),
        '',
        $phone,
        $email,
        trim((string) ($bookingData['address'] ?? '')),
        trim((string) ($bookingData['city'] ?? '')),
        strtoupper(trim((string) ($bookingData['state'] ?? ''))),
        trim((string) ($bookingData['zip'] ?? '')),
        'USA',
        $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
        null,
    ]);

    return (int) $pdo->lastInsertId();
}

function saveAbandonedBooking(PDO $pdo, array $bookingData): int {
    $customerId = resolveBookingCustomerId($pdo, $bookingData);
    $problemSummary = mb_substr((string) ($bookingData['problem'] ?? ''), 0, 255);
    $problemDetails = trim((string) ($bookingData['problem'] ?? ''));

    $existingRequestId = (int) ($bookingData['service_request_id'] ?? 0);
    if ($existingRequestId > 0) {
        $update = $pdo->prepare("
            UPDATE service_requests
               SET customer_id = ?, laser_brand = ?, laser_model = ?, laser_watts = ?, laser_age = ?,
                   problem_summary = ?, problem_details = ?, priority_level = ?, source = ?, request_status = ?,
                   preferred_date_start = NULL, preferred_date_end = NULL
             WHERE id = ?
        ");
        $update->execute([
            $customerId,
            trim((string) ($bookingData['machine_brand'] ?? '')),
            trim((string) ($bookingData['machine_model'] ?? '')),
            trim((string) ($bookingData['watts'] ?? '')) ?: null,
            trim((string) ($bookingData['age'] ?? '')) ?: null,
            $problemSummary,
            $problemDetails,
            'standard',
            'Website',
            'abandoned',
            $existingRequestId,
        ]);

        if ((int) $update->rowCount() > 0) {
            return $existingRequestId;
        }
        $existingCheck = $pdo->prepare("SELECT id FROM service_requests WHERE id = ? LIMIT 1");
        $existingCheck->execute([$existingRequestId]);
        if ((int) ($existingCheck->fetchColumn() ?: 0) > 0) {
            return $existingRequestId;
        }
    }

    $insert = $pdo->prepare("
        INSERT INTO service_requests (
            customer_id, laser_brand, laser_model, laser_watts, laser_age,
            problem_summary, problem_details, priority_level, source, request_status,
            preferred_date_start, preferred_date_end
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            NULL, NULL
        )
    ");
    $insert->execute([
        $customerId,
        trim((string) ($bookingData['machine_brand'] ?? '')),
        trim((string) ($bookingData['machine_model'] ?? '')),
        trim((string) ($bookingData['watts'] ?? '')) ?: null,
        trim((string) ($bookingData['age'] ?? '')) ?: null,
        $problemSummary,
        $problemDetails,
        'standard',
        'Website',
        'abandoned',
    ]);

    return (int) $pdo->lastInsertId();
}

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
if ((int) $pdo->query("SELECT COUNT(*) FROM service_speeds")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO service_speeds (speed_key, display_name, price_multiplier, sort_order) VALUES
        ('standard', 'Standard', 1.00, 1),
        ('rush', 'VIP', 1.35, 2),
        ('emergency', 'Emergency', 1.75, 3)");
}
$speedOptions = [];
foreach ($pdo->query("SELECT speed_key, display_name, price_multiplier FROM service_speeds ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $_spd) {
    $speedOptions[$_spd['speed_key']] = ['label' => $_spd['display_name'], 'multiplier' => (float) $_spd['price_multiplier']];
}
unset($_spd);

$travelSettings = getTravelSettings($pdo);
$travelPricePerMile = (float) ($travelSettings['price_per_mile'] ?? 2.00);
$travelMiles = null;
$travelDistanceError = null;

$step = isset($_GET['step']) ? (int) $_GET['step'] : 2;
if (!in_array($step, [2, 3, 4], true)) {
    $step = 2;
}
$errors = [];
$success = '';
$phoneError = '';
$smsConsentError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['form_step'] ?? '') === '1')) {
    $services = array_values(array_intersect(array_keys($serviceLabels), (array) ($_POST['services'] ?? [])));
    $otherService = trim((string) ($_POST['other_service'] ?? ''));
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
    if (!$errors && (string) ($_POST['sms_consent'] ?? '') !== '1') {
        $smsConsentError = 'Please check this box to continue — SMS consent is required.';
        $errors[] = $smsConsentError;
    }

    $password        = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['confirm_password'] ?? '';
    $preparedBookingData = null;

    // Check for an existing account BEFORE validating confirm_password so that a
    // returning customer who enters only their existing password is silently logged
    // in without needing to fill the "Confirm Password" field.
    // Skip this block entirely if the customer is already logged in via session.
    if (!$errors && empty($_SESSION['customer_id'])) {
        $emailCheck = trim((string) ($_POST['email'] ?? ''));
        $stmtCheck = $pdo->prepare('SELECT id, first_name, last_name, email, password_hash FROM customers WHERE email = ? LIMIT 1');
        $stmtCheck->execute([$emailCheck]);
        $existingCustomer = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existingCustomer && !empty($existingCustomer['password_hash'])) {
            if (password_verify($password, (string) $existingCustomer['password_hash'])) {
                // Correct password — silently log the customer in and proceed to speed selection.
                session_regenerate_id(true);
                $_SESSION['customer_id']         = (int) $existingCustomer['id'];
                $_SESSION['customer_first_name'] = $existingCustomer['first_name'];
                $_SESSION['customer_last_name']  = $existingCustomer['last_name'];
                $_SESSION['customer_email']      = $existingCustomer['email'];
                $preparedBookingData = [
                    'first_name' => trim((string) ($_POST['first_name'] ?? '')),
                    'last_name' => trim((string) ($_POST['last_name'] ?? '')),
                    'phone' => formatUsPhoneDisplay($normalizedPhone),
                    'phone_e164' => formatUsPhoneE164($normalizedPhone),
                    'email' => $emailCheck,
                    'machine_brand' => trim((string) ($_POST['machine_brand'] ?? '')),
                    'machine_model' => trim((string) ($_POST['machine_model'] ?? '')),
                    'watts' => trim((string) ($_POST['watts'] ?? '')),
                    'age' => trim((string) ($_POST['age'] ?? '')),
                    'address' => trim((string) ($_POST['address'] ?? '')),
                    'city' => trim((string) ($_POST['city'] ?? '')),
                    'state' => strtoupper(trim((string) ($_POST['state'] ?? ''))),
                    'zip' => trim((string) ($_POST['zip'] ?? '')),
                    'problem' => trim((string) ($_POST['problem'] ?? '')),
                    'services' => $services,
                    'other_service' => $otherService,
                    'sms_consent' => (string) ($_POST['sms_consent'] ?? ''),
                    'password' => $password,
                    'confirm_password' => $password,
                ];
                try {
                    $preparedBookingData['service_request_id'] = saveAbandonedBooking($pdo, $preparedBookingData);
                } catch (Throwable $e) {
                    error_log('book_a_technician.php step2 abandoned-save error: ' . $e->getMessage());
                    $errors[] = 'We could not save your booking details. Please try again.';
                }
                if ($errors) {
                    $showInlineStepOneLogin = false;
                    $inlineLoginError = '';
                    $inlineLoginEmail = '';
                    foreach ($preparedBookingData as $postKey => $postValue) {
                        if (is_array($postValue)) {
                            $_POST[$postKey] = $postValue;
                        } elseif (is_scalar($postValue) || $postValue === null) {
                            $_POST[$postKey] = (string) $postValue;
                        }
                    }
                    unset($preparedBookingData['password'], $preparedBookingData['confirm_password']);
                } else {
                $_SESSION['book_dash_repair'] = $preparedBookingData;
                unset($_SESSION[$pendingStepOneSessionKey]);
                header('Location: book_a_technician.php?step=3');
                exit;
                }
            } else {
                // Wrong password — show the inline login prompt.
                $showInlineStepOneLogin = true;
                $inlineLoginEmail = $emailCheck;
                $inlineLoginError = 'We found your account. Please log in.';
                $partialData = [
                    'first_name' => trim((string) ($_POST['first_name'] ?? '')),
                    'last_name' => trim((string) ($_POST['last_name'] ?? '')),
                    'phone' => formatUsPhoneDisplay($normalizedPhone),
                    'phone_e164' => formatUsPhoneE164($normalizedPhone),
                    'email' => $emailCheck,
                    'machine_brand' => trim((string) ($_POST['machine_brand'] ?? '')),
                    'machine_model' => trim((string) ($_POST['machine_model'] ?? '')),
                    'watts' => trim((string) ($_POST['watts'] ?? '')),
                    'age' => trim((string) ($_POST['age'] ?? '')),
                    'address' => trim((string) ($_POST['address'] ?? '')),
                    'city' => trim((string) ($_POST['city'] ?? '')),
                    'state' => strtoupper(trim((string) ($_POST['state'] ?? ''))),
                    'zip' => trim((string) ($_POST['zip'] ?? '')),
                    'problem' => trim((string) ($_POST['problem'] ?? '')),
                    'services' => $services,
                    'other_service' => $otherService,
                    'sms_consent' => (string) ($_POST['sms_consent'] ?? ''),
                    'password' => '',
                    'confirm_password' => '',
                ];
                $_SESSION[$pendingStepOneSessionKey] = $partialData;
            }
        }
    }

    // New-customer password validation — only runs when not already handled above
    // and when the user is not already logged in as a returning customer.
    if (!$errors && !$showInlineStepOneLogin && empty($_SESSION['customer_id'])) {
        if ($password === '' || $passwordConfirm === '') {
            $errors[] = 'Password and confirm password are required.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }
    }

    if (!$errors && !$showInlineStepOneLogin) {
        $preparedBookingData = [
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name' => trim((string) ($_POST['last_name'] ?? '')),
            'phone' => formatUsPhoneDisplay($normalizedPhone),
            'phone_e164' => formatUsPhoneE164($normalizedPhone),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'machine_brand' => trim((string) ($_POST['machine_brand'] ?? '')),
            'machine_model' => trim((string) ($_POST['machine_model'] ?? '')),
            'watts' => trim((string) ($_POST['watts'] ?? '')),
            'age' => trim((string) ($_POST['age'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'city' => trim((string) ($_POST['city'] ?? '')),
            'state' => strtoupper(trim((string) ($_POST['state'] ?? ''))),
            'zip' => trim((string) ($_POST['zip'] ?? '')),
            'problem' => trim((string) ($_POST['problem'] ?? '')),
            'services' => $services,
            'other_service' => $otherService,
            'sms_consent' => (string) ($_POST['sms_consent'] ?? ''),
            'password' => $password,
            'confirm_password' => $passwordConfirm,
        ];
        try {
            $preparedBookingData['service_request_id'] = saveAbandonedBooking($pdo, $preparedBookingData);
        } catch (Throwable $e) {
            error_log('book_a_technician.php step2 abandoned-save error: ' . $e->getMessage());
            $errors[] = 'We could not save your booking details. Please try again.';
        }
    }
    if (!$errors && !$showInlineStepOneLogin && is_array($preparedBookingData)) {
        $_SESSION['book_dash_repair'] = $preparedBookingData;
        unset($_SESSION[$pendingStepOneSessionKey]);
        header('Location: book_a_technician.php?step=3');
        exit;
    }
}

$booking = $_SESSION['book_dash_repair'] ?? null;
if ($step === 4) {
    unset($_SESSION['book_dash_repair'], $_SESSION[$pendingStepOneSessionKey]);
    $booking = null;
}
if ($step === 3 && !$booking) {
    header('Location: book_a_technician.php?step=2');
    exit;
}

// ── Real distance calculation for step 3 ─────────────────────────────────
if ($step === 3 && $booking) {
    $baseLocation = (string) ($travelSettings['base_location'] ?? '');
    $customerAddress = implode(', ', array_filter([
        $booking['address'] ?? '',
        $booking['city']    ?? '',
        $booking['state']   ?? '',
        $booking['zip']     ?? '',
    ]));
    $gmapsApiKey = loadGoogleMapsApiKey();
    $distanceResult = calculateDrivingDistanceMiles($baseLocation, $customerAddress, $gmapsApiKey);
    if (is_float($distanceResult)) {
        $travelMiles = round($distanceResult * 2, 1); // round trip
    } else {
        $travelDistanceError = $distanceResult['error'] ?? 'api_error';
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $step === 2) {
    $customerPrefill = $_SESSION[$customerPrefillSessionKey] ?? null;
    if (is_array($customerPrefill)) {
        foreach ($customerPrefill as $prefillKey => $prefillValue) {
            if (!isset($_POST[$prefillKey]) || trim((string) $_POST[$prefillKey]) === '') {
                $_POST[$prefillKey] = $prefillValue;
            }
        }
    }
}

$stepTwoPayload = null;
if ($step === 3 && $booking) {
    $stepTwoPayload = [
        'first_name' => (string) ($booking['first_name'] ?? ''),
        'last_name' => (string) ($booking['last_name'] ?? ''),
        'phone' => (string) ($booking['phone'] ?? ''),
        'phone_e164' => (string) ($booking['phone_e164'] ?? ''),
        'email' => (string) ($booking['email'] ?? ''),
        'machine_brand' => (string) ($booking['machine_brand'] ?? ''),
        'machine_model' => (string) ($booking['machine_model'] ?? ''),
        'watts' => (string) ($booking['watts'] ?? ''),
        'age' => (string) ($booking['age'] ?? ''),
        'address' => (string) ($booking['address'] ?? ''),
        'city' => (string) ($booking['city'] ?? ''),
        'state' => (string) ($booking['state'] ?? ''),
        'zip' => (string) ($booking['zip'] ?? ''),
        'problem' => (string) ($booking['problem'] ?? ''),
        'password' => (string) ($booking['password'] ?? ''),
        'confirm_password' => (string) ($booking['confirm_password'] ?? ''),
        'services' => array_values(array_intersect(array_keys($serviceLabels), (array) ($booking['services'] ?? []))),
        'other_service' => (string) ($booking['other_service'] ?? ''),
        'service_speed' => isset($speedOptions[$booking['service_speed'] ?? '']) ? (string) $booking['service_speed'] : 'standard',
        'service_request_id' => (int) ($booking['service_request_id'] ?? 0),
    ];
}

// Determine which section to render:
//   gate  – default landing (choose account type)
//   login – inline login form for returning customers
//   new   – full booking form for new customers (steps 1 & 2)
if (($loginError !== '' && !$showInlineStepOneLogin) || (isset($_GET['mode']) && $_GET['mode'] === 'login')) {
    $view = 'login';
} elseif (
    $showInlineStepOneLogin ||
    $_SERVER['REQUEST_METHOD'] === 'POST' ||
    $step >= 2
) {
    $view = 'new';
} else {
    $view = 'gate';
}

require_once __DIR__ . '/templates/header.php';
?>
<!-- PAGE HEADER -->
<section class="pt-32 pb-12 lg:pb-16 bg-zinc-950">
    <div class="max-w-3xl mx-auto px-6 lg:px-8 text-center">
        <div class="inline-flex items-center gap-2 bg-zinc-900 border border-cyan-500/30 rounded-full px-4 py-1.5 mb-8">
            <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
            <span class="text-xs text-cyan-400 font-medium tracking-wider uppercase">Book a Service</span>
        </div>
        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight mb-5">
            Book a <span class="text-cyan-400 glow-cyan">Technician</span>
        </h1>
        <p class="text-zinc-400 text-lg leading-relaxed max-w-xl mx-auto">
            Fill in the details below and our team will follow up within 2 hours with a diagnosis plan and transparent quote.
        </p>
    </div>
</section>

<section class="pb-24 lg:pb-32 bg-zinc-950">
    <div class="max-w-3xl mx-auto px-6">

        <?php if ($view === 'gate'): ?>
        <!-- ── GATE: Choose account type ── -->
        <div class="max-w-md mx-auto">
            <div class="space-y-6 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-8 sm:p-10 glow-box text-center">
                <div class="flex flex-col items-center">
                    <span class="w-12 h-12 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </span>
                    <h2 class="text-xl font-bold tracking-tight">Get Started</h2>
                    <p class="text-sm text-zinc-400 mt-1">Are you a new or returning customer?</p>
                </div>
                <div class="flex flex-col sm:flex-row gap-4 pt-2">
                    <a href="customer-login.php?step=1&amp;mode=login"
                       class="flex-1 inline-flex items-center justify-center gap-2 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-white font-semibold text-sm px-4 py-3.5 rounded-lg transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                        </svg>
                        I have an account
                    </a>
                    <a href="book_a_technician.php?step=2"
                       class="flex-1 inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-3.5 rounded-lg transition-all btn-glow">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                        </svg>
                        I'm a new customer
                    </a>
                </div>
            </div>
        </div>

        <?php elseif ($view === 'login'): ?>
        <!-- ── LOGIN: Inline form for returning customers ── -->
        <div class="max-w-md mx-auto">
            <div class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-8 sm:p-10 glow-box">
                <div class="flex flex-col items-center mb-8">
                    <span class="w-12 h-12 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                        </svg>
                    </span>
                    <h2 class="text-xl font-bold tracking-tight">Welcome Back</h2>
                    <p class="text-sm text-zinc-500 mt-1">Log in to book your technician visit</p>
                </div>

                <?php if ($loginError !== ''): ?>
                <div class="mb-5 flex items-center gap-2 bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg">
                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span><?= h($loginError) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="customer-login.php?step=1&amp;mode=login">
                    <input type="hidden" name="form_step" value="login">
                    <div class="flex flex-col gap-5">
                        <div>
                            <label for="login_email" class="block text-sm font-medium text-zinc-300 mb-1.5">Email Address</label>
                            <input
                                id="login_email"
                                name="login_email"
                                type="email"
                                autocomplete="email"
                                placeholder="you@example.com"
                                value="<?= h($_POST['login_email'] ?? '') ?>"
                                class="input-base"
                                required
                            >
                        </div>
                        <div>
                            <label for="login_password" class="block text-sm font-medium text-zinc-300 mb-1.5">Password</label>
                            <div class="relative">
                                <input
                                    id="login_password"
                                    name="login_password"
                                    type="password"
                                    autocomplete="current-password"
                                    placeholder="Enter your password"
                                    class="input-base pr-10"
                                    required
                                >
                                <button type="button" id="toggle-login-password"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300 transition-colors"
                                    aria-label="Toggle password visibility">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <button
                            type="submit"
                            class="w-full inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-2.5 rounded-md transition-all btn-glow mt-1">
                            Sign In &amp; Continue
                        </button>
                    </div>
                </form>

                <div class="mt-6 flex flex-col gap-2 text-center text-sm text-zinc-500">
                    <span>New customer?
                        <a href="book_a_technician.php?step=2" class="text-cyan-400 hover:text-cyan-300 transition-colors font-medium">Register here</a>
                    </span>
                    <a href="customer-login.php?step=1" class="text-zinc-600 hover:text-zinc-400 transition-colors text-xs">&larr; Back</a>
                </div>
            </div>
        </div>

        <?php else: /* $view === 'new' */ ?>
        <!-- ── NEW CUSTOMER: Full booking form (steps 1 & 2) ── -->
        <?php if ($errors): ?>
            <div class="mb-6 rounded-xl border border-red-500/30 bg-red-950/40 px-4 py-3 text-sm text-red-200">
                <?= h($errors[0]) ?>
            </div>
        <?php endif; ?>
        <?php if ($showInlineStepOneLogin && $step === 2): ?>
            <div class="mb-6 rounded-xl border border-amber-500/30 bg-amber-950/40 px-4 py-4 text-sm text-amber-100">
                <p class="font-semibold text-amber-200"><?= h($inlineLoginError !== '' ? $inlineLoginError : 'We found your account. Please log in.') ?></p>
                <form method="post" action="book_a_technician.php?step=2" class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                    <input type="hidden" name="form_step" value="login">
                    <input type="hidden" name="login_context" value="step1_existing_account">
                    <input
                        class="input-base"
                        type="email"
                        name="login_email"
                        placeholder="Email Address"
                        autocomplete="email"
                        value="<?= h($inlineLoginEmail !== '' ? $inlineLoginEmail : ($_POST['email'] ?? '')) ?>"
                        required
                    >
                    <input
                        class="input-base"
                        id="login_password"
                        type="password"
                        name="login_password"
                        placeholder="Password"
                        autocomplete="current-password"
                        required
                    >
                    <button type="submit" class="rounded-lg bg-amber-400 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-zinc-950 transition-colors hover:bg-amber-300">
                        Log In
                    </button>
                </form>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="mb-6 rounded-xl border border-emerald-500/30 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">
                <?= h($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 2): ?>
            <form id="step-1-booking-form" method="post" action="book_a_technician.php?step=2" class="space-y-6 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 sm:p-8 glow-box">
                <input type="hidden" name="form_step" value="1">
                <div>
                    <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Contact Information</p>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <input class="input-base" id="first_name" name="first_name" placeholder="First Name *" required value="<?= h($_POST['first_name'] ?? '') ?>">
                        </div>
                        <div>
                            <input class="input-base" id="last_name" name="last_name" placeholder="Last Name *" required value="<?= h($_POST['last_name'] ?? '') ?>">
                        </div>
                        <div>
                            <input class="input-base<?= $phoneError !== '' ? ' input-invalid' : '' ?>" type="tel" inputmode="tel" id="phone" name="phone" placeholder="Phone Number *" required value="<?= h(formatUsPhoneDisplay($_POST['phone'] ?? '')) ?>" aria-describedby="phone-error" aria-invalid="<?= $phoneError !== '' ? 'true' : 'false' ?>">
                            <p id="phone-error" class="field-error<?= $phoneError === '' ? ' hidden' : '' ?>"><?= h($phoneError) ?></p>
                        </div>
                        <div>
                            <input class="input-base" type="email" name="email" placeholder="Email Address *" required value="<?= h($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    id="sms-consent"
                                    name="sms_consent"
                                    class="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-zinc-600 bg-zinc-800 accent-cyan-500"
                                    value="1"
                                    <?= (string) ($_POST['sms_consent'] ?? '') === '1' ? 'checked' : '' ?>
                                    aria-describedby="sms-consent-error"
                                    aria-invalid="<?= $smsConsentError !== '' ? 'true' : 'false' ?>"
                                >
                                <span class="text-sm text-zinc-300 leading-snug">By checking this box you agree to receive recurring text messages from Acme Co. Message frequency varies. Message and data rates may apply. Reply STOP to cancel.</span>
                            </label>
                            <p id="sms-consent-error" class="field-error<?= $smsConsentError === '' ? ' hidden' : '' ?>" role="alert"><?= h($smsConsentError) ?></p>
                        </div>
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
                        <input class="input-base" name="address" placeholder="address *" required value="<?= h($_POST['address'] ?? '') ?>">
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
                                <label for="service-<?= h($serviceKey) ?>"><?= h($serviceLabel) ?> &ndash; $<?= formatPrice($serviceBasePrices[$serviceKey]) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="other-service-wrap" class="mt-4 <?= ($otherServiceId !== '' && in_array($otherServiceId, (array) ($_POST['services'] ?? []), true)) ? '' : 'hidden' ?>">
                        <input class="input-base" id="other-service-input" name="other_service" placeholder="Describe other service" value="<?= h($_POST['other_service'] ?? '') ?>">
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-zinc-400" for="problem">Problem Description *</label>
                    <textarea class="input-base resize-none" id="problem" name="problem" rows="4" required><?= h($_POST['problem'] ?? '') ?></textarea>
                </div>

                <div class="border-t border-zinc-800"></div>

                <?php if (empty($_SESSION['customer_id'])): ?>
                <div>
                    <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Create Account Password</p>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-400" for="password">Password *</label>
                            <input class="input-base" id="password" type="password" name="password" placeholder="Min. 8 characters" autocomplete="new-password" required minlength="8">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-400" for="confirm_password">Confirm Password *</label>
                            <input class="input-base" id="confirm_password" type="password" name="confirm_password" placeholder="Repeat password" autocomplete="new-password" required minlength="8">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div>
                    <button type="submit" class="w-full rounded-lg bg-cyan-500 py-3.5 text-sm font-bold text-zinc-950 hover:bg-cyan-400 btn-glow transition-all flex items-center justify-center gap-2">
                        Continue to Service Speed →
                    </button>
                </div>
            </form>
        <?php elseif ($step === 3): ?>
            <?php
                $selectedServices = $booking['services'];
                $currentSpeed = $booking['service_speed'] ?? 'standard';
                $baseTotal = 0;
                foreach ($selectedServices as $serviceKey) {
                    $baseTotal += $serviceBasePrices[$serviceKey] ?? 0;
                }
                $serviceTotal = round($baseTotal * ($speedOptions[$currentSpeed]['multiplier'] ?? 1), 2);
                $travelFee = $travelMiles !== null ? round($travelMiles * $travelPricePerMile, 2) : 0.0;
                $total = round($serviceTotal + $travelFee, 2);
                $travelMilesLabel = $travelMiles !== null ? number_format($travelMiles, 1) . ' miles round trip' : '';
            ?>
            <div class="space-y-6 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 sm:p-8 glow-box">
                <?php if (!empty($_SESSION['customer_id'])): ?>
                    <div class="rounded-xl border border-emerald-500/30 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">
                        You have been logged in successfully.
                    </div>
                <?php endif; ?>
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
                            <h2 id="step-2-success-heading" class="mt-8 text-3xl font-black tracking-tight text-white sm:text-4xl">Your Repair Has Been Booked</h2>
                            <p id="step-2-success-text" class="mt-3 max-w-2xl text-sm leading-7 text-zinc-300 sm:text-base">We’ve received your request and our team will reach out soon with the next steps.</p>
                        </div>

                        <div class="mt-8 grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                            <div class="rounded-2xl border border-zinc-800 bg-zinc-900/80 p-5">
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="text-[11px] font-semibold uppercase tracking-[0.24em] text-zinc-500">Chosen Priority</span>
                                    <span id="step-2-success-priority" class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold tracking-wide"></span>
                                </div>
                                <p id="step-2-success-priority-text" class="mt-4 text-sm leading-7 text-zinc-300">Your booking has been placed in our queue and we’ll contact you based on the priority you selected.</p>
                            </div>
                            <div class="rounded-2xl border border-zinc-800 bg-zinc-900/80 p-5">
                                <p id="step-2-success-dates-label" class="text-[11px] font-semibold uppercase tracking-[0.24em] text-zinc-500">Suggested Service Dates</p>
                                <ul id="step-2-success-dates" class="mt-4 space-y-3 text-sm text-zinc-100"></ul>
                            </div>
                        </div>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
                            <a href="book_a_technician.php?step=2" class="inline-flex items-center justify-center rounded-lg border border-zinc-700 px-5 py-3 text-sm font-semibold text-zinc-100 transition-all hover:border-zinc-500 hover:bg-zinc-900">Book Another Repair</a>
                            <a href="/" class="inline-flex items-center justify-center rounded-lg bg-cyan-500 px-5 py-3 text-sm font-semibold text-zinc-950 transition-all hover:bg-cyan-400 btn-glow">Return Home</a>
                        </div>
                    </div>
                </div>

                <div id="step-2-error" class="hidden rounded-xl border border-red-500/30 bg-red-950/40 px-4 py-4 text-sm text-red-200">
                    <p id="step-2-error-heading" class="font-semibold text-red-300">Something went wrong</p>
                    <p id="step-2-error-text" class="mt-1 text-red-100/80">Please check your details and try again.</p>
                </div>

                <div id="step-2-booking-shell">
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-cyan-400">Selected Services</p>
                    <ul class="space-y-2 text-sm text-zinc-200">
                        <?php foreach ($selectedServices as $serviceKey): ?>
                            <li class="flex items-center justify-between rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2">
                                <span><?= h($serviceLabels[$serviceKey] ?? $serviceKey) ?></span>
                                <span>$<?= formatPrice($serviceBasePrices[$serviceKey] ?? 0) ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($otherServiceId !== '' && in_array($otherServiceId, $selectedServices, true) && !empty($booking['other_service'])): ?>
                            <li class="rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2 text-zinc-400">Other details: <?= h($booking['other_service']) ?></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <form id="step-2-booking-form" class="space-y-4" novalidate>
                    <div>
                        <label class="flex items-center gap-1.5 text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide">
                            Phone Number <span class="text-red-400">*</span>
                            <span class="relative group inline-flex items-center">
                                <svg class="w-4 h-4 text-zinc-500 hover:text-cyan-400 cursor-pointer transition-colors duration-150 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd" />
                                </svg>
                                <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-72 rounded-lg bg-zinc-800 border border-zinc-700 px-3.5 py-2.5 text-xs font-normal normal-case tracking-normal text-zinc-300 leading-relaxed shadow-xl opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-50">
                                    By providing your phone number, you consent to receive SMS updates about your repair status and scheduling. Message and data rates may apply. Reply STOP to opt out anytime.
                                    <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-zinc-700"></span>
                                </span>
                            </span>
                        </label>
                        <p class="input-base opacity-70 cursor-not-allowed"><?= h($booking['phone'] ?? '') ?></p>
                    </div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">How soon do you need the appointment?</p>
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

                    <input type="hidden" name="booking_source" value="Website">
                    <div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
                        <label for="step-2-website">Website</label>
                        <input type="text" id="step-2-website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="rounded-lg border border-cyan-500/30 bg-cyan-950/30 px-4 py-3 text-sm space-y-2">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-zinc-300">Service Total</span>
                            <span id="current-service-total" class="font-semibold text-zinc-100">$<?= number_format($serviceTotal, 2) ?></span>
                        </div>
                        <?php if ($travelDistanceError): ?>
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-red-400">Travel Fee</span>
                            <span id="current-travel-fee" class="text-red-400">TBD</span>
                        </div>
                        <?php else: ?>
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-zinc-300">Travel Fee (<?= h($travelMilesLabel) ?> × $<?= number_format($travelPricePerMile, 2) ?>/mile)</span>
                            <span id="current-travel-fee" class="font-semibold text-zinc-100">$<?= number_format($travelFee, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="flex items-center justify-between gap-4 border-t border-cyan-500/20 pt-2">
                            <span class="text-zinc-300">Grand Total<?= $travelDistanceError ? ' <span class="text-xs font-normal text-red-400">(excl. travel)</span>' : '' ?></span>
                            <span id="current-total" class="font-semibold text-cyan-300">$<?= number_format($total, 2) ?></span>
                        </div>
                    </div>
                    <?php if ($travelDistanceError): ?>
                    <div class="rounded-lg border border-red-500/40 bg-red-950/40 px-4 py-3 text-sm text-red-300">
                        <?= h(travelDistanceErrorMessage($travelDistanceError)) ?>
                    </div>
                    <?php endif; ?>

                    <div class="flex gap-3">
                        <a href="book_a_technician.php?step=2" class="flex-1 rounded-lg border border-zinc-700 px-4 py-3 text-center text-sm font-semibold text-zinc-200 hover:bg-zinc-800 transition-all">Start Over</a>
                        <button id="step-2-submit-btn" type="submit" class="flex-1 rounded-lg bg-cyan-500 px-4 py-3 text-sm font-bold text-zinc-950 hover:bg-cyan-400 btn-glow transition-all flex items-center justify-center gap-2">
                            <span id="step-2-submit-label">Book My Repair</span>
                        </button>
                    </div>
                </form>
                </div>
            </div>
        <?php else: ?>
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
                    <h2 class="mt-8 text-3xl font-black tracking-tight text-white sm:text-4xl">Your booking has been received</h2>
                    <p class="mt-3 max-w-2xl text-sm leading-7 text-zinc-300 sm:text-base">Thank you. Our team will contact you soon with the next steps.</p>
                    <div class="mt-8 flex w-full flex-col gap-3 sm:flex-row sm:justify-center">
                        <a href="book_a_technician.php?step=2" class="inline-flex items-center justify-center rounded-lg border border-zinc-700 px-5 py-3 text-sm font-semibold text-zinc-100 transition-all hover:border-zinc-500 hover:bg-zinc-900">Book Another Repair</a>
                        <a href="/" class="inline-flex items-center justify-center rounded-lg bg-cyan-500 px-5 py-3 text-sm font-semibold text-zinc-950 transition-all hover:bg-cyan-400 btn-glow">Return Home</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php endif; /* end $view === 'new' */ ?>
    </div>
</section>

<section id="sms-policy" class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
    <div class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 sm:p-8">
        <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-cyan-400">SMS Policy</p>
        <h2 class="mb-4 text-lg font-bold text-zinc-100">SMS Messaging Terms</h2>
        <div class="space-y-3 text-sm text-zinc-400 leading-relaxed">
            <p>By providing your phone number and checking the consent box, you agree to receive SMS text messages from Ghost Laser regarding your repair booking, appointment scheduling, technician status updates, and related service communications.</p>
            <p>Message frequency varies based on your booking activity. Message and data rates may apply depending on your carrier and plan.</p>
            <p>You may opt out at any time by replying <strong class="text-zinc-300">STOP</strong> to any message. After opting out you will receive a single confirmation message and no further SMS messages will be sent. Reply <strong class="text-zinc-300">HELP</strong> for assistance.</p>
            <p>For full details, see our <a href="/sms-opt-in.php" class="text-cyan-400 underline hover:text-cyan-300 transition-colors">SMS Terms</a> and <a href="/privacy-policy.php" class="text-cyan-400 underline hover:text-cyan-300 transition-colors">Privacy Policy</a>.</p>
        </div>
    </div>
</section>

<script>
    const otherServiceCheckbox = document.getElementById('service-<?= h($otherServiceId) ?>');
    const otherServiceWrap = document.getElementById('other-service-wrap');
    const otherServiceInput = document.getElementById('other-service-input');
    const stepOneForm = document.getElementById('step-1-booking-form');
    const phoneInput = document.getElementById('phone');
    const phoneErrorEl = document.getElementById('phone-error');
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const smsConsentCheckbox = document.getElementById('sms-consent');
    const smsConsentError = document.getElementById('sms-consent-error');

    const normalizeUsPhone = (value) => {
        let digits = String(value || '').replace(/\D/g, '');
        if (digits.length === 11 && digits.startsWith('1')) {
            digits = digits.slice(1);
        }
        return digits.length === 10 ? digits : null;
    };

    const formatUsPhoneDisplay = (value) => {
        let digits = String(value || '').replace(/\D/g, '');
        if (digits.startsWith('1') && digits.length > 10) {
            digits = digits.slice(1);
        }
        digits = digits.slice(0, 10);

        if (!digits) return '';
        if (digits.length < 4) return `(${digits}`;
        if (digits.length < 7) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
        return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
    };

    const syncPhoneValidationState = () => {
        if (!phoneInput || !phoneErrorEl) return true;
        const digits = normalizeUsPhone(phoneInput.value);
        const hasValue = phoneInput.value.trim() !== '';
        const isValid = !hasValue || digits !== null;

        phoneInput.classList.toggle('input-invalid', !isValid);
        phoneInput.setAttribute('aria-invalid', isValid ? 'false' : 'true');
        phoneErrorEl.textContent = isValid ? '' : 'Please enter a valid 10-digit US phone number.';
        phoneErrorEl.classList.toggle('hidden', isValid);
        return isValid;
    };

    const syncPasswordMatchValidity = () => {
        if (!passwordInput || !confirmPasswordInput) return true;
        const hasBothValues = passwordInput.value !== '' && confirmPasswordInput.value !== '';
        const matches = !hasBothValues || passwordInput.value === confirmPasswordInput.value;
        confirmPasswordInput.setCustomValidity(matches ? '' : 'Passwords do not match.');
        return matches;
    };

    if (phoneInput) {
        phoneInput.addEventListener('input', () => {
            const cursorAtEnd = phoneInput.selectionStart === phoneInput.value.length;
            phoneInput.value = formatUsPhoneDisplay(phoneInput.value);
            if (cursorAtEnd) {
                phoneInput.setSelectionRange(phoneInput.value.length, phoneInput.value.length);
            }
            syncPhoneValidationState();
        });
        phoneInput.addEventListener('blur', syncPhoneValidationState);
        phoneInput.value = formatUsPhoneDisplay(phoneInput.value);
        syncPhoneValidationState();
    }

    if (passwordInput && confirmPasswordInput) {
        const clearPasswordMismatchIfFixed = () => {
            syncPasswordMatchValidity();
        };
        passwordInput.addEventListener('input', clearPasswordMismatchIfFixed);
        confirmPasswordInput.addEventListener('input', clearPasswordMismatchIfFixed);
        confirmPasswordInput.addEventListener('blur', clearPasswordMismatchIfFixed);
    }

    if (stepOneForm) {
        if (smsConsentCheckbox && smsConsentError) {
            const smsConsentRequiredMessage = 'Please check this box to continue — SMS consent is required.';
            const showSmsConsentError = () => {
                const message = smsConsentError.textContent.trim() || smsConsentRequiredMessage;
                smsConsentError.textContent = message;
                smsConsentCheckbox.setAttribute('aria-invalid', 'true');
                smsConsentError.classList.remove('hidden');
                smsConsentError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            };
            const clearSmsConsentError = () => {
                smsConsentCheckbox.setAttribute('aria-invalid', 'false');
                smsConsentError.classList.add('hidden');
                smsConsentError.textContent = '';
            };
            smsConsentCheckbox.addEventListener('change', () => {
                if (smsConsentCheckbox.checked) {
                    clearSmsConsentError();
                }
            });
            stepOneForm.addEventListener('submit', (event) => {
                if (phoneInput) {
                    phoneInput.value = formatUsPhoneDisplay(phoneInput.value);
                }
                if (!syncPasswordMatchValidity()) {
                    event.preventDefault();
                    confirmPasswordInput?.reportValidity();
                    confirmPasswordInput?.focus();
                    return;
                }
                if (!syncPhoneValidationState()) {
                    event.preventDefault();
                    phoneInput?.focus();
                    return;
                }
                if (!smsConsentCheckbox.checked) {
                    event.preventDefault();
                    showSmsConsentError();
                    return;
                }
                clearSmsConsentError();
            });
        } else {
            stepOneForm.addEventListener('submit', (event) => {
                if (phoneInput) {
                    phoneInput.value = formatUsPhoneDisplay(phoneInput.value);
                }
                if (!syncPasswordMatchValidity()) {
                    event.preventDefault();
                    confirmPasswordInput?.reportValidity();
                    confirmPasswordInput?.focus();
                    return;
                }
                if (!syncPhoneValidationState()) {
                    event.preventDefault();
                    phoneInput?.focus();
                }
            });
        }
    }

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

    const stepTwoForm = document.getElementById('step-2-booking-form');
    const bookingPayload = <?= $stepTwoPayload ? json_encode($stepTwoPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null' ?>;
    const serviceLabels = <?= json_encode($serviceLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const serviceBasePrices = <?= json_encode($serviceBasePrices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const speedOptions = <?= json_encode($speedOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const speedPriorityMap = { standard: 'standard', rush: 'vip', emergency: 'emergency' };
    const travelMiles = <?= json_encode($travelMiles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const travelPricePerMile = <?= json_encode($travelPricePerMile, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const travelMilesLabel = <?= json_encode($travelMilesLabel ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const travelDistanceError = <?= json_encode($travelDistanceError ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    if (stepTwoForm && bookingPayload) {
        const serviceTotalEl = document.getElementById('current-service-total');
        const travelFeeEl = document.getElementById('current-travel-fee');
        const totalEl = document.getElementById('current-total');
        const submitBtn = document.getElementById('step-2-submit-btn');
        const submitLabel = document.getElementById('step-2-submit-label');
        const bookingShell = document.getElementById('step-2-booking-shell');
        const successBox = document.getElementById('step-2-success');
        const successHeading = document.getElementById('step-2-success-heading');
        const successText = document.getElementById('step-2-success-text');
        const successPriority = document.getElementById('step-2-success-priority');
        const successPriorityText = document.getElementById('step-2-success-priority-text');
        const successDatesLabel = document.getElementById('step-2-success-dates-label');
        const successDates = document.getElementById('step-2-success-dates');
        const errorBox = document.getElementById('step-2-error');
        const errorHeading = document.getElementById('step-2-error-heading');
        const errorText = document.getElementById('step-2-error-text');
        const speedInputs = stepTwoForm.querySelectorAll('input[name="service_speed"]');
        const priorityConfig = {
            standard: {
                label: 'Standard',
                badgeClasses: ['border-zinc-500/40', 'bg-zinc-500/15', 'text-zinc-100'],
                headline: 'Your Repair Has Been Booked',
                message: 'We’ve received your request and will contact you soon to confirm the next steps. Standard bookings are typically scheduled within 3–5 business days.',
                detail: 'Your request is queued under Standard priority, and our team will follow up as soon as the next available scheduling window opens.'
            },
            vip: {
                label: 'VIP',
                badgeClasses: ['border-cyan-400/40', 'bg-cyan-400/15', 'text-cyan-100'],
                headline: 'VIP Repair Request Confirmed',
                message: 'Thank you — your VIP booking is locked in. We’ve received your request and will contact you soon with expedited scheduling details.',
                detail: 'Your request has been elevated to VIP priority so our team can move quickly and keep your downtime to a minimum.'
            },
            emergency: {
                label: 'Emergency',
                badgeClasses: ['border-red-400/40', 'bg-red-500/15', 'text-red-100'],
                headline: 'Emergency Repair Request Received',
                message: 'Your emergency request has been received and flagged for urgent attention. Our team will contact you as soon as possible.',
                detail: 'Your booking is marked Emergency, and we’ll prioritize outreach immediately based on your urgent service needs.'
            }
        };

        const updateDisplayedTotal = () => {
            const selectedSpeed = stepTwoForm.querySelector('input[name="service_speed"]:checked')?.value || 'standard';
            bookingPayload.service_speed = selectedSpeed;

            const baseTotal = (bookingPayload.services || []).reduce((sum, serviceKey) => {
                return sum + (serviceBasePrices[serviceKey] || 0);
            }, 0);

            bookingPayload.service_total = Number((baseTotal * (speedOptions[selectedSpeed]?.multiplier || 1)).toFixed(2));
            bookingPayload.travel_fee = travelMiles !== null ? Number((travelMiles * travelPricePerMile).toFixed(2)) : 0;
            bookingPayload.total_price = Number((bookingPayload.service_total + bookingPayload.travel_fee).toFixed(2));
            serviceTotalEl.textContent = `$${bookingPayload.service_total.toFixed(2)}`;
            if (travelMiles !== null) {
                travelFeeEl.textContent = `$${bookingPayload.travel_fee.toFixed(2)}`;
            }
            totalEl.textContent = `$${bookingPayload.total_price.toFixed(2)}`;
        };

        const formatDate = (dateStr) => {
            const [year, month, day] = dateStr.split('-').map(Number);
            const date = new Date(year, month - 1, day);
            const monthName = date.toLocaleString('en-US', { month: 'long' });
            const suffix = day === 1 || day === 21 || day === 31 ? 'st'
                : day === 2 || day === 22 ? 'nd'
                : day === 3 || day === 23 ? 'rd'
                : 'th';
            return `${monthName} ${day}${suffix}, ${year}`;
        };

        speedInputs.forEach((input) => {
            input.addEventListener('change', updateDisplayedTotal);
        });
        updateDisplayedTotal();

        stepTwoForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            successBox.classList.add('hidden');
            errorBox.classList.add('hidden');
            submitBtn.disabled = true;
            submitLabel.textContent = 'Submitting…';

            updateDisplayedTotal();

            const selectedSpeed = bookingPayload.service_speed || 'standard';
            const selectedSpeedLabel = speedOptions[selectedSpeed]?.label || 'Standard';
            const selectedServices = (bookingPayload.services || []).map((serviceKey) => serviceLabels[serviceKey] || serviceKey);
            const problemSections = [
                bookingPayload.problem,
                '--- Booking Summary ---',
                `Selected services: ${selectedServices.join(', ')}`,
                bookingPayload.other_service ? `Other service details: ${bookingPayload.other_service}` : null,
                `Service speed: ${selectedSpeedLabel}`,
                `Service total: $${bookingPayload.service_total.toFixed(2)}`,
                travelDistanceError
                    ? `Travel fee: unable to calculate (${travelDistanceError}) — contact us for a quote`
                    : `Travel fee (${travelMilesLabel} @ $${travelPricePerMile.toFixed(2)}/mile): $${bookingPayload.travel_fee.toFixed(2)}`,
                `Grand total: $${bookingPayload.total_price.toFixed(2)}`,
            ].filter(Boolean);

            const requestBody = {
                first_name: bookingPayload.first_name,
                last_name: bookingPayload.last_name,
                phone: bookingPayload.phone_e164 || `+1${(bookingPayload.phone || '').replace(/\D/g, '').slice(-10)}`,
                email: bookingPayload.email,
                machine_brand: bookingPayload.machine_brand,
                machine_model: bookingPayload.machine_model,
                machine_watts: bookingPayload.watts || null,
                machine_age: bookingPayload.age || null,
                address: bookingPayload.address,
                city: bookingPayload.city,
                state: (bookingPayload.state || '').toUpperCase(),
                zip: bookingPayload.zip,
                problem: problemSections.join('\n\n'),
                password: bookingPayload.password,
                confirm_password: bookingPayload.confirm_password,
                priority: speedPriorityMap[selectedSpeed] || 'standard',
                website: stepTwoForm.website.value.trim(),
                booking_source: stepTwoForm.booking_source.value,
                services: bookingPayload.services || [],
                other_service: bookingPayload.other_service || '',
                service_speed: selectedSpeed,
                total_price: bookingPayload.total_price,
                service_request_id: Number(bookingPayload.service_request_id || 0),
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
                        : (json.message || 'Please check your details and try again.');
                    throw new Error(apiMessage);
                }

                window.location.href = 'book_a_technician.php?step=4';
            } catch (error) {
                const errorMessage = error instanceof Error ? error.message : 'Network error — please check your connection and try again.';
                if (errorHeading) {
                    errorHeading.textContent = errorMessage;
                }
                errorText.textContent = '';
                errorBox.classList.remove('hidden');
                errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } finally {
                submitBtn.disabled = false;
                submitLabel.textContent = 'Book My Repair';
            }
        });
    }

    // Login view: password visibility toggle
    const toggleLoginBtn   = document.getElementById('toggle-login-password');
    const loginPasswordInput = document.getElementById('login_password');
    if (toggleLoginBtn && loginPasswordInput) {
        toggleLoginBtn.addEventListener('click', () => {
            const isPassword = loginPasswordInput.type === 'password';
            loginPasswordInput.type = isPassword ? 'text' : 'password';
            toggleLoginBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        });
    }

</script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
