import { fetchWithTimeout } from './fetch-with-timeout';

export function emailWorkspace(flashSuccess = null, flashError = null, hasValidationErrors = false) {
  return {
    viewing: null,
    previewSubject: '',
    previewHtml: '',
    originalBody: '',
    // Any render with flashed validation errors means what's on screen is
    // an unsaved, never-persisted submission attempt, not the true saved
    // state — always treated as dirty, regardless of whether the user has
    // touched anything since. See cancelEdit()/isFormDirty() below.
    hasValidationErrors,
    // Captured once, shortly after load (see init()), as the "nothing
    // changed yet" baseline for isFormDirty(). Stays null until that
    // snapshot is actually taken; isFormDirty() treats null the same as
    // "not dirty" — correct, since nothing could have changed yet either.
    initialFormSnapshot: null,
    init() {
      this.originalBody = this.getSummernoteHtml();

      // Deferred past the current paint cycle so Summernote's own init-time
      // DOM settling (parsing/re-rendering the server-supplied HTML into
      // its editable structure) has already happened before this baseline
      // is captured — otherwise a purely cosmetic reformatting Summernote
      // applies on mount could register as a "change" nobody actually
      // typed, producing a false-positive confirm dialog the very first
      // time Cancel is clicked.
      this.$nextTick(() => {
        setTimeout(() => {
          this.initialFormSnapshot = this.snapshotForm();
        }, 300);
      });

      if (flashSuccess) {
        this.notify('success', flashSuccess);
      } else if (flashError) {
        this.notify('error', flashError);
      }
    },
    // A plain serialization of every field currently in the form — covers
    // Template Name/Subject/Body plus Recipients/CC, and every Email
    // Notification Schedule's Event/Timing/Days/Channel/Clearance Signing
    // Deadline/Active Selections, all for free: each of those is a real,
    // named `<input>` in this same `<form>` (see each one's own nested
    // Alpine component), regardless of which component renders it — so one
    // native FormData read captures the lot with no per-field or
    // per-component tracking needed.
    snapshotForm() {
      const form = document.getElementById('templateForm');
      return form ? new URLSearchParams(new FormData(form)).toString() : '';
    },
    isFormDirty() {
      if (this.hasValidationErrors) return true;
      if (this.initialFormSnapshot === null) return false;

      return this.snapshotForm() !== this.initialFormSnapshot;
    },
    // A plain `location.reload()` is NOT enough to actually discard
    // anything the user typed: browsers separately cache "what was last
    // typed into this form on this exact URL" and silently REPLAY it back
    // into the freshly-reloaded page once it parses — a built-in
    // reload/back-forward UX convenience that's completely independent of
    // (and survives) the server sending back the real, correct, saved
    // values. That's what made Cancel look like a no-op: the page genuinely
    // did reload with the right data, the browser just overwrote it again
    // right after. Appending a throwaway, ever-changing query parameter
    // makes the browser treat this as a navigation to a URL it has no
    // cached form state for at all, which defeats that restoration — the
    // param itself is never read by the route/controller (Laravel matches
    // on the path, not the query string), so it has no effect beyond that.
    hardReload() {
      const url = new URL(window.location.href);
      url.searchParams.set('_cancelled', Date.now().toString());
      window.location.href = url.toString();
    },
    // "Cancel" on the edit form — never submits, validates, or writes to
    // the database (a plain type="button", not type="submit"). Discarding
    // reloads this exact same URL rather than patching 3 separate Alpine
    // components' state back to pristine by hand: a fresh GET re-renders
    // every field straight from the real saved record in one guaranteed-
    // correct step, which also naturally keeps the same sidebar tab/
    // highlighted template card and never navigates anywhere else.
    cancelEdit() {
      if (!this.isFormDirty()) {
        this.hardReload();

        return;
      }

      window.Swal?.fire({
        title: 'Unsaved Changes',
        text: 'You have unsaved changes. Are you sure you want to cancel?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Discard Changes',
        cancelButtonText: 'Continue Editing',
        confirmButtonColor: '#145a3a',
        cancelButtonColor: '#6b7280',
        reverseButtons: true,
      }).then((result) => {
        if (result.isConfirmed) {
          this.hardReload();
        }
      });
    },
    notify(icon, title) {
      window.Swal?.fire({
        toast: true,
        position: 'bottom-end',
        icon,
        title,
        showConfirmButton: false,
        timer: icon === 'success' ? 2000 : 2500,
        timerProgressBar: icon === 'success',
        customClass: { container: 'app-toast' },
      });
    },
    getSummernoteHtml() {
      if (window.jQuery && window.jQuery('.summernote').data('summernote')) {
        return window.jQuery('.summernote').summernote('code');
      }
      const bodyEl = document.getElementById('templateBody');
      return bodyEl ? bodyEl.value : '';
    },
    setSummernoteHtml(html) {
      if (window.jQuery && window.jQuery('.summernote').data('summernote')) {
        window.jQuery('.summernote').summernote('code', html);
      } else {
        const bodyEl = document.getElementById('templateBody');
        if (bodyEl) bodyEl.value = html;
      }
    },
    // "approver", "offboardee", and "employee" are the placeholder words the
    // backend mail-merge fills in with the signatory's, offboardee's, and
    // request creator's name respectively (see OffboardingRequestController).
    findPlaceholderMatches(text) {
      const pattern = /\{\s*(?:approver|offboardee|employee)\s*\}|\b(?:approver|offboardee|employee)\b/gi;
      const matches = [];
      let match;
      while ((match = pattern.exec(text)) !== null) {
        matches.push({ start: match.index, end: match.index + match[0].length });
      }
      return matches;
    },
    // Replaces only the placeholder closest to `position` (character offset),
    // leaving every other placeholder occurrence in the body untouched.
    replaceNearestPlaceholder(text, name, position) {
      const matches = this.findPlaceholderMatches(text);
      if (matches.length === 0) return null;

      const target = typeof position === 'number' ? position : 0;
      let closest = matches[0];
      let closestDistance = Infinity;

      matches.forEach((m) => {
        let distance;
        if (target >= m.start && target <= m.end) {
          distance = 0;
        } else if (target < m.start) {
          distance = m.start - target;
        } else {
          distance = target - m.end;
        }
        if (distance < closestDistance) {
          closestDistance = distance;
          closest = m;
        }
      });

      return text.slice(0, closest.start) + name + text.slice(closest.end);
    },
    insertEmployeeName(name, position = null) {
      const html = this.getSummernoteHtml();
      const updated = this.replaceNearestPlaceholder(html, name, position);

      if (updated !== null) {
        this.setSummernoteHtml(updated);
        return;
      }

      // No placeholder left anywhere — fall back to inserting the name
      // directly at the drop/cursor position so this is never a no-op.
      if (window.jQuery && window.jQuery('.summernote').data('summernote')) {
        window.jQuery('.summernote').summernote('focus');
        window.jQuery('.summernote').summernote('pasteHTML', name);
        return;
      }

      const bodyEl = document.getElementById('templateBody');
      if (!bodyEl) return;

      const insertAt = typeof position === 'number' ? position : bodyEl.selectionStart;
      bodyEl.value = bodyEl.value.slice(0, insertAt) + name + bodyEl.value.slice(insertAt);
      bodyEl.selectionStart = bodyEl.selectionEnd = insertAt + name.length;
    },
    handleEmployeeDrop(event) {
      const name = event.dataTransfer.getData('text/plain');
      if (!name) return;

      // Browsers keep a textarea's selectionStart in sync with the mouse
      // position while dragging over it, so this is the drop caret offset.
      const bodyEl = document.getElementById('templateBody');
      const dropIndex = bodyEl ? bodyEl.selectionStart : null;

      this.insertEmployeeName(name, dropIndex);
    },
    // Removes every dragged-in employee name by restoring the body to
    // whatever it was when the page loaded (placeholders intact).
    undoDraggedNames() {
      this.setSummernoteHtml(this.originalBody);
      this.notify('success', 'Dragged names removed.');
    },
    showPreview() {
      const subjectEl = document.getElementById('templateSubject');
      this.previewSubject = subjectEl ? subjectEl.value : '';
      this.previewHtml = this.getSummernoteHtml();
      this.$dispatch('open-preview-modal');
    },
    toggleTemplateStatus(event, url) {
      const checkbox = event.target;
      const label = checkbox.closest('label');
      const previousChecked = !checkbox.checked;
      const csrfMeta = document.querySelector('meta[name="csrf-token"]');

      // Disabled for the round trip so a second click can't race the first
      // — always re-enabled below, in both the success and error paths.
      checkbox.disabled = true;

      fetchWithTimeout(url, {
        method: 'PATCH',
        headers: {
          'X-CSRF-TOKEN': csrfMeta ? csrfMeta.content : '',
          Accept: 'application/json',
        },
      })
        .then((response) => {
          if (!response.ok) throw new Error('Request failed');
          return response.json();
        })
        .then((data) => {
          if (label) label.title = data.is_active ? 'Active' : 'Inactive';
          this.notify('success', data.message);
        })
        .catch((e) => {
          checkbox.checked = previousChecked;
          this.notify('error', e?.name === 'AbortError'
            ? 'This is taking longer than expected. Please check before trying again.'
            : 'Could not update template status.');
        })
        .finally(() => {
          checkbox.disabled = false;
        });
    },
  };
}

export default emailWorkspace;
