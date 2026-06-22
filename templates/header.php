<?php require_once __DIR__ . '/../functions.php'; ?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Ghost Laser') ?></title>
    <link rel="icon" type="image/png" href="<?= asset('ghost-logo2-32x32.png') ?>">
    <link rel="shortcut icon" type="image/png" href="<?= asset('ghost-logo2-32x32.png') ?>">
    <link rel="apple-touch-icon" href="<?= asset('ghost-logo2-32x32.png') ?>">
    <?php if (!empty($pageDescription)): ?>
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <?php endif; ?>
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
                    backgroundImage: {
                        'grid-pattern': "linear-gradient(rgba(6,182,212,0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(6,182,212,0.05) 1px, transparent 1px)",
                    },
                    backgroundSize: {
                        'grid': '60px 60px',
                    }
                }
            }
        }
    </script>
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
                <?= $headerRight ?? '' ?>
            </div>
        </div>
        <?= $headerMobileMenu ?? '' ?>
    </header>
