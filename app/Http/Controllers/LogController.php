<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Logs module — read-only, by design (see `ActivityLog`'s own
 * docblock): no role, admin included, gets an edit/delete action here.
 * The first page in this app to use real server-side pagination/
 * filtering rather than loading everything and filtering client-side in
 * Alpine — the only list page where that pattern doesn't scale, since an
 * audit trail is expected to grow large and keep growing.
 */
class LogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = ActivityLog::query()
            ->with('user')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->query('search');
                $query->where(function ($query) use ($search) {
                    $query->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('employee_name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->query('user_id')))
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->query('module')))
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->query('action')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->query('date_to')))
            ->orderBy('created_at', $request->query('sort') === 'oldest' ? 'asc' : 'desc')
            ->paginate(25)
            ->withQueryString();

        return view('pages.logs.index', [
            'title' => 'Logs',
            'logs' => $logs,
            'modules' => ActivityLog::query()->distinct()->orderBy('module')->pluck('module'),
            'actions' => ActivityLog::query()->distinct()->orderBy('action')->pluck('action'),
            'users' => User::orderBy('name')->get(['id', 'name', 'username']),
            'filters' => $request->only(['search', 'user_id', 'module', 'action', 'status', 'date_from', 'date_to', 'sort']),
        ]);
    }

    public function show(ActivityLog $activityLog): JsonResponse
    {
        $relatedLogin = null;

        if (in_array($activityLog->action, ['logout', 'session_expired'], true) && $activityLog->user_id) {
            $relatedLogin = ActivityLog::where('user_id', $activityLog->user_id)
                ->where('action', 'successful_login')
                ->where('created_at', '<=', $activityLog->created_at)
                ->orderByDesc('created_at')
                ->first();
        }

        return response()->json([
            'log' => $activityLog,
            'loginTime' => $relatedLogin?->created_at?->format('M d, Y g:i A'),
            'sessionDurationMinutes' => $relatedLogin
                ? $relatedLogin->created_at->diffInMinutes($activityLog->created_at)
                : null,
        ]);
    }

    /**
     * Logs → Email Notifications: every email send attempt recorded by
     * `EmailLogRecorder` (system-triggered and scheduled). Read-only,
     * server-side filtered/paginated like the activity log above.
     */
    public function emails(Request $request): View
    {
        $filtered = EmailLog::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->query('search');
                $query->where(function ($query) use ($search) {
                    $query->where('subject', 'like', "%{$search}%")
                        ->orWhere('template_name', 'like', "%{$search}%")
                        ->orWhere('offboardee_name', 'like', "%{$search}%")
                        ->orWhere('trigger', 'like', "%{$search}%")
                        ->orWhere('schedule_name', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('recipient'), fn ($query) => $query->where('recipients_search', 'like', '%'.$request->query('recipient').'%'))
            ->when(in_array($request->query('type'), array_keys(EmailLog::TYPE_LABELS), true), fn ($query) => $query->where('notification_type', $request->query('type')))
            ->when(in_array($request->query('status'), array_keys(EmailLog::STATUS_LABELS), true), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->query('date_to')));

        $statusCounts = (clone $filtered)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $emails = $filtered
            ->orderBy('created_at', $request->query('sort') === 'oldest' ? 'asc' : 'desc')
            ->orderBy('id', $request->query('sort') === 'oldest' ? 'asc' : 'desc')
            ->paginate(25)
            ->withQueryString();

        return view('pages.logs.email-notifications', [
            'title' => 'Logs',
            'emails' => $emails,
            'statusCounts' => $statusCounts,
            'filters' => $request->only(['search', 'recipient', 'type', 'status', 'date_from', 'date_to', 'sort']),
        ]);
    }

    public function emailShow(EmailLog $emailLog): JsonResponse
    {
        $format = fn ($date) => $date?->format('M d, Y g:i:s A');

        return response()->json([
            'id' => $emailLog->id,
            'subject' => $emailLog->subject,
            'templateName' => $emailLog->template_name,
            'mailable' => $emailLog->mailable,
            'to' => $emailLog->to_recipients ?? [],
            'cc' => $emailLog->cc_recipients ?? [],
            'bcc' => $emailLog->bcc_recipients ?? [],
            'redirectedTo' => $emailLog->redirected_to,
            'type' => $emailLog->notification_type,
            'typeLabel' => $emailLog->typeLabel(),
            'status' => $emailLog->status,
            'statusLabel' => $emailLog->statusLabel(),
            'error' => $emailLog->error_message,
            'trigger' => $emailLog->trigger,
            'triggerSource' => $emailLog->trigger_source,
            'delivery' => $emailLog->delivery,
            'scheduleName' => $emailLog->schedule_name,
            'scheduledFor' => $emailLog->scheduled_for?->format('M d, Y'),
            'offboardingRequestId' => $emailLog->offboarding_request_id,
            'offboardeeName' => $emailLog->offboardee_name,
            'triggeredBy' => $emailLog->triggered_by_name,
            'createdAt' => $format($emailLog->created_at),
            'sentAt' => $format($emailLog->sent_at),
            'failedAt' => $format($emailLog->failed_at),
            'smtpMessageId' => $emailLog->smtp_message_id,
            'attempt' => $emailLog->attempt,
            'attempts' => $emailLog->attempts()->get(['id', 'attempt', 'status', 'created_at', 'error_message'])
                ->map(fn (EmailLog $attempt) => [
                    'id' => $attempt->id,
                    'attempt' => $attempt->attempt,
                    'status' => $attempt->status,
                    'statusLabel' => $attempt->statusLabel(),
                    'at' => $format($attempt->created_at),
                    'error' => $attempt->error_message,
                    'current' => $attempt->id === $emailLog->id,
                ])->values(),
        ]);
    }
}
