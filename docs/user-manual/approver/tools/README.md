# Word (.docx) generator

`generate-docx.cjs` rebuilds `../approver-user-manual.docx` from `approver-user-manual.md`. Run it from the project root after editing that file:

```
npm install --no-save docx marked   # one-time, if not already present
node docs/user-manual/approver/tools/generate-docx.cjs
```

This is the same converter used for the Admin User Manual (`docs/user-manual/admin/tools/generate-docx.cjs`), pointed at this folder's own Markdown source instead. It walks `marked`'s token tree directly (no HTML round-trip) and maps each block (heading, paragraph, list, table, blockquote, code, image) to the matching [`docx`](https://www.npmjs.com/package/docx) construct: headings become native Word "Heading 1–4" paragraph styles, so the document opens with a real Word `TableOfContents` field (right-click it and choose "Update Field", or press F9, to populate it — that's normal, expected Word behavior for any TOC field, not a bug). Tables get the same dark-green header row as the rest of this app's documentation; screenshots are embedded directly as PNG image runs, scaled down from their native pixel size by reading the PNG's own `IHDR` chunk (no image-dimension package is installed).

One difference from the admin-manual converter: this manual's screenshots are followed immediately (no blank line) by an italic `*Figure N — ...*` caption on the very next line, which Markdown parses as a single paragraph containing both the image and the caption text rather than two separate blocks. The image token is therefore handled inline (inside `inlineRuns()`), not only in the block-level "paragraph containing just one image" special case — if you ever see an image fail to embed after editing the Markdown, check first whether that image's caption line accidentally lost its adjacency to the image line, or gained a blank line before it.

Neither `docx` nor `marked` is a project dependency — both are only needed transiently to regenerate this file.
