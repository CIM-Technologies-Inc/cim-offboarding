<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Middleware\PermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // Kept as the app's own alias (Spatie-backed internally, see
            // EnsureUserHasRole) rather than Spatie's own `role` middleware,
            // so every existing `role:admin` etc. route group across the
            // app keeps working completely unchanged.
            'role' => EnsureUserHasRole::class,
            'password.changed' => EnsurePasswordChanged::class,
            // Spatie's own permission middleware — new, only used by the
            // Roles & Permissions and Users pages.
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every uncaught exception is still fully logged via Laravel's
        // default reporting pipeline regardless of anything below — this
        // callback only ever affects what gets RENDERED back to the
        // client, never whether/how it's recorded server-side. See
        // storage/logs/laravel.log for the real exception class, message,
        // file/line, and stack trace.
        //
        // For a JSON-expecting request (every fetch()-based modal/action
        // in this app — General Signatory, Final Approver, Reset
        // Offboarding, checklist item "Done", etc.), Laravel's OWN default
        // behavior already redacts exception details when `APP_DEBUG` is
        // off, but INCLUDES the full exception class/message/file/line/
        // trace whenever `APP_DEBUG` is on — which is exactly the
        // environment this app is normally developed/tested in. That gap
        // is real: right now, a genuine server error during local testing
        // leaks a full stack trace straight into the browser's fetch()
        // response. Forcing a safe, generic message here for any 500+
        // status makes that unconditional — identical in development and
        // production — so no fetch()-driven action can ever surface raw
        // technical detail either way.
        //
        // 4xx statuses are deliberately left mostly untouched: those
        // already carry a specific, safe, human-written message the
        // calling JS already knows how to display (`data.message`/
        // `data.errors` — see e.g. `general-signatory-modal.blade.php`'s
        // `submitViaFetch()`). The two exceptions are 419 (CSRF/session
        // expiry) and 429 (rate limiting), whose Laravel-default messages
        // ("CSRF token mismatch.", "Too Many Attempts.") read as internal
        // jargon rather than the plain-language wording every other
        // message in this app uses.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // PHP's `max_execution_time` fatal (e.g. a slow SMTP send while
            // submitting a New Offboarding Request — see
            // `OffboardingRequestController::store()`) surfaces here as a
            // `FatalError`, synthesized by Laravel's own shutdown handler
            // from `error_get_last()` — a real, catchable \Throwable by the
            // time it reaches this callback, just like any other exception.
            // Checked BEFORE the JSON-only guard below since this needs to
            // render a friendly page for a plain browser form submission
            // too, not only for fetch()-driven actions.
            $isExecutionTimeout = $e instanceof \Symfony\Component\ErrorHandler\Error\FatalError
                && str_contains($e->getMessage(), 'Maximum execution time');

            if ($isExecutionTimeout) {
                // Laravel's default exception reporting already logs the
                // full exception (class/message/file/line/trace) regardless
                // of this callback — this is a second, deliberately terse
                // entry purely so a timeout specifically is trivial to grep
                // for, without dumping the same trace twice.
                Log::error('Request timed out (maximum execution time exceeded).', [
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                ]);

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'The request took too long to process. Please try again.',
                    ], 504);
                }

                // Sends the "Try Again" button back to whichever page the
                // timed-out form was actually submitted from (e.g. the
                // Dashboard or Offboardee page, both of which host the New
                // Offboarding Request modal) — never a hardcoded route, so
                // this works the same for any other form that happens to
                // time out, not just this one.
                return response()->view('errors.504', [
                    'primaryAction' => $request->headers->get('referer') ?: 'reload',
                ], 504);
            }

            if (! $request->expectsJson()) {
                return null;
            }

            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;

            if ($status === 419) {
                return response()->json([
                    'message' => 'Your session has expired. Please refresh the page and try again.',
                ], 419);
            }

            if ($status === 429) {
                return response()->json([
                    'message' => 'You are making requests too quickly. Please wait a moment and try again.',
                ], 429);
            }

            if ($status < 500) {
                return null;
            }

            return response()->json([
                'message' => "We're sorry, but we couldn't complete your request. The process may have taken too long or an unexpected error occurred. Please try again.",
            ], $status);
        });
    })->create();
