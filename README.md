# Offboarding Management System

A Laravel 12 application for managing the full employee **offboarding lifecycle**: creating offboarding requests, assigning and tracking clearance checklists across Department Heads / Clearance Signatories / General Signatories, enforcing due dates and approval sequencing, generating a Clearance Form, and routing the request through a Final Approval sign-off to completion.

Built on the [TailAdmin](https://tailadmin.com/laravel) Tailwind CSS admin dashboard template (Laravel + Tailwind CSS v4 + Alpine.js), heavily customized into a purpose-built business application. This README documents the **actual business logic implemented in this codebase** — not the generic template it started from — for developers who need to understand or safely modify the system.

> Every claim in this document is backed by the code it describes; file paths are cited throughout so you can jump straight to the source. Where something is ambiguous, partially implemented, or present in the backend but not currently reachable through the UI, it is called out explicitly rather than glossed over.

---

## Table of Contents

1. [Application Overview](#1-application-overview)
2. [Technology Stack](#2-technology-stack)
3. [System Architecture](#3-system-architecture)
4. [User Roles and Permissions](#4-user-roles-and-permissions)
5. [Main Application Workflow](#5-main-application-workflow-the-offboarding-request-lifecycle)
6. [Checklist Processing Logic](#6-checklist-processing-logic)
7. [Clearance and Signature Processing](#7-clearance-and-signature-processing)
8. [Notifications and Emails](#8-notifications-and-emails)
9. [Scheduled Tasks / Scheduler](#9-scheduled-tasks--scheduler)
10. [Database Structure](#10-database-structure)
11. [Important Business Rules](#11-important-business-rules)
12. [Development Setup](#12-development-setup)
13. [Common Commands](#13-common-commands)
14. [File and Folder Structure](#14-file-and-folder-structure)
15. [Troubleshooting / Known Issues](#15-troubleshooting--known-issues)
16. [Developer Guidelines](#16-developer-guidelines)

---

## 1. Application Overview

**Purpose**: Digitize and enforce an organization's employee offboarding process — from the moment HR/Admin submits a request through every clearance checklist being signed off, to a final sign-off and completion.

**Main functionality**:
- Create an offboarding request for an employee, capturing separation type, notice date, and Last Working Day.
- Automatically attach the right clearance checklists (by department, by Immediate Head, or company-wide "General Signatory" checklists) and notify the people responsible for signing them off.
- Let each responsible signatory (Department Head / Clearance Signatory / General Signatory) review, delegate, or personally approve their checklist(s), with per-item task assignment where configured.
- Track due dates, send overdue/reminder notifications, and let Admin/HR extend the Last Working Day (recalculating every dependent due date).
- Stage Primary → Secondary → Final Pay checklists (in the "Sync" workflow) or release everything at once ("Async" workflow — the current default).
- Generate a Clearance Form (PDF) aggregating every signatory's status and captured e-signature.
- Route the request to a single, system-wide **Final Approver** for a terminal sign-off once every checklist and General Signatory has cleared.
- Support retracting/cancelling a request, with full audit logging throughout (`offboarding_activities`) and an Offboarding Status/Timeline view for both HR and individual approvers.

**Primary users/roles**: Admin/HR staff, Department Heads / Clearance Signatories, General Signatories, individual Task Assignees, a single system-wide Final Approver, and the offboardee themselves (self-service, limited access). See [§4](#4-user-roles-and-permissions).

**High-level workflow**:
```
HR creates Offboarding Request
        │
        ▼
Checklists + General Signatories attached & notified
        │
        ▼
Clearance Signatories review / delegate / assign tasks → approve or decline
        │
        ▼
(Sync mode only) Secondary checklists released once Primary is done
        │
        ▼
Final Pay checklist(s) + General Signatories' Final Pay tier released
   once everything else has cleared
        │
        ▼
Request auto-marked "completed" once Final Pay + all General Signatories clear
        │
        ▼
Admin sends Final Approval to the system's Final Approver → they sign off
        │
        ▼
Clearance Form reflects every signature; offboarding process finished
```

---

## 2. Technology Stack

| Layer | Technology | Version |
|---|---|---|
| Language / Framework | PHP / Laravel | PHP `^8.2`, Laravel `^12.0` |
| Database | MySQL | Configured via `.env` (`DB_CONNECTION=mysql`) |
| Frontend build | Vite + `laravel-vite-plugin` | Vite `^7.0.4` |
| CSS | Tailwind CSS | `^4.1.12` (via `@tailwindcss/vite`) |
| JS interactivity | Alpine.js | `^3.14.9` |
| Navigation | Turbo Drive (`@hotwired/turbo`) | `^8.0.23` — SPA-like page loads without full reloads |
| Date picker | flatpickr | `^4.6.13` |
| Dialogs / toasts | SweetAlert2 | `^11.26.25` |
| Charts / calendar | ApexCharts, FullCalendar | `^5.3.5`, `^6.1.19` |
| Rich text editor | Summernote | `^0.9.1` (email template editor) |
| Roles & permissions | `spatie/laravel-permission` | `^8.3` |
| PDF generation | `barryvdh/laravel-dompdf` | `^3.1` |
| Spreadsheet export | `phpoffice/phpspreadsheet` | `^5.9` |
| Testing | Pest | `^4.0` (`pestphp/pest-plugin-laravel ^4.0`) |
| Queue / Cache / Session | Database driver | `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database` |

**Authentication/authorization**: `spatie/laravel-permission` is genuinely used (not a stub) — `App\Models\User` uses the `HasRoles` trait, and authorization is enforced via Spatie's `permission:` route middleware (`routes/web.php`, ~40 individually-gated route groups) and `@can`/`->can()` checks in Blade/controllers. There is **no** use of Laravel's own Gate/Policy system — `app/Policies/` and `app/Rules/` exist but contain zero files.

---

## 3. System Architecture

**Request lifecycle in one line**: Blade + Alpine (server-rendered HTML, progressively enhanced) → Laravel Controllers → Eloquent Models / Services → MySQL, with a scheduler-driven console command layer handling everything time-based, and a Notification/Mail layer handling everything communication-based.

### Major components

- **Controllers** (`app/Http/Controllers/`) — one per functional area (see [§14](#14-file-and-folder-structure)). Controllers are intentionally the orchestration layer: they validate input, call into models/services, and either return a Blade `View` or a JSON response for `fetch()`-driven Alpine interactions.
- **Services** (`app/Services/`) — the three places genuinely cross-cutting business logic lives, so it's never duplicated across controllers:
  - `ChecklistApprovalNotifier` — attaches checklist templates / General Signatories to a request and sends the corresponding "you've been assigned" notifications.
  - `ChecklistCompletionService` — the Primary → Secondary → Final Pay staging engine (see [§6](#6-checklist-processing-logic)) and the single place `offboarding_request.status` transitions to `'completed'`.
  - `ChecklistFollowUpService` — rate-limits and sends the offboardee's own "nudge my approver" follow-up action (`OFFBOARDING_MAX_FOLLOW_UP_ATTEMPTS`/`OFFBOARDING_FOLLOW_UP_COOLDOWN_HOURS` in `.env`).
- **Models** (`app/Models/`) — 28 Eloquent models. Beyond plain data access, several carry real business logic as methods (e.g. `OffboardingRequestApprover::isEligibleForPoolAssignment()`, `recalculateClearanceSigningDueDate()`, `isClearanceSigningOverdue()`; `Employee::approvalSubordinateEmployeeIds()`).
- **Frontend** — Blade views (`resources/views/pages/*` for routed pages, `resources/views/components/*` for reusable pieces) with Alpine.js components handling client-side interactivity (modals, dropdowns, live urgency coloring, `fetch()`-based form submission with SweetAlert2 confirm/loading dialogs). Most mutating actions that need to "stay on the same page" (approve, decline, extend due date, cancel, bulk-assign) submit via `fetch()` with `Accept: application/json` rather than a native form POST, so the server can return JSON and the page can patch its own Alpine state in place instead of doing a full reload.
- **Scheduled jobs** (`app/Console/Commands/` + `routes/console.php`) — the ONLY mechanism for anything time-triggered (overdue detection, scheduled emails, reminders). See [§9](#9-scheduled-tasks--scheduler).
- **Notifications & Email** — a dual system: Laravel's built-in database notifications (bell icon, `notifications` table) for in-app alerts, and a fully admin-editable `EmailTemplate` system (placeholder-driven HTML, rendered through a generic `ChecklistSignatoryAnnouncementMail` wrapper) for outgoing email, alongside a handful of purpose-built Mailables with fixed Blade views for specific transactional emails. See [§8](#8-notifications-and-emails).

### How the pieces interact

```
Blade/Alpine (browser)
   │  fetch() JSON, or native form POST
   ▼
Controller  ──validates──▶ Model / Service  ──writes──▶ MySQL
   │                              │
   │                              └──▶ Notification::send() ──▶ notifications table (bell icon)
   │                              └──▶ Mail::to()->send() ──▶ EmailTemplate::render() ──▶ SMTP
   ▼
JSON response (patched into Alpine state) or redirect + flash message

Scheduler (php artisan schedule:run, driven by cron/Task Scheduler)
   │
   ▼
app:notify-overdue-checklists / app:notify-clearance-signing-due / app:send-checklist-reminders /
app:send-scheduled-email-templates / app:send-scheduled-checklist-item-notifications
   │
   └──▶ same Model/Notification/Mail layer as above, running unattended
```

---

## 4. User Roles and Permissions

Three Spatie roles exist (`App\Models\User::ROLE_ADMIN = 'admin'`, `ROLE_APPROVER = 'approver'`, `ROLE_EMPLOYEE = 'employee'`), but the application differentiates several more **functional personas** on top of these, determined by an `Employee` record's relationships rather than by a distinct Spatie role:

| Persona | How it's determined | What they can do |
|---|---|---|
| **Admin / HR** | `User::isAdmin()` (Spatie role `admin`) | Full access — every permission is synced to this role. Creates offboarding requests, manages checklist templates, General Signatories, employees/groups, email templates, roles, separation types; views reports; can extend due dates, cancel requests, send Final Approval. |
| **Department Head / Clearance Signatory** | An `Employee` who is the `employee_id` (owner) on an `OffboardingRequestApprover` row — either directly assigned on the checklist template, or the head of the template's `employee_group_id` | Views their own Approvals queue; approves/declines/delegates checklists assigned to them; can bulk-assign eligible task lists to a subordinate; can extend the request's Last Working Day if they hold `checklists.extend-due`. |
| **General Signatory** | An `Employee` referenced by `general_signatories.clearance_signatory_id` | Independent clearance track outside the checklist system — approves/declines on their own `OffboardingRequestGeneralSignatory` assignment, scoped via `OffboardingRequestGeneralSignatory::scopeVisibleTo()`. |
| **Task Assignee** | `employees.is_task_assignee = true`, granted visibility via a `ChecklistItemAssignment` row (or the template's own group membership) | Can see and act on the specific checklist item(s) assigned to them, without being the checklist's overall owner. |
| **Delegate** | An employee named in `offboarding_request_approvers.delegated_employee_id` | Granted the same visibility/action rights as the primary approver on that one checklist, for helping complete it. |
| **Monitoring Head** | The approval-chain head (Immediate Head, else Group/Department Head) of a Task-Assignee-owned ("Use Task Assignee as Clearance Signatory") checklist | Read-only oversight of that checklist — explicitly documented in code as never granting write/approve rights (`OffboardingRequestApprover::monitoringDepartmentHeads()`). |
| **Final Approver** | The single active row in `final_approvers` (system-wide, not per-request) | Gives the terminal sign-off on a request once its status is already `'completed'`. |
| **Plain Employee / Offboardee** | `User::isEmployee()` | Self-service: views their own offboarding status/timeline, can send a rate-limited "follow-up" nudge to their assigned approver(s). No module permissions. |

### Permission taxonomy (Spatie, seeded/expanded across several migrations)

Grouped by area — route-enforced via `permission:` middleware in `routes/web.php` (~40 gated route groups):

- `dashboard.view`
- `offboarding-requests.create`, `.reset`, `.cancel`
- `offboarding-checklists.{view,create,edit,delete}`
- `onboarding-checklists.{view,create,edit,delete}` (a separate, parallel checklist feature for employee *onboarding* — not offboarding)
- `department-heads.{view,create,edit,delete}`
- `employee-master.{view,create,edit,delete}`
- `offboardees.view`
- `approvals.{view,approve}`
- `email-templates.{view,create,edit,delete}`
- `roles.{view,manage}`
- `users.{view,manage-roles}`
- `user-profile.{view,edit-personal-info,edit-contact-info,edit-employment-info,edit-photo,edit-signature,view-documents}`
- `final-approver.manage`, `final-approval.send`
- `separation-types.{view,create,edit,delete}`
- `reports.view`
- `checklists.extend-due`
- `calendar.view`

**Default assignment**: Admin gets every permission. Approver gets `approvals.view`, `approvals.approve`, `calendar.view`. Employee gets none — their dashboard access is identity-scoped (they only ever see their own request), not permission-gated.

### Login provisioning

Non-admin accounts are auto-provisioned on demand (`User::findOrCreateApprover()` / `findOrCreateEmployee()` / `findOrCreateGeneralSignatory()`, `app/Models/User.php`): **username = password = the employee's `employee_code_digits`** (their `employee_code` with the `"EMP"` prefix stripped). A freshly created account is flagged `must_change_password = true`. Role assignment is additive — an existing account is never demoted, only ever gains new roles as a person takes on new responsibilities (e.g. an Approver later also becoming an offboardee themselves).

---

## 5. Main Application Workflow: the Offboarding Request Lifecycle

### 5.1 Creating an offboarding request

`OffboardingRequestController::store()` (`app/Http/Controllers/OffboardingRequestController.php`).

**Submitted fields**: `employee_id`, `immediate_head_id` (nullable — falls back to the employee's Employee Master Group Head if omitted), `notice_date`, `last_working_day`, `separation_type_id`, `approval_mode` (nullable, `sync`/`async`), plus optional per-request overrides for three fixed-name email templates.

**Validation notes**:
- Only `separation_type_id` is trusted from the client — the Separation Type's title/description/default notice period are always re-looked-up server-side and frozen onto the request (`separation_type_description`, `notice_period_days`) so later edits to the catalog never retroactively change an already-created request.
- **There is no "Last Working Day must be after Notice Date" rule** — this was deliberately removed; both fields are validated independently as plain dates.
- An active `FinalApprover` must exist system-wide, and the target employee must currently have `status === 'active'`, or creation is rejected.

**What happens at creation** (inside a DB transaction): the request is created with `status: 'pending'`, `original_last_working_day` frozen for later Extend-Due comparisons, and the employee flipped to `status: 'offboarding'`. Login accounts are auto-provisioned for the offboardee (and the Immediate Head, if set).

**After the transaction** (so slow email I/O never holds a DB lock), `notifyDepartmentHeads()`:
1. Selects applicable checklist templates for the employee's department — **Async mode** (the effective default; see the note below) selects every non-Final-Pay template at once; **Sync mode** selects only `sequence_type === 'primary'` templates.
2. Attaches and notifies them via `ChecklistApprovalNotifier`.
3. Notifies the offboardee themselves ("your offboarding process has started", including login credentials if their account is new).
4. Does the identical staging, independently, for General Signatories.

> **UI note**: The Approval Mode picker in the "New Offboarding Request" modal is currently commented out in the Blade template, and `approval_mode` defaults to `'async'` when nothing is submitted. **Sync mode's staging logic is fully implemented and unit-testable in `ChecklistCompletionService`, but is not currently reachable through the UI** unless that picker is re-enabled or a request is submitted directly. Treat Sync mode as "supported by the backend, dormant in the current UI" rather than "in active use."

### 5.2 Checklist generation/assignment

`ChecklistApprovalNotifier::attachAndNotify()` (`app/Services/ChecklistApprovalNotifier.php`):
- Attaches the templates to the request's `checklistTemplates` pivot.
- For each template, resolves the responsible approver: the request's Immediate Head (if `is_immediate_head_checklist`), otherwise the template's own `department_head_id`. If neither resolves and the template isn't "Use Task Assignee as Clearance Signatory," it stays attached with **no** `OffboardingRequestApprover` row at all.
- Creates one `OffboardingRequestApprover` row per template, computing `due_at` (Last Working Day + `due_in_days`, end-of-day) and, independently, `clearance_signing_due_at` (Last Working Day + `clearance_signing_deadline_days` — stays `null` if unconfigured; see [§6](#6-checklist-processing-logic)).
- Snapshots, per item, which employee is the Task Assignee **at attachment time** — later template edits never retroactively change an already-created request.
- Emails any item-level Task Assignee whose resolved signatory differs from the checklist's own owner.

### 5.3 Clearance signatory / Immediate Head / task assignment processing

See [§6](#6-checklist-processing-logic) and [§7](#7-clearance-and-signature-processing) for the full mechanics of who can act on a checklist and when.

### 5.4 Checklist approval / clearance

A Clearance Signatory reviews their checklist's items, checks them off (personally or via a delegate/task assignee), and gives final approval — or **declines**, which is treated as a **completed** signatory action (not a block): it requires the same e-signature and a mandatory reason as approval, never stops the offboarding request, and the declined signature still appears on the Clearance Form.

### 5.5 Due dates and overdue processing

Each checklist has its own `due_at` (from the template's `due_in_days`, always end-of-day). `app:notify-overdue-checklists` (scheduled every 5 minutes) notifies admins, the Department Head, and (on per-item checklists) each unchecked item's assignee once a checklist's due date passes, guarded by `overdue_notified_at` so it fires exactly once per due-date event.

### 5.6 Due-date extensions

Admin/HR (permission `checklists.extend-due`) can extend a request's Last Working Day from the Offboardee page. This recalculates `due_at` for every applicable checklist (and `clearance_signing_due_at` alongside it — see [§6](#6-checklist-processing-logic)) from the SAME new Last Working Day, records a permanent audit row per checklist in `checklist_due_date_extensions`, and clears the `overdue_notified_at`/`clearance_signing_due_notified_at` guards so a checklist that's overdue again under the new date can be notified again. Submitted via `fetch()` with a SweetAlert2 dialog that stays open (its own loading spinner) for the whole request, only closing on genuine success.

### 5.7 Retracting an offboarding request

"Retract Offboarding" (Offboardee page) — a **destructive, irreversible** cancellation: it deletes every `OffboardingRequestApprover`, `OffboardingRequestGeneralSignatory`, follow-up, scheduled-send, and activity row for the request, detaches its checklist templates, and reverts the employee's `status` back to `active`. Requires a mandatory reason. Distinct from a plain "Cancellation" flag — this genuinely removes the working data, keeping only what's needed for the cancellation email/audit trail.

### 5.8 Final approval

`FinalApprovalController` (`app/Http/Controllers/FinalApprovalController.php`). Can only be sent once `offboarding_request.status === 'completed'` (see [§5.10](#510-final-pay-processing--completion)) — Final Approval is a terminal sign-off layered *after* completion, not a trigger for it. There is exactly **one** active Final Approver system-wide (`final_approvers` table — activating one deactivates all others); `OffboardingRequestFinalApproval` snapshots who was active when the request was sent to them, but a resend re-targets whoever is currently active. The Final Approver can only **approve** (no decline path exists here), either in-app or via a 30-day, token-based one-click email link that always attaches the Clearance Form PDF. Approving does **not** itself change `offboarding_request.status` — that transition already happened earlier.

### 5.9 General signatory processing

An entirely separate clearance mechanism from the checklist system — company-wide (not scoped to the offboardee's department), attached and staged the same Primary → Secondary → Final Pay way as checklists (its own parallel columns: `general_signatories.sequence_type`/`is_final_pay_signatory`), but independently gated. See [§6](#6-checklist-processing-logic).

### 5.10 Final pay processing / completion

`ChecklistCompletionService::checkFinalPayCompletion()` is the **only** code path that sets `offboarding_request.status = 'completed'`. It requires every Final Pay checklist assignment approved/declined AND every General Signatory tier (including their own Final Pay stage) fully cleared. On the transition, it also flips the employee's own record to `status = 'offboarded'` (dropping them from active-employee counts) and sends the "Final Pay Checklist Completed" notification to admins + the request's creator.

---

## 6. Checklist Processing Logic

### Checklist classification

An admin classifies each `ChecklistTemplate` as one of three types (`ChecklistTemplateController`, validated as `checklist_classification` ∈ `{primary, secondary, final_pay}`):

| Classification | `sequence_type` | `is_final_pay_checklist` |
|---|---|---|
| Primary | `'primary'` | `false` |
| Secondary | `'secondary'` | `false` |
| Final Pay | `null` | `true` |

General Signatories carry the identical pair of columns (`sequence_type`, `is_final_pay_signatory`) and are staged by the exact same rules.

> **UI note**: at least the admin General Signatory create/edit modal has its "Secondary" radio option commented out in the Blade template (only "Core" and "For Final Pay Checklist" are exposed) — the backend/validation still fully supports it. Verify the Checklist Template form separately if you need to confirm whether Secondary is currently reachable there too.

### Sync vs. Async workflow (`ChecklistCompletionService`)

- **Async** (the effective current default): every non-Final-Pay checklist template AND every non-Final-Pay-signature General Signatory is attached and notified **in one batch, immediately**, at request creation. Only the Final Pay tier is deferred, released once everything else clears.
- **Sync**: a staged pipeline — Primary checklists attach first; once every Primary assignment is approved/declined, Secondary checklists attach (guarded by `secondary_notified_at`, fires once); once every regular (Primary+Secondary) checklist is cleared **and** every regular General Signatory has cleared, Final Pay checklists attach. General Signatories mirror this staging independently (`general_signatory_secondary_notified_at`).

### Department-based checklists

A checklist template can be scoped to a specific `department` (only attached to requests for employees in that department) or left unscoped (`department = null`, applies to every department).

### Immediate Head checklists

`is_immediate_head_checklist = true` — assigned exclusively to whichever Immediate Head is chosen on that specific offboarding request (never a static Department Head), and never has its own Employee Master group.

### General Signatory checklists

See [§5.9](#59-general-signatory-processing) — a fully independent clearance track, company-wide rather than department-scoped, with its own tasks (`general_signatory_tasks`, informational display only — not individually checked off the way a real checklist item is).

### Task assignees

Individual checklist items can each have their own signatory (`checklist_items.signatory_id`), distinct from the checklist's overall owner. Per-request, this is snapshotted into `checklist_item_assignments` at attachment time, and can be reassigned afterward (creating a new `active` row and marking the old one `superseded`). A signatory owner (or their delegate) can also **bulk-assign** every currently-eligible (not yet approved/cleared, not per-item-approver) task list on a card to one or more subordinate employees in one submission (`ChecklistDelegationController::assignPool()`), splitting items evenly across the selected employees.

### Clearance signatories

The checklist's overall owner (`offboarding_request_approvers.employee_id`) — the Department Head, Immediate Head, or (for "Use Task Assignee as Clearance Signatory" templates) the task assignee themselves acting as their own signatory. Always the one who gives final approval/decline on the whole checklist.

### Combined vs. individual checklist behavior

Two genuinely different things share similar naming — don't conflate them:
1. **Per-user display preference** (`users.combine_assigned_checklists`, toggled via the "Separate Checklist" switch on the Approvals page) — purely a UI grouping choice: whether several checklists assigned to the *same signatory* for the *same offboardee* render as one merged card or as separate cards. Never changes underlying assignments or approval state.
2. **"Use Task Assignee as Clearance Signatory"** (`checklist_templates.use_task_assignee_as_signatory`) — a genuine behavioral mode where the checklist has no Department Head owner at all; each item's own assigned Task Assignee is their own item's signatory, with the assignee's approval-chain head (Immediate/Group/Department Head) granted read-only monitoring.

### Checklist / task status calculation

A checklist's status is derived (not a stored enum) from its `OffboardingRequestApprover.status` (`pending`/`viewed`/`approved`/`declined`) plus computed flags: `isOverdue()` (past `due_at`, not yet resolved), `isClearanceSigningOverdue()` (past `clearance_signing_due_at`, independently — see below), `hasReachedDueDate()`. Item-level status comes from `checklist_item_progress` (`is_checked`, `status` including a `hold` state with a required remark).

### Assignment rules

- A checklist's assignable pool (for delegation, task assignment, or bulk pool-assignment) is restricted to the same Employee Master group as the checklist's own owner — never the full active-employee roster — re-validated server-side on every submission, never trusting the client's own filtered list.
- An owner cannot be assigned as their own checklist's assignee.

### Approval / clearance rules

- Only the checklist's own primary approver (or an authorized delegate) may give final approval.
- Approval and decline both require the actor to have a usable e-signature on file (`users.signature_path` set) — enforced before every approve/decline action across checklists, General Signatories, and Final Approval alike.
- Decline requires a mandatory reason and is treated as a **completed**, non-blocking action (see [§5.4](#54-checklist-approval--clearance)).

### Due-date calculation

`due_at` = offboardee's Last Working Day + the checklist template's `due_in_days` (default 0 if unset), always computed to end-of-day (23:59:59) — "due until the end of that day," never midnight of the due date itself.

### Due-date extension behavior

Extending the request's Last Working Day recomputes `due_at` for every still-open checklist from that new date, using each checklist's own configured `due_in_days` — and, independently, recomputes `clearance_signing_due_at` the same way. See [§5.6](#56-due-date-extensions).

### Clearance Signing Deadline — a second, independent deadline concept

`checklist_templates.clearance_signing_deadline_days` / `offboarding_request_approvers.clearance_signing_due_at` (and the parallel `general_signatories.due_in_days` / `offboarding_request_general_signatories.due_at`) is a **deliberately separate** field from `due_in_days`/`due_at` above — added specifically so a signatory can be given a different (typically longer) deadline for *signing off* than the checklist's own general due date. It defaults to `null` (no deadline shown) when unconfigured, unlike `due_in_days` which defaults to 0. Visually, the UI escalates it to orange text 5 days before the deadline and red/bold on or after it, live-updating on the page without a reload. A separate scheduled command (`app:notify-clearance-signing-due`) notifies only the assigned signatory (never admins) once this specific deadline is reached, guarded by its own `clearance_signing_due_notified_at` flag.

---

## 7. Clearance and Signature Processing

Every approve/decline action — checklist, General Signatory, or Final Approval — is gated on the actor having a usable e-signature (`User::hasUsableSignature()`, i.e. `signature_path` is set). If missing, the action redirects the actor to upload one first (or, for `fetch()`-driven flows, returns a 422 with a clear message).

| Actor | Can act when… |
|---|---|
| Clearance Signatory / Department Head | They're the checklist's `employee_id` owner (or an authorized delegate), the checklist status is `pending`/`viewed`, and they have a usable signature. |
| Immediate Head | Same as above, for checklists with `is_immediate_head_checklist = true`, where they're the request's own designated Immediate Head. |
| General Signatory | They're referenced by `general_signatories.clearance_signatory_id` on a `pending` `OffboardingRequestGeneralSignatory` row, with a usable signature. |
| Final Approver | The offboarding request's `status` is already `'completed'`, an active `FinalApprover` row exists, they ARE that active row (or an admin), and they have a usable signature. |

A signature only ever renders on the **Clearance Form** once the corresponding approval is genuinely resolved (`approved` or `declined` — both count as "done," never a still-pending item). The Clearance Form (`ClearanceFormController`) is generated **on demand** from live data — not automatically emailed at a fixed point in the process, except as an attachment on the Final Approval email. It merges a single person's status across *every* responsibility they hold on the request (multiple checklists, General Signatory role, etc.) so their signature only shows once everything they're responsible for is cleared. Signature images are read from `Storage::disk('public')` and embedded as base64 data URIs (dompdf can't reliably fetch remote/local URLs).

---

## 8. Notifications and Emails

### System (in-app) notifications

Laravel's built-in database notification channel (bell icon, `notifications` table) — 3 classes, all `database`-channel only:

| Notification | Sent by | Recipients | Purpose |
|---|---|---|---|
| `OffboardingApprovalUpdated` | `ApprovalController` (on approve/decline) | All admins | "An approval action happened, here's the request" |
| `ChecklistOverdueNotification` | `app:notify-overdue-checklists` | Admins, the Department Head, per-item signatories | Checklist passed its `due_at` |
| `ClearanceSigningDueNotification` | `app:notify-clearance-signing-due` | Only the assigned signatory (never admins) | Checklist/General Signatory's `clearance_signing_due_at` reached |

### Email notifications

Two mechanisms coexist:
1. **`EmailTemplate`-driven** — an admin-editable database table (`email_templates`) with a placeholder system (`{{approver_name}}`, `{{due_date}}`, `{{checklist_name}}`, ~40 supported tokens — see `EmailTemplate::render()`'s docblock). Rendered content is wrapped by the generic `ChecklistSignatoryAnnouncementMail` Mailable and used across essentially every notification/reminder/assignment email in the app. Several fixed-name templates are seeded via migration (9 confirmed: Offboarding Details Notification, Follow-Up Notification, Final Approval Request, Checklist Ready for DH Approval, Checklist Due Date Extended, Offboarding Request Cancelled, Checklist Signatory Declined, Final Pay Checklist Completed, Clearance Signing Due Reached) — **`Offboarding Overdue Notice` has no seed migration and must be created manually in the admin UI**, or `app:notify-overdue-checklists` degrades to in-app-notification-only.
2. **Purpose-built Mailables** with fixed Blade views: `ChecklistItemApproverAssignedMail`, `ChecklistReadyForApprovalMail`, `ChecklistGroupApprovedMail`, `FinalApprovalCompletedMail`, `FinalApprovalRequestMail` (also attaches the Clearance Form PDF/screenshot), `PasswordResetMail`.

### Notification triggers, at a glance

- **Assignment** — item/task assigned (`ChecklistItemApproverAssignedMail`, plus new-account credentials if applicable).
- **Approval** — checklist group fully approved (`ChecklistGroupApprovedMail` to the request creator), Final Approval given (`FinalApprovalCompletedMail`).
- **Overdue** — `app:notify-overdue-checklists`, every 5 minutes.
- **Clearance signing due** — `app:notify-clearance-signing-due`, every 5 minutes.
- **Due-date extension** — via the `Checklist Due Date Extended` template, sent from `ApprovalController::extendAllDue()`.
- **Cancellation / retraction** — via the `Offboarding Request Cancelled` template.
- **Scheduled** — `app:send-scheduled-email-templates` (request-level, daily 08:00) and `app:send-scheduled-checklist-item-notifications` (item-level, daily 08:10), both driven by an `EmailTemplate`'s own "N days before/after Last Working Day" or recurring schedule.
- **Reminders** — `app:send-checklist-reminders`, every 15 minutes, per-checklist, off that checklist's own current `due_at`.

### `MAIL_REDIRECT_TO`

A QA/staging-only `.env` variable — when set, `App\Listeners\RedirectMailInNonProduction` redirects **every** outgoing email to that one address instead of its real recipient(s). Never set this in production.

---

## 9. Scheduled Tasks / Scheduler

Defined in `routes/console.php` (Laravel 12's slimmed skeleton has no `app/Console/Kernel.php`). **Requires the OS-level scheduler to actually be running** (`php artisan schedule:run` every minute, via cron or Windows Task Scheduler) — the registrations below are inert without it.

| Command | Schedule | What it does | Guard against duplicates |
|---|---|---|---|
| `app:notify-overdue-checklists` | every 5 min | Notifies admins/Department Head/per-item signatories once a checklist passes `due_at` | `overdue_notified_at` |
| `app:notify-clearance-signing-due` | every 5 min | Notifies the assigned signatory once `clearance_signing_due_at` is reached | `clearance_signing_due_notified_at` |
| `app:send-scheduled-email-templates` | daily 08:00 | Sends any active, scheduled `EmailTemplate` whose date trigger is due, per active request | `email_template_scheduled_sends` rows |
| `app:send-scheduled-checklist-item-notifications` | daily 08:10 | Same, but per checklist item's own scheduled notification config | `checklist_item_scheduled_sends` rows |
| `app:send-checklist-reminders` | every 15 min | Per-checklist reminder off that checklist's own current due date, per its configured reminder cycle | `checklist_reminder_logs` rows |

All 5 command classes in `app/Console/Commands/` are wired into the schedule above — none exist unscheduled.

---

## 10. Database Structure

### Core offboarding tables

| Table | Purpose |
|---|---|
| `offboarding_requests` | The root record of one employee's offboarding lifecycle. Freezes separation type/notice period at creation; tracks `status` (`pending`/`in_progress`/`completed`/`cancelled`, plus a computed `overdue`); holds Last Working Day (current + original, for Extend-Due history) and per-request email template overrides. |
| `offboarding_request_approvers` | Join of one request ↔ one attached `checklist_template`, PLUS the full approval-state machine for it: `status`, delegation, `due_at`/`clearance_signing_due_at` (two independent deadlines), decline reason, notification guards. |
| `checklist_item_progress` | Per-item completion state within one checklist approval — checked/hold status, remark, who acted, optional head-approval sign-off. |
| `checklist_item_assignments` | Per-request snapshot/override of who's actually assigned to a given checklist item (`active`/`superseded`), distinct from the template's static default signatory. |
| `checklist_due_date_extensions` | One permanent audit row per "Extend Due" action — previous/new due date, who did it, why. |
| `offboarding_activities` | Central audit/activity log for a request's entire lifecycle — feeds the Offboarding Status/Timeline view. |

### Checklist definition tables

| Table | Purpose |
|---|---|
| `checklist_templates` | A reusable checklist definition — owner (employee or Employee Master group), department scope, Final Pay/Immediate Head/"Use Task Assignee as Signatory" flags, `sequence_type`, due-date config, own optional scheduled-notification settings. |
| `checklist_items` | An individual task line within a template, optionally with its own signatory. |
| `checklist_assignments` | Plain pivot: which templates are attached to which requests (distinct from `offboarding_request_approvers`, which is the *approval-state* table for the same pairing). |

### General Signatory tables (independent clearance track)

| Table | Purpose |
|---|---|
| `general_signatories` | Company-wide clearance role, not tied to any checklist template or department — its own `sequence_type`/`is_final_pay_signatory` staging. |
| `general_signatory_tasks` | Informational task list under a General Signatory (not individually checked off). |
| `offboarding_request_general_signatories` | Join + approval-state machine, the General Signatory equivalent of `offboarding_request_approvers`. Snapshotted at attach time. |

### People / organization tables

| Table | Purpose |
|---|---|
| `users` | Login accounts. Spatie `HasRoles` for real role checks; `role` column kept only as a legacy label. `username`/`password` conventionally derived from an Employee's `employee_code_digits`. |
| `employees` | The Employee Master roster — every offboardee, signatory, head, and task assignee is ultimately an `Employee` row. `employee_code_digits` is the join key to `users.username`. |
| `employee_groups` | Admin-curated named group with one designated Group Head — used to assign a Clearance Signatory "as a group" on a checklist template. |

### Approval / signature / notification support tables

| Table | Purpose |
|---|---|
| `offboarding_request_final_approvals` | One-per-request Final Approval process row — status, who initiated/approved it, remarks. |
| `final_approvers` | Small config table: the single currently-active Final Approver. |
| `checklist_approval_tokens` / `general_signatory_approval_tokens` / `final_approval_tokens` | Hashed, expiring one-click email-approval links, one table per approval kind. |
| `email_templates` | Admin-managed, placeholder-driven HTML templates — see [§8](#8-notifications-and-emails). |
| `email_template_scheduled_sends` / `checklist_item_scheduled_sends` / `checklist_reminder_logs` | Send-tracking / dedupe tables for the three scheduled email commands. |
| `notifications` | Laravel's stock database-notifications table (polymorphic `notifiable_type`/`notifiable_id`). |
| `separation_types` | Admin-managed catalog for the request-creation picker — read once at creation and frozen; never read live afterward. |

### Other tables present but not deeply documented here

`checklist_delegations` (whole-checklist delegation event log), `department_heads` (legacy per-department registry, superseded by `employee_groups.group_head_employee_id` but still consulted as a fallback), `onboarding_checklist_templates`/`onboarding_checklist_items` (a separate, parallel *onboarding* feature — not offboarding), `password_reset_requests`, `checklist_follow_ups`, and the Spatie permission tables (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`). `checklist_assignment_pool_members` was created then dropped in a later migration — a since-abandoned feature, no longer part of the live schema.

---

## 11. Important Business Rules

- **No cross-validation between Notice Date and Last Working Day** — deliberately removed; do not reintroduce without confirming the business actually wants it.
- **`due_at` (checklist due date) and `clearance_signing_due_at` (clearance signing deadline) are independent fields** — never merge them. They were kept deliberately separate so a signatory can have a different sign-off deadline than the checklist's general due date.
- **A checklist template edited after a request has already attached it never retroactively changes that request** — items, signatories, and due-date configuration are all snapshotted at attachment time.
- **Decline is a completed action, not a block** — it never stops the offboarding request, never prevents Sync-mode staging or the Final-Pay/completion gate from proceeding, and the declined signature still appears on the Clearance Form.
- **Retracting a request is destructive** — it deletes working rows outright (approvers, General Signatory assignments, follow-ups, scheduled sends, activities) and reverts the employee to `active`. This is different from how "Cancellation" is described conceptually elsewhere in the UI; read `OffboardingRequestController`'s retract/cancel action carefully before touching it.
- **`offboarding_request.status` only ever becomes `'completed'` in one place**: `ChecklistCompletionService::checkFinalPayCompletion()`. Don't set it elsewhere.
- **Final Approval requires `status === 'completed'` first** — it is a sign-off layered after completion, not a trigger for it, and approving it does not itself change the request's status.
- **The assignable pool for delegation/task-assignment is always re-derived and re-validated server-side** from the checklist owner's own Employee Master group — never trust a client-submitted employee id list.
- **E-signature is required before any approve/decline action, everywhere** (`User::hasUsableSignature()`), including Final Approval.
- **Sync mode's staged (Primary → Secondary → Final Pay) workflow is implemented but not currently reachable via the UI** (its picker is commented out) — verify the current UI state before assuming Sync-mode behavior is in active use.

---

## 12. Development Setup

### Required software

- **PHP 8.2+**
- **Composer**
- **Node.js** + npm (for Vite/Tailwind build)
- **MySQL** (the app is configured for MySQL specifically — `config/database.php`'s own fallback is `sqlite`, but `.env.example` explicitly sets `mysql`)

### Steps

1. **Clone and install dependencies**
   ```bash
   composer install
   npm install
   ```

2. **Environment configuration**
   ```bash
   cp .env.example .env   # Windows: copy .env.example .env
   php artisan key:generate
   ```
   Review `.env` for these app-specific variables beyond the standard Laravel ones:
   - `OFFBOARDING_MAX_FOLLOW_UP_ATTEMPTS`, `OFFBOARDING_FOLLOW_UP_COOLDOWN_HOURS` — rate limits for the offboardee's "follow up" nudge.
   - `MAIL_REDIRECT_TO` — **QA/staging only**; when set, redirects every outgoing email to one address. Never set in production.
   - `APP_TIMEZONE=Asia/Manila` — due-date/notification calculations are timezone-sensitive; confirm this matches your deployment's intended timezone before changing it.

3. **Database setup**
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=your_database_name
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```
   ```bash
   mysql -u root -p -e "CREATE DATABASE your_database_name;"
   php artisan migrate
   ```
   Migrations include a number of `seed_*` migrations (roles/permissions, fixed-name email templates, separation types, a default Final Approver) — running `migrate` alone provisions everything needed to boot the app; there is no separate `db:seed` step required for baseline functionality.

4. **Storage link** (required — the app serves e-signatures and profile photos from `storage/app/public` via `Storage::disk('public')`)
   ```bash
   php artisan storage:link
   ```

5. **Run it**
   ```bash
   composer run dev
   ```
   This one command (via `npx concurrently`) starts `php artisan serve`, `php artisan queue:listen --tries=1`, `php artisan pail` (log viewer), and `npm run dev` (Vite) together. Visit `http://localhost:8000`.

   For the scheduler to actually run anything from [§9](#9-scheduled-tasks--scheduler) locally, you also need `php artisan schedule:work` running in a separate terminal (or a cron/Task Scheduler entry calling `php artisan schedule:run` every minute).

---

## 13. Common Commands

### Composer

```bash
composer install                 # install PHP dependencies
composer run dev                 # serve + queue:listen + pail + vite, all at once
composer run test                # config:clear then php artisan test
```

### NPM

```bash
npm install
npm run dev       # Vite dev server with HMR
npm run build     # production build
```
No `lint`/`format`/`preview` scripts are currently defined in `package.json` — don't assume they exist.

### Artisan

```bash
php artisan migrate                    # run migrations
php artisan migrate:fresh               # drop everything and re-migrate (destroys data — dev only)
php artisan storage:link                # required once, for e-signatures/photos
php artisan schedule:work               # run the scheduler locally (needed for §9's jobs)
php artisan queue:work                  # process queued jobs
php artisan test                        # run the Pest test suite
php artisan route:list                  # inspect registered routes/permissions
php artisan tinker                      # REPL — useful for inspecting model state directly
php artisan optimize:clear               # clear all caches (config/route/view) — first troubleshooting step
```

---

## 14. File and Folder Structure

```
app/
├── Console/Commands/     # the 5 scheduled commands — see §9
├── Exceptions/           # FollowUpNotAllowedException (used by ChecklistFollowUpService)
├── Helpers/              # MenuHelper
├── Http/
│   ├── Controllers/      # 28 controllers — one per functional area (Approvals, ChecklistTemplate,
│   │                     #   ChecklistDelegation, GeneralSignatory(+Approval), FinalApproval,
│   │                     #   ClearanceForm, OffboardingRequest, Offboardee, EmailTemplate, ...)
│   └── Middleware/       # EnsurePasswordChanged, EnsureUserHasRole
├── Listeners/            # RedirectMailInNonProduction (MAIL_REDIRECT_TO)
├── Mail/                 # 7 Mailables — see §8
├── Models/               # 28 Eloquent models
├── Notifications/        # 3 database-notification classes — see §8
├── Policies/             # empty — no Laravel Policies used (Spatie permission is the real gate)
├── Providers/            # AppServiceProvider only (Laravel 12 slimmed skeleton)
├── Rules/                # empty — no custom validation Rule classes
├── Services/             # ChecklistApprovalNotifier, ChecklistCompletionService, ChecklistFollowUpService
└── View/Components/      # Blade component classes (common/, form/, header/, profile/, ui/)

database/
├── migrations/           # 163 files — the authoritative schema history; see §10
└── factories/            # used by dev/test fixtures (Employee, OffboardingRequest, User)

resources/
├── views/
│   ├── pages/            # one full routed page per feature area
│   └── components/       # reusable pieces, mirrored subfolder structure (approvals/, offboarding/, ...)
├── js/
│   ├── app.js            # entry point — registers Alpine/ApexCharts/flatpickr/FullCalendar/SweetAlert2
│   └── components/       # feature-specific Alpine components (offboarding-request.js, email-workspace.js, ...)
└── css/

routes/
├── web.php                # all HTTP routes, individually gated with `permission:` middleware
└── console.php             # scheduled command registrations — see §9
```

**Where to look when modifying something:**
- Checklist/approval business logic → `app/Services/ChecklistApprovalNotifier.php`, `ChecklistCompletionService.php`, and `app/Http/Controllers/ApprovalController.php`/`ChecklistDelegationController.php`.
- Due dates/extensions → `OffboardingRequestApprover`/`OffboardingRequestGeneralSignatory` models (`recalculateClearanceSigningDueDate()` etc.) and `ApprovalController::extendAllDue()`.
- Email content → `app/Models/EmailTemplate.php` (the token system) and the admin UI under `resources/views/pages/email-templates/`.
- Anything time-triggered → `app/Console/Commands/` + `routes/console.php`.
- Permissions → the `permission:` middleware entries in `routes/web.php`, cross-referenced against the seed migrations under `database/migrations/*permission*.php`.

---

## 15. Troubleshooting / Known Issues

- **"Class not found" after pulling changes** → `composer dump-autoload`.
- **Blade template changes not showing up** → `php artisan view:clear` (Blade views are compiled and cached; a stale compiled cache is a common source of confusion when a `.blade.php` edit doesn't seem to take effect).
- **Scheduled jobs never fire** → confirm the OS-level scheduler is actually running (`php artisan schedule:work` locally, or a cron/Task Scheduler entry hitting `php artisan schedule:run` every minute in production) — the `Schedule::command(...)` registrations in `routes/console.php` do nothing on their own.
- **Overdue notification emails aren't sending** → check whether the `Offboarding Overdue Notice` `EmailTemplate` actually exists and is active; there's no seed migration for it, so it must be created manually via the admin UI. The command still sends the in-app notification either way.
- **Signature images not appearing on the Clearance Form / broken avatar-style images** → confirm `php artisan storage:link` has been run; signatures/profile photos are served from `storage/app/public` via a symlink at `public/storage`, and this step is not automated by any composer/artisan script.
- **A newly-created employee/approver can't log in** → the convention is username = password = `employee_code_digits` (the `employee_code` with its `"EMP"` prefix stripped) — check `Employee.employee_code`/`employee_code_digits` are set correctly, and that a matching `users.username` row actually got auto-provisioned (`User::findOrCreate*()`).
- **Emails going to the wrong address in a QA/staging environment** → check whether `MAIL_REDIRECT_TO` is set; if so, every email is being redirected there regardless of its real recipient. Never leave this set in production.
- **A permission-gated page 403s unexpectedly** → cross-reference the specific `permission:` string on that route (`routes/web.php`) against what's actually synced to the user's role (`database/migrations/*permission*.php`, or inspect live via `php artisan tinker` → `$user->getAllPermissions()`).
- **Database connection errors** → verify `.env` DB credentials, that the MySQL server is running, and that the configured database actually exists — `config/database.php`'s own fallback is SQLite, which can mask a missing `.env` value in confusing ways.

---

## 16. Developer Guidelines

- **Preserve existing business rules.** Several behaviors documented in [§11](#11-important-business-rules) exist because of explicit, deliberate decisions (removed cross-field validation, decline-as-completed-not-blocking, destructive retract) — don't "fix" them back to what might look more conventional without confirming the change is actually wanted.
- **Check related modules before changing shared logic.** `ChecklistCompletionService` and `ChecklistApprovalNotifier` are consulted from many call sites (controllers, console commands, other services) — a change to their gating logic has wide blast radius. Grep for every caller before altering a method's contract.
- **Avoid duplicating assignment/approval logic.** The assignable-employee-pool derivation, the eligibility-for-pool-assignment check, and the e-signature gate are each implemented once, on the relevant model — reuse them (`OffboardingRequestApprover::isEligibleForPoolAssignment()`, `eligiblePoolAssigneeIds()`, `User::hasUsableSignature()`) rather than re-deriving the same rule inline in a controller.
- **Validate important operations on both frontend and backend.** Several UI gates (e.g. whether a bulk-assign button even renders) exist purely for UX — the corresponding controller action independently re-validates the same condition, so it can't be bypassed via a direct/crafted request. Follow this pattern for new mutating actions.
- **`due_at` and `clearance_signing_due_at` are separate deadlines — never conflate them** when adding new due-date-related features.
- **Snapshot, don't live-reference, at attachment time.** Checklist item signatories, separation type details, and the original Last Working Day are all frozen onto the request/assignment when it's created specifically so later catalog/template edits don't retroactively alter history. Follow this pattern for any new per-request configuration you add.
- **Prefer `fetch()` + JSON response + in-place Alpine state patching** over a native form POST + full page reload for new mutating UI actions on pages that already follow this convention (Approvals, Offboardee) — check the existing SweetAlert2 `showLoaderOnConfirm`/async `preConfirm` pattern used by Extend Due / Retract / bulk-assign before building a new one from scratch.
- **Update this document when introducing major processing changes** — especially to the Sync/Async workflow, the Primary/Secondary/Final Pay staging gates, due-date semantics, or the permission taxonomy.

---

## License

Refer to the [TailAdmin license page](https://tailadmin.com/license) for the underlying dashboard template's license terms. This application's own business logic is proprietary to the organization it was built for.
