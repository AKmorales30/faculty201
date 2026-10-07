<?php
$role = current_user()['role'];
$here = basename($_SERVER['PHP_SELF']);
function nav_active($file, $here) { return $file === $here ? 'active' : ''; }
?>
<?php $sb_user = current_user(); ?>
<!-- Static sidebar on md+; slide-in (offcanvas) menu below md, opened by the navbar hamburger -->
<nav class="col-md-3 col-lg-2 sidebar offcanvas-md offcanvas-start" id="appSidebar" tabindex="-1" aria-labelledby="appSidebarLabel">
  <div class="offcanvas-header sidebar-mobile-header d-md-none">
    <div class="text-truncate">
      <div class="fw-semibold text-truncate" id="appSidebarLabel"><?= h($sb_user['full_name']) ?></div>
      <div class="small text-white-50 text-capitalize"><?= h(str_replace('_',' ',$role)) ?></div>
    </div>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close menu"></button>
  </div>
  <div class="sidebar-body pt-3">
    <ul class="nav flex-column">

      <?php if ($role === 'admin'): ?>
        <li class="nav-item"><a class="nav-link <?= nav_active('dashboard.php',$here) ?>" href="<?= BASE_URL ?>/admin/dashboard.php"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('manage_faculty.php',$here) ?>" href="<?= BASE_URL ?>/admin/manage_faculty.php"><i class="fa-solid fa-users-gear"></i> Manage Faculty</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('view_records.php',$here) ?>" href="<?= BASE_URL ?>/admin/view_records.php"><i class="fa-solid fa-folder"></i> Faculty Records</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('search_documents.php',$here) ?>" href="<?= BASE_URL ?>/admin/search_documents.php"><i class="fa-solid fa-magnifying-glass"></i> Search Documents</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('archived_documents.php',$here) ?>" href="<?= BASE_URL ?>/admin/archived_documents.php"><i class="fa-solid fa-box-archive"></i> Archived Documents</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('analytics.php',$here) ?>" href="<?= BASE_URL ?>/admin/analytics.php"><i class="fa-solid fa-chart-column"></i> Data Analytics</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('reports.php',$here) ?>" href="<?= BASE_URL ?>/admin/reports.php"><i class="fa-solid fa-file-lines"></i> Reports</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('training_report.php',$here) ?>" href="<?= BASE_URL ?>/training_report.php"><i class="fa-solid fa-chalkboard-user"></i> Seminar &amp; Training Report</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('classification_review.php',$here) ?>" href="<?= BASE_URL ?>/admin/classification_review.php">
          <i class="fa-solid fa-robot"></i> Classification Review
          <?php $lc = unreviewed_low_confidence_count($pdo); if ($lc): ?><span class="badge bg-warning rounded-pill ms-1"><?= $lc ?></span><?php endif; ?>
        </a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('activity_logs.php',$here) ?>" href="<?= BASE_URL ?>/admin/activity_logs.php"><i class="fa-solid fa-clock-rotate-left"></i> Activity Logs</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('security_alerts.php',$here) ?>" href="<?= BASE_URL ?>/admin/security_alerts.php">
          <i class="fa-solid fa-shield-halved"></i> Security Alerts
          <?php $sa = unresolved_security_alert_count($pdo); if ($sa): ?><span class="badge bg-danger rounded-pill ms-1"><?= $sa ?></span><?php endif; ?>
        </a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('notifications.php',$here) ?>" href="<?= BASE_URL ?>/admin/notifications.php"><i class="fa-solid fa-bell"></i> Notifications</a></li>
      <?php endif; ?>

      <?php if ($role === 'faculty'): ?>
        <li class="nav-item"><a class="nav-link <?= nav_active('dashboard.php',$here) ?>" href="<?= BASE_URL ?>/faculty/dashboard.php"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('submit_document.php',$here) ?>" href="<?= BASE_URL ?>/faculty/submit_document.php"><i class="fa-solid fa-file-arrow-up"></i> Upload Document</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('my_requests.php',$here) ?>" href="<?= BASE_URL ?>/faculty/my_requests.php"><i class="fa-solid fa-list-check"></i> Upload History</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('my_documents.php',$here) ?>" href="<?= BASE_URL ?>/faculty/my_documents.php"><i class="fa-solid fa-folder-open"></i> My 201 File</a></li>
        <li class="nav-item"><a class="nav-link <?= $here === 'archive.php' && empty($_GET['id']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/archive.php"><i class="fa-solid fa-box-archive"></i> My Archive</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('pds.php',$here) ?>" href="<?= BASE_URL ?>/faculty/pds.php"><i class="fa-solid fa-id-card"></i> My PDS</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('notifications.php',$here) ?>" href="<?= BASE_URL ?>/faculty/notifications.php"><i class="fa-solid fa-bell"></i> Notifications</a></li>
      <?php endif; ?>

      <?php if (in_array($role, ['program_chair','dean'], true)): ?>
        <li class="nav-item"><a class="nav-link <?= nav_active('dashboard.php',$here) ?>" href="<?= BASE_URL ?>/approval/dashboard.php"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('submit_document.php',$here) ?>" href="<?= BASE_URL ?>/faculty/submit_document.php"><i class="fa-solid fa-file-arrow-up"></i> Upload Document</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('my_requests.php',$here) ?>" href="<?= BASE_URL ?>/faculty/my_requests.php"><i class="fa-solid fa-list-check"></i> Upload History</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('my_documents.php',$here) ?>" href="<?= BASE_URL ?>/faculty/my_documents.php"><i class="fa-solid fa-folder-open"></i> My 201 File</a></li>
        <li class="nav-item"><a class="nav-link <?= $here === 'archive.php' && empty($_GET['id']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/archive.php"><i class="fa-solid fa-box-archive"></i> My Archive</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('pds.php',$here) ?>" href="<?= BASE_URL ?>/faculty/pds.php"><i class="fa-solid fa-id-card"></i> My PDS</a></li>
        <li class="nav-item"><a class="nav-link <?= in_array($here, ['faculty_directory.php'], true) || (in_array($here, ['profile.php', 'archive.php'], true) && !empty($_GET['id']) && (int)$_GET['id'] !== (int)$sb_user['user_id']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/faculty_directory.php"><i class="fa-solid fa-address-book"></i> Faculty Profiles</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('training_report.php',$here) ?>" href="<?= BASE_URL ?>/training_report.php"><i class="fa-solid fa-chalkboard-user"></i> Seminar &amp; Training Report</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('notifications.php',$here) ?>" href="<?= BASE_URL ?>/approval/notifications.php"><i class="fa-solid fa-bell"></i> Notifications</a></li>
      <?php endif; ?>

      <li class="nav-item border-top mt-2 pt-2"><a class="nav-link <?= $here === 'profile.php' && (empty($_GET['id']) || (int)$_GET['id'] === (int)$sb_user['user_id']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/profile.php"><i class="fa-solid fa-circle-user"></i> My Profile</a></li>
      <li class="nav-item"><a class="nav-link <?= nav_active('change_password.php',$here) ?>" href="<?= BASE_URL ?>/change_password.php"><i class="fa-solid fa-key"></i> Change Password</a></li>
      <li class="nav-item d-md-none"><a class="nav-link text-danger" href="<?= BASE_URL ?>/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></li>

    </ul>
  </div>
</nav>
