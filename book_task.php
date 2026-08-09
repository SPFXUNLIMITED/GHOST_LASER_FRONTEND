<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';

function loadGoogleMapsApiKey(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $envKey = getenv('GOOGLE_MAPS_API_KEY');
    if ($envKey !== false && trim($envKey) !== '') {
        $key = trim($envKey);
        return $key;
    }

    $dotenvPath = __DIR__ . '/api/.env';
    if (is_file($dotenvPath) && is_readable($dotenvPath)) {
        $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!str_starts_with($line, 'GOOGLE_MAPS_API_KEY=')) {
                    continue;
                }
                $val = trim((string) substr($line, strlen('GOOGLE_MAPS_API_KEY=')));
                if (strlen($val) >= 2) {
                    if ($val[0] === '"' && $val[-1] === '"') {
                        $val = substr($val, 1, -1);
                    } elseif ($val[0] === "'" && $val[-1] === "'") {
                        $val = substr($val, 1, -1);
                    }
                }
                $key = $val;
                return $key;
            }
        }
    }

    $key = '';
    return $key;
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalizeUsPhone($value): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    if (strlen($digits) === 11 && strpos($digits, '1') === 0) {
        $digits = substr($digits, 1);
    }
    return strlen($digits) === 10 ? $digits : null;
}

function formatUsPhoneDisplay($value): string
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    if (strlen($digits) === 11 && strpos($digits, '1') === 0) {
        $digits = substr($digits, 1);
    }
    $digits = substr($digits, 0, 10);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) < 4) {
        return '(' . $digits;
    }
    if (strlen($digits) < 7) {
        return sprintf('(%s) %s', substr($digits, 0, 3), substr($digits, 3));
    }
    return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
}


$googleMapsApiKey = loadGoogleMapsApiKey();

$pageTitle = 'Task Booking | Ghost Laser';
$pageDescription = 'Internal task form for non-customer jobs.';
$extraHead = <<<'HTML'
<style>
    .glow-cyan { text-shadow: 0 0 30px rgba(6,182,212,0.6), 0 0 60px rgba(6,182,212,0.3); }
    .glow-box { box-shadow: 0 0 0 1px rgba(6,182,212,0.2), 0 0 40px rgba(6,182,212,0.05); }
    .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
    .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
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
    .input-base.input-invalid { border-color: rgba(248,113,113,.95) !important; box-shadow: 0 0 0 1px rgba(248,113,113,.35) !important; }
    .field-error { margin-top: .5rem; color: rgb(248 113 113); font-size: .75rem; line-height: 1rem; }
</style>
HTML;

$headerRight = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                </div>
HTML;

require_once __DIR__ . '/templates/header.php';
?>

<section class="pt-32 pb-12 lg:pb-16 bg-zinc-950">
    <div class="max-w-3xl mx-auto px-6 lg:px-8 text-center">
        <div class="inline-flex items-center gap-2 bg-zinc-900 border border-amber-500/30 rounded-full px-4 py-1.5 mb-8">
            <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
            <span class="text-xs text-amber-400 font-medium tracking-wider uppercase">Internal Use Only</span>
        </div>
        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight mb-5">
            Internal Task Booking &mdash; <span class="text-cyan-400 glow-cyan">Dispatch Task</span>
        </h1>
        <p class="text-zinc-400 text-lg leading-relaxed max-w-xl mx-auto">
            Use this form for non-customer tasks like parts pickups, machine pickups, deliveries, and other dispatch work.
        </p>
    </div>
</section>

<section class="pb-24 lg:pb-32 bg-zinc-950">
    <div class="max-w-3xl mx-auto px-6">
        <div id="task-success" class="hidden mb-6 rounded-xl border border-emerald-500/30 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200"></div>
        <div id="task-error" class="hidden mb-6 rounded-xl border border-red-500/30 bg-red-950/40 px-4 py-3 text-sm text-red-200"></div>

        <div class="mb-6 flex justify-end">
            <a href="admin/scheduling-dashboard.php" class="inline-flex items-center gap-2 rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-200 hover:bg-zinc-700 hover:text-white transition-colors">
                Go to Scheduler &rarr;
            </a>
        </div>

        <form id="task-booking-form" class="space-y-6 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 sm:p-8 glow-box" novalidate>
            <input type="hidden" id="latitude" name="latitude" value="">
            <input type="hidden" id="longitude" name="longitude" value="">

            <div>
                <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Destination Information</p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2"><input class="input-base" id="company_name" name="company_name" placeholder="Company Name *" required></div>
                    <div><input class="input-base" id="first_name" name="first_name" placeholder="First Name"></div>
                    <div><input class="input-base" id="last_name" name="last_name" placeholder="Last Name"></div>
                    <div>
                        <input class="input-base" type="tel" inputmode="tel" id="phone" name="phone" placeholder="Phone Number" aria-describedby="phone-error" aria-invalid="false">
                        <p id="phone-error" class="field-error hidden"></p>
                    </div>
                    <div><input class="input-base" type="email" id="email" name="email" placeholder="Email Address"></div>
                </div>
            </div>

            <div class="border-t border-zinc-800"></div>

            <div>
                <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Destination Address</p>
                <div class="space-y-5">
                    <input class="input-base" id="address" name="address" placeholder="Address *" required>
                    <div class="grid gap-5 sm:grid-cols-3">
                        <input class="input-base" id="city" name="city" placeholder="City *" required>
                        <input class="input-base uppercase" id="state" name="state" maxlength="2" placeholder="State *" required>
                        <input class="input-base" id="zip" name="zip" placeholder="ZIP Code *" required>
                    </div>
                </div>
            </div>

            <div class="border-t border-zinc-800"></div>

            <div>
                <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-cyan-400">Task Details</p>
                <div class="space-y-5">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-zinc-400" for="problem">Task Purpose / Destination Notes *</label>
                        <textarea class="input-base resize-none" id="problem" name="problem" rows="4" required placeholder="Example: Pick up machine from warehouse dock and deliver to customer site."></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-zinc-400" for="duration_minutes">Duration (minutes) *</label>
                        <input class="input-base" type="number" id="duration_minutes" name="duration_minutes" min="5" step="5" value="15" required placeholder="e.g. 60">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-zinc-400" for="priority">Priority</label>
                        <select id="priority" name="priority" class="input-base">
                            <option value="standard">Standard</option>
                            <option value="vip">VIP</option>
                            <option value="emergency">Emergency</option>
                        </select>
                    </div>
                </div>
            </div>

            <div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="border-t border-zinc-800"></div>

            <button id="task-submit-btn" type="submit" class="w-full rounded-lg bg-cyan-500 py-3.5 text-sm font-bold text-zinc-950 hover:bg-cyan-400 btn-glow transition-all flex items-center justify-center gap-2">
                <span id="task-submit-label">Submit Task</span>
            </button>
        </form>
    </div>
</section>

<script>
    const googleMapsApiKey = <?= json_encode($googleMapsApiKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const formatUsPhoneDisplay = (value) => {
        let digits = String(value || '').replace(/\D/g, '');
        if (digits.startsWith('1') && digits.length > 10) digits = digits.slice(1);
        digits = digits.slice(0, 10);
        if (!digits) return '';
        if (digits.length < 4) return `(${digits}`;
        if (digits.length < 7) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
        return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
    };

    const normalizeUsPhone = (value) => {
        let digits = String(value || '').replace(/\D/g, '');
        if (digits.length === 11 && digits.startsWith('1')) digits = digits.slice(1);
        return digits.length === 10 ? digits : null;
    };

    const geocodeAddress = async () => {
        const addrEl = document.getElementById('address');
        const cityEl = document.getElementById('city');
        const stateEl = document.getElementById('state');
        const zipEl = document.getElementById('zip');
        const latEl = document.getElementById('latitude');
        const lngEl = document.getElementById('longitude');

        if (!latEl || !lngEl || !googleMapsApiKey) return;

        const parts = [
            (addrEl?.value || '').trim(),
            (cityEl?.value || '').trim(),
            (stateEl?.value || '').trim(),
            (zipEl?.value || '').trim(),
        ].filter(Boolean);

        if (parts.length < 2) return;

        try {
            const url = 'https://maps.googleapis.com/maps/api/geocode/json?' + new URLSearchParams({
                address: parts.join(', '),
                key: googleMapsApiKey
            });
            const resp = await fetch(url);
            const data = await resp.json();
            if (data.status === 'OK' && data.results?.[0]?.geometry?.location) {
                latEl.value = data.results[0].geometry.location.lat;
                lngEl.value = data.results[0].geometry.location.lng;
            } else {
                latEl.value = '';
                lngEl.value = '';
            }
        } catch (_) {
            latEl.value = '';
            lngEl.value = '';
        }
    };

    ['address', 'city', 'state', 'zip'].forEach(id => {
        document.getElementById(id)?.addEventListener('blur', geocodeAddress);
    });

    (() => {
        const phoneInput = document.getElementById('phone');
        const phoneErrorEl = document.getElementById('phone-error');
        if (!phoneInput || !phoneErrorEl) return;

        const syncPhoneValidationState = () => {
            const digits = normalizeUsPhone(phoneInput.value);
            const hasValue = phoneInput.value.trim() !== '';
            const isValid = !hasValue || digits !== null;
            phoneInput.classList.toggle('input-invalid', !isValid);
            phoneInput.setAttribute('aria-invalid', isValid ? 'false' : 'true');
            phoneErrorEl.textContent = isValid ? '' : 'Please enter a valid 10-digit US phone number.';
            phoneErrorEl.classList.toggle('hidden', isValid);
            return isValid;
        };

        phoneInput.addEventListener('input', () => {
            const cursorAtEnd = phoneInput.selectionStart === phoneInput.value.length;
            phoneInput.value = formatUsPhoneDisplay(phoneInput.value);
            if (cursorAtEnd) phoneInput.setSelectionRange(phoneInput.value.length, phoneInput.value.length);
            syncPhoneValidationState();
        });
        phoneInput.addEventListener('blur', syncPhoneValidationState);
        phoneInput.value = formatUsPhoneDisplay(phoneInput.value);
        syncPhoneValidationState();
    })();

    (() => {
        const form = document.getElementById('task-booking-form');
        const submitBtn = document.getElementById('task-submit-btn');
        const submitLabel = document.getElementById('task-submit-label');
        const errorBox = document.getElementById('task-error');
        const successBox = document.getElementById('task-success');
        if (!form || !submitBtn || !submitLabel || !errorBox || !successBox) return;

        const showError = (message) => {
            successBox.classList.add('hidden');
            errorBox.textContent = message;
            errorBox.classList.remove('hidden');
            errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        };

        const showSuccess = (message) => {
            errorBox.classList.add('hidden');
            successBox.textContent = message;
            successBox.classList.remove('hidden');
            successBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        };

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            errorBox.classList.add('hidden');
            successBox.classList.add('hidden');

            if (!form.reportValidity()) return;

            // Phone is optional for tasks; validate only when a value is entered.
            const rawPhone = form.phone.value.trim();
            let normalizedPhone = null;
            if (rawPhone !== '') {
                normalizedPhone = normalizeUsPhone(rawPhone);
                if (!normalizedPhone) {
                    showError('If a phone number is entered, it must be a valid 10-digit US number.');
                    return;
                }
            }

            await geocodeAddress();

            submitBtn.disabled = true;
            submitLabel.textContent = 'Submitting…';

            const taskPurpose = form.problem.value.trim();
            const requestBody = {
                is_task: true,
                first_name: form.first_name.value.trim(),
                last_name: form.last_name.value.trim(),
                company_name: form.company_name.value.trim(),
                phone: normalizedPhone ? `+1${normalizedPhone}` : '',
                email: form.email.value.trim(),
                machine_brand: '',
                machine_model: '',
                machine_watts: null,
                machine_age: null,
                address: form.address.value.trim(),
                city: form.city.value.trim(),
                state: form.state.value.trim().toUpperCase(),
                zip: form.zip.value.trim(),
                latitude: form.latitude.value.trim() || null,
                longitude: form.longitude.value.trim() || null,
                problem: taskPurpose,
                duration_minutes: parseInt(form.duration_minutes.value, 10) || null,
                password: '',
                confirm_password: '',
                priority: form.priority.value,
                booking_source: 'Internal',
                website: form.website.value.trim(),
                services: [],
                other_service: '',
                service_speed: 'standard'
            };

            try {
                const response = await fetch('/api/book-repair-api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestBody),
                });
                const json = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const apiMessage = (Array.isArray(json.errors) && json.errors.length > 0)
                        ? json.errors.join(' ')
                        : (json.message || 'Please check the details and try again.');
                    throw new Error(apiMessage);
                }

                showSuccess('Task submitted successfully and added to the scheduling queue.');
                form.reset();
            } catch (error) {
                showError(error instanceof Error ? error.message : 'Network error — please try again.');
            } finally {
                submitBtn.disabled = false;
                submitLabel.textContent = 'Submit Task';
            }
        });
    })();
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
