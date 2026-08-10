<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ChecklistTemplateController extends Controller
{
    public function index(): View
    {
        $templates = ChecklistTemplate::withCount('items')
            ->with('creator')
            ->latest()
            ->get();

        return view('pages.checklist-templates.index', [
            'title' => 'Offboarding Checklist',
            'templates' => $templates,
        ]);
    }

    public function create(): View
    {
        return view('pages.checklist-templates.create', [
            'title' => 'New Checklist Template',
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
            'is_final_pay_checklist' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.signatory_id' => ['nullable', 'exists:employees,id'],
        ]);

        DB::transaction(function () use ($validated, $request) {
            $template = ChecklistTemplate::create([
                'title' => $validated['title'],
                'department_head_id' => $validated['department_head_id'] ?: null,
                'department' => $validated['department'] ?? null,
                'is_final_pay_checklist' => $request->boolean('is_final_pay_checklist'),
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

        return redirect()->route('checklist-templates.index')->with('success', 'Checklist template saved.');
    }

    public function show(ChecklistTemplate $checklistTemplate): View
    {
        $checklistTemplate->load('items.signatory', 'creator');

        return view('pages.checklist-templates.show', [
            'title' => $checklistTemplate->title,
            'template' => $checklistTemplate,
        ]);
    }

    public function edit(ChecklistTemplate $checklistTemplate): View
    {
        $checklistTemplate->load('items');

        return view('pages.checklist-templates.edit', [
            'title' => 'Edit Checklist Template',
            'template' => $checklistTemplate,
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'department']),
            'departmentHeads' => $this->departmentHeadOptions(),
        ]);
    }

    public function update(Request $request, ChecklistTemplate $checklistTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_head_id' => ['nullable', 'exists:employees,id'],
            'department' => ['required', 'string', 'in:HR,IT,Accounting,Sales'],
            'is_final_pay_checklist' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.signatory_id' => ['nullable', 'exists:employees,id'],
        ]);

        DB::transaction(function () use ($validated, $request, $checklistTemplate) {
            $checklistTemplate->update([
                'title' => $validated['title'],
                'department_head_id' => $validated['department_head_id'] ?: null,
                'department' => $validated['department'],
                'is_final_pay_checklist' => $request->boolean('is_final_pay_checklist'),
            ]);

            $checklistTemplate->items()->delete();

            foreach ($validated['items'] as $index => $item) {
                $checklistTemplate->items()->create([
                    'title' => $item['title'],
                    'signatory_id' => $item['signatory_id'] ?: null,
                    'sort_order' => $index,
                ]);
            }
        });

        return redirect()->route('checklist-templates.index')->with('success', 'Checklist template updated.');
    }

    public function destroy(ChecklistTemplate $checklistTemplate): RedirectResponse
    {
        $checklistTemplate->delete();

        return redirect()->route('checklist-templates.index')->with('success', 'Checklist template deleted.');
    }

    public function toggleStatus(ChecklistTemplate $checklistTemplate): RedirectResponse
    {
        $checklistTemplate->update(['is_active' => ! $checklistTemplate->is_active]);

        return redirect()->route('checklist-templates.index')
            ->with('success', 'Checklist template marked as ' . ($checklistTemplate->is_active ? 'active' : 'inactive') . '.');
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
