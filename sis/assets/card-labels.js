/* v64.3: on phones, record tables show as cards without their column headings. Number and money cells get their
   column name as a label ("Copies 2", "Balance 5,275.00") so a bare number is never ambiguous. */
(function(register){register('card-labels',function(){
 'use strict';
 const NUM=/^[₱$€+\-–(]?\s*\d[\d.,]*\s*%?\)?(\s*\+\d+\s*ref\.?)?$/;
 document.querySelectorAll('#main table').forEach(t=>{
   const head=t.tHead&&t.tHead.rows[0];if(!head)return;
   const names=[];let col=0;[...head.cells].forEach(th=>{const span=th.colSpan||1;for(let i=0;i<span;i++)names[col++]=th.textContent.replace(/\s+/g,' ').trim();});
   [...t.tBodies].forEach(b=>[...b.rows].forEach(tr=>{let c=0;[...tr.cells].forEach(td=>{const name=names[c];c+=td.colSpan||1;
     if(!name||td.hasAttribute('data-label')||td.colSpan>1)return;const text=td.textContent.replace(/\s+/g,' ').trim();
     if(text&&text.length<=20&&NUM.test(text))td.setAttribute('data-label',name);});}));
 });
},98);})(window.SISLifecycle?window.SISLifecycle.register:function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
