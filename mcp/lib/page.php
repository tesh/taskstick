<?php
/**
 * Shared HTML shell for the OAuth consent screen, OAuth error page and the
 * MCP setup page — one stylesheet so they stay visually consistent.
 */

function mcpH(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Safely embed a PHP string as a JS string literal inside an HTML attribute. */
function mcpJsStr(string $val): string {
    return htmlspecialchars(json_encode($val), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mcpPageStart(string $title): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');                       // no clickjacking of the consent screen
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= mcpH($title) ?> — TaskStick</title>
<link rel="icon" href="/icons/favicon-32.png">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg: #f0f2f5; --card: #fff; --text: #222; --muted: #666; --faint: #999;
  --accent: #6366f1; --accent-soft: #eef0ff; --border: #d0d7de; --code-bg: #1a1a2e;
  --ok-bg: #e8f5e9; --ok: #1b5e20; --warn-bg: #fff8e1; --warn: #6b4c00; --err-bg: #fdecea; --err: #8b1c1c;
}
@media (prefers-color-scheme: dark) {
  :root { --bg: #15161c; --card: #1f2029; --text: #e8e8ee; --muted: #a8a9b5; --faint: #7b7c88;
          --accent: #8b8ff7; --accent-soft: #2a2c44; --border: #3a3b48;
          --ok-bg: #16301b; --ok: #9ad6a0; --warn-bg: #3a3017; --warn: #f0d58a; --err-bg: #3d1d1d; --err: #f3a5a5; }
}
body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text);
       min-height: 100vh; padding: 32px 16px 48px; line-height: 1.5; }
.wrap { max-width: 720px; margin: 0 auto; }
.wrap.narrow { max-width: 460px; }
.card { background: var(--card); border-radius: 14px; box-shadow: 0 2px 14px rgba(0,0,0,0.09); padding: 28px; margin-bottom: 20px; }
.brand { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; color: var(--muted); font-size: 0.85rem; }
.brand img { width: 28px; height: 28px; border-radius: 7px; }
h1 { font-size: 1.3rem; font-weight: 700; margin-bottom: 6px; }
h2 { font-size: 1.05rem; font-weight: 700; margin-bottom: 12px; }
h3 { font-size: 0.95rem; font-weight: 700; margin: 18px 0 8px; }
p, li { font-size: 0.9rem; }
.subtitle { color: var(--muted); font-size: 0.88rem; margin-bottom: 20px; }
.muted { color: var(--muted); } .small { font-size: 0.8rem; }
a { color: var(--accent); }
code { background: var(--accent-soft); padding: 1px 6px; border-radius: 4px; font-size: 0.85em; word-break: break-all; }
.alert { border-radius: 8px; padding: 12px 16px; font-size: 0.87rem; margin-bottom: 16px; }
.alert-ok { background: var(--ok-bg); color: var(--ok); }
.alert-warn { background: var(--warn-bg); color: var(--warn); }
.alert-err { background: var(--err-bg); color: var(--err); }
.btn { display: inline-block; padding: 10px 22px; border: 1.5px solid transparent; border-radius: 9px; font-size: 0.92rem;
       cursor: pointer; font-weight: 600; text-decoration: none; transition: opacity .15s; font-family: inherit; }
.btn:hover { opacity: .85; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-ghost { background: transparent; color: var(--text); border-color: var(--border); }
.btn-danger { background: transparent; color: #e53935; border-color: #e5393555; padding: 6px 14px; font-size: 0.8rem; }
.btn-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
.btn-row .btn { flex: 1; text-align: center; }
.perm { display: flex; gap: 12px; align-items: flex-start; padding: 12px 0; border-top: 1px solid var(--border); }
.perm:first-of-type { border-top: none; }
.perm .icon { font-size: 1.2rem; line-height: 1.3; }
.perm b { display: block; font-size: 0.9rem; }
.perm span { color: var(--muted); font-size: 0.83rem; }
.perm input { margin-top: 5px; width: 18px; height: 18px; accent-color: var(--accent); }
.copy-field { display: flex; align-items: stretch; border: 1.5px solid var(--border); border-radius: 9px; overflow: hidden; margin: 10px 0 14px; }
.copy-field .val { flex: 1; font-family: ui-monospace, 'SF Mono', Menlo, monospace; font-size: 0.85rem; padding: 11px 13px; word-break: break-all; }
.copy-field button { background: var(--accent-soft); color: var(--accent); border: none; border-left: 1.5px solid var(--border);
                     padding: 0 16px; font-weight: 600; cursor: pointer; font-size: 0.85rem; white-space: nowrap; font-family: inherit; }
.code-block { background: var(--code-bg); color: #e0e0ff; font-family: ui-monospace, 'SF Mono', Menlo, monospace; font-size: 0.78rem;
              line-height: 1.6; padding: 14px 16px; border-radius: 9px; white-space: pre; overflow-x: auto; margin: 8px 0 12px; position: relative; }
.code-block button { position: absolute; top: 8px; right: 8px; background: #ffffff1a; color: #fff; border: none; border-radius: 6px;
                     padding: 4px 10px; font-size: 0.75rem; cursor: pointer; font-family: inherit; }
.tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 18px; }
.tab { padding: 7px 14px; border-radius: 8px; border: 1.5px solid var(--border); background: transparent; color: var(--muted);
       font-size: 0.85rem; font-weight: 600; cursor: pointer; font-family: inherit; }
.tab.active { background: var(--accent); border-color: var(--accent); color: #fff; }
.panel { display: none; } .panel.active { display: block; }
ol.steps { padding-left: 22px; } ol.steps li { margin: 7px 0; }
.grant { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 0; border-top: 1px solid var(--border); }
.grant:first-child { border-top: none; }
.grant .who { font-weight: 600; font-size: 0.9rem; } .grant .meta { color: var(--muted); font-size: 0.8rem; }
.tools { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 18px; } .tools li { list-style: none; font-size: 0.83rem; }
@media (max-width: 560px) { .tools { grid-template-columns: 1fr; } .card { padding: 22px 18px; } }
.footer { text-align: center; font-size: 0.82rem; color: var(--faint); margin-top: 8px; }
</style>
</head>
<body>
<?php
}

function mcpPageEnd(): void {
    ?>
<script>
function copyText(text, btn) {
  const done = () => { const o = btn.textContent; btn.textContent = 'Copied ✓'; setTimeout(() => btn.textContent = o, 1800); };
  if (navigator.clipboard) navigator.clipboard.writeText(text).then(done).catch(() => prompt('Copy this:', text));
  else prompt('Copy this:', text);
}
function showTab(id, btn) {
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
  document.getElementById('panel-' + id).classList.add('active');
  btn.classList.add('active');
  history.replaceState(null, '', '#' + id);
}
document.addEventListener('DOMContentLoaded', () => {
  const h = location.hash.slice(1);
  const b = h && document.querySelector('.tab[data-tab="' + h + '"]');
  if (b) b.click();
});
</script>
</body>
</html>
<?php
}

function mcpBrand(): void {
    echo '<div class="brand"><img src="/icons/icon-192.png" alt=""><span>TaskStick · tasks.tesh.ai</span></div>';
}

/** Full-page OAuth error (used when we must NOT redirect back to the client). */
function mcpErrorPage(string $title, string $message, int $code = 400): void {
    http_response_code($code);
    mcpPageStart($title);
    echo '<div class="wrap narrow"><div class="card">';
    mcpBrand();
    echo '<h1>' . mcpH($title) . '</h1><p class="subtitle">' . mcpH($message) . '</p>';
    echo '<p class="small muted">Close this window and try connecting again from your AI app. Setup help: <a href="/mcp/setup.php">tasks.tesh.ai/mcp/setup.php</a></p>';
    echo '</div></div>';
    mcpPageEnd();
    exit;
}
