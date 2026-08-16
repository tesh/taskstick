<?php
require_once '../config.php';

// Revoke Google token if present
if (!empty($_SESSION['access_token'])) {
    @file_get_contents('https://oauth2.googleapis.com/revoke?token=' . urlencode($_SESSION['access_token']));
}

// Destroy session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}
session_destroy();

header('Location: /');
exit;
