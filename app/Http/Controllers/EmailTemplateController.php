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

            $emailTemplate->update($validated);
        });

        return redirect()->route('email-templates.create')
            ->with('success', 'Template updated successfully.');
    }

    /**
     * Discards a submitted timing/day count whenever scheduling is off, so
     * unchecking "Schedule Before/After Last Working Day" on an update
     * always clears any previously configured schedule rather than leaving
     * stale values behind.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeSchedule(array $validated): array
    {
        if (empty($validated['is_scheduled'])) {
            $validated['schedule_timing'] = null;
            $validated['schedule_days'] = null;
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
