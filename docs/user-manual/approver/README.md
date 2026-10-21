# CIM OffBoarding — Approver User Manual (documentation index)

**Version:** 1.0 &nbsp;|&nbsp; **Revision date:** October 16, 2026

This folder contains the complete Approver User Manual for CIM OffBoarding, written for Clearance Signatories, Task Assignees, and General Signatories — anyone who signs in with an "Approver" account to review and act on offboarding checklists. It was produced by directly exercising the running application end to end (every screenshot is a real, step-by-step walkthrough of sample data, not a mockup) and by inspecting the underlying routes, controllers, and validation rules, so every instruction, button label, and message quoted in the manual reflects what the application actually does today.

## Files in this folder

| File | What it is |
|---|---|
| **`approver-user-manual.md`** | The complete manual — editable Markdown source. Start here. |
| **`approver-user-manual.docx`** | The same content as an editable Microsoft Word document (native Heading 1–4 styles, a Word Table of Contents field, styled tables, and 33 embedded screenshots). This is the primary deliverable. |
| **`screenshots/`** | Every screenshot referenced by the manual, captured from the running application using disposable sample ("DOCTEST"-prefixed) employee and checklist data. |
| **`tools/`** | The script used to (re)generate the Word document from the Markdown source — see [`tools/README.md`](tools/README.md). |

## How this manual was produced

1. **Research first.** The Approver role's actual permissions, the Approval module's controllers (`ApprovalController`, `ChecklistDelegationController`, `GeneralSignatoryApprovalController`), the e-signature validation rule, and the email-based approval routes were read directly from the application's source before any screenshot was taken, so the manual's claims about what's possible (and what isn't) are grounded in the real authorization logic, not assumption.
2. **A disposable test scenario.** A synthetic offboarding request was built for a fictional employee ("Liza Fernandez Cruz," employee number `DOCTESTB01`) in its own uniquely-named department (`DOCTEST-APPROVER-MANUAL`) so its checklist templates could never accidentally attach to a real employee's real offboarding request. A handful of fictional Approver accounts (a Clearance Signatory, two Task Assignees, and a General Signatory) worked through every real action documented here — checking items, assigning tasks, holding, removing hold, declining, and approving — through the actual application UI, via Playwright.
3. **Every screenshot is genuine**, not fabricated or edited to show a feature that doesn't exist. Where a screen unavoidably also shows real, pre-existing company data that has no admin-side filter (the Calendar), the real employees' names were blurred directly in the captured image rather than the screenshot being skipped, since the rest of that page's content was still needed to document the feature accurately.
4. **The email-based approval flow** (section 5 of the manual) was verified by actually triggering the "Checklist Ready for Department Head Approval" email through the application's own notification code (with the mail driver temporarily pointed at a local log file instead of real SMTP, purely to safely capture the generated email and its one-click approval link without sending real mail), then visiting that exact link in a logged-out browser session to confirm the no-login approval flow works end to end.
5. **All sample data was deleted** immediately after the screenshots were captured and verified — the synthetic employees, checklist templates, offboarding request, and Approver accounts no longer exist in the application. No real employee's personal information, credentials, or private communications appear anywhere in this manual.

## How to update this manual

1. Edit `approver-user-manual.md` directly — plain Markdown with standard GitHub-flavored tables, headings, and image embeds. Each screenshot is immediately followed, on the next line, by an italic `*Figure N — caption.*` line; keep that convention since the Word generator (see below) renders the two as one inline block.
2. If a screenshot needs to be refreshed (the application's interface changed), re-capture it from the running application using a throwaway sample scenario — **never save a screenshot containing a real employee's name, email, or any other personal or confidential data.** Reuse the same `DOCTEST`-prefixed naming convention and a department string that cannot match any real employee, so synthetic checklist templates can never attach to real offboarding requests while testing.
3. Regenerate `approver-user-manual.docx` by running the script in `tools/` (see `tools/README.md` for the exact command).
4. Update the **Revision date** at the top of the manual.

## Accuracy notes

A small number of places where the application's interface doesn't quite match its underlying logic are called out explicitly in the manual (clearly marked, not hidden), including:
- The signature upload modal's own text says "max 2MB," but the server actually enforces a smaller 1,900 KB limit.
- The Calendar page's own heading is spelled "Calender" in the application itself.
- "Decline" and "On Hold" describe the same event — there is no separate whole-checklist "Hold" button, only a per-item one.
- The one-click email approval link only ever offers Approve — there is no emailed Decline or Hold link.
- The Calendar page shows every offboarding employee company-wide, not just the ones assigned to the signed-in Approver, and has no filter to narrow it down.

These are documented as-is because this was a **documentation-only task** — no part of producing this manual involved changing the application's code, database, or configuration.
