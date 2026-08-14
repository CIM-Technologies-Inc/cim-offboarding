<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\OnboardingChecklistTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OnboardingChecklistTemplateController extends Controller
{
    public function index(): View
    {
        $templates = OnboardingChecklistTemplate::withCount('items')
            ->with('creator')
            ->latest()
            ->get();

        return view('pages.onboarding-checklists.index', [
            'title' => 'Onboarding Checklist',
            'templates' => $templates,
        ]);
    }

    public function create(): View
    {
        return view('pages.onboarding-checklists.create', [
            'title' => 'New Onboarding Checklist Template',
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'department']),
            'departmentHeads' => $this->departmentHeadOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_head_id' => ['nullable', 'exists:employees,id'],
            'department' => ['nullable', 'string', 'in:HR,IT,Accounting,Sales'],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.signatory_id' => ['nullable', 'exists:employees,id'],
        ]);

        DB::transaction(function () use ($validated, $request) {
            $template = OnboardingChecklistTemplate::create([
                'title' => $validated['title'],
                'department_head_id' => $validated['department_head_id'] ?: null,
                'department' => $validated['department'] ?? null,
                'due_in_days' => $validated['due_in_days'] ?: null,
                'is_active' => true,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $index => $item) {
                $template->items()->create([
                    'title' => $item['title'],
                    'signatory_id' => $item['signatory_id'] ?: null,
                    'sort_order' => $index,
                ]);
            }
        });

        return redirect()->route('onboarding-checklists.index')->with('success', 'Onboarding checklist template saved.');
    }

    public function show(OnboardingChecklistTemplate $onboardingChecklist): View
    {
        $onboardingChecklist->load('items.signatory', 'creator');

        return view('pages.onboarding-checklists.show', [
            'title' => $onboardingChecklist->title,
            'template' => $onboardingChecklist,
        ]);
    }

    public function edit(OnboardingChecklistTemplate $onboardingChecklist): View
    {
        $onboardingChecklist->load('items');

        return view('pages.onboarding-checklists.edit', [
            'title' => 'Edit Onboarding Checklist Template',
            'template' => $onboardingChecklist,
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'department']),
            'departmentHeads' => $this->departmentHeadOptions(),
        ]);
    }

    public function update(Request $request, OnboardingChecklistTemplate $onboardingChecklist): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_head_id' => ['nullable', 'exists:employees,id'],
            'department' => ['nullable', 'string', 'in:HR,IT,Accounting,Sales'],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.signatory_id' => ['nullable', 'exists:employees,id'],
        ]);

        DB::transaction(function () use ($validated, $onboardingChecklist) {
            $onboardingChecklist->update([
                'title' => $validated['title'],
                'department_head_id' => $validated['department_head_id'] ?: null,
                'department' => $validated['department'] ?? null,
                'due_in_days' => $validated['due_in_days'] ?: null,
            ]);

            $onboardingChecklist->items()->delete();

            foreach ($validated['items'] as $index => $item) {
                $onboardingChecklist->items()->create([
                    'title' => $item['title'],
                    'signatory_id' => $item['signatory_id'] ?: null,
                    'sort_order' => $index,
                ]);
            }
        });

        return redirect()->route('onboarding-checklists.index')->with('success', 'Onboarding checklist template updated.');
    }

    public function destroy(OnboardingChecklistTemplate $onboardingChecklist): RedirectResponse
    {
        $onboardingChecklist->delete();

        return redirect()->route('onboarding-checklists.index')->with('success', 'Onboarding checklist template deleted.');
    }

    public function toggleStatus(OnboardingChecklistTemplate $onboardingChecklist): RedirectResponse
    {
        $onboardingChecklist->update(['is_active' => ! $onboardingChecklist->is_active]);

        return redirect()->route('onboarding-checklists.index')
            ->with('success', 'Onboarding checklist template marked as ' . ($onboardingChecklist->is_active ? 'active' : 'inactive') . '.');
    }

    /**
     * Employees eligible to be picked as a department head: managers and above.
     */
    private function departmentHeadOptions()
    {
        return Employee::whereIn('designation', Employee::MANAGEMENT_DESIGNATIONS)
            ->orderBy('name')
            ->get(['id', 'name', 'department', 'designation']);
    }
}
