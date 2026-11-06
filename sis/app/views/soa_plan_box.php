<?php
/**
 * v56: payment plan and balance on the Statement of Account (both templates).
 * Read only. Uses the live registration, so every Cashier payment, void, refund and earned full-payment
 * discount shows here at once (the same figures Accounting books). Expects $r and $cls ('soa' or 'enr').
 */
// $planPreview (set by the live preview): the plan chosen but not saved yet ([] = no plan chosen)
$isPreview = isset($planPreview);
$planView = $isPreview ? $planPreview : PaymentPlans::schedule($r);
$planTerms = $planView ? $planView['saved']['terms'] : null;
$payableCents = Money::cents($r['total_amount']);
$paidCents = Money::cents($r['amount_paid']);
$remainingCents = Registration::balance($r);
$cls = $cls ?? 'soa';
// v56.4: a full-payment plan that deducts its discount as soon as it is chosen: the preview shows the lower balance
$previewOff = $isPreview && $planTerms && $planTerms['kind'] === 'FULL' && !empty($planTerms['apply_now']) ? (int) $planView['offer'] : 0;
$payableCents -= $previewOff; $remainingCents -= $previewOff;
?>
<section class="<?= $cls ?>-section <?= $cls ?>-plan-box" aria-label="Payment plan and balance" data-plan-box>
  <h2 class="<?= $cls ?>-section-title">PAYMENT PLAN AND BALANCE</h2>
  <?php if ($isPreview): ?><p class="plan-preview-note d-print-none" role="status"><strong>Preview</strong> · not saved yet. Click <strong>Save plan</strong> to keep this payment plan.</p><?php endif; ?>
  <table class="<?= $cls ?>-table <?= $cls ?>-plan-table">
    <tbody>
      <tr><th>Payment plan</th><td><?= $planTerms ? h($planTerms['name']) . ' · ' . ($planTerms['kind'] === 'FULL' ? 'Full payment' : 'Installment') : '<span class="' . $cls . '-unassigned">Not yet selected</span>' ?></td></tr>
      <?php if ($previewOff): ?><tr><th>Full-payment discount</th><td class="<?= $cls ?>-num">−₱<?= Money::fmt($previewOff) ?></td></tr><?php endif; ?>
      <tr><th>Total payable</th><td class="<?= $cls ?>-num">₱<?= Money::fmt($payableCents) ?></td></tr>
      <tr><th>Total payments made</th><td class="<?= $cls ?>-num">₱<?= Money::fmt($paidCents) ?></td></tr>
      <tr class="<?= $cls ?>-balance"><th>Remaining balance</th><td class="<?= $cls ?>-num">₱<?= Money::fmt($remainingCents) ?></td></tr>
      <?php if ($planTerms && $planTerms['kind'] === 'FULL'): $due = $planTerms['steps'][0]['due']; ?>
        <?php if ((int) $planView['saved']['earned_cents']): ?><tr><th><?= !empty($planTerms['apply_now']) ? 'Full-payment discount (deducted)' : 'Full-payment discount earned' ?></th><td class="<?= $cls ?>-num">₱<?= Money::fmt((int) $planView['saved']['earned_cents']) ?></td></tr>
        <?php elseif (!$previewOff && $planView['offer'] && !$planView['expired'] && $remainingCents > 0): ?><tr><th>Pay in full by <?= h(date('M j, Y', strtotime($due))) ?></th><td class="<?= $cls ?>-num">₱<?= Money::fmt(max(0, $remainingCents - $planView['offer'])) ?> <small>(saves ₱<?= Money::fmt($planView['offer']) ?>)</small></td></tr>
        <?php else: ?><tr><th>Full payment due</th><td><?= h(date('M j, Y', strtotime($due))) ?></td></tr><?php endif; ?>
      <?php elseif ($planTerms): ?>
        <tr><th>Amount due now</th><td class="<?= $cls ?>-num">₱<?= Money::fmt($planView['due']) ?><?= $planView['overdue'] ? ' <small>(overdue ₱' . Money::fmt($planView['overdue']) . ')</small>' : '' ?></td></tr>
        <tr><th>Next due date</th><td><?= $planView['next'] ? h(date('M j, Y', strtotime($planView['next']))) : 'Fully paid' ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <?php if ($planTerms && $planTerms['kind'] !== 'FULL' && $planView['rows']): ?>
  <table class="<?= $cls ?>-table <?= $cls ?>-plan-steps">
    <thead><tr><th>Installment</th><th>Due</th><th class="<?= $cls ?>-num">Amount</th><th class="<?= $cls ?>-num">Paid</th><th class="<?= $cls ?>-num">Remaining</th></tr></thead>
    <tbody><?php foreach ($planView['rows'] as $step): ?><tr><td><?= h($step['label']) ?></td><td><?= h(date('M j, Y', strtotime($step['due']))) ?></td><td class="<?= $cls ?>-num"><?= Money::fmt($step['amount']) ?></td><td class="<?= $cls ?>-num"><?= Money::fmt($step['paid']) ?></td><td class="<?= $cls ?>-num"><?= Money::fmt($step['remaining']) ?></td></tr><?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
</section>
