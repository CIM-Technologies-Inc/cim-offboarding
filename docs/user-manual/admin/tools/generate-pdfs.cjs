const { chromium } = require('playwright');
const { marked } = require('marked');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');

function slugify(text) {
    return text.toLowerCase().trim().replace(/[^\w\s-]/g, '').replace(/\s+/g, '-');
}

const renderer = {
    heading(token) {
        const text = this.parser.parseInline(token.tokens);
        const slug = slugify(token.text);
        return `<h${token.depth} id="${slug}">${text}</h${token.depth}>\n`;
    },
    image({ href, title, text }) {
        // Base64-embedded, not a file:// link - page.setContent() gives the
        // page an opaque origin that Chromium's local-file security model
        // blocks from loading file:// resources. A data: URI has no such
        // restriction and keeps the generated HTML/PDF self-contained.
        const resolved = path.isAbsolute(href) ? href : path.join(ROOT, href);
        const base64 = fs.readFileSync(resolved).toString('base64');
        const ext = path.extname(resolved).slice(1) || 'png';
        return `<img src="data:image/${ext};base64,${base64}" alt="${text}" title="${title || ''}" />`;
    },
};
marked.use({ renderer });
marked.setOptions({ gfm: true });

function mdToHtml(mdPath) {
    const md = fs.readFileSync(mdPath, 'utf8');
    return marked.parse(md);
}

const STYLE = `
<style>
  @page { margin: 0; }
  * { box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    color: #1f2937;
    font-size: 11pt;
    line-height: 1.55;
    margin: 0;
    padding: 0 48px;
  }
  h1 { color: #0f4630; font-size: 22pt; border-bottom: 3px solid #145a3a; padding-bottom: 8px; margin-top: 36px; page-break-before: always; }
  h1:first-of-type { page-break-before: avoid; }
  h2 { color: #145a3a; font-size: 16pt; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; margin-top: 28px; }
  h3 { color: #145a3a; font-size: 13pt; margin-top: 20px; }
  h4 { color: #1f2937; font-size: 11.5pt; margin-top: 16px; }
  a { color: #145a3a; text-decoration: none; }
  code { background: #f3f4f6; padding: 1px 5px; border-radius: 4px; font-size: 9.5pt; color: #0f4630; }
  pre { background: #f3f4f6; padding: 12px; border-radius: 6px; overflow-x: auto; border-left: 3px solid #145a3a; white-space: pre; font-size: 6.3pt; line-height: 1.25; page-break-inside: avoid; page-break-before: always; }
  pre code { background: none; padding: 0; }
  table { border-collapse: collapse; width: 100%; margin: 14px 0; font-size: 9.5pt; }
  th { background: #145a3a; color: white; text-align: left; padding: 7px 9px; }
  td { border: 1px solid #e5e7eb; padding: 6px 9px; vertical-align: top; }
  tr:nth-child(even) td { background: #f9fafb; }
  blockquote { border-left: 4px solid #3aa876; background: #f0f9f4; margin: 14px 0; padding: 8px 16px; color: #0f4630; }
  img { max-width: 100%; border: 1px solid #d1d5db; border-radius: 6px; margin: 12px 0; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
  hr { border: none; border-top: 1px solid #d1d5db; margin: 28px 0; }
  ul, ol { padding-left: 24px; }
  li { margin: 3px 0; }
  .title-page {
    page-break-after: always;
    height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    background: linear-gradient(135deg, #0f4630 0%, #145a3a 55%, #0a3321 100%);
    color: white;
    margin: 0 -48px;
    padding: 0 48px;
  }
  .title-page .brand { font-size: 15pt; letter-spacing: 3px; text-transform: uppercase; opacity: 0.85; margin-bottom: 18px; }
  .title-page h1 { color: white; border: none; font-size: 30pt; margin: 0 0 14px 0; page-break-before: avoid; }
  .title-page .subtitle { font-size: 14pt; opacity: 0.9; margin-bottom: 40px; }
  .title-page .meta { font-size: 10.5pt; opacity: 0.75; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 18px; margin-top: 30px; }
</style>`;

function titlePage(subtitle) {
    return `<div class="title-page">
        <div class="brand">CIM Technologies, Inc.</div>
        <h1>CIM OffBoarding</h1>
        <div class="subtitle">${subtitle}</div>
        <div class="meta">Version 1.0 &nbsp;&middot;&nbsp; Revised October 16, 2026</div>
    </div>`;
}

function buildHtml({ title, subtitle, bodyHtml }) {
    return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title>${STYLE}</head>
    <body>
        ${titlePage(subtitle)}
        ${bodyHtml}
    </body></html>`;
}

async function renderPdf(browser, html, outPath, headerLabel) {
    const page = await browser.newPage();
    await page.setContent(html, { waitUntil: 'networkidle' });
    await page.pdf({
        path: outPath,
        format: 'Letter',
        printBackground: true,
        margin: { top: '70px', bottom: '56px', left: '0px', right: '0px' },
        displayHeaderFooter: true,
        headerTemplate: `<div style="font-size:8px; color:#6b7280; width:100%; padding:0 48px; display:flex; justify-content:space-between;">
            <span>CIM OffBoarding &mdash; ${headerLabel}</span><span></span></div>`,
        footerTemplate: `<div style="font-size:8px; color:#6b7280; width:100%; padding:0 48px; display:flex; justify-content:space-between;">
            <span>CIM Technologies, Inc. &mdash; Confidential, internal use</span>
            <span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>`,
    });
    await page.close();
}

(async () => {
    const browser = await chromium.launch({ headless: true });

    const docs = [
        { md: 'admin-user-manual.md', out: 'admin-user-manual.pdf', title: 'CIM OffBoarding Admin Manual', subtitle: 'Admin User Manual' },
        { md: 'offboarding-workflow.md', out: 'offboarding-workflow.pdf', title: 'CIM OffBoarding Workflow', subtitle: 'Complete Offboarding Workflow' },
    ];

    for (const doc of docs) {
        const bodyHtml = mdToHtml(path.join(ROOT, doc.md));
        const html = buildHtml({ title: doc.title, subtitle: doc.subtitle, bodyHtml });
        await renderPdf(browser, html, path.join(ROOT, doc.out), doc.subtitle);
        console.log('Generated', doc.out);
    }

    await browser.close();
    console.log('ALL PDFS DONE');
})().catch((e) => { console.error('FATAL:', e); process.exit(1); });
