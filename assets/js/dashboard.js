/* QueuePro — provider dashboard (vanilla JS, no build step). */
(() => {
  'use strict';
  const C = window.QPD;
  const LANG = C.lang;

  // ------------------------------------------------------------------ strings
  const STR = {
    ar: {
      tab_queue: 'الطابور', tab_services: 'الخدمات', tab_account: 'الحساب',
      queue: 'الطابور', open: 'مفتوح', closed: 'مغلق', credits: 'الرصيد', available: 'متاح', reserved: 'محجوز', consumed: 'مستهلك',
      now_serving: 'الرقم الحالي', waiting: 'المنتظرون', done_today: 'مكتملة اليوم',
      call_next: 'استدعاء التالي', walkin: 'عميل حاضر', live_queue: 'الطابور الحي', closed_today: 'منتهية اليوم',
      empty: 'لا يوجد أحد في الطابور الآن', empty_hint: 'شارك رابط صفحتك ليبدأ العملاء بالحجز', nobody_waiting: 'لا يوجد أحد في الانتظار',
      customer: 'عميل', walkin_label: 'حاضر',
      a_call: 'استدعاء', a_start: 'بدء الخدمة', a_complete: 'إكمال', a_delay: 'تأجيل', a_no_show: 'لم يحضر', a_cancel: 'إلغاء',
      confirm_cancel: 'إلغاء هذه التذكرة؟', confirm_no_show: 'تسجيل العميل كغائب؟',
      s_waiting: 'في الانتظار', s_called: 'تم الاستدعاء', s_in_service: 'قيد الخدمة', s_completed: 'مكتملة', s_cancelled: 'ملغاة', s_no_show: 'غائب', s_expired: 'منتهية',
      delayed: 'مؤجَّل',
      zero_credit: 'رصيدك صفر: الحجز الإلكتروني متوقف للعملاء الجدد. يمكنك إكمال خدمة من في الطابور. تواصل مع الإدارة لشحن رصيدك.',
      low_credit: 'رصيدك منخفض ({n} حجوزات متبقية). تواصل مع الإدارة لشحنه.',
      frozen: 'حسابك مجمّد مؤقتاً: لا يمكن استقبال حجوزات جديدة. تواصل مع الإدارة.',
      queue_closed_note: 'الطابور مغلق: لا يستطيع العملاء الحجز الآن.',
      new_booking: 'حجز جديد {code}',
      walkin_title: 'إضافة عميل حاضر', name_opt: 'الاسم (اختياري)', service: 'الخدمة', none: 'بدون تحديد', add: 'إضافة', cancel: 'إلغاء', save: 'حفظ',
      services: 'الخدمات والأسعار', add_service: 'إضافة خدمة', edit_service: 'تعديل الخدمة', name_ar: 'الاسم بالعربية', name_en: 'الاسم بالإنجليزية',
      price: 'السعر', duration: 'المدة (دقيقة)', active: 'مفعّلة', inactive: 'موقوفة', no_services: 'لم تضف أي خدمة بعد', min: 'د',
      my_page: 'رابط صفحة الحجز', copy: 'نسخ', copied: 'تم النسخ', share: 'مشاركة', print_qr: 'طباعة رمز QR',
      scan_to_book: 'امسح الرمز واحجز دورك', no_account: 'بدون تطبيق وبدون حساب',
      settings: 'إعدادات الطابور', avg_minutes: 'متوسط مدة الخدمة (دقيقة)', max_waiting: 'الحد الأقصى للمنتظرين (0 = بلا حد)',
      prefix: 'حرف التذكرة', address: 'العنوان', phone: 'هاتف المحل', description: 'وصف قصير',
      saved: 'تم الحفظ', alerts: 'تنبيه صوتي عند كل حجز جديد', logout: 'تسجيل الخروج',
      recharge_hint: 'لشحن الرصيد تواصل مع إدارة المنصة.', total: 'المجموع',
      images: 'صور صفحتك', cover: 'صورة الغلاف', logo: 'الشعار', change: 'تغيير', uploading: 'جارٍ الرفع…', img_hint: 'JPG أو PNG أو WebP — حتى 3 ميغابايت',
      maps: 'رابط الموقع على خرائط Google (اختياري)',
      change_pw: 'تغيير كلمة المرور', current_pw: 'كلمة المرور الحالية', new_pw: 'الجديدة (10 أحرف على الأقل)', pw_changed: 'تم تغيير كلمة المرور',
      error: 'حدث خطأ، حاول مرة أخرى', offline: 'لا يوجد اتصال — نحاول مجدداً…'
    },
    en: {
      tab_queue: 'Queue', tab_services: 'Services', tab_account: 'Account',
      queue: 'Queue', open: 'Open', closed: 'Closed', credits: 'Credits', available: 'available', reserved: 'reserved', consumed: 'used',
      now_serving: 'Now serving', waiting: 'Waiting', done_today: 'Done today',
      call_next: 'Call next', walkin: 'Walk-in', live_queue: 'Live queue', closed_today: 'Finished today',
      empty: 'Nobody in the queue right now', empty_hint: 'Share your page link so customers can book', nobody_waiting: 'Nobody is waiting',
      customer: 'Customer', walkin_label: 'Walk-in',
      a_call: 'Call', a_start: 'Start', a_complete: 'Complete', a_delay: 'Delay', a_no_show: 'No-show', a_cancel: 'Cancel',
      confirm_cancel: 'Cancel this ticket?', confirm_no_show: 'Mark as no-show?',
      s_waiting: 'Waiting', s_called: 'Called', s_in_service: 'In service', s_completed: 'Completed', s_cancelled: 'Cancelled', s_no_show: 'No-show', s_expired: 'Expired',
      delayed: 'Delayed',
      zero_credit: 'Zero credit: online booking is paused for new customers. You can still serve the current queue. Contact the platform to recharge.',
      low_credit: 'Low credit ({n} bookings left). Contact the platform to recharge.',
      frozen: 'Your account is temporarily frozen: no new bookings. Please contact the platform.',
      queue_closed_note: 'Queue is closed: customers cannot book right now.',
      new_booking: 'New booking {code}',
      walkin_title: 'Add walk-in customer', name_opt: 'Name (optional)', service: 'Service', none: 'Not specified', add: 'Add', cancel: 'Cancel', save: 'Save',
      services: 'Services & prices', add_service: 'Add service', edit_service: 'Edit service', name_ar: 'Name (Arabic)', name_en: 'Name (English)',
      price: 'Price', duration: 'Duration (min)', active: 'Active', inactive: 'Paused', no_services: 'No services yet', min: 'min',
      my_page: 'Your booking link', copy: 'Copy', copied: 'Copied', share: 'Share', print_qr: 'Print QR code',
      scan_to_book: 'Scan to take your number', no_account: 'No app, no account',
      settings: 'Queue settings', avg_minutes: 'Average service time (min)', max_waiting: 'Max waiting (0 = unlimited)',
      prefix: 'Ticket letter', address: 'Address', phone: 'Shop phone', description: 'Short description',
      saved: 'Saved', alerts: 'Sound alert for each new booking', logout: 'Sign out',
      recharge_hint: 'To recharge your credit, contact the platform team.', total: 'Total',
      images: 'Your page images', cover: 'Cover photo', logo: 'Logo', change: 'Change', uploading: 'Uploading…', img_hint: 'JPG, PNG or WebP — up to 3 MB',
      maps: 'Google Maps link (optional)',
      change_pw: 'Change password', current_pw: 'Current password', new_pw: 'New (10+ characters)', pw_changed: 'Password changed',
      error: 'Something went wrong, try again', offline: 'Offline — retrying…'
    }
  }[LANG];
  const t = (k, vars) => (STR[k] || k).replace(/\{(\w+)\}/g, (_, v) => (vars && vars[v] != null ? vars[v] : ''));

  // ------------------------------------------------------------------ helpers
  const $ = (sel, root = document) => root.querySelector(sel);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };
  const fmtTime = (utc) => {
    if (!utc) return '';
    const d = new Date(utc.replace(' ', 'T') + 'Z');
    return d.toLocaleTimeString(LANG === 'en' ? 'en-GB' : 'ar-DZ-u-nu-latn', { hour: '2-digit', minute: '2-digit' });
  };
  const money = (n) => { const v = Number(n); return Number.isInteger(v) ? String(v) : v.toFixed(2); };

  async function api(path, opts = {}) {
    const r = await fetch(C.api + path + (path.includes('?') ? '&' : '?') + 'lang=' + LANG, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: Object.assign({ 'X-CSRF-Token': C.nonce, 'Accept': 'application/json' }, opts.body ? { 'Content-Type': 'application/json' } : {}),
      body: opts.body ? JSON.stringify(opts.body) : undefined
    });
    let data = null;
    try { data = await r.json(); } catch (e) {}
    if (!r.ok) {
      if (data && (data.code === 'csrf' || data.code === 'unauthorized' || r.status === 401)) {
        location.reload();
      }
      const err = new Error((data && data.message) || t('error'));
      err.code = data && data.code;
      throw err;
    }
    return data;
  }

  let toastTimer;
  function toast(msg, kind = 'ok') {
    const el = $('#qpd-toast');
    el.textContent = msg;
    el.className = 'qpd-toast qpd-toast--' + kind;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.hidden = true; }, 3200);
  }

  // Short two-tone chime (Web Audio; no file to load).
  let audioCtx;
  function chime() {
    if (store.get('qpd_sound', '1') !== '1') return;
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      [[880, 0], [1320, 0.16]].forEach(([f, at]) => {
        const o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.frequency.value = f; o.type = 'sine';
        g.gain.setValueAtTime(0.0001, audioCtx.currentTime + at);
        g.gain.exponentialRampToValueAtTime(0.25, audioCtx.currentTime + at + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + at + 0.28);
        o.connect(g).connect(audioCtx.destination);
        o.start(audioCtx.currentTime + at); o.stop(audioCtx.currentTime + at + 0.3);
      });
    } catch (e) {}
    if (navigator.vibrate) navigator.vibrate(200);
  }
  // Browsers only allow sound after a user gesture: unlock on first tap.
  document.addEventListener('pointerdown', () => { try { audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)(); audioCtx.resume(); } catch (e) {} }, { once: true });

  // ------------------------------------------------------------------ state
  const S = { tab: 'queue', me: null, live: null, services: [], busy: new Set(), knownWaiting: null, showClosed: false };
  const root = $('#qpd');

  document.querySelectorAll('[data-t]').forEach((el) => { el.textContent = t(el.dataset.t); });
  document.querySelectorAll('.qpd-tabs button').forEach((b) => b.addEventListener('click', () => switchTab(b.dataset.tab)));

  function switchTab(tab) {
    S.tab = tab;
    document.querySelectorAll('.qpd-tabs button').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
    render();
    window.scrollTo(0, 0);
  }

  // ------------------------------------------------------------------ data
  async function loadAll() {
    const me = await api('');
    S.me = me;
    S.services = me.services;
    S.live = { queue_open: !!+me.provider.queue_open, status: me.provider.status, balance: me.balance, queue: me.queue };
    S.knownWaiting = new Set(me.queue.active.filter((x) => x.status === 'waiting').map((x) => x.id));
    render();
  }

  let offline = false;
  async function poll() {
    if (document.hidden || !S.live) return;
    try {
      const live = await api('/live');
      if (offline) { offline = false; $('#qpd-toast').hidden = true; }
      detectNew(live);
      S.live = live;
      if (S.tab === 'queue' && !S.busy.size) render();
    } catch (e) {
      if (!offline) { offline = true; toast(t('offline'), 'warn'); }
    }
  }
  function detectNew(live) {
    const waiting = live.queue.active.filter((x) => x.status === 'waiting');
    const fresh = waiting.filter((x) => S.knownWaiting && !S.knownWaiting.has(x.id) && !x.delayed && x.source === 'online');
    if (fresh.length) {
      chime();
      toast(t('new_booking', { code: fresh.map((x) => x.code).join('، ') }));
    }
    S.knownWaiting = new Set(waiting.map((x) => x.id));
  }
  setInterval(poll, C.poll);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

  // ------------------------------------------------------------------ actions
  async function act(fn, key) {
    if (key) { if (S.busy.has(key)) return; S.busy.add(key); render(); }
    try {
      await fn();
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      if (key) S.busy.delete(key);
      try { const live = await api('/live'); S.knownWaiting = new Set(live.queue.active.filter((x) => x.status === 'waiting').map((x) => x.id)); S.live = live; } catch (e) {}
      render();
    }
  }

  const ticketAction = (id, action) => {
    if (action === 'cancel' && !confirm(t('confirm_cancel'))) return;
    if (action === 'no_show' && !confirm(t('confirm_no_show'))) return;
    act(() => api('/tickets/' + id + '/' + action, { method: 'POST' }), 't' + id);
  };

  const ACTIONS = {
    waiting: [['call', 'primary'], ['no_show', 'ghost'], ['cancel', 'ghost-danger']],
    called: [['start', 'primary'], ['complete', 'success'], ['delay', 'ghost'], ['no_show', 'ghost'], ['cancel', 'ghost-danger']],
    in_service: [['complete', 'success'], ['cancel', 'ghost-danger']]
  };

  // ------------------------------------------------------------------ render
  function render() {
    if (!S.live) return;
    root.innerHTML = header() + (S.tab === 'queue' ? viewQueue() : S.tab === 'services' ? viewServices() : viewAccount());
    bind();
  }

  function header() {
    return `<header class="qpd-head"><div><small>${esc(t('tab_' + S.tab))}</small><h1>${esc(C.name)}</h1></div>
      <a class="qpd-head-link" href="${esc(C.publicUrl)}" target="_blank" rel="noopener" aria-label="${esc(t('my_page'))}">
      <svg viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg></a></header>`;
  }

  function banners() {
    const L = S.live, b = L.balance;
    let h = '';
    if (L.status === 'frozen') h += `<div class="qp-notice qp-notice--error">${esc(t('frozen'))}</div>`;
    if (b.available <= 0) h += `<div class="qp-notice qp-notice--error">${esc(t('zero_credit'))}</div>`;
    else if (b.low) h += `<div class="qp-notice qp-notice--warn">${esc(t('low_credit', { n: b.available }))}</div>`;
    return h ? `<div class="qpd-banners">${h}</div>` : '';
  }

  function viewQueue() {
    const L = S.live, q = L.queue, b = L.balance;
    const active = q.active;
    const serving = active.filter((x) => x.status !== 'waiting');
    const waiting = active.filter((x) => x.status === 'waiting');
    const busy = (k) => S.busy.has(k);
    return `
      ${banners()}
      <section class="qpd-row2">
        <div class="qpd-tile">
          <div class="qpd-tile-top"><span>${esc(t('queue'))}</span>
            <button class="qpd-switch" role="switch" aria-checked="${L.queue_open}" data-do="toggle" ${busy('toggle') ? 'disabled' : ''}><span></span></button></div>
          <div class="qpd-tile-val ${L.queue_open ? 'is-open' : 'is-closed'}">${esc(L.queue_open ? t('open') : t('closed'))}</div>
        </div>
        <div class="qpd-tile">
          <div class="qpd-tile-top"><span>${esc(t('credits'))}</span></div>
          <div class="qpd-tile-val ${b.available <= 0 ? 'is-zero' : b.low ? 'is-low' : ''}"><b>${b.available}</b> <small>${esc(t('available'))}</small></div>
          <div class="qpd-tile-sub">${b.reserved} ${esc(t('reserved'))} · ${b.consumed} ${esc(t('consumed'))}</div>
        </div>
      </section>
      ${!L.queue_open ? `<p class="qpd-note">${esc(t('queue_closed_note'))}</p>` : ''}

      <section class="qpd-now">
        <div class="qpd-now-main"><small>${esc(t('now_serving'))}</small><strong>${esc(q.live.current || '—')}</strong></div>
        <div class="qpd-now-stat"><small>${esc(t('waiting'))}</small><strong>${waiting.length}</strong></div>
        <div class="qpd-now-stat"><small>${esc(t('done_today'))}</small><strong>${q.counts.completed}</strong></div>
      </section>

      <section class="qpd-actions">
        <button class="qp-btn qpd-btn-call" data-do="next" ${!waiting.length || busy('next') ? 'disabled' : ''}>
          <svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>${esc(waiting.length ? t('call_next') : t('nobody_waiting'))}</button>
        <button class="qpd-btn-walkin" data-do="walkin" ${b.available <= 0 ? 'disabled' : ''}>
          <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>${esc(t('walkin'))}</button>
      </section>

      <section class="qp-section qpd-list">
        <h2 class="qp-h2">${esc(t('live_queue'))} <span class="qpd-count">${active.length}</span></h2>
        ${active.length ? [...serving, ...waiting].map(ticketCard).join('') :
          `<div class="qpd-empty"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12h8"/></svg><b>${esc(t('empty'))}</b><span>${esc(t('empty_hint'))}</span></div>`}
      </section>

      ${q.closed.length ? `<section class="qp-section qpd-list">
        <button class="qpd-collapse" data-do="closed" aria-expanded="${S.showClosed}">${esc(t('closed_today'))} <span class="qpd-count">${q.closed.length}</span></button>
        ${S.showClosed ? q.closed.map(closedRow).join('') : ''}</section>` : ''}`;
  }

  function serviceName(id) {
    const s = S.services.find((x) => +x.id === +id);
    return s ? (LANG === 'en' && s.name_en ? s.name_en : s.name_ar) : '';
  }

  function ticketCard(x) {
    const busy = S.busy.has('t' + x.id);
    const who = x.customer_name || (x.source === 'walkin' ? t('walkin_label') : t('customer'));
    const acts = (ACTIONS[x.status] || []).filter(([a]) => !(a === 'delay' && x.delayed));
    return `<article class="qpd-ticket qpd-ticket--${x.status}">
      <div class="qpd-ticket-head">
        <div class="qpd-code">${esc(x.code)}</div>
        <div class="qpd-who">
          <b>${esc(who)}</b>
          <span>${x.phone ? `<a href="tel:${esc(x.phone)}" dir="ltr">${esc(x.phone)}</a>` : ''}${x.service_id ? ` · ${esc(serviceName(x.service_id))}` : ''}</span>
        </div>
        <div class="qpd-meta">
          <span class="qp-pill qp-pill--${x.status}">${esc(t('s_' + x.status))}</span>
          <small>${x.delayed ? `<span class="qpd-delayed">${esc(t('delayed'))}</span> · ` : ''}${esc(fmtTime(x.created_at))}</small>
        </div>
      </div>
      <div class="qpd-ticket-actions">
        ${acts.map(([a, style]) => `<button class="qpd-act qpd-act--${style}" data-ticket="${x.id}" data-action="${a}" ${busy ? 'disabled' : ''}>${esc(t('a_' + a))}</button>`).join('')}
      </div>
    </article>`;
  }

  function closedRow(x) {
    return `<div class="qpd-closed"><b>${esc(x.code)}</b><span>${esc(x.customer_name || (x.source === 'walkin' ? t('walkin_label') : ''))}</span>
      <span class="qp-pill qp-pill--${x.status}">${esc(t('s_' + x.status))}</span><small>${esc(fmtTime(x.closed_at))}</small></div>`;
  }

  function viewServices() {
    const cur = S.me.currency_symbol || S.me.provider.currency_code;
    return `<section class="qp-section">
      <div class="qpd-section-head"><h2 class="qp-h2">${esc(t('services'))}</h2>
        <button class="qpd-btn-walkin qpd-btn-sm" data-do="add-service"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>${esc(t('add_service'))}</button></div>
      ${S.services.length ? S.services.map((s) => `
        <article class="qpd-service ${+s.is_active ? '' : 'is-off'}" data-service="${s.id}">
          <div><b>${esc(LANG === 'en' && s.name_en ? s.name_en : s.name_ar)}</b>
          <small>${s.duration_minutes} ${esc(t('min'))}${+s.is_active ? '' : ' · ' + esc(t('inactive'))}</small></div>
          <div class="qpd-price">${esc(money(s.price))} <small>${esc(cur)}</small></div>
          <svg class="qpd-chev" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>
        </article>`).join('') : `<div class="qpd-empty"><b>${esc(t('no_services'))}</b></div>`}
    </section>`;
  }

  function viewAccount() {
    const b = S.live.balance, p = S.me.provider;
    const total = b.available + b.reserved + b.consumed;
    return `
      ${banners()}
      <section class="qp-section"><div class="qp-card">
        <h2 class="qp-h2">${esc(t('credits'))}</h2>
        <div class="qpd-credit-grid">
          <div><strong class="${b.available <= 0 ? 'is-zero' : b.low ? 'is-low' : 'is-ok'}">${b.available}</strong><small>${esc(t('available'))}</small></div>
          <div><strong>${b.reserved}</strong><small>${esc(t('reserved'))}</small></div>
          <div><strong>${b.consumed}</strong><small>${esc(t('consumed'))}</small></div>
        </div>
        <div class="qpd-bar" role="img" aria-label="${b.available}/${total}">
          <span style="width:${total ? (b.consumed / total) * 100 : 0}%" class="c"></span><span style="width:${total ? (b.reserved / total) * 100 : 0}%" class="r"></span>
        </div>
        <p class="qp-hint" style="text-align:start">${esc(t('recharge_hint'))}</p>
      </div></section>

      <section class="qp-section"><div class="qp-card">
        <h2 class="qp-h2">${esc(t('my_page'))}</h2>
        <div class="qpd-link"><input class="qp-input" dir="ltr" readonly value="${esc(C.publicUrl)}"><button class="qpd-act qpd-act--ghost" data-do="copy">${esc(t('copy'))}</button></div>
        <div class="qpd-qr" id="qpd-qr"></div>
        <div class="qpd-two">
          <button class="qpd-btn-walkin" data-do="print">${esc(t('print_qr'))}</button>
          ${navigator.share ? `<button class="qpd-btn-walkin" data-do="share">${esc(t('share'))}</button>` : ''}
        </div>
      </div></section>

      <section class="qp-section"><div class="qp-card">
        <h2 class="qp-h2">${esc(t('images'))}</h2>
        <div class="qpd-media">
          <label class="qpd-cover" style="${p.cover_url ? `background-image:url('${esc(p.cover_url)}')` : ''}">
            <input type="file" accept="image/jpeg,image/png,image/webp" data-upload="cover" hidden>
            <span>${esc(t('cover'))} · ${esc(t('change'))}</span>
          </label>
          <label class="qpd-logo" style="${p.logo_url ? `background-image:url('${esc(p.logo_url)}')` : ''}">
            <input type="file" accept="image/jpeg,image/png,image/webp" data-upload="logo" hidden>
            <span>${esc(t('logo'))}</span>
          </label>
        </div>
        <p class="qp-hint" style="text-align:start">${esc(t('img_hint'))}</p>
      </div></section>

      <section class="qp-section"><form class="qp-card" id="qpd-settings">
        <h2 class="qp-h2">${esc(t('settings'))}</h2>
        <div class="qpd-two">
          <div class="qp-field"><label>${esc(t('avg_minutes'))}</label><input class="qp-input" name="avg_service_minutes" type="number" min="1" max="600" value="${esc(p.avg_service_minutes)}"></div>
          <div class="qp-field"><label>${esc(t('prefix'))}</label><input class="qp-input" name="ticket_prefix" maxlength="3" dir="ltr" value="${esc(p.ticket_prefix)}"></div>
        </div>
        <div class="qp-field"><label>${esc(t('max_waiting'))}</label><input class="qp-input" name="max_waiting" type="number" min="0" max="5000" value="${esc(p.max_waiting)}"></div>
        <div class="qp-field"><label>${esc(t('address'))}</label><input class="qp-input" name="address" maxlength="255" value="${esc(p.address)}"></div>
        <div class="qp-field"><label>${esc(t('phone'))}</label><input class="qp-input" name="phone" type="tel" dir="ltr" value="${esc(p.phone)}"></div>
        <div class="qp-field"><label>${esc(t('maps'))}</label><input class="qp-input" name="maps_url" type="url" dir="ltr" placeholder="https://maps.app.goo.gl/…" value="${esc(p.maps_url)}"></div>
        <div class="qp-field"><label>${esc(t('description'))}</label><textarea class="qp-input qpd-textarea" name="description_ar" maxlength="2000">${esc(p.description_ar)}</textarea></div>
        <button class="qp-btn" type="submit">${esc(t('save'))}</button>
      </form></section>

      <section class="qp-section"><div class="qp-card">
        <label class="qpd-check"><input type="checkbox" id="qpd-sound" ${store.get('qpd_sound', '1') === '1' ? 'checked' : ''}> ${esc(t('alerts'))}</label>
      </div></section>

      <section class="qp-section"><form class="qp-card" id="qpd-pw">
        <h2 class="qp-h2">${esc(t('change_pw'))}</h2>
        <div class="qp-field"><label>${esc(t('current_pw'))}</label><input class="qp-input" name="current" type="password" dir="ltr" required autocomplete="current-password"></div>
        <div class="qp-field"><label>${esc(t('new_pw'))}</label><input class="qp-input" name="new" type="password" dir="ltr" required minlength="10" autocomplete="new-password"></div>
        <button class="qpd-btn-walkin" type="submit" style="width:100%">${esc(t('save'))}</button>
      </form></section>

      <section class="qp-section"><a class="qp-btn qp-btn--ghost-danger" href="${esc(C.logoutUrl)}">${esc(t('logout'))}</a></section>`;
  }

  // ------------------------------------------------------------------ events
  function bind() {
    root.querySelectorAll('[data-ticket]').forEach((b) => b.addEventListener('click', () => ticketAction(b.dataset.ticket, b.dataset.action)));
    root.querySelectorAll('[data-do]').forEach((b) => b.addEventListener('click', () => handlers[b.dataset.do](b)));
    root.querySelectorAll('[data-service]').forEach((el) => el.addEventListener('click', () => serviceDialog(S.services.find((s) => +s.id === +el.dataset.service))));
    const pw = $('#qpd-pw', root);
    if (pw) pw.addEventListener('submit', async (e) => {
      e.preventDefault();
      try { await api('/password', { method: 'POST', body: Object.fromEntries(new FormData(pw)) }); pw.reset(); toast(t('pw_changed')); }
      catch (x) { toast(x.message, 'error'); }
    });
    const settings = $('#qpd-settings', root);
    if (settings) settings.addEventListener('submit', saveSettings);
    const sound = $('#qpd-sound', root);
    if (sound) sound.addEventListener('change', () => { store.set('qpd_sound', sound.checked ? '1' : '0'); if (sound.checked) chime(); });
    root.querySelectorAll('[data-upload]').forEach((inp) => inp.addEventListener('change', () => upload(inp)));
    const qr = $('#qpd-qr', root);
    if (qr) qr.innerHTML = qrSvg(C.publicUrl, 5);
  }

  const handlers = {
    toggle: () => act(() => api('/queue', { method: 'POST', body: { open: !S.live.queue_open } }), 'toggle'),
    next: () => act(async () => { const r = await api('/call-next', { method: 'POST' }); if (r.ticket) toast(t('a_call') + ' ' + r.ticket.code); }, 'next'),
    walkin: () => walkinDialog(),
    closed: () => { S.showClosed = !S.showClosed; render(); },
    'add-service': () => serviceDialog(null),
    copy: async () => {
      try { await navigator.clipboard.writeText(C.publicUrl); } catch (e) { const i = $('.qpd-link input', root); i.select(); document.execCommand('copy'); }
      toast(t('copied'));
    },
    share: () => navigator.share({ title: C.name, url: C.publicUrl }).catch(() => {}),
    print: () => printPoster()
  };

  // ------------------------------------------------------------------ dialogs
  const dialog = $('#qpd-dialog'), dform = $('#qpd-dialog-form');
  function openDialog(html, onSubmit) {
    dform.innerHTML = html + `<div class="qpd-two qpd-dialog-actions"><button type="button" class="qpd-btn-walkin" value="cancel">${esc(t('cancel'))}</button><button class="qp-btn" value="ok">${esc(t('save'))}</button></div>`;
    dform.querySelector('[value=cancel]').addEventListener('click', () => dialog.close());
    dform.onsubmit = async (ev) => {
      ev.preventDefault();
      const btn = dform.querySelector('[value=ok]');
      btn.disabled = true;
      try { await onSubmit(Object.fromEntries(new FormData(dform))); dialog.close(); }
      catch (e) { toast(e.message, 'error'); }
      finally { btn.disabled = false; }
    };
    if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
    const first = dform.querySelector('input'); if (first) first.focus();
  }

  function serviceOptions() {
    return `<option value="">${esc(t('none'))}</option>` + S.services.filter((s) => +s.is_active)
      .map((s) => `<option value="${s.id}">${esc(LANG === 'en' && s.name_en ? s.name_en : s.name_ar)}</option>`).join('');
  }

  function walkinDialog() {
    openDialog(`<h2 class="qp-h2">${esc(t('walkin_title'))}</h2>
      <div class="qp-field"><label>${esc(t('name_opt'))}</label><input class="qp-input" name="name" maxlength="80"></div>
      ${S.services.some((s) => +s.is_active) ? `<div class="qp-field"><label>${esc(t('service'))}</label><select class="qp-input" name="service_id">${serviceOptions()}</select></div>` : ''}`,
    async (d) => {
      const r = await api('/walkin', { method: 'POST', body: { name: d.name, service_id: d.service_id ? +d.service_id : 0 } });
      toast(t('walkin') + ' ' + r.ticket.code);
      act(async () => {});
    });
  }

  function serviceDialog(s) {
    openDialog(`<h2 class="qp-h2">${esc(s ? t('edit_service') : t('add_service'))}</h2>
      <div class="qp-field"><label>${esc(t('name_ar'))}</label><input class="qp-input" name="name_ar" required maxlength="120" value="${esc(s ? s.name_ar : '')}"></div>
      <div class="qp-field"><label>${esc(t('name_en'))}</label><input class="qp-input" name="name_en" dir="ltr" maxlength="120" value="${esc(s ? s.name_en : '')}"></div>
      <div class="qpd-two">
        <div class="qp-field"><label>${esc(t('price'))}</label><input class="qp-input" name="price" type="number" min="0" step="0.01" inputmode="decimal" value="${esc(s ? money(s.price) : '')}"></div>
        <div class="qp-field"><label>${esc(t('duration'))}</label><input class="qp-input" name="duration_minutes" type="number" min="1" max="600" value="${esc(s ? s.duration_minutes : 15)}"></div>
      </div>
      <label class="qpd-check"><input type="checkbox" name="is_active" value="1" ${!s || +s.is_active ? 'checked' : ''}> ${esc(t('active'))}</label>`,
    async (d) => {
      const body = { name_ar: d.name_ar, name_en: d.name_en, price: +d.price || 0, duration_minutes: +d.duration_minutes || 15, is_active: d.is_active ? 1 : 0, sort_order: s ? +s.sort_order : S.services.length };
      await api('/services' + (s ? '/' + s.id : ''), { method: 'POST', body });
      S.services = await api('/services');
      toast(t('saved'));
      render();
    });
  }

  async function saveSettings(ev) {
    ev.preventDefault();
    const d = Object.fromEntries(new FormData(ev.target));
    const btn = ev.target.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      S.me.provider = await api('/profile', { method: 'POST', body: d });
      toast(t('saved'));
      render();
    } catch (e) { toast(e.message, 'error'); btn.disabled = false; }
  }

  async function upload(inp) {
    const f = inp.files && inp.files[0];
    if (!f) return;
    toast(t('uploading'), 'warn');
    const fd = new FormData();
    fd.append('file', f);
    fd.append('kind', inp.dataset.upload);
    try {
      const r = await fetch(C.api + '/media?lang=' + LANG, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': C.nonce }, body: fd });
      const d = await r.json();
      if (!r.ok) throw new Error(d.message || t('error'));
      S.me.provider[d.kind] = d.url;
      toast(t('saved'));
      render();
    } catch (e) { toast(e.message, 'error'); }
  }

  // ------------------------------------------------------------------ QR & poster
  function qrSvg(text, cell) {
    if (typeof qrcode !== 'function') return '';
    const qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    return qr.createSvgTag({ cellSize: cell, margin: 2, scalable: true });
  }

  function printPoster() {
    const el = $('#qpd-print');
    el.innerHTML = `<div class="qpd-poster">
      <div class="qpd-poster-brand">QueuePro</div>
      <h1>${esc(C.name)}</h1>
      <p class="qpd-poster-lead">${esc(STR.scan_to_book)}</p>
      <div class="qpd-poster-qr">${qrSvg(C.publicUrl, 10)}</div>
      <p class="qpd-poster-url" dir="ltr">${esc(C.publicUrl.replace(/^https?:\/\//, ''))}</p>
      <p class="qpd-poster-foot">${esc(STR.no_account)}</p></div>`;
    setTimeout(() => window.print(), 50);
  }

  // ------------------------------------------------------------------ go
  loadAll().catch((e) => { root.innerHTML = `<div class="qp-section"><div class="qp-notice qp-notice--error">${esc(e.message)}</div></div>`; });
})();
