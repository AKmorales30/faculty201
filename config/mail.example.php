<?php
/**
 * Email settings -- TEMPLATE. Copy this file to config/mail.php and put the
 * real token there. config/mail.php is in .gitignore, so it never goes into
 * Git; config/.htaccess blocks web access to this folder.
 *
 * The login codes (two-factor), email verification and password reset all
 * send through a web API (includes/mailer.php) -- not SMTP, which Render's
 * free plan blocks. Postmark is the default; SendGrid also works.
 *
 * Postmark setup: https://account.postmarkapp.com
 *   1. Sender Signatures: add the address the emails come from and click
 *      the confirmation link Postmark emails to it (or verify a whole
 *      domain you own). Postmark doesn't accept free addresses such as
 *      @gmail.com as the sender -- use an address at a domain you own.
 *   2. Servers > your server > API Tokens: copy the Server API token.
 *   3. Until Postmark approves the account ("Request approval"), it only
 *      delivers to addresses at the sender's own domain.
 *
 * On Render, set environment variables instead (they override this file):
 * POSTMARK_SERVER_TOKEN, MAIL_FROM_EMAIL, MAIL_FROM_NAME (and MAIL_PROVIDER
 * only to use SendGrid: MAIL_PROVIDER=sendgrid + SENDGRID_API_KEY).
 */
return [
    'provider'              => 'postmark',   // 'postmark' or 'sendgrid'

    'postmark_server_token' => 'PASTE-YOUR-POSTMARK-SERVER-API-TOKEN-HERE',
    'sendgrid_api_key'      => '',           // only with 'provider' => 'sendgrid'

    // Must be a confirmed Sender Signature (step 1 above)
    'from_email'            => 'PASTE-YOUR-CONFIRMED-SENDER-EMAIL-HERE',
    'from_name'             => 'Faculty-201 File Management System',

    // Local testing only: write every email to this file instead of sending
    // it (codes included -- never set this on a real server). Empty = send.
    'log_file'              => '',
];
