/* v65.6: Curriculum → Subjects: Total units = lecture units + laboratory units, shown as you type. */
(function(register){register('subject-units',function(scope){
 'use strict';
 document.querySelectorAll('[data-unit-split]').forEach(box=>{
   const lec=box.querySelector('[name="u_lec[]"]')||box.querySelector('[name=lecUnits]'),lab=box.querySelector('[name="u_lab[]"]')||box.querySelector('[name=labUnits]'),out=box.querySelector('[data-unit-total]'); // v66: also the many-subjects editor
   if(!lec||!lab||!out)return;
   const n=v=>{const x=parseFloat(String(v).trim());return isFinite(x)?x:0;};
   const sum=()=>{const t=n(lec.value)+n(lab.value);out.textContent=String(+t.toFixed(1));};
   scope.on(lec,'input',sum);scope.on(lab,'input',sum);sum();
 });
},99);})(window.SISLifecycle?window.SISLifecycle.register:function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});

/* v66.4: the many-subjects units editor sends only the rows that were changed (the server takes at most 1,000 fields per form). */
(function(register){register('subject-units-send-changed',function(scope){
 'use strict';
 const form=document.querySelector('form[data-units-bulk]'); if(!form)return;
 form.querySelectorAll('tr[data-unit-split]').forEach(tr=>tr.querySelectorAll('input[name="u_lec[]"],input[name="u_lab[]"]').forEach(i=>{i.dataset.orig=i.value;}));
 scope.on(form,'submit',()=>{
   form.querySelectorAll('tr[data-unit-split]').forEach(tr=>{
     const ins=[...tr.querySelectorAll('input[name="u_lec[]"],input[name="u_lab[]"]')];
     if(ins.every(i=>i.value.trim()===i.dataset.orig))tr.querySelectorAll('input[name="u_id[]"],input[name="u_lec[]"],input[name="u_lab[]"]').forEach(i=>{i.disabled=true;});
   });
 });
},100);})(window.SISLifecycle?window.SISLifecycle.register:function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
