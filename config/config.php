<?php
/**
 * Application-wide configuration
 */

// Base URL -- reads APP_BASE_URL from the environment (set this in
// Render), falling back to the XAMPP htdocs path for local dev.
// No trailing slash.
define('BASE_URL', rtrim(getenv('APP_BASE_URL') ?: 'http://localhost/faculty201', '/'));

// Filesystem paths
define('ROOT_PATH', dirname(__DIR__));
define('TEMP_SCAN_PATH', ROOT_PATH . '/temp_scans');   // holds scans while the faculty member previews them, before upload
define('UPLOADS_PATH', ROOT_PATH . '/uploads');         // final filed repository: uploads/{faculty_id}/{document_type}/

// Allowed scan file types
define('ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'pdf']);
define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024); // 10MB

// Keyword hints used by the OCR classifier (OcrProcessor.php) to
// auto-categorize an upload. Matched case-insensitively as whole words
// against the extracted text; multi-word phrases count for more than
// single words because they're more specific. Keys must match
// document_categories() in includes/functions.php.
define('DOCUMENT_TYPE_KEYWORDS', [
    'PDS'         => ['personal data sheet', 'cs form no. 212', 'cs form 212', 'pds', 'family background', 'civil service eligibility',
                      'voluntary work', 'learning and development', 'name extension', 'residential address'],
    'Certificate' => ['certificate', 'certificate of completion', 'certificate of attendance', 'certificate of participation',
                      'certificate of appreciation', 'certificate of recognition', 'seminar', 'webinar', 'training', 'workshop',
                      'participant', 'awarded to', 'is hereby given', 'has attended', 'has participated'],
    'Diploma'     => ['diploma', 'conferred', 'conferred the degree', 'degree of', 'bachelor of', 'master of', 'doctor of',
                      'rights and privileges', 'board of regents', 'board of trustees'],
    'TOR'         => ['transcript of records', 'official transcript', 'transcript', 'tor', 'units earned', 'scholastic record',
                      'final grade', 'final grades', 'grading system', 'general weighted average', 'subject code', 'descriptive title'],
    'FTA'         => ['final teaching assignment', 'teaching assignment', 'teaching load', 'faculty load', 'fta', 'class schedule',
                      'no. of units', 'room', 'section', 'load units'],
    'IPCR'        => ['individual performance commitment and review', 'ipcr', 'performance commitment', 'success indicators',
                      'actual accomplishments', 'major final output', 'ratee', 'rater', 'final average rating'],
    'Contract'    => ['contract of service', 'contract of services', 'first party', 'second party', 'witnesseth',
                      'contractor', 'terms and conditions', 'part-time', 'part time'],
    'Affidavit'   => ['affidavit of undertaking', 'affidavit', 'undertaking', 'affiant', 'subscribed and sworn', 'undertake'],
    'Other'       => ['memorandum', 'memo', 'notice', 'promotion', 'special order', 'office order', 'notice of salary adjustment'],
]);

// Subtype hints, tried once the main category is known. The first
// subtype with the most hits wins; if none match, the category's
// fallback subtype (see document_categories()) is used.
define('DOCUMENT_SUBTYPE_KEYWORDS', [
    'Certificate' => [
        'Seminar'  => ['seminar', 'webinar', 'symposium', 'conference', 'forum', 'lecture', 'convention', 'summit'],
        'Training' => ['training', 'workshop', 'course', 'bootcamp', 'certificate of completion', 'hands-on'],
    ],
    'Diploma' => [
        'Doctorate' => ['doctor of philosophy', 'doctorate', 'doctor of', 'ph.d', 'phd', 'ed.d', 'd.i.t'],
        'Master'    => ['master of', 'masters', 'master in', 'm.a.', 'm.s.'],
        'Bachelor'  => ['bachelor of', 'bachelor in', 'baccalaureate'],
    ],
    'Other' => [
        'Memo'      => ['memorandum', 'memo'],
        'Notice'    => ['notice'],
        'Promotion' => ['promotion', 'promoted', 'reclassification', 'appointment'],
    ],
]);

// PDS Part VII (Learning and Development) rows that fit on one page of
// CS Form No. 212. When a faculty member's entries go past this, the
// printable PDS continues Part VII on an extra page.
define('PDS_PART7_ROWS_PER_PAGE', 21);

// Path to the tesseract binary. On XAMPP/Windows this is usually the full
// path to tesseract.exe; on macOS/Linux with Tesseract installed via
// brew/apt it is typically just "tesseract" (must be on PATH).
define('TESSERACT_BINARY_PATH', 'tesseract');

session_start();
