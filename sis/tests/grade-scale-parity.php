<?php
/** The SIS's GradeScale (PHP) must give the same results as the Grading System's js/grading-scale.js. Needs node. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/lib/grade-scale.php';
$tables = [GradeScale::DEFAULT_TRANSMUTATION,
    [['grade' => 1, 'min' => 95], ['grade' => 1.5, 'min' => 90], ['grade' => 2, 'min' => 85], ['grade' => 2.5, 'min' => 80], ['grade' => 3, 'min' => 75], ['grade' => 4, 'min' => 70]],
    [['grade' => 1, 'min' => 98.5], ['grade' => 3, 'min' => 74.995], ['grade' => 2, 'min' => null]]];
$options = [];
foreach (['percent', 'point1', 'point5'] as $scale) foreach (['same', 'percent', 'point1', 'point5'] as $final) foreach ($tables as $t) foreach ([true, false] as $c)
    $options[] = ['gradingScale' => $scale, 'finalScale' => $final, 'transmutation' => $t, 'allowConditional' => $c];
$values = [];
for ($v = 0; $v <= 100; $v += 0.25) $values[] = $v;
foreach ([1.005, 74.995, 74.994, 75.005, 88.875, 96.995, 2.675, 3.005, 2.995, 4.0, 1.0, 5.0, 2.0, 1.125, 1.374999, 97.0, 93.999999] as $x) $values[] = $x;
for ($i = 0; $i < 300; $i++) $values[] = mt_rand(0, 1000000) / 10000;
$proc = proc_open(['node', __DIR__ . '/grade-scale-parity.mjs'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
fwrite($pipes[0], json_encode(['options' => $options, 'values' => $values])); fclose($pipes[0]);
$js = json_decode(stream_get_contents($pipes[1]), true); $err = stream_get_contents($pipes[2]); proc_close($proc);
if (!is_array($js)) { echo "FAIL node helper: $err\n"; exit(1); }
$fails = 0; $n = 0;
foreach ($options as $i => $o) {
    $g = new GradeScale($o);
    foreach ($values as $j => $v) {
        $t = $g->toFinal((float) $v);
        $php = [$g->remarksFor((float) $v), $g->gwaRemarks(GradeScale::round2((float) $v)), $t, $t === null ? null : $g->finalRemarks($t), $g->finalScale()];
        $n++;
        $a = $js[$i][$j];
        $ok = $php[0] === $a[0] && $php[1] === $a[1] && $php[3] === $a[3] && $php[4] === $a[4] && abs((float) $php[2] - (float) $a[2]) < 1e-9;
        if (!$ok && $fails++ < 10) echo 'FAIL ' . json_encode($o + ['value' => $v]) . ' php=' . json_encode($php) . ' js=' . json_encode($a) . "\n";
    }
}
echo $fails ? "$fails of $n differ\n" : "PASS PHP and JavaScript agree on $n grade checks (" . count($options) . " setting combinations)\n";
exit($fails ? 1 : 0);
