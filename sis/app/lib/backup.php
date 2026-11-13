<?php
declare(strict_types=1);

/**
 * v63 Whole-system backup and restore (every workspace), for the built-in power.admin account only.
 *
 * A backup is one compressed file (.acadlink.gz): a header line, then for each table a line with its columns and one
 * line per row (JSON; binary columns such as photos and LMS files in base64). Tables are grouped into parts
 * (accounts, grading, SIS, finance, canteen, library, LMS, photos, history) so a backup or a restore can include only
 * some of them. Short-lived data (sign-in sessions, password-reset codes, sign-in attempts, restore staging) is never
 * backed up.
 *
 * Restoring replaces the chosen parts in one database transaction: if anything fails, nothing changes. A safety copy
 * of the chosen parts is saved on the server first. Restoring "Accounts" never changes power.admin's sign-in.
 */
final class Backup
{
    public const FORMAT = 1;
    public const OWNER = 'power.admin';
    public const PARTS = [
        'accounts' => ['Accounts, groups and access', 'Sign-in accounts, passwords, groups, permissions, parent links. power.admin\'s own sign-in is never changed by a restore.'],
        'grading'  => ['Grading System', 'Students, teachers, subjects, sections, class assignments, grades and their history.'],
        'sis'      => ['SIS records', 'Admissions, registrations and assessments, fees setup, documents, clearance, schedules, privacy records and settings.'],
        'finance'  => ['Cashier and accounting', 'Payments and receipts, O.R. series, payment plans, balances, chart of accounts, journals and reconciliations.'],
        'canteen'  => ['Canteen POS', 'Products and photos, stock, sales, shifts and requests.'],
        'library'  => ['Library', 'Catalog, copies and covers, loans, holds, fines, acquisitions and stocktakes.'],
        'lms'      => ['LMS', 'Classwork, quizzes, hand-ins and files, attendance, messages and class settings.'],
        'photos'   => ['Student photos', 'Student photos (approved, waiting and declined).'],
        'history'  => ['Activity history and notifications', 'The Activity Log, account change history and notifications.'],
    ];
    /** Never backed up or restored. */
    private const SKIP = ['central_sessions', 'central_password_resets', 'login_attempts', 'grading_restore_jobs', 'grading_restore_records', 'central_schema_migrations'];
    private const FINANCE = ['sis_payments', 'sis_payment_allocations', 'sis_refund_allocations', 'sis_or_series', 'sis_payment_plans', 'sis_payment_plan_events', 'sis_registration_plans',
        'sis_accounts', 'sis_account_map', 'sis_journal', 'sis_journal_lines', 'sis_reconciliations', 'sis_balance_cases', 'sis_balance_case_events', 'sis_document_payments', 'sis_migration_payment_items'];

    // ------------------------------------------------------------------ who may use it

    /** The account allowed to back up and restore: power.admin (or, if it no longer exists, the oldest active Power User). */
    public static function ownerId(): ?int
    {
        $id = Db::val("SELECT i.account_id FROM central_login_identifiers i JOIN central_accounts a ON a.id = i.account_id AND a.status = 'ACTIVE' WHERE i.identifier_key = ? AND i.is_primary = 1", [self::OWNER]);
        if ($id) return (int) $id;
        $id = Db::val("SELECT MIN(a.id) FROM central_accounts a JOIN central_user_groups ug ON ug.account_id = a.id AND ug.revoked_at IS NULL JOIN central_groups g ON g.id = ug.group_id AND g.code = 'POWER_USER' WHERE a.status = 'ACTIVE'");
        return $id ? (int) $id : null;
    }

    public static function allowed(): bool
    {
        $me = Auth::centralId();
        $a = $me ? Auth::central()->account($me) : null;
        return $me && $a && !$a['must_change_password'] && $me === self::ownerId() && Auth::central()->group($me) === 'POWER_USER';
    }

    private static function require(): void
    {
        if (!self::allowed()) throw new Forbidden('Only ' . self::OWNER . ' can back up and restore AcadLink.');
    }

    // ------------------------------------------------------------------ tables

    /** [table => part] for every table of this database that is backed up. */
    public static function tables(): array
    {
        $out = [];
        foreach (Db::col('SHOW TABLES') as $t) {
            $t = (string) $t;
            if (in_array($t, self::SKIP, true)) continue;
            $part = match (true) {
                $t === 'sis_student_photos' => 'photos',
                in_array($t, ['sis_audit_logs', 'central_account_audit', 'sis_notifications'], true) => 'history',
                in_array($t, self::FINANCE, true) || str_starts_with($t, 'sis_accounting_') => 'finance',
                str_starts_with($t, 'central_') || in_array($t, ['sis_users', 'accounts'], true) => 'accounts',
                $t === 'docs' => 'grading',
                str_starts_with($t, 'canteen_') => 'canteen',
                str_starts_with($t, 'library_') => 'library',
                str_starts_with($t, 'lms_') => 'lms',
                str_starts_with($t, 'sis_') => 'sis',
                default => null,
            };
            if ($part) $out[$t] = $part;
        }
        ksort($out);
        return $out;
    }

    /** Columns that can be written (not generated), with whether they hold binary data. */
    private static function columns(string $table): array
    {
        $cols = [];
        foreach (Db::all('SELECT column_name AS n, data_type AS t, extra AS e FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$table]) as $c) {
            if (stripos((string) $c['e'], 'GENERATED') !== false) continue;
            $cols[(string) $c['n']] = in_array(strtolower((string) $c['t']), ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'], true);
        }
        return $cols;
    }

    /** Rows and size per part (for the page). */
    public static function summary(): array
    {
        $sizes = array_column(Db::all('SELECT table_name AS t, table_rows AS r, data_length + index_length AS b FROM information_schema.tables WHERE table_schema = DATABASE()'), null, 't');
        $out = array_fill_keys(array_keys(self::PARTS), ['tables' => 0, 'rows' => 0, 'bytes' => 0]);
        foreach (self::tables() as $t => $p) { $out[$p]['tables']++; $out[$p]['rows'] += (int) ($sizes[$t]['r'] ?? 0); $out[$p]['bytes'] += (int) ($sizes[$t]['b'] ?? 0); }
        return $out;
    }

    // ------------------------------------------------------------------ server folder

    /**
     * Where backups are kept: "backup_dir" in api/config.php (best: a folder outside the website), otherwise backups/
     * inside AcadLink, where web access is refused (.htaccess, for Apache) and the files have random names.
     */
    public static function dir(): string
    {
        $cfg = (string) (sis_config()['backup_dir'] ?? '');
        $d = $cfg !== '' ? rtrim($cfg, '/\\') : dirname(SIS_ROOT) . '/backups';
        if (!is_dir($d)) @mkdir($d, 0770, true);
        if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\nOptions -Indexes\n");
        if (!is_file($d . '/index.html')) @file_put_contents($d . '/index.html', '');
        if (!is_dir($d) || !is_writable($d)) throw new AppError('The server folder for backups (' . $d . ') cannot be written. Ask the hosting to make it writable.');
        return $d;
    }

    /** Backups kept on the server, newest first: [file, created, size, parts, kind]. */
    public static function stored(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.acadlink.gz') ?: [] as $f) {
            $h = self::header($f);
            $out[] = ['file' => basename($f), 'size' => filesize($f), 'created' => $h['created_at'] ?? date('c', filemtime($f)), 'parts' => array_keys($h['parts'] ?? []), 'kind' => $h['kind'] ?? 'manual', 'note' => $h['note'] ?? ''];
        }
        usort($out, fn($a, $b) => strcmp($b['created'], $a['created']));
        return $out;
    }

    public static function path(string $file): string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+\.acadlink\.gz$/', $file)) throw new AppError('Backup not found.');
        $p = self::dir() . '/' . $file;
        if (!is_file($p)) throw new AppError('Backup not found.');
        return $p;
    }

    public static function delete(string $file): void
    {
        self::require();
        @unlink(self::path($file));
        Audit::log('backup_deleted', 'backup', 0, ['file' => $file]);
    }

    // ------------------------------------------------------------------ backing up

    /**
     * Write a backup of the chosen parts to the server folder; returns the file name.
     * $kind: 'manual' or 'safety' (made automatically before a restore).
     */
    public static function create(array $parts, string $kind = 'manual', string $note = ''): string
    {
        self::require();
        $parts = array_values(array_intersect(array_keys(self::PARTS), $parts));
        if (!$parts) throw new AppError('Tick at least one part to back up.');
        @set_time_limit(0);
        $tables = array_filter(self::tables(), fn($p) => in_array($p, $parts, true));
        $name = date('Ymd-His') . '-' . $kind . '-' . bin2hex(random_bytes(6)) . '.acadlink.gz';
        $path = self::dir() . '/' . $name;
        $gz = gzopen($path . '.part', 'wb6') ?: throw new AppError('Could not create the backup file on the server.');
        $counts = [];
        try {
            $head = ['acadlink_backup' => self::FORMAT, 'kind' => $kind, 'note' => mb_substr($note, 0, 200), 'created_at' => date('c'), 'school' => School::brand(),
                'database' => (string) Db::val('SELECT DATABASE()'), 'made_by' => (string) (Auth::user()['username'] ?? ''), 'parts' => array_fill_keys($parts, true), 'tables' => $tables];
            gzwrite($gz, json_encode($head, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $pdo = Db::pdo();
            foreach ($tables as $t => $part) {
                $cols = self::columns($t);
                gzwrite($gz, json_encode(['table' => $t, 'part' => $part, 'cols' => array_keys($cols), 'bin' => array_keys(array_filter($cols))], JSON_UNESCAPED_SLASHES) . "\n");
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $st = $pdo->query('SELECT ' . implode(',', array_map(fn($c) => "`$c`", array_keys($cols))) . " FROM `$t`");
                $n = 0;
                while ($r = $st->fetch(PDO::FETCH_NUM)) {
                    $i = 0;
                    foreach ($cols as $isBin) { if ($isBin && $r[$i] !== null) $r[$i] = base64_encode(is_resource($r[$i]) ? (string) stream_get_contents($r[$i]) : (string) $r[$i]); $i++; }
                    gzwrite($gz, json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                    $n++;
                }
                $st->closeCursor();
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                $counts[$t] = $n;
            }
            gzwrite($gz, json_encode(['end' => true, 'rows' => $counts]) . "\n");
        } catch (Throwable $e) {
            gzclose($gz); @unlink($path . '.part');
            Db::pdo()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            throw $e;
        }
        gzclose($gz);
        rename($path . '.part', $path);
        Audit::log('backup_created', 'backup', 0, ['file' => $name, 'kind' => $kind, 'parts' => $parts, 'rows' => array_sum($counts)]);
        return $name;
    }

    /** The first line of a backup file, or [] when it is not an AcadLink backup. */
    public static function header(string $path): array
    {
        $gz = @gzopen($path, 'rb');
        if (!$gz) return [];
        $line = gzgets($gz, 10 * 1048576);
        gzclose($gz);
        $h = $line ? json_decode($line, true) : null;
        return is_array($h) && ($h['acadlink_backup'] ?? null) === self::FORMAT ? $h : [];
    }

    /** Keep an uploaded backup file in the server folder (so it can be restored); returns its name. */
    public static function receive(array $upload): string
    {
        self::require();
        $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new AppError('The file is larger than the server allows (' . ini_get('upload_max_filesize') . '). Ask the hosting to raise upload_max_filesize and post_max_size, or copy the file into the backups folder on the server.');
        if ($err !== UPLOAD_ERR_OK || (PHP_SAPI !== 'cli' && !is_uploaded_file((string) $upload['tmp_name']))) throw new AppError('Choose an AcadLink backup file (.acadlink.gz).');
        $h = self::header((string) $upload['tmp_name']);
        if (!$h) throw new AppError('This is not an AcadLink backup file.');
        $name = date('Ymd-His') . '-uploaded-' . bin2hex(random_bytes(6)) . '.acadlink.gz';
        $dest = self::dir() . '/' . $name;
        if (!(PHP_SAPI === 'cli' ? copy((string) $upload['tmp_name'], $dest) : move_uploaded_file((string) $upload['tmp_name'], $dest))) throw new AppError('Could not save the uploaded file on the server.');
        Audit::log('backup_uploaded', 'backup', 0, ['file' => $name, 'from' => $h['created_at'] ?? '']);
        return $name;
    }

    // ------------------------------------------------------------------ restoring

    /**
     * Replace the chosen parts with the backup's. Everything runs in one transaction; a safety copy of the chosen parts
     * is saved first. Tables of a chosen part that are not in the backup are left as they are. Returns a report.
     */
    public static function restore(string $file, array $parts, string $confirm): array
    {
        self::require();
        if (trim($confirm) !== 'RESTORE') throw new AppError('Type RESTORE to confirm.');
        $path = self::path($file);
        $h = self::header($path) ?: throw new AppError('This is not an AcadLink backup file.');
        $parts = array_values(array_intersect(array_keys(self::PARTS), $parts, array_keys($h['parts'] ?? [])));
        if (!$parts) throw new AppError('Tick at least one part that is in this backup.');
        @set_time_limit(0); @ignore_user_abort(true);
        $here = self::tables();
        // power.admin's sign-in is kept as it is now
        $ownerId = self::ownerId();
        $keep = in_array('accounts', $parts, true) && $ownerId ? Db::one('SELECT a.id, c.password_hash, i.identifier FROM central_accounts a JOIN central_credentials c ON c.account_id = a.id JOIN central_login_identifiers i ON i.account_id = a.id AND i.is_primary = 1 WHERE a.id = ?', [$ownerId]) : null;
        $safety = self::create($parts, 'safety', 'Before restoring ' . $file);
        $pdo = Db::pdo(); $report = ['tables' => 0, 'rows' => 0, 'skipped' => [], 'safety' => $safety];
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->beginTransaction();
        try {
            $gz = gzopen($path, 'rb'); gzgets($gz, 10 * 1048576); // header
            $t = null; $use = false; $cols = []; $bin = []; $batch = []; $batchBytes = 0; $sql = '';
            $flush = function () use (&$batch, &$batchBytes, &$sql, $pdo, &$report) {
                if (!$batch) return;
                $st = $pdo->prepare($sql . implode(',', array_fill(0, count($batch), '(' . implode(',', array_fill(0, count($batch[0]), '?')) . ')')));
                $k = 1; foreach ($batch as $r) foreach ($r as $v) $st->bindValue($k++, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $st->execute(); $report['rows'] += count($batch); $batch = []; $batchBytes = 0;
            };
            $done = false;
            while (($line = gzgets($gz, 64 * 1048576)) !== false) {
                $line = rtrim($line, "\n");
                if ($line === '') continue;
                if ($line[0] === '{') {
                    $flush();
                    $m = json_decode($line, true) ?: [];
                    if (!empty($m['end'])) { $done = true; break; }
                    $t = (string) ($m['table'] ?? ''); $use = in_array($m['part'] ?? '', $parts, true);
                    if ($use && !isset($here[$t])) { $report['skipped'][] = $t . ' (not in this version)'; $use = false; }
                    if (!$use) continue;
                    $now = self::columns($t);
                    $cols = array_values(array_intersect((array) $m['cols'], array_keys($now)));
                    $idx = array_flip((array) $m['cols']); $bin = array_flip((array) ($m['bin'] ?? []));
                    $pdo->exec("DELETE FROM `$t`");
                    $sql = "INSERT INTO `$t` (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES ';
                    $report['tables']++;
                    $map = array_map(fn($c) => [$idx[$c], isset($bin[$c])], $cols);
                    continue;
                }
                if (!$use) continue;
                $r = json_decode($line, true);
                if (!is_array($r)) throw new AppError('The backup file is damaged (table ' . $t . ').');
                $row = [];
                foreach ($map as [$i, $isBin]) { $v = $r[$i] ?? null; $row[] = $isBin && $v !== null ? base64_decode((string) $v) : ($v === null ? null : (is_bool($v) ? (int) $v : (string) $v)); }
                $batch[] = $row; $batchBytes += strlen($line);
                if (count($batch) >= 200 || $batchBytes > 4 * 1048576) $flush();
            }
            $flush();
            gzclose($gz);
            if (!$done) throw new AppError('The backup file is incomplete (it ends before the last table). Nothing was changed.');
            // power.admin keeps its username, password and Power User access
            if ($keep) {
                $id = Db::val('SELECT account_id FROM central_login_identifiers WHERE identifier_key = ? AND is_primary = 1', [self::OWNER]);
                if (!$id) throw new AppError('The backup has no ' . self::OWNER . ' account. Untick "Accounts" to restore the rest.');
                Db::q('UPDATE central_credentials SET password_hash = ?, password_changed_at = NOW() WHERE account_id = ?', [$keep['password_hash'], $id]);
                Db::q("UPDATE central_accounts SET status = 'ACTIVE', must_change_password = 0, locked_until = NULL, failed_logins = 0 WHERE id = ?", [$id]);
                if ((int) $id !== (int) $keep['id']) $report['signin'] = 'power.admin has a different account number in the backup: sign in again.';
            }
            Audit::log('backup_restored', 'backup', 0, ['file' => $file, 'from' => $h['created_at'] ?? '', 'parts' => $parts, 'rows' => $report['rows'], 'safety' => $safety]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            throw $e instanceof AppError ? $e : new AppError('The restore stopped and nothing was changed: ' . $e->getMessage());
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        Auth::central()->clearCache();
        unset($_SESSION['sis_schema']); // re-check the tables of this version on the next page
        return $report;
    }
}
