<?php
declare(strict_types=1);

/**
 * v53: rooms and class schedules. A schedule is one meeting pattern of a subject in a section for a term,
 * e.g. NCM101 · BSN 1A · MWF 7:30–8:30 · Room 201 · Prof. Dela Rosa. A subject can have several (lecture, lab).
 * The same room, teacher or section can't have two classes at overlapping times; a room smaller than the
 * section is only a warning.
 */
final class Schedules
{
    public const DAYS = ['M' => 'Mon', 'T' => 'Tue', 'W' => 'Wed', 'Th' => 'Thu', 'F' => 'Fri', 'S' => 'Sat', 'Su' => 'Sun'];
    public const KINDS = ['LEC' => 'Lecture', 'LAB' => 'Laboratory', 'OTHER' => 'Other'];
    public const ROOM_KINDS = ['LECTURE' => 'Lecture room', 'LAB' => 'Laboratory', 'OTHER' => 'Other'];

    public static function install(PDO $db): void
    {
        Db::q("CREATE TABLE IF NOT EXISTS sis_rooms (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL, building VARCHAR(80) NOT NULL DEFAULT '', capacity SMALLINT UNSIGNED NOT NULL DEFAULT 40,
            kind ENUM('LECTURE','LAB','OTHER') NOT NULL DEFAULT 'LECTURE', is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY u_sis_room_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        Db::q("CREATE TABLE IF NOT EXISTS sis_class_schedules (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_year VARCHAR(9) NOT NULL, term VARCHAR(20) NOT NULL,
            section_doc_id VARCHAR(191) NOT NULL, subject_doc_id VARCHAR(191) NOT NULL, teacher_doc_id VARCHAR(191) NULL,
            room_id INT UNSIGNED NULL, days VARCHAR(20) NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL,
            kind ENUM('LEC','LAB','OTHER') NOT NULL DEFAULT 'LEC',
            created_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY k_sis_sched_term (school_year, term), KEY k_sis_sched_section (section_doc_id), KEY k_sis_sched_room (room_id), KEY k_sis_sched_teacher (teacher_doc_id),
            CONSTRAINT fk_sis_sched_room FOREIGN KEY (room_id) REFERENCES sis_rooms (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Access: Class schedules module (View / Edit); Registrars and Power Users get Edit once, everyone can see their own schedule
        if (!$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='central_permissions'")->fetchColumn()) return;
        $db->exec("INSERT IGNORE INTO central_permissions(code,workspace,label) VALUES ('sis.schedules.view','SIS','View class schedules and rooms'),('sis.schedules.manage','SIS','Set rooms and class schedules')");
        $db->exec("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
                   SELECT g.id,c.code,'ALL' FROM central_groups g JOIN central_permissions c ON c.code IN ('sis.schedules.view','sis.schedules.manage')
                   WHERE g.code IN ('POWER_USER','REGISTRAR') AND NOT EXISTS (SELECT 1 FROM central_schema_migrations WHERE migration_key='schedules_v1')");
        $db->exec("INSERT IGNORE INTO central_schema_migrations(migration_key) VALUES ('schedules_v1')");
    }

    // ------------------------------------------------------------------ rooms

    public static function rooms(bool $activeOnly = false): array
    {
        return Db::all('SELECT * FROM sis_rooms' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY building, code');
    }

    public static function saveRoom(?int $id, array $in): int
    {
        Auth::require('schedules.edit');
        $code = strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($in['code'] ?? ''))));
        if ($code === '' || mb_strlen($code) > 30) throw new AppError('Enter the room name or number (up to 30 characters), e.g. 201 or LAB-1.');
        $building = mb_substr(trim((string) ($in['building'] ?? '')), 0, 80);
        $cap = (string) ($in['capacity'] ?? '');
        if (!preg_match('/^\d{1,4}$/', $cap) || (int) $cap < 1) throw new AppError('Capacity: how many students the room holds (1 or more).');
        $kind = (string) ($in['kind'] ?? 'LECTURE');
        if (!isset(self::ROOM_KINDS[$kind])) throw new AppError('Choose the room type.');
        if (Db::val('SELECT id FROM sis_rooms WHERE code = ? AND id <> ?', [$code, (int) $id])) throw new AppError("Room $code already exists.");
        $data = ['code' => $code, 'building' => $building, 'capacity' => (int) $cap, 'kind' => $kind, 'is_active' => !empty($in['is_active']) ? 1 : 0];
        if ($id) { Db::q('UPDATE sis_rooms SET code = ?, building = ?, capacity = ?, kind = ?, is_active = ? WHERE id = ?', [...array_values($data), $id]); }
        else $id = Db::insert('sis_rooms', $data);
        Audit::log('room_saved', 'room', $id, $data);
        return $id;
    }

    // ------------------------------------------------------------------ schedules

    /** "MWF" / "M,W,F" / ['M','W','F'] → ['M','W','F'] in week order. */
    public static function parseDays($days): array
    {
        if (is_array($days)) $list = $days;
        else {
            $s = str_replace([',', ' ', '/'], '', (string) $days);
            preg_match_all('/Su|Th|M|T|W|F|S/i', $s, $m);
            $list = array_map(fn($d) => ucfirst(strtolower($d)), $m[0]);
        }
        $list = array_values(array_intersect(array_keys(self::DAYS), $list));
        return $list;
    }
    public static function daysText(string $days): string { return implode('', self::parseDays(explode(',', $days))); }
    public static function time(string $t): string { return date('g:i A', strtotime($t)); }
    public static function timeRange(array $s): string { return self::time($s['start_time']) . '–' . self::time($s['end_time']); }

    /** Schedules with subject, section, teacher and room names. Filters: school_year, term, section, teacher, room, ids. */
    public static function list(array $f = []): array
    {
        $w = []; $a = [];
        foreach (['school_year' => 's.school_year', 'term' => 's.term', 'section' => 's.section_doc_id', 'teacher' => 's.teacher_doc_id', 'room' => 's.room_id', 'subject' => 's.subject_doc_id'] as $k => $col)
            if (($f[$k] ?? '') !== '') { $w[] = "$col = ?"; $a[] = $f[$k]; }
        if (!empty($f['subjects'])) { $w[] = 's.subject_doc_id IN (' . implode(',', array_fill(0, count($f['subjects']), '?')) . ')'; array_push($a, ...array_values($f['subjects'])); }
        $rows = Db::all('SELECT s.*, r.code AS room_code, r.building, r.capacity FROM sis_class_schedules s LEFT JOIN sis_rooms r ON r.id = s.room_id'
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY s.start_time, s.days', $a);
        $subjects = array_column(GS::subjects(), null, 'id'); $sections = array_column(GS::sections(), null, 'id'); $teachers = array_column(GS::teachers(), null, 'id');
        foreach ($rows as &$r) {
            $r['subject_code'] = (string) ($subjects[$r['subject_doc_id']]['subjectCode'] ?? '?');
            $r['subject_name'] = (string) ($subjects[$r['subject_doc_id']]['subjectName'] ?? '');
            $r['section_name'] = (string) ($sections[$r['section_doc_id']]['sectionName'] ?? '?');
            $r['teacher_name'] = $r['teacher_doc_id'] ? (string) ($teachers[$r['teacher_doc_id']]['teacherName'] ?? '?') : '';
        }
        unset($r);
        return $rows;
    }

    /** Students placed in a section for the term (registrations), for the capacity warning. */
    public static function sectionSize(string $sectionId, string $sy, string $term): int
    {
        return (int) Db::val("SELECT COUNT(*) FROM sis_registrations WHERE section_doc_id = ? AND school_year = ? AND term = ? AND status = 'REGISTERED'", [$sectionId, $sy, $term]);
    }

    /** Other schedules that clash with $s (same term, a shared day, overlapping time, and the same room, teacher or section). */
    public static function conflicts(array $s, ?int $ignoreId = null): array
    {
        $days = self::parseDays(explode(',', (string) $s['days']));
        $out = [];
        foreach (self::list(['school_year' => $s['school_year'], 'term' => $s['term']]) as $o) {
            if ($ignoreId && (int) $o['id'] === $ignoreId) continue;
            if (!array_intersect($days, self::parseDays(explode(',', $o['days'])))) continue;
            if (!($s['start_time'] < $o['end_time'] && $o['start_time'] < $s['end_time'])) continue;
            $why = [];
            if ($s['room_id'] && (int) $o['room_id'] === (int) $s['room_id']) $why[] = 'room ' . $o['room_code'];
            if ($s['teacher_doc_id'] && $o['teacher_doc_id'] === $s['teacher_doc_id']) $why[] = 'teacher ' . $o['teacher_name'];
            if ($o['section_doc_id'] === $s['section_doc_id']) $why[] = 'section ' . $o['section_name'];
            if ($why) $out[] = $o + ['why' => $why];
        }
        return $out;
    }

    /** Adds or changes a schedule; refuses clashes. Returns ['id' => …, 'warnings' => […]]. */
    public static function save(?int $id, array $in): array
    {
        Auth::require('schedules.edit');
        $sy = trim((string) ($in['school_year'] ?? '')); $term = (string) ($in['term'] ?? '');
        if (!preg_match('/^\d{4}-\d{4}$/', $sy)) throw new AppError('Choose the school year.');
        if (!in_array($term, TERMS, true)) throw new AppError('Choose the term.');
        $section = (string) ($in['section_doc_id'] ?? ''); $subject = (string) ($in['subject_doc_id'] ?? '');
        if (!GS::get('sections', $section)) throw new AppError('Choose the section.');
        if (!GS::get('subjects', $subject)) throw new AppError('Choose the subject.');
        $teacher = (string) ($in['teacher_doc_id'] ?? '');
        if ($teacher !== '' && !GS::get('teachers', $teacher)) throw new AppError('Choose an existing teacher, or leave it empty.');
        $room = (int) ($in['room_id'] ?? 0);
        $roomRow = $room ? Db::one('SELECT * FROM sis_rooms WHERE id = ?', [$room]) : null;
        if ($room && (!$roomRow || !$roomRow['is_active'])) throw new AppError('Choose an active room, or leave it empty.');
        $days = self::parseDays($in['days'] ?? []);
        if (!$days) throw new AppError('Choose the days (e.g. M W F).');
        $start = self::normTime((string) ($in['start_time'] ?? ''), 'Start time'); $end = self::normTime((string) ($in['end_time'] ?? ''), 'End time');
        if ($end <= $start) throw new AppError('The end time must be after the start time.');
        if ((strtotime($end) - strtotime($start)) > 6 * 3600) throw new AppError('One meeting can be at most 6 hours.');
        $kind = (string) ($in['kind'] ?? 'LEC');
        if (!isset(self::KINDS[$kind])) $kind = 'LEC';
        $s = ['school_year' => $sy, 'term' => $term, 'section_doc_id' => $section, 'subject_doc_id' => $subject, 'teacher_doc_id' => $teacher ?: null,
              'room_id' => $room ?: null, 'days' => implode(',', $days), 'start_time' => $start, 'end_time' => $end, 'kind' => $kind];
        return Db::tx(function () use ($id, $s, $roomRow) {
            Seq::next('schedule_lock'); // one schedule change at a time: two clashing saves can't both pass the check
            if ($c = self::conflicts($s, $id)) {
                $c = $c[0];
                throw new AppError('Time conflict with ' . $c['subject_code'] . ' · ' . $c['section_name'] . ' · ' . self::daysText($c['days']) . ' ' . self::timeRange($c) . ' (same ' . implode(', same ', $c['why']) . ').');
            }
            if ($id) {
                if (!Db::val('SELECT 1 FROM sis_class_schedules WHERE id = ?', [$id])) throw new AppError('Schedule not found.');
                Db::q('UPDATE sis_class_schedules SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($s))) . ' WHERE id = ?', [...array_values($s), $id]);
            } else $id = Db::insert('sis_class_schedules', $s + ['created_by' => Auth::id()]);
            Audit::log('schedule_saved', 'schedule', $id, $s);
            $warn = [];
            if ($roomRow) {
                $n = self::sectionSize($s['section_doc_id'], $s['school_year'], $s['term']);
                if ($n > (int) $roomRow['capacity']) $warn[] = "Over capacity: $n students in the section, room {$roomRow['code']} holds {$roomRow['capacity']}.";
            }
            return ['id' => $id, 'warnings' => $warn];
        });
    }

    private static function normTime(string $t, string $label): string
    {
        $t = trim($t);
        $ts = $t !== '' ? strtotime('2000-01-01 ' . $t) : false;
        if ($ts === false || !preg_match('/^\d{1,2}:\d{2}/', $t)) throw new AppError("$label: choose a time, e.g. 7:30.");
        return date('H:i:00', $ts);
    }

    public static function delete(int $id): void
    {
        Auth::require('schedules.edit');
        $s = Db::one('SELECT * FROM sis_class_schedules WHERE id = ?', [$id]) ?: throw new AppError('Schedule not found.');
        Db::q('DELETE FROM sis_class_schedules WHERE id = ?', [$id]);
        Audit::log('schedule_deleted', 'schedule', $id, $s);
    }

    /** Copies one term's schedules to another (only those that don't clash there). Returns [copied, skipped]. */
    public static function copyTerm(string $fromSy, string $fromTerm, string $toSy, string $toTerm): array
    {
        Auth::require('schedules.edit');
        $done = 0; $skipped = 0;
        foreach (self::list(['school_year' => $fromSy, 'term' => $fromTerm]) as $s) {
            try { self::save(null, ['school_year' => $toSy, 'term' => $toTerm, 'days' => explode(',', $s['days'])] + $s); $done++; }
            catch (AppError) { $skipped++; }
        }
        return [$done, $skipped];
    }

    /** The teacher record a grading assignment gives for a subject in a section and term (to suggest the teacher). */
    public static function assignedTeacher(string $section, string $subject, string $sy, string $term): ?string
    {
        foreach (Db::all("SELECT data FROM docs WHERE col = 'gradingAssignments'") as $r) {
            $d = json_decode((string) $r['data'], true) ?: [];
            if (($d['sectionId'] ?? '') === $section && ($d['subjectId'] ?? '') === $subject && ($d['schoolYear'] ?? '') === $sy && ($d['term'] ?? '') === $term) return (string) ($d['teacherDocId'] ?? '') ?: null;
        }
        return null;
    }

    // ------------------------------------------------------------------ registration

    /** Schedules of the subjects of a registration (its section and term), and the clashes among them. */
    public static function forRegistration(array $r, array $subjectIds): array
    {
        $rows = $subjectIds ? self::list(['school_year' => $r['school_year'], 'term' => $r['term'], 'section' => $r['section_doc_id'], 'subjects' => $subjectIds]) : [];
        $clash = [];
        for ($i = 0; $i < count($rows); $i++) for ($j = $i + 1; $j < count($rows); $j++) {
            [$a, $b] = [$rows[$i], $rows[$j]];
            if ($a['subject_doc_id'] === $b['subject_doc_id']) continue;
            if (array_intersect(self::parseDays(explode(',', $a['days'])), self::parseDays(explode(',', $b['days']))) && $a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time'])
                $clash[] = "{$a['subject_code']} and {$b['subject_code']} meet at the same time (" . self::daysText($a['days']) . ' ' . self::timeRange($a) . ' / ' . self::daysText($b['days']) . ' ' . self::timeRange($b) . ')';
        }
        return ['rows' => $rows, 'clashes' => $clash];
    }

    // ------------------------------------------------------------------ timetable

    /** Weekly timetable HTML (days across, times down) for a list of schedules. $label: what each cell shows. */
    public static function grid(array $rows, string $show = 'section'): string
    {
        if (!$rows) return '<p class="text-muted mb-0">No classes scheduled.</p>';
        $used = []; foreach ($rows as $r) foreach (self::parseDays(explode(',', $r['days'])) as $d) $used[$d] = true;
        $days = array_values(array_intersect(array_keys(self::DAYS), array_keys($used)));
        $h = '<div class="table-responsive"><table class="table table-sm table-bordered sched-grid mb-0"><thead><tr><th style="width:120px">Time</th>';
        foreach ($days as $d) $h .= '<th>' . self::DAYS[$d] . '</th>';
        $h .= '</tr></thead><tbody>';
        $slots = [];
        foreach ($rows as $r) $slots[$r['start_time'] . '|' . $r['end_time']] = true;
        ksort($slots);
        foreach (array_keys($slots) as $slot) {
            [$st, $en] = explode('|', $slot);
            $h .= '<tr><th class="small text-nowrap">' . h(self::time($st) . '–' . self::time($en)) . '</th>';
            foreach ($days as $d) {
                $cell = array_filter($rows, fn($r) => $r['start_time'] === $st && $r['end_time'] === $en && in_array($d, self::parseDays(explode(',', $r['days'])), true));
                $h .= '<td>' . implode('', array_map(fn($r) => '<div class="sched-cell"><strong>' . h($r['subject_code']) . '</strong> <span class="small">' . h(self::KINDS[$r['kind']]) . '</span><div class="small">'
                    . h(implode(' · ', array_filter([$show !== 'section' ? $r['section_name'] : '', $show !== 'room' ? ($r['room_code'] ? 'Room ' . $r['room_code'] : '') : '', $show !== 'teacher' ? $r['teacher_name'] : ''])))
                    . '</div></div>', $cell)) . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table></div>';
    }
}
