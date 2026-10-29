{{-- "Offboardee is still a Task Assignee" gate for the New Offboarding
     Request form. `window.offboardingTaskAssigneeGate.check(employeeId)` is
     called when an offboardee is picked and again on every Review Request
     click. It asks OffboardingRequestController::taskAssigneeConflicts()
     whether that employee still has outstanding tasks on other active
     requests; if so it shows a warning notification (no task list — HR/Admin
     reassigns the tasks from the existing checklists themselves) and returns
     false so the form doesn't proceed. The server re-checks independently in
     preview() and store(), so a failed lookup here (which fails open) can
     never let a conflicting request through. --}}
<script>
    window.offboardingTaskAssigneeGate = window.offboardingTaskAssigneeGate || {
        baseUrl: @js(url('/offboarding-requests/task-assignee-conflicts')),
        // "Manage Checklist Templates" — only for users who may open that
        // page; navigates there in this same tab with a full page load (the
        // unsaved request form is left behind; a retry re-checks).
        manageTemplatesUrl: @js(auth()->user()?->can('offboarding-checklists.view') ? route('checklist-templates.index') : null),
        async check(employeeId) {
            if (!employeeId) return true;
            try {
                const response = await fetch(`${this.baseUrl}/${employeeId}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) return true;
                const data = await response.json();
                if (data.blocked) {
                    const manageUrl = this.manageTemplatesUrl;
                    window.Swal?.fire({
                        icon: 'warning',
                        title: 'Existing task assignments',
                        text: data.message,
                        showConfirmButton: !!manageUrl,
                        confirmButtonText: 'Manage Checklist Templates',
                        confirmButtonColor: '#145a3a',
                        showCancelButton: true,
                        cancelButtonText: manageUrl ? 'Close' : 'OK',
                        reverseButtons: true,
                    }).then((result) => {
                        if (result.isConfirmed && manageUrl) {
                            window.location.assign(manageUrl);
                        }
                    });
                    return false;
                }
                return true;
            } catch (e) {
                return true;
            }
        },
    };
</script>
