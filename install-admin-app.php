<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}
?>
<?php
$pageTitle       = 'Install Admin App | Ghost Laser';
$pageDescription = 'Step-by-step instructions for installing the Ghost Laser admin app on iPhone, Android, or desktop.';
$pwaHead         = <<<'HTML'
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#09090b">
    <link rel="apple-touch-icon" href="/ghost-logo-250x250.png">
    <link rel="icon" type="image/png" sizes="250x250" href="/ghost-logo-250x250.png">
    <link rel="manifest" href="/admin-manifest.webmanifest">
HTML;
$extraHead       = <<<'HTML'
    <style>
        .btn-glow         { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover   { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .card-glow        { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
        .step-number      {
            width: 2rem; height: 2rem;
            border-radius: 9999px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 700; flex-shrink: 0;
        }
    </style>
HTML;
$headerRight     = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen hero-grid flex items-start justify-center px-4 py-24">
        <!-- Ambient glow -->
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none overflow-hidden">
            <div class="w-[600px] h-[600px] rounded-full bg-cyan-500/5 blur-3xl"></div>
        </div>

        <div class="relative w-full max-w-3xl">
            <!-- Page header -->
            <div class="flex flex-col gap-2 mb-10">
                <span class="inline-flex items-center gap-2 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-emerald-400 w-fit">
                    PWA Install Guide
                </span>
                <h1 class="text-3xl font-bold tracking-tight md:text-4xl">Install Admin App</h1>
                <p class="text-zinc-400 mt-1 text-sm leading-7">
                    Add the Ghost Laser admin portal to your home screen or desktop for fast, app-like access.
                    Follow the steps for your device below.
                </p>
            </div>

            <div class="space-y-6">

                <!-- ── iPhone / iOS ── -->
                <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-7 card-glow">
                    <div class="flex items-start gap-4">
                        <!-- Icon -->
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-zinc-700 bg-zinc-800/60 text-zinc-300 flex-shrink-0">
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>
                            </svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <span class="inline-flex items-center rounded-full border border-zinc-700 bg-zinc-800/60 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-400 mb-3">
                                iPhone &amp; iPad
                            </span>
                            <h2 class="text-xl font-semibold text-white">iOS — Safari</h2>
                            <p class="mt-1 text-sm text-zinc-400 leading-6">
                                iOS only supports PWA installation through Safari. Make sure you are using Safari before starting.
                            </p>
                            <ol class="mt-5 space-y-3">
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">1</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        Open this page in <strong class="text-white">Safari</strong> on your iPhone or iPad.
                                    </span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">2</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        Tap the <strong class="text-white">Share</strong> icon at the bottom of the screen
                                        <span class="text-zinc-500">(the box with an arrow pointing up)</span>.
                                    </span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">3</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        Scroll down in the share sheet and tap
                                        <strong class="text-white">"Add to Home Screen"</strong>.
                                    </span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">4</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        Confirm the name <strong class="text-white">Ghost Laser</strong> and tap
                                        <strong class="text-white">Add</strong> in the top-right corner.
                                    </span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">5</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        The app icon will appear on your home screen. Tap it to open the admin portal in full-screen mode.
                                    </span>
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>

                <!-- ── Android ── -->
                <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-7 card-glow">
                    <div class="flex items-start gap-4">
                        <!-- Icon -->
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-zinc-700 bg-zinc-800/60 text-zinc-300 flex-shrink-0">
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.523 15.341c-.42 0-.76-.34-.76-.76V8.663c0-.42.34-.76.76-.76s.76.34.76.76v5.918c0 .42-.34.76-.76.76zm-11.046 0c-.42 0-.76-.34-.76-.76V8.663c0-.42.34-.76.76-.76s.76.34.76.76v5.918c0 .42-.34.76-.76.76zM8.5 19.5a.5.5 0 01-.5-.5v-1.5h8V19a.5.5 0 01-.5.5h-.5v1.5a1 1 0 01-2 0V19.5h-1v1.5a1 1 0 01-2 0V19.5H8.5zm-.78-5.5h8.56A2.22 2.22 0 0018.5 11.78V9.5a6.5 6.5 0 00-13 0v2.28A2.22 2.22 0 007.72 14zm1.53-8.64l.9-1.56a.25.25 0 01.43.25l-.9 1.56a4.87 4.87 0 012.3-.58 4.87 4.87 0 012.3.58l-.9-1.56a.25.25 0 01.43-.25l.9 1.56A5.47 5.47 0 0118.5 9.5H5.5a5.47 5.47 0 014.75-4.14z"/>
                            </svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <span class="inline-flex items-center rounded-full border border-zinc-700 bg-zinc-800/60 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-400 mb-3">
                                Android
                            </span>
                            <h2 class="text-xl font-semibold text-white">Android — Chrome</h2>
                            <p class="mt-1 text-sm text-zinc-400 leading-6">
                                Android Chrome often shows an automatic install banner. If you don't see it, follow these steps.
                            </p>
                            <ol class="mt-5 space-y-3">
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">1</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        Open this page in <strong class="text-white">Google Chrome</strong> on your Android device.
                                    </span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">2</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        Tap the <strong class="text-white">three-dot menu</strong> (⋮) in the top-right corner of Chrome.
                                    </span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">3</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        Tap <strong class="text-white">"Add to Home screen"</strong> or
                                        <strong class="text-white">"Install app"</strong>
                                        <span class="text-zinc-500">(wording may vary by Android version)</span>.
                                    </span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">4</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        Tap <strong class="text-white">Install</strong> on the confirmation prompt.
                                    </span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">5</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        The Ghost Laser icon will appear in your app drawer and home screen.
                                    </span>
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>

                <!-- ── Desktop PC ── -->
                <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-7 card-glow">
                    <div class="flex items-start gap-4">
                        <!-- Icon -->
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-zinc-700 bg-zinc-800/60 text-zinc-300 flex-shrink-0">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <span class="inline-flex items-center rounded-full border border-zinc-700 bg-zinc-800/60 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-400 mb-3">
                                Desktop PC &amp; Mac
                            </span>
                            <h2 class="text-xl font-semibold text-white">Desktop — Chrome / Edge</h2>
                            <p class="mt-1 text-sm text-zinc-400 leading-6">
                                On desktop, Chrome and Edge support one-click installation. Use the button below when it becomes
                                available, or follow the manual steps for your browser.
                            </p>

                            <!-- PC install button (shown automatically when prompt is available) -->
                            <div class="mt-5">
                                <button
                                    id="pwa-install-btn"
                                    type="button"
                                    hidden
                                    class="inline-flex items-center gap-2 rounded-md bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-sm px-5 py-2.5 transition-all"
                                >
                                    <svg class="h-4 w-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/>
                                    </svg>
                                    Install on PC
                                </button>
                                <p id="pwa-install-unavailable" class="text-sm text-zinc-500 italic">
                                    The install button will appear here automatically when your browser is ready.
                                    If it doesn't appear, follow the manual steps below.
                                </p>
                            </div>

                            <ol class="mt-6 space-y-3">
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">1</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        Open this page in <strong class="text-white">Google Chrome</strong> or
                                        <strong class="text-white">Microsoft Edge</strong>.
                                    </span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">2</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        Look for the <strong class="text-white">install icon</strong>
                                        <span class="text-zinc-500">(a monitor with a down-arrow)</span>
                                        in the browser address bar on the right side.
                                    </span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">3</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        Click it and select <strong class="text-white">Install</strong> on the confirmation dialog.
                                    </span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="step-number bg-zinc-800 border border-zinc-700 text-zinc-300">4</span>
                                    <span class="text-sm text-zinc-300 leading-6 pt-0.5">
                                        The app will open in its own window and a shortcut will be added to your desktop.
                                    </span>
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>

            </div><!-- /space-y-6 -->
        </div>
    </main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
<script>
    (() => {
        // Register the admin-only service worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/admin-sw.js', { scope: '/' })
                .catch((err) => console.warn('Admin SW registration failed:', err));
        }

        // Handle the PWA install prompt (desktop Chrome / Edge)
        let deferredPrompt = null;
        const installBtn         = document.getElementById('pwa-install-btn');
        const installUnavailable = document.getElementById('pwa-install-unavailable');

        const showInstallBtn = () => {
            if (installBtn)         installBtn.hidden         = false;
            if (installUnavailable) installUnavailable.hidden = true;
        };

        const hideInstallBtn = () => {
            if (installBtn)         installBtn.hidden         = true;
            if (installUnavailable) installUnavailable.hidden = false;
        };

        console.log('[PWA] Install page loaded. Waiting for beforeinstallprompt event...');

        window.addEventListener('beforeinstallprompt', (e) => {
            console.log('[PWA] beforeinstallprompt event fired — install prompt is available.');
            e.preventDefault();
            deferredPrompt = e;
            showInstallBtn();
            console.log('[PWA] Install button is now visible.');
        });

        if (installBtn) {
            installBtn.addEventListener('click', async () => {
                console.log('[PWA] Install button clicked.');

                if (!deferredPrompt) {
                    console.warn('[PWA] No deferred prompt available — cannot trigger install dialog.');
                    return;
                }

                const promptEvent = deferredPrompt;
                console.log('[PWA] Calling deferredPrompt.prompt()...');

                try {
                    await promptEvent.prompt();
                    const { outcome } = await promptEvent.userChoice;
                    console.log('[PWA] User choice outcome:', outcome);
                    if (outcome === 'accepted') {
                        console.log('[PWA] User accepted the install prompt.');
                        hideInstallBtn();
                    } else {
                        console.log('[PWA] User dismissed the install prompt.');
                    }
                } catch (error) {
                    console.error('[PWA] Failed to show install prompt:', error);
                } finally {
                    if (deferredPrompt === promptEvent) {
                        deferredPrompt = null;
                    }
                }
            });
        } else {
            console.warn('[PWA] Install button element #pwa-install-btn not found in DOM.');
        }

        // Hide the button once the app is installed
        window.addEventListener('appinstalled', () => {
            console.log('[PWA] App successfully installed.');
            hideInstallBtn();
            deferredPrompt = null;
        });
    })();
</script>
