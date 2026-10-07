<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

// The Academic Rank dropdown (Manage Faculty & Accounts > Edit details).
// The Admin can add ranks and hide ones no longer given out. A hidden rank
// stays on the accounts that have it; it just isn't offered anymore.
// Ranks aren't deleted or renamed, so no account is left with a rank
// that no longer exists.

$page_title = 'Academic Ranks';
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    } elseif ($action === 'add') {
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? ''))), 0, 60);
        $after = (int)($_POST['after'] ?? 0);   // rank_id to place it after; 0 = at the end
        $stmt = $pdo->prepare("SELECT 1 FROM academic_ranks WHERE name = ?");
        $stmt->execute([$name]);
        if ($name === '') {
            $_SESSION['flash_error'] = 'Please enter the name of the rank.';
        } elseif ($stmt->fetchColumn()) {
            $_SESSION['flash_error'] = "\"{$name}\" is already on the list.";
        } else {
            $stmt = $pdo->prepare("SELECT sort_order FROM academic_ranks WHERE rank_id = ?");
            $stmt->execute([$after]);
            $after_order = $stmt->fetchColumn();
            if ($after_order === false) {
                $order = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM academic_ranks")->fetchColumn();
            } else {   // make room right after the chosen rank
                $pdo->prepare("UPDATE academic_ranks SET sort_order = sort_order + 10 WHERE sort_order > ?")->execute([$after_order]);
                $order = (int)$after_order + 5;
            }
            $pdo->prepare("INSERT INTO academic_ranks (name, sort_order) VALUES (?, ?)")->execute([$name, $order]);
            log_my_activity($pdo, 'RANK_LIST_UPDATE', "Added the academic rank \"{$name}\".");
            $_SESSION['flash_success'] = "Added \"{$name}\" to the academic ranks.";
        }
    } elseif ($action === 'toggle') {
        $stmt = $pdo->prepare("SELECT * FROM academic_ranks WHERE rank_id = ?");
        $stmt->execute([(int)($_POST['rank_id'] ?? 0)]);
        if ($rank = $stmt->fetch()) {
            $pdo->prepare("UPDATE academic_ranks SET is_active = 1 - is_active WHERE rank_id = ?")->execute([$rank['rank_id']]);
            log_my_activity($pdo, 'RANK_LIST_UPDATE', ($rank['is_active'] ? 'Hid' : 'Showed again') . " the academic rank \"{$rank['name']}\".");
            $_SESSION['flash_success'] = ($rank['is_active'] ? 'Hid' : 'Showed') . " \"{$rank['name']}\".";
        }
    }
    header('Location: ' . BASE_URL . '/admin/academic_ranks.php');
    exit;
}

$ranks = $pdo->query(
    "SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.academic_rank = r.name) AS holders
     FROM academic_ranks r ORDER BY r.sort_order, r.name"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<a href="<?= BASE_URL ?>/admin/manage_faculty.php" class="small text-muted d-inline-block mb-3"><i class="fa-solid fa-arrow-left"></i> Back to Manage Faculty &amp; Accounts</a>
<h3 class="fw-bold mb-1">Academic Ranks</h3>
<p class="text-muted mb-4">The ranks offered when you set an account's academic rank. Hidden ranks stay on the accounts that already have them.</p>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card stat-card">
      <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-plus text-brand"></i> Add a Rank</div>
      <div class="card-body">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add">
          <div class="mb-2">
            <label for="rankName" class="form-label small">Name</label>
            <input type="text" name="name" id="rankName" class="form-control form-control-sm" maxlength="60" required placeholder="e.g. Professor VII">
          </div>
          <div class="mb-3">
            <label for="rankAfter" class="form-label small">Place it after</label>
            <select name="after" id="rankAfter" class="form-select form-select-sm">
              <option value="0">At the end of the list</option>
              <?php foreach ($ranks as $r): ?><option value="<?= (int)$r['rank_id'] ?>"><?= h($r['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-brand btn-sm w-100"><i class="fa-solid fa-plus"></i> Add Rank</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card stat-card">
      <div class="card-body p-0 table-responsive">
        <table class="table mb-0 align-middle">
          <thead class="table-light"><tr><th>Rank</th><th class="text-end">Accounts</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($ranks as $r): ?>
            <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
              <td><?= h($r['name']) ?></td>
              <td class="text-end"><?= (int)$r['holders'] ?></td>
              <td><span class="badge <?= $r['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $r['is_active'] ? 'Offered' : 'Hidden' ?></span></td>
              <td class="text-end">
                <form method="POST" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="rank_id" value="<?= (int)$r['rank_id'] ?>">
                  <button class="btn btn-sm btn-outline-brand"><i class="fa-solid <?= $r['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i> <?= $r['is_active'] ? 'Hide' : 'Show' ?></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
