<?php
/**
 * Floating "201 File Assistant" chat button + panel on every page after
 * login (included by includes/footer.php). Hidden while AI is turned off
 * (config/ai.php) and while a temporary password must still be changed.
 * Behaviour: assets/js/chatbot.js; answers: api/chatbot.php.
 */
require_once __DIR__ . '/ai_chatbot.php';
$cb_user = current_user();
if (!$cb_user || !empty($cb_user['must_change_password']) || !ai_enabled()) { return; }
?>
<div class="chatbot no-print" id="chatbot"
     data-endpoint="<?= h(BASE_URL . '/api/chatbot.php') ?>"
     data-csrf="<?= h(csrf_token()) ?>"
     data-first-name="<?= h(ai_first_name($cb_user['full_name'])) ?>"
     data-suggestions="<?= h(json_encode(chatbot_suggestions($cb_user), JSON_UNESCAPED_UNICODE)) ?>">
  <section class="chatbot-panel card" id="chatbotPanel" role="dialog" aria-labelledby="chatbotTitle" hidden>
    <header class="chatbot-header d-flex align-items-center gap-2">
      <i class="fa-solid fa-robot" aria-hidden="true"></i>
      <div class="flex-grow-1 min-w-0">
        <div class="fw-semibold" id="chatbotTitle">201 File Assistant</div>
        <div class="small chatbot-subtitle">Ask about your 201 file<?= chatbot_has_scope($cb_user) ? ' or your faculty' : '' ?></div>
      </div>
      <button type="button" class="btn btn-sm btn-link text-white chatbot-clear" data-tooltip title="Clear chat" aria-label="Clear chat"><i class="fa-solid fa-trash-can"></i></button>
      <button type="button" class="btn btn-sm btn-link text-white chatbot-close" data-tooltip title="Close" aria-label="Close chat"><i class="fa-solid fa-xmark fa-lg"></i></button>
    </header>
    <div class="chatbot-messages" aria-live="polite"></div>
    <div class="chatbot-typing" hidden><span></span><span></span><span></span><span class="visually-hidden">The assistant is typing</span></div>
    <form class="chatbot-form d-flex gap-2 align-items-end">
      <textarea class="form-control form-control-sm" rows="1" maxlength="1000" placeholder="Type your question..." aria-label="Your question" required></textarea>
      <button class="btn btn-brand btn-sm" type="submit" data-tooltip title="Send" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
    </form>
    <div class="chatbot-note">AI-generated content may contain errors. Please review before use.</div>
  </section>
  <button type="button" class="chatbot-toggle" aria-controls="chatbotPanel" aria-expanded="false" title="201 File Assistant">
    <i class="fa-solid fa-comments" aria-hidden="true"></i><span class="visually-hidden">Open the 201 File Assistant</span>
  </button>
</div>
<script src="<?= BASE_URL ?>/assets/js/chatbot.js"></script>
