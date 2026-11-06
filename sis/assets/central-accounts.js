/* Registered once; navigation disposes page listeners and observers before remounting. */
(function(register){register('central-accounts',function(scope){
(function(){
  const group=document.getElementById('centralGroup');
  if(!group)return;
  const descriptions={POWER_USER:'Power User: Manage accounts, groups, settings and both systems.',ACCOUNTING:'Accounting: Manage assessments, discounts, payments, receipts, balances and accounting. No grade entry or account administration.',REGISTRAR:'Registrar: Manage academic records, admission and registration. Finalize grades. No payment collection or account administration.',TEACHER:'Teacher: View assigned classes, enter open grades and submit grades. Cannot finalize grades or access accounting.',STUDENT:'Student: View own finalized grades, assessments and enrollment status. Submit own admission application. No access to other students.'};
  function update(){
    const opt=group.selectedOptions[0];
    document.getElementById('accessPreview').textContent=descriptions[group.value]||(opt&&opt.dataset.desc)||'';
    const sign=document.getElementById('clearanceSign'),hint=document.getElementById('clearanceSignHint');
    if(sign){const signs=opt&&opt.dataset.signs==='1';sign.disabled=!signs;
      hint.textContent=signs?'Untick to let this person see the clearance list without signing (e.g. a student assistant).':(group.value==='POWER_USER'?'Power Users approve any clearance line; what they approve shows "Approved".':'This group does not sign student clearance (tick it in Groups and access first).');}
    [['teacherAccountFields','TEACHER'],['studentAccountFields','STUDENT']].forEach(([id,code])=>{
      const box=document.getElementById(id);box.hidden=group.value!==code;
      box.querySelectorAll('input,select').forEach(el=>el.disabled=box.hidden);
    });
  }
  group.addEventListener('change',update);update();
})();

},70);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
