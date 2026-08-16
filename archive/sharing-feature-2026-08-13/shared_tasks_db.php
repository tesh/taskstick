<?php
/**
 * shared_tasks_db.php — Jointly-owned tasks ("Shared list"). When a task
 * or list is shared, it moves out of the owner's Google Tasks entirely and
 * becomes a row here, visible/manageable with full parity by everyone in
 * shared_task_participants (owner included). Auto-creates its tables on
 * first use.
 */
require_once __DIR__ . '/db.php';

class SharedTaskAuthException extends RuntimeException {}

function sharedTasksDb(): PDO {
    static $ready = false;
    $pdo = appDb();
    if (!$ready) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS shared_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(1024) NOT NULL,
                notes TEXT NULL,
                due DATE NULL,
                status ENUM('needsAction','completed') NOT NULL DEFAULT 'needsAction',
                completed_at DATETIME NULL,
                starred TINYINT(1) NOT NULL DEFAULT 0,
                owner_email VARCHAR(255) NOT NULL,
                owner_name VARCHAR(255) NOT NULL,
                source_list_title VARCHAR(255) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS shared_task_participants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                shared_task_id INT NOT NULL,
                email VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                UNIQUE KEY uniq_task_email (shared_task_id, email),
                FOREIGN KEY (shared_task_id) REFERENCES shared_tasks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $ready = true;
    }
    return $pdo;
}

/** Create a shared task. $participants is [['email'=>,'name'=>], ...] and MUST include the owner. */
function sharedTaskCreate(string $title, string $notes, ?string $due, string $ownerEmail,
                          string $ownerName, ?string $sourceListTitle, array $participants): int {
    $db = sharedTasksDb();
    $stmt = $db->prepare(
        'INSERT INTO shared_tasks (title, notes, due, owner_email, owner_name, source_list_title)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$title, $notes ?: null, $due ?: null, $ownerEmail, $ownerName, $sourceListTitle]);
    $id = (int)$db->lastInsertId();

    $pstmt = $db->prepare(
        'INSERT IGNORE INTO shared_task_participants (shared_task_id, email, name) VALUES (?, ?, ?)'
    );
    foreach ($participants as $p) {
        $pstmt->execute([$id, $p['email'], $p['name']]);
    }
    return $id;
}

function sharedTaskIsParticipant(int $id, string $email): bool {
    $stmt = sharedTasksDb()->prepare(
        'SELECT 1 FROM shared_task_participants WHERE shared_task_id = ? AND email = ?'
    );
    $stmt->execute([$id, $email]);
    return (bool)$stmt->fetchColumn();
}

/** All shared tasks the given email is a participant of, each with its participants[] attached. */
function sharedTaskListForUser(string $email): array {
    $db = sharedTasksDb();
    $stmt = $db->prepare("
        SELECT st.* FROM shared_tasks st
        JOIN shared_task_participants sp ON sp.shared_task_id = st.id
        WHERE sp.email = ?
        ORDER BY st.status ASC, st.sort_order ASC, st.created_at ASC
    ");
    $stmt->execute([$email]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$tasks) return [];

    $ids = array_column($tasks, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $pstmt = $db->prepare("SELECT shared_task_id, email, name FROM shared_task_participants WHERE shared_task_id IN ($in)");
    $pstmt->execute($ids);
    $byTask = [];
    foreach ($pstmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byTask[$row['shared_task_id']][] = ['email' => $row['email'], 'name' => $row['name']];
    }
    foreach ($tasks as &$t) {
        $t['participants'] = $byTask[$t['id']] ?? [];
        $t['starred']      = (bool)$t['starred'];
    }
    return $tasks;
}

/** Partial update of title/notes/due — only participants may call this. */
function sharedTaskUpdate(int $id, string $email, array $fields): void {
    if (!sharedTaskIsParticipant($id, $email)) {
        throw new SharedTaskAuthException('Not authorized — you are not a participant on this shared task');
    }
    $allowed = ['title', 'notes', 'due', 'starred', 'status', 'completed_at', 'sort_order'];
    $sets = [];
    $vals = [];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $sets[] = "$k = ?";
        $vals[] = $v;
    }
    if (!$sets) return;
    $vals[] = $id;
    $stmt = sharedTasksDb()->prepare('UPDATE shared_tasks SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($vals);
}

/** Replace the full participant list. Any current participant may call this. Deletes the task if it empties out. */
function sharedTaskSetParticipants(int $id, string $email, array $participants): void {
    if (!sharedTaskIsParticipant($id, $email)) {
        throw new SharedTaskAuthException('Not authorized — you are not a participant on this shared task');
    }
    if (!$participants) {
        sharedTaskDelete($id, $email);
        return;
    }
    $db = sharedTasksDb();
    $db->prepare('DELETE FROM shared_task_participants WHERE shared_task_id = ?')->execute([$id]);
    $pstmt = $db->prepare('INSERT IGNORE INTO shared_task_participants (shared_task_id, email, name) VALUES (?, ?, ?)');
    foreach ($participants as $p) {
        $pstmt->execute([$id, $p['email'], $p['name']]);
    }
}

/** Full delete — any participant may call this (full-parity permissions). */
function sharedTaskDelete(int $id, string $email): void {
    if (!sharedTaskIsParticipant($id, $email)) {
        throw new SharedTaskAuthException('Not authorized — you are not a participant on this shared task');
    }
    sharedTasksDb()->prepare('DELETE FROM shared_tasks WHERE id = ?')->execute([$id]);
}
