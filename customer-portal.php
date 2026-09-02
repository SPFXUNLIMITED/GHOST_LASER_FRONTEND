<?php
session_start();

if (empty($_SESSION['customer_id'])) {
    header('Location: customer-login.php?mode=login&redirect=' . rawurlencode('customer-portal.php'));
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/functions.php';

ensure_customer_status_table($pdo);

$customerId = (int) $_SESSION['customer_id'];

// Load customer + status
$stmt = $pdo->prepare(
    'SELECT c.id, c.first_name, c.last_name, c.company, c.email, c.phone, c.address, c.city, c.state, c.zip,
            COALESCE(cs.rating, 5) AS rating,
            COALESCE(cs.status, \'Good\') AS customer_status,
            COALESCE(cs.notes, \'\') AS status_notes,
            COALESCE(cs.has_outstanding_balance, 0) AS has_outstanding_balance
     FROM customers c
     LEFT JOIN customer_status cs ON cs.customer_id = c.id
     WHERE c.id = ?
     LIMIT 1'
);
$stmt->execute([$customerId]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    session_unset();
    session_destroy();
    header('Location: customer-login.php?mode=login');
    exit;
}

// Load bookings for this customer
$bookings = [];
try {
    $bStmt = $pdo->prepare(
        'SELECT id, laser_brand, laser_model, laser_watts, laser_age, problem_summary, problem_details,
                priority_level, request_status, preferred_date_start, preferred_date_end, created_at, updated_at
         FROM service_requests
         WHERE customer_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 50'
    );
    $bStmt->execute([$customerId]);
    $bookings = $bStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $bookings = [];
}

function cp_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cp_statusBadge(string $status): array
{
    return match ($status) {
        'abandoned' => ['label' => 'Abandoned', 'class' => 'border-orange-500/30 bg-orange-500/10 text-orange-300'],
        'new'       => ['label' => 'New', 'class' => 'border-zinc-600 bg-zinc-800/60 text-zinc-200'],
        'queued'    => ['label' => 'Queued', 'class' => 'border-blue-500/30 bg-blue-500/10 text-blue-300'],
        'completed' => ['label' => 'Completed', 'class' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'border-yellow-500/30 bg-yellow-500/10 text-yellow-300'],
        'deleted'   => ['label' => 'Deleted', 'class' => 'border-red-500/30 bg-red-500/10 text-red-300'],
        default     => ['label' => ucfirst($status), 'class' => 'border-zinc-600 bg-zinc-800/60 text-zinc-200'],
    };
}

function cp_priorityBadge(string $priority): array
{
    return match (strtolower($priority)) {
        'emergency' => ['label' => 'Emergency', 'class' => 'border-red-500/30 bg-red-500/10 text-red-300'],
        'vip'       => ['label' => 'VIP', 'class' => 'border-amber-500/30 bg-amber-500/10 text-amber-200'],
        default     => ['label' => 'Standard', 'class' => 'border-zinc-600 bg-zinc-800/60 text-zinc-300'],
    };
}

function cp_fmtDate(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '—';
    }
    try {
        $d = new DateTimeImmutable($dt, new DateTimeZone('America/Los_Angeles'));
        return $d->format('m/d/Y g:i A') . ' PT';
    } catch (Exception $e) {
        return cp_h($dt);
    }
}

$fullName = trim((string) (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')));
if ($fullName === '') {
    $fullName = (string) ($customer['email'] ?? 'Customer');
}
$customerStatus = (string) ($customer['customer_status'] ?? 'Good');
$isBanned = strcasecmp($customerStatus, 'Banned') === 0;
$isVip = strcasecmp($customerStatus, 'VIP') === 0;

$pageTitle = 'Customer Portal | Ghost Laser';
$pageDescription = 'Your Ghost Laser account, bookings, and status.';
$extraHead = <<<'HTML'
<style>
    .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.35); }
    .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.55); }
    .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
    .status-pill { display: inline-flex; align-items: center; gap: .35rem; border-radius: 9999px; border: 1px solid; padding: .2rem .65rem; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
</style>
HTML;
$headerRight = '<a href="book_a_technician.php?step=2" class="text-sm text-cyan-400 hover:text-cyan-300 transition-colors">+ New Booking</a>';
require_once __DIR__ . '/templates/header.php';
?>

<main class="min-h-screen hero-grid pt-24 pb-16 px-4">
    <div class="max-w-6xl mx-auto space-y-6">

        <!-- Account header -->
        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-4">
                    <span class="w-14 h-14 rounded-2xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-300 font-black text-xl">
                        <?= cp_h(strtoupper(substr($fullName, 0, 1))) ?>
                    </span>
                    <div>
                        <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/20 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-[0.2em] text-cyan-400 w-fit">
                            Customer Portal
                        </div>
                        <h1 class="text-2xl md:text-3xl font-bold tracking-tight mt-2"><?= cp_h($fullName) ?></h1>
                        <p class="text-zinc-400 text-sm mt-1">
                            <?= cp_h((string) ($customer['email'] ?? '')) ?>
                            <?php if (!empty($customer['phone'])): ?> · <?= cp_h((string) $customer['phone']) ?><?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="status-pill <?= $isBanned ? 'border-red-500/40 bg-red-500/10 text-red-300' : ($isVip ? 'border-amber-500/40 bg-amber-500/10 text-amber-200' : 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200') ?>">
                        <?= $isVip ? '★ ' : '' ?><?= cp_h($customerStatus) ?>
                    </span>
                    <span class="status-pill border-zinc-700 bg-zinc-800/60 text-zinc-300">
                        Rating: <?= str_repeat('★', max(1, min(5, (int) ($customer['rating'] ?? 5)))) ?>
                    </span>
                    <?php if ((int) ($customer['has_outstanding_balance'] ?? 0) === 1): ?>
                        <span class="status-pill border-red-500/30 bg-red-500/10 text-red-300">Outstanding Balance</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($customer['status_notes'])): ?>
                <div class="mt-5 rounded-xl border border-zinc-800 bg-zinc-950/50 px-4 py-3 text-sm text-zinc-300">
                    <p class="text-xs uppercase tracking-wider text-zinc-500 mb-1">Account Notes</p>
                    <p class="whitespace-pre-line"><?= cp_h((string) $customer['status_notes']) ?></p>
                </div>
            <?php endif; ?>
        </section>

        <!-- Quick actions -->
        <section class="grid gap-4 sm:grid-cols-3">
            <a href="book_a_technician.php?step=2" class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-5 card-glow hover:border-cyan-500/40 transition-colors">
                <p class="text-xs uppercase tracking-widest text-cyan-400 font-semibold">Book</p>
                <p class="text-lg font-semibold text-white mt-2">New Repair</p>
                <p class="text-sm text-zinc-400 mt-1">Schedule a technician visit.</p>
            </a>
            <a href="book_a_technician.php?step=2" class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-5 card-glow hover:border-cyan-500/40 transition-colors">
                <p class="text-xs uppercase tracking-widest text-cyan-400 font-semibold">Account</p>
                <p class="text-lg font-semibold text-white mt-2">Update Details</p>
                <p class="text-sm text-zinc-400 mt-1">Keep your contact info current.</p>
            </a>
            <a href="tel:9495652660" class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-5 card-glow hover:border-cyan-500/40 transition-colors">
                <p class="text-xs uppercase tracking-widest text-cyan-400 font-semibold">Support</p>
                <p class="text-lg font-semibold text-white mt-2">Call Us</p>
                <p class="text-sm text-zinc-400 mt-1">949-565-2660</p>
            </a>
        </section>

        <!-- Bookings -->
        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-5 md:p-6 card-glow">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-xl font-semibold text-white">Your Bookings</h2>
                    <p class="text-sm text-zinc-400 mt-1">Recent service requests tied to your account.</p>
                </div>
                <a href="book_a_technician.php?step=2" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 btn-glow whitespace-nowrap">Book Now</a>
            </div>

            <?php if ($bookings === []): ?>
                <div class="rounded-xl border border-dashed border-zinc-700 bg-zinc-950/40 px-4 py-10 text-center">
                    <p class="text-sm text-zinc-400">No bookings yet.</p>
                    <a href="book_a_technician.php?step=2" class="inline-block mt-3 text-sm font-semibold text-cyan-400 hover:text-cyan-300">Start your first booking →</a>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-800 text-left text-zinc-500">
                                <th class="pb-3 pr-3">#</th>
                                <th class="pb-3 pr-3">Machine</th>
                                <th class="pb-3 pr-3">Priority</th>
                                <th class="pb-3 pr-3">Status</th>
                                <th class="pb-3 pr-3">Submitted</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60">
                        <?php foreach ($bookings as $booking): ?>
                            <?php
                                $sid = cp_statusBadge((string) ($booking['request_status'] ?? 'new'));
                                $pid = cp_priorityBadge((string) ($booking['priority_level'] ?? 'standard'));
                                $machine = trim((string) (($booking['laser_brand'] ?? '') . ' ' . ($booking['laser_model'] ?? '')));
                            ?>
                            <tr class="align-top">
                                <td class="py-3 pr-3 text-zinc-500 font-mono text-xs"><?= (int) ($booking['id'] ?? 0) ?></td>
                                <td class="py-3 pr-3">
                                    <div class="text-zinc-200"><?= $machine !== '' ? cp_h($machine) : '—' ?></div>
                                    <div class="text-xs text-zinc-500 mt-1"><?= cp_h((string) ($booking['problem_summary'] ?? '')) ?></div>
                                </td>
                                <td class="py-3 pr-3"><span class="status-pill <?= cp_h($pid['class']) ?>"><?= cp_h($pid['label']) ?></span></td>
                                <td class="py-3 pr-3"><span class="status-pill <?= cp_h($sid['class']) ?>"><?= cp_h($sid['label']) ?></span></td>
                                <td class="py-3 pr-3 text-zinc-400 whitespace-nowrap"><?= cp_fmtDate((string) ($booking['created_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    </div>
</main>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
