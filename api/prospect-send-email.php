<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once dirname(__DIR__) . '/project/db.php';
require_once dirname(__DIR__) . '/prospects_schema.php';
require_once dirname(__DIR__) . '/smtp_config.php';
require_once dirname(__DIR__) . '/lib/PHPMailer/src/Exception.php';
require_once dirname(__DIR__) . '/lib/PHPMailer/src/PHPMailer.php';
require_once dirname(__DIR__) . '/lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

prospectsEnsureSchema($pdo);

$adminId = (int) $_SESSION['admin_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── GET: return templates list ────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $rows = $pdo->query(
            "SELECT id, title, subject, body FROM prospect_notification_templates ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['templates' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load templates.']);
    }
    exit;
}

// ── POST: send email and log interaction ─────────────────────────────────────
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

try {
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody ?: '{}', true) ?: [];

    $sessionCsrf = (string) ($_SESSION['prospects_csrf'] ?? '');
    $requestCsrf = trim((string) ($data['csrf'] ?? ''));

    if ($sessionCsrf === '' || $requestCsrf === '' || !hash_equals($sessionCsrf, $requestCsrf)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token.']);
        exit;
    }

    $prospectId = (int) ($data['prospect_id'] ?? 0);
    $subject    = trim((string) ($data['subject'] ?? ''));
    $body       = trim((string) ($data['body'] ?? ''));

    if ($prospectId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid prospect ID.']);
        exit;
    }
    if ($subject === '' || $body === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Subject and body are required.']);
        exit;
    }

    // Load prospect
    $pStmt = $pdo->prepare(
        "SELECT id, company, contact_name, phone, email, website, status, last_called_at, last_emailed_at
         FROM prospects WHERE id = :id AND is_archived = 0 LIMIT 1"
    );
    $pStmt->execute([':id' => $prospectId]);
    $prospect = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$prospect) {
        http_response_code(404);
        echo json_encode(['error' => 'Prospect not found.']);
        exit;
    }

    $toEmail = trim((string) ($prospect['email'] ?? ''));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['error' => 'Prospect does not have a valid email address.']);
        exit;
    }

    // Send via PHPMailer
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host      = $SMTP_HOST;
    $mailer->SMTPAuth  = true;
    $mailer->Username  = $SMTP_USERNAME;
    $mailer->Password  = $SMTP_PASSWORD;
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Port      = $SMTP_PORT;
    $mailer->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $toName = trim((string) ($prospect['contact_name'] ?? $prospect['company'] ?? ''));
    $mailer->setFrom($SMTP_FROM_EMAIL, $SMTP_FROM_NAME);
    $mailer->addAddress($toEmail, $toName);
    $mailer->Subject = $subject;
    $mailer->isHTML(false);
    $mailer->Body = $body;

    $mailer->send();

    // Log interaction
    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d H:i:s');

    $pdo->beginTransaction();

    $insert = $pdo->prepare("
        INSERT INTO prospect_interactions (
            prospect_id, interaction_type, outcome, interaction_notes, interacted_at, admin_id
        ) VALUES (
            :prospect_id, 'email', :outcome, :notes, :at, :admin_id
        )
    ");
    $insert->execute([
        ':prospect_id' => $prospectId,
        ':outcome'     => 'Email sent',
        ':notes'       => 'Subject: ' . mb_substr($subject, 0, 200),
        ':at'          => $now,
        ':admin_id'    => $adminId > 0 ? $adminId : null,
    ]);

    $pdo->prepare("UPDATE prospects SET last_emailed_at = :ts, updated_by = :admin_id WHERE id = :id")
        ->execute([':ts' => $now, ':id' => $prospectId, ':admin_id' => $adminId > 0 ? $adminId : null]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Email sent and interaction logged.']);
} catch (MailerException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Email could not be sent: ' . $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
