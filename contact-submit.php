<?php
// Honeypot check — bots fill hidden fields, humans leave them empty
if (!empty($_POST['website'])) {
    // Silent redirect; don't tell the bot it was caught
    header('Location: index.php?status=success');
    exit;
}

$name    = trim($_POST['name']    ?? '');
$email   = trim($_POST['email']   ?? '');
$machine = trim($_POST['machine'] ?? '');
$issue   = trim($_POST['issue']   ?? '');

// Basic validation
if ($name === '' || $email === '' || $issue === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: index.php?status=error#contact');
    exit;
}

// Sanitise values before use
$name    = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
$email   = htmlspecialchars($email,   ENT_QUOTES, 'UTF-8');
$machine = htmlspecialchars($machine, ENT_QUOTES, 'UTF-8');
$issue   = htmlspecialchars($issue,   ENT_QUOTES, 'UTF-8');

$to      = 'info@ghostlaser.co.uk'; // change to your address
$subject = 'New Contact Request from ' . $name;
$body    = "Name: {$name}\nEmail: {$email}\nMachine: {$machine}\n\nIssue:\n{$issue}";
$headers = "From: noreply@ghostlaser.co.uk\r\nReply-To: {$email}\r\nContent-Type: text/plain; charset=UTF-8";

if (mail($to, $subject, $body, $headers)) {
    header('Location: index.php?status=success#contact');
} else {
    header('Location: index.php?status=error#contact');
}
exit;
