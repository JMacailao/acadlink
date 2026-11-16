<?php
declare(strict_types=1);
/**
 * v65.6: Auto-schedule a section (Class Schedules → Auto-schedule).
 * Takes the section's classes (subjects in Grading assignments) for the term, works out the hours each still needs —
 * lecture hours = lecture units × hours per lecture unit (1), laboratory hours = lab units × hours per lab unit (3) —
 * and puts them in free times: the section, the class's teacher and (when rooms are set up) a room must all be free
 * on every day of the meeting. Lectures use common patterns (MWF 1 hr, TTh 1.5 hrs, …); a laboratory is one block
 * (up to 3 hrs) a day. Nothing is saved here: the result is a draft the user changes and saves.
 */
final class ScheduleAuto
{
    public const DEFAULTS = ['days' => ['M', 'T', 'W', 'Th', 'F'], 'start' => '07:30', 'end' => '18:00', 'lunch' => true, 'lec_hours' => 1.0, 'lab_hours' => 3.0, 'rooms' => true];

    /** Reads the options of the draft form (GET), falling back to the defaults. */
    public static function options(array $in): array
    {
        $o = self::DEFAULTS;
        if (isset($in['o_go'])) {
            $days = Schedules::parseDays((array) ($in['o_wd'] ?? []));
            $o['days'] = array_values(array_diff($days, ['Su'])) ?: $o['days'];
            foreach (['start', 'end'] as $k) if (preg_match('/^\d{2}:\d{2}$/', (string) ($in['o_' . $k] ?? ''))) $o[$k] = (string) $in['o_' . $k];
            $o['lunch'] = !empty($in['o_lunch']);
            $o['rooms'] = !empty($in['o_rooms']);
            foreach (['lec_hours' => [0.5, 3], 'lab_hours' => [1, 6]] as $k => [$min, $max]) { $v = (float) ($in['o_' . $k] ?? $o[$k]); if ($v >= $min && $v <= $max) $o[$k] = round($v * 2) / 2; }
        }
        if (self::min($o['end']) - self::min($o['start']) < 120) { $o['start'] = self::DEFAULTS['start']; $o['end'] = self::DEFAULTS['end']; }
        return $o;
    }

    /**
     * The draft: ['subjects' => [per subject: code, name, lec, lab, total, need/have hours, teacher], 'rows' => [meetings],
     * 'unplaced' => [messages], 'none' => bool (no classes)].
     */
    public static function draft(string $section, string $sy, string $term, array $o, array $planned = []): array
    {
        $classes = GS::sectionAssignments($section, $sy, $term);
        $subjects = array_column(GS::subjects(), null, 'id');
        $size = Schedules::sectionSize($section, $sy, $term);
        $rooms = $o['rooms'] ? array_values(array_filter(Schedules::rooms(true), fn($r) => (int) $r['capacity'] >= $size)) : [];
        // what is already taken this term: [day, from, to, section, teacher, room]
        $busy = [];
        foreach (Db::all('SELECT section_doc_id, teacher_doc_id, room_id, days, start_time, end_time, subject_doc_id, kind FROM sis_class_schedules WHERE school_year = ? AND term = ?', [$sy, $term]) as $r)
            foreach (array_filter(explode(',', (string) $r['days'])) as $d) $busy[] = [$d, self::min($r['start_time']), self::min($r['end_time']), (string) $r['section_doc_id'], (string) ($r['teacher_doc_id'] ?? ''), (int) ($r['room_id'] ?? 0)];
        foreach ($planned as $b) $busy[] = $b; // v66: meetings already drafted for other sections in this run
        // hours this section already has per subject and kind
        $have = [];
        foreach (Db::all('SELECT subject_doc_id, kind, days, start_time, end_time FROM sis_class_schedules WHERE school_year = ? AND term = ? AND section_doc_id = ?', [$sy, $term, $section]) as $r) {
            $k = $r['kind'] === 'LAB' ? 'LAB' : 'LEC';
            $have[$r['subject_doc_id']][$k] = ($have[$r['subject_doc_id']][$k] ?? 0) + count(array_filter(explode(',', (string) $r['days']))) * (self::min($r['end_time']) - self::min($r['start_time'])) / 60;
        }
        $out = ['subjects' => [], 'rows' => [], 'unplaced' => [], 'none' => !$classes];
        $jobs = [];
        foreach ($classes as $sid => $c) {
            $s = $subjects[$sid] ?? null; if (!$s) continue;
            [$lec, $lab, $total] = LecLab::ofSubject($s);
            $teacher = (string) ($c['teacherDocId'] ?? ''); if ($teacher === ScheduleTeacherSync::PLACEHOLDER) $teacher = '';
            $need = ['LEC' => $lec * $o['lec_hours'], 'LAB' => $lab * $o['lab_hours']];
            $info = ['id' => $sid, 'code' => (string) ($s['subjectCode'] ?? ''), 'name' => (string) ($s['subjectName'] ?? ''), 'lec' => $lec, 'lab' => $lab, 'total' => $total,
                     'need_lec' => $need['LEC'], 'need_lab' => $need['LAB'], 'have_lec' => round($have[$sid]['LEC'] ?? 0, 2), 'have_lab' => round($have[$sid]['LAB'] ?? 0, 2),
                     'teacher' => $teacher, 'teacher_name' => $teacher !== '' ? (string) ($c['teacherName'] ?? '') : ''];
            $out['subjects'][] = $info;
            foreach (['LAB', 'LEC'] as $k) {
                $left = round($need[$k] - ($have[$sid][$k] ?? 0), 2);
                if ($left >= 0.5) $jobs[] = ['subject' => $info, 'kind' => $k, 'hours' => $left];
            }
        }
        // longest first (labs are long blocks), so they get the open spaces
        usort($jobs, fn($a, $b) => [$b['kind'] === 'LAB', $b['hours']] <=> [$a['kind'] === 'LAB', $a['hours']]);
        $turn = 0;
        foreach ($jobs as $j) {
            $placed = null;
            foreach (self::patterns($j['kind'], $j['hours'], $o['days'], $turn++) as [$days, $mins]) {
                $placed = self::find($days, $mins, $o, $busy, $section, $j['subject']['teacher'], $rooms, $j['kind']);
                if ($placed) {
                    [$from, $room] = $placed;
                    foreach ($days as $d) $busy[] = [$d, $from, $from + $mins, $section, $j['subject']['teacher'], (int) ($room['id'] ?? 0)];
                    $out['rows'][] = ['section' => $section, 'teacher' => $j['subject']['teacher'], 'subject' => $j['subject']['id'], 'code' => $j['subject']['code'], 'name' => $j['subject']['name'], 'kind' => $j['kind'], 'days' => $days,
                        'start' => self::hhmm($from), 'end' => self::hhmm($from + $mins), 'room' => (int) ($room['id'] ?? 0), 'teacher_name' => $j['subject']['teacher_name']];
                    break;
                }
            }
            if (!$placed) $out['unplaced'][] = $j['subject']['code'] . ' ' . ($j['kind'] === 'LAB' ? 'laboratory' : 'lecture') . ' (' . self::hoursText($j['hours']) . ' a week): no free time for the section' . ($j['subject']['teacher'] !== '' ? ' and ' . $j['subject']['teacher_name'] : '') . ($rooms ? ' with a free room' : '') . '. Add it by hand.';
        }
        usort($out['rows'], fn($a, $b) => [array_search($a['days'][0], array_keys(Schedules::DAYS)), $a['start']] <=> [array_search($b['days'][0], array_keys(Schedules::DAYS)), $b['start']]);
        return $out;
    }

    /**
     * v66: drafts for every section of the term, one after another, so later sections see the teachers and rooms the
     * earlier ones already use. Returns [section id => ['name' => …, 'draft' => draft()]] (sections without classes left out).
     */
    public static function draftAll(string $sy, string $term, array $o): array
    {
        $planned = []; $out = [];
        foreach (GS::sections($sy) as $sec) {
            $d = self::draft((string) $sec['id'], $sy, $term, $o, $planned);
            if ($d['none']) continue;
            foreach ($d['rows'] as $r) foreach ($r['days'] as $day) $planned[] = [$day, self::min($r['start']), self::min($r['end']), (string) $sec['id'], (string) $r['teacher'], (int) $r['room']];
            $out[(string) $sec['id']] = ['name' => (string) ($sec['sectionName'] ?? ''), 'draft' => $d];
        }
        return $out;
    }

    /** Ways to meet $hours a week: [[days], minutes per meeting], best first. $turn rotates MWF / TTh so the week is used evenly. */
    public static function patterns(string $kind, float $hours, array $allowed, int $turn = 0): array
    {
        $m = (int) round($hours * 60);
        $ok = fn(array $days) => !array_diff($days, $allowed);
        $list = [];
        if ($kind === 'LAB') {
            $n = (int) max(1, ceil($hours / 3));
            $per = (int) (ceil($m / $n / 30) * 30);
            $groups = $n === 1 ? array_map(fn($d) => [$d], $allowed) : ($n === 2 ? [['T', 'Th'], ['M', 'W'], ['W', 'F'], ['M', 'Th']] : [['M', 'W', 'F'], ['T', 'Th', 'S']]);
            foreach ($groups as $g) if ($ok($g)) $list[] = [$g, $per];
            return $list;
        }
        $three = [[['M', 'W', 'F'], (int) round($m / 3)], [['T', 'Th'], (int) round($m / 2)], [['T', 'Th', 'S'], (int) round($m / 3)]];
        $two = [[['T', 'Th'], (int) round($m / 2)], [['M', 'W'], (int) round($m / 2)], [['W', 'F'], (int) round($m / 2)], [['M', 'Th'], (int) round($m / 2)]];
        if ($m <= 90) foreach ($allowed as $d) $list[] = [[$d], $m];
        elseif ($m <= 120) { $list = array_merge($two, array_map(fn($d) => [[$d], $m], $allowed)); }
        else { $list = $turn % 2 ? array_merge([$three[1], $three[0]], array_slice($three, 2), $two) : array_merge($three, $two); }
        // meetings in steps of 30 minutes, at most 3 hours
        $list = array_values(array_filter(array_map(fn($p) => [$p[0], (int) (ceil($p[1] / 30) * 30)], $list), fn($p) => $ok($p[0]) && $p[1] >= 30 && $p[1] <= 180 && count($p[0]) * $p[1] >= $m - 15));
        // closest to the hours needed first (sorting keeps the order above for equal ones)
        usort($list, fn($x, $y) => abs(count($x[0]) * $x[1] - $m) <=> abs(count($y[0]) * $y[1] - $m));
        if ($m <= 90 && $turn % 2) $list = array_merge(array_slice($list, (int) floor(count($list) / 2)), array_slice($list, 0, (int) floor(count($list) / 2)));
        return $list;
    }

    /** The earliest start where the section, teacher and a room are free on all $days; [minutes, room row|null] or null. */
    private static function find(array $days, int $mins, array $o, array $busy, string $section, string $teacher, array $rooms, string $kind): ?array
    {
        $start = self::min($o['start']); $end = self::min($o['end']);
        $want = $kind === 'LAB' ? ['LAB'] : ['LECTURE', 'OTHER'];
        $fit = $rooms ? (array_values(array_filter($rooms, fn($r) => in_array($r['kind'], $want, true))) ?: $rooms) : [];
        for ($t = $start; $t + $mins <= $end; $t += 30) {
            if ($o['lunch'] && $t < 13 * 60 && $t + $mins > 12 * 60) continue;
            $clash = false;
            foreach ($busy as [$d, $a, $b, $sec, $tch]) if (in_array($d, $days, true) && $a < $t + $mins && $b > $t && ($sec === $section || ($teacher !== '' && $tch === $teacher))) { $clash = true; break; }
            if ($clash) continue;
            if (!$fit) return [$t, null];
            foreach ($fit as $r) {
                $taken = false;
                foreach ($busy as [$d, $a, $b, , , $room]) if ($room === (int) $r['id'] && in_array($d, $days, true) && $a < $t + $mins && $b > $t) { $taken = true; break; }
                if (!$taken) return [$t, $r];
            }
        }
        return null;
    }

    public static function min(string $t): int { [$h, $m] = array_map('intval', explode(':', $t) + [0, 0]); return $h * 60 + $m; }
    public static function hhmm(int $m): string { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
    public static function hoursText(float $h): string { return rtrim(rtrim(number_format($h, 1, '.', ''), '0'), '.') . ' hr' . ($h == 1.0 ? '' : 's'); }

    /** Saves the draft rows the user kept (all or none). Returns how many. */
    public static function save(string $section, string $sy, string $term, array $in): int
    {
        Auth::require('schedules.edit');
        $subs = array_values((array) ($in['a_subject'] ?? []));
        if (!$subs) throw new AppError('There is nothing to save. Make the draft again.');
        $keep = array_flip(array_map('strval', (array) ($in['a_keep'] ?? [])));
        $col = fn(string $k) => array_values((array) ($in[$k] ?? []));
        [$kinds, $days, $starts, $ends, $roomsIn, $secs] = [$col('a_kind'), $col('a_days'), $col('a_start'), $col('a_end'), $col('a_room'), $col('a_section')];
        $n = 0;
        Db::tx(function () use ($subs, $keep, $kinds, $days, $starts, $ends, $roomsIn, $secs, $section, $sy, $term, &$n) {
            foreach ($subs as $i => $sid) {
                if (!isset($keep[(string) $i])) continue;
                $s = GS::get('subjects', (string) $sid);
                try {
                    $rowSection = (string) ($secs[$i] ?? $section); // v66: Auto-schedule all sections sends each row's section
                    if ($rowSection === '' || !GS::get('sections', $rowSection)) throw new AppError('Choose the section.');
                    Schedules::save(null, ['school_year' => $sy, 'term' => $term, 'section_doc_id' => $rowSection, 'subject_doc_id' => (string) $sid, 'teacher_doc_id' => '',
                        'room_id' => (string) ($roomsIn[$i] ?? ''), 'days' => (string) ($days[$i] ?? ''), 'start_time' => (string) ($starts[$i] ?? ''), 'end_time' => (string) ($ends[$i] ?? ''),
                        'kind' => in_array($kinds[$i] ?? '', ['LEC', 'LAB'], true) ? $kinds[$i] : 'LEC']);
                } catch (AppError $e) { throw new AppError(($s['subjectCode'] ?? 'A subject') . ' (row ' . ($i + 1) . '): ' . $e->getMessage()); }
                $n++;
            }
        });
        if (!$n) throw new AppError('Tick at least one row to save.');
        return $n;
    }
}
