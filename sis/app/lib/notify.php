<?php
declare(strict_types=1);

/**
 * v62 Personal notifications for everyone (students, teachers, librarians, canteen staff): a bell in the LMS, Library,
 * Canteen POS and Student Portal, a "Notifications" list, and an email copy when the school has set up email and the
 * person has an email on their account. Rows live in sis_notifications (recipient_user_id = the person's SIS id).
 *
 * Emails are queued and sent after the page has been delivered, so nobody waits for the mail server.
 * Library reminders (due tomorrow, overdue, reserved book ready) and canteen low-stock alerts come from a short check
 * that runs at most every 10 minutes while people use the system; each alert is sent once (dedupe key).
 */
final class Notify
{
    private static bool $flushRegistered = false;

    public static function install(PDO $db): void
    {
        $cols = array_column($db->query('SHOW COLUMNS FROM sis_notifications')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('link_url', $cols, true)) $db->exec("ALTER TABLE sis_notifications ADD COLUMN link_url VARCHAR(500) NULL, ADD COLUMN dedupe_key VARCHAR(120) NULL,
            ADD COLUMN email_to VARCHAR(191) NULL, ADD COLUMN email_status ENUM('NONE','PENDING','SENT','FAILED') NOT NULL DEFAULT 'NONE', ADD COLUMN emailed_at DATETIME NULL,
            ADD UNIQUE KEY u_sis_notif_dedupe (dedupe_key), ADD KEY k_sis_notif_email (email_status, id)");
    }

    // ------------------------------------------------------------------ who to send to

    /** The SIS user id of a central account (what sis_notifications.recipient_user_id holds). */
    public static function userOf(int $accountId): ?int
    {
        $a = Auth::central()->account($accountId);
        if (!$a || $a['status'] !== 'ACTIVE') return null;
        $id = Auth::central()->legacyId($accountId, 'SIS');
        return $id ? (int) $id : null;
    }

    public static function accountOfStudent(string $studentDocId): ?int
    {
        $id = Db::val('SELECT account_id FROM central_person_links WHERE student_doc_id = ? AND verified_at IS NOT NULL', [$studentDocId]);
        return $id ? (int) $id : null;
    }

    public static function accountOfTeacher(string $teacherDocId): ?int
    {
        $id = Db::val('SELECT account_id FROM central_person_links WHERE teacher_doc_id = ? AND verified_at IS NOT NULL', [$teacherDocId]);
        return $id ? (int) $id : null;
    }

    /** Active accounts whose groups give a permission (checked with per-person changes too). */
    public static function accountsWith(string $permission): array
    {
        $ids = Db::col("SELECT DISTINCT ug.account_id FROM central_user_groups ug JOIN central_groups g ON g.id = ug.group_id AND g.is_active = 1
            JOIN central_group_permissions gp ON gp.group_id = g.id AND gp.is_enabled = 1 AND gp.permission_code = ?
            JOIN central_accounts a ON a.id = ug.account_id AND a.status = 'ACTIVE'
            WHERE ug.revoked_at IS NULL AND ug.valid_from <= NOW() AND (ug.expires_at IS NULL OR ug.expires_at > NOW())", [$permission]);
        return array_values(array_filter(array_map('intval', $ids), fn($id) => Auth::central()->can($id, $permission)));
    }

    // ------------------------------------------------------------------ sending

    /**
     * Notify one account. $link: a site-relative address such as "../lms/index.php?r=work&id=…" or "index.php?…".
     * $dedupe: a key that makes the same alert go out only once. Returns the notification id, or null when skipped.
     */
    public static function toAccount(?int $accountId, string $type, string $title, string $message, string $link = '', ?string $dedupe = null): ?int
    {
        if (!$accountId) return null;
        try { return self::send($accountId, $type, $title, $message, $link, $dedupe); }
        catch (Throwable $e) { error_log('AcadLink notification not saved: ' . $e->getMessage()); return null; } // a notification never stops the work it is about
    }

    private static function send(int $accountId, string $type, string $title, string $message, string $link, ?string $dedupe): ?int
    {
        $uid = self::userOf($accountId);
        if (!$uid) return null;
        if ($dedupe !== null && Db::val('SELECT 1 FROM sis_notifications WHERE dedupe_key = ?', [mb_substr($dedupe, 0, 120)])) return null;
        $email = '';
        try { if (!Mailer::missing()) $email = AccountEmail::forReset($accountId); } catch (Throwable) { $email = ''; }
        try {
            $id = Db::insert('sis_notifications', ['recipient_user_id' => $uid, 'type' => mb_substr($type, 0, 40), 'title' => mb_substr($title, 0, 160), 'message' => mb_substr($message, 0, 1000),
                'link_url' => $link !== '' ? mb_substr($link, 0, 500) : null, 'dedupe_key' => $dedupe !== null ? mb_substr($dedupe, 0, 120) : null, 'is_read' => 0,
                'email_to' => $email !== '' ? $email : null, 'email_status' => $email !== '' ? 'PENDING' : 'NONE']);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') return null; // the same alert was just sent by another request
            throw $e;
        }
        if ($email !== '') self::scheduleFlush();
        return (int) $id;
    }

    /** Notify several accounts; returns how many got it. */
    public static function toAccounts(array $accountIds, string $type, string $title, string $message, string $link = '', ?string $dedupePrefix = null): int
    {
        $n = 0;
        foreach (array_unique(array_filter(array_map('intval', $accountIds))) as $id)
            if (self::toAccount($id, $type, $title, $message, $link, $dedupePrefix !== null ? $dedupePrefix . ':' . $id : null)) $n++;
        return $n;
    }

    public static function toStudents(array $studentDocIds, string $type, string $title, string $message, string $link = '', ?string $dedupePrefix = null): int
    {
        return self::toAccounts(array_map(fn($s) => self::accountOfStudent((string) $s), $studentDocIds), $type, $title, $message, $link, $dedupePrefix);
    }

    /** After the page is delivered, send up to 25 queued emails (and in tests: right now). */
    private static function scheduleFlush(): void
    {
        if (self::$flushRegistered || PHP_SAPI === 'cli') return;
        self::$flushRegistered = true;
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            try { self::flushEmails(25); } catch (Throwable $e) { error_log('AcadLink notification email: ' . $e->getMessage()); }
        });
    }

    /** Send queued notification emails. Returns [sent, failed]. */
    public static function flushEmails(int $max = 25): array
    {
        $sent = 0; $failed = 0;
        if (Mailer::missing()) return [0, 0];
        foreach (Db::all("SELECT * FROM sis_notifications WHERE email_status = 'PENDING' ORDER BY id LIMIT " . max(1, min(200, $max))) as $n) {
            if (!Db::q("UPDATE sis_notifications SET email_status = 'SENT', emailed_at = NOW() WHERE id = ? AND email_status = 'PENDING'", [$n['id']])->rowCount()) continue; // another request took it
            try {
                $name = (string) (Db::val('SELECT full_name FROM sis_users WHERE id = ?', [$n['recipient_user_id']]) ?: '');
                Mailer::send((string) $n['email_to'], $name, $n['title'], self::emailHtml($n, $name));
                $sent++;
            } catch (Throwable $e) {
                Db::q("UPDATE sis_notifications SET email_status = 'FAILED' WHERE id = ?", [$n['id']]); $failed++;
                error_log('AcadLink notification email ' . $n['id'] . ': ' . $e->getMessage());
            }
        }
        return [$sent, $failed];
    }

    private static function emailHtml(array $n, string $name): string
    {
        $base = self::siteBase();
        $link = $n['link_url'] ? ($base !== '' ? $base . preg_replace('#^(\.\./)+#', '', (string) $n['link_url']) : '') : '';
        return '<p>Hi ' . h($name !== '' ? $name : 'there') . ',</p><p><b>' . h($n['title']) . '</b></p><p>' . nl2br(h($n['message'])) . '</p>'
            . ($link !== '' ? '<p><a href="' . h($link) . '">Open in ' . h(School::brand()) . '</a></p>' : '')
            . '<p style="color:#6b7280;font-size:12px">You get this because you have an account at ' . h(School::brand()) . '. You can see all your notifications after signing in.</p>';
    }

    /** https://school.example/acadlink/ — the site root, from the current request (empty in the command line). */
    private static function siteBase(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') return '';
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $root = preg_replace('#/(sis|lms|canteen|library|portal|finance|api)(/.*)?$#', '/', $path) ?: '/';
        if (!str_ends_with($root, '/')) $root = dirname($root) . '/';
        return ($https ? 'https://' : 'http://') . $host . $root;
    }

    // ------------------------------------------------------------------ the signed-in person's own list

    public static function mine(int $limit = 50, bool $unreadOnly = false): array
    {
        $uid = Auth::id();
        if (!$uid) return [];
        return Db::all('SELECT * FROM sis_notifications WHERE recipient_user_id = ?' . ($unreadOnly ? ' AND is_read = 0' : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)), [$uid]);
    }

    public static function unread(): int
    {
        $uid = Auth::id();
        return $uid ? (int) Db::val('SELECT COUNT(*) FROM sis_notifications WHERE recipient_user_id = ? AND is_read = 0', [$uid]) : 0;
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM sis_notifications WHERE id = ? AND recipient_user_id = ?', [$id, (int) Auth::id()]);
    }

    public static function markRead(int $id): void
    {
        Db::q('UPDATE sis_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND recipient_user_id = ?', [$id, (int) Auth::id()]);
    }

    public static function markAllRead(): void
    {
        Db::q('UPDATE sis_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE recipient_user_id = ? AND is_read = 0', [(int) Auth::id()]);
    }

    /** Only links inside AcadLink are followed (never another website). */
    public static function safeLink(?string $link): string
    {
        $l = trim((string) $link);
        return $l !== '' && preg_match('#^(\.\./)?[a-z]+/index\.php\?[A-Za-z0-9_=&%.\-]*$|^index\.php\?[A-Za-z0-9_=&%.\-]*$#', $l) ? $l : '';
    }

    // ------------------------------------------------------------------ library and canteen checks

    /** At most every 10 minutes: due-tomorrow, overdue and ready-hold reminders; canteen low stock. */
    public static function scan(bool $force = false): array
    {
        $key = 'notify_scan_at';
        if (!$force) {
            $last = (string) (Db::val('SELECT svalue FROM sis_settings WHERE skey = ?', [$key]) ?: '');
            if ($last !== '' && strtotime($last) > time() - 600) return [];
            Db::q('INSERT INTO sis_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, date('Y-m-d H:i:s')]);
        }
        $out = ['due' => 0, 'overdue' => 0, 'ready' => 0, 'low' => 0];
        $tables = array_flip(Db::col('SHOW TABLES'));
        if (isset($tables['library_loans'])) {
            $who = fn(array $r) => $r['borrower_type'] === 'STUDENT' ? self::accountOfStudent((string) $r['borrower_ref']) : self::accountOfTeacher((string) $r['borrower_ref']);
            $link = fn(array $r) => $r['borrower_type'] === 'STUDENT' ? '../portal/index.php?r=library' : '';
            foreach (Db::all("SELECT l.*, t.title FROM library_loans l JOIN library_copies c ON c.id = l.copy_id JOIN library_titles t ON t.id = c.title_id WHERE l.status = 'OPEN' AND l.due_on = CURDATE() + INTERVAL 1 DAY") as $r)
                if (self::toAccount($who($r), 'LIBRARY_DUE', 'Book due tomorrow', '"' . $r['title'] . '" is due tomorrow, ' . date('M j', strtotime($r['due_on'])) . '. Return or renew it to avoid a fine.', $link($r), 'lib-due:' . $r['id'] . ':' . $r['due_on'])) $out['due']++;
            foreach (Db::all("SELECT l.*, t.title FROM library_loans l JOIN library_copies c ON c.id = l.copy_id JOIN library_titles t ON t.id = c.title_id WHERE l.status = 'OPEN' AND l.due_on < CURDATE()") as $r)
                if (self::toAccount($who($r), 'LIBRARY_OVERDUE', 'Book overdue', '"' . $r['title'] . '" was due on ' . date('M j', strtotime($r['due_on'])) . '. Please return it to the library; a fine is added each day it is late.', $link($r), 'lib-overdue:' . $r['id'] . ':' . $r['due_on'])) $out['overdue']++;
            foreach (Db::all("SELECT h.*, t.title FROM library_holds h JOIN library_titles t ON t.id = h.title_id WHERE h.status = 'READY'") as $r)
                if (self::toAccount($who($r), 'LIBRARY_READY', 'Your reserved book is ready', '"' . $r['title'] . '" is waiting for you at the library desk' . ($r['expires_on'] ? ' until ' . date('M j', strtotime($r['expires_on'])) : '') . '.', $link($r), 'lib-ready:' . $r['id'])) $out['ready']++;
        }
        if (isset($tables['canteen_products'])) {
            $low = Db::all('SELECT id, name, stock_qty, low_stock_at FROM canteen_products WHERE is_active = 1 AND track_stock = 1 AND stock_qty <= low_stock_at ORDER BY stock_qty, name LIMIT 50');
            if ($low) {
                $names = implode(', ', array_map(fn($p) => $p['name'] . ' (' . (int) $p['stock_qty'] . ' left)', array_slice($low, 0, 8))) . (count($low) > 8 ? ' and ' . (count($low) - 8) . ' more' : '');
                $out['low'] = self::toAccounts(self::accountsWith('canteen.inventory.manage') ?: self::accountsWith('canteen.inventory.view'), 'CANTEEN_LOW_STOCK', count($low) . ' canteen item' . (count($low) === 1 ? '' : 's') . ' running low',
                    $names . '.', '../canteen/index.php?r=products&low=1', 'cpos-low:' . date('Y-m-d') . ':' . md5(implode(',', array_column($low, 'id'))));
            }
        }
        return $out;
    }
}
