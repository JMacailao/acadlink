/* v63.9: a Grading System page shown inside SIS Settings. The frame grows with its content (same site),
   and links inside it that would leave the page open in the whole window. */
(function () {
  'use strict';
  var frame = document.getElementById('gsFrame'), loading = document.getElementById('gsFrameLoading');
  if (!frame) return;
  var last = 0;
  function fit() {
    try {
      var d = frame.contentDocument; if (!d || !d.body) return;
      var h = Math.max(d.documentElement.scrollHeight, d.body.scrollHeight);
      if (h && Math.abs(h - last) > 4) { last = h; frame.style.height = (h + 8) + 'px'; }
    } catch (e) { /* not reachable: leave the default height */ }
  }
  frame.addEventListener('load', function () {
    if (loading) loading.hidden = true;
    fit();
    try {
      var d = frame.contentDocument;
      // Grading links to its other pages open in the Grading System, outside SIS
      d.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || a.target || a.hasAttribute('data-bs-toggle')) return;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(javascript:|mailto:|tel:)/i.test(href)) return;
        e.preventDefault(); window.open(a.href, '_blank', 'noopener');
      });
      if ('ResizeObserver' in window) new ResizeObserver(fit).observe(d.body);
    } catch (e) { /* ignore */ }
  });
  setInterval(fit, 800);
})();
