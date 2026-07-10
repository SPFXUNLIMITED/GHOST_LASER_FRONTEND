<?php
// ===============================================
// Contact Form Handler - Frontend Version
// ===============================================

require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ================== CONFIG ==================
$SMTP_HOST       = 'mail.LaserCutterRepair.com';
$SMTP_PORT       = 587;
$SMTP_USERNAME   = 'sales@LaserCutterRepair.com';
$SMTP_PASSWORD = '@O+xmLX^*Lxt';                    // Change this
$SMTP_FROM_EMAIL = 'sales@LaserCutterRepair.com';
$SMTP_FROM_NAME  = 'Ghost Laser';
$ADMIN_EMAIL     = 'sales@LaserCutterRepair.com';  // Change to your real email if different

// Honeypot field name
$HONEYPOT_FIELD = 'website';

// ================== PROCESS FORM ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Honeypot check
    if (!empty($_POST[$HONEYPOT_FIELD])) {
        header('Location: index.php?status=success#contact');
        exit;
    }

    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $machine = trim($_POST['machine'] ?? '');
    $issue   = trim($_POST['issue'] ?? '');

    if ($name === '' || $email === '' || $issue === '') {
        header('Location: index.php?status=error#contact');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: index.php?status=error#contact');
        exit;
    }

    $mailer = new PHPMailer(true);

    try {
        $mailer->isSMTP();
        $mailer->Host       = $SMTP_HOST;
        $mailer->SMTPAuth   = true;
        $mailer->Username   = $SMTP_USERNAME;
        $mailer->Password   = $SMTP_PASSWORD;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port       = $SMTP_PORT;

        $mailer->setFrom($SMTP_FROM_EMAIL, $SMTP_FROM_NAME);
        $mailer->addAddress($ADMIN_EMAIL);
        $mailer->addReplyTo($email, $name);

        $mailer->Subject = "New Contact Form Submission from $name";

        $body = "New message from the website contact form:\n\n";
        $body .= "Name: $name\n";
        $body .= "Email: $email\n";
        if ($machine !== '') $body .= "Machine: $machine\n";
        $body .= "\nMessage:\n$issue\n";

        $mailer->isHTML(false);
        $mailer->Body = $body;

        $mailer->send();

        header('Location: index.php?status=success#contact');
        exit;

    } catch (Exception $e) {
        error_log("Contact form error: " . $e->getMessage());
        header('Location: index.php?status=error#contact');
        exit;
    }
}

// If someone visits directly, redirect to home
header('Location: index.php');
exit;