<?php
declare(strict_types=1);

/**
 * v56: student classification and the registration document checklist.
 *
 *   Classification (sis_admissions.applicant_type): New Student, Continuing Student, Transferee, Unit Earner
 *   (and Returnee for readmission). Continuing students and returnees keep the Student ID they already have.
 *
 *   Document requirements (Settings → Document Requirements): each has a name, whether it is required, and the
 *   classifications it applies to. The admission form shows the matching checklist; the registrar ticks what was
 *   submitted. Missing required documents are listed (and counted in the admission list) but do not block
 *   admission, so a school can accept documents that follow later.
 */
final class EnrollmentDocs
{
    /** Default requirements of a fresh installation (and of an upgrade without any requirements yet). */
    public const DEFAULTS = [
        ['Form 137 / Permanent Student Record', 'NEW', 1],
        ['Form 138 / Report Card', 'NEW', 1],
        ['Certificate of Good Moral Character', 'NEW,TRANSFEREE', 1],
        ['PSA Birth Certificate', 'NEW,TRANSFEREE,UNIT_EARNER', 1],
        ['Transcript of Records (TOR)', 'TRANSFEREE,UNIT_EARNER', 1],
        ['Transfer Credentials / Honorable Dismissal', 'TRANSFEREE', 1],
        ['Recent ID Pictures', 'NEW,CONTINUING,TRANSFEREE,UNIT_EARNER,RETURNEE', 1],
        ['Other Documents', 'NEW,CONTINUING,TRANSFEREE,UNIT_EARNER,RETURNEE', 0],
    ];

    public static function install(): void
    {
        $type = (string) Db::val("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sis_admissions' AND column_name = 'applicant_type'");
        if ($type !== '' && !str_contains($type, 'UNIT_EARNER'))
            Db::q("ALTER TABLE sis_admissions MODIFY applicant_type ENUM('NEW','CONTINUING','TRANSFEREE','UNIT_EARNER','RETURNEE') NOT NULL DEFAULT 'NEW'");
        $had = (bool) Db::val("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sis_document_requirements'");
        Db::q('CREATE TABLE IF NOT EXISTS sis_document_requirements (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description VARCHAR(255) NULL,
            applies_to VARCHAR(120) NOT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT NOT NULL DEFAULT 100,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY u_doc_requirement (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        Db::q('CREATE TABLE IF NOT EXISTS sis_admission_documents (
            admission_id INT UNSIGNED NOT NULL,
            requirement_id INT UNSIGNED NOT NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            received_by INT UNSIGNED NULL,
            PRIMARY KEY (admission_id, requirement_id),
            KEY k_admdoc_req (requirement_id),
            CONSTRAINT fk_admdoc_adm FOREIGN KEY (admission_id) REFERENCES sis_admissions (id) ON DELETE CASCADE,
            CONSTRAINT fk_admdoc_req FOREIGN KEY (requirement_id) REFERENCES sis_document_requirements (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        if (!$had) foreach (self::DEFAULTS as $i => [$n, $types, $req])
            Db::q('INSERT IGNORE INTO sis_document_requirements (name, applies_to, is_required, sort_order) VALUES (?, ?, ?, ?)', [$n, $types, $req, ($i + 1) * 10]);
    }

    public static function types(): array { return Admission::CLASSIFICATIONS; }

    public static function requirements(bool $activeOnly = false): array
    {
        $rows = Db::all('SELECT * FROM sis_document_requirements' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name');
        foreach ($rows as &$r) {
            $r['types'] = array_values(array_filter(explode(',', (string) $r['applies_to'])));
            $r['used'] = (int) Db::val('SELECT COUNT(*) FROM sis_admission_documents WHERE requirement_id = ?', [$r['id']]);
        }
        unset($r);
        return $rows;
    }

    /** Active requirements that apply to one classification. */
    public static function forType(string $type): array
    {
        return array_values(array_filter(self::requirements(true), fn($r) => in_array($type, $r['types'], true)));
    }

    public static function saveRequirement(?int $id, array $in): int
    {
        Auth::require('settings.manage');
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) throw new AppError('Enter the document name (up to 150 characters).');
        $types = array_values(array_intersect(array_keys(Admission::TYPES), array_map('strval', (array) ($in['applies_to'] ?? []))));
        if (!$types) throw new AppError('Tick at least one student classification this document applies to.');
        $d = ['name' => $name, 'description' => In::str($in, 'description', 255), 'applies_to' => implode(',', $types),
            'is_required' => !empty($in['is_required']) ? 1 : 0, 'sort_order' => In::int($in, 'sort_order', false, 'Order', 1, 999) ?? 100, 'is_active' => !empty($in['is_active']) ? 1 : 0];
        return Db::tx(function () use ($id, $d) {
            $dup = Db::val('SELECT id FROM sis_document_requirements WHERE name = ?', [$d['name']]);
            if ($dup && (int) $dup !== (int) $id) throw new AppError('A document requirement with this name already exists. Edit that one instead.');
            if ($id) {
                if (!Db::val('SELECT 1 FROM sis_document_requirements WHERE id = ? FOR UPDATE', [$id])) throw new AppError('Document requirement not found.');
                Db::update('sis_document_requirements', $d, $id);
            } else $id = Db::insert('sis_document_requirements', $d);
            Audit::log('document_requirement_saved', 'document_requirement', $id, $d);
            return (int) $id;
        });
    }

    /** Removes a requirement. One already ticked on an applicant is turned off instead, so its history stays. */
    public static function removeRequirement(int $id): string
    {
        Auth::require('settings.manage');
        return Db::tx(function () use ($id) {
            $r = Db::one('SELECT * FROM sis_document_requirements WHERE id = ? FOR UPDATE', [$id]);
            if (!$r) throw new AppError('Document requirement not found.');
            if (Db::val('SELECT 1 FROM sis_admission_documents WHERE requirement_id = ? LIMIT 1', [$id])) {
                Db::q('UPDATE sis_document_requirements SET is_active = 0 WHERE id = ?', [$id]);
                Audit::log('document_requirement_turned_off', 'document_requirement', $id, $r);
                return 'off';
            }
            Db::q('DELETE FROM sis_document_requirements WHERE id = ?', [$id]);
            Audit::log('document_requirement_removed', 'document_requirement', $id, $r);
            return 'removed';
        });
    }

    /** Submitted documents of an applicant: requirement id => ['submitted_at', 'received_by_name']. */
    public static function submitted(int $admissionId): array
    {
        $out = [];
        foreach (Db::all('SELECT d.*, u.full_name FROM sis_admission_documents d LEFT JOIN sis_users u ON u.id = d.received_by WHERE d.admission_id = ?', [$admissionId]) as $d)
            $out[(int) $d['requirement_id']] = ['submitted_at' => $d['submitted_at'], 'received_by_name' => (string) ($d['full_name'] ?? '')];
        return $out;
    }

    /** Ticks the submitted documents (only requirements of the applicant's classification are kept). */
    public static function saveChecklist(int $admissionId, array $requirementIds): void
    {
        Auth::require('admission');
        Db::tx(function () use ($admissionId, $requirementIds) {
            $a = Db::one('SELECT * FROM sis_admissions WHERE id = ? FOR UPDATE', [$admissionId]);
            if (!$a) throw new AppError('Admission not found.');
            if (!in_array($a['status'], ['PENDING', 'ADMITTED'], true)) throw new AppError('Documents can be updated only for pending or admitted applicants.');
            $allowed = array_column(self::forType((string) $a['applicant_type']), 'id');
            // Documents ticked earlier under another classification stay on record
            $keepOld = array_map('intval', Db::col('SELECT requirement_id FROM sis_admission_documents WHERE admission_id = ?', [$admissionId]));
            $want = array_values(array_intersect(array_map('intval', $requirementIds), array_map('intval', $allowed)));
            $before = $keepOld;
            foreach (array_diff(array_intersect($keepOld, array_map('intval', $allowed)), $want) as $gone)
                Db::q('DELETE FROM sis_admission_documents WHERE admission_id = ? AND requirement_id = ?', [$admissionId, $gone]);
            foreach (array_diff($want, $keepOld) as $new)
                Db::q('INSERT INTO sis_admission_documents (admission_id, requirement_id, received_by) VALUES (?, ?, ?)', [$admissionId, $new, Auth::id()]);
            if (array_values(array_diff($want, $before)) || array_values(array_diff(array_intersect($before, array_map('intval', $allowed)), $want)))
                Audit::log('admission_documents_saved', 'admission', $admissionId, ['submitted' => $want]);
        });
    }

    /** Required documents of the applicant's classification not yet submitted (names). */
    public static function missing(array $admission): array
    {
        $have = self::submitted((int) $admission['id']);
        $out = [];
        foreach (self::forType((string) $admission['applicant_type']) as $r) if ((int) $r['is_required'] && !isset($have[(int) $r['id']])) $out[] = $r['name'];
        return $out;
    }

    /** "3 of 5 required" for lists, plus the missing names. */
    public static function progress(array $admission): array
    {
        $have = self::submitted((int) $admission['id']);
        $req = array_filter(self::forType((string) $admission['applicant_type']), fn($r) => (int) $r['is_required']);
        $done = count(array_filter($req, fn($r) => isset($have[(int) $r['id']])));
        return ['done' => $done, 'required' => count($req), 'missing' => array_values(array_map(fn($r) => $r['name'], array_filter($req, fn($r) => !isset($have[(int) $r['id']]))))];
    }
}
