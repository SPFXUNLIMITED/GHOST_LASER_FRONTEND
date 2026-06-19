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
        .glow-cyan { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
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

                <!-- Error banner -->
                <div id="error-banner" class="hidden mb-5 flex items-center gap-2 bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg">
                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span id="error-text">Invalid credentials. Please try again.</span>
                </div>

                <form id="login-form" novalidate>
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
                                    <svg id="eye-icon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                            id="submit-btn"
                            type="submit"
                            class="w-full inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 disabled:cursor-not-allowed text-zinc-950 font-semibold text-sm px-4 py-2.5 rounded-md transition-all btn-glow mt-1">
                            <span id="btn-label">Sign In</span>
                            <svg id="btn-spinner" class="hidden w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                            </svg>
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

        // Login form submission
        const form = document.getElementById('login-form');
        const submitBtn = document.getElementById('submit-btn');
        const btnLabel = document.getElementById('btn-label');
        const btnSpinner = document.getElementById('btn-spinner');
        const errorBanner = document.getElementById('error-banner');
        const errorText = document.getElementById('error-text');

        function showError(msg) {
            errorText.textContent = msg || 'Invalid credentials. Please try again.';
            errorBanner.classList.remove('hidden');
            errorBanner.classList.add('flex');
        }

        function hideError() {
            errorBanner.classList.add('hidden');
            errorBanner.classList.remove('flex');
        }

        function setLoading(loading) {
            submitBtn.disabled = loading;
            btnLabel.textContent = loading ? 'Signing in…' : 'Sign In';
            btnSpinner.classList.toggle('hidden', !loading);
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideError();

            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;

            if (!username || !password) {
                showError('Please enter your username and password.');
                return;
            }

            setLoading(true);

            try {
                const res = await fetch('/project/api/login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password }),
                    credentials: 'same-origin',
                });

                const json = await res.json().catch(() => ({}));

                if (res.ok && (json.success !== false)) {
                    window.location.href = '/project/';
                } else {
                    showError(json.message || json.error || 'Invalid credentials. Please try again.');
                }
            } catch (err) {
                showError('Unable to reach the server. Please try again.');
            } finally {
                setLoading(false);
            }
        });
    </script>
</body>
</html>
