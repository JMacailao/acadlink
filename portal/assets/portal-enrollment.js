/* Local dropdowns only. The server rechecks every period, level and section on submission. */
(function(register){register('portal-enrollment',function(scope){
  'use strict';
  const form=document.getElementById('enrollmentChoiceForm');if(!form)return;
  const choices=JSON.parse(form.dataset.choices||'{}'),school=form.elements.school_year,term=form.elements.term,level=form.elements.requested_year_level,section=form.elements.preferred_section_id;
  const checked=document.getElementById('enrollmentCheckedResults'),notice=document.getElementById('enrollmentChoiceNotice');
  function dirty(){if(checked){checked.hidden=true;checked.querySelectorAll('button,input,textarea').forEach(el=>el.disabled=true);}notice.textContent='Your choices changed. Click Check requirements to review them before submitting.';}
  function current(){return choices[school.value+'|'+term.value]||{levels:[],sections:[]};}
  function sections(){const keep=section.value;section.replaceChildren(new Option('Registrar will assign my section',''),...current().sections.filter(s=>s.year_level===level.value).map(s=>new Option(s.name,s.id)));section.value=[...section.options].some(o=>o.value===keep)?keep:'';}
  function levels(){const keep=level.value,data=current();level.replaceChildren(new Option('Choose year level',''),...data.levels.map(value=>new Option(value,value)));level.value=data.levels.includes(keep)?keep:(data.levels[0]||'');sections();}
  function periods(){const keep=term.value;[...term.options].forEach(o=>{o.hidden=o.disabled=o.dataset.schoolYear!==school.value;});const match=[...term.options].find(o=>!o.disabled&&o.value===keep)||[...term.options].find(o=>!o.disabled);if(match)match.selected=true;levels();}
  scope.on(school,'change',()=>{periods();dirty();});
  scope.on(term,'change',()=>{levels();dirty();});
  scope.on(level,'change',()=>{sections();dirty();});
  scope.on(section,'change',dirty);
  periods();
},70);})(window.SISLifecycle?window.SISLifecycle.register:function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
