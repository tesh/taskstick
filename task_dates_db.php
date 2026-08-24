<?php
/**
 * task_dates_db.php — tracks when each task was first seen by this app, so
 * the task-age indicator has something to measure.
 *
 * Google Tasks' API has no "created" field on a task, so this records the
 * first moment *our own app* observed the task: exact (to the second) for
 * a task created through TaskStick itself (api/tasks.php stamps it right
 * after Google confirms creation), an approximation for anything that
 * already existed before this feature shipped or was created elsewhere
 * (Google's own web/mobile Tasks apps) — those get dated from whenever
 * TaskStick first happened to load them, not their true creation date.
 *
 * Auto-creates its table on first use, matching feedback_db.php.
 */
require_once __DIR__ . '/db.php';

function taskDatesDb(): PDO {
    static $ready = false;
    $pdo = appDb();
    if (!$ready) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS task_created_dates (
                task_id VARCHAR(255) NOT NULL,
                user_email VARCHAR(255) NOT NULL,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (task_id, user_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $ready = true;
    }
    return $pdo;
}

/** Record a first-seen date for each task ID that doesn't already have one
 * for this user — INSERT IGNORE means an existing row is left untouched. */
function taskDatesBackfillMissing(array $taskIds, string $userEmail): void {
    if (!$taskIds || !$userEmail) return;
    $stmt = taskDatesDb()->prepare(
        'INSERT IGNORE INTO task_created_dates (task_id, user_email) VALUES (?, ?)'
    );
    foreach ($taskIds as $taskId) {
        $stmt->execute([$taskId, $userEmail]);
    }
}

/** task_id => ISO 8601 UTC string, for exactly the task IDs asked for. */
function taskDatesGetFor(array $taskIds, string $userEmail): array {
    if (!$taskIds || !$userEmail) return [];
    $pdo = taskDatesDb();
    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT task_id, first_seen_at FROM task_created_dates
         WHERE user_email = ? AND task_id IN ($placeholders)"
    );
    $stmt->execute(array_merge([$userEmail], $taskIds));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // MySQL DATETIME has no timezone; it's always written via NOW()/
        // CURRENT_TIMESTAMP in the server's UTC session, so re-attach "Z"
        // rather than letting JS parse it as local time.
        $out[$row['task_id']] = str_replace(' ', 'T', $row['first_seen_at']) . 'Z';
    }
    return $out;
}
