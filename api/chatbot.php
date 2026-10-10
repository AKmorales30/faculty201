<?php
/**
 * Chatbot endpoint (assets/js/chatbot.js). POST only, signed-in users,
 * CSRF token required; answers JSON.
 *
 *   action=history   the user's saved conversation (last 50 messages)
 *   action=send      message=... -> the assistant's answer (includes/ai_chatbot.php)
 *   action=clear     delete the user's own conversation
 *
 * Limits: chatbot_messages_per_hour per user (config/ai.php), counted in
 * ai_usage_log so clearing the chat doesn't reset it; only the last
 * chatbot_context_messages messages go to Gemini as context. AI text is
 * returned as HTML built by ai_format_html(), which escapes everything first.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_chatbot.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function chatbot_reply(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatbot_reply(['ok' => false, 'error' => 'POST only.'], 405);
}
if (!is_logged_in()) {
    chatbot_reply(['ok' => false, 'error' => 'Your session has ended. Please log in again.'], 401);
}
if (!empty($_SESSION['user']['must_change_password'])) {
    chatbot_reply(['ok' => false, 'error' => 'Please change your temporary password first.'], 403);
}
if (!csrf_valid()) {
    chatbot_reply(['ok' => false, 'error' => 'Your session expired. Please reload the page and try again.'], 403);
}
$me = current_user();
$uid = (int)$me['user_id'];
session_write_close();   // an answer can take several seconds; don't block the user's other pages meanwhile

$config = ai_config();
$html_of = fn(array $rows): array => array_map(fn($m) => [
    'role' => $m['role'],
    'html' => $m['role'] === 'assistant' ? ai_format_html($m['message']) : nl2br(h($m['message'])),
], $rows);

try {
    switch ($_POST['action'] ?? '') {
        case 'history':
            chatbot_reply(['ok' => true, 'messages' => $html_of(chatbot_history($pdo, $uid, 50))]);

        case 'clear':
            $pdo->prepare("DELETE FROM ai_chat_messages WHERE user_id = ?")->execute([$uid]);
            chatbot_reply(['ok' => true]);

        case 'send':
            $message = trim(preg_replace('/[^\P{C}\n]+/u', '', (string)($_POST['message'] ?? '')));
            if ($message === '') {
                chatbot_reply(['ok' => false, 'error' => 'Please type a question.'], 400);
            }
            if (mb_strlen($message) > 1000) {
                chatbot_reply(['ok' => false, 'error' => 'Please keep your question under 1,000 characters.'], 400);
            }
            if (!ai_enabled()) {
                chatbot_reply(['ok' => false, 'error' => 'The assistant is turned off right now. For help, please contact the Admin.']);
            }
            $limit = max(1, (int)$config['chatbot_messages_per_hour']);
            $used = chatbot_messages_last_hour($pdo, $uid);
            if ($used['count'] >= $limit) {
                $wait = max(1, (int)ceil((strtotime($used['oldest']) + 3600 - time()) / 60));
                chatbot_reply(['ok' => false, 'limited' => true,
                    'error' => "You've reached the limit of {$limit} messages per hour. Please try again in about {$wait} minute" . ($wait === 1 ? '' : 's') . '. Thank you for your patience!']);
            }

            $pdo->prepare("INSERT INTO ai_chat_messages (user_id, role, message) VALUES (?, 'user', ?)")->execute([$uid, $message]);
            $question_id = (int)$pdo->lastInsertId();
            set_time_limit(150);
            $answer = chatbot_answer($pdo, $me, chatbot_history($pdo, $uid, max(1, (int)$config['chatbot_context_messages'])));
            if ($answer === null) {
                // Not kept, so the conversation (and the next question's context) stays question-answer
                $pdo->prepare("DELETE FROM ai_chat_messages WHERE id = ? AND user_id = ?")->execute([$question_id, $uid]);
                chatbot_reply(['ok' => false, 'error' => "Sorry, I can't answer right now -- the AI service is unavailable. Please try again in a few minutes. For urgent concerns, please contact the Admin."]);
            }
            $pdo->prepare("INSERT INTO ai_chat_messages (user_id, role, message) VALUES (?, 'assistant', ?)")->execute([$uid, $answer]);
            chatbot_reply(['ok' => true, 'html' => ai_format_html($answer)]);
    }
    chatbot_reply(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log('Chatbot failed: ' . $e->getMessage());
    chatbot_reply(['ok' => false, 'error' => 'Something went wrong. Please try again in a moment.'], 500);
}
