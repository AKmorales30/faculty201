<?php
/**
 * Import an uploaded PDS soft copy (CS Form No. 212) into the digital PDS.
 *
 * pds_import_extract_file() reads whatever the file contains -- all of it
 * or only some pages -- into an "extraction":
 *
 *   [ 'source'   => 'xlsx' | 'text' | 'ocr',
 *     'sections' => [section key => true]            sections found in the file
 *     'fields'   => [field key => ['value' => ..., 'sure' => bool]]
 *     'tables'   => [table key => [['cells' => [column => value], 'sure' => bool], ...]] ]
 *
 * Section keys are pds_schema()'s part keys, plus 'LD' for Section VI
 * (Learning and Development, table 'ld', stored in pds_learning_development).
 *
 *   .xlsx  the official CSC workbook, read cell by cell (ZipArchive +
 *          SimpleXML, no extra library) with the same cell addresses the
 *          printout uses (includes/pds_form_fill.php), checkboxes included.
 *          Exact; every value is marked sure. A workbook with a different
 *          layout (an older form) is read as text instead.
 *   .pdf   its text layer (pdftotext), or OCR of every page when it's a scan.
 *   image  OCR.
 *   Text is split into sections by their headings ("PERSONAL INFORMATION",
 *   "WORK EXPERIENCE", ...), so a file with only some pages yields only
 *   those sections. Values read by OCR are marked unsure unless they pass
 *   a strict format check (dates, ID numbers, e-mail, ...).
 *
 * Nothing is written here: faculty/pds_import.php shows the extraction next
 * to the current PDS for review, and saves only what is confirmed.
 */
require_once __DIR__ . '/pds.php';

// =====================================================================
// Sections, fields and tables
// =====================================================================

/** Importable sections: key => [label, scalar field keys, table keys]. */
function pds_import_sections(): array {
    $schema = pds_schema();
    $fields = fn(string $part) => array_keys($schema[$part]['fields'] ?? []);
    return [
        'I'    => ['label' => 'Personal Information', 'fields' => $fields('I'), 'tables' => []],
        'II'   => ['label' => 'Family Background', 'fields' => $fields('II'), 'tables' => ['children']],
        'III'  => ['label' => 'Educational Background', 'fields' => [], 'tables' => ['education']],
        'IV'   => ['label' => 'Civil Service Eligibility', 'fields' => [], 'tables' => ['eligibility']],
        'V'    => ['label' => 'Work Experience', 'fields' => [], 'tables' => ['work']],
        'LD'   => ['label' => 'Learning and Development', 'fields' => [], 'tables' => ['ld']],
        'VII'  => ['label' => 'Voluntary Work', 'fields' => [], 'tables' => ['voluntary']],
        'VIII' => ['label' => 'Other Information', 'fields' => [], 'tables' => ['skills', 'recognitions', 'memberships']],
        'Q'    => ['label' => 'Questions 34 to 40', 'fields' => $fields('Q'), 'tables' => []],
        'R'    => ['label' => 'References', 'fields' => [], 'tables' => ['references']],
        'ID'   => ['label' => 'Government Issued ID', 'fields' => $fields('ID'), 'tables' => []],
    ];
}

/** Every scalar field: key => [label, type, options]. */
function pds_import_field_defs(): array {
    $defs = [];
    foreach (pds_schema() as $part) {
        foreach ($part['fields'] ?? [] as $key => $def) { $defs[$key] = pds_field_def($def); }
    }
    return $defs;
}

/** Columns of a table: column => [label, type, options]. 'ld' is Section VI. */
function pds_import_columns(string $tkey): array {
    if ($tkey === 'ld') { return array_map('pds_field_def', pds_ld_columns()); }
    foreach (pds_schema() as $part) {
        if (isset($part['tables'][$tkey])) { return array_map('pds_field_def', $part['tables'][$tkey]['columns']); }
    }
    return [];
}

/** Display label of a table. */
function pds_import_table_label(string $tkey): string {
    if ($tkey === 'ld') { return 'Learning and Development'; }
    foreach (pds_schema() as $part) {
        if (isset($part['tables'][$tkey])) { return $part['tables'][$tkey]['label']; }
    }
    return $tkey;
}

/** pds_rules() key of a field or table column ('ld' columns are 'ld.*'). */
function pds_import_rule_key(?string $tkey, string $key): string {
    return $tkey === null ? $key : "$tkey.$key";
}

// =====================================================================
// Values: dates, years, N/A, and how sure we are
// =====================================================================

function pds_import_is_na(string $v): bool {
    return $v === '' || pds_is_na_text($v) || (bool)preg_match('/^(none|nil|-+|n\/?a\.?)$/i', trim($v));
}

/**
 * A date as Y-m-d, from an Excel serial number, Y-m-d, dd/mm/yyyy (the
 * form's format; mm/dd/yyyy when the day can't be a month), or a written
 * month ("January 5, 2020"). Returns [date or null, sure].
 */
function pds_import_date(string $v): array {
    $v = trim($v);
    if ($v === '') { return [null, true]; }
    if (preg_match('/^\d{4,5}(\.\d+)?$/', $v) && (float)$v > 1000 && (float)$v < 80000) {   // Excel date serial
        return [gmdate('Y-m-d', (int)round(((float)$v - 25569) * 86400)), true];
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? [sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), true] : [null, false];
    }
    if (preg_match('/^(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{2,4})$/', $v, $m)) {
        [$a, $b, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($y < 100) { $y += $y <= (int)date('y') + 1 ? 2000 : 1900; }
        if ($a > 12 && $b <= 12)      { [$d, $mo, $sure] = [$a, $b, true]; }   // dd/mm
        elseif ($b > 12 && $a <= 12)  { [$d, $mo, $sure] = [$b, $a, true]; }   // mm/dd
        else                          { [$d, $mo, $sure] = [$a, $b, $a === $b]; }   // the form says dd/mm/yyyy
        return checkdate($mo, $d, $y) ? [sprintf('%04d-%02d-%02d', $y, $mo, $d), $sure] : [null, false];
    }
    if (preg_match('/[a-z]{3}/i', $v) && preg_match('/\b(19|20)\d{2}\b/', $v) && ($ts = strtotime($v)) !== false) {
        return [date('Y-m-d', $ts), true];
    }
    return [null, false];
}

/**
 * Normalize one value for a field / column: drop N/A, dates to Y-m-d,
 * years to YYYY, "Present" kept where the form allows it, choices matched
 * to their options. $source 'ocr' makes free text unsure.
 * @return array{0: string, 1: bool} [value ('' = nothing), sure]
 */
function pds_import_normalize(string $rule_key, array $def, $raw, string $source): array {
    [, $type, $options] = $def;
    $v = trim(preg_replace('/\s+/u', ' ', (string)$raw));
    $v = trim($v, " \t:;|_");
    if (pds_import_is_na($v)) { return ['', true]; }
    $rule = pds_rules()[$rule_key] ?? [];
    $fmt = $rule['fmt'] ?? null;
    $sure = $source !== 'ocr';

    if (($rule['alt'] ?? null) === 'Present' && preg_match('/^(present|to\s+date|up\s+to\s+present|current)$/i', $v)) {
        return ['Present', $sure];
    }
    if ($type === 'date') {
        [$date, $date_sure] = pds_import_date($v);
        // In the official workbook a day-month like 02/03 follows the form's dd/mm/yyyy -- trusted; from text it's a guess to check
        return $date === null ? ['', false] : [$date, $source === 'xlsx' || ($date_sure && $source !== 'ocr')];
    }
    if ($fmt === 'year') {
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $v) && (float)$v > 3000) { [$d] = pds_import_date($v); $v = $d ? substr($d, 0, 4) : $v; }
        if (!preg_match('/\b((?:19|20)\d{2})\b/', $v, $m)) { return [$v, false]; }
        return [$m[1], $sure || preg_match('/^\d{4}$/', $v)];
    }
    if ($type === 'select' || $type === 'yesno') {
        $options = $type === 'yesno' ? ['Yes', 'No'] : $options;
        foreach ($options as $opt) {
            if (strcasecmp($v, $opt) === 0 || strcasecmp(rtrim($v, '.'), rtrim($opt, '.')) === 0) { return [$opt, $sure]; }
        }
        if ($options === ['Y', 'N']) {
            if (preg_match('/^y(es)?$/i', $v)) { return ['Y', $sure]; }
            if (preg_match('/^no?$/i', $v)) { return ['N', $sure]; }
        }
        if ($rule_key === 'education.level') {
            foreach ($options as $opt) { if (stripos($opt, strtok($v, ' /')) === 0) { return [$opt, false]; } }
        }
        return ['', false];
    }
    if ($type === 'number' || $fmt === 'hours') {
        $n = str_replace(',', '', $v);
        if (preg_match('/^(\d+(?:\.\d+)?)/', $n, $m)) { return [(string)(float)$m[1], $sure]; }
        return ['', false];
    }
    $v = mb_substr($v, 0, 255);
    // Read from text, a value that can't be this field (an "extension" that is a full name, a ZIP that
    // isn't 4 digits) is usually the next column's text sitting where this box was empty: leave it out
    if ($fmt && $source !== 'xlsx' && in_array($fmt, ['ext', 'blood', 'zip', 'email', 'height', 'weight'], true)
        && pds_check_value(['fmt' => $fmt], 'text', $v, []) !== null) {
        return ['', false];
    }
    if ($fmt) {
        // A strict format that checks out is believable even from OCR
        $ok = pds_check_value(['fmt' => $fmt], 'text', $v, []) === null;
        return [$v, $ok && ($sure || !in_array($fmt, ['name', 'idno'], true))];
    }
    return [$v, $sure];
}

/** An empty extraction. */
function pds_import_empty(string $source): array {
    return ['source' => $source, 'sections' => [], 'fields' => [], 'tables' => []];
}

/** Add a scalar value to an extraction (normalized; empty / N/A values are left out). */
function pds_import_put_field(array &$ex, string $section, string $key, $raw, bool $force_unsure = false): void {
    $defs = pds_import_field_defs();
    if (!isset($defs[$key])) { return; }
    [$v, $sure] = pds_import_normalize($key, $defs[$key], $raw, $ex['source']);
    if ($v === '') { return; }
    $ex['sections'][$section] = true;
    $ex['fields'][$key] = ['value' => $v, 'sure' => $sure && !$force_unsure];
}

/** Add a table row (normalized). Rows whose every cell is empty / N/A are left out. */
function pds_import_put_row(array &$ex, string $section, string $tkey, array $raw, bool $force_unsure = false): void {
    $cols = pds_import_columns($tkey);
    $cells = [];
    $sure = !$force_unsure;
    $any = false;
    foreach ($cols as $ckey => $def) {
        [$v, $s] = pds_import_normalize(pds_import_rule_key($tkey, $ckey), $def, $raw[$ckey] ?? '', $ex['source']);
        $cells[$ckey] = $v;
        if ($v !== '') { $any = true; if (!$s) { $sure = false; } }
    }
    // The column that identifies the row (title, school, position, ...) must be there
    $main = ['ld' => 'title', 'education' => 'school', 'eligibility' => 'name', 'work' => 'position', 'voluntary' => 'organization',
             'children' => 'name', 'references' => 'name'][$tkey] ?? 'value';
    if (!$any || ($cells[$main] ?? '') === '') { return; }
    if ($tkey === 'ld' && $cells['ld_type'] === '') { $cells['ld_type'] = 'Technical'; $sure = false; }   // the PDS needs a type: a guess to check
    $ex['sections'][$section] = true;
    $ex['tables'][$tkey][] = ['cells' => $cells, 'sure' => $sure];
}

/** Section of a table key. */
function pds_import_table_section(string $tkey): string {
    foreach (pds_import_sections() as $skey => $s) { if (in_array($tkey, $s['tables'], true)) { return $skey; } }
    return '';
}

/**
 * Comparable key of a table row, for spotting rows already in the PDS:
 * same title / school / position / name and same dates.
 */
function pds_import_row_key(string $tkey, array $r): string {
    $n = fn($v) => preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string)($v ?? ''))));
    return $tkey . ':' . match ($tkey) {
        'ld'          => $n($r['title'] ?? '') . '|' . $n($r['date_from'] ?? '') . '|' . $n($r['date_to'] ?? ''),
        'education'   => $n($r['school'] ?? '') . '|' . $n($r['level'] ?? '') . '|' . $n($r['from'] ?? '') . '|' . $n($r['to'] ?? ''),
        'eligibility' => $n($r['name'] ?? '') . '|' . $n($r['exam_date'] ?? ''),
        'work'        => $n($r['position'] ?? '') . '|' . $n($r['from'] ?? '') . '|' . $n($r['to'] ?? ''),
        'voluntary'   => $n($r['organization'] ?? '') . '|' . $n($r['from'] ?? '') . '|' . $n($r['to'] ?? ''),
        'children', 'references' => $n($r['name'] ?? ''),
        default       => $n($r['value'] ?? ''),
    };
}

// =====================================================================
// Reading the file
// =====================================================================

/**
 * Read whatever PDS content $abs_path holds. $known_text: the text the
 * upload preview already read from it, used if reading it again gives nothing.
 */
function pds_import_extract_file(string $abs_path, ?string $known_text = null): array {
    $ext = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        $sheets = xlsx_read_sheets($abs_path);
        if ($sheets === null) { return pds_import_empty('xlsx'); }
        return pds_xlsx_is_2026($sheets) ? pds_import_from_xlsx($sheets) : pds_import_from_text(xlsx_to_text($sheets), 'text');
    }
    require_once ROOT_PATH . '/ocr/OcrProcessor.php';
    $read = OcrProcessor::extractAllText($abs_path, PDS_IMPORT_MAX_PAGES);
    if (trim($read['text']) === '' && $known_text !== null) { $read = ['text' => $known_text, 'ocr' => true]; }   // no OCR here: the preview's text
    return pds_import_from_text($read['text'], $read['ocr'] ? 'ocr' : 'text');
}

/**
 * Cells of every sheet of an .xlsx: sheet name => [cell ref => text].
 * Booleans (checkbox links) come back as TRUE / FALSE, formulas as their
 * last value. Null if the file isn't a readable workbook.
 */
function xlsx_read_sheets(string $path): ?array {
    if (!class_exists('ZipArchive')) { error_log('PDS import: the zip extension is not installed'); return null; }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { return null; }
    $get = function (string $name) use ($zip): ?string {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > 30 * 1024 * 1024) { return null; }   // no zip bombs
        $s = $zip->getFromName($name);
        return $s === false ? null : $s;
    };
    $load = function (?string $xml): ?SimpleXMLElement {
        if ($xml === null) { return null; }
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $x ?: null;
    };
    $wb = $load($get('xl/workbook.xml'));
    $rels = $load($get('xl/_rels/workbook.xml.rels'));
    if (!$wb || !$rels) { $zip->close(); return null; }

    $shared = [];
    if ($ss = $load($get('xl/sharedStrings.xml'))) {
        foreach ($ss->si as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r as $r) { $t .= (string)$r->t; }
            $shared[] = $t;
        }
    }
    $targets = [];
    foreach ($rels->Relationship as $r) {
        $t = (string)$r['Target'];
        $targets[(string)$r['Id']] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
    }
    $sheets = [];
    foreach ($wb->sheets->sheet as $sh) {
        $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $ws = $load($get($targets[$rid] ?? ''));
        if (!$ws) { continue; }
        $cells = [];
        foreach ($ws->sheetData->row ?? [] as $row) {
            foreach ($row->c as $c) {
                $t = (string)$c['t'];
                if ($t === 's') { $v = $shared[(int)$c->v] ?? ''; }
                elseif ($t === 'inlineStr') {
                    $v = isset($c->is->t) ? (string)$c->is->t : '';
                    foreach ($c->is->r ?? [] as $r) { $v .= (string)$r->t; }
                }
                elseif ($t === 'b') { $v = (string)$c->v === '1' ? 'TRUE' : 'FALSE'; }
                else { $v = (string)($c->v ?? ''); }
                if (trim($v) !== '') { $cells[strtoupper((string)$c['r'])] = $v; }
            }
        }
        $sheets[(string)$sh['name']] = $cells;
    }
    $zip->close();
    return $sheets;
}

/** Column letters -> number (A = 1). */
function xlsx_col_number(string $col): int {
    $n = 0;
    foreach (str_split(strtoupper($col)) as $ch) { $n = $n * 26 + ord($ch) - 64; }
    return $n;
}

/** A workbook as text: one line per row, cells three spaces apart (for classification and the text reader). */
function xlsx_to_text(array $sheets): string {
    $out = [];
    foreach ($sheets as $cells) {
        $rows = [];
        foreach ($cells as $ref => $v) {
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m) || in_array(strtoupper($v), ['TRUE', 'FALSE'], true)) { continue; }
            $rows[(int)$m[2]][xlsx_col_number($m[1])] = trim(preg_replace('/\s+/', ' ', $v));
        }
        ksort($rows);
        foreach ($rows as $cols) { ksort($cols); $out[] = implode('   ', $cols); }
        $out[] = '';
    }
    return implode("\n", $out);
}

/** Cells of sheet C1, C2, ... (a sheet named "C1" or starting with it, not "C10"). */
function pds_xlsx_sheet(array $sheets, string $key): array {
    foreach ($sheets as $name => $cells) {
        if (preg_match('/^' . preg_quote($key, '/') . '(?!\d)/i', trim($name))) { return $cells; }
    }
    return [];
}

/** The 2026 revision (the layout of pds_xlsx_map()) has UMID and PhilSys on sheet C1. */
function pds_xlsx_is_2026(array $sheets): bool {
    foreach (pds_xlsx_sheet($sheets, 'C1') as $v) {
        if (stripos($v, 'UMID') !== false || stripos($v, 'PhilSys') !== false) { return true; }
    }
    return false;
}

/**
 * Where each value sits in the official CS Form No. 212 (Revised 2026)
 * workbook -- the same cells includes/pds_form_fill.php prints into (keep
 * the two in step). Tables: [sheet, first row, rows, column letter => column]
 * for the main sheet and its continuation sheet.
 */
function pds_xlsx_map(): array {
    return [
        'fields' => [
            'C1' => [
                'D10' => 'surname', 'D11' => 'first_name', 'N11' => 'name_extension', 'D12' => 'middle_name', 'D13' => 'date_of_birth',
                'D15' => 'place_of_birth', 'E20' => 'civil_status_other', 'D22' => 'height', 'D24' => 'weight', 'D25' => 'blood_type',
                'D27' => 'umid_id', 'D29' => 'pagibig_id', 'D31' => 'philhealth_no', 'D32' => 'philsys_pcn', 'D33' => 'tin_no',
                'D34' => 'agency_employee_no', 'J16' => 'dual_citizenship_country',
                'I17' => 'res_house', 'L17' => 'res_street', 'I19' => 'res_subdivision', 'L19' => 'res_barangay', 'I22' => 'res_city',
                'L22' => 'res_province', 'I24' => 'residential_zip',
                'I25' => 'perm_house', 'L25' => 'perm_street', 'I27' => 'perm_subdivision', 'L27' => 'perm_barangay', 'I29' => 'perm_city',
                'L29' => 'perm_province', 'I31' => 'permanent_zip',
                'I32' => 'telephone_no', 'I33' => 'mobile_no', 'I34' => 'email',
                'D36' => 'spouse_surname', 'D37' => 'spouse_first_name', 'H37' => 'spouse_name_extension', 'D38' => 'spouse_middle_name',
                'D39' => 'spouse_occupation', 'D40' => 'spouse_employer', 'D41' => 'spouse_business_address', 'D42' => 'spouse_telephone',
                'D44' => 'father_surname', 'D45' => 'father_first_name', 'H45' => 'father_name_extension', 'D46' => 'father_middle_name',
                'D48' => 'mother_surname', 'D49' => 'mother_first_name', 'D50' => 'mother_middle_name',
            ],
            'C4' => [
                'I6' => 'q34_details', 'I11' => 'q34b_details', 'I15' => 'q35a_details', 'L19' => 'q35b_details', 'L20' => 'q35b_date',
                'L21' => 'q35b_status', 'I25' => 'q36_details', 'I29' => 'q37_details', 'L32' => 'q38a_details', 'L35' => 'q38b_details',
                'I39' => 'q39_details', 'M44' => 'q40a_details', 'M46' => 'q40b_id', 'M48' => 'q40c_id',
                'D61' => 'gov_id_type', 'D62' => 'gov_id_number', 'D64' => 'gov_id_issued',
            ],
        ],
        // Checkbox link cells (TRUE when ticked): field => [cell => value]
        'checks' => [
            'C1' => [
                'sex'                 => ['D16' => 'Male', 'E16' => 'Female'],
                'civil_status'        => ['D17' => 'Single', 'E17' => 'Married', 'D18' => 'Widowed', 'E19' => 'Separated', 'D20' => 'Other/s'],
                'citizenship'         => ['J13' => 'Filipino', 'K13' => 'Dual Citizenship'],
                'dual_citizenship_by' => ['L14' => 'by birth', 'M14' => 'by naturalization'],
            ],
            'C4' => array_map(fn($row) => ["H$row" => 'Yes', "J$row" => 'No'],
                ['q34a' => 3, 'q34b' => 8, 'q35a' => 13, 'q35b' => 18, 'q36' => 23, 'q37' => 27, 'q38a' => 31, 'q38b' => 34,
                 'q39' => 37, 'q40a' => 43, 'q40b' => 45, 'q40c' => 47]),
        ],
        'tables' => [
            'children'    => [['C1', 37, 13, ['I' => 'name', 'M' => 'date_of_birth']], ['C7', 4, 20, ['I' => 'name', 'L' => 'date_of_birth']]],
            'eligibility' => [['C2', 5, 7, ['A' => 'name', 'F' => 'rating', 'G' => 'exam_date', 'I' => 'exam_place', 'L' => 'license_number', 'M' => 'license_valid']],
                              ['C9', 5, 24, ['A' => 'name', 'F' => 'rating', 'G' => 'exam_date', 'I' => 'exam_place', 'J' => 'license_number', 'K' => 'license_valid']]],
            'work'        => [['C2', 18, 24, $work = ['A' => 'from', 'C' => 'to', 'D' => 'position', 'G' => 'agency', 'J' => 'salary', 'K' => 'salary_grade', 'L' => 'status', 'M' => 'govt_service']],
                              ['C6', 7, 33, $work]],
            'ld'          => [['C3', 5, PDS_LD_ROWS_PAGE3, $ld = ['A' => 'title', 'E' => 'date_from', 'F' => 'date_to', 'G' => 'hours', 'H' => 'ld_type', 'I' => 'conducted_by']],
                              ['C5', 6, PDS_LD_ROWS_CONTINUATION, $ld]],
            'voluntary'   => [['C3', 27, 9, $vol = ['A' => 'organization', 'E' => 'from', 'F' => 'to', 'G' => 'hours', 'H' => 'position']],
                              ['C10', 6, 29, $vol]],
            'references'  => [['C4', 52, 3, ['A' => 'name', 'G' => 'address', 'H' => 'telephone']]],
        ],
        // Education: one fixed row per level on C1, any further schools on C8 (level in column A)
        'education' => [
            'rows' => ['Elementary' => 55, 'Secondary' => 56, 'Vocational / Trade Course' => 57, 'College' => 58, 'Graduate Studies' => 59],
            'cols' => ['D' => 'school', 'G' => 'degree', 'J' => 'from', 'K' => 'to', 'L' => 'units', 'M' => 'year_graduated', 'N' => 'honors'],
            'continuation' => ['C8', 6, 37],
        ],
        // Other information: three lists side by side
        'other' => [['C3', 39, 7], ['C11', 4, 31], 'cols' => ['A' => 'skills', 'C' => 'recognitions', 'I' => 'memberships']],
    ];
}

/** Read the official 2026 workbook cell by cell. */
function pds_import_from_xlsx(array $sheets): array {
    $ex = pds_import_empty('xlsx');
    $map = pds_xlsx_map();
    $section_of = [];
    foreach (pds_import_sections() as $skey => $s) { foreach ($s['fields'] as $f) { $section_of[$f] = $skey; } }
    $ticked = fn($v) => in_array(strtoupper(trim((string)$v)), ['TRUE', '1', 'X', '✓', '✔', 'YES'], true);

    foreach ($map['fields'] as $sheet => $cells) {
        $data = pds_xlsx_sheet($sheets, $sheet);
        foreach ($cells as $cell => $key) {
            if (isset($data[$cell])) { pds_import_put_field($ex, $section_of[$key], $key, $data[$cell]); }
        }
    }
    foreach ($map['checks'] as $sheet => $fields) {
        $data = pds_xlsx_sheet($sheets, $sheet);
        foreach ($fields as $key => $options) {
            $on = array_values(array_filter($options, fn($cell) => $ticked($data[$cell] ?? ''), ARRAY_FILTER_USE_KEY));
            if (count($on) === 1) { pds_import_put_field($ex, $section_of[$key], $key, $on[0]); }
        }
    }
    // An "other" civil status / dual-citizenship country only counts with its box ticked
    if (($ex['fields']['civil_status']['value'] ?? '') !== 'Other/s') { unset($ex['fields']['civil_status_other']); }
    if (($ex['fields']['citizenship']['value'] ?? '') !== 'Dual Citizenship') { unset($ex['fields']['dual_citizenship_country']); }

    foreach ($map['tables'] as $tkey => $parts) {
        foreach ($parts as [$sheet, $first, $count, $cols]) {
            $data = pds_xlsx_sheet($sheets, $sheet);
            for ($r = $first; $r < $first + $count; $r++) {
                $row = [];
                foreach ($cols as $col => $ckey) { $row[$ckey] = $data[$col . $r] ?? ''; }
                pds_import_put_row($ex, pds_import_table_section($tkey), $tkey, $row);
            }
        }
    }
    $e = $map['education'];
    $c1 = pds_xlsx_sheet($sheets, 'C1');
    foreach ($e['rows'] as $level => $r) {
        $row = ['level' => $level];
        foreach ($e['cols'] as $col => $ckey) { $row[$ckey] = $c1[$col . $r] ?? ''; }
        pds_import_put_row($ex, 'III', 'education', $row);
    }
    [$sheet, $first, $count] = $e['continuation'];
    $c8 = pds_xlsx_sheet($sheets, $sheet);
    for ($r = $first; $r < $first + $count; $r++) {
        $row = ['level' => $c8['A' . $r] ?? ''];
        foreach ($e['cols'] as $col => $ckey) { $row[$ckey] = $c8[$col . $r] ?? ''; }
        pds_import_put_row($ex, 'III', 'education', $row);
    }
    $o = $map['other'];
    foreach ([$o[0], $o[1]] as [$sheet, $first, $count]) {
        $data = pds_xlsx_sheet($sheets, $sheet);
        for ($r = $first; $r < $first + $count; $r++) {
            foreach ($o['cols'] as $col => $tkey) { pds_import_put_row($ex, 'VIII', $tkey, ['value' => $data[$col . $r] ?? '']); }
        }
    }
    return $ex;
}

// =====================================================================
// Text (PDF text layer, OCR, or a workbook in another layout)
// =====================================================================

const PDS_DATE_RE = '(?:\d{1,2}\s*[\/.\-]\s*\d{1,2}\s*[\/.\-]\s*\d{2,4}|\d{4}-\d{2}-\d{2})';

/**
 * Split text into sections by their headings. A heading must start a line
 * (or follow a wide gap) and be in capitals, so the same words inside a
 * sentence don't count. Every occurrence is kept (continuation sheets).
 * @return array<string, string> section key => its text
 */
function pds_text_sections(string $text): array {
    $heads = [
        'I'    => 'PERSONAL\s+INFORMATION',
        'II'   => 'FAMILY\s+BACKGROUND',
        'III'  => 'EDUCATIONAL\s+BACKGROUND',
        'IV'   => 'CIVIL\s+SERVICE\s+ELIGIBILITY',
        'V'    => 'WORK\s+EXPERIENCE',
        'LD'   => 'LEARNING\s+AND\s+DEVELOPMENT|L\s*&\s*D\s+INTERVENTIONS',
        'VII'  => 'VOLUNTARY\s+WORK',
        'VIII' => 'OTHER\s+INFORMATION|SPECIAL\s+SKILLS\s+(?:and|AND)\s+HOBBIES',
    ];
    $marks = [];
    foreach ($heads as $key => $re) {
        if (preg_match_all('/(?:^[ \t]*|[ \t]{2,})(?:[IVX]+\s*\.\s*|\d{1,2}\s*\.\s*)?(?:' . $re . ')/m', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$hit, $pos]) { $marks[] = [$pos, $key]; }
        }
    }
    // Where a section stops without a next heading: the page footer / signature line, or page 4 (questions 34-40, not read from text)
    if (preg_match_all('/Page\s+\d+\s+of\s+\d+|^[ \t]*SIGNATURE\b|34\.\s+Are\s+you\s+related/im', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$hit, $pos]) { $marks[] = [$pos, 'END']; }
    }
    usort($marks, fn($a, $b) => $a[0] <=> $b[0]);
    $out = [];
    foreach ($marks as $i => [$pos, $key]) {
        $end = $marks[$i + 1][0] ?? strlen($text);
        $out[$key] = ($out[$key] ?? '') . "\n" . substr($text, $pos, $end - $pos);
    }
    unset($out['END']);
    return $out;
}

/** Words that start a label: a value can't be one (that means the value box was empty). */
function pds_text_is_label(string $v): bool {
    return (bool)preg_match('/^(?:\d{1,2}\s*\.(?!\d)|\(|(?:SURNAME|FIRST NAME|MIDDLE NAME|NAME EXTENSION|DATE OF BIRTH|PLACE OF BIRTH|SEX|CIVIL STATUS|HEIGHT|WEIGHT|BLOOD|UMID|GSIS|PAG-?IBIG|PHILHEALTH|PHILSYS|SSS|TIN|AGENCY|CITIZENSHIP|RESIDENTIAL|PERMANENT|ZIP|TELEPHONE|MOBILE|E-?MAIL|House\/Block|Street|Subdivision|Barangay|City\/Municipality|Province|OCCUPATION|EMPLOYER|BUSINESS|SPOUSE|FATHER|MOTHER|NAME of CHILDREN|DATE OF BIRTH|Filipino|Dual|Pls\.|If holder|by birth|by naturalization)(?![A-Za-z]))/i', trim($v));
}

/**
 * The value after a label on the same line: the text up to the next wide
 * gap (columns in a laid-out page) or the next numbered label.
 */
function pds_text_value(string $chunk, string $label_re): string {
    if (!preg_match('/' . $label_re . '[ \t]*:?[ \t]*([^\n]*)/i', $chunk, $m)) { return ''; }
    $v = preg_split('/[ \t]{2,}/', trim($m[1]))[0] ?? '';
    $v = preg_split('/\s+(?=\d{1,2}\s*\.\s+[A-Z])/', $v)[0];   // "DELA CRUZ 2. FIRST NAME ..." on one OCR line
    return pds_text_is_label($v) ? '' : trim($v);
}

/** Which of $options is ticked on the line of $label_re (a check mark before it), or ''. */
function pds_text_checked(string $chunk, string $label_re, array $options): string {
    if (!preg_match('/' . $label_re . '[^\n]*(?:\n[^\n]*){0,2}/i', $chunk, $m)) { return ''; }
    $on = [];
    foreach ($options as $word => $value) {
        if (preg_match('/(?:[☑☒✓✔■▣●✗]|\[\s*[xX✓✔]\s*\]|\(\s*[xX✓✔]\s*\)|\b[xX])\s*' . $word . '\b/u', $m[0])) { $on[] = $value; }
    }
    return count($on) === 1 ? $on[0] : '';
}

/** Lines of a section without the form's printed instructions, headers and footers. */
function pds_text_lines(string $chunk): array {
    $noise = '/continue on separate sheet|SIGNATURE|CS\s+FORM|Page\s+\d+\s+of\s+\d+|^\s*DATE\s*$|\(Start from|\(Include private|INCLUSIVE DATES|Write in full|^\s*From\s+To\s*$|\(dd\/mm\/yyyy\)|\(if applicable\)|^\s*\(?Continue/i';
    return array_values(array_filter(preg_split('/\R/', $chunk), fn($l) => trim($l) !== '' && !preg_match($noise, $l)));
}

/** Cells of a laid-out line (separated by wide gaps). */
function pds_text_cells(string $line): array {
    return array_values(array_filter(array_map('trim', preg_split('/[ \t]{2,}|\t/', trim($line))), fn($c) => $c !== ''));
}

/** Read every section found in the text. */
function pds_import_from_text(string $text, string $source): array {
    $ex = pds_import_empty($source);
    if (trim($text) === '') { return $ex; }
    $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
    $sections = pds_text_sections($text);
    $D = PDS_DATE_RE;
    $unsure = $source === 'ocr';

    // ---- I. Personal information
    if (isset($sections['I'])) {
        $c = $sections['I'];
        $labels = [
            'surname' => '\bSURNAME\b', 'first_name' => 'FIRST\s+NAME', 'middle_name' => 'MIDDLE\s+NAME',
            'name_extension' => 'NAME\s+EXTENSION(?:\s*\([^)\n]*\))?', 'date_of_birth' => 'DATE\s+OF\s+BIRTH(?:\s*\([^)\n]*\))?',
            'place_of_birth' => 'PLACE\s+OF\s+BIRTH', 'height' => 'HEIGHT(?:\s*\(\s*m\s*\))?', 'weight' => 'WEIGHT(?:\s*\(\s*kg\s*\))?',
            'blood_type' => 'BLOOD\s+TYPE', 'umid_id' => 'UMID\s+(?:ID\s+)?NO\.?', 'pagibig_id' => 'PAG-?IBIG\s+(?:ID\s+)?NO\.?',
            'philhealth_no' => 'PHILHEALTH\s+NO\.?', 'philsys_pcn' => 'PhilSys\s+Card\s+Number(?:\s*\(PCN\))?:?', 'tin_no' => '\bTIN\s+NO\.?',
            'agency_employee_no' => 'AGENCY\s+EMPLOYEE\s+NO\.?', 'telephone_no' => 'TELEPHONE\s+NO\.?', 'mobile_no' => 'MOBILE\s+NO\.?',
            'email' => 'E-?MAIL\s+ADDRESS(?:\s*\([^)\n]*\))?',
        ];
        foreach ($labels as $key => $re) { pds_import_put_field($ex, 'I', $key, pds_text_value($c, $re), $unsure); }
        pds_import_put_field($ex, 'I', 'sex', pds_text_checked($c, 'SEX', ['Male' => 'Male', 'Female' => 'Female']), true);
        pds_import_put_field($ex, 'I', 'civil_status', pds_text_checked($c, 'CIVIL\s+STATUS',
            ['Single' => 'Single', 'Married' => 'Married', 'Widowed' => 'Widowed', 'Separated' => 'Separated', 'Other' => 'Other/s']), true);
        pds_import_put_field($ex, 'I', 'citizenship', pds_text_checked($c, 'CITIZENSHIP', ['Filipino' => 'Filipino', 'Dual' => 'Dual Citizenship']), true);
        pds_text_addresses($ex, $c);
        $ex['sections']['I'] = true;
    }

    // ---- II. Family background (spouse, father, mother blocks; children in the right-hand column)
    if (isset($sections['II'])) {
        $c = $sections['II'];
        $blocks = preg_split("/(?=SPOUSE'?S\s+SURNAME|FATHER'?S\s+SURNAME|MOTHER'?S\s+MAIDEN\s+NAME)/i", $c);
        foreach ($blocks as $b) {
            if (preg_match("/^SPOUSE'?S/i", $b)) {
                foreach (['spouse_surname' => "SPOUSE'?S\s+SURNAME", 'spouse_first_name' => 'FIRST\s+NAME', 'spouse_middle_name' => 'MIDDLE\s+NAME',
                          'spouse_name_extension' => 'NAME\s+EXTENSION(?:\s*\([^)\n]*\))?', 'spouse_occupation' => 'OCCUPATION',
                          'spouse_employer' => 'EMPLOYER\s*\/\s*BUSINESS\s+NAME', 'spouse_business_address' => 'BUSINESS\s+ADDRESS',
                          'spouse_telephone' => 'TELEPHONE\s+NO\.?'] as $key => $re) {
                    pds_import_put_field($ex, 'II', $key, pds_text_value($b, $re), $unsure);
                }
            } elseif (preg_match("/^FATHER'?S/i", $b)) {
                foreach (['father_surname' => "FATHER'?S\s+SURNAME", 'father_first_name' => 'FIRST\s+NAME', 'father_middle_name' => 'MIDDLE\s+NAME',
                          'father_name_extension' => 'NAME\s+EXTENSION(?:\s*\([^)\n]*\))?'] as $key => $re) {
                    pds_import_put_field($ex, 'II', $key, pds_text_value($b, $re), $unsure);
                }
            } elseif (preg_match("/^MOTHER'?S/i", $b)) {
                foreach (['mother_surname' => '\bSURNAME\b', 'mother_first_name' => 'FIRST\s+NAME', 'mother_middle_name' => 'MIDDLE\s+NAME'] as $key => $re) {
                    pds_import_put_field($ex, 'II', $key, pds_text_value($b, $re), $unsure);
                }
            }
        }
        // Children: the column under "NAME of CHILDREN", each with a date of birth
        $lines = preg_split('/\R/', $c);
        $offset = null;
        foreach ($lines as $line) {
            if ($offset === null) {
                if (($p = stripos($line, 'NAME of CHILDREN')) !== false) { $offset = $p; }
                continue;
            }
            $seg = substr($line, max(0, $offset - 2));
            // a name of single-spaced words (a wide gap separates it from the left-hand column), then the date
            if (!preg_match("/(?:^|[ \t]{2,})([A-Za-zÀ-ÿÑñ][A-Za-zÀ-ÿÑñ.,'-]*(?: [A-Za-zÀ-ÿÑñ.,'-]+)+)[ \t]+($D)[ \t]*$/u", $seg, $m)) { continue; }
            pds_import_put_row($ex, 'II', 'children', ['name' => $m[1], 'date_of_birth' => $m[2]], $unsure);
        }
        $ex['sections']['II'] = true;
    }

    // ---- III. Education: one line per school, starting with its level
    if (isset($sections['III'])) {
        foreach (pds_text_lines($sections['III']) as $line) {
            if (!preg_match('/^\s*(ELEMENTARY|SECONDARY|VOCATIONAL(?:\s*\/?\s*TRADE\s+COURSE)?|COLLEGE|GRADUATE\s+STUDIES)\b[ \t]*(.*)$/i', $line, $m)) { continue; }
            $level = ['ELEMENTARY' => 'Elementary', 'SECONDARY' => 'Secondary', 'COLLEGE' => 'College'][strtoupper($m[1])]
                ?? (stripos($m[1], 'VOC') === 0 ? 'Vocational / Trade Course' : 'Graduate Studies');
            $cells = pds_text_cells($m[2]);
            $fuzzy = count($cells) < 3;   // no columns to go by (OCR without spacing)
            if ($fuzzy) { $cells = preg_split('/\s+(?=(?:19|20)\d{2}\b|present\b)|(?<=\b(?:19|20)\d{2})\s+/i', trim($m[2])); }
            $row = ['level' => $level];
            $year_at = [];
            foreach ($cells as $i => $cell) { if (preg_match('/^((?:19|20)\d{2}|present)$/i', $cell)) { $year_at[] = $i; } }
            if ($year_at) {
                $before = array_slice($cells, 0, $year_at[0]);
                $row['school'] = $before[0] ?? '';
                $row['degree'] = implode(' ', array_slice($before, 1));
                $row['from'] = $cells[$year_at[0]];
                $i = $year_at[0] + 1;
                if (isset($cells[$i]) && in_array($i, $year_at, true)) { $row['to'] = $cells[$i]; $i++; }
                $after = array_slice($cells, $i);
                if ($after && !preg_match('/^(19|20)\d{2}$/', $after[0])) { $row['units'] = array_shift($after); }
                if ($after && preg_match('/^(19|20)\d{2}$/', $after[0])) { $row['year_graduated'] = array_shift($after); }
                $row['honors'] = implode(' ', $after);
            } else {
                [$row['school'], $row['degree']] = [$cells[0] ?? '', $cells[1] ?? ''];
            }
            pds_import_put_row($ex, 'III', 'education', $row, $unsure || $fuzzy);
        }
        $ex['sections']['III'] = true;
    }

    // ---- IV. Eligibility: name [rating] exam date, place, license no., valid until
    if (isset($sections['IV'])) {
        foreach (pds_text_lines($sections['IV']) as $line) {
            if (!preg_match("/^(.*?)[ \t]+($D)[ \t]*(.*)$/", trim($line), $m)) { continue; }
            $head = pds_text_cells($m[1]) ?: [trim($m[1])];
            $rating = '';
            if (count($head) > 1 && preg_match('/^\d{2,3}(\.\d{1,2})?%?$/', end($head))) { $rating = rtrim(array_pop($head), '%'); }
            elseif (preg_match('/^(.*\S)\s+(\d{2,3}\.\d{1,2})$/', $m[1], $r)) { [$head, $rating] = [[$r[1]], $r[2]]; }
            $tail = pds_text_cells($m[3]);
            $valid = $tail && preg_match("/^$D$/", end($tail)) ? array_pop($tail) : '';
            $license = count($tail) > 1 && preg_match('/\d/', end($tail)) ? array_pop($tail) : '';
            pds_import_put_row($ex, 'IV', 'eligibility', ['name' => implode(' ', $head), 'rating' => $rating, 'exam_date' => $m[2],
                'exam_place' => implode(' ', $tail), 'license_number' => $license, 'license_valid' => $valid], $unsure);
        }
        $ex['sections']['IV'] = true;
    }

    // ---- V. Work experience: from, to (or Present), position, agency, salary, grade, status, gov't service
    if (isset($sections['V'])) {
        foreach (pds_text_lines($sections['V']) as $line) {
            if (!preg_match("/^\s*($D)[ \t]+($D|present)[ \t]+(.+)$/i", $line, $m)) { continue; }
            $cells = pds_text_cells($m[3]);
            $fuzzy = count($cells) < 2;
            $row = ['from' => $m[1], 'to' => $m[2]];
            if ($cells && preg_match('/^(Y|N|YES|NO)$/i', end($cells))) { $row['govt_service'] = array_pop($cells); }
            $row['position'] = array_shift($cells) ?? '';
            $row['agency'] = array_shift($cells) ?? '';
            $rest = [];
            foreach ($cells as $cell) {
                if (!isset($row['salary']) && preg_match('/^(?:PHP|P|₱)?\s*[\d,]{3,}(\.\d{1,2})?$/i', $cell)) { $row['salary'] = preg_replace('/^(?:PHP|P|₱)\s*/i', '', $cell); }
                elseif (!isset($row['salary_grade']) && preg_match('/^(?:SG\s*)?\d{1,2}(?:\s*[-\/]\s*\d{1,2})?$/i', $cell)) { $row['salary_grade'] = $cell; }
                else { $rest[] = $cell; }
            }
            $row['status'] = implode(' ', $rest);
            pds_import_put_row($ex, 'V', 'work', $row, $unsure || $fuzzy);
        }
        $ex['sections']['V'] = true;
    }

    // ---- VI. Learning and development: title, from, to, hours, type, conducted / sponsored by.
    // A long title wraps: its first lines come before the line with the dates.
    if (isset($sections['LD'])) {
        $header = '/LEARNING\s+AND\s+DEVELOPMENT|TITLE OF|NUMBER OF|Type of|CONDUCTED|SPONSORED|INTERVENTIONS|Managerial\s*\/|Supervisory|^\s*From\s+To\b|^\s*(?:\d{1,2}\.)?\s*$/i';
        $wrapped = [];
        foreach (pds_text_lines($sections['LD']) as $line) {
            if (preg_match("/^(.*?)[ \t]+($D)[ \t]+($D)[ \t]*(.*)$/", $line, $m)) {
                $title = preg_replace('/^\s*\d{1,3}\s*[.)]?\s+(?=\S)/', '', trim($m[1]));
                $title = trim(implode(' ', array_merge($wrapped, [$title])));
                $rest = trim($m[4]);
                $hours = preg_match('/^(\d+(?:\.\d+)?)\s*(?:hrs?\.?|hours)?\s*(.*)$/i', $rest, $h) ? $h[1] : '';
                $rest = $hours !== '' ? $h[2] : $rest;
                $type = preg_match('/^(Managerial|Supervisory|Technical|Foundation|Other)\b[ \t]*(.*)$/i', $rest, $t) ? ucfirst(strtolower($t[1])) : '';
                $rest = $type !== '' ? $t[2] : $rest;
                pds_import_put_row($ex, 'LD', 'ld', ['title' => $title, 'date_from' => $m[2], 'date_to' => $m[3], 'hours' => $hours,
                    'ld_type' => $type, 'conducted_by' => $rest], $unsure || $wrapped);
                $wrapped = [];
            } elseif (!preg_match($header, $line) && !pds_import_is_na(trim($line))) {
                $wrapped[] = pds_text_cells($line)[0];
            }
        }
        $ex['sections']['LD'] = true;
    }

    // ---- VII. Voluntary work: organization, from, to, hours, position / nature of work
    if (isset($sections['VII'])) {
        foreach (pds_text_lines($sections['VII']) as $line) {
            if (!preg_match("/^(.*?)[ \t]+($D)[ \t]+($D|present)[ \t]*(.*)$/i", $line, $m)) { continue; }
            $rest = trim($m[4]);
            $hours = preg_match('/^(\d+(?:\.\d+)?)\s*(.*)$/', $rest, $h) ? $h[1] : '';
            pds_import_put_row($ex, 'VII', 'voluntary', ['organization' => implode(', ', pds_text_cells($m[1])), 'from' => $m[2], 'to' => $m[3],
                'hours' => $hours, 'position' => $hours !== '' ? $h[2] : $rest], $unsure);
        }
        $ex['sections']['VII'] = true;
    }

    // ---- VIII. Other information: three columns, split where their headings start
    if (isset($sections['VIII'])) {
        $lines = preg_split('/\R/', $sections['VIII']);
        $cols = null;
        foreach ($lines as $line) {
            if ($cols === null) {
                // column starts: the item numbers "31." "32." "33." (or the headings) on the heading line
                $a = stripos($line, 'SPECIAL SKILLS');
                $b = stripos($line, 'NON-ACADEMIC');
                $c = stripos($line, 'MEMBERSHIP');
                if ($a !== false && $b !== false && $c !== false) {
                    $cols = array_map(fn($p) => preg_match('/\d{1,2}\.\s*$/', substr($line, max(0, $p - 6), 6), $n) ? $p - strlen($n[0]) : $p, [$a, $b, $c]);
                }
                continue;
            }
            if (preg_match('/\(Write in full\)|34\.|Are you related/i', $line)) { continue; }
            preg_match_all('/\S(?:.*?\S)?(?=[ \t]{2,}|[ \t]*$)/', $line, $cells, PREG_OFFSET_CAPTURE);
            $values = ['', '', ''];
            foreach ($cells[0] as [$cell, $pos]) {
                $col = 0;
                foreach ($cols as $i => $start) { if ($pos + 6 >= $start) { $col = $i; } }
                $values[$col] = trim($values[$col] . ' ' . $cell);
            }
            foreach (['skills', 'recognitions', 'memberships'] as $i => $tkey) {
                pds_import_put_row($ex, 'VIII', $tkey, ['value' => $values[$i]], $unsure);
            }
        }
        if ($cols === null) {   // headings not on one line: only lines with exactly three columns can be split
            foreach (pds_text_lines($sections['VIII']) as $line) {
                $cells = pds_text_cells($line);
                if (count($cells) !== 3 || preg_match('/SPECIAL SKILLS|NON-ACADEMIC|MEMBERSHIP/i', $line)) { continue; }
                foreach (['skills', 'recognitions', 'memberships'] as $i => $tkey) {
                    pds_import_put_row($ex, 'VIII', $tkey, ['value' => $cells[$i]], true);
                }
            }
        }
        $ex['sections']['VIII'] = true;
    }
    return $ex;
}

/**
 * Residential / permanent address: the values sit above their small
 * captions ("House/Block/Lot No.   Street", ...), so each value is read
 * from the line above, at the caption's position. Best effort -- always
 * marked for checking.
 */
function pds_text_addresses(array &$ex, string $chunk): void {
    $lines = preg_split('/\R/', $chunk);
    $perm_from = PHP_INT_MAX;
    foreach ($lines as $i => $l) { if (stripos($l, 'PERMANENT ADDRESS') !== false) { $perm_from = $i; break; } }
    $captions = [['House/Block/Lot', 'house'], ['Street', 'street'], ['Subdivision/Village', 'subdivision'], ['Barangay', 'barangay'],
                 ['City/Municipality', 'city'], ['Province', 'province']];
    foreach ($lines as $i => $line) {
        $found = [];
        foreach ($captions as [$caption, $part]) {
            if (($p = stripos($line, $caption)) !== false) { $found[$p] = $part; }
        }
        if (!$found || $i === 0) { continue; }
        ksort($found);
        $prefix = $i >= $perm_from ? 'perm' : 'res';
        $above = '';
        for ($j = $i - 1; $j >= 0 && $above === ''; $j--) { if (trim($lines[$j]) !== '') { $above = $lines[$j]; } }
        $offs = array_keys($found);
        foreach ($offs as $k => $off) {
            $start = max(0, $off - 3);
            $len = isset($offs[$k + 1]) ? $offs[$k + 1] - 3 - $start : 60;
            $v = trim(preg_split('/[ \t]{2,}/', trim(substr($above, $start, max(0, $len))))[0] ?? '');
            if ($v !== '' && !pds_text_is_label($v)) { pds_import_put_field($ex, 'I', "{$prefix}_{$found[$off]}", $v, true); }
        }
    }
    if (preg_match_all('/ZIP\s+CODE[ \t]*:?[ \t]*(\d{4})\b/i', $chunk, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as [$zip, $pos]) {
            $line_no = substr_count(substr($chunk, 0, $pos), "\n");
            pds_import_put_field($ex, 'I', $line_no >= $perm_from ? 'permanent_zip' : 'residential_zip', $zip, $ex['source'] === 'ocr');
        }
    }
}

// =====================================================================
// Saving what was confirmed on the review page
// =====================================================================

/** Field key => its section key. */
function pds_import_field_sections(): array {
    $out = [];
    foreach (pds_import_sections() as $skey => $s) { foreach ($s['fields'] as $f) { $out[$f] = $skey; } }
    return $out;
}

/**
 * Comparable keys of the rows already in the PDS, per table (Section VI
 * from pds_learning_development), to spot imported rows that are there already.
 * @return array<string, true>
 */
function pds_import_existing_keys(array $pds): array {
    $keys = [];
    foreach (pds_import_sections() as $s) {
        foreach ($s['tables'] as $tkey) {
            $rows = $tkey === 'ld' ? $pds['ld'] : (array)($pds['data'][$tkey] ?? []);
            foreach ($rows as $r) { if (is_array($r)) { $keys[pds_import_row_key($tkey, $r)] = true; } }
        }
    }
    return $keys;
}

/**
 * A value as confirmed (and possibly edited) on the review page, cleaned
 * like an extracted one. $invalid counts values that were given but can't
 * be stored (e.g. a date that isn't one).
 */
function pds_import_confirmed_value(string $rule_key, array $def, string $raw, int &$invalid): string {
    $raw = mb_substr(trim($raw), 0, 500);
    if ($raw === '') { return ''; }
    [$v] = pds_import_normalize($rule_key, $def, $raw, 'text');
    if ($v === '' && !pds_import_is_na($raw)) { $invalid++; }
    return $v;
}

/**
 * Save the review form into the faculty member's PDS: checked fields are
 * filled in or replaced, checked rows are added (linked to the uploaded
 * document) -- nothing else is touched or removed. The previous version
 * is snapshotted first.
 * @return array{sections: array<string, array{fields: int, rows: int}>, invalid: int, over: int}
 */
function pds_import_apply(PDO $pdo, int $faculty_id, int $document_id, array $post): array {
    $defs = pds_import_field_defs();
    $section_of = pds_import_field_sections();
    $pds = pds_load($pdo, $faculty_id);
    $data = $pds['data'];
    $counts = [];
    $invalid = 0;
    $over = 0;

    foreach ((array)($post['use'] ?? []) as $key => $choice) {
        if ($choice !== 'new' || !is_string($key) || !isset($defs[$key], $section_of[$key])) { continue; }
        $v = pds_import_confirmed_value($key, $defs[$key], (string)($post['val'][$key] ?? ''), $invalid);
        if ($v === '' || (string)($data[$key] ?? '') === $v) { continue; }
        $data[$key] = $v;
        $counts[$section_of[$key]]['fields'] = ($counts[$section_of[$key]]['fields'] ?? 0) + 1;
    }

    $ld_new = [];
    $seen = [];
    foreach ((array)($post['rows'] ?? []) as $tkey => $list) {
        $cols = is_string($tkey) ? pds_import_columns($tkey) : [];
        if (!$cols) { continue; }
        foreach ((array)$list as $r) {
            if (!is_array($r) || empty($r['inc'])) { continue; }
            $cells = [];
            foreach ($cols as $ckey => $def) {
                $cells[$ckey] = pds_import_confirmed_value(pds_import_rule_key($tkey, $ckey), $def, (string)($r['cells'][$ckey] ?? ''), $invalid);
            }
            if (implode('', $cells) === '') { continue; }
            $k = pds_import_row_key($tkey, $cells);
            if (isset($seen[$k])) { continue; }   // the same row twice in one file
            $seen[$k] = true;
            if ($tkey === 'ld') {
                $ld_new[] = $cells;
            } else {
                if ($tkey === 'references' && count((array)($data['references'] ?? [])) >= 3) { $over++; continue; }   // the form has 3
                $data[$tkey][] = $cells + ['_source' => $document_id];   // shown as "Imported from uploaded PDS"
                $data[$tkey . '_na'] = '';
            }
            $sk = pds_import_table_section($tkey);
            $counts[$sk]['rows'] = ($counts[$sk]['rows'] ?? 0) + 1;
        }
    }
    if (!$counts) {
        return ['sections' => [], 'invalid' => $invalid, 'over' => $over];
    }
    if ($ld_new) { $data['ld_na'] = ''; }

    $pdo->beginTransaction();
    try {
        pds_snapshot($pdo, $faculty_id, "Before import from uploaded PDS (document #{$document_id})");
        pds_write_data($pdo, $faculty_id, $data, $document_id);
        foreach ($ld_new as $cells) {
            $clean = pds_clean_ld_row($cells);
            if ($clean !== null) { pds_insert_ld($pdo, $faculty_id, $clean, $document_id); }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $order = array_keys(pds_import_sections());
    uksort($counts, fn($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
    return ['sections' => $counts, 'invalid' => $invalid, 'over' => $over];
}

/** "Personal Information (8 fields), Learning and Development (5 entries added)" */
function pds_import_summary(array $sections): string {
    $labels = pds_import_sections();
    $parts = [];
    foreach ($sections as $skey => $c) {
        $what = [];
        if (!empty($c['fields'])) { $what[] = $c['fields'] . ' field' . ($c['fields'] === 1 ? '' : 's'); }
        if (!empty($c['rows'])) { $what[] = $c['rows'] . ' entr' . ($c['rows'] === 1 ? 'y' : 'ies') . ' added'; }
        $parts[] = $labels[$skey]['label'] . ' (' . implode(', ', $what) . ')';
    }
    return implode(', ', $parts);
}
