/* v63.17: draws the QR code for setting up two-step sign-in (vendor/qrcode-generator). */
(function () {
  'use strict';
  document.querySelectorAll('[data-page-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
  var box = document.querySelector('[data-ts-qr]');
  if (!box || typeof window.qrcode !== 'function') return;
  var qr = window.qrcode(0, 'M');
  qr.addData(box.getAttribute('data-ts-qr'));
  qr.make();
  box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
})();
