-- =====================================================================
-- OPTIONAL: remove the AI chatbot's stored data.
--
-- The chatbot was removed from the system; nothing reads or writes these
-- rows any more. Running this script is your choice -- the system works
-- the same whether or not you run it. Back up the database first.
--
--   mysql -u root faculty201_repository < database/drop_ai_chatbot.sql
--
-- Kept on purpose: the ai_usage_log table itself and its 'report' /
-- 'reminder' rows (AI usage of the report summary and reminder wording),
-- and ai_reports / ai_reminders.
-- =====================================================================

USE faculty201_repository;

-- 1. The chatbot conversations (one row per message). Cannot be undone.
DROP TABLE IF EXISTS ai_chat_messages;

-- 2. (Optional) The chatbot's lines in the AI usage log. Leave this
--    commented out to keep them as a record of past use.
-- DELETE FROM ai_usage_log WHERE feature = 'chatbot';
