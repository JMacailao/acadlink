<?php
declare(strict_types=1);
if (Auth::studentNumber()) redirect('myenrollment');
$problem='';
if($isPost) {
    try { Admission::applyOwn($_POST); flash('success','Application submitted. The registrar will review it.'); redirect('myadmission'); }
    catch(AppError $e) { $problem=$e->getMessage(); }
}
$aid=(int)Auth::centralId();
$applications=Db::all("SELECT a.* FROM central_record_owners o JOIN sis_admissions a ON CAST(a.id AS CHAR)=o.entity_key WHERE o.workspace='SIS' AND o.entity_type='admission' AND o.account_id=? ORDER BY a.id DESC",[$aid]);
$canApply=Auth::central()->can($aid,'portal.admission.apply');
$open=Db::all('SELECT * FROM sis_term_calendar WHERE CURDATE() BETWEEN enrollment_start AND enrollment_end ORDER BY school_year DESC,term');
$v=fn(string $key,string $default='')=>h((string)($_POST[$key]??$default));
layout_start('My admission application'); page_title('My admission application','','Submit your application, then follow the registrar’s decision here.');
if($problem): ?><div class="alert alert-danger" role="alert"><?= h($problem) ?></div><?php endif; ?>
<div class="form-section"><h2 class="h6">My applications</h2><div class="table-responsive"><table class="table"><thead><tr><th>Application</th><th>School year / term</th><th>Status</th><th>Student ID</th></tr></thead><tbody><?php foreach($applications as $a): ?><tr><td><?= h($a['admission_no']) ?></td><td><?= h($a['school_year'].' · '.($a['application_term']??'')) ?></td><td><?= badge($a['status']) ?></td><td><?= h($a['student_number']??'Awaiting approval') ?></td></tr><?php endforeach; if(!$applications): ?><tr><td colspan="4">No applications yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php if(!$canApply): ?><div class="alert alert-info">Application submission is currently disabled for your account.</div>
<?php elseif(!$open): ?><div class="alert alert-info">Enrollment is currently closed. Check back when the school opens an application period.</div>
<?php else: ?><form method="post" class="form-section"><?= csrf_field() ?><h2 class="h5">Submit an application</h2><p class="small text-muted">Staff will review your application. Submitting does not confirm enrollment.</p><div class="row g-3">
<div class="col-md-4"><label class="form-label">School year</label><select class="form-select" name="school_year"><?php foreach(array_unique(array_column($open,'school_year')) as $sy): ?><option <?= ($_POST['school_year']??'')===$sy?'selected':'' ?>><?= h($sy) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Term</label><select class="form-select" name="application_term"><?php foreach(array_unique(array_column($open,'term')) as $term): ?><option <?= ($_POST['application_term']??'')===$term?'selected':'' ?>><?= h($term) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label" for="own_type">Student classification *</label><select class="form-select" id="own_type" name="applicant_type" required><option value="">Choose…</option><?php foreach(Auth::studentNumber()?['CONTINUING'=>'Continuing Student','RETURNEE'=>'Returnee (readmission)']:['NEW'=>'New Student','TRANSFEREE'=>'Transferee','UNIT_EARNER'=>'Unit Earner'] as $k=>$label): ?><option value="<?= $k ?>" <?= ($_POST['applicant_type']??'')===$k?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
<?php foreach(['last_name'=>['Last name',80,true],'first_name'=>['First name',80,true],'middle_name'=>['Middle name',80,false],'program'=>['Program',40,true],'previous_school'=>['Previous school (transferees)',150,false],'email'=>['Email',191,false],'phone'=>['Phone',30,false],'address'=>['Complete address',255,true],'guardian_name'=>['Guardian name',150,false],'guardian_phone'=>['Guardian phone',30,false]] as $key=>[$label,$max,$required]): ?><div class="col-md-4"><label class="form-label" for="own_<?= $key ?>"><?= h($label) ?></label><input class="form-control" id="own_<?= $key ?>" name="<?= $key ?>" maxlength="<?= $max ?>" <?= $required?'required':'' ?> value="<?= $v($key) ?>" <?= $key==='email'?'type="email"':'' ?>></div><?php endforeach; ?>
<div class="col-md-4"><label class="form-label">Birth date</label><input class="form-control" type="date" name="birth_date" required value="<?= $v('birth_date') ?>"></div><div class="col-md-4"><label class="form-label">Year level</label><select class="form-select" name="year_level"><?= list_options(YEAR_LEVELS,$_POST['year_level']??'1st Year') ?></select></div>
</div>
<?php $docReqs = EnrollmentDocs::requirements(true); if ($docReqs): ?><div class="mt-3"><h3 class="h6">Documents to submit to the Registrar</h3><ul class="small mb-0"><?php foreach ($docReqs as $dr): ?><li><?= h($dr['name']) ?> <span class="text-muted">· <?= (int) $dr['is_required'] ? 'required' : 'optional' ?> for <?= h(implode(', ', array_map(fn($t) => Admission::TYPES[$t] ?? $t, $dr['types']))) ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>
<button class="btn btn-primary mt-3">Submit application for review</button></form><?php endif; layout_end();
