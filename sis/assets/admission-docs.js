/* v56 Admission → document checklist: on a new applicant, show the requirements of the chosen classification. */
(function (register) { register('admission-docs', function (scope) {
  document.querySelectorAll('[data-doc-list][data-doc-follow]').forEach(function (list) {
    var form = list.closest('form'), pick = form && form.querySelector('[data-doc-type]');
    if (!pick) return;
    function show() {
      var t = pick.value, any = false;
      list.querySelectorAll('[data-doc-row]').forEach(function (row) {
        var on = !!t && row.dataset.applies.split(',').indexOf(t) >= 0;
        row.hidden = !on; if (on) any = true;
        var box = row.querySelector('input'); if (box && !on) box.checked = false;
      });
      list.querySelector('[data-doc-choose]').hidden = !!t;
    }
    scope.on(pick, 'change', show); show();
  });
}, 80); })(window.SISLifecycle ? window.SISLifecycle.register : function () { (window.SISModuleQueue = window.SISModuleQueue || []).push(Array.from(arguments)); });
