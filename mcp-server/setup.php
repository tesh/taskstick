<?php
/**
 * MCP setup — tasks.tesh.ai/mcp/setup.php
 *
 * Public instructions for connecting AI apps to the TaskStick MCP server
 * (OAuth sign-in — no keys to copy), plus, when signed in, the list of
 * connected apps with a Disconnect button for each.
 */

require_once dirname(__DIR__) . '/config.php';        // main app first (session)
require_once __DIR__ . '/lib/tools.php';
require_once __DIR__ . '/lib/page.php';

$signedIn = isAuthenticated() && !empty($_SESSION['user']['id']);
$userId   = $signedIn ? $_SESSION['user']['id'] : '';
$email    = $signedIn ? ($_SESSION['user']['email'] ?? '') : '';
$_SESSION['mcp_setup_csrf'] = $_SESSION['mcp_setup_csrf'] ?? mcpB64url(random_bytes(16));
$csrf = $_SESSION['mcp_setup_csrf'];
$flash = null;

// ─── Retire v1 API keys (?key=… URLs stopped working in v2.0) ──────────────
$hadOldKey = false;
if ($signedIn) {
    foreach (glob(DATA_DIR . '/mcp_key_*.json') ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (($d['userId'] ?? '') === $userId) { @unlink($f); $hadOldKey = true; }
    }
}

// ─── Disconnect actions ───────────────────────────────────────────────────
if ($signedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $mine = oauthListGrants($userId);
    if (($_POST['action'] ?? '') === 'revoke') {
        $fam = (string)($_POST['family'] ?? '');
        foreach ($mine as $g) if ($g['familyId'] === $fam) { oauthRevokeFamily($fam); $flash = 'Disconnected ' . ($g['clientName'] ?: 'that app') . '.'; }
    } elseif (($_POST['action'] ?? '') === 'revoke_all') {
        foreach ($mine as $g) oauthRevokeFamily($g['familyId']);
        $flash = 'Disconnected every app.';
    }
    header('Location: /mcp/setup.php?done=' . urlencode((string)$flash) . '#connected');
    exit;
}
if (isset($_GET['done']) && $_GET['done'] !== '') $flash = (string)$_GET['done'];

$grants = $signedIn ? oauthListGrants($userId) : [];
$url = MCP_RESOURCE_URL;

function ago(int $ts): string {
    $d = time() - $ts;
    if ($d < 90) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 86400 * 30) return floor($d / 86400) . ' days ago';
    return date('M j, Y', $ts);
}

function codeBlock(string $code): void {
    echo '<div class="code-block"><button type="button" onclick="copyText(' . mcpJsStr($code) . ', this)">Copy</button>' . mcpH($code) . '</div>';
}

$cursorJson = json_encode(['mcpServers' => ['taskstick' => ['url' => $url]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$vscodeJson = json_encode(['servers' => ['taskstick' => ['type' => 'http', 'url' => $url]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$genericJson = json_encode(['mcpServers' => ['taskstick' => ['type' => 'http', 'url' => $url]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

mcpPageStart('Connect your AI apps');
?>
<div class="wrap">

<!-- ══ 1 · The URL ══ -->
<div class="card">
  <?php mcpBrand(); ?>
  <h1>Connect TaskStick to your AI apps</h1>
  <p class="subtitle">Let Claude, ChatGPT, Cursor and other AI apps see and manage your Google Tasks — including your Priority stars and snoozes — and open an interactive task board right in the chat.</p>

  <?php if ($hadOldKey): ?>
    <div class="alert alert-warn"><strong>Your old connector link has stopped working.</strong> Links ending in <code>?key=mcp_…</code> were retired on November 24, 2026 (putting keys in URLs isn't safe, and the AI apps now expect sign-in instead). Remove the old TaskStick connector from each app and add it again with the URL below — it takes a minute.</div>
  <?php endif; ?>

  <p style="font-size:0.88rem;margin-bottom:2px"><strong>Your connector URL</strong> — the same for everyone:</p>
  <div class="copy-field">
    <div class="val"><?= mcpH($url) ?></div>
    <button type="button" onclick="copyText(<?= mcpJsStr($url) ?>, this)">Copy</button>
  </div>
  <p class="small muted">There are no keys to copy. When you add the URL, your AI app opens a TaskStick page where you sign in with Google and click <strong>Allow</strong>. You can choose read-only access there, and disconnect any app later on this page.</p>
</div>

<!-- ══ 2 · Per-app instructions ══ -->
<div class="card">
  <h2>Pick your app</h2>
  <div class="tabs" role="tablist">
    <button class="tab active" data-tab="claude" onclick="showTab('claude', this)">Claude</button>
    <button class="tab" data-tab="plugin" onclick="showTab('plugin', this)">Claude plugin</button>
    <button class="tab" data-tab="claude-code" onclick="showTab('claude-code', this)">Claude Code</button>
    <button class="tab" data-tab="chatgpt" onclick="showTab('chatgpt', this)">ChatGPT</button>
    <button class="tab" data-tab="codex" onclick="showTab('codex', this)">Codex</button>
    <button class="tab" data-tab="ide" onclick="showTab('ide', this)">Cursor &amp; VS Code</button>
    <button class="tab" data-tab="other" onclick="showTab('other', this)">Other</button>
  </div>

  <!-- Claude -->
  <div class="panel active" id="panel-claude">
    <p class="muted" style="margin-bottom:10px">claude.ai, the Claude desktop app and Cowork share one connector list, so you set this up once. It then also works in Claude on your phone.</p>
    <ol class="steps">
      <li>Open <a href="https://claude.ai/customize/connectors" target="_blank" rel="noopener"><strong>Customize → Connectors</strong></a>.</li>
      <li>Select <strong>Add</strong>, then <strong>Add custom connector</strong>.</li>
      <li>Name it <strong>TaskStick</strong>, paste the URL above, and click <strong>Continue</strong>.</li>
      <li>Leave the authentication settings Claude marks <strong>Detected</strong> (sign in with OAuth, using Claude's published identity) and click <strong>Add</strong>.</li>
      <li>Click <strong>Connect</strong>, sign in on the TaskStick page that opens, and click <strong>Allow</strong>.</li>
    </ol>
    <p class="small muted" style="margin-top:10px">
      Then ask “what's on my plate today?” or “show me my task board”. On a connector's page you can set each tool to always allow, ask first, or block.<br>
      <strong>Free plan:</strong> one custom connector. <strong>Team / Enterprise:</strong> an Owner adds it once under <strong>Organization settings → Connectors → Add → Custom → Web</strong>; everyone else just clicks <strong>Connect</strong>.<br>
      Want Claude to also know how TaskStick works (Priority, Snooze, Daily Ritual)? Use the <a href="#plugin" onclick="showTab('plugin', document.querySelector('[data-tab=plugin]'));return false">Claude plugin</a> instead — it includes this connector.
    </p>
  </div>

  <!-- Claude plugin -->
  <div class="panel" id="panel-plugin">
    <p class="muted" style="margin-bottom:10px">The TaskStick plugin bundles the connector with a skill that teaches Claude how TaskStick works, plus two commands: <code>/taskstick:today</code> (plan my day) and <code>/taskstick:review</code> (weekly review). Install it once on your account and it's available in chat, Cowork and Claude Code.</p>
    <h3>In claude.ai or the desktop app</h3>
    <ol class="steps">
      <li>Open <a href="https://claude.ai/customize/plugins" target="_blank" rel="noopener"><strong>Customize → Plugins</strong></a>.</li>
      <li>Select <strong>Add → Add marketplace</strong> and enter <code>tesh/taskstick</code>. <span class="muted">(Or download <a href="/mcp-server/taskstick-plugin.zip">taskstick-plugin.zip</a> and use <strong>Add → Upload plugin</strong>.)</span></li>
      <li>Find <strong>TaskStick</strong> and select <strong>Add</strong>.</li>
      <li>Open the plugin, go to its <strong>Connectors</strong> tab, and click <strong>Connect</strong> — then sign in and <strong>Allow</strong>.</li>
    </ol>
    <p class="small muted" style="margin-top:8px">If you already added TaskStick as a custom connector with the same URL, the plugin reuses it — you won't get two sets of tools.</p>
    <h3>In Claude Code only</h3>
    <?php codeBlock('/plugin install taskstick --marketplace tesh/taskstick'); ?>
    <p class="small muted">Then run <code>/mcp</code>, pick <strong>taskstick</strong>, and choose <strong>Authenticate</strong>.</p>
  </div>

  <!-- Claude Code -->
  <div class="panel" id="panel-claude-code">
    <p class="muted" style="margin-bottom:10px">Add the connector from your terminal (<code>--scope user</code> makes it available in every project):</p>
    <?php codeBlock('claude mcp add --transport http --scope user taskstick ' . $url); ?>
    <ol class="steps">
      <li>Start Claude Code and run <code>/mcp</code>.</li>
      <li>Select <strong>taskstick</strong> → <strong>Authenticate</strong>. Your browser opens TaskStick; sign in and click <strong>Allow</strong>.</li>
    </ol>
    <p class="small muted" style="margin-top:8px">Already added TaskStick in claude.ai? Claude Code picks up your claude.ai connectors automatically when you're signed in with the same account, so you may not need this step. For the skill and commands too, use the <a href="#plugin" onclick="showTab('plugin', document.querySelector('[data-tab=plugin]'));return false">plugin</a>.</p>
  </div>

  <!-- ChatGPT -->
  <div class="panel" id="panel-chatgpt">
    <div class="alert alert-warn" style="margin-bottom:12px">ChatGPT only allows custom MCP servers on some plans: full read-and-write access on <strong>Business, Enterprise and Edu</strong> (web), and read-only on <strong>Pro</strong>. On Business, only workspace admins and owners can add one. Plus and Free don't offer it.</div>
    <ol class="steps">
      <li>In ChatGPT on the web, open <strong>Plugins</strong> and select the <strong>+</strong> button, then <strong>Add custom MCP server</strong>.</li>
      <li>Name it <strong>TaskStick</strong>. Under <strong>Connection</strong>, choose <strong>URL</strong> and paste the URL above.</li>
      <li>Under authentication, choose <strong>OAuth</strong>. ChatGPT finds TaskStick's sign-in settings on its own — leave the client fields empty.</li>
      <li>Accept the risk notice and select <strong>Create as a plugin</strong>. Sign in on the TaskStick page and click <strong>Allow</strong>.</li>
      <li>In a chat, pick TaskStick from the tools menu (or just mention it) and ask about your tasks.</li>
    </ol>
    <p class="small muted" style="margin-top:8px">ChatGPT moves these menus around; if yours look different (older workspaces use <strong>Settings → Apps → Create</strong>), follow OpenAI's current guide: <a href="https://help.openai.com/en/articles/12584461-developer-mode-and-mcp-apps-in-chatgpt" target="_blank" rel="noopener">Developer mode and MCP apps in ChatGPT</a>.</p>
  </div>

  <!-- Codex -->
  <div class="panel" id="panel-codex">
    <p class="muted" style="margin-bottom:10px">Codex CLI, the Codex IDE extension and the ChatGPT desktop app share one MCP configuration.</p>
    <?php codeBlock("codex mcp add taskstick --url $url\ncodex mcp login taskstick"); ?>
    <p class="small muted">The second command opens your browser to sign in. In the desktop app or IDE extension you can instead open <strong>Settings → MCP servers → Add server</strong>, choose <strong>Streamable HTTP</strong>, paste the URL, save, restart, and choose <strong>Authenticate</strong>.</p>
  </div>

  <!-- Cursor & VS Code -->
  <div class="panel" id="panel-ide">
    <h3 style="margin-top:0">Cursor — <code>~/.cursor/mcp.json</code></h3>
    <?php codeBlock($cursorJson); ?>
    <p class="small muted">Open <strong>Cursor Settings → MCP</strong> and click <strong>Connect</strong> next to taskstick to sign in.</p>
    <h3>VS Code (GitHub Copilot) — <code>.vscode/mcp.json</code></h3>
    <?php codeBlock($vscodeJson); ?>
    <p class="small muted">Click <strong>Start</strong> above the server entry, then <strong>Allow</strong> the sign-in prompt. VS Code also shows the interactive task board.</p>
  </div>

  <!-- Other -->
  <div class="panel" id="panel-other">
    <p class="muted" style="margin-bottom:10px">Any MCP client that supports remote servers with OAuth will work. Most accept this standard config:</p>
    <?php codeBlock($genericJson); ?>
    <h3>Technical details</h3>
    <ul style="padding-left:20px" class="small">
      <li><strong>Transport:</strong> Streamable HTTP, POST only. MCP <code>2026-07-28</code> (stateless, <code>server/discover</code>) and the earlier handshake versions <code>2025-11-25</code>, <code>2025-06-18</code>, <code>2025-03-26</code>, <code>2024-11-05</code>.</li>
      <li><strong>Auth:</strong> OAuth 2.1 with PKCE (S256). Client registration by Client ID Metadata Document or Dynamic Client Registration. Issuer <code><?= mcpH(MCP_ISSUER) ?></code> (RFC 9207 <code>iss</code> on redirects).</li>
      <li><strong>Discovery:</strong> <a href="/.well-known/oauth-protected-resource/mcp"><code>/.well-known/oauth-protected-resource/mcp</code></a> · <a href="/.well-known/oauth-authorization-server"><code>/.well-known/oauth-authorization-server</code></a></li>
      <li><strong>Scopes:</strong> <code>tasks.read</code>, <code>tasks.write</code>. Access tokens last an hour; refresh tokens rotate on every use and expire after 180 days unused.</li>
      <li><strong>Extensions:</strong> MCP Apps (<code>io.modelcontextprotocol/ui</code>) for the interactive board.</li>
      <li><strong>Source:</strong> <a href="https://github.com/tesh/taskstick" target="_blank" rel="noopener">github.com/tesh/taskstick</a> (<code>mcp/</code> and <code>oauth/</code>).</li>
    </ul>
  </div>
</div>

<!-- ══ 3 · Connected apps ══ -->
<div class="card" id="connected">
  <h2>Connected apps</h2>
  <?php if ($flash): ?><div class="alert alert-ok"><?= mcpH($flash) ?></div><?php endif; ?>
  <?php if (!$signedIn): ?>
    <p class="muted"><a href="/auth/login.php?return_to=<?= urlencode('/mcp/setup.php#connected') ?>">Sign in to TaskStick</a> to see which apps can reach your tasks and disconnect them.</p>
  <?php elseif (!$grants): ?>
    <p class="muted">No apps are connected to <strong><?= mcpH($email) ?></strong> yet. Add the URL above to one of your AI apps to get started.</p>
  <?php else: ?>
    <p class="muted small" style="margin-bottom:6px">Apps that can reach the tasks of <strong><?= mcpH($email) ?></strong>:</p>
    <div>
    <?php foreach ($grants as $g): $rw = in_array('tasks.write', $g['scope'] ?? [], true); ?>
      <div class="grant">
        <div>
          <div class="who"><?= mcpH($g['clientName'] ?: 'MCP client') ?></div>
          <div class="meta"><?= $rw ? 'Read and change tasks' : 'Read-only' ?> · connected <?= mcpH(date('M j, Y', (int)($g['createdAt'] ?? time()))) ?> · last active <?= mcpH(ago((int)($g['lastUsed'] ?? time()))) ?></div>
        </div>
        <form method="POST" onsubmit="return confirm('Disconnect this app? It will need to sign in again to reach your tasks.')">
          <input type="hidden" name="csrf" value="<?= mcpH($csrf) ?>">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="family" value="<?= mcpH($g['familyId']) ?>">
          <button class="btn btn-danger" type="submit">Disconnect</button>
        </form>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if (count($grants) > 1): ?>
      <form method="POST" style="margin-top:12px" onsubmit="return confirm('Disconnect every app?')">
        <input type="hidden" name="csrf" value="<?= mcpH($csrf) ?>"><input type="hidden" name="action" value="revoke_all">
        <button class="btn btn-danger" type="submit">Disconnect all</button>
      </form>
    <?php endif; ?>
    <p class="small muted" style="margin-top:12px">“Last active” updates when an app renews its access, about once an hour while in use.</p>
  <?php endif; ?>
</div>

<?php if ($signedIn && function_exists('isAdmin') && isAdmin()):
    $logFile = MCP_OAUTH_DIR . '/mcp-requests.log';
    $logLines = is_file($logFile) ? array_slice(file($logFile) ?: [], -60) : []; ?>
<div class="card" id="log">
  <h2>Recent MCP requests <span class="muted small">(admins only)</span></h2>
  <?php if (!$logLines): ?><p class="muted">No requests logged yet.</p><?php else: ?>
  <div class="code-block" style="white-space:pre-wrap;font-size:0.72rem;max-height:420px;overflow:auto"><?php
    foreach (array_reverse($logLines) as $l) {
        $d = json_decode($l, true); if (!$d) continue;
        echo mcpH(sprintf("%s  %s %s %s %s %s%s  | %s %s\n", substr($d['t'], 5, 14), $d['s'], $d['m'], $d['e'] ?: '-',
            $d['pv'] ?: '', $d['a'] ? '[token] ' : '[no token] ', $d['n'], $d['ua'], $d['p'] ?? ''));
    } ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ 4 · What the AI can do ══ -->
<div class="card">
  <h2>What your AI app can do</h2>
  <ul class="tools">
    <?php foreach (mcpToolCatalog() as $t): ?>
      <li><?= $t['write'] ? '✏️' : '👀' ?> <strong><?= mcpH($t['title']) ?></strong>
        <?php if (!empty($t['_meta']['ui'])): ?><span class="muted">(interactive)</span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <p class="small muted" style="margin-top:12px">👀 needs read access · ✏️ needs “Create and change tasks”. Stars and snoozes made by an AI app show up in TaskStick the next time it loads. If you have TaskStick open in a browser tab at the same time, refresh it before starring or snoozing there.</p>
</div>

<div class="footer"><a href="/">← Back to TaskStick</a> · <a href="/privacy.html">Privacy</a> · <a href="/terms.html">Terms</a></div>
</div>
<?php
mcpPageEnd();
