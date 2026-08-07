export function emailWorkspace() {
  return {
    viewing: null,
    previewSubject: '',
    previewHtml: '',
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
    findPlaceholderMatches(text) {
      const pattern = /\{\s*employee\s*\}|\bemployee\b/gi;
      const matches = [];
      let match;
      while ((match = pattern.exec(text)) !== null) {
        matches.push({ start: match.index, end: match.index + match[0].length });
      }
      return matches;
    },
    // Replaces only the placeholder closest to `position` (character offset),
    // leaving every other "employee" occurrence in the body untouched.
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

      // No "employee" placeholder left anywhere — fall back to inserting the
      // name directly at the drop/cursor position so this is never a no-op.
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
    showPreview() {
      const subjectEl = document.getElementById('templateSubject');
      this.previewSubject = subjectEl ? subjectEl.value : '';
      this.previewHtml = this.getSummernoteHtml();
      this.$dispatch('open-preview-modal');
    },
  };
}

export default emailWorkspace;
