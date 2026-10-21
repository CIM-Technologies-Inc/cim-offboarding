# CIM OffBoarding — Complete Offboarding Workflow

**Version:** 1.0 &nbsp;|&nbsp; **Revision date:** October 16, 2026

This document traces one offboarding request from creation through to Final Approval, exactly as the application actually executes it — including branches that only happen under certain configurations. It is the diagram-first companion to [`admin-user-manual.md`](admin-user-manual.md)'s §3; every step below is explained there in full procedural detail (exact button labels, fields, and validation rules).

> **Important:** every request created today runs in **Parallel (Async) mode.** A **Sequential (Sync)** mode exists in the application's underlying logic — described below for completeness — but the control for selecting it on the New Offboarding Request form is currently hidden from the interface, so it cannot actually be chosen today.

---

## Workflow diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│ 1. ADMIN creates an Offboarding Request                              │
│    Employee + Separation Type + Resignation Date + Last Working Day  │
└──────────────────────────────┬────────────────────────────────────────┘
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 2. System validates: active Final Approver configured?               │
│    Employee currently Active (not already offboarding)?              │
└──────────────────────────────┬────────────────────────────────────────┘
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 3. Request saved as Pending. Employee status → Offboarding.          │
│    Employee + Immediate Head login accounts created if needed.       │
└──────────────────────────────┬────────────────────────────────────────┘
                                 ▼
             ┌───────────────────┴────────────────────┐
             ▼ Parallel (actual, today)                ▼ Sequential (hidden today)
┌─────────────────────────────┐            ┌─────────────────────────────┐
│ Every applicable regular     │            │ Only "Support Group"        │
│ checklist (department-match- │            │ (Primary) checklists +      │
│ ed) + Core/Secondary General │            │ Core General Signatories    │
│ Signatories attached at once │            │ attached first              │
└───────────────┬───────────────┘            └───────────────┬───────────────┘
                 ▼                                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 4. Clearance Signatories / Task Assignees complete their checklists: │
│      check items → (Hold if blocked) → Approve / Decline             │
│    General Signatories independently: Approve / Decline their own    │
└──────────────────────────────┬────────────────────────────────────────┘
                                 │
                   ┌─────────────┴─────────────┐
                   ▼ Declined → On Hold          ▼ All approved
       ┌───────────────────────┐    (Sequential only: Secondary tier
       │ Admin/Signatory        │     attaches here, repeats step 4)
       │ reviews, removes hold  │
       │ with a reason          │
       └───────────┬───────────┘
                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 5. Once every regular checklist AND every regular General Signatory  │
│    is approved → Final Pay checklist(s) attach automatically         │
└──────────────────────────────┬────────────────────────────────────────┘
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 6. Final Pay checklist(s) approved + General Signatories' Final Pay  │
│    tier approved → Request status becomes Completed                  │
│    Employee status → Offboarded                                      │
└──────────────────────────────┬────────────────────────────────────────┘
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 7. ADMIN clicks "Final Approval" → email + in-app card sent to the   │
│    configured Final Signatory, with the Clearance Form attached      │
└──────────────────────────────┬────────────────────────────────────────┘
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 8. Final Signatory approves (in-app or via the emailed link)         │
│    Offboarding fully finished — Clearance Form shows every signature │
└─────────────────────────────────────────────────────────────────────┘
```

*(Retract and Extend Last Working Day — available at almost any point before completion — are summarized in the table just below the diagram.)*

---

## The 8 steps, with decision points and exception paths

### 1 — Create the request
An Admin selects an active employee, a Separation Type, an Immediate Head (auto-suggested), a Resignation Date, and a Last Working Day. See `admin-user-manual.md` §3, Step 2 for every field and validation rule.

### 2 — Validation gate
Two hard preconditions are checked before anything is saved: an **active Final Approver must already be configured somewhere in the system**, and the selected employee must currently be **Active** (not already mid-offboarding). Failing either blocks creation with a specific error message.

### 3 — Request created, accounts provisioned
Status becomes **Pending**, the employee's own status becomes **Offboarding**, and login accounts are auto-created (if missing) for the employee and the Immediate Head.

### 4 — Checklist/signatory attachment and completion (the decision point that actually matters)
This is where **Parallel vs. Sequential** genuinely changes behavior — though only Parallel is reachable from the UI today:
- **Parallel (actual):** every applicable regular checklist, plus the General Signatories' Core and Secondary tiers, attach simultaneously. Nobody waits on anybody else to start.
- **Sequential (hidden):** only Primary ("Support Group") checklists and Core General Signatories attach first; Secondary checklists and the Secondary General Signatory tier wait until every Primary item is approved, then attach and repeat this same step.

Within this step, each signatory works through: check items → optionally place an item or the whole checklist **On Hold** if blocked → **Approve** (or **Decline**, which is the same underlying action as placing the whole checklist On Hold). See `admin-user-manual.md` §3, Step 5 for the exact mechanics.

**Exception branch:** a declined/on-hold checklist pauses only itself — every other checklist on the request continues independently. An Admin or that checklist's own Clearance Signatory removes the hold (with a reason) to resume it.

### 5 — Final Pay checklists attach
Triggered automatically, the instant **every** regular checklist and **every** regular (non-Final-Pay) General Signatory is approved.

### 6 — Completion
Triggered automatically, the instant every Final Pay checklist **and** the General Signatories' own Final Pay tier is approved. Status → **Completed**, employee status → **Offboarded**.

### 7 — Final Approval sent
A manual Admin action — clicking **"Final Approval"** on the Offboardee page — only possible once step 6 has happened. Emails and in-app-notifies the configured Final Signatory, with the Clearance Form attached.

### 8 — Final Approval given
The Final Signatory approves either inside the application or via the emailed link — both are always available, there is no "email-only" restriction. This is the true end of the process.

---

## Branches available at nearly any earlier point

| Action | When it's available | Effect |
|---|---|---|
| **Retract** | Last Working Day hasn't passed **and** no checklist has been approved yet | Permanently closes the request as a read-only record (nothing deleted); employee reverts to Active. |
| **Extend Last Working Day** | At least one checklist is still pending with a due date (does not need to be overdue) | Moves the Last Working Day forward only; every pending checklist's due date recalculates automatically. |

For every exact button label, validation message, and field involved in each of these 8 steps, see the corresponding section of [`admin-user-manual.md`](admin-user-manual.md), particularly §3 ("The complete offboarding process, step by step").
