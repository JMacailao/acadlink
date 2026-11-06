<?php
/** Shared request details. $reviewCase and $reviewAction come from an authorized page. */
$approved = $reviewCase['status'] === 'APPROVED';
$canApprove = $reviewCase['status'] === 'PENDING' && BalanceEnrollment::canApprove($reviewCase['option_code']);
$canRevoke = $approved && NotificationCenter::canDeclineApproved();
$caseHistory = BalanceWorkspace::history((int)$reviewCase['id']);
$currentBalance = BalanceEnrollment::outstanding($reviewCase['student_number'],$reviewCase['target_school_year'],$reviewCase['target_term']);
?>
<section class="request-review" id="requestReview" aria-labelledby="requestReviewTitle">
  <div class="workspace-card-head"><div><h2 id="requestReviewTitle">Review <?= $reviewCase['option_code']==='SPECIAL' ? 'special approval' : 'payment arrangement' ?></h2><p><?= h($reviewCase['student_name'].' · '.$reviewCase['student_number']) ?></p></div><?= BalanceWorkspace::badge($reviewCase['status']) ?></div>
  <div class="request-review-body">
    <dl class="request-facts">
      <div><dt>Current previous balance</dt><dd>₱<?= Money::fmt($currentBalance['cents']) ?></dd></div>
      <div><dt>Requested initial payment</dt><dd>₱<?= Money::fmt(Money::cents($reviewCase['requested_payment'])) ?></dd></div>
      <div><dt>Target enrollment</dt><dd class="fact-text"><?= h($reviewCase['target_term'].' · '.$reviewCase['target_school_year']) ?></dd></div>
    </dl>
    <div class="request-description"><strong>Reason for request</strong><p><?= nl2br(h($reviewCase['request_reason'])) ?></p></div>
    <?php if ($reviewCase['arrangement_rules']): ?><div class="request-description"><strong>Arrangement conditions</strong><p><?= nl2br(h($reviewCase['arrangement_rules'])) ?></p></div><?php endif; ?>
    <?php if ($reviewCase['decision_reason']): ?><div class="request-description"><strong>Latest decision</strong><p><?= nl2br(h($reviewCase['decision_reason'])) ?><span class="d-block small text-muted"><?= h(($reviewCase['reviewer_name'] ?: 'Authorized staff').' · '.$reviewCase['reviewed_at']) ?></span></p></div><?php endif; ?>
    <?php if ($canApprove || $canRevoke): ?>
    <form method="post" action="<?= h($reviewAction) ?>" class="request-decision" data-confirm="Record this decision? The request's latest status will be checked again.">
      <?= csrf_field() ?><input type="hidden" name="action" value="decide"><input type="hidden" name="case_id" value="<?= (int)$reviewCase['id'] ?>"><input type="hidden" name="expected_status" value="<?= h($reviewCase['status']) ?>">
      <label class="form-label" for="decisionReason">Decision reason *</label><textarea id="decisionReason" class="form-control" name="decision_reason" required minlength="5" maxlength="1000" rows="2" placeholder="Enter your reason for approving or declining this request."></textarea>
      <div class="request-actions">
        <?php if ($canApprove): ?><button class="btn btn-primary" name="decision" value="APPROVED">Approve</button><button class="btn btn-outline-danger" name="decision" value="REJECTED">Decline</button>
        <?php else: ?><button class="btn btn-outline-danger" name="decision" value="REJECTED">Revoke approval</button><?php endif; ?>
        <?php if (Auth::can('registration')): ?><a class="workspace-text-link" href="<?= h(url('eligibility',['student'=>$reviewCase['student_number'],'sy'=>$reviewCase['target_school_year'],'term'=>$reviewCase['target_term']])) ?>">Check enrollment eligibility</a><?php endif; ?>
      </div>
    </form>
    <?php else: ?><p class="small text-muted">There is no review action available for this request and your role.</p><?php endif; ?>
    <p class="workspace-footnote">Approval does not mean the balance is settled. Enrollment eligibility and arrangement payment conditions are checked separately.</p>
    <details class="request-history"><summary>Decision history · <?= count($caseHistory) ?> event<?= count($caseHistory)===1?'':'s' ?></summary>
      <ol><?php foreach ($caseHistory as $event): $details=json_decode((string)$event['details'],true) ?: []; ?><li><strong><?= h(str_replace('_',' ',$event['event_type'])) ?></strong><span><?= h(($event['actor_name'] ?: 'Authorized staff').' · '.$event['created_at']) ?></span><?php if (!empty($details['reason'])): ?><p><?= nl2br(h($details['reason'])) ?></p><?php endif; ?></li><?php endforeach; ?></ol>
      <?php if (!$caseHistory): ?><p class="small text-muted">No recorded events for this older request.</p><?php endif; ?>
    </details>
  </div>
</section>
