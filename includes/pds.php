<?php
/**
 * Digital Personal Data Sheet (CS Form No. 212).
 *
 * Parts I-VI, VIII, questions 34-40, references and government ID are
 * stored as one JSON document per faculty member (pds_records.data).
 * Part VII (Learning and Development) lives in its own table,
 * pds_learning_development, because rows are added automatically from
 * uploaded seminar / training certificates and each row remembers the
 * certificate it came from.
 *
 * Whenever the PDS is changed, the previous version is copied into
 * pds_snapshots first, so saving never loses earlier information.
 */
require_once __DIR__ . '/functions.php';

/**
 * Form structure. Each part has scalar `fields` and/or repeating
 * `tables`. A field / column is [label, type, options]; type is text,
 * date, number, select or yesno. `page` is the page of the printed form
 * the part appears on.
 */
function pds_schema(): array {
    $yn = ['yesno'];
    return [
        'I' => ['title' => 'Personal Information', 'page' => 1, 'fields' => [
            'surname'            => ['Surname'],
            'first_name'         => ['First Name'],
            'name_extension'     => ['Name Extension (Jr., Sr.)'],
            'middle_name'        => ['Middle Name'],
            'date_of_birth'      => ['Date of Birth', 'date'],
            'place_of_birth'     => ['Place of Birth'],
            'sex'                => ['Sex', 'select', ['Male', 'Female']],
            'civil_status'       => ['Civil Status', 'select', ['Single', 'Married', 'Widowed', 'Separated', 'Other']],
            'height'             => ['Height (m)'],
            'weight'             => ['Weight (kg)'],
            'blood_type'         => ['Blood Type'],
            'gsis_id'            => ['GSIS ID No.'],
            'pagibig_id'         => ['PAG-IBIG ID No.'],
            'philhealth_no'      => ['PhilHealth No.'],
            'sss_no'             => ['SSS No.'],
            'tin_no'             => ['TIN No.'],
            'agency_employee_no' => ['Agency Employee No.'],
            'citizenship'        => ['Citizenship', 'select', ['Filipino', 'Dual Citizenship']],
            'dual_citizenship'   => ['If dual citizenship: by birth / by naturalization, country'],
            'residential_address'=> ['Residential Address'],
            'residential_zip'    => ['Residential ZIP Code'],
            'permanent_address'  => ['Permanent Address'],
            'permanent_zip'      => ['Permanent ZIP Code'],
            'telephone_no'       => ['Telephone No.'],
            'mobile_no'          => ['Mobile No.'],
            'email'              => ['E-mail Address'],
        ]],
        'II' => ['title' => 'Family Background', 'page' => 1, 'fields' => [
            'spouse_surname'          => ["Spouse's Surname"],
            'spouse_first_name'       => ["Spouse's First Name"],
            'spouse_name_extension'   => ["Spouse's Name Extension"],
            'spouse_middle_name'      => ["Spouse's Middle Name"],
            'spouse_occupation'       => ['Occupation'],
            'spouse_employer'         => ['Employer / Business Name'],
            'spouse_business_address' => ['Business Address'],
            'spouse_telephone'        => ['Telephone No.'],
            'father_surname'          => ["Father's Surname"],
            'father_first_name'       => ["Father's First Name"],
            'father_name_extension'   => ["Father's Name Extension"],
            'father_middle_name'      => ["Father's Middle Name"],
            'mother_surname'          => ["Mother's Maiden Surname"],
            'mother_first_name'       => ["Mother's First Name"],
            'mother_middle_name'      => ["Mother's Middle Name"],
        ], 'tables' => [
            'children' => ['label' => 'Name of Children', 'columns' => [
                'name'          => ['Full Name'],
                'date_of_birth' => ['Date of Birth', 'date'],
            ]],
        ]],
        'III' => ['title' => 'Educational Background', 'page' => 1, 'tables' => [
            'education' => ['label' => 'Schools Attended', 'columns' => [
                'level'          => ['Level', 'select', ['Elementary', 'Secondary', 'Vocational / Trade Course', 'College', 'Graduate Studies']],
                'school'         => ['Name of School'],
                'degree'         => ['Basic Education / Degree / Course'],
                'from'           => ['From (Year)'],
                'to'             => ['To (Year)'],
                'units'          => ['Highest Level / Units Earned'],
                'year_graduated' => ['Year Graduated'],
                'honors'         => ['Scholarship / Academic Honors'],
            ]],
        ]],
        'IV' => ['title' => 'Civil Service Eligibility', 'page' => 2, 'tables' => [
            'eligibility' => ['label' => 'Eligibility', 'columns' => [
                'name'           => ['Career Service / RA 1080 (Board / Bar) / CES / CSEE'],
                'rating'         => ['Rating'],
                'exam_date'      => ['Date of Examination / Conferment', 'date'],
                'exam_place'     => ['Place of Examination / Conferment'],
                'license_number' => ['License Number'],
                'license_valid'  => ['License Valid Until', 'date'],
            ]],
        ]],
        'V' => ['title' => 'Work Experience', 'page' => 2, 'tables' => [
            'work' => ['label' => 'Work Experience (most recent first)', 'columns' => [
                'from'          => ['From', 'date'],
                'to'            => ['To', 'date'],
                'position'      => ['Position Title'],
                'agency'        => ['Department / Agency / Office / Company'],
                'salary'        => ['Monthly Salary'],
                'salary_grade'  => ['Salary / Job / Pay Grade & Step'],
                'status'        => ['Status of Appointment'],
                'govt_service'  => ["Gov't Service", 'select', ['Y', 'N']],
            ]],
        ]],
        'VI' => ['title' => 'Voluntary Work or Involvement in Civic / Non-Government / People / Voluntary Organizations', 'page' => 3, 'tables' => [
            'voluntary' => ['label' => 'Voluntary Work', 'columns' => [
                'organization' => ['Name & Address of Organization'],
                'from'         => ['From', 'date'],
                'to'           => ['To', 'date'],
                'hours'        => ['Number of Hours'],
                'position'     => ['Position / Nature of Work'],
            ]],
        ]],
        'VIII' => ['title' => 'Other Information', 'page' => 3, 'tables' => [
            'skills'       => ['label' => 'Special Skills and Hobbies', 'columns' => ['value' => ['Skill / Hobby']]],
            'recognitions' => ['label' => 'Non-Academic Distinctions / Recognition', 'columns' => ['value' => ['Distinction / Recognition']]],
            'memberships'  => ['label' => 'Membership in Association / Organization', 'columns' => ['value' => ['Association / Organization']]],
        ]],
        'Q' => ['title' => 'Questions 34 to 40', 'page' => 4, 'fields' => [
            'q34a'         => ['34a. Related by consanguinity or affinity to the appointing / recommending authority, chief of bureau or office, or immediate supervisor -- within the third degree?', ...$yn],
            'q34b'         => ['34b. ...within the fourth degree (for Local Government Unit - Career Employees)?', ...$yn],
            'q34_details'  => ['If YES, give details'],
            'q35a'         => ['35a. Have you ever been found guilty of any administrative offense?', ...$yn],
            'q35a_details' => ['If YES, give details'],
            'q35b'         => ['35b. Have you been criminally charged before any court?', ...$yn],
            'q35b_date'    => ['If YES, date filed', 'date'],
            'q35b_status'  => ['If YES, status of case/s'],
            'q36'          => ['36. Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?', ...$yn],
            'q36_details'  => ['If YES, give details'],
            'q37'          => ['37. Have you ever been separated from the service (resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out) in the public or private sector?', ...$yn],
            'q37_details'  => ['If YES, give details'],
            'q38a'         => ['38a. Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?', ...$yn],
            'q38a_details' => ['If YES, give details'],
            'q38b'         => ['38b. Have you resigned from the government service during the three (3)-month period before the last election to promote / actively campaign for a national or local candidate?', ...$yn],
            'q38b_details' => ['If YES, give details'],
            'q39'          => ['39. Have you acquired the status of an immigrant or permanent resident of another country?', ...$yn],
            'q39_details'  => ['If YES, give details (country)'],
            'q40a'         => ['40a. Are you a member of any indigenous group? (RA 8371)', ...$yn],
            'q40a_details' => ['If YES, please specify'],
            'q40b'         => ['40b. Are you a person with disability? (RA 7277)', ...$yn],
            'q40b_id'      => ['If YES, PWD ID No.'],
            'q40c'         => ['40c. Are you a solo parent? (RA 8972)', ...$yn],
            'q40c_id'      => ['If YES, Solo Parent ID No.'],
        ]],
        'R' => ['title' => 'References', 'page' => 4, 'tables' => [
            'references' => ['label' => 'References (person not related by consanguinity or affinity)', 'max' => 3, 'columns' => [
                'name'      => ['Name'],
                'address'   => ['Address'],
                'telephone' => ['Tel. No.'],
            ]],
        ]],
        'ID' => ['title' => 'Government Issued ID', 'page' => 4, 'fields' => [
            'gov_id_type'   => ['Government Issued ID (e.g. Passport, GSIS, SSS, PRC, Driver\'s License)'],
            'gov_id_number' => ['ID / License / Passport No.'],
            'gov_id_issued' => ['Date / Place of Issuance'],
        ]],
    ];
}

/** Part VII columns (stored in pds_learning_development). */
function pds_ld_columns(): array {
    return [
        'title'        => ['Title of Learning and Development Interventions / Training Programs'],
        'date_from'    => ['From', 'date'],
        'date_to'      => ['To', 'date'],
        'hours'        => ['Number of Hours', 'number'],
        'ld_type'      => ['Type of L&D', 'select', ['Managerial', 'Supervisory', 'Technical', 'Foundation', 'Other']],
        'conducted_by' => ['Conducted / Sponsored By'],
    ];
}

/** Normalize a field definition to [label, type, options]. */
function pds_field_def(array $def): array {
    return [$def[0], $def[1] ?? 'text', $def[2] ?? []];
}

/**
 * Load a faculty member's PDS.
 * @return array{exists: bool, data: array, ld: array, updated_at: ?string, source_document_id: ?int}
 */
function pds_load(PDO $pdo, int $faculty_id): array {
    $stmt = $pdo->prepare("SELECT * FROM pds_records WHERE faculty_id = ?");
    $stmt->execute([$faculty_id]);
    $row = $stmt->fetch();

    return [
        'exists'             => (bool)$row,
        'data'               => $row ? (json_decode($row['data'], true) ?: []) : [],
        'ld'                 => pds_ld_rows($pdo, $faculty_id),
        'updated_at'         => $row['updated_at'] ?? null,
        'source_document_id' => isset($row['source_document_id']) ? (int)$row['source_document_id'] : null,
    ];
}

/** Part VII rows in entry order -- new entries go at the end (next page). */
function pds_ld_rows(PDO $pdo, int $faculty_id): array {
    $stmt = $pdo->prepare("SELECT * FROM pds_learning_development WHERE faculty_id = ? ORDER BY ld_id ASC");
    $stmt->execute([$faculty_id]);
    return $stmt->fetchAll();
}

/** Copy the current PDS (all parts incl. VII) into pds_snapshots. */
function pds_snapshot(PDO $pdo, int $faculty_id, string $reason): void {
    $current = pds_load($pdo, $faculty_id);
    if (!$current['exists'] && !$current['ld']) {
        return; // nothing to preserve yet
    }
    $ld = array_map(fn($r) => array_intersect_key($r, pds_ld_columns()), $current['ld']);
    $pdo->prepare("INSERT INTO pds_snapshots (faculty_id, data, reason) VALUES (?, ?, ?)")
        ->execute([$faculty_id, json_encode(['data' => $current['data'], 'ld' => $ld]), mb_substr($reason, 0, 150)]);
}

/** Write Parts I-VI, VIII etc. (creates the record if needed). */
function pds_write_data(PDO $pdo, int $faculty_id, array $data, ?int $source_document_id = null): void {
    $pdo->prepare(
        "INSERT INTO pds_records (faculty_id, data, source_document_id) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE data = VALUES(data), source_document_id = COALESCE(VALUES(source_document_id), source_document_id), updated_at = CURRENT_TIMESTAMP"
    )->execute([$faculty_id, json_encode($data), $source_document_id]);
}

/** Mark the PDS as updated (e.g. after Part VII changed). */
function pds_touch(PDO $pdo, int $faculty_id): void {
    $pdo->prepare(
        "INSERT INTO pds_records (faculty_id, data) VALUES (?, '{}')
         ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP"
    )->execute([$faculty_id]);
}

/**
 * Keep only known keys from submitted form data, trimmed.
 */
function pds_clean_input(array $input): array {
    $clean = [];
    foreach (pds_schema() as $part) {
        foreach ($part['fields'] ?? [] as $key => $def) {
            $clean[$key] = mb_substr(trim((string)($input[$key] ?? '')), 0, 500);
        }
        foreach ($part['tables'] ?? [] as $tkey => $table) {
            $rows = [];
            foreach ((array)($input[$tkey] ?? []) as $row) {
                if (!is_array($row)) { continue; }
                $r = [];
                foreach ($table['columns'] as $ckey => $cdef) {
                    $r[$ckey] = mb_substr(trim((string)($row[$ckey] ?? '')), 0, 500);
                }
                if (implode('', $r) !== '') { $rows[] = $r; }
            }
            if (!empty($table['max'])) { $rows = array_slice($rows, 0, $table['max']); }
            $clean[$tkey] = $rows;
        }
    }
    return $clean;
}

/** Sanitize one Part VII row; returns null if it has no title. */
function pds_clean_ld_row(array $row): ?array {
    $title = mb_substr(trim((string)($row['title'] ?? '')), 0, 255);
    if ($title === '') { return null; }
    $date = function ($v) {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    };
    $hours = trim((string)($row['hours'] ?? ''));
    $types = pds_ld_columns()['ld_type'][2];
    return [
        'title'        => $title,
        'date_from'    => $date($row['date_from'] ?? ''),
        'date_to'      => $date($row['date_to'] ?? '') ?? $date($row['date_from'] ?? ''),
        'hours'        => is_numeric($hours) ? round((float)$hours, 1) : null,
        'ld_type'      => in_array($row['ld_type'] ?? '', $types, true) ? $row['ld_type'] : 'Technical',
        'conducted_by' => mb_substr(trim((string)($row['conducted_by'] ?? '')), 0, 255) ?: null,
    ];
}

/**
 * Save the whole PDS from the edit form. Part VII rows carry their
 * ld_id; only rows the faculty member explicitly removed (_delete=1) are
 * deleted -- a row missing from the submission is left alone -- and new
 * rows are added. The previous version is snapshotted first.
 */
function pds_save(PDO $pdo, int $faculty_id, array $data_input, array $ld_input): void {
    $data = pds_clean_input($data_input);

    $pdo->beginTransaction();
    try {
        pds_snapshot($pdo, $faculty_id, 'Before edit on ' . date('M j, Y g:ia'));
        pds_write_data($pdo, $faculty_id, $data);

        $existing = array_column(pds_ld_rows($pdo, $faculty_id), null, 'ld_id');
        foreach ($ld_input as $row) {
            if (!is_array($row)) { continue; }
            $id = (int)($row['ld_id'] ?? 0);
            if (!empty($row['_delete'])) {
                if ($id && isset($existing[$id])) {
                    $pdo->prepare("DELETE FROM pds_learning_development WHERE ld_id=? AND faculty_id=?")->execute([$id, $faculty_id]);
                }
                continue;
            }
            $clean = pds_clean_ld_row($row);
            if ($clean === null) { continue; }
            if ($id && isset($existing[$id])) {
                $pdo->prepare(
                    "UPDATE pds_learning_development SET title=?, date_from=?, date_to=?, hours=?, ld_type=?, conducted_by=?
                     WHERE ld_id=? AND faculty_id=?"
                )->execute([...array_values($clean), $id, $faculty_id]);
            } else {
                pds_insert_ld($pdo, $faculty_id, $clean, null);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function pds_insert_ld(PDO $pdo, int $faculty_id, array $clean, ?int $source_document_id): int {
    $pdo->prepare(
        "INSERT INTO pds_learning_development (faculty_id, title, date_from, date_to, hours, ld_type, conducted_by, source_document_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$faculty_id, $clean['title'], $clean['date_from'], $clean['date_to'], $clean['hours'],
                $clean['ld_type'], $clean['conducted_by'], $source_document_id]);
    return (int)$pdo->lastInsertId();
}

/**
 * Add a seminar / training certificate to Part VII. Appends a new row --
 * existing entries are never changed -- and returns the Part VII page
 * number it landed on, or null if the entry had no title.
 */
function pds_add_training(PDO $pdo, int $faculty_id, array $entry, int $document_id): ?int {
    $clean = pds_clean_ld_row($entry);
    if ($clean === null) { return null; }
    pds_insert_ld($pdo, $faculty_id, $clean, $document_id);
    pds_touch($pdo, $faculty_id);
    $count = count(pds_ld_rows($pdo, $faculty_id));
    return pds_part7_page_number((int)ceil($count / PDS_PART7_ROWS_PER_PAGE));
}

/**
 * Fill empty fields of the digital PDS from an uploaded PDS file.
 * Fields the faculty member already filled in are never overwritten.
 * Returns the number of fields filled.
 */
function pds_import_upload(PDO $pdo, int $faculty_id, array $fields, int $document_id): int {
    $current = pds_load($pdo, $faculty_id);
    $data = $current['data'];
    $filled = 0;
    foreach ($fields as $key => $value) {
        if (trim((string)($data[$key] ?? '')) === '' && trim((string)$value) !== '') {
            $data[$key] = mb_substr((string)$value, 0, 500);
            $filled++;
        }
    }
    if ($filled > 0) {
        pds_snapshot($pdo, $faculty_id, 'Before import from uploaded PDS');
    }
    pds_write_data($pdo, $faculty_id, $data, $document_id);
    return $filled;
}

/**
 * Split Part VII into printed pages: the first chunk goes on page 3 of
 * the form (with Parts VI and VIII); every further chunk becomes its own
 * continuation page. Always returns at least one (possibly empty) page.
 * @return array<int, array> list of row chunks
 */
function pds_part7_pages(array $ld_rows): array {
    $chunks = array_chunk($ld_rows, PDS_PART7_ROWS_PER_PAGE);
    return $chunks ?: [[]];
}

/** Printed page number of the n-th (1-based) Part VII page: 3, 4, 5, ... */
function pds_part7_page_number(int $n): int {
    return 2 + max(1, $n);
}

/**
 * Annual update status: the PDS counts as current for this year if the
 * digital PDS was saved this year or a PDS file for this year was uploaded.
 * @return array{current: bool, year: int, updated_at: ?string}
 */
function pds_status(PDO $pdo, int $faculty_id): array {
    $year = (int)date('Y');
    $stmt = $pdo->prepare("SELECT updated_at FROM pds_records WHERE faculty_id = ?");
    $stmt->execute([$faculty_id]);
    $updated = $stmt->fetchColumn() ?: null;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE faculty_id = ? AND document_type = 'PDS' AND (period_year = ? OR YEAR(filed_at) = ?)");
    $stmt->execute([$faculty_id, $year, $year]);
    $uploaded_this_year = (int)$stmt->fetchColumn() > 0;

    return [
        'current'    => $uploaded_this_year || ($updated && (int)date('Y', strtotime($updated)) === $year),
        'year'       => $year,
        'updated_at' => $updated,
    ];
}

function pds_snapshots(PDO $pdo, int $faculty_id): array {
    $stmt = $pdo->prepare("SELECT snapshot_id, reason, created_at FROM pds_snapshots WHERE faculty_id = ? ORDER BY created_at DESC, snapshot_id DESC LIMIT 30");
    $stmt->execute([$faculty_id]);
    return $stmt->fetchAll();
}
