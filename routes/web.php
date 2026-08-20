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

Route::middleware(['auth', 'password.changed'])->group(function () {

// forced first-time password change — reachable even while
// `must_change_password` is true (EnsurePasswordChanged exempts these two
// route names specifically), unlike everything else in this group.
Route::get('/password/change', [ChangePasswordController::class, 'edit'])->name('password.change');
Route::put('/password/change', [ChangePasswordController::class, 'update'])->name('password.change.update');

// pages shared by both admin and approver roles
Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');

Route::get('/profile', function () {
    return view('pages.profile', ['title' => 'Profile']);
})->name('profile');

Route::patch('/profile/personal-info', [ProfileController::class, 'updatePersonalInfo'])->name('profile.personal-info.update');
Route::patch('/profile/address', [ProfileController::class, 'updateAddress'])->name('profile.address.update');
Route::post('/profile/signature', [ProfileController::class, 'updateSignature'])->name('profile.signature.update');
Route::delete('/profile/signature', [ProfileController::class, 'removeSignature'])->name('profile.signature.destroy');

// notifications
Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
Route::post('/notifications/mark-all-read', [NotificationController::class, 'readAll'])->name('notifications.read-all');

// approvals
Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
Route::post('/approvals/{offboardingRequestApprover}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
Route::post('/approvals/{offboardingRequestApprover}/decline', [ApprovalController::class, 'decline'])->name('approvals.decline');

// checklist delegation (assign to another approver)
Route::post('/approvals/{offboardingRequestApprover}/assign', [ChecklistDelegationController::class, 'assign'])->name('approvals.assign');
Route::post('/approvals/{offboardingRequestApprover}/save-progress', [ChecklistDelegationController::class, 'saveProgress'])->name('approvals.save-progress');
Route::post('/approvals/{offboardingRequestApprover}/items/{checklistItem}/hold', [ChecklistDelegationController::class, 'holdItem'])->name('approvals.items.hold');
Route::post('/approvals/{offboardingRequestApprover}/items/{checklistItem}/assign', [ChecklistDelegationController::class, 'assignItem'])->name('approvals.items.assign');
Route::post('/approvals/{offboardingRequestApprover}/items/{checklistItem}/take-over', [ChecklistDelegationController::class, 'takeOverItem'])->name('approvals.items.take-over');

// pages restricted to the admin role
Route::middleware('role:admin')->group(function () {

// dashboard pages
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// offboarding requests
Route::post('/offboarding-requests', [OffboardingRequestController::class, 'store'])->name('offboarding-requests.store');

// offboarding checklist templates
Route::get('/offboarding-checklists', [ChecklistTemplateController::class, 'index'])->name('checklist-templates.index');
Route::get('/offboarding-checklists/create', [ChecklistTemplateController::class, 'create'])->name('checklist-templates.create');
Route::post('/offboarding-checklists', [ChecklistTemplateController::class, 'store'])->name('checklist-templates.store');
Route::get('/offboarding-checklists/{checklistTemplate}', [ChecklistTemplateController::class, 'show'])->name('checklist-templates.show');
Route::get('/offboarding-checklists/{checklistTemplate}/edit', [ChecklistTemplateController::class, 'edit'])->name('checklist-templates.edit');
Route::put('/offboarding-checklists/{checklistTemplate}', [ChecklistTemplateController::class, 'update'])->name('checklist-templates.update');
Route::patch('/offboarding-checklists/{checklistTemplate}/toggle-status', [ChecklistTemplateController::class, 'toggleStatus'])->name('checklist-templates.toggle-status');
Route::delete('/offboarding-checklists/{checklistTemplate}', [ChecklistTemplateController::class, 'destroy'])->name('checklist-templates.destroy');

// onboarding checklist templates
Route::get('/onboarding-checklists', [OnboardingChecklistTemplateController::class, 'index'])->name('onboarding-checklists.index');
Route::get('/onboarding-checklists/create', [OnboardingChecklistTemplateController::class, 'create'])->name('onboarding-checklists.create');
Route::post('/onboarding-checklists', [OnboardingChecklistTemplateController::class, 'store'])->name('onboarding-checklists.store');
Route::get('/onboarding-checklists/{onboardingChecklist}', [OnboardingChecklistTemplateController::class, 'show'])->name('onboarding-checklists.show');
Route::get('/onboarding-checklists/{onboardingChecklist}/edit', [OnboardingChecklistTemplateController::class, 'edit'])->name('onboarding-checklists.edit');
Route::put('/onboarding-checklists/{onboardingChecklist}', [OnboardingChecklistTemplateController::class, 'update'])->name('onboarding-checklists.update');
Route::patch('/onboarding-checklists/{onboardingChecklist}/toggle-status', [OnboardingChecklistTemplateController::class, 'toggleStatus'])->name('onboarding-checklists.toggle-status');
Route::delete('/onboarding-checklists/{onboardingChecklist}', [OnboardingChecklistTemplateController::class, 'destroy'])->name('onboarding-checklists.destroy');

// department heads (independent of checklist templates — resolves any item
// approver's department to its head)
Route::get('/department-heads', [DepartmentHeadController::class, 'index'])->name('department-heads.index');
Route::post('/department-heads', [DepartmentHeadController::class, 'store'])->name('department-heads.store');
Route::put('/department-heads/{departmentHead}', [DepartmentHeadController::class, 'update'])->name('department-heads.update');
Route::delete('/department-heads/{departmentHead}', [DepartmentHeadController::class, 'destroy'])->name('department-heads.destroy');

// employee master (groups + group heads + employee membership)
Route::get('/employee-groups', [EmployeeGroupController::class, 'index'])->name('employee-groups.index');
Route::post('/employee-groups', [EmployeeGroupController::class, 'store'])->name('employee-groups.store');
Route::put('/employee-groups/{employeeGroup}', [EmployeeGroupController::class, 'update'])->name('employee-groups.update');
Route::delete('/employee-groups/{employeeGroup}', [EmployeeGroupController::class, 'destroy'])->name('employee-groups.destroy');
Route::post('/employee-groups/{employeeGroup}/employees', [EmployeeGroupController::class, 'addEmployee'])->name('employee-groups.employees.add');
Route::delete('/employee-groups/{employeeGroup}/employees/{employee}', [EmployeeGroupController::class, 'removeEmployee'])->name('employee-groups.employees.remove');

// offboardees
Route::get('/offboardees', [OffboardeeController::class, 'index'])->name('offboardees.index');

// clearance form (available once an offboarding request is fully completed)
Route::get('/offboarding-requests/{offboardingRequest}/clearance-form', [ClearanceFormController::class, 'pdf'])->name('clearance-form.pdf');
Route::get('/offboarding-requests/{offboardingRequest}/clearance-form/print', [ClearanceFormController::class, 'print'])->name('clearance-form.print');

// approver reminders (HR/Admin only)
Route::post('/approvals/{offboardingRequestApprover}/remind', [ApprovalController::class, 'remind'])->name('approvals.remind');

// email templates
Route::get('/email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
Route::get('/email-templates/create', [EmailTemplateController::class, 'create'])->name('email-templates.create');
Route::post('/email-templates', [EmailTemplateController::class, 'store'])->name('email-templates.store');
Route::get('/email-templates/{emailTemplate}/edit', [EmailTemplateController::class, 'edit'])->name('email-templates.edit');
Route::put('/email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->name('email-templates.update');
Route::delete('/email-templates/{emailTemplate}', [EmailTemplateController::class, 'destroy'])->name('email-templates.destroy');
Route::patch('/email-templates/{emailTemplate}/toggle-status', [EmailTemplateController::class, 'toggleStatus'])->name('email-templates.toggle-status');

});

});






















