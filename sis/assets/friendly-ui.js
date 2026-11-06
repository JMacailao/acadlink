/* Registered once; navigation disposes page listeners and observers before remounting. */
(function(register){register('friendly-ui',function(scope){
/* Reference UI: progressive enhancement; original server forms remain usable without JS. */
(function () {
  'use strict';
  if (!window.HTMLDialogElement) return;
  var route = document.body.dataset.route;
  var params = new URLSearchParams(location.search);
  var activeDialog = null;
  var titles = {students: 'Student directory', sections: 'Section directory', teachers: 'Teacher directory', curriculum: 'Curriculum directory', assessment: 'Assessment directory', discounts: 'Scholarship & discount programs', users: 'User directory'};
  if (route === 'assessment') titles.assessment = {categories: 'Fee categories', labels: 'Assessment labels', names: 'Fee templates'}[params.get('tab') || 'categories'];
  if (route === 'curriculum' && params.get('tab') === 'subjects') titles.curriculum = 'Subject directory';
  var addForms = Array.from(document.querySelectorAll('form.form-section[id^="add"], form#pw, form#access'));
  addForms.forEach(function (form, fi) {
    var formId = form.getAttribute('id');
    var host = form.parentElement;
    var heading = form.querySelector('h2');
    var title = heading ? heading.textContent.trim() : 'Add record';
    var dialog = document.createElement('dialog');
    dialog.className = 'record-dialog';
    dialog.setAttribute('aria-labelledby', 'record-title-' + fi);
    dialog.innerHTML = '<div class="record-dialog-head"><div><p class="record-eyebrow">' + (formId === 'pw' ? 'Account security' : formId === 'access' ? 'Access per system and module' : 'Record details') + '</p><h2 id="record-title-' + fi + '"></h2></div><button type="button" class="btn-close" aria-label="Close form"></button></div>';
    dialog.querySelector('h2').textContent = title;
    document.body.appendChild(dialog);
    scope.cleanup(function(){dialog.close();dialog.remove();});
    dialog.appendChild(form);
    form.classList.add('record-form');
    if (heading) heading.hidden = true;
    var hint = document.createElement('p'); hint.className = 'record-hint';
    hint.textContent = 'Fields marked * are required. Review the details before saving.';
    form.insertBefore(hint, form.firstChild);
    // Give every control a persistent, associated label; placeholders remain examples.
    form.querySelectorAll('input:not([type=hidden]), select, textarea').forEach(function (field, index) {
      if (/^(checkbox|radio|submit|button)$/.test(field.type)) return;
      if (field.form && field.form !== form) return; // belongs to another form (e.g. the add rows inside a fee template)
      if (!field.id) field.id = 'record-' + fi + '-' + index;
      var label = field.labels && field.labels[0];
      if (!label && field.previousElementSibling && field.previousElementSibling.tagName === 'LABEL') label = field.previousElementSibling;
      if (!label) {
        label = document.createElement('label'); label.className = 'form-label small';
        var names = {name:'Name', description:'Description', program:'Program', year_level:'Year level', yearLevel:'Year level', term:'Term', school_year:'School year', notes:'Notes', username:'Username', full_name:'Full name'};
        label.textContent = names[field.name] || field.placeholder || field.name.replace(/[_\[\]]/g, ' ') || 'Search';
        field.before(label);
      }
      label.htmlFor = field.id;
      if (field.required) { var star = document.createElement('span'); star.className = 'required-mark'; star.textContent = ' *'; label.appendChild(star); }
      if (field.type === 'email') field.autocomplete = 'email';
      if (field.name === 'studentName') field.placeholder = 'e.g. Dela Cruz, Juan M.';
      if (field.name === 'teacherName') field.placeholder = 'e.g. Maria Dela Cruz';
      if (/amount|^units$|^value$/.test(field.name)) field.inputMode = 'decimal';
    });
    var error = document.createElement('div'); error.className = 'record-errors'; error.setAttribute('role', 'alert'); error.hidden = true; form.prepend(error);
    form.addEventListener('invalid', function () { error.textContent = 'Please complete the highlighted field before saving.'; error.hidden = false; }, true);
    form.addEventListener('input', function () { error.hidden = true; });
    var cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn btn-outline-secondary record-cancel'; cancel.textContent = 'Cancel';
    var save = form.querySelector('button:not([type]), button[type=submit]');
    if (save) save.before(cancel); else form.appendChild(cancel);
    function close() { dialog.close(); }
    cancel.addEventListener('click', close);
    dialog.querySelector('.btn-close').addEventListener('click', close);
    // Native dialog supplies focus trapping, Escape, and restoration to the opener.
    function open() {
      if (activeDialog && activeDialog.open) activeDialog.close();
      activeDialog = dialog; dialog.showModal();
      var first = form.querySelector('input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea');
      if (first) first.focus();
    }
    document.querySelectorAll('a[href="#' + formId + '"]').forEach(function (link) {
      link.setAttribute('aria-haspopup', 'dialog');
      link.addEventListener('click', function (e) {
        e.preventDefault();
        // Add from an edit page should start with a clean server-backed form.
        if (formId !== 'pw' && formId !== 'access' && (params.has('edit') || params.has('open') || (formId === 'addNames' && params.has('id')))) {
          var next = new URL(location.href); ['edit','open','id'].forEach(function(k){next.searchParams.delete(k);}); next.hash = formId; location.href = next.href; return;
        }
        open();
      });
    });
    // Remove empty form columns and give their directory the full available width.
    if (!host.children.length && /col-(?:xl|lg)-/.test(host.className)) {
      var row = host.parentElement; host.remove();
      if (row) Array.from(row.children).forEach(function (column) { if (/col-(?:xl|lg)-/.test(column.className)) { column.className = column.className.replace(/col-(?:xl|lg)-\d+/g, 'col-12'); } });
    }
    var editOpen = (params.has('edit') && formId !== 'pw' && formId !== 'access' && location.hash !== '#pw' && location.hash !== '#access') || (params.has('open') && formId === 'addCurriculum') || (params.has('editName') && formId === 'addNames');
    if (location.hash === '#' + formId || editOpen) open();
  });
  // Directory headers and result counts mirror the supplied references.
  if (titles[route]) document.querySelectorAll('#main > .row > [class*="col-"] > .table-responsive, #main > .row > [class*="col-"] > .form-section.p-0').forEach(function (wrap) {
    var table = wrap.querySelector('table'); if (!table || document.querySelector('[data-record-list]')) return;
    // Replace the old compact search when this card supplies its own search field.
    Array.from(wrap.parentElement.children).forEach(function (el) { if (el.matches('input[data-filter]')) el.remove(); });
    var header = document.createElement('div'); header.className = 'directory-head';
    var h = document.createElement('h2'); h.textContent = titles[route]; header.appendChild(h);
    var count = document.createElement('p'); header.appendChild(count); wrap.prepend(header);
    var search = document.createElement('input'); search.type = 'search'; search.className = 'form-control directory-search'; search.placeholder = 'Search this directory…'; search.setAttribute('aria-label','Search ' + titles[route].toLowerCase()); header.appendChild(search);
    var rows = Array.from(table.querySelectorAll('tbody > tr')).filter(function (tr) { return !tr.querySelector('td[colspan]'); });
    var empty = document.createElement('tr'); var cell = document.createElement('td'); cell.colSpan = table.tHead ? table.tHead.rows[0].cells.length : 6; cell.className = 'text-center text-muted py-4'; cell.textContent = 'No matching records. Try another search.'; empty.appendChild(cell); empty.hidden = true; table.tBodies[0].appendChild(empty);
    function filter() { var q = search.value.trim().toLowerCase(), n=0; rows.forEach(function(tr){tr.hidden=q && !tr.textContent.toLowerCase().includes(q);if(!tr.hidden)n++;});empty.hidden = !rows.length || n > 0;count.textContent=n+' record'+(n===1?'':'s')+(q?' matching your search':''); }
    search.addEventListener('input',filter); filter();
  });
  // Account roles: request only details needed for the selected account type.
  var userForm = document.getElementById('addUser');
  if (userForm && userForm.elements.role) {
    var role = userForm.elements.role;
    function syncRole() { var student = role.value === 'STUDENT'; ['username','student_number'].forEach(function(name){var f=userForm.elements[name];f.hidden=name==='username'?student:!student;f.required=!f.hidden; if(f.labels[0])f.labels[0].hidden=f.hidden;});userForm.elements.full_name.required=!student;
      ['username','student_number','full_name'].forEach(function(name){var f=userForm.elements[name],label=f.labels[0];if(!label)return;var star=label.querySelector('.required-mark');if(star)star.remove();if(f.required){star=document.createElement('span');star.className='required-mark';star.textContent=' *';label.appendChild(star);}}); }
    role.addEventListener('change',syncRole);syncRole();
  }
})();

},50);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
