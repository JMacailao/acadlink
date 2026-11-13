<?php
declare(strict_types=1);
$isStudent = Auth::can('self') && !Auth::can('students');
if (!$isStudent) Auth::require('registration','accounting','payments.view','balances.view');
$sy = trim((string)($_GET['sy'] ?? $_POST['school_year'] ?? selected_sy()));
if (!GS::validSchoolYear($sy)) throw new AppError('Choose a school year such as 2027-2028.');
$term = in_array($_GET['term'] ?? $_POST['term'] ?? '',TERMS,true) ? (string)($_GET['term'] ?? $_POST['term']) : '1st Semester';
$q = trim((string)($_GET['q'] ?? ''));
$status = isset(BalanceWorkspace::REQUEST_STATES[$_GET['status'] ?? '']) ? $_GET['status'] : 'ALL';
$payment = isset(BalanceWorkspace::PAYMENT_STATES[$_GET['payment'] ?? '']) ? $_GET['payment'] : 'ALL';
$page = max(1,(int)($_GET['page'] ?? 1));
$num = $isStudent ? (string)Auth::studentNumber() : trim((string)($_GET['student'] ?? $_POST['student_number'] ?? ''));
$keep = ['sy'=>$sy,'term'=>$term,'q'=>$q,'status'=>$status,'payment'=>$payment,'page'=>$page];
if ($isPost) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'request') {
        if (!$isStudent) Auth::require('registration');
        BalanceEnrollment::request($num,$sy,$term,(string)($_POST['option_code'] ?? ''),(int)In::money($_POST,'requested_payment',true,'Initial payment'),(string)In::str($_POST,'reason',1000,true,'Reason'));
        flash('success','Request submitted for review. This does not confirm enrollment.');
    } elseif ($action === 'decide') {
        if ($isStudent) throw new Forbidden('Students cannot review requests.');
        $caseId=(int)In::int($_POST,'case_id',true,'Request');
        $selected=BalanceWorkspace::case($caseId);
        if (!$selected) throw new AppError('Request not found.');
        BalanceEnrollment::decide($caseId,(string)In::enum($_POST,'decision',['APPROVED','REJECTED'],true,'Decision'),(string)In::str($_POST,'decision_reason',1000,true,'Decision reason'),(string)In::enum($_POST,'expected_status',['PENDING','APPROVED'],true,'Request status'));
        $num=$selected['student_number']; $sy=$selected['target_school_year']; $term=$selected['target_term'];
        $keep['sy']=$sy; $keep['term']=$term;
        flash('success','Decision recorded. Financial approval and payment status remain separate.');
    } else throw new AppError('Unknown balance action.');
    header('Location: '.url('balance',$keep+['student'=>$num]).'#balanceDetails'); exit;
}
$directory = !$isStudent ? BalanceWorkspace::directory($sy,$term) : null;
$filtered = !$isStudent ? BalanceWorkspace::filterRows($directory['rows'],$q,$status,$payment) : [];
$total = count($filtered); $pages=max(1,(int)ceil($total/25));$page=min($page,$pages);$keep['page']=$page;
$visible=array_slice($filtered,($page-1)*25,25);
$student=$num!=='' ? GS::studentByNumber($num) : null;
$registrations=$student ? BalanceWorkspace::priorRegistrations($num,$sy,$term) : [];
$cases=$student ? Db::all('SELECT c.*, u.full_name AS reviewer_name FROM sis_balance_cases c LEFT JOIN sis_users u ON u.id=c.reviewed_by WHERE c.student_number=? AND c.target_school_year=? AND c.target_term=? ORDER BY id DESC',[$num,$sy,$term]) : [];
$reviewCase=null;
foreach ($cases as $c) if (!$reviewCase || (!in_array($reviewCase['status'],['PENDING','APPROVED'],true) && in_array($c['status'],['PENDING','APPROVED'],true))) $reviewCase=$c;
$current=$student ? BalanceEnrollment::outstanding($num,$sy,$term) : ['cents'=>0,'items'=>[]];
$canRequest=$student && (!$reviewCase || !in_array($reviewCase['status'],['PENDING','APPROVED'],true)) && $current['cents']>0 && ($isStudent || Auth::can('registration')) && (Settings::on('balance_allow_arrangement')||Settings::on('balance_allow_special'));
layout_start('Existing Balances');
page_title('Existing Balances','','Review previous-term balances and enrollment arrangements.');
?>
<?php if (!$isStudent): ?>
<div class="workspace-stats" aria-label="Balance summary">
<?php foreach ([['cash-stack','Previous balance','₱'.Money::fmt($directory['total_balance']),'green'],['people','Students with balances',$directory['students'],'blue'],['clock','Pending requests',$directory['pending'],'amber'],['check2','Approved requests',$directory['approved'],'green']] as [$icon,$label,$value,$color]): ?>
<div class="workspace-stat"><span class="stat-icon is-<?= $color ?>"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></span><div><span><?= h($label) ?></span><strong><?= h($value) ?></strong></div></div>
<?php endforeach; ?>
</div>
<p class="workspace-scope">Target: <?= h($sy.' · '.$term) ?>. Summary totals cover this target term before search and status filters.</p>
<form method="get" class="workspace-filters" role="search"><input type="hidden" name="r" value="balance">
<div class="filter-search"><label for="balanceSearch">Student</label><div class="workspace-search"><i class="bi bi-search" aria-hidden="true"></i><input id="balanceSearch" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Student ID or name"></div></div>
<div><label for="balanceYear">Target school year</label><input id="balanceYear" class="form-control" name="sy" maxlength="9" value="<?= h($sy) ?>" required></div>
<div><label for="balanceTerm">Term</label><select id="balanceTerm" class="form-select" name="term"><?= list_options(TERMS,$term) ?></select></div>
<div><label for="balanceStatus">Request status</label><select id="balanceStatus" class="form-select" name="status"><?php foreach(BalanceWorkspace::REQUEST_STATES as $key=>$label): ?><option value="<?= $key ?>"<?= $status===$key?' selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
<div><label for="balancePayment">Payment status</label><select id="balancePayment" class="form-select" name="payment"><?php foreach(BalanceWorkspace::PAYMENT_STATES as $key=>$label): ?><option value="<?= $key ?>"<?= $payment===$key?' selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
<button class="btn btn-primary">Apply filters</button><a class="btn btn-outline-secondary" href="<?= h(url('balance',['sy'=>$sy,'term'=>$term])) ?>">Reset</a>
</form>
<section class="workspace-card" aria-labelledby="balanceDirectoryTitle"><div class="workspace-card-head"><div><h2 id="balanceDirectoryTitle">Student balances</h2><p><?= $total ?> matching student<?= $total===1?'':'s' ?></p></div></div>
<div class="workspace-table-wrap"><table class="table workspace-table mb-0"><thead><tr><th>Student</th><th class="money">Previous balance</th><th>Payment status</th><th>Request status</th><th>Request type</th><th class="text-end">Action</th></tr></thead><tbody>
<?php foreach($visible as $row): $href=url('balance',array_replace($keep,['student'=>$row['number']])).'#balanceDetails'; ?>
<tr<?= $num===$row['number']?' class="workspace-selected"':'' ?>><td data-label="Student"><strong><?= h($row['name']) ?></strong><span class="d-block small text-muted"><?= h($row['number']) ?></span></td><td data-label="Previous balance" class="money fw-semibold">₱<?= Money::fmt($row['balance']) ?></td><td data-label="Payment status"><?= BalanceWorkspace::badge($row['payment_state'],true) ?></td><td data-label="Request status"><?= BalanceWorkspace::badge($row['request_state']) ?></td><td data-label="Request type" class="small"><?= $row['case'] ? ($row['case']['option_code']==='SPECIAL'?'Special approval':'Payment arrangement') : '—' ?></td><td data-label="Action" class="text-end"><a class="btn btn-sm <?= $row['request_state']==='PENDING'?'btn-primary':'btn-outline-primary' ?>" data-workspace-link href="<?= h($href) ?>"><?= $row['request_state']==='PENDING'?'Review':'View' ?><span class="visually-hidden"> <?= h($row['name']) ?></span></a></td></tr>
<?php endforeach; if (!$visible): ?><tr><td colspan="6" class="workspace-empty"><i class="bi bi-search" aria-hidden="true"></i><strong>No matching student balances</strong><span>Try a different name, target term or request status.</span></td></tr><?php endif; ?>
</tbody></table></div>
<div class="workspace-pagination"><span><?= $total ? 'Showing '.(($page-1)*25+1).'–'.min($page*25,$total).' of '.$total.' students' : 'No results' ?></span><nav aria-label="Balance pages"><?php if($page>1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url('balance',array_replace($keep,['page'=>$page-1]))) ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if($page<$pages): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url('balance',array_replace($keep,['page'=>$page+1]))) ?>">Next</a><?php endif; ?></nav></div></section>
<?php endif; ?>
<?php if ($num!=='' && !$student): ?><div class="alert alert-warning mt-3">Student not found. Use an existing Student ID.</div><?php endif; ?>
<?php if($student): ?>
<section class="workspace-card mt-3" id="balanceDetails" tabindex="-1" aria-labelledby="balanceDetailsTitle"><div class="workspace-card-head"><div><h2 id="balanceDetailsTitle"><?= h($student['studentName']) ?></h2><p><?= h($num.' · Target '.$sy.' · '.$term) ?></p></div><div class="d-flex flex-wrap gap-2"><?php if(Auth::can('registration')): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(url('eligibility',['student'=>$num,'sy'=>$sy,'term'=>$term])) ?>">Check eligibility</a><?php endif; ?><?php if(!$isStudent): ?><a class="btn btn-sm btn-outline-secondary" data-workspace-return href="<?= h(url('balance',$keep)) ?>">Close details</a><?php endif; ?></div></div>
<div class="workspace-detail-body"><div class="balance-current"><span>Current previous-term balance</span><strong>₱<?= Money::fmt($current['cents']) ?></strong><p><?= $current['cents']<=0?'The previous balance is settled. Other enrollment requirements may still apply.':'Amounts come from the original registrations and posted payments.' ?></p></div>
<div class="workspace-table-wrap"><table class="table workspace-table mb-0"><thead><tr><th>Original registration</th><th>School year / term</th><th class="money">Assessment</th><th class="money">Paid</th><th class="money">Balance</th><th>Action</th></tr></thead><tbody>
<?php foreach($registrations as $r): ?><tr><td data-label="Registration"><?= h($r['reg_no']) ?></td><td data-label="Term"><?= h($r['school_year'].' · '.$r['term']) ?></td><td data-label="Assessment" class="money">₱<?= Money::fmt(Money::cents($r['total_amount'])) ?></td><td data-label="Paid" class="money">₱<?= Money::fmt(Money::cents($r['amount_paid'])) ?></td><td data-label="Balance" class="money">₱<?= Money::fmt(max(0,Registration::balance($r))) ?></td><td data-label="Action"><?php if(Settings::on('balance_allow_pay') && Registration::balance($r)>0 && Auth::can('payments.view')): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(url('cashier',['reg'=>$r['id']])) ?>"><?= Auth::can('payments.record')?'Pay at Cashier':'View at Cashier' ?></a><?php else: ?><span class="small text-muted"><?= Registration::balance($r)>0?'Contact Cashier':'Settled' ?></span><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$registrations): ?><tr><td colspan="6" class="text-muted p-3">No earlier active registrations for this target term.</td></tr><?php endif; ?></tbody></table></div>
<?php if($registrations): $sourceIds=array_column($registrations,'id'); $sourcePayments=Db::all('SELECT p.*, r.reg_no FROM sis_payments p JOIN sis_registrations r ON r.id=p.registration_id WHERE p.registration_id IN '.Db::in($sourceIds).' ORDER BY p.paid_at DESC,p.id DESC LIMIT 50',$sourceIds); ?>
<details class="request-history"><summary>Payment history on earlier registrations · latest <?= count($sourcePayments) ?> receipts</summary>
<div class="workspace-table-wrap"><table class="table workspace-table mb-0"><thead><tr><th>Receipt</th><th>Registration</th><th>Date</th><th>Method</th><th class="money">Amount</th><th>Status</th></tr></thead><tbody>
<?php foreach($sourcePayments as $p): ?><tr><td data-label="Receipt"><?php if(Auth::can('payments.view')||$isStudent): ?><a href="<?= h(url('receipt',['id'=>$p['id']])) ?>"><?= h($p['receipt_no']) ?></a><?php else: ?><?= h($p['receipt_no']) ?><?php endif; ?></td><td data-label="Registration"><?= h($p['reg_no']) ?></td><td data-label="Date"><?= h($p['paid_at']) ?></td><td data-label="Method"><?= h($p['method']) ?></td><td data-label="Amount" class="money">₱<?= Money::fmt(Money::cents($p['amount'])) ?></td><td data-label="Status"><span class="workspace-status <?= $p['status']==='POSTED'?'is-positive':'is-negative' ?>"><?= h($p['status']) ?></span></td></tr><?php endforeach; ?>
<?php if(!$sourcePayments): ?><tr><td colspan="6" class="text-muted p-3">No receipts recorded against these earlier registrations.</td></tr><?php endif; ?></tbody></table></div>
</details><?php endif; ?>
<?php if($reviewCase): $reviewAction=url('balance',array_replace($keep,['student'=>$num])); require SIS_ROOT . '/app/views/balance_case_review.php'; endif; ?>
<?php if($canRequest): ?>
<details class="request-new mt-3"<?= !$reviewCase?' open':'' ?>><summary>Request a payment arrangement or special approval</summary><form method="post" action="<?= h(url('balance',array_replace($keep,['student'=>$num]))) ?>" class="row g-3 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="student_number" value="<?= h($num) ?>"><input type="hidden" name="school_year" value="<?= h($sy) ?>"><input type="hidden" name="term" value="<?= h($term) ?>">
<div class="col-md-6"><label for="requestOption" class="form-label">Request type</label><select class="form-select" id="requestOption" name="option_code"><?php if(Settings::on('balance_allow_arrangement')): ?><option value="ARRANGEMENT">Payment arrangement</option><?php endif; ?><?php if(Settings::on('balance_allow_special')): ?><option value="SPECIAL">Special approval</option><?php endif; ?></select></div>
<div class="col-md-6"><label for="requestPayment" class="form-label">Proposed initial payment *</label><div class="input-group"><span class="input-group-text">₱</span><input class="form-control" id="requestPayment" name="requested_payment" inputmode="decimal" value="<?= h(Settings::get('balance_min_payment')) ?>" required></div></div>
<div class="col-12"><label for="requestReason" class="form-label">Reason and proposed terms *</label><textarea class="form-control" id="requestReason" name="reason" maxlength="1000" rows="3" required></textarea><p class="form-text"><?= h(Settings::get('balance_installment_rules')) ?></p></div><div class="col-12"><button class="btn btn-primary">Submit request</button></div></form></details>
<?php endif; ?>
<?php if(count($cases)>1): ?><details class="request-history mt-3"><summary>Other requests for this target term</summary><ul><?php foreach($cases as $old) if($old['id']!==$reviewCase['id']): ?><li><strong>Request #<?= (int)$old['id'] ?> · <?= h($old['status']) ?></strong><span><?= h($old['created_at']) ?></span><p><?= h($old['decision_reason'] ?: $old['request_reason']) ?></p></li><?php endif; ?></ul></details><?php endif; ?>
</div></section>
<?php elseif(!$isStudent && $visible): ?><p class="workspace-footnote mt-3">Choose Review or View to inspect a student's original balances, request and decision history.</p><?php endif; ?>
<?php layout_end();
