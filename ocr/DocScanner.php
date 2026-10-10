<?php
/**
 * DocScanner -- turns a photo of a paper document into a clean page.
 *
 * Runs ocr/docscan.py (Python + OpenCV): finds the sheet's four corners,
 * crops away the table / background, corrects the perspective, evens out
 * the lighting and turns the page upright. The result is a JPEG (used for
 * OCR and the preview) and a one-page PDF, which is what gets filed in
 * the 201 file instead of the original photo.
 *
 * If Python / OpenCV aren't installed (e.g. a plain XAMPP setup) scan()
 * returns null and the upload is handled as before.
 */
class DocScanner
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * @param string     $photo   absolute path of the uploaded photo
     * @param string     $outBase absolute path prefix; writes $outBase.jpg, .pdf and _orig.jpg
     * @param array|null $corners [[x,y] x4] in photo pixels to crop by hand, or null to detect
     * @param bool       $whole   keep the whole photo (no crop)
     * @return array|null {cropped, corners, width, height, src_width, src_height, rotated} or null on failure
     */
    public static function scan(string $photo, string $outBase, ?array $corners = null, bool $whole = false): ?array
    {
        $script = __DIR__ . '/docscan.py';
        if (!is_file($photo) || !is_file($script)) {
            return null;
        }
        require_once __DIR__ . '/OcrProcessor.php';
        OcrProcessor::useToolDirs();   // python3 / tesseract may not be on the web server's PATH
        $cmd = escapeshellcmd(DOCSCAN_PYTHON) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($photo) . ' ' . escapeshellarg($outBase);
        if ($corners !== null) {
            $flat = [];
            foreach (array_slice($corners, 0, 4) as $pt) { $flat[] = (int)round((float)($pt[0] ?? 0)); $flat[] = (int)round((float)($pt[1] ?? 0)); }
            if (count($flat) !== 8) { return null; }
            $cmd .= ' --corners ' . escapeshellarg(implode(',', $flat));
        } elseif ($whole) {
            $cmd .= ' --whole';
        }
        if (DIRECTORY_SEPARATOR === '/' && trim((string)@shell_exec('command -v timeout 2>/dev/null')) !== '') {
            $cmd = 'timeout 150 ' . $cmd;   // never hang the upload on a stuck process
        }
        $out = @shell_exec($cmd . ' 2>/dev/null');
        $res = is_string($out) ? json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true) : null;
        if (!is_array($res) || empty($res['ok']) || !is_file($outBase . '.jpg') || !is_file($outBase . '.pdf')) {
            if (is_array($res) && !empty($res['error'])) { error_log('docscan failed: ' . $res['error']); }
            return null;
        }
        unset($res['ok']);
        return $res;
    }
}
