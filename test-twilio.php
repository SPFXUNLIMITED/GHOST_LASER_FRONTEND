<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

// ── Load Twilio credentials from environment ────────────────────────────────
require_once __DIR__ . '/bootstrap_env.php';
$twilioSid   = trim((string) (getenv('TWILIO_ACCOUNT_SID') ?: ''));
$twilioToken = trim((string) (getenv('TWILIO_AUTH_TOKEN') ?: ''));
$twilioFrom  = trim((string) (getenv('TWILIO_FROM_NUMBER') ?: ''));

// ── Handle form submission ────────────────────────────────────────────────────
$result = null; // ['success' => bool, 'message' => string]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $toRaw = preg_replace('/\D/', '', trim((string) ($_POST['to_phone'] ?? '')));

    if (strlen($toRaw) === 10) {
        $toE164 = '+1' . $toRaw;
    } elseif (strlen($toRaw) === 11 && $toRaw[0] === '1') {
        $toE164 = '+' . $toRaw;
    } else {
        $toE164 = '';
    }

    if ($toE164 === '') {
        $result = ['success' => false, 'message' => 'Please enter a valid 10-digit US phone number.'];
    } elseif (empty($twilioSid) || empty($twilioToken) || empty($twilioFrom)) {
        $result = ['success' => false, 'message' => 'Twilio credentials are not configured. Add TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and TWILIO_FROM_NUMBER to your .env file.'];
    } else {
        $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . $twilioSid . '/Messages.json';
        $body = 'This is a test message from Ghost Laser. If you received this, Twilio is working correctly!';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $twilioSid . ':' . $twilioToken,
            CURLOPT_POSTFIELDS     => http_build_query([
                'To'   => $toE164,
                'From' => $twilioFrom,
                'Body' => $body,
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            $result = ['success' => false, 'message' => 'cURL error: ' . htmlspecialchars($curlError)];
        } else {
            $data = json_decode($response, true);
            if ($httpCode === 201 && isset($data['sid'])) {
                $result = ['success' => true, 'message' => 'SMS sent successfully! Message SID: ' . htmlspecialchars($data['sid'])];
            } else {
                $errorMsg = isset($data['message']) ? $data['message'] : ('Unexpected response (HTTP ' . $httpCode . ').');
                $result = ['success' => false, 'message' => 'Twilio error: ' . htmlspecialchars($errorMsg)];
            }
        }
    }
}

// ── Page render ───────────────────────────────────────────────────────────────
$credsMissing = empty($twilioSid) || empty($twilioToken) || empty($twilioFrom);

$pageTitle       = 'Test Twilio SMS | Ghost Laser';
$pageDescription = 'Send a test SMS via Twilio to verify the integration.';
$pwaHead         = <<<'HTML'
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#09090b">
    <link rel="apple-touch-icon" href="/ghost-logo-250x250.png">
    <link rel="icon" type="image/png" sizes="250x250" href="/ghost-logo-250x250.png">
    <link rel="manifest" href="/manifest.json">
HTML;
$headerRight = <<<'HTML'
            <div class="flex items-center gap-3">
                <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Dashboard</a>
            </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen hero-grid flex items-center justify-center px-4 py-24">
        <!-- Ambient glow -->
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none overflow-hidden">
            <div class="w-[500px] h-[500px] rounded-full bg-cyan-500/5 blur-3xl"></div>
        </div>

        <div class="relative w-full max-w-md">
            <!-- Header -->
            <div class="flex flex-col gap-2 mb-8">
                <span class="inline-flex items-center gap-2 rounded-full border border-cyan-500/30 bg-cyan-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-cyan-400 w-fit">
                    Twilio Integration
                </span>
                <h1 class="text-3xl font-bold tracking-tight">Test SMS</h1>
                <p class="text-zinc-400 text-sm leading-relaxed">
                    Send a test message to verify your Twilio account is working.
                </p>
            </div>

            <?php if ($credsMissing): ?>
            <!-- Credentials warning -->
            <div class="mb-6 flex items-start gap-3 rounded-lg border border-yellow-500/30 bg-yellow-500/10 px-4 py-3.5">
                <svg class="h-5 w-5 flex-shrink-0 text-yellow-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <div>
                    <p class="text-sm font-semibold text-yellow-300">Credentials not configured</p>
                    <p class="text-xs text-yellow-400/80 mt-0.5 leading-relaxed">
                        Add the following lines to your <code class="font-mono bg-yellow-500/10 px-1 rounded">.env</code> file:
                    </p>
                    <pre class="mt-2 text-xs font-mono text-yellow-300/90 bg-black/30 rounded px-3 py-2 leading-relaxed">TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_FROM_NUMBER=+1xxxxxxxxxx</pre>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($result !== null): ?>
            <!-- Result banner -->
            <?php if ($result['success']): ?>
            <div class="mb-6 flex items-start gap-3 rounded-lg border border-green-500/40 bg-green-500/10 px-4 py-3.5">
                <svg class="h-5 w-5 flex-shrink-0 text-green-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm text-green-300"><?= $result['message'] ?></p>
            </div>
            <?php else: ?>
            <div class="mb-6 flex items-start gap-3 rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3.5">
                <svg class="h-5 w-5 flex-shrink-0 text-red-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm text-red-300"><?= $result['message'] ?></p>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Card -->
            <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-7" style="box-shadow: 0 0 0 1px rgba(6,182,212,0.08), 0 0 60px rgba(6,182,212,0.04);">
                <form method="POST" action="">
                    <!-- Phone Number -->
                    <div class="mb-6">
                        <label for="to_phone" class="block text-sm font-semibold text-zinc-300 mb-2">
                            Send Test SMS To
                        </label>
                        <input
                            type="tel"
                            id="to_phone"
                            name="to_phone"
                            placeholder="(949) 555-0123"
                            autocomplete="tel"
                            value="<?= htmlspecialchars((string) ($_POST['to_phone'] ?? '')) ?>"
                            class="w-full rounded-md border border-zinc-700 bg-zinc-800/60 px-4 py-3 text-white placeholder-zinc-500 text-sm focus:outline-none focus:border-cyan-500/60 focus:ring-2 focus:ring-cyan-500/20 transition-all"
                            required
                        >
                        <p class="mt-1.5 text-xs text-zinc-500">US numbers only &mdash; enter 10 digits, any format.</p>
                    </div>

                    <!-- Message preview -->
                    <div class="mb-8 rounded-md border border-zinc-700/60 bg-zinc-800/40 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 mb-1.5">Message preview</p>
                        <p class="text-sm text-zinc-300 leading-relaxed">
                            This is a test message from Ghost Laser. If you received this, Twilio is working correctly!
                        </p>
                    </div>

                    <!-- Send button -->
                    <button
                        type="submit"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-cyan-600 hover:bg-cyan-500 text-white font-bold text-sm px-4 py-4 transition-all"
                        style="box-shadow: 0 0 20px rgba(6,182,212,0.35);"
                    >
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3v-3z"/>
                        </svg>
                        Send Test SMS
                    </button>
                </form>
            </div>
        </div>
    </main>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
