<?php
declare(strict_types=1);

/**
 * The Grading System's grading scale, for documents printed by the SIS (TOR, certificates).
 * Same rules as js/grading-scale.js; tests/grade-scale-parity.php checks both give the same results.
 * Settings come from the Grading System's Setup and options (docs: settings/options).
 *
 *   percent  0 to 100; 75 and above is Passed
 *   point1   1.00 to 5.00, 1.00 highest (3.00 lowest passing, 4.00 conditional, 5.00 failing)
 *   point5   the reverse, 5.00 highest (3.00 lowest passing, 2.00 conditional, 1.00 failing)
 */
final class GradeScale
{
    public const SCALES = ['percent' => '0–100', 'point1' => '1.00–5.00', 'point5' => '5.00–1.00'];
    public const MARKS = ['INC' => 'Incomplete', 'DRP' => 'Dropped'];
    public const DEFAULT_TRANSMUTATION = [
        ['grade' => 1, 'min' => 97], ['grade' => 1.25, 'min' => 94], ['grade' => 1.5, 'min' => 91], ['grade' => 1.75, 'min' => 88],
        ['grade' => 2, 'min' => 85], ['grade' => 2.25, 'min' => 82], ['grade' => 2.5, 'min' => 79], ['grade' => 2.75, 'min' => 76],
        ['grade' => 3, 'min' => 75], ['grade' => 4, 'min' => null],
    ];

    private array $o;

    public function __construct(array $options = [])
    {
        $o = $options + ['gradingScale' => 'percent', 'allowConditional' => true, 'finalScale' => 'same', 'transmutation' => self::DEFAULT_TRANSMUTATION];
        if (!isset(self::SCALES[$o['gradingScale']])) $o['gradingScale'] = 'percent';
        if (!is_array($o['transmutation']) || !$o['transmutation']) $o['transmutation'] = self::DEFAULT_TRANSMUTATION;
        $this->o = $o;
    }

    /** The school's saved options (Grading System → Setup and options). */
    public static function current(): self
    {
        $raw = Db::val("SELECT data FROM docs WHERE col = 'settings' AND id = 'options'");
        return new self($raw ? (json_decode((string) $raw, true) ?: []) : []);
    }

    public function options(): array { return $this->o; }
    public function scale(): string { return $this->o['gradingScale']; }

    private static function same(float $a, float $b): bool { return abs($a - $b) < 1e-9; }
    /** Same as JavaScript Math.round((n + Number.EPSILON) * 100) / 100 (PHP's round() differs on halves like 1.005). */
    public static function round2(float $n): float { return floor(($n + PHP_FLOAT_EPSILON) * 100 + 0.5) / 100; }

    /** "Passed" | "Failed" | "Conditional" for one subject's grade. */
    public function remarksFor(float $n, ?string $scale = null): string
    {
        $scale ??= $this->scale();
        if ($scale === 'point1') return self::same($n, 4) ? 'Conditional' : ($n <= 3 ? 'Passed' : 'Failed');
        if ($scale === 'point5') return self::same($n, 2) ? 'Conditional' : ($n >= 3 ? 'Passed' : 'Failed');
        return $n >= 75 ? 'Passed' : 'Failed';
    }

    /** Passed / Failed for a GWA (already rounded to 2 decimals). */
    public function gwaRemarks(float $gwa, ?string $scale = null): string
    {
        $scale ??= $this->scale();
        if ($scale === 'point1') return $gwa <= 3 ? 'Passed' : 'Failed';
        if ($scale === 'point5') return $gwa >= 3 ? 'Passed' : 'Failed';
        return $gwa >= 75 ? 'Passed' : 'Failed';
    }

    /** "INC" / "DRP" for a remark saved instead of a number ("" for a normal grade). */
    public static function markCode($grade, $remarks): string
    {
        if ($grade !== null && $grade !== '') return '';
        $code = array_search($remarks, self::MARKS, true);
        return $code === false ? '' : $code;
    }

    /** The final grade's scale. Point grades can't be turned back into percentages. */
    public function finalScale(): string
    {
        $f = $this->o['finalScale'] ?? 'same';
        if (!$f || $f === 'same' || !isset(self::SCALES[$f])) return $this->scale();
        if ($this->scale() !== 'percent' && $f === 'percent') return $this->scale();
        return $f;
    }
    public function finalDiffers(): bool { return $this->finalScale() !== $this->scale(); }

    /** The conversion table in use, best grade first (rows switched off are left out). */
    public function transmutationRows(): array
    {
        $rows = array_values(array_filter($this->o['transmutation'], fn($r) => is_array($r) && isset($r['min']) && is_numeric($r['min'])));
        usort($rows, fn($a, $b) => $b['min'] <=> $a['min']);
        return $rows;
    }

    /** Converts a grade or GWA from the grading scale to the final grade's scale. */
    public function toFinal(?float $value): ?float
    {
        if ($value === null) return null;
        $from = $this->scale(); $to = $this->finalScale();
        if ($from === $to) return $value;
        if ($from === 'percent') {
            $v = self::round2($value); $g = 5.0;
            foreach ($this->transmutationRows() as $r) if ($v >= (float) $r['min']) { $g = (float) $r['grade']; break; }
            return $to === 'point5' ? 6 - $g : $g;
        }
        return self::round2(6 - $value);
    }

    /** Passed / Conditional / Failed for a final grade on the final scale. */
    public function finalRemarks(float $final): string
    {
        $to = $this->finalScale(); $v = self::round2($final);
        if (($to === 'point1' && self::same($v, 4)) || ($to === 'point5' && self::same($v, 2))) return 'Conditional';
        return $this->gwaRemarks($v, $to);
    }

    /** A grade as printed: "88", "1.75" (point scales always 2 decimals), "INC". */
    public function gradeText($grade, $remarks = null, ?string $scale = null): string
    {
        $code = self::markCode($grade, $remarks);
        if ($code !== '') return $code;
        if ($grade === null || $grade === '') return '';
        $scale ??= $this->scale();
        return $scale === 'percent' ? (string) (0 + $grade) : number_format((float) $grade, 2);
    }

    /** Units-weighted average of numeric grades (INC / DRP not counted): ['units' => counted units, 'gwa' => float|null]. */
    public static function weighted(array $subjects): array
    {
        $units = 0.0; $sum = 0.0;
        foreach ($subjects as $s) {
            if (!empty($s['mark']) || !is_numeric($s['finalGrade'] ?? null)) continue;
            $u = (float) ($s['units'] ?? 0); $units += $u; $sum += (float) $s['finalGrade'] * $u;
        }
        return ['units' => $units, 'gwa' => $units > 0 ? $sum / $units : null];
    }

    public function label(?string $scale = null): string { return self::SCALES[$scale ?? $this->scale()]; }

    /** Grading legend printed on documents, following the saved settings. */
    public function legend(): array
    {
        $c = !empty($this->o['allowConditional']);
        $lines = match ($this->scale()) {
            'point1' => ['1.00 highest · 1.00–3.00 Passed (steps of 0.25)' . ($c ? ' · 4.00 Conditional' : '') . ' · 5.00 Failed', 'GWA: 3.00 or better (lower) is Passed'],
            'point5' => ['5.00 highest · 5.00–3.00 Passed (steps of 0.25)' . ($c ? ' · 2.00 Conditional' : '') . ' · 1.00 Failed', 'GWA: 3.00 or better (higher) is Passed'],
            default => ['0–100 · 75 and above Passed · below 75 Failed', 'GWA: 75.00 and above is Passed'],
        };
        if ($this->finalDiffers()) {
            if ($this->scale() === 'percent') {
                $g = fn($x) => number_format($this->finalScale() === 'point5' ? 6 - (float) $x : (float) $x, 2);
                $rows = $this->transmutationRows(); $last = end($rows);
                $lines[] = 'Equivalent (' . $this->label($this->finalScale()) . '): ' . implode(', ', array_map(fn($r) => $r['min'] . '+ = ' . $g($r['grade']), $rows)) . ', below ' . ($last ? $last['min'] : 75) . ' = ' . $g(5);
            } else $lines[] = 'Equivalent (' . $this->label($this->finalScale()) . '): 6 − grade';
        }
        $lines[] = 'INC Incomplete · DRP Dropped (not counted in the GWA)';
        return $lines;
    }
}
