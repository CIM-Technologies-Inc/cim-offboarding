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
use App\Http\Controllers\EmailTemplateController;

// authentication pages
Route::get('/signin', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {

// dashboard pages
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// calender pages
Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');

// profile pages
Route::get('/profile', function () {
    return view('pages.profile', ['title' => 'Profile']);
})->name('profile');

Route::patch('/profile/personal-info', [ProfileController::class, 'updatePersonalInfo'])->name('profile.personal-info.update');
Route::patch('/profile/address', [ProfileController::class, 'updateAddress'])->name('profile.address.update');

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

// offboardees
Route::get('/offboardees', [OffboardeeController::class, 'index'])->name('offboardees.index');

// approvals
Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
Route::post('/approvals/{offboardingRequest}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
Route::post('/approvals/{offboardingRequest}/decline', [ApprovalController::class, 'decline'])->name('approvals.decline');

// email templates
Route::get('/email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
Route::get('/email-templates/create', [EmailTemplateController::class, 'create'])->name('email-templates.create');
Route::post('/email-templates', [EmailTemplateController::class, 'store'])->name('email-templates.store');
Route::get('/email-templates/{emailTemplate}/edit', [EmailTemplateController::class, 'edit'])->name('email-templates.edit');
Route::put('/email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->name('email-templates.update');
Route::delete('/email-templates/{emailTemplate}', [EmailTemplateController::class, 'destroy'])->name('email-templates.destroy');
Route::patch('/email-templates/{emailTemplate}/toggle-status', [EmailTemplateController::class, 'toggleStatus'])->name('email-templates.toggle-status');

});






















