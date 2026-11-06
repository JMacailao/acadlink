/* Registered once; navigation disposes page listeners and observers before remounting. */
(function(register){register('workspaces',function(scope){
/* Save only the list scroll position; filters stay in normal server-rendered URLs. */
(function () {
  'use strict';
  var route = document.body.dataset.route;
  if (route !== 'balance' && route !== 'notifications') return;
  var key = 'acadlink-workspace-scroll-' + route;
  document.querySelectorAll('[data-workspace-link]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey) return;
      try { sessionStorage.setItem(key, String(window.scrollY)); } catch (_) {}
    });
  });
  document.querySelectorAll('[data-workspace-return]').forEach(function (link) {
    link.addEventListener('click', function () { try { sessionStorage.setItem(key + '-restore','1'); } catch (_) {} });
  });
  try {
    if (sessionStorage.getItem(key + '-restore') === '1') {
      sessionStorage.removeItem(key + '-restore');
      var position = Number(sessionStorage.getItem(key) || 0);
      scope.frame(function () { window.scrollTo(0,position); });
    }
  } catch (_) {}
})();

},60);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
