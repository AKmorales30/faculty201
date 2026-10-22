<?php
/**
 * Privacy policy -- public, no login needed. Linked from the landing page
 * footer, and the address given to Google as the app's privacy policy (the
 * Gmail API that sends login codes). Describes what the system actually
 * stores and sends; keep it in step when that changes.
 */
require_once __DIR__ . '/includes/auth.php';

$updated = 'October 22, 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Policy — <?= h(SYSTEM_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Public+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?= h(admin_asset('assets/css/landing.css')) ?>" rel="stylesheet">
</head>
<body class="landing">

<header class="ld-header">
  <h1 class="ld-university">Universidad de Manila</h1>
</header>

<main class="ld-doc">
  <a class="ld-back" href="<?= BASE_URL ?>/index.php">&larr; Back to log in</a>

  <h2 class="ld-doc-title">Privacy Policy</h2>
  <p class="ld-doc-meta"><?= h(SYSTEM_NAME) ?> &middot; College of Computing Studies, Universidad de Manila &middot; Last updated <?= h($updated) ?></p>

  <p>The <?= h(SYSTEM_NAME) ?> ("the system") is used by the College of Computing Studies of Universidad de Manila to upload, store and monitor the
    201-file documents of its faculty. Only accounts created by the system administrator can log in. This policy explains what information the
    system keeps, why, and who can see it.</p>

  <h3>Information the system keeps</h3>
  <ul>
    <li><strong>Account details:</strong> your name, username, role, program or college, academic rank, employment details and a password (stored only
      as a one-way hash, never readable).</li>
    <li><strong>Your 201 file:</strong> the documents you or the administrator upload (for example certificates, seminar and training records and your
      Personal Data Sheet), with the details entered for them.</li>
    <li><strong>Profile:</strong> an optional profile picture, contact number and field of specialization.</li>
    <li><strong>Optional email:</strong> if you choose to add one, the email address you verify. It is used only for the purposes listed below.</li>
    <li><strong>Security records:</strong> login attempts, activity logs and security alerts, including the time, IP address and browser of each
      action, to protect accounts and investigate problems.</li>
  </ul>

  <h3>How your email is used</h3>
  <p>Adding an email is optional. If you add and verify one, the system uses it only to send:</p>
  <ul>
    <li>one-time login codes, if you turn on two-factor authentication;</li>
    <li>codes to verify an email address or to reset a forgotten password;</li>
    <li>notices about changes to your account's security (for example a changed password or email).</li>
  </ul>
  <p>These emails are sent from <strong>udmccsfaculty201.ms@gmail.com</strong> through Google's Gmail API. The system only has permission to
    <em>send</em> email from that address; it cannot read anyone's mailbox. Your email is never used for marketing and is never sold or shared.
    Codes are stored only in a scrambled (hashed) form and stop working after a few minutes.</p>

  <h3>Who can see your information</h3>
  <ul>
    <li><strong>You</strong> can see your own profile and 201 file.</li>
    <li><strong>The administrator</strong> can see and manage all accounts and 201 files.</li>
    <li><strong>Deans and Program Chairs</strong> can see summary information (such as document counts) for the faculty in their college or program,
      but cannot open faculty files.</li>
  </ul>

  <h3>Services the system uses</h3>
  <ul>
    <li><strong>Render</strong> hosts the website, and <strong>Supabase</strong> hosts the database where the information above is stored.</li>
    <li><strong>Google Gmail API</strong> sends the emails described above.</li>
    <li><strong>Google Gemini</strong> (when the AI features are turned on) helps write report summaries and reminders. It receives only what those
      features need, such as names, document categories, dates and seminar titles, and never ID numbers, addresses, birth dates, contact numbers,
      email addresses, passwords or the contents of your files.</li>
  </ul>

  <h3>Cookies and browser storage</h3>
  <p>The system uses one session cookie to keep you logged in, and your browser's storage to remember small display choices (such as which menu
    sections you closed). There are no advertising or tracking cookies.</p>

  <h3>How long information is kept</h3>
  <p>Your information is kept while you have an account. Deleted accounts and documents can be restored for <?= (int)DELETED_RETENTION_DAYS ?> days and are
    then removed. You can remove your email at any time by asking the administrator, and change it yourself in Profile Preference.</p>

  <h3>Your choices and questions</h3>
  <p>You can update your profile, email and two-factor authentication in Profile Preference. For anything else, such as correcting or removing
    information, contact the system administrator of the College of Computing Studies, or email
    <a href="mailto:udmccsfaculty201.ms@gmail.com">udmccsfaculty201.ms@gmail.com</a>.</p>
</main>

<footer class="ld-footer">
  <p><?= date('Y') ?> © Universidad de Manila — College of Computing Studies</p>
  <p>Developed by Arnesto, Austria, Morales</p>
</footer>

</body>
</html>
