{{--
    Assign Replacement Clearance Signatory modal — opened from the "Assign
    To" button on a signatory-conflict-cards.blade.php card. Modeled on
    resources/views/components/approvals/assign-item-modal.blade.php's own
    searchable-employee-picker/modal-shell conventions, but submits via
    fetch() rather than a real <form> POST: this modal is reached from
    INSIDE the still-open, not-yet-submitted New Offboarding Request modal
    (see new-request-modal.blade.php), and a real form navigation/redirect
    would discard whatever the admin has already typed into that form.
    Same fetch()/SweetAlert2 `preConfirm`/`showLoaderOnConfirm` pattern as
    resources/views/pages/offboardees/partials/retract-button.blade.php.
--}}
<div x-data="{
        selected: null,
        selectedReplacementId: '',
        query: '',
        employeeDropdownOpen: false,
        processing: false,
        setSelected(detail) {
            this.selected = detail;
            this.selectedReplacementId = detail?.defaultReplacementId ? String(detail.defaultReplacementId) : '';
            this.query = detail?.defaultReplacementLabel || '';
            this.employeeDropdownOpen = false;
            this.processing = false;
        },
        filteredReplacements() {
            const needle = this.query.toLowerCase();
            const list = this.selected?.eligibleReplacements || [];
            if (!needle) {
                return list;
            }
            return list.filter((e) =>
                e.name.toLowerCase().includes(needle)
                || e.code.toLowerCase().includes(needle)
            );
        },
        selectReplacement(employee) {
            this.selectedReplacementId = String(employee.id);
            this.query = `${employee.name} (${employee.code})`;
            this.employeeDropdownOpen = false;
        },
        // Permanent and Temporary are fundamentally different operations,
        // not two branches of the same POST:
        // - Permanent still calls `resolveSignatoryConflict()` immediately
        //   (updates `ChecklistTemplate.department_head_id` right now, plus
        //   the live instance if `approverId` is present) — unchanged.
        // - Temporary is a PURE client-side choice: there is nothing to
        //   persist yet (no `OffboardingRequestApprover` row may even exist
        //   — see signatory-conflict-cards.blade.php's own docblock on why
        //   `approverId` can be null). It's recorded into the New Request
        //   form's own `temporaryOverrides` state (a window event, same
        //   cross-component convention `setImmediateHeadFromEmployee`
        //   already uses) and only actually applied server-side when that
        //   form is submitted — see `OffboardingRequestController::store()`'s
        //   `temporary_signatory_overrides` handling.
        async submitAssignment(type) {
            if (!this.selectedReplacementId || this.processing) {
                return;
            }
            const typeLabel = type === 'permanent' ? 'Permanent' : 'Temporary';
            const description = type === 'permanent'
                ? 'Updates the affected active checklist\'s Clearance Signatory to the selected Group Head. The new assignment will apply to future offboarding requests using this checklist. All task-level assignees on the affected checklist will be cleared as part of the reassignment.'
                : 'Assigns the selected Group Head as the Clearance Signatory for this offboarding process only. The existing checklist template\'s default Clearance Signatory remains unchanged for future offboarding requests. All task-level assignees on the affected checklist will be cleared for this offboarding process.';
            const templateId = this.selected.templateId;
            const approverId = this.selected.approverId;
            const replacementId = this.selectedReplacementId;
            const replacementLabel = this.query;
            const checklistTitle = this.selected.checklistTitle;

            if (type === 'temporary') {
                const confirmResult = await Swal.fire({
                    title: 'Temporary Assignment?',
                    text: description,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Assign (Temporary)',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#145a3a',
                    cancelButtonColor: '#6b7280',
                    reverseButtons: true,
                });

                if (!confirmResult.isConfirmed) {
                    return;
                }

                window.dispatchEvent(new CustomEvent('close-signatory-conflict-modal'));
                window.dispatchEvent(new CustomEvent('signatory-conflict-resolved', { detail: { templateId } }));
                window.dispatchEvent(new CustomEvent('signatory-temporary-override-added', {
                    detail: { templateId, replacementId, replacementLabel, checklistTitle },
                }));

                Swal.fire({
                    icon: 'success',
                    title: 'Will Be Applied on Submit',
                    text: `'${checklistTitle}' will be temporarily assigned to ${replacementLabel} when this offboarding request is submitted.`,
                    confirmButtonColor: '#145a3a',
                });

                return;
            }

            const result = await Swal.fire({
                title: `${typeLabel} Assignment?`,
                text: description,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: `Yes, Assign (${typeLabel})`,
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#145a3a',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
                // Stays open on failure, with the error shown inline and
                // every selection preserved, per spec — only a genuine
                // success closes this dialog (and, below, the modal itself).
                showLoaderOnConfirm: true,
                preConfirm: async () => {
                    this.processing = true;
                    try {
                        const response = await fetch(`{{ url('/offboarding-requests/signatory-conflicts') }}/${templateId}/resolve`, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                replacement_employee_id: this.selectedReplacementId,
                                assignment_type: type,
                                expected_current_signatory_id: this.selected.currentSignatoryId,
                                offboarding_request_approver_id: approverId,
                            }),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            const fieldError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                            throw new Error(fieldError || data.message || 'Reassignment failed. Please try again.');
                        }
                        return data;
                    } catch (error) {
                        Swal.showValidationMessage(error.message);
                        return false;
                    } finally {
                        this.processing = false;
                    }
                },
            });

            if (!result.isConfirmed) {
                return;
            }

            // Closes this modal (its `open` flag lives on x-ui.modal's own
            // internal x-data, a DESCENDANT scope this method can't reach
            // directly — same reasoning new-request-modal.blade.php's own
            // `setImmediateHeadFromEmployee` already documents for the
            // identical problem) and tells the cards partial to drop this
            // now-resolved conflict from its list, both via window events.
            window.dispatchEvent(new CustomEvent('close-signatory-conflict-modal'));
            window.dispatchEvent(new CustomEvent('signatory-conflict-resolved', { detail: { templateId } }));

            Swal.fire({
                icon: 'success',
                title: 'Reassigned',
                text: result.value.message || 'Clearance Signatory reassigned.',
                confirmButtonColor: '#145a3a',
            });
        },
    }" @open-signatory-conflict-modal.window="setSelected($event.detail)">
    <x-ui.modal @open-signatory-conflict-modal.window="open = true"
        @close-signatory-conflict-modal.window="open = false"
        :isOpen="false" class="max-w-[830px]">
        <div class="no-scrollbar relative flex max-h-[85vh] w-full max-w-[830px] flex-col overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
                    <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90">Assign Replacement Clearance Signatory</h4>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-text="selected.checklistTitle"></p>

                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Select Replacement Group Head
                    </label>
                    <div class="relative" @click.away="employeeDropdownOpen = false">
                        <input type="text" x-model="query" autocomplete="off"
                            @focus="employeeDropdownOpen = true"
                            @input="selectedReplacementId = ''; employeeDropdownOpen = true"
                            placeholder="Search Group Head..."
                            class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />

                        <div x-show="employeeDropdownOpen"
                            class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                            <template x-for="employee in filteredReplacements()" :key="employee.id">
                                <div @click="selectReplacement(employee)"
                                    class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                    <span class="text-gray-800 dark:text-white/90" x-text="`${employee.name} (${employee.code})`"></span>
                                    <span class="block text-xs text-gray-400" x-text="employee.department"></span>
                                </div>
                            </template>
                            <div x-show="filteredReplacements().length === 0" class="px-4 py-2.5 text-sm text-gray-400">
                                No eligible Group Heads found
                            </div>
                        </div>
                    </div>
                    <p class="mt-1.5 text-xs text-gray-400" x-show="selected.defaultReplacementLabel">
                        Defaulted to this employee's own Group Head. You may choose a different eligible Group Head instead.
                    </p>
                    <p class="mt-1.5 text-xs text-gray-400" x-show="!selected.defaultReplacementLabel">
                        This employee's own group has no Group Head on file — please select an eligible replacement.
                    </p>

                    <div class="mt-5 space-y-3">
                        {{-- Always available, regardless of whether this card
                             represents an existing open checklist instance
                             (`approverId` present) or a template-only
                             conflict (`approverId` null — the employee is
                             still a template's configured default signatory,
                             but nothing currently open uses it). Either way,
                             Temporary applies ONLY to the new offboarding
                             request about to be created: see
                             `OffboardingRequestController::store()`'s own
                             `temporary_signatory_overrides` handling. --}}
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.03]">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Temporary Assignment</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Assigns the selected Group Head as the Clearance Signatory for this offboarding process only. The existing checklist template's default Clearance Signatory remains unchanged for future offboarding requests. All task-level assignees on the affected checklist will be cleared for this offboarding process.
                            </p>
                            <button type="button" @click="submitAssignment('temporary')"
                                :disabled="!selectedReplacementId || processing"
                                :class="(!selectedReplacementId || processing) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
                                class="mt-3 flex w-full items-center justify-center rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                Temporary
                            </button>
                        </div>

                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.03]">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Permanent Assignment</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Updates the affected active checklist's Clearance Signatory to the selected Group Head. The new assignment will apply to future offboarding requests using this checklist. All task-level assignees on the affected checklist will be cleared as part of the reassignment.
                            </p>
                            <button type="button" @click="submitAssignment('permanent')"
                                :disabled="!selectedReplacementId || processing"
                                :class="(!selectedReplacementId || processing) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                class="mt-3 flex w-full items-center justify-center rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white">
                                Permanent
                            </button>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-end">
                        <button @click="open = false" type="button" :disabled="processing"
                            class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                            Cancel
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>
