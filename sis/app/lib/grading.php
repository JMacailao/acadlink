<?php
/**
 * The grading system's records (same database, table `docs`): students, sections, teachers,
 * subjects and curriculum. The SIS reads and edits THESE records, so both systems always show
 * the same data. Record shapes follow the grading system exactly:
 *
 *   students    { studentId, studentName, email, schoolYear, yearLevel, sectionId, sectionName, status? }
 *   sections    { schoolYear, yearLevel, sectionName }          (name unique per school year)
 *   teachers    { teacherId, teacherName }
 *   subjects    { subjectCode, subjectName, units }
 *   curriculum  { program, yearLevel, term, subjectIds[] }       (id = program-year-term slug)
 *
 * Like the grading system, renaming a section / teacher / subject also updates the copies of
 * the name kept on students, grading assignments and grades.
 */
declare(strict_types=1);

final class GS
{
    /** v56: what the last curriculum save did: ['created', 'updated' (teachers), 'kept' (classes with submitted grades)]. */
    public static array $lastCurriculumSync = ['created' => 0, 'updated' => 0, 'kept' => 0];
    // ------------------------------------------------------------ basics
    public static function available(): bool
    {
        static $ok = null;
        return $ok ??= (bool) Db::val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'docs'");
    }

    /** A new record id like the grading system's (20 letters and digits). */
    public static function newId(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $id = '';
        for ($i = 0; $i < 20; $i++) $id .= $chars[random_int(0, 61)];
        return $id;
    }

    /** Timestamp in the grading system's stored form. */
    public static function ts(): array
    {
        $t = microtime(true);
        return ['__ts' => gmdate('Y-m-d\TH:i:s', (int) $t) . sprintf('.%03dZ', (int) (($t - floor($t)) * 1000))];
    }

    public static function get(string $col, string $id): ?array
    {
        $raw = Db::val('SELECT data FROM docs WHERE col = ? AND id = ?', [$col, $id]);
        if ($raw === null) return null;
        return ['id' => $id] + (json_decode((string) $raw, true) ?: []);
    }

    /** Every record of a collection (with 'id'). */
    public static function all(string $col): array
    {
        $out = [];
        foreach (Db::all('SELECT id, data FROM docs WHERE col = ?', [$col]) as $r) $out[] = ['id' => $r['id']] + (json_decode((string) $r['data'], true) ?: []);
        return $out;
    }

    private static function put(string $col, string $id, array $data): void
    {
        unset($data['id']);
        Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)',
            [$col, $id, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    /** Set fields on every record of $cols whose $field equals $value (keeps the grading system's copies in step). */
    private static function cascade(array $cols, string $field, string $value, array $patch): int
    {
        $n = 0;
        $indexed = ['sectionId' => 'f_sectionId', 'teacherDocId' => 'f_teacherDocId', 'studentId' => 'f_studentId'];
        $where = isset($indexed[$field]) ? "{$indexed[$field]} = ?" : "JSON_UNQUOTE(JSON_EXTRACT(data, '$.$field')) = ?";
        foreach (Db::all('SELECT col, id, data FROM docs WHERE col IN ' . Db::in($cols) . " AND $where", array_merge($cols, [$value])) as $r) {
            $d = json_decode((string) $r['data'], true) ?: [];
            self::put($r['col'], $r['id'], array_merge($d, $patch, ['updatedAt' => self::ts()]));
            $n++;
        }
        return $n;
    }

    /** "BSIT 1A" → "BSIT" (text before the first digit), as the grading system reads programs. */
    public static function programOf(string $sectionName): string
    {
        return preg_match('/^([^\d]*)\d/', $sectionName, $m) ? strtoupper(trim(rtrim($m[1], " -–_."))) : '';
    }

    public static function curriculumId(string $program, string $yearLevel, string $term): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower("$program $yearLevel " . ($term ?: 'no-term'))), '-');
    }

    public static function validSchoolYear(string $sy): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{4})$/', $sy, $m) && (int) $m[2] === (int) $m[1] + 1;
    }

    /** Next number after the highest one in use (keeps the school's prefix and width), like the grading system. */
    public static function nextNumber(array $existing, string $start = ''): string
    {
        $best = null;
        foreach ($existing as $raw) {
            if (!preg_match('/^(\D*?)(\d+)$/', trim((string) $raw), $m)) continue;
            if (!$best || strlen(ltrim($m[2], '0')) > strlen(ltrim($best[1], '0')) || (strlen(ltrim($m[2], '0')) === strlen(ltrim($best[1], '0')) && strcmp(ltrim($m[2], '0'), ltrim($best[1], '0')) > 0)) {
                $best = [$m[1], $m[2]];
            }
        }
        if (!$best) $best = preg_match('/^\d{4}/', $start, $y) ? ['', $y[0] . '0000'] : ['', '0'];
        $taken = array_flip(array_map(fn($v) => trim((string) $v), $existing));
        [$prefix, $digits] = $best;
        $n = $digits;
        do {
            $n = self::increment($n);
            $id = $prefix . str_pad($n, strlen($digits), '0', STR_PAD_LEFT);
        } while (isset($taken[$id]));
        return $id;
    }

    private static function increment(string $digits): string
    {
        $d = str_split(ltrim($digits, '0') ?: '0');
        for ($i = count($d) - 1; $i >= 0; $i--) {
            if ($d[$i] !== '9') { $d[$i] = (string) ((int) $d[$i] + 1); return implode('', $d); }
            $d[$i] = '0';
        }
        return '1' . implode('', $d);
    }

    // ------------------------------------------------------------ students
    public static function students(): array
    {
        $list = self::all('students');
        usort($list, fn($a, $b) => strcasecmp((string) ($a['studentName'] ?? ''), (string) ($b['studentName'] ?? '')));
        return $list;
    }

    /** The student's record (the grading system keeps one per student and moves it each school year). */
    public static function studentByNumber(string $number): ?array
    {
        $rows = Db::all("SELECT id, data FROM docs WHERE col = 'students' AND f_studentId = ?", [$number]);
        $best = null;
        foreach ($rows as $r) {
            $d = ['id' => $r['id']] + (json_decode((string) $r['data'], true) ?: []);
            if (!$best || strcmp((string) ($d['schoolYear'] ?? ''), (string) ($best['schoolYear'] ?? '')) > 0) $best = $d;
        }
        return $best;
    }

    /** Students whose name or Student ID contains $q (any order of words: "ben cruz" finds "Cruz, Ben L."). */
    public static function findStudents(string $q, int $limit = 30): array
    {
        $words = array_values(array_filter(preg_split('/[\s,]+/', mb_strtolower(trim($q))) ?: [], fn($w) => $w !== ''));
        if (!$words) return [];
        $where = []; $args = [];
        foreach ($words as $w) {
            $where[] = "(LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, '$.studentName'))) LIKE ? OR f_studentId LIKE ?)";
            array_push($args, "%$w%", "%$w%");
        }
        $out = [];
        foreach (Db::all("SELECT id, data FROM docs WHERE col = 'students' AND " . implode(' AND ', $where) . ' LIMIT ' . max(1, $limit), $args) as $r) {
            $out[] = ['id' => $r['id']] + (json_decode((string) $r['data'], true) ?: []);
        }
        usort($out, fn($a, $b) => strcasecmp((string) ($a['studentName'] ?? ''), (string) ($b['studentName'] ?? '')));
        return $out;
    }

    public static function nextStudentNumber(string $schoolYear): string
    {
        $used = Db::col("SELECT f_studentId FROM docs WHERE col = 'students' AND f_studentId IS NOT NULL");
        $used = array_merge($used, Db::col('SELECT student_number FROM sis_admissions WHERE student_number IS NOT NULL'));
        return self::nextNumber($used, $schoolYear);
    }

    /** Add or edit a student. $in: studentId (optional for new), studentName, email, sectionId. Returns the record id. */
    /** A short fingerprint of a record as it is now: an edit form sends it back so a change made meanwhile is noticed. */
    public static function version(string $col, string $id): string
    {
        return (string) Db::val('SELECT MD5(data) FROM docs WHERE col = ? AND id = ?', [$col, $id]);
    }

    public static function saveStudent(?string $id, array $in): string
    {
        Auth::require('students.edit', 'registration');
        $name = trim(preg_replace('/\s+/', ' ', (string) ($in['studentName'] ?? '')));
        if ($name === '' || mb_strlen($name) > 150) throw new AppError("Enter the student's name (Last, First M.).");
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('Enter a valid email address, or leave it empty.');
        $section = self::get('sections', (string) ($in['sectionId'] ?? ''));
        if (!$section) throw new AppError('Choose a section.');
        $number = trim((string) ($in['studentId'] ?? ''));
        $version = is_string($in['_version'] ?? null) ? $in['_version'] : '';
        return Db::tx(function () use ($id, $name, $email, $section, $number, $version) {
            Db::val("SELECT COUNT(*) FROM docs WHERE col = 'students' FOR UPDATE");
            $before = $id ? self::get('students', $id) : null;
            if ($id && !$before) throw new AppError('Student not found.');
            // Two people editing the same student: the second save does not silently undo the first
            if ($id && $version !== '' && !hash_equals(self::version('students', $id), $version))
                throw new AppError('Someone else changed this student after you opened the page. Your changes were NOT saved. They are still in the form: check the current details on the page, then save again.');
            if ($number === '') $number = $before['studentId'] ?? self::nextStudentNumber((string) $section['schoolYear']);
            if (mb_strlen($number) > 40) throw new AppError('Student ID is too long.');
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $number)) throw new AppError('Use only letters, numbers, dots, dashes or slashes in the Student ID.');
            // Unique regardless of upper/lower case, so 2026-ab1 and 2026-AB1 can't both exist.
            $dup = Db::val("SELECT id FROM docs WHERE col = 'students' AND UPPER(f_studentId) = UPPER(?) AND id <> ?", [$number, $id ?? '']);
            if ($dup) throw new AppError("Student ID $number is already used.");
            $data = array_merge($before ?? ['createdAt' => self::ts()], [
                'studentId' => $number, 'studentName' => $name, 'email' => $email,
                'schoolYear' => $section['schoolYear'], 'yearLevel' => $section['yearLevel'],
                'sectionId' => $section['id'], 'sectionName' => $section['sectionName'], 'updatedAt' => self::ts(),
            ]);
            $id ??= self::newId();
            StudentHistoryStore::capture(Db::pdo(),$id,$before);
            self::put('students', $id, $data);
            StudentHistoryStore::capture(Db::pdo(),$id,$data);
            if ($before && ($before['studentId'] !== $number || $before['studentName'] !== $name)) {
                // grades keep a copy of the student's number and name
                self::cascade(['grades', 'gradeCompletions'], 'studentId', $id, ['studentNumber' => $number, 'studentName' => $name]);
                Db::q('UPDATE sis_registrations SET student_number = ?, student_name = ? WHERE student_doc_id = ?', [$number, $name, $id]);
                // A new Student ID follows the student everywhere it is stored, so no portal account or request keeps the old one.
                if ($before['studentId'] !== $number) self::renumberSis($id, (string) $before['studentId'], $number);
            }
            Audit::log($before ? 'student_updated' : 'student_added', 'grading_student', $id, ['studentId' => $number, 'name' => $name, 'section' => $section['sectionName']]);
            return $id;
        });
    }

    /** Mark a promoted student as handed off to SIS registration. */
    public static function clearPromotion(string $id): void
    {
        $student = self::get('students', $id);
        if (!$student || ($student['promotionState'] ?? '') !== 'PROMOTED_PENDING_REGISTRATION') return;
        unset($student['promotionState'], $student['promotedFromSchoolYear'], $student['promotedAt']);
        self::put('students', $id, $student);
    }

    // ------------------------------------------------------------ final grades tagged complete
    /**
     * Final grades the registrar tagged Complete on the grading system's Final Grades page
     * (collection gradeCompletions: one per student, school year and term, with a copy of the grades).
     * Newest school year and term first.
     */
    public static function completions(string $studentDocId): array
    {
        $list = [];
        foreach (Db::all("SELECT id, data FROM docs WHERE col = 'gradeCompletions' AND f_studentId = ?", [$studentDocId]) as $r) {
            $data=json_decode((string)$r['data'],true)?:[];
            if(!empty($data['migrationReversed']))continue;
            $list[] = ['id' => $r['id']] + $data;
        }
        $order = fn(array $c) => ($i = array_search($c['term'] ?? '', TERMS, true)) === false ? (($c['term'] ?? '') === '' ? 9 : 8) : $i;
        usort($list, fn($a, $b) => strcmp((string) ($b['schoolYear'] ?? ''), (string) ($a['schoolYear'] ?? '')) ?: $order($b) <=> $order($a));
        return $list;
    }

    /** The key the grading system's Final grades page uses for a term: "1st Semester" → "1st-semester", "" → "noterm". */
    public static function termKey(string $term): string
    {
        return $term === '' ? 'noterm' : (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($term));
    }

    /** The tag of this student's school year + term (the term itself, or the whole school year), or null. */
    public static function completionFor(string $studentDocId, string $schoolYear, string $term): ?array
    {
        $raw = Db::val("SELECT data FROM docs WHERE col = 'gradeCompletions' AND id IN (?, ?) ORDER BY id LIMIT 1",
            ["{$studentDocId}__{$schoolYear}__" . self::termKey($term), "{$studentDocId}__{$schoolYear}__year"]);
        return $raw === null ? null : (json_decode((string) $raw, true) ?: []);
    }

    /** Is this student's school year + term tagged complete (the term itself, or the whole school year)? */
    public static function isComplete(string $studentDocId, string $schoolYear, string $term): bool
    {
        return (bool) Db::val("SELECT 1 FROM docs WHERE col = 'gradeCompletions' AND id IN (?, ?)",
            ["{$studentDocId}__{$schoolYear}__" . self::termKey($term), "{$studentDocId}__{$schoolYear}__year"]);
    }

    /** Is the exact term tagged complete? A whole-school-year tag is not a substitute. */
    /** Moves every SIS record of one student from the old Student ID to the new one. */
    private static function renumberSis(string $docId, string $old, string $new): void
    {
        Db::q('UPDATE central_person_links SET student_number = ? WHERE student_doc_id = ? OR (student_doc_id IS NULL AND student_number = ?)', [$new, $docId, $old]);
        Db::q('UPDATE sis_portal_enrollments SET student_number = ? WHERE student_doc_id = ? OR student_number = ?', [$new, $docId, $old]);
        foreach (['sis_admissions', 'sis_balance_cases', 'sis_eligibility_holds', 'sis_users'] as $t)
            if (Db::val('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t])) Db::q("UPDATE $t SET student_number = ? WHERE student_number = ?", [$new, $old]);
    }

    public static function isTermComplete(string $studentDocId, string $schoolYear, string $term): bool
    {
        return (bool) Db::val("SELECT 1 FROM docs WHERE col = 'gradeCompletions' AND id = ?",
            ["{$studentDocId}__{$schoolYear}__" . self::termKey($term)]);
    }

    /**
     * The student's last term before $schoolYear/$term: from their classes in the grading system and their SIS
     * registrations. Returns ['schoolYear' => …, 'term' => …] or null when they have none (e.g. a new student).
     */
    public static function previousTerm(string $studentDocId, string $studentNumber, string $schoolYear, string $term): ?array
    {
        $order = fn(string $t) => ($i = array_search($t, TERMS, true)) === false ? -1 : $i;
        $terms = [];
        foreach (Db::all("SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.schoolYear')) AS sy, JSON_UNQUOTE(JSON_EXTRACT(data, '$.term')) AS term FROM docs
                           WHERE col = 'gradingAssignments' AND JSON_CONTAINS(JSON_EXTRACT(data, '$.studentIds'), JSON_QUOTE(?))", [$studentDocId]) as $a) {
            $terms[] = [(string) $a['sy'], $a['term'] === null || $a['term'] === 'null' ? '' : (string) $a['term']];
        }
        foreach (Db::all("SELECT school_year AS sy, term FROM sis_registrations WHERE (student_doc_id = ? OR student_number = ?) AND status <> 'CANCELLED' AND record_origin='ENROLLMENT'", [$studentDocId, $studentNumber]) as $r) {
            $terms[] = [(string) $r['sy'], (string) $r['term']];
        }
        $best = null;
        foreach ($terms as [$sy, $t]) {
            $k = [$sy, $order($t)];
            if (($k <=> [$schoolYear, $order($term)]) >= 0) continue; // the same term or later
            if (!$best || ($k <=> [$best[0], $order($best[1])]) > 0) $best = [$sy, $t];
        }
        return $best ? ['schoolYear' => $best[0], 'term' => $best[1]] : null;
    }

    /** The grading assignments (classes) of a section and term, by subject id. */
    public static function sectionAssignments(string $sectionId, string $schoolYear, string $term): array
    {
        $out = [];
        foreach (Db::all("SELECT id, data FROM docs WHERE col = 'gradingAssignments' AND f_sectionId = ? AND f_schoolYear = ?", [$sectionId, $schoolYear]) as $r) {
            $a = ['id' => $r['id']] + (json_decode((string) $r['data'], true) ?: []);
            if ((string) ($a['term'] ?? '') === $term) $out[(string) ($a['subjectId'] ?? '')] = $a;
        }
        return $out;
    }

    /** Is the student already in a class of this subject this school year and term (any section)? */
    private static function takesSubject(string $studentDocId, string $subjectId, string $schoolYear, string $term): bool
    {
        foreach (Db::all("SELECT data FROM docs WHERE col = 'gradingAssignments' AND f_schoolYear = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.subjectId')) = ?", [$schoolYear, $subjectId]) as $r) {
            $a = json_decode((string) $r['data'], true) ?: [];
            if ((string) ($a['term'] ?? '') === $term && in_array($studentDocId, (array) ($a['studentIds'] ?? []), true)) return true;
        }
        return false;
    }

    /** Confirmed enrollment: put the student in the existing classes of their subjects (section + term). Returns the subject codes added. */
    public static function addToAssignments(string $studentDocId, string $sectionId, string $schoolYear, string $term, array $subjectIds): array
    {
        $added = [];
        $have = self::sectionAssignments($sectionId, $schoolYear, $term);
        foreach (array_unique($subjectIds) as $sid) {
            $a = $have[(string) $sid] ?? null;
            if (!$a || in_array($studentDocId, (array) ($a['studentIds'] ?? []), true) || self::takesSubject($studentDocId, (string) $sid, $schoolYear, $term)) continue;
            $id = $a['id']; unset($a['id']);
            $a['studentIds'] = array_values(array_merge((array) ($a['studentIds'] ?? []), [$studentDocId]));
            $a['updatedAt'] = self::ts();
            self::put('gradingAssignments', $id, $a);
            $added[] = (string) ($a['subjectCode'] ?? $sid);
        }
        return $added;
    }

    /**
     * A subject dropped after enrollment (v54): the student stays in the class with the grade DRP (Dropped),
     * as the Grading System records it, so the class list and the record show the drop. Returns the subject codes marked.
     */
    public static function markDropped(string $studentDocId, string $sectionId, string $schoolYear, string $term, array $subjectIds): array
    {
        $marked = [];
        $have = self::sectionAssignments($sectionId, $schoolYear, $term);
        $student = self::get('students', $studentDocId) ?: [];
        foreach (array_unique($subjectIds) as $sid) {
            $a = $have[(string) $sid] ?? null;
            if (!$a || !in_array($studentDocId, (array) ($a['studentIds'] ?? []), true)) continue;
            $gid = $a['id'] . '_' . $studentDocId;
            $g = self::get('grades', $gid) ?? [];
            unset($g['id']);
            $g = array_merge(['assignmentId' => $a['id'], 'teacherDocId' => $a['teacherDocId'] ?? '', 'teacherId' => $a['teacherId'] ?? '', 'teacherName' => $a['teacherName'] ?? '',
                'subjectId' => (string) $sid, 'subjectCode' => $a['subjectCode'] ?? '', 'subjectName' => $a['subjectName'] ?? '', 'units' => $a['units'] ?? 0,
                'schoolYear' => $schoolYear, 'term' => $term, 'yearLevel' => $a['yearLevel'] ?? '', 'sectionId' => $sectionId, 'sectionName' => $a['sectionName'] ?? '',
                'studentId' => $studentDocId, 'studentNumber' => $student['studentId'] ?? '', 'studentName' => $student['studentName'] ?? '', 'createdAt' => self::ts()], $g,
                ['finalGrade' => null, 'remarks' => 'Dropped', 'updatedAt' => self::ts()]);
            unset($g['draft']);
            self::put('grades', $gid, $g);
            $marked[] = (string) ($a['subjectCode'] ?? $sid);
        }
        return $marked;
    }

    /**
     * A voided registration: the student leaves the classes (grading assignments) of these subjects. Classes where a
     * grade is already recorded for the student are listed in 'graded' and left unchanged.
     */
    public static function removeFromAssignments(string $studentDocId, string $sectionId, string $schoolYear, string $term, array $subjectIds, bool $dryRun = false): array
    {
        $removed = []; $graded = [];
        $have = self::sectionAssignments($sectionId, $schoolYear, $term);
        foreach (array_unique($subjectIds) as $sid) {
            $a = $have[(string) $sid] ?? null;
            if (!$a || !in_array($studentDocId, (array) ($a['studentIds'] ?? []), true)) continue;
            $code = (string) ($a['subjectCode'] ?? $sid);
            $hasGrade = false;
            foreach (Db::col("SELECT data FROM docs WHERE col='grades' AND f_studentId=?", [$studentDocId]) as $raw) {
                $g = json_decode($raw, true);
                if (($g['assignmentId'] ?? '') === $a['id'] && ((($g['finalGrade'] ?? null) !== null && $g['finalGrade'] !== '') || !empty($g['mark']))) { $hasGrade = true; break; }
            }
            if ($hasGrade) { $graded[] = $code; continue; }
            $removed[] = $code;
            if ($dryRun) continue;
            $id = $a['id']; unset($a['id']);
            $a['studentIds'] = array_values(array_diff((array) ($a['studentIds'] ?? []), [$studentDocId]));
            $a['updatedAt'] = self::ts();
            self::put('gradingAssignments', $id, $a);
        }
        return ['removed' => $removed, 'graded' => $graded];
    }

    /**
     * "Assign teachers now?" from a registration: a new grading assignment (class) for a subject of the section and
     * term, with the teacher, and the students registered for it. Same record as the grading system's Grading assignments.
     */
    public static function assignTeacher(string $sectionId, string $term, string $subjectId, string $teacherDocId, array $studentDocIds): string
    {
        Auth::require('teachers.edit');
        $section = self::get('sections', $sectionId); $subject = self::get('subjects', $subjectId); $teacher = self::get('teachers', $teacherDocId);
        if (!$section || !$subject) throw new AppError('Section or subject not found.');
        if (!$teacher) throw new AppError('Choose a teacher.');
        if (!in_array($term, TERMS, true)) throw new AppError('Choose the semester: 1st Semester, 2nd Semester, 3rd Semester or Summer.');
        return CurriculumAssignments::locked(Db::pdo(),fn()=>Db::tx(function () use ($section, $subject, $teacher, $term, $studentDocIds) {
            $have = self::sectionAssignments($section['id'], (string) $section['schoolYear'], $term);
            if (isset($have[$subject['id']])) throw new AppError(($subject['subjectCode'] ?? 'This subject') . ' already has a teacher in ' . $section['sectionName'] . ': ' . ($have[$subject['id']]['teacherName'] ?? '') . '. Change it in the grading system.');
            $ids = array_values(array_filter(array_unique($studentDocIds), fn($s) => !self::takesSubject($s, $subject['id'], (string) $section['schoolYear'], $term)));
            $id = self::newId();
            $data = ['teacherDocId' => $teacher['id'], 'teacherId' => $teacher['teacherId'] ?? '', 'teacherName' => $teacher['teacherName'] ?? '',
                'subjectId' => $subject['id'], 'subjectCode' => $subject['subjectCode'] ?? '', 'subjectName' => $subject['subjectName'] ?? '', 'units' => (float) ($subject['units'] ?? 0),
                'schoolYear' => $section['schoolYear'], 'term' => $term, 'yearLevel' => $section['yearLevel'], 'sectionId' => $section['id'], 'sectionName' => $section['sectionName'],
                'studentIds' => $ids, 'createdAt' => self::ts(), 'updatedAt' => self::ts()];
            self::put('gradingAssignments', $id, $data);
            Audit::log('teacher_assigned', 'grading_assignment', $id, ['subject' => $data['subjectCode'], 'teacher' => $data['teacherName'], 'section' => $data['sectionName'], 'term' => $term]);
            return $id;
        }));
    }

    /** "2025-2026 · 1st Semester" (or the whole school year). */
    public static function completionPeriod(array $c): string
    {
        return ($c['schoolYear'] ?? '') . ' · ' . (($c['term'] ?? '') !== '' ? $c['term'] : 'Whole school year');
    }

    // ------------------------------------------------------------ sections and year levels
    public static function sections(?string $schoolYear = null): array
    {
        $list = array_filter(self::all('sections'), fn($s) => !$schoolYear || ($s['schoolYear'] ?? '') === $schoolYear);
        usort($list, fn($a, $b) => [$b['schoolYear'] ?? '', array_search($a['yearLevel'] ?? '', YEAR_LEVELS), $a['sectionName'] ?? '']
            <=> [$a['schoolYear'] ?? '', array_search($b['yearLevel'] ?? '', YEAR_LEVELS), $b['sectionName'] ?? '']);
        return array_values($list);
    }

    public static function schoolYears(): array
    {
        $ys = Db::col("SELECT DISTINCT f_schoolYear FROM docs WHERE col IN ('sections','students') AND f_schoolYear IS NOT NULL AND f_schoolYear <> ''");
        rsort($ys);
        return $ys;
    }

    public static function saveSection(?string $id, string $schoolYear, string $yearLevel, string $name): string
    {
        Auth::require('sections.edit');
        $schoolYear = trim($schoolYear);
        $name = strtoupper(trim(preg_replace('/\s+/', ' ', $name)));
        if (!self::validSchoolYear($schoolYear)) throw new AppError('School year must be consecutive years, for example 2026-2027.');
        if (!in_array($yearLevel, YEAR_LEVELS, true)) throw new AppError('Choose a year level.');
        if ($name === '' || mb_strlen($name) > 60) throw new AppError('Enter a section name.');
        return CurriculumAssignments::locked(Db::pdo(),fn()=>Db::tx(function () use ($id, $schoolYear, $yearLevel, $name) {
            foreach (self::all('sections') as $s) {
                if ($s['id'] !== $id && ($s['schoolYear'] ?? '') === $schoolYear && ($s['sectionName'] ?? '') === $name) throw new AppError("$name already exists for $schoolYear.");
            }
            $before = $id ? self::get('sections', $id) : null;
            if ($id && !$before) throw new AppError('Section not found.');
            $data = ['schoolYear' => $schoolYear, 'yearLevel' => $yearLevel, 'sectionName' => $name];
            $id ??= self::newId();
            self::put('sections', $id, array_merge($before ?? ['createdAt' => self::ts()], $data, ['updatedAt' => self::ts()]));
            if ($before && ($before['schoolYear'] !== $schoolYear || $before['yearLevel'] !== $yearLevel || $before['sectionName'] !== $name)) {
                self::cascade(['students', 'gradingAssignments', 'grades'], 'sectionId', $id, $data);
                Db::q('UPDATE sis_registrations SET section_name = ? WHERE section_doc_id = ?', [$name, $id]);
            }
            (new CurriculumAssignments(Db::pdo()))->syncSection($id);
            Audit::log($before ? 'section_updated' : 'section_added', 'grading_section', $id, $data);
            return $id;
        }));
    }

    // ------------------------------------------------------------ teachers
    public static function teachers(): array
    {
        $list = self::all('teachers');
        usort($list, fn($a, $b) => strcasecmp((string) ($a['teacherName'] ?? ''), (string) ($b['teacherName'] ?? '')));
        return $list;
    }

    /** A teacher renames themself (My profile): the caller has checked the account is linked to this teacher record. */
    public static function renameOwnTeacher(string $id, string $name): void
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '' || mb_strlen($name) > 150) throw new AppError('Enter your name.');
        CurriculumAssignments::locked(Db::pdo(), fn() => Db::tx(function () use ($id, $name) {
            $before = self::get('teachers', $id) ?: throw new AppError('Your teacher record was not found.');
            if (($before['teacherName'] ?? '') === $name) return;
            self::put('teachers', $id, array_merge($before, ['teacherName' => $name, 'updatedAt' => self::ts()]));
            self::cascade(['gradingAssignments', 'grades'], 'teacherDocId', $id, ['teacherId' => $before['teacherId'] ?? '', 'teacherName' => $name]);
            Audit::log('teacher_renamed_self', 'grading_teacher', $id, ['from' => $before['teacherName'] ?? '', 'to' => $name]);
        }));
    }

    public static function saveTeacher(?string $id, string $teacherId, string $name): string
    {
        Auth::require('teachers.edit');
        if ($id===CurriculumAssignments::PLACEHOLDER) throw new AppError('Professor X is a protected placeholder. Change the teacher in Grading Assignments instead.');
        $teacherId = strtoupper(trim($teacherId));
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '' || mb_strlen($name) > 150) throw new AppError("Enter the teacher's name.");
        return CurriculumAssignments::locked(Db::pdo(),fn()=>Db::tx(function () use ($id, $teacherId, $name) {
            $all = self::all('teachers');
            $before = $id ? self::get('teachers', $id) : null;
            if ($id && !$before) throw new AppError('Teacher not found.');
            if ($teacherId === '') {
                $ids = array_map(fn($t) => strtoupper((string) ($t['teacherId'] ?? '')), $all);
                $teacherId = $before['teacherId'] ?? self::nextNumber(preg_grep('/\d+$/', $ids) ? $ids : array_merge($ids, ['T000']));
            }
            foreach ($all as $t) if ($t['id'] !== $id && strtoupper((string) ($t['teacherId'] ?? '')) === $teacherId) throw new AppError("Teacher ID $teacherId is already used.");
            $data = ['teacherId' => $teacherId, 'teacherName' => $name];
            $id ??= self::newId();
            self::put('teachers', $id, array_merge($before ?? ['createdAt' => self::ts()], $data, ['updatedAt' => self::ts()]));
            if ($before && ($before['teacherId'] !== $teacherId || $before['teacherName'] !== $name)) {
                self::cascade(['gradingAssignments', 'grades'], 'teacherDocId', $id, $data);
            }
            Audit::log($before ? 'teacher_updated' : 'teacher_added', 'grading_teacher', $id, $data);
            return $id;
        }));
    }

    // ------------------------------------------------------------ subjects and curriculum
    public static function subjects(): array
    {
        $list = self::all('subjects');
        usort($list, fn($a, $b) => strcasecmp((string) ($a['subjectCode'] ?: $a['subjectName'] ?? ''), (string) ($b['subjectCode'] ?: $b['subjectName'] ?? '')));
        return $list;
    }

    public static function saveSubject(?string $id, string $code, string $name, string $units): string
    {
        Auth::require('curriculum.edit');
        $code = strtoupper(trim($code));
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) throw new AppError('Enter a subject name.');
        $units = trim($units);
        if (!preg_match('/^\d{1,2}(?:\.\d)?$/', $units) || (float) $units <= 0 || (float) $units > 30) throw new AppError('Units must be a number from 0.5 to 30, with at most one decimal (e.g. 3 or 1.5).');
        $u = (float) $units;
        $u = floor($u) == $u ? (int) $u : round($u, 1);
        return Db::tx(function () use ($id, $code, $name, $u) {
            $before = $id ? self::get('subjects', $id) : null;
            if ($id && !$before) throw new AppError('Subject not found.');
            if ($code !== '') foreach (self::all('subjects') as $s) if ($s['id'] !== $id && strtoupper((string) ($s['subjectCode'] ?? '')) === $code) throw new AppError("Subject code $code is already used.");
            $data = ['subjectCode' => $code, 'subjectName' => $name, 'units' => $u];
            $id ??= self::newId();
            self::put('subjects', $id, array_merge($before ?? ['createdAt' => self::ts()], $data, ['updatedAt' => self::ts()]));
            if ($before && ($before['subjectCode'] !== $code || $before['subjectName'] !== $name || (float) $before['units'] !== (float) $u)) {
                self::cascade(['gradingAssignments', 'grades'], 'subjectId', $id, $data);
            }
            Audit::log($before ? 'subject_updated' : 'subject_added', 'grading_subject', $id, $data);
            return $id;
        });
    }

    /** v56: Lecture / Laboratory / Lecture and Laboratory / Other ('' = not set), kept on the subject record. */
    public static function setSubjectType(string $id, string $type): void
    {
        Auth::require('curriculum.edit');
        if ($type !== '' && !in_array($type, ImportAll::SUBJECT_TYPES, true)) throw new AppError('Choose a subject type.');
        $s = self::get('subjects', $id);
        if (!$s) throw new AppError('Subject not found.');
        if ((string) ($s['subjectType'] ?? '') === $type) return;
        if ($type === '') unset($s['subjectType']); else $s['subjectType'] = $type;
        self::put('subjects', $id, array_merge($s, ['updatedAt' => self::ts()]));
        Audit::log('subject_type_set', 'grading_subject', $id, ['type' => $type]);
    }

    /** v56.2: lecture / laboratory units of a subject; their sum must equal the subject's units. Null = not split. */
    public static function setSubjectSplit(string $id, ?array $split): void
    {
        Auth::require('curriculum.edit');
        $s = self::get('subjects', $id);
        if (!$s) throw new AppError('Subject not found.');
        if ($split === null) { if (!isset($s['lecUnits']) && !isset($s['labUnits'])) return; unset($s['lecUnits'], $s['labUnits']); }
        else {
            [$lec, $lab] = $split;
            if (abs($lec + $lab - (float) $s['units']) > 0.001) throw new AppError('Lecture units plus laboratory units must equal the subject units (' . (float) $s['units'] . ').');
            if ((float) ($s['lecUnits'] ?? -1) === $lec && (float) ($s['labUnits'] ?? -1) === $lab) return;
            $s['lecUnits'] = floor($lec) == $lec ? (int) $lec : $lec; $s['labUnits'] = floor($lab) == $lab ? (int) $lab : $lab;
        }
        self::put('subjects', $id, array_merge($s, ['updatedAt' => self::ts()]));
        Audit::log('subject_units_split', 'grading_subject', $id, ['lec' => $s['lecUnits'] ?? null, 'lab' => $s['labUnits'] ?? null]);
        SubjectFees::refreshOpen([$id]); // open assessments without payments follow the new split
    }

    public static function curricula(): array
    {
        $list = self::all('curriculum');
        usort($list, fn($a, $b) => [$a['program'] ?? '', array_search($a['yearLevel'] ?? '', YEAR_LEVELS), array_search($a['term'] ?? '', TERMS)]
            <=> [$b['program'] ?? '', array_search($b['yearLevel'] ?? '', YEAR_LEVELS), array_search($b['term'] ?? '', TERMS)]);
        return $list;
    }

    /** "BSN · 1st Year · 1st Semester (10 subjects)" */
    public static function curriculumLabel(array $c): string
    {
        return trim(($c['program'] ?? '') . ' · ' . ($c['yearLevel'] ?? '') . ' · ' . ($c['term'] ?? ''), ' ·') . ' (' . count((array) ($c['subjectIds'] ?? [])) . ' subjects)';
    }

    /** The curriculum whose subjects are exactly these (null when the subjects were picked by hand). */
    public static function curriculumMatching(array $subjectIds): ?array
    {
        if (!$subjectIds) return null;
        sort($subjectIds);
        foreach (self::all('curriculum') as $c) {
            $ids = array_values((array) ($c['subjectIds'] ?? []));
            sort($ids);
            if ($ids === $subjectIds) return $c;
        }
        return null;
    }

    /** The curriculum record for a program, year level and term (null when there is none). */
    public static function curriculumFor(string $program, string $yearLevel, string $term): ?array
    {
        return self::get('curriculum', self::curriculumId($program, $yearLevel, $term));
    }

    public static function saveCurriculum(string $program, string $yearLevel, string $term, array $subjectIds, ?array $subjectTeachers=null): string
    {
        Auth::require('curriculum.edit');
        $program = strtoupper(trim($program));
        if ($program === '' || mb_strlen($program) > 40) throw new AppError('Enter the program (as in the section names, e.g. BSIT).');
        if (!in_array($yearLevel, YEAR_LEVELS, true)) throw new AppError('Choose a year level.');
        if ($term !== '' && !in_array($term, TERMS, true)) throw new AppError('Choose a term.');
        if ($term === '' && !self::get('curriculum', self::curriculumId($program, $yearLevel, ''))) throw new AppError('Choose the semester: 1st Semester, 2nd Semester, 3rd Semester or Summer.');
        return CurriculumAssignments::locked(Db::pdo(),function()use($program,$yearLevel,$term,$subjectIds,$subjectTeachers){
            return Db::tx(function()use($program,$yearLevel,$term,$subjectIds,$subjectTeachers){
                $id=self::curriculumId($program,$yearLevel,$term);$before=self::get('curriculum',$id);
                $data=array_merge($before??['createdAt'=>self::ts()],['program'=>$program,'yearLevel'=>$yearLevel,'term'=>$term,'subjectIds'=>$subjectIds,'updatedAt'=>self::ts()]);
                if ($subjectTeachers!==null) $data['subjectTeachers']=$subjectTeachers;
                $service=new CurriculumAssignments(Db::pdo());
                try {$data=$service->normalize($data,$before);}catch(RuntimeException $e){throw new AppError($e->getMessage());}
                self::put('curriculum',$id,$data);
                $n=$service->syncCurriculum($id,$before??['subjectIds'=>[],'subjectTeachers'=>[]]);
                self::$lastCurriculumSync=['created'=>$n]+$service->teacherSync;
                Audit::log('curriculum_saved','grading_curriculum',$id,['subjects'=>count($subjectIds),'assignments_created'=>$n,'teachers_updated'=>$service->teacherSync['updated'],'teachers_kept'=>$service->teacherSync['kept']]);
                return $id;
            });
        });
    }

    /** Programs known from section names and curriculum records. */
    public static function programs(): array
    {
        $p = array_merge(array_map(fn($s) => self::programOf((string) ($s['sectionName'] ?? '')), self::all('sections')),
            array_map(fn($c) => (string) ($c['program'] ?? ''), self::all('curriculum')));
        $p = array_values(array_unique(array_filter($p)));
        sort($p);
        return $p;
    }
}
