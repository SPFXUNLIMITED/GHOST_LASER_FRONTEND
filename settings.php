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
        'home_address' => trim((string) ($_POST['home_address'] ?? '')),
        'shop_address' => trim((string) ($_POST['shop_address'] ?? '')),
        'shop_latitude' => trim((string) ($_POST['shop_latitude'] ?? '')),
        'shop_longitude' => trim((string) ($_POST['shop_longitude'] ?? '')),
        'business_start_time' => trim((string) ($_POST['business_start_time'] ?? '')),
        'business_end_time' => trim((string) ($_POST['business_end_time'] ?? '')),
        'default_buffer_between_jobs_minutes' => trim((string) ($_POST['default_buffer_between_jobs_minutes'] ?? '')),
        'average_job_duration_minutes' => trim((string) ($_POST['average_job_duration_minutes'] ?? '')),
        'maximum_jobs_per_technician_per_day' => trim((string) ($_POST['maximum_jobs_per_technician_per_day'] ?? '')),
        'default_time_window_size_hours' => trim((string) ($_POST['default_time_window_size_hours'] ?? '')),
        'max_booking_advance_days' => trim((string) ($_POST['max_booking_advance_days'] ?? '')),
        'simmer_days_threshold' => trim((string) ($_POST['simmer_days_threshold'] ?? '')),
        'work_days' => trim((string) ($_POST['work_days'] ?? '')),
        'initial_appointment_confirmation_subject' => trim((string) ($_POST['initial_appointment_confirmation_subject'] ?? '')),
        'initial_appointment_confirmation_body' => trim((string) ($_POST['initial_appointment_confirmation_body'] ?? '')),
        'day_before_reminder_subject' => trim((string) ($_POST['day_before_reminder_subject'] ?? '')),
        'day_before_reminder_body' => trim((string) ($_POST['day_before_reminder_body'] ?? '')),
        'one_hour_arrival_notification_subject' => trim((string) ($_POST['one_hour_arrival_notification_subject'] ?? '')),
        'one_hour_arrival_notification_body' => trim((string) ($_POST['one_hour_arrival_notification_body'] ?? '')),
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
        'max_booking_advance_days' => 'Max booking advance days',
        'simmer_days_threshold' => 'Simmer days threshold',
    ];
    foreach ($integerFields as $field => $label) {
        if (filter_var($submittedSettings[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $formErrors[] = $label . ' must be a whole number greater than 0.';
        }
    }
    if (!array_key_exists($submittedSettings['work_days'], $workDayOptions)) {
        $formErrors[] = 'Select a valid work day schedule.';
    }

    $templateLabels = [
        'initial_appointment_confirmation_subject' => 'Initial appointment confirmation subject',
        'initial_appointment_confirmation_body' => 'Initial appointment confirmation body',
        'day_before_reminder_subject' => 'Day-before reminder subject',
        'day_before_reminder_body' => 'Day-before reminder body',
        'one_hour_arrival_notification_subject' => 'One-hour arrival notification subject',
        'one_hour_arrival_notification_body' => 'One-hour arrival notification body',
    ];
    foreach ($templateLabels as $field => $label) {
        if ($submittedSettings[$field] === '') {
            $formErrors[] = $label . ' is required.';
        }
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
<?php
$pageTitle       = 'Admin Settings | Ghost Laser';
$pageDescription = 'Ghost Laser admin settings.';
$extraHead       = <<<'HTML'
    <style>
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
    </style>
HTML;
$headerRight     = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                    <a href="technician/schedule.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Scheduling</a>
                    <a href="vehicle-settings.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Vehicle Settings</a>
                </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

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

                    <hr class="border-zinc-700/60">

                    <div>
                        <h2 class="text-xl font-semibold text-white">Home Location</h2>
                        <p class="mt-2 text-sm text-zinc-400">Set your home address as an optional return destination on the technician dashboard.</p>
                    </div>

                    <div class="space-y-3">
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-200">Home address</span>
                            <input type="text" name="home_address" value="<?= htmlspecialchars((string) $settings['home_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" placeholder="e.g. 123 Main St, Yorba Linda, CA">
                        </label>
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

                        <hr class="border-zinc-700/60">

                        <div>
                            <h3 class="text-base font-semibold text-white">Job Status Badge Thresholds</h3>
                            <p class="mt-1 text-xs text-zinc-400">Controls the informational status badges shown on jobs in the scheduling view.</p>
                        </div>

                        <label class="block">
                            <span class="text-sm font-medium text-zinc-200">Max booking advance days</span>
                            <p class="mt-1 text-xs text-zinc-500">Jobs due within this many days show <em>Due Today</em> or <em>Due Tomorrow</em> badges.</p>
                            <input type="number" min="1" name="max_booking_advance_days" value="<?= htmlspecialchars((string) $settings['max_booking_advance_days'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-200">Simmer days threshold</span>
                            <p class="mt-1 text-xs text-zinc-500">Jobs due within this many days (but beyond the advance window) show a <em>Simmering</em> badge. Jobs further out show <em>Ready to Cluster</em>.</p>
                            <input type="number" min="1" name="simmer_days_threshold" value="<?= htmlspecialchars((string) $settings['simmer_days_threshold'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                        </label>

                        <hr class="border-zinc-700/60">

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
                        Current technician-day capacity: <span class="font-semibold"><?= $calculatedCapacity ?></span>
                    </div>

                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-cyan-500 px-5 py-3 text-sm font-semibold text-zinc-950 transition hover:bg-cyan-400">
                        Save Settings
                    </button>
                </section>

                <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-6 lg:col-span-2">
                    <div>
                        <h2 class="text-xl font-semibold text-white">Email Templates</h2>
                        <p class="mt-2 text-sm text-zinc-400">Manage reusable subject and message templates for future email and SMS notifications.</p>
                    </div>

                    <div class="grid gap-6">
                        <div class="rounded-xl border border-zinc-800 bg-zinc-950/50 p-4 space-y-4">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-cyan-300">Initial Appointment Confirmation</h3>
                            <label class="block">
                                <span class="text-sm font-medium text-zinc-200">Subject</span>
                                <input type="text" name="initial_appointment_confirmation_subject" value="<?= htmlspecialchars((string) $settings['initial_appointment_confirmation_subject'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-zinc-200">Body</span>
                                <textarea name="initial_appointment_confirmation_body" rows="5" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required><?= htmlspecialchars((string) $settings['initial_appointment_confirmation_body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                            </label>
                        </div>

                        <div class="rounded-xl border border-zinc-800 bg-zinc-950/50 p-4 space-y-4">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-cyan-300">Day-Before Reminder</h3>
                            <label class="block">
                                <span class="text-sm font-medium text-zinc-200">Subject</span>
                                <input type="text" name="day_before_reminder_subject" value="<?= htmlspecialchars((string) $settings['day_before_reminder_subject'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-zinc-200">Body</span>
                                <textarea name="day_before_reminder_body" rows="5" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required><?= htmlspecialchars((string) $settings['day_before_reminder_body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                            </label>
                        </div>

                        <div class="rounded-xl border border-zinc-800 bg-zinc-950/50 p-4 space-y-4">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-cyan-300">One-Hour Arrival Notification</h3>
                            <label class="block">
                                <span class="text-sm font-medium text-zinc-200">Subject</span>
                                <input type="text" name="one_hour_arrival_notification_subject" value="<?= htmlspecialchars((string) $settings['one_hour_arrival_notification_subject'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-zinc-200">Body</span>
                                <textarea name="one_hour_arrival_notification_body" rows="5" class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm text-white focus:border-cyan-400 focus:outline-none" required><?= htmlspecialchars((string) $settings['one_hour_arrival_notification_body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                            </label>
                        </div>
                    </div>
                </section>
            </form>
        </div>
    </main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
