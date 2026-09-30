<div x-data="{
        selected: null,
        checked: {},
        remarks: {},
        holdProcessing: {},
        takeOverProcessing: {},
        doneProcessing: {},
        approveProcessing: {},
        selectedApprove: {},
        doneBulkProcessing: false,
        approveBulkProcessing: false,
        // True from the instant a card is opened until `refreshCheckedItems()`'s
        // fetch(es) below have all settled — gates the entire task list
        // (Select checkboxes, status text, Hold/Done/Submit) behind a
        // loading skeleton (see the template below) so the approver only
        // ever sees ONE, already-validated UI state instead of the stale
        // page-load snapshot flashing first and then flipping once the real
        // persisted state comes back a few milliseconds later.
        selectedLoading: false,
        // Bumped on every `setSelected()` call and captured per-call as
        // `token` below — guards against a slow fetch from a PREVIOUSLY
        // opened card resolving after the approver has already closed it
        // and opened a different one, which would otherwise incorrectly
        // clear `selectedLoading` (or patch stale data into) the new card.
        selectedLoadToken: 0,
        setSelected(detail) {
            const checked = {};
            const remarks = {};
            const takeOverProcessing = {};
            const doneProcessing = {};
            const approveProcessing = {};
            const selectedApprove = {};
            (detail.checklistItems || []).forEach((item) => {
                checked[item.id] = !!item.checked;
                remarks[item.id] = item.remark || '';
                takeOverProcessing[item.id] = false;
                doneProcessing[item.id] = false;
                approveProcessing[item.id] = false;
                selectedApprove[item.id] = false;
            });
            // Populate reactive state BEFORE assigning `selected` — that
            // assignment is what triggers the x-if/x-for to render, so
            // every item's `checked`/`remarks`/`takeOverProcessing`/
            // `doneProcessing`/`approveProcessing` entry must already exist
            // by then for bindings (like the Hold and Check This List
            // buttons' :disabled) that key off them to track correctly from
            // their very first evaluation.
            this.checked = checked;
            this.remarks = remarks;
            this.holdProcessing = {};
            this.takeOverProcessing = takeOverProcessing;
            this.doneProcessing = doneProcessing;
            this.approveProcessing = approveProcessing;
            this.selectedApprove = selectedApprove;
            this.doneBulkProcessing = false;
            this.approveBulkProcessing = false;
            this.declining = false;
            // A decline reason draft is scoped to whichever card was open
            // when it failed — never carried over to a different card
            // opened afterward.
            this.declineReasonDraft = '';
            this.selected = detail;
            this.selectedLoading = true;
            this.refreshCheckedItems();
        },
        // The page's own checklist data is a static snapshot baked into the
        // page's HTML at render time — it never changes after load, so
        // reopening a card already acted on earlier this session (bulk-
        // submitted, held, approved) would otherwise show it as pending
        // again. Fetches the real persisted state for every distinct
        // assignment on this card BEFORE the task list/Select checkboxes
        // are ever shown (see `selectedLoading` above) and patches it in,
        // the same `Object.assign` convention every other fetch()-driven
        // action here already uses.
        refreshCheckedItems() {
            const token = ++this.selectedLoadToken;
            const urls = new Set((this.selected?.checklistItems || []).map((item) => item.checkedItemsUrl).filter(Boolean));

            const requests = Array.from(urls).map((url) => window.fetchWithTimeout(url, {
                headers: { 'Accept': 'application/json' },
            }).then(async (res) => {
                if (!res.ok) {
                    return;
                }
                const data = await res.json();

                (data.items || []).forEach((patch) => {
                    const target = (this.selected?.checklistItems || []).find((i) => i.id === patch.id);

                    if (target) {
                        Object.assign(target, patch);
                        this.checked[target.id] = true;
                    }
                });
            }).catch(() => {
                // Silent — a missing/failed freshness check simply leaves
                // that assignment's items at their already-correct
                // page-load snapshot rather than blocking the whole
                // checklist from ever leaving its loading state.
            }));

            Promise.allSettled(requests).then(() => {
                // A newer `setSelected()` call already bumped the token —
                // this stale resolution must not touch the now-different
                // card's `selected`/`selectedLoading` state.
                if (token !== this.selectedLoadToken || !this.selected) {
                    return;
                }

                this.selected.allItemsCompleted = (this.selected.checklistItems || []).every((i) => this.checked[i.id]);
                this.selectedLoading = false;
            });
        },
        // Submits the Done click via `fetch()` instead of a real form
        // navigation, so completing one item never closes/reloads this
        // dialog — the assignee can keep processing other items in the
        // same session. Sends ONLY this one item's own checklist_item_id/
        // is_checked/remark — never `new FormData(form)` — to the same
        // `saveProgressUrl` this card's Save Progress/Submit buttons post
        // to; the server tells apart this request from a real form
        // submission purely by the `Accept: application/json` header,
        // returning a JSON patch instead of its usual redirect. A whole-
        // form payload would also re-include every OTHER item's hidden
        // `checklist_item_id` field while silently omitting their
        // `is_checked` value the moment a prior Done click disables their
        // now-completed checkbox — indistinguishable server-side from an
        // explicit uncheck, and exactly what caused already-completed
        // items to revert to unchecked after a page refresh. Only THIS
        // item's own button disables while its request is in flight —
        // every other item, and the whole-form Save Progress/Submit
        // buttons, stay fully usable.
        submitDone(item) {
            if (this.doneProcessing[item.id]) {
                return;
            }

            this.doneProcessing[item.id] = true;

            const formData = new FormData();
            formData.append(`items[${item.id}][checklist_item_id]`, item.id);
            formData.append(`items[${item.id}][is_checked]`, '1');
            formData.append(`items[${item.id}][remark]`, this.remarks[item.id] || '');

            window.fetchWithTimeout(this.selected.saveProgressUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: formData,
            }).then(async (res) => {
                if (!res.ok) {
                    throw new Error('request failed');
                }

                const data = await res.json();

                (data.items || []).forEach((patch) => {
                    const target = (this.selected.checklistItems || []).find((i) => i.id === patch.id);

                    if (target) {
                        Object.assign(target, patch);
                        this.checked[target.id] = true;
                    }
                });

                this.selected.allItemsCompleted = (this.selected.checklistItems || []).every((i) => this.checked[i.id]);
                this.doneProcessing[item.id] = false;

                window.Swal?.fire({
                    toast: true,
                    position: 'bottom-end',
                    icon: 'success',
                    title: data.message || 'Task list successfully checked.',
                    showConfirmButton: false,
                    timer: 2000,
                    timerProgressBar: true,
                    customClass: { container: 'app-toast' },
                });
            }).catch((e) => {
                this.doneProcessing[item.id] = false;
                Swal.fire({
                    icon: 'error',
                    title: e?.name === 'AbortError'
                        ? 'This is taking longer than expected. Please check before trying again.'
                        : 'Failed to save task completion',
                    confirmButtonColor: '#145a3a',
                });
            });
        },
        // This viewer's own not-yet-checked items eligible for the Done
        // bulk flow below — deliberately the exact same predicate
        // `isDoneFlowItem()`'s per-item-approver branch uses, restricted to
        // items still unchecked, so bulk selection can only ever affect
        // tasks this ONE Task Assignee owns and hasn't completed yet —
        // never another approver's items on the same checklist.
        myDoneEligibleItems() {
            return (this.selected?.checklistItems || []).filter((item) => item.editable && !item.checked && !!item.usesPerItemApprovers && !!item.isOwnItem);
        },
        // True only when this card's per-item-approver items are split
        // across more than one approver — i.e. at least one item belongs
        // to someone else. When every item on the list is this viewer's
        // own (a per-item-approver checklist that happens to be assigned
        // entirely to one person), canEditAll() already covers the same
        // check-everything action via the plain Select All checkbox
        // above, so Select All My Tasks would be a redundant, confusing
        // duplicate of it and must stay hidden.
        hasOtherApproverItems() {
            return (this.selected?.checklistItems || []).some((item) => !!item.usesPerItemApprovers && !item.isOwnItem);
        },
        showDoneSelectAll() {
            if (this.isBulkSelectRestrictedToHead()) {
                return false;
            }
            return this.hasOtherApproverItems() && this.myDoneEligibleItems().length > 1;
        },
        // Selection lives ENTIRELY on `checked[item.id]` — the exact same
        // checkbox each task's own row already renders next to Hold/Done —
        // never a second, separate selection flag. Select All My Tasks just
        // toggles that one existing checkbox for every eligible item at
        // once; ticking/unticking one task by hand is indistinguishable
        // from doing it through the Select All control.
        doneAllChecked() {
            const items = this.myDoneEligibleItems();
            return items.length > 0 && items.every((item) => this.checked[item.id]);
        },
        doneNoneChecked() {
            return this.myDoneEligibleItems().every((item) => !this.checked[item.id]);
        },
        toggleDoneAll(value) {
            this.myDoneEligibleItems().forEach((item) => { this.checked[item.id] = value; });
        },
        showSubmitSelectedDone() {
            const items = this.myDoneEligibleItems().filter((item) => this.checked[item.id]);
            return this.doneAllChecked() || items.length > 1;
        },
        // Bulk counterpart to submitDone() — same fetch/patch-in-place
        // convention, same saveProgressUrl, same items[] payload shape
        // `ChecklistDelegationController::saveProgress()` already accepts,
        // just carrying every SELECTED item at once instead of one. No new
        // backend endpoint needed: `authorizeItemAction()` already scopes a
        // bare item-approver to their own items regardless of what the
        // client sends.
        submitSelectedDone() {
            if (this.doneBulkProcessing) {
                return;
            }
            const items = this.myDoneEligibleItems().filter((item) => this.checked[item.id]);
            if (items.length === 0) {
                return;
            }

            this.doneBulkProcessing = true;
            items.forEach((item) => { this.doneProcessing[item.id] = true; });

            const formData = new FormData();
            items.forEach((item) => {
                formData.append(`items[${item.id}][checklist_item_id]`, item.id);
                formData.append(`items[${item.id}][is_checked]`, '1');
                formData.append(`items[${item.id}][remark]`, this.remarks[item.id] || '');
            });

            window.fetchWithTimeout(this.selected.saveProgressUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: formData,
            }).then(async (res) => {
                if (!res.ok) {
                    throw new Error('request failed');
                }

                const data = await res.json();

                (data.items || []).forEach((patch) => {
                    const target = (this.selected.checklistItems || []).find((i) => i.id === patch.id);

                    if (target) {
                        Object.assign(target, patch);
                        this.checked[target.id] = true;
                    }
                });

                this.selected.allItemsCompleted = (this.selected.checklistItems || []).every((i) => this.checked[i.id]);
                items.forEach((item) => { this.doneProcessing[item.id] = false; });
                this.doneBulkProcessing = false;

                // Requirement: close the modal automatically once the
                // selected tasks are successfully submitted. `open` lives
                // on the NESTED x-ui.modal's own x-data scope, unreachable
                // as `this.open` from a method defined out here —
                // dispatched as a window event instead, same open/close
                // convention `@open-checklist-modal.window` already uses in
                // the other direction.
                window.dispatchEvent(new CustomEvent('close-checklist-modal'));

                window.Swal?.fire({
                    toast: true,
                    position: 'bottom-end',
                    icon: 'success',
                    title: data.message || `${items.length} task(s) successfully submitted.`,
                    showConfirmButton: false,
                    timer: 2500,
                    timerProgressBar: true,
                    customClass: { container: 'app-toast' },
                });
            }).catch((e) => {
                items.forEach((item) => { this.doneProcessing[item.id] = false; });
                this.doneBulkProcessing = false;
                Swal.fire({
                    icon: 'error',
                    title: e?.name === 'AbortError'
                        ? 'This is taking longer than expected. Please check before trying again.'
                        : 'Failed to save task completion',
                    confirmButtonColor: '#145a3a',
                });
            });
        },
        // True when the current viewer is either (a) literally the named
        // signatory for this item on a per-item-approver checklist, or (b)
        // the whole-checklist delegate the Clearance Signatory forwarded
        // this checklist to — both get a Done button alongside Hold, gated
        // on the checkbox above being checked, so a delegate can mark each
        // task item done exactly like a per-item signatory does instead of
        // only having the card-wide Save Progress button. The Department
        // Head/primary approver's own checkbox-only experience is
        // unaffected either way (`selected.isDelegate` is only ever true
        // for whoever the checklist was forwarded to). Read off the ITEM's
        // own originating checklist for the per-item-approver case (not the
        // card as a whole) since a combined card can mix per-item-approver
        // and legacy checklists together.
        isDoneFlowItem(item) {
            return (!!item.usesPerItemApprovers && !!item.isOwnItem) || !!this.selected?.isDelegate;
        },
        canHold(item) {
            return !!(this.remarks[item.id] || '').trim() && !this.holdProcessing[item.id];
        },
        // Submits via `fetch()` with a JSON `Accept` header (same convention
        // as `submitDone()` above) so a successful Hold patches just this
        // item's own state in place instead of `window.location.reload()`'ing
        // — the dialog stays open and every other item stays exactly as it
        // was, ready to keep working on. All existing validation (remark
        // required client-side here, re-validated server-side) and the
        // confirmation dialog are unchanged.
        holdItem(item) {
            const remark = (this.remarks[item.id] || '').trim();
            if (!remark || this.holdProcessing[item.id]) {
                return;
            }
            Swal.fire({
                title: 'Put Checklist on Hold?',
                html: 'This checklist item will be placed on Hold. The Department Head will be notified that this checklist item is currently on Hold.<br><br>Hold this checklist item?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Put on Hold',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#d97706',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }
                this.holdProcessing[item.id] = true;
                const formData = new FormData();
                formData.append('remark', remark);
                window.fetchWithTimeout(item.holdUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: formData,
                }).then(async (res) => {
                    if (!res.ok) {
                        throw new Error('request failed');
                    }

                    const data = await res.json();
                    // Deliberately patches only onHold/heldBy*/heldAt — never
                    // `editable`/`checked` — the item must stay fully
                    // available to check/clear later, exactly as it was
                    // before Hold.
                    Object.assign(item, data.item);
                    this.holdProcessing[item.id] = false;

                    window.Swal?.fire({
                        toast: true,
                        position: 'bottom-end',
                        icon: 'success',
                        title: data.message || 'Task list successfully placed on hold.',
                        showConfirmButton: false,
                        timer: 2000,
                        timerProgressBar: true,
                        customClass: { container: 'app-toast' },
                    });
                }).catch((e) => {
                    this.holdProcessing[item.id] = false;
                    Swal.fire({
                        icon: 'error',
                        title: e?.name === 'AbortError'
                            ? 'This is taking longer than expected. Please check before trying again.'
                            : 'Failed to place item on Hold',
                        confirmButtonColor: '#145a3a',
                    });
                });
            });
        },
        // The card behind this modal is server-rendered — nothing on the
        // list page itself is reactive, so once a checklist clears every
        // remaining gate and drops out of the pending/viewed queue, that
        // card would otherwise keep showing as actionable until the whole
        // page is reloaded. Removes it directly by the `data-card-id`
        // `pages/approvals/index.blade.php` stamps on every card (same id
        // as `selected.id`) — a no-op if the card isn't on screen (e.g. a
        // filtered-out search result) or already gone.
        removeListCard(cardId) {
            if (!cardId) {
                return;
            }
            document.querySelector(`[data-card-id='${CSS.escape(String(cardId))}']`)?.remove();
        },
        // The On Hold counterpart to removeListCard() above — a held
        // checklist must STAY on the list (see decline/removeHold below),
        // so instead of removing the card this patches its status badge
        // in place, same data-card-id targeting convention. Matches the
        // [data-status-badge] span pages/approvals/index.blade.php renders
        // per card from its own $statusBadges map — the classes/labels
        // passed in here are kept in sync with that map by hand.
        patchListCardBadge(cardId, badgeClass, badgeLabel) {
            if (!cardId) {
                return;
            }
            const badge = document.querySelector(`[data-card-id='${CSS.escape(String(cardId))}'] [data-status-badge]`);
            if (!badge) {
                return;
            }
            badge.className = `rounded-full px-2.5 py-1 text-xs font-medium ${badgeClass}`;
            badge.textContent = badgeLabel;
        },
        // The card behind this modal is otherwise server-rendered — its
        // click handler dispatches a static JSON snapshot baked into the
        // page at render time (see pages/approvals/index.blade.php's own
        // matching comment), so mutating `this.selected` in place only
        // ever fixes the CURRENTLY open modal — closing and reopening the
        // same card would silently re-dispatch the stale pre-decline
        // snapshot. Persisting the patch here, in a plain global cache
        // that card's click handler merges in on every open, keeps a
        // reopen of the SAME card correct too, without ever reloading the
        // whole page. Cleared naturally the next time the page is
        // genuinely reloaded (a fresh page load has no need for it — the
        // server already reflects the real, current status by then).
        rememberCardOverride(cardId, patch) {
            if (!cardId) {
                return;
            }
            window.__approvalCardOverrides = window.__approvalCardOverrides || {};
            window.__approvalCardOverrides[cardId] = Object.assign({}, window.__approvalCardOverrides[cardId] || {}, patch);
        },
        // Use Task Assignee as Clearance Signatory head-approval gate:
        // only shown to the specific Department/Group Head recorded on this
        // one item (item.isHeadApprover && item.headApprovalPending —
        // never the Task Assignee who checked it, and never anyone else's
        // item on this same checklist). Same fetch-and-patch-in-place
        // convention as Hold/Done above, via checkedItemPatches()'s own
        // response shape (an items array), same as submitDone() reads.
        approveHeadItem(item) {
            if (this.approveProcessing[item.id]) {
                return;
            }
            Swal.fire({
                title: 'Approve This Task?',
                html: 'By approving, you confirm this completed task meets requirements. This cannot be undone.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Approve',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#145a3a',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }
                this.approveProcessing[item.id] = true;
                window.fetchWithTimeout(item.approveHeadItemUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                }).then(async (res) => {
                    if (!res.ok) {
                        throw new Error('request failed');
                    }

                    const data = await res.json();

                    (data.items || []).forEach((patch) => {
                        const target = (this.selected.checklistItems || []).find((i) => i.id === patch.id);

                        if (target) {
                            Object.assign(target, patch);
                        }
                    });

                    this.approveProcessing[item.id] = false;

                    // Requirement: an approval action closes the modal
                    // automatically once it's genuinely done — same
                    // window-event convention declineChecklist()/
                    // submitSelectedDone() already use.
                    window.dispatchEvent(new CustomEvent('close-checklist-modal'));

                    if (data.assignmentStatus && data.assignmentStatus !== 'pending' && data.assignmentStatus !== 'viewed') {
                        this.removeListCard(this.selected?.id);
                    }

                    window.Swal?.fire({
                        icon: 'success',
                        title: 'Task List Approved Successfully',
                        text: data.message || 'The selected task list has been approved successfully.',
                        confirmButtonColor: '#145a3a',
                    });
                }).catch((e) => {
                    this.approveProcessing[item.id] = false;
                    Swal.fire({
                        icon: 'error',
                        title: e?.name === 'AbortError'
                            ? 'This is taking longer than expected. Please check before trying again.'
                            : 'Failed to approve task',
                        confirmButtonColor: '#145a3a',
                    });
                });
            });
        },
        // This viewer's own pending head-approval items eligible for the
        // bulk Approve flow below — the same predicate the single-item
        // Approve button (`item.isHeadApprover && item.headApprovalPending`)
        // already gates on, so bulk selection can only ever affect tasks
        // this ONE head is actually recorded as the approver of — never
        // another head's or another Task Assignee's items.
        myApproveEligibleItems() {
            return (this.selected?.checklistItems || []).filter((item) => item.isHeadApprover && item.headApprovalPending);
        },
        showApproveSelectAll() {
            return this.myApproveEligibleItems().length > 1;
        },
        approveAllChecked() {
            const items = this.myApproveEligibleItems();
            return items.length > 0 && items.every((item) => this.selectedApprove[item.id]);
        },
        approveNoneChecked() {
            return this.myApproveEligibleItems().every((item) => !this.selectedApprove[item.id]);
        },
        toggleApproveAll(value) {
            this.myApproveEligibleItems().forEach((item) => { this.selectedApprove[item.id] = value; });
        },
        showSubmitSelectedApprove() {
            const items = this.myApproveEligibleItems().filter((item) => this.selectedApprove[item.id]);
            return this.approveAllChecked() || items.length > 1;
        },
        // Bulk counterpart to approveHeadItem() — posts every SELECTED
        // item's {assignment_id, checklist_item_id} pair to the new
        // bulk-approve endpoint in one request. Partial failures (a race
        // with someone else, an item that's since changed state) are
        // surfaced explicitly via `data.failed` rather than silently
        // treated as success — only items in `data.items` (server-confirmed
        // approvals) are patched in place.
        submitSelectedApprove() {
            if (this.approveBulkProcessing) {
                return;
            }
            const items = this.myApproveEligibleItems().filter((item) => this.selectedApprove[item.id]);
            if (items.length === 0) {
                return;
            }

            Swal.fire({
                title: 'Approve Selected Tasks?',
                html: `By approving, you confirm these ${items.length} completed tasks meet requirements. This cannot be undone.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Approve',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#145a3a',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                this.approveBulkProcessing = true;
                items.forEach((item) => { this.approveProcessing[item.id] = true; });

                window.fetchWithTimeout('{{ route('approvals.items.bulk-approve-head') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        items: items.map((item) => ({ assignment_id: item.assignmentId, checklist_item_id: item.id })),
                    }),
                }).then(async (res) => {
                    if (!res.ok) {
                        throw new Error('request failed');
                    }

                    const data = await res.json();

                    (data.items || []).forEach((patch) => {
                        const target = (this.selected.checklistItems || []).find((i) => i.id === patch.id);

                        if (target) {
                            Object.assign(target, patch);
                            this.selectedApprove[target.id] = false;
                        }
                    });

                    items.forEach((item) => { this.approveProcessing[item.id] = false; });
                    this.approveBulkProcessing = false;

                    const failed = data.failed || [];

                    // Requirement: the modal closes automatically once
                    // processing succeeds — but only on a CLEAN submission
                    // (nothing in `failed`). A partial failure is
                    // deliberately not counted as fully successful
                    // processing — the modal stays open so the user can see
                    // exactly what still needs attention, same as
                    // declineChecklist()/approveHeadItem() only ever close
                    // on genuine success too.
                    if (failed.length === 0) {
                        window.dispatchEvent(new CustomEvent('close-checklist-modal'));

                        // Every assignment this submission touched, that's
                        // no longer sitting in the pending/viewed queue —
                        // for a headless checklist (always a group of one —
                        // see ApprovalController's own docblock) this is
                        // just the one card currently open.
                        Object.values(data.assignmentStatuses || {}).some((status) => status !== 'pending' && status !== 'viewed')
                            && this.removeListCard(this.selected?.id);
                    }

                    window.Swal?.fire({
                        icon: failed.length > 0 ? 'warning' : 'success',
                        title: failed.length > 0 ? 'Some Tasks Could Not Be Approved' : 'Task List Approved Successfully',
                        html: failed.length > 0
                            ? (data.message || '') + '<br><br>' + failed.map((f) => f.message).join('<br>')
                            : 'The selected task list(s) have been approved successfully.',
                        confirmButtonColor: '#145a3a',
                    });
                }).catch((e) => {
                    items.forEach((item) => { this.approveProcessing[item.id] = false; });
                    this.approveBulkProcessing = false;
                    Swal.fire({
                        icon: 'error',
                        title: e?.name === 'AbortError'
                            ? 'This is taking longer than expected. Please check before trying again.'
                            : 'Failed to approve selected tasks',
                        confirmButtonColor: '#145a3a',
                    });
                });
            });
        },
        // 'Check This List': only accepts/assigns this item to the current
        // approver — it must never check/complete it or submit the form, and
        // it must never reload the page (that would close this very modal).
        // The server responds with the item's new editable/canTakeOver state
        // plus the assignment's usesPerItemApprovers/mustBeCheckedForSubmit/
        // allItemsCompleted flags; patching those into `selected` in place
        // is what makes Hold/Done unlock immediately — Alpine's reactivity
        // re-renders every binding that reads them without any navigation
        // at all. The item itself stays unchecked; only Hold/Done (clicked
        // separately, afterward) ever changes that.
        takeOverItem(item) {
            if (this.takeOverProcessing[item.id]) {
                return;
            }
            this.takeOverProcessing[item.id] = true;
            window.fetchWithTimeout(item.takeOverUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            }).then(async (res) => {
                if (!res.ok) {
                    throw new Error('request failed');
                }
                const data = await res.json();
                Object.assign(item, data.item);
                this.selected.usesPerItemApprovers = data.usesPerItemApprovers;
                this.selected.allItemsCompleted = data.allItemsCompleted;
                // `usesPerItemApprovers`/`mustBeCheckedForSubmit` are per-
                // ASSIGNMENT flags (this item's own originating checklist,
                // not the whole combined card — see the matching comment on
                // the initial `checklistItems` payload), copied onto every
                // one of that assignment's items at page load. Before this
                // item had ever been taken over, its whole assignment may
                // have had no distinct signatory anywhere yet, so every item
                // on it — including this one — started out with these flags
                // false. `isDoneFlowItem()` reads `item.usesPerItemApprovers`
                // to decide whether to show Done at all, so leaving only
                // `selected`'s (card-wide) copy updated would take over the
                // item successfully yet never reveal its Done button. Patch
                // every sibling item on this SAME assignment so the freshly
                // (re)computed values are reflected immediately, exactly as
                // a page reload would show.
                (this.selected.checklistItems || []).forEach((sibling) => {
                    if (sibling.assignmentId === item.assignmentId) {
                        sibling.usesPerItemApprovers = data.usesPerItemApprovers;
                        sibling.mustBeCheckedForSubmit = data.mustBeCheckedForSubmit;
                    }
                });
                this.takeOverProcessing[item.id] = false;
            }).catch((e) => {
                this.takeOverProcessing[item.id] = false;
                Swal.fire({
                    icon: 'error',
                    title: e?.name === 'AbortError'
                        ? 'This is taking longer than expected. Please check before trying again.'
                        : 'Failed to accept this checklist item',
                    confirmButtonColor: '#145a3a',
                });
            });
        },
        allChecked() {
            if (!this.selected || !this.selected.checklistItems || this.selected.checklistItems.length === 0) {
                return true;
            }
            return this.selected.checklistItems.every((item) => this.checked[item.id]);
        },
        toggleAll(value) {
            (this.selected.checklistItems || []).forEach((item) => {
                if (item.editable) this.checked[item.id] = value;
            });
        },
        // On a Core/Primary checklist, bulk selection (both this and Select
        // All My Tasks below) is reserved for whoever is recorded as this
        // checklist's own Immediate/Group/Department Head (isPrimaryApprover
        // — an admin counts too, same as everywhere else it's used) — never
        // a regular Task Assignee/per-item signatory, no matter how many of
        // the individual tasks happen to belong to them alone. Secondary and
        // Final Pay checklists (isCorePrimaryChecklist false) are untouched
        // by this and keep their existing behavior.
        isBulkSelectRestrictedToHead() {
            return !!this.selected?.isCorePrimaryChecklist && !this.selected?.isPrimaryApprover;
        },
        canEditAll() {
            if (this.isBulkSelectRestrictedToHead()) {
                return false;
            }
            return !!this.selected && (this.selected.checklistItems || []).every((item) => item.editable);
        },
        // Save Progress follows the same Core/Primary Head-only rule as the
        // bulk-selection checkboxes above, with one addition: on a Core/
        // Primary checklist it is withheld from a delegate too, not just a
        // regular Task Assignee/per-item signatory -- someone the Head
        // forwarded the whole checklist to is still not themselves an
        // Immediate/Group/Department Head. Secondary and Final Pay
        // checklists (isBulkSelectRestrictedToHead() always false there)
        // keep the original isDelegate-or-isPrimaryApprover behavior, where
        // a delegate's Save Progress access is their only way to persist
        // partial work since they have no Submit authority of their own.
        canSaveProgress() {
            if (this.isBulkSelectRestrictedToHead()) {
                return false;
            }
            return !!(this.selected?.isDelegate || this.selected?.isPrimaryApprover);
        },
        canApprove() {
            // The Department Head (primary approver) may submit once every
            // item that MUST be checked before submission is checked.
            // `mustBeCheckedForSubmit` is computed per item, off that
            // item's own originating checklist — a legacy
            // (non-per-item-approver) checklist's items are never required
            // (submitting is itself the confirmation that clearance is
            // complete, same as always), while a per-item-approver or
            // Immediate Head checklist's items all are. A combined card can
            // freely mix both kinds of checklist; this generalizes cleanly
            // to a single, non-combined checklist too. Computed from the
            // live `checked` state (not a stale server flag) so the Submit
            // button re-enables the instant the last required box is
            // ticked, without needing a page reload.
            if (!this.selected) return false;
            return (this.selected.checklistItems || []).every((item) => !item.mustBeCheckedForSubmit || !!this.checked[item.id]);
        },
        // Declining now places the checklist ON HOLD (see
        // ApprovalController::decline()) — it does NOT complete/resolve
        // it, no signature is recorded, and it stays on this signatory's
        // own queue (never removed — see removeHold() below for the only
        // way out). A mandatory-reason confirm via SweetAlert2 (same
        // input:'textarea' convention the overdue-Submit flow above
        // already uses), then a fetch() POST so the modal never
        // closes/reloads — patched in place on success, with
        // rememberCardOverride()/patchListCardBadge() keeping the
        // underlying list card correct too (see those methods' own
        // docblocks). selected.declineUrl is null for a combined
        // multi-checklist card (decline stays per-checklist), so the
        // button itself is never even rendered in that case.
        declining: false,
        // Whatever the user last typed into the reason prompt — kept around
        // (never cleared on failure) purely so a failed attempt can be
        // retried without re-typing; cleared only once a decline actually
        // succeeds.
        declineReasonDraft: '',
        declineChecklist() {
            if (this.declining || !this.selected?.declineUrl) {
                return;
            }
            Swal.fire({
                title: 'Decline This Checklist?',
                text: 'This checklist will be placed ON HOLD and cannot proceed — all tasks, approval, and further decline actions will be disabled until you remove the hold. Please provide a reason.',
                icon: 'warning',
                input: 'textarea',
                inputLabel: 'Reason for Declining',
                inputPlaceholder: 'Explain why this checklist is being declined...',
                inputValue: this.declineReasonDraft,
                inputValidator: (value) => {
                    if (!value || !value.trim()) {
                        return 'A reason is required to decline this checklist.';
                    }
                },
                showCancelButton: true,
                confirmButtonText: 'Yes, Decline',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                // Immediately visible loading state — the button itself
                // reflects `declining` (spinner + Declining... text,
                // disabled), preventing a double-submit for the whole
                // round trip, not just while this dialog is open.
                this.declining = true;
                this.declineReasonDraft = (result.value || '').trim();

                const targetItem = this.selected;
                const formData = new FormData();
                formData.append('comment', this.declineReasonDraft);

                window.fetchWithTimeout(targetItem.declineUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: formData,
                }).then(async (res) => {
                    const data = await res.json();

                    if (!res.ok) {
                        throw new Error(data.message || 'request failed');
                    }

                    this.declining = false;

                    // Patched in place — the modal stays open and shows
                    // the On Hold state immediately, no reload/close.
                    // rememberCardOverride() also persists this patch so
                    // closing this modal and reopening the SAME card later
                    // (without a full page reload in between) still shows
                    // it correctly, and patchListCardBadge() keeps the
                    // underlying list card's own badge in sync too.
                    targetItem.isOnHold = true;
                    targetItem.declinedAt = data.declinedAt;
                    targetItem.declineReason = this.declineReasonDraft;
                    (targetItem.checklistItems || []).forEach((item) => {
                        item.editable = false;
                    });
                    this.rememberCardOverride(targetItem.id, {
                        isOnHold: true,
                        declinedAt: targetItem.declinedAt,
                        declineReason: targetItem.declineReason,
                        checklistItems: targetItem.checklistItems,
                    });
                    this.patchListCardBadge(targetItem.id, 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400', 'On Hold');

                    // Only cleared on genuine success, and only AFTER the
                    // patch above already read it — a failed attempt (see
                    // .catch below) deliberately leaves this alone so the
                    // next Decline click re-opens pre-filled with what was
                    // already typed.
                    this.declineReasonDraft = '';

                    window.Swal?.fire({
                        icon: 'success',
                        title: 'Checklist Placed On Hold',
                        text: 'The checklist has been placed on hold and the reason has been recorded. It will stay unavailable until you remove the hold.',
                        confirmButtonColor: '#145a3a',
                    });
                }).catch((e) => {
                    this.declining = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed to Decline Checklist',
                        text: e?.name === 'AbortError'
                            ? 'This is taking longer than expected. Please check before trying again.'
                            : (e?.message || 'The checklist could not be declined. Please try again.'),
                        confirmButtonColor: '#145a3a',
                    });
                });
            });
        },
        // The only way out of On Hold (see declineChecklist() above) —
        // the SAME signatory reviews and confirms, with its own mandatory
        // reason, independent of the original decline reason. Reloads the
        // page on success rather than patching state in place: unlike a
        // decline (which only ever needs to freeze items — a single,
        // uniform `editable = false`), correctly RESTORING each item's own
        // `editable`/`canTakeOver` would mean duplicating
        // `ApprovalController::index()`'s own per-item eligibility rules
        // (isPrimaryApprover/isDelegate/isOwnItem/isMonitoring) here in
        // JS — a rare, deliberate action, so a reload is the simpler and
        // provably-correct choice over re-deriving that logic client-side.
        removingHold: false,
        onHoldRemovalReasonDraft: '',
        removeHold() {
            if (this.removingHold || !this.selected?.onHoldRemoveUrl) {
                return;
            }
            Swal.fire({
                title: 'Remove On Hold?',
                text: 'This checklist will become available for review and action again. Please provide a reason.',
                icon: 'question',
                input: 'textarea',
                inputLabel: 'Reason for Removing the Hold',
                inputPlaceholder: 'Explain why this hold is being removed...',
                inputValue: this.onHoldRemovalReasonDraft,
                inputValidator: (value) => {
                    if (!value || !value.trim()) {
                        return 'A reason is required to remove this hold.';
                    }
                },
                showCancelButton: true,
                confirmButtonText: 'Yes, Remove Hold',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#145a3a',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                this.removingHold = true;
                this.onHoldRemovalReasonDraft = (result.value || '').trim();

                const formData = new FormData();
                formData.append('reason', this.onHoldRemovalReasonDraft);

                window.fetchWithTimeout(this.selected.onHoldRemoveUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: formData,
                }).then(async (res) => {
                    const data = await res.json();

                    if (!res.ok) {
                        throw new Error(data.message || 'request failed');
                    }

                    window.Swal?.fire({
                        icon: 'success',
                        title: 'Hold Removed',
                        text: 'The checklist is available for review again. Reloading...',
                        confirmButtonColor: '#145a3a',
                        timer: 1500,
                        showConfirmButton: false,
                    }).then(() => window.location.reload());
                }).catch((e) => {
                    this.removingHold = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed to Remove Hold',
                        text: e?.name === 'AbortError'
                            ? 'This is taking longer than expected. Please check before trying again.'
                            : (e?.message || 'The hold could not be removed. Please try again.'),
                        confirmButtonColor: '#145a3a',
                    });
                });
            });
        },
        // Live 5-days-before / on-or-after Clearance Signing Due Date
        // urgency, recomputed off the wall clock rather than once at page
        // load — nowTick ticks every minute so a signatory who leaves this
        // modal open sees the label/value escalate to orange then red
        // without needing to refresh. Day-level thresholds only, so a
        // one-minute tick is far more than enough granularity.
        nowTick: Date.now(),
        init() {
            setInterval(() => {
                this.nowTick = Date.now();
            }, 60000);
        },
        clearanceSigningUrgency(iso) {
            if (!iso) {
                return 'none';
            }
            const due = new Date(iso).getTime();
            if (Number.isNaN(due)) {
                return 'none';
            }
            if (this.nowTick >= due) {
                return 'danger';
            }
            if (due - this.nowTick <= 5 * 24 * 60 * 60 * 1000) {
                return 'warning';
            }
            return 'none';
        },
    }" @open-checklist-modal.window="setSelected($event.detail)">
    <x-ui.modal x-data="{ open: false }" @open-checklist-modal.window="open = true" @close-checklist-modal.window="open = false" :isOpen="false" class="w-full sm:max-w-[50vw]">
        <!-- Flexible height: compact for a short checklist, growing up to
             85% of the viewport for a long one, at which point only the
             item list below scrolls internally — the header above and the
             action buttons below always stay in view. Mirrors the same
             pinned-header/scrollable-middle/pinned-footer pattern already
             used by the Offboarding Status/Timeline modal. -->
        <div class="relative flex max-h-[85vh] w-full flex-col rounded-3xl bg-white dark:bg-gray-900" x-show="selected" x-cloak>
            <template x-if="selected">
                <div class="flex min-h-0 flex-1 flex-col">
                <div class="shrink-0 p-6 pb-0 lg:p-8 lg:pb-0">
                    <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90" x-text="selected.name"></h4>

                    <!-- Loading skeleton — shown from the instant this card opens until
                         refreshCheckedItems() confirms the REAL, current task state (checked/
                         completed/assigned) from the server. Everything below that depends on
                         that state (Select checkboxes, status text, Hold/Done/Submit) stays
                         hidden until then, so the approver only ever sees one, already-correct
                         UI state instead of the stale page-load snapshot briefly flashing first. -->
                    <div x-show="selectedLoading" class="animate-pulse space-y-3 pb-6" aria-hidden="true">
                        <div class="h-4 w-2/3 rounded-md bg-gray-200 dark:bg-gray-800"></div>
                        <div class="h-10 rounded-lg bg-gray-200 dark:bg-gray-800"></div>
                        <div class="h-16 rounded-lg bg-gray-200 dark:bg-gray-800"></div>
                        <div class="h-16 rounded-lg bg-gray-200 dark:bg-gray-800"></div>
                        <div class="h-16 rounded-lg bg-gray-200 dark:bg-gray-800"></div>
                    </div>

                    <div x-show="!selectedLoading">

                    <!-- On Hold banner — shown from page load (selected.isOnHold, computed
                         server-side off this checklist's actual `status`) or the instant a
                         decline succeeds (patched in place by declineChecklist() above, no
                         reload needed). All tasks/Approve/Decline stay disabled until the
                         assigned signatory clicks Remove On Hold below. -->
                    <template x-if="selected.isOnHold">
                        <div class="mb-4 mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
                            <p class="font-medium text-amber-700 dark:text-amber-400">This checklist is On Hold.</p>
                            <p class="mt-1 text-amber-600 dark:text-amber-400" x-show="selected.declinedAt">
                                Declined: <span x-text="selected.declinedAt"></span>
                            </p>
                            <p class="mt-1 text-amber-600 dark:text-amber-400" x-show="selected.declineReason">
                                Reason: <span x-text="selected.declineReason"></span>
                            </p>
                            <template x-if="selected.isPrimaryApprover && selected.onHoldRemoveUrl">
                                <button type="button" @click="removeHold()" :disabled="removingHold"
                                    :class="removingHold ? 'opacity-70 cursor-not-allowed' : 'hover:bg-amber-100 dark:hover:bg-amber-500/20'"
                                    class="mt-3 flex items-center justify-center gap-1.5 rounded-lg border border-amber-500 px-4 py-2 text-sm font-medium text-amber-700 dark:border-amber-400 dark:text-amber-400">
                                    <span x-show="removingHold" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-amber-600 border-t-transparent dark:border-amber-400"></span>
                                    <span x-text="removingHold ? 'Removing Hold...' : 'Remove On Hold'"></span>
                                </button>
                            </template>
                        </div>
                    </template>

                    <template x-if="selected.isMonitoring">
                        <p class="mb-1 text-sm font-medium text-[#145a3a] dark:text-[#3aa876]">
                            Monitoring — Task Assignee's Checklist
                        </p>
                    </template>
                    <template x-if="!selected.isPrimaryApprover && !selected.isMonitoring">
                        <p class="mb-1 text-sm text-[#145a3a] dark:text-[#3aa876]">
                            Assigned Department Head: <span x-text="selected.assignedByName"></span>
                        </p>
                    </template>

                    <template x-if="selected.dueAt">
                        <p class="mb-1 text-sm font-semibold mb-2" :class="selected.isOverdue ? 'font-medium text-error-600 dark:text-error-400' : 'text-[#145a3a] dark:text-[#3aa876]'">
                            Checklist Due Date: <span x-text="selected.dueAt"></span>
                            <span x-show="selected.isOverdue"> — Overdue</span>
                        </p>
                    </template>

                    <!-- Clearance Signing Due Date is the Clearance Signatory's OWN
                         deadline (see `ChecklistTemplate.clearance_signing_deadline_days`)
                         — shown only to whoever that actually is (`isPrimaryApprover`),
                         never to a delegate, a monitoring Group/Immediate/Department
                         Head watching someone else's checklist, or a plain per-item
                         Task Assignee, none of whom carry this deadline themselves. -->
                    <template x-if="selected.isPrimaryApprover && selected.clearanceSigningDueAt">
                        <div class="mb-2">
                            <p class="mb-1"
                                :class="{
                                    'text-sm font-medium text-[#145a3a] dark:text-[#3aa876]': clearanceSigningUrgency(selected.clearanceSigningDueAtIso) === 'none',
                                    'text-sm font-semibold text-orange-600 dark:text-orange-400': clearanceSigningUrgency(selected.clearanceSigningDueAtIso) === 'warning',
                                    'text-base font-bold text-error-600 dark:text-error-400': clearanceSigningUrgency(selected.clearanceSigningDueAtIso) === 'danger',
                                }">
                                Clearance Signing Due Date: <span x-text="selected.clearanceSigningDueAt"></span>
                                <span x-show="clearanceSigningUrgency(selected.clearanceSigningDueAtIso) === 'danger'"> — Overdue</span>
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Please complete and approve your assigned checklist on or before the displayed due date.
                            </p>
                        </div>
                    </template>

                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-show="selected.isMonitoring">
                        You're viewing this checklist because one of your own people is a Task Assignee on it. Each Task Assignee remains responsible for completing their own item(s) — you can track status here, and approve a completed item below when it's waiting on you specifically.
                    </p>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-show="selected.isDelegate">
                        Check the box for each item, add a remark if needed, then click Done to confirm it (or Save Progress to save several at once). Use Hold instead if you're blocked and need to explain why. The Clearance Signatory will review your work before giving final approval.
                    </p>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-show="!selected.isPrimaryApprover && !selected.isDelegate && !selected.isMonitoring">
                        Check the box for each item assigned to you, add a remark if needed, then click Done to confirm it. Use Hold instead if you're blocked and need to explain why.
                    </p>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-show="selected.isPrimaryApprover && !selected.usesPerItemApprovers && !selected.allItemsCompleted">
                        Check the box for each item below and add a remark if needed — every item must be checked before you can Submit.
                    </p>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-show="selected.isPrimaryApprover && selected.usesPerItemApprovers && !selected.allItemsCompleted">
                        Each item is normally completed by its own assigned signatory, but as Department Head you can check any item directly yourself. Every item must be checked before you can Submit.
                    </p>
                    <p class="mb-5 text-sm font-medium text-[#145a3a] dark:text-[#3aa876]" x-show="selected.isPrimaryApprover && selected.allItemsCompleted">
                        All checklist items have been checked and this checklist is ready for your final approval. Review the details below, then click Submit.
                    </p>

                    <template x-if="selected.isPrimaryApprover && selected.delegations && selected.delegations.length">
                        <div class="mb-5 space-y-2">
                            <template x-for="delegation in selected.delegations" :key="delegation.templateTitle">
                                <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs dark:border-gray-800 dark:bg-white/[0.03]">
                                    <div class="mb-1.5 flex items-center justify-between" x-show="selected.checklistTemplates.length > 1">
                                        <span class="text-gray-400">Checklist</span>
                                        <span class="font-medium text-gray-700 dark:text-gray-300" x-text="delegation.templateTitle"></span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-gray-400">Assigned To</span>
                                        <span class="font-medium text-gray-700 dark:text-gray-300">
                                            <span x-text="delegation.delegatedEmployeeName"></span>
                                            (<span x-text="delegation.delegatedEmployeeCode"></span>)
                                        </span>
                                    </div>
                                    <div class="mt-1.5 flex items-center justify-between">
                                        <span class="text-gray-400">Delegated Approver Status</span>
                                        <span class="font-medium capitalize text-gray-700 dark:text-gray-300" x-text="delegation.delegationStatus === 'done' ? '✓ Done' : delegation.delegationStatus"></span>
                                    </div>
                                    <template x-if="delegation.delegateCompletedAt">
                                        <div class="mt-1.5 flex items-center justify-between">
                                            <span class="text-gray-400">Completed</span>
                                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="delegation.delegateCompletedAt"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="selected.checklistItems && selected.checklistItems.length && canEditAll()">
                        <label class="mb-2 flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <input type="checkbox" :checked="allChecked()" @change="toggleAll($event.target.checked)"
                                class="h-4 w-4 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Select All</span>
                        </label>
                    </template>

                    <!-- Bulk "Done": only when THIS viewer has more than one of their own
                         unchecked tasks on this card — a lone task keeps using its own Done
                         button below, unaffected. Selection/indeterminate state are scoped
                         entirely to myDoneEligibleItems(), which never includes another
                         approver's item. -->
                    <template x-if="showDoneSelectAll()">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <label class="flex cursor-pointer items-center gap-3">
                                <input type="checkbox" :checked="doneAllChecked()" @change="toggleDoneAll($event.target.checked)"
                                    x-effect="$el.indeterminate = !doneAllChecked() && !doneNoneChecked()"
                                    class="h-4 w-4 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Select All My Tasks</span>
                            </label>
                            <template x-if="showSubmitSelectedDone()">
                                <button type="button" @click="submitSelectedDone()" :disabled="doneBulkProcessing"
                                    :class="doneBulkProcessing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                    class="rounded-lg bg-[#145a3a] px-3 py-1.5 text-xs font-medium text-white">
                                    Submit Selected Task
                                </button>
                            </template>
                        </div>
                    </template>

                    <!-- Bulk "Approve": only when THIS viewer (the head approval-gate
                         recipient) has more than one pending head-approval task on this
                         card — see myApproveEligibleItems(). -->
                    <template x-if="showApproveSelectAll()">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <label class="flex cursor-pointer items-center gap-3">
                                <input type="checkbox" :checked="approveAllChecked()" @change="toggleApproveAll($event.target.checked)"
                                    x-effect="$el.indeterminate = !approveAllChecked() && !approveNoneChecked()"
                                    class="h-4 w-4 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Select All Pending My Approval</span>
                            </label>
                            <template x-if="showSubmitSelectedApprove()">
                                <button type="button" @click="submitSelectedApprove()" :disabled="approveBulkProcessing"
                                    :class="approveBulkProcessing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                    class="rounded-lg bg-[#145a3a] px-3 py-1.5 text-xs font-medium text-white">
                                    Submit Selected Task
                                </button>
                            </template>
                        </div>
                    </template>
                    </div>
                </div>

                <form method="POST" :action="selected.saveProgressUrl" x-show="!selectedLoading"
                    id="checklistProgressForm" x-data="{ processing: false, remarksResolved: false, approvalRemarks: '' }" @submit="processing = true"
                    class="flex min-h-0 flex-1 flex-col">
                        @csrf
                        <input type="hidden" name="remarks" :value="approvalRemarks" />
                        <div class="custom-scrollbar min-h-0 flex-1 space-y-3 overflow-y-auto px-6 pb-2 lg:px-8">
                            <template x-if="!selected.checklistItems || selected.checklistItems.length === 0">
                                <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                                    No checklist items are assigned to this request.
                                </p>
                            </template>
                            <template x-for="item in selected.checklistItems" :key="item.id">
                                <div class="rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                                    <input type="hidden" :name="`items[${item.id}][checklist_item_id]`" :value="item.id" />

                                    <label class="flex items-start gap-3" :class="item.editable ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'">
                                        <input type="checkbox" :name="`items[${item.id}][is_checked]`" value="1" x-model="checked[item.id]" :disabled="!item.editable"
                                            class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                                        <span>
                                            <span class="block text-sm font-medium text-gray-800 dark:text-white/90" x-text="item.title"></span>
                                            <span class="block text-xs text-gray-400" x-text="item.templateTitle"></span>
                                            <span class="block text-sm text-[#145a3a] dark:text-[#3aa876]" x-show="item.approverName"
                                                x-text="'Assigned To: ' + (item.approverCode ? item.approverCode + ' – ' : '') + item.approverName"></span>
                                            <span class="block text-xs text-gray-400" x-show="item.isReassigned"
                                                x-text="'Previously: ' + (item.originalApproverCode ? item.originalApproverCode + ' – ' : '') + (item.originalApproverName || 'Unassigned')"></span>
                                            <span class="block text-sm font-semibold" :class="item.completedLate ? 'text-error-600 dark:text-error-400' : 'text-success-600 dark:text-success-400'" x-show="item.checked && !item.headApprovalRequired">Status: Checked</span>
                                            <!-- "Use Task Assignee as Clearance Signatory" head-approval gate: the item is checked but
                                                 not yet counted toward the checklist's own completion until its Department/Group Head
                                                 (named here) also approves it — see `ChecklistItemProgress::isFullyApproved()`. -->
                                            <span class="block text-sm font-semibold text-warning-600 dark:text-orange-400" x-show="item.checked && item.headApprovalPending"
                                                x-text="'Status: Pending Head Approval' + (item.headApproverName ? ' (' + (item.headApproverCode ? item.headApproverCode + ' – ' : '') + item.headApproverName + ')' : '')"></span>
                                            <span class="block text-sm font-semibold text-success-600 dark:text-success-400" x-show="item.checked && item.headApprovalRequired && !item.headApprovalPending">Status: Fully Approved</span>
                                            <span class="block text-sm text-gray-500 dark:text-gray-400" x-show="item.checked && item.clearedByName"
                                                x-text="'Checked By: ' + (item.clearedByCode ? item.clearedByCode + ' – ' : '') + item.clearedByName"></span>
                                            <span class="block text-sm text-gray-500 dark:text-gray-400" x-show="item.checked && item.clearedAt"
                                                x-text="'Checked Date: ' + item.clearedAt"></span>
                                            <span class="block text-sm font-semibold text-warning-600 dark:text-orange-400" x-show="item.onHold">Status: Hold</span>
                                        </span>
                                    </label>

                                    <textarea :name="`items[${item.id}][remark]`" x-model="remarks[item.id]" rows="2" placeholder="Remark (optional)" :disabled="!item.editable"
                                        :class="!item.editable ? 'cursor-not-allowed bg-gray-100 dark:bg-gray-800' : ''"
                                        class="dark:bg-dark-900 mt-2 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"></textarea>

                                    <!-- Hold (always, whenever editable and not yet checked) + Done (only for the item's own signatory
                                         on a per-item-approver checklist) — Done stays disabled until the checkbox above is checked, and
                                         becomes disabled again if the signatory unchecks it. -->
                                    <template x-if="item.editable && !item.checked">
                                        <div class="mt-2 flex items-center gap-2">
                                            <!-- No separate bulk-selection checkbox here — the task's own
                                                 checkbox above (x-model="checked[item.id]") is the single
                                                 source of truth for both bulk (Select All My Tasks) and
                                                 single-item (Done) selection; see doneAllChecked()/
                                                 toggleDoneAll() above, which read/write that exact same state. -->
                                            <button type="button" @click="holdItem(item)"
                                                :disabled="!canHold(item)"
                                                :class="!canHold(item) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-amber-50 dark:hover:bg-amber-500/10'"
                                                class="rounded-lg border border-amber-500 px-3 py-1.5 text-xs font-medium text-amber-600 dark:border-amber-400 dark:text-amber-400">
                                                Hold
                                            </button>
                                            <template x-if="isDoneFlowItem(item)">
                                                <button type="button" @click="submitDone(item)"
                                                    :disabled="!checked[item.id] || doneProcessing[item.id]"
                                                    :class="(!checked[item.id] || doneProcessing[item.id]) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                                    class="rounded-lg bg-[#145a3a] px-3 py-1.5 text-xs font-medium text-white">
                                                    Submit
                                                </button>
                                            </template>
                                            <!-- Assign To: Department Head only — lets them hand this specific item off to a
                                                 different employee. Never shown to a delegate or to the item's own signatory. -->
                                            <template x-if="selected.isPrimaryApprover">
                                                <button type="button"
                                                    @click="open = false; $dispatch('open-item-assign-modal', {
                                                        offboardeeName: selected.name,
                                                        checklistTitle: item.templateTitle,
                                                        itemTitle: item.title,
                                                        currentApproverName: item.approverName,
                                                        currentApproverCode: item.approverCode,
                                                        assignItemUrl: item.assignItemUrl,
                                                        assignableEmployees: item.assignableEmployees,
                                                    })"
                                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                                    Assign To
                                                </button>
                                            </template>
                                        </div>
                                    </template>

                                    <template x-if="item.canTakeOver">
                                        <div class="mt-2">
                                            <button type="button" :disabled="takeOverProcessing[item.id]"
                                                @click="Swal.fire({
                                                    title: 'Confirm Responsibility',
                                                    html: 'This checklist item is assigned to another approver. By proceeding, you are confirming that you will take responsibility for this checklist item — you can then add remarks, place it on Hold, or check it off yourself.<br><br>Are you sure you want to continue?',
                                                    icon: 'warning',
                                                    showCancelButton: true,
                                                    confirmButtonText: 'Yes, Check This List',
                                                    cancelButtonText: 'Cancel',
                                                    confirmButtonColor: '#145a3a',
                                                    cancelButtonColor: '#6b7280',
                                                    reverseButtons: true
                                                }).then((result) => {
                                                    if (result.isConfirmed) {
                                                        takeOverItem(item);
                                                    }
                                                })"
                                                :class="takeOverProcessing[item.id] ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#145a3a]/5 dark:hover:bg-[#3aa876]/10'"
                                                class="rounded-lg border border-[#145a3a] px-3 py-1.5 text-xs font-medium text-[#145a3a] dark:border-[#3aa876] dark:text-[#3aa876]">
                                                Check This List
                                            </button>
                                        </div>
                                    </template>

                                    <!-- "Use Task Assignee as Clearance Signatory" head-approval gate — shown ONLY to the
                                         specific Department/Group Head recorded on this item, never the Task Assignee who
                                         checked it themselves. See `ChecklistItemProgress::resolveHeadApproval()`. -->
                                    <template x-if="item.isHeadApprover && item.headApprovalPending">
                                        <div class="mt-2 flex items-center gap-2">
                                            <!-- Bulk "Approve" per-item checkbox — only rendered once this
                                                 head has more than one pending approval on this card
                                                 (showApproveSelectAll()). -->
                                            <template x-if="showApproveSelectAll()">
                                                <input type="checkbox" x-model="selectedApprove[item.id]"
                                                    class="h-4 w-4 shrink-0 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                                            </template>
                                            <button type="button" @click="approveHeadItem(item)"
                                                :disabled="approveProcessing[item.id]"
                                                :class="approveProcessing[item.id] ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                                class="rounded-lg bg-[#145a3a] px-3 py-1.5 text-xs font-medium text-white">
                                                Approve
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <div class="shrink-0 flex items-center justify-end gap-3 border-t border-gray-100 p-6 pt-4 dark:border-gray-800 lg:px-8 lg:pb-8">
                            <!-- <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Close
                            </button> -->

                            <!-- Save Progress: the Head themselves on ANY checklist with items — every checklist now requires all items
                                 checked before Submit enables (see `OffboardingRequestApprover::requiresAllItemsCompletedBeforeApproval()`),
                                 so the Head needs a way to persist partial progress (check some items now, the rest later) on a "legacy"
                                 single-approver checklist exactly like they already could on a per-item-approver one — without this,
                                 checking 3 of 5 items and closing the modal before Submit is even enabled would lose that work. Also shown
                                 to a delegate (no Submit authority of their own — this is their only way to persist work for the Head to
                                 review), but ONLY on Secondary/Final Pay checklists — see canSaveProgress()'s own comment for why a Core/
                                 Primary checklist withholds this from a delegate too, same as the bulk-selection checkboxes above. A
                                 checklist item signatory only ever sees Done. -->
                            <template x-if="canSaveProgress() && selected.checklistItems && selected.checklistItems.length && !selected.isOnHold">
                                <button type="submit" :formaction="selected.saveProgressUrl" :disabled="processing" data-turbo-submits-with="Saving..."
                                    :class="processing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
                                    class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Save Progress
                                </button>
                            </template>

                            <!-- Decline: same authority as Submit (the assigned Clearance Signatory), but stays a
                                 per-checklist action — never rendered for a combined multi-checklist card (see
                                 `declineUrl`'s own null case in `ApprovalController::groupIntoCombinedApprovers()`).
                                 Places the checklist On Hold — no signature required (it's a pause, not a final
                                 decision) but a mandatory reason via the confirm dialog below. Hides once already
                                 on hold — nothing left to decline twice, see `selected.isOnHold`. -->
                            <template x-if="selected.isPrimaryApprover && selected.declineUrl && !selected.isOnHold">
                                <button type="button" @click="declineChecklist()" :disabled="declining"
                                    :class="declining ? 'opacity-70 cursor-not-allowed' : 'hover:bg-red-50 dark:hover:bg-red-500/10'"
                                    class="flex items-center justify-center gap-1.5 rounded-lg border border-red-500 px-4 py-2.5 text-sm font-medium text-red-600 dark:border-red-400 dark:text-red-400">
                                    <span x-show="declining" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-red-600 border-t-transparent dark:border-red-400"></span>
                                    <span x-text="declining ? 'Declining...' : 'Decline'"></span>
                                </button>
                            </template>

                            <!-- Department Head / primary approver: final Submit — only they can approve the whole checklist. Legacy
                                 checklists: enabled regardless of item checks (same as always). Per-item-approver checklists: enabled
                                 only once every item has actually been checked. Hides while On Hold — see Decline above. -->
                            <template x-if="selected.isPrimaryApprover && !selected.isOnHold">
                                <button type="submit" :formaction="selected.approveUrl" :disabled="processing || !canApprove()" data-turbo-submits-with="Submitting..."
                                    @click="
                                        if (!remarksResolved && selected.hasReachedDueDate) {
                                            $event.preventDefault();
                                            Swal.fire({
                                                title: 'This checklist has reached its due date.',
                                                text: 'A reason is required explaining why this checklist was not completed before its due date.',
                                                icon: 'warning',
                                                input: 'textarea',
                                                inputLabel: 'Remarks',
                                                inputPlaceholder: 'Explain the reason for the delay...',
                                                inputValidator: (value) => {
                                                    if (!value || !value.trim()) {
                                                        return 'A remark is required to approve an overdue checklist.';
                                                    }
                                                },
                                                showCancelButton: true,
                                                confirmButtonText: 'Approve',
                                                cancelButtonText: 'Cancel',
                                                confirmButtonColor: '#145a3a',
                                                cancelButtonColor: '#6b7280',
                                                reverseButtons: true,
                                            }).then((result) => {
                                                if (result.isConfirmed) {
                                                    approvalRemarks = (result.value || '').trim();
                                                    remarksResolved = true;
                                                    $nextTick(() => $el.click());
                                                }
                                            });
                                        }
                                    "
                                    :class="(processing || !canApprove()) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                    class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white">
                                    Approve
                                </button>
                            </template>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>
