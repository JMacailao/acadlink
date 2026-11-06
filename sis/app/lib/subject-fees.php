<?php
declare(strict_types=1);

/**
 * Fees that belong to one subject, charged per unit of that subject (for example a laboratory fee of
 * ₱200 per unit of NCM101). Whenever the subject is on a registration, its assessment gets
 * "Laboratory fee · NCM101  5 × ₱200.00".
 *
 * v56 (Fee Management):
 *   • Every subject is listed with a Price per Unit; a subject without a price shows ₱0.00.
 *     Total = Subject Units × Price per Unit. A subject never needs a fee when it is created.
 *   • Edit changes the existing price in place (never a duplicate).
 *   • Fee templates charge only the subject fees ticked for them (sis_assessment_name_subject_fees).
 *     A template's own subject fee (assessment_name_id = the template) always applies to it.
 *     Upgrading ticks every existing price in every existing template, so totals do not change.
 *   • A registration without a fee template still gets the Fee Management prices, as before.
 *   • Saving a price recomputes open (not yet confirmed) registrations with that subject that have no
 *     payments; confirmed enrollments keep the assessment they were confirmed with.
 */
final class SubjectFees
{
    public static function install(): void
    {
        Db::q('CREATE TABLE IF NOT EXISTS sis_subject_fees (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            subject_doc_id VARCHAR(191) NOT NULL,
            assessment_name_id INT UNSIGNED NULL,
            label_id INT UNSIGNED NOT NULL,
            rate DECIMAL(12,2) NOT NULL,
            created_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY u_subject_fee (subject_doc_id, label_id, assessment_name_id),
            CONSTRAINT fk_subject_fee_label FOREIGN KEY (label_id) REFERENCES sis_fee_labels (id),
            CONSTRAINT fk_subject_fee_name FOREIGN KEY (assessment_name_id) REFERENCES sis_assessment_names (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        // v48: subject fees of one fee template
        if (!Db::val("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sis_subject_fees' AND column_name = 'assessment_name_id'")) {
            Db::q('ALTER TABLE sis_subject_fees ADD COLUMN assessment_name_id INT UNSIGNED NULL AFTER subject_doc_id, DROP INDEX u_subject_fee,
                   ADD UNIQUE KEY u_subject_fee (subject_doc_id, label_id, assessment_name_id),
                   ADD CONSTRAINT fk_subject_fee_name FOREIGN KEY (assessment_name_id) REFERENCES sis_assessment_names (id) ON DELETE CASCADE');
        }
        // v56: which Fee Management prices each fee template charges (ticked by hand)
        $hadTicks = (bool) Db::val("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sis_assessment_name_subject_fees'");
        if (!$hadTicks) {
            Db::q('CREATE TABLE IF NOT EXISTS sis_assessment_name_subject_fees (
                assessment_name_id INT UNSIGNED NOT NULL,
                subject_fee_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (assessment_name_id, subject_fee_id),
                KEY k_ansf_fee (subject_fee_id),
                CONSTRAINT fk_ansf_name FOREIGN KEY (assessment_name_id) REFERENCES sis_assessment_names (id) ON DELETE CASCADE,
                CONSTRAINT fk_ansf_fee FOREIGN KEY (subject_fee_id) REFERENCES sis_subject_fees (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            // Before v56 every Fee Management price applied to every template: keep exactly that.
            Db::q('INSERT IGNORE INTO sis_assessment_name_subject_fees (assessment_name_id, subject_fee_id)
                   SELECT n.id, f.id FROM sis_assessment_names n JOIN sis_subject_fees f ON f.assessment_name_id IS NULL');
        }
    }

    // ------------------------------------------------------------ reading

    /** Subject fees with the subject, fee and template names. $nameId: only that template's own fees. */
    public static function all(?int $nameId = null): array
    {
        $subjects = array_column(GS::subjects(), null, 'id');
        $rows = Db::all('SELECT f.*, l.name AS label_name, l.is_active AS label_active, c.name AS category_name, c.sort_order, n.name AS template_name
                           FROM sis_subject_fees f JOIN sis_fee_labels l ON l.id = f.label_id JOIN sis_fee_categories c ON c.id = l.category_id
                           LEFT JOIN sis_assessment_names n ON n.id = f.assessment_name_id'
                        . ($nameId !== null ? ' WHERE f.assessment_name_id = ?' : '') . ' ORDER BY c.sort_order, l.name', $nameId !== null ? [$nameId] : []);
        foreach ($rows as &$r) {
            $s = $subjects[$r['subject_doc_id']] ?? null;
            $r['subject_code'] = (string) ($s['subjectCode'] ?? '?');
            $r['subject_name'] = (string) ($s['subjectName'] ?? 'Subject no longer exists');
            $r['subject_units'] = $s['units'] ?? null;
            $r['basis_units'] = $s ? LecLab::basisUnits(LecLab::ofSubject($s), (string) ($r['unit_basis'] ?? 'ALL')) : 0.0;
        }
        unset($r);
        usort($rows, fn($a, $b) => strcmp($a['subject_code'], $b['subject_code']) ?: $a['sort_order'] <=> $b['sort_order']);
        return $rows;
    }

    /** Fee Management prices (not tied to one template), with the templates that charge each. */
    public static function catalog(): array
    {
        $ticks = [];
        foreach (Db::all('SELECT t.subject_fee_id, n.id, n.name FROM sis_assessment_name_subject_fees t JOIN sis_assessment_names n ON n.id = t.assessment_name_id ORDER BY n.name') as $t)
            $ticks[(int) $t['subject_fee_id']][] = ['id' => (int) $t['id'], 'name' => $t['name']];
        $out = [];
        foreach (self::all() as $f) {
            if ($f['assessment_name_id'] !== null) continue;
            $f['templates'] = $ticks[(int) $f['id']] ?? [];
            $out[] = $f;
        }
        return $out;
    }

    /**
     * Fee Management list: every subject with its prices. A subject without any price gets one row with
     * ₱0.00 (no fee yet), so newly created subjects appear here automatically.
     * Each row: subject_doc_id, subject_code, subject_name, units, fee (catalog row or null), rate_cents, total_cents.
     */
    public static function management(): array
    {
        $bySubject = [];
        foreach (self::catalog() as $f) $bySubject[$f['subject_doc_id']][] = $f;
        $out = [];
        foreach (GS::subjects() as $s) {
            $units = (string) (float) ($s['units'] ?? 0);
            $split = LecLab::ofSubject($s);
            $base = ['subject_doc_id' => $s['id'], 'subject_code' => (string) ($s['subjectCode'] ?? ''), 'subject_name' => (string) ($s['subjectName'] ?? ''), 'units' => $units, 'lec' => $split[0], 'lab' => $split[1]];
            foreach ($bySubject[$s['id']] ?? [null] as $f) {
                $rate = $f ? Money::cents($f['rate']) : 0;
                $qty = $f ? LecLab::basisUnits($split, (string) ($f['unit_basis'] ?? 'ALL')) : (float) $units;
                $out[] = $base + ['fee' => $f, 'rate_cents' => $rate, 'basis_units' => $qty, 'total_cents' => Money::times($rate, (string) $qty)];
            }
        }
        return $out;
    }

    /** Ids of the Fee Management prices ticked for a template. */
    public static function ticks(int $nameId): array
    {
        return array_map('intval', Db::col('SELECT subject_fee_id FROM sis_assessment_name_subject_fees WHERE assessment_name_id = ?', [$nameId]));
    }

    /** The fee used when a price is first set for a subject: the saved choice, else a tuition-like per-unit fee. */
    public static function defaultLabel(): ?int
    {
        $saved = (int) Db::val("SELECT svalue FROM sis_settings WHERE skey = 'subject_fee_label_id'");
        if ($saved && Db::val('SELECT 1 FROM sis_fee_labels WHERE id = ? AND is_active = 1', [$saved])) return $saved;
        $id = Db::val("SELECT l.id FROM sis_fee_labels l JOIN sis_fee_categories c ON c.id = l.category_id WHERE l.is_active = 1 AND c.is_active = 1
                        ORDER BY (l.charge_type = 'PER_UNIT') DESC, (l.name LIKE '%tuition%') DESC, (c.name LIKE '%tuition%') DESC, c.sort_order, l.name LIMIT 1");
        return $id ? (int) $id : null;
    }

    public static function setDefaultLabel(int $labelId): void
    {
        Auth::require('assessment.edit');
        if (!Db::val('SELECT 1 FROM sis_fee_labels WHERE id = ? AND is_active = 1', [$labelId])) throw new AppError('Choose an active fee.');
        Db::q("INSERT INTO sis_settings (skey, svalue) VALUES ('subject_fee_label_id', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)", [(string) $labelId]);
        Audit::log('subject_fee_default_label', 'settings', 'subject_fee_label_id', ['label_id' => $labelId]);
    }

    // ------------------------------------------------------------ changing

    /**
     * Adds or edits the price of a subject. With 'id' the existing row is changed in place (Edit).
     * Price per unit may be ₱0.00 (blank = ₱0.00). For a Fee Management price (no template),
     * 'templates' (array of template ids) are the fee templates that charge it, when sent ('templates_sent').
     * Returns ['id', 'recomputed', 'skipped'].
     */
    public static function save(array $in): array
    {
        Auth::require('assessment.edit');
        return Db::tx(function () use ($in) {
            $editId = In::int($in, 'id');
            $before = $editId ? Db::one('SELECT * FROM sis_subject_fees WHERE id = ? FOR UPDATE', [$editId]) : null;
            if ($editId && !$before) throw new AppError('That subject fee no longer exists. Reload the page.');
            $name = $before ? ($before['assessment_name_id'] === null ? null : (int) $before['assessment_name_id']) : In::int($in, 'assessment_name_id');
            if ($name !== null && !Db::val('SELECT 1 FROM sis_assessment_names WHERE id = ?', [$name])) throw new AppError('Fee template not found.');
            $subject = $before ? (string) $before['subject_doc_id'] : (string) ($in['subject_doc_id'] ?? '');
            if ($subject === '' || !GS::get('subjects', $subject)) throw new AppError('Choose the subject.');
            $label = In::int($in, 'label_id') ?? ($before ? (int) $before['label_id'] : self::defaultLabel());
            if (!$label) throw new AppError('Choose the fee (Assessment → Labels). Add a label such as "Tuition fee" first if there is none.');
            if (!Db::val('SELECT 1 FROM sis_fee_labels WHERE id = ? AND is_active = 1', [$label])) throw new AppError('Choose an active fee.');
            $basis = (string) ($in['unit_basis'] ?? ($before['unit_basis'] ?? 'ALL'));
            if (!isset(LecLab::BASIS[$basis])) throw new AppError('Choose how the fee is charged: all, lecture or laboratory units.');
            $rateIn = trim((string) ($in['rate'] ?? ''));
            $cents = $rateIn === '' ? 0 : (int) In::money($in, 'rate', true, 'Price per unit', false);
            if ($cents < 0) throw new AppError('Price per unit cannot be negative. Enter ₱0.00 or more.');
            // NULL (all templates) never counts as a duplicate in a unique key, so look the row up first
            $same = Db::val('SELECT id FROM sis_subject_fees WHERE subject_doc_id = ? AND label_id = ? AND assessment_name_id <=> ?', [$subject, $label, $name]);
            if ($before) {
                if ($same && (int) $same !== $editId) throw new AppError('This subject already has that fee. Edit that row instead of creating a second one.');
                if (LecLab::ready('sis_subject_fees')) Db::q('UPDATE sis_subject_fees SET label_id = ?, rate = ?, unit_basis = ? WHERE id = ?', [$label, Money::dec($cents), $basis, $editId]);
                else Db::q('UPDATE sis_subject_fees SET label_id = ?, rate = ? WHERE id = ?', [$label, Money::dec($cents), $editId]);
                $id = $editId;
            } elseif ($same) {
                if (LecLab::ready('sis_subject_fees')) Db::q('UPDATE sis_subject_fees SET rate = ?, unit_basis = ? WHERE id = ?', [Money::dec($cents), $basis, $same]);
                else Db::q('UPDATE sis_subject_fees SET rate = ? WHERE id = ?', [Money::dec($cents), $same]);
                $id = (int) $same;
            } else {
                $id = (int) Db::insert('sis_subject_fees', ['subject_doc_id' => $subject, 'assessment_name_id' => $name, 'label_id' => $label, 'rate' => Money::dec($cents), 'created_by' => Auth::id()] + (LecLab::ready('sis_subject_fees') ? ['unit_basis' => $basis] : []));
            }
            if ($name === null && !empty($in['templates_sent'])) {
                $want = array_values(array_unique(array_filter(array_map('intval', (array) ($in['templates'] ?? [])))));
                if ($want && count($want) !== (int) Db::val('SELECT COUNT(*) FROM sis_assessment_names WHERE id IN ' . Db::in($want), $want)) throw new AppError('Choose existing fee templates.');
                Db::q('DELETE FROM sis_assessment_name_subject_fees WHERE subject_fee_id = ?', [$id]);
                foreach ($want as $t) Db::q('INSERT INTO sis_assessment_name_subject_fees (assessment_name_id, subject_fee_id) VALUES (?, ?)', [$t, $id]);
            }
            Audit::log($before ? 'subject_fee_updated' : 'subject_fee_saved', 'subject', 0, ['id' => $id, 'subject' => $subject, 'template' => $name, 'label_id' => $label, 'rate' => Money::dec($cents), 'before' => $before]);
            [$done, $skipped] = self::refreshOpen([$subject]);
            return ['id' => $id, 'recomputed' => $done, 'skipped' => $skipped];
        });
    }

    /** Tick by hand the Fee Management prices a template charges. */
    public static function setTicks(int $nameId, array $feeIds): array
    {
        Auth::require('assessment.edit');
        return Db::tx(function () use ($nameId, $feeIds) {
            if (!Db::val('SELECT 1 FROM sis_assessment_names WHERE id = ? FOR UPDATE', [$nameId])) throw new AppError('Fee template not found.');
            $want = array_values(array_unique(array_filter(array_map('intval', $feeIds))));
            if ($want && count($want) !== (int) Db::val('SELECT COUNT(*) FROM sis_subject_fees WHERE assessment_name_id IS NULL AND id IN ' . Db::in($want), $want))
                throw new AppError('A ticked subject fee no longer exists. Reload the page.');
            $old = self::ticks($nameId);
            Db::q('DELETE FROM sis_assessment_name_subject_fees WHERE assessment_name_id = ?', [$nameId]);
            foreach ($want as $f) Db::q('INSERT INTO sis_assessment_name_subject_fees (assessment_name_id, subject_fee_id) VALUES (?, ?)', [$nameId, $f]);
            $changed = array_merge(array_diff($want, $old), array_diff($old, $want));
            Audit::log('template_subject_fees_ticked', 'assessment_name', $nameId, ['ticked' => $want]);
            $subjects = $changed ? Db::col('SELECT DISTINCT subject_doc_id FROM sis_subject_fees WHERE id IN ' . Db::in($changed), $changed) : [];
            [$done, $skipped] = self::refreshOpen($subjects, $nameId);
            return ['ticked' => count($want), 'recomputed' => $done, 'skipped' => $skipped];
        });
    }

    public static function delete(int $id): array
    {
        Auth::require('assessment.edit');
        return Db::tx(function () use ($id) {
            $row = Db::one('SELECT * FROM sis_subject_fees WHERE id = ? FOR UPDATE', [$id]);
            if (!$row) throw new AppError('Subject fee not found.');
            Db::q('DELETE FROM sis_subject_fees WHERE id = ?', [$id]);
            Audit::log('subject_fee_removed', 'subject', 0, $row);
            return self::refreshOpen([(string) $row['subject_doc_id']]);
        });
    }

    /**
     * Recompute open registrations that take one of these subjects (optionally only those on one template),
     * so the assessment follows the new price. Registrations with payments, a published portal assessment
     * or a total that would fall below what was paid are left for the registrar (counted as skipped).
     */
    public static function refreshOpen(array $subjectIds, ?int $nameId = null): array
    {
        $subjectIds = array_values(array_unique(array_filter($subjectIds, 'is_string')));
        if (!$subjectIds) return [0, 0];
        $args = $subjectIds;
        $sql = "SELECT DISTINCT r.id FROM sis_registrations r JOIN sis_registration_subjects s ON s.registration_id = r.id
                 WHERE r.status <> 'CANCELLED' AND r.locked_at IS NULL AND r.record_origin = 'ENROLLMENT' AND s.subject_doc_id IN " . Db::in($subjectIds);
        if ($nameId !== null) { $sql .= ' AND r.assessment_name_id = ?'; $args[] = $nameId; }
        $done = 0; $skipped = 0;
        foreach (Db::col($sql, $args) as $rid) {
            $rid = (int) $rid;
            $paid = Db::val('SELECT 1 FROM sis_payments WHERE registration_id = ? LIMIT 1', [$rid]);
            $published = Db::val("SELECT 1 FROM sis_portal_enrollments WHERE registration_id = ? AND status = 'ACCEPTED' AND assessment_published_at IS NOT NULL LIMIT 1", [$rid]);
            if ($paid || $published) { $skipped++; continue; }
            try { Registration::recompute($rid); $done++; } catch (AppError) { $skipped++; }
        }
        return [$done, $skipped];
    }

    // ------------------------------------------------------------ assessment

    /**
     * Assessment lines for the subjects of a registration: fee × the subject's units.
     * Same shape as Assessment::line(); a subject's fee is on its own line, named after the subject.
     * With a fee template ($nameId): its own subject fees, plus the Fee Management prices ticked for it.
     * Without a template: the Fee Management prices (as before v56). Lines that come to ₱0.00 are not
     * booked; the subject still shows on the statement with its ₱0.00 price (see perSubject()).
     */
    public static function lines(array $subjects, ?int $nameId = null): array
    {
        if (!$subjects) return [];
        $ids = array_values(array_unique(array_column($subjects, 'subject_doc_id')));
        // Before the v56 upgrade has run (tick table missing): every Fee Management price applies, as in v55.
        static $ticksReady = null;
        $ticksReady ??= (bool) Db::val("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sis_assessment_name_subject_fees'");
        $scope = $nameId === null || !$ticksReady
            ? ($nameId === null ? 'f.assessment_name_id IS NULL' : '(f.assessment_name_id IS NULL OR f.assessment_name_id = ?)')
            : '(f.assessment_name_id = ? OR (f.assessment_name_id IS NULL AND EXISTS (SELECT 1 FROM sis_assessment_name_subject_fees t WHERE t.subject_fee_id = f.id AND t.assessment_name_id = ?)))';
        $rows = Db::all('SELECT f.*, l.name AS label_name, c.name AS category_name, c.sort_order FROM sis_subject_fees f
                           JOIN sis_fee_labels l ON l.id = f.label_id AND l.is_active = 1 JOIN sis_fee_categories c ON c.id = l.category_id AND c.is_active = 1
                          WHERE f.subject_doc_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND ' . $scope,
                        array_merge($ids, $nameId === null ? [] : ($ticksReady ? [$nameId, $nameId] : [$nameId])));
        // The template's own rate replaces the Fee Management rate for the same subject and fee
        $fees = [];
        foreach ($rows as $f) {
            $k = $f['subject_doc_id'] . '|' . $f['label_id'];
            if (!isset($fees[$k]) || $f['assessment_name_id'] !== null) $fees[$k] = $f;
        }
        $out = [];
        foreach ($subjects as $s) {
            foreach ($fees as $f) {
                if ($f['subject_doc_id'] !== $s['subject_doc_id']) continue;
                $rate = Money::cents($f['rate']);
                $qty = LecLab::basisUnits(LecLab::ofRow($s), (string) ($f['unit_basis'] ?? 'ALL')); // v56.2: all, lecture or laboratory units
                $amount = Money::times($rate, (string) $qty);
                if ($amount <= 0) continue;
                $out[] = ['label_id' => -(int) $f['id'], 'category_name' => $f['category_name'],
                    'label_name' => mb_substr($f['label_name'] . ' · ' . $s['subject_code'], 0, 100), 'charge_type' => 'PER_UNIT',
                    'quantity' => (string) $qty, 'unit_cents' => $rate, 'amount_cents' => $amount, 'sort_order' => (int) $f['sort_order']];
            }
        }
        return $out;
    }

    /**
     * Per subject of a registration, from its saved assessment lines: price per unit and amount
     * (Units × Price per Unit). Subjects without a subject fee show ₱0.00, so every subject is visible.
     */
    public static function perSubject(array $subs, array $items): array
    {
        $out = [];
        foreach ($subs as $s) {
            $suffix = ' · ' . (string) ($s['subject_code'] ?? '');
            $rate = 0; $amount = 0;
            foreach ($items as $i) {
                if (($i['charge_type'] ?? '') !== 'PER_UNIT' || !str_ends_with((string) $i['label_name'], $suffix)) continue;
                $rate += Money::cents($i['unit_amount']); $amount += Money::cents($i['amount']);
            }
            $out[(string) $s['subject_doc_id']] = ['rate_cents' => $rate, 'amount_cents' => $amount];
        }
        return $out;
    }
}

/**
 * v56.1 Fee Templates → Manage fees: ONE list of every fee. General fees (Assessment → Labels) and subject fees
 * (Assessment → Subject Fees) are ticked to be charged by the template; a ticked fee shows its price:
 *   • general fee: the amount (blank = the label's default) and how it is charged;
 *   • subject fee: its price per unit from Subject Fees, the same in every template (shown, not edited here).
 * Saving stores the same records as before (fee lines, ticks, template subject prices), so assessments compute
 * exactly as they did. Open assessments of the template without payments are recalculated.
 */
final class TemplateFees
{
    /** ['general' => rows, 'subjects' => rows] for the Manage fees list. */
    public static function rows(int $nameId): array
    {
        $items = [];
        foreach (Assessment::items($nameId) as $i) $items[(int) $i['label_id']] = $i;
        $general = [];
        foreach (Assessment::labels() as $l) {
            $it = $items[(int) $l['id']] ?? null;
            if (!$it && (!(int) $l['is_active'] || !self::categoryActive((int) $l['category_id']))) continue;
            $general[] = ['label_id' => (int) $l['id'], 'name' => $l['name'], 'category' => $l['category_name'], 'on' => (bool) $it,
                'charge_type' => $it ? $it['charge_type'] : $l['charge_type'], 'default_cents' => Money::cents($l['default_amount']),
                'amount_cents' => $it ? Money::cents($it['amount']) : Money::cents($l['default_amount']), 'active' => (bool) $l['is_active']];
        }
        $ticked = array_flip(SubjectFees::ticks($nameId));
        $own = [];
        foreach (SubjectFees::all($nameId) as $f) $own[$f['subject_doc_id'] . '|' . $f['label_id']] = $f;
        $subjects = [];
        foreach (SubjectFees::catalog() as $f) {
            $k = $f['subject_doc_id'] . '|' . $f['label_id'];
            $o = $own[$k] ?? null; unset($own[$k]);
            $subjects[] = ['key' => 'c' . $f['id'], 'subject_code' => $f['subject_code'], 'subject_name' => $f['subject_name'], 'units' => LecLab::text((float) $f['basis_units']),
                'fee' => $f['label_name'] . (($f['unit_basis'] ?? 'ALL') !== 'ALL' ? ' (per ' . (($f['unit_basis'] === 'LAB') ? 'lab' : 'lecture') . ' unit)' : ''), 'on' => isset($ticked[(int) $f['id']]) || $o !== null, 'normal_cents' => Money::cents($f['rate']),
                'price_cents' => $o ? Money::cents($o['rate']) : null];
        }
        foreach ($own as $o) // a price set only for this template (no Subject Fees price for that subject and fee)
            $subjects[] = ['key' => 'o' . $o['id'], 'subject_code' => $o['subject_code'], 'subject_name' => $o['subject_name'], 'units' => LecLab::text((float) $o['basis_units']),
                'fee' => $o['label_name'], 'on' => true, 'normal_cents' => null, 'price_cents' => Money::cents($o['rate'])];
        usort($subjects, fn($a, $b) => strcmp($a['subject_code'], $b['subject_code']) ?: strcmp($a['fee'], $b['fee']));
        return ['general' => $general, 'subjects' => $subjects];
    }

    private static function categoryActive(int $id): bool
    {
        static $active = null;
        $active ??= array_map('intval', Db::col('SELECT id FROM sis_fee_categories WHERE is_active = 1'));
        return in_array($id, $active, true);
    }

    private static function price(string $v, string $label): ?int
    {
        $v = trim(str_replace(['₱', ',', ' '], '', $v));
        if ($v === '') return null;
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $v)) throw new AppError("$label: enter ₱0.00 or more with at most two decimals.");
        return Money::cents($v);
    }

    /** Saves the whole list. $in['gen'][label id] = [on, amount, charge]; $in['sub'][key] = [on, price]. */
    public static function save(int $nameId, array $in): array
    {
        Auth::require('assessment.edit');
        return Db::tx(function () use ($nameId, $in) {
            if (!Db::val('SELECT 1 FROM sis_assessment_names WHERE id = ? FOR UPDATE', [$nameId])) throw new AppError('Fee template not found.');
            $rows = self::rows($nameId);
            $gen = (array) ($in['gen'] ?? []); $sub = (array) ($in['sub'] ?? []);
            $items = [];
            foreach (Assessment::items($nameId) as $i) $items[(int) $i['label_id']] = (int) $i['id'];
            $count = ['general' => 0, 'subjects' => 0];
            foreach ($rows['general'] as $r) {
                $g = (array) ($gen[$r['label_id']] ?? []);
                if (!empty($g['on'])) {
                    $charge = isset(CHARGE_TYPES[(string) ($g['charge'] ?? '')]) ? (string) $g['charge'] : $r['charge_type'];
                    $cents = self::price((string) ($g['amount'] ?? ''), $r['name']) ?? $r['default_cents'];
                    Assessment::setItem($nameId, $r['label_id'], $charge, $cents);
                    $count['general']++;
                } elseif (isset($items[$r['label_id']])) Assessment::removeItem($nameId, $items[$r['label_id']]);
            }
            $ticks = [];
            foreach ($rows['subjects'] as $r) {
                $s = (array) ($sub[$r['key']] ?? []);
                $on = !empty($s['on']);
                $id = (int) substr($r['key'], 1);
                if ($r['key'][0] === 'c') {
                    $f = Db::one('SELECT * FROM sis_subject_fees WHERE id = ?', [$id]);
                    if (!$f) continue;
                    $ownId = Db::val('SELECT id FROM sis_subject_fees WHERE subject_doc_id = ? AND label_id = ? AND assessment_name_id = ?', [$f['subject_doc_id'], $f['label_id'], $nameId]);
                    // v56.1: subject fees cost the same in every template (price set in Subject Fees). An older
                    // template price stays while the fee is ticked and is removed when it is unticked.
                    if ($on) { $ticks[] = $id; $count['subjects']++; }
                    elseif ($ownId) Db::q('DELETE FROM sis_subject_fees WHERE id = ?', [$ownId]);
                } else {
                    if (!$on) { Db::q('DELETE FROM sis_subject_fees WHERE id = ? AND assessment_name_id = ?', [$id, $nameId]); continue; }
                    $count['subjects']++;
                }
            }
            Db::q('DELETE FROM sis_assessment_name_subject_fees WHERE assessment_name_id = ?', [$nameId]);
            foreach ($ticks as $t) Db::q('INSERT INTO sis_assessment_name_subject_fees (assessment_name_id, subject_fee_id) VALUES (?, ?)', [$nameId, $t]);
            Audit::log('template_fees_saved', 'assessment_name', $nameId, $count + ['ticked' => $ticks]);
            [$done, $skipped] = self::refresh($nameId);
            return $count + ['recomputed' => $done, 'skipped' => $skipped];
        });
    }

    /** Recalculates open assessments of the template that have no payments (others keep their amount). */
    public static function refresh(int $nameId): array
    {
        $done = 0; $skipped = 0;
        foreach (Db::col("SELECT id FROM sis_registrations WHERE assessment_name_id = ? AND status <> 'CANCELLED' AND locked_at IS NULL AND record_origin = 'ENROLLMENT'", [$nameId]) as $rid) {
            $rid = (int) $rid;
            if (Db::val('SELECT 1 FROM sis_payments WHERE registration_id = ? LIMIT 1', [$rid]) || Db::val("SELECT 1 FROM sis_portal_enrollments WHERE registration_id = ? AND status = 'ACCEPTED' AND assessment_published_at IS NOT NULL LIMIT 1", [$rid])) { $skipped++; continue; }
            try { Registration::recompute($rid); $done++; } catch (AppError) { $skipped++; }
        }
        return [$done, $skipped];
    }
}


/**
 * v56.2 Settings → "Separate lecture and laboratory units". Subjects keep their total Units (as before) plus
 * lecUnits / labUnits; a registration keeps a copy (lec_units / lab_units). A subject fee may be charged per all
 * units, per lecture unit or per laboratory unit (sis_subject_fees.unit_basis). Without a valid split the subject
 * counts as all lecture, so old records and subjects edited only in Grading still compute the same as before.
 */
final class LecLab
{
    public const BASIS = ['ALL' => 'All units', 'LEC' => 'Lecture units', 'LAB' => 'Laboratory units'];
    private static bool $fresh = false;

    public static function on(): bool { return Settings::on('asm_split_lec_lab'); }

    /** True once the v56.2 columns exist (the upgrade runs when the SIS opens; until then nothing new is written). */
    public static function ready(string $table): bool
    {
        static $cache = [];
        if (self::$fresh) { $cache = []; self::$fresh = false; }
        return $cache[$table] ??= (bool) Db::val('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $table === 'sis_subject_fees' ? 'unit_basis' : 'lec_units']);
    }

    public static function install(): void
    {
        $col = fn(string $t, string $c) => (bool) Db::val('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
        if (!$col('sis_registration_subjects', 'lec_units')) Db::q('ALTER TABLE sis_registration_subjects ADD COLUMN lec_units DECIMAL(4,1) NULL AFTER units, ADD COLUMN lab_units DECIMAL(4,1) NULL AFTER lec_units');
        if (!$col('sis_subject_fees', 'unit_basis')) Db::q("ALTER TABLE sis_subject_fees ADD COLUMN unit_basis ENUM('ALL','LEC','LAB') NOT NULL DEFAULT 'ALL' AFTER rate");
        self::$fresh = true; // columns may have just been added: check again
    }

    private static function num(mixed $v): ?float { return is_numeric($v) ? round((float) $v, 1) : null; }

    /** [lecture, laboratory, total] units of a subject record (grading system doc). */
    public static function ofSubject(array $s): array
    {
        $u = (float) ($s['units'] ?? 0); $lec = self::num($s['lecUnits'] ?? null); $lab = self::num($s['labUnits'] ?? null);
        if ($lec === null || $lab === null || abs($lec + $lab - $u) > 0.001) return [$u, 0.0, $u];
        return [$lec, $lab, $u];
    }

    /** Open registration: its subjects take the current lecture / laboratory units of the subject records. */
    public static function refreshRegistration(int $regId): void
    {
        if (!self::ready('sis_registration_subjects')) return;
        foreach (Db::all('SELECT id, subject_doc_id, units, lec_units, lab_units FROM sis_registration_subjects WHERE registration_id = ?', [$regId]) as $row) {
            $s = GS::get('subjects', (string) $row['subject_doc_id']);
            if (!$s || abs((float) $s['units'] - (float) $row['units']) > 0.001) continue; // units changed: the registrar saves the subjects again
            [$lec, $lab] = self::ofSubject($s);
            if ((string) (float) $row['lec_units'] !== (string) $lec || (string) (float) $row['lab_units'] !== (string) $lab || $row['lec_units'] === null)
                Db::q('UPDATE sis_registration_subjects SET lec_units = ?, lab_units = ? WHERE id = ?', [$lec, $lab, $row['id']]);
        }
    }

    /** [lecture, laboratory, total] units of a registration subject row. */
    public static function ofRow(array $r): array
    {
        $u = (float) ($r['units'] ?? 0); $lec = self::num($r['lec_units'] ?? null); $lab = self::num($r['lab_units'] ?? null);
        if ($lec === null || $lab === null || abs($lec + $lab - $u) > 0.001) return [$u, 0.0, $u];
        return [$lec, $lab, $u];
    }

    /** Units a fee is charged on: all, lecture or laboratory. */
    public static function basisUnits(array $split, string $basis): float
    {
        return match ($basis) { 'LEC' => $split[0], 'LAB' => $split[1], default => $split[2] };
    }

    public static function text(float $u): string { return rtrim(rtrim(number_format($u, 1, '.', ''), '0'), '.'); }

    /** Validates lecture / laboratory units (0–30, one decimal); returns [lec, lab] or null when both are blank. */
    public static function parse(string $lec, string $lab): ?array
    {
        $lec = trim($lec); $lab = trim($lab);
        if ($lec === '' && $lab === '') return null;
        foreach (['Lecture units' => $lec, 'Laboratory units' => $lab] as $label => $v)
            if ($v !== '' && (!preg_match('/^\d{1,2}(?:\.\d)?$/', $v) || (float) $v > 30)) throw new AppError("$label must be a number from 0 to 30 with at most one decimal.");
        $a = (float) ($lec === '' ? 0 : $lec); $b = (float) ($lab === '' ? 0 : $lab);
        if ($a + $b <= 0) throw new AppError('Lecture units plus laboratory units must be more than 0.');
        if ($a + $b > 30) throw new AppError('Lecture units plus laboratory units cannot be more than 30.');
        return [$a, $b];
    }
}
