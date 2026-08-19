<?php
// ============================================================
// CalDAV.php — Apple iCloud CalDAV client, for Reminders (VTODO) sync.
// Uses an app-specific password (generated at appleid.apple.com), same
// discovery/PROPFIND/REPORT pattern as the sibling Contacts app's
// CardDAV.php, adapted for calendars + VTODO items instead of address
// books + vCards. A "Reminders list" is a calendar collection whose
// supported-calendar-component-set includes VTODO (event calendars only
// support VEVENT).
// ============================================================

class CalDAV {

    private string $username;
    private string $password;
    private string $principalUrl    = '';
    private string $calendarHomeUrl = '';
    private string $lastError       = '';

    private const DISCOVERY_URL  = 'https://caldav.icloud.com/.well-known/caldav';
    private const TIMEOUT        = 30;
    private const REPORT_TIMEOUT = 90;

    public function __construct(string $appleEmail, string $appSpecificPassword) {
        $this->username = $appleEmail;
        $this->password = $appSpecificPassword;
    }

    public function getLastError(): string { return $this->lastError; }

    // ---- Discovery ------------------------------------------

    public function discover(): bool {
        try {
            $principalUrl = $this->discoverPrincipal();
            if (!$principalUrl) {
                $this->lastError = 'Could not resolve iCloud principal URL (check credentials)';
                return false;
            }
            $this->principalUrl = $principalUrl;

            $homeUrl = $this->getCalendarHome($principalUrl);
            if (!$homeUrl) {
                $this->lastError = 'Could not find calendar home set';
                return false;
            }
            $this->calendarHomeUrl = $homeUrl;
            return true;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    private function discoverPrincipal(): string {
        // Apple's well-known endpoint redirects to a user-specific principal
        // URL that contains the DSID. Follow the redirect and return the
        // effective URL — the redirect itself is what gives us the right
        // URL, regardless of the final status code.
        $ch = curl_init(self::DISCOVERY_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HEADER         => true,
        ]);
        curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        return $info['url'] ?? '';
    }

    private function getCalendarHome(string $principalUrl): string {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <C:calendar-home-set/>
    <D:current-user-principal/>
  </D:prop>
</D:propfind>
XML;
        $response = $this->propfind($principalUrl, $xml, 0);

        // Scope the href search to INSIDE the calendar-home-set element
        // specifically. current-user-principal is requested in the same
        // PROPFIND and its href would otherwise be the next one found by a
        // loose "text after calendar-home-set" match — silently returning
        // the wrong URL instead of correctly failing when calendar-home-set
        // itself is empty.
        if (preg_match('|<(?:[a-zA-Z0-9_-]+:)?calendar-home-set[^>]*>(.*?)</(?:[a-zA-Z0-9_-]+:)?calendar-home-set>|si', $response, $homeBlock)) {
            if (preg_match('|<(?:[a-zA-Z0-9_-]+:)?href[^>]*>(.*?)</(?:[a-zA-Z0-9_-]+:)?href>|si', $homeBlock[1], $m)) {
                return $this->absoluteUrl($principalUrl, trim($m[1]));
            }
        }
        return '';
    }

    // ---- Reminders lists (calendars that support VTODO) -----

    /** Returns array of ['url' => ..., 'displayName' => ...] for calendars that support VTODO */
    public function listReminderLists(): array {
        if (!$this->calendarHomeUrl) {
            throw new RuntimeException('Not discovered — call discover() first.');
        }
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <D:resourcetype/>
    <D:displayname/>
    <C:supported-calendar-component-set/>
  </D:prop>
</D:propfind>
XML;
        $response = $this->propfind($this->calendarHomeUrl, $xml, 1);
        return $this->parseReminderLists($response);
    }

    private function parseReminderLists(string $xml): array {
        $lists = [];
        if (preg_match_all('|<(?:[a-zA-Z0-9_-]+:)?response>(.*?)</(?:[a-zA-Z0-9_-]+:)?response>|si', $xml, $matches)) {
            foreach ($matches[1] as $block) {
                // Only calendars advertising VTODO support are Reminders
                // lists — plain event calendars only support VEVENT.
                if (stripos($block, 'VTODO') === false) continue;

                $href = '';
                if (preg_match('|<(?:[a-zA-Z0-9_-]+:)?href[^>]*>(.*?)</(?:[a-zA-Z0-9_-]+:)?href>|si', $block, $m)) {
                    $href = trim($m[1]);
                }
                if (!$href) continue;

                $name = basename(rtrim($href, '/'));
                if (preg_match('|<(?:[a-zA-Z0-9_-]+:)?displayname[^>]*>(.*?)</(?:[a-zA-Z0-9_-]+:)?displayname>|si', $block, $m)) {
                    $decoded = html_entity_decode(trim($m[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    if ($decoded !== '') $name = $decoded;
                }

                $lists[] = [
                    'url'         => $this->absoluteUrl($this->calendarHomeUrl, $href),
                    'displayName' => $name,
                ];
            }
        }
        return $lists;
    }

    /** Find a Reminders list by exact display name, or null */
    public function findReminderListByName(string $name): ?array {
        foreach ($this->listReminderLists() as $list) {
            if ($list['displayName'] === $name) return $list;
        }
        return null;
    }

    // ---- List todos (two-step, mirrors CardDAV's contact listing) ----

    /** Returns array of ['uid' => ..., 'etag' => ..., 'ical' => ..., 'href' => ...] */
    public function listTodos(string $calendarUrl): array {
        $hrefs = $this->listTodoHrefs($calendarUrl);
        if (empty($hrefs)) return [];
        return $this->multigetTodos($calendarUrl, $hrefs);
    }

    private function listTodoHrefs(string $calendarUrl): array {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<D:propfind xmlns:D="DAV:">
  <D:prop>
    <D:getetag/>
  </D:prop>
</D:propfind>
XML;
        $response = $this->propfind($calendarUrl, $xml, 1);

        $hrefs = [];
        $calPath = rtrim(parse_url($calendarUrl, PHP_URL_PATH) ?: '', '/');
        if (preg_match_all('|<(?:[a-zA-Z0-9_-]+:)?href[^>]*>(.*?)</(?:[a-zA-Z0-9_-]+:)?href>|si', $response, $m)) {
            foreach ($m[1] as $href) {
                $href     = trim($href);
                $hrefPath = rtrim(parse_url($href, PHP_URL_PATH) ?: $href, '/');
                if (empty($href) || $hrefPath === $calPath) continue;
                $hrefs[] = $href;
            }
        }
        return $hrefs;
    }

    private function multigetTodos(string $calendarUrl, array $hrefs): array {
        $hrefXml = implode("\n", array_map(
            fn($h) => '  <D:href>' . htmlspecialchars(trim($h), ENT_XML1, 'UTF-8') . '</D:href>',
            $hrefs
        ));

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<C:calendar-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <D:getetag/>
    <C:calendar-data/>
  </D:prop>
$hrefXml
</C:calendar-multiget>
XML;

        $response = $this->report($calendarUrl, $xml);
        return $this->parseTodosList($response);
    }

    private function parseTodosList(string $xml): array {
        $todos = [];
        if (preg_match_all('|<(?:[a-zA-Z0-9_-]+:)?response>(.*?)</(?:[a-zA-Z0-9_-]+:)?response>|si', $xml, $responses)) {
            foreach ($responses[1] as $block) {
                $etag = ''; $ical = ''; $href = '';

                if (preg_match('|<(?:[a-zA-Z0-9_-]+:)?href[^>]*>(.*?)</(?:[a-zA-Z0-9_-]+:)?href>|si', $block, $m))
                    $href = trim($m[1]);
                if (preg_match('|<(?:[a-zA-Z0-9_-]+:)?getetag[^>]*>(.*?)</(?:[a-zA-Z0-9_-]+:)?getetag>|si', $block, $m))
                    $etag = trim($m[1]);
                if (preg_match('|<(?:[a-zA-Z0-9_-]+:)?calendar-data[^>]*>(.*?)</(?:[a-zA-Z0-9_-]+:)?calendar-data>|si', $block, $m))
                    $ical = html_entity_decode(trim($m[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');

                if (!empty($ical) && stripos($ical, 'VTODO') !== false) {
                    $uid = self::extractICalField($ical, 'UID');
                    $todos[] = [
                        'uid'  => $uid ?: basename($href, '.ics'),
                        'etag' => $etag,
                        'ical' => $ical,
                        'href' => $href,
                    ];
                }
            }
        }
        return $todos;
    }

    // ---- Create / Update / Delete ---------------------------

    /** Creates a new VTODO. Returns its UID. Throws if the write fails. */
    public function createTodo(string $calendarUrl, array $task): string {
        $uid  = 'task-' . uniqid('', true);
        $ical = $this->taskToVTodo($task, $uid);
        $url  = rtrim($calendarUrl, '/') . '/' . $uid . '.ics';
        if (!$this->putVTodo($url, $ical)) {
            throw new RuntimeException('Failed to create reminder in Apple Reminders');
        }
        return $uid;
    }

    /** Throws if the write fails. */
    public function updateTodo(string $calendarUrl, string $uid, array $task, ?string $etag = null): void {
        $ical = $this->taskToVTodo($task, $uid);
        $url  = rtrim($calendarUrl, '/') . '/' . $uid . '.ics';
        if (!$this->putVTodo($url, $ical, $etag)) {
            throw new RuntimeException('Failed to update reminder in Apple Reminders');
        }
    }

    /** Returns true on success (including 404 — already gone counts as deleted). */
    public function deleteTodo(string $calendarUrl, string $href): bool {
        $url = $this->absoluteUrl($calendarUrl, $href);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300) || $code === 404;
    }

    // ---- VTODO conversion ------------------------------------

    /** Parse a VTODO's key fields (title, notes, due, completed) */
    public static function parseVTodo(string $ical): array {
        $summary   = self::extractICalField($ical, 'SUMMARY');
        $desc      = self::extractICalField($ical, 'DESCRIPTION');
        $status    = self::extractICalField($ical, 'STATUS');
        $due       = self::extractICalField($ical, 'DUE');
        $completed = self::extractICalField($ical, 'COMPLETED');

        return [
            'title'     => self::decodeICalValue($summary),
            'notes'     => self::decodeICalValue($desc),
            'due'       => $due ? self::icalDateToIso($due) : null,
            'completed' => (strtoupper($status) === 'COMPLETED') || !empty($completed),
        ];
    }

    public static function extractICalField(string $ical, string $field): string {
        $field = strtoupper($field);
        $lines = self::unfoldLines($ical);
        foreach ($lines as $line) {
            // Match "FIELD:" or "FIELD;params:" but not e.g. "X-FIELD:"
            if (preg_match('/^' . preg_quote($field, '/') . '(?:;[^:]*)?:(.*)/i', $line, $m)) {
                return trim($m[1]);
            }
        }
        return '';
    }

    private static function unfoldLines(string $ical): array {
        // RFC 5545 §3.1 line folding — a leading space/tab continues the
        // previous line.
        $ical = preg_replace("/\r\n[ \t]/", '', $ical);
        $ical = preg_replace("/\n[ \t]/", '', $ical);
        return preg_split('/\r\n|\n|\r/', $ical);
    }

    private function taskToVTodo(array $task, string $uid): string {
        $now = gmdate('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//TaskStick//Apple Reminders Sync//EN',
            'BEGIN:VTODO',
            'UID:' . $uid,
            'DTSTAMP:' . $now,
            'SUMMARY:' . self::escapeICalValue($task['title'] ?? ''),
        ];

        if (!empty($task['notes'])) {
            $lines[] = 'DESCRIPTION:' . self::escapeICalValue($task['notes']);
        }
        if (!empty($task['due'])) {
            // Google Tasks due dates are always UTC midnight of a calendar
            // date — treat them as date-only here too (VALUE=DATE), no
            // timezone conversion needed either way.
            $dueDate = substr($task['due'], 0, 10);
            $lines[] = 'DUE;VALUE=DATE:' . str_replace('-', '', $dueDate);
        }
        if (($task['status'] ?? '') === 'completed') {
            $lines[] = 'STATUS:COMPLETED';
            $lines[] = 'COMPLETED:' . $now;
            $lines[] = 'PERCENT-COMPLETE:100';
        } else {
            $lines[] = 'STATUS:NEEDS-ACTION';
        }

        $lines[] = 'END:VTODO';
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    private static function escapeICalValue(string $v): string {
        $v = str_replace('\\', '\\\\', $v);
        $v = str_replace(["\r\n", "\n", "\r"], '\\n', $v);
        $v = str_replace(',', '\\,', $v);
        $v = str_replace(';', '\\;', $v);
        return $v;
    }

    private static function decodeICalValue(string $v): string {
        // A single left-to-right pass, not sequential str_replace() calls —
        // those independently re-scan the whole string each time, so a
        // literal backslash immediately followed by a bare "n" (e.g. a
        // Windows path like "C:\notes") gets its second escaped backslash
        // (encoded as \\) misread as a \n newline escape by the first pass,
        // silently corrupting the text. Matching one escape sequence at a
        // time and consuming it whole avoids that.
        return trim(preg_replace_callback('/\\\\([nN,;\\\\])/', function ($m) {
            return $m[1] === 'n' || $m[1] === 'N' ? "\n" : $m[1];
        }, $v));
    }

    private static function icalDateToIso(string $icalDate): ?string {
        // VALUE=DATE form: YYYYMMDD ; datetime form: YYYYMMDDTHHMMSSZ — both
        // reduce to the same calendar date, which is all Google Tasks
        // stores anyway.
        $digits = preg_replace('/[^0-9]/', '', $icalDate);
        if (strlen($digits) >= 8) {
            $y = substr($digits, 0, 4);
            $m = substr($digits, 4, 2);
            $d = substr($digits, 6, 2);
            return "{$y}-{$m}-{$d}T00:00:00.000Z";
        }
        return null;
    }

    // ---- HTTP helpers (identical pattern to CardDAV.php) -----

    private function propfind(string $url, string $xml, int $depth = 0): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PROPFIND',
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/xml; charset=utf-8',
                "Depth: $depth",
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return $raw ?: '';
    }

    private function report(string $url, string $xml): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'REPORT',
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/xml; charset=utf-8',
                'Depth: 1',
            ],
            CURLOPT_TIMEOUT        => self::REPORT_TIMEOUT,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return $raw ?: '';
    }

    private function putVTodo(string $url, string $ical, ?string $etag = null): bool {
        $headers = ['Content-Type: text/calendar; charset=utf-8'];
        if ($etag) $headers[] = "If-Match: $etag";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $ical,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    private function absoluteUrl(string $base, string $href): string {
        if (str_starts_with($href, 'http')) return $href;
        $parsed = parse_url($base);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host']   ?? '';
        $port   = isset($parsed['port']) ? ":{$parsed['port']}" : '';
        return str_starts_with($href, '/')
            ? "$scheme://$host$port$href"
            : rtrim($base, '/') . '/' . ltrim($href, '/');
    }
}
