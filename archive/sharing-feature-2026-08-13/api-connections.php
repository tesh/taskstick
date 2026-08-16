<?php
/**
 * api/connections.php — Manage user connections (invite / accept / remove)
 *
 * GET  — returns current user's connections + pending invites
 * POST { action:'invite',  email }       — send invite by email
 * POST { action:'accept',  token }       — accept a received invite
 * POST { action:'decline', token }       — decline a received invite
 * POST { action:'remove',  email }       — disconnect from a user
 */
require_once '../config.php';

if (!isAuthenticated()) { jsonResponse(['error' => 'Not authenticated'], 401); }

$userEmail = $_SESSION['user']['email'] ?? '';
$userName  = $_SESSION['user']['name']  ?? 'User';

if (!$userEmail) { jsonResponse(['error' => 'No user email in session'], 400); }

$DATA_FILE = dirname(__DIR__) . '/data/connections.json';

/* ── File helpers ─────────────────────────────────────────────── */
function loadConns(string $f): array {
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function saveConns(string $f, array $d): void {
    file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function emptyUser(): array {
    return ['connections' => [], 'pending_sent' => [], 'pending_recv' => []];
}

/* ── GET — return current user's data ────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $all  = loadConns($DATA_FILE);
    $mine = $all[$userEmail] ?? emptyUser();
    jsonResponse([
        'connections'  => $mine['connections']  ?? [],
        'pending_sent' => $mine['pending_sent'] ?? [],
        'pending_recv' => $mine['pending_recv'] ?? [],
    ]);
}

/* ── POST actions ─────────────────────────────────────────────── */
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

$all  = loadConns($DATA_FILE);
$mine = $all[$userEmail] ?? emptyUser();

/* INVITE */
if ($action === 'invite') {
    $toEmail = trim(strtolower($body['email'] ?? ''));
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Invalid email address'], 400);
    }
    if ($toEmail === strtolower($userEmail)) {
        jsonResponse(['error' => 'You cannot invite yourself'], 400);
    }
    // Already connected?
    $already = array_filter($mine['connections'] ?? [], fn($c) => strtolower($c['email']) === $toEmail);
    if (!empty($already)) {
        jsonResponse(['error' => 'You are already connected with this person'], 400);
    }
    // If a previous invite was already sent, remove it so we can resend with a fresh token.
    $hadPending = !empty(array_filter($mine['pending_sent'] ?? [], fn($i) => strtolower($i['to']) === $toEmail));
    if ($hadPending) {
        $mine['pending_sent'] = array_values(
            array_filter($mine['pending_sent'] ?? [], fn($i) => strtolower($i['to']) !== $toEmail)
        );
        // Also remove the stale recv entry on the recipient's side
        if (isset($all[$toEmail])) {
            $all[$toEmail]['pending_recv'] = array_values(
                array_filter($all[$toEmail]['pending_recv'] ?? [],
                    fn($i) => strtolower($i['from']) !== strtolower($userEmail))
            );
        }
    }

    $token  = bin2hex(random_bytes(20));
    $invite = [
        'from'     => $userEmail,
        'fromName' => $userName,
        'to'       => $toEmail,
        'token'    => $token,
        'sentAt'   => date('c'),
    ];

    // Add to sender's pending_sent
    $mine['pending_sent'][] = $invite;
    $all[$userEmail] = $mine;

    // Add to recipient's pending_recv
    $recv = $all[$toEmail] ?? emptyUser();
    $recv['pending_recv'][] = $invite;
    $all[$toEmail] = $recv;

    saveConns($DATA_FILE, $all);

    // Send invitation email
    $link    = 'https://tasks.tesh.ai/?accept_invite=' . rawurlencode($token);
    $subject = "$userName invited you to connect on TaskStick";
    $msg     = "Hi,\n\n"
             . "$userName ($userEmail) has invited you to connect on TaskStick,\n"
             . "a task management app powered by your Google Tasks.\n\n"
             . "Click the link below to accept the invitation:\n"
             . "$link\n\n"
             . "If you don't use TaskStick, you can safely ignore this email.\n\n"
             . "— TaskStick, by Purple Pill Solutions\n"
             . "https://tasks.tesh.ai";
    $hdrs    = "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n"
             . "From: TaskStick <noreply@tasks.tesh.ai>\r\n"
             . "Reply-To: noreply@tasks.tesh.ai\r\n"
             . "X-Mailer: PHP/" . phpversion();
    // Use -f to set the envelope sender (improves deliverability on shared hosting)
    $mailSent = mail($toEmail, $subject, $msg, $hdrs, '-f noreply@tasks.tesh.ai');

    $verb     = $hadPending ? 'Invite resent' : 'Invite sent';
    $response = ['ok' => true, 'message' => "$verb to $toEmail"];
    if (!$mailSent) {
        // Invite is saved — recipient can still accept via tasks.tesh.ai — but warn
        // the sender that the delivery email did not dispatch successfully.
        $response['emailWarning'] = true;
        $response['inviteLink']   = $link;
        $response['message']      = "Invite saved but the delivery email could not be sent. "
                                  . "Share this link directly with $toEmail: $link";
    }
    jsonResponse($response);
}

/* ACCEPT */
if ($action === 'accept') {
    $token = trim($body['token'] ?? '');
    if (!$token) { jsonResponse(['error' => 'Missing token'], 400); }

    // Find invite in pending_recv
    $invite = null;
    $idx    = null;
    foreach ($mine['pending_recv'] ?? [] as $i => $inv) {
        if ($inv['token'] === $token) { $invite = $inv; $idx = $i; break; }
    }
    if ($invite === null) { jsonResponse(['error' => 'Invite not found or already handled'], 404); }

    $fromEmail = $invite['from'];
    $fromName  = $invite['fromName'] ?? $fromEmail;

    // Add mutual connection
    if (!array_filter($mine['connections'], fn($c) => strtolower($c['email']) === strtolower($fromEmail))) {
        $mine['connections'][] = ['email' => $fromEmail, 'name' => $fromName];
    }
    array_splice($mine['pending_recv'], $idx, 1);
    $all[$userEmail] = $mine;

    $sender = $all[$fromEmail] ?? emptyUser();
    if (!array_filter($sender['connections'], fn($c) => strtolower($c['email']) === strtolower($userEmail))) {
        $sender['connections'][] = ['email' => $userEmail, 'name' => $userName];
    }
    $sender['pending_sent'] = array_values(
        array_filter($sender['pending_sent'] ?? [], fn($i) => $i['token'] !== $token)
    );
    $all[$fromEmail] = $sender;

    saveConns($DATA_FILE, $all);
    jsonResponse(['ok' => true, 'connectedWith' => ['email' => $fromEmail, 'name' => $fromName]]);
}

/* DECLINE */
if ($action === 'decline') {
    $token = trim($body['token'] ?? '');
    if (!$token) { jsonResponse(['error' => 'Missing token'], 400); }

    $mine['pending_recv'] = array_values(
        array_filter($mine['pending_recv'] ?? [], fn($i) => $i['token'] !== $token)
    );
    $all[$userEmail] = $mine;

    // Remove from sender's pending_sent too
    foreach ($all as $email => &$data) {
        if (isset($data['pending_sent'])) {
            $data['pending_sent'] = array_values(
                array_filter($data['pending_sent'], fn($i) => $i['token'] !== $token)
            );
        }
    }
    unset($data);

    saveConns($DATA_FILE, $all);
    jsonResponse(['ok' => true]);
}

/* REMOVE */
if ($action === 'remove') {
    $removeEmail = strtolower(trim($body['email'] ?? ''));
    if (!$removeEmail) { jsonResponse(['error' => 'Missing email'], 400); }

    $mine['connections'] = array_values(
        array_filter($mine['connections'] ?? [], fn($c) => strtolower($c['email']) !== $removeEmail)
    );
    $all[$userEmail] = $mine;

    if (isset($all[$removeEmail])) {
        $all[$removeEmail]['connections'] = array_values(
            array_filter($all[$removeEmail]['connections'] ?? [], fn($c) => strtolower($c['email']) !== strtolower($userEmail))
        );
    }

    saveConns($DATA_FILE, $all);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Unknown action'], 400);
