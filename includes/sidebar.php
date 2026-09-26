<?php
$role = current_user()['role'];
$here = basename($_SERVER['PHP_SELF']);
function nav_active($file, $here) { return $file === $here ? 'active' : ''; }
?>
<nav class="col-md-2 d-md-block sidebar collapse d-md-flex flex-column">
  <div class="pt-3">
    <ul class="nav flex-column">

      <?php if ($role === 'admin'): ?>
        <li class="nav-item"><a class="nav-link <?= nav_active('dashboard.php',$here) ?>" href="<?= BASE_URL ?>/admin/dashboard.php"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('manage_faculty.php',$here) ?>" href="<?= BASE_URL ?>/admin/manage_faculty.php"><i class="fa-solid fa-users-gear"></i> Manage Faculty</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('view_records.php',$here) ?>" href="<?= BASE_URL ?>/admin/view_records.php"><i class="fa-solid fa-folder"></i> Faculty Records</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('search_documents.php',$here) ?>" href="<?= BASE_URL ?>/admin/search_documents.php"><i class="fa-solid fa-magnifying-glass"></i> Search Documents</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('analytics.php',$here) ?>" href="<?= BASE_URL ?>/admin/analytics.php"><i class="fa-solid fa-chart-column"></i> Data Analytics</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('security_alerts.php',$here) ?>" href="<?= BASE_URL ?>/admin/security_alerts.php">
          <i class="fa-solid fa-shield-halved"></i> Security Alerts
          <?php $sa = unresolved_security_alert_count($pdo); if ($sa): ?><span class="badge bg-danger rounded-pill ms-1"><?= $sa ?></span><?php endif; ?>
        </a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('notifications.php',$here) ?>" href="<?= BASE_URL ?>/admin/notifications.php"><i class="fa-solid fa-bell"></i> Notifications</a></li>
      <?php endif; ?>

      <?php if ($role === 'faculty'): ?>
        <li class="nav-item"><a class="nav-link <?= nav_active('dashboard.php',$here) ?>" href="<?= BASE_URL ?>/faculty/dashboard.php"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('submit_document.php',$here) ?>" href="<?= BASE_URL ?>/faculty/submit_document.php"><i class="fa-solid fa-file-arrow-up"></i> Submit Document</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('my_requests.php',$here) ?>" href="<?= BASE_URL ?>/faculty/my_requests.php"><i class="fa-solid fa-list-check"></i> My Requests</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('my_documents.php',$here) ?>" href="<?= BASE_URL ?>/faculty/my_documents.php"><i class="fa-solid fa-folder-open"></i> My 201 File</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('notifications.php',$here) ?>" href="<?= BASE_URL ?>/faculty/notifications.php"><i class="fa-solid fa-bell"></i> Notifications</a></li>
      <?php endif; ?>

      <?php if (in_array($role, ['program_chair','dean'], true)): ?>
        <li class="nav-item"><a class="nav-link <?= nav_active('pending_requests.php',$here) ?>" href="<?= BASE_URL ?>/approval/pending_requests.php"><i class="fa-solid fa-hourglass-half"></i> Pending Requests</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('history.php',$here) ?>" href="<?= BASE_URL ?>/approval/history.php"><i class="fa-solid fa-clock-rotate-left"></i> Confirmation History</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('notifications.php',$here) ?>" href="<?= BASE_URL ?>/approval/notifications.php"><i class="fa-solid fa-bell"></i> Notifications</a></li>
      <?php endif; ?>

    </ul>
  </div>
</nav>
