/* Registered once; navigation disposes page listeners and observers before remounting. */
(function(register){register('eligibility',function(scope){
/* Read-only preview; the server independently evaluates every create/confirm request. */
(function () {
  'use strict';
  var form = document.getElementById('newRegistrationForm');
  var target = document.getElementById('registrationEligibility');
  var button = document.getElementById('createRegistrationButton');
  if (!form || !target || !button) return;
  var pending = false, failed = false, controller;
  scope.cleanup(function(){if(controller)controller.abort();});
  function sync() {
    var panel = target.querySelector('[data-can-create]');
    var reason = form.elements.override_reason;
    button.disabled = pending || failed || !panel || !(panel.dataset.canCreate === '1' || (panel.dataset.canExcept === '1' && reason && reason.value.trim() !== ''));
  }
  async function refresh() {
    if (controller) controller.abort();
    controller = new AbortController(); var current = controller;
    pending = true; failed = false; target.setAttribute('aria-busy', 'true'); sync();
    var checking=document.createElement('p');checking.className='alert alert-info';checking.setAttribute('role','status');checking.textContent='Checking enrollment requirements…';target.querySelector('[data-loading]')?.remove();checking.dataset.loading='';target.prepend(checking);
    var url = new URL(target.dataset.previewUrl, location.href);
    var fields = {section:'section_id', term:'term', curriculum:'curriculum_id', student:'student_number', admission:'admission_id'};
    Object.keys(fields).forEach(function (key) { url.searchParams.set(key, form.elements[fields[key]].value); });
    try {
      var response = await fetch(url, {signal: current.signal, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
      var html = await response.text();
      if (current !== controller) return;
      var parsed = new DOMParser().parseFromString(html, 'text/html');
      var panel = parsed.querySelector('.eligibility-panel');
      if (!response.ok || !panel) throw new Error('Eligibility check unavailable. Refresh this page and check your sign-in and selected section.');
      target.replaceChildren(document.importNode(panel, true));
    } catch (error) {
      if (error.name === 'AbortError' || current !== controller) return;
      failed = true; var notice = document.createElement('p'); notice.className='alert alert-warning'; notice.textContent=error.message; target.replaceChildren(notice);
    } finally {
      if (current === controller) { pending=false;target.removeAttribute('aria-busy');sync(); }
    }
  }
  ['section_id','term','curriculum_id'].forEach(function (name) { form.elements[name].addEventListener('change', refresh); });
  if (form.elements.override_reason) form.elements.override_reason.addEventListener('input', sync);
  form.addEventListener('submit', function (event) { if (button.disabled || pending || failed) { event.preventDefault(); event.stopImmediatePropagation(); target.scrollIntoView({block:'center',behavior:'smooth'}); } }, true);
  sync();
})();

},60);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
