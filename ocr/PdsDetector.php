<?php
/**
 * PdsDetector -- is this text from a Personal Data Sheet (CS Form No. 212),
 * and which of its four pages?
 *
 * Run by OcrProcessor::process() BEFORE the general keyword classifier: a
 * phone photo of a PDS often reads too badly for the classifier's exact
 * keyword matches, and the form's section titles are white text on gray
 * bars that OCR tends to miss. Instead, the text is checked for many
 * anchor phrases of the form (its labels: "SURNAME", "DATE OF BIRTH",
 * "GSIS ID NO.", "FAMILY BACKGROUND", ...), each matched fuzzily:
 *   - case and spacing don't matter, punctuation is ignored;
 *   - common OCR confusions count as the same letter (0/O, 1/I/l/|, 5/S/$);
 *   - a few wrong, missing or extra letters are allowed (Levenshtein
 *     similarity, stricter for short phrases), and words OCR split or
 *     joined still match.
 * Score: one point per anchor found, two for the form's title and "CS Form
 * No. 212". Labels that also appear on other documents (date of birth,
 * civil status, name of school, ...) add at most GENERIC_CAP points
 * together, so a TOR or an ID can't pass for a PDS. Compared with
 * PDS_DETECT_MIN_SCORE (it's a PDS) and PDS_DETECT_ASK_SCORE (ask the user,
 * when at least one PDS-only label was found).
 */
class PdsDetector
{
    private const GENERIC_CAP = 2;

    /**
     * [phrase, page (0 = any), points, generic]. Phrases are written as on the
     * 2017 form; page tells which of the four pages the label is printed on.
     */
    private const ANCHORS = [
        ['PERSONAL DATA SHEET', 0, 2, false],
        ['CS FORM NO 212', 0, 2, false],
        // Page 1
        ['PERSONAL INFORMATION', 1, 1, false],
        ['NAME EXTENSION', 1, 1, false],
        ['SURNAME', 1, 1, true],
        ['MIDDLE NAME', 1, 1, true],
        ['DATE OF BIRTH', 1, 1, true],
        ['PLACE OF BIRTH', 1, 1, true],
        ['CIVIL STATUS', 1, 1, true],
        ['GSIS ID NO', 1, 1, false],
        ['PAG IBIG ID NO', 1, 1, false],
        ['PHILHEALTH NO', 1, 1, false],
        ['TIN NO', 1, 1, false],
        ['AGENCY EMPLOYEE NO', 1, 1, false],
        ['RESIDENTIAL ADDRESS', 1, 1, false],
        ['PERMANENT ADDRESS', 1, 1, false],
        ['FAMILY BACKGROUND', 1, 1, false],
        ['SPOUSES SURNAME', 1, 1, false],
        ['NAME OF CHILDREN', 1, 1, false],
        ['FATHERS SURNAME', 1, 1, false],
        ['MOTHERS MAIDEN NAME', 1, 1, false],
        ['EDUCATIONAL BACKGROUND', 1, 1, false],
        ['NAME OF SCHOOL', 1, 1, true],
        ['PERIOD OF ATTENDANCE', 1, 1, true],
        ['YEAR GRADUATED', 1, 1, true],
        ['SCHOLARSHIP ACADEMIC HONORS RECEIVED', 1, 1, false],
        // Page 2
        ['CIVIL SERVICE ELIGIBILITY', 2, 1, false],
        ['CAREER SERVICE', 2, 1, false],
        ['PLACE OF EXAMINATION', 2, 1, false],
        ['DATE OF EXAMINATION', 2, 1, false],
        ['WORK EXPERIENCE', 2, 1, false],
        ['DEPARTMENT AGENCY OFFICE COMPANY', 2, 1, false],
        ['STATUS OF APPOINTMENT', 2, 1, false],
        ['GOVT SERVICE', 2, 1, false],
        // Page 3
        ['VOLUNTARY WORK', 3, 1, false],
        ['NAME ADDRESS OF ORGANIZATION', 3, 1, false],
        ['LEARNING AND DEVELOPMENT', 3, 1, false],
        ['TRAINING PROGRAMS ATTENDED', 3, 1, false],
        ['CONDUCTED SPONSORED BY', 3, 1, false],
        ['OTHER INFORMATION', 3, 1, false],
        ['SPECIAL SKILLS AND HOBBIES', 3, 1, false],
        ['NON ACADEMIC DISTINCTIONS RECOGNITION', 3, 1, false],
        ['MEMBERSHIP IN ASSOCIATION ORGANIZATION', 3, 1, false],
        // Page 4
        ['CONSANGUINITY OR AFFINITY', 4, 1, false],
        ['FOUND GUILTY OF ANY ADMINISTRATIVE OFFENSE', 4, 1, false],
        ['IMMIGRANT OR PERMANENT RESIDENT', 4, 1, false],
        ['INDIGENOUS GROUP', 4, 1, false],
        ['SOLO PARENT', 4, 1, false],
        ['GOVERNMENT ISSUED ID', 4, 1, false],
        ['PERSON ADMINISTERING OATH', 4, 1, false],
        ['RIGHT THUMBMARK', 4, 1, false],
    ];

    /**
     * @return array{score: int, is_pds: bool, ask: bool, matched: string[], pages: int[]}
     *   matched: the anchors found (as printed); pages: which of pages 1-4 the
     *   text is from ("Page 2 of 4" footers, or two or more of a page's labels).
     */
    public static function detect(string $text): array
    {
        $norm = self::normalize(mb_substr($text, 0, 60000));
        $tokens = $norm === '' ? [] : explode(' ', $norm);
        $joined = str_replace(' ', '', $norm);

        $score = $generic = $specific = 0;
        $matched = [];
        $per_page = [];
        foreach (self::ANCHORS as [$phrase, $page, $points, $is_generic]) {
            if (!self::found(self::normalize($phrase), $tokens, $joined)) { continue; }
            $matched[] = $phrase;
            if ($is_generic) {
                $add = min($points, self::GENERIC_CAP - $generic);
                $generic += $add;
                $score += $add;
            } else {
                $score += $points;
                $specific++;
            }
            if ($page) { $per_page[$page] = ($per_page[$page] ?? 0) + 1; }
        }

        // "Page 2 of 4" in the footer (after normalizing: "PAGE 2 OF 4", 1 read as I)
        $pages = [];
        if (preg_match_all('/PAGE\s?([I234])\s?OF\s?4/', $norm, $m)) {
            foreach ($m[1] as $p) { $pages[] = $p === 'I' ? 1 : (int)$p; }
        }
        foreach ($per_page as $page => $n) {
            if ($n >= 2) { $pages[] = $page; }
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        return [
            'score'   => $score,
            'is_pds'  => $score >= PDS_DETECT_MIN_SCORE,
            // Only labels found on other documents too (a TOR, an ID) aren't reason enough to ask
            'ask'     => $score < PDS_DETECT_MIN_SCORE && $score >= PDS_DETECT_ASK_SCORE && $specific > 0,
            'matched' => $matched,
            'pages'   => $pages,
        ];
    }

    /** A 0-1 confidence for a detected PDS: CONFIDENCE_THRESHOLD at PDS_DETECT_MIN_SCORE, rising to 1. */
    public static function confidence(array $detection): float
    {
        $over = max(0, $detection['score'] - PDS_DETECT_MIN_SCORE);
        return round(min(1.0, CONFIDENCE_THRESHOLD + $over * 0.05), 3);
    }

    /** "page 1" / "pages 1 and 3" / "pages 1, 2 and 4" of the form, or '' when unknown. */
    public static function pagesLabel(array $pages): string
    {
        if (!$pages) { return ''; }
        $last = array_pop($pages);
        return ($pages ? 'pages ' . implode(', ', $pages) . ' and ' : 'page ') . $last . ' of 4';
    }

    /**
     * Upper case, OCR look-alikes folded together (0->O, 1/L/|/!->I, 5/$->S),
     * anything else that isn't a letter or digit -> space, single spaces.
     */
    private static function normalize(string $s): string
    {
        $s = strtr(mb_strtoupper($s), ['0' => 'O', '1' => 'I', 'L' => 'I', '|' => 'I', '!' => 'I', '5' => 'S', '$' => 'S']);
        return trim(preg_replace('/[^A-Z0-9]+/', ' ', $s));
    }

    /**
     * Whether the (normalized) phrase is in the text: exactly, or fuzzily --
     * compared without spaces against every run of words about as long as
     * it, so words OCR split ("EDUCA TIONAL") or joined still match.
     */
    private static function found(string $phrase, array $tokens, string $joined): bool
    {
        $target = str_replace(' ', '', $phrase);
        $len = strlen($target);
        if ($len === 0) { return false; }
        // Short phrases must match whole words exactly; their few letters leave no room for errors
        if ($len < 8) {
            return (bool)preg_match('/(?:^| )' . preg_quote($phrase, '/') . '(?: |$)/', implode(' ', $tokens));
        }
        if (str_contains($joined, $target)) { return true; }
        $max_dist = (int)floor($len * ($len >= 14 ? 0.2 : 0.15));
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $run = '';
            for ($j = $i; $j < $n; $j++) {
                $run .= $tokens[$j];
                $diff = strlen($run) - $len;
                if ($diff > $max_dist) { break; }
                if ($diff >= -$max_dist && levenshtein($run, $target) <= $max_dist) { return true; }
            }
        }
        return false;
    }
}
