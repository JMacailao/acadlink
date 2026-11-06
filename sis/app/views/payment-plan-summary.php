<?php
/** Caller must already have authorized access to $r. No writes are performed here. */
$planSummary=PaymentPlans::schedule($r);
if($planSummary): $terms=$planSummary['saved']['terms']; ?>
<section class="form-section payment-plan-summary" aria-label="Payment plan">
<h2 class="h6">Payment plan · <?= h($terms['name']) ?></h2>
<p class="small">Amount due now: <strong>₱<?= Money::fmt($planSummary['due']) ?></strong> · Overdue: ₱<?= Money::fmt($planSummary['overdue']) ?> · Next unpaid due date: <?= h($planSummary['next']??'Fully paid') ?></p>
<?php if($terms['kind']==='FULL'&&$planSummary['offer']): ?>
<p class="small <?= (int)$planSummary['saved']['earned_cents']?'text-success':'text-muted' ?>"><?php if((int)$planSummary['saved']['earned_cents']): ?>Earned full-payment discount: ₱<?= Money::fmt((int)$planSummary['saved']['earned_cents']) ?>.<?php elseif(!$planSummary['expired']): ?>Conditional offer: pay ₱<?= Money::fmt(max(0,Registration::balance($r)-$planSummary['offer'])) ?> more by <?= h($terms['steps'][0]['due']) ?> to earn a ₱<?= Money::fmt($planSummary['offer']) ?> discount. Regular balance applies until qualified.<?php else: ?>Full-payment offer expired without qualifying payment. Regular balance applies.<?php endif; ?></p>
<?php endif; ?>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Installment</th><th>Percent</th><th>Due</th><th class="money">Amount</th><th class="money">Paid</th><th class="money">Remaining</th><th>Status</th></tr></thead><tbody>
<?php foreach($planSummary['rows'] as $step): ?><tr><td><?= h($step['label']) ?></td><td><?= h(Money::dec((int)$step['bp'])) ?>%</td><td><?= h($step['due']) ?></td><td class="money"><?= Money::fmt($step['amount']) ?></td><td class="money"><?= Money::fmt($step['paid']) ?></td><td class="money"><?= Money::fmt($step['remaining']) ?></td><td><?= h($step['status']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><p class="small text-muted mb-0">Payments apply to the earliest unpaid installment. Partial and advance payments are allowed. Voids and posted refunds recalculate the schedule. Saved plan terms are retained.</p>
</section>
<?php endif; ?>
