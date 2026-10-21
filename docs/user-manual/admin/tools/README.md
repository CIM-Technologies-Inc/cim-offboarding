# PDF generator

`generate-pdfs.cjs` rebuilds both PDFs in `..` (`admin-user-manual.pdf` and `offboarding-workflow.pdf`) from the current Markdown source. Run it from the project root after editing either `.md` file:

```
npm install --no-save marked playwright   # one-time, if not already present
node docs/user-manual/admin/tools/generate-pdfs.cjs
```

It converts each Markdown file to HTML with [`marked`](https://www.npmjs.com/package/marked), applies the inline stylesheet defined in the script (matching the application's own dark-green `#145a3a` branding), embeds every referenced screenshot from `../screenshots/` as a base64 data URI (so the output is fully self-contained — a plain `file://` image reference does not reliably load inside a Chromium page rendered via `page.setContent()`), and renders the result to PDF with Playwright/Chromium: a branded title page, a running header/footer with page numbers, and automatic page breaks at every top-level heading.

Neither `marked` nor `playwright` is a project dependency (they are not listed in `package.json`) — both are only needed transiently to regenerate these two PDFs.

# Word (.docx) generator

`generate-docx.cjs` rebuilds `../admin-user-manual.docx` from `admin-user-manual.md`. Run it from the project root after editing that file:

```
npm install --no-save docx marked   # one-time, if not already present
node docs/user-manual/admin/tools/generate-docx.cjs
```

Unlike the PDF generator, this script doesn't go through HTML at all — it walks `marked`'s token tree directly and maps each block (heading, paragraph, list, table, blockquote, code, image) to the matching [`docx`](https://www.npmjs.com/package/docx) construct, so headings become native Word "Heading 1–4" paragraph styles (not just bold text), and the document opens with a real Word `TableOfContents` field (right-click it and choose "Update Field", or press F9, to populate it — that's normal, expected Word behavior for any TOC field, not a bug). Tables get the same dark-green header row as the PDF; screenshots are embedded directly as PNG image runs, scaled down from their native pixel size by reading the PNG's own `IHDR` chunk (no image-dimension package is installed). Internal `#anchor` and relative `.md` links are rendered as plain text rather than live hyperlinks, since Word's native TOC already covers in-document navigation; true `https://` links remain clickable.

Neither `docx` nor `marked` is a project dependency — both are only needed transiently to regenerate this file.
