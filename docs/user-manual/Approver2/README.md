# CIM OffBoarding — Approver User Manual (alternate version)

**Version:** 1.0 &nbsp;|&nbsp; **Date:** October 21, 2026

This folder holds a second, independently-built Approver User Manual, produced in response to a later, separately-worded request. A more comprehensive manual already exists at [`../approver/`](../approver/) (version 1.0, dated October 16, 2026, 33 screenshots, Markdown source + regeneration tooling) — see that folder's own `README.md` for its production process and accuracy notes. This folder is kept alongside it, not as a replacement, per the request that produced it.

## Files in this folder

| File | What it is |
|---|---|
| **`approver-user-manual-v2.docx`** | The complete manual as a Microsoft Word document — 13 sections, 20 embedded screenshots, title page, closing quick-reference table, custom header/footer with page numbers. |
| **`screenshots/`** | The 20 screenshots embedded in the document. |

Unlike `../approver/`, this version has no Markdown source and no regeneration script — it was assembled directly as a `.docx` via the `docx` npm package. To update it, the document would need to be rebuilt from scratch rather than edited from a source file.

## Scope

Covers, step by step with a screenshot for each: signing in; the Approvals page; opening a checklist via View Checklist; checking items and Save Progress; Decline (with required remark); Remove On Hold (with required remark); Approve (enabled only once every item is checked); the success toast and the checklist disappearing from the Approvals list afterward; the notification bell; the Checklist Status view; the Assign Checklist modal; and approving a checklist via the one-click email link.

## How this manual was produced

1. Read the real controllers/routes behind these actions (`ApprovalController`, `ChecklistDelegationController`, `ChecklistApprovalToken`-based email approval) before capturing anything, so the steps described match what the application actually does.
2. Built three disposable demo offboarding requests (`Demo Offboardee Alpha/Bravo/Charlie`, employee codes `DEMOMANUALA/B/C`) against a real approver account (Juana Lemintang Espere), using Playwright to drive the real UI for every screenshot. The account's password was temporarily changed to a demo value for login and restored to its original hash immediately afterward.
3. All demo employees, offboarding requests, and the one manually-created email-approval token were deleted once screenshots were captured and verified. No real employee's personal data appears in this manual beyond names already visible in the approver account used (Juana Lemintang Espere), which is a real production account used for its UI, not depicted with any invented personal data.
