<?php
/**
 * "My 201 file checklist" card on the Faculty, Program Chair and Dean Home:
 * what their own 201 file should contain now (faculty_201_checklist()),
 * with a progress bar and an Upload / Update button for each missing item.
 * Expects $checklist.
 */
$check_done = count(array_filter($checklist, fn($i) => $i['done']));
$check_total = count($checklist);
$check_pct = $check_total ? (int)round($check_done / $check_total * 100) : 0;
?>
<section class="ui-card" aria-labelledby="checkTitle" data-animate>
  <div class="ui-card-head">
    <span class="ui-head-icon tone-navy" aria-hidden="true"><i class="fa-solid fa-list-check"></i></span>
    <h2 class="ui-card-title" id="checkTitle">My 201 file checklist</h2>
    <span class="ui-tile-dot" style="--dot: <?= $check_done === $check_total ? 'var(--ad-teal)' : 'var(--ad-gold)' ?>"><?= $check_done === $check_total ? 'Complete' : ($check_total - $check_done) . ' to do' ?></span>
  </div>
  <div class="ui-check-progress">
    <div class="ui-check-progress-top"><span><?= $check_done ?> of <?= $check_total ?> complete</span><span><?= $check_pct ?>%</span></div>
    <span class="ui-bar ui-bar-lg" role="progressbar" aria-valuenow="<?= $check_pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Checklist progress"><span style="--w: <?= $check_pct ?>%; --bar: var(--ad-teal)"></span></span>
  </div>
  <ul class="ui-check-list">
    <?php foreach ($checklist as $item): ?>
      <li class="ui-check-row">
        <span class="ui-check-icon <?= $item['done'] ? 'tone-teal' : 'tone-gold' ?>" aria-hidden="true"><i class="fa-solid <?= $item['done'] ? 'fa-check' : 'fa-exclamation' ?>"></i></span>
        <span class="ui-check-label"><?= h($item['label']) ?><span class="visually-hidden"><?= $item['done'] ? ' (done)' : ' (to do)' ?></span></span>
        <?php if (!$item['done']): ?>
          <a href="<?= BASE_URL ?>/faculty/<?= $item['type'] === 'PDS' ? 'pds.php' : 'submit_document.php' ?>" class="btn btn-sm btn-outline-brand"><?= $item['type'] === 'PDS' ? 'Update' : 'Upload' ?></a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
