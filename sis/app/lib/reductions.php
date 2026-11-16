<?php
declare(strict_types=1);
/**
 * v66: changes that lower what a student pays, and reopening a confirmed enrollment.
 *
 * Every such change goes through Reductions::request(): it needs a reason (Settings → Fee reductions and reopening),
 * and it waits for an approver when Settings say so:
 *   - reopening a confirmed enrollment, and
 *   - lowering the fees of a registration that was reopened after being confirmed,
 * both only when the school turns on Settings → "Use an approver when a confirmed enrollment is reopened" (approval_on_reopen, v66.2).
 * Approvers hold "Approve fee reductions and reopening" (sis.reductions.approve) in Users and Groups; an approver's own
 * change applies at once. Each change is kept in sis_registration_changes with its reason, who asked, who decided and
 * the total before and after, and is listed in SIS → Approvals.
 */
final class Reductions
{
    public const KINDS = [
        'REOPEN' => 'Reopen the confirmed enrollment',
        'REMOVE_FEE' => 'Take a fee off',
        'SKIP_SUBJECT_FEE' => 'Take a subject fee off',
        'GIVE_DISCOUNT' => 'Give a scholarship / discount',
        'LESS' => 'Less (discount)',
        'LOWER_FEE' => 'Charge a fee at a lower amount',
        'CHANGE_TEMPLATE' => 'Change to a cheaper fee template',
    ];
    public const STATUSES = ['APPLIED' => 'Done', 'PENDING' => 'Waiting for approval', 'APPROVED' => 'Approved', 'REJECTED' => 'Declined', 'CANCELLED' => 'Cancelled'];
    public const PERMISSION = 'sis.reductions.approve';

    public static function install(): void
    {
        Db::q("CREATE TABLE IF NOT EXISTS sis_registration_changes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL,
            kind ENUM('REOPEN','REMOVE_FEE','SKIP_SUBJECT_FEE','GIVE_DISCOUNT','LESS','LOWER_FEE','CHANGE_TEMPLATE') NOT NULL,
            params TEXT NULL,
            summary VARCHAR(255) NOT NULL,
            reason VARCHAR(500) NOT NULL DEFAULT '',
            status ENUM('APPLIED','PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL,
            total_before DECIMAL(12,2) NULL, total_after DECIMAL(12,2) NULL,
            requested_by INT UNSIGNED NULL, requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_by INT UNSIGNED NULL, decided_at DATETIME NULL, decision_note VARCHAR(500) NULL,
            KEY k_sis_rchg_reg (registration_id), KEY k_sis_rchg_status (status, requested_at),
            CONSTRAINT fk_sis_rchg_reg FOREIGN KEY (registration_id) REFERENCES sis_registrations (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // v66.1: two more kinds (a fee charged lower than usual; a cheaper fee template)
        $type = (string) Db::val("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sis_registration_changes' AND column_name = 'kind'");
        if ($type !== '' && !str_contains($type, 'CHANGE_TEMPLATE'))
            Db::q("ALTER TABLE sis_registration_changes MODIFY kind ENUM('REOPEN','REMOVE_FEE','SKIP_SUBJECT_FEE','GIVE_DISCOUNT','LESS','LOWER_FEE','CHANGE_TEMPLATE') NOT NULL");
    }

    /** v66.1: the usual amount of a fee for this registration: its fee template's amount, else the fee's default. In cents. */
    public static function usualCents(array $r, int $labelId): int
    {
        if ($r['assessment_name_id']) {
            $v = Db::val('SELECT amount FROM sis_assessment_name_items WHERE assessment_name_id = ? AND label_id = ?', [$r['assessment_name_id'], $labelId]);
            if ($v !== null) return Money::cents((string) $v);
        }
        return Money::cents((string) (Db::val('SELECT default_amount FROM sis_fee_labels WHERE id = ?', [$labelId]) ?? '0'));
    }

    /** v66.1: the total this registration would have with another fee template (null = none). In cents. */
    public static function totalWithTemplate(array $r, ?int $nameId): int
    {
        return (int) Registration::build(array_replace($r, ['assessment_name_id' => $nameId]))['total_cents'];
    }

    public static function canApprove(): bool { return Auth::can('registration.approve'); }
    public static function reasonRequired(): bool { return Settings::on('red_reason'); }

    /** Was this registration reopened after being confirmed (now or earlier)? */
    public static function wasReopened(int $regId): bool
    {
        return (bool) Db::val("SELECT 1 FROM sis_audit_logs WHERE action = 'registration_reopened' AND entity_type = 'registration' AND entity_id = ? LIMIT 1", [(string) $regId]);
    }

    /** Does this change wait for an approver? */
    public static function needsApproval(int $regId, string $kind): bool
    {
        if (!Settings::on('approval_on_reopen')) return false; // v66.2: one switch, off unless the school wants an approver
        return $kind === 'REOPEN' || self::wasReopened($regId);
    }

    /**
     * Ask for a change. Returns 'applied' (done now) or 'pending' (waits for an approver, who is notified).
     * $params are what apply() needs; $summary is shown to people ("Tuition Fee · IT201").
     */
    public static function request(int $regId, string $kind, array $params, string $summary, string $reason): string
    {
        if (!isset(self::KINDS[$kind])) throw new AppError('Unknown change.');
        $reason = trim(preg_replace('/\s+/u', ' ', $reason));
        if ($kind === 'REOPEN' && $reason === '') throw new AppError('Give the reason for reopening.');
        if ($reason === '' && self::reasonRequired()) throw new AppError('Give the reason for this change (Settings: a reason is needed when what a student pays is lowered).');
        if (mb_strlen($reason) > 500) throw new AppError('Keep the reason to 500 characters.');
        $r = Registration::get($regId);
        if (self::needsApproval($regId, $kind) && !self::canApprove()) {
            if (Db::val("SELECT 1 FROM sis_registration_changes WHERE registration_id = ? AND kind = ? AND params <=> ? AND status = 'PENDING'", [$regId, $kind, self::enc($params)]))
                throw new AppError('This change is already waiting for approval.');
            $id = Db::insert('sis_registration_changes', ['registration_id' => $regId, 'kind' => $kind, 'params' => self::enc($params), 'summary' => mb_substr($summary, 0, 255),
                'reason' => $reason, 'status' => 'PENDING', 'total_before' => $r['total_amount'], 'requested_by' => Auth::id()]);
            Audit::log('change_requested', 'registration', $regId, ['change' => $id, 'kind' => $kind, 'summary' => $summary, 'reason' => $reason]);
            $who = Auth::user()['full_name'] ?? 'Someone';
            Notify::toAccounts(Notify::accountsWith(self::PERMISSION), 'approval', 'Approval needed: ' . self::KINDS[$kind],
                $who . ' asks: ' . $summary . ' — ' . $r['student_name'] . ' (' . $r['reg_no'] . '). Reason: ' . ($reason !== '' ? $reason : '—'),
                'index.php?r=approvals', 'rchg:' . $id);
            return 'pending';
        }
        Db::tx(function () use ($regId, $kind, $params, $summary, $reason, $r) {
            self::apply($regId, $kind, $params, $reason);
            $after = Db::val('SELECT total_amount FROM sis_registrations WHERE id = ?', [$regId]);
            Db::insert('sis_registration_changes', ['registration_id' => $regId, 'kind' => $kind, 'params' => self::enc($params), 'summary' => mb_substr($summary, 0, 255),
                'reason' => $reason, 'status' => 'APPLIED', 'total_before' => $r['total_amount'], 'total_after' => $after, 'requested_by' => Auth::id(),
                'decided_by' => self::needsApproval($regId, $kind) ? Auth::id() : null, 'decided_at' => self::needsApproval($regId, $kind) ? date('Y-m-d H:i:s') : null]);
        });
        return 'applied';
    }

    /** Approve or decline a waiting change. Approving makes the change now (the approver needs the usual rights too). */
    public static function decide(int $changeId, bool $approve, string $note): void
    {
        if (!self::canApprove()) throw new Forbidden('You cannot approve fee reductions or reopening.');
        $note = trim($note);
        if (!$approve && $note === '') throw new AppError('Give the reason for declining.');
        $c = Db::tx(function () use ($changeId, $approve, $note) {
            $c = Db::one("SELECT * FROM sis_registration_changes WHERE id = ? FOR UPDATE", [$changeId]);
            if (!$c) throw new AppError('That request no longer exists.');
            if ($c['status'] !== 'PENDING') throw new AppError('That request was already ' . strtolower(self::STATUSES[$c['status']]) . '.');
            $before = Db::val('SELECT total_amount FROM sis_registrations WHERE id = ?', [$c['registration_id']]);
            if ($approve) self::apply((int) $c['registration_id'], $c['kind'], self::dec($c['params']), (string) $c['reason']);
            $after = Db::val('SELECT total_amount FROM sis_registrations WHERE id = ?', [$c['registration_id']]);
            Db::q('UPDATE sis_registration_changes SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ?, total_before = ?, total_after = ? WHERE id = ?',
                [$approve ? 'APPROVED' : 'REJECTED', Auth::id(), $note !== '' ? mb_substr($note, 0, 500) : null, $before, $approve ? $after : null, $changeId]);
            Audit::log($approve ? 'change_approved' : 'change_declined', 'registration', (int) $c['registration_id'], ['change' => $changeId, 'kind' => $c['kind'], 'summary' => $c['summary'], 'note' => $note]);
            return $c;
        });
        $r = Registration::get((int) $c['registration_id']);
        $acc = $c['requested_by'] ? Db::val("SELECT account_id FROM central_legacy_links WHERE source_system = 'SIS' AND source_key = ?", [(string) $c['requested_by']]) : null;
        Notify::toAccount($acc ? (int) $acc : null, 'approval', ($approve ? 'Approved: ' : 'Declined: ') . self::KINDS[$c['kind']],
            $c['summary'] . ' — ' . $r['student_name'] . ' (' . $r['reg_no'] . ')' . ($note !== '' ? '. ' . $note : ''), 'index.php?r=registration&id=' . (int) $c['registration_id']);
    }

    /** The person who asked can take back a waiting request. */
    public static function cancel(int $changeId): void
    {
        $c = Db::one('SELECT * FROM sis_registration_changes WHERE id = ?', [$changeId]);
        if (!$c || $c['status'] !== 'PENDING') throw new AppError('That request is not waiting any more.');
        if ((int) $c['requested_by'] !== (int) Auth::id() && !self::canApprove()) throw new Forbidden('Only the person who asked can cancel this request.');
        Db::q("UPDATE sis_registration_changes SET status = 'CANCELLED', decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'PENDING'", [Auth::id(), $changeId]);
        Audit::log('change_cancelled', 'registration', (int) $c['registration_id'], ['change' => $changeId]);
    }

    private static function apply(int $regId, string $kind, array $p, string $reason): void
    {
        match ($kind) {
            'REOPEN' => Registration::reopen($regId, $reason),
            'REMOVE_FEE' => Registration::removeLabel($regId, (int) ($p['label_id'] ?? 0)),
            'SKIP_SUBJECT_FEE' => Registration::skipSubjectFee($regId, (string) ($p['subject_doc_id'] ?? ''), (int) ($p['label_id'] ?? 0)),
            'GIVE_DISCOUNT' => Discounts::give($regId, (int) ($p['discount_id'] ?? 0), ($p['remarks'] ?? '') !== '' ? (string) $p['remarks'] : $reason),
            'LESS' => Registration::addAdjustment($regId, (string) ($p['label'] ?? 'Less'), -abs((int) ($p['cents'] ?? 0)), 'Less / Discount'),
            'LOWER_FEE' => Registration::chargeLabel($regId, (int) ($p['label_id'] ?? 0), (int) ($p['cents'] ?? 0)),
            'CHANGE_TEMPLATE' => Registration::update($regId, ($p['name_id'] ?? null) !== null ? (int) $p['name_id'] : null, array_column(Db::all('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [$regId]), 'subject_doc_id'), true),
        };
    }

    /** Changes of one registration, newest first, with names. */
    public static function forRegistration(int $regId): array
    {
        return self::table('c.registration_id = ?', [$regId], 100);
    }

    /** For SIS → Approvals: waiting requests, or the history (with filters). */
    public static function list(string $status, string $sy = '', string $term = '', string $q = '', int $limit = 500): array
    {
        $w = []; $p = [];
        if ($status === 'PENDING') $w[] = "c.status = 'PENDING'"; else $w[] = "c.status <> 'PENDING'";
        if ($sy !== '') { $w[] = 'r.school_year = ?'; $p[] = $sy; }
        if ($term !== '') { $w[] = 'r.term = ?'; $p[] = $term; }
        if ($q !== '') { $w[] = '(r.student_name LIKE ? OR r.reg_no LIKE ? OR c.summary LIKE ? OR c.reason LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
        return self::table(implode(' AND ', $w), $p, $limit);
    }

    public static function pendingCount(): int
    {
        try { return (int) Db::val("SELECT COUNT(*) FROM sis_registration_changes WHERE status = 'PENDING'"); } catch (Throwable) { return 0; }
    }

    private static function table(string $where, array $params, int $limit): array
    {
        return Db::all("SELECT c.*, r.reg_no, r.student_name, r.student_number, r.school_year, r.term, r.section_name, r.total_amount AS total_now,
                               ru.full_name AS requested_by_name, du.full_name AS decided_by_name
                          FROM sis_registration_changes c JOIN sis_registrations r ON r.id = c.registration_id
                          LEFT JOIN sis_users ru ON ru.id = c.requested_by LEFT JOIN sis_users du ON du.id = c.decided_by
                         WHERE $where ORDER BY c.id DESC LIMIT " . max(1, min(2000, $limit)), $params);
    }

    private static function enc(array $p): string { ksort($p); return json_encode($p, JSON_UNESCAPED_UNICODE); }
    private static function dec(?string $s): array { return json_decode((string) $s, true) ?: []; }
}
