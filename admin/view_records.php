<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Faculty Records';
$categories = document_categories();
$q = trim($_GET['q'] ?? '');

// Everyone with a 201 file: Deans and Program Chairs keep one too
$stmt = $pdo->query("SELECT * FROM users WHERE role IN ('dean', 'program_chair', 'faculty') ORDER BY full_name");
$everyone = $stmt->fetchAll();

// Search button / Enter: every word typed must match part of the name (first, middle or last), case-insensitive
$terms = preg_split('/\s+/', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY);
$faculty = array_values(array_filter($everyone, function ($f) use ($terms) {
    $name = mb_strtolower($f['full_name']);
    foreach ($terms as $t) { if (!str_contains($name, $t)) { return false; } }
    return true;
}));
if ($q !== '') {
    log_my_activity($pdo, 'SEARCH', 'Faculty Records -- ' . describe_filters(['Name' => $q])
        . ' (' . count($faculty) . ' match' . (count($faculty) === 1 ? '' : 'es') . ').');
}

// Sections, in this order. Faculty without an employment type still get listed.
$sections = [
    'dean'          => ['label' => 'Dean',              'icon' => 'fa-user-tie',          'match' => fn($f) => $f['role'] === 'dean'],
    'program_chair' => ['label' => 'Program Chair',     'icon' => 'fa-user-gear',         'match' => fn($f) => $f['role'] === 'program_chair'],
    'full_time'     => ['label' => 'Full-Time Faculty', 'icon' => 'fa-chalkboard-user',   'match' => fn($f) => $f['role'] === 'faculty' && $f['employment_type'] === 'full_time'],
    'part_time'     => ['label' => 'Part-Time Faculty', 'icon' => 'fa-clock',             'match' => fn($f) => $f['role'] === 'faculty' && $f['employment_type'] === 'part_time'],
    'other'         => ['label' => 'Faculty (employment type not set)', 'icon' => 'fa-user', 'match' => fn($f) => $f['role'] === 'faculty' && !in_array($f['employment_type'], ['full_time', 'part_time'], true)],
];

/** Short role / employment description shown under a name. */
function record_subtitle(array $f): string {
    $program = PROGRAMS[$f['program'] ?? '']['label'] ?? null;
    $college = COLLEGES[$f['college'] ?? ''] ?? null;
    if ($f['role'] === 'dean') { return 'Dean' . ($college ? ' · ' . $college : ''); }
    if ($f['role'] === 'program_chair') { return 'Program Chair' . ($program ? ' · ' . $program : ''); }
    return ucfirst(str_replace('_', '-', (string)($f['employment_type'] ?: 'faculty'))) . ($program ? ' · ' . $program : '');
}

// For the live suggestions: everyone, whatever is currently filtered
$suggest = array_map(fn($f) => [
    'id'   => (int)$f['user_id'],
    'name' => $f['full_name'],
    'info' => record_subtitle($f),
], $everyone);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <h3 class="fw-bold mb-0">Faculty Records</h3>
  <form method="GET" class="d-flex" role="search" autocomplete="off">
    <div class="faculty-search">
      <input type="search" name="q" id="facultySearch" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Search faculty name..."
             role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="facultySuggest" aria-label="Search faculty name">
      <div class="list-group shadow-sm faculty-suggest" id="facultySuggest" role="listbox" hidden></div>
    </div>
    <button class="btn btn-sm btn-outline-brand ms-2" data-tooltip title="Search faculty" aria-label="Search faculty"><i class="fa-solid fa-magnifying-glass"></i></button>
  </form>
</div>

<?php if ($q !== ''): ?>
  <p class="small text-muted mb-3">
    <?= count($faculty) ?> result<?= count($faculty) === 1 ? '' : 's' ?> for "<?= h($q) ?>" ·
    <a href="view_records.php">Show everyone</a>
  </p>
<?php endif; ?>

<?php if (!$faculty): ?>
  <div class="text-center text-muted py-5">No faculty found.</div>
<?php endif; ?>

<?php foreach ($sections as $skey => $section):
  $members = array_values(array_filter($faculty, $section['match']));
  if (!$members) { continue; } ?>
  <section class="records-section mb-4">
    <h5 class="records-heading">
      <i class="fa-solid <?= $section['icon'] ?>"></i> <?= h(mb_strtoupper($section['label'])) ?>
      <span class="badge bg-light text-dark border ms-1"><?= count($members) ?></span>
    </h5>
    <div class="row g-3">
      <?php foreach ($members as $f):
        $counts = faculty_document_counts($pdo, (int)$f['user_id']);
        $total = array_sum($counts);
      ?>
      <div class="col-md-6 col-lg-4">
        <a href="faculty_documents.php?id=<?= (int)$f['user_id'] ?>" class="text-decoration-none">
          <div class="card portal-card h-100 p-3">
            <div class="d-flex align-items-center gap-3 mb-2">
              <div class="portal-icon portal-icon-navy" style="width:48px;height:48px;font-size:1.1rem;"><i class="fa-solid <?= $f['role'] === 'faculty' ? 'fa-user' : $section['icon'] ?>"></i></div>
              <div>
                <div class="fw-bold text-charcoal"><?= h($f['full_name']) ?></div>
                <div class="text-muted small">
                  <?= h(record_subtitle($f)) ?>
                  <?php if ($f['employment_status']): ?> ·
                    <span class="text-capitalize <?= $f['employment_status'] === 'active' ? 'text-success' : 'text-warning' ?>"><?= h($f['employment_status']) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <?php foreach ($categories as $key => $meta): if (empty($counts[$key])) continue; ?>
                <span class="badge bg-light text-dark border"><?= h($meta['short']) ?>: <?= (int)$counts[$key] ?></span>
              <?php endforeach; ?>
            </div>
            <div class="text-muted small mt-2"><?= $total ?> document<?= $total === 1 ? '' : 's' ?> filed</div>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>

<script>
// Live suggestions while typing: matches the start of any part of the name
// (first, middle or last), case- and accent-insensitive. Clicking (or Enter
// on) a suggestion opens that person's record; Enter without one runs the
// normal search.
(function () {
  var PEOPLE = <?= json_encode($suggest) ?>;
  var input = document.getElementById('facultySearch');
  var box = document.getElementById('facultySuggest');
  var active = -1, shown = [];

  function fold(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
  PEOPLE.forEach(function (p) { p.words = fold(p.name).split(/[\s.,'-]+/).filter(Boolean); p.full = fold(p.name); });

  function matches(q) {
    var terms = fold(q).split(/\s+/).filter(Boolean);
    if (!terms.length) return [];
    return PEOPLE.map(function (p) {
      var score = 0;
      for (var i = 0; i < terms.length; i++) {
        var t = terms[i];
        if (p.words.some(function (w) { return w.indexOf(t) === 0; })) score += 2;   // start of a name
        else if (p.full.indexOf(t) !== -1) score += 1;                              // anywhere in the name
        else return null;
      }
      return { p: p, score: score };
    }).filter(Boolean).sort(function (a, b) { return b.score - a.score || a.p.name.localeCompare(b.p.name); })
      .slice(0, 8).map(function (m) { return m.p; });
  }

  function open(id) { window.location.href = 'faculty_documents.php?id=' + id; }

  function render() {
    var q = input.value.trim();
    box.innerHTML = '';
    active = -1;
    if (!q) { close(); return; }
    shown = matches(q);
    if (!shown.length) {
      var none = document.createElement('div');
      none.className = 'list-group-item small text-muted';
      none.textContent = 'No faculty found.';
      box.appendChild(none);
    }
    shown.forEach(function (p, i) {
      var a = document.createElement('a');
      a.href = 'faculty_documents.php?id=' + p.id;
      a.className = 'list-group-item list-group-item-action py-2';
      a.id = 'suggest-' + i;
      a.setAttribute('role', 'option');
      var name = document.createElement('div');
      name.className = 'small fw-semibold';
      name.textContent = p.name;
      var info = document.createElement('div');
      info.className = 'small text-muted';
      info.textContent = p.info;
      a.appendChild(name);
      a.appendChild(info);
      a.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep focus so the click lands
      box.appendChild(a);
    });
    box.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }
  function close() { box.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
  function highlight(i) {
    var items = box.querySelectorAll('[role="option"]');
    if (!items.length) return;
    active = (i + items.length) % items.length;
    items.forEach(function (el, k) { el.classList.toggle('active', k === active); });
    input.setAttribute('aria-activedescendant', items[active].id);
  }

  input.addEventListener('input', render);
  input.addEventListener('focus', function () { if (input.value.trim()) render(); });
  input.addEventListener('blur', function () { setTimeout(close, 150); });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); if (box.hidden) render(); highlight(active + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(active - 1); }
    else if (e.key === 'Escape') { close(); }
    else if (e.key === 'Enter' && !box.hidden && active >= 0 && shown[active]) { e.preventDefault(); open(shown[active].id); }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
