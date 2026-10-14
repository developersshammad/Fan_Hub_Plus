<?php
/**
 * Fan Hub Plus — configuration
 * Edit the database credentials below to match your XAMPP / server setup.
 */

define('DB_HOST', getenv('FH_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('FH_DB_PORT') ?: '3306');
define('DB_NAME', getenv('FH_DB_NAME') ?: 'fanhub_plus');
define('DB_USER', getenv('FH_DB_USER') ?: 'root');
define('DB_PASS', getenv('FH_DB_PASS') !== false ? getenv('FH_DB_PASS') : '');   // XAMPP default: empty

/*
 * MAIL_DEMO = true  -> verification / reset links are also shown on screen and
 *                      saved in the `mail_outbox` table (XAMPP has no SMTP by default).
 * MAIL_DEMO = false -> links are sent only with PHP mail().
 */
define('MAIL_DEMO', true);
define('MAIL_FROM', 'no-reply@fanhubplus.io');

/*
 * ORA chatbot — AI mode (answers ANY question, like ChatGPT / Claude / Gemini).
 * Easiest: Admin → Chatbot → pick a provider, paste an API key, switch "AI answers" on.
 * Values here (or the FH_AI_* environment variables) override the admin settings.
 *   Providers: 'gemini'    — Google Gemini (free key: https://aistudio.google.com/apikey)
 *              'anthropic' — Claude (https://console.anthropic.com)
 *              'openai'    — OpenAI (https://platform.openai.com/api-keys)
 *              'groq'      — Groq, OpenAI-compatible (free tier: https://console.groq.com/keys)
 *              'openrouter'— OpenRouter, OpenAI-compatible (https://openrouter.ai/keys)
 *              'custom'    — any OpenAI-compatible server (set CHAT_AI_BASE_URL, e.g. a local Ollama: http://localhost:11434/v1)
 * Keep the key secret (never commit it).
 */
define('CHAT_AI_PROVIDER', getenv('FH_AI_PROVIDER') ?: '');           // e.g. 'gemini'
define('CHAT_AI_KEY', getenv('FH_AI_KEY') ?: '');                     // e.g. 'AIza...' / 'sk-ant-...' / 'sk-...'
define('CHAT_AI_MODEL', getenv('FH_AI_MODEL') ?: '');                 // empty = provider default
define('CHAT_AI_BASE_URL', getenv('FH_AI_BASE_URL') ?: '');           // empty = provider default endpoint
define('CHAT_RATE_LIMIT', 20);                                        // messages per minute per visitor
define('CHAT_IMAGE_MAX_BYTES', 4 * 1024 * 1024);                      // images visitors can attach for the AI to look at

define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_MAX_BYTES', 3 * 1024 * 1024);   // 3 MB per image

// Base URL is detected automatically (works in http://localhost/fanhub/ and in a domain root).
// Override it by setting e.g. define('BASE_URL', '/fanhub/'); here if needed.
if (!defined('BASE_URL')) {
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $appRoot = realpath(APP_ROOT);
    $base = '/';
    if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
        $base = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        $base = '/' . trim($base, '/') . '/';
        $base = preg_replace('#/+#', '/', $base);
    }
    define('BASE_URL', $base);
}

date_default_timezone_set('Asia/Karachi');
error_reporting(E_ALL);
ini_set('display_errors', '0');
