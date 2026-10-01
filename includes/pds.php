<?php
/**
 * Digital Personal Data Sheet (CS Form No. 212).
 *
 * Parts I-VI, VIII, questions 34-40, references and government ID are
 * stored as one JSON document per faculty member (pds_records.data).
 * Section VI (Learning and Development) lives in its own table,
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
            'sex'                => ['Sex at Birth', 'select', ['Male', 'Female']],
            'civil_status'       => ['Civil Status', 'select', ['Single', 'Married', 'Widowed', 'Separated', 'Other/s']],
            'civil_status_other' => ['If Other/s, please specify'],
            'height'             => ['Height (m)'],
            'weight'             => ['Weight (kg)'],
            'blood_type'         => ['Blood Type'],
            'umid_id'            => ['UMID ID No.'],
            'pagibig_id'         => ['PAG-IBIG ID No.'],
            'philhealth_no'      => ['PhilHealth No.'],
            'philsys_pcn'        => ['PhilSys Card Number (PCN)'],
            'tin_no'             => ['TIN No.'],
            'agency_employee_no' => ['Agency Employee No.'],
            'citizenship'        => ['Citizenship', 'select', ['Filipino', 'Dual Citizenship']],
            'dual_citizenship_by'=> ['If dual citizenship', 'select', ['by birth', 'by naturalization']],
            'dual_citizenship_country' => ['If dual citizenship, country'],
            'res_house'          => ['Residential: House/Block/Lot No.'],
            'res_street'         => ['Residential: Street'],
            'res_subdivision'    => ['Residential: Subdivision/Village'],
            'res_barangay'       => ['Residential: Barangay'],
            'res_city'           => ['Residential: City/Municipality'],
            'res_province'       => ['Residential: Province'],
            'residential_zip'    => ['Residential: ZIP Code'],
            'perm_house'         => ['Permanent: House/Block/Lot No.'],
            'perm_street'        => ['Permanent: Street'],
            'perm_subdivision'   => ['Permanent: Subdivision/Village'],
            'perm_barangay'      => ['Permanent: Barangay'],
            'perm_city'          => ['Permanent: City/Municipality'],
            'perm_province'      => ['Permanent: Province'],
            'permanent_zip'      => ['Permanent: ZIP Code'],
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
        'VII' => ['title' => 'Voluntary Work or Involvement in Civic / Non-Government / People / Voluntary Organizations', 'page' => 3, 'tables' => [
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
            'q34_details'  => ['34a. If YES, give details'],
            'q34b_details' => ['34b. If YES, give details'],
            'q35a'         => ['35a. Have you ever been found guilty of any administrative offense?', ...$yn],
            'q35a_details' => ['If YES, give details'],
            'q35b'         => ['35b. Have you been criminally charged before any court?', ...$yn],
            'q35b_details' => ['If YES, give details'],
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
            'q40b'         => ['40b. Are you a person with disability? (RA 7277, as amended)', ...$yn],
            'q40b_id'      => ['If YES, PWD ID No.'],
            'q40c'         => ['40c. Are you a solo parent? (RA 11861)', ...$yn],
            'q40c_id'      => ['If YES, Solo Parent ID No.'],
        ]],
        'R' => ['title' => 'References', 'page' => 4, 'tables' => [
            'references' => ['label' => 'References (person not related by consanguinity or affinity)', 'max' => 3, 'columns' => [
                'name'      => ['Name'],
                'address'   => ['Address'],
                'telephone' => ['Contact No. and/or Email'],
            ]],
        ]],
        'ID' => ['title' => 'Government Issued ID', 'page' => 4, 'fields' => [
            'gov_id_type'   => ['Government Issued ID (i.e. Passport, GSIS, SSS, PRC, Driver\'s License, etc.)'],
            'gov_id_number' => ['ID / License / Passport No.'],
            'gov_id_issued' => ['Date / Place of Issuance'],
        ]],
    ];
}

/** Section VI (L&D) columns (stored in pds_learning_development). */
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

/**
 * Input formats. `re` is written so it works unchanged as a JavaScript
 * RegExp and a PCRE pattern (no \u escapes, no "~"); `strip` characters
 * are removed before matching, `min` / `max` bound the numeric value.
 */
function pds_formats(): array {
    $year = (int)date('Y');
    return [
        'name'    => ['re' => "^[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ.' -]*$", 'msg' => 'may only contain letters, spaces, periods, apostrophes and hyphens'],
        'ext'     => ['re' => '^(JR|SR|I{1,3}|IV|V|VI{1,3}|IX|X)\.?$', 'flags' => 'i', 'msg' => 'must be a name extension such as Jr., Sr., II or III'],
        'height'  => ['re' => '^\d(\.\d{1,2})?$', 'min' => 0.5, 'max' => 2.5, 'msg' => 'must be in meters, e.g. 1.65'],
        'weight'  => ['re' => '^\d{1,3}(\.\d{1,2})?$', 'min' => 20, 'max' => 300, 'msg' => 'must be in kilograms, e.g. 58.5'],
        'blood'   => ['re' => '^(A|B|AB|O)[+-]$', 'flags' => 'i', 'msg' => 'must be one of A+, A-, B+, B-, AB+, AB-, O+ or O-'],
        'zip'     => ['re' => '^\d{4}$', 'msg' => 'must be a 4-digit ZIP code'],
        'mobile'  => ['re' => '^(09|\+639)\d{9}$', 'strip' => '[\s-]', 'msg' => 'must be a Philippine mobile number, e.g. 09171234567'],
        'phone'   => ['re' => '^\+?[0-9()]{7,15}$', 'strip' => '[\s-]', 'msg' => 'must be a valid telephone number (digits only, e.g. 02 8123 4567)'],
        'email'   => ['re' => '^[^\s@]+@[^\s@]+\.[^\s@]+$', 'msg' => 'must be a valid e-mail address'],
        'contact' => ['re' => '^(\+?[0-9()\s-]{7,20}|[^\s@]+@[^\s@]+\.[^\s@]+)$', 'msg' => 'must be a telephone number or an e-mail address'],
        'umid'    => ['re' => '^\d{12}$', 'strip' => '[\s-]', 'msg' => 'must be the 12-digit UMID CRN'],
        'pagibig' => ['re' => '^\d{12}$', 'strip' => '[\s-]', 'msg' => 'must be the 12-digit PAG-IBIG MID number'],
        'philhealth' => ['re' => '^\d{12}$', 'strip' => '[\s-]', 'msg' => 'must be the 12-digit PhilHealth number'],
        'pcn'     => ['re' => '^\d{16}$', 'strip' => '[\s-]', 'msg' => 'must be the 16-digit PhilSys Card Number'],
        'tin'     => ['re' => '^\d{9}(\d{3}|\d{5})?$', 'strip' => '[\s-]', 'msg' => 'must be a 9- or 12-digit TIN, e.g. 123-456-789-000'],
        'idno'    => ['re' => '^[A-Za-z0-9][A-Za-z0-9 -]*$', 'msg' => 'may only contain letters, digits, spaces and hyphens'],
        'year'    => ['re' => '^\d{4}$', 'min' => 1900, 'max' => $year, 'msg' => "must be a 4-digit year from 1900 to $year"],
        'money'   => ['re' => '^\d+(\.\d{1,2})?$', 'strip' => '[,\s]', 'min' => 0, 'msg' => 'must be an amount, e.g. 35,000.00'],
        'rating'  => ['re' => '^\d{1,3}(\.\d{1,2})?$', 'min' => 0, 'max' => 100, 'msg' => 'must be a rating from 0 to 100'],
        'hours'   => ['re' => '^\d+(\.\d)?$', 'min' => 0.5, 'max' => 9999, 'msg' => 'must be a number of hours greater than 0'],
    ];
}

/**
 * Validation rules, keyed by field ("surname") or "table.column"
 * ("education.school"; "ld.*" is Section VI). Fields not listed are
 * optional.
 *   req    required
 *   alt    required, but the user may tick this value instead ("N/A",
 *          "Present") -- the only way to answer N/A
 *   if     [field, [values]]: required only when that field has one of the values
 *   fmt    a pds_formats() key
 *   past   date / year may not be in the future
 *   after  column in the same row this one may not be earlier than
 */
function pds_rules(): array {
    $na = ['alt' => 'N/A'];
    $yes = fn($q) => ['if' => [$q, ['Yes']]];
    return [
        // I. Personal information
        'surname' => ['req' => 1, 'fmt' => 'name'], 'first_name' => ['req' => 1, 'fmt' => 'name'],
        'name_extension' => $na + ['fmt' => 'ext'], 'middle_name' => $na + ['fmt' => 'name'],
        'date_of_birth' => ['req' => 1, 'past' => 1], 'place_of_birth' => ['req' => 1],
        'sex' => ['req' => 1], 'civil_status' => ['req' => 1], 'civil_status_other' => ['if' => ['civil_status', ['Other/s']]],
        'height' => ['req' => 1, 'fmt' => 'height'], 'weight' => ['req' => 1, 'fmt' => 'weight'], 'blood_type' => $na + ['fmt' => 'blood'],
        'umid_id' => $na + ['fmt' => 'umid'], 'pagibig_id' => $na + ['fmt' => 'pagibig'], 'philhealth_no' => $na + ['fmt' => 'philhealth'],
        'philsys_pcn' => $na + ['fmt' => 'pcn'], 'tin_no' => $na + ['fmt' => 'tin'], 'agency_employee_no' => $na + ['fmt' => 'idno'],
        'citizenship' => ['req' => 1],
        'dual_citizenship_by' => ['if' => ['citizenship', ['Dual Citizenship']]],
        'dual_citizenship_country' => ['if' => ['citizenship', ['Dual Citizenship']]],
        'res_house' => $na, 'res_street' => $na, 'res_subdivision' => $na,
        'res_barangay' => ['req' => 1], 'res_city' => ['req' => 1], 'res_province' => ['req' => 1], 'residential_zip' => ['req' => 1, 'fmt' => 'zip'],
        'perm_house' => $na, 'perm_street' => $na, 'perm_subdivision' => $na,
        'perm_barangay' => ['req' => 1], 'perm_city' => ['req' => 1], 'perm_province' => ['req' => 1], 'permanent_zip' => ['req' => 1, 'fmt' => 'zip'],
        'telephone_no' => $na + ['fmt' => 'phone'], 'mobile_no' => ['req' => 1, 'fmt' => 'mobile'], 'email' => ['req' => 1, 'fmt' => 'email'],

        // II. Family background
        'spouse_surname' => $na + ['fmt' => 'name'], 'spouse_first_name' => $na + ['fmt' => 'name'],
        'spouse_name_extension' => $na + ['fmt' => 'ext'], 'spouse_middle_name' => $na + ['fmt' => 'name'],
        'spouse_occupation' => $na, 'spouse_employer' => $na, 'spouse_business_address' => $na, 'spouse_telephone' => $na + ['fmt' => 'phone'],
        'father_surname' => $na + ['fmt' => 'name'], 'father_first_name' => $na + ['fmt' => 'name'],
        'father_name_extension' => $na + ['fmt' => 'ext'], 'father_middle_name' => $na + ['fmt' => 'name'],
        'mother_surname' => $na + ['fmt' => 'name'], 'mother_first_name' => $na + ['fmt' => 'name'], 'mother_middle_name' => $na + ['fmt' => 'name'],
        'children.name' => ['req' => 1, 'fmt' => 'name'], 'children.date_of_birth' => ['req' => 1, 'past' => 1],

        // III. Education
        'education.level' => ['req' => 1], 'education.school' => ['req' => 1], 'education.degree' => ['req' => 1],
        'education.from' => ['req' => 1, 'fmt' => 'year'], 'education.to' => ['alt' => 'Present', 'fmt' => 'year', 'after' => 'from'],
        'education.units' => $na, 'education.year_graduated' => $na + ['fmt' => 'year', 'after' => 'from'], 'education.honors' => $na,

        // IV. Eligibility
        'eligibility.name' => ['req' => 1], 'eligibility.rating' => $na + ['fmt' => 'rating'],
        'eligibility.exam_date' => ['req' => 1, 'past' => 1], 'eligibility.exam_place' => ['req' => 1],
        'eligibility.license_number' => $na + ['fmt' => 'idno'], 'eligibility.license_valid' => $na,

        // V. Work experience
        'work.from' => ['req' => 1, 'past' => 1], 'work.to' => ['alt' => 'Present', 'after' => 'from'],
        'work.position' => ['req' => 1], 'work.agency' => ['req' => 1], 'work.salary' => $na + ['fmt' => 'money'],
        'work.salary_grade' => $na, 'work.status' => ['req' => 1], 'work.govt_service' => ['req' => 1],

        // VI. Learning and development
        'ld.title' => ['req' => 1], 'ld.date_from' => ['req' => 1, 'past' => 1], 'ld.date_to' => ['req' => 1, 'past' => 1, 'after' => 'date_from'],
        'ld.hours' => ['req' => 1, 'fmt' => 'hours'], 'ld.ld_type' => ['req' => 1], 'ld.conducted_by' => ['req' => 1],

        // VII. Voluntary work
        'voluntary.organization' => ['req' => 1], 'voluntary.from' => ['req' => 1, 'past' => 1], 'voluntary.to' => ['alt' => 'Present', 'after' => 'from'],
        'voluntary.hours' => $na + ['fmt' => 'hours'], 'voluntary.position' => ['req' => 1],

        // VIII. Other information
        'skills.value' => ['req' => 1], 'recognitions.value' => ['req' => 1], 'memberships.value' => ['req' => 1],

        // Questions 34-40: each needs an answer, details only when the answer is YES
        'q34a' => ['req' => 1], 'q34b' => ['req' => 1], 'q34_details' => $yes('q34a'), 'q34b_details' => $yes('q34b'),
        'q35a' => ['req' => 1], 'q35a_details' => $yes('q35a'),
        'q35b' => ['req' => 1], 'q35b_details' => $yes('q35b'), 'q35b_date' => $yes('q35b') + ['past' => 1], 'q35b_status' => $yes('q35b'),
        'q36' => ['req' => 1], 'q36_details' => $yes('q36'), 'q37' => ['req' => 1], 'q37_details' => $yes('q37'),
        'q38a' => ['req' => 1], 'q38a_details' => $yes('q38a'), 'q38b' => ['req' => 1], 'q38b_details' => $yes('q38b'),
        'q39' => ['req' => 1], 'q39_details' => $yes('q39'),
        'q40a' => ['req' => 1], 'q40a_details' => $yes('q40a'),
        'q40b' => ['req' => 1], 'q40b_id' => $yes('q40b') + ['fmt' => 'idno'], 'q40c' => ['req' => 1], 'q40c_id' => $yes('q40c') + ['fmt' => 'idno'],

        // References and government ID
        'references.name' => ['req' => 1, 'fmt' => 'name'], 'references.address' => ['req' => 1], 'references.telephone' => ['req' => 1, 'fmt' => 'contact'],
        'gov_id_type' => ['req' => 1], 'gov_id_number' => ['req' => 1, 'fmt' => 'idno'], 'gov_id_issued' => ['req' => 1],
    ];
}

/**
 * Row rules for repeating tables: `min` rows needed, or -- when `na` is
 * set -- the "N/A" box ticked instead (stored as data[<table>_na]).
 */
function pds_table_rules(): array {
    return [
        'children' => ['min' => 1, 'na' => 1], 'education' => ['min' => 1], 'eligibility' => ['min' => 1, 'na' => 1],
        'work' => ['min' => 1, 'na' => 1], 'ld' => ['min' => 1, 'na' => 1], 'voluntary' => ['min' => 1, 'na' => 1],
        'skills' => ['min' => 1, 'na' => 1], 'recognitions' => ['min' => 1, 'na' => 1], 'memberships' => ['min' => 1, 'na' => 1],
        'references' => ['min' => 3],
    ];
}

/** True for values that are a hand-typed "N/A" (n/a, NA, N.A., not applicable). */
function pds_is_na_text(string $v): bool {
    return (bool)preg_match('~^\s*(n\s*[/\\\\.]?\s*a\.?|not\s+applicable)\s*$~i', $v);
}

/** Does this rule make the field required, given the rest of the form? */
function pds_rule_required(array $rule, array $data): bool {
    if (!empty($rule['req']) || isset($rule['alt'])) { return true; }
    if (isset($rule['if'])) { return in_array(trim((string)($data[$rule['if'][0]] ?? '')), $rule['if'][1], true); }
    return false;
}

/**
 * Check one value. Returns an error message (without the label) or null.
 * Keep in step with checkValue() in faculty/pds.php.
 */
function pds_check_value(array $rule, string $type, string $v, array $data, array $row = []): ?string {
    $v = trim($v);
    if (isset($rule['alt']) && strcasecmp($v, $rule['alt']) === 0) { return null; }
    if ($v === '') { return pds_rule_required($rule, $data) ? 'is required' . (isset($rule['alt']) ? ' (or tick ' . $rule['alt'] . ')' : '') : null; }
    if (pds_is_na_text($v)) {
        return ($rule['alt'] ?? '') === 'N/A' ? null : 'cannot be N/A -- please enter the actual information';
    }
    if (isset($rule['fmt'])) {
        $f = pds_formats()[$rule['fmt']];
        $s = isset($f['strip']) ? preg_replace('~' . $f['strip'] . '~u', '', $v) : $v;
        $ok = preg_match('~' . $f['re'] . '~u' . ($f['flags'] ?? ''), $s)
           && (!isset($f['min']) || (float)$s >= $f['min']) && (!isset($f['max']) || (float)$s <= $f['max']);
        if (!$ok) { return $f['msg']; }
    }
    if ($type === 'date') {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return 'must be a valid date';
        }
        // one day of slack so a browser ahead of the server's time zone isn't rejected
        if (!empty($rule['past']) && $v > date('Y-m-d', strtotime('+1 day'))) { return 'cannot be a future date'; }
    }
    if (isset($rule['after'])) {
        $other = trim((string)($row[$rule['after']] ?? ''));
        if ($other !== '' && strlen($other) === strlen($v) && $v < $other) { return 'cannot be earlier than the start'; }
    }
    return null;
}

/**
 * Validate a submitted PDS against pds_rules() / pds_table_rules(). The
 * form checks the same rules section by section before it can be saved;
 * this is the server-side backstop.
 * @return string[] error messages
 */
function pds_validate(array $data, array $ld): array {
    $rules = pds_rules();
    $trules = pds_table_rules();
    $errors = [];
    $check_table = function (string $tkey, string $title, array $columns, array $rows) use (&$errors, $rules, $trules, $data) {
        $rows = array_values(array_filter($rows, fn($r) => is_array($r) && empty($r['_delete'])
            && implode('', array_map(fn($c) => trim((string)($r[$c] ?? '')), array_keys($columns))) !== ''));
        $tr = $trules[$tkey] ?? [];
        if ($rows && !empty($data[$tkey . '_na'])) { $errors[] = "$title: remove the entries or untick N/A."; }
        if (count($rows) < ($tr['min'] ?? 0) && !(!empty($tr['na']) && !empty($data[$tkey . '_na']))) {
            $errors[] = "$title: add at least " . $tr['min'] . ' entr' . ($tr['min'] === 1 ? 'y' : 'ies') . (!empty($tr['na']) ? ' or tick N/A' : '') . '.';
        }
        foreach ($rows as $i => $row) {
            foreach ($columns as $ckey => $cdef) {
                [$label, $type] = pds_field_def($cdef);
                if (!isset($rules["$tkey.$ckey"])) { continue; }
                $msg = pds_check_value($rules["$tkey.$ckey"], $type, (string)($row[$ckey] ?? ''), $data, $row);
                if ($msg) { $errors[] = "$title, row " . ($i + 1) . ": $label $msg."; }
            }
        }
    };
    foreach (pds_schema() as $part_key => $part) {
        if ($part_key === 'VII') { $check_table('ld', 'Learning and Development', pds_ld_columns(), $ld); }
        foreach ($part['fields'] ?? [] as $key => $def) {
            [$label, $type] = pds_field_def($def);
            if (!isset($rules[$key])) { continue; }
            $msg = pds_check_value($rules[$key], $type, (string)($data[$key] ?? ''), $data);
            if ($msg) { $errors[] = "$label $msg."; }
        }
        foreach ($part['tables'] ?? [] as $tkey => $table) {
            $check_table($tkey, $table['label'], $table['columns'], (array)($data[$tkey] ?? []));
        }
    }
    return $errors;
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

/** L&D rows in entry order -- new entries go at the end (next sheet). */
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

/** Mark the PDS as updated (e.g. after an L&D entry was added). */
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
    $rules = pds_rules();
    // A ticked N/A / Present always reaches the database spelled the same way
    $value = function (string $rkey, $v) use ($rules): string {
        $v = mb_substr(trim((string)$v), 0, 500);
        $alt = $rules[$rkey]['alt'] ?? null;
        if ($alt !== null && (strcasecmp($v, $alt) === 0 || ($alt === 'N/A' && pds_is_na_text($v)))) { return $alt; }
        return $v;
    };
    $clean = [];
    foreach (pds_schema() as $part) {
        foreach ($part['fields'] ?? [] as $key => $def) {
            $clean[$key] = $value($key, $input[$key] ?? '');
        }
        foreach ($part['tables'] ?? [] as $tkey => $table) {
            $rows = [];
            foreach ((array)($input[$tkey] ?? []) as $row) {
                if (!is_array($row)) { continue; }
                $r = [];
                foreach ($table['columns'] as $ckey => $cdef) {
                    $r[$ckey] = $value("$tkey.$ckey", $row[$ckey] ?? '');
                }
                if (implode('', $r) !== '') { $rows[] = $r; }
            }
            if (!empty($table['max'])) { $rows = array_slice($rows, 0, $table['max']); }
            $clean[$tkey] = $rows;
            $clean[$tkey . '_na'] = !$rows && !empty($input[$tkey . '_na']) ? '1' : '';
        }
    }
    // Section VI rows live in their own table; only its N/A box is kept here
    $clean['ld_na'] = !empty($input['ld_na']) ? '1' : '';
    return $clean;
}

/** Sanitize one L&D row; returns null if it has no title. */
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
 * Save the whole PDS from the edit form. L&D rows carry their
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
 * Add a seminar / training certificate to Section VI (L&D). Appends a new
 * row -- existing entries are never changed -- and returns where it lands
 * on the printed form ("page 3", "continuation sheet C5", ...), or null
 * if the entry had no title.
 */
function pds_add_training(PDO $pdo, int $faculty_id, array $entry, int $document_id): ?string {
    $clean = pds_clean_ld_row($entry);
    if ($clean === null) { return null; }
    pds_insert_ld($pdo, $faculty_id, $clean, $document_id);
    pds_touch($pdo, $faculty_id);
    $count = count(pds_ld_rows($pdo, $faculty_id));
    return pds_ld_sheet_label(count(pds_part7_pages(array_fill(0, $count, []))));
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
 * Split Section VI (L&D) into printed sheets exactly as the official form
 * holds them: the first PDS_LD_ROWS_PAGE3 entries on page 3 (sheet C3),
 * then PDS_LD_ROWS_CONTINUATION per continuation sheet C5. Always returns
 * at least one (possibly empty) chunk.
 * @return array<int, array> list of row chunks
 */
function pds_part7_pages(array $ld_rows): array {
    $chunks = [array_slice($ld_rows, 0, PDS_LD_ROWS_PAGE3)];
    foreach (array_chunk(array_slice($ld_rows, PDS_LD_ROWS_PAGE3), PDS_LD_ROWS_CONTINUATION) as $c) { $chunks[] = $c; }
    return $chunks;
}

/** Where the n-th (1-based) L&D chunk prints: "page 3", "continuation sheet C5", "continuation sheet C5 (2)", ... */
function pds_ld_sheet_label(int $n): string {
    if ($n <= 1) { return 'page 3'; }
    return 'continuation sheet C5' . ($n > 2 ? ' (' . ($n - 1) . ')' : '');
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
