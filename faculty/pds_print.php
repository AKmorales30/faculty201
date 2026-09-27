<?php
/**
 * Printable PDS on the official CSC form: CS Form No. 212 (Revised 2026).
 *
 * Pages C1-C4 (and the form's continuation sheets C5-C11 when a table
 * overflows) are rendered from templates generated from the official
 * workbook -- see tools/build_pds_template.php and includes/pds_form.php --
 * and printed on A4 with each sheet's own margins and scale, one sheet
 * per page. Use the browser's Print -> "Save as PDF" to generate the file.
 *
 * Faculty: their own PDS (?snapshot=ID for an earlier version).
 * Admin / Program Chair / Dean: any faculty member's (?faculty_id=ID).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pds.php';
require_once __DIR__ . '/../includes/pds_form_fill.php';
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

$pages = pds_form_build($data, $ld);
$extra = count($pages) - 4;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PDS — <?= h($faculty['full_name']) ?></title>
<style>
  body { margin: 0; background: #e9ecef; font-family: Arial, Helvetica, sans-serif; }
  .toolbar { position: sticky; top: 0; z-index: 5; background: #1b4b93; color: #fff; padding: 10px 16px;
             display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 13px; }
  .toolbar button { background: #fff; color: #1b4b93; border: 0; border-radius: 6px; padding: 6px 14px; font-weight: 600; cursor: pointer; }
  .toolbar .note { opacity: .9; }
  .pages { overflow-x: auto; padding: 16px 0; }
  .pages .pds-page { margin: 0 auto 16px; box-shadow: 0 2px 10px rgba(0,0,0,.18); }
  <?= pds_form_css() ?>
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .pages { padding: 0; overflow: visible; }
    .pages .pds-page { margin: 0; box-shadow: none; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <button type="button" onclick="window.print()">Print / Save as PDF</button>
  <span class="note">
    CS Form No. 212 (Revised 2026) &middot; A4 &middot;
    <?= $snapshot ? 'Earlier version: ' . h($snapshot['reason']) : 'Current version' ?>
    <?= $updated_at ? ' &middot; ' . date('M j, Y g:ia', strtotime($updated_at)) : '' ?>
    &middot; <?= count($pages) ?> pages<?= $extra > 0 ? ' (incl. ' . $extra . ' continuation sheet' . ($extra > 1 ? 's' : '') . ')' : '' ?>
  </span>
</div>
<div class="pages">
<?php foreach ($pages as $p) { echo pds_form_page($p['key'], $p['values'], $p['checks']); } ?>
</div>
</body>
</html>
