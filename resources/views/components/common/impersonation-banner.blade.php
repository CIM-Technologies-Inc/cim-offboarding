{{--
    Shown on every page, in every tab, for as long as the current browser
    session carries an `impersonator_id` (set by
    ImpersonationController::start(), cleared by its stop()) — see that
    controller's own docblock for why a session-wide banner, not a
    per-tab indicator, is the correct design for a cookie-based session.
    Deliberately included directly in layouts/app.blade.php (ahead of the
    sidebar/header) rather than conditionally in individual pages, so it's
    impossible for an impersonated session to end up on a page with no way
    back to the admin's own account.
--}}
{{-- `sticky top-0` (with a higher z-index than app-header.blade.php's own
     `z-99999` sticky header) so this stays reachable even once the page is
     scrolled — without it, the banner scrolls away with the rest of the
     static content while the header stays pinned, and the only way back to
     the admin's own account becomes unreachable without scrolling back up
     first. --}}
<div class="sticky top-0 z-[100000] flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber-500 px-4 py-2 text-center text-sm font-medium text-amber-950">
    <span>
        You are viewing this application as <strong>{{ auth()->user()->name }}</strong> — impersonation session started by <strong>{{ session('impersonator_name') }}</strong>.
    </span>
    <form method="POST" action="{{ route('impersonate.stop') }}">
        @csrf
        {{-- data-turbo-submits-with: Turbo's own built-in submit guard —
             disables the button and swaps its label for the duration of the
             request, same convention every other submit button in this app
             already uses (e.g. the role-update Save button), so a second
             click while the first request is in flight can't fire a second,
             now-stale-token submission. --}}
        <button type="submit" data-turbo-submits-with="Returning..."
            class="rounded-lg bg-amber-950 px-3 py-1 text-xs font-semibold text-amber-50 hover:bg-amber-900">
            Return to My Account
        </button>
    </form>
</div>
