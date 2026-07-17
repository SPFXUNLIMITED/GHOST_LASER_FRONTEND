<?php
require_once __DIR__ . '/../functions.php';

$sessionActive = session_status() === PHP_SESSION_ACTIVE;
$customerLoggedIn = $sessionActive && !empty($_SESSION['customer_id']);
$customerFirstName = $customerLoggedIn ? trim((string) ($_SESSION['customer_first_name'] ?? '')) : '';
$customerLastName = $customerLoggedIn ? trim((string) ($_SESSION['customer_last_name'] ?? '')) : '';
$customerEmail = $customerLoggedIn ? trim((string) ($_SESSION['customer_email'] ?? '')) : '';
$customerFullName = trim($customerFirstName . ' ' . $customerLastName);
if ($customerLoggedIn && $customerFullName === '') {
    $customerFullName = $customerEmail !== '' ? $customerEmail : 'Customer';
}
$customerInitials = '';
if ($customerLoggedIn) {
    if ($customerFirstName !== '') {
        $customerInitials .= substr($customerFirstName, 0, 1);
    }
    if ($customerLastName !== '') {
        $customerInitials .= substr($customerLastName, 0, 1);
    }
    if ($customerInitials === '') {
        $customerInitials = $customerEmail !== '' ? substr($customerEmail, 0, 1) : 'C';
    }
    $customerInitials = strtoupper($customerInitials);
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= $pwaHead ?? '' ?>
    <title><?= htmlspecialchars($pageTitle ?? 'Ghost Laser') ?></title>
    <?php if (empty($pwaHead)): ?>
    <link rel="icon" type="image/png" href="<?= asset('ghost-logo2-32x32.png') ?>">
    <link rel="shortcut icon" type="image/png" href="<?= asset('ghost-logo2-32x32.png') ?>">
    <link rel="apple-touch-icon" href="<?= asset('ghost-logo2-32x32.png') ?>">
    <?php endif; ?>
    <?php if (!empty($pageDescription)): ?>
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= asset('assets/css/tailwind.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap&v=1.2" rel="stylesheet">
    <style>
        .nav-blur {
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
        .hero-grid {
            background-image: linear-gradient(rgba(6,182,212,0.04) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(6,182,212,0.04) 1px, transparent 1px);
            background-size: 60px 60px;
        }
        .header-user-menu-dropdown {
            min-width: 14rem;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45);
        }
        .header-user-avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            background: rgba(6, 182, 212, 0.2);
            border: 1px solid rgba(34, 211, 238, 0.4);
            color: rgb(165, 243, 252);
            flex-shrink: 0;
        }
    </style>
    <?= $extraHead ?? '' ?>
</head>
<body class="bg-zinc-950 text-white font-sans antialiased <?= $bodyClass ?? '' ?>">

    <header class="fixed top-0 left-0 right-0 z-50 nav-blur bg-zinc-950/80 border-b border-zinc-800/60">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="<?= $logoHref ?? '/' ?>" class="flex items-center gap-2.5 group">
                    <span class="w-7 h-7 rounded bg-cyan-500 flex items-center justify-center flex-shrink-0 group-hover:bg-cyan-400 transition-colors">
                        <svg class="w-4 h-4 text-zinc-950" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10 1C6.13 1 3 4.13 3 8v10l2.5-2 2.5 2 2.5-2 2.5 2 2.5-2 2.5 2V8C17 4.13 13.87 1 10 1z"/>
                        </svg>
                    </span>
                    <span class="text-white font-bold text-lg tracking-tight">Ghost<span class="text-cyan-400">Laser</span></span>
                </a>
                <div class="flex items-center gap-3">
                    <?= $headerRight ?? '' ?>
                    <?php if ($customerLoggedIn): ?>
                    <div class="relative" id="header-user-menu">
                        <button
                            type="button"
                            id="header-user-menu-toggle"
                            class="inline-flex items-center gap-2 rounded-full border border-zinc-700/90 bg-zinc-900/85 px-2.5 py-1.5 text-sm text-zinc-100 transition-colors hover:border-cyan-400/50 hover:bg-zinc-900 focus:outline-none focus:ring-2 focus:ring-cyan-500/50"
                            aria-haspopup="menu"
                            aria-expanded="false"
                        >
                            <span class="header-user-avatar"><?= htmlspecialchars($customerInitials, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="max-w-[9rem] truncate text-sm font-medium text-zinc-200"><?= htmlspecialchars($customerFirstName !== '' ? $customerFirstName : 'Customer', ENT_QUOTES, 'UTF-8') ?></span>
                            <svg class="h-4 w-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div
                            id="header-user-menu-dropdown"
                            class="header-user-menu-dropdown hidden absolute right-0 mt-2 rounded-xl border border-zinc-700 bg-zinc-900/95 p-2"
                            role="menu"
                        >
                            <div class="px-2.5 py-2">
                                <p class="text-sm font-semibold text-white truncate"><?= htmlspecialchars($customerFullName, ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-0.5 text-xs text-zinc-400 truncate"><?= htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div class="my-1 border-t border-zinc-700/80"></div>
                            <a href="/customer-logout.php" class="block rounded-lg px-2.5 py-2 text-sm font-medium text-red-300 transition-colors hover:bg-red-950/40 hover:text-red-200" role="menuitem">
                                Log Out
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?= $headerMobileMenu ?? '' ?>
    </header>
    <script>
        (() => {
            const menu = document.getElementById('header-user-menu');
            const toggle = document.getElementById('header-user-menu-toggle');
            const dropdown = document.getElementById('header-user-menu-dropdown');
            if (!menu || !toggle || !dropdown) return;

            const closeMenu = () => {
                dropdown.classList.add('hidden');
                toggle.setAttribute('aria-expanded', 'false');
            };
            const openMenu = () => {
                dropdown.classList.remove('hidden');
                toggle.setAttribute('aria-expanded', 'true');
            };

            toggle.addEventListener('click', (event) => {
                event.stopPropagation();
                if (dropdown.classList.contains('hidden')) {
                    openMenu();
                } else {
                    closeMenu();
                }
            });

            document.addEventListener('click', (event) => {
                if (!menu.contains(event.target)) {
                    closeMenu();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeMenu();
                }
            });
        })();
    </script>
