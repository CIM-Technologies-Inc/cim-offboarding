export function offboardingRequestForm(flashSuccess = null, flashError = null, hasErrors = false) {
  return {
    submitting: false,
    // Set true only by the Review panel's own "Submit Request" button,
    // right before it calls the real form's `requestSubmit()` — the
    // `@submit` handler in new-request-modal.blade.php lets a submit
    // through only when this is already true, so the form can never
    // actually POST without first having gone through Review (a stray
    // native Enter-key submit, for instance, is blocked the same way the
    // old inline confirm dialog used to block it — the Review step now
    // serves as that same gate, just richer).
    confirmed: false,
    // Whether this page load's `old(...)`/`@error(...)` values (from a
    // just-failed submission) are still showing — reachable from every
    // nested field island below via Alpine's ancestor walk, so a single
    // `x-show="hasErrors"` on each `@error(...)` paragraph anywhere in this
    // form can all be suppressed together by resetForm() below, with no
    // per-field wiring needed. Starts matching the server-computed
    // `$offboardingRequestHasErrors` so nothing changes for a normal
    // failed-validation reopen.
    hasErrors,
    // "Review Offboarding Request" state — a content swap within this same
    // modal (not a second stacked modal), toggled once the read-only
    // preview endpoint responds successfully. `reviewData` holds the exact
    // JSON `OffboardingRequestController::preview()` returns.
    reviewing: false,
    reviewLoading: false,
    reviewError: null,
    reviewData: null,
    init() {
      if (flashSuccess) {
        this.notify('success', flashSuccess);
      } else if (flashError) {
        this.notify('error', flashError);
      }
    },
    notify(icon, title) {
      window.Swal?.fire({
        toast: true,
        position: 'bottom-end',
        icon,
        title,
        showConfirmButton: false,
        timer: icon === 'success' ? 2000 : 2500,
        timerProgressBar: icon === 'success',
        customClass: { container: 'app-toast' },
      });
    },
    // "Review Request" — the form's own native validation runs first
    // (required fields, same as a normal submit would enforce), then the
    // CURRENT form values are sent to the read-only preview endpoint via
    // fetch(). Nothing is saved by this call — see
    // `OffboardingRequestController::preview()`'s own docblock. On success,
    // swaps this modal's content from the form to the Review panel; the
    // form's own fields are never touched, so Back to Edit always shows
    // exactly what was already typed.
    async reviewRequest(formEl, previewUrl) {
      if (!formEl || !formEl.reportValidity()) {
        return;
      }

      this.reviewLoading = true;
      this.reviewError = null;

      try {
        const response = await fetch(previewUrl, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
          body: new FormData(formEl),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
          const fieldError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
          throw new Error(fieldError || data.message || 'Could not build the request review. Please check the form and try again.');
        }

        this.reviewData = data;
        this.reviewing = true;
      } catch (error) {
        this.notify('error', error.message || 'Could not build the request review. Please try again.');
      } finally {
        this.reviewLoading = false;
      }
    },
    // Back to Edit — the form fields were never cleared or resubmitted by
    // reviewRequest() above (it only ever READ them via FormData), so
    // everything the admin already typed is exactly as they left it.
    backToEdit() {
      this.reviewing = false;
    },
    // Submit Request, from the Review panel — the real submission. Flips
    // `confirmed` so the form's own `@submit` handler lets this specific
    // submit through, then fires the native submit itself.
    confirmSubmit(formEl) {
      this.confirmed = true;
      this.submitting = true;
      formEl.requestSubmit();
    },
    // Whether the Review panel's own Submit Request button should be
    // disabled — any `critical` warning (e.g. an unresolved Clearance
    // Signatory conflict, no active Final Approver) blocks submission
    // client-side, mirroring what the server already independently rejects
    // in `store()` regardless.
    hasCriticalWarnings() {
      return !!this.reviewData?.warnings?.some((warning) => warning.critical);
    },
    // Close button: returns the WHOLE form to a genuinely blank state —
    // not just this scope's own submitting/confirmed/hasErrors, but every
    // nested field island's own local state too, none of which is
    // reachable from here directly (each is its own isolated Alpine
    // component — see their own comments on why). Dispatches a single
    // window event every one of them listens for and resets itself in
    // response to, the same convention `immediate-head-auto-select`/
    // `set-date-{id}` already use elsewhere in this same form.
    resetForm() {
      this.submitting = false;
      this.confirmed = false;
      this.hasErrors = false;
      this.reviewing = false;
      this.reviewLoading = false;
      this.reviewError = null;
      this.reviewData = null;
      window.dispatchEvent(new CustomEvent('reset-offboarding-request-fields'));
    },
  };
}

export default offboardingRequestForm;
