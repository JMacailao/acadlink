/* Print button on a TOR / certificate page (docview); hidden when the page is shown inside the SIS preview frame. */
(function () {
  function ready() {
    var bar = document.querySelector('.doc-toolbar');
    if (!bar) return;
    if (window.self !== window.top) { bar.style.display = 'none'; return; }
    document.getElementById('docPrint').addEventListener('click', function () { window.print(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
})();
