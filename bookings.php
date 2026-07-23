<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/functions.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function bk_fmtDateTime(?string $dt): string
{
    if ($dt === null || $dt === '') return '—';
    try {
        $d = new DateTimeImmutable($dt, new DateTimeZone('America/Los_Angeles'));
        return $d->format('m/d/Y g:i A') . ' PT';
    } catch (Exception $e) {
        return htmlspecialchars($dt, ENT_QUOTES, 'UTF-8');
    }
}

function bk_statusInfo(string $status): array
{
    return match ($status) {
        'abandoned' => ['label' => 'Abandoned', 'class' => 'badge-abandoned'],
        'new'       => ['label' => 'New',      'class' => 'badge-new'],
        'queued'    => ['label' => 'Queued',    'class' => 'badge-queued'],
        'completed' => ['label' => 'Completed', 'class' => 'badge-completed'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'badge-cancelled'],
        'deleted'   => ['label' => 'Deleted',   'class' => 'badge-deleted'],
        default     => ['label' => ucfirst($status), 'class' => 'badge-new'],
    };
}

function bk_priorityInfo(string $priority): array
{
    return match (strtolower($priority)) {
        'emergency' => ['label' => 'Emergency', 'class' => 'priority-emergency'],
        'vip'       => ['label' => 'VIP',        'class' => 'priority-vip'],
        default     => ['label' => 'Standard',   'class' => 'priority-standard'],
    };
}

function bk_sourceInfo(string $source): array
{
    return match ($source) {
        'Internal' => ['label' => 'Internal', 'class' => 'badge-source-internal'],
        default    => ['label' => 'Website',  'class' => 'badge-source-website'],
    };
}

function bk_tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function bk_deleteBookings(PDO $pdo, array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if ($ids === []) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    try {
        $pdo->beginTransaction();

        $clusterIds = [];
        if (bk_tableExists($pdo, 'scheduled_cluster_jobs')) {
            $clusterIdsStmt = $pdo->prepare("
                SELECT DISTINCT scheduled_cluster_id
                FROM scheduled_cluster_jobs
                WHERE service_request_id IN ($placeholders)
            ");
            $clusterIdsStmt->execute($ids);
            $clusterIds = array_map('intval', $clusterIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

            $deleteScheduledJobsStmt = $pdo->prepare("
                DELETE FROM scheduled_cluster_jobs
                WHERE service_request_id IN ($placeholders)
            ");
            $deleteScheduledJobsStmt->execute($ids);
        }

        $deleteBookingsStmt = $pdo->prepare("
            DELETE FROM service_requests
            WHERE id IN ($placeholders)
        ");
        $deleteBookingsStmt->execute($ids);
        $deletedCount = (int) $deleteBookingsStmt->rowCount();

        if ($clusterIds !== [] && bk_tableExists($pdo, 'scheduled_clusters')) {
            $clusterPlaceholders = implode(',', array_fill(0, count($clusterIds), '?'));
            $deleteEmptyClustersStmt = $pdo->prepare("
                DELETE FROM scheduled_clusters
                WHERE id IN ($clusterPlaceholders)
                  AND NOT EXISTS (
                      SELECT 1
                      FROM scheduled_cluster_jobs
                      WHERE scheduled_cluster_id = scheduled_clusters.id
                  )
            ");
            $deleteEmptyClustersStmt->execute($clusterIds);
        }

        $pdo->commit();
        return $deletedCount;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

$adminUsername = trim((string) ($_SESSION['admin_username'] ?? 'Admin'));
if ($adminUsername === '') $adminUsername = 'Admin';

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['bk_csrf'])) {
    $_SESSION['bk_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['bk_csrf'];

// ── Ensure service_requests table has created_at (idempotent) ─────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS service_requests (
            id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id          INT UNSIGNED NOT NULL,
            laser_brand          VARCHAR(255) NULL,
            laser_model          VARCHAR(255) NULL,
            laser_watts          VARCHAR(100) NULL,
            laser_age            VARCHAR(100) NULL,
            problem_summary      TEXT NULL,
            problem_details      TEXT NULL,
            priority_level       VARCHAR(50)  NOT NULL DEFAULT 'standard',
            source               VARCHAR(50)  NOT NULL DEFAULT 'api',
            request_status       VARCHAR(50)  NOT NULL DEFAULT 'new',
            latitude             DECIMAL(10,7) NULL,
            longitude            DECIMAL(10,7) NULL,
            geocode_status       VARCHAR(50)  NULL,
            preferred_date_start DATE NULL,
            preferred_date_end   DATE NULL,
            created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer   (customer_id),
            INDEX idx_status     (request_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    // Table already exists — non-fatal
}

// ── POST handler (PRG pattern) ────────────────────────────────────────────────
$actionError   = '';
$actionSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = trim((string) ($_POST['csrf'] ?? ''));
    $action   = trim((string) ($_POST['action'] ?? ''));

    // Preserve current GET filter params for redirect
    $redirectQs = http_build_query(array_filter([
        'q'        => trim((string) ($_POST['fq']        ?? '')),
        'status'   => trim((string) ($_POST['fstatus']   ?? '')),
        'priority' => trim((string) ($_POST['fpriority'] ?? '')),
        'start'    => trim((string) ($_POST['fstart']    ?? '')),
        'end'      => trim((string) ($_POST['fend']      ?? '')),
    ]));

    if (!hash_equals($csrf, $postCsrf)) {
        $_SESSION['bk_flash_error'] = 'Invalid security token. Please refresh and try again.';
        header('Location: bookings.php' . ($redirectQs !== '' ? '?' . $redirectQs : ''));
        exit;
    }

    if ($action === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            try {
                $deletedCount = bk_deleteBookings($pdo, [$delId]);
                if ($deletedCount > 0) {
                    $_SESSION['bk_flash_success'] = 'Booking #' . $delId . ' has been permanently deleted.';
                } else {
                    $_SESSION['bk_flash_error'] = 'Booking #' . $delId . ' could not be found.';
                }
            } catch (Throwable $e) {
                $_SESSION['bk_flash_error'] = 'Delete failed: ' . $e->getMessage();
            }
        }
        header('Location: bookings.php' . ($redirectQs !== '' ? '?' . $redirectQs : ''));
        exit;
    }

    if ($action === 'bulk_delete') {
        $rawIds = $_POST['ids'] ?? [];
        if (is_array($rawIds) && count($rawIds) > 0) {
            $ids = array_values(array_filter(array_map('intval', $rawIds), fn($v) => $v > 0));
            if (count($ids) > 0) {
                try {
                    $deletedCount = bk_deleteBookings($pdo, $ids);
                    $_SESSION['bk_flash_success'] = $deletedCount . ' booking' . ($deletedCount !== 1 ? 's' : '') . ' permanently deleted.';
                } catch (Throwable $e) {
                    $_SESSION['bk_flash_error'] = 'Bulk delete failed: ' . $e->getMessage();
                }
            }
        }
        header('Location: bookings.php' . ($redirectQs !== '' ? '?' . $redirectQs : ''));
        exit;
    }

    if ($action === 'edit') {
        $editId = (int) ($_POST['id'] ?? 0);
        if ($editId > 0) {
            $cFirstName  = trim((string) ($_POST['c_first_name']  ?? ''));
            $cLastName   = trim((string) ($_POST['c_last_name']   ?? ''));
            $cEmail      = trim((string) ($_POST['c_email']       ?? ''));
            $cPhone      = trim((string) ($_POST['c_phone']       ?? ''));
            $cAddress    = trim((string) ($_POST['c_address']     ?? ''));
            $cCity       = trim((string) ($_POST['c_city']        ?? ''));
            $cState      = trim((string) ($_POST['c_state']       ?? ''));
            $cZip        = trim((string) ($_POST['c_zip']         ?? ''));
            $cCompany    = trim((string) ($_POST['c_company']     ?? ''));
            $srBrand     = trim((string) ($_POST['sr_brand']      ?? ''));
            $srModel     = trim((string) ($_POST['sr_model']      ?? ''));
            $srWatts     = trim((string) ($_POST['sr_watts']      ?? ''));
            $srAge       = trim((string) ($_POST['sr_age']        ?? ''));
            $srSummary   = trim((string) ($_POST['sr_summary']    ?? ''));
            $srDetails   = trim((string) ($_POST['sr_details']    ?? ''));
            $srPriority  = trim((string) ($_POST['sr_priority']   ?? 'standard'));
            $srStatus    = trim((string) ($_POST['sr_status']     ?? 'new'));
            $srPrefStart = trim((string) ($_POST['sr_pref_start'] ?? ''));
            $srPrefEnd   = trim((string) ($_POST['sr_pref_end']   ?? ''));

            $validStatuses   = ['abandoned', 'new', 'queued', 'completed', 'cancelled', 'deleted'];
            $validPriorities = ['standard', 'vip', 'emergency'];
            if (!in_array($srStatus, $validStatuses, true))     $srStatus   = 'new';
            if (!in_array($srPriority, $validPriorities, true)) $srPriority = 'standard';
            $srPrefStart = ($srPrefStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $srPrefStart)) ? $srPrefStart : null;
            $srPrefEnd   = ($srPrefEnd   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $srPrefEnd))   ? $srPrefEnd   : null;

            try {
                $srRow = $pdo->prepare("SELECT customer_id FROM service_requests WHERE id = ?");
                $srRow->execute([$editId]);
                $srData = $srRow->fetch();

                if ($srData) {
                    $custId = (int) $srData['customer_id'];
                    $pdo->prepare("
                        UPDATE customers
                           SET first_name = ?, last_name = ?, email = ?, phone = ?,
                               address = ?, city = ?, state = ?, zip = ?, company = ?
                         WHERE id = ?
                    ")->execute([$cFirstName, $cLastName, $cEmail, $cPhone,
                                 $cAddress, $cCity, $cState, $cZip, $cCompany, $custId]);

                    $pdo->prepare("
                        UPDATE service_requests
                           SET laser_brand = ?, laser_model = ?, laser_watts = ?, laser_age = ?,
                               problem_summary = ?, problem_details = ?, priority_level = ?,
                               request_status = ?, preferred_date_start = ?, preferred_date_end = ?
                         WHERE id = ?
                    ")->execute([$srBrand, $srModel, $srWatts ?: null, $srAge ?: null,
                                 $srSummary, $srDetails, $srPriority,
                                 $srStatus, $srPrefStart, $srPrefEnd, $editId]);

                    $_SESSION['bk_flash_success'] = 'Booking #' . $editId . ' has been updated.';
                } else {
                    $_SESSION['bk_flash_error'] = 'Booking not found.';
                }
            } catch (PDOException $e) {
                $_SESSION['bk_flash_error'] = 'Update failed: ' . $e->getMessage();
            }
        }
        header('Location: bookings.php' . ($redirectQs !== '' ? '?' . $redirectQs : ''));
        exit;
    }
}

// ── Flash messages ────────────────────────────────────────────────────────────
if (!empty($_SESSION['bk_flash_error'])) {
    $actionError = $_SESSION['bk_flash_error'];
    unset($_SESSION['bk_flash_error']);
}
if (!empty($_SESSION['bk_flash_success'])) {
    $actionSuccess = $_SESSION['bk_flash_success'];
    unset($_SESSION['bk_flash_success']);
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterSearch    = trim((string) ($_GET['q']        ?? ''));
$filterStatus    = trim((string) ($_GET['status']   ?? ''));
$filterPriority  = trim((string) ($_GET['priority'] ?? ''));
$filterDateStart = trim((string) ($_GET['start']    ?? ''));
$filterDateEnd   = trim((string) ($_GET['end']      ?? ''));

$dateStart = ($filterDateStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateStart)) ? $filterDateStart : null;
$dateEnd   = ($filterDateEnd   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateEnd))   ? $filterDateEnd   : null;

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($filterSearch !== '') {
    $like = '%' . $filterSearch . '%';
    $where[]          = '(c.first_name LIKE :q1 OR c.last_name LIKE :q2 OR c.email LIKE :q3 OR c.phone LIKE :q4 OR CONCAT(c.first_name,\' \',c.last_name) LIKE :q5)';
    $params[':q1']    = $like;
    $params[':q2']    = $like;
    $params[':q3']    = $like;
    $params[':q4']    = $like;
    $params[':q5']    = $like;
}

if ($filterStatus === 'completed') {
    $where[] = "sr.request_status = 'completed'";
} elseif ($filterStatus === 'not_completed') {
    $where[] = "sr.request_status NOT IN ('completed', 'deleted')";
} elseif ($filterStatus === 'incomplete_only') {
    $where[] = "sr.request_status = 'abandoned'";
    $where[] = "NOT EXISTS (SELECT 1 FROM scheduled_cluster_jobs scj WHERE scj.service_request_id = sr.id)";
} elseif (in_array($filterStatus, ['abandoned', 'new', 'queued', 'cancelled', 'deleted'], true)) {
    $where[]          = 'sr.request_status = :status';
    $params[':status'] = $filterStatus;
}

if (in_array($filterPriority, ['standard', 'vip', 'emergency'], true)) {
    $where[]            = 'sr.priority_level = :priority';
    $params[':priority'] = $filterPriority;
}

if ($dateStart !== null) {
    $where[]       = 'sr.created_at >= :ds';
    $params[':ds'] = $dateStart . ' 00:00:00';
}
if ($dateEnd !== null) {
    $where[]       = 'sr.created_at <= :de';
    $params[':de'] = $dateEnd . ' 23:59:59';
}

$whereClause = implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT
            sr.id,
            sr.customer_id,
            sr.laser_brand,
            sr.laser_model,
            sr.laser_watts,
            sr.laser_age,
            sr.problem_summary,
            sr.problem_details,
            sr.priority_level,
            sr.source,
            sr.request_status,
            sr.latitude,
            sr.longitude,
            sr.geocode_status,
            sr.preferred_date_start,
            sr.preferred_date_end,
            sr.created_at,
            sr.updated_at,
            c.first_name,
            c.last_name,
            c.email,
            c.phone,
            c.address,
            c.city,
            c.state,
            c.zip,
            c.company
        FROM service_requests sr
        JOIN customers c ON c.id = sr.customer_id
        WHERE {$whereClause}
        ORDER BY sr.id DESC
    ");
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $dbError  = null;
} catch (PDOException $e) {
    $bookings = [];
    $dbError  = $e->getMessage();
}

// ── Stats (unfiltered totals, exclude deleted) ────────────────────────────────
try {
    $statsRow = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(request_status = 'completed') AS completed,
            SUM(request_status = 'new')       AS new_count,
            SUM(request_status = 'queued')    AS queued,
            SUM(request_status = 'cancelled') AS cancelled,
            SUM(request_status = 'abandoned') AS abandoned
        FROM service_requests
        WHERE request_status != 'deleted'
    ")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $statsRow = ['total' => 0, 'completed' => 0, 'new_count' => 0, 'queued' => 0, 'cancelled' => 0, 'abandoned' => 0];
}

// ── CSV export (before any HTML output) ───────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bookings-' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel UTF-8

    fputcsv($out, [
        'ID',
        'First Name', 'Last Name', 'Company',
        'Email', 'Phone',
        'Address', 'City', 'State', 'ZIP',
        'Laser Brand', 'Laser Model', 'Watts', 'Age',
        'Problem Summary', 'Problem Details',
        'Priority', 'Status', 'Source',
        'Preferred Start', 'Preferred End',
        'Submitted',
    ]);

    foreach ($bookings as $row) {
        fputcsv($out, [
            $row['id'],
            $row['first_name'],
            $row['last_name'],
            $row['company'] ?? '',
            $row['email'],
            $row['phone'],
            $row['address'],
            $row['city'],
            $row['state'],
            $row['zip'],
            $row['laser_brand'] ?? '',
            $row['laser_model'] ?? '',
            $row['laser_watts'] ?? '',
            $row['laser_age']   ?? '',
            $row['problem_summary']  ?? '',
            $row['problem_details']  ?? '',
            $row['priority_level'],
            $row['request_status'],
            $row['source'],
            $row['preferred_date_start'] ?? '',
            $row['preferred_date_end']   ?? '',
            $row['created_at'] ?? '',
        ]);
    }

    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookings | Ghost Laser</title>
    <link rel="stylesheet" href="<?= asset('assets/css/tailwind.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap&v=1.2" rel="stylesheet">
    <style>
        body { -webkit-tap-highlight-color: transparent; }

        .stat-card {
            background: rgba(24,24,27,0.85);
            border: 1px solid rgba(63,63,70,0.8);
            border-radius: 0.75rem;
            padding: 0.65rem 0.85rem;
        }
        .filter-input {
            background: rgba(24,24,27,0.85);
            border: 1px solid rgba(63,63,70,0.8);
            border-radius: 0.5rem;
            color: #e4e4e7;
            font-size: 0.8125rem;
            padding: 0.45rem 0.75rem;
            outline: none;
            min-width: 130px;
        }
        .filter-input:focus { border-color: rgba(6,182,212,0.55); }
        .filter-btn {
            display: inline-flex; align-items: center; gap: 0.35rem;
            background: #06b6d4; color: #09090b; font-weight: 600;
            font-size: 0.8125rem; padding: 0.45rem 1rem;
            border-radius: 0.5rem; cursor: pointer; border: none;
            transition: background .15s;
        }
        .filter-btn:hover { background: #22d3ee; }
        .reset-btn {
            display: inline-flex; align-items: center;
            background: rgba(39,39,42,0.85); border: 1px solid rgba(63,63,70,0.8);
            color: #a1a1aa; font-size: 0.8125rem; padding: 0.45rem 0.85rem;
            border-radius: 0.5rem; text-decoration: none;
            transition: border-color .15s, color .15s;
        }
        .reset-btn:hover { border-color: rgba(6,182,212,0.4); color: #fff; }
        .export-btn {
            display: inline-flex; align-items: center; gap: 0.4rem;
            border: 1px solid rgba(63,63,70,0.8);
            background: rgba(24,24,27,0.85); color: #a1a1aa;
            font-size: 0.8125rem; font-weight: 500;
            padding: 0.45rem 0.9rem; border-radius: 0.5rem;
            text-decoration: none; transition: border-color .15s, color .15s;
        }
        .export-btn:hover { border-color: rgba(6,182,212,0.4); color: #fff; }
        .nav-btn {
            display: inline-flex; align-items: center; gap: 0.35rem;
            border: 1px solid rgba(63,63,70,0.8);
            background: rgba(39,39,42,0.7); color: #a1a1aa;
            font-size: 0.8125rem; padding: 0.35rem 0.75rem;
            border-radius: 0.5rem; text-decoration: none;
            transition: border-color .15s, color .15s;
        }
        .nav-btn:hover { border-color: rgba(6,182,212,0.45); color: #fff; }

        /* Table */
        .table-wrap {
            overflow-x: auto;
            border-radius: 0.875rem;
            border: 1px solid rgba(63,63,70,0.8);
        }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            background: rgba(24,24,27,0.95);
            color: #71717a;
            font-size: 0.6875rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: .06em;
            padding: 0.625rem 0.875rem;
            border-bottom: 1px solid rgba(63,63,70,0.8);
            white-space: nowrap; text-align: left;
        }
        tbody tr { border-bottom: 1px solid rgba(39,39,42,0.8); transition: background .12s; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: rgba(39,39,42,0.55); }
        tbody td { padding: 0.625rem 0.875rem; font-size: 0.8125rem; color: #e4e4e7; vertical-align: top; }

        /* Badges */
        .badge {
            display: inline-flex; align-items: center;
            font-size: 0.6875rem; font-weight: 600;
            padding: 0.2rem 0.55rem; border-radius: 9999px; white-space: nowrap;
        }
        .badge-abandoned { background: rgba(251,146,60,0.14); color: #fdba74; border: 1px solid rgba(251,146,60,0.3); }
        .badge-new       { background: rgba(63,63,70,0.7);    color: #a1a1aa; border: 1px solid rgba(113,113,122,0.4); }
        .badge-queued    { background: rgba(59,130,246,0.15); color: #93c5fd; border: 1px solid rgba(59,130,246,0.3); }
        .badge-completed { background: rgba(34,197,94,0.15);  color: #86efac; border: 1px solid rgba(34,197,94,0.3); }
        .badge-cancelled { background: rgba(234,179,8,0.15);  color: #fde047; border: 1px solid rgba(234,179,8,0.3); }
        .badge-deleted   { background: rgba(239,68,68,0.15);  color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
        .badge-source-internal { background: rgba(168,85,247,0.15); color: #d8b4fe; border: 1px solid rgba(168,85,247,0.3); }
        .badge-source-website  { background: rgba(20,184,166,0.15); color: #5eead4; border: 1px solid rgba(20,184,166,0.3); }
        .priority-emergency { background: rgba(239,68,68,0.15);  color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
        .priority-vip       { background: rgba(168,85,247,0.15); color: #d8b4fe; border: 1px solid rgba(168,85,247,0.3); }
        .priority-standard  { background: rgba(63,63,70,0.5);    color: #a1a1aa; border: 1px solid rgba(113,113,122,0.3); }

        /* Action buttons */
        .btn-action {
            display: inline-flex; align-items: center;
            font-size: 0.75rem; font-weight: 500;
            padding: 0.25rem 0.6rem; border-radius: 0.375rem;
            cursor: pointer; border: 1px solid transparent;
            transition: all .12s; white-space: nowrap;
        }
        .btn-view   { background: rgba(6,182,212,0.12);  color: #67e8f9; border-color: rgba(6,182,212,0.3); }
        .btn-view:hover { background: rgba(6,182,212,0.22); border-color: rgba(6,182,212,0.5); }
        .btn-edit   { background: rgba(234,179,8,0.1);   color: #fde047; border-color: rgba(234,179,8,0.25); }
        .btn-edit:hover { background: rgba(234,179,8,0.2); border-color: rgba(234,179,8,0.45); }
        .btn-delete { background: rgba(239,68,68,0.1);   color: #fca5a5; border-color: rgba(239,68,68,0.25); }
        .btn-delete:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.45); }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.75); z-index: 50;
            align-items: center; justify-content: center; padding: 1rem;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #18181b; border: 1px solid rgba(63,63,70,0.9);
            border-radius: 1rem; width: 100%; max-width: 700px;
            max-height: 90vh; overflow-y: auto;
            box-shadow: 0 0 60px rgba(0,0,0,0.7);
        }
        .modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(63,63,70,0.7);
            position: sticky; top: 0; background: #18181b; z-index: 1;
        }
        .modal-body  { padding: 1.5rem; }
        .modal-footer {
            padding: 1rem 1.5rem; border-top: 1px solid rgba(63,63,70,0.7);
            display: flex; justify-content: flex-end; gap: .75rem;
            position: sticky; bottom: 0; background: #18181b;
        }
        .modal-close {
            background: none; border: none; color: #71717a;
            cursor: pointer; padding: .25rem; border-radius: .375rem; transition: color .12s;
        }
        .modal-close:hover { color: #fff; }

        /* Detail view */
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 500px) { .detail-grid { grid-template-columns: 1fr; } }
        .detail-item label { display: block; font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: #71717a; margin-bottom: .2rem; }
        .detail-item p     { font-size: 0.875rem; color: #e4e4e7; word-break: break-word; }

        /* Edit form */
        .form-label    { display: block; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: #71717a; margin-bottom: .25rem; }
        .form-input, .form-select, .form-textarea {
            width: 100%; background: rgba(9,9,11,0.85);
            border: 1px solid rgba(63,63,70,0.9); border-radius: .5rem;
            color: #e4e4e7; font-size: 0.875rem; padding: .5rem .75rem;
            outline: none; transition: border-color .12s; box-sizing: border-box;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus { border-color: rgba(6,182,212,0.55); }
        .form-textarea  { resize: vertical; min-height: 80px; }
        .form-select option { background: #27272a; }
        .form-section-title { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #06b6d4; margin: 1.25rem 0 .75rem; }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        @media (max-width: 480px) { .form-grid-2 { grid-template-columns: 1fr; } }
        .btn-save {
            background: #06b6d4; color: #09090b; font-weight: 600;
            font-size: 0.875rem; padding: .5rem 1.25rem;
            border-radius: .5rem; border: none; cursor: pointer; transition: background .12s;
        }
        .btn-save:hover { background: #22d3ee; }
        .btn-cancel-modal {
            background: rgba(39,39,42,0.8); border: 1px solid rgba(63,63,70,0.8);
            color: #a1a1aa; font-size: 0.875rem; padding: .5rem 1rem;
            border-radius: .5rem; cursor: pointer; transition: border-color .12s, color .12s;
        }
        .btn-cancel-modal:hover { border-color: rgba(6,182,212,0.4); color: #fff; }

        /* Row checkboxes */
        .row-check, #selectAll {
            width: 1rem; height: 1rem; cursor: pointer;
            accent-color: #ef4444;
        }
        thead th.th-check { width: 2.5rem; text-align: center; }
        tbody td.td-check  { text-align: center; vertical-align: middle; }

        /* Bulk toolbar */
        #bulkToolbar {
            display: none;
            align-items: center; gap: .75rem;
            background: rgba(239,68,68,0.08);
            border: 1px solid rgba(239,68,68,0.3);
            border-radius: .625rem;
            padding: .55rem 1rem;
            margin-bottom: .75rem;
        }
        #bulkToolbar.visible { display: flex; }
        #bulkCount {
            font-size: .8125rem; color: #fca5a5; font-weight: 600;
        }
        .btn-bulk-delete {
            display: inline-flex; align-items: center; gap: .35rem;
            background: rgba(239,68,68,0.15); color: #fca5a5;
            border: 1px solid rgba(239,68,68,0.35);
            font-size: .8125rem; font-weight: 600;
            padding: .35rem .9rem; border-radius: .5rem;
            cursor: pointer; transition: background .12s, border-color .12s;
        }
        .btn-bulk-delete:hover { background: rgba(239,68,68,0.28); border-color: rgba(239,68,68,0.6); }
    </style>
</head>
<body class="bg-zinc-950 text-white font-sans antialiased min-h-screen">

<!-- Header -->
<header class="sticky top-0 z-40 bg-zinc-950/90 border-b border-zinc-800/60" style="backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);">
    <div class="max-w-7xl mx-auto px-4 flex items-center justify-between h-14">
        <a href="dashboard.php" class="flex items-center gap-2 group">
            <span class="w-6 h-6 rounded bg-cyan-500 flex items-center justify-center flex-shrink-0 group-hover:bg-cyan-400 transition-colors">
                <svg class="w-3.5 h-3.5 text-zinc-950" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/>
                </svg>
            </span>
            <span class="text-white font-bold text-base tracking-tight">Ghost<span class="text-cyan-400">Laser</span></span>
        </a>
        <div class="flex items-center gap-3">
            <span class="text-xs text-zinc-400 font-medium hidden sm:block">Bookings</span>
            <a href="dashboard.php" class="nav-btn">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Dashboard
            </a>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 pb-16 pt-6">

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white leading-tight">Bookings</h1>
            <p class="text-sm text-zinc-400 mt-1">All customer registration &amp; booking form submissions &mdash; view, edit, or remove any record.</p>
        </div>
        <div class="flex self-start gap-2">
            <a href="technician/schedule.php" class="filter-btn">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10m-11 9h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v11a2 2 0 002 2z"/></svg>
                Scheduling
            </a>
            <a href="book_internal.php" class="filter-btn">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Booking
            </a>
        </div>
    </div>

    <?php if ($dbError !== null): ?>
    <div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
        Database error: <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($actionError !== ''): ?>
    <div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
        <?= htmlspecialchars($actionError, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($actionSuccess !== ''): ?>
    <div class="mb-4 rounded-xl border border-green-500/30 bg-green-500/10 px-4 py-3 text-sm text-green-300">
        <?= htmlspecialchars($actionSuccess, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-3 sm:grid-cols-6 gap-2.5 mb-6">
        <div class="stat-card">
            <div class="text-[11px] text-zinc-500 font-medium uppercase tracking-widest mb-0.5">Total</div>
            <div class="text-xl font-bold text-white leading-tight"><?= (int) ($statsRow['total'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="text-[11px] text-zinc-500 font-medium uppercase tracking-widest mb-0.5">New</div>
            <div class="text-xl font-bold text-zinc-300 leading-tight"><?= (int) ($statsRow['new_count'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="text-[11px] text-zinc-500 font-medium uppercase tracking-widest mb-0.5">Abandoned</div>
            <div class="text-xl font-bold text-orange-400 leading-tight"><?= (int) ($statsRow['abandoned'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="text-[11px] text-zinc-500 font-medium uppercase tracking-widest mb-0.5">Queued</div>
            <div class="text-xl font-bold text-blue-400 leading-tight"><?= (int) ($statsRow['queued'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="text-[11px] text-zinc-500 font-medium uppercase tracking-widest mb-0.5">Completed</div>
            <div class="text-xl font-bold text-green-400 leading-tight"><?= (int) ($statsRow['completed'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="text-[11px] text-zinc-500 font-medium uppercase tracking-widest mb-0.5">Cancelled</div>
            <div class="text-xl font-bold text-yellow-400 leading-tight"><?= (int) ($statsRow['cancelled'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" action="bookings.php" autocomplete="off" class="flex flex-wrap items-end gap-3 mb-5">
        <div class="flex flex-col gap-1">
            <label class="text-xs text-zinc-500 font-medium">Search</label>
            <input
                type="text" name="q"
                placeholder="Name, email, or phone…"
                value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>"
                class="filter-input" style="min-width:200px;"
            >
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs text-zinc-500 font-medium">Status</label>
            <select name="status" class="filter-input">
                <option value="">All Statuses</option>
                <option value="incomplete_only" <?= $filterStatus === 'incomplete_only' ? 'selected' : '' ?>>Started but Incomplete</option>
                <option value="not_completed"   <?= $filterStatus === 'not_completed'   ? 'selected' : '' ?>>Not Completed</option>
                <option value="abandoned"       <?= $filterStatus === 'abandoned'       ? 'selected' : '' ?>>Abandoned</option>
                <option value="new"             <?= $filterStatus === 'new'             ? 'selected' : '' ?>>New</option>
                <option value="queued"          <?= $filterStatus === 'queued'          ? 'selected' : '' ?>>Queued</option>
                <option value="completed"       <?= $filterStatus === 'completed'       ? 'selected' : '' ?>>Completed</option>
                <option value="cancelled"       <?= $filterStatus === 'cancelled'       ? 'selected' : '' ?>>Cancelled</option>
                <option value="deleted"         <?= $filterStatus === 'deleted'         ? 'selected' : '' ?>>Deleted</option>
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs text-zinc-500 font-medium">Priority</label>
            <select name="priority" class="filter-input">
                <option value="">All Priorities</option>
                <option value="emergency" <?= $filterPriority === 'emergency' ? 'selected' : '' ?>>Emergency</option>
                <option value="vip"       <?= $filterPriority === 'vip'       ? 'selected' : '' ?>>VIP</option>
                <option value="standard"  <?= $filterPriority === 'standard'  ? 'selected' : '' ?>>Standard</option>
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs text-zinc-500 font-medium">From Date</label>
            <input type="date" name="start" value="<?= htmlspecialchars($filterDateStart, ENT_QUOTES, 'UTF-8') ?>" class="filter-input">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs text-zinc-500 font-medium">To Date</label>
            <input type="date" name="end" value="<?= htmlspecialchars($filterDateEnd, ENT_QUOTES, 'UTF-8') ?>" class="filter-input">
        </div>
        <button type="submit" class="filter-btn">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            Search
        </button>
        <a href="bookings.php" class="reset-btn">Reset</a>
        <a href="bookings.php?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8') ?>" class="export-btn ml-auto">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Export CSV
        </a>
    </form>

    <!-- Bulk delete toolbar (visible when rows are checked) -->
    <div id="bulkToolbar" role="toolbar" aria-label="Bulk actions">
        <span id="bulkCount"></span>
        <button type="button" class="btn-bulk-delete" onclick="openBulkDeleteModal()">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            Bulk Delete
        </button>
        <button type="button" class="btn-cancel-modal" style="font-size:.8125rem;padding:.35rem .75rem;" onclick="clearAllChecks()">Clear</button>
    </div>

    <!-- Table -->
    <?php if (empty($bookings)): ?>
    <div class="flex flex-col items-center justify-center py-20 text-center">
        <div class="w-14 h-14 rounded-2xl bg-zinc-800/80 flex items-center justify-center mb-4">
            <svg class="w-7 h-7 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
        </div>
        <p class="text-zinc-400 text-sm">No bookings found<?= ($filterSearch !== '' || $filterStatus !== '' || $filterPriority !== '' || $dateStart !== null || $dateEnd !== null) ? ' matching your filters' : '' ?>.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap mb-4">
        <table>
            <thead>
                <tr>
                    <th class="th-check"><input type="checkbox" id="selectAll" title="Select all visible rows" aria-label="Select all"></th>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th>Location</th>
                    <th>Priority</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $row):
                $fullName    = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                $location    = implode(', ', array_filter([
                    $row['city']  ?? '',
                    $row['state'] ?? '',
                ]));
                $machine     = implode(' ', array_filter([
                    $row['laser_brand'] ?? '',
                    $row['laser_model'] ?? '',
                ]));
                $si          = bk_statusInfo($row['request_status'] ?? 'new');
                $pi          = bk_priorityInfo($row['priority_level'] ?? 'standard');
                $srcInfo     = bk_sourceInfo($row['source'] ?? 'Website');
                $rowJson     = htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                $nameJson    = htmlspecialchars(json_encode($fullName), ENT_QUOTES, 'UTF-8');
            ?>
            <tr>
                <td class="td-check"><input type="checkbox" class="row-check" value="<?= (int) $row['id'] ?>" aria-label="Select booking #<?= (int) $row['id'] ?>"></td>
                <td class="text-zinc-500 font-mono text-xs"><?= (int) $row['id'] ?></td>
                <td>
                    <div class="font-medium text-white"><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($row['company'])): ?>
                    <div class="text-xs text-zinc-500 mt-0.5"><?= htmlspecialchars($row['company'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <div><?= htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-zinc-400 text-xs mt-0.5"><?= htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td class="text-zinc-300"><?= $location !== '' ? htmlspecialchars($location, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                <td><span class="badge <?= htmlspecialchars($pi['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($pi['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><span class="badge <?= htmlspecialchars($srcInfo['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($srcInfo['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><span class="badge <?= htmlspecialchars($si['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($si['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td class="text-zinc-400 text-xs whitespace-nowrap"><?= htmlspecialchars(bk_fmtDateTime($row['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="btn-action btn-view"   onclick="openViewModal(<?= $rowJson ?>)">View</button>
                        <button type="button" class="btn-action btn-edit"   onclick="openEditModal(<?= $rowJson ?>)">Edit</button>
                        <button type="button" class="btn-action btn-delete" onclick="openDeleteModal(<?= (int) $row['id'] ?>, <?= $nameJson ?>)">Delete</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-xs text-zinc-500"><?= count($bookings) ?> record<?= count($bookings) !== 1 ? 's' : '' ?> shown</p>
    <?php endif; ?>

</main>

<!-- View Modal -->
<div id="viewModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="viewModalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="viewModalTitle" class="text-base font-semibold text-white">Booking Details</h2>
            <button class="modal-close" onclick="closeModal('viewModal')" aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-body" id="viewModalBody"></div>
        <div class="modal-footer">
            <button class="btn-cancel-modal" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="editModalTitle" class="text-base font-semibold text-white">Edit Booking</h2>
            <button class="modal-close" onclick="closeModal('editModal')" aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" action="bookings.php" autocomplete="off">
            <input type="hidden" name="action"    value="edit" autocomplete="off">
            <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
            <input type="hidden" name="id"        id="editId" autocomplete="off">
            <!-- Preserve current filter params -->
            <input type="hidden" name="fq"        value="<?= htmlspecialchars($filterSearch,    ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
            <input type="hidden" name="fstatus"   value="<?= htmlspecialchars($filterStatus,    ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
            <input type="hidden" name="fpriority" value="<?= htmlspecialchars($filterPriority,  ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
            <input type="hidden" name="fstart"    value="<?= htmlspecialchars($filterDateStart, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
            <input type="hidden" name="fend"      value="<?= htmlspecialchars($filterDateEnd,   ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
            <div class="modal-body">
                <p class="form-section-title" style="margin-top:0">Customer Information</p>
                <div class="form-grid-2">
                    <div><label class="form-label">First Name</label><input type="text"  name="c_first_name" id="editFirstName" class="form-input" required autocomplete="off"></div>
                    <div><label class="form-label">Last Name</label> <input type="text"  name="c_last_name"  id="editLastName"  class="form-input" required autocomplete="off"></div>
                    <div><label class="form-label">Email</label>     <input type="email" name="c_email"      id="editEmail"     class="form-input" required autocomplete="off"></div>
                    <div><label class="form-label">Phone</label>     <input type="text"  name="c_phone"      id="editPhone"     class="form-input" autocomplete="off"></div>
                    <div><label class="form-label">Company</label>   <input type="text"  name="c_company"    id="editCompany"   class="form-input" autocomplete="off"></div>
                    <div><label class="form-label">Address</label>   <input type="text"  name="c_address"    id="editAddress"   class="form-input" autocomplete="off"></div>
                    <div><label class="form-label">City</label>      <input type="text"  name="c_city"       id="editCity"      class="form-input" autocomplete="off"></div>
                    <div><label class="form-label">State</label>     <input type="text"  name="c_state"      id="editState"     class="form-input" maxlength="2" autocomplete="off"></div>
                    <div><label class="form-label">ZIP</label>       <input type="text"  name="c_zip"        id="editZip"       class="form-input" autocomplete="off"></div>
                </div>

                <p class="form-section-title">Machine &amp; Request</p>
                <div class="form-grid-2">
                    <div><label class="form-label">Laser Brand</label>      <input type="text" name="sr_brand" id="editBrand" class="form-input" autocomplete="off"></div>
                    <div><label class="form-label">Laser Model</label>      <input type="text" name="sr_model" id="editModel" class="form-input" autocomplete="off"></div>
                    <div><label class="form-label">Watts</label>            <input type="text" name="sr_watts" id="editWatts" class="form-input" autocomplete="off"></div>
                    <div><label class="form-label">Age</label>              <input type="text" name="sr_age"   id="editAge"   class="form-input" autocomplete="off"></div>
                    <div>
                        <label class="form-label">Priority</label>
                        <select name="sr_priority" id="editPriority" class="form-select" autocomplete="off">
                            <option value="standard">Standard</option>
                            <option value="vip">VIP</option>
                            <option value="emergency">Emergency</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Status</label>
                        <select name="sr_status" id="editStatus" class="form-select" autocomplete="off">
                            <option value="abandoned">Abandoned</option>
                            <option value="new">New</option>
                            <option value="queued">Queued</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="deleted">Deleted</option>
                        </select>
                    </div>
                    <div><label class="form-label">Preferred Start</label>  <input type="date" name="sr_pref_start" id="editPrefStart" class="form-input" autocomplete="off"></div>
                    <div><label class="form-label">Preferred End</label>    <input type="date" name="sr_pref_end"   id="editPrefEnd"   class="form-input" autocomplete="off"></div>
                </div>
                <div class="mt-3"><label class="form-label">Problem Summary</label><input type="text" name="sr_summary" id="editSummary" class="form-input" autocomplete="off"></div>
                <div class="mt-3"><label class="form-label">Problem Details</label><textarea name="sr_details" id="editDetails" class="form-textarea" autocomplete="off"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <h2 id="deleteModalTitle" class="text-base font-semibold text-white">Confirm Delete</h2>
            <button class="modal-close" onclick="closeModal('deleteModal')" aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" action="bookings.php" autocomplete="off">
            <input type="hidden" name="action"    value="delete">
            <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id"        id="deleteId">
            <input type="hidden" name="fq"        value="<?= htmlspecialchars($filterSearch,    ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="fstatus"   value="<?= htmlspecialchars($filterStatus,    ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="fpriority" value="<?= htmlspecialchars($filterPriority,  ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="fstart"    value="<?= htmlspecialchars($filterDateStart, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="fend"      value="<?= htmlspecialchars($filterDateEnd,   ENT_QUOTES, 'UTF-8') ?>">
            <div class="modal-body">
                <p class="text-sm text-zinc-300">Are you sure you want to <strong class="text-red-400">permanently delete</strong> this booking?</p>
                <p id="deleteCustomerName" class="mt-2 text-sm font-medium text-white"></p>
                <p class="mt-3 text-xs text-zinc-500">This will completely remove the booking record from the database.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn-save" style="background:#ef4444;">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Delete Modal -->
<div id="bulkDeleteModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="bulkDeleteModalTitle">
    <div class="modal-box" style="max-width:440px;">
        <div class="modal-header">
            <h2 id="bulkDeleteModalTitle" class="text-base font-semibold text-white">Confirm Bulk Delete</h2>
            <button class="modal-close" onclick="closeModal('bulkDeleteModal')" aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" action="bookings.php" autocomplete="off" id="bulkDeleteForm">
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="fq"        value="<?= htmlspecialchars($filterSearch,    ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="fstatus"   value="<?= htmlspecialchars($filterStatus,    ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="fpriority" value="<?= htmlspecialchars($filterPriority,  ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="fstart"    value="<?= htmlspecialchars($filterDateStart, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="fend"      value="<?= htmlspecialchars($filterDateEnd,   ENT_QUOTES, 'UTF-8') ?>">
            <div id="bulkDeleteIds"></div>
            <div class="modal-body">
                <p class="text-sm text-zinc-300">Are you sure you want to <strong class="text-red-400">permanently delete</strong> <strong id="bulkDeleteCount" class="text-red-400"></strong>?</p>
                <p class="mt-3 text-xs text-zinc-500">This will completely remove the selected booking records from the database.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeModal('bulkDeleteModal')">Cancel</button>
                <button type="submit" class="btn-save" style="background:#ef4444;">Delete Selected</button>
            </div>
        </form>
    </div>
</div>

<script>
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(function(m) { m.classList.remove('open'); });
    }
});

document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('mousedown', function(e) {
        if (e.target === overlay) closeModal(overlay.id);
    });
});

function esc(v) {
    if (v === null || v === undefined || v === '') return '—';
    return String(v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function openViewModal(row) {
    var fullName = [row.first_name, row.last_name].filter(Boolean).join(' ') || '—';
    var location = [row.address, row.city, row.state, row.zip].filter(Boolean).join(', ') || '—';
    var machine  = [row.laser_brand, row.laser_model].filter(Boolean).join(' ') || '—';

    document.getElementById('viewModalTitle').textContent = 'Booking #' + row.id + ' \u2014 ' + fullName;
    document.getElementById('viewModalBody').innerHTML = [
        '<p class="form-section-title" style="margin-top:0">Customer</p>',
        '<div class="detail-grid">',
        '<div class="detail-item"><label>Full Name</label><p>' + esc(fullName) + '</p></div>',
        '<div class="detail-item"><label>Company</label><p>' + esc(row.company) + '</p></div>',
        '<div class="detail-item"><label>Email</label><p>' + esc(row.email) + '</p></div>',
        '<div class="detail-item"><label>Phone</label><p>' + esc(row.phone) + '</p></div>',
        '<div class="detail-item" style="grid-column:1/-1"><label>Address</label><p>' + esc(location) + '</p></div>',
        '</div>',
        '<p class="form-section-title">Machine</p>',
        '<div class="detail-grid">',
        '<div class="detail-item"><label>Brand / Model</label><p>' + esc(machine) + '</p></div>',
        '<div class="detail-item"><label>Watts</label><p>' + esc(row.laser_watts) + '</p></div>',
        '<div class="detail-item"><label>Age</label><p>' + esc(row.laser_age) + '</p></div>',
        '</div>',
        '<p class="form-section-title">Request</p>',
        '<div class="detail-grid">',
        '<div class="detail-item"><label>Priority</label><p>' + esc(row.priority_level) + '</p></div>',
        '<div class="detail-item"><label>Status</label><p>' + esc(row.request_status) + '</p></div>',
        '<div class="detail-item"><label>Source</label><p>' + esc(row.source) + '</p></div>',
        '<div class="detail-item"><label>Submitted</label><p>' + esc(row.created_at) + '</p></div>',
        '<div class="detail-item"><label>Preferred Start</label><p>' + esc(row.preferred_date_start) + '</p></div>',
        '<div class="detail-item"><label>Preferred End</label><p>' + esc(row.preferred_date_end) + '</p></div>',
        '<div class="detail-item" style="grid-column:1/-1"><label>Problem Summary</label><p>' + esc(row.problem_summary) + '</p></div>',
        '<div class="detail-item" style="grid-column:1/-1"><label>Problem Details</label><p style="white-space:pre-wrap">' + esc(row.problem_details) + '</p></div>',
        '</div>',
        '<p class="form-section-title">Geocoding</p>',
        '<div class="detail-grid">',
        '<div class="detail-item"><label>Latitude</label><p>' + esc(row.latitude) + '</p></div>',
        '<div class="detail-item"><label>Longitude</label><p>' + esc(row.longitude) + '</p></div>',
        '<div class="detail-item"><label>Geocode Status</label><p>' + esc(row.geocode_status) + '</p></div>',
        '</div>',
    ].join('');
    document.getElementById('viewModal').classList.add('open');
}

function openEditModal(row) {
    document.getElementById('editId').value        = row.id          || '';
    document.getElementById('editFirstName').value = row.first_name  || '';
    document.getElementById('editLastName').value  = row.last_name   || '';
    document.getElementById('editEmail').value     = row.email       || '';
    document.getElementById('editPhone').value     = row.phone       || '';
    document.getElementById('editCompany').value   = row.company     || '';
    document.getElementById('editAddress').value   = row.address     || '';
    document.getElementById('editCity').value      = row.city        || '';
    document.getElementById('editState').value     = row.state       || '';
    document.getElementById('editZip').value       = row.zip         || '';
    document.getElementById('editBrand').value     = row.laser_brand || '';
    document.getElementById('editModel').value     = row.laser_model || '';
    document.getElementById('editWatts').value     = row.laser_watts || '';
    document.getElementById('editAge').value       = row.laser_age   || '';
    document.getElementById('editSummary').value   = row.problem_summary || '';
    document.getElementById('editDetails').value   = row.problem_details || '';
    document.getElementById('editPrefStart').value = row.preferred_date_start || '';
    document.getElementById('editPrefEnd').value   = row.preferred_date_end   || '';

    var pEl = document.getElementById('editPriority');
    pEl.value = row.priority_level || 'standard';

    var sEl = document.getElementById('editStatus');
    sEl.value = row.request_status || 'new';

    document.getElementById('editModalTitle').textContent = 'Edit Booking #' + row.id;
    document.getElementById('editModal').classList.add('open');
}

function openDeleteModal(id, name) {
    document.getElementById('deleteId').value           = id;
    document.getElementById('deleteCustomerName').textContent = name || '';
    document.getElementById('deleteModal').classList.add('open');
}

// ── Bulk-delete helpers ───────────────────────────────────────────────────────
function getCheckedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(function(cb) {
        return parseInt(cb.value, 10);
    });
}

function updateBulkToolbar() {
    var ids    = getCheckedIds();
    var n      = ids.length;
    var toolbar = document.getElementById('bulkToolbar');
    var countEl = document.getElementById('bulkCount');
    if (n > 0) {
        countEl.textContent = n + ' booking' + (n !== 1 ? 's' : '') + ' selected';
        toolbar.classList.add('visible');
    } else {
        toolbar.classList.remove('visible');
    }
    // Keep select-all checkbox in sync
    var all  = document.querySelectorAll('.row-check');
    var saEl = document.getElementById('selectAll');
    if (saEl) {
        saEl.checked       = all.length > 0 && n === all.length;
        saEl.indeterminate = n > 0 && n < all.length;
    }
}

function clearAllChecks() {
    document.querySelectorAll('.row-check').forEach(function(cb) { cb.checked = false; });
    var saEl = document.getElementById('selectAll');
    if (saEl) { saEl.checked = false; saEl.indeterminate = false; }
    updateBulkToolbar();
}

function openBulkDeleteModal() {
    var ids = getCheckedIds();
    if (ids.length === 0) return;

    // Populate hidden id inputs
    var container = document.getElementById('bulkDeleteIds');
    container.innerHTML = '';
    ids.forEach(function(id) {
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'ids[]';
        inp.value = id;
        container.appendChild(inp);
    });

    var n = ids.length;
    document.getElementById('bulkDeleteCount').textContent = n + ' booking' + (n !== 1 ? 's' : '');
    document.getElementById('bulkDeleteModal').classList.add('open');
}

// Wire checkboxes once DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Select-all toggle
    var saEl = document.getElementById('selectAll');
    if (saEl) {
        saEl.addEventListener('change', function() {
            document.querySelectorAll('.row-check').forEach(function(cb) {
                cb.checked = saEl.checked;
            });
            updateBulkToolbar();
        });
    }
    // Individual row checkboxes
    document.querySelectorAll('.row-check').forEach(function(cb) {
        cb.addEventListener('change', updateBulkToolbar);
    });
});
</script>
</body>
</html>
