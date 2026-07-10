<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/smtp_config.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER !== 'POST') {
    echo "This file only accepts POST requests.";
    exit;
}

$name    = trim($_POST ?? '');
$email   = trim($_POST ?? '');
$machine = trim($_POST ?? '');
$issue   = trim($_POST ?? '');

echo "<h2>Debug Mode - Form Received</h2>";
echo "Name: " . htmlspecialchars($name) . "<br>";
echo "Email: " . htmlspecialchars($email) . "<br>";
echo "Machine: " . htmlspecialchars($machine) . "<br>";
echo "Issue length: " . strlen($issue) . " characters<br><br>";

try {
    echo "Creating PHPMailer object...<br>";
    
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host       = $SMTP_HOST;
    $mailer->SMTPAuth   = true;
    $mailer->Username   = $SMTP_USERNAME;
    $mailer->Password   = $SMTP_PASSWORD;
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Port       = $SMTP_PORT;

    $mailer->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );

    echo "Connecting to SMTP server...<br>";
    
    $mailer->setFrom($SMTP_FROM_EMAIL, $SMTP_FROM_NAME);
    $mailer->addAddress($ADMIN_EMAIL);
    $mailer->addReplyTo($email, $name);
    $mailer->Subject = "New Contact Form Submission from $name";
    $mailer->Body = "Name: $name\nEmail: $email\nMachine: $machine\n\nMessage:\n$issue";

    echo "Sending email...<br>";
    
    if ($mailer->send()) {
        echo "<strong style='color:green'>SUCCESS! Email was sent.</strong>";
    } else {
        echo "<strong style='color:red'>Failed to send email.</strong>";
    }

} catch (Exception $e) {
    echo "<strong style='color:red'>ERROR: " . htmlspecialchars($e->getMessage()) . "</strong>";
} catch (Throwable $e) {
    echo "<strong style='color:red'>FATAL ERROR: " . htmlspecialchars($e->getMessage()) . "</strong>";
}
exit;
?>