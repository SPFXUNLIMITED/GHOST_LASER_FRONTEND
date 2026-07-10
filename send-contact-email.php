<?php
// Contact Form Handler
require_once __DIR__ . '/smtp_config.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Honeypot check
if (!empty($_POST )) {
    header('Location: index.php?status=success#contact');
    exit;
}

$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST ?? '');
$machine = trim($_POST ?? '');
$issue   = trim($_POST ?? '');

if (empty($name) || empty($email) || empty($issue)) {
    header('Location: index.php?status=error#contact');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: index.php?status=error#contact');
    exit;
}

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $SMTP_USERNAME;
    $mail->Password   = $SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $SMTP_PORT;

    $mail->setFrom($SMTP_FROM_EMAIL, $SMTP_FROM_NAME);
    $mail->addAddress($ADMIN_EMAIL);
    $mail->addReplyTo($email, $name);

    $mail->Subject = "New Contact Form Submission from $name";

    $body = "Name: $name\n";
    $body .= "Email: $email\n";
    if ($machine !== '') $body .= "Machine: $machine\n";
    $body .= "\nMessage:\n$issue\n";

    $mail->Body = $body;
    $mail->send();

    header('Location: index.php?status=success#contact');
    exit;

} catch (Exception $e) {
    error_log("Contact form error: " . $e->getMessage());
    header('Location: index.php?status=error#contact');
    exit;
}
?>