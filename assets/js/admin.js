/* QueuePro — Super Admin dashboard (vanilla JS, hash routing, talks to /api/admin/*). */
(() => {
  'use strict';
  const C = window.QPA;
  const AR = C.lang !== 'en';

  // ================================================================== strings
  const S = AR ? {
    nav_home: 'لوحة التحكم', nav_providers: 'المزوّدون', nav_tickets: 'الحجوزات', nav_packages: 'الباقات', nav_ratings: 'التقييمات',
    nav_messages: 'الرسائل', nav_audit: 'سجل العمليات', nav_settings: 'الإعدادات', view_site: 'عرض الموقع', logout: 'تسجيل الخروج',
    providers: 'المزوّدون', active: 'نشط', frozen: 'مجمّد', blocked: 'محظور', open_now: 'مفتوحة الآن', active_bookings: 'الحجوزات النشطة',
    completed: 'الخدمات المكتملة', sold: 'Credits المباعة', consumed: 'Credits المستهلكة', revenue: 'إيرادات الباقات', low_credit: 'مزوّدون برصيد منخفض',
    chart_title: 'الخدمات المكتملة — آخر 30 يوماً', latest: 'آخر العمليات', all: 'الكل', search: 'بحث', new_provider: 'مزوّد جديد',
    name: 'الاسم', status: 'الحالة', queue: 'الطابور', available: 'المتاح', reserved: 'محجوز', actions: '', open: 'مفتوح', closed: 'مغلق',
    manage: 'إدارة', back: 'رجوع', public_page: 'الصفحة العامة', credits: 'الرصيد', add_credits: 'إضافة رصيد', package: 'الباقة',
    payment: 'طريقة الدفع', note: 'ملاحظة', cash: 'نقداً', baridimob: 'بريدي موب', ccp: 'CCP', bank: 'تحويل بنكي', other: 'أخرى',
    adjust: 'تعديل يدوي', amount: 'الكمية (+ أو −)', reason: 'السبب (إلزامي)', apply: 'تطبيق', ledger: 'سجل الرصيد (Ledger)', type: 'النوع',
    after: 'المتاح بعدها', time: 'الوقت', by: 'بواسطة', login: 'حساب الدخول', reset_pw: 'إنشاء كلمة مرور جديدة', no_login: 'لا يوجد حساب دخول مرتبط',
    profile: 'بيانات المزوّد', save: 'حفظ', saved: 'تم الحفظ', today_queue: 'طابور اليوم', none: 'لا يوجد', freeze: 'تجميد', block: 'حظر',
    activate: 'تفعيل', confirm_status: 'تأكيد تغيير حالة المزوّد إلى: {s}؟', name_ar: 'الاسم بالعربية', name_en: 'الاسم بالإنجليزية',
    slug: 'الرابط (حروف إنجليزية صغيرة وأرقام و -)', category: 'التصنيف', city: 'المدينة', address: 'العنوان', phone: 'الهاتف',
    email: 'بريد المزوّد (لإنشاء حساب دخول)', timezone: 'المنطقة الزمنية', open_queue: 'فتح الطابور مباشرة', create: 'إنشاء', cancel: 'إلغاء',
    creds_title: 'بيانات دخول المزوّد', creds_hint: 'انسخها وسلّمها للمزوّد الآن — لن تظهر مرة أخرى.', copy: 'نسخ', copied: 'تم النسخ', close: 'إغلاق',
    url: 'رابط اللوحة', username: 'اسم الدخول', password: 'كلمة المرور', created: 'تم إنشاء المزوّد مع {n} حجوزات مجانية',
    tickets: 'الحجوزات', provider: 'المزوّد', number: 'الرقم', customer: 'العميل', date: 'التاريخ', credit: 'الرصيد', source: 'المصدر',
    walkin: 'حاضر', online: 'إلكتروني', more: 'المزيد', packages: 'الباقات', credits_n: 'عدد الحجوزات', price: 'السعر', edit: 'تعديل',
    add: 'إضافة', ratings: 'التقييمات', stars: 'النجوم', comment: 'التعليق', visible: 'ظاهر', hidden: 'مخفي', hide: 'إخفاء', show: 'إظهار',
    messages: 'الرسائل', to: 'إلى', body: 'النص', sent: 'أُرسلت', pending: 'في الانتظار', failed: 'فشلت', sending: 'جارٍ الإرسال', test_sms: 'إرسال رسالة تجريبية',
    test_ok: 'تم الإرسال بنجاح', log_mode: 'وضع السجل: الرسائل لا تُرسل فعلياً حتى تضبط بوابة SMS في الإعدادات.', audit: 'سجل العمليات', action: 'الإجراء',
    target: 'الهدف', user: 'المستخدم', details: 'التفاصيل', settings: 'الإعدادات',
    g_credit: 'سياسة الرصيد', g_abuse: 'الحماية من الإساءة', g_notify: 'التحقق والإشعارات', g_sms: 'بوابة الرسائل (SMS / واتساب)', g_brand: 'المنصة',
    s_free_grant_credits: 'الحجوزات المجانية لكل مزوّد جديد', s_low_credit_threshold: 'تنبيه الرصيد المنخفض عند', s_consume_on_no_show: 'خصم حجز عند الغياب (No-show)',
    s_consume_on_cancel: 'خصم حجز عند إلغاء المزوّد', s_max_joins_per_ip_window: 'حجوزات من نفس الشبكة (Wi-Fi) قبل طلب رمز SMS', s_max_joins_per_ip_hard: 'الحد الأقصى من نفس الشبكة', s_max_joins_per_device_window: 'أقصى حجوزات من نفس الهاتف', s_ip_window_minutes: 'خلال (دقيقة)',
    s_max_joins_per_phone_day: 'أقصى حجوزات لنفس الهاتف يومياً', s_poll_interval_seconds: 'تحديث صفحة العميل كل (ثانية، 5–10)', s_require_otp: 'إلزام العميل برمز تحقق SMS عند الحجز',
    s_notify_on_join: 'إرسال رابط التذكرة بالـ SMS بعد الحجز', s_notify_on_call: 'رسالة "حان دورك" عند الاستدعاء', s_notify_near_ahead: 'رسالة "اقترب دورك" عندما يبقى أمامه (0 = إيقاف)',
    s_otp_ttl_minutes: 'صلاحية رمز التحقق (دقيقة)', s_rating_window_days: 'مدة السماح بالتقييم (يوم)', s_take_homepage: 'استخدام الصفحة الرئيسية للمنصة بدل القالب',
    s_sms_driver: 'مزوّد الرسائل', s_sms_channel: 'القناة', s_sms_sender: 'اسم المرسل', s_sms_http_url: 'رابط API (HTTPS)', s_sms_http_method: 'طريقة الطلب',
    s_sms_http_body: 'جسم الطلب', s_sms_http_headers: 'الترويسات (سطر لكل واحدة)', s_sms_http_secret: 'مفتاح API', s_twilio_sid: 'Twilio Account SID',
    s_twilio_from: 'Twilio رقم المرسل', s_twilio_secret: 'Twilio Auth Token', s_brand_name: 'اسم المنصة', s_support_phone: 'هاتف الدعم (يظهر للزوار)',
    drv_log: 'سجل فقط (للتجربة)', drv_http: 'بوابة HTTP عامة', drv_twilio: 'Twilio', ch_sms: 'SMS', ch_whatsapp: 'واتساب (Twilio)',
    http_help: 'المتغيرات المتاحة: {to} {to_plain} {to_local} {message} {sender} {secret}', yes: 'نعم', no: 'لا', error: 'حدث خطأ',
    services: 'الخدمات', login_url: 'رابط دخول المزوّد', waiting: 'منتظر', counts: 'إحصاءات اليوم', public_link: 'رابط الحجز',
    filter_phone: 'رقم الهاتف', empty: 'لا توجد بيانات',
    username_opt: 'اسم مستخدم الدخول (اختياري — الافتراضي هو الرابط)', create_login: 'إنشاء حساب دخول للمزوّد', create_login_btn: 'إنشاء حساب دخول',
    system: 'حالة النظام', backup: 'تنزيل نسخة احتياطية', backup_hint: 'احتفظ بنسخة دورية على حاسوبك. SQLite: للاسترجاع استبدل ملف storage/database.sqlite بالنسخة. MySQL / ووردبريس: النسخة ملف JSON للأرشفة، واعتمد أيضاً على النسخ الاحتياطي لقاعدة البيانات من الاستضافة أو إضافة نسخ ووردبريس.',
    change_pw: 'تغيير كلمة مروري', current_pw: 'كلمة المرور الحالية', new_pw: 'كلمة المرور الجديدة (10 أحرف على الأقل)', pw_changed: 'تم تغيير كلمة المرور',
    h_version: 'الإصدار', h_php: 'PHP', h_db: 'قاعدة البيانات', h_size: 'حجم القاعدة', h_storage: 'حماية مجلد storage', h_writable: 'صلاحيات الكتابة',
    h_https: 'اتصال آمن HTTPS', h_maint: 'آخر صيانة تلقائية', h_pending: 'رسائل في الانتظار', h_failed: 'رسائل فشلت', h_books: 'توازن حسابات الرصيد',
    h_ext: 'الإضافات', ok: 'سليم', bad: 'مشكلة!', unknown: 'تعذّر الفحص', storage_bad: 'خطر: مجلد storage مكشوف للعامة! راجع إعدادات الخادم (README).',
    never: 'لم تعمل بعد', downloading: 'جارٍ تجهيز النسخة…', https_off: 'غير مفعّل — فعّل شهادة SSL المجانية من لوحة الاستضافة', ledger_types: { free_grant: 'منحة مجانية', purchased: 'شراء باقة', reserved: 'حجز', released: 'تحرير', consumed: 'استهلاك', adjustment: 'تعديل' },
    st: { waiting: 'في الانتظار', called: 'تم الاستدعاء', in_service: 'قيد الخدمة', completed: 'مكتملة', cancelled: 'ملغاة', no_show: 'غائب', expired: 'منتهية' },
    adm_actions: { 'provider.create': 'إنشاء مزوّد', 'provider.update': 'تعديل مزوّد', 'provider.status': 'تغيير حالة مزوّد', 'provider.password_reset': 'كلمة مرور جديدة',
      'credits.free_grant': 'منحة مجانية', 'credits.purchased': 'إضافة باقة', 'credits.adjustment': 'تعديل رصيد', 'settings.update': 'تعديل إعداد', 'package.save': 'حفظ باقة',
      'rating.hide': 'إخفاء تقييم', 'rating.show': 'إظهار تقييم', 'sms.test': 'رسالة تجريبية' }
  } : {
    nav_home: 'Dashboard', nav_providers: 'Providers', nav_tickets: 'Bookings', nav_packages: 'Packages', nav_ratings: 'Reviews',
    nav_messages: 'Messages', nav_audit: 'Audit log', nav_settings: 'Settings', view_site: 'View site', logout: 'Sign out',
    providers: 'Providers', active: 'Active', frozen: 'Frozen', blocked: 'Blocked', open_now: 'Open now', active_bookings: 'Active bookings',
    completed: 'Completed services', sold: 'Credits sold', consumed: 'Credits used', revenue: 'Package revenue', low_credit: 'Low-credit providers',
    chart_title: 'Completed services — last 30 days', latest: 'Latest operations', all: 'All', search: 'Search', new_provider: 'New provider',
    name: 'Name', status: 'Status', queue: 'Queue', available: 'Available', reserved: 'Reserved', actions: '', open: 'Open', closed: 'Closed',
    manage: 'Manage', back: 'Back', public_page: 'Public page', credits: 'Credits', add_credits: 'Add credits', package: 'Package',
    payment: 'Payment method', note: 'Note', cash: 'Cash', baridimob: 'BaridiMob', ccp: 'CCP', bank: 'Bank transfer', other: 'Other',
    adjust: 'Manual adjustment', amount: 'Amount (+ or −)', reason: 'Reason (required)', apply: 'Apply', ledger: 'Credit ledger', type: 'Type',
    after: 'Available after', time: 'Time', by: 'By', login: 'Login account', reset_pw: 'Generate new password', no_login: 'No login account linked',
    profile: 'Provider details', save: 'Save', saved: 'Saved', today_queue: "Today's queue", none: 'None', freeze: 'Freeze', block: 'Block',
    activate: 'Activate', confirm_status: 'Change provider status to: {s}?', name_ar: 'Name (Arabic)', name_en: 'Name (English)',
    slug: 'Link (lowercase letters, digits, -)', category: 'Category', city: 'City', address: 'Address', phone: 'Phone',
    email: 'Provider email (creates a login)', timezone: 'Time zone', open_queue: 'Open the queue now', create: 'Create', cancel: 'Cancel',
    creds_title: 'Provider login details', creds_hint: 'Copy and hand these over now — they will not be shown again.', copy: 'Copy', copied: 'Copied', close: 'Close',
    url: 'Dashboard URL', username: 'Username', password: 'Password', created: 'Provider created with {n} free bookings',
    tickets: 'Bookings', provider: 'Provider', number: 'Number', customer: 'Customer', date: 'Date', credit: 'Credit', source: 'Source',
    walkin: 'Walk-in', online: 'Online', more: 'More', packages: 'Packages', credits_n: 'Bookings', price: 'Price', edit: 'Edit',
    add: 'Add', ratings: 'Reviews', stars: 'Stars', comment: 'Comment', visible: 'Visible', hidden: 'Hidden', hide: 'Hide', show: 'Show',
    messages: 'Messages', to: 'To', body: 'Text', sent: 'Sent', pending: 'Pending', failed: 'Failed', sending: 'Sending', test_sms: 'Send a test message',
    test_ok: 'Sent successfully', log_mode: 'Log mode: messages are not really sent until you configure an SMS gateway in Settings.', audit: 'Audit log', action: 'Action',
    target: 'Target', user: 'User', details: 'Details', settings: 'Settings',
    g_credit: 'Credit policy', g_abuse: 'Abuse protection', g_notify: 'Verification & notifications', g_sms: 'Messaging gateway (SMS / WhatsApp)', g_brand: 'Platform',
    s_free_grant_credits: 'Free bookings for each new provider', s_low_credit_threshold: 'Low-credit alert at', s_consume_on_no_show: 'Charge a credit on no-show',
    s_consume_on_cancel: 'Charge a credit when the provider cancels', s_max_joins_per_ip_window: 'Bookings from one network (Wi-Fi) before an SMS code is asked', s_max_joins_per_ip_hard: 'Hard limit per network', s_max_joins_per_device_window: 'Max bookings per phone / browser', s_ip_window_minutes: 'within (minutes)',
    s_max_joins_per_phone_day: 'Max bookings per phone per day', s_poll_interval_seconds: 'Customer page refresh (seconds, 5–10)', s_require_otp: 'Require an SMS code to book',
    s_notify_on_join: 'Text the ticket link after booking', s_notify_on_call: '"Your turn" message on call', s_notify_near_ahead: '"Almost your turn" when this many are left (0 = off)',
    s_otp_ttl_minutes: 'Code validity (minutes)', s_rating_window_days: 'Rating window (days)', s_take_homepage: 'Use the platform home instead of the theme',
    s_sms_driver: 'Messaging provider', s_sms_channel: 'Channel', s_sms_sender: 'Sender name', s_sms_http_url: 'API URL (HTTPS)', s_sms_http_method: 'Method',
    s_sms_http_body: 'Request body', s_sms_http_headers: 'Headers (one per line)', s_sms_http_secret: 'API key', s_twilio_sid: 'Twilio Account SID',
    s_twilio_from: 'Twilio sender number', s_twilio_secret: 'Twilio Auth Token', s_brand_name: 'Platform name', s_support_phone: 'Support phone (public)',
    drv_log: 'Log only (testing)', drv_http: 'Generic HTTP gateway', drv_twilio: 'Twilio', ch_sms: 'SMS', ch_whatsapp: 'WhatsApp (Twilio)',
    http_help: 'Placeholders: {to} {to_plain} {to_local} {message} {sender} {secret}', yes: 'Yes', no: 'No', error: 'Something went wrong',
    services: 'Services', login_url: 'Provider login URL', waiting: 'waiting', counts: "Today's numbers", public_link: 'Booking link',
    filter_phone: 'Phone number', empty: 'No data',
    username_opt: 'Login username (optional — defaults to the link)', create_login: 'Create a login for the provider', create_login_btn: 'Create login',
    system: 'System status', backup: 'Download a backup', backup_hint: 'Keep a regular copy on your computer. SQLite: to restore, replace storage/database.sqlite with the backup. MySQL / WordPress: the backup is a JSON archive; also keep regular database backups from your hosting or a WordPress backup plugin.',
    change_pw: 'Change my password', current_pw: 'Current password', new_pw: 'New password (10+ characters)', pw_changed: 'Password changed',
    h_version: 'Version', h_php: 'PHP', h_db: 'Database', h_size: 'Database size', h_storage: 'storage/ folder protected', h_writable: 'Write permissions',
    h_https: 'Secure HTTPS', h_maint: 'Last automatic maintenance', h_pending: 'Pending messages', h_failed: 'Failed messages', h_books: 'Credit books balanced',
    h_ext: 'Extensions', ok: 'OK', bad: 'Problem!', unknown: 'Could not check', storage_bad: 'Danger: the storage folder is publicly reachable! See README server setup.',
    never: 'Not yet', downloading: 'Preparing the backup…', https_off: 'Off — enable the free SSL certificate in your hosting panel', ledger_types: { free_grant: 'Free grant', purchased: 'Package purchase', reserved: 'Reserved', released: 'Released', consumed: 'Consumed', adjustment: 'Adjustment' },
    st: { waiting: 'Waiting', called: 'Called', in_service: 'In service', completed: 'Completed', cancelled: 'Cancelled', no_show: 'No-show', expired: 'Expired' },
    adm_actions: {}
  };
  const t = (k, v) => String(S[k] ?? k).replace(/\{(\w+)\}/g, (_, x) => (v && v[x] != null ? v[x] : '{' + x + '}'));

  // ================================================================== helpers
  const $ = (s, r = document) => r.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = (n) => Number(n || 0).toLocaleString(AR ? 'ar-DZ-u-nu-latn' : 'en-US');
  const when = (utc) => { if (!utc) return ''; const d = new Date(utc.replace(' ', 'T') + 'Z'); return d.toLocaleString(AR ? 'ar-DZ-u-nu-latn' : 'en-GB', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); };
  const code = (prefix, n) => (prefix || 'A') + String(n).padStart(3, '0');
  const view = $('#qpa-view');
  let META = null;

  async function api(path, opts = {}) {
    const r = await fetch(C.api + path + (path.includes('?') ? '&' : '?') + 'lang=' + C.lang, {
      method: opts.method || 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: Object.assign({ 'X-CSRF-Token': C.nonce, Accept: 'application/json' }, opts.body ? { 'Content-Type': 'application/json' } : {}),
      body: opts.body ? JSON.stringify(opts.body) : undefined
    });
    let d = null; try { d = await r.json(); } catch (e) {}
    if (!r.ok) {
      if (r.status === 401 || (d && d.code === 'csrf')) location.reload();
      throw new Error((d && d.message) || t('error'));
    }
    return d;
  }
  let toastT;
  function toast(m, kind = 'ok') { const el = $('#qpa-toast'); el.textContent = m; el.className = 'qpd-toast qpd-toast--' + kind; el.hidden = false; clearTimeout(toastT); toastT = setTimeout(() => (el.hidden = true), 3500); }
  const meta = async () => (META = META || (await api('/meta')));
  const pill = (st) => `<span class="qp-pill qp-pill--${esc(st)}">${esc(S.st[st] || st)}</span>`;
  const statusBadge = (s) => `<span class="qpa-status qpa-status--${esc(s)}">${esc(t(s))}</span>`;
  const empty = (cols) => `<tr><td colspan="${cols}" class="qpa-empty">${esc(t('empty'))}</td></tr>`;

  // dialog
  const dlg = $('#qpa-dialog'), dform = $('#qpa-dialog-form');
  function modal(html, onSubmit, okLabel) {
    dform.innerHTML = html + (onSubmit ? `<div class="qpa-dialog-actions"><button type="button" class="qpa-btn qpa-btn--ghost" data-x>${esc(t('cancel'))}</button><button class="qpa-btn" value="ok">${esc(okLabel || t('save'))}</button></div>`
      : `<div class="qpa-dialog-actions"><button type="button" class="qpa-btn" data-x>${esc(t('close'))}</button></div>`);
    dform.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', () => dlg.close()));
    dform.onsubmit = async (ev) => {
      ev.preventDefault();
      if (!onSubmit) return dlg.close();
      const b = dform.querySelector('[value=ok]'); b.disabled = true;
      try { const keep = await onSubmit(Object.fromEntries(new FormData(dform)), dform); if (keep !== true) dlg.close(); }
      catch (e) { toast(e.message, 'error'); }
      finally { b.disabled = false; }
    };
    dlg.showModal ? dlg.showModal() : dlg.setAttribute('open', '');
  }
  const field = (label, input) => `<label class="qpa-field"><span>${esc(label)}</span>${input}</label>`;
  const input = (name, val = '', attrs = '') => `<input class="qp-input" name="${name}" value="${esc(val)}" ${attrs}>`;
  const select = (name, opts, val) => `<select class="qp-input" name="${name}">${opts.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(val) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
  const nm = (row) => (!AR && row.name_en ? row.name_en : row.name_ar);

  function credsModal(c) {
    const rows = [[t('url'), c.url], [t('username'), c.login], [t('password'), c.password]];
    modal(`<h2>${esc(t('creds_title'))}</h2><p class="qpa-muted">${esc(t('creds_hint'))}</p>
      <div class="qpa-creds">${rows.map(([k, v]) => `<div><small>${esc(k)}</small><code dir="ltr">${esc(v)}</code></div>`).join('')}</div>
      <button type="button" class="qpa-btn qpa-btn--ghost" id="qpa-copy-creds">${esc(t('copy'))}</button>`);
    $('#qpa-copy-creds').addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(rows.map(([k, v]) => k + ': ' + v).join('\n')); toast(t('copied')); } catch (e) {}
    });
  }

  // ================================================================== router
  const routes = { '': home, providers, provider, tickets, packages, ratings, messages, audit, settings };
  document.querySelectorAll('[data-t]').forEach((el) => (el.textContent = t(el.dataset.t)));
  async function route() {
    const [name, arg] = location.hash.replace(/^#\/?/, '').split('/');
    const fn = routes[name] || home;
    document.body.classList.remove('qpa-open');
    document.querySelectorAll('.qpa-nav a').forEach((a) => a.classList.toggle('is-active', a.dataset.r === (name === 'provider' ? 'providers' : name || 'home')));
    view.innerHTML = '<div class="qpd-loading"><span class="qp-live-dot"></span></div>';
    try { await fn(arg); } catch (e) { view.innerHTML = `<div class="qp-notice qp-notice--error">${esc(e.message)}</div>`; }
    window.scrollTo(0, 0);
  }
  const title = (s) => { $('#qpa-title').textContent = s; document.title = s + ' — ' + C.brand; };
  window.addEventListener('hashchange', route);

  // ================================================================== dashboard
  async function home() {
    title(t('nav_home'));
    const [st, audit] = await Promise.all([api('/stats?days=30'), api('/audit?limit=8')]);
    const o = st.overview;
    const kpis = [
      [t('providers'), o.providers.total, `${o.providers.active} ${t('active')} · ${o.providers.open_now} ${t('open_now')}`, 'store'],
      [t('active_bookings'), o.active_bookings, '', 'ticket'],
      [t('completed'), o.completed_total, '', 'check'],
      [t('sold'), o.credits.purchased, `${num(o.credits.revenue)} ${AR ? 'دج' : 'DZD'}`, 'grid'],
      [t('consumed'), o.credits.consumed, `${o.credits.reserved} ${t('reserved')}`, 'chart'],
      [t('low_credit'), o.low_credit_providers, '', 'bell']
    ];
    view.innerHTML = `
      <section class="qpa-kpis">${kpis.map(([l, v, sub], i) => `<div class="qpa-kpi${i === 5 && v > 0 ? ' qpa-kpi--warn' : ''}"><small>${esc(l)}</small><strong>${num(v)}</strong>${sub ? `<span>${esc(sub)}</span>` : ''}</div>`).join('')}</section>
      <section class="qpa-grid2">
        <div class="qpa-card"><h2>${esc(t('chart_title'))}</h2>${lineChart(st.series)}</div>
        <div class="qpa-card"><h2>${esc(t('latest'))}</h2><ul class="qpa-feed">${audit.map(feedItem).join('') || `<li class="qpa-empty">${esc(t('empty'))}</li>`}</ul>
          <a href="#/audit" class="qpa-more">${esc(t('nav_audit'))} ${AR ? '←' : '→'}</a></div>
      </section>`;
    bindChart();
  }
  function feedItem(a) {
    let d = {}; try { d = JSON.parse(a.details || '{}') || {}; } catch (e) {}
    const extra = d.amount != null ? ` (${d.amount > 0 ? '+' : ''}${d.amount})` : d.to ? ` → ${t(d.to)}` : d.key ? ` · ${d.key}` : d.slug ? ` · ${d.slug}` : '';
    return `<li><span class="qpa-feed-dot"></span><div><b>${esc((S.adm_actions[a.action] || a.action) + extra)}</b><small>${esc(a.target_type)} #${esc(a.target_id || '—')} · ${esc(when(a.created_at))}</small></div></li>`;
  }

  // Single-series line chart (inline SVG): brand green 2px line, soft area, recessive grid, crosshair tooltip.
  function lineChart(series) {
    const W = 640, H = 220, P = { l: 34, r: 12, t: 12, b: 26 };
    const vals = series.map((d) => d.completed), max = Math.max(4, ...vals);
    const step = Math.pow(10, Math.floor(Math.log10(max))), top = Math.ceil(max / step) * step;
    const x = (i) => P.l + (i * (W - P.l - P.r)) / Math.max(1, series.length - 1);
    const y = (v) => H - P.b - (v / top) * (H - P.t - P.b);
    const pts = series.map((d, i) => `${x(i).toFixed(1)},${y(d.completed).toFixed(1)}`).join(' ');
    const grid = [0, .25, .5, .75, 1].map((f) => { const v = Math.round(top * f); return `<line x1="${P.l}" x2="${W - P.r}" y1="${y(v)}" y2="${y(v)}" class="qpa-grid"/><text x="${P.l - 6}" y="${y(v) + 4}" class="qpa-axis" text-anchor="end">${v}</text>`; }).join('');
    const ticks = series.map((d, i) => (i % 7 === 0 || i === series.length - 1) ? `<text x="${x(i)}" y="${H - 6}" class="qpa-axis" text-anchor="middle">${d.date.slice(5).replace('-', '/')}</text>` : '').join('');
    const total = vals.reduce((a, b) => a + b, 0);
    return `<div class="qpa-chart" data-series='${esc(JSON.stringify(series))}'>
      <svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(t('chart_title'))}: ${total}" direction="ltr">
        ${grid}${ticks}
        <polygon points="${P.l},${H - P.b} ${pts} ${x(series.length - 1)},${H - P.b}" class="qpa-area"/>
        <polyline points="${pts}" class="qpa-line"/>
        <line class="qpa-cross" y1="${P.t}" y2="${H - P.b}" x1="-10" x2="-10"/>
        <circle class="qpa-dot" r="4.5" cx="-10" cy="-10"/>
        <rect class="qpa-hit" x="${P.l}" y="${P.t}" width="${W - P.l - P.r}" height="${H - P.t - P.b}"/>
      </svg><div class="qpa-tip" hidden></div></div>`;
  }
  function bindChart() {
    const box = $('.qpa-chart'); if (!box) return;
    const series = JSON.parse(box.dataset.series), svg = box.querySelector('svg'), tip = box.querySelector('.qpa-tip');
    const vb = svg.viewBox.baseVal, P = { l: 34, r: 12, t: 12, b: 26 };
    const max = Math.max(4, ...series.map((d) => d.completed)), step = Math.pow(10, Math.floor(Math.log10(max))), top = Math.ceil(max / step) * step;
    svg.querySelector('.qpa-hit').addEventListener('pointermove', (e) => {
      const r = svg.getBoundingClientRect(), sx = ((e.clientX - r.left) / r.width) * vb.width;
      const i = Math.max(0, Math.min(series.length - 1, Math.round(((sx - P.l) / (vb.width - P.l - P.r)) * (series.length - 1))));
      const cx = P.l + (i * (vb.width - P.l - P.r)) / (series.length - 1), cy = vb.height - P.b - (series[i].completed / top) * (vb.height - P.t - P.b);
      svg.querySelector('.qpa-cross').setAttribute('x1', cx); svg.querySelector('.qpa-cross').setAttribute('x2', cx);
      svg.querySelector('.qpa-dot').setAttribute('cx', cx); svg.querySelector('.qpa-dot').setAttribute('cy', cy);
      tip.hidden = false; tip.innerHTML = `<small>${esc(series[i].date)}</small><b>${series[i].completed}</b>`;
      tip.style.left = Math.min(r.width - 90, Math.max(0, (cx / vb.width) * r.width - 40)) + 'px';
    });
    svg.querySelector('.qpa-hit').addEventListener('pointerleave', () => { tip.hidden = true; svg.querySelector('.qpa-dot').setAttribute('cx', -10); svg.querySelector('.qpa-cross').setAttribute('x1', -10); svg.querySelector('.qpa-cross').setAttribute('x2', -10); });
  }

  // ================================================================== providers
  let provFilter = { status: '', q: '' };
  async function providers() {
    title(t('nav_providers'));
    const list = await api('/providers' + (provFilter.status ? '?status=' + provFilter.status : ''));
    const q = provFilter.q.trim().toLowerCase();
    const rows = list.filter((p) => !q || (p.name_ar + ' ' + (p.name_en || '') + ' ' + p.slug).toLowerCase().includes(q));
    view.innerHTML = `
      <div class="qpa-toolbar">
        <input class="qp-input" id="qpa-q" placeholder="${esc(t('search'))}…" value="${esc(provFilter.q)}">
        ${select('st', [['', t('all')], ['active', t('active')], ['frozen', t('frozen')], ['blocked', t('blocked')]], provFilter.status).replace('name="st"', 'id="qpa-st"')}
        <button class="qpa-btn" id="qpa-new">${esc(t('new_provider'))}</button>
      </div>
      <div class="qpa-card qpa-table-wrap"><table class="qpa-table"><thead><tr><th>${esc(t('name'))}</th><th>${esc(t('status'))}</th><th>${esc(t('queue'))}</th><th>${esc(t('available'))}</th><th>${esc(t('reserved'))}</th><th></th></tr></thead>
      <tbody>${rows.map((p) => `<tr data-go="#/provider/${p.id}">
        <td><b>${esc(nm(p))}</b><small class="qpa-sub" dir="ltr">/p/${esc(p.slug)}</small></td><td>${statusBadge(p.status)}</td>
        <td>${+p.queue_open ? `<span class="qpa-dotlabel is-on">${esc(t('open'))}</span>` : `<span class="qpa-dotlabel">${esc(t('closed'))}</span>`}</td>
        <td class="${+p.available <= 5 ? 'qpa-warn' : ''}"><b>${num(p.available ?? 0)}</b></td><td>${num(p.reserved ?? 0)}</td>
        <td><a class="qpa-btn qpa-btn--sm qpa-btn--ghost" href="#/provider/${p.id}">${esc(t('manage'))}</a></td></tr>`).join('') || empty(6)}</tbody></table></div>`;
    $('#qpa-q').addEventListener('input', (e) => { provFilter.q = e.target.value; clearTimeout(window.__qpq); window.__qpq = setTimeout(providers, 250); });
    $('#qpa-st').addEventListener('change', (e) => { provFilter.status = e.target.value; providers(); });
    $('#qpa-new').addEventListener('click', newProvider);
    view.querySelectorAll('[data-go]').forEach((tr) => tr.addEventListener('click', (e) => { if (!e.target.closest('a,button')) location.hash = tr.dataset.go; }));
    const qi = $('#qpa-q'); qi.focus(); qi.setSelectionRange(qi.value.length, qi.value.length);
  }

  async function newProvider() {
    const m = await meta();
    modal(`<h2>${esc(t('new_provider'))}</h2><div class="qpa-form2">
      ${field(t('name_ar') + ' *', input('name_ar', '', 'required maxlength="120"'))}
      ${field(t('name_en'), input('name_en', '', 'dir="ltr" maxlength="120"'))}
      ${field(t('slug') + ' *', input('slug', '', 'dir="ltr" required pattern="[a-z0-9][a-z0-9\\-]+[a-z0-9]" placeholder="salon-elegance"'))}
      ${field(t('email'), input('email', '', 'type="email" dir="ltr"'))}
      ${field(t('username_opt'), input('username', '', 'dir="ltr" pattern="[A-Za-z0-9][A-Za-z0-9._\\-]{2,59}"'))}
      ${field(t('category'), select('category_id', m.categories.map((c) => [c.id, nm(c)])))}
      ${field(t('city'), select('city_id', m.cities.map((c) => [c.id, nm(c)])))}
      ${field(t('address'), input('address', '', 'maxlength="255"'))}
      ${field(t('phone'), input('phone', '', 'type="tel" dir="ltr"'))}
      ${field(t('timezone'), select('timezone', m.timezones.map((z) => [z, z]), 'Africa/Algiers'))}
      <label class="qpa-check"><input type="checkbox" name="create_login" value="1" checked> ${esc(t('create_login'))}</label>
      <label class="qpa-check"><input type="checkbox" name="open_queue" value="1" checked> ${esc(t('open_queue'))}</label></div>`,
    async (d) => {
      d.open_queue = d.open_queue ? 1 : 0;
      d.create_login = d.create_login ? 1 : 0;
      const r = await api('/providers', { method: 'POST', body: d });
      toast(t('saved'));
      location.hash = '#/provider/' + r.provider.id;
      if (r.credentials) setTimeout(() => credsModal(r.credentials), 300);
    }, t('create'));
  }

  async function provider(id) {
    const [d, ledger, m] = await Promise.all([api('/providers/' + id), api('/providers/' + id + '/ledger?limit=30'), meta()]);
    const p = d.provider, b = d.balance, q = d.today;
    title(nm(p));
    const statusBtns = ['active', 'frozen', 'blocked'].filter((s) => s !== p.status)
      .map((s) => `<button class="qpa-btn qpa-btn--sm ${s === 'blocked' ? 'qpa-btn--danger' : 'qpa-btn--ghost'}" data-status="${s}">${esc(t(s === 'active' ? 'activate' : s === 'frozen' ? 'freeze' : 'block'))}</button>`).join('');
    view.innerHTML = `
      <div class="qpa-toolbar qpa-toolbar--head">
        <a href="#/providers" class="qpa-btn qpa-btn--ghost qpa-btn--sm">${AR ? '→' : '←'} ${esc(t('back'))}</a>
        ${statusBadge(p.status)} <span class="qpa-dotlabel ${+p.queue_open ? 'is-on' : ''}">${esc(t('queue'))}: ${esc(+p.queue_open ? t('open') : t('closed'))}</span>
        <span class="qpa-spacer"></span>${statusBtns}
        <a class="qpa-btn qpa-btn--sm" href="${esc(d.public_url)}" target="_blank" rel="noopener">${esc(t('public_page'))} ↗</a>
      </div>
      <section class="qpa-grid3">
        <div class="qpa-card">
          <h2>${esc(t('credits'))}</h2>
          <div class="qpa-credits"><div><strong class="${b.available <= 5 ? 'qpa-warn' : 'qpa-ok'}">${num(b.available)}</strong><small>${esc(t('available'))}</small></div>
            <div><strong>${num(b.reserved)}</strong><small>${esc(t('reserved'))}</small></div><div><strong>${num(b.consumed)}</strong><small>${esc(t('consumed'))}</small></div></div>
          <form id="qpa-pkg" class="qpa-inline-form">
            ${select('package_id', m.packages.filter((k) => +k.is_active).map((k) => [k.id, `${nm(k)} — ${k.credits} (${num(k.price)} ${k.currency_code})`]))}
            ${select('payment_method', ['cash', 'baridimob', 'ccp', 'bank', 'other'].map((k) => [k, t(k)]))}
            <input class="qp-input" name="note" placeholder="${esc(t('note'))}">
            <button class="qpa-btn">${esc(t('add_credits'))}</button>
          </form>
          <details class="qpa-details"><summary>${esc(t('adjust'))}</summary>
            <form id="qpa-adj" class="qpa-inline-form"><input class="qp-input" name="amount" type="number" step="1" placeholder="${esc(t('amount'))}" required>
              <input class="qp-input" name="reason" placeholder="${esc(t('reason'))}" required minlength="3"><button class="qpa-btn qpa-btn--ghost">${esc(t('apply'))}</button></form>
          </details>
        </div>
        <div class="qpa-card">
          <h2>${esc(t('login'))}</h2>
          ${d.login ? `<div class="qpa-kv"><small>${esc(t('username'))}</small><code dir="ltr">${esc(d.login.login)}</code></div><div class="qpa-kv"><small>Email</small><code dir="ltr">${esc(d.login.email)}</code></div>
            <div class="qpa-kv"><small>${esc(t('login_url'))}</small><code dir="ltr">${esc(C.home)}dashboard/</code></div>
            <button class="qpa-btn qpa-btn--ghost" id="qpa-reset">${esc(t('reset_pw'))}</button>` : `<p class="qpa-muted">${esc(t('no_login'))}</p><button class="qpa-btn" id="qpa-mklogin">${esc(t('create_login_btn'))}</button>`}
          <div class="qpa-kv" style="margin-top:12px"><small>${esc(t('public_link'))}</small><code dir="ltr">${esc(d.public_url)}</code></div>
        </div>
        <div class="qpa-card">
          <h2>${esc(t('counts'))} <small class="qpa-muted">${esc(q.date)}</small></h2>
          <div class="qpa-counts">${['waiting', 'called', 'in_service', 'completed', 'cancelled', 'no_show'].map((s) => `<div><strong>${q.counts[s] || 0}</strong><small>${esc(S.st[s])}</small></div>`).join('')}</div>
          <p class="qpa-muted" style="margin:10px 0 0">${esc(t('services'))}: ${d.services.map((s) => esc(nm(s))).join('، ') || '—'}</p>
        </div>
      </section>
      <section class="qpa-grid2">
        <form class="qpa-card" id="qpa-profile"><h2>${esc(t('profile'))}</h2><div class="qpa-form2">
          ${field(t('name_ar'), input('name_ar', p.name_ar, 'required'))}${field(t('name_en'), input('name_en', p.name_en, 'dir="ltr"'))}
          ${field(t('slug'), input('slug', p.slug, 'dir="ltr" required'))}${field(t('phone'), input('phone', p.phone, 'dir="ltr"'))}
          ${field(t('category'), select('category_id', m.categories.map((c) => [c.id, nm(c)]), p.category_id))}
          ${field(t('city'), select('city_id', m.cities.map((c) => [c.id, nm(c)]), p.city_id))}
          ${field(t('address'), input('address', p.address))}${field(t('timezone'), select('timezone', m.timezones.map((z) => [z, z]), p.timezone))}
        </div><button class="qpa-btn">${esc(t('save'))}</button></form>
        <div class="qpa-card"><h2>${esc(t('today_queue'))}</h2>
          <table class="qpa-table qpa-table--compact"><tbody>${q.active.map((x) => `<tr><td><b>${esc(x.code)}</b></td><td>${pill(x.status)}</td><td>${esc(x.customer_name || (x.source === 'walkin' ? t('walkin') : ''))}</td><td dir="ltr">${esc(x.phone || '')}</td></tr>`).join('') || empty(4)}</tbody></table></div>
      </section>
      <section class="qpa-card"><h2>${esc(t('ledger'))}</h2><div class="qpa-table-wrap"><table class="qpa-table qpa-table--compact">
        <thead><tr><th>#</th><th>${esc(t('type'))}</th><th>${esc(t('amount'))}</th><th>${esc(t('after'))}</th><th>${esc(t('reason'))}</th><th>${esc(t('time'))}</th></tr></thead>
        <tbody>${ledger.map((l) => `<tr><td>${l.id}</td><td>${esc(S.ledger_types[l.type] || l.type)}</td><td class="${+l.amount < 0 ? 'qpa-warn' : ''}" dir="ltr">${+l.amount > 0 ? '+' : ''}${l.amount}</td><td><b>${l.available_after}</b></td><td>${esc(l.reason || l.payment_method || (l.ticket_id ? '#' + l.ticket_id : ''))}</td><td>${esc(when(l.created_at))}</td></tr>`).join('') || empty(6)}</tbody></table></div></section>`;

    view.querySelectorAll('[data-status]').forEach((btn) => btn.addEventListener('click', async () => {
      const s = btn.dataset.status;
      if (!confirm(t('confirm_status', { s: t(s) }))) return;
      try { await api('/providers/' + id + '/status', { method: 'POST', body: { status: s } }); toast(t('saved')); provider(id); } catch (e) { toast(e.message, 'error'); }
    }));
    $('#qpa-pkg').addEventListener('submit', async (e) => { e.preventDefault(); const b2 = e.target.querySelector('button'); b2.disabled = true;
      try { await api('/providers/' + id + '/credits', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) }); toast(t('saved')); provider(id); } catch (x) { toast(x.message, 'error'); b2.disabled = false; } });
    $('#qpa-adj').addEventListener('submit', async (e) => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target));
      try { await api('/providers/' + id + '/credits', { method: 'POST', body: { type: 'adjustment', amount: +f.amount, reason: f.reason } }); toast(t('saved')); provider(id); } catch (x) { toast(x.message, 'error'); } });
    $('#qpa-profile').addEventListener('submit', async (e) => { e.preventDefault();
      try { await api('/providers/' + id, { method: 'POST', body: Object.fromEntries(new FormData(e.target)) }); toast(t('saved')); provider(id); } catch (x) { toast(x.message, 'error'); } });
    const mk = $('#qpa-mklogin'); if (mk) mk.addEventListener('click', () => modal(`<h2>${esc(t('create_login_btn'))}</h2><div class="qpa-form2">
        ${field(t('username'), input('username', p.slug, 'dir="ltr" required pattern="[A-Za-z0-9][A-Za-z0-9._\\-]{2,59}"'))}${field('Email', input('email', '', 'type="email" dir="ltr"'))}</div>`,
      async (f) => { const c = await api('/providers/' + id + '/login', { method: 'POST', body: f }); dlg.close(); await provider(id); credsModal(c); return true; }, t('create')));
    const rb = $('#qpa-reset'); if (rb) rb.addEventListener('click', async () => {
      if (!confirm(t('reset_pw') + '?')) return;
      try { credsModal(await api('/providers/' + id + '/password', { method: 'POST' })); } catch (x) { toast(x.message, 'error'); } });
  }

  // ================================================================== bookings
  let tf = { status: '', date: '', phone: '', offset: 0 };
  async function tickets() {
    title(t('nav_tickets'));
    const qs = new URLSearchParams({ status: tf.status, date: tf.date, phone: tf.phone, offset: tf.offset }).toString();
    const rows = await api('/tickets?' + qs);
    view.innerHTML = `<form class="qpa-toolbar" id="qpa-tf">
        ${select('status', [['', t('all')], ['active', t('active_bookings')], ...Object.keys(S.st).map((k) => [k, S.st[k]])], tf.status)}
        <input class="qp-input" type="date" name="date" value="${esc(tf.date)}"><input class="qp-input" name="phone" dir="ltr" placeholder="${esc(t('filter_phone'))}" value="${esc(tf.phone)}">
        <button class="qpa-btn">${esc(t('search'))}</button></form>
      <div class="qpa-card qpa-table-wrap"><table class="qpa-table"><thead><tr><th>${esc(t('number'))}</th><th>${esc(t('provider'))}</th><th>${esc(t('customer'))}</th><th>${esc(t('status'))}</th><th>${esc(t('credit'))}</th><th>${esc(t('date'))}</th></tr></thead>
      <tbody>${rows.map((r) => `<tr><td><b>${esc(code(r.ticket_prefix, r.ticket_number))}</b><small class="qpa-sub">${esc(r.public_ref)}</small></td><td><a href="#/provider/${r.provider_id}">${esc(r.provider_name)}</a></td>
        <td>${esc(r.customer_name || '')}<small class="qpa-sub" dir="ltr">${esc(r.phone || (r.source === 'walkin' ? t('walkin') : ''))}</small></td><td>${pill(r.status)}</td><td>${esc(S.ledger_types[r.credit_state] || r.credit_state)}</td><td>${esc(when(r.created_at))}</td></tr>`).join('') || empty(6)}</tbody></table></div>
      <div class="qpa-pager">${tf.offset ? `<button class="qpa-btn qpa-btn--ghost" data-off="-50">←</button>` : ''}${rows.length === 50 ? `<button class="qpa-btn qpa-btn--ghost" data-off="50">${esc(t('more'))}</button>` : ''}</div>`;
    $('#qpa-tf').addEventListener('submit', (e) => { e.preventDefault(); Object.assign(tf, Object.fromEntries(new FormData(e.target)), { offset: 0 }); tickets(); });
    view.querySelectorAll('[data-off]').forEach((b) => b.addEventListener('click', () => { tf.offset = Math.max(0, tf.offset + +b.dataset.off); tickets(); }));
  }

  // ================================================================== packages
  async function packages() {
    title(t('nav_packages'));
    META = null;
    const m = await meta();
    view.innerHTML = `<div class="qpa-toolbar"><span class="qpa-spacer"></span><button class="qpa-btn" id="qpa-addpkg">${esc(t('add'))}</button></div>
      <div class="qpa-pkgs">${m.packages.map((k) => `<div class="qpa-pkg ${+k.is_active ? '' : 'is-off'}"><small>${esc(nm(k))}</small><strong>${num(k.credits)}</strong><span>${esc(t('credits_n'))}</span>
        <b>${num(k.price)} ${esc(k.currency_code)}</b><button class="qpa-btn qpa-btn--ghost qpa-btn--sm" data-pkg="${k.id}">${esc(t('edit'))}</button></div>`).join('')}</div>`;
    const edit = (k) => modal(`<h2>${esc(k ? t('edit') : t('add'))}</h2><div class="qpa-form2">
        ${field(t('name_ar'), input('name_ar', k?.name_ar || '', 'required'))}${field(t('name_en'), input('name_en', k?.name_en || '', 'dir="ltr"'))}
        ${field(t('credits_n'), input('credits', k?.credits || 150, 'type="number" min="1" required'))}${field(t('price'), input('price', k?.price || 0, 'type="number" min="0" step="0.01"'))}
        <label class="qpa-check"><input type="checkbox" name="is_active" value="1" ${!k || +k.is_active ? 'checked' : ''}> ${esc(t('active'))}</label></div>`,
      async (d) => { await api('/packages', { method: 'POST', body: Object.assign(d, { id: k?.id, is_active: d.is_active ? 1 : 0, sort_order: k?.sort_order || m.packages.length + 1 }) }); toast(t('saved')); packages(); });
    $('#qpa-addpkg').addEventListener('click', () => edit(null));
    view.querySelectorAll('[data-pkg]').forEach((b) => b.addEventListener('click', () => edit(m.packages.find((k) => +k.id === +b.dataset.pkg))));
  }

  // ================================================================== ratings
  async function ratings() {
    title(t('nav_ratings'));
    const rows = await api('/ratings');
    view.innerHTML = `<div class="qpa-card qpa-table-wrap"><table class="qpa-table"><thead><tr><th>${esc(t('provider'))}</th><th>${esc(t('stars'))}</th><th>${esc(t('comment'))}</th><th>${esc(t('status'))}</th><th>${esc(t('date'))}</th><th></th></tr></thead>
      <tbody>${rows.map((r) => `<tr class="${+r.is_visible ? '' : 'is-off'}"><td><a href="#/provider/${r.provider_id}">${esc(r.provider_name)}</a></td><td class="qpa-stars">${'★'.repeat(r.stars)}<span>${'★'.repeat(5 - r.stars)}</span></td>
        <td>${esc(r.comment || '—')}<small class="qpa-sub">${esc(r.customer_name || '')}</small></td><td>${esc(+r.is_visible ? t('visible') : t('hidden'))}</td><td>${esc(when(r.created_at))}</td>
        <td><button class="qpa-btn qpa-btn--sm qpa-btn--ghost" data-rt="${r.id}" data-v="${+r.is_visible ? 0 : 1}">${esc(+r.is_visible ? t('hide') : t('show'))}</button></td></tr>`).join('') || empty(6)}</tbody></table></div>`;
    view.querySelectorAll('[data-rt]').forEach((b) => b.addEventListener('click', async () => { try { await api('/ratings/' + b.dataset.rt, { method: 'POST', body: { visible: +b.dataset.v } }); ratings(); } catch (e) { toast(e.message, 'error'); } }));
  }

  // ================================================================== messages
  async function messages() {
    title(t('nav_messages'));
    const [rows, st] = await Promise.all([api('/notifications'), api('/settings')]);
    view.innerHTML = `${st.sms_driver === 'log' ? `<div class="qp-notice qp-notice--warn" style="margin-bottom:14px">${esc(t('log_mode'))} <a href="#/settings">${esc(t('nav_settings'))}</a></div>` : ''}
      <form class="qpa-toolbar" id="qpa-test"><input class="qp-input" name="phone" dir="ltr" placeholder="05xx xx xx xx" required><button class="qpa-btn">${esc(t('test_sms'))}</button></form>
      <div class="qpa-card qpa-table-wrap"><table class="qpa-table"><thead><tr><th>${esc(t('type'))}</th><th>${esc(t('to'))}</th><th>${esc(t('body'))}</th><th>${esc(t('status'))}</th><th>${esc(t('time'))}</th></tr></thead>
      <tbody>${rows.map((r) => `<tr><td>${esc(r.type)}<small class="qpa-sub">${esc(r.channel)}</small></td><td dir="ltr">${esc(r.to_phone)}</td><td class="qpa-msg">${esc(r.body)}${r.last_error ? `<small class="qpa-sub">${esc(r.last_error)}</small>` : ''}</td>
        <td><span class="qpa-status qpa-status--${r.status === 'sent' ? 'active' : r.status === 'failed' ? 'blocked' : 'frozen'}">${esc(t(r.status))}</span></td><td>${esc(when(r.created_at))}</td></tr>`).join('') || empty(5)}</tbody></table></div>`;
    $('#qpa-test').addEventListener('submit', async (e) => { e.preventDefault(); const b = e.target.querySelector('button'); b.disabled = true;
      try { const r = await api('/notifications/test', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) }); r.ok ? toast(t('test_ok') + (r.note ? ' (' + r.note + ')' : '')) : toast(r.error, 'error'); }
      catch (x) { toast(x.message, 'error'); } finally { b.disabled = false; } });
  }

  // ================================================================== audit
  let auditOff = 0;
  async function audit() {
    title(t('nav_audit'));
    const rows = await api('/audit?limit=50&offset=' + auditOff);
    view.innerHTML = `<div class="qpa-card qpa-table-wrap"><table class="qpa-table"><thead><tr><th>${esc(t('time'))}</th><th>${esc(t('user'))}</th><th>${esc(t('action'))}</th><th>${esc(t('target'))}</th><th>${esc(t('details'))}</th></tr></thead>
      <tbody>${rows.map((a) => `<tr><td>${esc(when(a.created_at))}</td><td>#${esc(a.actor_user_id || '—')} <small class="qpa-sub">${esc(a.actor_role)}</small></td><td><b>${esc(S.adm_actions[a.action] || a.action)}</b></td>
        <td>${a.target_type === 'provider' && a.target_id ? `<a href="#/provider/${a.target_id}">provider #${a.target_id}</a>` : esc(a.target_type + (a.target_id ? ' #' + a.target_id : ''))}</td><td class="qpa-msg"><code>${esc(a.details || '')}</code></td></tr>`).join('') || empty(5)}</tbody></table></div>
      <div class="qpa-pager">${auditOff ? `<button class="qpa-btn qpa-btn--ghost" data-off="-50">←</button>` : ''}${rows.length === 50 ? `<button class="qpa-btn qpa-btn--ghost" data-off="50">${esc(t('more'))}</button>` : ''}</div>`;
    view.querySelectorAll('[data-off]').forEach((b) => b.addEventListener('click', () => { auditOff = Math.max(0, auditOff + +b.dataset.off); audit(); }));
  }

  // ================================================================== settings
  const BOOL = ['consume_on_no_show', 'consume_on_cancel', 'require_otp', 'notify_on_join', 'notify_on_call', 'take_homepage'];
  const GROUPS = [
    ['g_credit', ['free_grant_credits', 'low_credit_threshold', 'consume_on_no_show', 'consume_on_cancel']],
    ['g_abuse', ['max_joins_per_device_window', 'max_joins_per_ip_window', 'max_joins_per_ip_hard', 'ip_window_minutes', 'max_joins_per_phone_day', 'poll_interval_seconds']],
    ['g_notify', ['require_otp', 'otp_ttl_minutes', 'notify_on_join', 'notify_on_call', 'notify_near_ahead', 'rating_window_days']],
    ['g_sms', ['sms_driver', 'sms_channel', 'sms_sender', 'sms_http_url', 'sms_http_method', 'sms_http_body', 'sms_http_headers', 'sms_http_secret', 'twilio_sid', 'twilio_from', 'twilio_secret']],
    ['g_brand', ['brand_name', 'support_phone']]
  ];
  async function settings() {
    title(t('nav_settings'));
    const st = await api('/settings');
    const ctl = (k) => {
      const v = st[k] ?? '';
      if (BOOL.includes(k)) return `<label class="qpa-switchrow"><span>${esc(t('s_' + k))}</span><input type="checkbox" name="${k}" value="1" ${+v ? 'checked' : ''} class="qpa-toggle"></label>`;
      if (k === 'sms_driver') return field(t('s_' + k), select(k, [['log', t('drv_log')], ['http', t('drv_http')], ['twilio', t('drv_twilio')]], v));
      if (k === 'sms_channel') return field(t('s_' + k), select(k, [['sms', t('ch_sms')], ['whatsapp', t('ch_whatsapp')]], v));
      if (k === 'sms_http_method') return field(t('s_' + k), select(k, [['POST', 'POST'], ['GET', 'GET']], v));
      if (k === 'sms_http_body' || k === 'sms_http_headers') return field(t('s_' + k), `<textarea class="qp-input qpa-code" name="${k}" dir="ltr" rows="3">${esc(v)}</textarea>`);
      const isNum = !isNaN(+v) && v !== '' && !/^(brand|support|sms|twilio)/.test(k);
      return field(t('s_' + k), input(k, v, (isNum ? 'type="number" min="0"' : '') + (/(url|secret|sid|from|phone|sender)/.test(k) ? ' dir="ltr"' : '') + (/secret/.test(k) ? ' autocomplete="off"' : '')));
    };
    view.innerHTML = `<form id="qpa-settings">${GROUPS.map(([g, keys]) => `<section class="qpa-card" data-group="${g}"><h2>${esc(t(g))}</h2><div class="qpa-form2">${keys.map((k) => `<div data-k="${k}">${ctl(k)}</div>`).join('')}</div>
        ${g === 'g_sms' ? `<p class="qpa-muted qpa-help" data-k-help>${esc(t('http_help'))}</p>` : ''}</section>`).join('')}
      <div class="qpa-savebar"><button class="qpa-btn">${esc(t('save'))}</button></div></form>
      <section class="qpa-grid2" style="margin-top:8px">
        <div class="qpa-card"><h2>${esc(t('system'))}</h2><div id="qpa-health"><div class="qpd-loading" style="min-height:80px"><span class="qp-live-dot"></span></div></div></div>
        <div>
          <div class="qpa-card"><h2>${esc(t('backup'))}</h2><p class="qpa-muted">${esc(t('backup_hint'))}</p><button class="qpa-btn" id="qpa-backup" type="button">${esc(t('backup'))}</button></div>
          <form class="qpa-card" id="qpa-pw"><h2>${esc(t('change_pw'))}</h2>
            ${field(t('current_pw'), '<input class="qp-input" name="current" type="password" dir="ltr" required autocomplete="current-password">')}
            ${field(t('new_pw'), '<input class="qp-input" name="new" type="password" dir="ltr" required minlength="10" autocomplete="new-password">')}
            <button class="qpa-btn" style="margin-top:10px">${esc(t('save'))}</button></form>
        </div>
      </section>`;
    health();
    $('#qpa-backup').addEventListener('click', backup);
    $('#qpa-pw').addEventListener('submit', async (e) => {
      e.preventDefault();
      try { await api('/password', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) }); e.target.reset(); toast(t('pw_changed')); } catch (x) { toast(x.message, 'error'); }
    });
    const f = $('#qpa-settings');
    const sync = () => {
      const drv = f.sms_driver.value;
      ['sms_http_url', 'sms_http_method', 'sms_http_body', 'sms_http_headers', 'sms_http_secret'].forEach((k) => (f.querySelector(`[data-k="${k}"]`).hidden = drv !== 'http'));
      ['twilio_sid', 'twilio_from', 'twilio_secret'].forEach((k) => (f.querySelector(`[data-k="${k}"]`).hidden = drv !== 'twilio'));
      f.querySelector('[data-k="sms_channel"]').hidden = drv !== 'twilio';
      f.querySelector('[data-k-help]').hidden = drv !== 'http';
    };
    f.sms_driver.addEventListener('change', sync); sync();
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const out = {};
      GROUPS.forEach(([, keys]) => keys.forEach((k) => { const el = f.elements[k]; if (!el) return; out[k] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; }));
      const b = f.querySelector('.qpa-savebar button'); b.disabled = true;
      try { await api('/settings', { method: 'POST', body: { settings: out } }); toast(t('saved')); settings(); } catch (x) { toast(x.message, 'error'); } finally { b.disabled = false; }
    });
  }

  async function health() {
    const box = $('#qpa-health'); if (!box) return;
    try {
      const h = await api('/health');
      const yes = (v) => v === null ? `<span class="qpa-muted">${esc(t('unknown'))}</span>` : v ? `<span class="qpa-ok">✓ ${esc(t('ok'))}</span>` : `<b class="qpa-warn">✗ ${esc(t('bad'))}</b>`;
      const size = (b) => b == null ? '—' : b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB';
      const rows = [
        [t('h_version'), esc(h.version)], [t('h_php'), esc(h.php)], [t('h_db'), esc(h.db.driver + ' ' + h.db.version)], [t('h_size'), size(h.db.size)],
        [t('h_storage'), yes(h.storage_protected)], [t('h_writable'), yes(h.storage_writable && h.uploads_writable)], [t('h_https'), h.https ? yes(true) : `<span class="qpa-warn-soft">${esc(t('https_off'))}</span>`],
        [t('h_books'), yes(h.books_ok)], [t('h_maint'), esc(h.maintenance_last_run ? when(h.maintenance_last_run) : t('never'))],
        [t('h_pending'), num(h.pending_messages)], [t('h_failed'), num(h.failed_messages)],
        [t('h_ext'), Object.entries(h.extensions).map(([k, v]) => `<span class="${v ? 'qpa-ok' : 'qpa-muted'}">${v ? '✓' : '—'} ${esc(k)}</span>`).join(' · ')]
      ];
      box.innerHTML = (h.storage_protected === false ? `<div class="qp-notice qp-notice--error" style="margin-bottom:10px">${esc(t('storage_bad'))}</div>` : '')
        + `<table class="qpa-table qpa-table--compact"><tbody>${rows.map(([k, v]) => `<tr><td>${esc(k)}</td><td>${v}</td></tr>`).join('')}</tbody></table>`;
    } catch (e) { box.innerHTML = `<div class="qp-notice qp-notice--error">${esc(e.message)}</div>`; }
  }

  async function backup() {
    const b = $('#qpa-backup'); b.disabled = true; toast(t('downloading'), 'warn');
    try {
      const r = await fetch(C.api + '/backup?lang=' + C.lang, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': C.nonce } });
      if (!r.ok) { let d = {}; try { d = await r.json(); } catch (e) {} throw new Error(d.message || t('error')); }
      const blob = await r.blob();
      const name = ((r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/) || [])[1] || 'queuepro-backup';
      const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
      toast(t('saved'));
    } catch (e) { toast(e.message, 'error'); } finally { b.disabled = false; }
  }

  route();
})();
