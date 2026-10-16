<?php
/**
 * ORA chat — hosting diagnostic.
 * Open https://your-site/ora-check.php in the browser. It sends a series of small test requests and shows
 * which ones the host lets through, so we can see exactly what a firewall (e.g. InfinityFree's) is blocking.
 * Safe to keep, but you can delete it once the chat works.
 */
if (isset($_REQUEST['t']) && $_REQUEST['t'] === 'ping') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'method' => $_SERVER['REQUEST_METHOD'], 'fields' => array_keys($_POST + $_GET)]);
    exit;
}
require_once __DIR__ . '/includes/functions.php';     // session + CSRF token only (no database query here)
$csrf = csrf_token();
$php  = PHP_VERSION;
$ext  = ['curl' => function_exists('curl_init'), 'mbstring' => function_exists('mb_strlen'), 'pdo_mysql' => extension_loaded('pdo_mysql'), 'fileinfo' => function_exists('finfo_open')];
$files = [];
foreach (['api/chat.php', 'chat-api.php', 'includes/chatbot_engine.php', 'assets/js/app.js'] as $f) $files[$f] = is_file(__DIR__ . '/' . $f) ? date('Y-m-d H:i', filemtime(__DIR__ . '/' . $f)) . ' · ' . filesize(__DIR__ . '/' . $f) . ' bytes' : 'MISSING';
$db = 'not tested';
try { db()->query('SELECT 1'); $db = 'connected'; } catch (Throwable $e) { $db = 'failed'; }
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ORA Chat Check</title>
<style>
  :root { --bg:#0b0b12; --card:#14141f; --line:#26263a; --fg:#ece9f4; --mut:#9a96ad; --ok:#34d399; --bad:#f87171; --acc:#8b5cf6; }
  * { box-sizing:border-box } body { margin:0; background:var(--bg); color:var(--fg); font:14px/1.5 system-ui,-apple-system,Segoe UI,sans-serif; padding:24px max(16px,env(safe-area-inset-left)) }
  main { max-width:880px; margin:0 auto; display:grid; gap:18px } h1 { margin:0; font-size:22px } p { margin:0; color:var(--mut) }
  .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px; overflow-x:auto }
  table { width:100%; border-collapse:collapse; font-size:13px } td, th { text-align:left; padding:7px 8px; border-bottom:1px solid var(--line); vertical-align:top }
  th { color:var(--mut); font-weight:600 } .ok { color:var(--ok); font-weight:700 } .bad { color:var(--bad); font-weight:700 } code { font:12px ui-monospace,Menlo,Consolas,monospace; color:#c4b5fd }
  .sum { font-size:15px } button { background:var(--acc); color:#fff; border:0; border-radius:999px; padding:9px 16px; font-weight:600; cursor:pointer }
</style></head>
<body><main>
  <div><h1>ORA chat — hosting check</h1><p>Take one screenshot of this whole page and send it. Nothing here changes your site.</p></div>
  <div class="card"><table>
    <tr><th>PHP</th><td><?= htmlspecialchars($php) ?></td></tr>
    <tr><th>Extensions</th><td><?php foreach ($ext as $k => $v) echo '<span class="' . ($v ? 'ok' : 'bad') . '">' . $k . '</span> &nbsp; '; ?></td></tr>
    <tr><th>Database</th><td class="<?= $db === 'connected' ? 'ok' : 'bad' ?>"><?= $db ?></td></tr>
    <?php foreach ($files as $f => $info) echo '<tr><th><code>' . $f . '</code></th><td class="' . ($info === 'MISSING' ? 'bad' : '') . '">' . htmlspecialchars($info) . '</td></tr>'; ?>
  </table></div>
  <div class="card"><p class="sum" id="sum">Running tests…</p><table id="t"><tr><th>#</th><th>Request</th><th>HTTP</th><th>Result</th></tr></table></div>
  <div><button id="again" type="button">Run again</button></div>
</main>
<script>
(function () {
  var CSRF = <?= json_encode($csrf) ?>;
  var hex = Array.prototype.map.call(new TextEncoder().encode("What's trending in anime this week?"), function (b) { return (b < 16 ? '0' : '') + b.toString(16); }).join('');
  function form(o) { var p = new URLSearchParams(); Object.keys(o).forEach(function (k) { p.append(k, o[k]); }); return p; }
  function fd(o) { var f = new FormData(); Object.keys(o).forEach(function (k) { f.append(k, o[k]); }); return f; }
  var tests = [
    ['GET ping', 'ora-check.php?t=ping', {}],
    ['POST ping (form)', 'ora-check.php', { method: 'POST', body: form({ t: 'ping' }) }],
    ['POST ping (multipart)', 'ora-check.php', { method: 'POST', body: fd({ t: 'ping' }) }],
    ['POST + X-CSRF-Token header', 'ora-check.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: form({ t: 'ping' }) }],
    ['POST field action=send', 'ora-check.php', { method: 'POST', body: form({ t: 'ping', action: 'send', stream: '1' }) }],
    ['POST field mh=<hex text>', 'ora-check.php', { method: 'POST', body: form({ t: 'ping', mh: hex }) }],
    ['POST message=hello', 'ora-check.php', { method: 'POST', body: form({ t: 'ping', message: 'hello' }) }],
    ["POST message with apostrophe", 'ora-check.php', { method: 'POST', body: form({ t: 'ping', message: "What's trending in anime this week?" }) }],
    ['GET api/chat.php history', 'api/chat.php?action=history', {}],
    ['GET chat-api.php history', 'chat-api.php?action=history', {}],
    ['POST api/chat.php send "hello"', 'api/chat.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: form({ action: 'send', message: 'hello' }) }],
    ['POST chat-api.php send "hello"', 'chat-api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: form({ action: 'send', message: 'hello' }) }],
    ['POST chat-api.php send (hex)', 'chat-api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: form({ action: 'send', mh: hex }) }]
  ];
  function run() {
    var t = document.getElementById('t'); while (t.rows.length > 1) t.deleteRow(1);
    var pass = 0, fail = [];
    var chain = Promise.resolve();
    tests.forEach(function (x, i) {
      chain = chain.then(function () {
        var init = Object.assign({ credentials: 'same-origin', cache: 'no-store' }, x[2]);
        return fetch(x[1], init).then(function (r) {
          return r.text().then(function (body) {
            var j = null; try { j = JSON.parse(body); } catch (e) {}
            var ok = r.status < 400 && j && typeof j === 'object';
            var note = ok ? (j.ok === false && j.error ? 'reached PHP: ' + j.error : 'OK') : body.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 90);
            return { status: r.status, ok: ok, note: note };
          });
        }).catch(function (e) { return { status: '—', ok: false, note: 'network error: ' + e.message }; }).then(function (res) {
          var row = t.insertRow(); row.insertCell().textContent = i + 1; row.insertCell().textContent = x[0];
          row.insertCell().textContent = res.status;
          var c = row.insertCell(); c.textContent = (res.ok ? '✔ ' : '✘ ') + res.note; c.className = res.ok ? 'ok' : 'bad';
          if (res.ok) pass++; else fail.push(i + 1);
        });
      });
    });
    chain.then(function () { document.getElementById('sum').textContent = pass + ' of ' + tests.length + ' passed' + (fail.length ? ' — blocked: #' + fail.join(', #') : ' — the host allows everything the chat needs.'); });
  }
  document.getElementById('again').onclick = run;
  run();
})();
</script>
</body></html>
