<?php
session_start();

// Redirect already-logged-in customers
if (!empty($_SESSION['customer_id'])) {
    header('Location: book-repair.php');
    exit;
}

require_once __DIR__ . '/project/db.php';

$mode   = $_GET['mode'] ?? 'landing'; // 'landing' | 'login'
$error  = '';
$notice = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($mode === 'login')) {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, first_name, password_hash FROM customers WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($customer && !empty($customer['password_hash']) && password_verify($password, $customer['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['customer_id']         = $customer['id'];
            $_SESSION['customer_first_name'] = $customer['first_name'];
            header('Location: book-repair.php');
            exit;
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}

$pageTitle       = 'Customer Login | Ghost Laser';
$pageDescription = 'Log in or register to book a laser machine repair with Ghost Laser.';
$extraHead       = <<<'HTML'
    <style>
        .btn-glow     { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .card-glow    { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
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
    </style>
HTML;
$headerRight = '<a href="/" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Home</a>';
require_once __DIR__ . '/templates/header.php';
?>

<main class="min-h-screen flex items-center justify-center hero-grid pt-16 px-4">
    <!-- Radial glow -->
    <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
        <div class="w-[500px] h-[500px] rounded-full bg-cyan-500/5 blur-3xl"></div>
    </div>

    <div class="relative w-full max-w-sm">

        <?php if ($mode !== 'login'): ?>
        <!-- ── LANDING: Choose Login or Register ── -->
        <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-8 card-glow">
            <!-- Icon + heading -->
            <div class="flex flex-col items-center mb-8">
                <span class="w-12 h-12 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center mb-4">
                    <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </span>
                <h1 class="text-xl font-bold tracking-tight">Book a Repair</h1>
                <p class="text-sm text-zinc-500 mt-1 text-center">Are you a new or returning customer?</p>
            </div>

            <div class="flex flex-col gap-4">
                <!-- Register -->
                <a href="book_dash_repair.php"
                   class="w-full inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-semibold text-sm px-4 py-3 rounded-md transition-all btn-glow">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                    </svg>
                    New Customer — Register &amp; Book
                </a>

                <!-- Login -->
                <a href="customer-login.php?mode=login"
                   class="w-full inline-flex items-center justify-center gap-2 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-white font-semibold text-sm px-4 py-3 rounded-md transition-all">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                    </svg>
                    Returning Customer — Log In
                </a>
            </div>
        </div>

        <?php else: ?>
        <!-- ── LOGIN FORM ── -->
        <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-8 card-glow">
            <!-- Icon + heading -->
            <div class="flex flex-col items-center mb-8">
                <span class="w-12 h-12 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center mb-4">
                    <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </span>
                <h1 class="text-xl font-bold tracking-tight">Welcome Back</h1>
                <p class="text-sm text-zinc-500 mt-1">Log in to your Ghost Laser account</p>
            </div>

            <?php if ($error !== ''): ?>
            <div class="mb-5 flex items-center gap-2 bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg">
                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <span><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="customer-login.php?mode=login">
                <div class="flex flex-col gap-5">
                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-zinc-300 mb-1.5">Email Address</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            autocomplete="email"
                            placeholder="you@example.com"
                            value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            class="input-base"
                            required
                        >
                    </div>

                    <!-- Password -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="password" class="block text-sm font-medium text-zinc-300">Password</label>
                            <a href="customer-forgot-password.php" class="text-xs text-cyan-400 hover:text-cyan-300 transition-colors">Forgot password?</a>
                        </div>
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

            <!-- Back / Register link -->
            <div class="mt-6 text-center text-sm text-zinc-500">
                New customer?
                <a href="customer-login.php" class="text-cyan-400 hover:text-cyan-300 transition-colors font-medium">Register here</a>
            </div>
        </div>
        <?php endif; ?>

    </div>
</main>

<script>
    const toggleBtn     = document.getElementById('toggle-password');
    const passwordInput = document.getElementById('password');
    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        });
    }
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
