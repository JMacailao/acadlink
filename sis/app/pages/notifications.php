<?php
declare(strict_types=1);
Auth::require('admission','registration','payments.view','accounting');
$tab=in_array($_GET['tab'] ?? '',['all','unread','action'],true) ? $_GET['tab'] : 'all';
$events=['ALL'=>'All event types','REQUESTED'=>'Request needs review','APPROVED'=>'Request approved','DECLINED'=>'Request declined','REVOKED'=>'Approval revoked','OTHER'=>'Other updates'];
$event=isset($events[$_GET['event'] ?? '']) ? $_GET['event'] : 'ALL';
$q=trim((string)($_GET['q'] ?? ''));$after=BalanceWorkspace::dateFilter($_GET,'after');$before=BalanceWorkspace::dateFilter($_GET,'before');
if($after!=='' && $before!=='' && $before<$after) throw new AppError('The end date must be on or after the start date.');
$page=max(1,(int)($_GET['page'] ?? 1));$keep=compact('tab','event','q','after','before','page');
$selectedId=max(0,(int)($_GET['notification'] ?? 0));
if($isPost) {
    $action=(string)($_POST['action'] ?? '');
    if($action==='read') {
        $selectedId=(int)In::int($_POST,'notification_id',true,'Notification');
        if(!NotificationCenter::findCurrent($selectedId)) throw new AppError('Notification not found in your inbox.');
        NotificationCenter::markRead($selectedId);flash('success','Notification marked as read. Its request status has not changed.');
    } elseif($action==='read_all') { NotificationCenter::markAllRead();flash('success','Inbox marked as read. No requests were approved or declined.'); }
    elseif($action==='decide') {
        if(!$selectedId || !($n=NotificationCenter::findCurrent($selectedId))) throw new AppError('Open the notification in your inbox before reviewing it.');
        $caseId=(int)In::int($_POST,'case_id',true,'Request');
        if(NotificationCenter::balanceCaseId($n)!==$caseId) throw new Forbidden('This request does not belong to the selected notification.');
        BalanceEnrollment::decide($caseId,(string)In::enum($_POST,'decision',['APPROVED','REJECTED'],true,'Decision'),(string)In::str($_POST,'decision_reason',1000,true,'Decision reason'),(string)In::enum($_POST,'expected_status',['PENDING','APPROVED'],true,'Request status'));
        flash('success','Decision recorded. The notification remains unread until you mark it as read.');
    } else throw new AppError('Unknown notification action.');
    header('Location: '.url('notifications',$keep+($selectedId?['notification'=>$selectedId]:[])).($selectedId?'#notificationDetails':''));exit;
}
$inbox=NotificationCenter::inbox($keep);$keep['page']=$inbox['page'];
$selected=$selectedId?NotificationCenter::findCurrent($selectedId):null;
$unread=NotificationCenter::unreadCount();
$reviewCase=$selected && NotificationCenter::balanceCaseId($selected) ? BalanceWorkspace::case((int)NotificationCenter::balanceCaseId($selected)) : null;
// Enrollment application notifications link to their application (Enrollment Applications).
$enrollmentApp=static fn(array $n): ?array => ($n['entity_type']??'')==='portal_enrollment' && ctype_digit((string)($n['entity_id']??'')) ? Db::one('SELECT * FROM sis_portal_enrollments WHERE id=?',[(int)$n['entity_id']]) : null;
$reviewApp=$selected?$enrollmentApp($selected):null;
layout_start('Notifications');
$markAll='<form method="post" action="'.h(url('notifications',$keep)).'">'.csrf_field().'<input type="hidden" name="action" value="read_all"><button class="btn btn-outline-primary"'.(!$unread?' disabled':'').'>Mark all as read</button></form>';
page_title('Notifications',$markAll,'Updates and requests that need your attention.');
?>
<div class="notification-inbox-summary"><span class="workspace-status is-positive"><?= $unread ?> unread</span><span>Reading a notification does not approve a request.</span></div>
<nav class="workspace-tabs" aria-label="Notification views"><?php foreach(['all'=>'All','unread'=>'Unread','action'=>'Needs my action'] as $key=>$label): ?><a href="<?= h(url('notifications',array_replace($keep,['tab'=>$key,'page'=>1]))) ?>"<?= $tab===$key?' class="active" aria-current="page"':'' ?>><?= $label ?></a><?php endforeach; ?></nav>
<form method="get" class="workspace-filters notification-filters" role="search"><input type="hidden" name="r" value="notifications"><input type="hidden" name="tab" value="<?= h($tab) ?>">
<div class="filter-search"><label class="visually-hidden" for="notificationSearch">Student or request</label><div class="workspace-search"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="notificationSearch" name="q" value="<?= h($q) ?>" placeholder="Student or request"></div></div>
<div><label class="visually-hidden" for="notificationEvent">Event type</label><select class="form-select" id="notificationEvent" name="event"><?php foreach($events as $key=>$label): ?><option value="<?= $key ?>"<?= $event===$key?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div><label for="notificationFrom">From</label><input class="form-control" id="notificationFrom" type="date" name="after" value="<?= h($after) ?>"></div><div><label for="notificationUntil">To</label><input class="form-control" id="notificationUntil" type="date" name="before" value="<?= h($before) ?>"></div>
<button class="btn btn-primary">Apply filters</button><a class="btn btn-outline-secondary" href="<?= h(url('notifications',['tab'=>$tab])) ?>">Reset</a>
</form>
<section class="workspace-card notification-card" aria-label="Notification inbox">
<?php if(!$inbox['rows']): ?><div class="workspace-empty"><i class="bi bi-bell" aria-hidden="true"></i><strong><?= $tab==='action'?'No requests need your review':'No matching notifications' ?></strong><span><?= $tab==='action'?'Only pending requests you have permission to review appear here.':'Try another search, event type or date range.' ?></span></div>
<?php else: foreach($inbox['rows'] as $n):
$pending=$n['case_status']==='PENDING' && BalanceEnrollment::canApprove((string)$n['option_code']);
$label=$pending?'Review request':($n['case_status']!==null?'View decision':'View notification');
$icon=match($n['event']){'REQUESTED'=>'file-earmark-text','APPROVED'=>'check2-circle','DECLINED','REVOKED'=>'exclamation-triangle',default=>'bell'};
$href=url('notifications',array_replace($keep,['notification'=>$n['id']])).'#notificationDetails';
if(($n['entity_type']??'')==='portal_enrollment' && Auth::can('admission') && ($app=$enrollmentApp($n))){$label=$app['status']==='PENDING'?'Review application':'Open application';$actionHref=url('enrollment',['id'=>$app['id']]);}else $actionHref=$href; ?>
<article class="notification-row<?= !$n['is_read']?' is-unread':'' ?><?= $selectedId===(int)$n['id']?' is-selected':'' ?>">
<span class="notification-dot<?= $n['is_read']?' is-read':'' ?>" aria-label="<?= $n['is_read']?'Read':'Unread' ?>"></span><span class="notification-event-icon"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></span>
<div class="notification-copy"><h2><a data-workspace-link href="<?= h($href) ?>"><?= h($n['title']) ?></a></h2>
<?php if($n['student_number']): ?><p><?= h($n['student_name'].' · '.$n['student_number']) ?></p><p class="notification-meta"><?= $n['event']==='REQUESTED'?'Balance at request: ₱'.Money::fmt(Money::cents($n['balance_amount'])).' · Initial payment: ₱'.Money::fmt(Money::cents($n['requested_payment'])):h($n['message']) ?></p><span class="notification-meta">Target: <?= h($n['target_term'].' · '.$n['target_school_year']) ?> · Current request: <?= h(BalanceWorkspace::REQUEST_STATES[$n['case_status']] ?? $n['case_status']) ?></span>
<?php else: ?><p class="notification-meta"><?= h($n['message']) ?></p><?php endif; ?></div>
<time datetime="<?= h(date(DATE_ATOM,strtotime($n['created_at']))) ?>" title="<?= h($n['created_at']) ?>"><?= h(BalanceWorkspace::relativeTime($n['created_at'])) ?></time><a class="btn btn-sm btn-outline-primary notification-action" data-workspace-link href="<?= h($actionHref) ?>"><?= h($label) ?></a>
</article>
<?php endforeach; endif; ?>
<div class="workspace-pagination"><span><?= $inbox['total'] ?> matching notification<?= $inbox['total']===1?'':'s' ?></span><nav aria-label="Notification pages"><?php if($inbox['page']>1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url('notifications',array_replace($keep,['page'=>$inbox['page']-1]))) ?>">Previous</a><?php endif; ?><span>Page <?= $inbox['page'] ?> of <?= $inbox['pages'] ?></span><?php if($inbox['page']<$inbox['pages']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url('notifications',array_replace($keep,['page'=>$inbox['page']+1]))) ?>">Next</a><?php endif; ?></nav></div>
</section>
<?php if($selectedId && !$selected): ?><div class="alert alert-warning mt-3">Notification not found in your inbox.</div><?php endif; ?>
<?php if($selected): ?>
<section class="workspace-card mt-3" id="notificationDetails" tabindex="-1" aria-labelledby="notificationDetailsTitle"><div class="workspace-card-head"><div><h2 id="notificationDetailsTitle">Request details</h2><p><?= h($selected['title']) ?></p></div><a class="btn btn-sm btn-outline-secondary" data-workspace-return href="<?= h(url('notifications',$keep)) ?>">Close details</a></div>
<div class="workspace-detail-body"><div class="notification-detail-summary"><div><p><?= nl2br(h($selected['message'])) ?></p><span class="small text-muted">Received <?= h($selected['created_at']) ?><?= $selected['read_at']?' · Read '.$selected['read_at']:'' ?></span></div><div><span class="d-block small text-muted mb-1">Current request status</span><?= $reviewCase?BalanceWorkspace::badge($reviewCase['status']):($reviewApp?'<span class="workspace-status is-neutral">'.h(PortalEnrollment::progress($reviewApp)[0]).'</span>':'<span class="workspace-status is-neutral">No linked request</span>') ?></div></div>
<div class="request-actions">
<?php if (($selected['entity_type']??'')==='portal_enrollment' && Auth::can('admission')): ?><a class="btn btn-primary" href="<?= h(url('enrollment',['id'=>$selected['entity_id']])) ?>">Review enrollment application</a><?php endif; ?>
<?php if($reviewCase): ?><a class="btn btn-primary" href="<?= h(url('balance',['student'=>$reviewCase['student_number'],'sy'=>$reviewCase['target_school_year'],'term'=>$reviewCase['target_term']])).'#balanceDetails' ?>">Open request</a><?php if(Auth::can('registration')): ?><a class="btn btn-outline-primary" href="<?= h(url('eligibility',['student'=>$reviewCase['student_number'],'sy'=>$reviewCase['target_school_year'],'term'=>$reviewCase['target_term']])) ?>">Check eligibility</a><?php endif; ?><?php endif; ?>
<?php if(!$selected['is_read']): ?><form method="post" action="<?= h(url('notifications',$keep+['notification'=>$selectedId])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="notification_id" value="<?= $selectedId ?>"><button class="btn btn-outline-primary">Mark as read</button></form><?php else: ?><span class="workspace-status is-neutral">Read</span><?php endif; ?></div>
<p class="workspace-footnote">Marking as read does not approve, decline or resolve a request. Decisions below use the current request status.</p>
<?php if($reviewCase): ?><div class="notification-request-context"><dl><div><dt>Student</dt><dd><?= h($reviewCase['student_name'].' · '.$reviewCase['student_number']) ?></dd></div><div><dt>Target enrollment</dt><dd><?= h($reviewCase['target_term'].' · '.$reviewCase['target_school_year']) ?></dd></div></dl><p><strong>Request reason:</strong> <?= h($reviewCase['request_reason']) ?></p><?php if($reviewCase['decision_reason']): ?><p><strong>Latest decision:</strong> <?= h($reviewCase['decision_reason']) ?></p><?php endif; ?><p class="small text-muted mb-0">Open the request to review original balances, payment conditions and decision history.</p></div><?php endif; ?>
</div></section>
<?php endif; ?>
<?php layout_end();
