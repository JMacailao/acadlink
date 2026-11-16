/* v65.6: Curriculum → Subjects: Total units = lecture units + laboratory units, shown as you type. */
(function(register){register('subject-units',function(scope){
 'use strict';
 document.querySelectorAll('[data-unit-split]').forEach(box=>{
   const lec=box.querySelector('[name=lecUnits]'),lab=box.querySelector('[name=labUnits]'),out=box.querySelector('[data-unit-total]');
   if(!lec||!lab||!out)return;
   const n=v=>{const x=parseFloat(String(v).trim());return isFinite(x)?x:0;};
   const sum=()=>{const t=n(lec.value)+n(lab.value);out.textContent=String(+t.toFixed(1));};
   scope.on(lec,'input',sum);scope.on(lab,'input',sum);sum();
 });
},99);})(window.SISLifecycle?window.SISLifecycle.register:function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
