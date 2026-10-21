# Screenshot index

Every screenshot below was captured from the live, running CIM OffBoarding application. To comply with this manual's explicit requirement to never expose real employee personal information, all of them were captured using a small set of **fabricated, clearly-fictional sample records** created specifically for this documentation:

- Employee `DOCTEST001` — "Maria Dela Cruz Santos," the sample offboardee.
- Employee `DOCTEST002` — "Pedro Reyes Garcia," the sample Clearance Signatory / Approver.
- Employee `DOCTEST003` — "Juan Dela Torre," a throwaway account used only to demonstrate the first-login Change Password screen.
- A sample "IT Clearance Checklist" checklist template and a sample offboarding request tying the two together.

**Every one of these records was deleted from the system immediately after the screenshots were taken.**

Several pages in this application show data that cannot be scoped to sample records alone (lists/tables that always include every real record system-wide, with no per-admin filter). Where that happened, the specific real names, employee numbers, or email addresses were **blurred directly in the image** before saving — the surrounding page layout and every other field stays fully legible, so the screenshot still genuinely illustrates what the page looks like and what information it contains, without revealing who any of it actually belongs to. This was necessary on:
- `01-dashboard.png` — the "Recent Offboarding Requests" table (blurred 4 real employee rows).
- `04-checklist-templates-list.png` — the General Signatory table and the Final Approver table (blurred all real rows in both).
- `03-employee-directory.png` — two real Group Head names still visible above the filtered directory table.
- `11-email-templates.png` — the "Employees" placeholder-insertion panel (blurred the entire real employee list).
- `14-user-profile.png` — the Admin account's own real email address.

## Index

| File | Shows |
|---|---|
| `02-signin.png` | The Sign-In page. |
| `03-change-password.png` | The forced first-login Change Password page. |
| `01-dashboard.png` | The Admin Dashboard (recent-requests table partially blurred — see above). |
| `14-user-profile.png` | The User Profile page (own email address blurred — see above). |
| `15-signature-upload.png` | The "Upload E-Signature" modal. |
| `04-checklist-templates-list.png` | The Offboarding Checklist page's full three-section layout (General Signatory and Final Approver tables blurred — see above). |
| `05-checklist-template-create.png` | The "New Checklist Template" creation form. |
| `06-offboardees.png` | The Offboardee page, filtered to the sample request. |
| `07-status-timeline-modal.png` | The Offboarding Status/Timeline modal. |
| `08-new-offboarding-request.png` | The "New Offboarding Request" modal. |
| `03-employee-directory.png` | Employee Master's Groups grid and Employee Directory, filtered to the sample records (2 real Group Head names blurred — see above). |
| `02-add-new-employee.png` | The "Add New Employee" modal (empty form). |
| `16-approval-admin-view.png` | The Approval page, Admin's system-wide view. |
| `11-email-templates.png` | The Email Templates workspace (real employee insertion panel blurred — see above). |
| `18-separation-types.png` | The Separation Types page. |
| `19-reports.png` | The Reports page with a generated report, filtered to the sample offboardee. |
| `19-reports-filters.png` | The Reports page's filter panel, unfiltered (bonus — not referenced inline in the manual). |
| `10-roles-permissions.png` | The Roles & Permissions page. |
| `09-users.png` | The Users page, filtered to the 2 sample accounts. |
| `13-logs.png` | The Logs page, filtered to the sample scenario's own log entries. |
| `12-mail-settings.png` | The Mail Settings page, shown unconfigured (`Status: Inactive`, every field blank) so no real SMTP credentials of any kind are visible. |

## Screens intentionally not captured

- **Calendar page.** This page has **no admin-side filtering whatsoever** — it always displays every employee with a current or recent offboarding request, by real name, with no way to scope the view down to sample data alone. Capturing it live would have unavoidably exposed several real employees' names and offboarding dates in a saved, potentially-redistributed document. Its functionality is instead documented in full, in plain text, directly in the manual's Calendar section (§G) — view modes, what each event and badge means, and what the event-detail popup shows — based on direct inspection of the application's code (`CalendarController`, `calendar-init.js`, and the relevant Blade views).
- **A populated "Last Employee Master File Upload" card.** No Excel roster import has been run on this installation, so the live card currently reads "No employee master file has been uploaded yet." Running a real import purely to produce one screenshot was judged out of scope for a documentation task (and risks disrupting the real, live employee roster) — the populated-card field layout is instead documented from the application's own source code.
- **Forgot Password / Reset Password pages.** Exercising this flow sends a real password-reset email and requires intercepting a real, time-limited reset token to reach the reset form itself. The exact field labels, validation rules, and messages for both pages are documented verbatim from the source code in the manual instead.
- **"Login As User" (impersonation) banner.** Not captured for this Admin-focused manual, since none of its required sections depend on showing what an impersonated session looks like.
