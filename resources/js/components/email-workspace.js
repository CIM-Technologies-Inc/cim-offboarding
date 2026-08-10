export function emailWorkspace(flashSuccess = null, flashError = null) {
  return {
    viewing: null,
    previewSubject: '',
    previewHtml: '',
    originalBody: '',
    init() {
      this.originalBody = this.getSummernoteHtml();

      if (flashSuccess) {
        this.notify('success', flashSuccess);
      } else if (flashError) {
        this.notify('error', flashError);
      }
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

      fetch(url, {
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
        .catch(() => {
          checkbox.checked = previousChecked;
          this.notify('error', 'Could not update template status.');
        });
    },
  };
}

export default emailWorkspace;
