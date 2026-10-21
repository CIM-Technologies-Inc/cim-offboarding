// Generates task-assignee-user-manual.docx from task-assignee-user-manual.md.
// Same converter used for the admin and approver manuals (docs/user-manual/admin/tools/generate-docx.cjs,
// docs/user-manual/approver/tools/generate-docx.cjs):
// walks marked's token tree directly (no HTML round-trip) and builds a
// matching Word document with the `docx` package - headings mapped to
// Word's native Heading styles (so Word's own Table of Contents field
// actually works), tables with the app's dark-green header row, embedded
// screenshots, and the same #145a3a / #0f4630 brand colors used elsewhere
// in this app's documentation.
const { marked } = require('marked');
const fs = require('fs');
const path = require('path');
const {
    Document, Packer, Paragraph, TextRun, HeadingLevel, Table, TableRow, TableCell,
    ImageRun, TableOfContents, PageBreak, WidthType, ShadingType, BorderStyle,
    AlignmentType, ExternalHyperlink, convertInchesToTwip,
} = require('docx');

const ROOT = path.join(__dirname, '..');
const GREEN_DARK = '0F4630';
const GREEN = '145A3A';
const GREEN_LIGHT = '3AA876';
const GRAY = '6B7280';
const CODE_BG = 'F3F4F6';

function readMd(file) {
    return fs.readFileSync(path.join(ROOT, file), 'utf8');
}

function pngDimensions(buffer) {
    // PNG: 8-byte signature, then first chunk is IHDR (4-byte length + "IHDR" + data).
    // Width = bytes 16-19, Height = bytes 20-23, both big-endian.
    return { width: buffer.readUInt32BE(16), height: buffer.readUInt32BE(20) };
}

function scaledImageSize(buffer, maxWidthPx = 560) {
    const { width, height } = pngDimensions(buffer);
    const ratio = Math.min(1, maxWidthPx / width);
    return { width: Math.round(width * ratio), height: Math.round(height * ratio) };
}

// ---------- inline tokens -> TextRun[] / ExternalHyperlink[] ----------
function inlineRuns(tokens, baseOpts = {}) {
    const runs = [];
    for (const t of tokens || []) {
        switch (t.type) {
            case 'text':
            case 'escape':
                runs.push(new TextRun({ text: t.text, ...baseOpts }));
                break;
            case 'strong':
                runs.push(...inlineRuns(t.tokens, { ...baseOpts, bold: true }));
                break;
            case 'em':
                runs.push(...inlineRuns(t.tokens, { ...baseOpts, italics: true }));
                break;
            case 'codespan':
                runs.push(new TextRun({
                    text: t.text, font: 'Consolas', size: 18, color: GREEN_DARK,
                    shading: { type: ShadingType.CLEAR, fill: CODE_BG }, ...baseOpts, bold: false, italics: false,
                }));
                break;
            case 'link': {
                const inner = inlineRuns(t.tokens, baseOpts);
                if (/^https?:\/\//.test(t.href)) {
                    runs.push(new ExternalHyperlink({
                        link: t.href,
                        children: inner.length ? inner : [new TextRun({ text: t.text, ...baseOpts })],
                    }));
                } else {
                    // Internal anchor / relative .md link - Word can't
                    // resolve either reliably, so keep the readable text
                    // only rather than emit a dead link.
                    runs.push(...(inner.length ? inner : [new TextRun({ text: t.text, ...baseOpts })]));
                }
                break;
            }
            case 'br':
                runs.push(new TextRun({ text: '', break: 1 }));
                break;
            case 'del':
                runs.push(...inlineRuns(t.tokens, { ...baseOpts, strike: true }));
                break;
            case 'image': {
                // A screenshot followed immediately by an italic "*Figure N -
                // ...*" caption line (no blank line between them in the
                // source) parses as ONE paragraph with [image, br?, text...]
                // tokens, not a paragraph containing only an image - handled
                // here, inline, rather than only in the block-level
                // single-image-paragraph special case below.
                const resolved = path.isAbsolute(t.href) ? t.href : path.join(ROOT, t.href);
                if (fs.existsSync(resolved)) {
                    const buf = fs.readFileSync(resolved);
                    const { width, height } = scaledImageSize(buf);
                    runs.push(new ImageRun({ data: buf, transformation: { width, height }, type: 'png' }));
                }
                break;
            }
            default:
                if (t.raw) runs.push(new TextRun({ text: t.raw, ...baseOpts }));
        }
    }
    return runs;
}

function headingLevel(depth) {
    return [HeadingLevel.HEADING_1, HeadingLevel.HEADING_2, HeadingLevel.HEADING_3, HeadingLevel.HEADING_4][depth - 1] || HeadingLevel.HEADING_4;
}

// ---------- block tokens -> Paragraph[] / Table[] ----------
function blockToElements(token) {
    switch (token.type) {
        case 'heading':
            return [new Paragraph({ heading: headingLevel(token.depth), children: inlineRuns(token.tokens), spacing: { before: 240, after: 120 } })];

        case 'paragraph': {
            // A paragraph that's JUST a single image renders as a block figure.
            if (token.tokens.length === 1 && token.tokens[0].type === 'image') {
                const img = token.tokens[0];
                const resolved = path.isAbsolute(img.href) ? img.href : path.join(ROOT, img.href);
                if (!fs.existsSync(resolved)) return [];
                const buf = fs.readFileSync(resolved);
                const { width, height } = scaledImageSize(buf);
                return [
                    new Paragraph({
                        children: [new ImageRun({ data: buf, transformation: { width, height }, type: 'png' })],
                        spacing: { before: 120, after: 80 },
                    }),
                    new Paragraph({
                        children: [new TextRun({ text: img.text || '', italics: true, size: 18, color: GRAY })],
                        spacing: { after: 160 },
                    }),
                ];
            }
            return [new Paragraph({ children: inlineRuns(token.tokens), spacing: { after: 160 } })];
        }

        case 'blockquote': {
            return token.tokens.flatMap((tt) => (tt.type === 'paragraph'
                ? [new Paragraph({
                    children: inlineRuns(tt.tokens, { color: GREEN_DARK }),
                    border: { left: { style: BorderStyle.SINGLE, size: 18, color: GREEN_LIGHT, space: 12 } },
                    shading: { type: ShadingType.CLEAR, fill: 'F0F9F4' },
                    spacing: { before: 120, after: 120 },
                    indent: { left: 100 },
                })]
                : blockToElements(tt)));
        }

        case 'list': {
            const items = [];
            token.items.forEach((item) => {
                const text = item.tokens.filter((tk) => tk.type === 'text' || tk.type === 'paragraph');
                const runs = text.flatMap((tk) => inlineRuns(tk.tokens || [tk]));
                items.push(new Paragraph({
                    children: runs,
                    bullet: token.ordered ? undefined : { level: 0 },
                    numbering: token.ordered ? { reference: 'default-numbering', level: 0 } : undefined,
                    spacing: { after: 60 },
                }));
                // nested sub-lists
                item.tokens.filter((tk) => tk.type === 'list').forEach((sub) => {
                    sub.items.forEach((subItem) => {
                        const subText = subItem.tokens.filter((tk) => tk.type === 'text' || tk.type === 'paragraph');
                        const subRuns = subText.flatMap((tk) => inlineRuns(tk.tokens || [tk]));
                        items.push(new Paragraph({ children: subRuns, bullet: { level: 1 }, spacing: { after: 60 } }));
                    });
                });
            });
            return items;
        }

        case 'table': {
            const headerCells = token.header.map((cell) => new TableCell({
                shading: { type: ShadingType.CLEAR, fill: GREEN },
                children: [new Paragraph({ children: inlineRuns(cell.tokens, { color: 'FFFFFF', bold: true }) })],
                margins: { top: 80, bottom: 80, left: 100, right: 100 },
            }));
            const rows = [new TableRow({ children: headerCells, tableHeader: true })];
            token.rows.forEach((row, ri) => {
                const cells = row.map((cell) => new TableCell({
                    shading: ri % 2 === 1 ? { type: ShadingType.CLEAR, fill: 'F9FAFB' } : undefined,
                    children: [new Paragraph({ children: inlineRuns(cell.tokens) })],
                    margins: { top: 60, bottom: 60, left: 100, right: 100 },
                }));
                rows.push(new TableRow({ children: cells }));
            });
            return [
                new Table({ rows, width: { size: 100, type: WidthType.PERCENTAGE } }),
                new Paragraph({ text: '', spacing: { after: 160 } }),
            ];
        }

        case 'code': {
            const lines = token.text.split('\n');
            return [new Paragraph({
                children: lines.flatMap((line, i) => [
                    new TextRun({ text: line || ' ', font: 'Consolas', size: 16 }),
                    ...(i < lines.length - 1 ? [new TextRun({ text: '', break: 1 })] : []),
                ]),
                shading: { type: ShadingType.CLEAR, fill: CODE_BG },
                border: { left: { style: BorderStyle.SINGLE, size: 18, color: GREEN } },
                spacing: { before: 120, after: 160 },
                indent: { left: 100 },
            })];
        }

        case 'hr':
            return [new Paragraph({ border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: 'D1D5DB' } }, spacing: { before: 200, after: 200 } })];

        case 'space':
            return [];

        default:
            return [];
    }
}

function mdToElements(markdown) {
    const tokens = marked.lexer(markdown, { gfm: true });
    return tokens.flatMap(blockToElements);
}

// ---------- build the document ----------
const bodyElements = mdToElements(readMd('task-assignee-user-manual.md'));

const doc = new Document({
    numbering: {
        config: [{
            reference: 'default-numbering',
            levels: [{ level: 0, format: 'decimal', text: '%1.', alignment: AlignmentType.START }],
        }],
    },
    styles: {
        default: {
            document: { run: { font: 'Calibri', size: 22, color: '1F2937' } },
        },
        paragraphStyles: [
            { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { size: 40, bold: true, color: GREEN_DARK }, paragraph: { spacing: { before: 320, after: 160 } } },
            { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { size: 32, bold: true, color: GREEN }, paragraph: { spacing: { before: 280, after: 140 } } },
            { id: 'Heading3', name: 'Heading 3', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { size: 26, bold: true, color: GREEN }, paragraph: { spacing: { before: 220, after: 110 } } },
            { id: 'Heading4', name: 'Heading 4', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { size: 23, bold: true, color: '1F2937' }, paragraph: { spacing: { before: 180, after: 90 } } },
        ],
    },
    sections: [{
        properties: {
            page: {
                margin: { top: convertInchesToTwip(0.9), bottom: convertInchesToTwip(0.9), left: convertInchesToTwip(1), right: convertInchesToTwip(1) },
            },
        },
        children: [
            // ---- Cover ----
            new Paragraph({ text: '', spacing: { before: 1800 } }),
            new Paragraph({
                alignment: AlignmentType.CENTER,
                children: [new TextRun({ text: 'CIM TECHNOLOGIES, INC.', color: GREEN, bold: true, size: 22, characterSpacing: 20 })],
            }),
            new Paragraph({
                alignment: AlignmentType.CENTER,
                spacing: { before: 200, after: 200 },
                children: [new TextRun({ text: 'CIM OffBoarding', bold: true, size: 72, color: GREEN_DARK })],
            }),
            new Paragraph({
                alignment: AlignmentType.CENTER,
                spacing: { after: 400 },
                children: [new TextRun({ text: 'Task Assignee User Manual', size: 32, color: '1F2937' })],
            }),
            new Paragraph({
                alignment: AlignmentType.CENTER,
                border: { top: { style: BorderStyle.SINGLE, size: 4, color: 'D1D5DB' } },
                spacing: { before: 300 },
                children: [],
            }),
            new Paragraph({
                alignment: AlignmentType.CENTER,
                spacing: { before: 200 },
                children: [new TextRun({ text: 'Version 1.0  ·  Revised October 16, 2026', size: 20, color: GRAY })],
            }),
            new Paragraph({
                alignment: AlignmentType.CENTER,
                spacing: { before: 120 },
                children: [new TextRun({ text: 'CIM Technologies, Inc. — Confidential, internal use', size: 18, color: GRAY, italics: true })],
            }),
            new Paragraph({ children: [new PageBreak()] }),

            // ---- Table of Contents (Word field - right-click and "Update
            // Field" in Word to populate it from the real heading styles) ----
            new Paragraph({ heading: HeadingLevel.HEADING_1, children: [new TextRun('Table of Contents')] }),
            new Paragraph({
                children: [new TextRun({ text: 'Right-click this area and choose "Update Field" (or press F9) to populate the table of contents from the headings below.', italics: true, size: 18, color: GRAY })],
                spacing: { after: 200 },
            }),
            new TableOfContents('Table of Contents', { hyperlink: true, headingStyleRange: '1-4' }),
            new Paragraph({ children: [new PageBreak()] }),

            // ---- Body ----
            ...bodyElements,
        ],
    }],
});

Packer.toBuffer(doc).then((buffer) => {
    const outPath = path.join(ROOT, 'task-assignee-user-manual.docx');
    fs.writeFileSync(outPath, buffer);
    console.log('Generated task-assignee-user-manual.docx');
}).catch((e) => { console.error('FATAL:', e); process.exit(1); });
