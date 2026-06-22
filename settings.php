<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/scheduling_settings.php';

$settings = getSchedulingSettings($pdo);
$workDayOptions = getSchedulingWorkDayOptions();
$successMessage = null;
$errorMessage = null;
$formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedSettings = [
        'shop_address' => trim((string) ($_POST['shop_address'] ?? '')),
        'shop_latitude' => trim((string) ($_POST['shop_latitude'] ?? '')),
        'shop_longitude' => trim((string) ($_POST['shop_longitude'] ?? '')),
        'business_start_time' => trim((string) ($_POST['business_start_time'] ?? '')),
        'business_end_time' => trim((string) ($_POST['business_end_time'] ?? '')),
        'default_buffer_between_jobs_minutes' => trim((string) ($_POST['default_buffer_between_jobs_minutes'] ?? '')),
        'average_job_duration_minutes' => trim((string) ($_POST['average_job_duration_minutes'] ?? '')),
        'maximum_jobs_per_technician_per_day' => trim((string) ($_POST['maximum_jobs_per_technician_per_day'] ?? '')),
        'default_time_window_size_hours' => trim((string) ($_POST['default_time_window_size_hours'] ?? '')),
        'work_days' => trim((string) ($_POST['work_days'] ?? '')),
    ];

    if ($submittedSettings['shop_address'] === '') {
        $formErrors[] = 'Shop address is required.';
    }
    if (!is_numeric($submittedSettings['shop_latitude'])) {
        $formErrors[] = 'Shop latitude must be numeric.';
    }
    if (!is_numeric($submittedSettings['shop_longitude'])) {
        $formErrors[] = 'Shop longitude must be numeric.';
    }

    $startTime = DateTimeImmutable::createFromFormat('H:i', $submittedSettings['business_start_time']);
    $endTime = DateTimeImmutable::createFromFormat('H:i', $submittedSettings['business_end_time']);
    if ($startTime === false || $startTime->format('H:i') !== $submittedSettings['business_start_time']) {
        $formErrors[] = 'Business start time must use HH:MM format.';
    }
    if ($endTime === false || $endTime->format('H:i') !== $submittedSettings['business_end_time']) {
        $formErrors[] = 'Business end time must use HH:MM format.';
    }
    if ($startTime !== false && $endTime !== false && $endTime <= $startTime) {
        $formErrors[] = 'Business end time must be after the start time.';
    }

    $integerFields = [
        'default_buffer_between_jobs_minutes' => 'Default buffer between jobs',
        'average_job_duration_minutes' => 'Average job duration',
        'maximum_jobs_per_technician_per_day' => 'Maximum jobs per technician per day',
        'default_time_window_size_hours' => 'Default time window size',
    ];
    foreach ($integerFields as $field => $label) {
        if (filter_var($submittedSettings[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $formErrors[] = $label . ' must be a whole number greater than 0.';
        }
    }
    if (!array_key_exists($submittedSettings['work_days'], $workDayOptions)) {
        $formErrors[] = 'Select a valid work day schedule.';
    }

    if ($formErrors === []) {
        $settings = normalizeSchedulingSettings($submittedSettings);

        try {
            updateSchedulingSettings($pdo, $settings);
            $settings = getSchedulingSettings($pdo);
            $successMessage = 'Scheduling settings updated successfully.';
        } catch (Throwable $e) {
            $errorMessage = 'Unable to save settings right now.';
        }
    } else {
        $settings = array_merge($settings, $submittedSettings);
        $errorMessage = 'Please correct the highlighted settings and try again.';
    }
}

$calculatedCapacity = calculateTechnicianDailyCapacity($settings);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Settings | Ghost Laser</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="/favicon.ico">
    <meta name="description" content="Ghost Laser admin settings.">
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
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
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
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                    <a href="technician/schedule.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Scheduling</a>
                </div>
            </div>
        </div>
    </header>

    <main class="min-h-screen hero-grid pt-24 pb-16 px-4">
        <div class="max-w-6xl mx-auto space-y-8">
            <div class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/20 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-[0.2em] text-cyan-400">
                            Admin Settings
                        </div>
                        <h1 class="mt-4 text-3xl font-bold tracking-tight">Scheduling Configuration</h1>
                        <p class="mt-2 text-zinc-400">Update the operating values used by the admin scheduling tools and clustering workflow.</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:min-w-[360px]">
                        <div class="rounded-2xl border border-zinc-700 bg-zinc-950/80 px-4 py-3">
                            <div class="text-xs uppercase tracking-wide text-zinc-500">Calculated Daily Capacity</div>
                            <div class="mt-2 text-2xl font-semibold text-white"><?= $calculatedCapacity ?></div>
                        </div>
                        <div class="rounded-2xl border border-zinc-700 bg-zinc-950/80 px-4 py-3">
                            <div class="text-xs uppercase tracking-wide text-zinc-500">Work Days</div>
                            <div class="mt-2 text-sm font-semibold text-white"><?= htmlspecialchars(getSchedulingWorkDayLabel((string) $settings['work_days'])) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($successMessage !== null): ?>
                <div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                    <?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== null): ?>
                <div class="rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    <div><?= htmlspecialchars($errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php if ($formErrors !== []): ?>
                        <ul class="mt-2 list-disc pl-5 space-y-1">
                            <?php foreach ($formErrors as $formError): ?>
                                <li><?= htmlspecialchars($formError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="grid gap-8 lg:grid-cols-[1.1fr_0.9fr]">
                <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-6">
                    <div>
                        <h2 class="text-xl font-semibold text-white">Shop Location</h2>
                        <p class="mt-2 text-sm text-zinc-400">Set the main service hub used by dispatch and future route planning.</p>
                    </div>

                    <div class="space-y-4">
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-200">Shop address</span>
                            <input type="text" name="shop_address" value="<?= htmlspecialchars((string) $settings['shop_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                        </label>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium text-zinc-200">Latitude</span>
                                <input type="number" step="0.000001" name="shop_latitude" value="<?= htmlspecialchars((string) $settings['shop_latitude'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-zinc-200">Longitude</span>
                                <input type="number" step="0.000001" name="shop_longitude" value="<?= htmlspecialchars((string) $settings['shop_longitude'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                            </label>
                        </div>
                    </div>

                    <div>
                        <h2 class="text-xl font-semibold text-white">Business Hours</h2>
                        <p class="mt-2 text-sm text-zinc-400">These hours drive daily capacity calculations for scheduling routes.</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-200">Start time</span>
                            <input type="time" name="business_start_time" value="<?= htmlspecialchars((string) $settings['business_start_time'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-200">End time</span>
                            <input type="time" name="business_end_time" value="<?= htmlspecialchars((string) $settings['business_end_time'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                        </label>
                    </div>
                </section>

                <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-6">
                    <div>
                        <h2 class="text-xl font-semibold text-white">Scheduling Defaults</h2>
                        <p class="mt-2 text-sm text-zinc-400">These values are used by the current admin scheduling workflow.</p>
                    </div>

                    <div class="grid gap-4">
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-200">Default buffer between jobs (minutes)</span>
                            <input type="number" min="1" name="default_buffer_between_jobs_minutes" value="<?= htmlspecialchars((string) $settings['default_buffer_between_jobs_minutes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-200">Average job duration (minutes)</span>
                            <input type="number" min="1" name="average_job_duration_minutes" value="<?= htmlspecialchars((string) $settings['average_job_duration_minutes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-200">Maximum jobs per technician per day</span>
                            <input type="number" min="1" name="maximum_jobs_per_technician_per_day" value="<?= htmlspecialchars((string) $settings['maximum_jobs_per_technician_per_day'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-200">Default time window size (hours)</span>
                            <input type="number" min="1" name="default_time_window_size_hours" value="<?= htmlspecialchars((string) $settings['default_time_window_size_hours'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-200">Work days</span>
                            <select name="work_days" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                                <?php foreach ($workDayOptions as $value => $option): ?>
                                    <option value="<?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= (string) $settings['work_days'] === $value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($option['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="rounded-2xl border border-cyan-500/20 bg-cyan-500/10 p-4 text-sm text-cyan-100">
                        Current calculated technician-day capacity: <span class="font-semibold"><?= $calculatedCapacity ?></span>
                    </div>

                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-cyan-500 px-5 py-3 text-sm font-semibold text-zinc-950 transition hover:bg-cyan-400">
                        Save Settings
                    </button>
                </section>
            </form>
        </div>
    </main>
</body>
</html>
