<?php
/**
 * Balanced draft generation for confirmed assessments and receipts, followed by reviewed posting.
 * Financial statements and reconciliation include only posted journals. Receipt and assessment
 * reversals are authorized by AccountingWorkflow before the source record may be corrected.
 */
declare(strict_types=1);

final class Accounting
{
    public const TYPES = ['ASSET' => 'Asset', 'LIABILITY' => 'Liability', 'EQUITY' => 'Equity', 'REVENUE' => 'Revenue',
                          'CONTRA_REVENUE' => 'Contra revenue (less on revenue)', 'EXPENSE' => 'Expense'];
    /** Account types whose balance grows with debits */
    public const DEBIT_NORMAL = ['ASSET', 'CONTRA_REVENUE', 'EXPENSE'];
    /** The fixed mappings and what they are for */
    public const MAP_KEYS = [
        'ar' => 'Accounts receivable (what students owe)',
        'default_revenue' => 'Fee revenue when a fee has no account of its own',
        'default_discount' => 'Scholarships and discounts when a program has no account of its own',
        'adjust_charge' => 'Other charges added to an assessment',
        'adjust_less' => 'Other less (discounts) added to an assessment',
    ];

    // ------------------------------------------------------------ chart of accounts
    public static function accounts(bool $activeOnly = false): array
    {
        return Db::all('SELECT a.*, (SELECT COUNT(*) FROM sis_journal_lines l WHERE l.account_id = a.id) AS used FROM sis_accounts a'
            . ($activeOnly ? ' WHERE a.is_active = 1' : '') . ' ORDER BY a.code');
    }

    public static function account(int $id): ?array
    {
        return Db::one('SELECT * FROM sis_accounts WHERE id = ?', [$id]);
    }

    public static function saveAccount(?int $id, array $in): int
    {
        Auth::require('accounting.edit');
        $d = ['code' => trim((string) ($in['code'] ?? '')), 'name' => trim((string) ($in['name'] ?? '')), 'type' => (string) ($in['type'] ?? ''),
              'is_cash' => !empty($in['is_cash']) ? 1 : 0, 'is_active' => !empty($in['is_active']) ? 1 : 0,
              'description' => ($x = trim((string) ($in['description'] ?? ''))) === '' ? null : mb_substr($x, 0, 255)];
        if (!preg_match('/^[0-9A-Za-z.\-]{1,20}$/', $d['code'])) throw new AppError('Account code: 1 to 20 letters, numbers, dots or dashes (e.g. 1010).');
        if ($d['name'] === '' || mb_strlen($d['name']) > 120) throw new AppError('Enter the account name (up to 120 characters).');
        if (!isset(self::TYPES[$d['type']])) throw new AppError('Choose the account type.');
        if ($d['is_cash'] && $d['type'] !== 'ASSET') throw new AppError('Only an asset account can be a cash or bank account.');
        return Db::tx(function () use ($id, $d) {
            if (Db::val('SELECT 1 FROM sis_accounts WHERE code = ? AND id <> ?', [$d['code'], (int) $id])) throw new AppError("Account code {$d['code']} is already used.");
            if ($id) {
                $old = Db::one('SELECT * FROM sis_accounts WHERE id = ? FOR UPDATE', [$id]);
                if (!$old) throw new AppError('Account not found.');
                $used = (int) Db::val('SELECT COUNT(*) FROM sis_journal_lines WHERE account_id = ?', [$id]);
                if ($used && $old['type'] !== $d['type']) throw new AppError('This account already has entries, so its type can\'t change. Make a new account instead.');
                if (!$d['is_active'] && Db::val('SELECT 1 FROM sis_account_map WHERE account_id = ?', [$id])) throw new AppError('This account is used in the account mappings. Map another account first.');
                Db::update('sis_accounts', $d, $id);
            } else {
                $id = Db::insert('sis_accounts', $d);
            }
            Audit::log('account_saved', 'account', $id, $d);
            return $id;
        });
    }

    // ------------------------------------------------------------ account mappings
    /** map_key => account id */
    public static function map(): array
    {
        return array_map('intval', array_column(Db::all('SELECT map_key, account_id FROM sis_account_map'), 'account_id', 'map_key'));
    }

    /** Save the mappings form: map[key] = account id ('' = remove the specific mapping, use the default). */
    public static function saveMap(array $in): void
    {
        Auth::require('accounting.edit');
        $valid = array_column(self::accounts(true), 'id');
        Db::tx(function () use ($in, $valid) {
            foreach ($in as $key => $acc) {
                $key = (string) $key;
                if (!preg_match('/^(ar|default_revenue|default_discount|adjust_charge|adjust_less|doc_fee|method:[A-Z]+|cat:\d+|label:\d+|discount:\d+)$/', $key)) continue;
                if ($acc === '' || $acc === null) {
                    if (isset(self::MAP_KEYS[$key]) || str_starts_with($key, 'method:')) throw new AppError('Choose an account for every required mapping.');
                    Db::q('DELETE FROM sis_account_map WHERE map_key = ?', [$key]);
                    continue;
                }
                if (!in_array((int) $acc, array_map('intval', $valid), true)) throw new AppError('Choose an active account.');
                Db::q('INSERT INTO sis_account_map (map_key, account_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE account_id = VALUES(account_id)', [$key, (int) $acc]);
            }
            Audit::log('account_map_saved', 'account', null, $in);
        });
    }

    /** The account for a key, falling back to $fallback; throws when nothing is mapped. */
    private static function acc(array $map, string $key, string $fallback = ''): int
    {
        $id = $map[$key] ?? ($fallback !== '' ? ($map[$fallback] ?? null) : null);
        if (!$id) throw new AppError('Accounting: no account is mapped for "' . ($fallback ?: $key) . '". Set it in Accounting → Account mappings.');
        return (int) $id;
    }

    // ------------------------------------------------------------ journal
    /**
     * Book a balanced entry. $lines: [[account id, debit cents, credit cents, memo?], …] (amounts ≥ 0, one side each).
     * Lines on the same account and side are added together. Returns the entry id.
     */
    public static function post(string $date, string $source, ?int $sourceId, ?int $regId, string $memo, array $lines, ?int $reverses = null, ?string $status = null, string $fund = 'OPERATING', string $department = 'School'): int
    {
        $status=$status??($reverses?'POSTED':($source==='MANUAL'?'DRAFT':'REVIEW'));
        if(!in_array($status,['DRAFT','REVIEW','POSTED'],true))throw new AppError('Invalid journal status.');
        [$fund,$department]=AccountingWorkflow::dimensions(['fund'=>$fund,'department'=>$department]);
        AccountingWorkflow::date($date);
        $merged = [];
        foreach ($lines as $l) {
            [$acc, $dr, $cr] = [(int) $l[0], (int) $l[1], (int) $l[2]];
            if ($dr > 99999999999 || $cr > 99999999999 || ($dr>0 && $cr>0))throw new AppError('Each journal line needs one debit or credit within the supported amount.');
            if (!($account=self::account($acc)) || !$account['is_active'])throw new AppError('Choose an active account.');
            if ($dr < 0 || $cr < 0) throw new AppError('Journal amounts can\'t be negative.');
            if ($dr === 0 && $cr === 0) continue;
            $net = $dr - $cr;
            $k = $acc . ($net > 0 ? 'D' : 'C') . '|' . ($l[3] ?? '');
            $merged[$k] = [$acc, ($merged[$k][1] ?? 0) + max(0, $net), ($merged[$k][2] ?? 0) + max(0, -$net), $l[3] ?? null];
        }
        $dr = array_sum(array_column($merged, 1)); $cr = array_sum(array_column($merged, 2));
        if (!$merged || $dr <= 0) throw new AppError('A journal entry needs at least one debit and one credit.');
        if ($dr !== $cr) throw new AppError('Debits (₱' . Money::fmt($dr) . ') and credits (₱' . Money::fmt($cr) . ') must be equal.');
        return Db::tx(function () use ($date, $source, $sourceId, $regId, $memo, $merged, $reverses, $status, $fund, $department) {
            AccountingWorkflow::assertOpen($date);
            $no = 'JE-' . substr($date, 0, 4) . '-' . str_pad((string) Seq::next('journal'), 6, '0', STR_PAD_LEFT);
            $id = Db::insert('sis_journal', ['entry_no' => $no, 'entry_date' => $date, 'source' => $source, 'source_id' => $sourceId,
                'registration_id' => $regId, 'memo' => mb_substr($memo, 0, 255), 'reverses_id' => $reverses, 'created_by' => Auth::id(), 'status'=>$status,'fund'=>$fund,'department'=>$department,'reviewed_by'=>$status==='POSTED'?Auth::id():null,'reviewed_at'=>$status==='POSTED'?date('Y-m-d H:i:s'):null,'posted_by'=>$status==='POSTED'?Auth::id():null,'posted_at'=>$status==='POSTED'?date('Y-m-d H:i:s'):null]);
            foreach ($merged as [$acc, $d, $c, $m]) {
                Db::insert('sis_journal_lines', ['journal_id' => $id, 'account_id' => $acc, 'debit' => Money::dec($d), 'credit' => Money::dec($c),
                    'memo' => $m === null ? null : mb_substr((string) $m, 0, 160)]);
            }
            if ($reverses) Db::q('UPDATE sis_journal SET reversed_by_id = ? WHERE id = ?', [$id, $reverses]);
            return $id;
        });
    }

    /** Reverse an entry (same lines, debits and credits swapped). The original stays, marked reversed. */
    public static function reverse(int $entryId, string $reason, ?string $date = null): int
    {
        return Db::tx(function () use ($entryId, $reason, $date) {
            AccountingWorkflow::lock();
            $e = Db::one('SELECT * FROM sis_journal WHERE id = ? FOR UPDATE', [$entryId]);
            if (!$e) throw new AppError('Journal entry not found.');
            if(in_array($e['status'],['DRAFT','REVIEW'],true)){Db::q("UPDATE sis_journal SET status='CANCELLED',review_note=? WHERE id=?",[$reason,$entryId]);Audit::log('journal_cancelled','journal',$entryId,['reason'=>$reason]);return $entryId;}
            if($e['status']!=='POSTED')throw new AppError('This journal is already cancelled.');
            if ($e['reversed_by_id']) throw new AppError("Entry {$e['entry_no']} is already reversed.");
            if ($e['reverses_id']) throw new AppError("Entry {$e['entry_no']} is itself a reversal.");
            $lines = array_map(fn($l) => [(int) $l['account_id'], Money::cents($l['credit']), Money::cents($l['debit']), $l['memo']],
                Db::all('SELECT * FROM sis_journal_lines WHERE journal_id = ?', [$entryId]));
            return self::post($date ?? date('Y-m-d'), $e['source'], $e['source_id'] === null ? null : (int) $e['source_id'], $e['registration_id'] === null ? null : (int) $e['registration_id'],
                "Reversal of {$e['entry_no']}: $reason", $lines, $entryId, 'POSTED', $e['fund'], $e['department']);
        });
    }

    /** The current (not reversed) entry of an assessment or payment, or null. */
    public static function activeEntry(string $source, int $sourceId): ?array
    {
        return Db::one('SELECT * FROM sis_journal WHERE source = ? AND source_id = ? AND status<>\'CANCELLED\' AND reverses_id IS NULL AND reversed_by_id IS NULL ORDER BY id DESC LIMIT 1', [$source, $sourceId]);
    }

    /** Book a confirmed assessment: Dr AR (total), Dr discounts / less, Cr revenue per fee line, Cr other charges. */
    public static function postAssessment(int $regId): ?int
    {
        $r = Registration::get($regId);
        if (self::activeEntry('ASSESSMENT', $regId)) return null; // already booked
        $b = Registration::build($r);
        if ($b['total_cents'] <= 0 && !$b['lines']) return null;
        $map = self::map();
        $labelCat = array_column(Db::all('SELECT id, category_id FROM sis_fee_labels'), 'category_id', 'id');
        $lines = [[self::acc($map, 'ar'), $b['total_cents'], 0, 'Assessment ' . $r['reg_no']]];
        foreach ($b['lines'] as $l) {
            $acc = $map['label:' . $l['label_id']] ?? ($map['cat:' . ($labelCat[$l['label_id']] ?? 0)] ?? self::acc($map, 'default_revenue'));
            $lines[] = [(int) $acc, 0, (int) $l['amount_cents'], $l['category_name']];
        }
        foreach (Db::all("SELECT label_name, amount FROM sis_registration_items WHERE registration_id = ? AND charge_type = 'ADJUSTMENT'", [$regId]) as $a) {
            $c = Money::cents($a['amount']);
            $lines[] = $c >= 0 ? [self::acc($map, 'adjust_charge'), 0, $c, $a['label_name']] : [self::acc($map, 'adjust_less'), -$c, 0, $a['label_name']];
        }
        foreach ($b['discounts'] as $d) {
            $acc = $map['discount:' . ($d['discount_id'] ?? 0)] ?? self::acc($map, 'default_discount');
            $lines[] = [(int) $acc, (int) $d['cents'], 0, mb_substr($d['label'], 0, 160)];
        }
        return self::post(date('Y-m-d'), 'ASSESSMENT', $regId, $regId, "Assessment {$r['reg_no']} · {$r['student_name']} · {$r['school_year']} {$r['term']}", $lines);
    }

    /** Take a booked assessment out of the books (enrollment reopened or voided). */
    public static function reverseAssessment(int $regId, string $reason): void
    {
        $e = self::activeEntry('ASSESSMENT', $regId);
        if(Db::val("SELECT j.id FROM sis_payment_plan_events e JOIN sis_journal j ON j.id=e.journal_id WHERE e.registration_id=? AND j.status='POSTED' AND j.reversed_by_id IS NULL",[$regId]))throw new AppError('Accounting must reverse the assessment and linked plan adjustments before reopening or voiding it.');
        PaymentPlans::reverseJournals($regId,$reason,date('Y-m-d'));
        if($e && $e['status']==='POSTED' && Db::val('SELECT record_origin FROM sis_registrations WHERE id=?',[$regId])!=='MIGRATION')throw new AppError('Accounting has posted this assessment. Accounting must reverse it first before Registration can reopen or void it.');
        if ($e) self::reverse((int) $e['id'], $reason);
    }

    /** Book a payment: Dr the cash or bank account of its method, Cr AR. */
    public static function postPayment(int $paymentId): int
    {
        $p = Db::one('SELECT p.*, r.reg_no, r.student_name FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE p.id = ?', [$paymentId]);
        if (!$p) throw new AppError('Payment not found.');
        $map = self::map();
        $c = Money::cents($p['amount']);
        return self::post(substr((string) $p['paid_at'], 0, 10) ?: date('Y-m-d'), 'PAYMENT', $paymentId, (int) $p['registration_id'],
            "O.R. {$p['receipt_no']} · {$p['student_name']} · {$p['method']}" . ($p['reference_no'] ? " {$p['reference_no']}" : ''),
            [[self::acc($map, 'method:' . $p['method']), $c, 0, 'O.R. ' . $p['receipt_no']], [self::acc($map, 'ar'), 0, $c, $p['reg_no']]]);
    }

    /** Cancel an unposted receipt journal; posted receipts must already have an Accounting reversal. */
    public static function reversePayment(int $paymentId, string $reason): void
    {
        $e = self::activeEntry('PAYMENT', $paymentId);
        if($e && $e['status']==='POSTED')throw new AppError('This receipt is posted in Accounting. Accounting must reverse the posting before Cashier can void it.');
        if ($e) self::reverse((int) $e['id'], 'receipt voided — ' . $reason);
    }

    /** Book what was recorded before accounting existed: confirmed assessments and posted payments (once). */
    public static function syncExisting(): array
    {
        $n = ['assessments' => 0, 'payments' => 0];
        Db::tx(function () use (&$n) {
            foreach (Db::col("SELECT id FROM sis_registrations WHERE status <> 'CANCELLED' AND locked_at IS NOT NULL
                               AND id NOT IN (SELECT source_id FROM sis_journal WHERE source = 'ASSESSMENT' AND source_id IS NOT NULL) ORDER BY id") as $id) {
                if (self::postAssessment((int) $id)) $n['assessments']++;
            }
            foreach (Db::col("SELECT id FROM sis_payments WHERE status = 'POSTED'
                               AND id NOT IN (SELECT source_id FROM sis_journal WHERE source = 'PAYMENT' AND source_id IS NOT NULL) ORDER BY id") as $id) {
                self::postPayment((int) $id); $n['payments']++;
            }
        });
        if ($n['assessments'] || $n['payments']) Audit::log('ledger_synced', 'account', null, $n);
        return $n;
    }

    /** A journal entry typed by accounting staff. $rows: [['account_id', 'debit', 'credit', 'memo'], …] (amounts as typed). */
    public static function manualEntry(string $date, string $memo, array $rows, string $fund='OPERATING', string $department='School'): int
    {
        Auth::require('accounting.edit');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new AppError('Enter the entry date.');
        if (trim($memo) === '') throw new AppError('Enter what the entry is for.');
        $active = array_map('intval', array_column(self::accounts(true), 'id'));
        $lines = [];
        foreach ($rows as $row) {
            $acc = (int) ($row['account_id'] ?? 0);
            $d = trim((string) ($row['debit'] ?? '')); $c = trim((string) ($row['credit'] ?? ''));
            if (!$acc && $d === '' && $c === '') continue;
            if (!in_array($acc, $active, true)) throw new AppError('Choose an active account on every line.');
            foreach ([$d, $c] as $v) if ($v !== '' && !preg_match('/^\d{1,10}(\.\d{1,2})?$/', str_replace(',', '', $v))) throw new AppError('Amounts: numbers with up to 2 decimals.');
            $dc = $d === '' ? 0 : Money::cents(str_replace(',', '', $d)); $cc = $c === '' ? 0 : Money::cents(str_replace(',', '', $c));
            if (($dc > 0) === ($cc > 0)) throw new AppError('Each line has either a debit or a credit.');
            $lines[] = [$acc, $dc, $cc, ($m = trim((string) ($row['memo'] ?? ''))) === '' ? null : $m];
        }
        if (count($lines) < 2) throw new AppError('A journal entry needs at least two lines.');
        $id = self::post($date, 'MANUAL', null, null, trim($memo), $lines,null,'DRAFT',$fund,$department);
        Audit::log('journal_entry_made', 'journal', $id, ['memo' => $memo]);
        return $id;
    }

    /** Compatibility action routed through the authorized Accounting reversal workflow. */
    public static function reverseManual(int $entryId, string $reason): int
    {
        return AccountingWorkflow::reverse($entryId,$reason,date('Y-m-d'));
    }

    // ------------------------------------------------------------ balances and statements
    /** Debit-positive balance in cents of each account up to $asOf (inclusive), optionally from $from. account id => cents */
    public static function balances(?string $asOf = null, ?string $from = null, ?string $fund=null, ?string $department=null): array
    {
        $w = ["j.status='POSTED'"]; $a = [];
        if ($asOf) { $w[] = 'j.entry_date <= ?'; $a[] = $asOf; }
        if ($from) { $w[] = 'j.entry_date >= ?'; $a[] = $from; }
        if($fund){$w[]='j.fund=?';$a[]=$fund;}if($department){$w[]='j.department=?';$a[]=$department;}
        $out = [];
        foreach (Db::all('SELECT l.account_id, SUM(l.debit) - SUM(l.credit) AS net FROM sis_journal_lines l JOIN sis_journal j ON j.id = l.journal_id'
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' GROUP BY l.account_id', $a) as $r) $out[(int) $r['account_id']] = Money::cents($r['net']);
        return $out;
    }

    /** The balance on the account's normal side (positive = normal). */
    public static function normal(array $account, int $debitPositive): int
    {
        return in_array($account['type'], self::DEBIT_NORMAL, true) ? $debitPositive : -$debitPositive;
    }

    /** Ledger lines of one account between two dates, with the opening balance (debit-positive). */
    public static function ledger(int $accountId, string $from, string $to, ?string $fund=null, ?string $department=null): array
    {
        $opening = (int) (self::balances(date('Y-m-d', strtotime($from . ' -1 day')),null,$fund,$department)[$accountId] ?? 0);
        $dims='';$params=[$accountId,$from,$to];if($fund){$dims.=' AND j.fund=?';$params[]=$fund;}if($department){$dims.=' AND j.department=?';$params[]=$department;}
        $rows = Db::all('SELECT j.id, j.entry_no, j.entry_date, j.memo, j.source, j.reverses_id, j.reversed_by_id, l.debit, l.credit, l.memo AS line_memo, l.reconciliation_id
                           FROM sis_journal_lines l JOIN sis_journal j ON j.id = l.journal_id
                          WHERE j.status=\'POSTED\' AND l.account_id = ? AND j.entry_date BETWEEN ? AND ?'.$dims.' ORDER BY j.entry_date, j.id, l.id', $params);
        return ['opening' => $opening, 'rows' => $rows];
    }

    /** Cash and bank reconciliation: the uncleared lines up to the statement date, and the balances. */
    public static function reconcileView(int $accountId, string $statementDate): array
    {
        $acc = self::account($accountId);
        if (!$acc || !(int) $acc['is_cash']) throw new AppError('Choose a cash or bank account.');
        $lines = Db::all('SELECT l.id, l.debit, l.credit, l.memo AS line_memo, j.entry_no, j.entry_date, j.memo FROM sis_journal_lines l JOIN sis_journal j ON j.id = l.journal_id
                           WHERE j.status=\'POSTED\' AND l.account_id = ? AND l.reconciliation_id IS NULL AND j.entry_date <= ? ORDER BY j.entry_date, j.id', [$accountId, $statementDate]);
        $cleared = Money::cents((string) Db::val('SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM sis_journal_lines WHERE account_id = ? AND reconciliation_id IS NOT NULL', [$accountId]));
        $book = (int) (self::balances($statementDate)[$accountId] ?? 0);
        $last = Db::one('SELECT * FROM sis_reconciliations WHERE account_id = ? ORDER BY statement_date DESC, id DESC LIMIT 1', [$accountId]);
        return ['account' => $acc, 'lines' => $lines, 'cleared' => $cleared, 'book' => $book, 'last' => $last];
    }

    /** Save a reconciliation: the ticked lines are cleared; the difference must be zero. */
    public static function reconcile(int $accountId, string $statementDate, int $statementCents, array $lineIds, ?string $notes): int
    {
        Auth::require('accounting.reconcile');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $statementDate)) throw new AppError('Enter the statement date.');
        return Db::tx(function () use ($accountId, $statementDate, $statementCents, $lineIds, $notes) {
            AccountingWorkflow::lock();AccountingWorkflow::authorize('accounting.reconcile');AccountingWorkflow::assertOpen($statementDate);
            Db::one('SELECT id FROM sis_accounts WHERE id=? FOR UPDATE',[$accountId]);
            if(Db::val('SELECT id FROM sis_reconciliations WHERE account_id=? AND statement_date>?',[$accountId,$statementDate]))throw new AppError('Statement dates must follow the previous reconciliation.');
            $v = self::reconcileView($accountId, $statementDate);
            $open = array_column($v['lines'], null, 'id');
            $ids = array_values(array_unique(array_filter(array_map('intval', $lineIds), fn($i) => isset($open[$i]))));
            if(count($ids)!==count(array_unique(array_map('intval',$lineIds))))throw new AppError('Some lines were already reconciled or do not belong to this account/date. Reload and review.');
            $sum = 0;
            foreach ($ids as $i) $sum += Money::cents($open[$i]['debit']) - Money::cents($open[$i]['credit']);
            $cleared = $v['cleared'] + $sum;
            if ($cleared !== $statementCents) {
                throw new AppError('Not balanced: the cleared total is ₱' . Money::fmt($cleared) . ' but the statement says ₱' . Money::fmt($statementCents)
                    . ' (difference ₱' . Money::fmt($statementCents - $cleared) . '). Tick the items on the statement, or book the bank charges and interest first.');
            }
            $id = Db::insert('sis_reconciliations', ['account_id' => $accountId, 'statement_date' => $statementDate, 'statement_balance' => Money::dec($statementCents),
                'cleared_balance' => Money::dec($cleared), 'book_balance' => Money::dec($v['book']), 'notes' => $notes ? mb_substr($notes, 0, 255) : null, 'created_by' => Auth::id()]);
            if ($ids) Db::q('UPDATE sis_journal_lines SET reconciliation_id = ? WHERE id IN ' . Db::in($ids), [$id, ...$ids]);
            Audit::log('account_reconciled', 'account', $accountId, ['statement_date' => $statementDate, 'statement' => Money::dec($statementCents), 'items' => count($ids)]);
            return $id;
        });
    }
}
