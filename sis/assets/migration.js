/* Registered once; navigation disposes page listeners and observers before remounting. */
(function(register){register('migration',function(scope){
(()=>{
  const form=document.getElementById('migrationTemplate');if(!form)return;
  const school=form.elements.sy,year=form.elements.year,section=form.elements.section;
  function updateRecordType(){
    const blank=form.elements.kind.value==='BLANK';
    form.elements.mode.disabled=blank;
    document.getElementById('migrationBlankHint').hidden=!blank;
  }
  form.elements.kind.addEventListener('change',updateRecordType);updateRecordType();
  const sections=[...section.options].filter(o=>o.value);
  function updateYears(){
    const available=new Set(sections.filter(o=>!school.value||o.dataset.schoolYear===school.value).map(o=>o.dataset.yearLevel));
    JSON.parse(form.dataset.levels||'[]').filter(([sy])=>!school.value||sy===school.value).forEach(([,level])=>available.add(level));
    [...year.options].forEach(o=>{o.hidden=o.disabled=!!(o.value&&!available.has(o.value));});
    if(year.selectedOptions[0]?.disabled)year.value='';
    updateSections();
  }
  function updateSections(){
    sections.forEach(o=>{o.hidden=o.disabled=!!((school.value&&o.dataset.schoolYear!==school.value)||(year.value&&o.dataset.yearLevel!==year.value));});
    if(section.selectedOptions[0]?.disabled)section.value='';
  }
  school.addEventListener('change',updateYears);year.addEventListener('change',updateSections);updateYears();
})();

},70);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
