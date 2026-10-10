<?php
/**
 * AI (Google Gemini) settings -- TEMPLATE. Copy this file to config/ai.php
 * and put the real API key there. config/ai.php is in .gitignore, so the
 * key never goes into Git; config/.htaccess blocks web access to this
 * folder, and the key is only ever sent to Google in a request header
 * (includes/GeminiService.php) -- never to the browser or a log.
 *
 * To keep the key outside the web folder entirely, save the file anywhere
 * else (e.g. C:\xampp\faculty201-ai.php) and point the AI_CONFIG_FILE
 * environment variable at it. Environment variables also override single
 * settings (for hosting, e.g. Render): GEMINI_API_KEY, GEMINI_MODEL, AI_ENABLED.
 *
 * Get a key at https://aistudio.google.com/apikey
 */
return [
    // Global on / off switch for all AI features (report summary, reminder
    // wording, chatbot). Off: the report and reminders still work (reminders
    // use fixed template messages) and the chatbot button is hidden.
    'ai_enabled'      => true,

    'gemini_api_key'  => 'PASTE-YOUR-GEMINI-API-KEY-HERE',

    // Current stable Gemini Flash model (fast and inexpensive). The model
    // list is at https://ai.google.dev/gemini-api/docs/models -- change only
    // this value to switch models.
    'gemini_model'    => 'gemini-3.8-flash',

    // Seconds to wait for Gemini before giving up (and falling back)
    'request_timeout' => 25,
    'connect_timeout' => 5,

    // Chatbot: messages each user may send per hour, and how many earlier
    // messages are sent along as conversation context
    'chatbot_messages_per_hour' => 30,
    'chatbot_context_messages'  => 6,

    // AI report summary: true sends faculty full names (so "faculty needing
    // attention" names them); false sends "Maria S." style short names.
    'report_send_full_names' => true,

    // Windows XAMPP only, if AI calls fail with "SSL certificate problem":
    // full path to a CA bundle, e.g. 'C:\xampp\php\extras\ssl\cacert.pem'
    // (download from https://curl.se/ca/cacert.pem). Empty = PHP's default.
    'ca_bundle'       => '',
];
