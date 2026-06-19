<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Repair | Ghost Laser</title>
    <meta name="description" content="Book a laser machine repair with Ghost Laser. Fast, professional service for all major laser cutting and engraving machines.">
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
        .glow-box { box-shadow: 0 0 0 1px rgba(6,182,212,0.2), 0 0 40px rgba(6,182,212,0.05); }
        .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.4); }
        .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.7); }
        .nav-blur {
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
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
        .priority-radio input[type="radio"] { display: none; }
        .priority-radio label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid rgb(63,63,70);
            background: rgba(39,39,42,0.6);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }
        .priority-radio input[type="radio"]:checked + label {
            border-color: #06b6d4;
            background: rgba(6,182,212,0.08);
            color: #22d3ee;
        }
        .priority-radio label:hover { border-color: rgb(113,113,122); }
        .priority-dot { width: 0.5rem; height: 0.5rem; border-radius: 9999px; flex-shrink: 0; }
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
                <nav class="hidden md:flex items-center gap-8">
                    <a href="/#services" class="text-sm text-zinc-400 hover:text-white transition-colors">Services</a>
                    <a href="/#why-us" class="text-sm text-zinc-400 hover:text-white transition-colors">Why Us</a>
                    <a href="/#process" class="text-sm text-zinc-400 hover:text-white transition-colors">Process</a>
                    <a href="/#contact" class="text-sm text-zinc-400 hover:text-white transition-colors">Contact</a>
                </nav>
                <a href="/" class="hidden md:inline-flex items-center gap-2 bg-zinc-800 hover:bg-zinc-700 text-white font-semibold text-sm px-4 py-2 rounded-md transition-colors border border-zinc-700">
                    ← Back to Home
                </a>
                <!-- Mobile menu button -->
                <button id="mobile-menu-btn" class="md:hidden text-zinc-400 hover:text-white p-1" aria-label="Open menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>
        <!-- Mobile menu -->
        <div id="mobile-menu" class="hidden md:hidden border-t border-zinc-800/60 bg-zinc-950/95">
            <div class="px-6 py-4 flex flex-col gap-4">
                <a href="/#services" class="text-sm text-zinc-400 hover:text-white transition-colors">Services</a>
                <a href="/#why-us" class="text-sm text-zinc-400 hover:text-white transition-colors">Why Us</a>
                <a href="/#process" class="text-sm text-zinc-400 hover:text-white transition-colors">Process</a>
                <a href="/#contact" class="text-sm text-zinc-400 hover:text-white transition-colors">Contact</a>
                <a href="/" class="inline-flex items-center justify-center gap-2 bg-zinc-800 hover:bg-zinc-700 text-white font-semibold text-sm px-4 py-2 rounded-md transition-colors border border-zinc-700 w-full">
                    ← Back to Home
                </a>
            </div>
        </div>
    </header>

    <!-- PAGE HEADER -->
    <section class="pt-32 pb-12 lg:pb-16 bg-zinc-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8 text-center">
            <div class="inline-flex items-center gap-2 bg-zinc-900 border border-cyan-500/30 rounded-full px-4 py-1.5 mb-8">
                <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
                <span class="text-xs text-cyan-400 font-medium tracking-wider uppercase">Book a Service</span>
            </div>
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight mb-5">
                Book Your <span class="text-cyan-400 glow-cyan">Repair</span>
            </h1>
            <p class="text-zinc-400 text-lg leading-relaxed max-w-xl mx-auto">
                Fill in the details below and our team will follow up within 2 hours with a diagnosis plan and transparent quote.
            </p>
        </div>
    </section>

    <!-- FORM -->
    <section class="pb-24 lg:pb-32 bg-zinc-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">

            <!-- Success message (hidden by default) -->
            <div id="msg-success" class="hidden mb-6 flex items-start gap-3 bg-emerald-950/60 border border-emerald-500/30 rounded-xl px-5 py-4">
                <svg class="w-5 h-5 text-emerald-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <div>
                    <p class="font-semibold text-emerald-400 text-sm">Booking received!</p>
                    <p class="text-emerald-300/70 text-sm mt-0.5">We'll be in touch within 2 hours to confirm your appointment.</p>
                </div>
            </div>

            <!-- Error message (hidden by default) -->
            <div id="msg-error" class="hidden mb-6 flex items-start gap-3 bg-red-950/60 border border-red-500/30 rounded-xl px-5 py-4">
                <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="font-semibold text-red-400 text-sm">Something went wrong</p>
                    <p id="msg-error-text" class="text-red-300/70 text-sm mt-0.5">Please check your details and try again.</p>
                </div>
            </div>

            <form id="repair-form" novalidate class="bg-zinc-900/60 border border-zinc-800 rounded-2xl p-6 sm:p-8 text-left glow-box space-y-6">

                <!-- Section: Contact -->
                <div>
                    <p class="text-xs text-cyan-400 font-semibold tracking-widest uppercase mb-4">Contact Information</p>
                    <div class="grid sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="name">Full Name <span class="text-red-400">*</span></label>
                            <input type="text" id="name" name="name" placeholder="Jane Smith" required
                                class="input-base">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="phone">Phone Number <span class="text-red-400">*</span></label>
                            <input type="tel" id="phone" name="phone" placeholder="+1 (555) 000-0000" required
                                class="input-base">
                        </div>
                    </div>
                    <div class="mt-5">
                        <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="email">Email Address <span class="text-red-400">*</span></label>
                        <input type="email" id="email" name="email" placeholder="jane@company.com" required
                            class="input-base">
                    </div>
                </div>

                <div class="border-t border-zinc-800"></div>

                <!-- Section: Machine Details -->
                <div>
                    <p class="text-xs text-cyan-400 font-semibold tracking-widest uppercase mb-4">Machine Details</p>
                    <div class="grid sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="brand">Brand <span class="text-red-400">*</span></label>
                            <input type="text" id="brand" name="brand" placeholder="e.g. Epilog, Thunder Laser, xTool" required
                                class="input-base">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="model">Model <span class="text-red-400">*</span></label>
                            <input type="text" id="model" name="model" placeholder="e.g. Fusion Pro 48, Nova 35" required
                                class="input-base">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="watts">Wattage <span class="text-zinc-600 normal-case font-normal">(optional)</span></label>
                            <input type="number" id="watts" name="watts" placeholder="e.g. 60" min="1"
                                class="input-base">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="age">Machine Age <span class="text-zinc-600 normal-case font-normal">(optional)</span></label>
                            <input type="text" id="age" name="age" placeholder="e.g. 3 years"
                                class="input-base">
                        </div>
                    </div>
                </div>

                <div class="border-t border-zinc-800"></div>

                <!-- Section: Service Address -->
                <div>
                    <p class="text-xs text-cyan-400 font-semibold tracking-widest uppercase mb-4">Service Address</p>
                    <div class="space-y-5">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="street">Street Address <span class="text-red-400">*</span></label>
                            <input type="text" id="street" name="street" placeholder="123 Workshop Lane" required
                                class="input-base">
                        </div>
                        <div class="grid sm:grid-cols-3 gap-5">
                            <div class="sm:col-span-1">
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="city">City <span class="text-red-400">*</span></label>
                                <input type="text" id="city" name="city" placeholder="Austin" required
                                    class="input-base">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="state">State <span class="text-red-400">*</span></label>
                                <input type="text" id="state" name="state" placeholder="TX" required maxlength="2"
                                    class="input-base uppercase">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="zip">ZIP Code <span class="text-red-400">*</span></label>
                                <input type="text" id="zip" name="zip" placeholder="78701" required
                                    class="input-base">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-t border-zinc-800"></div>

                <!-- Section: Problem & Priority -->
                <div>
                    <p class="text-xs text-cyan-400 font-semibold tracking-widest uppercase mb-4">Problem &amp; Priority</p>
                    <div class="mb-5">
                        <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wide" for="problem">Problem Description <span class="text-red-400">*</span></label>
                        <textarea id="problem" name="problem" rows="4" required
                            placeholder="Describe what your machine is doing (or not doing). Include any error messages, sounds, or recent changes..."
                            class="input-base resize-none" style="height:auto"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-3 uppercase tracking-wide">Priority Level <span class="text-red-400">*</span></label>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">

                            <div class="priority-radio">
                                <input type="radio" id="priority-standard" name="priority" value="standard" checked>
                                <label for="priority-standard">
                                    <span class="priority-dot bg-zinc-400"></span>
                                    <span>
                                        <span class="block font-semibold text-sm">Standard</span>
                                        <span class="block text-zinc-500 text-xs">3–5 business days</span>
                                    </span>
                                </label>
                            </div>

                            <div class="priority-radio">
                                <input type="radio" id="priority-vip" name="priority" value="vip">
                                <label for="priority-vip">
                                    <span class="priority-dot bg-cyan-400"></span>
                                    <span>
                                        <span class="block font-semibold text-sm">VIP</span>
                                        <span class="block text-zinc-500 text-xs">Next business day</span>
                                    </span>
                                </label>
                            </div>

                            <div class="priority-radio">
                                <input type="radio" id="priority-emergency" name="priority" value="emergency">
                                <label for="priority-emergency">
                                    <span class="priority-dot bg-red-400"></span>
                                    <span>
                                        <span class="block font-semibold text-sm">Emergency</span>
                                        <span class="block text-zinc-500 text-xs">Same-day response</span>
                                    </span>
                                </label>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <button type="submit" id="submit-btn"
                    class="w-full bg-cyan-500 hover:bg-cyan-400 text-zinc-950 font-bold text-sm py-3.5 rounded-lg transition-all btn-glow flex items-center justify-center gap-2">
                    <span id="submit-label">Book My Repair →</span>
                    <svg id="submit-spinner" class="hidden w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                    </svg>
                </button>

                <p class="text-center text-xs text-zinc-600">
                    By submitting this form you agree to be contacted by our repair team regarding your booking.
                </p>

            </form>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="bg-zinc-950 border-t border-zinc-800/60 py-10">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2.5">
                <span class="w-6 h-6 rounded bg-cyan-500 flex items-center justify-center flex-shrink-0">
                    <svg class="w-3.5 h-3.5 text-zinc-950" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"/>
                    </svg>
                </span>
                <span class="text-white font-bold text-sm">Ghost<span class="text-cyan-400">Laser</span></span>
            </div>
            <p class="text-xs text-zinc-500 text-center">
                &copy; <?= date('Y') ?> Ghost Laser. All rights reserved. Expert laser machine repair.
            </p>
            <div class="flex items-center gap-5">
                <a href="/#services" class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors">Services</a>
                <a href="/#contact" class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors">Contact</a>
            </div>
        </div>
    </footer>

    <script>
        // Mobile menu toggle
        const btn = document.getElementById('mobile-menu-btn');
        const menu = document.getElementById('mobile-menu');
        btn.addEventListener('click', () => menu.classList.toggle('hidden'));
        menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => menu.classList.add('hidden')));

        // Form submission
        const form = document.getElementById('repair-form');
        const submitBtn = document.getElementById('submit-btn');
        const submitLabel = document.getElementById('submit-label');
        const submitSpinner = document.getElementById('submit-spinner');
        const msgSuccess = document.getElementById('msg-success');
        const msgError = document.getElementById('msg-error');
        const msgErrorText = document.getElementById('msg-error-text');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            // Hide previous messages
            msgSuccess.classList.add('hidden');
            msgError.classList.add('hidden');

            // Loading state
            submitBtn.disabled = true;
            submitLabel.textContent = 'Submitting…';
            submitSpinner.classList.remove('hidden');

            const data = {
                name:     form.name.value.trim(),
                phone:    form.phone.value.trim(),
                email:    form.email.value.trim(),
                brand:    form.brand.value.trim(),
                model:    form.model.value.trim(),
                watts:    form.watts.value.trim() || null,
                age:      form.age.value.trim() || null,
                street:   form.street.value.trim(),
                city:     form.city.value.trim(),
                state:    form.state.value.trim().toUpperCase(),
                zip:      form.zip.value.trim(),
                problem:  form.problem.value.trim(),
                priority: form.priority.value,
            };

            try {
                const res = await fetch('/project/api/book-repair-api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data),
                });

                const json = await res.json().catch(() => ({}));

                if (res.ok) {
                    form.reset();
                    // Re-check the default radio after reset
                    document.getElementById('priority-standard').checked = true;
                    msgSuccess.classList.remove('hidden');
                    msgSuccess.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } else {
                    msgErrorText.textContent = json.message || 'Please check your details and try again.';
                    msgError.classList.remove('hidden');
                    msgError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            } catch {
                msgErrorText.textContent = 'Network error — please check your connection and try again.';
                msgError.classList.remove('hidden');
                msgError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } finally {
                submitBtn.disabled = false;
                submitLabel.textContent = 'Book My Repair →';
                submitSpinner.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
