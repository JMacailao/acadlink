/* Registered once; navigation disposes page listeners and observers before remounting. */
(function(register){register('accounting-ui',function(scope){
/* Accounting presentation only. Native forms, routes, permissions and posting remain authoritative. */
(() => {
  'use strict';
  if(document.body.dataset.route!=='accounting')return;
  const main=document.getElementById('main');if(!main)return;
  const nav=main.querySelector('.accounting-navigation'),context=main.querySelector('.accounting-context'),dock=main.querySelector('.sis-list-dock');
  // Keep the shared header toolbar, then the accounting tasks and compact filters, above scrolling records.
  if(dock){if(nav)main.insertBefore(nav,dock);if(context)main.insertBefore(context,dock);}
  main.querySelectorAll('.sis-toolbar-description').forEach(el=>el.remove());
  main.querySelectorAll('.page-head .page-lede').forEach(el=>el.remove());
  const panel=main.querySelector('[data-record-list]');
  const form=panel&&document.getElementById(panel.dataset.filterForm);
  if(panel&&form){
    form.querySelectorAll('.record-filter-cell').forEach(cell=>{if(!cell.querySelector('input:not([type=hidden]),select,textarea'))cell.hidden=true;});
    const fields=[...form.querySelectorAll('input:not([type=hidden]),select,textarea')];
    panel.classList.toggle('accounting-search-only',fields.length===0);
    // Search-only lists keep their search and Apply action in the common toolbar, without an empty filter card.
    if(!fields.length){panel.hidden=true;const toggle=main.querySelector('[data-list-toggle]');if(toggle){toggle.hidden=true;toggle.disabled=true;}}
  }
  const scope=main.querySelector('.sis-record-scroll')||main;
  const tables=[...scope.querySelectorAll('table')].filter(table=>table.tHead&&!table.closest('form,.modal,dialog,#entryDetail'));
  const applied=panel?.dataset.applied==='1';
  const tab=new URLSearchParams(location.search).get('tab')||'overview';
  const titles={review:'Transactions',ledger:'Posted journal entries',accounts:'Chart of accounts',transactions:'Transactions',budgets:'Budget versus actual',assets:'School assets',financial:'Financial reports',periods:'Accounting periods',reconcile:'Cash and bank transactions'};
  const changed=()=>main.querySelectorAll('.accounting-results-state').forEach(el=>el.textContent='Apply filters to update');
  if(form){form.addEventListener('input',changed);form.addEventListener('change',changed);}
  main.querySelectorAll('[data-list-search] input').forEach(input=>{input.addEventListener('input',changed);input.addEventListener('change',changed);});
  let first=true;
  tables.forEach(table=>{
    const section=table.closest('.form-section');if(!section)return;
    section.classList.add('accounting-record-panel');
    if(!section.querySelector(':scope > .accounting-results-head')&&!section.querySelector('h2,h3')){
      const head=document.createElement('div');head.className='accounting-results-head';
      const title=document.createElement('h3');title.textContent=titles[tab]||'Records';
      const state=document.createElement('span');state.className='accounting-results-state';state.textContent=applied?'Applied results':'Awaiting filters';
      head.append(title,state);section.prepend(head);
    }
    if(panel){
      const rows=[...table.querySelectorAll('tbody > tr')];
      if(!rows.length||rows.every(row=>row.querySelector('[colspan]'))){
        const tbody=table.tBodies[0]||table.createTBody();tbody.replaceChildren();
        const cell=tbody.insertRow().insertCell();cell.colSpan=table.tHead.rows[0]?.cells.length||5;cell.className='accounting-empty-cell';
        const empty=document.createElement('div');empty.className='accounting-empty';
        const icon=document.createElement('i');icon.className='bi bi-file-earmark-text';icon.setAttribute('aria-hidden','true');
        const title=document.createElement('strong');title.textContent=applied?'No matching records':'Choose what you want to review';
        const text=document.createElement('p');text.textContent=applied?'Try a different search or filter, then select Apply Filters.':'Set your filters above, then click Apply Filters to show records.';
        empty.append(icon,title,text);cell.append(empty);
      }
    }
    if(first){
      const note=document.createElement('p');note.className='accounting-posting-note';
      const icon=document.createElement('i');icon.className='bi bi-lock';icon.setAttribute('aria-hidden','true');
      note.append(icon,document.createTextNode(' Student receipts update balances immediately. The General Ledger shows posted entries only.'));
      section.append(note);first=false;
    }
  });
})();

},30);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
