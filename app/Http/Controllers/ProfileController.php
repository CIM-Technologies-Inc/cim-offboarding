<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * Field-level permission enforcement: the validation rules array is
     * built up ONLY from the fields the current user actually holds
     * permission to edit, so a field they lack permission for is never
     * validated and never reaches `$validated` — `$user->update($validated)`
     * physically cannot touch it, regardless of what a crafted request
     * sends. This is the true authority boundary; the "User Profile"
     * permissions' matching field disable/hide logic in
     * `resources/views/components/profile/*` is a courtesy, not the
     * enforcement itself. Fields the browser never submits (a genuinely
     * disabled input) simply have no matching key in the request either
     * way, so this naturally handles both cases identically.
     */
    public function updatePersonalInfo(Request $request): RedirectResponse
    {
        $user = $request->user();

        $rules = [];

        if ($user->can('user-profile.edit-personal-info')) {
            $rules['name'] = ['required', 'string', 'max:255'];
        }

        if ($user->can('user-profile.edit-contact-info')) {
            $rules['email'] = ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)];
            $rules['mobile_number'] = ['nullable', 'string', 'max:30'];
        }

        if ($user->can('user-profile.edit-employment-info')) {
            $rules['position'] = ['nullable', 'string', 'max:255'];
            $rules['department'] = ['nullable', 'string', 'max:255'];
        }

        abort_if(empty($rules), 403, 'You do not have permission to edit any of these fields.');

        $validated = $request->validate($rules);

        $previousValues = collect($validated)->keys()->mapWithKeys(fn ($key) => [$key => $user->$key])->all();

        $user->update($validated);

        ActivityLog::record('employee_profile_updated', 'Users', "{$user->name} updated their personal information.", [
            'subject_type' => 'User',
            'subject_id' => $user->id,
            'old_values' => $previousValues,
            'new_values' => $validated,
        ]);

        return back()->with('success', 'Personal information updated.');
    }

    /**
     * Address is treated as "contact information" for permission purposes —
     * same gate as the email/mobile fields above.
     */
    public function updateAddress(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->can('user-profile.edit-contact-info'), 403);

        $validated = $request->validate([
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $previousAddress = $user->address;

        $user->update($validated);

        ActivityLog::record('employee_profile_updated', 'Users', "{$user->name} updated their address.", [
            'subject_type' => 'User',
            'subject_id' => $user->id,
            'old_values' => ['address' => $previousAddress],
            'new_values' => $validated,
        ]);

        return back()->with('success', 'Address updated.');
    }

    /**
     * Uploads (or replaces) the user's electronic signature. The old file is
     * deleted once the new one is safely stored, so a failed upload never
     * leaves the user without their previous signature.
     */
    public function updateSignature(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->can('user-profile.edit-signature'), 403);

        $validated = $request->validate([
            'signature' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:1900'],
        ]);

        $path = $validated['signature']->store('signatures', 'public');

        $previousPath = $user->signature_path;

        $user->update(['signature_path' => $path]);

        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }

        ActivityLog::record('employee_profile_updated', 'Users', "{$user->name} uploaded a new e-signature.", [
            'subject_type' => 'User',
            'subject_id' => $user->id,
            'old_values' => ['signature_path' => $previousPath],
            'new_values' => ['signature_path' => $path],
        ]);

        return back()->with('success', 'E-signature uploaded.');
    }

    public function removeSignature(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->can('user-profile.edit-signature'), 403);

        if ($user->signature_path) {
            $previousPath = $user->signature_path;
            Storage::disk('public')->delete($user->signature_path);
            $user->update(['signature_path' => null]);

            ActivityLog::record('employee_profile_updated', 'Users', "{$user->name} removed their e-signature.", [
                'subject_type' => 'User',
                'subject_id' => $user->id,
                'old_values' => ['signature_path' => $previousPath],
                'new_values' => ['signature_path' => null],
            ]);
        }

        return back()->with('success', 'E-signature removed.');
    }

    /**
     * Uploads (or replaces) the user's profile photo — same convention as
     * `updateSignature()` above: the old file is deleted only once the new
     * one is safely stored, so a failed upload never leaves the user
     * without their previous photo.
     */
    public function updateProfilePhoto(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->can('user-profile.edit-photo'), 403);

        $validated = $request->validate([
            'profile_photo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ]);

        $path = $validated['profile_photo']->store('profile-photos', 'public');

        $previousPath = $user->profile_photo_path;

        $user->update(['profile_photo_path' => $path]);

        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }

        ActivityLog::record('employee_profile_updated', 'Users', "{$user->name} updated their profile photo.", [
            'subject_type' => 'User',
            'subject_id' => $user->id,
            'old_values' => ['profile_photo_path' => $previousPath],
            'new_values' => ['profile_photo_path' => $path],
        ]);

        return back()->with('success', 'Profile photo updated.');
    }

    public function removeProfilePhoto(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->can('user-profile.edit-photo'), 403);

        if ($user->profile_photo_path) {
            $previousPath = $user->profile_photo_path;
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->update(['profile_photo_path' => null]);

            ActivityLog::record('employee_profile_updated', 'Users', "{$user->name} removed their profile photo.", [
                'subject_type' => 'User',
                'subject_id' => $user->id,
                'old_values' => ['profile_photo_path' => $previousPath],
                'new_values' => ['profile_photo_path' => null],
            ]);
        }

        return back()->with('success', 'Profile photo removed.');
    }
}
