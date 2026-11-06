<?php
declare(strict_types=1);

/**
 * v55: Online clearance. Each confirmed enrollment of a term with a clearance period gets a clearance:
 * one line per subject (signed by that class's teacher) and one per office (each group ticked
 * "This group signs student clearance", e.g. Accounting, Library). The lines are worked out when shown,
 * so unticking a group drops it from clearances still in progress while the signatures it gave stay.
 * When every line is cleared the clearance is completed; the registrar then e-signs it as final.
 * Before the next term's enrollment is confirmed, an incomplete clearance needs the registrar's reason and password.
 */
final class Clearance
{
    /** Settings → Online clearance (Power Users only). Off: nothing about clearance shows anywhere. */
    public const SETTINGS = ['clr_enabled' => '0', 'clr_required' => '1', 'clr_uploads' => '1'];
    public const MAX_FILE = 5 * 1024 * 1024;
    public const FILE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    private const NO_TEACHER = '_acadlink_professor_x';
    private const TERM_ORDER = ['1st Semester' => 1, '2nd Semester' => 2, '3rd Semester' => 3, 'Summer' => 4];
    private static array $classCache = [];

    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS sis_clearance_periods (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_year VARCHAR(9) NOT NULL, term VARCHAR(20) NOT NULL, opens_on DATE NOT NULL, closes_on DATE NOT NULL,
            is_open TINYINT(1) NOT NULL DEFAULT 1, updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY u_sis_clr_period (school_year, term)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS sis_clearance_tags (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL, item_key VARCHAR(220) NOT NULL, item_label VARCHAR(200) NOT NULL DEFAULT '',
            status ENUM('CLEARED','NOT_CLEARED') NOT NULL, note VARCHAR(500) NOT NULL DEFAULT '',
            signer_account_id BIGINT UNSIGNED NOT NULL, signer_name VARCHAR(200) NOT NULL, signer_position VARCHAR(100) NOT NULL DEFAULT '',
            signature_mode ENUM('ESIGN','APPROVED','NONE') NOT NULL DEFAULT 'NONE', signature MEDIUMBLOB NULL, control_no VARCHAR(30) NULL,
            signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            undone_at DATETIME NULL, undone_by BIGINT UNSIGNED NULL, undo_reason VARCHAR(255) NULL,
            active_flag TINYINT GENERATED ALWAYS AS (IF(undone_at IS NULL, 1, NULL)) STORED,
            UNIQUE KEY u_sis_clr_active (registration_id, item_key, active_flag), KEY k_sis_clr_reg (registration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS sis_clearance_submissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL, item_key VARCHAR(220) NOT NULL, message VARCHAR(1000) NOT NULL DEFAULT '',
            file MEDIUMBLOB NULL, file_name VARCHAR(150) NULL, file_mime VARCHAR(60) NULL, file_size INT UNSIGNED NULL,
            status ENUM('NEW','SEEN','REPLIED') NOT NULL DEFAULT 'NEW', reply VARCHAR(1000) NULL, replied_by BIGINT UNSIGNED NULL, replied_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY k_sis_clr_sub (registration_id, item_key), KEY k_sis_clr_sub_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS sis_clearance_overrides (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL, cleared_registration_id INT UNSIGNED NOT NULL, pending VARCHAR(1000) NOT NULL DEFAULT '',
            reason VARCHAR(255) NOT NULL, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY k_sis_clr_ovr (registration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Groups that sign clearance, their order on the slip, and who in them may e-sign
        if (!$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='central_groups'")->fetchColumn()) return;
        if (!$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='central_groups' AND column_name='signs_clearance'")->fetchColumn()) {
            $db->exec('ALTER TABLE central_groups ADD COLUMN signs_clearance TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN clearance_order SMALLINT UNSIGNED NOT NULL DEFAULT 50');
        }
        $db->exec("CREATE TABLE IF NOT EXISTS central_clearance_signers (
            account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, can_sign TINYINT(1) NOT NULL,
            updated_by BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("INSERT IGNORE INTO central_permissions(code,workspace,label) VALUES ('sis.clearance.view','SIS','See students'' clearance'),('sis.clearance.manage','SIS','Open clearance periods and give the final approval')");
        if (!$db->query("SELECT 1 FROM central_schema_migrations WHERE migration_key='clearance_v1'")->fetchColumn()) {
            $db->exec("UPDATE central_groups SET signs_clearance=1, clearance_order=CASE code WHEN 'TEACHER' THEN 10 WHEN 'ACCOUNTING' THEN 60 WHEN 'REGISTRAR' THEN 90 END WHERE code IN ('TEACHER','ACCOUNTING','REGISTRAR')");
            $db->exec("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
                       SELECT g.id,c.code,'ALL' FROM central_groups g JOIN central_permissions c ON (c.code='sis.clearance.view' AND g.code IN ('POWER_USER','REGISTRAR','ACCOUNTING')) OR (c.code='sis.clearance.manage' AND g.code IN ('POWER_USER','REGISTRAR'))");
            $db->exec("INSERT IGNORE INTO central_schema_migrations(migration_key) VALUES ('clearance_v1')");
        }
    }

    // ------------------------------------------------------------------ settings

    public static function setting(string $k): string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = self::SETTINGS;
            try { foreach (Db::all('SELECT skey, svalue FROM sis_settings WHERE skey LIKE "clr\\_%"') as $r) if (isset($cache[$r['skey']])) $cache[$r['skey']] = (string) $r['svalue']; }
            catch (PDOException) {}
        }
        return $cache[$k] ?? '';
    }
    public static function on(): bool { return self::setting('clr_enabled') === '1'; }
    public static function required(): bool { return self::on() && self::setting('clr_required') === '1'; }
    public static function uploads(): bool { return self::on() && self::setting('clr_uploads') === '1'; }

    /** Settings → Online clearance. Power Users only; every change is logged. Turning it off keeps every record. */
    public static function saveSettings(array $in): void
    {
        $me = self::me();
        if (!self::isPower($me)) throw new Forbidden('Only a Power User can turn online clearance on or off.');
        $new = [];
        foreach (self::SETTINGS as $k => $_) $new[$k] = !empty($in[$k]) ? '1' : '0';
        $old = array_combine(array_keys(self::SETTINGS), array_map([self::class, 'setting'], array_keys(self::SETTINGS)));
        Db::tx(function () use ($new) { foreach ($new as $k => $v) Db::q('INSERT INTO sis_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$k, $v]); });
        Audit::log('clearance_settings_saved', 'settings', 0, ['before' => $old, 'after' => $new]);
    }

    public static function offMessage(): string { return 'Online clearance is turned off. A Power User can turn it on in Settings → Online clearance.'; }

    // ------------------------------------------------------------------ who signs

    private static function me(): int { return (int) (Auth::central()->current()['id'] ?? 0); }
    public static function isPower(int $account): bool { return $account > 0 && Auth::central()->group($account) === 'POWER_USER'; }

    /** Groups that sign clearance (offices), in slip order. TEACHER means each class's teacher. */
    public static function offices(): array
    {
        return Db::all("SELECT code, name, clearance_order FROM central_groups WHERE signs_clearance = 1 AND is_active = 1 AND code NOT IN ('POWER_USER','STUDENT') ORDER BY clearance_order, id");
    }

    public static function groupSigns(string $code): bool
    {
        return $code !== '' && !in_array($code, ['POWER_USER', 'STUDENT'], true) && (bool) Db::val('SELECT signs_clearance FROM central_groups WHERE code = ? AND is_active = 1', [$code]);
    }

    /** The account's own "Can e-sign clearance" tick: defaults to yes in a group that signs clearance. */
    public static function accountTick(int $account): bool
    {
        $v = Db::val('SELECT can_sign FROM central_clearance_signers WHERE account_id = ?', [$account]);
        return $v === null || $v === false ? true : (bool) $v;
    }

    /** May this account sign clearance at all (its group signs it and the account is ticked)? Power Users approve anything. */
    public static function canSign(int $account): bool
    {
        if ($account <= 0) return false;
        if (self::isPower($account)) return true;
        return self::groupSigns(Auth::central()->group($account)) && self::accountTick($account);
    }

    /** Users and Groups: tick an account's "Can e-sign clearance" (only in a group that signs clearance). */
    public static function setAccountTick(int $actor, int $account, bool $can): void
    {
        Auth::central()->requirePower($actor);
        $group = Auth::central()->group($account);
        if (!self::groupSigns($group)) return; // greyed out: the group doesn't sign clearance
        $before = self::accountTick($account);
        if ($before === $can) return;
        Db::q('INSERT INTO central_clearance_signers (account_id, can_sign, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE can_sign = VALUES(can_sign), updated_by = VALUES(updated_by)', [$account, (int) $can, $actor]);
        Auth::central()->audit($actor, $account, 'clearance_signer_changed', ['before' => $before, 'after' => $can]);
    }

    /** Users and Groups: "This group signs student clearance" and its order on the slip. */
    public static function setGroup(int $actor, string $code, bool $signs, int $order): void
    {
        Auth::central()->requirePower($actor);
        if (in_array($code, ['POWER_USER', 'STUDENT'], true)) throw new AppError('Power Users and Students do not sign clearance.');
        $g = Db::one('SELECT * FROM central_groups WHERE code = ?', [$code]) ?: throw new AppError('Group not found.');
        $order = max(1, min(999, $order));
        Db::tx(function () use ($g, $signs, $order) {
            Db::q('UPDATE central_groups SET signs_clearance = ?, clearance_order = ? WHERE id = ?', [(int) $signs, $order, $g['id']]);
            // An office needs the SIS to sign there: it gets "See students' clearance" (adjustable in its access)
            if ($signs && $g['code'] !== 'TEACHER') Db::q("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope) SELECT ?,c.code,'ALL' FROM central_permissions c WHERE c.code IN ('sis.clearance.view','sis.workspace.view')", [$g['id']]);
        });
        if ((bool) $g['signs_clearance'] !== $signs || (int) $g['clearance_order'] !== $order)
            Auth::central()->audit($actor, null, 'group_clearance_updated', ['group' => $code, 'before' => ['signs' => (bool) $g['signs_clearance'], 'order' => (int) $g['clearance_order']], 'after' => ['signs' => $signs, 'order' => $order]]);
        Auth::central()->clearCache();
    }

    // ------------------------------------------------------------------ periods

    public static function periods(): array
    {
        return Db::all('SELECT * FROM sis_clearance_periods ORDER BY school_year DESC, FIELD(term,"Summer","3rd Semester","2nd Semester","1st Semester")');
    }
    public static function period(string $sy, string $term): ?array
    {
        return Db::one('SELECT * FROM sis_clearance_periods WHERE school_year = ? AND term = ?', [$sy, $term]);
    }
    public static function isOpen(?array $p, ?string $today = null): bool
    {
        $d = $today ?? date('Y-m-d');
        return $p !== null && (int) $p['is_open'] === 1 && $d >= $p['opens_on'] && $d <= $p['closes_on'];
    }

    /** The registrar opens (or closes) clearance for a term, with dates. */
    public static function savePeriod(array $in): void
    {
        Auth::require('clearance.manage');
        $sy = trim((string) ($in['school_year'] ?? '')); $term = trim((string) ($in['term'] ?? ''));
        if (!GS::validSchoolYear($sy) || !isset(self::TERM_ORDER[$term])) throw new AppError('Choose the school year and term.');
        $opens = In::date($in, 'opens_on', true, 'Opens on'); $closes = In::date($in, 'closes_on', true, 'Closes on');
        if ($closes < $opens) throw new AppError('The closing date must be on or after the opening date.');
        $open = !empty($in['is_open']) ? 1 : 0;
        Db::q('INSERT INTO sis_clearance_periods (school_year, term, opens_on, closes_on, is_open, updated_by) VALUES (?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE opens_on = VALUES(opens_on), closes_on = VALUES(closes_on), is_open = VALUES(is_open), updated_by = VALUES(updated_by)', [$sy, $term, $opens, $closes, $open, Auth::id()]);
        Audit::log('clearance_period_saved', 'clearance', 0, compact('sy', 'term', 'opens', 'closes', 'open'));
    }

    // ------------------------------------------------------------------ lines of a clearance

    public static function registration(int $id): array
    {
        return Db::one("SELECT * FROM sis_registrations WHERE id = ? AND status = 'REGISTERED'", [$id]) ?: throw new AppError('Enrollment not found.');
    }

    /** The class (grading assignment) of each subject the student is in this term: subject id => assignment. */
    private static function classesOf(array $r): array
    {
        $key = $r['school_year'] . '|' . $r['term'];
        if (!isset(self::$classCache[$key])) {
            self::$classCache[$key] = [];
            foreach (Db::all("SELECT id, data FROM docs WHERE col = 'gradingAssignments' AND f_schoolYear = ?", [$r['school_year']]) as $row) {
                $a = ['id' => $row['id']] + (json_decode((string) $row['data'], true) ?: []);
                if ((string) ($a['term'] ?? '') === $r['term']) self::$classCache[$key][] = $a;
            }
        }
        $out = [];
        foreach (self::$classCache[$key] as $a) {
            $sid = (string) ($a['subjectId'] ?? '');
            if (in_array($r['student_doc_id'], (array) ($a['studentIds'] ?? []), true)) $out[$sid] = $a;
            elseif (!isset($out[$sid]) && (string) ($a['sectionId'] ?? '') === $r['section_doc_id']) $out[$sid] = $a + ['_section_only' => true];
        }
        return $out;
    }

    /**
     * The lines this clearance needs today, in slip order:
     * [key, kind SUBJECT|OFFICE, label, office (group code), teacher_doc_id, teacher_name, subject_code].
     * A subject whose class has no teacher yet is signed by the registrar.
     */
    public static function items(array $r): array
    {
        $items = [];
        foreach (self::offices() as $o) {
            if ($o['code'] === 'TEACHER') {
                $classes = self::classesOf($r);
                foreach (Db::all('SELECT subject_doc_id, subject_code, subject_name FROM sis_registration_subjects WHERE registration_id = ? ORDER BY subject_code', [$r['id']]) as $s) {
                    $c = $classes[$s['subject_doc_id']] ?? null;
                    $t = $c && ($c['teacherDocId'] ?? '') !== self::NO_TEACHER ? (string) ($c['teacherDocId'] ?? '') : '';
                    $items[] = ['key' => 'SUBJECT:' . $s['subject_doc_id'], 'kind' => 'SUBJECT', 'label' => $s['subject_code'] . ' · ' . $s['subject_name'], 'office' => 'TEACHER',
                        'teacher_doc_id' => $t, 'teacher_name' => $t !== '' ? (string) ($c['teacherName'] ?? '') : 'No teacher assigned (the registrar signs)', 'subject_code' => (string) $s['subject_code']];
                }
            } else {
                $items[] = ['key' => 'OFFICE:' . $o['code'], 'kind' => 'OFFICE', 'label' => (string) $o['name'], 'office' => (string) $o['code'], 'teacher_doc_id' => '', 'teacher_name' => '', 'subject_code' => ''];
            }
        }
        return $items;
    }

    /** Active tags of registrations: [registration id][item key] => tag (without the signature image). */
    private static function tags(array $regIds): array
    {
        if (!$regIds) return [];
        $out = [];
        foreach (Db::all('SELECT id, registration_id, item_key, item_label, status, note, signer_account_id, signer_name, signer_position, signature_mode, control_no, signed_at FROM sis_clearance_tags WHERE undone_at IS NULL AND registration_id IN ' . Db::in($regIds), array_values($regIds)) as $t)
            $out[(int) $t['registration_id']][$t['item_key']] = $t;
        return $out;
    }

    /**
     * A student's clearance for a term: lines with their state (CLEARED / NOT_CLEARED / PENDING), signatures kept from
     * offices that no longer sign ('retired'), whether it is completed, and the registrar's final approval.
     */
    public static function status(array $r, ?array $tags = null): array
    {
        $tags ??= self::tags([(int) $r['id']])[(int) $r['id']] ?? [];
        $items = [];
        $counts = ['CLEARED' => 0, 'NOT_CLEARED' => 0, 'PENDING' => 0];
        foreach (self::items($r) as $it) {
            $t = $tags[$it['key']] ?? null;
            $it['tag'] = $t; $it['state'] = $t ? $t['status'] : 'PENDING';
            $counts[$it['state']]++;
            $items[] = $it;
        }
        $keys = array_column($items, 'key');
        $retired = array_values(array_filter($tags, fn($t) => $t['item_key'] !== 'FINAL' && !in_array($t['item_key'], $keys, true) && $t['status'] === 'CLEARED'));
        $complete = $items && $counts['CLEARED'] === count($items);
        return ['registration' => $r, 'items' => $items, 'retired' => $retired, 'counts' => $counts, 'complete' => $complete,
            'final' => $tags['FINAL'] ?? null, 'period' => self::period($r['school_year'], $r['term']),
            'pending' => array_values(array_map(fn($i) => $i['label'] . ($i['state'] === 'NOT_CLEARED' ? ' (not cleared: ' . $i['tag']['note'] . ')' : ''), array_filter($items, fn($i) => $i['state'] !== 'CLEARED')))];
    }

    /** Confirmed enrollments of a term (the students who need clearance), optionally by program and year level. */
    public static function registrations(string $sy, string $term, string $program = '', string $year = '', string $q = ''): array
    {
        $sql = "SELECT * FROM sis_registrations WHERE status = 'REGISTERED' AND locked_at IS NOT NULL AND school_year = ? AND term = ?";
        $args = [$sy, $term];
        if ($program !== '') { $sql .= ' AND program = ?'; $args[] = $program; }
        if ($year !== '') { $sql .= ' AND year_level = ?'; $args[] = $year; }
        if (trim($q) !== '') { $sql .= ' AND (student_name LIKE ? OR student_number LIKE ?)'; $args[] = '%' . trim($q) . '%'; $args[] = '%' . trim($q) . '%'; }
        return Db::all($sql . ' ORDER BY student_name', $args);
    }

    /** Clearances of many registrations at once. */
    public static function statuses(array $regs): array
    {
        $tags = self::tags(array_map(fn($r) => (int) $r['id'], $regs));
        $out = [];
        foreach ($regs as $r) $out[(int) $r['id']] = self::status($r, $tags[(int) $r['id']] ?? []);
        return $out;
    }

    // ------------------------------------------------------------------ signing

    /** Can $account tag this line? Teachers their own classes; office staff their office; the registrar classes without a teacher and the final approval. */
    public static function mayTag(int $account, array $item): bool
    {
        if (!self::on() || !self::canSign($account)) return false;
        if (self::isPower($account)) return true;
        $c = Auth::central();
        $group = $c->group($account);
        if ($item['key'] === 'FINAL') return $group === 'REGISTRAR';
        if ($item['kind'] === 'SUBJECT') {
            if ($item['teacher_doc_id'] === '') return $group === 'REGISTRAR' && self::groupSigns('REGISTRAR');
            $a = $c->account($account);
            return $group === 'TEACHER' && !empty($a['person_verified']) && (string) $a['teacher_doc_id'] === $item['teacher_doc_id'];
        }
        return $group === $item['office'];
    }

    /** What an account may see: every clearance (view permission), or only lines it signs. */
    public static function canView(int $account): bool
    {
        return self::isPower($account) || Auth::can('clearance') || self::canSign($account);
    }

    /** Signature block for a tag: the e-signature (snapshot), or "Approved" for a Power User. */
    private static function signature(int $account, string $position): array
    {
        $a = Auth::central()->account($account);
        if (self::isPower($account)) return ['APPROVED', null, (string) $a['display_name'], 'Power User'];
        $img = Db::val('SELECT image FROM central_esignatures WHERE account_id = ?', [$account]);
        if (!$img) throw new AppError('Add your e-signature first: open My profile → E-signature. Clearance is signed with it.');
        return ['ESIGN', (string) $img, (string) $a['display_name'], $position];
    }

    private static function positionOf(int $account, array $item): string
    {
        if ($item['key'] === 'FINAL') return 'Registrar';
        if ($item['kind'] === 'SUBJECT') return $item['teacher_doc_id'] !== '' ? 'Teacher, ' . $item['subject_code'] : 'Registrar (for ' . $item['subject_code'] . ')';
        return (string) (Db::val('SELECT name FROM central_groups WHERE code = ?', [$item['office']]) ?: $item['office']);
    }

    private static function findItem(array $r, string $key): array
    {
        if ($key === 'FINAL') return ['key' => 'FINAL', 'kind' => 'FINAL', 'label' => 'Registrar · final approval', 'office' => 'REGISTRAR', 'teacher_doc_id' => '', 'subject_code' => ''];
        foreach (self::items($r) as $it) if ($it['key'] === $key) return $it;
        throw new AppError('This line is not on the student\'s clearance (the office may no longer sign clearance).');
    }

    /**
     * Sign a line as cleared (with the e-signature) or tag it "Not cleared" with what's missing.
     * Replaces a "Not cleared" tag; a cleared line must be undone first.
     */
    public static function tag(int $regId, string $key, string $status, string $note = ''): void
    {
        if (!self::on()) throw new AppError(self::offMessage());
        $me = self::me();
        if (!in_array($status, ['CLEARED', 'NOT_CLEARED'], true)) throw new AppError('Choose Cleared or Not cleared.');
        $note = trim($note);
        if ($status === 'NOT_CLEARED' && mb_strlen($note) < 3) throw new AppError('Write what the student still has to settle (e.g. "Return 2 library books").');
        Db::tx(function () use ($regId, $key, $status, $note, $me) {
            $r = Db::one("SELECT * FROM sis_registrations WHERE id = ? AND status = 'REGISTERED' FOR UPDATE", [$regId]) ?: throw new AppError('Enrollment not found.');
            if (!$r['locked_at']) throw new AppError('Only confirmed enrollments have a clearance.');
            if (!self::isOpen(self::period($r['school_year'], $r['term']))) throw new AppError('Clearance for ' . $r['school_year'] . ' ' . $r['term'] . ' is not open. The registrar opens it with dates on the Online Clearance page.');
            $item = self::findItem($r, $key);
            if (!self::mayTag($me, $item)) throw new Forbidden('You cannot sign this line of the clearance.');
            $old = Db::one('SELECT * FROM sis_clearance_tags WHERE registration_id = ? AND item_key = ? AND undone_at IS NULL FOR UPDATE', [$regId, $key]);
            if ($old && $old['status'] === 'CLEARED') throw new AppError($item['label'] . ' is already signed as cleared for ' . $r['student_name'] . '. Undo it first to change it.');
            if ($key !== 'FINAL' && Db::val("SELECT 1 FROM sis_clearance_tags WHERE registration_id = ? AND item_key = 'FINAL' AND undone_at IS NULL", [$regId])) throw new AppError('The registrar already approved this clearance as final.');
            if ($old) Db::q('UPDATE sis_clearance_tags SET undone_at = NOW(), undone_by = ?, undo_reason = ? WHERE id = ?', [$me, $status === 'CLEARED' ? 'Signed as cleared' : 'Note changed', $old['id']]);
            [$mode, $img, $name, $pos] = $status === 'CLEARED' ? self::signature($me, self::positionOf($me, $item)) : ['NONE', null, (string) Auth::central()->account($me)['display_name'], self::positionOf($me, $item)];
            $control = $key === 'FINAL' ? self::nextControl() : null;
            Db::insert('sis_clearance_tags', ['registration_id' => $regId, 'item_key' => $key, 'item_label' => mb_substr($item['label'], 0, 200), 'status' => $status, 'note' => mb_substr($note, 0, 500),
                'signer_account_id' => $me, 'signer_name' => $name, 'signer_position' => mb_substr($pos, 0, 100), 'signature_mode' => $mode, 'signature' => $img, 'control_no' => $control]);
            Audit::log($status === 'CLEARED' ? 'clearance_signed' : 'clearance_not_cleared', 'registration', $regId, ['line' => $item['label'], 'note' => $note]);
            self::notifyStudent($r, $key === 'FINAL' ? 'Clearance approved' : ($status === 'CLEARED' ? 'Clearance signed' : 'Clearance: not cleared yet'),
                $key === 'FINAL' ? 'The registrar approved your clearance for ' . $r['school_year'] . ' ' . $r['term'] . ' (control no. ' . $control . '). You can print your clearance slip.'
                : ($status === 'CLEARED' ? $name . ' signed ' . $item['label'] . ' on your clearance for ' . $r['school_year'] . ' ' . $r['term'] . '.'
                : $item['label'] . ': not cleared yet. To settle: ' . $note));
        });
    }

    /** "Sign all cleared": e-sign many lines at once after re-entering the password. $pairs: ["regId|key", ...]. */
    public static function signMany(array $pairs, string $password): array
    {
        if (!self::on()) throw new AppError(self::offMessage());
        $me = self::me();
        if (!Auth::central()->verifyPassword($me, $password)) throw new AppError('Your password is not correct. Nothing was signed.');
        $done = 0; $skipped = [];
        foreach (array_unique(array_filter(array_map('strval', $pairs))) as $p) {
            [$reg, $key] = array_pad(explode('|', $p, 2), 2, '');
            try { self::tag((int) $reg, $key, 'CLEARED'); $done++; }
            catch (AppError | Forbidden $e) { $skipped[] = $e->getMessage(); }
        }
        if (!$done && !$skipped) throw new AppError('Tick the students to sign first.');
        return ['signed' => $done, 'skipped' => $skipped];
    }

    /** Undo a tag with a reason: the person who signed it, the registrar (final approval) or a Power User. */
    public static function undo(int $tagId, string $reason): void
    {
        $me = self::me();
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new AppError('Give the reason for undoing it.');
        Db::tx(function () use ($tagId, $reason, $me) {
            $t = Db::one('SELECT * FROM sis_clearance_tags WHERE id = ? AND undone_at IS NULL FOR UPDATE', [$tagId]) ?: throw new AppError('This tag was already undone.');
            $own = (int) $t['signer_account_id'] === $me;
            $registrar = $t['item_key'] === 'FINAL' && Auth::central()->group($me) === 'REGISTRAR' && self::canSign($me);
            if (!$own && !$registrar && !self::isPower($me)) throw new Forbidden('Only the person who signed it, or a Power User, can undo it.');
            if ($t['item_key'] !== 'FINAL' && Db::val("SELECT 1 FROM sis_clearance_tags WHERE registration_id = ? AND item_key = 'FINAL' AND undone_at IS NULL", [$t['registration_id']])) throw new AppError('Undo the registrar\'s final approval first.');
            Db::q('UPDATE sis_clearance_tags SET undone_at = NOW(), undone_by = ?, undo_reason = ? WHERE id = ?', [$me, mb_substr($reason, 0, 255), $tagId]);
            Audit::log('clearance_undone', 'registration', (int) $t['registration_id'], ['line' => $t['item_label'], 'status' => $t['status'], 'reason' => $reason]);
        });
    }

    /** The registrar's final e-signature on a completed clearance ("Clearance approved"), with a control number. */
    public static function approve(int $regId): void
    {
        $r = self::registration($regId);
        if (!self::status($r)['complete']) throw new AppError('The clearance is not completed yet: every line must be signed as cleared first.');
        self::tag($regId, 'FINAL', 'CLEARED');
    }

    private static function nextControl(): string
    {
        $yr = (int) date('Y');
        Db::q('INSERT INTO sis_document_counters (prefix, yr, last_no) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE last_no = last_no + 1', ['CLR', $yr]);
        return sprintf('CLR-%d-%05d', $yr, (int) Db::val('SELECT last_no FROM sis_document_counters WHERE prefix = ? AND yr = ?', ['CLR', $yr]));
    }

    public static function signatureImage(int $tagId): string
    {
        $img = Db::val('SELECT signature FROM sis_clearance_tags WHERE id = ?', [$tagId]);
        return $img ? 'data:image/png;base64,' . base64_encode((string) $img) : '';
    }

    // ------------------------------------------------------------------ the signer's list

    /**
     * Lines $account signs in a term: [ ['reg' => registration, 'item' => line with state and tag, 'balance' => cents], ... ].
     * Filtered by program, year level, state (PENDING / NOT_CLEARED / CLEARED) and a name.
     */
    public static function toSign(int $account, string $sy, string $term, array $f = []): array
    {
        $regs = self::registrations($sy, $term, (string) ($f['program'] ?? ''), (string) ($f['year'] ?? ''), (string) ($f['q'] ?? ''));
        $out = [];
        foreach (self::statuses($regs) as $id => $st) {
            foreach ($st['items'] as $it) {
                if (!self::mayTag($account, $it)) continue;
                if (($f['office'] ?? '') !== '' && $it['office'] !== $f['office']) continue;
                if (($f['state'] ?? '') !== '' && $it['state'] !== $f['state']) continue;
                $out[] = ['reg' => $st['registration'], 'item' => $it, 'final' => $st['final'], 'balance' => max(0, Registration::balance($st['registration'])),
                    'new' => (int) Db::val("SELECT COUNT(*) FROM sis_clearance_submissions WHERE registration_id = ? AND item_key = ? AND status = 'NEW'", [$id, $it['key']])];
            }
        }
        return $out;
    }

    /** Office dashboard: per line kind (office or Teachers), counts cleared / not cleared / waiting, plus completed and approved students. */
    public static function overview(string $sy, string $term, string $program = '', string $year = ''): array
    {
        $by = []; $students = ['total' => 0, 'complete' => 0, 'approved' => 0];
        foreach (self::statuses(self::registrations($sy, $term, $program, $year)) as $st) {
            $students['total']++;
            if ($st['complete']) $students['complete']++;
            if ($st['final']) $students['approved']++;
            foreach ($st['items'] as $it) {
                $k = $it['kind'] === 'SUBJECT' ? 'TEACHER' : $it['office'];
                $by[$k] ??= ['label' => $it['kind'] === 'SUBJECT' ? 'Teachers (per class)' : $it['label'], 'CLEARED' => 0, 'NOT_CLEARED' => 0, 'PENDING' => 0];
                $by[$k][$it['state']]++;
            }
        }
        return ['offices' => $by, 'students' => $students];
    }

    // ------------------------------------------------------------------ the student's side

    /** The signed-in student's confirmed enrollments that have a clearance period, newest first. */
    public static function ownRegistrations(): array
    {
        $num = Auth::studentNumber();
        if (!$num) return [];
        return Db::all("SELECT r.* FROM sis_registrations r JOIN sis_clearance_periods p ON p.school_year = r.school_year AND p.term = r.term
                        WHERE r.student_number = ? AND r.status = 'REGISTERED' AND r.locked_at IS NOT NULL ORDER BY r.school_year DESC, FIELD(r.term,'Summer','3rd Semester','2nd Semester','1st Semester')", [$num]);
    }

    public static function ownsRegistration(array $r): bool
    {
        $num = Auth::studentNumber();
        return $num !== null && $num !== '' && (string) $r['student_number'] === $num;
    }

    /** "Submit compliance online": a message and/or a photo or PDF of the proof, for one line of the student's own clearance. */
    public static function submit(int $regId, string $key, string $message, ?array $file): int
    {
        if (!self::uploads()) throw new AppError('Submitting compliance online is turned off. Settle it at the office.');
        $r = self::registration($regId);
        if (!self::ownsRegistration($r)) throw new Forbidden('This is not your clearance.');
        $item = self::findItem($r, $key);
        if ($key === 'FINAL') throw new AppError('Choose an office or subject.');
        $message = trim($message);
        $bytes = null; $name = null; $mime = null; $size = null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) throw new AppError(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'The file is too large (5 MB at most).' : 'The file did not upload. Try again.');
            $size = (int) $file['size'];
            if ($size <= 0 || $size > self::MAX_FILE) throw new AppError('The file is too large (5 MB at most).');
            $bytes = (string) file_get_contents((string) $file['tmp_name']);
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!isset(self::FILE_TYPES[$mime])) throw new AppError('Upload a photo (JPG, PNG or WebP) or a PDF.');
            $name = mb_substr(preg_replace('/[^\w .()-]+/u', '_', basename((string) $file['name'])) ?: ('proof.' . self::FILE_TYPES[$mime]), 0, 150);
        }
        if ($message === '' && $bytes === null) throw new AppError('Write a message or attach a photo or PDF of your proof.');
        $id = Db::insert('sis_clearance_submissions', ['registration_id' => $regId, 'item_key' => $key, 'message' => mb_substr($message, 0, 1000), 'file' => $bytes, 'file_name' => $name, 'file_mime' => $mime, 'file_size' => $size]);
        Audit::log('clearance_submitted', 'registration', $regId, ['line' => $item['label'], 'file' => $name]);
        return $id;
    }

    public static function submissions(int $regId, ?string $key = null): array
    {
        return Db::all('SELECT id, registration_id, item_key, message, file_name, file_mime, file_size, status, reply, replied_by, replied_at, created_at FROM sis_clearance_submissions WHERE registration_id = ?' . ($key !== null ? ' AND item_key = ?' : '') . ' ORDER BY id DESC',
            $key !== null ? [$regId, $key] : [$regId]);
    }

    /** The office or teacher answers a submission (and it's marked seen). */
    public static function reply(int $subId, string $text): void
    {
        $s = Db::one('SELECT * FROM sis_clearance_submissions WHERE id = ?', [$subId]) ?: throw new AppError('Submission not found.');
        $r = self::registration((int) $s['registration_id']);
        if (!self::mayTag(self::me(), self::findItem($r, $s['item_key']))) throw new Forbidden('You cannot answer for this line.');
        $text = trim($text);
        if ($text === '') throw new AppError('Write your reply.');
        Db::q("UPDATE sis_clearance_submissions SET status = 'REPLIED', reply = ?, replied_by = ?, replied_at = NOW() WHERE id = ?", [mb_substr($text, 0, 1000), self::me(), $subId]);
        self::notifyStudent($r, 'Clearance: reply to your submission', self::findItem($r, $s['item_key'])['label'] . ' replied: ' . $text);
    }

    public static function markSeen(int $regId, string $key): void
    {
        Db::q("UPDATE sis_clearance_submissions SET status = 'SEEN' WHERE registration_id = ? AND item_key = ? AND status = 'NEW'", [$regId, $key]);
    }

    /** A submitted file, for its student or someone who signs that line (or sees every clearance). */
    public static function file(int $subId): array
    {
        $s = Db::one('SELECT * FROM sis_clearance_submissions WHERE id = ?', [$subId]);
        if (!$s || $s['file'] === null) throw new AppError('File not found.');
        $r = self::registration((int) $s['registration_id']);
        $me = self::me();
        $ok = self::ownsRegistration($r) || self::isPower($me) || Auth::can('clearance');
        if (!$ok) { try { $ok = self::mayTag($me, self::findItem($r, $s['item_key'])); } catch (AppError) { $ok = false; } }
        if (!$ok) throw new Forbidden('You cannot open this file.');
        return $s;
    }

    /** New submissions waiting for lines this account signs. */
    public static function newCount(int $account): int
    {
        if (!self::on() || !self::canSign($account)) return 0;
        $n = 0;
        foreach (Db::all("SELECT s.registration_id, s.item_key, r.* FROM sis_clearance_submissions s JOIN sis_registrations r ON r.id = s.registration_id WHERE s.status = 'NEW' LIMIT 500") as $row) {
            try { if (self::mayTag($account, self::findItem($row + ['id' => $row['registration_id']], $row['item_key']))) $n++; } catch (AppError) {}
        }
        return $n;
    }

    /** In-app notice to the student, and an email when email sending is set up (best effort). */
    private static function notifyStudent(array $r, string $title, string $message): void
    {
        $acct = (int) Db::val('SELECT account_id FROM central_person_links WHERE student_number = ?', [$r['student_number']]);
        if (!$acct) return;
        $uid = Auth::central()->legacyId($acct, 'SIS');
        if ($uid) Db::insert('sis_notifications', ['recipient_user_id' => (int) $uid, 'type' => 'CLEARANCE', 'title' => $title, 'message' => mb_substr($message, 0, 1000), 'entity_type' => 'registration', 'entity_id' => (string) $r['id'], 'is_read' => 0]);
        $email = AccountEmail::forReset($acct);
        if ($email !== '' && !Mailer::missing()) {
            try { Mailer::send($email, (string) $r['student_name'], $title, '<p>' . h($message) . '</p><p>See your clearance in the Student Portal → Clearance.</p>'); }
            catch (AppError $e) { error_log('Clearance email failed: ' . $e->getMessage()); }
        }
    }

    public static function notices(int $sisUserId, int $limit = 8): array
    {
        return Db::all("SELECT * FROM sis_notifications WHERE recipient_user_id = ? AND type = 'CLEARANCE' ORDER BY id DESC LIMIT " . max(1, min(50, $limit)), [$sisUserId]);
    }

    // ------------------------------------------------------------------ before the next enrollment

    /** The student's latest confirmed enrollment before this term that had a clearance period, or null. */
    public static function previous(string $studentNumber, string $sy, string $term): ?array
    {
        $ord = fn(string $t) => self::TERM_ORDER[$t] ?? 9;
        $best = null;
        foreach (Db::all("SELECT r.* FROM sis_registrations r JOIN sis_clearance_periods p ON p.school_year = r.school_year AND p.term = r.term
                          WHERE r.student_number = ? AND r.status = 'REGISTERED' AND r.locked_at IS NOT NULL", [$studentNumber]) as $r) {
            if (([$r['school_year'], $ord($r['term'])] <=> [$sy, $ord($term)]) >= 0) continue;
            if (!$best || ([$r['school_year'], $ord($r['term'])] <=> [$best['school_year'], $ord($best['term'])]) > 0) $best = $r;
        }
        return $best;
    }

    /** Clearance needed before enrolling in $sy $term: null when not required, else the earlier term's clearance status. */
    public static function requirement(string $studentNumber, string $sy, string $term): ?array
    {
        if (!self::required()) return null;
        $prev = self::previous($studentNumber, $sy, $term);
        return $prev ? self::status($prev) : null;
    }

    /** The registrar confirms enrollment although the clearance isn't completed: a reason and their password, logged. */
    public static function override(int $regId, array $need, string $reason, string $password): void
    {
        $me = self::me();
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) throw new AppError("The clearance of {$need['registration']['school_year']} {$need['registration']['term']} is not completed:\n• " . implode("\n• ", $need['pending']) . "\nTo confirm anyway, type the reason and your password.");
        if (!Auth::central()->verifyPassword($me, $password)) throw new AppError('Your password is not correct. The enrollment was not confirmed.');
        Db::insert('sis_clearance_overrides', ['registration_id' => $regId, 'cleared_registration_id' => (int) $need['registration']['id'], 'pending' => mb_substr(implode('; ', $need['pending']), 0, 1000), 'reason' => mb_substr($reason, 0, 255), 'created_by' => $me]);
        Audit::log('clearance_overridden', 'registration', $regId, ['term' => $need['registration']['school_year'] . ' ' . $need['registration']['term'], 'pending' => $need['pending'], 'reason' => $reason]);
    }

    /** Enrollments confirmed without a completed clearance, for a registration or a student. */
    public static function overrides(?int $regId = null, ?string $studentNumber = null): array
    {
        $sql = 'SELECT o.*, r.school_year, r.term, c.school_year AS cleared_sy, c.term AS cleared_term, a.display_name AS by_name FROM sis_clearance_overrides o
                JOIN sis_registrations r ON r.id = o.registration_id JOIN sis_registrations c ON c.id = o.cleared_registration_id LEFT JOIN central_accounts a ON a.id = o.created_by WHERE ';
        return $regId !== null ? Db::all($sql . 'o.registration_id = ? ORDER BY o.id DESC', [$regId]) : Db::all($sql . 'r.student_number = ? ORDER BY o.id DESC', [(string) $studentNumber]);
    }
}
