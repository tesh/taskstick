<?php
/**
 * OAuth 2.1 authorization endpoint (authorization code + PKCE S256).
 *
 * Flow: an AI app (Claude, ChatGPT, Cursor…) sends the user here. We validate
 * the client (Client ID Metadata Document URL or a registered client_id) and
 * redirect URI, make sure the user is signed into TaskStick with Google, show
 * a consent screen, then redirect back with ?code=…&state=…&iss=….
 *
 * The pending request is parked in the PHP session under a random id so it
 * survives the detour through Google sign-in.
 */

require_once dirname(__DIR__) . '/config.php';          // main app: session + isAuthenticated()
require_once dirname(__DIR__) . '/mcp-server/lib/oauth.php';
require_once dirname(__DIR__) . '/mcp-server/lib/page.php';

/** Redirect back to the client with an OAuth error (only after redirect_uri is validated). */
function authzRedirectError(string $redirectUri, string $error, string $desc, ?string $state): void {
    $q = ['error' => $error, 'error_description' => $desc, 'iss' => MCP_ISSUER];
    if ($state !== null && $state !== '') $q['state'] = $state;
    header('Location: ' . $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($q));
    exit;
}

$_SESSION['mcp_authz'] = $_SESSION['mcp_authz'] ?? [];
// Drop stale parked requests (older than 30 min)
foreach ($_SESSION['mcp_authz'] as $k => $v) {
    if (($v['at'] ?? 0) < time() - 1800) unset($_SESSION['mcp_authz'][$k]);
}

// ─── 1. Fresh request from a client: validate and park it ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['client_id'])) {
    $clientId    = (string)$_GET['client_id'];
    $redirectUri = (string)($_GET['redirect_uri'] ?? '');
    $state       = isset($_GET['state']) ? (string)$_GET['state'] : null;

    $err = null;
    $client = oauthResolveClient($clientId, $err);
    if (!$client) mcpErrorPage('Unknown app', $err ?: 'This app is not registered.');

    if ($redirectUri === '') mcpErrorPage('Missing redirect URI', 'The app did not say where to send you back to.');
    if (!oauthRedirectAllowed($client, $redirectUri)) {
        mcpErrorPage('Redirect not allowed', 'The return address this app asked for is not one it registered, so TaskStick stopped here to protect your account.');
    }

    // From here on, errors go back to the client.
    if (($_GET['response_type'] ?? '') !== 'code') {
        authzRedirectError($redirectUri, 'unsupported_response_type', 'Only response_type=code is supported.', $state);
    }
    $challenge = (string)($_GET['code_challenge'] ?? '');
    if ($challenge === '' || ($_GET['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
        authzRedirectError($redirectUri, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.', $state);
    }
    $resource = isset($_GET['resource']) ? (string)$_GET['resource'] : null;
    if (!mcpResourceMatches($resource)) {
        authzRedirectError($redirectUri, 'invalid_target', 'This authorization server only issues tokens for ' . MCP_RESOURCE_URL, $state);
    }

    $rid = mcpB64url(random_bytes(16));
    $_SESSION['mcp_authz'][$rid] = [
        'at'          => time(),
        'clientId'    => $clientId,
        'clientName'  => $client['client_name'] ?? $clientId,
        'clientUri'   => $client['client_uri'] ?? null,
        'clientType'  => $client['type'] ?? 'dcr',
        'redirectUri' => $redirectUri,
        'state'       => $state,
        'challenge'   => $challenge,
        'scope'       => oauthParseScopes($_GET['scope'] ?? null),
        'csrf'        => mcpB64url(random_bytes(16)),
    ];
    header('Location: /oauth/authorize.php?rid=' . urlencode($rid));
    exit;
}

// ─── 2. Look up the parked request ─────────────────────────────────────────
$rid = (string)($_GET['rid'] ?? $_POST['rid'] ?? '');
$req = $_SESSION['mcp_authz'][$rid] ?? null;
if (!$req) {
    mcpErrorPage('Sign-in link expired', 'This sign-in request has expired or was already used.');
}
$selfUrl = '/oauth/authorize.php?rid=' . urlencode($rid);

// ─── 3. Make sure the user is signed into TaskStick with Tasks access and a
//        Google refresh token we can keep for the MCP server ────────────────
$userId = $_SESSION['user']['id'] ?? '';
$haveGoogle = $userId && (!empty($_SESSION['refresh_token']) || mcpHasGoogleTokens($userId));
if (!isAuthenticated() || !$userId || !($_SESSION['hasTasksAccess'] ?? true) || !$haveGoogle) {
    header('Location: /auth/login.php?force=1&return_to=' . urlencode($selfUrl));
    exit;
}
$email = $_SESSION['user']['email'] ?? '';

// ─── 4. Consent decision ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($req['csrf'], (string)($_POST['csrf'] ?? ''))) {
        mcpErrorPage('Request check failed', 'Please start the connection again from your AI app.');
    }
    unset($_SESSION['mcp_authz'][$rid]);

    if (($_POST['decision'] ?? '') !== 'allow') {
        authzRedirectError($req['redirectUri'], 'access_denied', 'The user declined access.', $req['state']);
    }

    // Keep the freshest Google refresh token for the MCP server to use.
    if (!empty($_SESSION['refresh_token'])) {
        mcpSaveGoogleTokens($userId, $email, $_SESSION['refresh_token'],
            $_SESSION['access_token'] ?? '', (int)($_SESSION['token_expiry'] ?? 0));
    }

    $scope = ['tasks.read'];
    if (in_array('tasks.write', $req['scope'], true) && !empty($_POST['allow_write'])) $scope[] = 'tasks.write';

    $code = mcpRandomToken('tsk_code_');
    oauthPut('code', $code, [
        'clientId'    => $req['clientId'],
        'clientName'  => $req['clientName'],
        'redirectUri' => $req['redirectUri'],
        'challenge'   => $req['challenge'],
        'scope'       => $scope,
        'userId'      => $userId,
        'email'       => $email,
        'exp'         => time() + MCP_AUTH_CODE_TTL,
    ]);

    $q = ['code' => $code, 'iss' => MCP_ISSUER];
    if ($req['state'] !== null && $req['state'] !== '') $q['state'] = $req['state'];
    $ru = $req['redirectUri'];
    header('Location: ' . $ru . (str_contains($ru, '?') ? '&' : '?') . http_build_query($q));
    exit;
}

// ─── 5. Consent screen ────────────────────────────────────────────────────
$redirectHost = parse_url($req['redirectUri'], PHP_URL_HOST) ?: parse_url($req['redirectUri'], PHP_URL_SCHEME);
$isLoopback   = oauthIsLoopbackRedirect($req['redirectUri']);
$clientHost   = oauthIsCimdClientId($req['clientId']) ? parse_url($req['clientId'], PHP_URL_HOST) : null;
$wantsWrite   = in_array('tasks.write', $req['scope'], true);

mcpPageStart('Connect ' . $req['clientName']);
?>
<div class="wrap narrow">
  <div class="card">
    <?php mcpBrand(); ?>
    <h1>Connect <?= mcpH($req['clientName']) ?> to your tasks?</h1>
    <p class="subtitle">
      Signed in as <strong><?= mcpH($email) ?></strong> ·
      <a href="/auth/logout.php">not you?</a>
    </p>

    <?php if ($isLoopback): ?>
      <div class="alert alert-warn">This app runs on your own computer (it will send you back to <code><?= mcpH($req['redirectUri']) ?></code>). Only continue if you just started this connection yourself.</div>
    <?php endif; ?>

    <form method="POST" action="/oauth/authorize.php">
      <input type="hidden" name="rid" value="<?= mcpH($rid) ?>">
      <input type="hidden" name="csrf" value="<?= mcpH($req['csrf']) ?>">

      <div class="perm">
        <div class="icon">👀</div>
        <div><b>See your task lists and tasks</b><span>Titles, notes, due dates, subtasks, and which tasks are starred or snoozed.</span></div>
      </div>
      <?php if ($wantsWrite): ?>
      <label class="perm" style="cursor:pointer">
        <input type="checkbox" name="allow_write" value="1" checked>
        <div><b>Create and change tasks</b><span>Add, edit, complete, star, snooze, move and delete tasks, and add or rename lists. Untick for read-only access.</span></div>
      </label>
      <?php endif; ?>

      <p class="small muted" style="margin-top:14px">
        You'll be sent back to <strong><?= mcpH($redirectHost) ?></strong><?php if ($clientHost && $clientHost !== $redirectHost): ?> (app identified by <strong><?= mcpH($clientHost) ?></strong>)<?php endif; ?>.
        You can disconnect at any time from <a href="/mcp/setup.php" target="_blank">MCP setup</a>.
      </p>

      <div class="btn-row">
        <button type="submit" name="decision" value="deny" class="btn btn-ghost">Cancel</button>
        <button type="submit" name="decision" value="allow" class="btn btn-primary">Allow</button>
      </div>
    </form>
  </div>
</div>
<?php
mcpPageEnd();
