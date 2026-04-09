const {
    Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
    Header, Footer, AlignmentType, HeadingLevel, BorderStyle, WidthType,
    ShadingType, VerticalAlign, PageNumber, PageBreak, LevelFormat,
    TableOfContents, UnderlineType,
} = require('docx');
const fs = require('fs');

const data = JSON.parse(fs.readFileSync(process.env.TC_JSON || '/tmp/tc_data.json', 'utf8'));

// ── Constants ──────────────────────────────────────────────────────────────────
const A4_W = 11906;
const A4_H = 16838;
const MARGIN = 1080;
const CONTENT_W = A4_W - MARGIN * 2;

// ── Helpers ────────────────────────────────────────────────────────────────────
function fmt(n) { return Number(n || 0).toLocaleString('en-IN'); }

const B  = { style: BorderStyle.SINGLE, size: 4, color: '000000' };
const NB = { style: BorderStyle.NONE,   size: 0, color: 'FFFFFF' };
const BORDERS    = { top: B,  bottom: B,  left: B,  right: B  };
const NO_BORDERS = { top: NB, bottom: NB, left: NB, right: NB };

function cell(children, opts = {}) {
    return new TableCell({
        borders:       opts.borders ?? BORDERS,
        width:         opts.width ? { size: opts.width, type: WidthType.DXA } : undefined,
        shading:       opts.bg ? { fill: opts.bg, type: ShadingType.CLEAR } : undefined,
        verticalAlign: opts.valign ?? VerticalAlign.CENTER,
        margins:       { top: 60, bottom: 60, left: 100, right: 100 },
        columnSpan:    opts.span,
        children:      Array.isArray(children) ? children : [children],
    });
}

function para(text, opts = {}) {
    return new Paragraph({
        alignment: opts.align ?? AlignmentType.LEFT,
        spacing:   { before: opts.spaceBefore ?? 40, after: opts.spaceAfter ?? 40 },
        border:    opts.border,
        children: [new TextRun({
            text:      String(text ?? ''),
            bold:      opts.bold   ?? false,
            italics:   opts.italic ?? false,
            size:      opts.size   ?? 18,
            font:      'Arial',
            color:     opts.color  ?? '000000',
            underline: opts.underline ? { type: UnderlineType.SINGLE } : undefined,
        })],
        ...(opts.numbering ? { numbering: opts.numbering } : {}),
        ...(opts.indent    ? { indent:    opts.indent }    : {}),
    });
}

function h1(text) {
    return new Paragraph({
        heading: HeadingLevel.HEADING_1,
        spacing: { before: 200, after: 100 },
        children: [new TextRun({ text, bold: true, size: 24, font: 'Arial' })],
    });
}
function h2(text) {
    return new Paragraph({
        heading: HeadingLevel.HEADING_2,
        spacing: { before: 140, after: 60 },
        children: [new TextRun({ text, bold: true, size: 20, font: 'Arial' })],
    });
}
function spacer(pt = 1) {
    return new Paragraph({
        spacing: { before: 0, after: 0 },
        children: [new TextRun({ text: '', size: Math.round(18 * pt) })],
    });
}
function pageBreak() {
    return new Paragraph({ children: [new PageBreak()] });
}

// ── Header ─────────────────────────────────────────────────────────────────────
function makeHeader() {
    const LW = Math.round(CONTENT_W * 0.28);
    const RW = CONTENT_W - LW;
    return new Header({
        children: [
            new Table({
                width: { size: CONTENT_W, type: WidthType.DXA },
                columnWidths: [LW, RW],
                rows: [new TableRow({
                    children: [
                        new TableCell({
                            borders: { top: NB, bottom: B, left: NB, right: B },
                            width: { size: LW, type: WidthType.DXA },
                            margins: { top: 40, bottom: 40, left: 80, right: 80 },
                            children: [
                                new Paragraph({ children: [new TextRun({ text: 'ELTRIVE', bold: true, size: 32, font: 'Arial', color: '1a4e79' })] }),
                                para('Automations', { italic: true, size: 16 }),
                            ],
                        }),
                        new TableCell({
                            borders: { top: NB, bottom: B, left: B, right: NB },
                            width: { size: RW, type: WidthType.DXA },
                            margins: { top: 40, bottom: 40, left: 120, right: 80 },
                            verticalAlign: VerticalAlign.CENTER,
                            children: [para(data.project_title, { size: 20 })],
                        }),
                    ],
                })],
            }),
        ],
    });
}

// ── Footer ─────────────────────────────────────────────────────────────────────
function makeFooter() {
    const C1 = Math.round(CONTENT_W * 0.28);
    const C2 = Math.round(CONTENT_W * 0.38);
    const C3 = Math.round(CONTENT_W * 0.16);
    const C4 = CONTENT_W - C1 - C2 - C3;

    const fc = (children, w) => new TableCell({
        borders: { top: B, bottom: NB, left: NB, right: NB },
        width: { size: w, type: WidthType.DXA },
        margins: { top: 40, bottom: 0, left: 80, right: 80 },
        children: Array.isArray(children) ? children : [children],
    });

    return new Footer({
        children: [
            new Table({
                width: { size: CONTENT_W, type: WidthType.DXA },
                columnWidths: [C1, C2, C3, C4],
                rows: [new TableRow({
                    children: [
                        fc([
                            para(`Designed by   ${data.designed_by}`, { size: 14 }),
                            para(`Released by   ${data.released_by}`, { size: 14 }),
                            para('ELTRIVE AUTOMATIONS PVT LTD', { bold: true, size: 16 }),
                            para(data.company_email, { size: 14 }),
                        ], C1),
                        fc([
                            para(data.designed_title, { size: 14 }),
                            para(data.released_title, { size: 14 }),
                            para(`Title: ${data.project_title}`, { bold: true, size: 14 }),
                            para(`Version:${data.version}`, { size: 14 }),
                        ], C2),
                        fc([
                            para('Template Ver:', { size: 14 }),
                            para('Document Key:', { size: 14 }),
                            para(data.doc_date, { size: 14 }),
                            new Paragraph({
                                spacing: { before: 0, after: 0 },
                                children: [
                                    new TextRun({ text: 'Page no:  ', size: 14, font: 'Arial' }),
                                    new TextRun({ children: [PageNumber.CURRENT], size: 14, font: 'Arial' }),
                                ],
                            }),
                        ], C3),
                        fc([
                            para(`Rev:${data.revision}`, { size: 14 }),
                            para(data.doc_key, { size: 14 }),
                            para('', { size: 14 }),
                        ], C4),
                    ],
                })],
            }),
        ],
    });
}

// ── Cover page ─────────────────────────────────────────────────────────────────
function coverPage() {
    const out = [];
    for (let i = 0; i < 8; i++) out.push(spacer());

    const infoRows = [
        ['Project:',      data.project_title],
        ['Document Key:', data.doc_key],
        ['Version:',      data.version_desc],
        ['Revision',      data.revision],
        ['Customer',      data.customer_name],
    ];

    for (const [label, value] of infoRows) {
        const LW = Math.round(CONTENT_W * 0.25);
        const RW = CONTENT_W - LW;
        out.push(new Table({
            width: { size: CONTENT_W, type: WidthType.DXA },
            columnWidths: [LW, RW],
            rows: [new TableRow({
                children: [
                    cell(para(label, { bold: true, size: 20 }), { borders: NO_BORDERS, width: LW }),
                    cell(para(value, { size: 20 }),              { borders: NO_BORDERS, width: RW }),
                ],
            })],
        }));
        out.push(spacer(0.4));
    }

    out.push(spacer(4));

    // Authors table
    const aW = [
        Math.round(CONTENT_W * 0.13),
        Math.round(CONTENT_W * 0.20),
        Math.round(CONTENT_W * 0.17),
        Math.round(CONTENT_W * 0.35),
        CONTENT_W - Math.round(CONTENT_W * 0.13) - Math.round(CONTENT_W * 0.20) - Math.round(CONTENT_W * 0.17) - Math.round(CONTENT_W * 0.35),
    ];
    out.push(new Table({
        width: { size: CONTENT_W, type: WidthType.DXA },
        columnWidths: aW,
        rows: [
            new TableRow({ children: [
                cell(para('Role',        { bold: true, size: 18 }), { bg: 'E0E0E0', width: aW[0] }),
                cell(para('Name:',       { bold: true, size: 18 }), { bg: 'E0E0E0', width: aW[1] }),
                cell(para('Department:', { bold: true, size: 18 }), { bg: 'E0E0E0', width: aW[2] }),
                cell(para('Email',       { bold: true, size: 18 }), { bg: 'E0E0E0', width: aW[3] }),
                cell(para('Date:',       { bold: true, size: 18 }), { bg: 'E0E0E0', width: aW[4] }),
            ]}),
            ...(data.authors || []).map(a => new TableRow({ children: [
                cell(para(a.role  || '', { size: 18 }), { width: aW[0] }),
                cell(para(a.name  || '', { size: 18 }), { width: aW[1] }),
                cell(para(a.dept  || '', { size: 18 }), { width: aW[2] }),
                cell(para(a.email || '', { size: 18, underline: true, color: '1155CC' }), { width: aW[3] }),
                cell(para(a.date  || '', { size: 18 }), { width: aW[4] }),
            ]})),
        ],
    }));

    out.push(spacer(4));

    // Revision history
    out.push(para('Revision History', { bold: true, size: 22 }));
    out.push(spacer(0.5));
    const rW = [
        Math.round(CONTENT_W * 0.12),
        Math.round(CONTENT_W * 0.15),
        Math.round(CONTENT_W * 0.15),
        CONTENT_W - Math.round(CONTENT_W * 0.12) - Math.round(CONTENT_W * 0.15) - Math.round(CONTENT_W * 0.15),
    ];
    out.push(new Table({
        width: { size: CONTENT_W, type: WidthType.DXA },
        columnWidths: rW,
        rows: [
            new TableRow({ children: [
                cell(para('Version',        { bold: true, size: 18 }), { bg: 'E0E0E0', width: rW[0] }),
                cell(para('Previous Ver',   { bold: true, size: 18 }), { bg: 'E0E0E0', width: rW[1] }),
                cell(para('Date',           { bold: true, size: 18 }), { bg: 'E0E0E0', width: rW[2] }),
                cell(para('Change Content', { bold: true, size: 18 }), { bg: 'E0E0E0', width: rW[3] }),
            ]}),
            ...(data.revisions || []).map(r => new TableRow({ children: [
                cell(para(r.ver    || '', { size: 18 }), { width: rW[0] }),
                cell(para(r.prev   || '', { size: 18 }), { width: rW[1] }),
                cell(para(r.date   || '', { size: 18 }), { width: rW[2] }),
                cell(para(r.change || '', { size: 18 }), { width: rW[3] }),
            ]})),
        ],
    }));

    out.push(pageBreak());
    return out;
}

// ── TOC page ───────────────────────────────────────────────────────────────────
function tocPage() {
    return [
        new Paragraph({
            spacing: { before: 160, after: 160 },
            children: [new TextRun({ text: 'Contents', bold: true, size: 24, font: 'Arial' })],
        }),
        new TableOfContents('Table of Contents', { hyperlink: true, headingStyleRange: '1-2' }),
        pageBreak(),
    ];
}

// ── BOQ tables ─────────────────────────────────────────────────────────────────
function hwTable(rows) {
    const cW = [
        Math.round(CONTENT_W * 0.06),
        Math.round(CONTENT_W * 0.50),
        Math.round(CONTENT_W * 0.07),
        Math.round(CONTENT_W * 0.09),
        Math.round(CONTENT_W * 0.14),
        CONTENT_W - Math.round(CONTENT_W * 0.06) - Math.round(CONTENT_W * 0.50) - Math.round(CONTENT_W * 0.07) - Math.round(CONTENT_W * 0.09) - Math.round(CONTENT_W * 0.14),
    ];
    return new Table({
        width: { size: CONTENT_W, type: WidthType.DXA },
        columnWidths: cW,
        rows: [
            new TableRow({ children: [
                cell(para('S.No',   { bold: true, size: 16 }),                       { bg: 'E0E0E0', width: cW[0] }),
                cell(para('Details',{ bold: true, size: 16 }),                       { bg: 'E0E0E0', width: cW[1] }),
                cell(para('Qty',    { bold: true, size: 16, align: AlignmentType.CENTER }), { bg: 'E0E0E0', width: cW[2] }),
                cell(para('Unit',   { bold: true, size: 16, align: AlignmentType.CENTER }), { bg: 'E0E0E0', width: cW[3] }),
                cell(para('Price',  { bold: true, size: 16, align: AlignmentType.RIGHT }),  { bg: 'E0E0E0', width: cW[4] }),
                cell(para('Amount', { bold: true, size: 16, align: AlignmentType.RIGHT }),  { bg: 'E0E0E0', width: cW[5] }),
            ]}),
            ...(rows || []).map(r => new TableRow({ children: [
                cell(para(r.sno   || '',           { size: 16 }),                              { width: cW[0] }),
                cell(para(r.desc  || '',           { size: 16 }),                              { width: cW[1] }),
                cell(para(r.qty   || '1',          { size: 16, align: AlignmentType.CENTER }), { width: cW[2] }),
                cell(para(r.unit  || 'Lot',        { size: 16, align: AlignmentType.CENTER }), { width: cW[3] }),
                cell(para(fmt(r.price  || 0),      { size: 16, align: AlignmentType.RIGHT }),  { width: cW[4] }),
                cell(para(fmt(r.amount || 0),      { size: 16, bold: true, align: AlignmentType.RIGHT }), { width: cW[5] }),
            ]})),
        ],
    });
}

function svcTable(rows) {
    const svcTotal = (rows || []).reduce((s, r) => s + Number(r.amount || 0), 0);
    const cW = [
        Math.round(CONTENT_W * 0.07),
        Math.round(CONTENT_W * 0.50),
        Math.round(CONTENT_W * 0.12),
        Math.round(CONTENT_W * 0.08),
        CONTENT_W - Math.round(CONTENT_W * 0.07) - Math.round(CONTENT_W * 0.50) - Math.round(CONTENT_W * 0.12) - Math.round(CONTENT_W * 0.08),
    ];
    return new Table({
        width: { size: CONTENT_W, type: WidthType.DXA },
        columnWidths: cW,
        rows: [
            new TableRow({ children: [
                cell(para('S.No',   { bold: true, size: 16 }), { bg: 'E0E0E0', width: cW[0] }),
                cell(para('Details',{ bold: true, size: 16 }), { bg: 'E0E0E0', width: cW[1] }),
                cell(para('Make',   { bold: true, size: 16 }), { bg: 'E0E0E0', width: cW[2] }),
                cell(para('Qty',    { bold: true, size: 16, align: AlignmentType.CENTER }), { bg: 'E0E0E0', width: cW[3] }),
                cell(para('Amount', { bold: true, size: 16, align: AlignmentType.RIGHT }),  { bg: 'E0E0E0', width: cW[4] }),
            ]}),
            ...(rows || []).map(r => new TableRow({ children: [
                cell(para(r.sno  || '',      { size: 16 }),                              { width: cW[0] }),
                cell(para(r.desc || '',      { size: 16 }),                              { width: cW[1] }),
                cell(para(r.make || '',      { size: 16 }),                              { width: cW[2] }),
                cell(para(r.qty  || '1',     { size: 16, align: AlignmentType.CENTER }), { width: cW[3] }),
                cell(para(fmt(r.amount || 0),{ size: 16, align: AlignmentType.RIGHT }),  { width: cW[4] }),
            ]})),
            new TableRow({ children: [
                cell(para('', { size: 16 }), { width: cW[0] }),
                cell(para('', { size: 16 }), { width: cW[1] }),
                cell(para('', { size: 16 }), { width: cW[2] }),
                cell(para('Service Supply Total', { bold: true, size: 16, align: AlignmentType.RIGHT }), { bg: 'E0E0E0', width: cW[3] }),
                cell(para(fmt(svcTotal), { bold: true, size: 16, align: AlignmentType.RIGHT }),          { bg: 'FFFDE7', width: cW[4] }),
            ]}),
        ],
    });
}

function commercialsTable(rows) {
    const total = (rows || []).reduce((s, r) => s + Number(r.amount || 0), 0);
    const cW = [
        Math.round(CONTENT_W * 0.07),
        Math.round(CONTENT_W * 0.38),
        Math.round(CONTENT_W * 0.13),
        Math.round(CONTENT_W * 0.07),
        Math.round(CONTENT_W * 0.10),
        CONTENT_W - Math.round(CONTENT_W * 0.07) - Math.round(CONTENT_W * 0.38) - Math.round(CONTENT_W * 0.13) - Math.round(CONTENT_W * 0.07) - Math.round(CONTENT_W * 0.10),
    ];
    return new Table({
        width: { size: CONTENT_W, type: WidthType.DXA },
        columnWidths: cW,
        rows: [
            new TableRow({ children: [
                cell(para('Item',       { bold: true, size: 16 }),                              { bg: 'E0E0E0', width: cW[0] }),
                cell(para('Details',    { bold: true, size: 16 }),                              { bg: 'E0E0E0', width: cW[1] }),
                cell(para('HSN',        { bold: true, size: 16, align: AlignmentType.CENTER }), { bg: 'E0E0E0', width: cW[2] }),
                cell(para('Qty',        { bold: true, size: 16, align: AlignmentType.CENTER }), { bg: 'E0E0E0', width: cW[3] }),
                cell(para('Unit',       { bold: true, size: 16, align: AlignmentType.CENTER }), { bg: 'E0E0E0', width: cW[4] }),
                cell(para('Amount(Rs)', { bold: true, size: 16, align: AlignmentType.RIGHT }),  { bg: 'E0E0E0', width: cW[5] }),
            ]}),
            ...(rows || []).map(r => new TableRow({ children: [
                cell(para(r.item || '',      { size: 16 }),                              { width: cW[0] }),
                cell(para(r.desc || '',      { size: 16 }),                              { width: cW[1] }),
                cell(para(r.hsn  || '',      { size: 16, align: AlignmentType.CENTER }), { width: cW[2] }),
                cell(para(r.qty  || '1',     { size: 16, align: AlignmentType.CENTER }), { width: cW[3] }),
                cell(para(r.unit || 'Lot',   { size: 16, align: AlignmentType.CENTER }), { width: cW[4] }),
                cell(para(fmt(r.amount || 0),{ size: 16, align: AlignmentType.RIGHT }),  { width: cW[5] }),
            ]})),
            new TableRow({ children: [
                cell(para('', { size: 16 }), { width: cW[0] }),
                cell(para('', { size: 16 }), { width: cW[1] }),
                cell(para('', { size: 16 }), { width: cW[2] }),
                cell(para('', { size: 16 }), { width: cW[3] }),
                cell(para('Total Amount', { bold: true, size: 16, align: AlignmentType.RIGHT }), { bg: 'E0E0E0', width: cW[4] }),
                cell(para(fmt(total),     { bold: true, size: 16, align: AlignmentType.RIGHT }), { bg: 'FFFACD', width: cW[5] }),
            ]}),
        ],
    });
}

// ── Body content ───────────────────────────────────────────────────────────────
const payTermLines = (data.payment_terms || '').split('\n').filter(l => l.trim());

const body = [
    ...coverPage(),
    ...tocPage(),

    h1('1.   Project Overview'),
    para(`The fire pump house at ${data.customer_name} plays a mission-critical role in plant safety. This Techno-Commercial proposal details the scope, deliverables, and commercials for the ${data.project_title}.`, { size: 18, spaceBefore: 80, spaceAfter: 80 }),
    spacer(),
    h2('1.1   Benefits'),
    h2('1.2   Key Features'),

    h1('2.   Scope of Work'),
    h2('2.1   Eltrive Scope'),
    h2('2.2   Customer Scope / Support Required'),
    h2('2.3   Assumptions'),
    h2('2.4   Out of Scope'),

    h1('3.   Deliverables'),
    h2('3.1   Hardware Deliverables (BOQ)'),
    spacer(0.5),
    hwTable(data.hw_rows),
    spacer(),
    h2('3.2   Services Supply'),
    spacer(0.5),
    svcTable(data.svc_rows),
    spacer(),

    h1('4.   User Access & Application Availability'),
    h2('4.1   Mobile Application Access'),
    h2('4.2   Web Application Access'),
    h2('4.3   Additional Users'),
    h2('4.4   Access Control & Security'),

    h1('5.   Application Reference Images'),
    h1('6.   Site Wise Proposals'),

    h1('7.   Commercials'),
    h2('7.1   Price Breakup'),
    spacer(0.5),
    commercialsTable(data.comm_rows),
    spacer(),
    h2('7.2   Tension Free AMC'),
    para(`7.2.1   Yearly AMC Charges – ${fmt(data.amc_yearly)}`, { bold: true, size: 18, spaceBefore: 80 }),
    h2('7.3   Total Cost'),
    para(`7.3.1   ${data.total_cost_desc}${fmt(data.total_cost)}`, { bold: true, size: 18, color: 'B8860B', spaceBefore: 80 }),
    h2('7.4   Payment Terms'),
    ...payTermLines.map((line, i) =>
        para(`7.4.${i + 1}   ${line}`, { size: 18, spaceBefore: 50 })
    ),
    ...(data.notes ? [spacer(), para(data.notes, { size: 18 })] : []),
];

// ── Build & write ──────────────────────────────────────────────────────────────
const doc = new Document({
    styles: {
        default: { document: { run: { font: 'Arial', size: 18 } } },
        paragraphStyles: [
            {
                id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true,
                run: { size: 24, bold: true, font: 'Arial', color: '000000' },
                paragraph: { spacing: { before: 240, after: 120 }, outlineLevel: 0 },
            },
            {
                id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true,
                run: { size: 20, bold: true, font: 'Arial', color: '000000' },
                paragraph: { spacing: { before: 160, after: 80 }, outlineLevel: 1 },
            },
        ],
    },
    sections: [{
        properties: {
            page: {
                size: { width: A4_W, height: A4_H },
                margin: { top: MARGIN, right: MARGIN, bottom: MARGIN + 900, left: MARGIN, footer: 360, header: 400 },
            },
        },
        headers: { default: makeHeader() },
        footers: { default: makeFooter() },
        children: body,
    }],
});

Packer.toBuffer(doc)
    .then(buf => { fs.writeFileSync(process.env.TC_OUT || '/tmp/tc_output.docx', buf); console.log('OK'); })
    .catch(err => { console.error(err.message); process.exit(1); });