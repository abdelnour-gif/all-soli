/* Small UI behaviours shared by all pages (kept out of inline handlers for a strict CSP). */
(function () {
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-toggle-nav]');
    if (t) {
      var n = document.getElementById(t.getAttribute('data-toggle-nav'));
      if (n) { var o = n.classList.toggle('is-open'); t.setAttribute('aria-expanded', String(o)); }
      return;
    }
    var b = e.target.closest('[data-body-toggle]');
    if (b) { document.body.classList.toggle(b.getAttribute('data-body-toggle')); return; }
    var c = e.target.closest('[data-body-remove]');
    if (c) { document.body.classList.remove(c.getAttribute('data-body-remove')); }
  });
})();
