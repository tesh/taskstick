<?php
/**
 * api/apple-sync.php — Push enabled lists' Google Tasks to Apple Reminders.
 *
 * GET  → status (last synced, last result)
 * POST { force: bool } → runs a push now.
 *   force:false (used by the automatic background trigger on the client's
 *   normal poll cycle) self-throttles: only actually does the CalDAV work
 *   if enough time has passed since the last run, so having several
 *   devices/tabs open doesn't multiply load against Apple's servers —
 *   whichever device's poll happens to land after the throttle window
 *   does the real work, the rest get an instant no-op. The throttle check
 *   AND the claim (stamping lastSyncedAt) happen under one file lock, so
 *   two requests landing genuinely concurrently can't both pass the check
 *   and both run a full push — the loser sees the winner's just-claimed
 *   timestamp and backs off instead of racing it (which would otherwise
 *   risk creating duplicate reminders for the same not-yet-linked task).
 *   force:true (a manual "Sync now") always runs.
 *
 * Google Tasks is the trusted source: title/notes/due date/list membership
 * always flow FROM Google TO Reminders here. Pulling completion status
 * back from Reminders is a separate stage, not implemented in this file.
 */
require_once '../config.php';
require_once '../lib/Encryption.php';
require_once '../lib/CalDAV.php';
require_once '../apple_sync_db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
set_time_limit(120);

if (!isAuthenticated()) { jsonResponse(['error' => 'Not authenticated'], 401); }

$userEmail = $_SESSION['user']['email'] ?? '';
$userId    = $_SESSION['user']['id']    ?? '';
if (!$userEmail || !$userId) { jsonResponse(['error' => 'User not found in session'], 400); }

$safeId  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId);
$dataDir = dirname(__DIR__) . '/data';
$file    = $dataDir . '/apple_sync_' . $safeId . '.json';

const APPLE_SYNC_THROTTLE_SECONDS = 600; // 10 minutes

function loadAppleSync(string $file): array {
    if (!file_exists($file)) return [];
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : [];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $data = loadAppleSync($file);
    jsonResponse([
        'lastSyncedAt'   => $data['lastSyncedAt']   ?? null,
        'lastSyncResult' => $data['lastSyncResult'] ?? null,
    ]);
}

if ($method !== 'POST') { jsonResponse(['error' => 'Method not allowed'], 405); }

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$force = !empty($body['force']);

// Claim the sync slot atomically: check config/throttle/in-progress AND
// stamp lastSyncedAt in the same locked read-modify-write, so a concurrent
// request can't slip through. The lock itself is only held for this brief
// check-and-stamp, not for the whole (potentially 100s of seconds) push
// that follows — so a separate syncInProgress flag is what actually
// prevents two pushes running at once; force:true only bypasses the time
// throttle, never this. syncInProgress is a timestamp (not a bare
// boolean) so a crashed/timed-out run's flag is treated as stale and
// self-clears after a few minutes, instead of permanently wedging sync.
$claimed    = false;
$skipReason = null;
$data = updateJsonFile($file, function (array $data) use ($force, &$claimed, &$skipReason) {
    if (empty($data['appleEmail']) || empty($data['passwordEnc'])) {
        $skipReason = 'not_configured';
        return $data;
    }
    if (empty($data['enabledLists'])) {
        $skipReason = 'no_lists_selected';
        return $data;
    }
    if (!empty($data['syncInProgress'])) {
        $startedAt = strtotime($data['syncInProgress']);
        if ($startedAt && (time() - $startedAt) < 180) {
            $skipReason = 'already_running';
            return $data;
        }
        // Stale — the process that set this never got to clear it
        // (crash, or hit set_time_limit). Treat the slot as free.
    }
    if (!$force && !empty($data['lastSyncedAt'])) {
        $elapsed = time() - strtotime($data['lastSyncedAt']);
        if ($elapsed < APPLE_SYNC_THROTTLE_SECONDS) {
            $skipReason = 'throttled';
            return $data;
        }
    }
    $claimed = true;
    $data['lastSyncedAt']   = gmdate('c');
    $data['syncInProgress'] = gmdate('c');
    return $data;
});

if (!$claimed) { jsonResponse(['skipped' => $skipReason]); }

try {
    $password = Encryption::decrypt($data['passwordEnc']);
    $dav = new CalDAV($data['appleEmail'], $password);
    if (!$dav->discover()) {
        throw new RuntimeException('Could not connect to iCloud: ' . $dav->getLastError());
    }

    // Fetch every Reminders list once, up front, instead of once per
    // enabled list — a Reminders list lookup is a full PROPFIND of the
    // whole calendar home, so doing it N times for N enabled lists is
    // pure waste against the same unchanging data.
    $listsByName = [];
    foreach ($dav->listReminderLists() as $l) {
        $listsByName[$l['displayName']] = $l;
    }

    $pushed = 0;
    $errors = [];

    foreach ($data['enabledLists'] as $listInfo) {
        $googleListId = $listInfo['id']    ?? '';
        $listTitle    = $listInfo['title'] ?? '';
        if (!$googleListId || !$listTitle) continue;

        $appleList = $listsByName[$listTitle] ?? null;
        if (!$appleList) {
            // No matching Reminders list exists yet — create one rather
            // than failing, so enabling a list "just works" without a
            // separate manual step on the device.
            try {
                $appleList = $dav->createReminderList($listTitle);
            } catch (Throwable $e) {
                $appleList = null;
            }
            if ($appleList) {
                $listsByName[$listTitle] = $appleList;
            } else {
                $errors[] = "Could not create a Reminders list named \"$listTitle\": " . $dav->getLastError();
                continue;
            }
        }

        $result = googleApiRequest('/lists/' . urlencode($googleListId) . '/tasks?' . http_build_query([
            'maxResults'    => 100,
            'showCompleted' => 'true',
            'showHidden'    => 'true',
        ]));
        $tasks = $result['items'] ?? [];
        $currentTaskIds = [];

        foreach ($tasks as $task) {
            if (empty($task['title'])) continue;
            $googleTaskId  = $task['id'];
            $googleUpdated = $task['updated'] ?? '';
            $currentTaskIds[] = $googleTaskId;

            $link = appleLinkGet($userEmail, $googleTaskId);

            // Already pushed and nothing has changed on the Google side
            // since — skip the network round-trip entirely.
            if ($link && $link['google_updated_at'] === $googleUpdated) continue;

            $taskData = [
                'title'  => $task['title'],
                'notes'  => $task['notes'] ?? '',
                'due'    => $task['due']   ?? null,
                'status' => $task['status'] ?? 'needsAction',
            ];

            try {
                if ($link) {
                    $dav->updateTodo($appleList['url'], $link['apple_uid'], $taskData, $link['apple_etag']);
                    appleLinkUpsert($userEmail, $googleTaskId, $googleListId, $appleList['url'], $link['apple_uid'], null, $googleUpdated);
                } else {
                    $uid = $dav->createTodo($appleList['url'], $taskData);
                    appleLinkUpsert($userEmail, $googleTaskId, $googleListId, $appleList['url'], $uid, null, $googleUpdated);
                }
                $pushed++;
            } catch (Throwable $e) {
                $errors[] = "\"{$task['title']}\": " . $e->getMessage();
            }
        }

        // Reconcile: a task previously pushed from this list that's no
        // longer present here — because it was deleted, or moved to a
        // different list (Google issues a new task ID on every cross-list
        // move, so the "old" link is otherwise never revisited) — is
        // stale. Remove its reminder and the link row so it doesn't sit
        // in Apple Reminders forever as an orphan; a genuine move gets a
        // fresh link created above/next-run from its new list.
        foreach (appleLinksForList($userEmail, $googleListId) as $prevLink) {
            if (in_array($prevLink['google_task_id'], $currentTaskIds, true)) continue;
            try {
                if ($dav->deleteTodo($prevLink['apple_calendar_url'], $prevLink['apple_uid'] . '.ics')) {
                    appleLinkDelete($userEmail, $prevLink['google_task_id']);
                } else {
                    $errors[] = 'Could not remove a stale reminder — will retry next sync.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Could not remove a stale reminder: ' . $e->getMessage();
            }
        }
    }

    updateJsonFile($file, function (array $data) use ($pushed, $errors) {
        $data['lastSyncResult']  = ['pushed' => $pushed, 'errors' => $errors];
        $data['syncInProgress']  = null;
        return $data;
    });

    jsonResponse(['success' => true, 'pushed' => $pushed, 'errors' => $errors]);
} catch (Throwable $e) {
    updateJsonFile($file, function (array $data) use ($e) {
        $data['lastSyncResult']  = ['error' => $e->getMessage()];
        $data['syncInProgress']  = null;
        return $data;
    });
    jsonResponse(['error' => $e->getMessage()], 500);
}
