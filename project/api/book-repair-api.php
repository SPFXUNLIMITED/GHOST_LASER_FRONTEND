<?php
declare(strict_types=1);

header('Content-Type: application/json');

// ── helpers ──────────────────────────────────────────────────────────────────

function sendError(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function addBusinessDays(DateTimeImmutable $date, int $days): DateTimeImmutable
{
    $current = $date;
    $added   = 0;

    while ($added < $days) {
        $current = $current->modify('+1 day');
        if ((int) $current->format('N') < 6) {
            $added++;
        }
    }

    return $current;
}

function suggestedDatesForPriority(string $priority): array
{
    $today = new DateTimeImmutable('today');

    switch ($priority) {
        case 'emergency':
            return [
                $today->format('Y-m-d'),
                addBusinessDays($today, 1)->format('Y-m-d'),
            ];

        case 'vip':
            return [
                addBusinessDays($today, 1)->format('Y-m-d'),
                addBusinessDays($today, 2)->format('Y-m-d'),
            ];

        default:
            return [
                addBusinessDays($today, 3)->format('Y-m-d'),
                addBusinessDays($today, 4)->format('Y-m-d'),
                addBusinessDays($today, 5)->format('Y-m-d'),
            ];
    }
}

// ── parse request body ────────────────────────────────────────────────────────

$raw   = (string) file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    sendError('Invalid request body.');
}

// ── reCAPTCHA verification ────────────────────────────────────────────────────

$token = trim((string) ($input['recaptcha_token'] ?? ''));

if ($token === '') {
    sendError('reCAPTCHA token is required.');
}

$config    = require __DIR__ . '/../config.php';
$secretKey = (string) ($config['recaptcha_secret'] ?? getenv('RECAPTCHA_SECRET_KEY') ?: '');

if ($secretKey !== '') {
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secretKey, 'response' => $token]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $captchaRaw    = (string) curl_exec($ch);
    curl_close($ch);
    $captchaResult = json_decode($captchaRaw, true);

    if (!($captchaResult['success'] ?? false)) {
        sendError('reCAPTCHA verification failed. Please try again.');
    }
}

// ── read and sanitise form fields ─────────────────────────────────────────────
// Use the values the user typed into the form — do NOT fall back to session data.

$fullName = trim((string) ($input['name']  ?? ''));
$phone    = trim((string) ($input['phone'] ?? ''));
$email    = strtolower(trim((string) ($input['email'] ?? '')));
$city     = trim((string) ($input['city']  ?? ''));
$street   = trim((string) ($input['street'] ?? ''));
$state    = strtoupper(trim((string) ($input['state'] ?? '')));
$zip      = trim((string) ($input['zip']   ?? ''));
$brand    = trim((string) ($input['machine_brand'] ?? ''));
$model    = trim((string) ($input['machine_model'] ?? ''));
$wattsRaw = $input['watts'] ?? null;
$watts    = ($wattsRaw !== null && $wattsRaw !== '') ? (int) $wattsRaw : null;
$age      = trim((string) ($input['age']     ?? ''));
$problem  = trim((string) ($input['problem'] ?? ''));
$priority = strtolower(trim((string) ($input['priority'] ?? 'standard')));

if (!in_array($priority, ['standard', 'vip', 'emergency'], true)) {
    $priority = 'standard';
}

// Split full name into first / last using the first space as the boundary.
$nameParts = explode(' ', $fullName, 2);
$firstName = trim($nameParts[0]);
$lastName  = trim($nameParts[1] ?? '');

// ── validate required fields ──────────────────────────────────────────────────

$missing = [];
if ($firstName === '') { $missing[] = 'name'; }
if ($phone     === '') { $missing[] = 'phone'; }
if ($email     === '') { $missing[] = 'email'; }
if ($city      === '') { $missing[] = 'city'; }
if ($street    === '') { $missing[] = 'street'; }
if ($brand     === '') { $missing[] = 'machine_brand'; }
if ($model     === '') { $missing[] = 'machine_model'; }
if ($problem   === '') { $missing[] = 'problem'; }

if ($missing !== []) {
    sendError('Missing required fields: ' . implode(', ', $missing) . '.');
}

// ── database ──────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../db.php';

// Create or update the customer record using the form-submitted name and city.
$stmt = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$existingCustomer = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existingCustomer) {
    $customerId = (int) $existingCustomer['id'];

    $pdo->prepare('
        UPDATE customers
        SET first_name = ?, last_name = ?, phone = ?, city = ?, street = ?, state = ?, zip = ?
        WHERE id = ?
    ')->execute([$firstName, $lastName, $phone, $city, $street, $state, $zip, $customerId]);
} else {
    $pdo->prepare('
        INSERT INTO customers (first_name, last_name, phone, email, city, street, state, zip)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([$firstName, $lastName, $phone, $email, $city, $street, $state, $zip]);

    $customerId = (int) $pdo->lastInsertId();
}

// Calculate suggested service dates based on priority level.
$suggestedDates = suggestedDatesForPriority($priority);

// Insert the service request.
$pdo->prepare('
    INSERT INTO service_requests
        (customer_id, machine_brand, machine_model, watts, machine_age, problem_summary,
         priority_level, request_status, suggested_dates,
         preferred_date_start, preferred_date_end, created_at)
    VALUES
        (?, ?, ?, ?, ?, ?, ?, \'new\', ?, ?, ?, NOW())
')->execute([
    $customerId,
    $brand,
    $model,
    $watts,
    $age !== '' ? $age : null,
    $problem,
    $priority,
    json_encode($suggestedDates),
    $suggestedDates[0]                        ?? null,
    $suggestedDates[count($suggestedDates) - 1] ?? null,
]);

// ── success response ──────────────────────────────────────────────────────────

http_response_code(201);
echo json_encode([
    'priority'        => $priority,
    'suggested_dates' => $suggestedDates,
]);
