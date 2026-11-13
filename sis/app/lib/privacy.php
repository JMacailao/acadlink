<?php
declare(strict_types=1);

/**
 * v62 Data Privacy Act of 2012 (RA 10173) helpers:
 * - the school's privacy notice (editable in SIS Settings), shown at admission and in the Student Portal;
 * - consent records (who agreed, to which version of the notice, when, recorded by whom);
 * - "Download my data" for students (one JSON file of what AcadLink holds about them);
 * - a retention review: students whose last enrolment ended N years ago (default 5) are listed for the registrar.
 *   Nothing is ever deleted automatically; the registrar records "keep" with a note.
 */
final class Privacy
{
    public const DEFAULT_YEARS = 5;

    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS sis_privacy_consents (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, subject_type ENUM('ADMISSION','STUDENT','ACCOUNT') NOT NULL, subject_ref VARCHAR(191) NOT NULL,
            given_by ENUM('APPLICANT','PARENT','STUDENT') NOT NULL, notice_version CHAR(12) NOT NULL, recorded_by INT UNSIGNED NULL, ip VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_privacy_subject (subject_type, subject_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS sis_privacy_reviews (
            student_number VARCHAR(40) NOT NULL PRIMARY KEY, decision ENUM('KEEP') NOT NULL DEFAULT 'KEEP', note VARCHAR(255) NULL,
            reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // ------------------------------------------------------------------ the notice

    public static function defaultNotice(): string
    {
        $s = School::brand();
        return "$s collects your personal information (name, birth date, contact details, photo, guardian details, grades, attendance, payments and library and canteen records) to admit and enrol you, keep your academic records, bill and receive payments, and run school services.\n\n"
            . "Only school staff whose work needs it can see your information. It is not sold or shared, except with government agencies when the law requires (for example CHED, DepEd or TESDA reports).\n\n"
            . "We keep your records while you are a student and afterwards for as long as school and government rules require. Old records are reviewed regularly.\n\n"
            . "Under the Data Privacy Act of 2012 (RA 10173) you may ask to see, correct or get a copy of your information, and object to its use or ask for it to be erased where the law allows. Students can download a copy from the Student Portal (My profile → Download my data). For questions, contact the school's Data Protection Officer at the Registrar's office.";
    }

    public static function notice(): string
    {
        $v = (string) (Db::val("SELECT svalue FROM sis_settings WHERE skey = 'privacy_notice'") ?: '');
        return $v !== '' ? $v : self::defaultNotice();
    }

    public static function version(): string { return substr(sha1(self::notice()), 0, 12); }

    public static function saveNotice(string $text, int $years): void
    {
        Auth::require('settings.manage');
        $text = trim(str_replace("\r\n", "\n", $text));
        if (mb_strlen($text) > 8000) throw new AppError('The privacy notice can be up to 8,000 characters.');
        if ($years < 1 || $years > 50) throw new AppError('Keep records for 1 to 50 years before review.');
        foreach (['privacy_notice' => $text === self::defaultNotice() ? '' : $text, 'privacy_retention_years' => (string) $years] as $k => $v)
            Db::q('INSERT INTO sis_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$k, $v]);
        Audit::log('privacy_settings_saved', 'settings', 0, ['version' => self::version(), 'years' => $years]);
    }

    public static function noticeHtml(): string
    {
        $paras = array_filter(array_map('trim', preg_split("/\n{2,}/", self::notice()) ?: []));
        return '<div class="privacy-notice" tabindex="0" aria-label="Privacy notice"><h3>Privacy notice</h3>' . implode('', array_map(fn($p) => '<p class="mb-2">' . nl2br(h($p)) . '</p>', $paras)) . '</div>';
    }

    // ------------------------------------------------------------------ consent

    public static function recordConsent(string $type, string $ref, string $by): void
    {
        $by = in_array($by, ['APPLICANT', 'PARENT', 'STUDENT'], true) ? $by : 'APPLICANT';
        Db::insert('sis_privacy_consents', ['subject_type' => $type, 'subject_ref' => mb_substr($ref, 0, 191), 'given_by' => $by, 'notice_version' => self::version(),
            'recorded_by' => Auth::id(), 'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64)]);
        Audit::log('privacy_consent_recorded', strtolower($type), 0, ['ref' => $ref, 'by' => $by, 'version' => self::version()]);
    }

    /** The latest consent of a student (through their admission or their own), or null. */
    public static function consentOf(string $studentNumber): ?array
    {
        return Db::one("SELECT c.* FROM sis_privacy_consents c WHERE (c.subject_type = 'STUDENT' AND c.subject_ref = ?)
            OR (c.subject_type = 'ADMISSION' AND c.subject_ref IN (SELECT CAST(id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci FROM sis_admissions WHERE student_number = ?)) ORDER BY c.id DESC LIMIT 1", [$studentNumber, $studentNumber]) ?: null;
    }

    // ------------------------------------------------------------------ download my data

    /** Everything AcadLink holds about the signed-in student, as one array (sent as a JSON file). */
    public static function myData(): array
    {
        $no = (string) Auth::studentNumber();
        if ($no === '') throw new Forbidden('Only students can download their data here.');
        $a = Auth::central()->current();
        $s = GS::studentByNumber($no) ?: [];
        $sid = (string) ($s['id'] ?? '');
        $strip = fn(array $r) => array_diff_key($r, array_flip(['password_hash', 'bytes', 'temp_hash']));
        $grades = array_values(array_map(fn($g) => array_intersect_key($g, array_flip(['schoolYear', 'term', 'subjectCode', 'subjectName', 'units', 'finalGrade', 'remarks', 'sectionName', 'teacherName'])),
            array_filter(GS::all('grades'), fn($g) => ($g['studentId'] ?? '') === $sid && ($g['draft'] ?? false) !== true)));
        $out = [
            'exported_at' => date('c'), 'school' => School::brand(), 'notice_version' => self::version(),
            'account' => ['username' => $a['username'] ?? null, 'display_name' => $a['display_name'] ?? null, 'email' => AccountEmail::get((int) ($a['id'] ?? 0)) ?: null, 'last_login_at' => $a['last_login_at'] ?? null],
            'student_record' => array_diff_key($s, array_flip(['studentIds'])),
            'personal_details' => $sid !== '' ? StudentDetails::get($sid) : null,
            'admissions' => array_map($strip, Db::all('SELECT * FROM sis_admissions WHERE student_number = ? ORDER BY id', [$no])),
            'registrations' => array_map($strip, Db::all('SELECT * FROM sis_registrations WHERE student_number = ? ORDER BY id', [$no])),
            'payments' => array_map($strip, Db::all('SELECT p.receipt_no, p.amount, p.method, p.reference_no, p.status, p.paid_at, r.school_year, r.term FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE r.student_number = ? ORDER BY p.id', [$no])),
            'grades' => $grades,
            'consents' => array_map($strip, Db::all("SELECT subject_type, given_by, notice_version, created_at FROM sis_privacy_consents WHERE (subject_type = 'STUDENT' AND subject_ref = ?) OR (subject_type = 'ADMISSION' AND subject_ref IN (SELECT CAST(id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci FROM sis_admissions WHERE student_number = ?))", [$no, $no])),
            'photos' => Db::all('SELECT id, status, source, created_at, decided_at FROM sis_student_photos WHERE student_number = ? ORDER BY id', [$no]),
        ];
        $t = array_flip(Db::col('SHOW TABLES'));
        if ($sid !== '' && isset($t['lms_submissions'])) {
            $out['lms_work'] = Db::all('SELECT w.title, w.kind, w.category, w.points, s.status, s.submitted_at, s.is_late, s.score, s.feedback, s.text_answer, s.link_url FROM lms_submissions s JOIN lms_work w ON w.id = s.work_id WHERE s.student_doc_id = ? ORDER BY s.id', [$sid]);
            $out['lms_attendance'] = Db::all('SELECT s.class_id, s.meeting_date, s.label, a.status, a.note FROM lms_attendance a JOIN lms_attendance_sessions s ON s.id = a.session_id WHERE a.student_doc_id = ? ORDER BY s.meeting_date', [$sid]);
            $out['lms_messages'] = Db::all('SELECT class_id, from_teacher, sender_name, body, created_at FROM lms_messages WHERE student_doc_id = ? ORDER BY id', [$sid]);
        }
        if ($sid !== '' && isset($t['library_loans'])) {
            $out['library_loans'] = Db::all("SELECT t.title, l.loaned_at, l.due_on, l.returned_at, l.status FROM library_loans l JOIN library_copies c ON c.id = l.copy_id JOIN library_titles t ON t.id = c.title_id WHERE l.borrower_type = 'STUDENT' AND l.borrower_ref = ? ORDER BY l.id", [$sid]);
            $out['library_fines'] = Db::all("SELECT * FROM library_fines WHERE borrower_type = 'STUDENT' AND borrower_ref = ? ORDER BY id", [$sid]);
        }
        $out['notifications'] = Db::all('SELECT type, title, message, created_at, is_read FROM sis_notifications WHERE recipient_user_id = ? ORDER BY id', [(int) Auth::id()]);
        Audit::log('privacy_data_exported', 'student', 0, ['student_number' => $no]);
        return $out;
    }

    // ------------------------------------------------------------------ retention review

    public static function years(): int
    {
        $v = (int) (Db::val("SELECT svalue FROM sis_settings WHERE skey = 'privacy_retention_years'") ?: 0);
        return $v > 0 ? $v : self::DEFAULT_YEARS;
    }

    /**
     * Students whose last registration's school year ended at least N years ago, not yet reviewed after that.
     * [student_number, name, last_school_year, years_ago, reviewed_at, note]
     */
    public static function dueForReview(int $limit = 500): array
    {
        $years = self::years(); $now = (int) date('Y');
        $rows = Db::all("SELECT r.student_number, MAX(r.school_year) last_sy FROM sis_registrations r WHERE r.status <> 'CANCELLED' GROUP BY r.student_number");
        $out = [];
        foreach ($rows as $r) {
            $end = (int) substr((string) $r['last_sy'], -4);
            if (!$end || $now - $end < $years) continue;
            $rev = Db::one('SELECT * FROM sis_privacy_reviews WHERE student_number = ?', [$r['student_number']]);
            if ($rev && (int) substr((string) $rev['reviewed_at'], 0, 4) >= $now) continue; // reviewed this year
            $s = GS::studentByNumber((string) $r['student_number']);
            $out[] = ['student_number' => $r['student_number'], 'name' => (string) ($s['studentName'] ?? '—'), 'last_school_year' => $r['last_sy'], 'years_ago' => $now - $end,
                'reviewed_at' => $rev['reviewed_at'] ?? null, 'note' => $rev['note'] ?? null];
            if (count($out) >= $limit) break;
        }
        usort($out, fn($a, $b) => $b['years_ago'] <=> $a['years_ago'] ?: strcmp($a['name'], $b['name']));
        return $out;
    }

    public static function markReviewed(array $studentNumbers, string $note): int
    {
        Auth::require('students.edit');
        $note = mb_substr(trim($note), 0, 255); $n = 0;
        foreach (array_unique(array_filter(array_map('strval', $studentNumbers))) as $no) {
            Db::q('INSERT INTO sis_privacy_reviews (student_number, note, reviewed_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE note = VALUES(note), reviewed_by = VALUES(reviewed_by), reviewed_at = NOW()', [$no, $note !== '' ? $note : null, Auth::id()]);
            $n++;
        }
        if ($n) Audit::log('privacy_records_reviewed', 'student', 0, ['count' => $n, 'note' => $note]);
        return $n;
    }
}
