/**
 * Admin PWA Service Worker
 * Only caches and serves admin-section pages.
 * Public booking pages are never intercepted.
 */

const CACHE_NAME = 'ghost-laser-admin-v1';

const ADMIN_PAGES = [
    '/dashboard.php',
    '/settings.php',
    '/service-settings.php',
    '/speed-settings.php',
    '/travel-settings.php',
    '/scheduling_settings.php',
    '/book_internal.php',
    '/book_task.php',
    '/mileage-tracker.php',
    '/sms-tool.php',
    '/technician/schedule.php',
    '/technician-dashboard.php',
];

/**
 * Returns true for any request that belongs to an admin page or its
 * same-origin sub-resources (JS, CSS, images, API calls made from admin pages).
 * Public-facing pages (index, book_a_technician, customer-login, etc.) are
 * explicitly excluded so this SW never intercepts the booking flow.
 */
const PUBLIC_PATHS = [
    '/index.php',
    '/',
    '/book_a_technician.php',
    '/book_a_repair.php',
    '/customer-login.php',
    '/customer-logout.php',
    '/customer-login-ajax.php',
    '/contact-submit.php',
    '/send-contact-email.php',
    '/sms-opt-in.php',
    '/privacy-policy.php',
    '/terms.php',
];

function isAdminRequest(url) {
    if (url.origin !== self.location.origin) return false;
    const path = url.pathname;
    // Never cache public booking pages
    if (PUBLIC_PATHS.some(p => path === p || path.startsWith(p + '?'))) return false;
    // Cache known admin pages
    return ADMIN_PAGES.some(p => path === p || path.startsWith(p + '?'));
}

// Install: pre-cache the admin shell page
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.add('/dashboard.php'))
    );
    self.skipWaiting();
});

// Activate: remove old admin caches, but leave any caches owned by other SWs
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((k) => k.startsWith('ghost-laser-admin-') && k !== CACHE_NAME)
                    .map((k) => caches.delete(k))
            )
        )
    );
    self.clients.claim();
});

// Fetch: network-first for admin pages; pass through everything else untouched
self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);
    if (!isAdminRequest(url)) return; // let the browser handle it normally

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (response && response.status === 200) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                }
                return response;
            })
            .catch(() => caches.match(event.request))
    );
});
