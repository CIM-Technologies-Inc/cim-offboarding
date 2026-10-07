<?php

namespace App\Http\Controllers;

use App\Mail\SmtpTestMail;
use App\Models\ActivityLog;
use App\Models\SmtpSetting;
use App\Services\MailConfigurator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The SMTP Settings module — a singleton settings record (see
 * `SmtpSetting`'s own docblock), applied at runtime by
 * `MailConfigurator`. The real password is NEVER passed to a view from
 * here — only a `hasPassword` boolean — and NEVER included in anything
 * logged via `ActivityLog::record()` below.
 */
class SmtpSettingController extends Controller
{
    public function edit(): View
    {
        $setting = SmtpSetting::current();

        return view('pages.smtp-settings.edit', [
            'title' => 'Mail Settings',
            'setting' => $setting,
            'hasPassword' => (bool) $setting?->smtp_password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $this->validateSettings($request);

        $setting = SmtpSetting::current();
        $isNew = $setting === null;
        $wasActive = (bool) $setting?->is_active;

        $data = $validated;

        // Blank password field means "keep the existing one" — never
        // overwrite a real, already-encrypted password with an empty
        // value just because the admin didn't retype it.
        if (empty($data['smtp_password'])) {
            unset($data['smtp_password']);
        }

        $data['updated_by'] = $request->user()->id;

        if ($isNew) {
            $data['created_by'] = $request->user()->id;
            $setting = SmtpSetting::create($data);
        } else {
            $setting->update($data);
        }

        $this->logSettingsChange($isNew ? 'smtp_settings_created' : 'smtp_settings_updated', $setting, $validated);

        if ($wasActive !== $setting->is_active) {
            ActivityLog::record($setting->is_active ? 'smtp_enabled' : 'smtp_disabled', 'Mail Settings', $setting->is_active
                ? 'SMTP configuration enabled.'
                : 'SMTP configuration disabled.', [
                'subject_type' => 'SmtpSetting',
                'subject_id' => $setting->id,
            ]);
        }

        return back()->with('success', 'SMTP settings have been successfully saved.');
    }

    public function testConnection(Request $request): JsonResponse
    {
        $settings = $this->resolveTestSettings($request);

        $result = MailConfigurator::testConnection($settings);

        ActivityLog::record('smtp_connection_tested', 'Mail Settings', 'SMTP connection test '.($result['success'] ? 'succeeded' : 'failed').'.', [
            'status' => $result['success'] ? 'success' : 'failed',
        ]);

        return response()->json($result);
    }

    public function sendTestEmail(Request $request): JsonResponse
    {
        $request->validate([
            'test_email' => ['required', 'email'],
        ]);

        $settings = $this->resolveTestSettings($request);

        MailConfigurator::apply($settings);

        try {
            Mail::to($request->input('test_email'))->send(new SmtpTestMail());

            ActivityLog::record('smtp_test_email_sent', 'Mail Settings', "Test email sent to {$request->input('test_email')}.", [
                'status' => 'success',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Test email sent successfully to {$request->input('test_email')}.",
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send SMTP test email.', [
                'recipient' => $request->input('test_email'),
                'exception' => $e->getMessage(),
            ]);

            ActivityLog::record('smtp_test_email_sent', 'Mail Settings', 'Failed to send test email.', [
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send the test email. Please verify the SMTP host, port, encryption, username, password, and authentication settings.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSettings(Request $request): array
    {
        // A checkbox sends nothing at all when unchecked — merging the
        // coerced boolean BEFORE validating ensures "turn this off" is
        // actually captured, rather than silently leaving the column
        // untouched because the key was missing from the request.
        $request->merge([
            'smtp_authentication' => $request->boolean('smtp_authentication'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return $request->validate([
            'mail_driver' => ['required', Rule::in(['smtp', 'log', 'sendmail', 'array'])],
            'smtp_host' => ['required_if:mail_driver,smtp', 'nullable', 'string', 'max:255'],
            'smtp_port' => ['required_if:mail_driver,smtp', 'nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['required', Rule::in(['none', 'tls', 'ssl'])],
            'smtp_authentication' => ['boolean'],
            'smtp_username' => ['required_if:smtp_authentication,true', 'nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            'from_email' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * Resolves the settings array the Test Connection / Send Test Email
     * actions use — draft form values, with a blank password falling
     * back to the already-saved encrypted one (so testing a saved
     * config never requires retyping the password), same semantics as
     * `update()`'s own blank-password rule.
     *
     * @return array<string, mixed>
     */
    private function resolveTestSettings(Request $request): array
    {
        $validated = $this->validateSettings($request);
        $existing = SmtpSetting::current();

        if (empty($validated['smtp_password']) && $existing?->smtp_password) {
            $validated['smtp_password'] = $existing->smtp_password;
        }

        return $validated;
    }

    /**
     * Never includes `smtp_password`/`smtp_username` in the logged
     * payload, regardless of what changed — the audit trail records
     * THAT settings changed, never the credentials themselves.
     *
     * @param  array<string, mixed>  $validated
     */
    private function logSettingsChange(string $action, SmtpSetting $setting, array $validated): void
    {
        $safeValues = collect($validated)->except(['smtp_password', 'smtp_username'])->all();
        $safeValues['password_changed'] = ! empty($validated['smtp_password']);

        ActivityLog::record($action, 'Mail Settings', $action === 'smtp_settings_created'
            ? 'SMTP settings created.'
            : 'SMTP settings updated.', [
            'subject_type' => 'SmtpSetting',
            'subject_id' => $setting->id,
            'new_values' => $safeValues,
        ]);
    }
}
