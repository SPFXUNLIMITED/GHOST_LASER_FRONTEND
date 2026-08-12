<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/project/db.php';

function getCustomerSearchSelectColumns(PDO $pdo): array
{
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
        // Fall back to base fields.
    }

    return $baseColumns;
}

function ensureCustomerStatusTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_status (
            customer_id INT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
            status ENUM('VIP','Good','Caution','Banned') NOT NULL DEFAULT 'Good',
            notes TEXT NULL,
            has_outstanding_balance TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by VARCHAR(255) NULL,
            PRIMARY KEY (customer_id),
            CONSTRAINT fk_customer_status_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $pdo->exec("ALTER TABLE customer_status MODIFY COLUMN status ENUM('VIP','Good','Caution','Banned') NOT NULL DEFAULT 'Good'");
    } catch (Throwable $e) {
        // Ignore compatibility errors.
    }
}

function mapCustomerStatusResults(array $rows): array
{
    $results = [];
    foreach ($rows as $row) {
        $customerName = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
        $results[] = [
            'id'            => (int) ($row['id'] ?? 0),
            'customer_name' => $customerName,
            'first_name'    => (string) ($row['first_name'] ?? ''),
            'last_name'     => (string) ($row['last_name'] ?? ''),
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
            'rating'        => (int) ($row['customer_rating'] ?? 5),
            'status'        => (string) ($row['customer_status'] ?? 'Good'),
            'notes'         => (string) ($row['customer_status_notes'] ?? ''),
            'has_outstanding_balance' => (int) ($row['customer_has_outstanding_balance'] ?? 0) === 1,
        ];
    }

    return $results;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array((string) ($_GET['action'] ?? ''), ['customer_search', 'customer_status_list'], true)) {
    if (empty($_SESSION['admin_id'])) {
        http_response_code(403);
        echo json_encode(['results' => [], 'error' => 'Admin authentication required.']);
        exit;
    }

    $csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $allowedTokens = array_filter([
        (string) ($_SESSION['book_internal_customer_search_csrf'] ?? ''),
        (string) ($_SESSION['recurring_csrf'] ?? ''),
        (string) ($_SESSION['customer_status_csrf'] ?? ''),
    ], static fn(string $token): bool => $token !== '');
    if ($csrfHeader === '' || $allowedTokens === [] || !in_array($csrfHeader, $allowedTokens, true)) {
        http_response_code(403);
        echo json_encode(['results' => [], 'error' => 'Invalid CSRF token.']);
        exit;
    }

    $action = (string) ($_GET['action'] ?? '');

    if ($action === 'customer_status_list') {
        $statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
        $statusMap = [
            'vip' => 'VIP',
            'good' => 'Good',
            'caution' => 'Caution',
            'banned' => 'Banned',
        ];

        if ($statusFilter !== '' && !isset($statusMap[$statusFilter])) {
            http_response_code(400);
            echo json_encode(['results' => [], 'error' => 'Invalid status filter.']);
            exit;
        }

        try {
            ensureCustomerStatusTable($pdo);
            $sql = '
                SELECT
                    c.id,
                    c.first_name,
                    c.last_name,
                    c.company,
                    c.email,
                    c.phone,
                    c.address,
                    c.city,
                    c.state,
                    c.zip,
                    COALESCE(cs.rating, 5) AS customer_rating,
                    COALESCE(cs.status, \'Good\') AS customer_status,
                    COALESCE(cs.notes, \'\') AS customer_status_notes,
                    COALESCE(cs.has_outstanding_balance, 0) AS customer_has_outstanding_balance
                FROM customers c
                LEFT JOIN customer_status cs ON cs.customer_id = c.id
            ';
            $params = [];
            if ($statusFilter !== '') {
                $sql .= ' WHERE COALESCE(cs.status, \'Good\') = :status';
                $params[':status'] = $statusMap[$statusFilter];
            }
            $sql .= ' ORDER BY FIELD(COALESCE(cs.status, \'Good\'), \'VIP\', \'Good\', \'Caution\', \'Banned\'), c.last_name, c.first_name';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['results' => [], 'error' => 'Customer list failed.']);
            exit;
        }

        echo json_encode(['results' => mapCustomerStatusResults($rows)]);
        exit;
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        ensureCustomerStatusTable($pdo);
        $like = '%' . $q . '%';
        $searchColumns = getCustomerSearchSelectColumns($pdo);
        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', array_map(static fn(string $column): string => 'c.' . $column, $searchColumns)) . ',
                    COALESCE(cs.rating, 5) AS customer_rating,
                    COALESCE(cs.status, \'Good\') AS customer_status,
                    COALESCE(cs.notes, \'\') AS customer_status_notes,
                    COALESCE(cs.has_outstanding_balance, 0) AS customer_has_outstanding_balance
             FROM customers c
             LEFT JOIN customer_status cs ON cs.customer_id = c.id
             WHERE c.first_name LIKE :first_name_q
                OR c.last_name LIKE :last_name_q
                OR c.company LIKE :company_q
                OR c.email LIKE :email_q
                OR c.phone LIKE :phone_q
             ORDER BY FIELD(COALESCE(cs.status, \'Good\'), \'VIP\', \'Good\', \'Caution\', \'Banned\'), c.last_name, c.first_name
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

    echo json_encode(['results' => mapCustomerStatusResults($rows)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$email    = trim($body['email'] ?? '');
$password = $body['password'] ?? '';

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter your email and password.']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, first_name, last_name, email, password_hash, phone, address, city, state, zip FROM customers WHERE email = ? LIMIT 1'
);
$stmt->execute([$email]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if ($customer && !empty($customer['password_hash']) && password_verify($password, $customer['password_hash'])) {
    session_regenerate_id(true);
    $_SESSION['customer_id']         = (int) $customer['id'];
    $_SESSION['customer_first_name'] = $customer['first_name'];
    $_SESSION['customer_last_name']  = $customer['last_name'];
    $_SESSION['customer_email']      = $customer['email'];

    echo json_encode([
        'success'    => true,
        'first_name' => $customer['first_name'],
        'last_name'  => $customer['last_name'],
        'email'      => $customer['email'],
        'phone'      => $customer['phone'] ?? '',
        'address'     => $customer['address'] ?? '',
        'city'       => $customer['city'] ?? '',
        'state'      => $customer['state'] ?? '',
        'zip'        => $customer['zip'] ?? '',
    ]);
} else {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password. Please try again.']);
}
