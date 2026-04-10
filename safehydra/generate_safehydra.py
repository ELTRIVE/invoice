#!/usr/bin/env python3
"""
Safe Hydra PDF Generator
Uses qpdf overlay to replace customer name and amount in the template PDF.
Usage: python3 generate_safehydra.py "Customer Name" "Amount" output.pdf [template.pdf]
"""
import sys, io, os, tempfile
from pypdf import PdfReader, PdfWriter
from reportlab.pdfgen import canvas

TEMPLATE = '/var/www/html/invoice/safehydra/safe_hydra_template.pdf'

def indian_format(n):
    n = int(float(str(n).replace(',', '')))
    s = str(n)
    if len(s) <= 3: return s
    last3 = s[-3:]; rest = s[:-3]; groups = []
    while len(rest) > 2: groups.append(rest[-2:]); rest = rest[:-2]
    if rest: groups.append(rest)
    groups.reverse()
    return ','.join(groups) + ',' + last3

def make_overlay_pdf(W, H, pages_items):
    """
    pages_items: dict of {page_index: [list of overlay items]}
    Returns path to a temp multi-page overlay PDF
    """
    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=(W, H))
    # We need one page per template page; blank for pages without changes
    total_pages = max(pages_items.keys()) + 1

    for page_num in range(total_pages):
        items = pages_items.get(page_num, [])
        if items:
            for item in items:
                cx0 = item.get('cover_x0', item['x0'] - 2)
                cx1 = item.get('cover_x1', item['x1'] + 2)
                rl_b = H - item['bottom'] - 1
                rl_t = H - item['top'] + 1
                bh = rl_t - rl_b
                # White cover
                c.setFillColorRGB(1, 1, 1)
                c.setStrokeColorRGB(1, 1, 1)
                c.rect(cx0, rl_b, cx1 - cx0, bh, fill=1, stroke=0)
                # New text
                r, g, b = item.get('color', (0, 0, 0))
                c.setFillColorRGB(r, g, b)
                fn = 'Times-Bold' if item.get('bold') else 'Times-Roman'
                fs = item.get('font_size', 9.5)
                c.setFont(fn, fs)
                c.drawString(item['x0'], rl_b + (bh - fs) / 2 + 1, item['text'])
        c.showPage()

    c.save()
    buf.seek(0)

    tmp = tempfile.NamedTemporaryFile(suffix='.pdf', delete=False)
    tmp.write(buf.read())
    tmp.close()
    return tmp.name


def generate_pdf(customer_name, amount_raw, template_path, output_path):
    formatted = indian_format(amount_raw)

    reader = PdfReader(template_path)
    W = float(reader.pages[0].mediabox.width)
    H = float(reader.pages[0].mediabox.height)
    total_pages = len(reader.pages)

    pages_items = {}

    # Page 1 (index 0): Customer value row
    # Covers "M/S Aragen Pharma, Nacharam" (x0=175.8 → x1=346.6, top=247.1, bottom=259.1)
    pages_items[0] = [{
        'x0': 175.8, 'top': 247.1, 'x1': 346.6, 'bottom': 259.1,
        'cover_x0': 174, 'cover_x1': 400,
        'text': customer_name, 'font_size': 9.5, 'bold': False,
    }]

    # Page 3 (index 2): Replace only customer name text at "Aragen Pharma, Nacharam"
    pages_items[2] = [{
        'x0': 261.3, 'top': 101.5, 'x1': 381.2, 'bottom': 115.5,
        'cover_x0': 259, 'cover_x1': 382,
        'text': customer_name, 'font_size': 9.5, 'bold': False,
    }]

    # Page 4 (index 3): Total Amount field
    pages_items[3] = [{
        'x0': 365.1, 'top': 454.5, 'x1': 404.5, 'bottom': 467.5,
        'cover_x0': 340, 'cover_x1': 430,
        'text': formatted, 'font_size': 9.5, 'bold': True,
    }]

    # Make full-template-length overlay PDF (blank pages for unchanged pages)
    overlay_path = make_overlay_pdf(W, H, pages_items)

    try:
        overlay_reader = PdfReader(overlay_path)
        writer = PdfWriter()

        for idx in range(total_pages):
            base_page = reader.pages[idx]
            if idx < len(overlay_reader.pages):
                base_page.merge_page(overlay_reader.pages[idx])
            writer.add_page(base_page)

        with open(output_path, "wb") as f:
            writer.write(f)
        return True
    finally:
        os.unlink(overlay_path)


if __name__ == '__main__':
    if len(sys.argv) < 4:
        print("Usage: python3 generate_safehydra.py 'Customer Name' 'Amount' output.pdf [template.pdf]")
        sys.exit(1)
    tmpl = sys.argv[4] if len(sys.argv) > 4 else TEMPLATE
    success = generate_pdf(sys.argv[1], sys.argv[2], tmpl, sys.argv[3])
    if success:
        print(f"Generated: {sys.argv[3]}")
