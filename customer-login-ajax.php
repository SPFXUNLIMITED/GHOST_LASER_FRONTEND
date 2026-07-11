<?php
session_start();
header('Content-Type: application/json');

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

require_once __DIR__ . '/project/db.php';

$stmt = $pdo->prepare(
    'SELECT id, first_name, last_name, email, password_hash, phone, street, city, state, zip FROM customers WHERE email = ? LIMIT 1'
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
        'street'     => $customer['street'] ?? '',
        'city'       => $customer['city'] ?? '',
        'state'      => $customer['state'] ?? '',
        'zip'        => $customer['zip'] ?? '',
    ]);
} else {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password. Please try again.']);
}
