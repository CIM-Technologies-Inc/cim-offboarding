<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel sends no `Cache-Control` header at all by default, which leaves
 * the BROWSER free to reuse its own cached copy of a page's full HTML —
 * most aggressively on back/forward navigation, where Chrome/Firefox/Safari
 * all treat "revisit via history" far more leniently than a normal
 * reload/link-click, often serving the cached response with zero network
 * validation even though a network request still shows up in devtools.
 * This is invisible for mostly-static pages, but every page here embeds
 * the notification bell (`<x-header.notification-dropdown>`), whose
 * notification list/unread badge is baked into that same server-rendered
 * HTML — so a stale cached copy shows notifications that were already
 * permanently deleted (e.g. via "Clear All" — see
 * `NotificationController::clearAll()`, which already deletes for real;
 * the bug was never that it failed to delete, only that the BROWSER kept
 * showing an old snapshot from before the delete). Applied to the whole
 * `auth` middleware group in routes/web.php (same convention as
 * `EnsurePasswordChanged`/`EnsureAccountNotBlocked`), so every
 * authenticated page is covered with nothing to remember per-route — the
 * one place notifications are NOT rendered is the public, unauthenticated
 * pages outside this group, which never needed this anyway. Deliberately
 * leaves `/build/*` asset responses (served directly by the web server,
 * never routed through this middleware) free to keep their own
 * fingerprinted-filename caching untouched.
 */
class PreventPageCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
