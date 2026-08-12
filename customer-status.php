<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/functions.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

ensure_customer_status_table($pdo);

if (empty($_SESSION['customer_status_csrf'])) {
    $_SESSION['customer_status_csrf'] = bin2hex(random_bytes(32));
}
$customerSearchCsrfToken = (string) $_SESSION['customer_status_csrf'];

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 5);
    $status = trim((string) ($_POST['status'] ?? 'Good'));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $allowedStatuses = ['VIP', 'Good', 'Caution', 'Banned'];

    if ($customerId <= 0) {
        $errorMessage = 'Please select a customer.';
    } elseif ($rating < 1 || $rating > 5) {
        $errorMessage = 'Rating must be between 1 and 5.';
    } elseif (!in_array($status, $allowedStatuses, true)) {
        $errorMessage = 'Please choose a valid status.';
    } else {
        $hasOutstandingBalance = isset($_POST['has_outstanding_balance']) ? 1 : 0;
        $adminIdentity = trim((string) ($_SESSION['admin_username'] ?? 'Admin #' . (int) $_SESSION['admin_id']));
        $stmt = $pdo->prepare("
            INSERT INTO customer_status (customer_id, rating, status, notes, has_outstanding_balance, updated_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                rating = VALUES(rating),
                status = VALUES(status),
                notes = VALUES(notes),
                has_outstanding_balance = VALUES(has_outstanding_balance),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $customerId,
            $rating,
            $status,
            $notes !== '' ? $notes : null,
            $hasOutstandingBalance,
            $adminIdentity,
        ]);
        $successMessage = 'Customer status updated.';
    }
}

$pageTitle = 'Customer Status | Ghost Laser';
$pageDescription = 'Customer blacklist and rating management.';
$extraHead = <<<'HTML'
<style>
    .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
    #customerSearchSuggestions {
        position: absolute;
        z-index: 50;
        left: 0;
        right: 0;
        top: calc(100% + 4px);
        background: #18181b;
        border: 1px solid rgb(63,63,70);
        border-radius: 0.5rem;
        box-shadow: 0 8px 30px rgba(0,0,0,0.5);
        max-height: 280px;
        overflow-y: auto;
    }
    #customerSearchSuggestions li {
        padding: 0.65rem 1rem;
        font-size: 0.8rem;
        color: #d4d4d8;
        cursor: pointer;
        border-bottom: 1px solid rgba(63,63,70,0.5);
    }
    #customerSearchSuggestions li:last-child { border-bottom: none; }
    #customerSearchSuggestions li:hover, #customerSearchSuggestions li.active { background: rgba(6,182,212,0.12); color: #22d3ee; }
    #customerSearchSuggestions li .result-name { font-weight: 600; color: #f4f4f5; }
    #customerSearchSuggestions li .result-meta { color: #71717a; margin-top: 1px; }
</style>
HTML;
$headerRight = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                    <a href="book_internal.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Internal Booking</a>
                </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

<main class="min-h-screen hero-grid pt-24 pb-16 px-4">
    <div class="max-w-7xl mx-auto space-y-6">
        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
            <div class="flex flex-col gap-3">
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/20 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-[0.2em] text-cyan-400 w-fit">
                    Customer Controls
                </div>
                <h1 class="text-3xl font-bold tracking-tight">Customer Blacklist / Rating System</h1>
                <p class="text-zinc-400">Search, review, and update customer health status before booking.</p>
            </div>
        </section>

        <?php if ($successMessage !== null): ?>
            <div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                <?= h($successMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <div class="rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                <?= h($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 card-glow space-y-4">
            <label class="block">
                <span class="text-sm font-medium text-zinc-200">Live Customer Search</span>
                <div class="relative mt-2" id="live-search-wrap">
                    <input
                        id="customerSearchInput"
                        type="text"
                        class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none"
                        placeholder="Search by name, email, or phone..."
                        autocomplete="off"
                    >
                    <ul id="customerSearchSuggestions" class="hidden" role="listbox" aria-label="Customer search results"></ul>
                </div>
            </label>
            <div class="flex flex-wrap items-center gap-2" id="statusFilterGroup">
                <button data-filter="" type="button" class="status-filter-btn rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition border-cyan-500/40 bg-cyan-500/10 text-cyan-200 hover:bg-cyan-500/20">
                    All Customers
                </button>
                <button data-filter="vip" type="button" class="status-filter-btn rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition border-amber-500/60 bg-amber-500/20 text-amber-200 ring-2 ring-amber-400/70">
                    VIP
                </button>
                <button data-filter="good" type="button" class="status-filter-btn rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition border-emerald-500/40 bg-emerald-500/10 text-emerald-200 hover:bg-emerald-500/20">
                    Good
                </button>
                <button data-filter="caution" type="button" class="status-filter-btn rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition border-yellow-500/40 bg-yellow-500/10 text-yellow-200 hover:bg-yellow-500/20">
                    Caution
                </button>
                <button data-filter="banned" type="button" class="status-filter-btn rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition border-red-500/40 bg-red-500/10 text-red-200 hover:bg-red-500/20">
                    Banned
                </button>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-[1.45fr_1fr]">
            <div class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-4 md:p-5 card-glow overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                    <tr class="text-left text-zinc-400 border-b border-zinc-800">
                        <th class="py-3 px-3">Customer</th>
                        <th class="py-3 px-3">Rating</th>
                        <th class="py-3 px-3">Status</th>
                        <th class="py-3 px-3">Notes</th>
                        <th class="py-3 px-3">
                            Outstanding Balance
                            <span title="Flags customers who have unpaid invoices" class="ml-1 cursor-help text-zinc-500 hover:text-zinc-300">&#9432;</span>
                        </th>
                    </tr>
                    </thead>
                    <tbody id="customerStatusTableBody"></tbody>
                </table>
            </div>

            <div class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 card-glow">
                <h2 class="text-xl font-semibold text-white">Update Customer Status</h2>
                <p id="selectedCustomerLabel" class="mt-2 text-sm text-zinc-400">Select a customer from the table or live search.</p>
                <form method="post" class="mt-5 space-y-4">
                    <input type="hidden" id="customer_id" name="customer_id" value="">
                    <div>
                        <label class="text-sm font-medium text-zinc-200" for="rating">Star Rating</label>
                        <select id="rating" name="rating" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <option value="<?= $i ?>"><?= $i ?> Star<?= $i === 1 ? '' : 's' ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-zinc-200" for="status">Status</label>
                        <select id="status" name="status" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none">
                            <option value="VIP">VIP</option>
                            <option value="Good">Good</option>
                            <option value="Caution">Caution</option>
                            <option value="Banned">Banned</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-zinc-200" for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="5" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" placeholder="Issue history, behavior notes, payment concerns..."></textarea>
                    </div>
                    <label class="flex items-center gap-3 rounded-lg border border-zinc-800 bg-zinc-950/60 px-4 py-3 text-sm text-zinc-200">
                        <input
                            id="has_outstanding_balance"
                            name="has_outstanding_balance"
                            type="checkbox"
                            value="1"
                            class="h-4 w-4 rounded border-zinc-600 bg-zinc-900 text-cyan-500 focus:ring-cyan-400"
                        >
                        <span>Outstanding Balance</span>
                    </label>
                    <button type="submit" class="w-full rounded-lg bg-cyan-500 px-4 py-3 text-sm font-semibold text-zinc-950 transition hover:bg-cyan-400">Save Status</button>
                </form>
            </div>
        </section>
    </div>
</main>

<script>
const customerSearchCsrfToken = <?= json_encode($customerSearchCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const searchInput = document.getElementById('customerSearchInput');
const suggestionsEl = document.getElementById('customerSearchSuggestions');
const tableBody = document.getElementById('customerStatusTableBody');
const filterBtns = Array.from(document.querySelectorAll('.status-filter-btn'));
let rows = [];
let debounceTimer = null;
let activeIndex = -1;
let currentResults = [];
let activeStatusFilter = 'vip';

const escHtml = (str) => String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

const bindCustomer = (customer) => {
    const customerId = String(customer.id || customer.customerId || '');
    if (!customerId) return;
    document.getElementById('customer_id').value = customerId;
    document.getElementById('rating').value = String(customer.rating || 5);
    document.getElementById('status').value = String(customer.status || 'Good');
    document.getElementById('notes').value = String(customer.notes || '');

    const hasBalance = Boolean(Number(customer.outstanding ?? customer.has_outstanding_balance ?? 0));
    document.getElementById('has_outstanding_balance').checked = hasBalance;

    const fullName = String(customer.name || customer.customer_name || '').trim();
    const email = String(customer.email || '').trim();
    document.getElementById('selectedCustomerLabel').textContent = `Editing: ${fullName || 'Customer #' + customerId}${email ? ' · ' + email : ''}`;
};

const getRowClasses = (status) => {
    const normalizedStatus = String(status || 'Good').trim().toLowerCase();
    if (normalizedStatus === 'banned') {
        return {
            rowClass: 'bg-red-950/40 border-red-500/20 hover:bg-red-900/40',
            statusClass: 'border-red-500/30 bg-red-500/20 text-red-200',
            displayStatus: 'Banned'
        };
    }
    if (normalizedStatus === 'vip') {
        return {
            rowClass: 'bg-purple-950/40 border-purple-500/20 hover:bg-purple-900/40',
            statusClass: 'border-amber-400/40 bg-gradient-to-r from-amber-500/30 to-purple-500/30 text-amber-100',
            displayStatus: 'VIP'
        };
    }
    if (normalizedStatus === 'caution') {
        return {
            rowClass: 'hover:bg-zinc-800/50',
            statusClass: 'border-amber-500/30 bg-amber-500/20 text-amber-200',
            displayStatus: 'Caution'
        };
    }
    return {
        rowClass: 'hover:bg-zinc-800/50',
        statusClass: 'border-emerald-500/30 bg-emerald-500/20 text-emerald-200',
        displayStatus: 'Good'
    };
};

const buildTableRow = (customer) => {
    const customerId = Number(customer.id || customer.customerId || 0);
    const statusValue = String(customer.status || 'Good');
    const normalizedStatus = statusValue.toLowerCase();
    const rowMeta = getRowClasses(statusValue);
    const fullName = String(customer.customer_name || customer.name || `${customer.first_name || ''} ${customer.last_name || ''}`).trim();
    const displayName = fullName || `Customer #${customerId}`;
    const email = String(customer.email || '').trim();
    const phone = String(customer.phone || '').trim();
    const rating = Math.max(1, Math.min(5, Number(customer.rating || 5)));
    const notes = String(customer.notes || '').trim();
    const hasOutstandingBalance = Number(customer.has_outstanding_balance || customer.outstanding || 0) === 1;

    const tr = document.createElement('tr');
    tr.className = `border-b border-zinc-800/70 cursor-pointer transition-colors ${rowMeta.rowClass}`;
    tr.dataset.customerRow = '1';
    tr.dataset.id = String(customerId);
    tr.dataset.name = displayName;
    tr.dataset.email = email;
    tr.dataset.phone = phone;
    tr.dataset.rating = String(rating);
    tr.dataset.status = rowMeta.displayStatus;
    tr.dataset.notes = notes;
    tr.dataset.outstanding = hasOutstandingBalance ? '1' : '0';
    tr.innerHTML = `
        <td class="py-3 px-3">
            <div class="font-medium text-white">${escHtml(displayName)}</div>
            <div class="text-xs text-zinc-400">${escHtml(email)}${phone ? ' · ' + escHtml(phone) : ''}</div>
        </td>
        <td class="py-3 px-3 text-zinc-100">${'★'.repeat(rating)}</td>
        <td class="py-3 px-3">
            <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${rowMeta.statusClass}">
                ${normalizedStatus === 'vip' ? '★ ' : ''}${escHtml(rowMeta.displayStatus)}
            </span>
        </td>
        <td class="py-3 px-3 text-zinc-300 max-w-[260px] truncate">${escHtml(notes)}</td>
        <td class="py-3 px-3">
            ${hasOutstandingBalance
                ? '<span class="inline-flex rounded-full border border-red-500/30 bg-red-500/20 px-2.5 py-1 text-xs font-semibold text-red-200">Yes</span>'
                : '<span class="inline-flex rounded-full border border-zinc-600 px-2.5 py-1 text-xs font-semibold text-zinc-300">No</span>'}
        </td>
    `;
    tr.addEventListener('click', () => {
        bindCustomer({
            customerId: tr.dataset.id,
            name: tr.dataset.name,
            email: tr.dataset.email,
            rating: tr.dataset.rating,
            status: tr.dataset.status,
            notes: tr.dataset.notes,
            outstanding: tr.dataset.outstanding
        });
        tr.scrollIntoView({ block: 'nearest' });
    });
    return tr;
};

const renderTableRows = (customers) => {
    tableBody.innerHTML = '';
    const rowsToRender = Array.isArray(customers) ? customers : [];
    if (!rowsToRender.length) {
        tableBody.innerHTML = '<tr><td colspan="5" class="px-3 py-6 text-center text-sm text-zinc-500">No customers found.</td></tr>';
        rows = [];
        return;
    }
    const fragment = document.createDocumentFragment();
    rowsToRender.forEach((customer) => fragment.appendChild(buildTableRow(customer)));
    tableBody.appendChild(fragment);
    rows = Array.from(document.querySelectorAll('[data-customer-row]'));
    syncTableFilter();
};

const fetchCustomersByStatus = async (statusFilter) => {
    const response = await fetch(`customer-login-ajax.php?action=customer_status_list&status=${encodeURIComponent(statusFilter)}`, {
        headers: { 'X-CSRF-Token': customerSearchCsrfToken }
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Customer list failed.');
    return Array.isArray(data.results) ? data.results : [];
};

const fetchCustomersBySearch = async (query) => {
    const response = await fetch(`customer-login-ajax.php?action=customer_search&q=${encodeURIComponent(query)}`, {
        headers: { 'X-CSRF-Token': customerSearchCsrfToken }
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Customer search failed.');
    return Array.isArray(data.results) ? data.results : [];
};

const syncTableFilter = () => {
    const query = searchInput.value.trim().toLowerCase();
    rows.forEach((row) => {
        const textBlob = `${row.dataset.name || ''} ${row.dataset.email || ''} ${row.dataset.phone || ''}`.toLowerCase();
        const status = String(row.dataset.status || '').toLowerCase();
        const queryMatches = query === '' || textBlob.includes(query);
        const statusMatches = activeStatusFilter === '' || status === activeStatusFilter;
        row.classList.toggle('hidden', !(queryMatches && statusMatches));
    });
};

const renderSuggestions = (results) => {
    suggestionsEl.innerHTML = '';
    activeIndex = -1;
    if (!results.length) {
        suggestionsEl.classList.add('hidden');
        return;
    }
    results.forEach((customer, idx) => {
        const li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.dataset.idx = String(idx);
        const isBanned = String(customer.status || '').toLowerCase() === 'banned';
        const isVip = String(customer.status || '').toLowerCase() === 'vip';
        const statusMeta = isBanned
            ? '<span class="text-red-300 font-semibold">BANNED</span> &nbsp;&middot;&nbsp; '
            : (isVip ? '<span class="text-amber-200 font-semibold">★ VIP</span> &nbsp;&middot;&nbsp; ' : '');
        li.innerHTML = `<div class="result-name">${escHtml(customer.customer_name || '')}</div>`
            + `<div class="result-meta">${statusMeta}${customer.phone ? escHtml(customer.phone) + ' &nbsp;&middot;&nbsp; ' : ''}${escHtml(customer.email)}</div>`;
        if (isBanned) {
            li.classList.add('!bg-red-950/40', '!text-red-200');
        } else if (isVip) {
            li.classList.add('!bg-purple-950/40', '!text-amber-100');
        }
        li.addEventListener('mousedown', (event) => {
            event.preventDefault();
            searchInput.value = customer.customer_name || '';
            suggestionsEl.classList.add('hidden');
            bindCustomer(customer);
            syncTableFilter();
            const row = document.querySelector(`[data-customer-row][data-id="${CSS.escape(String(customer.id || ''))}"]`);
            if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        suggestionsEl.appendChild(li);
    });
    suggestionsEl.classList.remove('hidden');
};

searchInput.addEventListener('input', () => {
    syncTableFilter();
    clearTimeout(debounceTimer);
    const q = searchInput.value.trim();
    if (q.length < 2) {
        suggestionsEl.classList.add('hidden');
        suggestionsEl.innerHTML = '';
        currentResults = [];
        if (q.length === 0) {
            loadStatusCustomers();
        }
        return;
    }
    debounceTimer = setTimeout(async () => {
        try {
            currentResults = await fetchCustomersBySearch(q);
            renderTableRows(currentResults);
            renderSuggestions(currentResults);
        } catch (_) {
            suggestionsEl.classList.add('hidden');
            suggestionsEl.innerHTML = '';
            renderTableRows([]);
        }
    }, 180);
});

searchInput.addEventListener('keydown', (event) => {
    const items = suggestionsEl.querySelectorAll('li');
    if (!items.length) return;
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex = Math.min(activeIndex + 1, items.length - 1);
        items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
    } else if (event.key === 'Enter' && activeIndex >= 0) {
        event.preventDefault();
        const selected = currentResults[activeIndex];
        if (!selected) return;
        searchInput.value = selected.customer_name || '';
        suggestionsEl.classList.add('hidden');
        bindCustomer(selected);
        syncTableFilter();
    } else if (event.key === 'Escape') {
        suggestionsEl.classList.add('hidden');
    }
});

document.addEventListener('click', (event) => {
    if (!document.getElementById('live-search-wrap')?.contains(event.target)) {
        suggestionsEl.classList.add('hidden');
    }
});

const activeClasses = {
    '':       ['border-cyan-500/60',   'bg-cyan-500/15',    'text-cyan-200',   'ring-2', 'ring-cyan-400/70'],
    'vip':    ['border-amber-500/60',  'bg-amber-500/20',   'text-amber-200',  'ring-2', 'ring-amber-400/70'],
    'good':   ['border-emerald-500/60','bg-emerald-500/20', 'text-emerald-200','ring-2', 'ring-emerald-400/70'],
    'caution':['border-yellow-500/60', 'bg-yellow-500/20',  'text-yellow-200', 'ring-2', 'ring-yellow-400/70'],
    'banned': ['border-red-500/60',    'bg-red-500/20',     'text-red-200',    'ring-2', 'ring-red-400/70'],
};

const loadStatusCustomers = async () => {
    try {
        const customers = await fetchCustomersByStatus(activeStatusFilter);
        renderTableRows(customers);
    } catch (_) {
        renderTableRows([]);
    }
};

const setStatusFilter = async (statusFilter) => {
    activeStatusFilter = statusFilter;
    filterBtns.forEach((btn) => {
        const filter = btn.dataset.filter;
        const classes = activeClasses[filter] || [];
        if (filter === statusFilter) {
            btn.classList.add(...classes);
        } else {
            btn.classList.remove(...classes);
        }
    });
    if (searchInput.value.trim().length < 2) {
        await loadStatusCustomers();
    } else {
        syncTableFilter();
    }
};

filterBtns.forEach((btn) => {
    btn.addEventListener('click', async () => {
        searchInput.value = '';
        currentResults = [];
        suggestionsEl.classList.add('hidden');
        suggestionsEl.innerHTML = '';
        await setStatusFilter(btn.dataset.filter);
    });
});

setStatusFilter('vip');
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
