// Shrinks big phone photos in the browser BEFORE upload (the server compresses again).
// This keeps uploads fast and avoids the hosting upload-size limit.
(function () {
  // Only very large photos are shrunk, and to a size above what the server keeps, at high quality,
  // so the browser step never decides the final quality.
  var MAX = 2600, QUALITY = 0.95, MIN_BYTES = 2.5 * 1024 * 1024;
  if (!window.DataTransfer || !HTMLCanvasElement.prototype.toBlob) return;

  function shrink(file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|jpg|webp)$/i.test(file.type) || file.size < MIN_BYTES) return resolve(file);
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight, r = Math.min(1, MAX / Math.max(w, h));
        var c = document.createElement('canvas');
        c.width = Math.round(w * r); c.height = Math.round(h * r);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (blob) {
          if (!blob || blob.size >= file.size) return resolve(file);
          resolve(new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
        }, 'image/jpeg', QUALITY);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  var pending = 0;
  document.querySelectorAll('input[type=file][accept^="image"]').forEach(function (input) {
    input.addEventListener('change', function () {
      var files = Array.prototype.slice.call(input.files || []);
      if (!files.length) return;
      pending++;
      Promise.all(files.map(shrink)).then(function (out) {
        var dt = new DataTransfer();
        out.forEach(function (f) { dt.items.add(f); });
        input.files = dt.files;
        pending--;
      });
    });
    var form = input.form;
    if (form && !form.dataset.shrinkHooked) {
      form.dataset.shrinkHooked = '1';
      form.addEventListener('submit', function (e) {
        if (pending > 0) {
          e.preventDefault();
          var btn = form.querySelector('button:not([type=button])');
          if (btn) { btn.disabled = true; btn.dataset.txt = btn.dataset.txt || btn.textContent; btn.textContent = 'جاري تجهيز الصور...'; }
          var wait = setInterval(function () { if (pending === 0) { clearInterval(wait); if (btn) btn.disabled = false; form.requestSubmit ? form.requestSubmit() : form.submit(); } }, 200);
        }
      });
    }
  });
})();
