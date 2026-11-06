<?php
/** @var array $application Historical request and approved registration, displayed without changing either. */
$current=json_decode((string)($application['current_enrollment']??''),true)?:null;
$approved=json_decode((string)($application['approved_placement']??''),true)?:null;
$placementRegistration=!empty($application['registration_id'])?Registration::get((int)$application['registration_id']):null;
if (!$approved && $placementRegistration) $approved=$placementRegistration;
$placementConfirmed=$placementRegistration && $placementRegistration['status']!=='CANCELLED' && !empty($placementRegistration['locked_at']);
?>
<div class="row g-3 my-1">
  <section class="col-md-6"><div class="border rounded p-3 h-100"><h3 class="h6">Current enrollment when submitted</h3>
  <?php if ($current): ?><dl class="row small mb-0"><dt class="col-5">School year</dt><dd class="col-7"><?= h($current['school_year']?:'Not recorded') ?></dd><dt class="col-5">Semester</dt><dd class="col-7"><?= h($current['term']?:'Not recorded') ?></dd><dt class="col-5">Year level</dt><dd class="col-7"><?= h($current['year_level']?:'Not recorded') ?></dd><dt class="col-5">Section</dt><dd class="col-7"><?= h($current['section_name']?:'Not recorded') ?></dd></dl><?php else: ?><p class="small text-muted mb-0">This older application has no saved current-enrollment snapshot. Review the student history.</p><?php endif; ?>
  </div></section>
  <section class="col-md-6"><div class="border rounded p-3 h-100"><h3 class="h6">Applying for</h3><dl class="row small mb-0"><dt class="col-5">School year</dt><dd class="col-7"><?= h($application['school_year']) ?></dd><dt class="col-5">Semester</dt><dd class="col-7"><?= h($application['term']) ?></dd><dt class="col-5">Year level</dt><dd class="col-7"><?= h($application['requested_year_level']?:'Registrar to confirm') ?></dd><dt class="col-5">Preferred section</dt><dd class="col-7"><?= h($application['preferred_section_name']?:'Registrar will assign') ?></dd></dl></div></section>
</div>
<?php if ($approved): ?><div class="alert alert-<?= $placementConfirmed?'success':'info' ?> small mt-3 mb-0"><strong><?= $placementConfirmed?'Confirmed section':'Registrar-approved section' ?>:</strong> <?= h(($approved['year_level']??'').' · '.($approved['section_name']??'')) ?>. <?= $placementConfirmed?'Enrollment has been confirmed.':'Your current section changes only after enrollment is confirmed.' ?></div><?php else: ?><p class="small text-muted mt-3 mb-0">Your preferred section is a request. The registrar approves the final placement.</p><?php endif; ?>
