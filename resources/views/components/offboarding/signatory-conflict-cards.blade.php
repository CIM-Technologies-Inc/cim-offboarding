{{--
    Clearance Signatory conflict warning + one full-width card per affected
    checklist — shown directly below the Immediate Head field (see
    new-request-modal.blade.php) the moment the selected offboardee turns
    out to be the Clearance Signatory of record on one or more OTHER,
    still-open offboarding requests' checklists.

    Deliberately declares NO `x-data` of its own: it's @include'd straight
    inside new-request-modal.blade.php's own outer wrapper x-data (the same
    one that already holds `setImmediateHeadFromEmployee`), so it reads/
    writes that same `signatoryConflicts`/`eligibleReplacements`/
    `checkingSignatoryConflicts` state directly — same non-isolated-@include
    convention the Immediate Head field block right above it already relies
    on for its own shared `activeDropdown`.
--}}
<div x-show="checkingSignatoryConflicts || signatoryConflicts.length > 0" x-cloak
    {{-- A resolved conflict's card is removed here, reactively, the moment
         the Assign To modal reports success — see
         signatory-conflict-modal.blade.php's own `submitAssignment()`,
         which dispatches this same event. One card per CHECKLIST (see
         `OffboardingRequestController::checkSignatoryConflicts()`'s own
         docblock on why it's deduped server-side this way) — resolving a
         card's "Assign To" action fixes every open instance of that
         checklist in one go, so removing it here by `key` is always
         correct, never partial. --}}
    @signatory-conflict-resolved.window="signatoryConflicts = signatoryConflicts.filter((c) => c.key !== $event.detail.key)">
    <div x-show="checkingSignatoryConflicts"
        class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-400">
        <span class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-solid border-gray-400 border-t-transparent"></span>
        Checking Clearance Signatory assignments...
    </div>

    <template x-if="!checkingSignatoryConflicts && signatoryConflicts.length > 0">
        <div class="space-y-4">
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                <p class="text-sm font-semibold text-amber-800 dark:text-amber-400">Warning: Clearance Signatory Assignment</p>
                <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">
                    The selected employee is currently assigned as a Clearance Signatory for one or more active checklists. Since this employee is being offboarded, please assign a replacement Clearance Signatory for the affected checklist(s).
                </p>
            </div>

            <template x-for="conflict in signatoryConflicts" :key="conflict.key">
                <div class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-theme-xs dark:border-gray-800 dark:bg-white/[0.03] sm:flex-row sm:items-center sm:justify-between sm:gap-6">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-800 dark:text-white/90" x-text="conflict.checklistTitle"></p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            Current Clearance Signatory:
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="conflict.currentSignatoryName"></span>
                        </p>
                    </div>
                    <div class="shrink-0">
                        <button type="button"
                            @click="$dispatch('open-signatory-conflict-modal', Object.assign({}, conflict, { eligibleReplacements: eligibleReplacements }))"
                            class="flex w-full items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630] sm:w-auto">
                            Assign To
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </template>
</div>
