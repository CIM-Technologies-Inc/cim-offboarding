# CIM OffBoarding — Task Assignee User Manual (alternate version)

**Version:** 1.0 &nbsp;|&nbsp; **Date:** October 21, 2026

This folder holds a second, independently-built Task Assignee User Manual, produced in response to a later, separately-worded request. A more comprehensive manual already exists at [`../task-assignee/`](../task-assignee/) (version 1.0, dated October 16, 2026, 9 screenshots, Markdown source + regeneration tooling) — see that folder's own `README.md` for its production process and accuracy notes. This folder is kept alongside it, not as a replacement, per the request that produced it.

## Files in this folder

| File | What it is |
|---|---|
| **`task-assignee-user-manual-v2.docx`** | The complete manual as a Microsoft Word document — 10 sections, 13 embedded screenshots, title page, closing quick-reference table, custom header/footer with page numbers. |
| **`screenshots/`** | The 13 screenshots embedded in the document. |

Unlike `../task-assignee/`, this version has no Markdown source and no regeneration script — it was assembled directly as a `.docx` via the `docx` npm package. To update it, the document would need to be rebuilt from scratch rather than edited from a source file.

## Scope

Covers, step by step with a screenshot for each: signing in; navigating to Approval under Dashboard & Reports; viewing a checklist card and opening it (View Checklist); checking/clearing a task (tick the checkbox, optional remark, Submit — locked once submitted); placing a task on hold (a remark is required before Hold becomes clickable); claiming a task visible on the card but not yet assigned ("Process Task List," with its confirmation prompt); and viewing the full offboarding timeline (View Checklist Status).

A closing section explains, in plain text (no screenshot needed), what happens once a Task Assignee finishes their own tasks: the checklist's Clearance Signatory is notified automatically once every task on the checklist is checked, but the checklist itself **stays visible** on the Task Assignee's own Approval page — showing their tasks as completed — until the Clearance Signatory actually gives final approval, at which point it disappears from everyone's queue, including the Task Assignee's. This was verified directly against the application's own code (`checklist-modal.blade.php`'s `submitSelectedDone()`/`submitDone()`, neither of which removes the card from view) rather than assumed, since it does not immediately disappear the moment a Task Assignee finishes their own portion.

## How this manual was produced

1. Read the real controllers/views behind these actions (`ChecklistDelegationController`, the Approval page's card buttons, `checklist-modal.blade.php`'s per-item Hold/Submit/Process Task List logic) before capturing anything, so every button label and behavior described matches what the application actually does — including confirming the exact "View Checklist Status" label is specific to a Task Assignee viewer (a Clearance Signatory instead sees "Checklist Status").
2. Built one disposable demo offboarding request (`Demo Offboardee TaskManual`) against the real "Accounting Checklist" template, with two of its five tasks reassigned to a **fully synthetic** Task Assignee account (`Demo Task Assignee Manual`, employee code `TADEMOASSIGNEE`) created specifically for this manual — not a real employee's account — so its Approval page could never show any other real employee's work. Playwright drove the real UI for every screenshot: checking one task, holding another, and claiming a third (still assigned to the checklist's real Clearance Signatory) via Process Task List.
3. All demo data — the synthetic offboardee, the synthetic Task Assignee employee and user account, the offboarding request, and its checklist assignment/reassignment records — was deleted once screenshots were captured and verified. No real employee's personal data appears in this manual.
