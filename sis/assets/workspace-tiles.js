/* v63.1 Workspace chooser on phones: scale each picture so it fills its tile (the pictures are drawn 232 × 200). */
(function () {
  'use strict';
  function fit() {
    var phone = window.matchMedia('(max-width: 767.98px)').matches;
    document.querySelectorAll('#portalChooser .al-art').forEach(function (art) {
      var stage = art.querySelector('.al-stage'); if (!stage) return;
      if (!phone) { stage.style.removeProperty('--z'); stage.style.top = ''; return; }
      var w = art.clientWidth, h = art.clientHeight, z = Math.min(w / 232, h / 200);
      stage.style.setProperty('--z', z.toFixed(4));
      stage.style.left = ((w - 232 * z) / 2) + 'px';
      stage.style.top = ((h - 200 * z) / 2) + 'px';
    });
  }
  fit();
  window.addEventListener('resize', fit);
  window.addEventListener('orientationchange', fit);
})();
