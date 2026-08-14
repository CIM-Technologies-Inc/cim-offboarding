<?php

namespace App\Http\Controllers;

use App\Models\DepartmentHead;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DepartmentHeadController extends Controller
{
    public function index(): View
    {
        return view('pages.department-heads.index', [
            'title' => 'Department Heads',
            'departmentHeads' => DepartmentHead::with('employee')->orderBy('department')->get(),
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'department', 'designation']),
            'knownDepartments' => Employee::query()
                ->select('department')
                ->distinct()
                ->orderBy('department')
                ->pluck('department'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'department' => ['required', 'string', 'max:255', 'unique:department_heads,department'],
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        DepartmentHead::create($validated);

        return back()->with('success', 'Department head added.');
    }

    public function update(Request $request, DepartmentHead $departmentHead): RedirectResponse
    {
        $validated = $request->validate([
            'department' => ['required', 'string', 'max:255', 'unique:department_heads,department,' . $departmentHead->id],
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        $departmentHead->update($validated);

        return back()->with('success', 'Department head updated.');
    }

    public function destroy(DepartmentHead $departmentHead): RedirectResponse
    {
        $departmentHead->delete();

        return back()->with('success', 'Department head removed.');
    }
}
