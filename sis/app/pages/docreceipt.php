<?php
declare(strict_types=1);
/** Official receipt of a document fee (v52), for the Cashier and the student who paid. */
$p = Db::one('SELECT p.*, r.ref_no, r.student_name, r.student_number, r.student_doc_id, r.doc_type, r.copies, r.fee_per_copy, r.purpose, u.full_name
                FROM sis_document_payments p JOIN sis_document_requests r ON r.id = p.request_id JOIN sis_users u ON u.id = p.received_by WHERE p.id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$p) throw new AppError('Receipt not found.');
if (!Auth::can('payments.view') && Auth::studentNumber() !== $p['student_number']) throw new Forbidden('Not your receipt.');
$logo = School::logo();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>O.R. <?= h($p['receipt_no']) ?></title>
<style>
  body{font:14px/1.45 Arial,Helvetica,sans-serif;color:#111;margin:0;background:#eef1f5}.or{max-width:520px;margin:16px auto;background:#fff;padding:22px 24px;border:1px solid #ccd3dc}
  .head{display:flex;gap:12px;align-items:center;border-bottom:2px solid #111;padding-bottom:8px}.head img{height:48px}.school{font-weight:bold;font-size:16px}
  h1{font-size:16px;text-align:center;letter-spacing:.08em;margin:12px 0}.row{display:flex;justify-content:space-between;gap:12px;padding:3px 0}
  table{width:100%;border-collapse:collapse;margin:10px 0}td,th{border-bottom:1px solid #ccd3dc;padding:5px 4px;text-align:left}.n{text-align:right}
  .void{color:#b42318;font-weight:bold;text-align:center;font-size:22px;border:3px solid #b42318;padding:4px;margin:8px 0}.sign{margin-top:36px;text-align:right}.sign span{display:inline-block;border-top:1px solid #111;min-width:200px;text-align:center;padding-top:2px}
  .bar{text-align:center;margin:10px}@media print{body{background:#fff}.or{border:0;margin:0 auto}.bar{display:none}}
</style></head><body>
<div class="bar"><button type="button" id="docPrint" style="font:600 14px system-ui;padding:6px 16px">Print</button></div>
<div class="or">
  <div class="head"><?php if ($logo): ?><img src="<?= h($logo) ?>" alt=""><?php endif; ?><div><div class="school"><?= h(School::brand()) ?></div><div><?= h(Settings::get('print_address')) ?></div></div></div>
  <h1>OFFICIAL RECEIPT</h1>
  <?php if ($p['status'] === 'VOIDED'): ?><div class="void">VOID · <?= h($p['void_reason']) ?></div><?php endif; ?>
  <div class="row"><span>O.R. No. <strong><?= h($p['receipt_no']) ?></strong></span><span><?= h(date('F j, Y g:i A', strtotime($p['paid_at']))) ?></span></div>
  <div class="row"><span>Received from <strong><?= h($p['student_name']) ?></strong></span><span><?= h($p['student_number']) ?></span></div>
  <table><thead><tr><th>Particulars</th><th class="n">Qty</th><th class="n">Amount</th></tr></thead><tbody>
    <tr><td><?= h(Documents::TYPES[$p['doc_type']][0]) ?> (<?= h($p['ref_no']) ?>)<br><small><?= h($p['purpose']) ?></small></td><td class="n"><?= (int) $p['copies'] ?></td><td class="n">₱<?= Money::fmt(Money::cents($p['amount'])) ?></td></tr>
    <tr><th colspan="2">Total</th><th class="n">₱<?= Money::fmt(Money::cents($p['amount'])) ?></th></tr></tbody></table>
  <div class="row"><span>Payment: <?= h($p['method'] . ($p['reference_no'] ? ' · ' . $p['reference_no'] : '')) ?></span></div>
  <div class="sign"><span><?= h($p['full_name']) ?><br><small><?= h(Settings::get('or_sign') ?: 'Cashier') ?></small></span></div>
</div>
<script src="<?= defined('PORTAL_ROOT') ? '../sis/' : '' ?>assets/doc-print.js?v=50"></script>
</body></html>
