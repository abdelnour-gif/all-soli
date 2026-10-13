/* QueuePro — shared helpers for public pages (no build step). */
window.QP = (function () {
  'use strict';
  var C = window.QP_CFG || {};

  function ls(op, k, v) {
    try {
      if (op === 'get') return localStorage.getItem(k);
      if (op === 'set') localStorage.setItem(k, v);
      if (op === 'del') localStorage.removeItem(k);
    } catch (e) { return null; }
  }

  // ---------------------------------------------------------- tickets saved on this phone
  function tickets() {
    var list = [];
    try { list = JSON.parse(ls('get', 'qp_tickets') || '[]') || []; } catch (e) { list = []; }
    // migrate v0.1/0.2 storage (one key per provider)
    try {
      for (var i = 0; i < localStorage.length; i++) {
        var k = localStorage.key(i);
        if (k && k.indexOf('qp_ticket_') === 0) {
          var tok = localStorage.getItem(k);
          if (tok && !list.some(function (x) { return x.t === tok; })) list.push({ t: tok, s: k.slice(10) });
        }
      }
    } catch (e) {}
    return list;
  }
  function saveTickets(list) { ls('set', 'qp_tickets', JSON.stringify(list.slice(-10))); }
  function addTicket(t) {
    var list = tickets().filter(function (x) { return x.t !== t.t && x.s !== t.s; });
    list.push(t);
    saveTickets(list);
    ls('del', 'qp_ticket_' + t.s);
  }
  function removeTicket(token) {
    var list = tickets();
    list.forEach(function (x) { if (x.t === token) ls('del', 'qp_ticket_' + x.s); });
    saveTickets(list.filter(function (x) { return x.t !== token; }));
  }
  function ticketFor(slug) {
    var x = tickets().filter(function (t) { return t.s === slug; })[0];
    return x ? x.t : null;
  }

  // ---------------------------------------------------------- polling
  function getJSON(url) {
    return fetch(url, { cache: 'no-store', credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (d) { return { ok: r.ok, status: r.status, data: d }; });
    });
  }
  function poll(_unused, restPath) {
    return getJSON(C.rest + restPath + '?lang=' + (C.lang || 'ar'));
  }
  function ticket(token) { return poll('t=' + encodeURIComponent(token), 'tickets/' + token); }
  function live(slug) { return poll('p=' + encodeURIComponent(slug), 'providers/' + slug + '/live'); }

  function post(path, body, headers) {
    return fetch(C.rest + path + '?lang=' + (C.lang || 'ar'), {
      method: 'POST', credentials: 'same-origin',
      headers: Object.assign({ 'Content-Type': 'application/json', 'Accept': 'application/json' }, headers || {}),
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok) { var e = new Error(d.message || 'Error'); e.code = d.code; e.data = d; throw e; }
        return d;
      });
    });
  }

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var a = new Uint8Array(16); (window.crypto || {}).getRandomValues ? crypto.getRandomValues(a) : a.forEach(function (_, i) { a[i] = Math.random() * 256; });
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  function qr(text, cell) {
    if (typeof qrcode !== 'function') return '';
    var q = qrcode(0, 'M'); q.addData(text); q.make();
    return q.createSvgTag({ cellSize: cell || 4, margin: 1, scalable: true });
  }

  // Two-tone chime + vibration for "it's your turn".
  var ac;
  function chime() {
    try {
      ac = ac || new (window.AudioContext || window.webkitAudioContext)();
      [[660, 0], [880, .18], [1100, .36]].forEach(function (n) {
        var o = ac.createOscillator(), g = ac.createGain();
        o.frequency.value = n[0];
        g.gain.setValueAtTime(.0001, ac.currentTime + n[1]);
        g.gain.exponentialRampToValueAtTime(.3, ac.currentTime + n[1] + .02);
        g.gain.exponentialRampToValueAtTime(.0001, ac.currentTime + n[1] + .3);
        o.connect(g).connect(ac.destination); o.start(ac.currentTime + n[1]); o.stop(ac.currentTime + n[1] + .32);
      });
    } catch (e) {}
    if (navigator.vibrate) navigator.vibrate([300, 150, 300, 150, 300]);
  }
  document.addEventListener('pointerdown', function () { try { ac = ac || new (window.AudioContext || window.webkitAudioContext)(); ac.resume(); } catch (e) {} }, { once: true });

  return { cfg: C, tickets: tickets, addTicket: addTicket, removeTicket: removeTicket, ticketFor: ticketFor,
           ticket: ticket, live: live, post: post, uuid: uuid, esc: esc, qr: qr, chime: chime };
})();
