/* My profile → E-signature: draw with mouse or finger; the drawing is sent as a PNG (or a photo is uploaded). */
(function () {
  function setup() {
    var form = document.querySelector('form[data-esign]');
    if (!form || form.dataset.ready) return;
    form.dataset.ready = '1';
    var canvas = form.querySelector('canvas'), ctx = canvas.getContext('2d'), drawn = false, down = false;
    var err = form.querySelector('[data-esign-error]');
    ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0b2a6b';
    function pos(e) { var r = canvas.getBoundingClientRect(); return [(e.clientX - r.left) * canvas.width / r.width, (e.clientY - r.top) * canvas.height / r.height]; }
    canvas.addEventListener('pointerdown', function (e) { down = true; canvas.setPointerCapture(e.pointerId); var p = pos(e); ctx.beginPath(); ctx.moveTo(p[0], p[1]); });
    canvas.addEventListener('pointermove', function (e) { if (!down) return; var p = pos(e); ctx.lineTo(p[0], p[1]); ctx.stroke(); drawn = true; });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) { canvas.addEventListener(t, function () { down = false; }); });
    form.querySelector('[data-esign-clear]').addEventListener('click', function () { ctx.clearRect(0, 0, canvas.width, canvas.height); drawn = false; });
    form.addEventListener('submit', function (e) {
      var file = form.querySelector('input[type=file]');
      if (file.files.length) { form.elements.drawn.value = ''; return; }
      if (!drawn) { e.preventDefault(); err.textContent = 'Draw your signature in the box, or choose a photo of it.'; err.hidden = false; return; }
      form.elements.drawn.value = canvas.toDataURL('image/png');
    });
  }
  if (window.SISLifecycle) window.SISLifecycle.register('esign', setup, 80);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup); else setup();
})();
