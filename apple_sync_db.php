<?php
/**
 * apple_sync_db.php — Google Task ↔ Apple Reminders (VTODO) link table.
 *
 * TaskStick has no local table for tasks themselves — Google Tasks is
 * fetched live on every load. This table exists purely to remember which
 * Google tasks have already been pushed to Apple Reminders and their
 * remote identifiers, so a later push updates the existing reminder
 * instead of creating a duplicate.
 */
require_once __DIR__ . '/db.php';

function appleSyncDb(): PDO {
    static $ready = false;
    $pdo = appDb();
    if (!$ready) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS apple_reminder_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_email VARCHAR(255) NOT NULL,
                google_task_id VARCHAR(255) NOT NULL,
                google_list_id VARCHAR(255) NOT NULL,
                apple_calendar_url VARCHAR(500) NOT NULL,
                apple_uid VARCHAR(255) NOT NULL,
                apple_etag VARCHAR(255) NULL,
                google_updated_at VARCHAR(64) NULL,
                last_pushed_at DATETIME NULL,
                UNIQUE KEY uniq_task (user_email, google_task_id),
                INDEX idx_apple_uid (user_email, apple_uid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $ready = true;
    }
    return $pdo;
}

function appleLinkGet(string $userEmail, string $googleTaskId): ?array {
    $stmt = appleSyncDb()->prepare(
        'SELECT * FROM apple_reminder_links WHERE user_email = ? AND google_task_id = ?'
    );
    $stmt->execute([$userEmail, $googleTaskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function appleLinkByUid(string $userEmail, string $appleUid): ?array {
    $stmt = appleSyncDb()->prepare(
        'SELECT * FROM apple_reminder_links WHERE user_email = ? AND apple_uid = ?'
    );
    $stmt->execute([$userEmail, $appleUid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function appleLinksForList(string $userEmail, string $googleListId): array {
    $stmt = appleSyncDb()->prepare(
        'SELECT * FROM apple_reminder_links WHERE user_email = ? AND google_list_id = ?'
    );
    $stmt->execute([$userEmail, $googleListId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function appleLinkUpsert(
    string $userEmail,
    string $googleTaskId,
    string $googleListId,
    string $appleCalendarUrl,
    string $appleUid,
    ?string $etag,
    string $googleUpdatedAt
): void {
    $stmt = appleSyncDb()->prepare('
        INSERT INTO apple_reminder_links
            (user_email, google_task_id, google_list_id, apple_calendar_url, apple_uid, apple_etag, google_updated_at, last_pushed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            apple_calendar_url = VALUES(apple_calendar_url),
            apple_uid          = VALUES(apple_uid),
            apple_etag         = VALUES(apple_etag),
            google_updated_at  = VALUES(google_updated_at),
            last_pushed_at     = NOW()
    ');
    $stmt->execute([$userEmail, $googleTaskId, $googleListId, $appleCalendarUrl, $appleUid, $etag, $googleUpdatedAt]);
}

function appleLinkDelete(string $userEmail, string $googleTaskId): void {
    $stmt = appleSyncDb()->prepare(
        'DELETE FROM apple_reminder_links WHERE user_email = ? AND google_task_id = ?'
    );
    $stmt->execute([$userEmail, $googleTaskId]);
}
