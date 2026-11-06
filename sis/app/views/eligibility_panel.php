<?php
/** @var array $eligibility A server-computed result; never accept this payload back as authorization. */
$eligibilityStyles = ['PASS' => 'success', 'ELIGIBLE' => 'success', 'WARNING' => 'warning', 'OVERRIDDEN' => 'warning', 'NEEDS_APPROVAL' => 'warning', 'BLOCKED' => 'danger', 'INFO' => 'secondary'];
?>
<?php $creatingBlockers = array_filter($eligibility['checks'], fn($c) => in_array($c['state'], ['BLOCKED','NEEDS_APPROVAL'], true) && $c['scope'] === 'ENROLLMENT');
$canCreationException = Auth::can('users.manage') && $creatingBlockers && !array_filter($creatingBlockers, fn($c) => !$c['overrideable']); ?>
<section data-can-create="<?= $eligibility['can_create'] ? '1' : '0' ?>" data-can-except="<?= $canCreationException ? '1' : '0' ?>" class="form-section eligibility-panel d-print-none" aria-labelledby="eligibilityTitle">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <h2 class="h6 mb-0 me-auto" id="eligibilityTitle"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Enrollment eligibility</h2>
    <span class="badge text-bg-<?= $eligibilityStyles[$eligibility['result']] ?>"><?= h(str_replace('_', ' ', $eligibility['result'])) ?></span>
  </div>
  <?php if (!empty($eligibilityHistorical)): ?><p class="small text-muted">Saved eligibility decision at confirmation; this is historical, not a new check.</p><?php endif; ?>
  <p class="small text-muted mb-3"><?= h($eligibility['student_number'] . ' · ' . $eligibility['school_year'] . ' · ' . $eligibility['term']) ?> · Checked <?= h(date('M j, Y g:i A', strtotime($eligibility['checked_at']))) ?></p>
  <?php
  $primaryChecks = array_filter($eligibility['checks'], fn($c) => !in_array($c['state'], ['PASS','INFO'], true) || $c['code'] === 'documents');
  $otherChecks = array_filter($eligibility['checks'], fn($c) => in_array($c['state'], ['PASS','INFO'], true) && $c['code'] !== 'documents');
  foreach ([$primaryChecks, $otherChecks] as $groupIndex => $groupChecks): if (!$groupChecks) continue; ?>
  <?php if ($groupIndex === 1): ?><details class="eligibility-details mt-2"><summary class="small fw-semibold">View <?= count($groupChecks) ?> passed / informational checks</summary><?php endif; ?>
  <ul class="eligibility-checks<?= $groupIndex === 1 ? ' mt-2' : '' ?>">
    <?php foreach ($groupChecks as $check): ?>
    <li class="eligibility-check eligibility-<?= h(strtolower($check['state'])) ?>">
      <div class="d-flex flex-wrap align-items-center gap-2"><strong><?= h($check['label']) ?></strong><span class="badge text-bg-<?= $eligibilityStyles[$check['state']] ?>"><?= h(str_replace('_', ' ', $check['state'])) ?></span><?= $check['scope'] === 'SUBJECT' ? '<span class="small text-muted">Selected subject only</span>' : '' ?></div>
      <p class="small mb-1 mt-1"><?= h($check['message']) ?></p>
      <div class="small text-muted">Responsible office: <?= h($check['office']) ?><?php if ($check['route'] !== ''): ?> · <a href="<?= url($check['route'], $check['params']) ?>" target="_top">View / resolve</a><?php endif; ?></div>
      <?php if (!empty($check['override_reason'])): ?><div class="small mt-1">Administrator exception: <?= h($check['override_reason']) ?></div><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($groupIndex === 1): ?></details><?php endif; ?>
  <?php endforeach; ?>
  <p class="small mt-3 mb-0"><?= $eligibility['can_confirm'] ? 'Eligibility checks pass under the current school policy. Assessment checks must also pass before confirmation.' : 'Resolve the listed restrictions before confirming. Subject restrictions can be resolved by removing the affected subject from the draft.' ?> All rules are checked again on the server when you submit.</p>
</section>
