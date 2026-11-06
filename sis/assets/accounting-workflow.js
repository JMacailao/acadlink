/* Registered once; navigation disposes page listeners and observers before remounting. */
(function(register){register('accounting-workflow',function(scope){
(()=>{
  'use strict';
  const main=document.getElementById('main'),tools=main?.querySelector('[data-accounting-toolbar]');if(!tools)return;
  const header=main.querySelector('.record-toolbar-buttons'),note=main.querySelector('[data-accounting-selection]');
  if(header){header.querySelectorAll('.record-selection-action').forEach(el=>el.remove());header.prepend(tools);const toolbar=header.closest('.record-toolbar');toolbar.after(note);}
  const buttons=[...tools.querySelectorAll('[data-accounting-action]')],dialogs=[...main.querySelectorAll('[data-accounting-dialog]')];
  let selected=null,dirty=false;
  function sync(target){
    selected=target;
    main.querySelectorAll('[data-accounting-entry]').forEach(el=>{const active=target&&el.dataset.accountingEntry===target.dataset.accountingEntry;el.classList.toggle('accounting-entry-selected',!!active);if(el.matches('tr'))el.setAttribute('aria-selected',String(!!active));});
    const allowed=(target?.dataset.accountingActions||'').split(' ');
    buttons.forEach(b=>{const key=b.dataset.accountingAction;b.disabled=dirty||!target||(key!=='view'&&!allowed.includes(key));b.title=b.disabled?(dirty?'Apply filters again before selecting an entry.':'Select an entry with the required status and permission.'):b.textContent.trim();});
    note.textContent=dirty?'Filters changed — click Apply Filters, then select an entry.':target?target.dataset.accountingReference+' · '+target.dataset.accountingState+' · 1 selected. '+(allowed.includes('post')?'Approved and ready to post.':allowed.includes('submit')?'Send for review before approval and posting.':allowed.includes('approve')?'Review and approve before posting.':allowed.includes('reverse_posted')?'Void creates an Accounting reversal.':'View only; no posting or void action is available.'):'Select an entry to see the available actions.';
  }
  function invalidate(){dirty=true;sync(null);dialogs.forEach(d=>{if(d.open)d.close();});}
  const panel=main.querySelector('[data-record-list]'),filters=panel&&document.getElementById(panel.dataset.filterForm);
  if(filters){scope.on(filters,'input',invalidate);scope.on(filters,'change',invalidate);}
  main.querySelectorAll('[data-list-search] input').forEach(el=>scope.on(el,'input',invalidate));
  const detail=main.querySelector('#entryDetail[data-accounting-entry]');
  scope.on(main,'click',e=>{const row=e.target.closest('tr[data-accounting-entry],tbody[data-accounting-entry]');if(row&&!dirty&&!e.target.closest('a,button,input,select,textarea'))sync(row);});
  scope.on(main,'keydown',e=>{const row=e.target.closest('tr[data-accounting-entry],tbody[data-accounting-entry]');if(row&&!dirty&&e.target===row&&(e.key==='Enter'||e.key===' ')){e.preventDefault();sync(row);}});
  main.querySelectorAll('tbody[data-accounting-entry]').forEach(el=>el.tabIndex=0);
  buttons.forEach(button=>scope.on(button,'click',()=>{
    if(button.disabled||!selected||dirty)return;
    const action=button.dataset.accountingAction,url=selected.dataset.accountingView;
    if(action==='view'){if(window.SISNavigation)window.SISNavigation.navigate(url);else location.assign(url);return;}
    const dialog=dialogs.find(d=>d.dataset.accountingDialog===action);if(!dialog)return;
    const f=dialog.querySelector('form');f.reset();f.action=url.split('#')[0];f.elements.entry_id.value=selected.dataset.accountingEntry;
    dialog.querySelector('[data-accounting-target]').textContent=selected.dataset.accountingReference+' · '+selected.dataset.accountingState;
    if(f.elements.reversal_date){const input=f.elements.reversal_date;input.min=selected.dataset.accountingDate;if(input.value<input.min)input.value=input.min;}
    dialog.showModal();
  }));
  dialogs.forEach(dialog=>{scope.on(dialog.querySelector('[data-accounting-cancel]'),'click',()=>dialog.close());scope.on(dialog.querySelector('form'),'submit',e=>{if(dirty||!selected){e.preventDefault();return;}const b=buttons.find(b=>b.dataset.accountingAction===dialog.dataset.accountingDialog);if(!b||b.disabled)e.preventDefault();});});
  scope.cleanup(()=>dialogs.forEach(d=>{if(d.open)d.close();}));
  sync(detail);
})();
(()=>{const form=document.getElementById('accountingDocument');if(!form)return;const kind=form.elements.kind;function update(){form.querySelectorAll('[data-kinds]').forEach(box=>{const visible=box.dataset.kinds.split(' ').includes(kind.value);box.hidden=!visible;box.querySelectorAll('input,select').forEach(input=>input.disabled=!visible);});const required={supplier_id:['BILL'],bill_id:['DISBURSEMENT'],account_id:['BILL','EXPENSE','REIMBURSEMENT','PAYROLL','ADJUSTMENT'],payable_account_id:['BILL','PAYROLL'],cash_account_id:['DISBURSEMENT','EXPENSE','REIMBURSEMENT','REFUND'],registration_id:['ADJUSTMENT'],payment_id:['REFUND']};Object.entries(required).forEach(([name,kinds])=>form.elements[name].required=kinds.includes(kind.value));}kind.addEventListener('change',update);update();})();

},70);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
