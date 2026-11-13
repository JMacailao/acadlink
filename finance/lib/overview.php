<?php
declare(strict_types=1);

/**
 * v58 Finance overview: money collected by source, and the finance work waiting for someone.
 * Each source reads its own posted (not voided) receipts. Canteen POS counts net sales (v59). Library shows
 * "Not set up yet" until its system records fines; it plugs in by adding its query here.
 */
final class FinanceOverview
{
    /** source => [label, icon, SQL giving SUM(amount) and COUNT(*) for posted receipts between ? and ?, or null] */
    public const SOURCES = [
        'TUITION'   => ['Tuition and fees', 'mortarboard', "SELECT COALESCE(SUM(amount),0), COUNT(*) FROM sis_payments WHERE status='POSTED' AND paid_at >= ? AND paid_at < ?"],
        'DOCUMENTS' => ['Documents (TOR and certificates)', 'file-earmark-text', "SELECT COALESCE(SUM(amount),0), COUNT(*) FROM sis_document_payments WHERE status='POSTED' AND paid_at >= ? AND paid_at < ?"],
        'CANTEEN'   => ['Canteen POS', 'shop', 'canteen'], // v59: net sales (sales − approved refunds), see Canteen::netSales()
        'LIBRARY'   => ['Library fines', 'book', null],
    ];

    /**
     * Collections from $from to $to (inclusive dates, Y-m-d).
     * @return array{rows: list<array{key:string,label:string,icon:string,ready:bool,cents:int,count:int}>, total:int}
     */
    public static function collections(string $from, string $to): array
    {
        [$from, $to] = self::range($from, $to);
        $end = date('Y-m-d', strtotime($to . ' +1 day'));
        $rows = []; $total = 0;
        foreach (self::SOURCES as $key => [$label, $icon, $sql]) {
            $cents = 0; $count = 0;
            if ($sql === 'canteen') {
                [$cents, $count] = Canteen::netSales($from . ' 00:00:00', $end . ' 00:00:00');
                $total += $cents;
            } elseif ($sql !== null) {
                [$sum, $count] = self::sum($sql, $from, $end);
                $cents = Money::cents((string) $sum);
                $total += $cents;
            }
            $rows[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'ready' => $sql !== null, 'cents' => $cents, 'count' => (int) $count];
        }
        return ['rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to];
    }

    /** Valid dates in order; defaults to the first of this month through today. */
    public static function range(string $from, string $to): array
    {
        $ok = fn(string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false && date('Y-m-d', strtotime($d)) === $d;
        $from = $ok($from) ? $from : date('Y-m-01');
        $to = $ok($to) ? $to : date('Y-m-d');
        return $from > $to ? [$to, $from] : [$from, $to];
    }

    /** Finance work waiting for someone, limited to what this person may act on. */
    public static function attention(): array
    {
        $out = [];
        if (Auth::can('accounting')) {
            $out[] = ['Entries waiting for review', (int) Db::val("SELECT COUNT(*) FROM sis_journal WHERE status='REVIEW'"), 'clipboard-check', url('accounting', ['tab' => 'review'])];
            $out[] = ['Draft entries', (int) Db::val("SELECT COUNT(*) FROM sis_journal WHERE status='DRAFT'"), 'pencil-square', url('accounting', ['tab' => 'review'])];
        }
        if (Auth::can('registration', 'accounting', 'balances.view')) {
            $out[] = ['Balance requests waiting', (int) Db::val("SELECT COUNT(*) FROM sis_balance_cases WHERE status='PENDING'"), 'wallet2', url('balance')];
        }
        if (Auth::can('payments.view')) {
            $out[] = ['Receipts today', (int) Db::val("SELECT (SELECT COUNT(*) FROM sis_payments WHERE status='POSTED' AND DATE(paid_at)=CURDATE()) + (SELECT COUNT(*) FROM sis_document_payments WHERE status='POSTED' AND DATE(paid_at)=CURDATE())"), 'receipt', url('cashier')];
        }
        return $out;
    }

    private static function sum(string $sql, string $from, string $end): array
    {
        $st = Db::pdo()->prepare($sql);
        $st->execute([$from . ' 00:00:00', $end . ' 00:00:00']);
        $r = $st->fetch(PDO::FETCH_NUM) ?: ['0', 0];
        return [(string) $r[0], (int) $r[1]];
    }
}
