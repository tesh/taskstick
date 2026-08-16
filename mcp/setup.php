<?php
/**
 * MCP Server Setup — Per-user API key generator
 * tasks.tesh.ai/mcp/setup.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/config.php';

if (!isAuthenticated()) {
    header('Location: /?redirect_to=mcp%2Fsetup.php');
    exit;
}

$userId     = $_SESSION['user']['id']    ?? '';
$userEmail  = $_SESSION['user']['email'] ?? 'unknown';
$safeUserId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId);

// Ensure data dir + .htaccess
$dataDir = DATA_DIR;
if (!is_dir($dataDir)) mkdir($dataDir, 0750, true);
$htaccess = $dataDir . '/.htaccess';
if (!file_exists($htaccess)) file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");

function findUserKey(string $dataDir, string $userId): ?string {
    foreach (glob($dataDir . '/mcp_key_*.json') ?: [] as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!empty($d['userId']) && $d['userId'] === $userId) return $d['key'] ?? null;
    }
    return null;
}

/**
 * Safely embed a PHP string as a JS string literal inside an HTML attribute.
 * json_encode() wraps the value in double-quotes, which would break onclick="..."
 * htmlspecialchars() converts those to &quot; which the browser decodes correctly.
 */
function jsStr(string $val): string {
    return htmlspecialchars(json_encode($val), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$existingKey = findUserKey($dataDir, $userId);
$newKey = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['generate','regenerate'])) {
    if (empty($_SESSION['refresh_token'])) {
        $error = 'No refresh token in session. Please sign out and sign back into tasks.tesh.ai, then return here.';
    } else {
        foreach (glob($dataDir . '/mcp_key_*.json') ?: [] as $f) {
            $d = json_decode(file_get_contents($f), true);
            if (!empty($d['userId']) && $d['userId'] === $userId) unlink($f);
        }
        $key     = 'mcp_' . bin2hex(random_bytes(24));
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        $keyRecord = ['key' => $key, 'userId' => $userId, 'email' => $userEmail, 'createdAt' => date('c')];
        if (file_put_contents($dataDir . '/mcp_key_' . $safeKey . '.json', json_encode($keyRecord, JSON_PRETTY_PRINT)) === false) {
            $error = 'Could not write key file. Check server file permissions on: ' . $dataDir;
        } else {
            $tokens = [
                'refresh_token' => $_SESSION['refresh_token'],
                'access_token'  => $_SESSION['access_token']  ?? '',
                'token_expiry'  => $_SESSION['token_expiry']  ?? 0,
                'saved_at'      => date('c'),
                'user_email'    => $userEmail,
            ];
            file_put_contents($dataDir . '/tokens_' . $safeUserId . '.json', json_encode($tokens, JSON_PRETTY_PRINT));
            $existingKey = $key;
            $newKey      = $key;
        }
    }
}

$tokenFile   = $dataDir . '/tokens_' . $safeUserId . '.json';
$tokenData   = file_exists($tokenFile) ? json_decode(file_get_contents($tokenFile), true) : null;
$tokensReady = !empty($tokenData['refresh_token']);
$isReady     = !empty($existingKey) && $tokensReady;

$mcpUrl     = 'https://tasks.tesh.ai/mcp/';
$mcpUrlKey  = $isReady ? $mcpUrl . '?key=' . ($newKey ?? $existingKey) : $mcpUrl . '?key=YOUR_API_KEY';
$displayKey = $newKey ?? $existingKey ?? '';

// Pre-build the config strings once so they're used consistently
$desktopConfig = implode("\n", [
    '"google-tasks": {',
    '  "type": "http",',
    '  "url": "' . $mcpUrl . '",',
    '  "headers": {',
    '    "Authorization": "Bearer ' . $displayKey . '"',
    '  }',
    '}',
]);

$fullDesktopConfig = implode("\n", [
    '{',
    '  "mcpServers": {',
    '    "google-tasks": {',
    '      "type": "http",',
    '      "url": "' . $mcpUrl . '",',
    '      "headers": {',
    '        "Authorization": "Bearer ' . $displayKey . '"',
    '      }',
    '    }',
    '  }',
    '}',
]);

$cursorConfig = implode("\n", [
    '{',
    '  "mcpServers": {',
    '    "google-tasks": {',
    '      "url": "' . $mcpUrl . '",',
    '      "headers": {',
    '        "Authorization": "Bearer ' . $displayKey . '"',
    '      }',
    '    }',
    '  }',
    '}',
]);

$bearerHeader = 'Bearer ' . $displayKey;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MCP Setup — tasks.tesh.ai</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: system-ui, -apple-system, sans-serif;
  background: #f0f2f5;
  color: #222;
  min-height: 100vh;
  padding: 32px 16px 48px;
}
.wrap { max-width: 680px; margin: 0 auto; }

.card {
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 2px 14px rgba(0,0,0,0.09);
  padding: 28px;
  margin-bottom: 20px;
}
h1 { font-size: 1.3rem; font-weight: 700; margin-bottom: 4px; }
.subtitle { color: #666; font-size: 0.87rem; margin-bottom: 22px; }
h2 { font-size: 1rem; font-weight: 700; margin-bottom: 14px; }

.status-list { list-style: none; display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
.status-row  { display: flex; align-items: center; gap: 10px; font-size: 0.88rem; }
.dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.green { background: #4caf50; } .red { background: #f44336; } .grey { background: #bbb; }

.alert { border-radius: 8px; padding: 13px 16px; font-size: 0.87rem; margin-bottom: 18px; line-height: 1.5; }
.alert-ok   { background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7; }
.alert-warn { background: #fff8e1; color: #6b4c00; border: 1px solid #ffe082; }
.alert-err  { background: #fdecea; color: #8b1c1c; border: 1px solid #ef9a9a; }
.alert-new  { background: #e3f2fd; color: #0d47a1; border: 1px solid #90caf9; }

.btn { display: inline-block; padding: 9px 22px; border: none; border-radius: 8px; font-size: 0.92rem; cursor: pointer; font-weight: 500; transition: opacity 0.15s; }
.btn:hover { opacity: 0.82; }
.btn-primary { background: #1a73e8; color: #fff; }
.btn-danger  { background: #e53935; color: #fff; }

/* ── Field reference table ── */
.field-ref {
  border: 1.5px solid #d0d7de;
  border-radius: 8px;
  overflow: hidden;
  margin: 10px 0;
  font-size: 0.85rem;
}
.field-ref-row {
  display: flex;
  align-items: stretch;
  border-bottom: 1px solid #e8eaed;
}
.field-ref-row:last-child { border-bottom: none; }
.field-name {
  background: #f6f8fa;
  padding: 10px 14px;
  font-weight: 600;
  color: #333;
  min-width: 180px;
  max-width: 180px;
  font-size: 0.82rem;
  display: flex;
  align-items: center;
  border-right: 1px solid #d0d7de;
  flex-shrink: 0;
}
.field-value {
  padding: 10px 12px;
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  background: #fff;
}
/* Value text: takes all remaining space, wraps cleanly */
.field-val-text {
  font-family: 'Courier New', monospace;
  font-size: 0.78rem;
  color: #1a1a2e;
  flex: 1;
  min-width: 0;
  word-break: break-all;
  overflow-wrap: anywhere;
  line-height: 1.5;
}
.muted { color: #999; font-style: italic; font-family: system-ui; font-size: 0.82rem; }

/* Copy button: never shrinks */
.copy-inline {
  background: #e8eaf6;
  border: none;
  border-radius: 5px;
  color: #3949ab;
  font-size: 0.74rem;
  padding: 4px 10px;
  cursor: pointer;
  white-space: nowrap;
  font-weight: 600;
  flex-shrink: 0;
  transition: background 0.15s;
}
.copy-inline:hover { background: #c5cae9; }

/* ── Key box (dark, full display) ── */
.key-box {
  background: #1a1a2e;
  color: #a5d8ff;
  font-family: 'Courier New', monospace;
  font-size: 0.82rem;
  padding: 14px 16px;
  border-radius: 8px;
  word-break: break-all;
  overflow-wrap: anywhere;
  margin-bottom: 12px;
  line-height: 1.6;
}

/* ── Copy-field: label | value | copy ── */
.copy-field {
  display: flex;
  align-items: stretch;
  border: 1.5px solid #d0d7de;
  border-radius: 8px;
  overflow: hidden;
  margin-bottom: 10px;
  background: #f6f8fa;
}
.copy-field .cf-label {
  background: #eaecef;
  color: #555;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  padding: 0 12px;
  display: flex;
  align-items: center;
  white-space: nowrap;
  border-right: 1.5px solid #d0d7de;
  min-width: 80px;
}
.copy-field .cf-val {
  flex: 1;
  font-family: 'Courier New', monospace;
  font-size: 0.76rem;
  padding: 10px 12px;
  color: #1a1a2e;
  word-break: break-all;
  overflow-wrap: anywhere;
  line-height: 1.5;
}
.copy-field .cf-btn {
  background: #e8eaf6;
  border: none;
  border-left: 1.5px solid #d0d7de;
  color: #3949ab;
  font-size: 0.8rem;
  padding: 0 14px;
  cursor: pointer;
  white-space: nowrap;
  font-weight: 600;
  transition: background 0.15s;
  flex-shrink: 0;
}
.copy-field .cf-btn:hover { background: #c5cae9; }

/* ── Code block ── */
.code-block {
  background: #1a1a2e;
  color: #e0e0ff;
  font-family: 'Courier New', monospace;
  font-size: 0.76rem;
  line-height: 1.65;
  padding: 16px;
  border-radius: 8px;
  white-space: pre;
  overflow-x: auto;
  margin: 10px 0;
}

/* ── Tab bar ── */
.tab-bar {
  display: flex;
  gap: 4px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.tab-btn {
  padding: 7px 16px;
  border-radius: 8px;
  border: 1.5px solid #d0d7de;
  background: #f6f8fa;
  color: #555;
  font-size: 0.84rem;
  font-weight: 500;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
  transition: all 0.15s;
}
.tab-btn:hover  { border-color: #1a73e8; color: #1a73e8; }
.tab-btn.active { background: #1a73e8; color: #fff; border-color: #1a73e8; }

.tab-panel         { display: none; }
.tab-panel.active  { display: block; }

/* ── Step list ── */
.steps { list-style: none; counter-reset: steps; display: flex; flex-direction: column; gap: 12px; }
.step  { counter-increment: steps; display: flex; gap: 12px; font-size: 0.88rem; line-height: 1.5; }
.step::before {
  content: counter(steps);
  background: #e8eaf6; color: #3949ab;
  font-weight: 700; font-size: 0.78rem;
  width: 24px; height: 24px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; margin-top: 1px;
}

.footer { text-align: center; font-size: 0.82rem; color: #999; margin-top: 8px; }
.footer a { color: #1a73e8; }
hr { border: none; border-top: 1px solid #eee; margin: 20px 0; }
.sep { height: 16px; }
p code { background: #f1f3f4; padding: 2px 5px; border-radius: 3px; font-size: 0.85em; }
</style>
</head>
<body>
<div class="wrap">

<!-- ══ CARD 1 — Key generation / status ══ -->
<div class="card">
  <h1>🔑 MCP Server Setup</h1>
  <p class="subtitle">Connect any AI assistant to your Google Tasks via the MCP protocol.</p>

  <?php if ($error): ?>
    <div class="alert alert-err"><strong>❌ Error:</strong> <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($newKey): ?>
    <div class="alert alert-new"><strong>✅ New API key generated!</strong> Copy it from the box below — it won't be shown in full again after you leave this page.</div>
  <?php endif; ?>

  <ul class="status-list">
    <li class="status-row">
      <span class="dot green"></span>
      Signed in as <strong>&nbsp;<?= htmlspecialchars($userEmail) ?></strong>
    </li>
    <li class="status-row">
      <span class="dot <?= !empty($existingKey) ? 'green' : 'red' ?>"></span>
      API key:&nbsp;<strong><?= !empty($existingKey) ? 'Generated' : 'Not created yet' ?></strong>
    </li>
    <li class="status-row">
      <span class="dot <?= $tokensReady ? 'green' : 'red' ?>"></span>
      Google tokens:&nbsp;<strong><?= $tokensReady
        ? 'Saved&nbsp;<span style="color:#888;font-weight:400;font-size:0.82em">(' . htmlspecialchars($tokenData['saved_at'] ?? '') . ')</span>'
        : 'Not saved' ?></strong>
    </li>
  </ul>

  <?php if ($newKey): ?>
    <div class="key-box"><?= htmlspecialchars($newKey) ?></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
      <button class="btn btn-primary" onclick="copyText(<?= jsStr($newKey) ?>, this)">📋 Copy API key</button>
    </div>
    <hr>
    <form method="POST" onsubmit="return confirm('Regenerate? Your current key will stop working immediately.')">
      <input type="hidden" name="action" value="regenerate">
      <button type="submit" class="btn btn-danger" style="font-size:0.82rem;padding:7px 16px">⟳ Regenerate key</button>
    </form>
    <p style="font-size:0.78rem;color:#999;margin-top:8px">⚠️ Regenerating creates a brand-new key and immediately invalidates the old one.</p>

  <?php elseif ($isReady): ?>
    <div class="alert alert-ok">✅ Your MCP server is active.</div>
    <p style="font-size:0.85rem;color:#555;margin-bottom:8px"><strong>Step 1:</strong> copy your connector URL —</p>
    <div class="copy-field" style="margin-bottom:16px">
      <div class="cf-label">URL</div>
      <div class="cf-val"><?= htmlspecialchars($mcpUrlKey) ?></div>
      <button class="cf-btn" onclick="copyText(<?= jsStr($mcpUrlKey) ?>, this)">📋 Copy</button>
    </div>
    <p style="font-size:0.85rem;color:#555;margin-bottom:4px"><strong>Step 2:</strong> paste it into your AI client — pick yours below.</p>
    <hr>
    <form method="POST" onsubmit="return confirm('Regenerate? Your current key will stop working immediately.')">
      <input type="hidden" name="action" value="regenerate">
      <button type="submit" class="btn btn-danger" style="font-size:0.82rem;padding:7px 16px">⟳ Regenerate key</button>
    </form>
    <p style="font-size:0.78rem;color:#999;margin-top:8px">⚠️ Regenerating immediately invalidates the URL above — you'd need to update every client using it.</p>

  <?php else: ?>
    <div class="alert alert-warn">⚠️ Not set up yet. Click below to generate your personal API key.</div>
    <form method="POST">
      <input type="hidden" name="action" value="generate">
      <button type="submit" class="btn btn-primary">Generate my API key</button>
    </form>
    <?php if (empty($_SESSION['refresh_token'])): ?>
      <p style="font-size:0.8rem;color:#c62828;margin-top:10px">
        ⚠️ No refresh token found. Please <a href="/auth/logout">sign out</a> and sign back into tasks.tesh.ai, then return here.
      </p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($isReady): ?>
<!-- ══ CARD 2 — Client instructions ══ -->
<div class="card">
  <h2>Connect your AI client</h2>

  <div class="tab-bar">
    <button class="tab-btn active" onclick="showTab('claude', this)">🌐 Claude</button>
    <button class="tab-btn" onclick="showTab('openai', this)">🤖 ChatGPT</button>
    <button class="tab-btn" onclick="showTab('other', this)">🔗 Other</button>
  </div>

  <!-- ── Claude (claude.ai web + Desktop — same flow as of 2026) ── -->
  <div class="tab-panel active" id="tab-claude">
    <p style="font-size:0.87rem;color:#555;margin-bottom:14px">
      Works the same in <strong>claude.ai</strong> and the <strong>Claude Desktop app</strong> — both use the same Connectors screen now:
    </p>
    <ul class="steps" style="margin-bottom:16px">
      <li class="step"><div>Go to <strong>Customize → Connectors</strong> (in Claude Desktop: the ⚙ menu → Connectors).</div></li>
      <li class="step"><div>Click <strong>Add custom connector</strong>.</div></li>
      <li class="step"><div>Paste your URL from the box above into <strong>Remote MCP server URL</strong>.</div></li>
      <li class="step"><div>Leave OAuth Client ID / Secret blank, then click <strong>Add</strong>. Done — no extra fields needed.</div></li>
    </ul>
    <p style="font-size:0.8rem;color:#888">
      💡 Your API key is embedded in that URL, so Claude authenticates automatically — nothing else to configure.
      On <strong>Team/Enterprise</strong> plans, an admin adds it once under <strong>Organization Settings → Connectors</strong> and everyone else just clicks <strong>Connect</strong>.
    </p>

    <details style="margin-top:16px">
      <summary style="cursor:pointer;font-size:0.85rem;color:#555;font-weight:600">Older Claude Desktop version without a Connectors screen? Use a config file instead ▾</summary>
      <div style="margin-top:12px">
        <p style="font-size:0.87rem;color:#555;margin-bottom:12px">
          Open your <strong>Claude Desktop config file</strong> and add the block below inside <code>"mcpServers"</code>:
        </p>
        <ul class="steps" style="margin-bottom:16px">
          <li class="step"><div><strong>macOS:</strong> <code>~/Library/Application Support/Claude/claude_desktop_config.json</code></div></li>
          <li class="step"><div><strong>Windows:</strong> <code>%APPDATA%\Claude\claude_desktop_config.json</code></div></li>
          <li class="step"><div>Paste the config below into the <code>"mcpServers": { … }</code> section, then restart Claude Desktop.</div></li>
        </ul>
        <div class="copy-field">
          <div class="cf-label">Entry</div>
          <div class="cf-val"><?= htmlspecialchars($desktopConfig) ?></div>
          <button class="cf-btn" onclick="copyText(<?= jsStr($desktopConfig) ?>, this)">Copy</button>
        </div>
        <p style="font-size:0.82rem;color:#555;margin:14px 0 6px">Full file example:</p>
        <div class="code-block"><?= htmlspecialchars($fullDesktopConfig) ?></div>
        <button class="btn btn-primary" style="margin-top:8px;font-size:0.82rem"
                onclick="copyText(<?= jsStr($fullDesktopConfig) ?>, this)">📋 Copy full config</button>
      </div>
    </details>
  </div>

  <!-- ── ChatGPT ── -->
  <div class="tab-panel" id="tab-openai">
    <p style="font-size:0.87rem;color:#555;margin-bottom:14px">
      ChatGPT supports custom remote MCP connectors via <strong>Developer mode</strong> (beta — Plus, Pro, Business, Enterprise, or Education plans, on the web). OpenAI moves this menu around fairly often, so instead of a fixed set of steps that go stale, follow their own current instructions:
    </p>
    <p style="margin-bottom:16px">
      <a href="https://help.openai.com/en/articles/12584461-developer-mode-and-mcp-apps-in-chatgpt" target="_blank" rel="noopener" class="btn btn-primary" style="text-decoration:none;display:inline-block">
        Open OpenAI's Developer Mode guide →
      </a>
    </p>
    <p style="font-size:0.85rem;color:#555;margin-bottom:8px">Roughly: turn on <strong>Developer mode</strong> somewhere under <strong>Settings → Apps</strong> (exact label varies), then add a custom connector — either from that same Settings area or from the <strong>+ / tools</strong> icon in the chat composer — and paste this in as the server URL:</p>
    <div class="field-ref">
      <div class="field-ref-row">
        <div class="field-name">Server URL</div>
        <div class="field-value">
          <span class="field-val-text"><?= htmlspecialchars($mcpUrlKey) ?></span>
          <button class="copy-inline" onclick="copyText(<?= jsStr($mcpUrlKey) ?>, this)">Copy</button>
        </div>
      </div>
      <div class="field-ref-row">
        <div class="field-name">Transport</div>
        <div class="field-value"><span class="field-val-text">Streamable HTTP · JSON-RPC 2.0</span></div>
      </div>
    </div>
    <p style="font-size:0.8rem;color:#888;margin-top:8px">
      💡 ChatGPT cannot reach a server on your own laptop — this works because tasks.tesh.ai is a public HTTPS server.
      Native MCP tool-calling is most reliable in Claude; ChatGPT's custom-connector support is newer, still in beta, and can be flakier.
    </p>
  </div>

  <!-- ── Other ── -->
  <div class="tab-panel" id="tab-other">
    <p style="font-size:0.87rem;color:#555;margin-bottom:14px">
      For any MCP-compatible client — Cursor, Windsurf, Zed, custom integrations, etc.:
    </p>
    <div class="field-ref">
      <div class="field-ref-row">
        <div class="field-name">Server URL</div>
        <div class="field-value">
          <span class="field-val-text"><?= htmlspecialchars($mcpUrl) ?></span>
          <button class="copy-inline" onclick="copyText(<?= jsStr($mcpUrl) ?>, this)">Copy</button>
        </div>
      </div>
      <div class="field-ref-row">
        <div class="field-name">Authorization header</div>
        <div class="field-value">
          <span class="field-val-text"><?= htmlspecialchars($bearerHeader) ?></span>
          <button class="copy-inline" onclick="copyText(<?= jsStr($bearerHeader) ?>, this)">Copy</button>
        </div>
      </div>
      <div class="field-ref-row">
        <div class="field-name">Alt URL (key in URL)</div>
        <div class="field-value">
          <span class="field-val-text"><?= htmlspecialchars($mcpUrlKey) ?></span>
          <button class="copy-inline" onclick="copyText(<?= jsStr($mcpUrlKey) ?>, this)">Copy</button>
        </div>
      </div>
      <div class="field-ref-row">
        <div class="field-name">Transport</div>
        <div class="field-value"><span class="field-val-text">Streamable HTTP · POST · JSON-RPC 2.0</span></div>
      </div>
    </div>

    <p style="font-size:0.87rem;color:#555;margin:16px 0 6px"><strong>Cursor — <code>.cursor/mcp.json</code>:</strong></p>
    <div class="code-block"><?= htmlspecialchars($cursorConfig) ?></div>
    <button class="btn btn-primary" style="margin-top:8px;font-size:0.82rem"
            onclick="copyText(<?= jsStr($cursorConfig) ?>, this)">📋 Copy</button>
  </div>

</div><!-- /card 2 -->
<?php endif; ?>

<div class="footer"><a href="/">← Back to tasks.tesh.ai</a></div>
</div>

<script>
function showTab(id, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  btn.classList.add('active');
}

function copyText(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    const orig = btn.textContent;
    btn.textContent = '✅ Copied!';
    setTimeout(() => { btn.textContent = orig; }, 2000);
  }).catch(() => {
    // Clipboard API blocked (e.g. non-HTTPS) — fall back to prompt
    prompt('Copy this value:', text);
  });
}
</script>
</body>
</html>
