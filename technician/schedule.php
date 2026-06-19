<?php

declare(strict_types=1);

function envValue(array $keys, ?string $default = null): ?string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }

    return $default;
}

function quoteIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException('Invalid SQL identifier.');
    }

    return '`' . $identifier . '`';
}

function pickFirstExisting(array $candidates, array $available): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $available, true)) {
            return $candidate;
        }
    }

    return null;
}

function createDatabaseConnection(): PDO
{
    $databaseUrl = envValue(['DATABASE_URL', 'MYSQL_URL']);

    if ($databaseUrl !== null) {
        $parts = parse_url($databaseUrl);
        if ($parts === false || !isset($parts['scheme']) || strtolower($parts['scheme']) !== 'mysql') {
            throw new RuntimeException('DATABASE_URL must use the mysql scheme.');
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int) ($parts['port'] ?? 3306);
        $database = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
        $user = $parts['user'] ?? '';
        $password = $parts['pass'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        $charset = $query['charset'] ?? 'utf8mb4';
    } else {
        $host = envValue(['DB_HOST', 'DATABASE_HOST', 'MYSQL_HOST'], '127.0.0.1');
        $port = (int) envValue(['DB_PORT', 'DATABASE_PORT', 'MYSQL_PORT'], '3306');
        $database = envValue(['DB_NAME', 'DB_DATABASE', 'DATABASE_NAME', 'MYSQL_DATABASE']);
        $user = envValue(['DB_USER', 'DB_USERNAME', 'DATABASE_USER', 'MYSQL_USER'], 'root') ?? 'root';
        $password = envValue(['DB_PASS', 'DB_PASSWORD', 'DATABASE_PASSWORD', 'MYSQL_PASSWORD'], '') ?? '';
        $charset = envValue(['DB_CHARSET', 'DATABASE_CHARSET', 'MYSQL_CHARSET'], 'utf8mb4') ?? 'utf8mb4';
    }

    if ($database === null || $database === '') {
        throw new RuntimeException('Database name is not configured for the technician schedule page.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function fetchColumns(PDO $pdo, string $table): array
{
    $statement = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
    $statement->execute([$table]);

    return $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function buildCustomerNameExpression(array $customerColumns, array $serviceRequestColumns): string
{
    if (in_array('name', $customerColumns, true)) {
        return 'c.' . quoteIdentifier('name');
    }

    if (in_array('full_name', $customerColumns, true)) {
        return 'c.' . quoteIdentifier('full_name');
    }

    $firstNameColumn = pickFirstExisting(['first_name', 'firstname'], $customerColumns);
    $lastNameColumn = pickFirstExisting(['last_name', 'lastname'], $customerColumns);

    if ($firstNameColumn !== null && $lastNameColumn !== null) {
        return sprintf(
            'NULLIF(TRIM(CONCAT_WS(" ", c.%s, c.%s)), "")',
            quoteIdentifier($firstNameColumn),
            quoteIdentifier($lastNameColumn)
        );
    }

    if ($firstNameColumn !== null) {
        return 'c.' . quoteIdentifier($firstNameColumn);
    }

    if ($lastNameColumn !== null) {
        return 'c.' . quoteIdentifier($lastNameColumn);
    }

    $serviceNameColumn = pickFirstExisting(['customer_name', 'name'], $serviceRequestColumns);
    if ($serviceNameColumn !== null) {
        return 'sr.' . quoteIdentifier($serviceNameColumn);
    }

    throw new RuntimeException('Unable to locate a customer name column in the database schema.');
}

function buildColumnExpression(array $candidates, array $preferredColumns, string $preferredAlias, array $fallbackColumns = [], ?string $fallbackAlias = null): ?string
{
    $preferredColumn = pickFirstExisting($candidates, $preferredColumns);
    if ($preferredColumn !== null) {
        return $preferredAlias . '.' . quoteIdentifier($preferredColumn);
    }

    if ($fallbackAlias !== null) {
        $fallbackColumn = pickFirstExisting($candidates, $fallbackColumns);
        if ($fallbackColumn !== null) {
            return $fallbackAlias . '.' . quoteIdentifier($fallbackColumn);
        }
    }

    return null;
}

function parseSuggestedDates(mixed $rawValue): array
{
    if ($rawValue === null) {
        return [];
    }

    if (is_array($rawValue)) {
        $values = $rawValue;
    } else {
        $rawString = trim((string) $rawValue);
        if ($rawString === '') {
            return [];
        }

        $decoded = json_decode($rawString, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded)) {
                $values = $decoded;
            } elseif (is_string($decoded) && trim($decoded) !== '') {
                $values = [$decoded];
            } else {
                $values = [$rawString];
            }
        } else {
            $values = preg_split('/\s*(?:,|\||;)\s*/', $rawString) ?: [];
        }
    }

    $formatted = [];
    foreach ($values as $value) {
        $label = formatDateLabel((string) $value);
        if ($label !== null) {
            $formatted[$label] = $label;
        }
    }

    return array_values($formatted);
}

function formatDateLabel(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('F j, Y', $timestamp);
}

function earliestDateTimestamp(array $dates): ?int
{
    $timestamps = [];
    foreach ($dates as $date) {
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            $timestamps[] = strtotime(date('Y-m-d', $timestamp));
        }
    }

    if ($timestamps === []) {
        return null;
    }

    return min($timestamps);
}

function normalizePriority(string|null $priority): string
{
    $normalized = strtolower(trim((string) $priority));

    return match ($normalized) {
        'emergency' => 'Emergency',
        'vip' => 'VIP',
        default => 'Standard',
    };
}

function priorityRank(string $priority): int
{
    return match (normalizePriority($priority)) {
        'Emergency' => 0,
        'VIP' => 1,
        default => 2,
    };
}

$priorityStyles = [
    'Emergency' => [
        'badge' => 'border-red-400/30 bg-red-500/15 text-red-200',
        'dot' => 'bg-red-400',
    ],
    'VIP' => [
        'badge' => 'border-orange-400/30 bg-orange-500/15 text-orange-200',
        'dot' => 'bg-orange-400',
    ],
    'Standard' => [
        'badge' => 'border-blue-400/30 bg-blue-500/15 text-blue-200',
        'dot' => 'bg-blue-400',
    ],
];

$statusStyles = [
    'confirmed' => 'border-emerald-400/25 bg-emerald-500/10 text-emerald-200',
    'pending customer approval' => 'border-violet-400/25 bg-violet-500/10 text-violet-200',
    'parts check in progress' => 'border-cyan-400/25 bg-cyan-500/10 text-cyan-200',
    'dispatch ready' => 'border-green-400/25 bg-green-500/10 text-green-200',
    'awaiting technician assignment' => 'border-amber-400/25 bg-amber-500/10 text-amber-200',
    'scheduling review' => 'border-zinc-400/25 bg-zinc-500/10 text-zinc-200',
    'scheduled' => 'border-cyan-400/25 bg-cyan-500/10 text-cyan-200',
    'in progress' => 'border-orange-400/25 bg-orange-500/10 text-orange-200',
];

$serviceRequests = [];
$errorMessage = null;

try {
    $pdo = createDatabaseConnection();
    $serviceRequestColumns = fetchColumns($pdo, 'service_requests');
    $customerColumns = fetchColumns($pdo, 'customers');

    if ($serviceRequestColumns === [] || $customerColumns === []) {
        throw new RuntimeException('Required service request tables were not found in the configured database.');
    }

    $serviceRequestCustomerKey = pickFirstExisting(['customer_id', 'customers_id', 'client_id'], $serviceRequestColumns);
    $customerPrimaryKey = pickFirstExisting(['id', 'customer_id', 'client_id'], $customerColumns);

    if ($serviceRequestCustomerKey === null || $customerPrimaryKey === null) {
        throw new RuntimeException('Unable to determine how service_requests joins to customers.');
    }

    $customerNameExpression = buildCustomerNameExpression($customerColumns, $serviceRequestColumns);
    $cityExpression = buildColumnExpression(
        ['city', 'service_city', 'service_address_city'],
        $serviceRequestColumns,
        'sr',
        $customerColumns,
        'c'
    );
    $stateExpression = buildColumnExpression(
        ['state', 'service_state', 'service_address_state'],
        $serviceRequestColumns,
        'sr',
        $customerColumns,
        'c'
    );
    $priorityExpression = buildColumnExpression(['priority_level', 'priority'], $serviceRequestColumns, 'sr');
    $statusExpression = buildColumnExpression(['status', 'request_status'], $serviceRequestColumns, 'sr');
    $suggestedDatesExpression = buildColumnExpression(
        ['suggested_dates', 'suggested_service_dates', 'suggested_service_date', 'suggested_date', 'service_date', 'scheduled_date'],
        $serviceRequestColumns,
        'sr'
    );

    if ($cityExpression === null || $stateExpression === null || $priorityExpression === null || $suggestedDatesExpression === null) {
        throw new RuntimeException('The database schema is missing one or more required schedule fields.');
    }

    $select = [
        $customerNameExpression . ' AS customer_name',
        $cityExpression . ' AS city',
        $stateExpression . ' AS state',
        $priorityExpression . ' AS priority_level',
        $suggestedDatesExpression . ' AS suggested_dates_raw',
        ($statusExpression ?? 'NULL') . ' AS status',
    ];

    if (in_array('id', $serviceRequestColumns, true)) {
        $select[] = 'sr.`id` AS service_request_id';
    }

    $sql = sprintf(
        'SELECT %s FROM %s sr INNER JOIN %s c ON sr.%s = c.%s',
        implode(', ', $select),
        quoteIdentifier('service_requests'),
        quoteIdentifier('customers'),
        quoteIdentifier($serviceRequestCustomerKey),
        quoteIdentifier($customerPrimaryKey)
    );

    if ($statusExpression !== null) {
        $sql .= ' WHERE LOWER(TRIM(' . $statusExpression . ')) NOT IN ("cancelled", "canceled", "completed", "closed")';
    }

    $statement = $pdo->query($sql);
    $rows = $statement->fetchAll();

    $today = strtotime(date('Y-m-d'));

    foreach ($rows as $row) {
        $dates = parseSuggestedDates($row['suggested_dates_raw'] ?? null);
        $earliestDate = earliestDateTimestamp($dates);

        if ($earliestDate !== null && $earliestDate < $today) {
            continue;
        }

        $serviceRequests[] = [
            'customer_name' => trim((string) ($row['customer_name'] ?? '')) !== '' ? (string) $row['customer_name'] : 'Unknown Customer',
            'city' => trim((string) ($row['city'] ?? '')),
            'state' => strtoupper(trim((string) ($row['state'] ?? ''))),
            'priority_level' => normalizePriority((string) ($row['priority_level'] ?? '')),
            'suggested_dates' => $dates,
            'status' => trim((string) ($row['status'] ?? '')) !== '' ? (string) $row['status'] : 'Pending Review',
            'sort_priority' => priorityRank((string) ($row['priority_level'] ?? '')),
            'sort_date' => $earliestDate ?? PHP_INT_MAX,
        ];
    }

    usort($serviceRequests, static function (array $left, array $right): int {
        return [$left['sort_priority'], $left['sort_date'], $left['customer_name']]
            <=> [$right['sort_priority'], $right['sort_date'], $right['customer_name']];
    });
} catch (Throwable $throwable) {
    $errorMessage = $throwable->getMessage();
}

$totalRequests = count($serviceRequests);
$emergencyCount = count(array_filter($serviceRequests, static fn (array $request): bool => $request['priority_level'] === 'Emergency'));
$vipCount = count(array_filter($serviceRequests, static fn (array $request): bool => $request['priority_level'] === 'VIP'));
$standardCount = count(array_filter($serviceRequests, static fn (array $request): bool => $request['priority_level'] === 'Standard'));
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Schedule | Ghost Laser</title>
    <meta name="description" content="Upcoming Ghost Laser service requests for technician scheduling and dispatch planning.">
    <script src="https://cdn.tailwindcss.com?v=1.2"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        cyan: {
                            400: '#22d3ee',
                            500: '#06b6d4',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap&v=1.2" rel="stylesheet">
    <style>
        .glow-cyan { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
        .glow-box { box-shadow: 0 0 0 1px rgba(6,182,212,0.2), 0 0 40px rgba(6,182,212,0.05); }
        .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .nav-blur {
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
    </style>
</head>
<body class="bg-zinc-950 text-white font-sans antialiased">
    <header class="fixed top-0 left-0 right-0 z-50 nav-blur bg-zinc-950/80 border-b border-zinc-800/60">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="../" class="flex items-center gap-2.5 group">
                    <span class="w-7 h-7 rounded bg-cyan-500 flex items-center justify-center flex-shrink-0 group-hover:bg-cyan-400 transition-colors">
                        <svg class="w-4 h-4 text-zinc-950" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"/>
                        </svg>
                    </span>
                    <span class="text-white font-bold text-lg tracking-tight">Ghost<span class="text-cyan-400">Laser</span></span>
                </a>
                <nav class="hidden md:flex items-center gap-8">
                    <a href="../#services" class="text-sm text-zinc-400 hover:text-white transition-colors">Services</a>
                    <a href="../#why-us" class="text-sm text-zinc-400 hover:text-white transition-colors">Why Us</a>
                    <a href="../#process" class="text-sm text-zinc-400 hover:text-white transition-colors">Process</a>
                    <a href="../book-repair.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Bookings</a>
                </nav>
                <a href="../book-repair.php" class="hidden md:inline-flex items-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-2 rounded-md transition-colors btn-glow">
                    New Repair Request
                </a>
                <button id="mobile-menu-btn" class="md:hidden text-zinc-400 hover:text-white p-1" aria-label="Open menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>
        <div id="mobile-menu" class="hidden md:hidden border-t border-zinc-800/60 bg-zinc-950/95">
            <div class="px-6 py-4 flex flex-col gap-4">
                <a href="../#services" class="text-sm text-zinc-400 hover:text-white transition-colors">Services</a>
                <a href="../#why-us" class="text-sm text-zinc-400 hover:text-white transition-colors">Why Us</a>
                <a href="../#process" class="text-sm text-zinc-400 hover:text-white transition-colors">Process</a>
                <a href="../book-repair.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Bookings</a>
                <a href="../book-repair.php" class="inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-2 rounded-md transition-colors w-full">
                    New Repair Request
                </a>
            </div>
        </div>
    </header>

    <main class="pt-32 pb-24 lg:pb-32">
        <section class="mb-12">
            <div class="max-w-7xl mx-auto px-6 lg:px-8">
                <div class="inline-flex items-center gap-2 bg-zinc-900 border border-cyan-500/30 rounded-full px-4 py-1.5 mb-8">
                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
                    <span class="text-xs text-cyan-400 font-medium tracking-wider uppercase">Technician Dispatch Board</span>
                </div>
                <div class="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight mb-5">
                            Upcoming <span class="text-cyan-400 glow-cyan">Service Requests</span>
                        </h1>
                        <p class="text-zinc-400 text-lg leading-relaxed max-w-2xl">
                            Live schedule data from the repair queue, sorted by priority first and then by the next suggested service date.
                        </p>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 w-full lg:max-w-3xl">
                        <div class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4 glow-box">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Total</p>
                            <p class="mt-3 text-3xl font-black text-white"><?= $totalRequests ?></p>
                        </div>
                        <div class="rounded-2xl border border-red-500/20 bg-red-500/5 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-red-300/80">Emergency</p>
                            <p class="mt-3 text-3xl font-black text-red-200"><?= $emergencyCount ?></p>
                        </div>
                        <div class="rounded-2xl border border-orange-500/20 bg-orange-500/5 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-300/80">VIP</p>
                            <p class="mt-3 text-3xl font-black text-orange-200"><?= $vipCount ?></p>
                        </div>
                        <div class="rounded-2xl border border-blue-500/20 bg-blue-500/5 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-300/80">Standard</p>
                            <p class="mt-3 text-3xl font-black text-blue-200"><?= $standardCount ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section>
            <div class="max-w-7xl mx-auto px-6 lg:px-8">
                <?php if ($errorMessage !== null): ?>
                    <div class="mb-8 rounded-2xl border border-red-500/30 bg-red-950/50 px-5 py-4 text-sm text-red-100">
                        <p class="font-semibold text-red-300">Unable to load live schedule data.</p>
                        <p class="mt-1 text-red-200/80"><?= htmlspecialchars($errorMessage) ?></p>
                    </div>
                <?php endif; ?>

                <div class="overflow-hidden rounded-3xl border border-zinc-800 bg-zinc-900/60 glow-box">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-800">
                            <thead class="bg-zinc-950/70">
                                <tr>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Customer name</th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Service address</th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Priority level</th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Suggested service date(s)</th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/80">
                                <?php if ($serviceRequests === []): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-12 text-center">
                                            <p class="text-sm font-semibold text-white">No upcoming service requests found.</p>
                                            <p class="mt-2 text-sm text-zinc-400">Once real requests with upcoming suggested dates are available, they will appear here automatically.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($serviceRequests as $request): ?>
                                        <?php
                                        $priorityStyle = $priorityStyles[$request['priority_level']] ?? $priorityStyles['Standard'];
                                        $statusKey = strtolower(trim($request['status']));
                                        $statusStyle = $statusStyles[$statusKey] ?? 'border-zinc-400/25 bg-zinc-500/10 text-zinc-200';
                                        ?>
                                        <tr class="align-top transition-colors hover:bg-zinc-900/80">
                                            <td class="px-6 py-5">
                                                <p class="text-sm font-semibold text-white"><?= htmlspecialchars($request['customer_name']) ?></p>
                                            </td>
                                            <td class="px-6 py-5">
                                                <p class="text-sm text-zinc-300">
                                                    <?= htmlspecialchars($request['city'] !== '' ? $request['city'] : 'Unknown City') ?>,
                                                    <?= htmlspecialchars($request['state'] !== '' ? $request['state'] : 'N/A') ?>
                                                </p>
                                            </td>
                                            <td class="px-6 py-5">
                                                <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold tracking-wide <?= $priorityStyle['badge'] ?>">
                                                    <span class="h-2 w-2 rounded-full <?= $priorityStyle['dot'] ?>"></span>
                                                    <?= htmlspecialchars($request['priority_level']) ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-5">
                                                <div class="flex flex-wrap gap-2">
                                                    <?php if ($request['suggested_dates'] === []): ?>
                                                        <span class="inline-flex items-center rounded-full border border-zinc-700 bg-zinc-800/70 px-3 py-1 text-xs font-medium text-zinc-300">
                                                            Awaiting scheduling
                                                        </span>
                                                    <?php else: ?>
                                                        <?php foreach ($request['suggested_dates'] as $date): ?>
                                                            <span class="inline-flex items-center rounded-full border border-cyan-500/20 bg-cyan-500/5 px-3 py-1 text-xs font-medium text-cyan-100">
                                                                <?= htmlspecialchars($date) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-5">
                                                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold tracking-wide <?= $statusStyle ?>">
                                                    <?= htmlspecialchars($request['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="bg-zinc-950 border-t border-zinc-800/60 py-10">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2.5">
                <span class="w-6 h-6 rounded bg-cyan-500 flex items-center justify-center flex-shrink-0">
                    <svg class="w-3.5 h-3.5 text-zinc-950" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"/>
                    </svg>
                </span>
                <span class="text-white font-bold text-sm">Ghost<span class="text-cyan-400">Laser</span></span>
            </div>
            <p class="text-xs text-zinc-500 text-center">
                &copy; <?= date('Y') ?> Ghost Laser. All rights reserved. Expert laser machine repair.
            </p>
            <div class="flex items-center gap-5">
                <a href="../" class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors">Home</a>
                <a href="../book-repair.php" class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors">Bookings</a>
            </div>
        </div>
    </footer>

    <script>
        const btn = document.getElementById('mobile-menu-btn');
        const menu = document.getElementById('mobile-menu');

        btn.addEventListener('click', () => menu.classList.toggle('hidden'));
        menu.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => menu.classList.add('hidden'));
        });
    </script>
</body>
</html>
