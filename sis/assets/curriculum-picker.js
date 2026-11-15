/* v64.6: Curriculum form with many subjects and teachers.
   - Each subject's teacher list starts short; the full list (written once in <template id="teacherOptions">) is copied in
     when that list is opened, so 1,000 subjects × 1,000 teachers no longer freeze the browser.
   - Saving sends the teacher of the checked subjects only, so the form stays under the server's input limit (1,000). */
(function(register){register('curriculum-picker',function(scope){
 'use strict';
 const form=document.getElementById('addCurriculum'),tpl=document.getElementById('teacherOptions');
 if(!form||!tpl)return;
 const fill=sel=>{
   if(!sel||sel.dataset.full)return;sel.dataset.full='1';
   const keep=sel.value,frag=tpl.content.cloneNode(true);
   frag.querySelectorAll('option').forEach(o=>{if(o.value===keep&&sel.querySelector('option[value="'+CSS.escape(keep)+'"]'))o.remove();});
   sel.append(frag);sel.value=keep;
 };
 const pick=e=>{const s=e.target.closest&&e.target.closest('select[data-teacher-pick]');if(s)fill(s);};
 scope.on(form,'pointerdown',pick);scope.on(form,'focusin',pick);scope.on(form,'keydown',pick);
 scope.on(form,'submit',()=>{form.querySelectorAll('select[data-teacher-pick]').forEach(s=>{const box=s.closest('tr')?.querySelector('input[type=checkbox][name="subjectIds[]"]');if(box&&!box.checked)s.disabled=true;});});
},96);})(window.SISLifecycle?window.SISLifecycle.register:function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
