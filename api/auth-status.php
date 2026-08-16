<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (isAuthenticated()) {
    $user = $_SESSION['user'] ?? [];
    $user['isAdmin']        = isAdmin();
    // Sessions created before this flag existed default to true — they
    // already have full access, so there's nothing to warn them about.
    $user['hasTasksAccess'] = $_SESSION['hasTasksAccess'] ?? true;
    jsonResponse([
        'authenticated' => true,
        'user'          => $user,
    ]);
} else {
    jsonResponse(['authenticated' => false]);
}
