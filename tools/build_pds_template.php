<?php
/**
 * Builds the printable PDS templates from the official CSC workbook.
 *
 *   php tools/build_pds_template.php "CS Form No. 212 Revised 2026 Personal Data Sheet PDS.xlsx"
 *
 * Every sheet's print area (C1-C4 and the continuation sheets C5-C11) is
 * converted cell-for-cell into HTML -- column widths, row heights, merged
 * cells, fonts (incl. rich-text runs), borders, fills, alignment -- plus
 * the form's checkboxes, photo box and lines at their anchored positions,
 * and each sheet's page setup (margins, fit-to-page scale, centering; the
 * paper size itself is set in includes/pds_form.php).
 *
 * Output (committed; regenerate only when CSC releases a new form):
 *   includes/pds_form/sheets/C1.html ... C11.html   one page each
 *   includes/pds_form/pds_form.css                  cell / font styles
 *   includes/pds_form/sheets.json                   page setup + geometry
 *
 * Empty cells are emitted as {{REF}} placeholders (e.g. {{D10}}) and
 * checkboxes as {{chk:D16}}; includes/pds_form.php fills them in.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // command-line tool only
if ($argc < 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php tools/build_pds_template.php <official PDS .xlsx>\n");
    exit(1);
}
$zip = new ZipArchive();
if ($zip->open($argv[1]) !== true) { fwrite(STDERR, "Cannot open workbook\n"); exit(1); }
$read = function (string $path) use ($zip) {
    $s = $zip->getFromName($path);
    return $s === false ? null : $s;
};
$xml = function (string $path) use ($read) {
    $s = $read($path);
    return $s === null ? null : simplexml_load_string($s);
};
$outDir = __DIR__ . '/../includes/pds_form';
@mkdir("$outDir/sheets", 0775, true);

const MDW = 7;                 // max digit width (px) of the default font, Arial 10
const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

// ---------------------------------------------------------------- colors
$theme = [];
if ($t = $read('xl/theme/theme1.xml')) {
    $tx = simplexml_load_string($t);
    $tx->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
    foreach (['dk1', 'lt1', 'dk2', 'lt2', 'accent1', 'accent2', 'accent3', 'accent4', 'accent5', 'accent6', 'hlink', 'folHlink'] as $k) {
        $n = $tx->xpath("//a:clrScheme/a:$k")[0] ?? null;
        $v = 'FFFFFF';
        if ($n) {
            $n->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            if ($c = $n->xpath('a:srgbClr')) { $v = (string)$c[0]['val']; }
            elseif ($c = $n->xpath('a:sysClr')) { $v = (string)($c[0]['lastClr'] ?: '000000'); }
        }
        $theme[] = strtoupper($v);
    }
    // Excel's theme index order swaps the first two pairs
    [$theme[0], $theme[1], $theme[2], $theme[3]] = [$theme[1], $theme[0], $theme[3], $theme[2]];
}
$palette = ['000000','FFFFFF','FF0000','00FF00','0000FF','FFFF00','FF00FF','00FFFF','000000','FFFFFF','FF0000','00FF00','0000FF','FFFF00','FF00FF','00FFFF',
            '800000','008000','000080','808000','800080','008080','C0C0C0','808080','9999FF','993366','FFFFCC','CCFFFF','660066','FF8080','0066CC','CCCCFF',
            '000080','FF00FF','FFFF00','00FFFF','800080','800000','008080','0000FF','00CCFF','CCFFFF','CCFFCC','FFFF99','99CCFF','FF99CC','CC99FF','FFCC99',
            '3366FF','33CCCC','99CC00','FFCC00','FF9900','FF6600','666699','969696','003366','339966','003300','333300','993300','993366','333399','333333'];
$styles = $xml('xl/styles.xml');
if (isset($styles->colors->indexedColors)) {
    $palette = [];
    foreach ($styles->colors->indexedColors->rgbColor as $c) { $palette[] = substr(strtoupper((string)$c['rgb']), -6); }
}
function tint(string $hex, float $tint): string {
    [$r, $g, $b] = array_map(fn($x) => hexdec($x) / 255, str_split($hex, 2));
    $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2; $d = $max - $min;
    $h = $s = 0;
    if ($d > 0) {
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max === $r) { $h = fmod(($g - $b) / $d + 6, 6); } elseif ($max === $g) { $h = ($b - $r) / $d + 2; } else { $h = ($r - $g) / $d + 4; }
        $h /= 6;
    }
    $l = $tint < 0 ? $l * (1 + $tint) : $l * (1 - $tint) + $tint;
    $f = function ($t) use ($s, $l) {
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s; $p = 2 * $l - $q;
        if ($t < 0) $t += 1; if ($t > 1) $t -= 1;
        if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1 / 2) return $q;
        if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
        return $p;
    };
    $rgb = $s == 0 ? [$l, $l, $l] : [$f($h + 1 / 3), $f($h), $f($h - 1 / 3)];
    return strtoupper(implode('', array_map(fn($v) => str_pad(dechex((int)round($v * 255)), 2, '0', STR_PAD_LEFT), $rgb)));
}
$color = function ($el) use ($theme, $palette): ?string {
    if ($el === null || !$el->attributes()) { return null; }
    if (isset($el['rgb'])) { $hex = substr(strtoupper((string)$el['rgb']), -6); }
    elseif (isset($el['theme'])) { $hex = $theme[(int)$el['theme']] ?? '000000'; }
    elseif (isset($el['indexed'])) {
        $i = (int)$el['indexed'];
        if ($i === 64) { return null; } // system foreground (auto)
        if ($i === 65) { return '#FFFFFF'; }
        $hex = $palette[$i] ?? '000000';
    } else { return null; }
    if (isset($el['tint']) && (float)$el['tint'] != 0) { $hex = tint($hex, (float)$el['tint']); }
    return '#' . $hex;
};

// ---------------------------------------------------------------- styles
$numFmts = [];
foreach ($styles->numFmts->numFmt ?? [] as $n) { $numFmts[(int)$n['numFmtId']] = (string)$n['formatCode']; }

$fontCss = function ($f) use ($color): array {
    $css = [];
    $name = (string)($f->name['val'] ?? $f->rFont['val'] ?? 'Arial');
    // "Calibri (Body)" is a theme-font placeholder name; Excel prints it with Arial
    if (str_contains($name, '(Body)')) { $name = 'Arial'; }
    $fallback = stripos($name, 'narrow') !== false ? "'Arial Narrow', 'Liberation Sans Narrow', 'Nimbus Sans Narrow', Arial, sans-serif"
              : (stripos($name, 'black') !== false ? "'Arial Black', 'Arial', sans-serif" : "'$name', Arial, sans-serif");
    $css['font-family'] = "'$name', " . $fallback;
    $css['font-size'] = ((float)($f->sz['val'] ?? 10)) . 'pt';
    $css['font-weight'] = isset($f->b) && (string)($f->b['val'] ?? '1') !== '0' ? 'bold' : 'normal';
    $css['font-style'] = isset($f->i) && (string)($f->i['val'] ?? '1') !== '0' ? 'italic' : 'normal';
    $deco = [];
    if (isset($f->u) && (string)($f->u['val'] ?? 'single') !== 'none') { $deco[] = 'underline'; }
    if (isset($f->strike) && (string)($f->strike['val'] ?? '1') !== '0') { $deco[] = 'line-through'; }
    $css['text-decoration'] = $deco ? implode(' ', $deco) : 'none';
    $css['color'] = $color($f->color ?? null) ?? '#000000';
    return $css;
};
$fonts = [];
foreach ($styles->fonts->font as $f) { $fonts[] = $fontCss($f); }

$fills = [];
foreach ($styles->fills->fill as $f) {
    $p = $f->patternFill;
    $type = (string)($p['patternType'] ?? 'none');
    $fills[] = $type === 'none' ? null : ($type === 'solid' ? ($color($p->fgColor ?? null) ?? ($color($p->bgColor ?? null))) : ($color($p->fgColor ?? null) ?? '#D9D9D9'));
}
$borderCss = function ($side) use ($color): ?string {
    if ($side === null) { return null; }
    $style = (string)($side['style'] ?? '');
    if ($style === '') { return null; }
    $map = ['thin' => '1px solid', 'hair' => '1px solid', 'medium' => '2px solid', 'thick' => '3px solid', 'dashed' => '1px dashed',
            'dotted' => '1px dotted', 'double' => '3px double', 'mediumDashed' => '2px dashed', 'dashDot' => '1px dashed',
            'mediumDashDot' => '2px dashed', 'dashDotDot' => '1px dotted', 'mediumDashDotDot' => '2px dotted', 'slantDashDot' => '2px dashed'];
    // Widths are divided by the page zoom (--z) so they print at their true 1/2/3px: Chrome
    // snaps zoomed border widths down to whole pixels, which would turn medium into thin.
    [$w, $st] = explode(' ', $map[$style] ?? '1px solid');
    return 'calc(' . ((int)$w + 0.05) . 'px / var(--z, 1)) ' . $st . ' ' . ($color($side->color ?? null) ?? '#000000');
};
$borders = [];
foreach ($styles->borders->border as $b) {
    $borders[] = ['top' => $borderCss($b->top ?? null), 'right' => $borderCss($b->right ?? null),
                  'bottom' => $borderCss($b->bottom ?? null), 'left' => $borderCss($b->left ?? null)];
}
$xfs = [];
foreach ($styles->cellXfs->xf as $x) {
    $a = $x->alignment;
    $xfs[] = [
        'font'   => (int)$x['fontId'],
        'fill'   => $fills[(int)$x['fillId']] ?? null,
        'border' => $borders[(int)$x['borderId']] ?? [],
        'numFmt' => (int)$x['numFmtId'],
        'h'      => (string)($a['horizontal'] ?? 'general'),
        'v'      => (string)($a['vertical'] ?? 'bottom'),
        'wrap'   => (string)($a['wrapText'] ?? '0') === '1',
        'indent' => (int)($a['indent'] ?? 0),
        'rot'    => (int)($a['textRotation'] ?? 0),
        'shrink' => (string)($a['shrinkToFit'] ?? '0') === '1',
    ];
}

// ---------------------------------------------------------------- shared strings (with rich-text runs)
$shared = [];
if ($ss = $xml('xl/sharedStrings.xml')) {
    foreach ($ss->si as $si) {
        if (isset($si->t)) { $shared[] = [['t' => (string)$si->t, 'font' => null]]; continue; }
        $runs = [];
        foreach ($si->r as $r) { $runs[] = ['t' => (string)$r->t, 'font' => isset($r->rPr) ? $fontCss($r->rPr) : null]; }
        $shared[] = $runs;
    }
}

// ---------------------------------------------------------------- helpers
function col2n(string $c): int { $n = 0; foreach (str_split($c) as $ch) { $n = $n * 26 + ord($ch) - 64; } return $n; }
function n2col(int $n): string { $s = ''; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); } return $s; }
function parseRef(string $r): array { preg_match('/^\$?([A-Z]+)\$?(\d+)$/', $r, $m); return [col2n($m[1]), (int)$m[2]]; }
function parseRange(string $r): array { $p = explode(':', str_replace('$', '', $r)); [$c1, $r1] = parseRef($p[0]); [$c2, $r2] = parseRef($p[1] ?? $p[0]); return [$c1, $r1, $c2, $r2]; }
function cssStr(array $css): string { $o = []; foreach ($css as $k => $v) { if ($v !== null) $o[] = "$k:$v"; } return implode(';', $o); }

// ---------------------------------------------------------------- workbook / sheets
$wb = $xml('xl/workbook.xml');
$rels = $xml('xl/_rels/workbook.xml.rels');
$relTarget = [];
foreach ($rels->Relationship as $r) { $relTarget[(string)$r['Id']] = 'xl/' . ltrim((string)$r['Target'], '/'); }
$printAreas = [];
foreach ($wb->definedNames->definedName ?? [] as $dn) {
    if ((string)$dn['name'] === '_xlnm.Print_Area') {
        $printAreas[(int)$dn['localSheetId']] = preg_replace("/^.*!/", '', (string)$dn);
    }
}

$css = [];         // class => css (dedup)
$cssIndex = [];
$cls = function (string $prefix, string $rule) use (&$css, &$cssIndex): string {
    if (!isset($cssIndex[$rule])) { $cssIndex[$rule] = $prefix . count($cssIndex); $css[$cssIndex[$rule]] = $rule; }
    return $cssIndex[$rule];
};

$meta = [];
$sheetIdx = -1;
foreach ($wb->sheets->sheet as $sh) {
    $sheetIdx++;
    $name = (string)$sh['name'];
    $key = preg_match('/^(C\d+)/', $name, $m) ? $m[1] : 'S' . $sheetIdx;
    $path = $relTarget[(string)$sh->attributes(NS_R)['id']];
    $ws = simplexml_load_string($read($path));
    $sheetRels = [];
    if ($rx = $xml(dirname($path) . '/_rels/' . basename($path) . '.rels')) {
        foreach ($rx->Relationship as $r) {
            $sheetRels[(string)$r['Id']] = (string)$r['TargetMode'] === 'External' ? null
                : 'xl/' . preg_replace('#^\.\./#', '', (string)$r['Target']);
        }
    }

    // Print area (fall back to the used range)
    $area = $printAreas[$sheetIdx] ?? (string)$ws->dimension['ref'];
    [$c1, $r1, $c2, $r2] = parseRange($area);

    // Column widths (px) for the whole sheet, 1-based; hidden => 0
    $defW = (float)($ws->sheetFormatPr['defaultColWidth'] ?? 0);
    if (!$defW) { $base = (int)($ws->sheetFormatPr['baseColWidth'] ?? 8); $defW = $base + 5 / MDW; }
    $colPx = []; $colStyle = [];
    $maxCol = max($c2, 40);
    for ($c = 1; $c <= $maxCol; $c++) { $colPx[$c] = (int)floor(((256 * $defW + floor(128 / MDW)) / 256) * MDW); }
    foreach ($ws->cols->col ?? [] as $col) {
        for ($c = (int)$col['min']; $c <= min((int)$col['max'], $maxCol); $c++) {
            $w = (float)$col['width'];
            $colPx[$c] = (string)($col['hidden'] ?? '0') === '1' ? 0 : (int)floor(((256 * $w + floor(128 / MDW)) / 256) * MDW);
            if (isset($col['style'])) { $colStyle[$c] = (int)$col['style']; }
        }
    }
    // Rows
    $defH = (float)($ws->sheetFormatPr['defaultRowHeight'] ?? 12.75);
    $rowPx = []; $rowStyle = []; $cells = [];
    foreach ($ws->sheetData->row as $row) {
        $r = (int)$row['r'];
        $rowPx[$r] = (string)($row['hidden'] ?? '0') === '1' ? 0 : (int)round(((float)($row['ht'] ?? $defH)) * 4 / 3);
        if ((string)($row['customFormat'] ?? '0') === '1') { $rowStyle[$r] = (int)$row['s']; }
        foreach ($row->c as $c) {
            [$cc, $rr] = parseRef((string)$c['r']);
            $runs = null;
            $t = (string)$c['t'];
            if ($t === 's') { $runs = $shared[(int)$c->v]; }
            elseif ($t === 'inlineStr') { $runs = [['t' => (string)$c->is->t, 'font' => null]]; }
            elseif ($t === 'str' || isset($c->v)) { $runs = isset($c->v) ? [['t' => (string)$c->v, 'font' => null, 'num' => $t !== 'str']] : null; }
            $cells[$rr][$cc] = ['s' => isset($c['s']) ? (int)$c['s'] : null, 'runs' => $runs];
        }
    }
    $rowH = fn($r) => $rowPx[$r] ?? (int)round($defH * 4 / 3);
    $styleOf = function ($r, $c) use ($cells, $rowStyle, $colStyle) {
        if (isset($cells[$r][$c]['s'])) { return $cells[$r][$c]['s']; }
        return $rowStyle[$r] ?? ($colStyle[$c] ?? 0);
    };

    // Merges
    $mergeAt = []; $covered = [];
    foreach ($ws->mergeCells->mergeCell ?? [] as $mc) {
        [$mc1, $mr1, $mc2, $mr2] = parseRange((string)$mc['ref']);
        $mergeAt[$mr1][$mc1] = [$mc2, $mr2];
        for ($r = $mr1; $r <= $mr2; $r++) { for ($c = $mc1; $c <= $mc2; $c++) { if ($r !== $mr1 || $c !== $mc1) { $covered[$r][$c] = true; } } }
    }

    // Checkbox link cells: their TRUE/FALSE value is state, not printed text
    $vmlBoxes = [];
    foreach ($ws->legacyDrawing ?? [] as $ld) {
        $target = $sheetRels[(string)$ld->attributes(NS_R)['id']] ?? null;
        if (!$target || !($vml = $read($target))) { continue; }
        preg_match_all('/<v:shape\b(.*?)<\/v:shape>/s', $vml, $shapes);
        foreach ($shapes[0] as $s) {
            if (!preg_match('/ObjectType="(\w+)"/', $s, $ot)) { continue; }
            preg_match('/<x:Anchor>\s*([^<]+)</', $s, $a);
            preg_match('/<x:FmlaLink>\$?([A-Z]+)\$?(\d+)</', $s, $l);
            preg_match('/<font[^>]*face="([^"]+)"[^>]*size="(\d+)"[^>]*>(.*?)<\/font>/s', $s, $f);
            $hidden = preg_match('/visibility:\s*hidden/', $s);
            if ($hidden) { continue; }
            $vmlBoxes[] = ['type' => $ot[1], 'anchor' => array_map('intval', explode(',', preg_replace('/\s+/', '', $a[1] ?? ''))),
                           'link' => isset($l[1]) ? $l[1] . $l[2] : null,
                           'label' => isset($f[3]) ? trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/', ' ', $f[3])))) : '',
                           'font' => isset($f[1]) ? [$f[1], (int)$f[2] / 20] : ['Tahoma', 8]];
        }
    }
    $linkCells = array_filter(array_column($vmlBoxes, 'link'));

    // Pixel geometry (sheet coordinates)
    $colX = [1 => 0]; for ($c = 1; $c <= $maxCol; $c++) { $colX[$c + 1] = $colX[$c] + $colPx[$c]; }
    $maxRow = max($r2, max(array_keys($rowPx) ?: [1]), 300);
    $rowY = [1 => 0]; for ($r = 1; $r <= $maxRow; $r++) { $rowY[$r + 1] = $rowY[$r] + $rowH($r); }
    $x0 = $colX[$c1]; $y0 = $rowY[$r1];
    $width = $colX[$c2 + 1] - $x0; $height = $rowY[$r2 + 1] - $y0;

    // ---------------------------------------------------------- table
    $html = '<table class="xl" style="width:' . $width . 'px"><colgroup>';
    for ($c = $c1; $c <= $c2; $c++) { if ($colPx[$c] > 0) { $html .= '<col style="width:' . $colPx[$c] . 'px">'; } }
    $html .= '</colgroup>';
    $placeholders = [];
    for ($r = $r1; $r <= $r2; $r++) {
        if ($rowH($r) === 0) { continue; }
        $html .= '<tr style="height:' . $rowH($r) . 'px">';
        for ($c = $c1; $c <= $c2; $c++) {
            if ($colPx[$c] === 0 || isset($covered[$r][$c])) { continue; }
            [$ec, $er] = $mergeAt[$r][$c] ?? [$c, $r];
            $ec = min($ec, $c2); $er = min($er, $r2);
            $cs = 0; for ($i = $c; $i <= $ec; $i++) { if ($colPx[$i] > 0) $cs++; }
            $rs = 0; $h = 0; for ($i = $r; $i <= $er; $i++) { if ($rowH($i) > 0) { $rs++; $h += $rowH($i); } }
            $w = $colX[$ec + 1] - $colX[$c];
            if ($cs === 0 || $rs === 0) { continue; }

            $xf = $xfs[$styleOf($r, $c)] ?? $xfs[0];
            // Borders of a merged range come from its edge cells
            $edge = function ($side) use ($r, $c, $er, $ec, $styleOf, $xfs) {
                $cellsOnEdge = match ($side) {
                    'top'    => array_map(fn($i) => [$r, $i], range($c, $ec)),
                    'bottom' => array_map(fn($i) => [$er, $i], range($c, $ec)),
                    'left'   => array_map(fn($i) => [$i, $c], range($r, $er)),
                    'right'  => array_map(fn($i) => [$i, $ec], range($r, $er)),
                };
                // Excel draws each cell's own segment; use the heaviest one along the edge
                $best = null; $bestW = 0;
                foreach ($cellsOnEdge as [$rr, $cc]) {
                    $b = $xfs[$styleOf($rr, $cc)]['border'][$side] ?? null;
                    if ($b && ($w = (float)substr($b, 5)) > $bestW) { $best = $b; $bestW = $w; }
                }
                return $best;
            };
            $tdCss = [];
            foreach (['top', 'right', 'bottom', 'left'] as $side) { if ($b = $edge($side)) { $tdCss["border-$side"] = $b; } }
            if ($xf['fill']) { $tdCss['background'] = $xf['fill']; }

            $runs = $cells[$r][$c]['runs'] ?? null;
            $ref = n2col($c) . $r;
            $isNum = $runs && !empty($runs[0]['num']);
            $fmt = $numFmts[$xf['numFmt']] ?? '';
            if ($isNum && (in_array($ref, $linkCells, true) || $fmt === ';;;')) { $runs = null; }
            $text = $runs ? implode('', array_column($runs, 't')) : '';

            $h_align = $xf['h'] === 'general' ? ($isNum ? 'right' : 'left') : $xf['h'];
            $justify = ['left' => 'flex-start', 'center' => 'center', 'centerContinuous' => 'center', 'right' => 'flex-end',
                        'fill' => 'flex-start', 'justify' => 'flex-start', 'distributed' => 'center'][$h_align] ?? 'flex-start';
            $align = ['top' => 'flex-start', 'center' => 'center', 'bottom' => 'flex-end', 'justify' => 'flex-start', 'distributed' => 'center'][$xf['v']] ?? 'flex-end';
            $txtAlign = ['justify' => 'justify', 'distributed' => 'justify', 'center' => 'center', 'centerContinuous' => 'center', 'right' => 'right'][$h_align] ?? 'left';

            $boxCss = $fonts[$xf['font']] + [
                'justify-content' => $justify, 'align-items' => $align,
                'text-align' => $txtAlign, 'white-space' => $xf['wrap'] ? 'pre-wrap' : 'pre',
                'overflow' => $xf['wrap'] ? 'hidden' : 'visible',
                'padding-left' => $xf['indent'] ? ($xf['indent'] * 9 + 2) . 'px' : null,
            ];
            $tdClass = $tdCss ? $cls('b', cssStr($tdCss)) : '';
            $boxClass = $cls('f', cssStr($boxCss));

            if (trim($text) === '') {
                $inner = '{{' . $ref . '}}';
                $placeholders[$ref] = ['w' => $w, 'h' => $h, 'wrap' => $xf['wrap']];
            } else {
                $inner = '';
                foreach ($runs as $run) {
                    $t = htmlspecialchars($run['t'], ENT_QUOTES, 'UTF-8');
                    $inner .= $run['font'] ? '<span class="' . $cls('r', cssStr($run['font'])) . '">' . $t . '</span>' : $t;
                }
            }
            $spanAttr = ($cs > 1 ? ' colspan="' . $cs . '"' : '') . ($rs > 1 ? ' rowspan="' . $rs . '"' : '');
            $html .= '<td' . $spanAttr . ($tdClass ? ' class="' . $tdClass . '"' : '') . '><div class="c ' . $boxClass
                   . ($xf['wrap'] ? ' w' : '') . '" data-ref="' . $ref . '"><span>' . $inner . '</span></div></td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';

    // ---------------------------------------------------------- form controls (checkboxes, dropdown)
    // Excel clamps an anchor offset that is larger than its column / row
    $clampX = fn($c, $off) => min($off, $colPx[$c] ?? $off);
    $clampY = fn($r, $off) => min($off, $rowH($r));
    $overlay = '';
    foreach ($vmlBoxes as $b) {
        $a = $b['anchor'];
        if (count($a) !== 8) { continue; }
        $x = $colX[$a[0] + 1] + $clampX($a[0] + 1, $a[1]) - $x0; $y = $rowY[$a[2] + 1] + $clampY($a[2] + 1, $a[3]) - $y0;
        $w = $colX[$a[4] + 1] + $clampX($a[4] + 1, $a[5]) - $x0 - $x; $h = $rowY[$a[6] + 1] + $clampY($a[6] + 1, $a[7]) - $y0 - $y;
        if ($x < 0 || $y < 0 || $x > $width || $y > $height) { continue; }
        $pos = "left:{$x}px;top:{$y}px;width:{$w}px;height:{$h}px";
        if ($b['type'] === 'Checkbox') {
            $font = "font-family:'{$b['font'][0]}',sans-serif;font-size:{$b['font'][1]}pt";
            $overlay .= '<div class="xl-chk" style="' . $pos . ';' . $font . '"><i class="{{chk:' . $b['link'] . '}}"></i>'
                      . '<span>' . htmlspecialchars($b['label']) . '</span></div>';
        } elseif ($b['type'] === 'Drop') {
            $overlay .= '<div class="xl-drop" style="' . $pos . '"><b></b></div>';
        }
    }

    // ---------------------------------------------------------- drawings (photo box, lines)
    foreach ($ws->drawing ?? [] as $dr) {
        $target = $sheetRels[(string)$dr->attributes(NS_R)['id']] ?? null;
        if (!$target || !($dx = $read($target))) { continue; }
        preg_match_all('/<xdr:(twoCellAnchor|oneCellAnchor)\b.*?<\/xdr:\1>/s', $dx, $anchors);
        foreach ($anchors[0] as $an) {
            if (preg_match('/<xdr:cNvPr [^>]*hidden="1"/', $an)) { continue; }
            if (!preg_match('/<xdr:from><xdr:col>(\d+)<\/xdr:col><xdr:colOff>(-?\d+)<\/xdr:colOff><xdr:row>(\d+)<\/xdr:row><xdr:rowOff>(-?\d+)/', $an, $f)) { continue; }
            $x = $colX[$f[1] + 1] + $clampX($f[1] + 1, $f[2] / 9525) - $x0; $y = $rowY[$f[3] + 1] + $clampY($f[3] + 1, $f[4] / 9525) - $y0;
            // Size: the shape's own extent is authoritative (the "to" anchor can overshoot a column)
            if (preg_match('/<a:xfrm[^>]*>\s*<a:off [^>]*\/>\s*<a:ext cx="(\d+)" cy="(\d+)"/', $an, $e) || preg_match('/<xdr:ext cx="(\d+)" cy="(\d+)"/', $an, $e)) {
                $w = $e[1] / 9525; $h = $e[2] / 9525;
            } elseif (preg_match('/<xdr:to><xdr:col>(\d+)<\/xdr:col><xdr:colOff>(-?\d+)<\/xdr:colOff><xdr:row>(\d+)<\/xdr:row><xdr:rowOff>(-?\d+)/', $an, $t)) {
                $w = $colX[$t[1] + 1] + $t[2] / 9525 - $x0 - $x; $h = $rowY[$t[3] + 1] + $t[4] / 9525 - $y0 - $y;
            } else { continue; }
            if ($x < 0 || $y < 0 || $x >= $width || $y >= $height || $w < 2) { continue; }
            // Only shapes that actually draw something: a line, or a filled / outlined box
            $spPr = preg_match('/<xdr:spPr\b[^>]*>(.*?)<\/xdr:spPr>/s', $an, $sp) ? $sp[1] : '';
            $hasLine = str_contains($spPr, '<a:ln') && !preg_match('/<a:ln[^>]*>\s*<a:noFill\/>/', $spPr);
            $lineW = preg_match('/<a:ln w="(\d+)"/', $spPr, $lw) ? max(1, round($lw[1] / 9525)) : 1;
            $hasFill = (bool)preg_match('/<a:solidFill>/', explode('<a:ln', $spPr)[0]);
            $isLine = str_contains($an, '<xdr:cxnSp') || preg_match('/prst="line"/', $an);
            // Text: one line per paragraph (empty paragraphs keep their height)
            $paras = []; $hasText = false;
            preg_match_all('/<a:p>(.*?)<\/a:p>/s', $an, $ps);
            foreach ($ps[1] as $p) {
                preg_match_all('/<a:r>.*?<a:rPr([^>]*)>.*?<a:t>([^<]*)<\/a:t>.*?<\/a:r>/s', $p, $runs, PREG_SET_ORDER);
                $algn = preg_match('/algn="(\w+)"/', $p, $al) ? ['ctr' => 'center', 'r' => 'right', 'just' => 'justify'][$al[1]] ?? 'left' : 'left';
                $line = '';
                foreach ($runs as $r) {
                    $rsz = preg_match('/sz="(\d+)"/', $r[1], $m1) ? $m1[1] / 100 : 10;
                    $line .= '<span style="font-size:' . $rsz . 'pt' . (preg_match('/ b="1"/', $r[1]) ? ';font-weight:bold' : '') . '">' . htmlspecialchars($r[2]) . '</span>';
                    if (trim($r[2]) !== '') { $hasText = true; }
                }
                $esz = preg_match('/<a:endParaRPr[^>]*sz="(\d+)"/', $p, $m2) ? $m2[1] / 100 : 10;
                $paras[] = '<p style="text-align:' . $algn . ';font-size:' . $esz . 'pt">' . ($line === '' ? '&nbsp;' : $line) . '</p>';
            }
            $x = round($x, 1); $y = round($y, 1); $w = round($w, 1); $h = round($h, 1);
            if ($isLine) {
                $overlay .= '<div class="xl-line" style="left:' . $x . 'px;top:' . $y . 'px;width:' . $w . 'px"></div>';
            } elseif ($hasLine || $hasFill || $hasText) {
                $ins = fn($k, $d) => preg_match('/' . $k . '="(\d+)"/', $an, $mm) ? round($mm[1] / 9525, 1) : $d;
                $pad = $ins('tIns', 4) . 'px ' . $ins('rIns', 10) . 'px ' . $ins('bIns', 4) . 'px ' . $ins('lIns', 10) . 'px';
                $anchor = preg_match('/<a:bodyPr[^>]*anchor="(\w+)"/', $an, $am) ? ['ctr' => 'center', 'b' => 'flex-end'][$am[1]] ?? 'flex-start' : 'flex-start';
                $overlay .= '<div class="xl-box" style="left:' . $x . 'px;top:' . $y . 'px;width:' . $w . 'px;height:' . $h . 'px;padding:' . $pad
                          . ';align-items:' . $anchor . ($hasLine ? ';border-width:calc(' . ($lineW + 0.05) . 'px / var(--z, 1))' : ';border:0')
                          . ($hasFill ? '' : ';background:transparent') . '"><div>' . ($hasText ? implode('', $paras) : '') . '</div></div>';
            }
        }
    }

    file_put_contents("$outDir/sheets/$key.html",
        "<!-- Generated by tools/build_pds_template.php from the official CSC workbook, sheet \"$name\" ($area). Do not edit by hand. -->\n"
        . '<div class="xl-sheet" style="width:' . $width . 'px;height:' . $height . 'px">' . $html . $overlay . "</div>\n");

    // ---------------------------------------------------------- page setup
    $pm = $ws->pageMargins; $ps = $ws->pageSetup;
    $fit = (string)($ws->sheetPr->pageSetUpPr['fitToPage'] ?? '0') === '1';
    $meta[$key] = [
        'name' => $name, 'area' => $area, 'width' => $width, 'height' => $height,
        'orientation' => (string)($ps['orientation'] ?? 'portrait'),
        'margins' => ['left' => (float)$pm['left'], 'right' => (float)$pm['right'], 'top' => (float)$pm['top'], 'bottom' => (float)$pm['bottom']],
        'fit' => $fit, 'fitW' => (int)($ps['fitToWidth'] ?? 1) ?: ((string)($ps['fitToWidth'] ?? '') === '0' ? 0 : 1),
        'fitH' => isset($ps['fitToHeight']) ? (int)$ps['fitToHeight'] : 1, 'zoom' => (int)($ps['scale'] ?? 100),
        'hCenter' => (string)($ws->printOptions['horizontalCentered'] ?? '0') === '1',
        'vCenter' => (string)($ws->printOptions['verticalCentered'] ?? '0') === '1',
        'cells' => $placeholders,
    ];
    echo sprintf("%-4s %-30s %-12s %4dx%-4dpx  %d empty cells, %d controls\n", $key, $name, $area, $width, $height, count($placeholders), count($vmlBoxes));
}

// ---------------------------------------------------------------- css
$out = "/* Generated by tools/build_pds_template.php from the official CSC workbook. Do not edit by hand. */\n";
foreach ($css as $class => $rule) { $out .= ".xl .$class{" . $rule . "}\n"; }
file_put_contents("$outDir/pds_form.css", $out);
file_put_contents("$outDir/sheets.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Wrote " . count($meta) . " sheets, " . count($css) . " style classes to includes/pds_form/\n";
