<?php
/**
 * Email settings -- TEMPLATE. Copy this file to config/mail.php and put the
 * real values there. config/mail.php is in .gitignore, so it never goes into
 * Git; config/.htaccess blocks web access to this folder.
 *
 * The login codes (two-factor), email verification and password reset all
 * send through a web API (includes/mailer.php) -- not SMTP, which Render's
 * free plan blocks. Gmail (Google's Gmail API) is the default: the emails
 * come from your own Gmail address, free, up to about 500 a day.
 *
 * Gmail setup (once), signed in as the Gmail account that will send:
 *   1. https://console.cloud.google.com -- create a project, then
 *      APIs & Services > Library > "Gmail API" > Enable.
 *      APIs & Services > OAuth consent screen: User type External; fill in
 *      the app name and your email; add your Gmail as a Test user; then
 *      click "Publish app" (In production). Leave it in Testing and the
 *      refresh token stops working after 7 days. (Google shows an
 *      "unverified app" warning on the next step -- Advanced > Go to ...
 *      is fine, it's your own app.)
 *      APIs & Services > Credentials > Create credentials > OAuth client ID:
 *      type Web application, Authorized redirect URI
 *      https://developers.google.com/oauthplayground -- copy the Client ID
 *      and Client secret.
 *   2. https://developers.google.com/oauthplayground -- gear icon (top
 *      right): tick "Use your own OAuth credentials", paste the Client ID
 *      and secret. In "Input your own scopes" type
 *      https://www.googleapis.com/auth/gmail.send, Authorize APIs, sign in
 *      with the sending Gmail, allow; then "Exchange authorization code for
 *      tokens" and copy the Refresh token. (A new one is needed only if
 *      access is removed at myaccount.google.com > Security > Third-party
 *      connections, or the Gmail password changes.)
 *   3. from_email below = that same Gmail address.
 *
 * On Render, set environment variables instead (they override this file):
 * GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REFRESH_TOKEN,
 * MAIL_FROM_EMAIL, MAIL_FROM_NAME. (Postmark or SendGrid still work:
 * MAIL_PROVIDER=postmark + POSTMARK_SERVER_TOKEN, or MAIL_PROVIDER=sendgrid
 * + SENDGRID_API_KEY, with a sender address they have confirmed.)
 */
return [
    'provider'              => 'gmail',      // 'gmail', 'postmark' or 'sendgrid'

    'google_client_id'      => 'PASTE-YOUR-GOOGLE-CLIENT-ID-HERE',
    'google_client_secret'  => 'PASTE-YOUR-GOOGLE-CLIENT-SECRET-HERE',
    'google_refresh_token'  => 'PASTE-YOUR-GOOGLE-REFRESH-TOKEN-HERE',

    'postmark_server_token' => '',           // only with 'provider' => 'postmark'
    'sendgrid_api_key'      => '',           // only with 'provider' => 'sendgrid'

    // Gmail: the account that authorized step 2
    'from_email'            => 'PASTE-YOUR-GMAIL-ADDRESS-HERE',
    'from_name'             => 'Faculty-201 File Management System',

    // Local testing only: write every email to this file instead of sending
    // it (codes included -- never set this on a real server). Empty = send.
    'log_file'              => '',
];
