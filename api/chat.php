<?php
/**
 * ORA chatbot API
 *   GET  ?action=history            last messages of this visitor / member (rich cards restored)
 *   POST action=send  message=...   [image=<file>] [stream=1]
 *        → rules answer instantly when they are sure (cards + quick actions);
 *          anything else goes to the AI (when configured) and streams back as NDJSON lines:
 *            {"t":"start"} {"t":"delta","text":"..."} … {"t":"done", ...final reply...}   or   {"t":"error","error":"..."}
 *        Without stream=1 (or for rule answers) the reply is one JSON object.
 *   POST action=feedback id= value=1|0   thumbs up / down on an answer
 *   POST action=clear               start a fresh conversation (history kept for admin analytics)
 */
// Always answer in JSON — even when PHP hits a fatal error. Shared hosts (InfinityFree etc.) would otherwise
// send an HTML error page, which the chat shows as "Unexpected server response".
@ini_set('display_errors', '0');
ob_start();
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    while (ob_get_level() > 0) @ob_end_clean();
    error_log('ORA fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    $admin = false;
    try { $admin = function_exists('is_admin') && is_admin(); } catch (Throwable $x) {}
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    $out = ['ok' => false, 'error' => 'ORA hit a server error. Please try again in a moment.'];
    // the exact cause is shown to admins only (it can contain file paths or database names)
    if ($admin) $out['detail'] = basename($e['file']) . ':' . $e['line'] . ' — ' . mb_substr(preg_replace('/\s+/', ' ', $e['message']), 0, 300);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
});

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/chatbot_engine.php';
ensure_schema();

$user = current_user();
$uid  = $user ? (int)$user['user_id'] : null;
if (empty($_SESSION['chat_sid'])) $_SESSION['chat_sid'] = bin2hex(random_bytes(16));
$sid  = $_SESSION['chat_sid'];
$after = (int)($_SESSION['chat_cleared_after'] ?? 0);

$action = $_REQUEST['action'] ?? 'send';

if ($action === 'history') {
    $sql = 'SELECT query_id, message, response, intent, meta, helpful FROM (SELECT * FROM chatbot_queries WHERE %s AND query_id > ? ORDER BY query_id DESC LIMIT 20) t ORDER BY query_id';
    $rows = $uid ? q_all(sprintf($sql, 'user_id = ?'), [$uid, $after]) : q_all(sprintf($sql, 'session_id = ?'), [$sid, $after]);
    $out = [];
    foreach ($rows as $r) {
        $meta = json_decode((string)$r['meta'], true) ?: [];
        $out[] = ['id' => (int)$r['query_id'], 'message' => $r['message'], 'reply' => $r['response'], 'intent' => $r['intent'], 'image' => !empty($meta['image']),
                  'cards' => $meta['cards'] ?? [], 'helpful' => $r['helpful'] === null ? null : (int)$r['helpful']];
    }
    $ai = ora_ai_enabled();
    json_out(['ok' => true, 'history' => $out, 'ai' => $ai, 'aiLabel' => $ai ? ora_ai_config()['label'] : '']);
}

require_post_csrf_json();

if ($action === 'clear') {
    $_SESSION['chat_cleared_after'] = (int)q_val('SELECT COALESCE(MAX(query_id),0) FROM chatbot_queries');
    unset($_SESSION['chat_tour'], $_SESSION['ora_ctx']);
    json_out(['ok' => true]);
}

if ($action === 'feedback') {
    $id = (int)($_POST['id'] ?? 0);
    $val = ($_POST['value'] ?? '') === '1' ? 1 : 0;
    // only the owner of the exchange can rate it
    $owner = $uid ? 'user_id = ?' : 'session_id = ?';
    q("UPDATE chatbot_queries SET helpful = ? WHERE query_id = ? AND $owner", [$val, $id, $uid ?: $sid]);
    json_out(['ok' => true, 'message' => $val ? 'Thanks for the feedback!' : 'Thanks — we\'ll use this to improve ORA.']);
}

/* ---- send ---- */
// The browser sends the message base64-encoded (m64) so hosting firewalls (mod_security) don't block
// ordinary text that merely looks like code or SQL — apostrophes, <tags>, "SELECT … FROM", etc.
if (isset($_POST['m64']) && $_POST['m64'] !== '') {
    $dec = base64_decode(strtr((string)$_POST['m64'], ' ', '+'), true);
    if ($dec !== false && mb_check_encoding($dec, 'UTF-8')) $_POST['message'] = $dec;
}
$msg = trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', (string)($_POST['message'] ?? ''))));
$msg = mb_substr($msg, 0, 4000);

// optional image for the AI to look at
$image = null;
if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['image'];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > CHAT_IMAGE_MAX_BYTES) json_out(['ok' => false, 'error' => 'That image is too large — please use one under ' . round(CHAT_IMAGE_MAX_BYTES / 1048576) . ' MB.'], 422);
    $mime = function_exists('finfo_open') ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']) : (getimagesize($f['tmp_name'])['mime'] ?? '');
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) json_out(['ok' => false, 'error' => 'Please attach a JPG, PNG, WebP or GIF image.'], 422);
    $image = ['mime' => $mime, 'data' => base64_encode(file_get_contents($f['tmp_name']))];
}
if ($msg === '' && !$image) json_out(['ok' => false, 'error' => 'Type a message first.'], 422);

// simple per-visitor rate limit (sliding one-minute window)
$now = time();
$_SESSION['chat_times'] = array_values(array_filter($_SESSION['chat_times'] ?? [], fn($t) => $t > $now - 60));
if (count($_SESSION['chat_times']) >= CHAT_RATE_LIMIT) {
    json_out(['ok' => false, 'error' => 'Whoa, that was fast! Give me a few seconds and try again.'], 429);
}
$_SESSION['chat_times'][] = $now;

$save = function (array $r) use ($uid, $sid, $msg, $image): int {
    $meta = json_encode(['cards' => $r['cards'], 'image' => (bool)$image], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    q('INSERT INTO chatbot_queries (user_id, session_id, message, response, intent, answered, meta) VALUES (?,?,?,?,?,?,?)',
      [$uid, $sid, $msg !== '' ? $msg : '[image]', $r['reply'], $r['intent'], $r['answered'] ? 1 : 0, $meta]);
    return (int)db()->lastInsertId();
};
$final = fn(array $r, int $id) => ['ok' => true, 'id' => $id, 'reply' => $r['reply'], 'cards' => $r['cards'], 'actions' => $r['actions'], 'intent' => $r['intent'], 'lang' => $r['lang'] ?? 'en'];

try {
    $r = $msg !== '' ? ora_reply($msg, $user) : ['reply' => '', 'cards' => [], 'actions' => [], 'intent' => 'fallback', 'answered' => false, 'lang' => 'en'];
} catch (Throwable $ex) {
    error_log('ORA error: ' . $ex->getMessage());
    $r = ['reply' => 'Something went wrong on my side — please try again in a moment.', 'cards' => [], 'actions' => [], 'intent' => 'error', 'answered' => false, 'lang' => 'en'];
}
$lang = $r['lang'] ?? 'en';
$forceAI = !empty($_POST['regen']) || $image;
$useAI = ora_ai_enabled() && ($forceAI || !ora_is_sure($r, $msg));

if ($image && !ora_ai_enabled()) {
    $r = ['reply' => 'I can look at images once AI mode is switched on — the site admin can enable it in **Admin → Chatbot**. Meanwhile, ask me in words!', 'cards' => [], 'actions' => ora_default_actions($lang), 'intent' => 'fallback', 'answered' => false, 'lang' => $lang];
}
if (!$useAI) {
    $id = $save($r);
    json_out($final($r, $id));
}

/* ---- AI answer, streamed ---- */
$stream = !empty($_POST['stream']);
if ($stream) {
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) @ob_end_clean();     // discard stray output, then stream
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    ignore_user_abort(false);
    session_write_close();                       // don't lock the session while the answer streams
}
$emit = function (array $line) use ($stream) {
    if (!$stream) return;
    echo json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    @flush();
};
$emit(['t' => 'start']);
$pending = ''; $lastFlush = microtime(true);
$ctx = $_SESSION['ora_ctx'] ?? [];
$res = ora_ai_stream($msg, $user, is_array($ctx) ? $ctx : [], $lang, $image, function (string $d) use (&$pending, &$lastFlush, $emit) {
    $pending .= $d;
    if (microtime(true) - $lastFlush > 0.05 || mb_strlen($pending) > 120) { $emit(['t' => 'delta', 'text' => $pending]); $pending = ''; $lastFlush = microtime(true); }
});
if ($pending !== '') $emit(['t' => 'delta', 'text' => $pending]);

if ($res['ok'] || trim($res['text']) !== '') {
    $text = trim($res['text']);
    $r = ['reply' => $text, 'cards' => ora_cards_from_text($text), 'actions' => [], 'intent' => 'ai', 'answered' => true, 'lang' => $lang];
} else {
    // AI unavailable → the rule engine's own answer, with the reason in the error log
    if ($r['intent'] === 'fallback' || $r['intent'] === 'didyoumean' || $image) {
        $r['reply'] = $res['error'] . ($r['intent'] !== 'fallback' || $image ? '' : "\n\n" . $r['reply']);
        $r['answered'] = false;
    }
}
$id = $save($r);
if ($stream) { $emit(['t' => 'done'] + $final($r, $id)); exit; }
json_out($final($r, $id));
