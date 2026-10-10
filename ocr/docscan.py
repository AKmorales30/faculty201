#!/usr/bin/env python3
"""
Document scanner for photos of paper documents.

    docscan.py INPUT OUTBASE [--corners x1,y1,...,x4,y4 | --whole] [--debug DEBUG.jpg]

Finds the sheet of paper in a photo, crops away the background (table,
other papers, hands...), corrects the perspective so the page is a flat
rectangle, evens out the lighting, turns it upright, and writes it as a
JPEG (for OCR and preview) and a one-page PDF (for the 201 file). The
corners can also be given by hand (--corners) when the detection was off.

If no sheet can be found with confidence -- e.g. the image is already a
clean scan or screenshot -- the whole image is kept (only straightened and
cleaned up), so content is never cut off by a bad guess.

Prints one line of JSON: {"ok": true, "cropped": bool, "corners": [...],
"rotated": deg, "width": w, "height": h, "src_width": w, "src_height": h}
or {"ok": false, "error": "..."}. Corners are in photo pixels (after EXIF
rotation), top-left, top-right, bottom-right, bottom-left.
"""
import json
import subprocess
import sys

import cv2
import numpy as np
from PIL import Image, ImageOps

DETECT_SIZE = 1000        # long side used for detection
MAX_OUTPUT = 3000         # long side of the stored page
MIN_AREA = 0.12           # a page must cover at least this share of the photo
FULL_FRAME = 0.92         # a quad this big that hugs the frame = already cropped
INNER_CONTRAST = 15       # colour difference a page inside such a frame needs from its surroundings


def load(path):
    img = Image.open(path)
    img = ImageOps.exif_transpose(img)   # phone photos are stored sideways with a rotate tag
    return cv2.cvtColor(np.array(img.convert('RGB')), cv2.COLOR_RGB2BGR)


def order_corners(pts):
    """top-left, top-right, bottom-right, bottom-left"""
    pts = np.asarray(pts, dtype=np.float32).reshape(4, 2)
    s = pts.sum(axis=1)
    d = np.diff(pts, axis=1).ravel()
    return np.array([pts[np.argmin(s)], pts[np.argmin(d)], pts[np.argmax(s)], pts[np.argmax(d)]], dtype=np.float32)


def angles_ok(q, tol=25):
    """Every corner within tol degrees of 90 (allows for perspective)."""
    for i in range(4):
        a, b, c = q[i - 1], q[i], q[(i + 1) % 4]
        v1, v2 = a - b, c - b
        cos = np.dot(v1, v2) / (np.linalg.norm(v1) * np.linalg.norm(v2) + 1e-9)
        if abs(np.degrees(np.arccos(np.clip(cos, -1, 1))) - 90) > tol:
            return False
    return True


def edge_map(img):
    """Edges of a text-free version of the photo, from brightness AND colour:
    a white certificate on white paper is often only told apart by its
    slightly different tint."""
    lab = cv2.cvtColor(img, cv2.COLOR_BGR2LAB)
    mag = np.zeros(img.shape[:2], np.float32)
    for ch, weight in zip(cv2.split(lab), (1.0, 3.0, 3.0)):
        c = cv2.medianBlur(ch, 7).astype(np.float32)          # drops text and noise, keeps step edges
        gx = cv2.Sobel(c, cv2.CV_32F, 1, 0, ksize=3)
        gy = cv2.Sobel(c, cv2.CV_32F, 0, 1, ksize=3)
        mag = np.maximum(mag, weight * cv2.magnitude(gx, gy))
    edges = (mag > 12).astype(np.uint8) * 255
    return edges, cv2.dilate(edges, np.ones((5, 5), np.uint8))


def side_support(near, p, q, samples=120):
    """Share of the segment p-q that lies on (dilated) edges."""
    t = np.linspace(0.02, 0.98, samples)
    xs = np.clip((p[0] + (q[0] - p[0]) * t).astype(int), 0, near.shape[1] - 1)
    ys = np.clip((p[1] + (q[1] - p[1]) * t).astype(int), 0, near.shape[0] - 1)
    return float(np.count_nonzero(near[ys, xs])) / samples


def intersect(l1, l2):
    (x1, y1, x2, y2), (x3, y3, x4, y4) = l1, l2
    d = (x1 - x2) * (y3 - y4) - (y1 - y2) * (x3 - x4)
    if abs(d) < 1e-6:
        return None
    a, b = x1 * y2 - y1 * x2, x3 * y4 - y3 * x4
    return np.array([(a * (x3 - x4) - (x1 - x2) * b) / d, (a * (y3 - y4) - (y1 - y2) * b) / d], dtype=np.float32)


def long_lines(edges, w, h):
    """Long straight segments, split into roughly horizontal / vertical and de-duplicated."""
    segs = cv2.HoughLinesP(edges, 1, np.pi / 360, threshold=60, minLineLength=int(0.2 * min(w, h)), maxLineGap=25)
    horiz, vert = [], []
    for x1, y1, x2, y2 in (segs.reshape(-1, 4) if segs is not None else []):
        ang = abs(np.degrees(np.arctan2(y2 - y1, x2 - x1))) % 180
        length = np.hypot(x2 - x1, y2 - y1)
        fam = horiz if ang < 30 or ang > 150 else vert if 60 < ang < 120 else None
        if fam is None:
            continue
        mid = ((y1 + y2) / 2) if fam is horiz else ((x1 + x2) / 2)
        for item in fam:   # same line seen twice: keep the longer piece
            if abs(item[1] - mid) < 0.015 * max(w, h) and abs(item[3] - ang) < 4:
                if length > item[2]:
                    item[:] = [(x1, y1, x2, y2), mid, length, ang]
                break
        else:
            fam.append([(x1, y1, x2, y2), mid, length, ang])
    pick = lambda fam: [f[0] for f in sorted(fam, key=lambda f: -f[2])[:14]]
    return pick(horiz), pick(vert)


def band_contrast(lab, q):
    """Colour difference just inside vs just outside the quad's sides. A real
    page edge separates paper from background; a border printed on the
    page has the same paper on both sides."""
    h, w = lab.shape[:2]
    c = q.mean(axis=0)
    diffs = []
    for i in range(4):
        p1, p2 = q[i], q[(i + 1) % 4]
        mid = (p1 + p2) / 2
        n = c - mid
        n /= (np.linalg.norm(n) + 1e-9)
        ins, outs = [], []
        for t in np.linspace(0.15, 0.85, 25):
            pt = p1 + (p2 - p1) * t
            a, b = (pt + n * 8).astype(int), (pt - n * 8).astype(int)
            if 0 <= b[0] < w and 0 <= b[1] < h and 0 <= a[0] < w and 0 <= a[1] < h:
                ins.append(lab[a[1], a[0]]); outs.append(lab[b[1], b[0]])
        if len(ins) > 5:
            diffs.append(float(np.linalg.norm(np.median(ins, axis=0) - np.median(outs, axis=0))))
        else:
            diffs.append(0.0)   # side runs along the frame: nothing outside it
    return float(np.median(diffs))


def aspect_ok(q, limit=2.3):
    """Documents are between about 1:2.3 and 2.3:1 (A4, Letter, Legal, certificates, IDs)."""
    w = (np.linalg.norm(q[1] - q[0]) + np.linalg.norm(q[2] - q[3])) / 2
    h = (np.linalg.norm(q[3] - q[0]) + np.linalg.norm(q[2] - q[1])) / 2
    return min(w, h) > 0 and max(w, h) / min(w, h) <= limit


def contour_quads(edges, w, h):
    """Closed outlines that simplify to four corners (works best for a page on a contrasting table)."""
    closed = cv2.morphologyEx(edges, cv2.MORPH_CLOSE, np.ones((7, 7), np.uint8))
    cnts, _ = cv2.findContours(closed, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
    out = []
    for c in sorted(cnts, key=cv2.contourArea, reverse=True)[:12]:
        if cv2.contourArea(c) < MIN_AREA * w * h:
            break
        hull = cv2.convexHull(c)
        peri = cv2.arcLength(hull, True)
        for eps in (0.015, 0.025, 0.04):
            approx = cv2.approxPolyDP(hull, eps * peri, True)
            if len(approx) == 4:
                out.append(order_corners(approx))
                break
    return out


def line_quads(edges, w, h):
    """Quadrilaterals from pairs of long roughly-horizontal and roughly-vertical lines."""
    horiz, vert = long_lines(edges, w, h)
    for i in range(len(horiz)):
        for j in range(i + 1, len(horiz)):
            for k in range(len(vert)):
                for m in range(k + 1, len(vert)):
                    pts = [intersect(horiz[a], vert[b]) for a, b in ((i, k), (i, m), (j, m), (j, k))]
                    if all(p is not None for p in pts):
                        yield order_corners(pts)


def find_page(small, inner=False):
    """Best page outline in the (downscaled) photo as (quad, confidence), or (None, 0).
    inner: the best outline whose sides clearly separate the page from what
    surrounds it (band_contrast() >= INNER_CONTRAST), leaving out the ones
    that hug the photo's frame (see main)."""
    h, w = small.shape[:2]
    edges, _ = edge_map(small)
    near = cv2.dilate(edges, np.ones((3, 3), np.uint8))
    best, best_score, best_conf = None, 0.0, 0.0
    valid = []
    for q in list(contour_quads(edges, w, h)) + list(line_quads(edges, w, h)):
        if (q[:, 0] < -0.02 * w).any() or (q[:, 0] > 1.02 * w).any() or (q[:, 1] < -0.02 * h).any() or (q[:, 1] > 1.02 * h).any():
            continue
        area = cv2.contourArea(q) / (w * h)
        if area < MIN_AREA or (not inner and area <= best_score) or not angles_ok(q, 15) or not aspect_ok(q):
            continue
        if inner and area > FULL_FRAME and touches_frame(q, w, h):
            continue
        sup = [side_support(near, q[s], q[(s + 1) % 4], 200) for s in range(4)]
        if min(sup) < 0.8:
            continue
        score = area * min(sup)
        if inner:
            valid.append((score, min(sup), q))
        elif score > best_score:
            best, best_score, best_conf = q, score, min(sup)
    if inner:
        lab = cv2.cvtColor(small, cv2.COLOR_BGR2LAB).astype(np.float32)
        for score, conf, q in sorted(valid, key=lambda v: -v[0])[:40]:
            if band_contrast(lab, q) >= INNER_CONTRAST:
                return q, conf
        return None, 0.0
    return best, best_conf


def touches_frame(q, w, h, margin=0.02):
    mx, my = margin * w, margin * h
    return all(x < mx or x > w - mx or y < my or y > h - my for x, y in q)


def warp(img, q):
    tl, tr, br, bl = q
    width = int(max(np.linalg.norm(tr - tl), np.linalg.norm(br - bl)))
    height = int(max(np.linalg.norm(bl - tl), np.linalg.norm(br - tr)))
    dst = np.array([[0, 0], [width - 1, 0], [width - 1, height - 1], [0, height - 1]], dtype=np.float32)
    m = cv2.getPerspectiveTransform(q, dst)
    return cv2.warpPerspective(img, m, (width, height), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)


def enhance(img):
    """Scanner-style clean-up that keeps colours (logos, seals, coloured
    borders): white-balance on the paper colour, then stretch so the paper
    is white and the ink dark."""
    out = img.astype(np.float32)
    bright = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY) >= np.percentile(cv2.cvtColor(img, cv2.COLOR_BGR2GRAY), 80)
    paper = np.array([np.median(out[..., c][bright]) for c in range(3)], dtype=np.float32)
    out *= 245.0 / np.maximum(paper, 1)                     # paper -> near white, removes colour casts
    lo = np.percentile(out, 0.5)
    out = (out - lo) * (255.0 / max(1.0, 245.0 - lo))
    return np.clip(out, 0, 255).astype(np.uint8)


def osd_rotation(img):
    """Degrees to turn clockwise so the text is upright (tesseract OSD), or 0."""
    try:
        ok, buf = cv2.imencode('.png', cv2.cvtColor(img, cv2.COLOR_BGR2GRAY))
        r = subprocess.run(['tesseract', 'stdin', 'stdout', '--psm', '0'], input=buf.tobytes(),
                           capture_output=True, timeout=60, env={'OMP_THREAD_LIMIT': '1', 'PATH': '/usr/bin:/usr/local/bin:/opt/homebrew/bin'})
        for line in r.stdout.decode(errors='ignore').splitlines():
            if line.startswith('Rotate:'):
                deg = int(line.split(':')[1])
                return deg if deg in (90, 180, 270) else 0
    except Exception:
        pass
    return 0


def main(argv):
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument('input')
    ap.add_argument('outbase', help='writes OUTBASE.jpg (page), OUTBASE.pdf and OUTBASE_orig.jpg (preview of the photo)')
    ap.add_argument('--corners', help='x1,y1,...,x4,y4 in photo pixels: crop here instead of detecting')
    ap.add_argument('--whole', action='store_true', help='keep the whole photo (no crop)')
    ap.add_argument('--debug', help='write the photo with the detected outline drawn on it')
    args = ap.parse_args(argv[1:])

    img = load(args.input)
    h, w = img.shape[:2]
    scale = DETECT_SIZE / max(h, w)
    small = cv2.resize(img, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_AREA)
    sh, sw = small.shape[:2]

    # Preview of the photo itself, for adjusting the corners by hand
    pf = min(1.0, 1400 / max(h, w))
    cv2.imwrite(args.outbase + '_orig.jpg', cv2.resize(img, (int(w * pf), int(h * pf)), interpolation=cv2.INTER_AREA),
                [cv2.IMWRITE_JPEG_QUALITY, 85])

    full = None   # corners in photo pixels
    if args.corners:
        vals = [float(v) for v in args.corners.split(',')]
        if len(vals) != 8:
            raise ValueError('--corners needs 8 numbers')
        full = order_corners(np.clip(np.array(vals, np.float32).reshape(4, 2), 0, [w - 1, h - 1]))
    elif not args.whole:
        q, _conf = find_page(small)
        lab = cv2.cvtColor(small, cv2.COLOR_BGR2LAB).astype(np.float32)
        if q is not None:
            area = cv2.contourArea(q) / (sw * sh)
            if (area > FULL_FRAME and touches_frame(q, sw, sh)) or (area > 0.6 and band_contrast(lab, q) < 6):
                # The page already fills the picture, or the outline is a border printed on an
                # already-cropped page -- or the photo's frame won because wood grain or another
                # paper lines its borders. A page inside still counts if it clearly stands out
                # from what surrounds it (paper on a desk); a clean scan has only printed boxes.
                q, _conf = find_page(small, inner=True)
        if q is not None:
            full = q / scale

    page = warp(img, full) if full is not None else img

    if args.debug:
        dbg = small.copy()
        if full is not None:
            cv2.polylines(dbg, [(full * scale).astype(np.int32)], True, (0, 0, 255), 3)
        cv2.imwrite(args.debug, dbg)

    ph, pw = page.shape[:2]
    if max(ph, pw) > MAX_OUTPUT:
        f = MAX_OUTPUT / max(ph, pw)
        page = cv2.resize(page, (int(pw * f), int(ph * f)), interpolation=cv2.INTER_AREA)
    page = enhance(page)

    rot = osd_rotation(page)
    if rot:
        page = cv2.rotate(page, {90: cv2.ROTATE_90_CLOCKWISE, 180: cv2.ROTATE_180, 270: cv2.ROTATE_90_COUNTERCLOCKWISE}[rot])

    cv2.imwrite(args.outbase + '.jpg', page, [cv2.IMWRITE_JPEG_QUALITY, 90])
    ph, pw = page.shape[:2]
    pil = Image.fromarray(cv2.cvtColor(page, cv2.COLOR_BGR2RGB))
    # about 11 inches along the long side, so the PDF opens at a sensible page size
    pil.save(args.outbase + '.pdf', 'PDF', resolution=max(72, round(max(ph, pw) / 11.0)), quality=90)

    print(json.dumps({'ok': True, 'cropped': full is not None, 'rotated': rot, 'width': pw, 'height': ph,
                      'src_width': w, 'src_height': h,
                      'corners': full.round().astype(int).tolist() if full is not None else None}))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main(sys.argv))
    except Exception as e:   # never let PHP see a traceback
        print(json.dumps({'ok': False, 'error': str(e)}))
        sys.exit(1)
