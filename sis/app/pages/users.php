<?php
/** The only account administration surface. Always enforce Power User on the server. */
declare(strict_types=1);
require_once SIS_ROOT.'/app/views/access_grid.php';
$c=Auth::central(); $actor=(int)Auth::centralId();
try { $c->requirePower($actor); } catch(CentralAccountError $ex) { throw new Forbidden($ex->getMessage()); }
$problem='';
if ($isPost) {
    try {
        if (($_POST['action']??'')==='reset') {
            $c->setPassword((int)($_POST['id']??0),(string)($_POST['temp_password']??''),true,$actor);
            flash('success','Temporary password saved. Give it to the user securely. They must change it at sign-in.');
            redirect('users');
        } elseif (($_POST['action']??'')==='group') {
            $c->setGroupActive($actor,(string)($_POST['group']??''),isset($_POST['enabled']));
            flash('success','Group access updated. Previous sessions must sign in again.');
            redirect('users',['tab'=>'groups']);
        } elseif (($_POST['action']??'')==='group_new') {
            $code=$c->createGroup($actor,(string)($_POST['name']??''),(string)($_POST['description']??''));
            flash('success','Group added. Set its access per system and module, and tick "signs student clearance" if it is a clearance office.');
            header('Location: '.url('users',['tab'=>'groups']).'#g-'.$code); exit;
        } elseif (($_POST['action']??'')==='group_edit') {
            $c->updateGroup($actor,(string)($_POST['group']??''),(string)($_POST['name']??''),(string)($_POST['description']??''));
            flash('success','Group renamed.');
            redirect('users',['tab'=>'groups']);
        } elseif (($_POST['action']??'')==='group_delete') {
            $c->deleteGroup($actor,(string)($_POST['group']??''));
            flash('success','Group deleted.');
            redirect('users',['tab'=>'groups']);
        } elseif (($_POST['action']??'')==='group_clearance') {
            Clearance::setGroup($actor,(string)($_POST['group']??''),!empty($_POST['signs_clearance']),(int)($_POST['clearance_order']??50));
            flash('success','Clearance settings of the group saved.');
            header('Location: '.url('users',['tab'=>'groups']).'#g-'.rawurlencode((string)($_POST['group']??''))); exit;
        } elseif (($_POST['action']??'')==='group_access') {
            $code=(string)($_POST['group']??'');
            $c->setGroupModules($actor,$code,access_posted($_POST));
            flash('success','Access for this group saved. It applies from each member\'s next click.');
            redirect('users',['tab'=>'groups']);
        } elseif (($_POST['action']??'')==='user_access') {
            $id=(int)($_POST['id']??0);
            if (isset($_POST['reset_all'])) { $c->resetUserModules($actor,$id); flash('success','This person now has exactly their group\'s access.'); }
            else { $c->setUserModules($actor,$id,access_posted($_POST)); flash('success','Access for this person saved. Modules marked CUSTOM differ from their group.'); }
            header('Location: '.url('users',['edit'=>$id]).'#access'); exit; // reopens the access dialog
        } elseif (($_POST['action']??'')==='save') {
            $id=Db::tx(function() use($c,$actor) {
                $in=$_POST;
                $c->q('SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE');
                if (!empty($in['new_teacher'])) {
                    if (($in['group']??'')!=='TEACHER') throw new AppError('Choose Teacher before creating a teacher record.');
                    $in['teacher_doc_id']=GS::saveTeacher(null,(string)($in['teacherId']??''),(string)($in['full_name']??''));
                }
                $saved=$c->save($actor,$in);
                AccountEmail::set($saved,(string)($in['email']??''),$actor); // required for Power Users (Forgot password)
                if (!empty($in['clearance_sign_shown'])) Clearance::setAccountTick($actor,$saved,!empty($in['clearance_sign'])); // v55
                return $saved;
            });
            flash('success','Account saved. Accounts and passwords are now managed here for both systems.');
            // Updating yourself changes the auth version; re-establish after the verified administrative action.
            if ($id===$actor) $c->establish($actor);
            redirect('users');
        } else throw new AppError('Unknown account action.');
    } catch(CentralAccountError|AppError $ex) { $c->clearCache(); $problem=$ex->getMessage(); }
      catch(PDOException $ex) { $c->clearCache(); if($ex->getCode()!=='23000') throw $ex; $problem='This username, teacher, student or legacy account is already linked. Review the existing account.'; }
}
$tab=($_GET['tab']??'')==='groups'?'groups':'accounts';
$editId=(int)($_GET['edit']??($_POST['id']??0));
$e=$editId?$c->account($editId):null;
$values=$isPost && ($_POST['action']??'')==='save'?$_POST:($e?['id'=>$e['id'],'full_name'=>$e['display_name'],'username'=>$e['username'],'group'=>$c->group((int)$e['id']),'teacher_doc_id'=>$e['teacher_doc_id'],'student_number'=>$e['student_number'],'status'=>$e['status']]:[]);
$v=fn(string $k,string $default='')=>h((string)($values[$k]??$default));
$groups=Db::all('SELECT * FROM central_groups ORDER BY id');
$members=array_column(Db::all('SELECT group_id,COUNT(*) n FROM central_user_groups WHERE revoked_at IS NULL GROUP BY group_id'),'n','group_id');
$users=RecordList::rows(fn()=>Db::all("SELECT a.*,i.identifier AS username,g.name AS group_name,g.code AS group_code FROM central_accounts a LEFT JOIN central_login_identifiers i ON i.account_id=a.id AND i.is_primary=1 LEFT JOIN central_user_groups ug ON ug.account_id=a.id AND ug.revoked_at IS NULL LEFT JOIN central_groups g ON g.id=ug.group_id ORDER BY a.display_name,a.id"));
$legacySis=Db::all("SELECT u.id,u.username,u.full_name,u.role FROM sis_users u LEFT JOIN central_legacy_links l ON l.source_system='SIS' AND l.source_key=CAST(u.id AS CHAR) WHERE l.account_id IS NULL ORDER BY u.username");
$legacyGrades=Db::all("SELECT d.id,JSON_UNQUOTE(JSON_EXTRACT(d.data,'$.username')) AS username,JSON_UNQUOTE(JSON_EXTRACT(d.data,'$.displayName')) AS full_name,JSON_UNQUOTE(JSON_EXTRACT(d.data,'$.role')) AS role FROM docs d LEFT JOIN central_legacy_links l ON l.source_system='GRADING' AND l.source_key=d.id WHERE d.col='users' AND l.account_id IS NULL ORDER BY username");
layout_start('Users and Groups');
page_title('Users and Groups','<a class="btn btn-outline-primary me-2" href="'.url('bulkaccounts').'">Bulk create accounts</a> <a class="btn btn-primary" href="'.($tab==='groups'?url('users'):'').'#addUser">Add account</a>','One account for SIS and Grading. Only Power Users manage accounts.');
if($problem): ?><div class="alert alert-danger" role="alert"><?= h($problem) ?></div><?php endif; ?>
<nav class="nav nav-pills gap-2 mb-3"><a class="nav-link <?= $tab==='accounts'?'active':'' ?>" href="<?= url('users') ?>">Accounts</a><a class="nav-link <?= $tab==='groups'?'active':'' ?>" href="<?= url('users',['tab'=>'groups']) ?>">Groups and access</a></nav>
<?php if($tab==='groups'): ?>
<details class="form-section mb-3" id="addGroup"<?= $problem && ($_POST['action']??'')==='group_new' ? ' open' : '' ?>><summary class="fw-semibold"><i class="bi bi-plus-circle me-1"></i>Add a group</summary>
<form method="post" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="group_new">
  <div class="col-md-4"><label class="form-label small" for="ngName">Name</label><input class="form-control" id="ngName" name="name" required minlength="2" maxlength="100" placeholder="e.g. Library, Guidance, Dean, Clinic" value="<?= ($_POST['action']??'')==='group_new' ? h((string)($_POST['name']??'')) : '' ?>"></div>
  <div class="col-md-6"><label class="form-label small" for="ngDesc">Description</label><input class="form-control" id="ngDesc" name="description" maxlength="500" placeholder="What this group does" value="<?= ($_POST['action']??'')==='group_new' ? h((string)($_POST['description']??'')) : '' ?>"></div>
  <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Add group</button></div>
  <p class="form-text mb-0">A new group starts with no access (only the Learning Management System). Set its access below. It can be renamed or turned off; it can be deleted only while no account uses it.</p>
</form></details>
<div class="row g-3"><?php foreach($groups as $g): $grants=Db::all('SELECT gp.*,p.label,p.workspace FROM central_group_permissions gp JOIN central_permissions p ON p.code=gp.permission_code WHERE gp.group_id=? ORDER BY p.workspace,p.label',[$g['id']]); ?>
<section class="col-lg-6" id="g-<?= h($g['code']) ?>"><div class="form-section h-100"><h2 class="h5"><?= h($g['name']) ?><?= !(int)$g['is_default'] ? ' <span class="badge text-bg-light border">added</span>' : '' ?><?= !(int)$g['is_active'] ? ' <span class="badge text-bg-secondary">off</span>' : '' ?> <span class="small text-muted fw-normal"><?= (int)($members[$g['id']]??0) ?> account(s)</span></h2><p><?= h($g['description']) ?></p>
<?php if(!(int)$g['is_default']): ?><details class="mb-2"><summary class="small">Rename or delete</summary>
<form method="post" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="group_edit"><input type="hidden" name="group" value="<?= h($g['code']) ?>">
  <div class="col-sm-5"><input class="form-control form-control-sm" name="name" required minlength="2" maxlength="100" value="<?= h($g['name']) ?>" aria-label="Group name"></div>
  <div class="col-sm-5"><input class="form-control form-control-sm" name="description" maxlength="500" value="<?= h($g['description']) ?>" aria-label="Description"></div>
  <div class="col-sm-2"><button class="btn btn-sm btn-outline-primary w-100">Save</button></div></form>
<form method="post" class="mt-2" data-confirm="Delete the group <?= h($g['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="group_delete"><input type="hidden" name="group" value="<?= h($g['code']) ?>"><button class="btn btn-sm btn-outline-danger"<?= (int)($members[$g['id']]??0) ? ' disabled title="Accounts use this group: move them first, or turn the group off"' : '' ?>>Delete group</button></form>
</details><?php endif; ?>
<?php if(!in_array($g['code'],['POWER_USER','STUDENT'],true)): ?><form method="post" class="clr-group border rounded p-2 mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="group_clearance"><input type="hidden" name="group" value="<?= h($g['code']) ?>">
  <div class="d-flex flex-wrap align-items-center gap-3"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="signs_clearance" value="1"<?= (int)$g['signs_clearance'] ? ' checked' : '' ?>> <strong>This group signs student clearance</strong></label>
  <label class="small d-flex align-items-center gap-1 mb-0">Order on the slip <input class="form-control form-control-sm" style="width:5.5em" type="number" name="clearance_order" min="1" max="999" value="<?= (int)$g['clearance_order'] ?>"></label>
  <button class="btn btn-sm btn-outline-primary ms-auto">Save</button></div>
  <p class="form-text mb-0"><?= $g['code']==='TEACHER' ? 'Each subject\'s teacher signs for their own students.' : 'Signs once per student as an office.' ?> Lower numbers come first; the registrar's final approval is always last.<?= Clearance::on() ? '' : ' Online clearance is off (Settings).' ?></p>
</form><?php endif; ?>
<details><summary>View permissions (<?= count($grants) ?>)</summary><ul class="small mt-2"><?php foreach($grants as $p): ?><li><?= h($p['label'].' · '.$p['access_scope']) ?></li><?php endforeach; ?></ul></details>
<?php if($g['code']!=='POWER_USER'): $gp=array_column($grants,'access_scope','permission_code'); ?>
<details class="mt-3"><summary class="fw-semibold">Edit access per system and module</summary>
<form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="group_access"><input type="hidden" name="group" value="<?= h($g['code']) ?>">
<?= access_grid(access_systems($g['code']),$gp) ?>
<p class="small text-muted mb-2">A system appears after sign-in only when at least one of its modules is allowed. Extra actions apply with "View and edit".</p>
<button class="btn btn-primary btn-sm">Save group access</button></form></details>
<?php else: ?><p class="small text-muted mt-3 mb-0">Power Users always have full access to every system and module.</p><?php endif; ?>
<?php if($g['code']!=='POWER_USER'): ?><form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="group"><input type="hidden" name="group" value="<?= h($g['code']) ?>"><label class="form-check"><input class="form-check-input" type="checkbox" name="enabled" <?= $g['is_active']?'checked':'' ?>> Group enabled</label><button class="btn btn-sm btn-outline-primary mt-2">Save group access</button></form><?php else: ?><p class="small text-muted mt-3">Power User access stays enabled. Default permission boundaries are fixed.</p><?php endif; ?>
</div></section><?php endforeach; ?></div>
<?php else: ?>
<div class="row g-3"><div class="col-xl-8"><label for="accountSearch" class="form-label">Find an account</label><input id="accountSearch" class="form-control mb-3" data-filter="#centralUsers" placeholder="Name, username or group">
<div class="table-responsive border rounded bg-white"><table class="table align-middle mb-0" id="centralUsers"><thead><tr><th>Name / username</th><th>Group</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($users as $u): ?><tr><td><strong><?= h($u['display_name']) ?></strong><br><span class="text-muted small"><?= h($u['username']) ?></span></td><td><?= h($u['group_name']) ?></td><td><?= badge($u['status']) ?><?= $u['must_change_password']?' <span class="small">Password change required</span>':'' ?></td><td><a class="btn btn-sm btn-outline-secondary" href="<?= url('users',['edit'=>$u['id']]) ?>">Manage</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<details class="form-section mt-3"><summary>Migration review · <?= count($legacySis)+count($legacyGrades) ?> unlinked legacy accounts</summary><p class="small mt-2">Verify identity using records and account IDs. Select both legacy accounts in the account form before saving. Names alone are not proof. Cashier → Accounting and Admission staff → Registrar grant broader access and need explicit review.</p>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Source / ID</th><th>Username</th><th>Name</th><th>Old role</th></tr></thead><tbody><?php foreach(['SIS'=>RecordList::applied()?$legacySis:[],'GRADING'=>RecordList::applied()?$legacyGrades:[]] as $system=>$rows): foreach($rows as $l): ?><tr><td><?= h($system.' / '.$l['id']) ?></td><td><?= h($l['username']) ?></td><td><?= h($l['full_name']) ?></td><td><?= h($l['role']) ?></td></tr><?php endforeach; endforeach; ?></tbody></table></div></details>
</div><div class="col-xl-4"><form method="post" class="form-section" id="addUser"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $v('id') ?>"><h2 class="h5"><?= $e?'Manage account':'Add account' ?></h2>
<label class="form-label">Full name</label><input class="form-control mb-3" name="full_name" required maxlength="150" value="<?= $v('full_name') ?>" autocomplete="name">
<?php $lockedName = $e && strtolower((string) $e['username']) === 'power.admin'; ?><label class="form-label">Username</label><input class="form-control mb-<?= $lockedName ? '1' : '3' ?>" name="username" required pattern="[a-zA-Z0-9._\-]{3,64}" maxlength="64" value="<?= $v('username') ?>" autocomplete="off"<?= $lockedName ? ' readonly' : '' ?>><?php if ($lockedName): ?><p class="small text-muted mb-3">power.admin's username can't be changed (it is the account that backs up and restores). Its password can be changed.</p><?php endif; ?>
<label class="form-label" for="acctEmail">Email <span class="small text-muted fw-normal">(for Forgot password; required for Power Users)</span></label><input class="form-control mb-3" type="email" id="acctEmail" name="email" maxlength="191" value="<?= h((string)($values['email']??($e?AccountEmail::get((int)$e['id']):''))) ?>" autocomplete="off" placeholder="name@gmail.com">
<label class="form-label">Default group</label><select class="form-select mb-3" name="group" id="centralGroup"><?php foreach($groups as $g): ?><option value="<?= h($g['code']) ?>" <?= ($values['group']??'TEACHER')===$g['code']?'selected':'' ?> <?= !$g['is_active']?'disabled':'' ?> data-desc="<?= h($g['name'].': '.$g['description']) ?>" data-signs="<?= (int)$g['signs_clearance'] && !in_array($g['code'],['POWER_USER','STUDENT'],true) ? 1 : 0 ?>"><?= h($g['name']) ?></option><?php endforeach; ?></select>
<?php $clrTick=$isPost && ($_POST['action']??'')==='save' ? !empty($_POST['clearance_sign']) : ($e ? Clearance::accountTick((int)$e['id']) : true); ?>
<div class="mb-3" id="clearanceSignBox"><input type="hidden" name="clearance_sign_shown" value="1"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="clearance_sign" value="1" id="clearanceSign"<?= $clrTick ? ' checked' : '' ?>> Can e-sign clearance</label><div class="form-text" id="clearanceSignHint">Untick to let this person see the clearance list without signing (e.g. a student assistant).</div></div>
<div class="alert alert-info small" id="accessPreview" aria-live="polite"></div>
<div id="teacherAccountFields"><label class="form-label">Linked teacher record</label><select class="form-select mb-2" name="teacher_doc_id"><option value="">Choose a teacher</option><?php foreach(GS::teachers() as $t): if($t['id']==='_acadlink_professor_x') continue; ?><option value="<?= h($t['id']) ?>" <?= ($values['teacher_doc_id']??'')===$t['id']?'selected':'' ?>><?= h(($t['teacherId']??'').' · '.$t['teacherName']) ?></option><?php endforeach; ?></select>
<?php if(!$e): ?><label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="new_teacher" value="1" <?= !empty($values['new_teacher'])?'checked':'' ?>> Create teacher record and account together</label><label class="form-label small">New teacher ID (blank = next number)</label><input class="form-control mb-2" name="teacherId" maxlength="40" value="<?= $v('teacherId') ?>"><?php endif; ?><p class="form-text">Class assignments stay in Grading.</p></div>
<div id="studentAccountFields"><label class="form-label">Linked Student ID</label><input class="form-control mb-2" name="student_number" maxlength="40" value="<?= $v('student_number') ?>"><p class="form-text">Leave blank for an applicant. Grades and assessments appear after a verified student record is linked.</p></div>
<?php if(!$e): ?><label class="form-label">Temporary password</label><input class="form-control mb-2" type="password" name="temp_password" required minlength="8" maxlength="72" autocomplete="new-password"><p class="form-text">8–72 characters with letters and numbers. The user must change it at first sign-in. Give it to them securely.</p><?php endif; ?>
<label class="form-label">Account status</label><select class="form-select mb-3" name="status"><option value="ACTIVE" <?= ($values['status']??'ACTIVE')==='ACTIVE'?'selected':'' ?>>Active</option><option value="DISABLED" <?= ($values['status']??'')!=='ACTIVE' && isset($values['status'])?'selected':'' ?>>Disabled</option></select>
<details class="mb-3"><summary>Verified legacy account links</summary><p class="small mt-2">For existing users, select their original accounts to preserve IDs. Existing links cannot be replaced here.</p>
<?php foreach(['SIS'=>['source_sis',$legacySis],'GRADING'=>['source_grading',$legacyGrades]] as $system=>[$key,$rows]): $linked=$e?$c->legacyId((int)$e['id'],$system):null; ?><label class="form-label"><?= h($system) ?> account</label><?php if($linked): ?><p class="small">Linked: <?= h($linked) ?></p><?php else: ?><select class="form-select mb-2" name="<?= $key ?>"><option value="">No existing account</option><?php foreach($rows as $l): ?><option value="<?= h((string)$l['id']) ?>" <?= ($values[$key]??'')===(string)$l['id']?'selected':'' ?>><?= h($l['username'].' · '.$l['full_name'].' · '.$l['role'].' · ID '.$l['id']) ?></option><?php endforeach; ?></select><?php endif; endforeach; ?>
<label class="form-label">Identity verification / review note</label><textarea class="form-control" name="verification_note" maxlength="1000"><?= $v('verification_note') ?></textarea></details>
<button class="btn btn-primary">Save account</button><?php if($e): ?> <a class="btn btn-outline-primary" href="#access">Access</a> <?php if(ESign::canHave((int)$e['id'])): ?><a class="btn btn-outline-primary" href="<?= url('profile',['account'=>$e['id']]) ?>"><i class="bi bi-pen"></i> E-signature</a> <?php endif; ?><a class="btn btn-outline-warning" href="#pw">Reset password</a> <a class="btn btn-link" href="<?= url('users') ?>">Cancel</a><?php endif; ?></form>
<?php if($e): ?><form method="post" class="form-section mt-3" id="pw"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><h2 class="h6">Reset password</h2><label class="form-label">New temporary password</label><input class="form-control mb-2" type="password" name="temp_password" required minlength="8" maxlength="72" autocomplete="new-password"><p class="small text-muted">Signs this user out of both systems. They must change this password at sign-in.</p><button class="btn btn-outline-warning">Reset password</button></form><?php endif; ?>
<?php if($e): $eg=$c->group((int)$e['id']); ?><form method="post" class="form-section mt-3" id="access"><h2 class="h6">Access for <?= h($e['display_name']) ?></h2>
<?php if($eg==='POWER_USER'): ?><p class="small mb-0">Power Users always have full access. To limit this person, move them to another group.</p>
<?php else: $gRow=Db::one('SELECT id FROM central_groups WHERE code=?',[$eg]); $gPerms=$gRow?array_column(Db::all('SELECT permission_code,access_scope FROM central_group_permissions WHERE group_id=? AND is_enabled=1',[$gRow['id']]),'access_scope','permission_code'):[]; $over=$c->moduleOverrides((int)$e['id']); $eff=$c->permissions((int)$e['id']); $open=ModuleAccess::systems($eff); ?>
<p class="small">Follows the <strong><?= h($eg) ?></strong> group unless a module is changed here (marked CUSTOM). After sign-in this person sees: <strong><?= $open?h(implode(', ',array_map(fn($s)=>ModuleAccess::SYSTEMS[$s],$open))):'no system (they will be told they have no access)' ?></strong>.</p>
<?= csrf_field() ?><input type="hidden" name="action" value="user_access"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
<?= access_grid(access_systems((string)$e['account_kind']),$eff,$gPerms,$over) ?>
<button class="btn btn-primary btn-sm">Save access</button><?php if($over): ?> <button class="btn btn-outline-secondary btn-sm" name="reset_all" value="1" data-confirm="Remove all custom access and use the group's access?">Reset all to group</button><?php endif; ?>
<?php endif; ?></form><?php endif; ?></div></div>
<?php endif; ?>
<script src="assets/central-accounts.js?v=55"></script>
<?php layout_end();
