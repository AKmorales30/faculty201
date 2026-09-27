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
        $matchedName = self::matchName($text, $facultyFullName);
        $confidenceNote = self::buildConfidenceNote($detectedType, $scores, $matchedName, $text);

        return [
            'text'            => $text,
            'detected_type'   => $detectedType,
            'scores'          => $scores,
            'matched_name'    => $matchedName,
            'confidence_note' => $confidenceNote,
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
        $out = @shell_exec(
            escapeshellcmd(TESSERACT_BINARY_PATH) . ' ' . escapeshellarg($imagePath) . ' stdout 2>/dev/null'
        );
        return is_string($out) ? trim($out) : '';
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
        $haystack = strtolower($text);
        foreach (DOCUMENT_TYPE_KEYWORDS as $type => $keywords) {
            $hits = 0;
            foreach ($keywords as $kw) {
                if ($kw !== '' && str_contains($haystack, strtolower($kw))) {
                    $hits++;
                }
            }
            $scores[$type] = $hits;
        }
        arsort($scores);
        $top = array_key_first($scores);
        $detected = ($top !== null && $scores[$top] > 0) ? $top : null;
        return [$detected, $scores];
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

    private static function buildConfidenceNote(?string $detectedType, array $scores, ?string $matchedName, string $text): string
    {
        if ($text === '') {
            return 'No text could be extracted automatically. Please select the document type manually and double-check the details before confirming.';
        }
        $notes = [];
        $notes[] = $detectedType
            ? "Auto-categorized as {$detectedType} ({$scores[$detectedType]} keyword match" . ($scores[$detectedType] === 1 ? '' : 'es') . ' found).'
            : 'Could not confidently auto-categorize this document -- please confirm the type manually.';
        $notes[] = $matchedName
            ? "Faculty name \"{$matchedName}\" was found in the extracted text."
            : 'The faculty name was not found in the extracted text -- please double-check this is the correct document before confirming.';
        return implode(' ', $notes);
    }
}
