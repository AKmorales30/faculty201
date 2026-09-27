<?php
/**
 * Printable digital PDS (CS Form No. 212 layout). Use the browser's
 * Print -> "Save as PDF" to generate the document.
 *
 * Faculty: their own PDS (?snapshot=ID for an earlier version).
 * Admin / Program Chair / Dean: any faculty member's (?faculty_id=ID).
 *
 * Part VII continues on extra pages once page 3 is full, so pages are
 * numbered: 1-2, 3 (Parts VI, VII, VIII), 4.. (Part VII continuation),
 * then the questions / references page last.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pds.php';
require_role(['faculty', 'admin', 'program_chair', 'dean']);

$me = current_user();
$faculty_id = $me['role'] === 'faculty' ? (int)$me['user_id'] : (int)($_GET['faculty_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'faculty'");
$stmt->execute([$faculty_id]);
$faculty = $stmt->fetch();
if (!$faculty) {
    http_response_code(404);
    exit('Faculty member not found.');
}

$snapshot = null;
if (!empty($_GET['snapshot'])) {
    $stmt = $pdo->prepare("SELECT * FROM pds_snapshots WHERE snapshot_id = ? AND faculty_id = ?");
    $stmt->execute([(int)$_GET['snapshot'], $faculty_id]);
    $snapshot = $stmt->fetch();
    if (!$snapshot) {
        http_response_code(404);
        exit('That PDS version could not be found.');
    }
    $saved = json_decode($snapshot['data'], true) ?: [];
    $data = $saved['data'] ?? [];
    $ld = $saved['ld'] ?? [];
    $updated_at = $snapshot['created_at'];
} else {
    $pds = pds_load($pdo, $faculty_id);
    $data = $pds['data'];
    $ld = $pds['ld'];
    $updated_at = $pds['updated_at'];
}

$schema = pds_schema();

function pds_fmt(array $def, $value): string {
    $value = trim((string)($value ?? ''));
    if ($value === '') { return ''; }
    if (($def[1] ?? 'text') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return date('m/d/Y', strtotime($value));
    }
    return $value;
}

function pds_fields_block(array $part, array $data): void {
    echo '<table class="pds-grid"><tbody>';
    $cells = [];
    foreach ($part['fields'] as $key => $def) {
        $cells[] = '<th>' . h($def[0]) . '</th><td>' . h(pds_fmt($def, $data[$key] ?? '')) . '</td>';
    }
    foreach (array_chunk($cells, 2) as $pair) {
        echo '<tr>' . $pair[0] . ($pair[1] ?? '<th></th><td></td>') . '</tr>';
    }
    echo '</tbody></table>';
}

function pds_table_block(array $table, array $rows, int $min_rows = 3, int $start_num = 0): void {
    echo '<table class="pds-list"><thead><tr>';
    if ($start_num) { echo '<th class="num">#</th>'; }
    foreach ($table['columns'] as $def) { echo '<th>' . h($def[0]) . '</th>'; }
    echo '</tr></thead><tbody>';
    $n = $start_num;
    foreach ($rows as $row) {
        echo '<tr>';
        if ($start_num) { echo '<td class="num">' . $n++ . '</td>'; }
        foreach ($table['columns'] as $key => $def) { echo '<td>' . h(pds_fmt($def, $row[$key] ?? '')) . '</td>'; }
        echo '</tr>';
    }
    for ($i = count($rows); $i < $min_rows; $i++) {
        echo '<tr>' . ($start_num ? '<td class="num"></td>' : '') . str_repeat('<td>&nbsp;</td>', count($table['columns'])) . '</tr>';
    }
    echo '</tbody></table>';
}

function pds_section_title(string $num, string $title): void {
    echo '<div class="pds-section">' . ($num !== '' ? h($num) . '. ' : '') . h(strtoupper($title)) . '</div>';
}

function pds_page_footer(int $page, int $total, array $faculty): void {
    echo '<div class="pds-footer"><span>CS Form No. 212 &middot; ' . h($faculty['full_name']) . '</span><span>Page ' . $page . ' of ' . $total . '</span></div>';
}

$ld_table = ['columns' => pds_ld_columns()];
$ld_table['columns']['hours'][1] = 'text';
$ld = array_map(function ($r) {
    if (isset($r['hours']) && $r['hours'] !== null && $r['hours'] !== '') { $r['hours'] = rtrim(rtrim((string)$r['hours'], '0'), '.'); }
    return $r;
}, $ld);
$ld_pages = pds_part7_pages($ld);
$total_pages = 3 + count($ld_pages);   // pages 1-2, page 3 + continuations, last page
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PDS — <?= h($faculty['full_name']) ?></title>
<style>
  :root { --ink: #111; --band: #969696; --line: #444; --muted: #666; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #e9ecef; color: var(--ink); font: 11px/1.3 Arial, Helvetica, sans-serif; }
  .toolbar { position: sticky; top: 0; background: #1b4b93; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; z-index: 5; }
  .toolbar button { background: #fff; color: #1b4b93; border: 0; border-radius: 6px; padding: 6px 14px; font-weight: 600; cursor: pointer; }
  .toolbar .note { font-size: 12px; opacity: .85; }
  .sheet { background: #fff; width: 8.5in; max-width: 100%; margin: 16px auto; padding: .45in .45in .35in; min-height: 13in; position: relative; box-shadow: 0 2px 10px rgba(0,0,0,.15); display: flex; flex-direction: column; }
  .sheet-body { flex: 1; }
  .pds-head { text-align: center; margin-bottom: 8px; }
  .pds-head .form-no { text-align: left; font-size: 10px; font-style: italic; }
  .pds-head h1 { font-size: 20px; letter-spacing: 1px; margin: 6px 0 2px; }
  .pds-head .warn { font-size: 9px; color: var(--muted); }
  .pds-section { background: var(--band); color: #fff; font-weight: 700; font-style: italic; padding: 3px 6px; margin-top: 8px; border: 1px solid var(--line); }
  .pds-sub { font-weight: 700; margin: 6px 0 2px; font-size: 10px; }
  table { width: 100%; border-collapse: collapse; }
  .pds-grid th, .pds-grid td, .pds-list th, .pds-list td { border: 1px solid var(--line); padding: 3px 5px; vertical-align: top; }
  .pds-grid th { background: #eaeaea; font-weight: 400; width: 22%; font-size: 10px; text-align: left; }
  .pds-grid td { width: 28%; font-weight: 600; }
  .pds-list th { background: #eaeaea; font-weight: 400; font-size: 9.5px; text-align: center; }
  .pds-list td { font-weight: 600; word-break: break-word; }
  .pds-list .num { width: 26px; text-align: center; font-weight: 400; color: var(--muted); }
  .cont-note { font-size: 10px; font-style: italic; color: var(--muted); margin-top: 3px; }
  .pds-footer { display: flex; justify-content: space-between; font-size: 9px; color: var(--muted); border-top: 1px solid var(--line); padding-top: 4px; margin-top: 10px; }
  .sign { display: flex; gap: 24px; margin-top: 18px; }
  .sign div { flex: 1; border-top: 1px solid var(--line); text-align: center; padding-top: 3px; font-size: 10px; }
  .version { font-size: 10px; color: var(--muted); text-align: right; }
  @page { size: 8.5in 13in; margin: 0; }
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { margin: 0; box-shadow: none; width: 8.5in; page-break-after: always; break-after: page; }
    .sheet:last-child { page-break-after: auto; break-after: auto; }
  }
  @media (max-width: 8.6in) {
    .sheet { padding: 16px; min-height: 0; }
    body { font-size: 10px; }
    .pds-grid th { width: 30%; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <button type="button" onclick="window.print()">Print / Save as PDF</button>
  <span class="note">
    <?= $snapshot ? 'Earlier version: ' . h($snapshot['reason']) : 'Current version' ?>
    <?= $updated_at ? ' &middot; ' . date('M j, Y g:ia', strtotime($updated_at)) : '' ?>
    &middot; <?= $total_pages ?> pages<?= count($ld_pages) > 1 ? ' (Part VII continues on ' . (count($ld_pages) - 1) . ' extra page' . (count($ld_pages) > 2 ? 's' : '') . ')' : '' ?>
  </span>
</div>

<!-- Page 1: I, II, III -->
<div class="sheet"><div class="sheet-body">
  <div class="pds-head">
    <div class="form-no">CS Form No. 212</div>
    <h1>PERSONAL DATA SHEET</h1>
    <div class="warn">WARNING: Any misrepresentation made in the Personal Data Sheet and the Work Experience Sheet shall cause the filing of administrative/criminal case/s against the person concerned.</div>
  </div>
  <?php pds_section_title('I', $schema['I']['title']); pds_fields_block($schema['I'], $data); ?>
  <?php pds_section_title('II', $schema['II']['title']); pds_fields_block($schema['II'], $data); ?>
  <div class="pds-sub">NAME OF CHILDREN</div>
  <?php pds_table_block($schema['II']['tables']['children'], $data['children'] ?? []); ?>
  <?php pds_section_title('III', $schema['III']['title']); pds_table_block($schema['III']['tables']['education'], $data['education'] ?? [], 5); ?>
</div><?php pds_page_footer(1, $total_pages, $faculty); ?></div>

<!-- Page 2: IV, V -->
<div class="sheet"><div class="sheet-body">
  <?php pds_section_title('IV', $schema['IV']['title']); pds_table_block($schema['IV']['tables']['eligibility'], $data['eligibility'] ?? []); ?>
  <?php pds_section_title('V', $schema['V']['title']); pds_table_block($schema['V']['tables']['work'], $data['work'] ?? [], 5); ?>
</div><?php pds_page_footer(2, $total_pages, $faculty); ?></div>

<!-- Page 3: VI, VII (first page), VIII -->
<div class="sheet"><div class="sheet-body">
  <?php pds_section_title('VI', $schema['VI']['title']); pds_table_block($schema['VI']['tables']['voluntary'], $data['voluntary'] ?? []); ?>
  <?php pds_section_title('VII', 'Learning and Development (L&D) Interventions / Training Programs Attended'); ?>
  <?php pds_table_block($ld_table, $ld_pages[0], 3, 1); ?>
  <?php if (count($ld_pages) > 1): ?>
    <div class="cont-note">Continued on page 4 (Part VII continuation).</div>
  <?php endif; ?>
  <?php pds_section_title('VIII', $schema['VIII']['title']); ?>
  <?php foreach ($schema['VIII']['tables'] as $tkey => $table): ?>
    <div class="pds-sub"><?= h(strtoupper($table['label'])) ?></div>
    <?php pds_table_block($table, $data[$tkey] ?? [], 2); ?>
  <?php endforeach; ?>
</div><?php pds_page_footer(3, $total_pages, $faculty); ?></div>

<!-- Part VII continuation pages -->
<?php for ($p = 1; $p < count($ld_pages); $p++):
  $page_no = pds_part7_page_number($p + 1); ?>
<div class="sheet"><div class="sheet-body">
  <?php pds_section_title('VII', 'Learning and Development (L&D) Interventions / Training Programs Attended (continuation)'); ?>
  <?php pds_table_block($ld_table, $ld_pages[$p], 0, $p * PDS_PART7_ROWS_PER_PAGE + 1); ?>
  <?php if ($p < count($ld_pages) - 1): ?>
    <div class="cont-note">Continued on page <?= $page_no + 1 ?>.</div>
  <?php endif; ?>
</div><?php pds_page_footer($page_no, $total_pages, $faculty); ?></div>
<?php endfor; ?>

<!-- Last page: questions, references, ID, signature -->
<div class="sheet"><div class="sheet-body">
  <?php pds_section_title('', $schema['Q']['title']); ?>
  <table class="pds-grid"><tbody>
    <?php foreach ($schema['Q']['fields'] as $key => $def): if (str_contains($key, '_')) continue; ?>
      <tr>
        <th style="width:70%"><?= h($def[0]) ?></th>
        <td><?= h($data[$key] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <div class="pds-sub">DETAILS</div>
  <table class="pds-grid"><tbody>
    <?php foreach ($schema['Q']['fields'] as $key => $def): if (!str_contains($key, '_') || trim((string)($data[$key] ?? '')) === '') continue; ?>
      <tr><th><?= h(strtoupper(explode('_', $key)[0])) ?> &ndash; <?= h($def[0]) ?></th><td colspan="3"><?= h(pds_fmt($def, $data[$key])) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>

  <?php pds_section_title('41', $schema['R']['title']); pds_table_block($schema['R']['tables']['references'], $data['references'] ?? [], 3); ?>
  <?php pds_section_title('', $schema['ID']['title']); pds_fields_block($schema['ID'], $data); ?>

  <p style="font-size:10px;margin-top:10px">42. I declare under oath that I have personally accomplished this Personal Data Sheet which is a true, correct and complete statement pursuant to the provisions of pertinent laws, rules and regulations of the Republic of the Philippines.</p>
  <div class="sign"><div>Signature (sign inside the box)</div><div>Date Accomplished</div><div>Right Thumbmark</div></div>
</div><?php pds_page_footer($total_pages, $total_pages, $faculty); ?></div>

</body>
</html>
