<?php
/**
 * OcrProcessor
 *
 * OCR + AI-style document categorization used by the Faculty "Submit
 * Document" portal (Figure 7 of the capstone paper) and by the
 * catch-up filing routine for requests left over from the old approval
 * workflow (file_outstanding_requests() in includes/functions.php).
 *
 * - Text is extracted with the Tesseract OCR engine (TESSERACT_BINARY_PATH,
 *   see config/config.php) for image scans, and with pdftotext / a
 *   rasterized first page for PDF scans, depending on what's installed.
 * - Categorization is a transparent keyword classifier against
 *   DOCUMENT_TYPE_KEYWORDS: each candidate type's keyword hits are
 *   counted and the highest-scoring type is suggested. This mirrors
 *   what the paper describes (Ch.3 3.1: "automated document
 *   categorization" module) without requiring a trained ML model.
 * - If no OCR binary is available, or extraction returns nothing, the
 *   faculty member is told to confirm the type manually instead of the
 *   system silently guessing.
 */
class OcrProcessor
{
    /**
     * Run OCR + classification on a scanned file.
     *
     * @return array{
     *   text: string,
     *   detected_type: ?string,
     *   scores: array<string,int>,
     *   matched_name: ?string,
     *   confidence_note: string
     * }
     */
    public static function process(string $filePath, string $facultyFullName): array
    {
        $text = self::extractText($filePath);
        [$detectedType, $scores] = self::classify($text);
        $detectedSubtype = $detectedType ? self::classifySubtype($detectedType, $text) : null;
        $matchedName = self::matchName($text, $facultyFullName);
        $confidenceNote = self::buildConfidenceNote($detectedType, $detectedSubtype, $scores, $matchedName, $text);

        return [
            'text'             => $text,
            'detected_type'    => $detectedType,
            'detected_subtype' => $detectedSubtype,
            'scores'           => $scores,
            'matched_name'     => $matchedName,
            'confidence_note'  => $confidenceNote,
            'period'           => self::extractPeriod($text),
            'training'         => self::extractTraining($text),
            'pds_fields'       => $detectedType === 'PDS' ? self::extractPdsFields($text) : [],
        ];
    }

    private static function extractText(string $filePath): string
    {
        if (!is_file($filePath)) {
            return '';
        }
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            if (self::binaryExists('pdftotext')) {
                $out = @shell_exec('pdftotext -layout ' . escapeshellarg($filePath) . ' - 2>/dev/null');
                if (is_string($out) && trim($out) !== '') {
                    return trim($out);
                }
            }
            // No text layer (scanned PDF) -- rasterize page 1 and OCR it.
            if (self::binaryExists('pdftoppm') && self::binaryExists(TESSERACT_BINARY_PATH)) {
                $tmpBase = sys_get_temp_dir() . '/ocr_' . bin2hex(random_bytes(4));
                @shell_exec('pdftoppm -jpeg -r 200 -f 1 -l 1 ' . escapeshellarg($filePath) . ' ' . escapeshellarg($tmpBase) . ' 2>/dev/null');
                $rendered = $tmpBase . '-1.jpg';
                if (!is_file($rendered)) {
                    $rendered = $tmpBase . '-01.jpg';
                }
                if (is_file($rendered)) {
                    $text = self::runTesseract($rendered);
                    @unlink($rendered);
                    return $text;
                }
            }
            return '';
        }

        // Image scan (jpg / jpeg / png / webp)
        if (self::binaryExists(TESSERACT_BINARY_PATH)) {
            return self::runTesseract($filePath);
        }

        return '';
    }

    private static function runTesseract(string $imagePath): string
    {
        $prepared = self::prepareImage($imagePath);
        $out = self::tesseract($prepared ?? $imagePath, '');
        if ($prepared !== null) {
            @unlink($prepared);
        }
        return is_string($out) ? trim($out) : '';
    }

    /** One tesseract run. OMP_THREAD_LIMIT=1: extra threads only slow it down on a small (shared-CPU) server. */
    private static function tesseract(string $imagePath, string $args): ?string
    {
        $env = DIRECTORY_SEPARATOR === '/' ? 'OMP_THREAD_LIMIT=1 ' : '';
        $out = @shell_exec($env . escapeshellcmd(TESSERACT_BINARY_PATH) . ' ' . escapeshellarg($imagePath) . ' stdout ' . $args . ' 2>/dev/null');
        return is_string($out) ? $out : null;
    }

    /**
     * Make a phone photo readable for tesseract, which ignores EXIF
     * orientation: iPhone photos are stored sideways with a "rotate me"
     * tag, so they were OCR'd sideways and came out as gibberish. Applies
     * the EXIF rotation, scales large photos down, converts to grayscale,
     * then lets tesseract's orientation detection (OSD) fix pages that are
     * still sideways or upside down. Needs ImageMagick; returns the path of
     * a temporary PNG, or null to OCR the original as-is.
     */
    private static function prepareImage(string $imagePath): ?string
    {
        if (!self::binaryExists('convert')) {
            return null;
        }
        $out = sys_get_temp_dir() . '/ocr_' . bin2hex(random_bytes(4)) . '.png';
        @shell_exec('convert ' . escapeshellarg($imagePath . '[0]') . ' -auto-orient -resize ' . escapeshellarg('2600x2600>')
            . ' -colorspace Gray -normalize ' . escapeshellarg($out) . ' 2>/dev/null');
        if (!is_file($out) || filesize($out) === 0) {
            @unlink($out);
            return null;
        }
        // "Rotate: 90" = turn 90 degrees clockwise to make the text upright
        $osd = self::tesseract($out, '--psm 0');
        if ($osd !== null && preg_match('/Rotate:\s*(90|180|270)\b/', $osd, $m)) {
            @shell_exec('convert ' . escapeshellarg($out) . ' -rotate ' . $m[1] . ' ' . escapeshellarg($out) . ' 2>/dev/null');
        }
        return $out;
    }

    private static function binaryExists(string $bin): bool
    {
        // TESSERACT_BINARY_PATH may be a full path (Windows/XAMPP) rather
        // than a bare command, so only probe with `command -v` when it
        // looks like a bare command name.
        if ($bin !== basename($bin)) {
            return is_file($bin);
        }
        $which = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
        return is_string($which) && trim($which) !== '';
    }

    /**
     * @return array{0: ?string, 1: array<string,int>}
     */
    private static function classify(string $text): array
    {
        $scores = [];
        $haystack = self::normalizeText($text);
        foreach (DOCUMENT_TYPE_KEYWORDS as $type => $keywords) {
            $scores[$type] = self::keywordScore($haystack, $keywords);
        }
        arsort($scores);
        $top = array_key_first($scores);
        $detected = ($top !== null && $scores[$top] > 0) ? $top : null;
        return [$detected, $scores];
    }

    /** Subtype within a category (e.g. Certificate -> Seminar / Training). */
    private static function classifySubtype(string $type, string $text): ?string
    {
        $options = DOCUMENT_SUBTYPE_KEYWORDS[$type] ?? null;
        if (!$options) {
            return null;
        }
        $haystack = self::normalizeText($text);
        $best = null;
        $bestScore = 0;
        foreach ($options as $subtype => $keywords) {
            $score = self::keywordScore($haystack, $keywords);
            if ($score > $bestScore) {
                $best = $subtype;
                $bestScore = $score;
            }
        }
        return $best ?? (document_categories()[$type]['default_subtype'] ?? null);
    }

    private static function normalizeText(string $text): string
    {
        return preg_replace('/\s+/', ' ', strtolower($text));
    }

    /**
     * Whole-word keyword hits; a multi-word phrase scores one point per
     * word since it's a much stronger signal than a lone word (and a lone
     * "tor" no longer matches inside "director" or "doctor").
     */
    private static function keywordScore(string $haystack, array $keywords): int
    {
        $score = 0;
        foreach ($keywords as $kw) {
            $kw = strtolower(trim($kw));
            if ($kw === '') { continue; }
            if (preg_match('/(?<![a-z0-9])' . preg_quote($kw, '/') . '(?![a-z0-9])/', $haystack)) {
                $score += count(preg_split('/\s+/', $kw));
            }
        }
        return $score;
    }

    // -----------------------------------------------------------------
    // Information extraction
    // -----------------------------------------------------------------

    private const MONTH = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sept?(?:ember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?';

    /**
     * Academic year + semester an FTA / IPCR covers, if printed on it.
     * @return array{academic_year: ?string, semester: ?int}
     */
    public static function extractPeriod(string $text): array
    {
        $ay = null;
        if (preg_match('/\b(20\d{2})\s*(?:-|–|—|\/)\s*(20\d{2}|\d{2})\b/', $text, $m)) {
            $end = strlen($m[2]) === 2 ? substr($m[1], 0, 2) . $m[2] : $m[2];
            if ((int)$end === (int)$m[1] + 1) {
                $ay = $m[1] . '-' . $end;
            }
        }
        $sem = null;
        if (preg_match('/\b(1st|first)\s+sem/i', $text)) {
            $sem = 1;
        } elseif (preg_match('/\b(2nd|second)\s+sem/i', $text)) {
            $sem = 2;
        } elseif (preg_match('/\b(summer|mid-?year)\b/i', $text)) {
            $sem = 3;
        }
        return ['academic_year' => $ay, 'semester' => $sem];
    }

    /**
     * Details of a seminar / training certificate for PDS Section VI (L&D).
     * Every value is a best guess the faculty member reviews before saving.
     * @return array{title: ?string, date_from: ?string, date_to: ?string, hours: ?string, ld_type: string, conducted_by: ?string}
     */
    public static function extractTraining(string $text): array
    {
        $flat = trim(preg_replace('/\s+/', ' ', $text));
        [$from, $to] = self::extractDateRange($flat);

        return [
            'title'        => self::extractTrainingTitle($text, $flat),
            'date_from'    => $from,
            'date_to'      => $to,
            'hours'        => self::extractHours($flat),
            'ld_type'      => self::extractLdType($flat),
            'conducted_by' => self::extractOrganizer($text),
        ];
    }

    private static function cleanValue(?string $s, int $max = 200): ?string
    {
        if ($s === null) { return null; }
        $s = preg_replace('/\s+/', ' ', $s);
        $s = (string)preg_replace('/^(?:[\s"\':;,.\-]|“|”|‘|’|–|—)+|(?:[\s"\':;,.\-]|“|”|‘|’|–|—)+$/', '', $s);
        if ($s === '' || mb_strlen($s) < 3) { return null; }
        return mb_substr($s, 0, $max);
    }

    private static function extractTrainingTitle(string $text, string $flat): ?string
    {
        // "Certificate of Completion – Microsoft Excel Training"
        if (preg_match('/certificate\s+of\s+(?:completion|attendance|participation|training|appreciation|recognition)\s*(?:-|–|—|:)\s*([^\n]{4,150})/i', $text, $m)) {
            return self::cleanValue($m[1]);
        }
        // Title in quotes
        if (preg_match('/["“]([^"”]{6,150})["”]/u', $flat, $m)) {
            return self::cleanValue($m[1]);
        }
        // "... has attended / participated in / completed the <title> held / conducted on ..."
        if (preg_match('/\b(?:attended|participated\s+in|completed|finished|joined|for\s+(?:having\s+)?(?:successfully\s+)?(?:completed|attended|participated\s+in))\s+(?:the\s+|a\s+|an\s+)?(.{6,150}?)(?=\s+(?:held|conducted|organized|organised|sponsored|given|presented|hosted|facilitated|offered|on|at|last|from|via|with|this)\b|[.\n]|$)/i', $flat, $m)) {
            return self::cleanValue($m[1]);
        }
        // Fall back to the first line that names a seminar / training
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if (preg_match('/\b(seminar|webinar|training|workshop|course|conference|symposium)\b/i', $line)
                && !preg_match('/^certificate\b/i', $line)) {
                return self::cleanValue($line);
            }
        }
        return null;
    }

    /** @return array{0: ?string, 1: ?string} Y-m-d strings */
    private static function extractDateRange(string $flat): array
    {
        $M = self::MONTH;
        $ymd = function ($month, $day, $year) {
            $ts = strtotime("$month $day $year");
            return $ts ? date('Y-m-d', $ts) : null;
        };

        // September 15-17, 2026  /  September 15 to 17, 2026
        if (preg_match("/\b$M\s+(\d{1,2})\s*(?:-|–|—|to|and)\s*(\d{1,2}),?\s+(\d{4})/i", $flat, $m)) {
            return [$ymd($m[1], $m[2], $m[4]), $ymd($m[1], $m[3], $m[4])];
        }
        // September 30 - October 2, 2026
        if (preg_match("/\b$M\s+(\d{1,2})\s*(?:-|–|—|to)\s*$M\s+(\d{1,2}),?\s+(\d{4})/i", $flat, $m)) {
            return [$ymd($m[1], $m[2], $m[5]), $ymd($m[3], $m[4], $m[5])];
        }
        // 15-17 September 2026
        if (preg_match("/\b(\d{1,2})\s*(?:-|–|—|to)\s*(\d{1,2})\s+$M,?\s+(\d{4})/i", $flat, $m)) {
            return [$ymd($m[3], $m[1], $m[4]), $ymd($m[3], $m[2], $m[4])];
        }
        // September 15, 2026
        if (preg_match("/\b$M\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})/i", $flat, $m)) {
            $d = $ymd($m[1], $m[2], $m[3]);
            return [$d, $d];
        }
        // 15th day of September, 2026  /  15 September 2026
        if (preg_match("/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:day\s+of\s+)?$M,?\s+(\d{4})/i", $flat, $m)) {
            $d = $ymd($m[2], $m[1], $m[3]);
            return [$d, $d];
        }
        // 09/15/2026
        if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(20\d{2})\b/', $flat, $m) && checkdate((int)$m[1], (int)$m[2], (int)$m[3])) {
            $d = sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
            return [$d, $d];
        }
        return [null, null];
    }

    private static function extractHours(string $flat): ?string
    {
        if (preg_match('/\((\d{1,3}(?:\.\d)?)\)\s*(?:training\s+|contact\s+|learning\s+)?(?:hours|hrs)\b/i', $flat, $m)
            || preg_match('/\b(\d{1,3}(?:\.\d)?)\s*-?\s*(?:training\s+|contact\s+|learning\s+)?(?:hours|hrs)\b/i', $flat, $m)) {
            return $m[1];
        }
        $words = ['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,
                  'twelve'=>12,'sixteen'=>16,'twenty'=>20,'twenty-four'=>24,'thirty'=>30,'thirty-two'=>32,'forty'=>40];
        if (preg_match('/\b(' . implode('|', array_map('preg_quote', array_keys($words))) . ')\s+(?:\(\d+\)\s*)?(?:training\s+)?hours\b/i', $flat, $m)) {
            return (string)$words[strtolower($m[1])];
        }
        return null;
    }

    private static function extractLdType(string $flat): string
    {
        foreach (['Managerial', 'Supervisory', 'Foundation'] as $type) {
            if (stripos($flat, $type) !== false) {
                return $type;
            }
        }
        return 'Technical';
    }

    private static function extractOrganizer(string $text): ?string
    {
        if (preg_match('/\b(?:conducted|organized|organised|sponsored|presented|hosted|facilitated|offered|given)\s+by\s*:?\s*(?:the\s+)?([^\n.,]{3,150})/i', $text, $m)
            || preg_match('/\b(?:organizer|organiser|sponsor|provider|conducted by)\s*:\s*([^\n]{3,150})/i', $text, $m)) {
            return self::cleanValue($m[1]);
        }
        return null;
    }

    /**
     * Best-effort read of Part I fields from an uploaded PDS. A PDS is a
     * dense form, so OCR text is often out of order -- anything not read
     * cleanly is simply left for the faculty member to fill in.
     * @return array<string,string> keys match pds_schema() field keys
     */
    public static function extractPdsFields(string $text): array
    {
        $patterns = [
            'surname'           => '/\bSURNAME\s*[:]?\s+([A-ZÑ][A-ZÑa-zñ .\'-]{1,60}?)(?=\s{2,}|\s+(?:FIRST|NAME)\b|\R|$)/u',
            'first_name'        => '/\bFIRST\s+NAME\s*[:]?\s+([A-ZÑ][A-ZÑa-zñ .\'-]{1,60}?)(?=\s{2,}|\s+(?:NAME\s+EXT|MIDDLE)\b|\R|$)/u',
            'middle_name'       => '/\bMIDDLE\s+NAME\s*[:]?\s+([A-ZÑ][A-ZÑa-zñ .\'-]{1,60}?)(?=\s{2,}|\R|$)/u',
            'date_of_birth'     => '/\bDATE\s+OF\s+BIRTH[^0-9\n]{0,30}(\d{1,2}\/\d{1,2}\/\d{4})/i',
            'place_of_birth'    => '/\bPLACE\s+OF\s+BIRTH\s*[:]?\s+([^\n]{3,80}?)(?=\s{2,}|\R|$)/i',
            'height'            => '/\bHEIGHT\s*\(?m?\)?\s*[:]?\s*(\d(?:\.\d{1,2})?)\b/i',
            'weight'            => '/\bWEIGHT\s*\(?kg\)?\s*[:]?\s*(\d{2,3}(?:\.\d)?)\b/i',
            'blood_type'        => '/\bBLOOD\s+TYPE\s*[:]?\s*((?:AB|A|B|O)\s?[+-]?)(?![A-Za-z])/i',
            'gsis_id'           => '/\bGSIS\s+ID\s+NO\.?\s*[:]?\s*([0-9][0-9 -]{4,20}[0-9])/i',
            'pagibig_id'        => '/\bPAG-?IBIG\s+ID\s+NO\.?\s*[:]?\s*([0-9][0-9 -]{4,20}[0-9])/i',
            'philhealth_no'     => '/\bPHILHEALTH\s+NO\.?\s*[:]?\s*([0-9][0-9 -]{4,20}[0-9])/i',
            'sss_no'            => '/\bSSS\s+NO\.?\s*[:]?\s*([0-9][0-9 -]{4,20}[0-9])/i',
            'tin_no'            => '/\bTIN\s+NO\.?\s*[:]?\s*([0-9][0-9 -]{4,20}[0-9])/i',
            'agency_employee_no'=> '/\bAGENCY\s+EMPLOYEE\s+NO\.?\s*[:]?\s*([A-Z0-9][A-Z0-9 -]{2,20})/i',
            'mobile_no'         => '/\bMOBILE\s+NO\.?\s*[:]?\s*(\+?[0-9][0-9 -]{8,15}[0-9])/i',
            'telephone_no'      => '/\bTELEPHONE\s+NO\.?\s*[:]?\s*(\(?[0-9][0-9() -]{5,15}[0-9])/i',
            'email'             => '/\b([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})\b/i',
        ];
        $fields = [];
        foreach ($patterns as $key => $re) {
            if (preg_match($re, $text, $m)) {
                $val = trim(preg_replace('/\s+/', ' ', $m[1]));
                if ($key === 'date_of_birth') {
                    [$mo, $d, $y] = array_map('intval', explode('/', $val));
                    $val = checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
                }
                if ($val !== '' && !in_array(strtoupper($val), ['N/A', 'NA', 'NONE'], true)) {
                    $fields[$key] = $val;
                }
            }
        }
        return $fields;
    }

    private static function matchName(string $text, string $facultyFullName): ?string
    {
        $name = trim($facultyFullName);
        if ($name === '' || $text === '') {
            return null;
        }
        $normalize = fn($s) => preg_replace('/\s+/', ' ', strtolower(trim((string)$s)));

        if (str_contains($normalize($text), $normalize($name))) {
            return $name;
        }
        // Loosen: match on the last word of the full name (surname).
        $parts = preg_split('/\s+/', $name);
        $lastName = end($parts);
        if ($lastName && mb_strlen($lastName) > 2 && str_contains($normalize($text), $normalize($lastName))) {
            return $lastName . ' (partial match)';
        }
        return null;
    }

    private static function buildConfidenceNote(?string $detectedType, ?string $detectedSubtype, array $scores, ?string $matchedName, string $text): string
    {
        if ($text === '') {
            return 'No text could be extracted automatically. Please select the document type manually and double-check the details before confirming.';
        }
        $notes = [];
        $notes[] = $detectedType
            ? 'Auto-categorized as ' . document_type_label($detectedType, $detectedSubtype)
              . " ({$scores[$detectedType]} keyword match" . ($scores[$detectedType] === 1 ? '' : 'es') . ' found).'
            : 'Could not confidently auto-categorize this document -- please confirm the type manually.';
        $notes[] = $matchedName
            ? "Faculty name \"{$matchedName}\" was found in the extracted text."
            : 'The faculty name was not found in the extracted text -- please double-check this is the correct document before confirming.';
        return implode(' ', $notes);
    }
}
