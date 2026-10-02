<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmailTemplateRequest;
use App\Http\Requests\UpdateEmailTemplateRequest;
use App\Models\Employee;
use App\Models\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EmailTemplateController extends Controller
{
    public function index(): View
    {
        return $this->workspace(null);
    }

    public function create(): View
    {
        return $this->workspace(null);
    }

    public function edit(EmailTemplate $emailTemplate): View
    {
        return $this->workspace($emailTemplate);
    }

    private function workspace(?EmailTemplate $template): View
    {
        return view('pages.email-templates.workspace', [
            'title' => 'Email and Notification',
            'templates' => EmailTemplate::latest('updated_at')->get(),
            'template' => $template,
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'department']),
        ]);
    }

    public function store(StoreEmailTemplateRequest $request): RedirectResponse
    {
        $validated = $this->normalizeSchedule($request->validated());

        DB::transaction(function () use ($request, $validated) {
            if ($validated['is_default_announcement']) {
                $this->clearOtherDefaultAnnouncements();
            }

            if ($validated['is_default_reactivation']) {
                $this->clearOtherDefaultReactivations();
            }

            EmailTemplate::create($validated + [
                'created_by' => $request->user()->id,
            ]);
        });

        return redirect()->route('email-templates.create')
            ->with('success', 'Template saved successfully.');
    }

    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $validated = $this->normalizeSchedule($request->validated());

        DB::transaction(function () use ($validated, $emailTemplate) {
            if ($validated['is_default_announcement']) {
                $this->clearOtherDefaultAnnouncements($emailTemplate->id);
            }

            if ($validated['is_default_reactivation']) {
                $this->clearOtherDefaultReactivations($emailTemplate->id);
            }

            $emailTemplate->update($validated);
        });

        return redirect()->route('email-templates.create')
            ->with('success', 'Template updated successfully.');
    }

    /**
     * Discards whichever schedule fields don't apply, so stale values from
     * an earlier configuration never linger. Unchecking "Schedule
     * Email/Notification" clears every schedule field. Otherwise, only the
     * fields for the currently selected `schedule_type` are kept — a
     * template switched from "One-time" to "Recurring" (or back) never
     * keeps the other mode's now-irrelevant timing/day values.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeSchedule(array $validated): array
    {
        if (empty($validated['is_scheduled'])) {
            // `schedule_type` itself is NOT NULL at the database level
            // (defaults to 'one_time' — see the
            // `add_recurring_schedule_to_email_templates_table` migration),
            // unlike every other schedule field here. Reset to that same
            // default rather than null, which the column rejects outright.
            // Harmless either way: every consumer (`SendScheduledEmailTemplates`,
            // `EmailTemplate::scheduleLabel()`) gates on `is_scheduled`
            // first and never reads `schedule_type` once it's false.
            $validated['schedule_type'] = 'one_time';
            $validated['schedule_timing'] = null;
            $validated['schedule_days'] = null;
            $validated['schedule_interval_days'] = null;

            return $validated;
        }

        if ($validated['schedule_type'] === 'recurring') {
            $validated['schedule_timing'] = null;
            $validated['schedule_days'] = null;
        } else {
            $validated['schedule_interval_days'] = null;
        }

        return $validated;
    }

    /**
     * Only one template may be the default Offboarding Announcement
     * template — enforced here rather than relying on the checkbox alone,
     * since a client could submit `is_default_announcement=true` for more
     * than one template.
     */
    private function clearOtherDefaultAnnouncements(?int $exceptId = null): void
    {
        EmailTemplate::where('is_default_announcement', true)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default_announcement' => false]);
    }

    /**
     * Only one template may be the default Account Reactivation template —
     * same enforcement as `clearOtherDefaultAnnouncements()` above, for the
     * same reason (a client could otherwise submit
     * `is_default_reactivation=true` for more than one template).
     */
    private function clearOtherDefaultReactivations(?int $exceptId = null): void
    {
        EmailTemplate::where('is_default_reactivation', true)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default_reactivation' => false]);
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->delete();

        return redirect()->route('email-templates.index')
            ->with('success', 'Template deleted successfully.');
    }

    public function toggleStatus(Request $request, EmailTemplate $emailTemplate): RedirectResponse|JsonResponse
    {
        $emailTemplate->update(['is_active' => ! $emailTemplate->is_active]);

        $message = 'Template marked as ' . ($emailTemplate->is_active ? 'active' : 'inactive') . '.';

        if ($request->wantsJson()) {
            return response()->json([
                'is_active' => $emailTemplate->is_active,
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }
}
