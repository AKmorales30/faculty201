<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../ocr/OcrProcessor.php';
require_role(['program_chair', 'dean']);

$page_title = 'Review Request';
$me = current_user();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT sr.*, u.full_name, u.email, u.employment_type, u.employment_status
     FROM submission_requests sr
     JOIN users u ON u.user_id = sr.faculty_id
     WHERE sr.request_id = ?"
);
$stmt->execute([$id]);
$request = $stmt->fetch();

if (!$request) {
    $_SESSION['flash_error'] = 'That request could not be found.';
    header('Location: ' . BASE_URL . '/approval/pending_requests.php');
    exit;
}

$already_acted_by_me = ($me['role'] === 'program_chair' && $request['chair_id'] == $me['user_id'])
                     || ($me['role'] === 'dean' && $request['dean_id'] == $me['user_id']);
$is_open = !in_array($request['status'], ['uploaded', 'rejected'], true);

// ---------------------------------------------------------------------
// Handle Confirm / Reject
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_open) {
    $decision = $_POST['decision'] ?? '';

    if ($decision === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $_SESSION['flash_error'] = 'Please provide a reason for rejecting this request.';
            header('Location: ' . BASE_URL . '/approval/request_detail.php?id=' . $id);
            exit;
        }
        $stmt = $pdo->prepare(
            "UPDATE submission_requests SET status='rejected', rejected_by=?, rejection_reason=? WHERE request_id=?"
        );
        $stmt->execute([$me['user_id'], $reason, $id]);
        notify($pdo, (int)$request['faculty_id'],
            "Your {$request['document_type_hint']} submission was rejected by " . ($me['role'] === 'dean' ? 'the Dean' : 'the Program Chair') . ": {$reason}",
            $id);
        $_SESSION['flash_success'] = 'Request rejected and the faculty member has been notified.';
        header('Location: ' . BASE_URL . '/approval/pending_requests.php');
        exit;
    }

    if ($decision === 'confirm') {
        $new_status = $request['status'];

        if ($me['role'] === 'program_chair') {
            $pdo->prepare("UPDATE submission_requests SET chair_id=?, chair_confirmed_at=NOW() WHERE request_id=?")
                ->execute([$me['user_id'], $id]);
            $new_status = ($request['status'] === 'dean_confirmed') ? 'fully_confirmed' : 'chair_confirmed';
        } else { // dean
            $pdo->prepare("UPDATE submission_requests SET dean_id=?, dean_confirmed_at=NOW() WHERE request_id=?")
                ->execute([$me['user_id'], $id]);
            $new_status = ($request['status'] === 'chair_confirmed') ? 'fully_confirmed' : 'dean_confirmed';
        }
        $pdo->prepare("UPDATE submission_requests SET status=? WHERE request_id=?")->execute([$new_status, $id]);

        if ($new_status === 'fully_confirmed') {
            file_request($pdo, $id);
            $_SESSION['flash_success'] = 'Confirmed. Both approvals are in -- the document has been filed to the faculty member\'s 201 file.';
        } else {
            $waiting_on = ($me['role'] === 'program_chair') ? 'the Dean' : 'the Program Chair';
            notify_role($pdo, $me['role'] === 'program_chair' ? 'dean' : 'program_chair',
                "{$request['full_name']}'s {$request['document_type_hint']} was confirmed by " . ($me['role'] === 'program_chair' ? 'the Program Chair' : 'the Dean') . " -- your confirmation is needed too.",
                $id);
            $_SESSION['flash_success'] = "Confirmed. Waiting on {$waiting_on} before this is filed.";
        }
        header('Location: ' . BASE_URL . '/approval/pending_requests.php');
        exit;
    }
}

/**
 * Runs once a request has been confirmed by BOTH the Program Chair and
 * the Dean: re-runs OCR/AI extraction on the temp scan, moves the file
 * into the permanent repository (uploads/{faculty_id}/{document_type}/),
 * inserts the documents row, marks the request 'uploaded', and notifies
 * the faculty member.
 */
function file_request(PDO $pdo, int $request_id): void {
    $stmt = $pdo->prepare(
        "SELECT sr.*, u.full_name FROM submission_requests sr
         JOIN users u ON u.user_id = sr.faculty_id WHERE sr.request_id = ?"
    );
    $stmt->execute([$request_id]);
    $req = $stmt->fetch();
    if (!$req) return;

    $tempAbs = ROOT_PATH . '/' . $req['temp_file_path'];
    $result = OcrProcessor::process($tempAbs, $req['full_name']);
    $documentType = $req['document_type_hint'];

    $destDir = UPLOADS_PATH . '/' . $req['faculty_id'] . '/' . $documentType;
    if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
    $filename = basename($req['temp_file_path']);
    $destAbs = $destDir . '/' . $filename;
    $relativePath = 'uploads/' . $req['faculty_id'] . '/' . $documentType . '/' . $filename;

    if (is_file($tempAbs)) {
        @rename($tempAbs, $destAbs);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO documents (request_id, faculty_id, document_type, file_path, expiration_date, ocr_extracted_text, ocr_matched_name, ocr_confidence_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $request_id, $req['faculty_id'], $documentType, $relativePath, $req['expiration_date_hint'] ?: null,
        $result['text'], $result['matched_name'], $result['confidence_note'],
    ]);

    $pdo->prepare("UPDATE submission_requests SET status='uploaded' WHERE request_id=?")->execute([$request_id]);

    notify($pdo, (int)$req['faculty_id'],
        "Your {$documentType} has been fully confirmed and filed to your 201 file.", $request_id);
}

include __DIR__ . '/../includes/header.php';
[$label, $badge] = status_badge($request['status']);
$ext = strtolower(pathinfo($request['temp_file_path'] ?? '', PATHINFO_EXTENSION));
?>

<a href="<?= BASE_URL ?>/approval/pending_requests.php" class="small text-muted d-inline-block mb-3">
  <i class="fa-solid fa-arrow-left"></i> Back to Pending Requests
</a>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card stat-card">
      <div class="card-header bg-white fw-semibold">Scanned Document</div>
      <div class="card-body text-center">
        <?php if (!$is_open): ?>
          <div class="text-muted small mb-2">This request has already been resolved.</div>
        <?php endif; ?>
        <?php if ($ext === 'pdf'): ?>
          <i class="fa-solid fa-file-pdf fa-4x text-danger mb-3"></i><br>
          <a href="<?= BASE_URL . '/' . h($request['temp_file_path']) ?>" target="_blank" class="btn btn-outline-brand btn-sm">
            <i class="fa-solid fa-up-right-from-square"></i> Open PDF
          </a>
        <?php else: ?>
          <img src="<?= BASE_URL . '/' . h($request['temp_file_path']) ?>" class="img-fluid rounded border" alt="Scanned document">
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card stat-card mb-3">
      <div class="card-header bg-white fw-semibold">Request Details</div>
      <div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-sm-4">Faculty</dt><dd class="col-sm-8"><?= h($request['full_name']) ?></dd>
          <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= h($request['email']) ?></dd>
          <dt class="col-sm-4">Employment</dt><dd class="col-sm-8 text-capitalize"><?= h(str_replace('_',' ',$request['employment_type'] ?? '—')) ?></dd>
          <dt class="col-sm-4">Document Type</dt><dd class="col-sm-8"><?= h($request['document_type_hint']) ?></dd>
          <dt class="col-sm-4">Submitted</dt><dd class="col-sm-8"><?= date('M j, Y g:ia', strtotime($request['submitted_at'])) ?></dd>
          <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><span class="badge <?= $badge ?>"><?= h($label) ?></span></dd>
        </dl>

        <hr>
        <div class="d-flex gap-4 small">
          <div>
            <i class="fa-solid <?= $request['chair_confirmed_at'] ? 'fa-circle-check text-success' : 'fa-circle text-muted' ?>"></i>
            Program Chair <?= $request['chair_confirmed_at'] ? 'confirmed ' . date('M j, g:ia', strtotime($request['chair_confirmed_at'])) : '(pending)' ?>
          </div>
          <div>
            <i class="fa-solid <?= $request['dean_confirmed_at'] ? 'fa-circle-check text-success' : 'fa-circle text-muted' ?>"></i>
            Dean <?= $request['dean_confirmed_at'] ? 'confirmed ' . date('M j, g:ia', strtotime($request['dean_confirmed_at'])) : '(pending)' ?>
          </div>
        </div>

        <?php if ($request['status'] === 'rejected'): ?>
          <div class="alert alert-danger small mt-3 mb-0"><strong>Rejection reason:</strong> <?= h($request['rejection_reason']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($is_open && !$already_acted_by_me): ?>
    <div class="card stat-card">
      <div class="card-header bg-white fw-semibold">Your Decision</div>
      <div class="card-body">
        <form method="POST" class="mb-3">
          <input type="hidden" name="id" value="<?= (int)$request['request_id'] ?>">
          <input type="hidden" name="decision" value="confirm">
          <button type="submit" class="btn btn-brand w-100">
            <i class="fa-solid fa-check"></i> Confirm This Document
          </button>
        </form>
        <form method="POST" class="d-flex gap-2">
          <input type="hidden" name="id" value="<?= (int)$request['request_id'] ?>">
          <input type="hidden" name="decision" value="reject">
          <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason for rejection..." required>
          <button type="submit" class="btn btn-outline-danger btn-sm text-nowrap">
            <i class="fa-solid fa-xmark"></i> Reject
          </button>
        </form>
      </div>
    </div>
    <?php elseif ($is_open && $already_acted_by_me): ?>
      <div class="alert alert-info small">You've already confirmed this request. Waiting on the other approver.</div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
