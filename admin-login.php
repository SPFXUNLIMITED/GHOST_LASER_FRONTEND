<?php
session_start();

if (!empty($_SESSION)) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';

$dbPath = $_SERVER['DOCUMENT_ROOT'] . '/website_a844b2f2/project/db.php';

if (!file_exists($dbPath)) {
    $error = 'System configuration error. Path not found: ' . $dbPath;
} else {
    require_once $dbPath;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        try {
            // ── PDO path ──────────────────────────────────────────────────────
            if (isset($pdo)) {
                $stmt = $pdo->prepare(
                    'SELECT id, password_hash, is_admin, role FROM users WHERE username = ? LIMIT 1'
                );
                $stmt->execute([$username]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            // ── mysqli path ───────────────────────────────────────────────────
            } elseif (isset($conn)) {
                $stmt = $conn->prepare(
                    'SELECT id, password_hash, is_admin, role FROM users WHERE username = ? LIMIT 1'
                );
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $result = $stmt->get_result();
                $admin  = $result->fetch_assoc();
                $stmt->close();

            } else {
                throw new RuntimeException('No database connection available.');
            }

            $isAdmin = $admin && ($admin['is_admin'] == 1 || $admin['role'] === 'admin');

            if ($isAdmin && password_verify($password, $admin['password_hash'])) {
                // Regenerate session ID to prevent fixation
                session_regenerate_id(true);
                $_SESSION['admin_id']       = $admin['id'];
                $_SESSION['admin_username'] = $username;
                header('Location: /admin/dashboard.php');
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (Throwable $e) {
            $error = 'A server error occurred. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Ghost Laser</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="/favicon.ico">
    <meta name="description" content="Ghost Laser admin login.">
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
        .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
        .input-base {
            width: 100%;
            background: rgba(39,39,42,0.6);
            border: 1px solid rgb(63,63,70);
            color: white;
            border-radius: 0.5rem;
            padding: 0.625rem 1rem;
            font-size: 0.875rem;
            transition: border-color 0.15s, box-shadow 0.15s;
            outline: none;
        }
        .input-base::placeholder { color: rgb(82,82,91); }
        .input-base:focus { border-color: #06b6d4; box-shadow: 0 0 0 1px rgba(6,182,212,0.5); }
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
</head>
<body class="bg-zinc-950 text-white font-sans antialiased">

    <!-- NAV -->
    <header class="fixed top-0 left-0 right-0 z-50 nav-blur bg-zinc-950/80 border-b border-zinc-800/60">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="/" class="flex items-center gap-2.5 group">
                    <span class="w-7 h-7 rounded bg-cyan-500 flex items-center justify-center flex-shrink-0 group-hover:bg-cyan-400 transition-colors">
                        <svg class="w-4 h-4 text-zinc-950" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"/>
                        </svg>
                    </span>
                    <span class="text-white font-bold text-lg tracking-tight">Ghost<span class="text-cyan-400">Laser</span></span>
                </a>
                <a href="/" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Home</a>
            </div>
        </div>
    </header>

    <!-- LOGIN -->
    <main class="min-h-screen flex items-center justify-center hero-grid pt-16 px-4">
        <!-- Radial glow -->
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <div class="w-[500px] h-[500px] rounded-full bg-cyan-500/5 blur-3xl"></div>
        </div>

        <div class="relative w-full max-w-sm">
            <!-- Card -->
            <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-8 card-glow">
                <!-- Icon + heading -->
                <div class="flex flex-col items-center mb-8">
                    <span class="w-12 h-12 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </span>
                    <h1 class="text-xl font-bold tracking-tight">Admin Login</h1>
                    <p class="text-sm text-zinc-500 mt-1">Ghost Laser management portal</p>
                </div>

                <?php if ($error !== ''): ?>
                <!-- Error banner -->
                <div class="mb-5 flex items-center gap-2 bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg">
                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="flex flex-col gap-5">
                        <!-- Username -->
                        <div>
                            <label for="username" class="block text-sm font-medium text-zinc-300 mb-1.5">Username</label>
                            <input
                                id="username"
                                name="username"
                                type="text"
                                autocomplete="username"
                                placeholder="Enter your username"
                                value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                class="input-base"
                                required
                            >
                        </div>

                        <!-- Password -->
                        <div>
                            <label for="password" class="block text-sm font-medium text-zinc-300 mb-1.5">Password</label>
                            <div class="relative">
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    autocomplete="current-password"
                                    placeholder="Enter your password"
                                    class="input-base pr-10"
                                    required
                                >
                                <!-- Toggle visibility -->
                                <button type="button" id="toggle-password"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300 transition-colors"
                                    aria-label="Toggle password visibility">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Submit -->
                        <button
                            type="submit"
                            class="w-full inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-2.5 rounded-md transition-all btn-glow mt-1">
                            Sign In
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        // Toggle password visibility
        const toggleBtn = document.getElementById('toggle-password');
        const passwordInput = document.getElementById('password');
        toggleBtn.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        });
    </script>
</body>
</html>
