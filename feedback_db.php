<?php
/**
 * feedback_db.php — feedback table (bug/feature/suggestion submissions
 * from users, reviewed by admins). Auto-creates its table on first use.
 */
require_once __DIR__ . '/db.php';

function feedbackDb(): PDO {
    static $ready = false;
    $pdo = appDb();
    if (!$ready) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS feedback (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM('bug','feature','suggestion') NOT NULL,
                description TEXT NOT NULL,
                submitter_email VARCHAR(255) NOT NULL,
                submitter_name VARCHAR(255) NOT NULL,
                status ENUM('new','reviewed','resolved') NOT NULL DEFAULT 'new',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $ready = true;
    }
    return $pdo;
}

function feedbackCreate(string $type, string $description, string $email, string $name): void {
    $stmt = feedbackDb()->prepare(
        'INSERT INTO feedback (type, description, submitter_email, submitter_name) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$type, $description, $email, $name]);
}

function feedbackList(): array {
    return feedbackDb()->query('SELECT * FROM feedback ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
}

function feedbackUpdateStatus(int $id, string $status): void {
    $stmt = feedbackDb()->prepare('UPDATE feedback SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
}
