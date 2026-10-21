# CIM OffBoarding — Admin User Manual (documentation index)

**Version:** 1.0 &nbsp;|&nbsp; **Revision date:** October 16, 2026

This folder contains the complete Admin User Manual for CIM OffBoarding, written for HR personnel and system administrators. It was produced by directly inspecting the application's routes, controllers, Blade views, validation rules, and permissions — not by assuming how a feature is supposed to work — so every instruction, label, and message quoted here reflects what the application actually does today.

## Files in this folder

| File | What it is |
|---|---|
| **`admin-user-manual.md`** | The complete manual — editable Markdown source. Start here. |
| **`admin-user-manual.pdf`** | The same content, formatted as a printable, professionally-branded PDF. |
| **`admin-user-manual.docx`** | The same content as an editable Microsoft Word document (native Heading 1–4 styles, a Word Table of Contents field, styled tables, and embedded screenshots). |
| **`offboarding-workflow.md`** | A dedicated, diagram-first walkthrough of the end-to-end offboarding process (source). |
| **`offboarding-workflow.pdf`** | The printable version of the workflow walkthrough. |
| **`screenshots/`** | Every genuine application screenshot referenced by the manual, plus its own index explaining which real data was blurred and which screens were intentionally not captured, and why. See [`screenshots/README.md`](screenshots/README.md). |
| **`tools/`** | The scripts used to (re)generate the PDFs and the Word document from the Markdown source — see [`tools/README.md`](tools/README.md). |

## How to update this manual

1. Edit `admin-user-manual.md` or `offboarding-workflow.md` directly — both are plain Markdown with standard GitHub-flavored tables, headings, and image embeds.
2. If a screenshot needs to be refreshed (the application's interface changed), re-capture it from the running application using a throwaway sample scenario — **never save a screenshot containing a real employee's name, email, or any other personal or confidential data.** See `screenshots/README.md` for the exact sample-data convention already in use, which keeps future screenshots consistent with the existing ones.
3. Regenerate the PDFs and/or the Word document by running the relevant script in `tools/` (see `tools/README.md` for the exact commands).
4. Update the **Revision date** at the top of both `.md` files.

## Accuracy notes

A small number of places where the application's interface doesn't quite match its underlying logic are called out explicitly in the manual (clearly marked, not hidden), including:
- The Mail Settings "Test SMTP Connection" / "Send Test Email" buttons likely always fail validation regardless of correct SMTP settings.
- The Logs page's "Login Time"/"Session Duration" fields never populate for a successful login.
- The electronic signature upload's on-screen "max 2MB" text doesn't match the server's actual 1,900 KB limit.
- The Calendar page's own heading is spelled "Calender" in the application itself.
- There are only **3** report types on the Reports page, not 4 — there is no "All Offboarding Requests" report.

These are documented as-is because this was a **documentation-only task** — no part of producing this manual involved changing the application's code, database, or configuration.
