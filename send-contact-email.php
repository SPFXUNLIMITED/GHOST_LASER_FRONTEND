<?php
declare(strict_types=1);

require_once __DIR__ . '/smtp_config.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Redirects back to the contact section with status and optional message.
 */
function redirectWithStatus(string $status, string $message = ''): void
{
    $query = ['status' => $status];
    if ($message !== '') {
        $query['contact_message'] = $message;
    }

    header('Location: index.php?' . http_build_query($query) . '#contact');
    exit;
}

/**
 * Logs contact form errors in a consistent format.
 */
function logContactFormError(string $error, array $context = []): void
{
    $safeContext = [];
    foreach ($context as $key => $value) {
        $safeContext[$key] = is_scalar($value) ? (string) $value : '[non-scalar]';
    }

    error_log('[CONTACT_FORM] ' . $error . ' | context=' . json_encode($safeContext));
}

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithStatus('error', 'Invalid request method. Please submit the contact form.');
}

// Honeypot trap: act like success to avoid teaching bots.
if (!empty($_POST[$HONEYPOT_FIELD])) {
    redirectWithStatus('success', 'Thanks! Your message has been sent.');
}

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$machine = trim((string) ($_POST['machine'] ?? ''));
$issue = trim((string) ($_POST['issue'] ?? ''));

if ($name === '' || $email === '' || $issue === '') {
    redirectWithStatus('error', 'Please complete name, email, and issue details.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirectWithStatus('error', 'Please enter a valid email address.');
}

try {
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = $SMTP_HOST;
    $mailer->SMTPAuth = true;
    $mailer->Username = $SMTP_USERNAME;
    $mailer->Password = $SMTP_PASSWORD;
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Port = $SMTP_PORT;

    // HostGator SSL fix.
    $mailer->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ];

    $mailer->setFrom($SMTP_FROM_EMAIL, $SMTP_FROM_NAME);
    $mailer->addAddress($ADMIN_EMAIL);
    $mailer->addReplyTo($email, $name);
    $mailer->Subject = 'New Contact Form Submission from ' . $name;
    $mailer->isHTML(false);

    $body = "New message from the website contact form:\n\n";
    $body .= "Name: {$name}\n";
    $body .= "Email: {$email}\n";
    if ($machine !== '') {
        $body .= "Machine: {$machine}\n";
    }
    $body .= "\nMessage:\n{$issue}\n";
    $mailer->Body = $body;

    $mailer->send();
    redirectWithStatus('success', 'Thanks! Your message has been sent. We will reply shortly.');
} catch (Exception $e) {
    logContactFormError('SMTP send failure', [
        'phpmailer_error' => $e->getMessage(),
        'email' => $email,
        'name' => $name,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);

    redirectWithStatus('error', 'Message could not be sent right now. Please try again in a few minutes.');
} catch (Throwable $e) {
    logContactFormError('Unexpected contact form error', [
        'error' => $e->getMessage(),
        'email' => $email,
        'name' => $name,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);

    redirectWithStatus('error', 'Unexpected server error. Please email us directly if this continues.');
}