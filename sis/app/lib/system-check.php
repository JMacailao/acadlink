<?php
declare(strict_types=1);
/**
 * v63.16 System Check (Settings → System Check, power.admin): is this server ready and set up safely?
 * Each check: [group, label, status ok|warn|fail|info, what was found, how to fix (when not ok)].
 */
final class SystemCheck
{
    public const GROUPS = ['server' => 'Server', 'files' => 'Files uploaded', 'backups' => 'Backups', 'accounts' => 'Accounts & security', 'school' => 'School'];
    /** Extensions AcadLink uses (gd: photos; curl/openssl: email and HTTPS calls; fileinfo: uploads; iconv/mbstring: text; simplexml: Excel). */
    public const EXTENSIONS = ['pdo_mysql', 'mbstring', 'gd', 'curl', 'openssl', 'fileinfo', 'iconv', 'simplexml', 'json'];
    /** Every .htaccess AcadLink needs; the ones that force HTTPS carry the v63.13 block. */
    public const HTACCESS = ['.htaccess' => true, 'sis/.htaccess' => true, 'portal/.htaccess' => true, 'lms/.htaccess' => true, 'canteen/.htaccess' => true,
        'library/.htaccess' => true, 'finance/.htaccess' => true, 'api/.htaccess' => false, 'shared/.htaccess' => false, 'sis/app/.htaccess' => false,
        'sis/bin/.htaccess' => false, 'backups/.htaccess' => false];
    public const VENDOR = ['vendor/bootstrap-5.3.8/css/bootstrap.min.css', 'vendor/bootstrap-5.3.8/js/bootstrap.bundle.min.js',
        'vendor/bootstrap-icons-1.13.1/font/bootstrap-icons.min.css', 'vendor/bootstrap-icons-1.13.1/font/fonts/bootstrap-icons.woff2'];
    /** Files earlier versions used; safe to delete from the server. */
    public const LEFTOVERS = ['js/backup.js', 'sis/assets/idle-signout.js', 'sis/assets/idle-signout.css', 'sis/app/pages/keepalive.php'];

    private static array $out = [];
    private static function add(string $g, string $label, string $status, string $found, string $fix = ''): void { self::$out[] = [$g, $label, $status, $found, $fix]; }

    /** Is this a local install (localhost, an IP address, .local/.lan/.test)? Then HTTPS isn't needed. */
    public static function local(): bool
    {
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
        return $host === 'localhost' || $host === '[::1]' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || (bool) preg_match('/\.(local|lan|test|localhost)$/', $host);
    }
    public static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
    private static function bytes(string $v): int
    {
        $v = trim($v); if ($v === '' || $v === '-1') return PHP_INT_MAX;
        $n = (int) $v; $u = strtolower(substr($v, -1));
        return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
    }
    private static function size(float $b): string { return $b >= 1 << 30 ? round($b / (1 << 30), 1) . ' GB' : ($b >= 1 << 20 ? round($b / (1 << 20)) . ' MB' : round($b / 1024) . ' KB'); }
    private static function ago(int $t): string { $d = time() - $t; return $d < 3600 ? 'less than an hour ago' : ($d < 86400 ? round($d / 3600) . ' hours ago' : round($d / 86400) . ' days ago'); }

    /** All checks, in page order. */
    public static function run(): array
    {
        self::$out = [];
        $root = dirname(SIS_ROOT);
        $local = self::local();

        // ---------------------------------------------------------------- server
        $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (PHP_VERSION_ID >= 80300) self::add('server', 'PHP version', 'ok', 'PHP ' . $v);
        elseif (PHP_VERSION_ID >= 80200) self::add('server', 'PHP version', 'warn', 'PHP ' . $v . ' (supported; 8.3 is recommended)', 'Hostinger: hPanel → Advanced → PHP Configuration → PHP version → 8.3.');
        else self::add('server', 'PHP version', 'fail', 'PHP ' . $v . ': no longer gets security fixes', 'Hostinger: hPanel → Advanced → PHP Configuration → PHP version → 8.3.');
        $missing = array_values(array_filter(self::EXTENSIONS, fn($e) => !extension_loaded($e)));
        self::add('server', 'PHP extensions', $missing ? 'fail' : 'ok', $missing ? 'Missing: ' . implode(', ', $missing) : 'All ' . count(self::EXTENSIONS) . ' needed extensions are on', $missing ? 'Turn them on in the host\'s PHP settings (Hostinger: hPanel → Advanced → PHP Configuration → PHP extensions).' : '');
        if ($local) self::add('server', 'HTTPS (secure address)', 'info', 'Local install (' . (string) ($_SERVER['HTTP_HOST'] ?? '') . '): HTTPS is not needed here');
        elseif (self::https()) self::add('server', 'HTTPS (secure address)', 'ok', 'This page was opened over https');
        else self::add('server', 'HTTPS (secure address)', 'fail', 'This page was opened over plain http', 'Turn SSL on for the domain (Hostinger: hPanel → Security → SSL), then open the site with https://.');
        if (!$local && !empty(sis_config()['debug'])) self::add('server', 'Debug mode', 'fail', 'sis_debug is on: error details are shown to visitors', 'Remove "sis_debug" (or set it to false) in api/config.php.');
        $mem = self::bytes((string) ini_get('memory_limit'));
        self::add('server', 'Memory for PHP', $mem >= 256 << 20 ? 'ok' : ($mem >= 128 << 20 ? 'warn' : 'fail'), 'memory_limit = ' . ini_get('memory_limit'), $mem >= 256 << 20 ? '' : 'Set memory_limit to 256M or more (hPanel → Advanced → PHP Configuration → PHP options). Backups and Excel imports need it.');
        $up = min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size')));
        self::add('server', 'Largest upload', $up >= 20 << 20 ? 'ok' : 'warn', 'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size'), $up >= 20 << 20 ? '' : 'Set both to 20M or more, for LMS files, photos and backup uploads (hPanel → PHP Configuration → PHP options).');
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $limit = (int) ini_get('max_execution_time');
        $canExtend = function_exists('set_time_limit') && !in_array('set_time_limit', $disabled, true);
        self::add('server', 'Time for long jobs', $canExtend || $limit === 0 || $limit >= 300 ? 'ok' : 'warn', $canExtend ? 'Backups can run as long as they need' : 'max_execution_time = ' . $limit . ' s and it cannot be raised', $canExtend || $limit === 0 || $limit >= 300 ? '' : 'Set max_execution_time to 300 or more, or a large backup may stop half-way (the scheduled job has no limit).');
        try {
            $ver = (string) Db::val('SELECT VERSION()');
            $maria = stripos($ver, 'mariadb') !== false; $num = preg_replace('/[^0-9.].*$/', '', $ver);
            $ok = version_compare($num, $maria ? '10.4' : '8.0', '>=');
            self::add('server', 'Database server', $ok ? 'ok' : 'warn', ($maria ? 'MariaDB ' : 'MySQL ') . preg_replace('/-.*/', '', $ver), $ok ? '' : 'Ask the host for MariaDB 10.6+ or MySQL 8.');
            $dbBytes = (float) Db::val('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');
            self::add('server', 'Database size', $dbBytes > 4 * (1 << 30) ? 'warn' : 'info', self::size($dbBytes), $dbBytes > 4 * (1 << 30) ? 'Check your hosting plan\'s database size limit (some plans allow about 6 GB).' : '');
        } catch (Throwable $e) { self::add('server', 'Database server', 'fail', 'Could not read it: ' . $e->getMessage()); }

        // ---------------------------------------------------------------- files
        $noHta = []; $oldHta = [];
        foreach (self::HTACCESS as $f => $httpsBlock) {
            if (!is_file($root . '/' . $f)) { $noHta[] = $f; continue; }
            if ($httpsBlock && !str_contains((string) file_get_contents($root . '/' . $f), 'v63.13')) $oldHta[] = $f;
        }
        if ($noHta) self::add('files', '.htaccess files (protection and HTTPS)', 'fail', 'Missing: ' . implode(', ', $noHta), 'Upload them again. Many FTP programs and file managers hide names that start with a dot: turn on "show hidden files" first.');
        elseif ($oldHta) self::add('files', '.htaccess files (protection and HTTPS)', 'warn', 'Older versions (no HTTPS redirect): ' . implode(', ', $oldHta), 'Upload the .htaccess files of this version (show hidden files first).');
        else self::add('files', '.htaccess files (protection and HTTPS)', 'ok', 'All ' . count(self::HTACCESS) . ' are in place');
        $noVendor = array_values(array_filter(self::VENDOR, fn($f) => !is_file($root . '/' . $f)));
        self::add('files', 'Bootstrap and icons (vendor folder)', $noVendor ? 'fail' : 'ok', $noVendor ? 'Missing: ' . implode(', ', $noVendor) : 'Bootstrap 5.3.8 and Bootstrap Icons 1.13.1 are on this server', $noVendor ? 'Upload the whole vendor folder: without it, pages lose their layout and icons.' : '');
        $left = array_values(array_filter(self::LEFTOVERS, fn($f) => is_file($root . '/' . $f)));
        if ($left) self::add('files', 'Files from older versions', 'info', 'Not used any more: ' . implode(', ', $left), 'You may delete them from the server.');

        // ---------------------------------------------------------------- backups
        try {
            $dir = Backup::dir();
            $real = realpath($dir) ?: $dir; $site = realpath($root) ?: $root;
            $inSite = str_starts_with($real . '/', $site . '/');
            self::add('backups', 'Where backups are kept', $inSite ? 'warn' : 'ok', $inSite ? 'Inside the website folder (' . $real . '); the web is blocked from it by .htaccess' : 'Outside the website folder (' . $real . ')',
                $inSite ? 'Safer outside the website: create a folder such as /home/<account>/acadlink-backups and set "backup_dir" => that path in api/config.php.' : '');
            $free = @disk_free_space($dir);
            if ($free === false || $free === null) self::add('backups', 'Free disk space', 'info', 'This server does not say');
            else self::add('backups', 'Free disk space', $free < 200 << 20 ? 'fail' : ($free < 1 << 30 ? 'warn' : 'ok'), self::size((float) $free) . ' free', $free < 1 << 30 ? 'Delete old backups (download them first) or move to a plan with more storage.' : '');
        } catch (Throwable $e) { self::add('backups', 'Where backups are kept', 'fail', $e->getMessage(), 'Make the backups folder writable, or set "backup_dir" in api/config.php to a writable folder.'); }
        $a = Backup::auto(); $hours = Backup::autoHours();
        $last = $a['autobackup_last_ok'] !== '' ? (int) strtotime($a['autobackup_last_ok']) : 0;
        if (!$hours) self::add('backups', 'Automatic backups', 'warn', 'Off', 'Turn them on in Settings → Backup & Restore.');
        elseif ($a['autobackup_last_error'] !== '') self::add('backups', 'Automatic backups', 'fail', 'The last one failed: ' . $a['autobackup_last_error'], 'Open Settings → Backup & Restore and press "Make the automatic backup now" to see the problem.');
        elseif (!$last) self::add('backups', 'Automatic backups', 'warn', Backup::AUTO_EVERY[$a['autobackup_every']] . '; none made yet', 'Press "Make the automatic backup now" in Settings → Backup & Restore.');
        elseif (time() - $last > 2 * $hours * 3600 + 3600) self::add('backups', 'Automatic backups', 'warn', 'Last one ' . self::ago($last) . ' (late)', 'Set up the scheduled job shown in Settings → Backup & Restore.');
        else self::add('backups', 'Automatic backups', 'ok', Backup::AUTO_EVERY[$a['autobackup_every']] . '; last one ' . self::ago($last));
        if ($hours) self::add('backups', 'Scheduled job (cron)', Backup::cronActive() ? 'ok' : 'warn', Backup::cronActive() ? 'Runs the backups on time' : 'Not set up: backups wait until someone opens a page', Backup::cronActive() ? '' : 'Add the hourly job shown in Settings → Backup & Restore (Hostinger: hPanel → Advanced → Cron Jobs).');
        $dl = $a['autobackup_last_download'] !== '' ? (int) strtotime($a['autobackup_last_download']) : 0;
        self::add('backups', 'A copy kept off this server', $dl && time() - $dl <= 14 * 86400 ? 'ok' : 'warn', $dl ? 'Last downloaded ' . self::ago($dl) : 'No backup downloaded yet', $dl && time() - $dl <= 14 * 86400 ? '' : 'Download the latest backup (Settings → Backup & Restore) to a USB drive or Google Drive, at least every two weeks. Keep it as private as the records.');

        // ---------------------------------------------------------------- accounts & security
        self::add('accounts', 'power.admin password', FirstSetup::needed() ? 'fail' : 'ok', FirstSetup::needed() ? 'Not set yet: nobody can manage AcadLink' : 'Set', FirstSetup::needed() ? 'Open First-time setup and choose the password.' : '');
        foreach (self::$extra as $fn) $fn(); // later checks (e.g. two-step sign-in) register here
        $single = Auth::central()->singleDevice();
        self::add('accounts', 'One device at a time', 'info', $single ? 'On: a sign-in elsewhere signs the other device out (after asking)' : 'Off: an account can be open on several devices', 'Settings → System Settings → Sign-in security.');
        $mail = Mailer::missing();
        self::add('accounts', 'Email for "Forgot password"', $mail ? 'warn' : 'ok', $mail ? 'Not ready: ' . implode('; ', $mail) : 'Ready', $mail ? 'Settings → Email Sending. Until then only power.admin can reset passwords.' : '');

        // ---------------------------------------------------------------- school
        $name = School::brand();
        self::add('school', 'School name', in_array($name, ['AcadLink', 'School Information System'], true) ? 'warn' : 'ok', $name, in_array($name, ['AcadLink', 'School Information System'], true) ? 'Settings → School Profile.' : '');
        self::add('school', 'School logo', School::logo() !== '' ? 'ok' : 'warn', School::logo() !== '' ? 'Uploaded' : 'Not uploaded: the AcadLink logo is shown instead', School::logo() !== '' ? '' : 'Settings → School Profile → School logo.');
        return self::$out;
    }

    /** Extra checks added by other features: fn() that calls SystemCheck::addCheck(). */
    private static array $extra = [];
    public static function register(callable $fn): void { self::$extra[] = $fn; }
    public static function addCheck(string $g, string $label, string $status, string $found, string $fix = ''): void { self::add($g, $label, $status, $found, $fix); }

    /** Number of problems, for power.admin's dashboard (kept for 15 minutes). */
    public static function problems(): int
    {
        $c = $_SESSION['syscheck'] ?? null;
        if (is_array($c) && ($c['at'] ?? 0) > time() - 900) return (int) $c['n'];
        $n = count(array_filter(self::run(), fn($r) => $r[2] === 'fail'));
        $_SESSION['syscheck'] = ['at' => time(), 'n' => $n];
        return $n;
    }
}
