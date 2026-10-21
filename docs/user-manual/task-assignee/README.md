# CIM OffBoarding — Task Assignee User Manual (documentation index)

**Version:** 1.0 &nbsp;|&nbsp; **Revision date:** October 16, 2026

This folder contains the complete Task Assignee User Manual for CIM OffBoarding, written for employees handed individual checklist tasks within someone else's offboarding checklist, without owning the checklist itself. It was produced by directly exercising the running application end to end (every screenshot is a real, step-by-step walkthrough of sample data) and by reading the underlying controller code for every action described, so every instruction, button label, and status reflects what the application actually does today.

## Files in this folder

| File | What it is |
|---|---|
| **`task-assignee-user-manual.md`** | The complete manual — editable Markdown source. Start here. |
| **`task-assignee-user-manual.docx`** | The same content as an editable Microsoft Word document (native Heading 1–4 styles, a Word Table of Contents field, styled tables, and 9 embedded screenshots). This is the primary deliverable. |
| **`screenshots/`** | Every screenshot referenced by the manual, captured from the running application using disposable sample ("DOCTEST"-prefixed) employee and checklist data. |
| **`tools/`** | The script used to (re)generate the Word document from the Markdown source — see [`tools/README.md`](tools/README.md). |

## How this manual was produced

1. **The controller code was read first**, specifically to settle the one question the task hinged on: can a Task Assignee undo a completed task? `ChecklistItemProgress::syncForAssignment()` (`app/Models/ChecklistItemProgress.php`) settles this directly in its own comment — *"There is no 'uncheck' action anywhere in the app — once an item is checked, its checkbox and remark field are both disabled in the UI."* The manual documents this as a hard limitation (section 5) rather than inventing a workaround.
2. **A disposable test scenario** was built for a fictional employee ("Grace Villamor Santos," employee number `DOCTESTC01`) in its own uniquely-named department (`DOCTEST-TASKASSIGNEE-MANUAL`) so its one checklist template could never attach to a real employee's real offboarding request. A fictional Clearance Signatory account assigned two of the checklist's three tasks to a fictional Task Assignee account ("Elena Cruz Dimaano," `DOCTESTC03`) through the application's real "Assign To" flow, and that Task Assignee account then checked one task and placed the other on hold through the application's actual UI, via Playwright.
3. **Every screenshot is genuine**, not fabricated or edited to show a control that doesn't exist. No screenshot in this manual required blurring or omitting anything — a Task Assignee's own Approval page is scoped strictly to what's assigned to them, so no other real employee's data is ever visible.
4. **A real discrepancy was found and documented**, not hidden: the per-task Hold confirmation dialog states "The Department Head will be notified," but `ChecklistDelegationController::holdItem()` sends no such notification (email or otherwise) — only an activity-log entry. This is called out explicitly in section 6 of the manual.
5. **All sample data was deleted** immediately after the screenshots were captured and verified — the synthetic employees, checklist template, offboarding request, and accounts no longer exist in the application. No real employee's personal information, credentials, or private communications appear anywhere in this manual.

## How to update this manual

1. Edit `task-assignee-user-manual.md` directly — plain Markdown with standard GitHub-flavored tables, headings, and image embeds. Each screenshot is immediately followed, on the next line, by an italic `*Figure N — caption.*` line; keep that convention since the Word generator (see below) renders the two as one inline block.
2. If a screenshot needs to be refreshed (the application's interface changed), re-capture it from the running application using a throwaway sample scenario — **never save a screenshot containing a real employee's name, email, or any other personal or confidential data.** Reuse the same `DOCTEST`-prefixed naming convention and a department string that cannot match any real employee, so synthetic checklist templates can never attach to real offboarding requests while testing.
3. Regenerate `task-assignee-user-manual.docx` by running the script in `tools/` (see `tools/README.md` for the exact command).
4. Update the **Revision date** at the top of the manual.

## Accuracy notes

- **Clearing/unchecking a completed task is not available** — confirmed directly from the model code, not inferred from UI behavior alone. This is documented as a hard limitation, not a feature.
- **The per-task Hold confirmation dialog's own wording overstates what actually happens** — it says the Department Head "will be notified," but no notification is actually sent for this specific action.
- **A held task is never locked** — only a *checked* task is. A Task Assignee can still check, re-hold with a new reason, or otherwise edit a task that's currently on hold.
- **The Hold button requires the task's own Remark box to already contain text** before it becomes clickable — the remark typed there doubles as the hold reason; there is no separate prompt.

These are documented as-is because this was a **documentation-only task** — no part of producing this manual involved changing the application's code, database, or configuration.
