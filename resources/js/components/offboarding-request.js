export function offboardingRequestForm(flashSuccess = null, flashError = null, hasErrors = false) {
  return {
    submitting: false,
    // Guards the confirmation dialog (see `new-request-modal.blade.php`'s
    // `@submit` handler) so it only ever intercepts the FIRST submit
    // attempt — once the admin confirms, this flips true and the form's
    // own `requestSubmit()` re-fires the native submit, which this same
    // guard then lets straight through.
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
      window.dispatchEvent(new CustomEvent('reset-offboarding-request-fields'));
    },
  };
}

export default offboardingRequestForm;
