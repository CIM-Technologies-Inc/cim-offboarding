@props(['emailTemplates'])

{{-- "Reactivate This Account" modal — opened via a window event carrying
     one row's data (see the "Reactivate" button in pages/users/index.blade.php),
     same convention as components/approvals/general-signatory-modal.blade.php.
     Reviewing the employee info + chosen template before clicking the single
     Reactivate button IS the confirmation step — no separate native confirm
     dialog on top of it. --}}
<div x-data="{
    selected: null,
    emailTemplates: @js($emailTemplates),
    selectedTemplateId: null,
    showEmailPicker: false,
    processing: false,
    defaultTemplateId() {
        const defaultTemplate = this.emailTemplates.find((t) => t.is_default_reactivation);
        return defaultTemplate ? defaultTemplate.id : (this.emailTemplates[0]?.id ?? null);
    },
    templateNameFor(id) {
        return this.emailTemplates.find((t) => t.id === id)?.template_name ?? '';
    },
}" @open-reactivate-modal.window="selected = $event.detail; selectedTemplateId = defaultTemplateId(); showEmailPicker = false; processing = false">
    <x-ui.modal x-data="{ open: false }" @open-reactivate-modal.window="open = true" @close-reactivate-modal.window="open = false" :isOpen="false" class="w-full sm:max-w-[520px]">
        <div class="no-scrollbar relative max-h-[85vh] w-full overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
                    <h4 class="mb-1 text-xl font-semibold text-gray-800 dark:text-white/90">Reactivate This Account</h4>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                        Restore login access for this employee and notify them by email.
                    </p>

                    {{-- Employee information + Account Status --}}
                    <div class="mb-5 space-y-1.5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Employee</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.name"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Employee No.</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.employeeCode"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Username</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.username"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Email</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.email || '—'"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Account Status</span>
                            <span class="rounded-full bg-error-50 px-2 py-0.5 text-[11px] font-medium text-error-700 dark:bg-error-500/15 dark:text-error-400">
                                Blocked
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Blocked Since</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.blockedAt"></span>
                        </div>
                    </div>

                    <form method="POST" :action="selected.reactivateUrl" @submit="processing = true">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="email_template_id" :value="selectedTemplateId">
                        <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
                            <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Cancel
                            </button>
                            <button type="submit" :disabled="processing" data-turbo-submits-with="Reactivating..."
                                :class="processing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white">
                                <span x-show="processing" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-white border-t-transparent"></span>
                                <span x-text="processing ? 'Reactivating...' : 'Reactivate'"></span>
                            </button>
                            <button type="button" title="Choose Email Template" @click="showEmailPicker = !showEmailPicker"
                                class="rounded-lg border border-gray-300 p-2.5 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                <svg width="14" height="14" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M2.25 5.25C2.25 4.42157 2.92157 3.75 3.75 3.75H14.25C15.0784 3.75 15.75 4.42157 15.75 5.25V12.75C15.75 13.5784 15.0784 14.25 14.25 14.25H3.75C2.92157 14.25 2.25 13.5784 2.25 12.75V5.25Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                    <path d="M2.75 5L9 9.75L15.25 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>

                        {{-- Email template picker — collapsed by default, same
                             "icon toggles a hidden panel" convention
                             components/offboarding/status-timeline-modal.blade.php
                             already uses for its own "Notify Approver" action.
                             `selectedTemplateId` is already defaulted to the
                             default reactivation template the moment the modal
                             opens, so reactivation uses the correct template
                             even if this panel is never opened. --}}
                        <div x-show="showEmailPicker" x-cloak class="mt-3 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-1">
                            <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Select Email Template</label>
                            <template x-if="emailTemplates.length">
                                <select x-model.number="selectedTemplateId"
                                    class="dark:bg-dark-900 h-9 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-3 text-xs text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                    <template x-for="tmpl in emailTemplates" :key="tmpl.id">
                                        <option :value="tmpl.id" x-text="tmpl.template_name"></option>
                                    </template>
                                </select>
                            </template>
                            <p x-show="!emailTemplates.length" class="text-xs text-error-500">
                                No email templates are available — the account will still be reactivated, but no notification email will be sent.
                            </p>
                            <p x-show="emailTemplates.length && selectedTemplateId" class="mt-1.5 text-xs text-gray-400">
                                Will notify using: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="templateNameFor(selectedTemplateId)"></span>
                            </p>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>
