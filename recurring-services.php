<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';

function recColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function recEnsureSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recurring_service_customers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            default_machine_brand VARCHAR(255) NULL,
            default_machine_model VARCHAR(255) NULL,
            default_machine_watts VARCHAR(100) NULL,
            default_machine_age VARCHAR(100) NULL,
            default_problem_summary VARCHAR(255) NULL,
            default_problem_details TEXT NULL,
            default_services JSON NULL,
            default_other_service TEXT NULL,
            default_service_speed VARCHAR(50) NULL,
            service_fingerprint CHAR(64) NOT NULL,
            frequency_value INT UNSIGNED NOT NULL DEFAULT 1,
            frequency_unit ENUM('days','weeks','months') NOT NULL DEFAULT 'months',
            last_serviced_date DATE NULL,
            next_due_date DATE NOT NULL,
            priority_default ENUM('standard','vip','emergency') NOT NULL DEFAULT 'standard',
            active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_active_profile_per_service (customer_id, service_fingerprint, active),
            KEY idx_recurring_due (active, next_due_date),
            CONSTRAINT fk_recurring_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!recColumnExists($pdo, 'service_requests', 'recurring_profile_id')) {
        $pdo->exec("ALTER TABLE service_requests ADD COLUMN recurring_profile_id INT UNSIGNED NULL AFTER preferred_date_end");
    }

    $idxExistsStmt = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'service_requests'
          AND INDEX_NAME = 'idx_recurring_profile'
    ");
    if ((int) $idxExistsStmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE service_requests ADD INDEX idx_recurring_profile (recurring_profile_id)");
    }
}

function recNormalizeServices(array $rawServices): array
{
    $services = array_values(array_filter(array_unique(array_map('intval', $rawServices)), static fn($v) => $v > 0));
    sort($services);
    return $services;
}

function recBuildServiceFingerprint(array $services, string $otherService, string $serviceSpeed, string $machineBrand, string $machineModel): string
{
    return hash('sha256', json_encode([
        'services' => $services,
        'other_service' => trim($otherService),
        'service_speed' => trim($serviceSpeed),
        'machine_brand' => trim($machineBrand),
        'machine_model' => trim($machineModel),
    ], JSON_UNESCAPED_UNICODE));
}

function recCleanDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    return $value;
}

function recComputeNextDueDate(?string $lastServicedDate, ?string $nextDueDate, int $frequencyValue, string $frequencyUnit): ?string
{
    if ($nextDueDate !== null) {
        return $nextDueDate;
    }
    if ($lastServicedDate === null) {
        return (new DateTimeImmutable('today'))->format('Y-m-d');
    }
    $anchor = DateTimeImmutable::createFromFormat('Y-m-d', $lastServicedDate);
    if ($anchor === false) {
        return null;
    }
    return match ($frequencyUnit) {
        'days' => $anchor->modify('+' . $frequencyValue . ' day')->format('Y-m-d'),
        'weeks' => $anchor->modify('+' . $frequencyValue . ' week')->format('Y-m-d'),
        default => $anchor->modify('+' . $frequencyValue . ' month')->format('Y-m-d'),
    };
}

function recGetDueBadge(?string $nextDueDate, bool $active): array
{
    if (!$active) {
        return ['label' => 'Inactive', 'class' => 'bg-zinc-600/30 text-zinc-300'];
    }
    if ($nextDueDate === null || $nextDueDate === '') {
        return ['label' => 'No Due Date', 'class' => 'bg-zinc-600/30 text-zinc-300'];
    }
    $today = new DateTimeImmutable('today');
    $due = DateTimeImmutable::createFromFormat('Y-m-d', $nextDueDate);
    if ($due === false) {
        return ['label' => 'Invalid Date', 'class' => 'bg-zinc-600/30 text-zinc-300'];
    }
    if ($due < $today) {
        return ['label' => 'Overdue', 'class' => 'bg-red-500/20 text-red-300'];
    }
    if ($due == $today) {
        return ['label' => 'Due Today', 'class' => 'bg-orange-500/20 text-orange-300'];
    }
    return ['label' => 'Upcoming', 'class' => 'bg-cyan-500/20 text-cyan-300'];
}

recEnsureSchema($pdo);

if (empty($_SESSION['recurring_csrf'])) {
    $_SESSION['recurring_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['recurring_csrf'];

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && (($_GET['action'] ?? '') === 'customer_search')
) {
    header('Content-Type: application/json');
    $csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($csrfHeader === '' || !hash_equals($csrf, $csrfHeader)) {
        http_response_code(403);
        echo json_encode(['results' => [], 'error' => 'Invalid CSRF token.']);
        exit;
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT
            id,
            first_name,
            last_name,
            company,
            email,
            phone,
            address,
            city,
            state,
            zip,
            machine_brand,
            machine_model,
            machine_watts,
            machine_age
        FROM customers
        WHERE first_name LIKE :q
           OR last_name LIKE :q
           OR company LIKE :q
           OR email LIKE :q
           OR phone LIKE :q
        ORDER BY last_name ASC, first_name ASC
        LIMIT 10
    ");
    $stmt->execute([':q' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = array_map(static function ($row): array {
        return [
            'id' => (int) $row['id'],
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'company' => (string) ($row['company'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'state' => (string) ($row['state'] ?? ''),
            'zip' => (string) ($row['zip'] ?? ''),
            'machine_brand' => (string) ($row['machine_brand'] ?? ''),
            'machine_model' => (string) ($row['machine_model'] ?? ''),
            'machine_watts' => (string) ($row['machine_watts'] ?? ''),
            'machine_age' => (string) ($row['machine_age'] ?? ''),
        ];
    }, $rows);

    echo json_encode(['results' => $results]);
    exit;
}

$flashError = '';
$flashSuccess = '';
if (!empty($_SESSION['recurring_flash_error'])) {
    $flashError = (string) $_SESSION['recurring_flash_error'];
    unset($_SESSION['recurring_flash_error']);
}
if (!empty($_SESSION['recurring_flash_success'])) {
    $flashSuccess = (string) $_SESSION['recurring_flash_success'];
    unset($_SESSION['recurring_flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = trim((string) ($_POST['csrf'] ?? ''));
    $action = trim((string) ($_POST['action'] ?? ''));
    $scope = trim((string) ($_POST['scope'] ?? 'due'));
    $q = trim((string) ($_POST['q'] ?? ''));
    $redirectQs = http_build_query(array_filter([
        'scope' => $scope,
        'q' => $q,
    ]));

    if ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
        $_SESSION['recurring_flash_error'] = 'Invalid security token. Please refresh and try again.';
        header('Location: recurring-services.php' . ($redirectQs !== '' ? '?' . $redirectQs : ''));
        exit;
    }

    try {
        if (in_array($action, ['create_profile', 'update_profile'], true)) {
            $profileId = (int) ($_POST['profile_id'] ?? 0);
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $frequencyValue = max(1, (int) ($_POST['frequency_value'] ?? 1));
            $frequencyUnit = strtolower(trim((string) ($_POST['frequency_unit'] ?? 'months')));
            if (!in_array($frequencyUnit, ['days', 'weeks', 'months'], true)) {
                $frequencyUnit = 'months';
            }
            $lastServicedDate = recCleanDate((string) ($_POST['last_serviced_date'] ?? ''));
            $nextDueDate = recCleanDate((string) ($_POST['next_due_date'] ?? ''));
            $priorityDefault = strtolower(trim((string) ($_POST['priority_default'] ?? 'standard')));
            if (!in_array($priorityDefault, ['standard', 'vip', 'emergency'], true)) {
                $priorityDefault = 'standard';
            }
            $active = isset($_POST['active']) ? 1 : 0;
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $defaultProblemSummary = trim((string) ($_POST['default_problem_summary'] ?? ''));
            $defaultProblemDetails = trim((string) ($_POST['default_problem_details'] ?? ''));
            $defaultOtherService = trim((string) ($_POST['default_other_service'] ?? ''));
            $defaultServiceSpeed = trim((string) ($_POST['default_service_speed'] ?? 'standard'));
            $defaultMachineBrand = trim((string) ($_POST['default_machine_brand'] ?? ''));
            $defaultMachineModel = trim((string) ($_POST['default_machine_model'] ?? ''));
            $defaultMachineWatts = trim((string) ($_POST['default_machine_watts'] ?? ''));
            $defaultMachineAge = trim((string) ($_POST['default_machine_age'] ?? ''));
            $services = recNormalizeServices((array) ($_POST['default_services'] ?? []));
            $servicesJson = $services !== [] ? json_encode($services) : null;

            if ($customerId <= 0) {
                throw new RuntimeException('Select a valid customer.');
            }

            $computedDueDate = recComputeNextDueDate($lastServicedDate, $nextDueDate, $frequencyValue, $frequencyUnit);
            if ($computedDueDate === null) {
                throw new RuntimeException('Enter a valid next due date or last serviced date.');
            }

            $serviceFingerprint = recBuildServiceFingerprint(
                $services,
                $defaultOtherService,
                $defaultServiceSpeed,
                $defaultMachineBrand,
                $defaultMachineModel
            );

            $dupStmt = $pdo->prepare("
                SELECT id
                FROM recurring_service_customers
                WHERE customer_id = :customer_id
                  AND service_fingerprint = :service_fingerprint
                  AND active = 1
                  AND (:current_id = 0 OR id != :current_id)
                LIMIT 1
            ");
            $dupStmt->execute([
                ':customer_id' => $customerId,
                ':service_fingerprint' => $serviceFingerprint,
                ':current_id' => $profileId,
            ]);
            if ((int) ($dupStmt->fetchColumn() ?: 0) > 0 && $active === 1) {
                throw new RuntimeException('An active recurring profile already exists for this customer and service profile.');
            }

            if ($action === 'create_profile') {
                $insert = $pdo->prepare("
                    INSERT INTO recurring_service_customers (
                        customer_id,
                        default_machine_brand,
                        default_machine_model,
                        default_machine_watts,
                        default_machine_age,
                        default_problem_summary,
                        default_problem_details,
                        default_services,
                        default_other_service,
                        default_service_speed,
                        service_fingerprint,
                        frequency_value,
                        frequency_unit,
                        last_serviced_date,
                        next_due_date,
                        priority_default,
                        active,
                        notes
                    ) VALUES (
                        :customer_id,
                        :default_machine_brand,
                        :default_machine_model,
                        :default_machine_watts,
                        :default_machine_age,
                        :default_problem_summary,
                        :default_problem_details,
                        :default_services,
                        :default_other_service,
                        :default_service_speed,
                        :service_fingerprint,
                        :frequency_value,
                        :frequency_unit,
                        :last_serviced_date,
                        :next_due_date,
                        :priority_default,
                        :active,
                        :notes
                    )
                ");
                $insert->execute([
                    ':customer_id' => $customerId,
                    ':default_machine_brand' => $defaultMachineBrand !== '' ? $defaultMachineBrand : null,
                    ':default_machine_model' => $defaultMachineModel !== '' ? $defaultMachineModel : null,
                    ':default_machine_watts' => $defaultMachineWatts !== '' ? $defaultMachineWatts : null,
                    ':default_machine_age' => $defaultMachineAge !== '' ? $defaultMachineAge : null,
                    ':default_problem_summary' => $defaultProblemSummary !== '' ? mb_substr($defaultProblemSummary, 0, 255) : null,
                    ':default_problem_details' => $defaultProblemDetails !== '' ? $defaultProblemDetails : null,
                    ':default_services' => $servicesJson,
                    ':default_other_service' => $defaultOtherService !== '' ? $defaultOtherService : null,
                    ':default_service_speed' => $defaultServiceSpeed !== '' ? $defaultServiceSpeed : null,
                    ':service_fingerprint' => $serviceFingerprint,
                    ':frequency_value' => $frequencyValue,
                    ':frequency_unit' => $frequencyUnit,
                    ':last_serviced_date' => $lastServicedDate,
                    ':next_due_date' => $computedDueDate,
                    ':priority_default' => $priorityDefault,
                    ':active' => $active,
                    ':notes' => $notes !== '' ? $notes : null,
                ]);
                $_SESSION['recurring_flash_success'] = 'Recurring profile created.';
            } else {
                if ($profileId <= 0) {
                    throw new RuntimeException('Invalid recurring profile.');
                }
                $update = $pdo->prepare("
                    UPDATE recurring_service_customers
                    SET
                        customer_id = :customer_id,
                        default_machine_brand = :default_machine_brand,
                        default_machine_model = :default_machine_model,
                        default_machine_watts = :default_machine_watts,
                        default_machine_age = :default_machine_age,
                        default_problem_summary = :default_problem_summary,
                        default_problem_details = :default_problem_details,
                        default_services = :default_services,
                        default_other_service = :default_other_service,
                        default_service_speed = :default_service_speed,
                        service_fingerprint = :service_fingerprint,
                        frequency_value = :frequency_value,
                        frequency_unit = :frequency_unit,
                        last_serviced_date = :last_serviced_date,
                        next_due_date = :next_due_date,
                        priority_default = :priority_default,
                        active = :active,
                        notes = :notes
                    WHERE id = :id
                ");
                $update->execute([
                    ':customer_id' => $customerId,
                    ':default_machine_brand' => $defaultMachineBrand !== '' ? $defaultMachineBrand : null,
                    ':default_machine_model' => $defaultMachineModel !== '' ? $defaultMachineModel : null,
                    ':default_machine_watts' => $defaultMachineWatts !== '' ? $defaultMachineWatts : null,
                    ':default_machine_age' => $defaultMachineAge !== '' ? $defaultMachineAge : null,
                    ':default_problem_summary' => $defaultProblemSummary !== '' ? mb_substr($defaultProblemSummary, 0, 255) : null,
                    ':default_problem_details' => $defaultProblemDetails !== '' ? $defaultProblemDetails : null,
                    ':default_services' => $servicesJson,
                    ':default_other_service' => $defaultOtherService !== '' ? $defaultOtherService : null,
                    ':default_service_speed' => $defaultServiceSpeed !== '' ? $defaultServiceSpeed : null,
                    ':service_fingerprint' => $serviceFingerprint,
                    ':frequency_value' => $frequencyValue,
                    ':frequency_unit' => $frequencyUnit,
                    ':last_serviced_date' => $lastServicedDate,
                    ':next_due_date' => $computedDueDate,
                    ':priority_default' => $priorityDefault,
                    ':active' => $active,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':id' => $profileId,
                ]);
                $_SESSION['recurring_flash_success'] = 'Recurring profile updated.';
            }
        } elseif ($action === 'delete_profile') {
            $profileId = (int) ($_POST['profile_id'] ?? 0);
            if ($profileId <= 0) {
                throw new RuntimeException('Invalid recurring profile.');
            }
            $stmt = $pdo->prepare("DELETE FROM recurring_service_customers WHERE id = :id");
            $stmt->execute([':id' => $profileId]);
            $_SESSION['recurring_flash_success'] = 'Recurring profile deleted.';
        } elseif ($action === 'add_to_schedule') {
            $profileId = (int) ($_POST['profile_id'] ?? 0);
            if ($profileId <= 0) {
                throw new RuntimeException('Invalid recurring profile.');
            }

            $pdo->beginTransaction();

            $profileStmt = $pdo->prepare("
                SELECT
                    r.*,
                    c.address,
                    c.city,
                    c.state,
                    c.zip
                FROM recurring_service_customers r
                JOIN customers c ON c.id = r.customer_id
                WHERE r.id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $profileStmt->execute([':id' => $profileId]);
            $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
            if (!$profile) {
                throw new RuntimeException('Recurring profile not found.');
            }
            if ((int) ($profile['active'] ?? 0) !== 1) {
                throw new RuntimeException('This recurring profile is inactive.');
            }

            $dupQueueStmt = $pdo->prepare("
                SELECT id
                FROM service_requests
                WHERE recurring_profile_id = :profile_id
                  AND request_status IN ('new', 'queued')
                LIMIT 1
                FOR UPDATE
            ");
            $dupQueueStmt->execute([':profile_id' => $profileId]);
            $existingQueueId = (int) ($dupQueueStmt->fetchColumn() ?: 0);
            if ($existingQueueId > 0) {
                throw new RuntimeException('This recurring profile is already in the scheduling queue (job #' . $existingQueueId . ').');
            }

            $geoStmt = $pdo->prepare("
                SELECT latitude, longitude, geocode_status
                FROM service_requests
                WHERE customer_id = :customer_id
                  AND latitude IS NOT NULL
                  AND longitude IS NOT NULL
                ORDER BY id DESC
                LIMIT 1
            ");
            $geoStmt->execute([':customer_id' => (int) $profile['customer_id']]);
            $geo = $geoStmt->fetch(PDO::FETCH_ASSOC) ?: ['latitude' => null, 'longitude' => null, 'geocode_status' => null];

            $today = new DateTimeImmutable('today');
            $dueDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($profile['next_due_date'] ?? ''));
            $preferredDate = ($dueDate !== false && $dueDate > $today) ? $dueDate->format('Y-m-d') : $today->format('Y-m-d');

            $insertStmt = $pdo->prepare("
                INSERT INTO service_requests (
                    customer_id,
                    laser_brand,
                    laser_model,
                    laser_watts,
                    laser_age,
                    problem_summary,
                    problem_details,
                    priority_level,
                    source,
                    request_status,
                    latitude,
                    longitude,
                    geocode_status,
                    preferred_date_start,
                    preferred_date_end,
                    services,
                    other_service,
                    service_speed,
                    recurring_profile_id
                ) VALUES (
                    :customer_id,
                    :laser_brand,
                    :laser_model,
                    :laser_watts,
                    :laser_age,
                    :problem_summary,
                    :problem_details,
                    :priority_level,
                    'Internal',
                    'new',
                    :latitude,
                    :longitude,
                    :geocode_status,
                    :preferred_date_start,
                    :preferred_date_end,
                    :services,
                    :other_service,
                    :service_speed,
                    :recurring_profile_id
                )
            ");
            $insertStmt->execute([
                ':customer_id' => (int) $profile['customer_id'],
                ':laser_brand' => $profile['default_machine_brand'] ?: null,
                ':laser_model' => $profile['default_machine_model'] ?: null,
                ':laser_watts' => $profile['default_machine_watts'] ?: null,
                ':laser_age' => $profile['default_machine_age'] ?: null,
                ':problem_summary' => mb_substr((string) ($profile['default_problem_summary'] ?: 'Recurring service visit'), 0, 255),
                ':problem_details' => $profile['default_problem_details'] ?: ($profile['notes'] ?: null),
                ':priority_level' => strtolower((string) ($profile['priority_default'] ?? 'standard')),
                ':latitude' => $geo['latitude'],
                ':longitude' => $geo['longitude'],
                ':geocode_status' => $geo['geocode_status'],
                ':preferred_date_start' => $preferredDate,
                ':preferred_date_end' => $preferredDate,
                ':services' => $profile['default_services'] ?: null,
                ':other_service' => $profile['default_other_service'] ?: null,
                ':service_speed' => $profile['default_service_speed'] ?: 'standard',
                ':recurring_profile_id' => $profileId,
            ]);

            $newId = (int) $pdo->lastInsertId();
            $pdo->commit();
            $_SESSION['recurring_flash_success'] = 'Recurring profile added to scheduling queue as job #' . $newId . '.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['recurring_flash_error'] = $e->getMessage();
    }

    header('Location: recurring-services.php' . ($redirectQs !== '' ? '?' . $redirectQs : ''));
    exit;
}

$scope = trim((string) ($_GET['scope'] ?? 'due'));
$search = trim((string) ($_GET['q'] ?? ''));
$upcomingDays = 14;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = "(c.first_name LIKE :q OR c.last_name LIKE :q OR c.company LIKE :q OR c.email LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}

switch ($scope) {
    case 'overdue':
        $where[] = "r.active = 1";
        $where[] = "r.next_due_date < CURDATE()";
        break;
    case 'upcoming':
        $where[] = "r.active = 1";
        $where[] = "r.next_due_date > CURDATE()";
        $where[] = "r.next_due_date <= DATE_ADD(CURDATE(), INTERVAL :upcoming_days DAY)";
        $params[':upcoming_days'] = $upcomingDays;
        break;
    case 'inactive':
        $where[] = "r.active = 0";
        break;
    case 'all':
        break;
    case 'due_today':
        $where[] = "r.active = 1";
        $where[] = "r.next_due_date = CURDATE()";
        break;
    case 'active':
        $where[] = "r.active = 1";
        break;
    case 'due':
    default:
        $scope = 'due';
        $where[] = "r.active = 1";
        $where[] = "r.next_due_date <= CURDATE()";
        break;
}

$whereSql = implode(' AND ', $where);

$listStmt = $pdo->prepare("
    SELECT
        r.*,
        c.first_name,
        c.last_name,
        c.company,
        c.email,
        c.phone,
        c.city,
        (
            SELECT COUNT(*)
            FROM service_requests sr
            WHERE sr.recurring_profile_id = r.id
              AND sr.request_status IN ('new', 'queued')
        ) AS active_queue_jobs
    FROM recurring_service_customers r
    JOIN customers c ON c.id = r.customer_id
    WHERE {$whereSql}
    ORDER BY r.next_due_date ASC, r.id DESC
");
$listStmt->execute($params);
$profiles = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$services = $pdo->query("SELECT id, service_name FROM services ORDER BY service_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Recurring Services | Ghost Laser';
$pageDescription = 'Manage recurring service profiles and queue due jobs.';
$headerRight = '<a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>';
$extraHead = <<<'HTML'
<style>
    .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
    .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(2px); display: none; align-items: center; justify-content: center; z-index: 60; padding: 1rem; }
    .modal-overlay.open { display: flex; }
    .modal-box { width: min(980px, 96vw); max-height: 92vh; overflow: auto; border: 1px solid rgb(63,63,70); background: rgba(24,24,27,.98); border-radius: 1rem; }
    .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid rgb(63,63,70); }
    .modal-body { padding: 1.25rem; }
    .modal-footer { padding: 1rem 1.25rem; border-top: 1px solid rgb(63,63,70); display: flex; justify-content: flex-end; gap: .65rem; }
    .field { width: 100%; border: 1px solid rgb(63,63,70); background: rgb(9,9,11); color: #fff; border-radius: .5rem; padding: .55rem .75rem; font-size: .875rem; }
    .label { font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: rgb(161,161,170); margin-bottom: .35rem; display: block; font-weight: 600; }
    .customer-results { border: 1px solid rgb(63,63,70); border-radius: .5rem; overflow: hidden; margin-top: .5rem; }
    .customer-item { width: 100%; text-align: left; background: rgb(24,24,27); color: rgb(228,228,231); padding: .55rem .65rem; border-bottom: 1px solid rgb(39,39,42); font-size: .82rem; }
    .customer-item:hover { background: rgb(39,39,42); }
    .customer-item:last-child { border-bottom: 0; }
</style>
HTML;
require_once __DIR__ . '/templates/header.php';
?>
<main class="min-h-screen hero-grid pt-24 pb-16 px-4">
    <div class="max-w-7xl mx-auto">
        <div class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">Recurring Service Schedule</h1>
                    <p class="mt-2 text-sm text-zinc-400">Manage customer-linked recurring profiles and send due work into the scheduling queue.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="technician/schedule.php" class="inline-flex items-center rounded-md border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-zinc-200 hover:bg-zinc-700">Scheduling Queue</a>
                    <button type="button" onclick="openCreateModal()" class="inline-flex items-center rounded-md bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 btn-glow hover:bg-cyan-400">Add Recurring Profile</button>
                </div>
            </div>

            <?php if ($flashError !== ''): ?>
                <div class="mt-5 rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($flashSuccess !== ''): ?>
                <div class="mt-5 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="GET" class="mt-6 flex flex-wrap items-end gap-3">
                <div>
                    <label class="label">Scope</label>
                    <select name="scope" class="field">
                        <option value="due" <?= $scope === 'due' ? 'selected' : '' ?>>Due (today + overdue)</option>
                        <option value="due_today" <?= $scope === 'due_today' ? 'selected' : '' ?>>Due Today</option>
                        <option value="overdue" <?= $scope === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="upcoming" <?= $scope === 'upcoming' ? 'selected' : '' ?>>Upcoming (14 days)</option>
                        <option value="active" <?= $scope === 'active' ? 'selected' : '' ?>>All Active</option>
                        <option value="inactive" <?= $scope === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>All</option>
                    </select>
                </div>
                <div class="min-w-[260px]">
                    <label class="label">Search</label>
                    <input class="field" type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Customer, company, email">
                </div>
                <button type="submit" class="inline-flex items-center rounded-md bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400">Apply</button>
                <a href="recurring-services.php" class="inline-flex items-center rounded-md border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-200 hover:bg-zinc-700">Reset</a>
            </form>

            <div class="mt-6 overflow-hidden rounded-xl border border-zinc-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-800 text-sm">
                        <thead class="bg-zinc-900/90">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider text-zinc-500">Customer</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider text-zinc-500">Frequency</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider text-zinc-500">Last Serviced</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider text-zinc-500">Next Due</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider text-zinc-500">Priority</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider text-zinc-500">Queue</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider text-zinc-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/90 bg-zinc-950/70">
                        <?php if ($profiles === []): ?>
                            <tr><td colspan="7" class="px-4 py-8 text-center text-zinc-400">No recurring profiles found for this view.</td></tr>
                        <?php else: ?>
                            <?php foreach ($profiles as $profile): ?>
                                <?php
                                $fullName = trim((string) ($profile['first_name'] ?? '') . ' ' . (string) ($profile['last_name'] ?? ''));
                                $displayName = $fullName !== '' ? $fullName : ((string) ($profile['company'] ?? 'Customer #' . (int) $profile['customer_id']));
                                $dueBadge = recGetDueBadge($profile['next_due_date'] ?? null, (int) ($profile['active'] ?? 0) === 1);
                                $rowJson = htmlspecialchars(json_encode($profile, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr class="hover:bg-zinc-900/70">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-white"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-xs text-zinc-500"><?= htmlspecialchars((string) ($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-zinc-300"><?= (int) $profile['frequency_value'] ?> <?= htmlspecialchars((string) $profile['frequency_unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-4 py-3 text-zinc-300"><?= htmlspecialchars((string) ($profile['last_serviced_date'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-4 py-3">
                                        <div class="text-zinc-200"><?= htmlspecialchars((string) $profile['next_due_date'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <span class="mt-1 inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold <?= htmlspecialchars($dueBadge['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dueBadge['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td class="px-4 py-3 text-zinc-300"><?= htmlspecialchars(ucfirst((string) $profile['priority_default']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-4 py-3 text-zinc-300"><?= (int) $profile['active_queue_jobs'] ?></td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <form method="POST">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="add_to_schedule">
                                                <input type="hidden" name="profile_id" value="<?= (int) $profile['id'] ?>">
                                                <input type="hidden" name="scope" value="<?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="rounded-md bg-cyan-500 px-3 py-1.5 text-xs font-semibold text-zinc-950 hover:bg-cyan-400">Add to Schedule</button>
                                            </form>
                                            <button type="button" onclick="openEditModal(<?= $rowJson ?>)" class="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-200 hover:bg-zinc-700">Edit</button>
                                            <form method="POST" onsubmit="return confirm('Delete this recurring profile?');">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="delete_profile">
                                                <input type="hidden" name="profile_id" value="<?= (int) $profile['id'] ?>">
                                                <input type="hidden" name="scope" value="<?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="rounded-md border border-red-700/70 bg-red-950/30 px-3 py-1.5 text-xs text-red-300 hover:bg-red-950/60">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<div id="profileModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="profileModalTitle" class="text-lg font-semibold text-white">Recurring Profile</h2>
            <button type="button" onclick="closeProfileModal()" class="text-zinc-400 hover:text-white">&times;</button>
        </div>
        <form method="POST" id="profileForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" id="formAction" value="create_profile">
            <input type="hidden" name="profile_id" id="profileId" value="0">
            <input type="hidden" name="scope" value="<?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <div class="modal-body space-y-5">
                <div>
                    <label class="label">Customer</label>
                    <input type="hidden" name="customer_id" id="customerId">
                    <input type="text" id="customerSearch" class="field" placeholder="Search customer by name, company, email or phone">
                    <div id="customerSelected" class="mt-2 text-xs text-cyan-300"></div>
                    <div id="customerResults" class="customer-results hidden"></div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="label">Frequency Value</label>
                        <input type="number" min="1" name="frequency_value" id="frequencyValue" class="field" value="1" required>
                    </div>
                    <div>
                        <label class="label">Frequency Unit</label>
                        <select name="frequency_unit" id="frequencyUnit" class="field">
                            <option value="days">Days</option>
                            <option value="weeks">Weeks</option>
                            <option value="months" selected>Months</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Last Serviced Date</label>
                        <input type="date" name="last_serviced_date" id="lastServicedDate" class="field">
                    </div>
                    <div>
                        <label class="label">Next Due Date</label>
                        <input type="date" name="next_due_date" id="nextDueDate" class="field">
                    </div>
                    <div>
                        <label class="label">Priority Default</label>
                        <select name="priority_default" id="priorityDefault" class="field">
                            <option value="standard">Standard</option>
                            <option value="vip">VIP</option>
                            <option value="emergency">Emergency</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Service Speed</label>
                        <select name="default_service_speed" id="defaultServiceSpeed" class="field">
                            <option value="standard">Standard</option>
                            <option value="rush">VIP</option>
                            <option value="emergency">Emergency</option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="label">Machine Brand</label>
                        <input type="text" name="default_machine_brand" id="defaultMachineBrand" class="field">
                    </div>
                    <div>
                        <label class="label">Machine Model</label>
                        <input type="text" name="default_machine_model" id="defaultMachineModel" class="field">
                    </div>
                    <div>
                        <label class="label">Machine Watts</label>
                        <input type="text" name="default_machine_watts" id="defaultMachineWatts" class="field">
                    </div>
                    <div>
                        <label class="label">Machine Age</label>
                        <input type="text" name="default_machine_age" id="defaultMachineAge" class="field">
                    </div>
                </div>

                <div>
                    <label class="label">Default Problem Summary</label>
                    <input type="text" name="default_problem_summary" id="defaultProblemSummary" class="field" maxlength="255" placeholder="Recurring service visit">
                </div>
                <div>
                    <label class="label">Default Problem Details</label>
                    <textarea name="default_problem_details" id="defaultProblemDetails" class="field" rows="3"></textarea>
                </div>
                <div>
                    <label class="label">Default Other Service Notes</label>
                    <textarea name="default_other_service" id="defaultOtherService" class="field" rows="2"></textarea>
                </div>
                <div>
                    <label class="label">Default Services</label>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <?php foreach ($services as $service): ?>
                            <label class="inline-flex items-center gap-2 rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-200">
                                <input type="checkbox" name="default_services[]" value="<?= (int) $service['id'] ?>" class="service-cb">
                                <span><?= htmlspecialchars((string) $service['service_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label class="label">Notes</label>
                    <textarea name="notes" id="profileNotes" class="field" rows="3"></textarea>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-zinc-200">
                    <input type="checkbox" name="active" id="profileActive" checked>
                    Active profile
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeProfileModal()" class="rounded-md border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-200 hover:bg-zinc-700">Cancel</button>
                <button type="submit" class="rounded-md bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400">Save Profile</button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const customerSearchInput = document.getElementById('customerSearch');
const customerResultsEl = document.getElementById('customerResults');
const customerIdEl = document.getElementById('customerId');
const customerSelectedEl = document.getElementById('customerSelected');
const serviceCheckboxes = Array.from(document.querySelectorAll('.service-cb'));

function closeProfileModal() {
    document.getElementById('profileModal').classList.remove('open');
}

function resetProfileForm() {
    document.getElementById('profileForm').reset();
    document.getElementById('formAction').value = 'create_profile';
    document.getElementById('profileId').value = '0';
    customerIdEl.value = '';
    customerSearchInput.value = '';
    customerSelectedEl.textContent = '';
    serviceCheckboxes.forEach(cb => cb.checked = false);
}

function openCreateModal() {
    resetProfileForm();
    document.getElementById('profileModalTitle').textContent = 'Add Recurring Profile';
    document.getElementById('profileActive').checked = true;
    document.getElementById('profileModal').classList.add('open');
}

function setSelectedCustomer(customer) {
    customerIdEl.value = String(customer.id || '');
    const fullName = [customer.first_name || '', customer.last_name || ''].join(' ').trim();
    customerSelectedEl.textContent = `Selected: ${fullName || 'Customer #' + customer.id} · ${customer.email || ''}`;
    customerSearchInput.value = fullName || (customer.company || '');
    customerResultsEl.classList.add('hidden');
    customerResultsEl.innerHTML = '';
    if (!document.getElementById('defaultMachineBrand').value) document.getElementById('defaultMachineBrand').value = customer.machine_brand || '';
    if (!document.getElementById('defaultMachineModel').value) document.getElementById('defaultMachineModel').value = customer.machine_model || '';
    if (!document.getElementById('defaultMachineWatts').value) document.getElementById('defaultMachineWatts').value = customer.machine_watts || '';
    if (!document.getElementById('defaultMachineAge').value) document.getElementById('defaultMachineAge').value = customer.machine_age || '';
}

function openEditModal(profile) {
    resetProfileForm();
    document.getElementById('profileModalTitle').textContent = 'Edit Recurring Profile';
    document.getElementById('formAction').value = 'update_profile';
    document.getElementById('profileId').value = profile.id || 0;
    customerIdEl.value = profile.customer_id || '';
    const fullName = [profile.first_name || '', profile.last_name || ''].join(' ').trim();
    customerSearchInput.value = fullName;
    customerSelectedEl.textContent = `Selected: ${fullName || 'Customer #' + profile.customer_id}`;
    document.getElementById('frequencyValue').value = profile.frequency_value || 1;
    document.getElementById('frequencyUnit').value = profile.frequency_unit || 'months';
    document.getElementById('lastServicedDate').value = profile.last_serviced_date || '';
    document.getElementById('nextDueDate').value = profile.next_due_date || '';
    document.getElementById('priorityDefault').value = profile.priority_default || 'standard';
    document.getElementById('defaultServiceSpeed').value = profile.default_service_speed || 'standard';
    document.getElementById('defaultMachineBrand').value = profile.default_machine_brand || '';
    document.getElementById('defaultMachineModel').value = profile.default_machine_model || '';
    document.getElementById('defaultMachineWatts').value = profile.default_machine_watts || '';
    document.getElementById('defaultMachineAge').value = profile.default_machine_age || '';
    document.getElementById('defaultProblemSummary').value = profile.default_problem_summary || '';
    document.getElementById('defaultProblemDetails').value = profile.default_problem_details || '';
    document.getElementById('defaultOtherService').value = profile.default_other_service || '';
    document.getElementById('profileNotes').value = profile.notes || '';
    document.getElementById('profileActive').checked = String(profile.active) === '1';
    let selectedServices = [];
    if (profile.default_services) {
        try {
            const decoded = JSON.parse(profile.default_services);
            if (Array.isArray(decoded)) selectedServices = decoded.map(v => parseInt(v, 10));
        } catch (_) {}
    }
    serviceCheckboxes.forEach(cb => {
        cb.checked = selectedServices.includes(parseInt(cb.value, 10));
    });
    document.getElementById('profileModal').classList.add('open');
}

let searchTimer = null;
customerSearchInput.addEventListener('input', () => {
    const q = customerSearchInput.value.trim();
    if (searchTimer) clearTimeout(searchTimer);
    if (q.length < 2) {
        customerResultsEl.classList.add('hidden');
        customerResultsEl.innerHTML = '';
        return;
    }
    searchTimer = setTimeout(async () => {
        try {
            const url = new URL(window.location.origin + '/recurring-services.php');
            url.searchParams.set('action', 'customer_search');
            url.searchParams.set('q', q);
            const response = await fetch(url.toString(), {
                headers: { 'X-CSRF-Token': csrfToken }
            });
            const json = await response.json();
            const rows = Array.isArray(json.results) ? json.results : [];
            if (rows.length === 0) {
                customerResultsEl.innerHTML = '<div class="px-3 py-2 text-xs text-zinc-400 bg-zinc-900">No customers found.</div>';
                customerResultsEl.classList.remove('hidden');
                return;
            }
            customerResultsEl.innerHTML = rows.map((row) => {
                const fullName = `${row.first_name || ''} ${row.last_name || ''}`.trim();
                const company = row.company ? ` · ${row.company}` : '';
                return `<button type="button" class="customer-item" data-row='${JSON.stringify(row).replace(/'/g, '&#39;')}'>${fullName || 'Customer #' + row.id}${company}<div class="text-[11px] text-zinc-500">${row.email || ''} ${row.phone || ''}</div></button>`;
            }).join('');
            customerResultsEl.classList.remove('hidden');
            customerResultsEl.querySelectorAll('.customer-item').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const payload = btn.getAttribute('data-row') || '{}';
                    try {
                        setSelectedCustomer(JSON.parse(payload));
                    } catch (_) {}
                });
            });
        } catch (_) {
            customerResultsEl.innerHTML = '<div class="px-3 py-2 text-xs text-red-300 bg-zinc-900">Customer search failed.</div>';
            customerResultsEl.classList.remove('hidden');
        }
    }, 220);
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeProfileModal();
});
document.getElementById('profileModal').addEventListener('mousedown', (event) => {
    if (event.target.id === 'profileModal') closeProfileModal();
});
</script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
