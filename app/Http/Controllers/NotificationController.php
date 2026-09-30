<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Marks a single notification as read and redirects to wherever it
     * points. Scoped to the authenticated user's own notifications, so one
     * user can never mark-as-read/redirect using another user's ID.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($notification);
        $notification->markAsRead();

        return redirect($notification->data['url'] ?? route('dashboard'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    /**
     * Permanently deletes EVERY notification (read and unread) belonging
     * to the authenticated user — never another user's, since
     * `$request->user()->notifications()` is the same `notifiable`-scoped
     * relation `read()` above already relies on for that same guarantee.
     * Called via `fetch()` from the notification dropdown so the panel and
     * badge can update instantly with no page navigation.
     */
    public function clearAll(Request $request): JsonResponse|RedirectResponse
    {
        $request->user()->notifications()->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => 'All notifications cleared.']);
        }

        return back()->with('success', 'All notifications cleared.');
    }
}
