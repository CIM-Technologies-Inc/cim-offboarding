<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmailTemplateRequest;
use App\Http\Requests\UpdateEmailTemplateRequest;
use App\Models\Employee;
use App\Models\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'title' => 'Email Template Management',
            'templates' => EmailTemplate::latest('updated_at')->get(),
            'template' => $template,
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'department']),
        ]);
    }

    public function store(StoreEmailTemplateRequest $request): RedirectResponse
    {
        EmailTemplate::create($request->validated() + [
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('email-templates.create')
            ->with('success', 'Template saved successfully.');
    }

    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->update($request->validated());

        return redirect()->route('email-templates.create')
            ->with('success', 'Template updated successfully.');
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
