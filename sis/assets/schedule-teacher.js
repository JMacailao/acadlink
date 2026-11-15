/* v65.5: Class Schedules → Add a class: the Teacher fills in with the teacher of this section's class (Grading
   assignments) for the chosen subject. A teacher picked by hand is kept. */
(function(register){register('schedule-teacher',function(scope){
 'use strict';
 const sel=document.getElementById('scTeacher'),sub=document.getElementById('scSub');
 if(!sel||!sub)return;
 let map={};try{map=JSON.parse(sel.dataset.classTeachers||'{}')||{};}catch(e){}
 let auto=sel.value==='';
 const fill=()=>{const t=map[sub.value]||'';if(!auto)return;if(t&&sel.querySelector('option[value="'+CSS.escape(t)+'"]'))sel.value=t;else sel.value='';};
 scope.on(sel,'change',()=>{auto=sel.value==='';});
 scope.on(sub,'change',fill);
 if(auto&&sub.value)fill();
},98);})(window.SISLifecycle?window.SISLifecycle.register:function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
