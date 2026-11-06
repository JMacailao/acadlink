<?php
declare(strict_types=1);
/** Printable clearance slip (v55): every office's and teacher's e-signature with the date, the registrar's final e-signature and control number. */
if (!Clearance::on()) throw new AppError(Clearance::offMessage());
$r = Clearance::registration((int) ($_GET['reg'] ?? 0));
$me = (int) Auth::centralId();
$st = Clearance::status($r);
$allowed = Clearance::ownsRegistration($r) || Clearance::isPower($me) || Auth::can('clearance') || array_filter($st['items'], fn($i) => Clearance::mayTag($me, $i));
if (!$allowed) throw new Forbidden('You cannot open this clearance.');
require_once SIS_ROOT . '/templates/documents/_helpers.php';
$doc = ['school' => Documents::school()];
$sig = function (?array $t): string {
    if (!$t) return '<span class="doc-preview">Waiting</span>';
    if ($t['status'] !== 'CLEARED') return '<span style="color:#b42318;font-weight:bold">NOT CLEARED</span><div style="font-size:10.5px">' . e($t['note']) . '</div>';
    $img = $t['signature_mode'] === 'ESIGN' ? '<img src="' . e(Clearance::signatureImage((int) $t['id'])) . '" alt="Signature" style="height:34px;display:block">' : '<span class="doc-stamp" style="font-size:11px">APPROVED</span>';
    return $img . '<div style="font-weight:bold">' . e($t['signer_name']) . '</div><div style="font-size:10.5px">' . e($t['signer_position']) . ' · ' . e(substr($t['signed_at'], 0, 16)) . '</div>';
};
$final = $st['final'];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Clearance – <?= e($r['student_name']) ?></title>
<?= doc_page_css('A4') ?>
<style>.doc-toolbar{position:sticky;top:0;z-index:5;display:flex;gap:8px;justify-content:center;padding:8px;background:#1f2a44}.doc-toolbar button{font:600 14px system-ui;padding:6px 16px;border-radius:8px;border:0;background:#fff;cursor:pointer}@media print{.doc-toolbar{display:none!important}}
.clr td{height:48px}.clr td:first-child{width:46%}</style></head><body>
<div class="doc-toolbar"><button type="button" id="docPrint">Print</button></div>
<div class="doc">
<?= doc_letterhead($doc) ?>
<div class="doc-title">STUDENT CLEARANCE</div>
<?php if ($final): ?><div class="doc-control">Control no. <?= e((string) $final['control_no']) ?></div><?php endif; ?>
<table style="width:100%;margin-bottom:8px"><tr><td>Name: <strong><?= e($r['student_name']) ?></strong></td><td>Student ID: <strong><?= e($r['student_number']) ?></strong></td></tr>
<tr><td>Program / year / section: <?= e(trim($r['program'] . ' ' . $r['year_level'] . ' · ' . $r['section_name'])) ?></td><td>Term: <?= e($r['school_year'] . ' · ' . $r['term']) ?></td></tr></table>
<table class="doc-table clr"><thead><tr><th>Office / subject</th><th>Signature</th></tr></thead><tbody>
<?php foreach ($st['items'] as $it): ?><tr><td><strong><?= e($it['label']) ?></strong><?= $it['kind'] === 'SUBJECT' ? '<div style="font-size:11px">' . e($it['teacher_name']) . '</div>' : '' ?></td><td><?= $sig($it['tag']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-top:6px">
  <div><?= $final ? '<span class="doc-stamp">CLEARANCE APPROVED</span>' : ($st['complete'] ? '<strong>Clearance completed</strong> · waiting for the registrar\'s approval' : '<span class="doc-preview">Not completed: ' . $st['counts']['CLEARED'] . ' of ' . count($st['items']) . ' cleared</span>') ?></div>
  <?= doc_signature(['signature' => $final ? ($final['signature_mode'] === 'ESIGN' ? ['mode' => 'ESIGN', 'image' => Clearance::signatureImage((int) $final['id']), 'name' => $final['signer_name'], 'position' => 'Registrar', 'signed_at' => substr($final['signed_at'], 0, 16)] : ['mode' => 'APPROVED', 'name' => $final['signer_name'], 'position' => $final['signer_position'], 'signed_at' => substr($final['signed_at'], 0, 16)]) : ['mode' => 'PENDING']], 'Approved:') ?>
</div>
<div class="doc-foot"><span><?= $final ? 'Control no. ' . e((string) $final['control_no']) : '' ?></span><span>Printed <?= e(doc_date(date('Y-m-d'))) ?></span></div>
</div>
<script src="<?= defined('PORTAL_ROOT') ? '../sis/' : '' ?>assets/doc-print.js?v=50"></script>
</body></html>
<?php
