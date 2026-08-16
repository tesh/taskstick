<?php
/**
 * db.php — Shared MySQL connection for app-owned tables (feedback,
 * shared_tasks). Requires DB_HOST/DB_NAME/DB_USER/DB_PASS in config.php —
 * until set, throws a clear error the API layer turns into a 503 instead
 * of a fatal error.
 */

function appDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!DB_HOST || !DB_NAME || !DB_USER) {
        throw new RuntimeException('Database is not configured yet (missing DB credentials in config.php)');
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    return $pdo;
}
