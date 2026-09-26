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
define('TEMP_SCAN_PATH', ROOT_PATH . '/temp_scans');   // holds unconfirmed submissions
define('UPLOADS_PATH', ROOT_PATH . '/uploads');         // final filed repository: uploads/{faculty_id}/{document_type}/

// Allowed scan file types
define('ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'pdf']);
define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024); // 10MB

// Keyword hints used by the OCR classifier (OcrProcessor.php) to confirm
// document type / disambiguate when a faculty member submits without
// certainty. Matched case-insensitively against extracted OCR text.
define('DOCUMENT_TYPE_KEYWORDS', [
    'TOR'         => ['transcript of records', 'transcript', 'tor', 'units earned', 'scholastic record'],
    'Diploma'     => ['diploma', 'conferred the degree', 'bachelor of', 'master of', 'doctor of'],
    'Certificate' => ['certificate', 'certification', 'seminar', 'training', 'attendance', 'participant'],
]);

// Path to the tesseract binary. On XAMPP/Windows this is usually the full
// path to tesseract.exe; on macOS/Linux with Tesseract installed via
// brew/apt it is typically just "tesseract" (must be on PATH).
define('TESSERACT_BINARY_PATH', 'tesseract');

session_start();
