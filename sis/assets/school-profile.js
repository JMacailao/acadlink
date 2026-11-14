/* School Profile (v63.8): shrink the logo and sign-in picture in the browser before saving,
   with the same limits as the Grading System's School settings. */
(function () {
  'use strict';
  var form = document.getElementById('schoolForm');
  if (!form) return;
  var LOGO_SIZE = 160, MAX_LOGO_CHARS = 30000, PIC_MAX = 30000 * 20, PIC_WIDTH = 1600;
  var logoData = document.getElementById('spLogoData'), picData = document.getElementById('spPicData');
  var logoBox = document.getElementById('spLogoBox'), picBox = document.getElementById('spPicBox');
  var logoRemove = document.getElementById('spLogoRemove'), picRemove = document.getElementById('spPicRemove');

  function warn(msg) { window.alert(msg); }
  function show(box, src, alt) { box.innerHTML = src ? '<img alt="' + alt + '">' : '<i class="bi bi-image text-secondary" aria-hidden="true"></i>'; if (src) box.querySelector('img').src = src; }
  function load(file, maxBytes, types) {
    return new Promise(function (resolve, reject) {
      if (!types.test(file.type)) return reject(new Error('Choose a PNG, JPG or WEBP image.'));
      if (file.size > maxBytes) return reject(new Error('That image is larger than ' + Math.round(maxBytes / 1048576) + ' MB. Choose a smaller one.'));
      // Read as a data: URL (the SIS's security policy allows data: images, not blob:)
      var reader = new FileReader(), img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { reject(new Error("Couldn't read that image.")); };
      reader.onload = function () { img.src = String(reader.result); };
      reader.onerror = function () { reject(new Error("Couldn't read that image.")); };
      reader.readAsDataURL(file);
    });
  }
  function canvasFor(img, maxW, maxH, fill) {
    var scale = Math.min(1, maxW / (img.width || maxW), maxH / (img.height || maxH));
    var c = document.createElement('canvas');
    c.width = Math.max(1, Math.round((img.width || maxW) * scale)); c.height = Math.max(1, Math.round((img.height || maxH) * scale));
    var x = c.getContext('2d'); if (fill) { x.fillStyle = fill; x.fillRect(0, 0, c.width, c.height); }
    x.drawImage(img, 0, 0, c.width, c.height); return c;
  }
  function logoFrom(img) {
    var c = canvasFor(img, LOGO_SIZE, LOGO_SIZE, null);
    var tries = [function () { return c.toDataURL('image/png'); }, function () { return c.toDataURL('image/webp', 0.9); }, function () { return c.toDataURL('image/webp', 0.75); },
      function () { var w = canvasFor(c, c.width, c.height, '#ffffff'); return w.toDataURL('image/jpeg', 0.8); }];
    for (var i = 0; i < tries.length; i++) { var d = tries[i](); if (d.length <= MAX_LOGO_CHARS && d.indexOf('data:image/') === 0) return d; }
    throw new Error('This logo is too detailed to store. Try a simpler image or one with fewer colors.');
  }
  function picFrom(img) {
    var widths = [PIC_WIDTH, 1280, 1024], qs = [0.82, 0.72, 0.62, 0.52];
    for (var i = 0; i < widths.length; i++) {
      var c = canvasFor(img, widths[i], 100000, '#14203a');
      for (var j = 0; j < qs.length; j++) { var d = c.toDataURL('image/jpeg', qs[j]); if (d.length <= PIC_MAX && d.indexOf('data:image/jpeg;base64,') === 0) return d; }
    }
    throw new Error('This picture is too detailed to store. Try a smaller one.');
  }

  document.getElementById('spLogoFile').addEventListener('change', function () {
    var f = this.files[0], input = this; if (!f) return;
    load(f, 5 * 1048576, /^image\/(png|jpeg|webp)$/).then(function (img) {
      var d = logoFrom(img); logoData.value = d; show(logoBox, d, 'School logo'); logoRemove.classList.remove('d-none');
    }).catch(function (e) { warn(e.message); }).then(function () { input.value = ''; });
  });
  logoRemove.addEventListener('click', function () { logoData.value = ''; show(logoBox, '', ''); logoRemove.classList.add('d-none'); });

  document.getElementById('spPicFile').addEventListener('change', function () {
    var f = this.files[0], input = this; if (!f) return;
    load(f, 15 * 1048576, /^image\/(png|jpeg|webp)$/).then(function (img) {
      var d = picFrom(img); picData.value = d; show(picBox, d, 'Sign-in page picture'); picRemove.classList.remove('d-none');
    }).catch(function (e) { warn(e.message); }).then(function () { input.value = ''; });
  });
  picRemove.addEventListener('click', function () { picData.value = ''; show(picBox, '', ''); picRemove.classList.add('d-none'); });
})();
