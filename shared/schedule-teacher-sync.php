<?php
declare(strict_types=1);
/**
 * v65.5: a class schedule follows its class's teacher.
 * A class (grading assignment) is one subject of one section in one school year and term. When its teacher changes —
 * in Grading assignments, from the curriculum, or when the class is created — the schedule entries of that section,
 * subject and term that still have the old teacher (or no teacher yet) take the new one. An entry is left as it is when
 * the new teacher already teaches another class at an overlapping time (Class Schedules then shows the difference).
 * Professor X ("needs teacher") is shown as no teacher in schedules.
 */
final class ScheduleTeacherSync
{
    public const PLACEHOLDER = '_acadlink_professor_x';
    /** Entries changed / left because of a clash during this request. */
    public static int $moved = 0;
    public static int $clashed = 0;
    private static ?bool $ready = null;

    /** $oldTeacher null = a new class: only entries without a teacher take its teacher. */
    public static function follow(PDO $db, array $class, ?string $oldTeacher): void
    {
        if (!self::ready($db)) return;
        $section = (string) ($class['sectionId'] ?? ''); $subject = (string) ($class['subjectId'] ?? '');
        $sy = (string) ($class['schoolYear'] ?? ''); $term = (string) ($class['term'] ?? '');
        if ($section === '' || $subject === '' || $sy === '' || $term === '') return;
        $new = self::real((string) ($class['teacherDocId'] ?? ''));
        $old = $oldTeacher === null ? null : self::real($oldTeacher);
        if ($old !== null && $old === $new) return;
        $sql = 'SELECT id, school_year, term, days, start_time, end_time, teacher_doc_id FROM sis_class_schedules
                WHERE section_doc_id = ? AND subject_doc_id = ? AND school_year = ? AND term = ? AND (teacher_doc_id IS NULL OR teacher_doc_id = \'\'' . ($old ? ' OR teacher_doc_id = ?' : '') . ') FOR UPDATE';
        $q = $db->prepare($sql);
        $q->execute(array_merge([$section, $subject, $sy, $term], $old ? [$old] : []));
        $set = $db->prepare('UPDATE sis_class_schedules SET teacher_doc_id = ? WHERE id = ?');
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string) ($row['teacher_doc_id'] ?? '') === $new) continue;
            if ($new !== '' && self::clashes($db, $row, $new)) { self::$clashed++; continue; }
            $set->execute([$new !== '' ? $new : null, (int) $row['id']]);
            self::$moved++;
        }
    }

    /**
     * v66: Class Schedules → "Use the class teachers": every schedule entry of the term whose teacher differs from its
     * class's teacher (Grading assignments) takes the class's teacher, unless that teacher already has a class then.
     * Returns ['changed' => n, 'clashed' => [labels], 'differ' => n before].
     */
    public static function useClassTeachers(PDO $db, string $sy, string $term, bool $dryRun = false): array
    {
        $out = ['changed' => 0, 'clashed' => [], 'differ' => 0];
        if (!self::ready($db)) return $out;
        $q = $db->prepare('SELECT id, section_doc_id, subject_doc_id, school_year, term, days, start_time, end_time, teacher_doc_id FROM sis_class_schedules WHERE school_year = ? AND term = ? ORDER BY section_doc_id, id');
        $q->execute([$sy, $term]);
        $bySection = [];
        $set = $db->prepare('UPDATE sis_class_schedules SET teacher_doc_id = ? WHERE id = ?');
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sec = (string) $row['section_doc_id'];
            $bySection[$sec] ??= self::classTeachers($db, $sec, $sy, $term);
            $want = (string) ($bySection[$sec][(string) $row['subject_doc_id']]['id'] ?? '');
            if ($want === '' || $want === (string) ($row['teacher_doc_id'] ?? '')) continue;
            $out['differ']++;
            if ($dryRun) continue;
            if (self::clashes($db, $row, $want)) { $out['clashed'][] = (int) $row['id']; continue; }
            $set->execute([$want, (int) $row['id']]);
            $out['changed']++;
        }
        return $out;
    }

    /** The teacher a schedule should have: the class's teacher for that section, subject and term ('' = none or Professor X). */
    public static function classTeachers(PDO $db, string $section, string $sy, string $term): array
    {
        $q = $db->prepare("SELECT data FROM docs WHERE col = 'gradingAssignments' AND f_sectionId = ?");
        $q->execute([$section]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            $d = json_decode((string) $raw, true) ?: [];
            if ((string) ($d['schoolYear'] ?? '') !== $sy || (string) ($d['term'] ?? '') !== $term) continue;
            $out[(string) ($d['subjectId'] ?? '')] = ['id' => self::real((string) ($d['teacherDocId'] ?? '')), 'name' => (string) ($d['teacherName'] ?? '')];
        }
        return $out;
    }

    private static function real(string $teacher): string { return $teacher === self::PLACEHOLDER ? '' : $teacher; }

    /** Does $teacher already teach another class at a time that overlaps $row (same term, a shared day)? */
    public static function clashes(PDO $db, array $row, string $teacher): bool
    {
        $q = $db->prepare('SELECT days FROM sis_class_schedules WHERE school_year = ? AND term = ? AND teacher_doc_id = ? AND id <> ? AND start_time < ? AND end_time > ?');
        $q->execute([$row['school_year'], $row['term'], $teacher, (int) $row['id'], $row['end_time'], $row['start_time']]);
        $days = array_filter(explode(',', (string) $row['days']));
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $other) if (array_intersect($days, array_filter(explode(',', (string) $other)))) return true;
        return false;
    }

    private static function ready(PDO $db): bool
    {
        if (self::$ready === null) self::$ready = (bool) $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sis_class_schedules'")->fetchColumn();
        return self::$ready;
    }
}
