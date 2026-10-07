@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Mail Settings" />

    <div x-data="flashToast(@js(session('success')), @js(session('error')))" class="mb-6"></div>

    <div x-data="{
        processingTest: false,
        processingSend: false,
        processingSave: false,
        changingPassword: {{ $hasPassword ? 'false' : 'true' }},
        showPassword: false,
        testEmail: '',
        conn: {
            mail_driver: @js(old('mail_driver', $setting->mail_driver ?? 'smtp')),
            smtp_host: @js(old('smtp_host', $setting->smtp_host ?? '')),
            smtp_port: @js(old('smtp_port', $setting->smtp_port ?? 587)),
            smtp_encryption: @js(old('smtp_encryption', $setting->smtp_encryption ?? 'tls')),
            smtp_username: @js(old('smtp_username', $setting->smtp_username ?? '')),
            smtp_password: '',
            smtp_authentication: {{ old('smtp_authentication', $setting->smtp_authentication ?? true) ? 'true' : 'false' }},
            smtp_timeout: @js(old('smtp_timeout', $setting->smtp_timeout ?? '')),
        },
        csrfToken() {
            return document.querySelector('meta[name=csrf-token]').content;
        },
        testConnection() {
            this.processingTest = true;
            window.fetchWithTimeout(@js(route('smtp-settings.test-connection')), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': this.csrfToken(),
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(this.conn),
            }).then((res) => res.json()).then((data) => {
                this.processingTest = false;
                window.Swal?.fire({
                    icon: data.success ? 'success' : 'error',
                    title: data.success ? 'Connection Successful' : 'Connection Failed',
                    text: data.message,
                    confirmButtonColor: '#145a3a',
                });
            }).catch(() => {
                this.processingTest = false;
                window.Swal?.fire({ icon: 'error', title: 'Request Failed', text: 'Could not reach the server. Please try again.', confirmButtonColor: '#145a3a' });
            });
        },
        sendTestEmail() {
            if (!this.testEmail) {
                window.Swal?.fire({ icon: 'warning', title: 'Enter a test email address', confirmButtonColor: '#145a3a' });
                return;
            }
            this.processingSend = true;
            window.fetchWithTimeout(@js(route('smtp-settings.send-test-email')), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': this.csrfToken(),
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ ...this.conn, test_email: this.testEmail }),
            }).then((res) => res.json()).then((data) => {
                this.processingSend = false;
                window.Swal?.fire({
                    icon: data.success ? 'success' : 'error',
                    title: data.success ? 'Test Email Sent' : 'Send Failed',
                    text: data.message,
                    confirmButtonColor: '#145a3a',
                });
            }).catch(() => {
                this.processingSend = false;
                window.Swal?.fire({ icon: 'error', title: 'Request Failed', text: 'Could not reach the server. Please try again.', confirmButtonColor: '#145a3a' });
            });
        },
    }">
        <form method="POST" action="{{ route('smtp-settings.update') }}"
            class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]"
            @submit="processingSave = true">
            @csrf
            @method('PUT')

            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">SMTP Server Settings</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Status: <span class="font-medium {{ $setting?->is_active ? 'text-[#145a3a] dark:text-[#3aa876]' : 'text-error-600 dark:text-error-400' }}">{{ $setting?->is_active ? 'Active' : 'Inactive' }}</span>
                    </p>
                </div>
                <label class="relative inline-flex cursor-pointer items-center" title="Active / Inactive">
                    <input type="checkbox" name="is_active" class="peer sr-only" @checked(old('is_active', $setting->is_active ?? false)) />
                    <div class="peer h-5 w-9 rounded-full bg-gray-200 transition-colors duration-200 peer-checked:bg-[#145a3a] peer-focus:outline-hidden after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-all after:duration-200 after:content-[''] peer-checked:after:translate-x-4 dark:bg-gray-700"></div>
                </label>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Mail Driver</label>
                    <select name="mail_driver" x-model="conn.mail_driver"
                        class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 text-sm text-gray-800 shadow-sm focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <option value="smtp">SMTP</option>
                        <option value="log">Log (dev only — writes to storage/logs)</option>
                        <option value="sendmail">Sendmail</option>
                        <option value="array">Array (disabled — never actually sends)</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Encryption</label>
                    <select name="smtp_encryption" x-model="conn.smtp_encryption"
                        class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 text-sm text-gray-800 shadow-sm focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <option value="none">None</option>
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        SMTP Host <span class="text-error-500">*</span>
                    </label>
                    <input type="text" name="smtp_host" x-model="conn.smtp_host" placeholder="e.g. smtp.gmail.com"
                        class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        SMTP Port <span class="text-error-500">*</span>
                    </label>
                    <input type="number" name="smtp_port" x-model="conn.smtp_port" placeholder="587"
                        class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Connection Timeout (seconds)</label>
                    <input type="number" name="smtp_timeout" x-model="conn.smtp_timeout" placeholder="e.g. 30"
                        class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                </div>
                <div class="flex items-center gap-2 sm:col-span-2">
                    <input type="checkbox" id="smtp_authentication" name="smtp_authentication" x-model="conn.smtp_authentication"
                        class="h-4 w-4 rounded border-gray-300 text-[#145a3a] accent-[#145a3a] focus:ring-[#145a3a]/40 dark:border-gray-700" />
                    <label for="smtp_authentication" class="text-sm font-medium text-gray-700 dark:text-gray-400">
                        SMTP Authentication Required
                    </label>
                </div>
                <template x-if="conn.smtp_authentication">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">SMTP Username</label>
                        <input type="text" name="smtp_username" x-model="conn.smtp_username" placeholder="username@example.com"
                            class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                    </div>
                </template>
                <template x-if="conn.smtp_authentication">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">SMTP Password</label>
                        <template x-if="!changingPassword">
                            <div class="flex items-center gap-3">
                                <input type="text" value="••••••••••••" disabled
                                    class="h-11 w-full rounded-xl border border-gray-300 bg-gray-50 px-4 text-sm text-gray-500 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-400" />
                                <button type="button" @click="changingPassword = true"
                                    class="shrink-0 rounded-lg border border-gray-300 px-3 py-2.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                    Change Password
                                </button>
                            </div>
                        </template>
                        <template x-if="changingPassword">
                            <div class="relative">
                                <input :type="showPassword ? 'text' : 'password'" name="smtp_password" x-model="conn.smtp_password"
                                    placeholder="Enter new SMTP password"
                                    class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 pr-11 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                                <button type="button" @click="showPassword = !showPassword"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                    <svg x-show="!showPassword" width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1.66667 10C1.66667 10 4.16667 4.16667 10 4.16667C15.8333 4.16667 18.3333 10 18.3333 10C18.3333 10 15.8333 15.8333 10 15.8333C4.16667 15.8333 1.66667 10 1.66667 10Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /><path d="M10 12.5C11.3807 12.5 12.5 11.3807 12.5 10C12.5 8.61929 11.3807 7.5 10 7.5C8.61929 7.5 7.5 8.61929 7.5 10C7.5 11.3807 8.61929 12.5 10 12.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    <svg x-show="showPassword" x-cloak width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 3L17 17M8.5 8.7C8.2 9 8 9.5 8 10C8 11.1 8.9 12 10 12C10.5 12 11 11.8 11.3 11.5M6 6.3C3.5 7.8 2 10 2 10C2 10 4.5 15 10 15C11.5 15 12.7 14.6 13.7 14M16 12.5C17.5 11 18 10 18 10C18 10 15.5 5 10 5C9.5 5 9 5.1 8.5 5.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                </button>
                            </div>
                        </template>
                        @if ($hasPassword)
                            <p class="mt-1 text-xs text-gray-400" x-show="!changingPassword">Leave unchanged to keep the existing password.</p>
                        @endif
                    </div>
                </template>
            </div>

            <div class="mt-8 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Sender Information</h3>
            </div>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        From Email Address <span class="text-error-500">*</span>
                    </label>
                    <input type="email" name="from_email" placeholder="noreply@example.com"
                        value="{{ old('from_email', $setting->from_email ?? '') }}"
                        class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        From Name <span class="text-error-500">*</span>
                    </label>
                    <input type="text" name="from_name" placeholder="CIM OffBoarding"
                        value="{{ old('from_name', $setting->from_name ?? '') }}"
                        class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                </div>
            </div>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                <button type="submit" :disabled="processingSave" data-turbo-submits-with="Saving..."
                    :class="processingSave ? 'opacity-60 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                    class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-5 py-2.5 text-sm font-medium text-white">
                    <span x-show="processingSave" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-white border-t-transparent"></span>
                    <span x-text="processingSave ? 'Saving...' : 'Save SMTP Settings'"></span>
                </button>
                <button type="button" @click="testConnection()" :disabled="processingTest"
                    :class="processingTest ? 'opacity-60 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
                    class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300">
                    <span x-show="processingTest" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-gray-500 border-t-transparent"></span>
                    <span x-text="processingTest ? 'Testing...' : 'Test SMTP Connection'"></span>
                </button>
            </div>
        </form>

        <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Send Test Email</h3>
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                Sends a one-off test message using the settings above (unsaved changes included), to confirm mail actually delivers end-to-end.
            </p>
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[260px] flex-1">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Test Email Address</label>
                    <input type="email" x-model="testEmail" placeholder="admin@example.com"
                        class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                </div>
                <button type="button" @click="sendTestEmail()" :disabled="processingSend"
                    :class="processingSend ? 'opacity-60 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                    class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-5 py-2.5 text-sm font-medium text-white">
                    <span x-show="processingSend" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-white border-t-transparent"></span>
                    <span x-text="processingSend ? 'Sending...' : 'Send Test Email'"></span>
                </button>
            </div>
        </div>
    </div>
@endsection
