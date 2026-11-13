<?php
declare(strict_types=1);

/**
 * v62 Parent / Guardian accounts. The Registrar creates a Parent account (or reuses one) and links it to one or more
 * students. A parent signs in to the Student Portal and sees, read-only, only their linked children: final grades
 * (with the same visibility rules as the student), LMS class work and attendance, balance and receipts.
 */
final class ParentAccess
{
    public const RELATIONSHIPS = ['Mother', 'Father', 'Guardian', 'Grandparent', 'Sibling', 'Other'];

    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS central_parent_links (
            parent_account_id BIGINT UNSIGNED NOT NULL, student_number VARCHAR(40) NOT NULL, relationship VARCHAR(40) NOT NULL DEFAULT 'Guardian',
            created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (parent_account_id, student_number), KEY k_parent_student (student_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function isParent(): bool
    {
        $id = Auth::centralId();
        return $id && Auth::central()->group($id) === 'PARENT';
    }

    /** The signed-in parent's children: [student_number, student (Grading record), relationship]. */
    public static function children(): array
    {
        $id = Auth::centralId();
        if (!$id || Auth::central()->group($id) !== 'PARENT') return [];
        $out = [];
        foreach (Db::all('SELECT student_number, relationship FROM central_parent_links WHERE parent_account_id = ? ORDER BY created_at', [$id]) as $r) {
            $s = GS::studentByNumber((string) $r['student_number']);
            if ($s) $out[] = ['student_number' => (string) $r['student_number'], 'student' => $s, 'relationship' => (string) $r['relationship']];
        }
        return $out;
    }

    /** One child of the signed-in parent, or refuses. */
    public static function child(string $studentNumber): array
    {
        foreach (self::children() as $c) if ($c['student_number'] === $studentNumber) return $c;
        throw new Forbidden('This student is not linked to your account.');
    }

    /** Parent accounts linked to a student (for the Registrar). */
    public static function parentsOf(string $studentNumber): array
    {
        return Db::all("SELECT l.parent_account_id AS id, l.relationship, l.created_at, a.display_name, a.status, a.last_login_at, i.identifier AS username
            FROM central_parent_links l JOIN central_accounts a ON a.id = l.parent_account_id LEFT JOIN central_login_identifiers i ON i.account_id = a.id AND i.is_primary = 1
            WHERE l.student_number = ? ORDER BY l.created_at", [$studentNumber]);
    }

    private static function requireRegistrar(): void
    {
        Auth::require('students.edit');
    }

    private static function relationship(string $r): string
    {
        return in_array($r, self::RELATIONSHIPS, true) ? $r : 'Guardian';
    }

    /** Create a new Parent account and link it. Returns the account id. */
    public static function create(string $studentNumber, array $in): int
    {
        self::requireRegistrar();
        if (!GS::studentByNumber($studentNumber)) throw new AppError('Student not found.');
        $c = Auth::central();
        try {
            $id = $c->saveParent((int) Auth::centralId(), ['full_name' => (string) ($in['full_name'] ?? ''), 'username' => (string) ($in['username'] ?? ''),
                'status' => 'ACTIVE', 'temp_password' => (string) ($in['temp_password'] ?? '')]);
        } catch (CentralAccountError $e) { throw new AppError($e->getMessage()); }
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '') AccountEmail::set($id, $email, (int) Auth::centralId());
        self::linkAccount($id, $studentNumber, (string) ($in['relationship'] ?? ''));
        return $id;
    }

    /** Link an existing Parent account (by username) to a student. */
    public static function link(string $studentNumber, string $username, string $relationship): int
    {
        self::requireRegistrar();
        if (!GS::studentByNumber($studentNumber)) throw new AppError('Student not found.');
        $id = (int) Db::val('SELECT account_id FROM central_login_identifiers WHERE identifier_key = ? AND verified_at IS NOT NULL', [strtolower(trim($username))]);
        if (!$id) throw new AppError('No account has that username.');
        if (Auth::central()->group($id) !== 'PARENT') throw new AppError('That account is not a Parent / Guardian account.');
        self::linkAccount($id, $studentNumber, $relationship);
        return $id;
    }

    private static function linkAccount(int $accountId, string $studentNumber, string $relationship): void
    {
        Db::q('INSERT INTO central_parent_links (parent_account_id, student_number, relationship, created_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE relationship = VALUES(relationship)',
            [$accountId, $studentNumber, self::relationship($relationship), Auth::id()]);
        Audit::log('parent_linked', 'student', 0, ['student_number' => $studentNumber, 'parent_account' => $accountId, 'relationship' => self::relationship($relationship)]);
    }

    public static function unlink(string $studentNumber, int $accountId): void
    {
        self::requireRegistrar();
        if (!Db::q('DELETE FROM central_parent_links WHERE parent_account_id = ? AND student_number = ?', [$accountId, $studentNumber])->rowCount()) throw new AppError('That parent is not linked to this student.');
        Audit::log('parent_unlinked', 'student', 0, ['student_number' => $studentNumber, 'parent_account' => $accountId]);
    }

    // ------------------------------------------------------------------ what a parent (or the student) sees

    /**
     * Final grades the student may see: tagged complete, and (when the school holds grades for unpaid balances) only
     * terms with no balance. Returns [completions, held periods].
     */
    public static function visibleCompletions(string $studentNumber, ?array $student): array
    {
        $completions = $student ? GS::completions((string) $student['id']) : [];
        $held = [];
        if (Settings::on('portal_hide_grades_unpaid')) {
            $completions = array_values(array_filter($completions, function ($c) use ($studentNumber, &$held) {
                $owed = 0;
                foreach (Db::all("SELECT * FROM sis_registrations WHERE student_number = ? AND school_year = ? AND status <> 'CANCELLED'" . (($c['term'] ?? '') !== '' ? ' AND term = ?' : ''),
                    array_merge([$studentNumber, (string) ($c['schoolYear'] ?? '')], ($c['term'] ?? '') !== '' ? [$c['term']] : [])) as $r) $owed += max(0, Registration::balance($r));
                if ($owed > 0) { $held[] = GS::completionPeriod($c) . ' (balance ₱' . Money::fmt($owed) . ')'; return false; }
                return true;
            }));
        }
        return [$completions, $held];
    }

    /** Everything on a child's page. */
    public static function summary(string $studentNumber): array
    {
        $c = self::child($studentNumber);
        $s = $c['student']; $sid = (string) $s['id'];
        [$completions, $held] = self::visibleCompletions($studentNumber, $s);
        $regs = Db::all("SELECT * FROM sis_registrations WHERE student_number = ? AND status = 'REGISTERED' ORDER BY school_year DESC, id DESC", [$studentNumber]);
        $balance = 0; foreach ($regs as $r) $balance += max(0, Registration::balance($r));
        $pays = Db::all("SELECT p.id, p.receipt_no, p.amount, p.method, p.status, p.paid_at FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE r.student_number = ? ORDER BY p.paid_at DESC LIMIT 50", [$studentNumber]);
        $classes = [];
        $t = array_flip(Db::col('SHOW TABLES'));
        if (isset($t['lms_work'])) {
            $sy = GS::schoolYears()[0] ?? '';
            foreach (GS::all('gradingAssignments') as $a) {
                if (($a['schoolYear'] ?? '') !== $sy || !in_array($sid, array_map('strval', (array) ($a['studentIds'] ?? [])), true)) continue;
                $cid = (string) $a['id'];
                $att = Db::one("SELECT SUM(a.status = 'PRESENT') p, SUM(a.status = 'LATE') l, SUM(a.status = 'ABSENT') ab, SUM(a.status = 'EXCUSED') e, COUNT(*) n FROM lms_attendance a JOIN lms_attendance_sessions s ON s.id = a.session_id WHERE s.class_id = ? AND a.student_doc_id = ?", [$cid, $sid]);
                $work = Db::one("SELECT COUNT(*) n, SUM(s.status = 'RETURNED') returned, SUM(s.status IS NULL AND w.due_at < NOW()) missing FROM lms_work w LEFT JOIN lms_submissions s ON s.work_id = w.id AND s.student_doc_id = ? WHERE w.class_id = ? AND w.status = 'PUBLISHED' AND w.deleted_at IS NULL", [$sid, $cid]);
                $book = Lms::gradebook(['id' => $cid, 'studentIds' => [$sid]] + self::classShape($a));
                $classes[] = ['code' => (string) ($a['subjectCode'] ?? ''), 'name' => (string) ($a['subjectName'] ?? ''), 'teacher' => (string) ($a['teacherName'] ?? ''), 'term' => (string) ($a['term'] ?? ''),
                    'percent' => $book['rows'][$sid]['percent'] ?? null, 'works' => (int) $work['n'], 'returned' => (int) $work['returned'], 'missing' => (int) $work['missing'],
                    'present' => (int) $att['p'], 'late' => (int) $att['l'], 'absent' => (int) $att['ab'], 'excused' => (int) $att['e'], 'meetings' => (int) $att['n']];
            }
        }
        Audit::log('parent_viewed_child', 'student', 0, ['student_number' => $studentNumber]);
        return ['child' => $c, 'completions' => $completions, 'held' => $held, 'registrations' => $regs, 'balance' => $balance, 'payments' => $pays, 'classes' => $classes,
            'photo' => StudentPhoto::currentId($studentNumber)];
    }

    private static function classShape(array $a): array
    {
        return ['subjectCode' => (string) ($a['subjectCode'] ?? ''), 'subjectName' => (string) ($a['subjectName'] ?? ''), 'sectionName' => (string) ($a['sectionName'] ?? ''), 'teacherName' => (string) ($a['teacherName'] ?? ''),
            'schoolYear' => (string) ($a['schoolYear'] ?? ''), 'term' => (string) ($a['term'] ?? ''), 'role' => 'ADMIN'];
    }
}
