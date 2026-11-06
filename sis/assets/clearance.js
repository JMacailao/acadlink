/* Online Clearance (v55): "tick all" on the signer's list, and the term picker. */
(function(register){register('clearance',function(scope){
  const all=document.getElementById('clrAll');
  if(all) all.addEventListener('change',()=>document.querySelectorAll('.clr-pick').forEach(c=>{c.checked=all.checked;}));
},70);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
