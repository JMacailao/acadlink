<?php
/** Teachers = the grading system's teachers. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$canEdit = Auth::can('teachers.edit');
$canManageAccounts=Auth::can('users.manage') && Auth::central()->group((int)Auth::centralId())==='POWER_USER';
if ($isPost) {
    if (($_POST['action']??'')==='create_account') {
        if (!$canManageAccounts) throw new Forbidden('Only a Power User can create teacher accounts.');
        try {
            $created=Auth::central()->createTeacherAccount((int)Auth::centralId(),(string)($_POST['teacher_doc_id']??''));
        } catch(CentralAccountError $ex) { throw new AppError($ex->getMessage()); }
        $_SESSION['teacher_account_created']=$created+['actor_id'=>(int)Auth::centralId(),'expires_at'=>time()+300];
        redirect('teachers');
    }
    GS::saveTeacher(In::str($_POST, 'id', 191), (string) ($_POST['teacherId'] ?? ''), (string) ($_POST['teacherName'] ?? ''));
    flash('success', 'Teacher saved in the grading system.');
    redirect('teachers');
}
$teachers = RecordList::rows(fn()=>GS::teachers());
$accounts=array_column(Db::all('SELECT p.teacher_doc_id,a.id,a.status,i.identifier AS username FROM central_person_links p JOIN central_accounts a ON a.id=p.account_id LEFT JOIN central_login_identifiers i ON i.account_id=a.id AND i.is_primary=1 WHERE p.teacher_doc_id IS NOT NULL'),null,'teacher_doc_id');
$credentials=$_SESSION['teacher_account_created']??null;
unset($_SESSION['teacher_account_created']);
if (!$canManageAccounts || !$credentials || (int)$credentials['actor_id']!==(int)Auth::centralId() || (int)$credentials['expires_at']<time()) $credentials=null;
$classes = [];
foreach (Db::all("SELECT f_teacherDocId AS t, COUNT(*) AS n FROM docs WHERE col = 'gradingAssignments' GROUP BY f_teacherDocId") as $r) $classes[$r['t']] = (int) $r['n'];
$edit = isset($_GET['edit']) ? GS::get('teachers', (string) $_GET['edit']) : null;
layout_start('Teachers');
page_title('Teachers', $canEdit ? '<a class="btn btn-primary" href="#addTeacher"><i class="bi bi-plus-lg me-1"></i>Add teacher</a>' : '', "Manage your teacher directory and view assigned classes.");
?>
<?php if($credentials): ?>
<section class="form-section border-success mb-3" id="teacherAccountCredentials" role="status" aria-labelledby="teacherCredentialTitle">
<h2 class="h5" id="teacherCredentialTitle">Teacher account created</h2>
<p><strong><?= h($credentials['teacher_name']) ?></strong> now has a linked Teacher account for assigned classes.</p>
<dl class="row mb-2"><dt class="col-sm-3">Username</dt><dd class="col-sm-9"><code><?= h($credentials['username']) ?></code></dd><dt class="col-sm-3">Temporary password</dt><dd class="col-sm-9"><code><?= h($credentials['temporary_password']) ?></code></dd></dl>
<p class="small mb-2">Copy these details now and give them to the teacher securely. The temporary password is shown once. The teacher must change it at first sign-in. Assign classes in Grading.</p>
<a class="btn btn-sm btn-outline-primary" href="<?= url('users',['edit'=>$credentials['account_id']]) ?>">Manage account</a>
</section>
<?php endif; ?>
<div class="row g-3">
<div class="<?= $canEdit ? 'col-xl-8' : 'col-12' ?>">
  <input class="form-control form-control-sm mb-2" data-filter="#teachers" placeholder="Search">
  <div class="table-responsive bg-white border rounded"><table class="table table-sm table-hover mb-0" id="teachers">
  <thead class="table-light"><tr><th>Teacher ID</th><th>Name</th><th>Classes</th><th>Account</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($teachers as $t): ?><tr><td class="font-monospace"><?= h($t['teacherId'] ?? '') ?></td><td><?= h($t['teacherName'] ?? '') ?></td><td><?= $classes[$t['id']] ?? 0 ?></td>
    <td><?php $linked=$accounts[$t['id']]??null; if($linked): ?>
      <span class="badge <?= $linked['status']==='ACTIVE'?'text-bg-success':'text-bg-secondary' ?>"><?= $linked['status']==='ACTIVE'?'Linked':'Disabled / linked' ?></span>
      <?php if($canManageAccounts): ?><div class="small"><?= h($linked['username']) ?></div><?php endif; ?>
    <?php else: ?><span class="text-muted small">No account</span><?php endif; ?></td>
    <td class="text-end"><?php if($t['id']==='_acadlink_professor_x'): ?><span class="small text-muted">Protected placeholder</span><?php else: ?><details class="teacher-action-menu"><summary class="btn btn-sm btn-outline-secondary" aria-label="Actions for <?= h($t['teacherName']) ?>"><span aria-hidden="true">⋯</span><span class="visually-hidden">Actions</span></summary><div class="teacher-action-items">
      <?php if($canEdit && $t['id']!=='_acadlink_professor_x'): ?><a class="btn btn-sm btn-light text-start" href="<?= url('teachers',['edit'=>$t['id']]) ?>">Edit teacher</a><?php endif; ?>
      <?php if($canManageAccounts && !$linked && $t['id']!=='_acadlink_professor_x'):
        $suggestedUsername=null; $usernameError=null;
        try { $suggestedUsername=CentralAccounts::teacherUsernameBase((string)($t['teacherName']??'')); }
        catch(CentralAccountError $ex) { $usernameError=$ex->getMessage(); }
      ?>
        <?php if($suggestedUsername!==null): ?>
        <div class="small text-muted px-2 py-1 text-start">Suggested username: <code><?= h($suggestedUsername) ?></code>. A number is added if taken. Verify the surname before creating.</div>
        <form method="post" action="<?= url('teachers') ?>" data-confirm="<?= h('Create a linked Teacher account for '.($t['teacherName']??'').' using username '.$suggestedUsername.' (or with a number if taken)? Confirm that the surname is correct. For a different username, cancel and use SIS Users and Groups.') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="create_account"><input type="hidden" name="teacher_doc_id" value="<?= h($t['id']) ?>"><button class="btn btn-sm btn-light text-start w-100">Create account</button></form>
        <?php else: ?><div class="small text-muted px-2 py-1 text-start"><?= h($usernameError) ?></div><?php endif; ?>
        <a class="btn btn-sm btn-light text-start" href="<?= url('users') ?>">Users and Groups</a>
      <?php elseif($canManageAccounts && $linked): ?><a class="btn btn-sm btn-light text-start" href="<?= url('users',['edit'=>$linked['id']]) ?>">Manage account</a><?php endif; ?>
    </div></details><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$teachers): ?><tr><td colspan="5" class="text-center text-muted py-4">No teachers.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php if ($canEdit): ?>
<div class="col-xl-4"><form method="post" class="form-section" id="addTeacher"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
  <h2 class="h6"><?= $edit ? 'Edit ' . h($edit['teacherName']) : 'Add a teacher' ?></h2>
  <label class="form-label small">Teacher ID (blank = next number)</label><input class="form-control form-control-sm mb-2" name="teacherId" maxlength="40" value="<?= h($edit['teacherId'] ?? '') ?>">
  <label class="form-label small">Name</label><input class="form-control form-control-sm mb-2" name="teacherName" required maxlength="150" value="<?= h($edit['teacherName'] ?? '') ?>">
  <button class="btn btn-sm btn-primary">Save teacher</button><?php if ($edit): ?> <a class="btn btn-sm btn-link" href="<?= url('teachers') ?>">Add instead</a><?php endif; ?>
  <div class="form-text">Enter First name → optional Middle name or initial → Last name → optional suffix or degree. Example: ALLAN L. BALBACAL, RRT → abalbacal; Juan M. Dela Cruz, LPT → jdelacruz. Recognized suffixes and degrees are ignored in usernames. Use the row’s ⋯ menu → Create account to review the suggested username and create a linked Teacher sign-in (Power User only). For an unusual surname or a different username, use SIS Users and Groups. Class assignments remain in Grading.</div>
</form></div>
<?php endif; ?>
</div>
<?php layout_end();
