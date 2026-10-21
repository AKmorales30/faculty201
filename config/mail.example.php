<?php
/**
 * Email (SendGrid) settings -- TEMPLATE. Copy this file to config/mail.php
 * and put the real API key there. config/mail.php is in .gitignore, so the
 * key never goes into Git; config/.htaccess blocks web access to this folder.
 *
 * The login codes (two-factor), email verification and password reset all
 * send through SendGrid's web API (includes/mailer.php) -- not SMTP, which
 * Render's free plan blocks.
 *
 * SendGrid setup: https://app.sendgrid.com
 *   1. Settings > Sender Authentication > Verify a Single Sender: the
 *      address the emails come from (e.g. your Gmail); click the link
 *      SendGrid emails you.
 *   2. Settings > API Keys > Create API Key, "Restricted Access" with
 *      Mail Send only. Copy the key (shown once).
 *
 * On Render, set environment variables instead (they override this file):
 * SENDGRID_API_KEY, MAIL_FROM_EMAIL, MAIL_FROM_NAME.
 */
return [
    'sendgrid_api_key' => 'PASTE-YOUR-SENDGRID-API-KEY-HERE',

    // Must be the verified Single Sender address (step 1 above)
    'from_email'       => 'PASTE-YOUR-VERIFIED-SENDER-EMAIL-HERE',
    'from_name'        => 'Faculty-201 File Management System',

    // Local testing only: write every email to this file instead of sending
    // it (codes included -- never set this on a real server). Empty = send.
    'log_file'         => '',
];
