export function offboardingRequestForm(flashSuccess = null, flashError = null) {
  return {
    submitting: false,
    // Guards the confirmation dialog (see `new-request-modal.blade.php`'s
    // `@submit` handler) so it only ever intercepts the FIRST submit
    // attempt — once the admin confirms, this flips true and the form's
    // own `requestSubmit()` re-fires the native submit, which this same
    // guard then lets straight through.
    confirmed: false,
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
  };
}

export default offboardingRequestForm;
