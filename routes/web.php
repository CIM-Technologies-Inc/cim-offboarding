<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\OffboardingRequestController;
use App\Http\Controllers\ChecklistTemplateController;
use App\Http\Controllers\OffboardeeController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\ChecklistDelegationController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ClearanceFormController;
use App\Http\Controllers\DepartmentHeadController;
use App\Http\Controllers\OnboardingChecklistTemplateController;
use App\Http\Controllers\EmployeeGroupController;
use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\EmployeeDashboardController;
use App\Http\Controllers\EmployeeFollowUpController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\GeneralSignatoryController;
use App\Http\Controllers\GeneralSignatoryApprovalController;
use App\Http\Controllers\FinalApproverController;
use App\Http\Controllers\FinalApprovalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SeparationTypeController;

// authentication pages
Route::get('/signin', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

// forgot / reset password — public, unauthenticated. Throttled since this
// endpoint sends real email on every submission.
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink'])
    ->middleware('throttle:5,1')
    ->name('password.email');
Route::get('/reset-password/{id}/{token}', [ForgotPasswordController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password/{id}/{token}', [ForgotPasswordController::class, 'reset'])->name('password.update');

// "Approve" link embedded in the Checklist Ready for Department Head
// Approval email — public/unauthenticated, same as the reset-password links
// above, so the Department Head never has to log in first just to click it.
// GET shows a confirmation page (validating the link/checklist state without
// approving anything yet); POST — triggered only by confirming on that page
// — performs the actual approval.
Route::get('/approval/{id}/{token}', [ApprovalController::class, 'showEmailApproval'])->name('approval.show');
Route::post('/approval/{id}/{token}', [ApprovalController::class, 'confirmEmailApproval'])->name('approval.confirm');

// Same public, unauthenticated "Approve" link pattern as above, for the
// General Signatory Offboarding Notification email — see
// GeneralSignatoryApprovalController's docblock. Deliberately its own
// controller/token model/routes, entirely independent of the checklist
// Clearance Signatory pair above.
Route::get('/general-signatory-approval/{id}/{token}', [GeneralSignatoryApprovalController::class, 'showEmailApproval'])->name('general-signatory-approval.show');
Route::post('/general-signatory-approval/{id}/{token}', [GeneralSignatoryApprovalController::class, 'confirmEmailApproval'])->name('general-signatory-approval.confirm');

// Same public, unauthenticated "Approve" link pattern, for the Final
// Approval Request email — see FinalApprovalController's docblock.
Route::get('/final-approval/{id}/{token}', [FinalApprovalController::class, 'showEmailApproval'])->name('final-approval.show');
Route::post('/final-approval/{id}/{token}', [FinalApprovalController::class, 'confirmEmailApproval'])->name('final-approval.confirm');

Route::middleware(['auth', 'password.changed'])->group(function () {

// forced first-time password change — reachable even while
// `must_change_password` is true (EnsurePasswordChanged exempts these two
// route names specifically), unlike everything else in this group.
Route::get('/password/change', [ChangePasswordController::class, 'edit'])->name('password.change');
Route::put('/password/change', [ChangePasswordController::class, 'update'])->name('password.change.update');

// pages shared by every role (admin, approver, employee)
Route::get('/profile', function () {
    return view('pages.profile', ['title' => 'Profile']);
})->name('profile');

Route::patch('/profile/personal-info', [ProfileController::class, 'updatePersonalInfo'])->name('profile.personal-info.update');
Route::patch('/profile/address', [ProfileController::class, 'updateAddress'])->name('profile.address.update');
Route::post('/profile/signature', [ProfileController::class, 'updateSignature'])->name('profile.signature.update');
Route::delete('/profile/signature', [ProfileController::class, 'removeSignature'])->name('profile.signature.destroy');
Route::post('/profile/photo', [ProfileController::class, 'updateProfilePhoto'])->name('profile.photo.update');
Route::delete('/profile/photo', [ProfileController::class, 'removeProfilePhoto'])->name('profile.photo.destroy');

// notifications
Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
Route::post('/notifications/mark-all-read', [NotificationController::class, 'readAll'])->name('notifications.read-all');

// Calendar and Approvals: gated purely by permission now, not by a
// hardcoded admin/approver role check — a custom role (e.g. one created on
// the Roles & Permissions page) reaches these the moment it's granted the
// matching permission, with no dependency on its name. Every route below
// already has its own specific `permission:` middleware, so there is no
// remaining gap left by dropping the old role-name wrapper.
Route::middleware('permission:calendar.view')->group(function () {
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
});

// approvals — "view" covers the index page and every "work on a checklist"
// action (save progress, hold, take over, delegate) so a delegate can do
// their part without needing final-approval capability; "approve" is the
// stricter, additional gate on the routes that actually finalize an
// offboarding request.
Route::middleware('permission:approvals.view')->group(function () {
    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');

    // per-approver display preference: whether multiple checklists for the
    // same offboardee are combined into one card or shown separately — see
    // ApprovalController::groupIntoCombinedApprovals(). Purely a view-layer
    // toggle; never touches the underlying checklist assignments.
    Route::patch('/approvals/display-preference', [ApprovalController::class, 'updateDisplayPreference'])->name('approvals.update-display-preference');

    // checklist delegation (assign to another approver)
    Route::post('/approvals/{offboardingRequestApprover}/assign', [ChecklistDelegationController::class, 'assign'])->name('approvals.assign');
    Route::post('/approvals/{offboardingRequestApprover}/save-progress', [ChecklistDelegationController::class, 'saveProgress'])->name('approvals.save-progress');
    Route::post('/approvals/{offboardingRequestApprover}/items/{checklistItem}/hold', [ChecklistDelegationController::class, 'holdItem'])->name('approvals.items.hold');
    Route::post('/approvals/{offboardingRequestApprover}/items/{checklistItem}/assign', [ChecklistDelegationController::class, 'assignItem'])->name('approvals.items.assign');
    Route::post('/approvals/{offboardingRequestApprover}/items/{checklistItem}/take-over', [ChecklistDelegationController::class, 'takeOverItem'])->name('approvals.items.take-over');

    // combined-card actions: every checklist the same approver is assigned
    // for the same offboarding request, actioned in one request instead of
    // one per checklist template — see ApprovalController::index()'s grouping.
    Route::post('/approvals/group/{offboardingRequest}/{employee}/save-progress', [ChecklistDelegationController::class, 'saveProgressGroup'])->name('approvals.group.save-progress');
    Route::post('/approvals/group/{offboardingRequest}/{employee}/assign', [ChecklistDelegationController::class, 'assignGroup'])->name('approvals.group.assign');
    Route::post('/approvals/group/{offboardingRequest}/{employee}/assign-pool', [ChecklistDelegationController::class, 'assignPool'])->name('approvals.group.assign-pool');
});

Route::middleware('permission:approvals.approve')->group(function () {
    Route::post('/approvals/{offboardingRequestApprover}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{offboardingRequestApprover}/decline', [ApprovalController::class, 'decline'])->name('approvals.decline');
    Route::post('/approvals/group/{offboardingRequest}/{employee}/approve', [ApprovalController::class, 'approveGroup'])->name('approvals.group.approve');

    // General Signatory in-app Submit — same permission gate as the
    // checklist approve actions above, independent controller/model.
    Route::post('/general-signatory-approvals/{generalSignatoryApproval}/approve', [GeneralSignatoryApprovalController::class, 'approve'])->name('general-signatory-approvals.approve');
});

// pages restricted to the employee role — the offboardee's own self-service
// dashboard, scoped exclusively to their own offboarding request. Identity-
// scoped, not an admin-toggleable module, so this stays role-gated only
// (no matching permission — every Employee-role account always has it).
Route::middleware('role:employee')->group(function () {

Route::get('/employee/dashboard', [EmployeeDashboardController::class, 'index'])->name('employee.dashboard');
Route::post('/employee/checklists/{offboardingRequestApprover}/follow-up', [EmployeeFollowUpController::class, 'store'])->name('employee.follow-up');

});

// Every route below is gated purely by permission now, not by a hardcoded
// "admin" role check — a custom role reaches whichever of these it's been
// granted the matching permission for, with no dependency on its name.
// Every route already has its own specific `permission:` middleware.

// dashboard pages
Route::middleware('permission:dashboard.view')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
});

// offboarding requests — only a "create" action exists (no dedicated list/
// edit/delete page for the request itself; Offboardees/Dashboard cover
// viewing).
Route::middleware('permission:offboarding-requests.create')->group(function () {
    Route::post('/offboarding-requests', [OffboardingRequestController::class, 'store'])->name('offboarding-requests.store');
});

// offboarding checklist templates
// The "create" group must be registered before the "view" group below:
// Laravel matches GET routes in registration order, and the "view" group's
// bare `/offboarding-checklists/{checklistTemplate}` would otherwise match
// `/offboarding-checklists/create` first, binding "create" as the model ID
// and 404ing instead of ever reaching ChecklistTemplateController::create().
Route::middleware('permission:offboarding-checklists.create')->group(function () {
    Route::get('/offboarding-checklists/create', [ChecklistTemplateController::class, 'create'])->name('checklist-templates.create');
    Route::post('/offboarding-checklists', [ChecklistTemplateController::class, 'store'])->name('checklist-templates.store');
    // General Signatory — a standalone Clearance Signatory + Task List
    // record, deliberately independent of the checklist workflow above
    // (never attaches to an offboarding request). Lives on the same
    // "Offboarding Checklist" page, so it reuses this page's own
    // permissions rather than a new taxonomy.
    Route::post('/general-signatories', [GeneralSignatoryController::class, 'store'])->name('general-signatories.store');
});
Route::middleware('permission:offboarding-checklists.view')->group(function () {
    Route::get('/offboarding-checklists', [ChecklistTemplateController::class, 'index'])->name('checklist-templates.index');
    Route::get('/offboarding-checklists/{checklistTemplate}', [ChecklistTemplateController::class, 'show'])->name('checklist-templates.show');
    Route::get('/offboarding-checklists/{checklistTemplate}/edit', [ChecklistTemplateController::class, 'edit'])->name('checklist-templates.edit');
});
Route::middleware('permission:offboarding-checklists.edit')->group(function () {
    Route::put('/offboarding-checklists/{checklistTemplate}', [ChecklistTemplateController::class, 'update'])->name('checklist-templates.update');
    Route::patch('/offboarding-checklists/{checklistTemplate}/toggle-status', [ChecklistTemplateController::class, 'toggleStatus'])->name('checklist-templates.toggle-status');
    Route::put('/general-signatories/{generalSignatory}', [GeneralSignatoryController::class, 'update'])->name('general-signatories.update');
    Route::patch('/general-signatories/{generalSignatory}/toggle-status', [GeneralSignatoryController::class, 'toggleStatus'])->name('general-signatories.toggle-status');
});
Route::middleware('permission:offboarding-checklists.delete')->group(function () {
    Route::delete('/offboarding-checklists/{checklistTemplate}', [ChecklistTemplateController::class, 'destroy'])->name('checklist-templates.destroy');
    Route::delete('/general-signatories/{generalSignatory}', [GeneralSignatoryController::class, 'destroy'])->name('general-signatories.destroy');
});

// Final Approver — also lives on the "Offboarding Checklist" page, but
// deliberately gated by its OWN permission (not `offboarding-checklists.*`,
// unlike General Signatory above) since it controls a single, org-wide
// signatory used on every future Clearance Form.
Route::middleware('permission:final-approver.manage')->group(function () {
    Route::post('/final-approvers', [FinalApproverController::class, 'store'])->name('final-approvers.store');
    Route::patch('/final-approvers/{finalApprover}/toggle-status', [FinalApproverController::class, 'toggleStatus'])->name('final-approvers.toggle-status');
    Route::delete('/final-approvers/{finalApprover}', [FinalApproverController::class, 'destroy'])->name('final-approvers.destroy');
});

// onboarding checklist templates
// Same registration-order requirement as the offboarding checklists group
// above: "create" must come before "view" so the bare `/{onboardingChecklist}`
// show route never swallows `/onboarding-checklists/create` first.
Route::middleware('permission:onboarding-checklists.create')->group(function () {
    Route::get('/onboarding-checklists/create', [OnboardingChecklistTemplateController::class, 'create'])->name('onboarding-checklists.create');
    Route::post('/onboarding-checklists', [OnboardingChecklistTemplateController::class, 'store'])->name('onboarding-checklists.store');
});
Route::middleware('permission:onboarding-checklists.view')->group(function () {
    Route::get('/onboarding-checklists', [OnboardingChecklistTemplateController::class, 'index'])->name('onboarding-checklists.index');
    Route::get('/onboarding-checklists/{onboardingChecklist}', [OnboardingChecklistTemplateController::class, 'show'])->name('onboarding-checklists.show');
    Route::get('/onboarding-checklists/{onboardingChecklist}/edit', [OnboardingChecklistTemplateController::class, 'edit'])->name('onboarding-checklists.edit');
});
Route::middleware('permission:onboarding-checklists.edit')->group(function () {
    Route::put('/onboarding-checklists/{onboardingChecklist}', [OnboardingChecklistTemplateController::class, 'update'])->name('onboarding-checklists.update');
    Route::patch('/onboarding-checklists/{onboardingChecklist}/toggle-status', [OnboardingChecklistTemplateController::class, 'toggleStatus'])->name('onboarding-checklists.toggle-status');
});
Route::middleware('permission:onboarding-checklists.delete')->group(function () {
    Route::delete('/onboarding-checklists/{onboardingChecklist}', [OnboardingChecklistTemplateController::class, 'destroy'])->name('onboarding-checklists.destroy');
});

// department heads (independent of checklist templates — resolves any item
// approver's department to its head)
Route::middleware('permission:department-heads.view')->group(function () {
    Route::get('/department-heads', [DepartmentHeadController::class, 'index'])->name('department-heads.index');
});
Route::middleware('permission:department-heads.create')->group(function () {
    Route::post('/department-heads', [DepartmentHeadController::class, 'store'])->name('department-heads.store');
});
Route::middleware('permission:department-heads.edit')->group(function () {
    Route::put('/department-heads/{departmentHead}', [DepartmentHeadController::class, 'update'])->name('department-heads.update');
});
Route::middleware('permission:department-heads.delete')->group(function () {
    Route::delete('/department-heads/{departmentHead}', [DepartmentHeadController::class, 'destroy'])->name('department-heads.destroy');
});

// separation type management — the New Offboarding Request form's
// "Separation Type" picker (see OffboardingRequestController::store(),
// which snapshots title/description/default notice period onto the
// request at creation time, never re-reading these rows afterward).
Route::middleware('permission:separation-types.view')->group(function () {
    Route::get('/separation-types', [SeparationTypeController::class, 'index'])->name('separation-types.index');
});
Route::middleware('permission:separation-types.create')->group(function () {
    Route::post('/separation-types', [SeparationTypeController::class, 'store'])->name('separation-types.store');
});
Route::middleware('permission:separation-types.edit')->group(function () {
    Route::put('/separation-types/{separationType}', [SeparationTypeController::class, 'update'])->name('separation-types.update');
});
Route::middleware('permission:separation-types.delete')->group(function () {
    Route::delete('/separation-types/{separationType}', [SeparationTypeController::class, 'destroy'])->name('separation-types.destroy');
});

// reports — read-only, so a single `.view` permission covers the page and
// every export format.
Route::middleware('permission:reports.view')->group(function () {
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
});

// employee master (groups + group heads + employee membership)
Route::middleware('permission:employee-master.view')->group(function () {
    Route::get('/employee-groups', [EmployeeGroupController::class, 'index'])->name('employee-groups.index');
});
Route::middleware('permission:employee-master.create')->group(function () {
    Route::post('/employee-groups', [EmployeeGroupController::class, 'store'])->name('employee-groups.store');
    Route::post('/employee-groups/{employeeGroup}/employees', [EmployeeGroupController::class, 'addEmployee'])->name('employee-groups.employees.add');
    Route::post('/employee-groups/import', [EmployeeGroupController::class, 'import'])->name('employee-groups.import');
});
Route::middleware('permission:employee-master.edit')->group(function () {
    Route::put('/employee-groups/{employeeGroup}', [EmployeeGroupController::class, 'update'])->name('employee-groups.update');
    Route::patch('/employee-groups/{employeeGroup}/employees/{employee}/task-assignee', [EmployeeGroupController::class, 'toggleTaskAssignee'])->name('employee-groups.employees.toggle-task-assignee');
});
Route::middleware('permission:employee-master.delete')->group(function () {
    Route::delete('/employee-groups/{employeeGroup}', [EmployeeGroupController::class, 'destroy'])->name('employee-groups.destroy');
    Route::delete('/employee-groups/{employeeGroup}/employees/{employee}', [EmployeeGroupController::class, 'removeEmployee'])->name('employee-groups.employees.remove');
});

// offboardees (also covers the clearance form — a sub-view of an
// offboardee's own record, not a separate module)
Route::middleware('permission:offboardees.view')->group(function () {
    Route::get('/offboardees', [OffboardeeController::class, 'index'])->name('offboardees.index');
    Route::get('/offboarding-requests/{offboardingRequest}/clearance-form', [ClearanceFormController::class, 'pdf'])->name('clearance-form.pdf');
    Route::get('/offboarding-requests/{offboardingRequest}/clearance-form/print', [ClearanceFormController::class, 'print'])->name('clearance-form.print');
});

// Reset Offboarding Request (Offboardee page) — a destructive admin action
// that wipes all checklist/approval progress and reinitializes the request
// exactly like a newly created one, so it needs its own stricter permission
// rather than reusing "offboardees.view". Admin-only by default (see the
// permission's own seeding migration).
Route::middleware('permission:offboarding-requests.reset')->group(function () {
    Route::post('/offboarding-requests/{offboardingRequest}/reset', [OffboardingRequestController::class, 'reset'])->name('offboarding-requests.reset');
});

// Cancel Offboarding Request (Offboardee page) — a destructive admin action
// that retracts the request and permanently deletes its dependent records
// (the request row itself survives, marked 'cancelled' — see
// `OffboardingRequestController::cancel()`'s own docblock). Its own
// dedicated permission, same reasoning as the reset action above.
Route::middleware('permission:offboarding-requests.cancel')->group(function () {
    Route::post('/offboarding-requests/{offboardingRequest}/cancel', [OffboardingRequestController::class, 'cancel'])->name('offboarding-requests.cancel');
});

// Final Approval (Offboardee page) — emails the active Final Signatory to
// sign off on a COMPLETED offboarding request. Its own dedicated
// permission, same reasoning as the reset action above.
Route::middleware('permission:final-approval.send')->group(function () {
    Route::post('/offboarding-requests/{offboardingRequest}/final-approval', [FinalApprovalController::class, 'send'])->name('final-approval.send');
});

// Extend Due (Offboarding Status page) — pushes a checklist's due date
// forward once it's reached, only available past that due date. Its own
// dedicated permission, same reasoning as the reset/final-approval actions
// above, so an Admin can grant/revoke it independently of the general
// approve/decline/remind permission below.
Route::middleware('permission:checklists.extend-due')->group(function () {
    Route::post('/approvals/{offboardingRequestApprover}/extend-due', [ApprovalController::class, 'extendDue'])->name('approvals.extend-due');

    // Bulk equivalent (Offboardee page) — extends EVERY still-applicable
    // checklist on the request in one action, only available once all of
    // them have individually reached their own due date. Same permission
    // as the single-checklist version above; deliberately not a new one,
    // per spec ("users who currently have the appropriate permission for
    // extending checklist due dates should also have permission to use
    // this Extend Due action").
    Route::post('/offboarding-requests/{offboardingRequest}/extend-all-due', [ApprovalController::class, 'extendAllDue'])->name('offboarding-requests.extend-all-due');
});

// approver reminders (HR/Admin only) — part of "acting on" an approval.
Route::middleware('permission:approvals.approve')->group(function () {
    Route::post('/approvals/{offboardingRequestApprover}/remind', [ApprovalController::class, 'remind'])->name('approvals.remind');

    // General Signatory equivalent — resends the existing assignment's own
    // notification email (Offboarding Status/Timeline's "Notify Approver"),
    // never creates a new assignment. Same permission as the checklist
    // reminder above; both controllers additionally hard-require
    // `isAdmin()` themselves, since `approvals.approve` alone is broader
    // (also granted to the Approver role by default).
    Route::post('/general-signatory-approvals/{generalSignatoryApproval}/remind', [GeneralSignatoryApprovalController::class, 'remind'])->name('general-signatory-approvals.remind');
});

// email templates
Route::middleware('permission:email-templates.view')->group(function () {
    Route::get('/email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
    Route::get('/email-templates/{emailTemplate}/edit', [EmailTemplateController::class, 'edit'])->name('email-templates.edit');
});
Route::middleware('permission:email-templates.create')->group(function () {
    Route::get('/email-templates/create', [EmailTemplateController::class, 'create'])->name('email-templates.create');
    Route::post('/email-templates', [EmailTemplateController::class, 'store'])->name('email-templates.store');
});
Route::middleware('permission:email-templates.edit')->group(function () {
    Route::put('/email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->name('email-templates.update');
    Route::patch('/email-templates/{emailTemplate}/toggle-status', [EmailTemplateController::class, 'toggleStatus'])->name('email-templates.toggle-status');
});
Route::middleware('permission:email-templates.delete')->group(function () {
    Route::delete('/email-templates/{emailTemplate}', [EmailTemplateController::class, 'destroy'])->name('email-templates.destroy');
});

// roles & permissions — layered with real Spatie permission checks (not
// just the surrounding role:admin group) since these are brand-new pages.
Route::middleware('permission:roles.view')->group(function () {
    Route::get('/roles-permissions', [RoleController::class, 'index'])->name('roles.index');
});
Route::middleware('permission:roles.manage')->group(function () {
    Route::post('/roles-permissions', [RoleController::class, 'store'])->name('roles.store');
    Route::put('/roles-permissions/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('/roles-permissions/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    Route::put('/roles-permissions/{role}/permissions', [RoleController::class, 'syncPermissions'])->name('roles.permissions.sync');
});

// users — role assignment only, see UserController's docblock.
Route::middleware('permission:users.view')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
});
Route::middleware('permission:users.manage-roles')->group(function () {
    Route::put('/users/{employee}/role', [UserController::class, 'updateRole'])->name('users.role.update');
});

});






















