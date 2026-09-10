<?php

namespace App\Http\Controllers;

use App\Models\SeparationType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Separation Type Management — admin CRUD for the categories offered on the
 * New Offboarding Request form's "Separation Type" picker (see
 * `OffboardingRequestController::store()`, which snapshots a type's title/
 * description/default notice period onto the request at creation time, and
 * never re-reads a `SeparationType` row afterward). Editing or deleting a
 * row here is always safe for already-created requests precisely because of
 * that snapshot — see `SeparationType`'s own docblock.
 */
class SeparationTypeController extends Controller
{
    public function index(): View
    {
        return view('pages.separation-types.index', [
            'title' => 'Separation Types',
            'separationTypes' => SeparationType::orderBy('title')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255', Rule::unique('separation_types', 'title')],
            'description' => ['required', 'string'],
            'default_notice_period_days' => ['required', 'integer', 'min:0'],
        ]);

        SeparationType::create($validated);

        return back()->with('success', 'Separation type created.');
    }

    public function update(Request $request, SeparationType $separationType): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255', Rule::unique('separation_types', 'title')->ignore($separationType->id)],
            'description' => ['required', 'string'],
            'default_notice_period_days' => ['required', 'integer', 'min:0'],
        ]);

        // Deliberately never touches any existing `OffboardingRequest` row —
        // each one already has its own frozen `reason`/
        // `separation_type_description`/`notice_period_days` snapshot from
        // whenever it was created, per this feature's own requirement that
        // later config changes must never alter historical requests.
        $separationType->update($validated);

        return back()->with('success', 'Separation type updated.');
    }

    public function destroy(SeparationType $separationType): RedirectResponse
    {
        $title = $separationType->title;

        // Safe even if past requests reference this row — `separation_type_id`
        // simply nulls out (see the FK's `nullOnDelete()`), while each
        // request's own frozen `reason`/`separation_type_description`/
        // `notice_period_days` snapshot is completely unaffected.
        $separationType->delete();

        return back()->with('success', 'Separation type "' . $title . '" deleted.');
    }
}
