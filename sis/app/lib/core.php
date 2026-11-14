<?php
/**
 * Core helpers: database access, money arithmetic, settings, numbering, audit log,
 * input validation and HTML escaping. Loaded by app/bootstrap.php.
 */
declare(strict_types=1);

/** An error whose message is safe to show to the user (validation, business rule). */
class AppError extends RuntimeException {}

// ------------------------------------------------------------------ database
final class Db
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function connect(array $cfg): void
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['db_host'], (int)($cfg['db_port'] ?? 3306), $cfg['db_name']);
        if (!empty($cfg['db_socket'])) $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $cfg['db_socket'], $cfg['db_name']);
        self::$pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // v63.19: one text-comparison rule (collation) for the whole connection. Some hosts (e.g. Hostinger) set the server's
        // collation_connection to utf8mb4_unicode_ci while sent values get utf8mb4_general_ci: "Illegal mix of collations".
        self::$pdo->exec('SET NAMES utf8mb4 COLLATE ' . self::COLLATION);
        self::$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$pdo->exec("SET time_zone = '" . date('P') . "'");
    }

    /** v63.19: the text-comparison rule (collation) of every connection and of AcadLink's tables. */
    public const COLLATION = 'utf8mb4_unicode_ci';

    /**
     * v63.20: makes the database and its tables use one text-comparison rule, so a comparison between two tables can
     * never fail with "Illegal mix of collations" (e.g. tables a host created with utf8mb4_general_ci or
     * utf8mb4_uca1400_ai_ci). Converts only tables whose text columns are all utf8mb4 and none is utf8mb4_bin
     * (binary tables, e.g. the Grading System's docs, and ascii token columns are left as they are on purpose).
     * Returns ['converted' => [...], 'skipped' => [table => reason], 'database' => bool].
     */
    public static function alignCollation(): array
    {
        $want = self::COLLATION; $out = ['converted' => [], 'skipped' => [], 'database' => false];
        $pdo = self::pdo();
        $dbColl = (string) $pdo->query('SELECT default_collation_name FROM information_schema.schemata WHERE schema_name = DATABASE()')->fetchColumn();
        if ($dbColl !== $want) {
            try { $pdo->exec('ALTER DATABASE `' . str_replace('`', '``', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()) . "` CHARACTER SET utf8mb4 COLLATE $want"); $out['database'] = true; }
            catch (PDOException $e) { error_log('AcadLink collation (database): ' . $e->getMessage()); }
        }
        $rows = $pdo->query("SELECT t.table_name AS t, t.table_collation AS tc,
                SUM(c.collation_name IS NOT NULL AND c.collation_name NOT LIKE 'utf8mb4\\_%') AS other_charset,
                SUM(c.collation_name = 'utf8mb4_bin') AS bin_cols,
                SUM(c.collation_name LIKE 'utf8mb4\\_%' AND c.collation_name NOT IN ('$want', 'utf8mb4_bin')) AS wrong_cols
            FROM information_schema.tables t LEFT JOIN information_schema.columns c ON c.table_schema = t.table_schema AND c.table_name = t.table_name
            WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
            GROUP BY t.table_name, t.table_collation")->fetchAll(PDO::FETCH_ASSOC);
        $todo = [];
        foreach ($rows as $r) {
            if ($r['tc'] === $want && (int) $r['wrong_cols'] === 0) continue;   // already right
            if ($r['tc'] === 'utf8mb4_bin' && (int) $r['wrong_cols'] === 0) continue; // binary on purpose
            if ((int) $r['other_charset'] > 0 || (int) $r['bin_cols'] > 0) { $out['skipped'][$r['t']] = 'has ascii or binary columns'; continue; }
            $todo[] = $r['t'];
        }
        if (!$todo) return $out;
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($todo as $t) {
                try { $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $t) . "` CONVERT TO CHARACTER SET utf8mb4 COLLATE $want"); $out['converted'][] = $t; }
                catch (PDOException $e) { $out['skipped'][$t] = $e->getMessage(); error_log("AcadLink collation ($t): " . $e->getMessage()); }
            }
        } finally { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); }
        return $out;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) throw new RuntimeException('Database not connected');
        return self::$pdo;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function col(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        foreach ($cols as $c) self::ident($c);
        $sql = 'INSERT INTO ' . self::ident($table) . ' (' . implode(',', array_map(fn($c) => "`$c`", $cols))
             . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** UPDATE table SET data WHERE id = ? — returns affected rows. */
    public static function update(string $table, array $data, int $id): int
    {
        $sets = [];
        foreach (array_keys($data) as $c) $sets[] = '`' . self::ident($c) . '` = ?';
        $params = array_values($data);
        $params[] = $id;
        return self::q('UPDATE ' . self::ident($table) . ' SET ' . implode(',', $sets) . ' WHERE id = ?', $params)->rowCount();
    }

    /** Run $fn inside one transaction (nested calls join the outer transaction). */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$depth > 0) {
            self::$depth++;
            try { return $fn(); } finally { self::$depth--; }
        }
        $pdo->beginTransaction();
        self::$depth = 1;
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            self::$depth = 0;
        }
    }

    public static function inTx(): bool { return self::$depth > 0; }

    private static function ident(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) throw new RuntimeException("Bad identifier $name");
        return $name;
    }

    /** "IN (?,?,?)" placeholder list for an array. */
    public static function in(array $values): string
    {
        if (!$values) return '(NULL)';
        return '(' . implode(',', array_fill(0, count($values), '?')) . ')';
    }
}

// ------------------------------------------------------------------ money (integer cents)
final class Money
{
    /** "12,000.50" / "12000.5" / 12000 → 1200050. Rejects anything that is not a plain amount. */
    public static function cents(string|int|float|null $v): int
    {
        if ($v === null || $v === '') return 0;
        if (is_int($v)) return $v * 100;
        // Spaces and commas count only as real thousands separators: 1,500.50 and 1 500 are fine; 1,0,0 is refused.
        $s = trim((string) $v);
        if (str_contains($s, ' ')) $s = str_replace(' ', ',', preg_replace('/\s+/', ' ', $s));
        if (!preg_match('/^(-)?(\d{1,3}(?:,\d{3})+|\d*)(?:\.(\d{1,2}))?$/', $s, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new AppError('"' . $v . '" is not an amount. Type pesos like 1500, 1,500 or 1500.50 (at most 2 decimals).');
        }
        $whole = str_replace(',', '', $m[2]);
        if (strlen(ltrim($whole, '0')) > 10) throw new AppError('The amount ' . $v . ' is too large.');
        $c = (int) $whole * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
        return ($m[1] ?? '') === '-' ? -$c : $c;
    }

    /** Cents → "12000.50" for DECIMAL columns. */
    public static function dec(int $cents): string
    {
        $neg = $cents < 0;
        $a = abs($cents);
        return ($neg ? '-' : '') . intdiv($a, 100) . '.' . str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Cents → "12,000.50" for display. */
    public static function fmt(int $cents): string
    {
        $neg = $cents < 0;
        $a = abs($cents);
        return ($neg ? '-' : '') . number_format(intdiv($a, 100)) . '.' . str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT);
    }

    /** round(cents * percent / 100), half up, without floating point. $percentCents is percent × 100 (50% = 5000). */
    public static function percentOf(int $cents, int $percentCents): int
    {
        return intdiv($cents * $percentCents + 5000, 10000);
    }

    /** cents × quantity where quantity may have one decimal (units 1.5) — half up. */
    public static function times(int $cents, string|int|float $qty): int
    {
        $q10 = (int) round(((float) $qty) * 10);
        return intdiv($cents * $q10 + 5, 10);
    }
}

function peso(string|int|null $decimalOrCents, bool $isCents = false): string
{
    $c = $isCents ? (int) $decimalOrCents : Money::cents($decimalOrCents ?? '0');
    return '₱' . Money::fmt($c);
}

// ------------------------------------------------------------------ school name (grading system settings)
final class School
{
    private static ?array $d = null;

    /** The grading system's School settings (name, logo…). */
    private static function settings(): array
    {
        if (self::$d === null) {
            $raw = Db::val("SELECT data FROM docs WHERE col = 'settings' AND id = 'school'");
            self::$d = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        }
        return self::$d;
    }

    public static function name(): string
    {
        $d = self::settings();
        return trim((string) ($d['schoolName'] ?? $d['shortName'] ?? '')) ?: 'School Information System';
    }

    /** The name for the top bar, sign-in page and system chooser: the school's full name (its short name only when no full name is set). */
    public static function brand(): string
    {
        $d = self::settings();
        return trim((string) ($d['schoolName'] ?? '')) ?: (trim((string) ($d['shortName'] ?? '')) ?: 'AcadLink');
    }

    /** The school's logo from the grading system's School settings (an uploaded image), or ''. */
    public static function logo(): string
    {
        $logo = (string) (self::settings()['logo'] ?? '');
        return preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $logo) ? $logo : '';
    }

    /**
     * The sign-in page picture from the grading system's School settings (settings/signin, stored in
     * pieces image0…imageN so each fits one Excel cell in backups): [mime type, bytes], or null.
     */
    public static function signinPicture(): ?array
    {
        $raw = Db::val("SELECT data FROM docs WHERE col = 'settings' AND id = 'signin'");
        $d = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $parts = min(20, max(0, (int) ($d['parts'] ?? 0)));
        $data = '';
        for ($i = 0; $i < $parts; $i++) $data .= is_string($d['image' . $i] ?? null) ? $d['image' . $i] : '';
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $data, $m)) return null;
        $bytes = base64_decode($m[2], true);
        return $bytes === false || $bytes === '' ? null : ['image/' . $m[1], $bytes];
    }
}

// ------------------------------------------------------------------ settings
/** SIS settings (Administration → Settings), with defaults for anything not saved yet. */
/** v56: the name printed above "Prepared by" on a Statement of Account: the staff member signed in now. */
final class SoaPreparer
{
    public static function name(): string
    {
        $u = Auth::user();
        if (!$u || ($u['role_code'] ?? '') === 'STUDENT') return '';
        $name = trim((string) ($u['full_name'] ?? ''));
        if ($name === '' && Auth::id()) $name = trim((string) Db::val('SELECT full_name FROM sis_users WHERE id = ?', [Auth::id()]));
        return $name;
    }
}

final class Settings
{
    public const DEFAULTS = [
        // Header of both printouts
        'print_school_name' => '',            // empty = the name from the grading system's School settings
        'print_address'     => '',
        'print_contact'     => '',
        'print_show_logo'   => '1',
        // Assessment form
        'asm_template'       => '1',
        'asm_title'          => 'Statement of Account',
        'asm_paper'          => 'A4',
        'asm_show_subjects'  => '1',
        'asm_show_breakdown' => '1',
        'asm_show_payments'  => '1',
        'asm_split_lec_lab'  => '0',   // v56.2: subjects have lecture and laboratory units, shown on the assessment
        'asm_note'           => '',
        'asm_sign_left'      => 'Prepared by (Registrar)',
        'asm_sign_right'     => 'Conforme (Student / Parent)',
        // Official receipt
        'or_title'        => 'Official Receipt',
        'or_paper'        => 'HALF',
        'or_copies'       => '1',
        'or_show_words'   => '1',
        'or_show_balance' => '1',
        // Student Portal
        'portal_hide_grades_unpaid' => '0',
        'eligibility_block_incomplete' => '0',
        // Existing student balance enrollment workflow
        'balance_enrollment_enabled' => '0',
        'balance_allow_pay' => '1',
        'balance_allow_arrangement' => '1',
        'balance_allow_special' => '1',
        'balance_show_on_latest_assessment' => '0',
        'balance_min_payment' => '0.00',
        'balance_installment_rules' => 'Minimum payment and installment terms are reviewed by Accounting.',
        'balance_arrangement_approver_roles' => 'ACCOUNTING,REGISTRAR',
        'balance_special_approver_roles' => 'ACCOUNTING,REGISTRAR',
        'balance_require_approval' => '1',
        'acct_separate_duties' => '1',
        // v56: Cashier needs the student's payment plan before the first payment of an enrollment
        'cashier_require_plan' => '1',
        'or_note'         => 'Thank you for your payment. Please keep this receipt.',
        // TOR and certificates (Documents → Settings; saved by Documents::saveSettings, not the main form)
        'doc_prepared_by' => '', 'doc_checked_by' => '',
        'doc_template_tor' => 'standard', 'doc_template_enrollment' => 'standard', 'doc_template_grades' => 'standard', 'doc_template_good-moral' => 'standard', 'doc_template_honorable-dismissal' => 'standard',
        'doc_signer_tor' => '', 'doc_signer_enrollment' => '', 'doc_signer_grades' => '', 'doc_signer_good-moral' => '', 'doc_signer_honorable-dismissal' => '',
        'doc_signer_position_tor' => 'Registrar', 'doc_signer_position_enrollment' => 'Registrar', 'doc_signer_position_grades' => 'Registrar',
        'doc_signer_position_good-moral' => 'Guidance Counselor', 'doc_signer_position_honorable-dismissal' => 'Registrar',
        // v52: requests from the Student Portal: fee per copy, processing working days, requestable online
        'doc_fee_tor' => '150.00', 'doc_fee_enrollment' => '50.00', 'doc_fee_grades' => '50.00', 'doc_fee_good-moral' => '50.00', 'doc_fee_honorable-dismissal' => '50.00',
        'doc_days_tor' => '5', 'doc_days_enrollment' => '2', 'doc_days_grades' => '2', 'doc_days_good-moral' => '2', 'doc_days_honorable-dismissal' => '3',
        'doc_online_tor' => '1', 'doc_online_enrollment' => '1', 'doc_online_grades' => '1', 'doc_online_good-moral' => '1', 'doc_online_honorable-dismissal' => '1',
        'or_sign'         => 'Cashier',
    ];
    /** The on/off settings (checkboxes: not sent = off) */
    public const SWITCHES = ['eligibility_block_incomplete', 'print_show_logo', 'asm_show_subjects', 'asm_show_breakdown', 'asm_show_payments', 'or_show_words', 'or_show_balance', 'portal_hide_grades_unpaid', 'balance_enrollment_enabled', 'balance_allow_pay', 'balance_allow_arrangement', 'balance_allow_special', 'balance_show_on_latest_assessment', 'balance_require_approval', 'acct_separate_duties', 'cashier_require_plan', 'asm_split_lec_lab'];
    public const PAPERS = ['A4' => 'A4 (210 × 297 mm)', 'LETTER' => 'Letter (8.5 × 11 in)', 'LEGAL' => 'Legal / Long (8.5 × 13 in)', 'HALF' => 'Half letter (5.5 × 8.5 in)', 'THERMAL' => 'Receipt printer (80 mm roll)'];
    private static ?array $all = null;

    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = self::DEFAULTS;
            try {
                foreach (Db::all('SELECT skey, svalue FROM sis_settings') as $r) if (array_key_exists($r['skey'], self::DEFAULTS)) self::$all[$r['skey']] = (string) $r['svalue'];
            } catch (PDOException) { /* not installed yet: defaults */ }
        }
        return self::$all;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    public static function on(string $key): bool
    {
        return self::get($key) === '1';
    }

    /** Save the settings form (unknown keys are ignored; checkboxes not sent are off). */
    public static function save(array $in): void
    {
        Auth::require('settings.manage');
        $v = [];
        foreach (self::DEFAULTS as $k => $def) {
            if (str_starts_with($k, 'doc_')) continue; // Documents settings have their own form
            if (in_array($k, self::SWITCHES, true)) { $v[$k] = !empty($in[$k]) ? '1' : '0'; continue; }
            $v[$k] = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in[$k] ?? '')));
        }
        foreach (['print_school_name' => 150, 'print_address' => 200, 'print_contact' => 150, 'asm_title' => 80, 'asm_sign_left' => 60, 'asm_sign_right' => 60,
                  'or_title' => 60, 'or_sign' => 60, 'asm_note' => 1000, 'or_note' => 500, 'balance_installment_rules' => 1000,
                  'balance_arrangement_approver_roles' => 100, 'balance_special_approver_roles' => 100] as $k => $max) {
            if (mb_strlen($v[$k]) > $max) throw new AppError("Too long: keep it under $max characters.");
        }
        if ($v['asm_title'] === '') $v['asm_title'] = self::DEFAULTS['asm_title'];
        if ($v['or_title'] === '') $v['or_title'] = self::DEFAULTS['or_title'];
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $v['balance_min_payment'])) throw new AppError('Minimum balance payment must be a valid amount.');
        Money::cents($v['balance_min_payment']); // refuses amounts too large to store
        foreach (['balance_arrangement_approver_roles', 'balance_special_approver_roles'] as $rk) {
            $roles = array_filter(array_map('trim', explode(',', strtoupper($v[$rk]))));
            foreach ($roles as $role) if (!in_array($role, ['ADMIN', 'REGISTRAR', 'ACCOUNTING'], true)) throw new AppError('Approver roles may only be ADMIN, REGISTRAR, or ACCOUNTING.');
            $v[$rk] = implode(',', $roles);
        }
        foreach (['asm_paper', 'or_paper'] as $k) if (!isset(self::PAPERS[$k === 'asm_paper' ? $v[$k] : $v[$k]])) throw new AppError('Choose a paper size.');
        if (!in_array($v['asm_template'], ['1', '2'], true)) throw new AppError('Choose an assessment template.');
        if (!in_array($v['or_copies'], ['1', '2'], true)) throw new AppError('Copies: 1 or 2.');
        Db::tx(function () use ($v) {
            foreach ($v as $k => $val) Db::q('INSERT INTO sis_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$k, $val]);
            Audit::log('settings_saved', 'settings', 'print', $v);
        });
        self::$all = null;
    }

    /** CSS @page size for a paper key. */
    public static function pageSize(string $paper): string
    {
        return ['A4' => 'A4', 'LETTER' => 'letter', 'LEGAL' => '8.5in 13in', 'HALF' => '5.5in 8.5in', 'THERMAL' => '80mm 297mm'][$paper] ?? 'A4';
    }

    /** ₱1,250.50 → "One thousand two hundred fifty pesos and 50/100" */
    public static function pesosInWords(int $cents): string
    {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $three = function (int $n) use ($ones, $tens): string {
            $w = [];
            if ($n >= 100) { $w[] = $ones[intdiv($n, 100)] . ' hundred'; $n %= 100; }
            if ($n >= 20) { $w[] = $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : ''); } elseif ($n > 0) { $w[] = $ones[$n]; }
            return implode(' ', $w);
        };
        $pesos = intdiv(abs($cents), 100); $c = abs($cents) % 100;
        if ($pesos === 0) $words = 'zero';
        else {
            $parts = [];
            foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand', 1 => ''] as $unit => $name) {
                if ($pesos >= $unit) { $parts[] = trim($three(intdiv($pesos, $unit)) . ' ' . $name); $pesos %= $unit; }
            }
            $words = implode(' ', $parts);
        }
        return ucfirst($words) . ' peso' . (intdiv(abs($cents), 100) === 1 ? '' : 's') . ' and ' . str_pad((string) $c, 2, '0', STR_PAD_LEFT) . '/100';
    }
}

// ------------------------------------------------------------------ document numbers
final class Seq
{
    /** Next number of a named sequence; must run inside a transaction (row lock). */
    public static function next(string $name): int
    {
        if (!Db::inTx()) throw new RuntimeException('Seq::next needs a transaction');
        $v = Db::val('SELECT next_value FROM sis_sequences WHERE name = ? FOR UPDATE', [$name]);
        if ($v === null) {
            Db::q('INSERT INTO sis_sequences (name, next_value) VALUES (?, 2)', [$name]);
            return 1;
        }
        Db::q('UPDATE sis_sequences SET next_value = next_value + 1 WHERE name = ?', [$name]);
        return (int) $v;
    }
}

// ------------------------------------------------------------------ audit log
final class Audit
{
    public static function log(string $action, string $entityType, int|string|null $entityId, array $details = []): void
    {
        Db::insert('sis_audit_logs', [
            'user_id'     => Auth::id(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId === null ? null : (string) $entityId,
            'details'     => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]);
    }
}

// ------------------------------------------------------------------ input
final class In
{
    public static function str(array $src, string $key, int $max = 255, bool $required = false, string $label = ''): ?string
    {
        $v = isset($src[$key]) && is_scalar($src[$key]) ? trim((string) $src[$key]) : '';
        if ($v === '') {
            if ($required) throw new AppError(($label ?: $key) . ' is required.');
            return null;
        }
        if (mb_strlen($v) > $max) throw new AppError(($label ?: $key) . " must be at most $max characters.");
        return $v;
    }

    public static function int(array $src, string $key, bool $required = false, string $label = '', ?int $min = null, ?int $max = null): ?int
    {
        $v = isset($src[$key]) && is_scalar($src[$key]) ? trim((string) $src[$key]) : '';
        if ($v === '') {
            if ($required) throw new AppError(($label ?: $key) . ' is required.');
            return null;
        }
        if (!preg_match('/^-?\d{1,10}$/', $v)) throw new AppError(($label ?: $key) . ' must be a whole number.');
        $i = (int) $v;
        if ($min !== null && $i < $min) throw new AppError(($label ?: $key) . " must be at least $min.");
        if ($max !== null && $i > $max) throw new AppError(($label ?: $key) . " must be at most $max.");
        return $i;
    }

    /** Decimal amount → cents. */
    public static function money(array $src, string $key, bool $required = false, string $label = '', bool $allowZero = true): ?int
    {
        $v = isset($src[$key]) && is_scalar($src[$key]) ? trim((string) $src[$key]) : '';
        if ($v === '') {
            if ($required) throw new AppError(($label ?: $key) . ' is required.');
            return null;
        }
        try { $c = Money::cents($v); }
        catch (AppError $e) { throw new AppError(($label ?: $key) . ': ' . $e->getMessage()); }
        if ($c < 0 || (!$allowZero && $c === 0)) throw new AppError(($label ?: $key) . ' must be ' . ($allowZero ? 'zero or more.' : 'more than zero.'));
        if ($c > 99999999999) throw new AppError(($label ?: $key) . ' is too large.');
        return $c;
    }

    public static function decimal(array $src, string $key, bool $required = false, string $label = '', float $min = 0, float $max = 1e9): ?string
    {
        $v = isset($src[$key]) && is_scalar($src[$key]) ? trim((string) $src[$key]) : '';
        if ($v === '') {
            if ($required) throw new AppError(($label ?: $key) . ' is required.');
            return null;
        }
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $v)) throw new AppError(($label ?: $key) . ' must be a number.');
        if ((float) $v < $min || (float) $v > $max) throw new AppError(($label ?: $key) . " must be between $min and $max.");
        return $v;
    }

    public static function date(array $src, string $key, bool $required = false, string $label = ''): ?string
    {
        $v = isset($src[$key]) && is_scalar($src[$key]) ? trim((string) $src[$key]) : '';
        if ($v === '') {
            if ($required) throw new AppError(($label ?: $key) . ' is required.');
            return null;
        }
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) throw new AppError(($label ?: $key) . ' must be a valid date (YYYY-MM-DD).');
        return $v;
    }

    public static function time(array $src, string $key): ?string
    {
        $v = isset($src[$key]) && is_scalar($src[$key]) ? trim((string) $src[$key]) : '';
        if ($v === '') return null;
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) throw new AppError("$key must be a time (HH:MM).");
        return $v . ':00';
    }

    /** Value must be one of $allowed. */
    public static function enum(array $src, string $key, array $allowed, bool $required = true, string $label = ''): ?string
    {
        $v = isset($src[$key]) && is_scalar($src[$key]) ? trim((string) $src[$key]) : '';
        if ($v === '') {
            if ($required) throw new AppError(($label ?: $key) . ' is required.');
            return null;
        }
        if (!in_array($v, $allowed, true)) throw new AppError(($label ?: $key) . ' has an invalid value.');
        return $v;
    }

    public static function bool(array $src, string $key): int
    {
        return !empty($src[$key]) ? 1 : 0;
    }

    /** List of ints from a multi-select (name[]). */
    public static function ints(array $src, string $key): array
    {
        $v = $src[$key] ?? [];
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $x) if (is_scalar($x) && preg_match('/^\d{1,10}$/', (string) $x)) $out[] = (int) $x;
        return array_values(array_unique($out));
    }

    public static function strs(array $src, string $key, array $allowed): array
    {
        $v = $src[$key] ?? [];
        if (!is_array($v)) return [];
        return array_values(array_unique(array_filter($v, fn($x) => is_string($x) && in_array($x, $allowed, true))));
    }
}

// ------------------------------------------------------------------ output helpers
function h(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** v58: pages that belong only to the Finance system (../finance/). Old SIS links to them are forwarded there.
 *  v58.1: Cashier (payments, receipts, Document Fees, O.R. series) is in both the SIS and Finance; each keeps its own menu. */
const FINANCE_PAGES = ['balance', 'accounting'];
/** Pages that belong to the Student Portal (../portal/). */
const PORTAL_PAGES = ['my', 'myenrollment', 'myadmission'];

/**
 * Link to a page. A system with its own folder (Student Portal, Finance, LMS) defines LOCAL_ROUTES: its own
 * pages stay there. Finance pages go to ../finance/, Student Portal pages to ../portal/, everything else to the SIS.
 */
function url(string $route, array $params = []): string
{
    $q = http_build_query(['r' => $route] + $params);
    $local = defined('LOCAL_ROUTES');
    if ($local && in_array($route, LOCAL_ROUTES, true)) return 'index.php?' . $q;
    if (in_array($route, FINANCE_PAGES, true)) return '../finance/' . 'index.php?' . $q;
    if (in_array($route, PORTAL_PAGES, true)) return '../portal/index.php?' . $q;
    return ($local ? '../sis/' : '') . 'index.php?' . $q;
}

/** The system this request belongs to: SIS, FINANCE or PORTAL (set by each system's index.php). */
function app_system(): string
{
    return defined('APP_SYSTEM') ? APP_SYSTEM : (defined('PORTAL_ROOT') ? 'PORTAL' : 'SIS');
}

/** Path from the current system's folder to the SIS folder, for shared stylesheets and scripts. */
function sis_web(): string
{
    return app_system() === 'SIS' ? '' : '../sis/';
}

function redirect(string $route, array $params = []): never
{
    if(class_exists('RecordList'))$params=RecordList::redirectParams($route,$params);
    header('Location: ' . url($route, $params));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

/** A refused form keeps what was typed: saved for the next page load, which fills the form in again. */
function keep_old_input(): void
{
    $skip = ['_csrf', '_once', 'idem', 'expected_paid', 'or_suggested'];
    $clip = fn($v) => is_scalar($v) ? mb_substr((string) $v, 0, 5000) : '';
    $fields = [];
    foreach ($_POST as $k => $v) {
        if (!is_string($k) || in_array($k, $skip, true) || stripos($k, 'password') !== false) continue;
        if (is_scalar($v)) $fields[$k] = $clip($v);
        elseif (is_array($v)) $fields[$k] = array_map($clip, array_slice($v, 0, 500, true));
    }
    $_SESSION['old_input'] = ['uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''), 'action' => is_string($_POST['action'] ?? null) ? $_POST['action'] : '', 'fields' => $fields];
}

/** The refused form's values for this page, once (JSON for assets/app.js). */
function take_old_input(): ?string
{
    $o = $_SESSION['old_input'] ?? null;
    unset($_SESSION['old_input']);
    if (!is_array($o) || ($o['uri'] ?? '') !== (string) ($_SERVER['REQUEST_URI'] ?? '')) return null;
    return json_encode(['action' => $o['action'], 'fields' => $o['fields']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function student_name(array $s): string
{
    return trim($s['last_name'] . ', ' . $s['first_name'] . (!empty($s['middle_name']) ? ' ' . mb_substr($s['middle_name'], 0, 1) . '.' : ''));
}

function badge(string $status): string
{
    $map = [
        'success' => ['ADMITTED','REGISTERED','DONE','APPROVED','VERIFIED','ENROLLED','FINALIZED','PAID','GRADES_COMPLETE','POSTED','ISSUED','ACTIVE','PASSED','CREDITED','RELEASED'],
        'warning' => ['PARTIAL','PENDING','SUBMITTED','PENDING_REVIEW','DRAFT','PARTIALLY_PAID','PENDING_GRADES','REQUIRES_REVIEW','REFUND_PENDING','PARTIALLY_REFUNDED','INC','IN_PROGRESS'],
        'danger'  => ['REJECTED','CANCELLED','VOIDED','OVERDUE','GRADES_INCOMPLETE','FAILED','REVOKED','UNPAID','DROPPED'],
        'info'    => ['OPEN','RETURNED','ASSESSED','REFUNDED','NOT_TAKEN'],
    ];
    $cls = 'secondary';
    foreach ($map as $c => $list) if (in_array($status, $list, true)) { $cls = $c; break; }
    return '<span class="badge text-bg-' . $cls . '">' . h(str_replace('_', ' ', $status)) . '</span>';
}

function opt(array $rows, string $valueKey, string|callable $labelKey, mixed $selected = null, string $empty = ''): string
{
    $html = $empty !== '' ? '<option value="">' . h($empty) . '</option>' : '';
    foreach ($rows as $r) {
        $v = (string) $r[$valueKey];
        $label = is_callable($labelKey) ? $labelKey($r) : $r[$labelKey];
        $sel = ((string) $selected === $v) ? ' selected' : '';
        $html .= '<option value="' . h($v) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html;
}

function enum_opts(array $values, mixed $selected = null, string $empty = ''): string
{
    $rows = array_map(fn($v) => ['v' => $v, 'l' => str_replace('_', ' ', $v)], $values);
    return opt($rows, 'v', 'l', $selected, $empty);
}

const YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'];   // as in the grading system
const TERMS = ['1st Semester', '2nd Semester', '3rd Semester', 'Summer'];            // as in the grading system
const CHARGE_TYPES = ['FIXED' => 'Fixed amount', 'PER_UNIT' => 'Per unit', 'PER_SUBJECT' => 'Per subject'];
const PAYMENT_METHODS = ['CASH', 'GCASH', 'MAYA', 'BANK', 'CARD', 'CHECK'];

/**
 * One-time form tokens: a form carries once_field(); its action runs inside FormOnce::run(), so a resubmit
 * (reload after a dropped connection, double click, Back + submit) is recognised and not applied twice.
 */
final class FormOnce
{
    private const TABLE = 'CREATE TABLE IF NOT EXISTS sis_form_once (token CHAR(32) NOT NULL PRIMARY KEY, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public static function token(): string { return bin2hex(random_bytes(16)); }

    /** Runs $action once per token. Returns false when this token was already used (the earlier submit counted). */
    public static function run(array $post, callable $action): bool
    {
        $t = is_string($post['_once'] ?? null) && preg_match('/^[a-f0-9]{32}$/', $post['_once']) ? $post['_once'] : null;
        if ($t === null) { $action(); return true; }
        static $ready = false;
        if (!$ready) { Db::pdo()->exec(self::TABLE); $ready = true; } // DDL commits, so it runs before the transaction
        return (bool) Db::tx(function () use ($t, $action) {
            if (Db::q('INSERT IGNORE INTO sis_form_once (token) VALUES (?)', [$t])->rowCount() === 0) return false;
            if (random_int(1, 200) === 1) Db::q('DELETE FROM sis_form_once WHERE created_at < NOW() - INTERVAL 30 DAY');
            $action();
            return true;
        });
    }
}
