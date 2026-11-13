<?php
declare(strict_types=1);

/** UI capabilities only. AccountingWorkflow rechecks permissions and state on every POST. */
function accounting_entry_attributes(array $j): string {
    $original=empty($j['reverses_id']) && empty($j['reversed_by_id']);
    $actions=[];
    if($j['status']==='DRAFT' && Auth::can('accounting.edit')) $actions[]='submit';
    if($j['status']==='REVIEW' && $original) {
        if(Auth::can('accounting.review')) $actions[]='approve';
        if(!empty($j['reviewed_by']) && Auth::can('accounting.post')) $actions[]='post';
    }
    if($j['status']==='POSTED' && $original && !PaymentPlans::managedForUi((int)$j['id']) && Auth::can('accounting.post')) $actions[]='reverse_posted';
    $state=!empty($j['reversed_by_id'])?'Reversed':(!empty($j['reverses_id'])?'Reversal':($j['status']==='REVIEW' && !empty($j['reviewed_by'])?'Ready to post':$j['status']));
    $attrs=['entry'=>(string)$j['id'],'reference'=>(string)$j['entry_no'],'state'=>$state,'date'=>(string)$j['entry_date'],'actions'=>implode(' ',$actions),'view'=>url('accounting',['tab'=>'review','entry'=>$j['id'],'status'=>$j['status'],'apply'=>1]).'#entryDetail'];
    $out='';foreach($attrs as $key=>$value)$out.=' data-accounting-'.$key.'="'.h($value).'"';return $out;
}

function accounting_entry_toolbar(): void {
    echo '<div data-accounting-toolbar class="accounting-entry-actions d-print-none" role="group" aria-label="Selected accounting entry">';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" data-accounting-action="view" disabled><i class="bi bi-eye" aria-hidden="true"></i> View</button>';
    foreach(['submit'=>['accounting.edit','Send for review','send','outline-secondary'],'approve'=>['accounting.review','Approve','check2-circle','outline-primary'],'post'=>['accounting.post','Post','check2','success'],'reverse_posted'=>['accounting.post','Void','slash-circle','outline-danger']] as $key=>[$permission,$label,$icon,$style]) {
        if(!Auth::can($permission))continue;
        echo '<button type="button" class="btn btn-sm btn-'.$style.'" data-accounting-action="'.$key.'" disabled><i class="bi bi-'.$icon.'" aria-hidden="true"></i> '.$label.'</button>';
    }
    echo '</div><p data-accounting-selection role="status" aria-live="polite" class="accounting-selection-note d-print-none">Select an entry to see the available actions.</p>';
    $definitions=[
        'submit'=>['accounting.edit','Send for review','Send this draft for Accounting review. This does not post it.','Send for review'],
        'approve'=>['accounting.review','Approve entry','Verify the source, amount, accounts and classification before approving.','Approve entry'],
        'post'=>['accounting.post','Post entry','Post the approved entry to the General Ledger? Related receipts will be locked against Cashier edits and voids.','Post entry'],
        'reverse_posted'=>['accounting.post','Void posting','Create a reversing entry in Accounting. The original stays in the audit history. A receipt remains active until Cashier voids it separately; the student balance changes at that step.','Void posting'],
    ];
    foreach($definitions as $action=>[$permission,$title,$help,$label]) {
        if(!Auth::can($permission))continue;
        echo '<dialog class="accounting-action-dialog d-print-none" data-accounting-dialog="'.$action.'" aria-labelledby="accountingActionTitle-'.$action.'"><form method="post" data-loading-label="Saving accounting action…">'.csrf_field().'<input type="hidden" name="entry_id" value=""><input type="hidden" name="action" value="'.$action.'"><h2 class="h5" id="accountingActionTitle-'.$action.'">'.$title.'</h2><p class="fw-semibold" data-accounting-target></p><p>'.$help.'</p>';
        if($action==='reverse_posted')echo '<label class="form-label" for="accountingReversalDate">Reversal date</label><input type="date" id="accountingReversalDate" class="form-control mb-3" name="reversal_date" required value="'.date('Y-m-d').'">';
        if(in_array($action,['approve','reverse_posted'],true))echo '<label class="form-label" for="accountingReason-'.$action.'">'.($action==='approve'?'What did you verify?':'Reason for voiding').'</label><textarea id="accountingReason-'.$action.'" class="form-control mb-3" name="reason" required minlength="8" maxlength="1000" rows="3"></textarea>';
        echo '<div class="d-flex justify-content-end gap-2"><button type="button" class="btn btn-outline-secondary" data-accounting-cancel>Cancel</button><button class="btn '.($action==='reverse_posted'?'btn-outline-danger':'btn-primary').'">'.$label.'</button></div></form></dialog>';
    }
}
