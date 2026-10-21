{{--
    "Complete Offboarding" (on behalf of the Final Approver) confirmation
    modal — triggered via the `open-complete-on-behalf-modal` window event
    dispatched from `pages.offboardees.partials.final-approval-button`.
    Included once on the Offboardees index page rather than duplicated per
    card.

    The rich state (`url`/`finalSignatoryName`/etc.) MUST live on this
    outer wrapping div, never crammed into `<x-ui.modal>`'s own `x-data`
    attribute — that component already hardcodes its own `x-data="{ open:
    ..., init() {...} }"` on its root element, and Blade's `{{ $attributes
    }}` spread then appends a SECOND `x-data="..."` attribute onto that
    same tag. HTML silently keeps only the FIRST of two same-named
    attributes on one element, so anything added only via the caller's
    `x-data` (a second occurrence) is discarded before Alpine ever sees it
    — `open` kept working (it's also a key on the component's own built-in
    x-data), but `url`/etc. never became real reactive state, so
    `:action="url"` on the form below resolved to nothing and it silently
    fell back to submitting the current page. Same two-layer shape already
    used correctly by `status-timeline-modal.blade.php`: the outer div owns
    all custom state, `<x-ui.modal>` gets only the trivial `{ open: false }`
    override it needs for show/hide.
--}}
<div x-data="{
        url: null,
        finalSignatoryName: '',
        offboardeeName: '',
        hasSignature: true,
        submitting: false,
    }"
    @open-complete-on-behalf-modal.window="
        url = $event.detail.url;
        finalSignatoryName = $event.detail.finalSignatoryName;
        offboardeeName = $event.detail.offboardeeName;
        hasSignature = $event.detail.hasSignature;
        submitting = false;
    ">
    <x-ui.modal x-data="{ open: false }" @open-complete-on-behalf-modal.window="open = true" :isOpen="false" class="max-w-[520px]">
        <div class="relative w-full max-w-[520px] rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8">
            <h4 class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">Complete Offboarding</h4>
            <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                You are about to complete the Final Approval process — and the entire offboarding request — on behalf of
                <strong class="text-gray-700 dark:text-gray-300" x-text="finalSignatoryName"></strong>
                for <strong class="text-gray-700 dark:text-gray-300" x-text="offboardeeName"></strong>.
                This will attach <span x-text="finalSignatoryName"></span>'s own signature to the Clearance Form.
                The action will be recorded under your name as the one who performed it — not as if
                <span x-text="finalSignatoryName"></span> personally logged in or approved it themselves.
            </p>

            <form method="POST" :action="url" enctype="multipart/form-data" @submit="submitting = true">
                @csrf

                <template x-if="!hasSignature">
                    <div class="mb-4">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Upload <span x-text="finalSignatoryName"></span>'s e-signature <span class="text-error-500">*</span>
                        </label>
                        <input type="file" name="signature" accept=".png,.jpg,.jpeg" required
                            class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-[#145a3a]/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-[#145a3a] hover:file:bg-[#145a3a]/20 dark:text-gray-400 dark:file:bg-[#3aa876]/15 dark:file:text-[#3aa876]" />
                        <p class="mt-1.5 text-xs text-gray-400">
                            <span x-text="finalSignatoryName"></span> has no signature on file yet. Upload one now (PNG or JPG, up to 1.9MB) —
                            it will be saved to their profile, same as if they'd uploaded it themselves.
                        </p>
                    </div>
                </template>
                <template x-if="hasSignature">
                    <p class="mb-4 text-xs text-gray-400">
                        Will use <span x-text="finalSignatoryName"></span>'s e-signature already on file — no upload needed.
                    </p>
                </template>

                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Remarks <span class="text-gray-400">(optional — why this is being completed on their behalf)</span>
                </label>
                <textarea name="remarks" rows="3" maxlength="2000"
                    placeholder="e.g. Final Approver is on extended leave and unreachable."
                    class="h-auto w-full rounded-xl border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"></textarea>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" @click="open = false" :disabled="submitting"
                        class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                        Cancel
                    </button>
                    {{-- Spinner swapped in via Alpine (not Turbo's own
                         `data-turbo-submits-with`, which only replaces the
                         button's text and would otherwise fight this same
                         element's content) — same two-icon x-show pattern
                         `retract-button.blade.php`'s own loading state
                         already uses elsewhere on this page. --}}
                    <button type="submit" :disabled="submitting"
                        class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630] disabled:cursor-not-allowed disabled:opacity-60">
                        <svg x-show="submitting" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span x-text="submitting ? 'Completing...' : 'Complete Offboarding'"></span>
                    </button>
                </div>
            </form>
        </div>
    </x-ui.modal>
</div>
