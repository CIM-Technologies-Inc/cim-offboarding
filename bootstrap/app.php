<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        //
    })->create();
