#!/usr/bin/env python3
"""
Combine page pictures into ONE PDF, in the order given -- the several-picture
upload of faculty/submit_document.php (through DocScanner::combine()).

    python3 combine_pdf.py OUT.pdf PAGE1.jpg PAGE2.jpg ... [--max-side 2000]

Each picture: turned upright from its EXIF tag (phone photos are stored
sideways), converted to RGB, made no bigger than --max-side pixels on its
longest side (keeps the text readable for OCR and the PDF a sensible size),
and placed on its own page, which fits the picture (about 11 inches on the
long side). Prints one JSON line: {"ok": true, "pages": N} or {"ok": false,
"error": "..."} -- never a traceback.
"""
import json
import sys

from PIL import Image, ImageOps


def page(path, max_side):
    img = Image.open(path)
    img = ImageOps.exif_transpose(img)
    img = img.convert('RGB')
    w, h = img.size
    if max(w, h) > max_side:
        f = max_side / float(max(w, h))
        img = img.resize((max(1, int(w * f)), max(1, int(h * f))), Image.LANCZOS)
    return img


def main(argv):
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument('out')
    ap.add_argument('pages', nargs='+')
    ap.add_argument('--max-side', type=int, default=2000)
    args = ap.parse_args(argv[1:])

    pages = [page(p, max(500, args.max_side)) for p in args.pages]
    first = pages[0]
    # about 11 inches along the long side, so the PDF opens at a sensible page size
    resolution = max(72, round(max(first.size) / 11.0))
    first.save(args.out, 'PDF', resolution=resolution, quality=88, save_all=True, append_images=pages[1:])
    print(json.dumps({'ok': True, 'pages': len(pages)}))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main(sys.argv))
    except Exception as e:   # never let PHP see a traceback
        print(json.dumps({'ok': False, 'error': str(e)}))
