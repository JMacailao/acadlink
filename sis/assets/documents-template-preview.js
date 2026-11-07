/* Keep each Settings preview link in sync with its selected document template. */
(() => {
  'use strict';
  document.querySelectorAll('[data-document-template-preview]').forEach(link => {
    const type = link.dataset.type;
    const select = link.closest('tr')?.querySelector(`[name="doc_template_${CSS.escape(type)}"]`);
    if (!select) return;
    const update = () => {
      const url = new URL(link.href, window.location.href);
      url.searchParams.set('doc', `${type}:${select.value}`);
      link.href = url.href;
    };
    select.addEventListener('change', update);
    update();
  });
})();
