{{--
    Shared content for every resources/views/errors/{code}.blade.php page —
    used as <x-errors.page ... />. Laravel resolves those numbered files
    purely by filename convention (errors/404.blade.php, errors/500.blade.php,
    etc.) so each one has to exist on its own; this component exists so the
    actual markup/styling lives in exactly ONE place instead of being
    copy-pasted nine times.

    Deliberately renders on `layouts.fullscreen-layout` (the same lightweight,
    no-sidebar, no-DB-dependent layout the sign-in and email-approval-confirm
    pages already use) rather than the main app shell — an error page must
    still render correctly even when the thing that broke was the database
    itself, so it can never depend on the notification bell, user menu, or
    any other query-driven partial the main layout pulls in.

    Props:
    - code (string|int)      e.g. "404"
    - heading (string)       short human title, e.g. "Page Not Found"
    - message (string)       one or two plain-language sentences — never a
                              raw exception message
    - primaryLabel (?string) null omits the primary button entirely
    - primaryAction (string) 'reload' (default) or an absolute/relative URL
    - secondaryLabel (?string) null omits the secondary button entirely
    - secondaryUrl (?string)
--}}
@props([
    'code' => '',
    'heading' => 'Something Went Wrong',
    'message' => "We're sorry, but we couldn't complete your request. Please try again.",
    'primaryLabel' => 'Try Again',
    'primaryAction' => 'reload',
    'secondaryLabel' => 'Back to Dashboard',
    'secondaryUrl' => null,
])

@php
    // `route('dashboard')` merely builds a URL string here — it never
    // checks auth/permissions to do so, so this is always safe to compute
    // even when rendering this page for a logged-out or unauthorized
    // visitor. Falls back to the site root only if that named route is
    // ever missing (e.g. mid-refactor), so this component can never itself
    // throw while trying to render an error page.
    $secondaryUrl = $secondaryUrl ?? (\Illuminate\Support\Facades\Route::has('dashboard') ? route('dashboard') : url('/'));
@endphp

<div class="relative z-1 bg-white p-6 sm:p-0 dark:bg-gray-900">
    <div class="relative flex h-screen w-full flex-col justify-center sm:p-0 lg:flex-row dark:bg-gray-900">
        <div class="flex w-full flex-1 flex-col lg:w-1/2">
            <div class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center">
                <div class="text-center">
                    @if ($code)
                        <p class="mb-3 text-sm font-semibold tracking-wide text-gray-400 dark:text-gray-500">
                            ERROR {{ $code }}
                        </p>
                    @endif

                    <h1 class="text-title-sm sm:text-title-md mb-3 font-semibold text-gray-800 dark:text-white/90">
                        {{ $heading }}
                    </h1>

                    <p class="mb-8 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ $message }}
                    </p>

                    @php
                        $primaryOnClick = match (true) {
                            $primaryAction === 'reload' => 'window.location.reload()',
                            $primaryAction === 'back' => 'window.history.length > 1 ? window.history.back() : window.location.assign(' . json_encode($secondaryUrl) . ')',
                            default => 'window.location.assign(' . json_encode($primaryAction) . ')',
                        };
                    @endphp

                    @if ($primaryLabel || $secondaryLabel)
                        <div class="flex flex-col items-center justify-center gap-3 sm:flex-row">
                            @if ($primaryLabel)
                                <button type="button"
                                    onclick="{{ $primaryOnClick }}"
                                    class="flex w-full items-center justify-center rounded-lg bg-[#145a3a] px-5 py-3 text-sm font-medium text-white hover:bg-[#0f4630] sm:w-auto">
                                    {{ $primaryLabel }}
                                </button>
                            @endif

                            @if ($secondaryLabel)
                                <a href="{{ $secondaryUrl }}"
                                    class="flex w-full items-center justify-center rounded-lg border border-gray-300 px-5 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03] sm:w-auto">
                                    {{ $secondaryLabel }}
                                </a>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="bg-brand-950 relative hidden h-full w-full items-center lg:grid lg:w-1/2 dark:bg-white/5">
            <div class="z-1 flex items-center justify-center">
                <x-common.common-grid-shape/>
                <div class="flex max-w-xs flex-col items-center">
                    <a href="/" class="block">
                        <img src="/images/logo/signin-logo.svg" alt="Logo" />
                    </a>
                    <p class="text-center text-gray-400 dark:text-white/60">
                        Welcome To Employee Offboarding Platform
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
