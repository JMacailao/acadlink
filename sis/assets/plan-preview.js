/* v56.2 Registration → Payment plan: choosing a plan shows it on the assessment at once (preview until Save plan). */
(function (register) { register('plan-preview', function (scope) {
  var pick = document.querySelector('select[data-plan-preview]');
  if (!pick) return;
  var saved = pick.value, busy = null;
  function show() {
    var box = document.querySelector('[data-plan-box]');
    if (!box) return;
    if (pick.value === saved) { if (box.dataset.original) { box.outerHTML = box.dataset.original; } return; }
    var url = pick.dataset.planPreview + encodeURIComponent(pick.value);
    if (busy) busy.abort();
    busy = new AbortController();
    box.setAttribute('aria-busy', 'true');
    fetch(url, { credentials: 'same-origin', signal: busy.signal, headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.text().then(function (t) { if (!res.ok) throw new Error(t); return t; }); })
      .then(function (html) {
        var tmp = document.createElement('div'); tmp.innerHTML = html.trim();
        var next = tmp.querySelector('[data-plan-box]');
        if (!next) throw new Error('No preview');
        var current = document.querySelector('[data-plan-box]');
        next.dataset.original = current.dataset.original || current.outerHTML;
        current.replaceWith(next);
      })
      .catch(function (e) { if (e.name !== 'AbortError') { box.removeAttribute('aria-busy'); } });
  }
  scope.on(pick, 'change', show);
}, 85); })(window.SISLifecycle ? window.SISLifecycle.register : function () { (window.SISModuleQueue = window.SISModuleQueue || []).push(Array.from(arguments)); });
