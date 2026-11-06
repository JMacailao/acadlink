<?php
declare(strict_types=1);

/**
 * v54: prerequisites when subjects are chosen (with a registrar override and its reason), and adding/dropping
 * subjects after the enrollment is confirmed (by the registrar). A dropped subject keeps part of its fees by
 * week of classes, counted from the term's classes start date: before 0%, week 1 25%, week 2 50%, after 100%.
 */
final class AddDrop
{
    public const SETTINGS = ['drop_charge_week1' => '25', 'drop_charge_week2' => '50', 'drop_charge_after' => '100'];

    public static function install(): void
    {
        Db::q("CREATE TABLE IF NOT EXISTS sis_prereq_overrides (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL, subject_doc_id VARCHAR(191) NOT NULL, prerequisite_code VARCHAR(40) NOT NULL DEFAULT '',
            reason VARCHAR(255) NOT NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY u_sis_prereq_override (registration_id, subject_doc_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        Db::q("CREATE TABLE IF NOT EXISTS sis_adddrop_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL, kind ENUM('ADD_DROP','WITHDRAW') NOT NULL DEFAULT 'ADD_DROP',
            added VARCHAR(500) NOT NULL DEFAULT '', dropped VARCHAR(500) NOT NULL DEFAULT '',
            charge_percent TINYINT UNSIGNED NOT NULL DEFAULT 0, charged DECIMAL(12,2) NOT NULL DEFAULT 0, total_before DECIMAL(12,2) NOT NULL, total_after DECIMAL(12,2) NOT NULL,
            reason VARCHAR(255) NOT NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY k_sis_adddrop_reg (registration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // ------------------------------------------------------------------ drop charge

    public static function percents(): array
    {
        $out = [];
        foreach (self::SETTINGS as $k => $def) {
            $v = Db::val('SELECT svalue FROM sis_settings WHERE skey = ?', [$k]);
            $out[$k] = $v === null || $v === false || $v === '' ? (int) $def : max(0, min(100, (int) $v));
        }
        return $out;
    }

    public static function savePercents(array $in): void
    {
        Auth::require('settings.manage');
        foreach (self::SETTINGS as $k => $_) {
            $v = trim((string) ($in[$k] ?? ''));
            if (!preg_match('/^\d{1,3}$/', $v) || (int) $v > 100) throw new AppError('Each percentage must be a whole number from 0 to 100.');
            Db::q('INSERT INTO sis_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$k, (string) (int) $v]);
        }
        Audit::log('drop_charges_saved', 'settings', 0, array_intersect_key($in, self::SETTINGS));
    }

    /** What part of a dropped subject's fees stays charged today: ['percent' => int, 'text' => "Week 2 of classes: 50% stays charged"]. */
    public static function charge(array $r, ?string $today = null): array
    {
        $start = (string) (Db::val('SELECT classes_start FROM sis_term_calendar WHERE school_year = ? AND term = ?', [$r['school_year'], $r['term']]) ?: '');
        $p = self::percents();
        if ($start === '') return ['percent' => $p['drop_charge_after'], 'text' => 'No classes start date is set for ' . $r['school_year'] . ' ' . $r['term'] . ' (Settings → Terms), so the "after week 2" rule applies: ' . $p['drop_charge_after'] . '% stays charged.'];
        $days = (int) floor((strtotime($today ?? date('Y-m-d')) - strtotime($start)) / 86400);
        if ($days < 0) return ['percent' => 0, 'text' => 'Classes start ' . date('M j, Y', strtotime($start)) . ': dropping now is free (0% charged).'];
        if ($days < 7) return ['percent' => $p['drop_charge_week1'], 'text' => 'Week 1 of classes (from ' . date('M j', strtotime($start)) . '): ' . $p['drop_charge_week1'] . '% of a dropped subject\'s fees stays charged.'];
        if ($days < 14) return ['percent' => $p['drop_charge_week2'], 'text' => 'Week 2 of classes: ' . $p['drop_charge_week2'] . '% of a dropped subject\'s fees stays charged.'];
        return ['percent' => $p['drop_charge_after'], 'text' => 'After week 2 of classes: ' . $p['drop_charge_after'] . '% of a dropped subject\'s fees stays charged.'];
    }

    // ------------------------------------------------------------------ prerequisites

    /** Subjects in $subjectIds whose prerequisite the student hasn't passed: [subject id => "NCM102 needs NCM101"]. */
    public static function unmet(string $studentDocId, string $sy, string $term, array $subjectIds): array
    {
        if (!$subjectIds) return [];
        $subjects = array_column(GS::subjects(), null, 'id');
        $passed = EnrollmentEligibility::passedSubjectCodes(GS::completions($studentDocId), $sy, $term);
        $out = [];
        foreach (Db::all('SELECT * FROM sis_subject_prerequisites ORDER BY id') as $rule) {
            if (!in_array($rule['subject_doc_id'], $subjectIds, true)) continue;
            $code = strtoupper(trim((string) ($subjects[$rule['prerequisite_doc_id']]['subjectCode'] ?? '')));
            if ($code !== '' && isset($passed[$code])) continue;
            $sc = (string) ($subjects[$rule['subject_doc_id']]['subjectCode'] ?? '?');
            $out[$rule['subject_doc_id']] = isset($out[$rule['subject_doc_id']]) ? $out[$rule['subject_doc_id']] . ', ' . ($code ?: '?') : "$sc needs " . ($code ?: 'a prerequisite');
        }
        return $out;
    }

    /** Refuses unmet prerequisites, or records the registrar's override (with the reason) for them. */
    public static function checkPrerequisites(int $regId, array $subjectIds, ?string $override): void
    {
        $r = Db::one('SELECT * FROM sis_registrations WHERE id = ?', [$regId]) ?: throw new AppError('Registration not found.');
        $docId = (string) ($r['student_doc_id'] ?: (GS::studentByNumber((string) $r['student_number'])['id'] ?? ''));
        $unmet = self::unmet($docId, $r['school_year'], $r['term'], $subjectIds);
        $already = array_column(Db::all('SELECT subject_doc_id FROM sis_prereq_overrides WHERE registration_id = ?', [$regId]), 'subject_doc_id');
        $unmet = array_diff_key($unmet, array_flip($already));
        if (!$unmet) return;
        $override = trim((string) $override);
        if ($override === '') throw new AppError("Prerequisite not passed:\n• " . implode("\n• ", $unmet) . "\nRemove the subject, or type a reason in \"Prerequisite override\" to allow it.");
        if (!Auth::can('registration')) throw new Forbidden('Only the registrar can override a prerequisite.');
        if (mb_strlen($override) < 5) throw new AppError('Prerequisite override: write a clear reason (at least 5 characters).');
        foreach ($unmet as $sid => $text) {
            Db::q('INSERT INTO sis_prereq_overrides (registration_id, subject_doc_id, prerequisite_code, reason, created_by) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_by = VALUES(created_by), created_at = NOW()', [$regId, $sid, mb_substr(preg_replace('/^.* needs /', '', $text), 0, 40), mb_substr($override, 0, 255), Auth::id()]);
            Audit::log('prerequisite_overridden', 'registration', $regId, ['subject' => $text, 'reason' => $override]);
        }
    }

    public static function overrides(int $regId): array
    {
        $subjects = array_column(GS::subjects(), null, 'id');
        return array_map(fn($o) => $o + ['subject_code' => (string) ($subjects[$o['subject_doc_id']]['subjectCode'] ?? '?')],
            Db::all('SELECT * FROM sis_prereq_overrides WHERE registration_id = ? ORDER BY id', [$regId]));
    }

    public static function history(int $regId): array
    {
        return Db::all('SELECT l.*, u.full_name FROM sis_adddrop_log l LEFT JOIN sis_users u ON u.id = l.created_by WHERE l.registration_id = ? ORDER BY l.id DESC', [$regId]);
    }
}
