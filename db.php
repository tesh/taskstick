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
    // Force this session's timezone to UTC — IONOS's MySQL default isn't
    // guaranteed to be UTC, and NOW()/CURRENT_TIMESTAMP values get sent to
    // the client as if they were UTC (see task_dates_db.php). Without this,
    // a non-UTC server default would silently skew every stored timestamp.
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}
