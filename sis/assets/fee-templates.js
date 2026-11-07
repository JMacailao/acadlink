/* Fee Templates workspace: progressive editor dialog and clear new/edit actions. */
(function (register) {
  register('fee-templates', function (scope) {
    var page = document.querySelector('[data-fee-template-page]');
    if (!page) return;

    var dialog = document.getElementById('feeTemplateEditor');
    var form = dialog && dialog.querySelector('[data-template-editor-form]');
    var title = dialog && dialog.querySelector('[data-template-editor-title]');
    var cleanups = [];

    function listen(node, eventName, handler) {
      if (!node) return;
      node.addEventListener(eventName, handler);
      cleanups.push(function () { node.removeEventListener(eventName, handler); });
    }

    function showDialog() {
      if (!dialog || dialog.open) return;
      dialog.showModal();
      var name = dialog.querySelector('[name="name"]');
      if (name) name.focus();
    }

    function openNewTemplate() {
      if (!form || !dialog) return;
      form.reset();
      form.querySelector('[name="id"]').value = '';
      form.querySelector('[name="name"]').value = '';
      form.querySelector('[name="program"]').value = '';
      form.querySelector('[name="year_level"]').selectedIndex = 0;
      form.querySelector('[name="term"]').selectedIndex = 0;
      form.querySelector('[name="school_year"]').value = '';
      form.querySelector('[name="notes"]').value = '';
      form.querySelector('[name="is_active"]').checked = true;
      if (title) title.textContent = 'New fee template';
      var submit = form.querySelector('button[type="submit"], button:not([type])');
      if (submit) submit.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i>Create template';
      showDialog();
    }

    document.querySelectorAll('[data-open-template-editor]').forEach(function (button) {
      listen(button, 'click', openNewTemplate);
    });

    if (dialog) {
      dialog.querySelectorAll('[data-template-editor-close]').forEach(function (button) {
        listen(button, 'click', function () { dialog.close(); });
      });
      listen(dialog, 'click', function (event) {
        if (event.target === dialog) dialog.close();
      });
      if (page.dataset.editName === '1') scope.frame(showDialog);
    }

    scope.cleanup(function () { cleanups.forEach(function (cleanup) { cleanup(); }); });
  });
})(window.SISLifecycle ? window.SISLifecycle.register : function () {
  (window.SISModuleQueue = window.SISModuleQueue || []).push(Array.from(arguments));
});
