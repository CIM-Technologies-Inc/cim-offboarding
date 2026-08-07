import $ from 'jquery';

window.$ = window.jQuery = $;

import 'summernote/dist/summernote-lite.css';
import 'summernote/dist/summernote-lite.js';

export function summernoteInit() {
  document.querySelectorAll('.summernote').forEach((el) => {
    const $el = $(el);

    if ($el.data('summernote')) {
      // Already has a live plugin instance bound to this exact node.
      return;
    }

    // A Turbo page-cache restore can bring back a *visual* .note-editor
    // left over from a previous visit, without any live jQuery plugin
    // instance attached to it (jQuery's data is tied to the original DOM
    // nodes, which the cached snapshot doesn't preserve). Clear that stale
    // markup out before initializing a fresh instance.
    const stale = $el.next('.note-editor');
    if (stale.length) {
      stale.remove();
      $el.show();
    }

    const height = Number(el.dataset.height) || 350;

    $el.summernote({
      height,
      width: '100%',
      placeholder: 'Write the email content...',
      toolbar: [
        ['history', ['undo', 'redo']],
        ['font', ['bold', 'italic', 'underline']],
        ['fontname', ['fontname']],
        ['fontsize', ['fontsize']],
        ['color', ['color']],
        ['para', ['ul', 'ol', 'paragraph']],
        ['table', ['table']],
        ['insert', ['link', 'hr']],
        ['view', ['fullscreen', 'codeview']],
      ],
    });
  });
}

export function summernoteDestroy() {
  document.querySelectorAll('.summernote').forEach((el) => {
    const $el = $(el);
    if ($el.data('summernote')) {
      $el.summernote('destroy');
    }
  });
}

export default summernoteInit;
