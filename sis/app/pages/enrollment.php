<?php
declare(strict_types=1);
$id=In::int($_GET,'id');
if ($isPost) {
    $id=(int)In::int($_POST,'id',true,'Application'); $action=(string)($_POST['action']??'');
    if ($action==='accept') { $rid=PortalEnrollment::accept($id,$_POST); flash('success','Application accepted. Review subjects, fees and discounts, then publish the assessment to the student.'); redirect('registration',['id'=>$rid]); }
    PortalEnrollment::decide($id,$action,(string)($_POST['reason']??'')); flash('success','Registrar decision saved. The student can view it in the portal.'); redirect('enrollment',['id'=>$id]);
}
layout_start('Enrollment Applications'); page_title('Enrollment Applications','','Review Student Portal applications, then prepare and publish their assessments.');
if ($id):
    $a=Db::one('SELECT * FROM sis_portal_enrollments WHERE id=?',[$id]); if (!$a) throw new AppError('Application not found.');
    $student=GS::studentByNumber($a['student_number']); $check=$student?PortalEnrollment::eligibility($student,$a['school_year'],$a['term']):null;
    [$progress]=PortalEnrollment::progress($a);
    $placementOptions=$student?PortalEnrollment::placementOptions($student,$a['school_year'],$a['term']):['levels'=>[],'sections'=>[]];
    $requestedLevel=(string)($a['requested_year_level']??'');
    $reviewSections=array_values(array_filter($placementOptions['sections'],fn($s)=>$requestedLevel==='' || $s['year_level']===$requestedLevel));
?>
<a class="btn btn-sm btn-outline-secondary mb-3" href="<?= url('enrollment',['apply'=>1]) ?>">All applications</a><div class="form-section"><h2 class="h5"><?= h($a['student_name'].' · '.$a['student_number']) ?></h2><p><?= h($a['school_year'].' · '.$a['term'].' · '.$progress) ?></p><?php $application=$a; include SIS_ROOT.'/app/views/enrollment_placement.php'; ?><p class="mt-3"><strong>Student note:</strong> <?= h($a['student_note']?:'None') ?></p><?php if ($student): ?><a href="<?= url('history',['id'=>$a['student_doc_id'],'tab'=>'grades']) ?>">Review student history</a><?php endif; ?><?php if ($a['registration_id'] && Auth::can('registration')): ?> · <a href="<?= url('registration',['id'=>$a['registration_id']]) ?>">Open registration / assessment</a><?php endif; ?></div>
<?php if ($check): ?><div class="form-section"><h2 class="h6">Current eligibility checks</h2><ul class="small"><?php foreach ($check['checks'] as $c): ?><li><?= h($c['state'].' · '.$c['message']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($a['status']==='PENDING'): ?>
<?php if (Auth::can('registration')): ?><form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="accept"><h2 class="h6">Accept and create a registration draft</h2><div class="row g-3"><div class="col-md-6"><label class="form-label" for="enrollSection">Approve section</label><select class="form-select" name="section_id" id="enrollSection" required><option value="">Choose section</option><?php foreach ($reviewSections as $s): ?><option value="<?= h($s['id']) ?>"<?= $s['id']===($a['preferred_section_doc_id']??'')?' selected':'' ?>><?= h($s['year_level'].' · '.$s['name'].($s['id']===($a['preferred_section_doc_id']??'')?' (student preference)':'')) ?></option><?php endforeach; ?></select><p class="form-text">Choose the student’s preferred section or another eligible section in the requested year level. Placement takes effect only on enrollment confirmation.</p></div><div class="col-md-6"><label class="form-label" for="enrollCur">Curriculum</label><select class="form-select" id="enrollCur" name="curriculum_id"><option value="">Match section and term automatically</option><?php foreach (GS::curricula() as $cur) if (($cur['term']??'')===$a['term'] && ($requestedLevel==='' || ($cur['yearLevel']??'')===$requestedLevel)): ?><option value="<?= h($cur['id']) ?>"><?= h(($cur['program']??'').' · '.($cur['yearLevel']??'').' · '.($cur['term']??'')) ?></option><?php endif; ?></select></div><div class="col-md-6"><label class="form-label" for="enrollFees">Assessment template</label><select class="form-select" name="assessment_name_id" id="enrollFees"><option value="">Match automatically</option><?php foreach (Assessment::names(true) as $fee): ?><option value="<?= (int)$fee['id'] ?>"><?= h($fee['name']) ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label" for="enrollReview">Review note (optional)</label><textarea class="form-control" id="enrollReview" name="review_note" maxlength="1000" rows="2"></textarea></div></div><label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="review_confirmed" value="1" required><span class="form-check-label">I verified identity, requirements, promotion, year level and curriculum. Complete grades alone do not prove passing or promotion.</span></label><button class="btn btn-primary mt-3"<?= !$check || !$check['can_create']?' disabled':'' ?>>Accept and prepare assessment</button></form><?php endif; ?>
<form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><h2 class="h6">Return or reject</h2><label class="form-label" for="enrollReason">Reason / correction instructions</label><textarea class="form-control" id="enrollReason" name="reason" required maxlength="1000" rows="2"></textarea><div class="d-flex gap-2 mt-3"><button class="btn btn-outline-primary" name="action" value="RETURNED">Return for correction</button><button class="btn btn-outline-danger" name="action" value="REJECTED">Reject application</button></div></form><?php endif; ?>
<div class="form-section"><h2 class="h6">Application history</h2><ul><?php foreach (Db::all('SELECT * FROM sis_portal_enrollment_events WHERE application_id=? ORDER BY id DESC',[$id]) as $event): ?><li><?= h($event['created_at'].' · '.$event['message']) ?></li><?php endforeach; ?></ul></div>
<?php else:
$status=(string)($_GET['status']??'ALL'); if (!in_array($status,['PENDING','RETURNED','ACCEPTED','REJECTED','ALL'],true)) $status='ALL';
$fsy=(string)($_GET['sy']??''); $fterm=(string)($_GET['term']??''); $fyear=(string)($_GET['year']??'');
$q=trim((string)($_GET['q']??'')); $params=[]; $where='1=1';
if ($status!=='ALL') { $where.=' AND status=?'; $params[]=$status; }
if ($fsy!=='' && GS::validSchoolYear($fsy)) { $where.=' AND school_year=?'; $params[]=$fsy; }
if (in_array($fterm,TERMS,true)) { $where.=' AND term=?'; $params[]=$fterm; }
if (in_array($fyear,YEAR_LEVELS,true)) { $where.=' AND requested_year_level=?'; $params[]=$fyear; }
if ($q!=='') {
    $byNumber=preg_match('/^(?:APP-\d{4}-)?0*(\d+)$/i',$q,$m)?(int)$m[1]:0;
    $where.=' AND (student_number LIKE ? OR student_name LIKE ?'.($byNumber?' OR id=?':'').')'; $params[]='%'.$q.'%'; $params[]='%'.$q.'%'; if ($byNumber) $params[]=$byNumber;
}
$rows=RecordList::query('SELECT * FROM sis_portal_enrollments WHERE '.$where.' ORDER BY id DESC LIMIT 1000',$params);
$years=Db::col('SELECT DISTINCT school_year FROM sis_portal_enrollments ORDER BY school_year DESC');
$appNo=fn(array $a)=>'APP-'.substr((string)$a['school_year'],0,4).'-'.str_pad((string)$a['id'],4,'0',STR_PAD_LEFT);
// One plain status word per application, taken from its real progress.
$statusOf=function(array $a): string {
    if ($a['status']!=='ACCEPTED') return ['PENDING'=>'Pending','RETURNED'=>'Returned','REJECTED'=>'Rejected'][$a['status']]??ucfirst(strtolower($a['status']));
    [,$step]=PortalEnrollment::progress($a);
    return [2=>'For Assessment',3=>'Payment Due',4=>'Ready to Enroll',5=>'Enrolled'][$step]??'For Assessment';
};
$canRegistration=Auth::can('registration'); $canStudents=Auth::can('students'); $canEditStudents=Auth::can('students.edit');
?>
<?php $waiting=(int)Db::val("SELECT COUNT(*) FROM sis_portal_enrollments WHERE status='PENDING'"); if ($waiting && (!RecordList::applied() || !in_array($status,['ALL','PENDING'],true) || $fsy!=='' || $fterm!=='' || $fyear!=='' || $q!=='')): ?>
<div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2"><i class="bi bi-bell" aria-hidden="true"></i><span class="me-auto"><?= $waiting ?> application<?= $waiting===1?'':'s' ?> from the Student Portal <?= $waiting===1?'is':'are' ?> waiting for review.</span><a class="btn btn-sm btn-primary" href="<?= h(url('enrollment',['apply'=>1,'status'=>'PENDING','sy'=>'','term'=>'','year'=>'','q'=>''])) ?>">Show pending applications</a></div>
<?php endif; ?>
<form id="enrollmentFilters" data-record-filters method="get" class="form-section"><input type="hidden" name="r" value="enrollment">
<label for="appYear">School Year</label><select class="form-select w-auto" id="appYear" name="sy"><option value="">All</option><?php foreach ($years as $y): ?><option value="<?= h($y) ?>"<?= $fsy===$y?' selected':'' ?>><?= h(str_replace('-','–',$y)) ?></option><?php endforeach; ?></select>
<label for="appTerm">Requested Term</label><select class="form-select w-auto" id="appTerm" name="term"><option value="">All</option><?php foreach (TERMS as $t): ?><option<?= $fterm===$t?' selected':'' ?>><?= h($t) ?></option><?php endforeach; ?></select>
<label for="appLevel">Year Level</label><select class="form-select w-auto" id="appLevel" name="year"><option value="">All</option><?php foreach (YEAR_LEVELS as $l): ?><option<?= $fyear===$l?' selected':'' ?>><?= h($l) ?></option><?php endforeach; ?></select>
<label for="appFilter">Status</label><select class="form-select w-auto" id="appFilter" name="status"><?php foreach (['ALL'=>'All','PENDING'=>'Pending','RETURNED'=>'Returned','ACCEPTED'=>'Accepted','REJECTED'=>'Rejected'] as $v=>$text): ?><option value="<?= $v ?>"<?= $status===$v?' selected':'' ?>><?= h($text) ?></option><?php endforeach; ?></select>
<input class="form-control" id="appSearch" name="q" value="<?= h($q) ?>" placeholder="Search application no., student name or student ID" aria-label="Search application no., student name or student ID"><button class="btn btn-primary" name="apply" value="1">Apply filters</button></form>
<div class="form-section"><div class="table-responsive"><table class="table" data-record-noun="application" data-record-title="Application"><thead><tr><th>Application No.</th><th>Student ID</th><th>Student Name</th><th>Current Year / Term</th><th>Requested Year / Term</th><th>Preferred Section</th><th>Status</th><th class="sis-row-actions"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
<?php foreach ($rows as $a): $cur=json_decode((string)($a['current_enrollment']??''),true)?:[]; $doc=(string)$a['student_doc_id']; ?>
<tr><td><?= h($appNo($a)) ?></td><td><?= h($a['student_number']) ?></td><td><?= h($a['student_name']) ?></td>
<td><?= h($cur ? trim(($cur['year_level']??'').' / '.($cur['term']?:'No term recorded'),' /') : 'Not recorded') ?></td>
<td><?= h(($a['requested_year_level']?:'Year level to confirm').' / '.$a['term']) ?></td>
<td><?= h($a['preferred_section_name']?:'Registrar to assign') ?></td><td data-status><?= h($statusOf($a)) ?></td>
<td class="sis-row-actions"><a href="<?= url('enrollment',['id'=>$a['id']]) ?>" data-icon="file-earmark-text">Review Application</a>
<?php if ($a['status']==='PENDING' && $canRegistration): ?><a href="<?= url('enrollment',['id'=>$a['id']]) ?>#enrollSection" data-icon="check2">Approve Section</a><?php endif; ?>
<?php if ($a['registration_id'] && $canRegistration): ?><a href="<?= url('registration',['id'=>$a['registration_id']]) ?>" data-icon="calculator">Prepare Assessment</a><?php endif; ?>
<?php if ($canStudents): ?><a href="<?= url('student',['id'=>$doc]) ?>" data-panel-group="Student Tools" data-icon="<?= $canEditStudents?'pencil-square':'person-vcard' ?>"><?= $canEditStudents?'View and Edit Student':'View Student' ?></a><?php endif; ?>
<a href="<?= url('history',['id'=>$doc,'tab'=>'grades']) ?>" data-panel-group="Student Tools" data-icon="journal-text">History of Grades</a>
<a href="<?= url('history',['id'=>$doc,'tab'=>'assessments']) ?>" data-panel-group="Student Tools" data-icon="receipt">History of Assessment</a></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8"><?= RecordList::applied()?'No applications match these filters. Change a filter or clear them.':'Choose your filters, then click Apply Filters to show applications.' ?></td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; layout_end();
