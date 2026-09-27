<?php
/**
 * Maps a faculty member's digital PDS onto the cells of the official
 * CS Form No. 212 (Revised 2026) sheets rendered by includes/pds_form.php.
 *
 * Cell addresses are the workbook's own (e.g. C1!D10 = SURNAME value box).
 * Tables fill the rows printed on C1-C4 first; any further entries go on
 * the form's own continuation sheets (C5 L&D, C6 work experience, C7
 * children, C8 education, C9 eligibility, C10 voluntary work, C11 other
 * information), repeated as many times as needed -- rows are never
 * shrunk to fit more entries.
 *
 * N/A: the form says "Indicate N/A if not applicable", so an empty
 * personal / family field, an empty education level, or a table with no
 * entries at all prints N/A. Signature, date, references, government ID
 * and "If YES" details stay blank when empty.
 */
require_once __DIR__ . '/pds_form.php';

/** dd/mm/yyyy, the format the form asks for; anything else is printed as typed. */
function pds_fill_date($v): string {
    $v = trim((string)($v ?? ''));
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) { return "$m[3]/$m[2]/$m[1]"; }
    return $v;
}

function pds_fill_hours($v): string {
    if ($v === null || $v === '') { return ''; }
    return is_numeric($v) ? rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.') : (string)$v;
}

/**
 * Lay rows into a table: the first $capacity rows on the main sheet, the
 * rest on continuation sheets.
 * @param array  $rows      list of [colLetter => text] (already formatted)
 * @param int    $firstRow  first data row on the main sheet
 * @param int    $capacity  rows available on the main sheet
 * @param array  $cont      [sheetKey, firstRow, capacity, colMap] for continuation, colMap maps main col => cont col
 * @return array [mainValues, [ [contValues], ... ]]
 */
function pds_fill_table(array $rows, int $firstRow, int $capacity, ?array $cont): array {
    $main = [];
    foreach (array_slice($rows, 0, $capacity) as $i => $row) {
        foreach ($row as $col => $text) { if ($text !== '') { $main[$col . ($firstRow + $i)] = $text; } }
    }
    $sheets = [];
    if ($cont) {
        [, $cFirst, $cCap, $colMap] = $cont;
        foreach (array_chunk(array_slice($rows, $capacity), $cCap) as $chunk) {
            $vals = [];
            foreach ($chunk as $i => $row) {
                foreach ($row as $col => $text) {
                    if ($text !== '' && isset($colMap[$col])) { $vals[$colMap[$col] . ($cFirst + $i)] = $text; }
                }
            }
            $sheets[] = $vals;
        }
    }
    return [$main, $sheets];
}

/**
 * Build every printed page for a PDS.
 * @return array list of ['key' => 'C1', 'values' => [...], 'checks' => [...]]
 */
function pds_form_build(array $d, array $ld): array {
    $s = fn($k) => trim((string)($d[$k] ?? ''));
    $na = fn($k) => $s($k) !== '' ? $s($k) : 'N/A';
    $rows = fn($k) => array_values(array_filter((array)($d[$k] ?? []), fn($r) => is_array($r) && implode('', array_map('strval', $r)) !== ''));
    $pages = array_fill_keys(['C1', 'C2', 'C3', 'C4'], null);
    $cont = [];   // sheetKey => list of value arrays

    // ------------------------------------------------------------ C1
    $v = []; $c = [];
    // Fields that apply to everyone stay blank when empty (N/A would be wrong there)
    foreach (['D10' => 'surname', 'D11' => 'first_name', 'D15' => 'place_of_birth', 'D22' => 'height', 'D24' => 'weight', 'D25' => 'blood_type'] as $cell => $key) {
        $v[$cell] = $s($key);
    }
    $v['D13'] = pds_fill_date($s('date_of_birth'));
    foreach (['N11' => 'name_extension', 'D12' => 'middle_name', 'D27' => 'umid_id', 'D29' => 'pagibig_id',
              'D31' => 'philhealth_no', 'D32' => 'philsys_pcn', 'D33' => 'tin_no', 'D34' => 'agency_employee_no',
              'I32' => 'telephone_no', 'I33' => 'mobile_no', 'I34' => 'email'] as $cell => $key) {
        $v[$cell] = $na($key);
    }

    $sex = $s('sex');
    if ($sex === 'Male') { $c['D16'] = true; } elseif ($sex === 'Female') { $c['E16'] = true; }

    $civil = $s('civil_status');
    $civilCell = ['Single' => 'D17', 'Married' => 'E17', 'Widowed' => 'D18', 'Separated' => 'E19', 'Other/s' => 'D20', 'Other' => 'D20'][$civil] ?? null;
    if ($civilCell) { $c[$civilCell] = true; }
    if ($civilCell === 'D20' && $s('civil_status_other') !== '') { $v['E20'] = $s('civil_status_other'); }

    // 16. Citizenship
    $cit = $s('citizenship');
    if ($cit === 'Filipino') { $c['J13'] = true; }
    if ($cit === 'Dual Citizenship') {
        $c['K13'] = true;
        $by = $s('dual_citizenship_by');
        if ($by === 'by birth') { $c['L14'] = true; } elseif ($by === 'by naturalization') { $c['M14'] = true; }
        $country = $s('dual_citizenship_country') !== '' ? $s('dual_citizenship_country') : $s('dual_citizenship');
        $v['J16'] = ['text' => $country !== '' ? $country : 'N/A', 'spill' => true];
    }

    // 17-18. Addresses (older records kept one line per address: print it in the first box)
    foreach (['res' => ['I17', 'L17', 'I19', 'L19', 'I22', 'L22', 'I24', 'residential_zip', 'residential_address'],
              'perm' => ['I25', 'L25', 'I27', 'L27', 'I29', 'L29', 'I31', 'permanent_zip', 'permanent_address']] as $p => $cells) {
        $parts = ['house', 'street', 'subdivision', 'barangay', 'city', 'province'];
        $any = false; foreach ($parts as $part) { if ($s("{$p}_$part") !== '') { $any = true; } }
        foreach ($parts as $i => $part) {
            $v[$cells[$i]] = $any ? $na("{$p}_$part") : ($i === 0 && $s($cells[8]) !== '' ? $s($cells[8]) : 'N/A');
        }
        $v[$cells[6]] = $na($cells[7]);
    }

    // 22-25. Family
    foreach (['D36' => 'spouse_surname', 'D37' => 'spouse_first_name', 'H37' => 'spouse_name_extension', 'D38' => 'spouse_middle_name',
              'D39' => 'spouse_occupation', 'D40' => 'spouse_employer', 'D41' => 'spouse_business_address', 'D42' => 'spouse_telephone',
              'D44' => 'father_surname', 'D45' => 'father_first_name', 'H45' => 'father_name_extension', 'D46' => 'father_middle_name',
              'D48' => 'mother_surname', 'D49' => 'mother_first_name', 'D50' => 'mother_middle_name'] as $cell => $key) {
        $v[$cell] = $na($key);
    }
    $children = array_map(fn($r) => ['I' => trim((string)($r['name'] ?? '')), 'M' => pds_fill_date($r['date_of_birth'] ?? '')], $rows('children'));
    if (!$children) { $children = [['I' => 'N/A', 'M' => '']]; }
    [$main, $more] = pds_fill_table($children, 37, 13, ['C7', 4, 20, ['I' => 'I', 'M' => 'L']]);
    $v += $main;
    foreach ($more as $m) { $cont['C7'][] = $m; }

    // 26. Education: one fixed row per level; extra schools for a level go to C8
    $levels = ['Elementary' => 55, 'Secondary' => 56, 'Vocational / Trade Course' => 57, 'College' => 58, 'Graduate Studies' => 59];
    $extra = []; $used = [];
    foreach ($rows('education') as $r) {
        $cells = ['D' => $r['school'] ?? '', 'G' => $r['degree'] ?? '', 'J' => $r['from'] ?? '', 'K' => $r['to'] ?? '',
                  'L' => $r['units'] ?? '', 'M' => $r['year_graduated'] ?? '', 'N' => $r['honors'] ?? ''];
        $cells = array_map(fn($x) => trim((string)$x), $cells);
        $lvl = $r['level'] ?? '';
        if (isset($levels[$lvl]) && !isset($used[$lvl])) {
            $used[$lvl] = true;
            foreach ($cells as $col => $text) { if ($text !== '') { $v[$col . $levels[$lvl]] = $text; } }
        } else {
            $extra[] = ['A' => strtoupper($lvl)] + $cells;
        }
    }
    foreach ($levels as $lvl => $row) { if (!isset($used[$lvl])) { $v['D' . $row] = 'N/A'; } }
    foreach (array_chunk($extra, 37) as $chunk) {
        [$m] = pds_fill_table($chunk, 6, 37, null);
        $cont['C8'][] = $m;
    }
    $pages['C1'] = ['values' => $v, 'checks' => $c];

    // ------------------------------------------------------------ C2
    $v = [];
    $elig = array_map(fn($r) => ['A' => trim((string)($r['name'] ?? '')), 'F' => trim((string)($r['rating'] ?? '')),
        'G' => pds_fill_date($r['exam_date'] ?? ''), 'I' => trim((string)($r['exam_place'] ?? '')),
        'L' => trim((string)($r['license_number'] ?? '')), 'M' => pds_fill_date($r['license_valid'] ?? '')], $rows('eligibility'));
    if (!$elig) { $elig = [['A' => 'N/A']]; }
    [$main, $more] = pds_fill_table($elig, 5, 7, ['C9', 5, 24, ['A' => 'A', 'F' => 'F', 'G' => 'G', 'I' => 'I', 'L' => 'J', 'M' => 'K']]);
    $v += $main; foreach ($more as $m) { $cont['C9'][] = $m; }

    $work = array_map(fn($r) => ['A' => pds_fill_date($r['from'] ?? ''), 'C' => pds_fill_date($r['to'] ?? ''),
        'D' => trim((string)($r['position'] ?? '')), 'G' => trim((string)($r['agency'] ?? '')), 'J' => trim((string)($r['salary'] ?? '')),
        'K' => trim((string)($r['salary_grade'] ?? '')), 'L' => trim((string)($r['status'] ?? '')), 'M' => trim((string)($r['govt_service'] ?? ''))], $rows('work'));
    if (!$work) { $work = [['D' => 'N/A']]; }
    [$main, $more] = pds_fill_table($work, 18, 24, ['C6', 7, 33, ['A' => 'A', 'C' => 'C', 'D' => 'D', 'G' => 'G', 'J' => 'J', 'K' => 'K', 'L' => 'L', 'M' => 'M']]);
    $v += $main; foreach ($more as $m) { $cont['C6'][] = $m; }
    $pages['C2'] = ['values' => $v, 'checks' => []];

    // ------------------------------------------------------------ C3
    $v = [];
    $ldRows = array_map(fn($r) => ['A' => trim((string)($r['title'] ?? '')), 'E' => pds_fill_date($r['date_from'] ?? ''),
        'F' => pds_fill_date($r['date_to'] ?? ''), 'G' => pds_fill_hours($r['hours'] ?? ''), 'H' => trim((string)($r['ld_type'] ?? '')),
        'I' => trim((string)($r['conducted_by'] ?? ''))], array_values($ld));
    if (!$ldRows) { $ldRows = [['A' => 'N/A']]; }
    [$main, $more] = pds_fill_table($ldRows, 5, PDS_LD_ROWS_PAGE3, ['C5', 6, PDS_LD_ROWS_CONTINUATION, ['A' => 'A', 'E' => 'E', 'F' => 'F', 'G' => 'G', 'H' => 'H', 'I' => 'I']]);
    $v += $main; foreach ($more as $m) { $cont['C5'][] = $m; }

    $vol = array_map(fn($r) => ['A' => trim((string)($r['organization'] ?? '')), 'E' => pds_fill_date($r['from'] ?? ''),
        'F' => pds_fill_date($r['to'] ?? ''), 'G' => trim((string)($r['hours'] ?? '')), 'H' => trim((string)($r['position'] ?? ''))], $rows('voluntary'));
    if (!$vol) { $vol = [['A' => 'N/A']]; }
    [$main, $more] = pds_fill_table($vol, 27, 9, ['C10', 6, 29, ['A' => 'A', 'E' => 'E', 'F' => 'F', 'G' => 'G', 'H' => 'H']]);
    $v += $main; foreach ($more as $m) { $cont['C10'][] = $m; }

    // 31-33. Other information: three independent lists side by side
    $lists = [];
    foreach (['A' => 'skills', 'C' => 'recognitions', 'I' => 'memberships'] as $col => $key) {
        $items = array_map(fn($r) => trim((string)($r['value'] ?? '')), $rows($key));
        $lists[$col] = $items ?: ['N/A'];
    }
    $other = [];
    for ($i = 0, $n = max(array_map('count', $lists)); $i < $n; $i++) {
        $other[] = ['A' => $lists['A'][$i] ?? '', 'C' => $lists['C'][$i] ?? '', 'I' => $lists['I'][$i] ?? ''];
    }
    [$main, $more] = pds_fill_table($other, 39, 7, ['C11', 4, 31, ['A' => 'A', 'C' => 'C', 'I' => 'I']]);
    $v += $main; foreach ($more as $m) { $cont['C11'][] = $m; }
    $pages['C3'] = ['values' => $v, 'checks' => []];

    // ------------------------------------------------------------ C4
    $v = []; $c = [];
    foreach (['q34a' => 3, 'q34b' => 8, 'q35a' => 13, 'q35b' => 18, 'q36' => 23, 'q37' => 27, 'q38a' => 31, 'q38b' => 34,
              'q39' => 37, 'q40a' => 43, 'q40b' => 45, 'q40c' => 47] as $q => $row) {
        if ($s($q) === 'Yes') { $c['H' . $row] = true; } elseif ($s($q) === 'No') { $c['J' . $row] = true; }
    }
    foreach (['I6' => 'q34_details', 'I11' => 'q34b_details', 'I15' => 'q35a_details', 'L19' => 'q35b_details', 'L21' => 'q35b_status',
              'I25' => 'q36_details', 'I29' => 'q37_details', 'L32' => 'q38a_details', 'L35' => 'q38b_details', 'I39' => 'q39_details',
              'M44' => 'q40a_details', 'M46' => 'q40b_id', 'M48' => 'q40c_id',
              'D61' => 'gov_id_type', 'D62' => 'gov_id_number', 'D64' => 'gov_id_issued'] as $cell => $key) {
        if ($s($key) !== '') { $v[$cell] = $s($key); }
    }
    if ($s('q35b_date') !== '') { $v['L20'] = pds_fill_date($s('q35b_date')); }
    foreach (array_slice($rows('references'), 0, 3) as $i => $r) {
        foreach (['A' => 'name', 'G' => 'address', 'H' => 'telephone'] as $col => $key) {
            if (trim((string)($r[$key] ?? '')) !== '') { $v[$col . (52 + $i)] = trim((string)$r[$key]); }
        }
    }
    $pages['C4'] = ['values' => $v, 'checks' => $c];

    // ------------------------------------------------------------ assemble in workbook order
    $out = [];
    foreach ($pages as $key => $p) { $out[] = ['key' => $key] + $p; }
    foreach (['C5', 'C6', 'C7', 'C8', 'C9', 'C10', 'C11'] as $key) {
        foreach ($cont[$key] ?? [] as $vals) { $out[] = ['key' => $key, 'values' => $vals, 'checks' => []]; }
    }
    return $out;
}
