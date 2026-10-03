"""Export the live public catalogue to a printable customer price list."""
import json
from collections import OrderedDict
from datetime import datetime
from pathlib import Path
from urllib.request import urlopen

from pypdf import PdfReader
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / 'output' / 'pdf' / 'Dripclean-Laundry-Price-List.pdf'
with urlopen('http://localhost/Driplaudary/catalog.php', timeout=15) as response:
    snapshot = json.load(response)
items = snapshot['catalog']
groups = OrderedDict()
loads = [item for item in items if item.get('unit') == 'load']
assert len(loads) <= 3, 'Expand the load-price panel before adding more than three packages.'
for item in items:
    if item.get('unit') == 'load':
        continue
    groups.setdefault(item['category'], []).append(item)
assert len(items) == len({item['id'] for item in items}), 'Duplicate catalogue entries'

NAVY = colors.HexColor('#173F53')
BLUE = colors.HexColor('#008AB5')
PALE = colors.HexColor('#E9F6FC')
RULE = colors.HexColor('#CAE4EF')
MUTED = colors.HexColor('#4D6B79')
WHITE = colors.white
WIDTH, HEIGHT = A4
LEFT, RIGHT = 40, WIDTH - 40

def price(value):
    return 'Ask for a quote' if value is None else f'{value:,.2f}'

def text(x, y, value, size=10, bold=False, color=NAVY, align='left'):
    pdf.setFont('Helvetica-Bold' if bold else 'Helvetica', size)
    pdf.setFillColor(color)
    (pdf.drawRightString if align == 'right' else pdf.drawString)(x, y, value)

# Keep every category intact. Start a new page before a section would hit the footer.
pages = [[]]
space = 682
for category, rows in groups.items():
    required = 46 + 18 * len(rows)
    if space - required < 160:
        pages.append([])
        space = 682
    if required > 522:
        raise ValueError('Category is too long for a single page; adjust the layout.')
    pages[-1].append((category, rows))
    space -= required + 12

OUTPUT.parent.mkdir(parents=True, exist_ok=True)
pdf = canvas.Canvas(str(OUTPUT), pagesize=A4)
pdf.setTitle('Dripclean Laundry - Price List')
pdf.setAuthor('Dripclean Laundry')
pdf.setSubject('Current wash and fold and wash and iron prices in Ghana cedis')
date_label = datetime.now().strftime('%d %B %Y')
for page_number, categories in enumerate(pages, 1):
    pdf.drawImage(str(ROOT / 'assets' / 'dripclean-logo.png'), LEFT, 750, 168, 84, mask='auto', preserveAspectRatio=True)
    text(265, 793, 'LAUNDRY PRICE LIST', 21, True)
    text(265, 772, 'Fresh clothes. Care for every item.', 10, color=MUTED)
    text(265, 753, 'GHS per item; weight packages priced per load.', 9, color=MUTED)
    pdf.setStrokeColor(RULE)
    pdf.line(LEFT, 738, RIGHT, 738)
    pdf.setFillColor(PALE)
    pdf.roundRect(LEFT, 701, RIGHT - LEFT, 25, 5, fill=1, stroke=0)
    text(54, 709, 'OPEN 24 HOURS', 9, True, BLUE)
    text(RIGHT - 14, 709, 'FREE PICKUP & DELIVERY', 9, True, BLUE, 'right')
    top = 682
    for category, rows in categories:
        pdf.setFillColor(NAVY)
        pdf.rect(LEFT, top - 24, RIGHT - LEFT, 24, fill=1, stroke=0)
        text(54, top - 16, category, 11, True, WHITE)
        pdf.setFillColor(PALE)
        pdf.rect(LEFT, top - 46, RIGHT - LEFT, 22, fill=1, stroke=0)
        text(54, top - 39, 'ITEM', 8, True)
        text(407, top - 39, 'WASH & FOLD', 8, True, align='right')
        text(RIGHT - 14, top - 39, 'WASH & IRON', 8, True, align='right')
        y = top - 46
        for idx, item in enumerate(rows):
            if idx % 2 == 0:
                pdf.setFillColor(colors.HexColor('#F5FAFD'))
                pdf.rect(LEFT, y - 18, RIGHT - LEFT, 18, fill=1, stroke=0)
            text(54, y - 12, item['name'], 9)
            text(407, y - 12, price(item['fold']), 9, align='right')
            text(RIGHT - 14, y - 12, price(item['iron']), 9, align='right')
            y -= 18
        pdf.setStrokeColor(RULE)
        pdf.line(LEFT, y, RIGHT, y)
        top = y - 12

    if page_number == 1 and loads:
        assert top >= 206, 'Load-price panel overlaps the item tables.'
        pdf.setFillColor(PALE)
        pdf.roundRect(LEFT, 156, RIGHT - LEFT, 50, 6, fill=1, stroke=0)
        text(54, 191, 'WASH & FOLD BY WEIGHT - PRICE PER LOAD', 9, True, BLUE)
        for idx, load in enumerate(loads):
            text(54 + idx * 163, 171, f'{load["name"]}: GHS {price(load["fold"])}', 10, True)

    pdf.setFillColor(PALE)
    pdf.roundRect(LEFT, 84, RIGHT - LEFT, 63, 6, fill=1, stroke=0)
    text(54, 130, 'SAVE ON YOUR ORDER', 9, True, BLUE)
    text(54, 114, '5% off subtotals over GHS 100 and below GHS 200.', 9)
    text(54, 99, '10% off subtotals of GHS 200 or more. One discount applies per order.', 9)
    text(LEFT, 68, 'Ask for a quote where no fixed price is listed.', 8, color=MUTED)
    pdf.setStrokeColor(RULE)
    pdf.line(LEFT, 58, RIGHT, 58)
    text(LEFT, 44, 'Madina Estate  |  0508103264 / 0556333654', 9, True)
    text(LEFT, 29, f'Current price list: {date_label}', 8, color=MUTED)
    text(RIGHT, 29, f'Page {page_number} of {len(pages)}', 8, color=MUTED, align='right')
    pdf.showPage()
pdf.save()

# Verify every exported item and both prices against the same live snapshot.
reader = PdfReader(str(OUTPUT))
assert len(reader.pages) == len(pages)
for document_page, categories in zip(reader.pages, pages):
    extracted = document_page.extract_text()
    for category, rows in categories:
        assert category in extracted, f'Missing category: {category}'
        for item in rows:
            expected = '\n'.join((item['name'], price(item['fold']), price(item['iron'])))
            assert expected in extracted, f'Incorrect or missing price row: {item["id"]}'
for load in loads:
    assert f'{load["name"]}: GHS {price(load["fold"])}' in reader.pages[0].extract_text(), f'Missing load price: {load["id"]}'
print(f'Created {OUTPUT}: {len(items)} items and load packages, {len(pages)} pages. Every price verified against the live catalogue.')
