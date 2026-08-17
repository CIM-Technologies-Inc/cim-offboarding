// Global 10-minute inactivity auto-logout, active on every authenticated
// page without any per-page wiring. Initialized once from app.js (NOT on
// every `turbo:load`) since this app is a Turbo Drive SPA — the JS realm
// and its event listeners/interval persist across Turbo-driven navigations
// on their own, so re-attaching listeners per page would only create
// duplicates. A genuine hard page refresh DOES tear down and re-run this
// module from scratch, which is exactly why the "last activity" clock is
// persisted in localStorage rather than kept purely in memory: a refresh
// must never hand the user a fresh, full 10 minutes just because the page
// happened to reload while they were already sitting idle.
const INACTIVITY_LIMIT_MS = 10 * 60 * 1000;
const CHECK_INTERVAL_MS = 5 * 1000;
const ACTIVITY_THROTTLE_MS = 1000;
const STORAGE_KEY = 'cim_last_activity_at';
const SESSION_MARKER_KEY = 'cim_session_marker';
const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'];

export function initInactivityMonitor() {
    let loggingOut = false;
    let lastWriteAt = 0;

    // The current page only carries these `data-*` attributes when it was
    // rendered by the authenticated layout — read fresh every time (never
    // cached) so a Turbo-driven navigation to the public sign-in page (a
    // manual "Sign out", or the auto-logout's own redirect) is picked up
    // immediately and every subsequent tick simply stops acting.
    function isAuthenticatedPage() {
        return document.body?.dataset.inactivityMonitor === '1';
    }

    function currentSessionMarker() {
        return document.body?.dataset.sessionMarker || '';
    }

    function readLastActivity() {
        const raw = localStorage.getItem(STORAGE_KEY);
        const parsed = raw ? parseInt(raw, 10) : NaN;

        return Number.isFinite(parsed) ? parsed : null;
    }

    function writeLastActivity(timestamp) {
        try {
            localStorage.setItem(STORAGE_KEY, String(timestamp));
        } catch (e) {
            // Storage unavailable (private browsing, quota) — degrades to
            // an in-memory-only clock for this tab; still correct, just
            // doesn't survive a hard refresh.
        }
    }

    // Throttled so a flurry of mousemove/scroll events doesn't hammer
    // localStorage — only the timestamp value matters, not every event.
    function recordActivity() {
        if (loggingOut || !isAuthenticatedPage()) {
            return;
        }

        const now = Date.now();

        if (now - lastWriteAt < ACTIVITY_THROTTLE_MS) {
            return;
        }

        lastWriteAt = now;
        writeLastActivity(now);
    }

    // A different Laravel session (session ID changes on every real login —
    // see AuthController::store()'s `$request->session()->regenerate()`)
    // means any persisted "last activity" clock belongs to a previous
    // session and must not be used to judge this one's idle time — e.g. a
    // user logging back in shortly after their session auto-expired must
    // get a fresh 10 minutes, not an instant re-logout from the stale clock.
    function ensureSessionMarkerFresh() {
        const marker = currentSessionMarker();

        if (!marker) {
            return;
        }

        if (localStorage.getItem(SESSION_MARKER_KEY) !== marker) {
            localStorage.setItem(SESSION_MARKER_KEY, marker);
            writeLastActivity(Date.now());
        }
    }

    function submitLogout() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/logout';
        form.style.display = 'none';

        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value = token;
        form.appendChild(csrfInput);

        document.body.appendChild(form);
        form.submit();
    }

    // `loggingOut` guards this against firing twice — from this tick or a
    // later one — and it's only ever called from `tick()` below.
    function triggerLogout() {
        if (loggingOut) {
            return;
        }

        loggingOut = true;

        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // ignore
        }

        if (!window.Swal) {
            submitLogout();
            return;
        }

        window.Swal.fire({
            icon: 'warning',
            title: 'Session Expiring',
            text: "You've been inactive for 10 minutes and will now be logged out for security purposes.",
            confirmButtonText: 'OK',
            confirmButtonColor: '#145a3a',
            allowOutsideClick: false,
            allowEscapeKey: false,
            timer: 4000,
            timerProgressBar: true,
        }).then(() => submitLogout());
    }

    function tick() {
        if (loggingOut || !isAuthenticatedPage()) {
            return;
        }

        ensureSessionMarkerFresh();

        const lastActivity = readLastActivity();

        if (lastActivity === null) {
            writeLastActivity(Date.now());
            return;
        }

        if (Date.now() - lastActivity >= INACTIVITY_LIMIT_MS) {
            triggerLogout();
        }
    }

    ACTIVITY_EVENTS.forEach((eventName) => {
        document.addEventListener(eventName, recordActivity, { passive: true });
    });

    // A Turbo-driven page navigation is itself evidence the user is present
    // — recorded as activity like any other event above. Also re-runs the
    // full check immediately (not just recordActivity()): this module's own
    // one-time init call (below) can run on a pre-login page — e.g. a fresh
    // sign-in submits through Turbo too, so `initInactivityMonitor()`
    // actually executes once on the sign-in page itself, before
    // `data-session-marker` exists — so without this, the session marker
    // wouldn't get seeded until the next periodic tick, up to
    // CHECK_INTERVAL_MS later.
    document.addEventListener('turbo:load', () => {
        recordActivity();
        tick();
    });

    ensureSessionMarkerFresh();
    tick();
    setInterval(tick, CHECK_INTERVAL_MS);
}

export default initInactivityMonitor;
