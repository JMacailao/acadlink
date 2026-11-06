<?php
declare(strict_types=1);
$c=Auth::central(); $actor=(int)Auth::centralId();
try { $c->requirePower($actor); } catch(CentralAccountError $e) { throw new Forbidden($e->getMessage()); }
require_once SIS_ROOT.'/app/lib/bulk-accounts.php';
$bulk=new BulkAccounts($c,$actor); $problem=''; $shownPassword='';
header('Cache-Control: no-store'); header('Referrer-Policy: same-origin');
if (isset($_SESSION['bulk_accounts']) && ($_SESSION['bulk_accounts']['expires']<time() || $_SESSION['bulk_accounts']['actor']!==$actor)) unset($_SESSION['bulk_accounts']);
$action=(string)($_POST['action']??'');
if ($isPost && in_array($action,['run','retry','download'],true)) {
    try {
        $job=&$_SESSION['bulk_accounts'];
        if (!$job || !hash_equals($job['token'],(string)($_POST['token']??''))) throw new CentralAccountError('This batch expired. Preview a new batch.');
        if ($action==='download') {
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="account-batch-results.csv"');
            $out=fopen('php://output','w'); fputcsv($out,['Group','Record ID','Name','Year level','Section','Username','Result','Details']);
            foreach($job['rows'] as $r) { $cells=[$job['group'],$r['number'],$r['name'],$r['year_level']??'',$r['section_name']??'',$r['username'],$r['status'],$r['message']]; fputcsv($out,array_map(fn($v)=>preg_match('/^[=+@\-\t\r]/',(string)$v)?"'".$v:$v,$cells)); }
            fclose($out); exit;
        }
        $failed=array_filter($job['rows'],fn($r)=>$r['status']==='Failed');
        if (!empty($job['finished']) && !$failed) {
            header('Content-Type: application/json'); echo json_encode(['rows'=>$job['rows'],'finished'=>true],JSON_THROW_ON_ERROR); exit;
        }
        $password=(string)($_POST['password']??'');
        if (!password_verify($password,$job['password_hash'])) throw new CentralAccountError('Enter the original shared temporary password for this batch.');
        if ($action==='retry') {
            foreach($job['rows'] as &$r) { if ($r['status']==='Failed') $r['status']='Ready'; }
            unset($r);
        }
        $job['finished']=false;
        $bulk->install(); $processed=0;
        foreach($job['rows'] as &$r) {
            if ($r['status']!=='Ready') continue;
            try { $r=$bulk->create($job['group'],$r,$password,$job['days']); }
            catch(CentralAccountError $e) { $r['status']='Failed'; $r['message']=$e->getMessage(); }
            catch(Throwable $e) { error_log('Bulk account creation failed: '.$e->getMessage()); $r['status']='Failed'; $r['message']='Account was not saved. Retry, or review Users and Groups.'; }
            if (++$processed>=4) break;
        } unset($r);
        $job['finished']=!array_filter($job['rows'],fn($r)=>$r['status']==='Ready');
        if ($job['finished'] && !array_filter($job['rows'],fn($r)=>$r['status']==='Failed')) unset($job['password_hash']);
        header('Content-Type: application/json'); echo json_encode(['rows'=>$job['rows'],'finished'=>$job['finished']],JSON_THROW_ON_ERROR); exit;
    } catch(CentralAccountError $e) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['error'=>$e->getMessage()]); exit; }
}
if ($isPost && $action==='preview') {
    try {
        $group=(string)($_POST['group']??'');
        $password=(string)($_POST['password_mode']??'')==='system'?'Acad-'.bin2hex(random_bytes(8)).'-A1':(string)($_POST['password']??'');
        if (($_POST['password_mode']??'')!=='system' && !hash_equals($password,(string)($_POST['confirm_password']??''))) throw new CentralAccountError('The passwords do not match.');
        if ($p=CentralAccounts::passwordProblem($password)) throw new CentralAccountError($p);
        $days=(int)($_POST['days']??7); if ($days<1 || $days>30) throw new CentralAccountError('Choose an expiry of 1–30 days.');
        $ids=(array)($_POST['records']??[]);
        if (isset($_POST['records_json'])) {
            $raw=(string)$_POST['records_json'];
            $ids=strlen($raw)<=200000?json_decode($raw,true):null;
            // PHP 8.0 (including XAMPP 8.0) has no array_is_list().
            if (!is_array($ids) || array_keys($ids)!==array_keys(array_values($ids))) throw new CentralAccountError('Invalid record selection. Select the records again.');
        }
        $rows=$bulk->preview($group,$ids);
        $_SESSION['bulk_accounts']=['actor'=>$actor,'token'=>bin2hex(random_bytes(24)),'expires'=>time()+7200,'group'=>$group,'days'=>$days,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'rows'=>$rows];
        $filters=[];
        foreach (['school_year','year','section','search'] as $key) {
            $value=$_POST['filter_'.$key]??'';
            $filters[$key]=is_string($value)?mb_substr(trim($value),0,191):'';
        }
        $_SESSION['bulk_filters']=['actor'=>$actor,'group'=>$group,'values'=>$filters];
        // One-use display, outside the resumable job. Redirect prevents form replay on refresh.
        $_SESSION['bulk_password_once']=['actor'=>$actor,'token'=>$_SESSION['bulk_accounts']['token'],'expires'=>time()+60,'password'=>$password];
        redirect('bulkaccounts',['group'=>$group]);
    } catch(CentralAccountError $e) { $problem=$e->getMessage(); }
}
$job=$_SESSION['bulk_accounts']??null;
$finished=$job && !empty($job['finished']);
$counts=$job?array_count_values(array_column($job['rows'],'status')):[];
$displayRows=$job?($finished?array_values(array_filter($job['rows'],fn($r)=>!in_array($r['status'],['Created','Skipped'],true))):$job['rows']):[];
$canRetry=($counts['Failed']??0)>0;
$once=$_SESSION['bulk_password_once']??null; unset($_SESSION['bulk_password_once']);
if (!$finished && $job && $once && $once['actor']===$actor && $once['expires']>=time() && hash_equals($job['token'],$once['token'])) $shownPassword=$once['password'];
unset($once);
$group=($_GET['group']??($_POST['group']??'TEACHER'))==='STUDENT'?'STUDENT':'TEACHER';
$filterState=$_SESSION['bulk_filters']??[];
$filterState=($filterState['actor']??null)===$actor && ($filterState['group']??null)===$group?($filterState['values']??[]):[];
$records=RecordList::applied()?($group==='TEACHER'?GS::teachers():GS::students()):[];
if(RecordList::applied())$filterState=['school_year'=>(string)($_GET['sy']??''),'year'=>(string)($_GET['year']??''),'section'=>(string)($_GET['section']??''),'search'=>(string)($_GET['q']??'')];
$sections=[];
if ($group==='STUDENT') foreach (GS::all('sections') as $section) $sections[$section['id']]=$section;
foreach ($records as &$record) {
    $section=$sections[(string)($record['sectionId']??'')]??[];
    $record['_schoolYear']=trim((string)($record['schoolYear']??''))?:trim((string)($section['schoolYear']??''));
    $record['_year']=trim((string)($record['yearLevel']??''))?:trim((string)($section['yearLevel']??''));
    $record['_section']=(string)($record['sectionId']??'');
    if ($record['_section']==='') $record['_section']=!empty($record['sectionName'])?'name:'.$record['sectionName']:'none:';
    $record['_sectionLabel']=$group==='STUDENT'?(trim((string)($section['sectionName']??($record['sectionName']??'')))?:'Unassigned section'):'';
    if (!empty($section['schoolYear'])) $record['_sectionLabel'].=' · '.$section['schoolYear'];
} unset($record);
$records=RecordList::filter($records);
layout_start('Bulk create accounts');
page_title('Bulk create accounts','<a class="btn btn-outline-secondary" href="'.url('users').'">Users and Groups</a>','Select records → Check the preview → Create accounts. Existing accounts keep their passwords and status.');
if($problem): ?><div class="alert alert-danger" role="alert"><?= h($problem) ?></div><?php endif; ?>
<?php if($job): ?>
<section class="form-section mb-4" id="bulkJob" data-group="<?= h($job['group']) ?>" data-finished="<?= $finished?'1':'0' ?>" data-token="<?= h($job['token']) ?>">
<h2 class="h5" id="bulkJobTitle"><?= $finished?'Batch results':'Batch preview' ?> · <?= $job['group']==='TEACHER'?'Teachers':'Students' ?></h2>
<p>Each new account gets the same temporary password and must change it at first sign-in. It expires <?= (int)$job['days'] ?> days after account creation. This batch can resume for two hours in this browser session.</p>
<?php if($shownPassword): ?><div class="alert alert-warning" id="bulkSecretDisplay">Save and distribute this temporary password securely. It is shown here once: <code id="sharedPassword"><?= h($shownPassword) ?></code></div><?php endif; ?>
<p class="small"><?= $job['group']==='TEACHER'?'Teachers can view assigned classes, enter open grades and submit grades. They cannot finalize grades or access accounting.':'Students can view only their own grades, assessments and enrollment, and submit their own admission application.' ?></p>
<div id="bulkPasswordControls"<?= $finished&&!$canRetry?' hidden':'' ?>><label class="form-label" for="batchPassword">Shared temporary password <?= $shownPassword?'':'(re-enter to resume)' ?></label>
<input type="password" id="batchPassword" class="form-control mb-3" style="max-width:28rem" autocomplete="off" value="<?= h($shownPassword) ?>"></div>
<div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-primary" id="bulkStart"<?= $finished?' hidden':'' ?>>Create / resume accounts</button><button type="button" class="btn btn-outline-primary" id="bulkRetry"<?= $finished&&!$canRetry?' hidden':'' ?>>Retry failed accounts</button><button type="button" class="btn btn-outline-secondary" id="bulkPause"<?= $finished?' hidden':'' ?>>Pause after current group</button>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="download"><input type="hidden" name="token" value="<?= h($job['token']) ?>"><button class="btn btn-outline-secondary">Download results (no passwords)</button></form></div>
<p id="bulkProgress" class="mt-3" role="status" aria-live="polite"><?= $finished?h('Completed: '.($counts['Created']??0).' created · '.($counts['Skipped']??0).' skipped.'): "" ?></p><progress<?= $finished?' hidden':'' ?> id="bulkMeter" class="w-100" max="<?= count($job['rows']) ?>" value="0"></progress>
<div class="table-responsive" id="bulkResultsWrap"<?= !$displayRows?' hidden':'' ?>><table class="table"><thead><tr><th>Name / ID</th><th>Year / section</th><th>Username</th><th>Result</th><th>Details</th></tr></thead><tbody id="bulkResults"><?php foreach($displayRows as $r): ?><tr><td><?= h($r['name'].' · '.$r['number']) ?></td><td><?= h(trim(($r['year_level']??'').' '.($r['section_name']??''))) ?></td><td><?= h($r['username']) ?></td><td><?= h($r['status']) ?></td><td><?= h($r['message']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<script type="application/json" id="bulkInitial"><?= json_encode($job['rows'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
</section><?php endif; ?>
<section class="form-section"><h2 class="h5">Start a new batch</h2>
<form method="get" id="bulkFilters" data-record-filters class="mb-3"><input type="hidden" name="r" value="bulkaccounts"><input type="hidden" name="apply" value="1"><div class="record-filter-fields"><div><label class="form-label" for="bulkType">Account type</label><select name="group" id="bulkType" class="form-select"><option value="TEACHER" <?= $group==='TEACHER'?'selected':'' ?>>Teachers</option><option value="STUDENT" <?= $group==='STUDENT'?'selected':'' ?>>Students</option></select></div><div><label class="form-label" for="listBulkSchool">School year (students)</label><select name="sy" id="listBulkSchool" class="form-select"><?= sy_options((string)($_GET['sy']??selected_sy()),true) ?><option value="__unassigned" <?= ($_GET['sy']??'')==='__unassigned'?'selected':'' ?>>Unassigned school year</option></select></div><div><label class="form-label" for="listBulkYear">Year level (students)</label><select name="year" id="listBulkYear" class="form-select"><?= list_options(YEAR_LEVELS,(string)($_GET['year']??''),'All year levels') ?><option value="__unassigned" <?= ($_GET['year']??'')==='__unassigned'?'selected':'' ?>>Unassigned year level</option></select></div><div><label class="form-label" for="listBulkSection">Section (students)</label><select name="section" id="listBulkSection" class="form-select"><option value="">All sections</option><option value="none:" <?= ($_GET['section']??'')==='none:'?'selected':'' ?>>Unassigned section</option><?php foreach(GS::sections() as $sec): ?><option value="<?= h($sec['id']) ?>" <?= ($_GET['section']??'')===$sec['id']?'selected':'' ?>><?= h($sec['sectionName'].' · '.$sec['schoolYear'].' · '.$sec['yearLevel']) ?></option><?php endforeach; ?></select></div><div><label class="form-label" for="listBulkQuery">Search</label><input id="listBulkQuery" class="form-control" name="q" value="<?= h($_GET['q']??'') ?>"></div></div><button class="btn btn-outline-primary">Apply Filters</button></form>
<form method="post" id="bulkSelect" data-loading-label="Checking selected accounts…"><?= csrf_field() ?><input type="hidden" name="action" value="preview"><input type="hidden" name="group" value="<?= h($group) ?>">
<?php if($group==='STUDENT'): ?><div hidden class="row g-3 mb-3"><div class="col-md-4"><label class="form-label" for="bulkSchoolYear">School year</label><select id="bulkSchoolYear" name="filter_school_year" class="form-select" data-default="<?= h(selected_sy()) ?>"><option value="">All school years</option></select></div><div class="col-md-4"><label class="form-label" for="bulkYear">Year level</label><select id="bulkYear" name="filter_year" class="form-select"><option value="">All year levels</option></select></div><div class="col-md-4"><label class="form-label" for="bulkSection">Section</label><select id="bulkSection" name="filter_section" class="form-select"><option value="">All sections</option></select></div></div><button type="button" id="bulkResetFilters" hidden class="btn btn-outline-secondary btn-sm mb-3">Reset filters</button><?php endif; ?>
<script type="application/json" id="bulkFilterInitial"><?= json_encode($filterState,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<div hidden data-bulk-legacy-search><label class="form-label" for="bulkSearch">Find records</label><input id="bulkSearch" name="filter_search" class="form-control mb-2" placeholder="Name, ID, year or section"></div>
<label class="form-check mb-2"><input type="checkbox" id="bulkAll" class="form-check-input"> Select all matching records</label><p id="selectionCount" class="small" role="status" aria-live="polite">0 selected · maximum 1,000</p><p id="filterNotice" class="small text-muted" role="status" aria-live="polite"></p>
<div class="table-responsive mb-3" style="max-height:28rem;overflow:auto"><table class="table"><thead><tr><th>Select</th><th>Name</th><th>ID</th><th>Year / section</th></tr></thead><tbody id="bulkRecords"><?php foreach($records as $r): $name=$r[$group==='TEACHER'?'teacherName':'studentName']??''; ?><tr data-school-year="<?= h($r['_schoolYear']) ?>" data-year="<?= h($r['_year']) ?>" data-section="<?= h($r['_section']) ?>" data-section-label="<?= h($r['_sectionLabel']) ?>"><td><input type="checkbox" name="records[]" value="<?= h($r['id']) ?>" class="form-check-input" aria-label="<?= h('Select '.$name) ?>"></td><td><?= h($name) ?></td><td><?= h($r[$group==='TEACHER'?'teacherId':'studentId']??'') ?></td><td><?= h(trim($r['_year'].' '.$r['_sectionLabel'])) ?></td></tr><?php endforeach; ?></tbody></table><p id="bulkEmpty" class="text-center text-muted p-3" hidden>No records match these filters.</p></div>
<label class="form-label" for="passwordMode">Temporary password for this batch</label><select class="form-select mb-3" name="password_mode" id="passwordMode"><option value="system">Generate one password for everyone selected</option><option value="admin">I will provide one password for everyone selected</option></select>
<div id="adminPassword" hidden><label class="form-label">Password</label><input type="password" name="password" class="form-control mb-2" minlength="8" maxlength="72" autocomplete="new-password"><label class="form-label">Confirm password</label><input type="password" name="confirm_password" class="form-control mb-2" autocomplete="new-password"><p class="form-text">8–72 characters, including letters and numbers.</p></div>
<label class="form-label" for="bulkDays">Temporary password expires after</label><select class="form-select mb-3" name="days" id="bulkDays"><option value="1">1 day</option><option value="3">3 days</option><option value="7" selected>7 days</option><option value="14">14 days</option><option value="30">30 days</option></select>
<p class="small">Teacher usernames use the first initial and full surname, with a number if needed. Student usernames use the Student ID. Previewing creates no accounts. Starting a new preview replaces the current batch preview.</p><button class="btn btn-primary" <?= !$records?'disabled':'' ?>>Preview accounts</button></form></section>
<script src="assets/bulk-accounts.js?v=31"></script>
<?php layout_end();
