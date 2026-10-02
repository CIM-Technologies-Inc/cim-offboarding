export function flashToast(flashSuccess = null, flashError = null, flashWarning = null) {
  return {
    init() {
      if (flashSuccess) {
        this.notify('success', flashSuccess);
      } else if (flashError) {
        this.notify('error', flashError);
      } else if (flashWarning) {
        this.notify('warning', flashWarning);
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

export default flashToast;
