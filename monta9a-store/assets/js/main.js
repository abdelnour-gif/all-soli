(function () {
  'use strict';
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
  const csrf = $('meta[name="csrf"]')?.content || '';
  const T = Object.assign({ cur: ' د.ج' }, window.T || {});
  const isFr = document.documentElement.lang === 'fr';
  const fmt = n => (isFr ? Number(n).toLocaleString('fr-FR').replace(/\s/g, '\u202F') : Number(n).toLocaleString('en-US')) + T.cur;

  /* ---------- Toast ---------- */
  let toastTimer;
  function toast(msg, isError = false, link = null) {
    const t = $('#toast');
    if (!t) return;
    t.textContent = msg;
    if (link) {
      const a = document.createElement('a');
      a.href = link.href; a.textContent = link.text;
      t.appendChild(a);
    }
    t.classList.toggle('error', isError);
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 3200);
  }

  /* ---------- API ---------- */
  async function api(data) {
    const body = new URLSearchParams(data);
    body.append('csrf', csrf);
    try {
      const res = await fetch('api/cart.php', { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' } });
      return await res.json();
    } catch (e) {
      return { success: false, message: T.netErr };
    }
  }
  function setCount(n, total) {
    const t = $('#cartTotal');
    const mc = $('#mCartCount'); if (mc) mc.textContent = n;
    if (t && total) t.textContent = total;
    const b = $('#cartCount');
    if (b) { b.textContent = n; b.hidden = false; b.animate?.([{ transform: 'scale(1.5)' }, { transform: 'scale(1)' }], { duration: 300 }); }
  }
  function setWishCount(n) {
    const b = $('#wishCount');
    if (b) { b.textContent = n; }
    const m = $('#mWishCount'); if (m) m.textContent = n;
  }

  /* ---------- Mobile menu ---------- */
  const nav = $('#mainNav'), overlay = $('#navOverlay'), filters = $('#filters');
  function closeAll() { nav?.classList.remove('open'); filters?.classList.remove('open'); overlay?.classList.remove('show'); document.body.classList.remove('nav-open'); document.body.style.overflow = ''; }
  function openPanel(el) { el?.classList.add('open'); if (el === nav) document.body.classList.add('nav-open'); overlay?.classList.add('show'); document.body.style.overflow = 'hidden'; }
  $('#menuToggle')?.addEventListener('click', () => openPanel(nav));
  $('#menuClose')?.addEventListener('click', closeAll);
  $('#filtersOpen')?.addEventListener('click', () => openPanel(filters));
  $('#filtersClose')?.addEventListener('click', closeAll);
  overlay?.addEventListener('click', closeAll);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAll(); });
  $$('.has-drop > a').forEach(a => a.addEventListener('click', e => {
    if (window.matchMedia('(max-width:1024px)').matches) {
      e.preventDefault();
      a.parentElement.classList.toggle('open');
    }
  }));
  $('#searchToggle')?.addEventListener('click', () => {
    const box = $('.search-box');
    box?.classList.toggle('open');
    if (box?.classList.contains('open')) box.querySelector('input')?.focus();
  });

  /* ---------- Language switch ---------- */
  const ls = $('.lang-switch');
  if (ls) {
    const btn = $('.ls-btn', ls);
    btn.addEventListener('click', e => { e.stopPropagation(); const o = ls.classList.toggle('open'); btn.setAttribute('aria-expanded', o); });
    document.addEventListener('click', () => { ls.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); });
  }

  /* ---------- Hero slider ---------- */
  const hero = $('#hero');
  if (hero) {
    const slides = $$('.slide', hero), dots = $$('.hero-dots button', hero);
    let idx = 0, timer;
    const go = i => {
      idx = (i + slides.length) % slides.length;
      slides.forEach((s, k) => s.classList.toggle('active', k === idx));
      dots.forEach((d, k) => d.classList.toggle('active', k === idx));
    };
    const auto = () => { clearInterval(timer); if (slides.length > 1) timer = setInterval(() => go(idx + 1), 6000); };
    $('.hero-nav.next', hero)?.addEventListener('click', () => { go(idx + 1); auto(); });
    $('.hero-nav.prev', hero)?.addEventListener('click', () => { go(idx - 1); auto(); });
    dots.forEach(d => d.addEventListener('click', () => { go(+d.dataset.slide); auto(); }));
    let sx = null;
    hero.addEventListener('touchstart', e => { sx = e.touches[0].clientX; }, { passive: true });
    hero.addEventListener('touchend', e => {
      if (sx === null) return;
      const dx = e.changedTouches[0].clientX - sx;
      if (Math.abs(dx) > 40) { go(idx + (dx > 0 ? 1 : -1)); auto(); }
      sx = null;
    });
    auto();
  }

  /* ---------- Brand strip arrows ---------- */
  $$('[data-scroll]').forEach(btn => btn.addEventListener('click', () => {
    const track = document.getElementById(btn.dataset.scroll);
    // RTL: positive dir moves toward the start (right)
    track?.scrollBy({ left: (+btn.dataset.dir) * track.clientWidth * 0.6, behavior: 'smooth' });
  }));

  /* ---------- Add to cart (cards) ---------- */
  document.addEventListener('click', async e => {
    const addBtn = e.target.closest('[data-add]');
    if (addBtn) {
      e.preventDefault();
      addBtn.disabled = true;
      const r = await api({ action: 'add', id: addBtn.dataset.add, color: addBtn.dataset.color || '', size: addBtn.dataset.size || '', qty: 1 });
      addBtn.disabled = false;
      if (r.success) { setCount(r.count, r.total); toast(r.message, false, { href: 'cart.php', text: T.viewCart }); }
      else toast(r.message, true);
      return;
    }
    const wishBtn = e.target.closest('[data-wish]');
    if (wishBtn) {
      e.preventDefault();
      const r = await api({ action: 'wish', id: wishBtn.dataset.wish });
      if (r.success) {
        $$(`[data-wish="${wishBtn.dataset.wish}"]`).forEach(b => b.classList.toggle('active', r.active));
        setWishCount(r.wish_count);
        toast(r.message);
      } else toast(r.message, true);
    }
  });

  /* ---------- Newsletter ---------- */
  const newsForm = $('#newsForm');
  newsForm?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = newsForm.querySelector('button');
    btn.disabled = true;
    const body = new URLSearchParams({ email: newsForm.email.value, csrf });
    let r;
    try { r = await (await fetch('api/subscribe.php', { method: 'POST', body })).json(); }
    catch (err) { r = { success: false, message: T.netErr }; }
    btn.disabled = false;
    toast(r.message, !r.success);
    if (r.success) newsForm.reset();
  });

  /* ---------- Product page ---------- */
  const addForm = $('#addForm');
  if (addForm) {
    const qtyInput = $('input[name="qty"]', addForm);
    const err = $('#addError');
    $$('[data-qty]', addForm).forEach(b => b.addEventListener('click', () => {
      const max = +qtyInput.max || 20;
      qtyInput.value = Math.min(max, Math.max(1, (+qtyInput.value || 1) + (+b.dataset.qty)));
    }));
    $$('input[name="color"]', addForm).forEach(r => r.addEventListener('change', () => {
      $('#colorLabel').textContent = r.dataset.name;
      err.hidden = true;
    }));
    $$('input[name="size"]', addForm).forEach(r => r.addEventListener('change', () => {
      $('#sizeLabel').textContent = r.value;
      err.hidden = true;
    }));
    const pickedSize = () => {
      const s = $$('input[name="size"]', addForm);
      const c = s.find(i => i.checked);
      return { need: s.length > 0, val: c ? c.value : '' };
    };
    $$('.gallery-thumbs button').forEach(b => b.addEventListener('click', () => {
      $('#mainImg').src = b.dataset.img;
      $$('.gallery-thumbs button').forEach(x => x.classList.toggle('active', x === b));
    }));
    async function add(goCheckout) {
      const colorInputs = $$('input[name="color"]', addForm);
      const chosen = colorInputs.find(i => i.checked);
      if (colorInputs.length && !chosen) {
        err.textContent = T.errColorFirst;
        err.hidden = false;
        return;
      }
      const sz = pickedSize();
      if (sz.need && !sz.val) { err.textContent = T.errSize; err.hidden = false; return; }
      const r = await api({ action: 'add', id: addForm.dataset.product, color: chosen ? chosen.value : '', size: sz.val, qty: qtyInput.value });
      if (!r.success) { err.textContent = r.message; err.hidden = false; return; }
      err.hidden = true;
      setCount(r.count, r.total);
      if (goCheckout) location.href = 'checkout.php';
      else toast(r.message, false, { href: 'cart.php', text: T.viewCart });
    }
    addForm.addEventListener('submit', e => { e.preventDefault(); add(false); });
    $('#buyNow')?.addEventListener('click', () => {
      const colorInputs = $$('input[name="color"]', addForm);
      const chosen = colorInputs.find(i => i.checked);
      if (colorInputs.length > 1 && !chosen) { err.textContent = T.errColorFirst; err.hidden = false; return; }
      err.hidden = true;
      openOrder(JSON.parse($('#buyNow').dataset.orderInfo), chosen ? chosen.value : '', +qtyInput.value || 1, pickedSize().val);
    });
  }

  /* ---------- Cart page ---------- */
  $$('.cart-item').forEach(row => {
    const key = row.dataset.key;
    const input = $('[data-cart-input]', row);
    const update = async qty => {
      const r = await api({ action: 'update', key, qty });
      if (r.success) location.reload();
      else { toast(r.message, true); input.value = input.defaultValue; }
    };
    $$('[data-cart-qty]', row).forEach(b => b.addEventListener('click', () => update((+input.value || 1) + (+b.dataset.cartQty))));
    input.addEventListener('change', () => update(+input.value || 1));
    $('[data-cart-remove]', row).addEventListener('click', async () => {
      const r = await api({ action: 'remove', key });
      if (r.success) location.reload();
    });
  });

  /* ---------- Checkout ---------- */
  /* Shared by the checkout page and the "اطلب الآن" popup (same field ids; never both on one page) */
  let orderFormReady = false, recalc = () => {};
  function initOrderForm() {
    const wilaya = $('#wilaya');
    if (!wilaya || orderFormReady) return;
    orderFormReady = true;
    const sub = () => +$('#subtotal').dataset.v;
    const dp = $('#deliveryPrice');
    const freeMin = +dp.dataset.free;
    const addressField = $('#addressField');
    const giftWrap = $('#giftWrap');
    const wrapCost = () => (giftWrap && giftWrap.checked ? +giftWrap.dataset.price : 0);
    giftWrap?.addEventListener('change', () => { const r = $('#giftRow'); if (r) r.hidden = !giftWrap.checked; calc(); });
    /* Delivery companies (from api/desks.php): each with its prices and offices in the wilaya.
       companies = null -> no company linked: the store's own prices (data-home / data-office). */
    let companies = null, cur = null;
    const courierField = $('#courierField'), courierOpts = $('#courierOpts'), courierInput = $('#courierInput');
    const officeRadio = $('input[name="delivery"][value="office"]');
    const prices = () => {
      const opt = wilaya.selectedOptions[0];
      if (cur) return { home: +cur.home, office: cur.office === null ? null : +cur.office };
      return { home: +(opt?.dataset.home || 0), office: +(opt?.dataset.office || 0) };
    };
    const calc = () => {
      const opt = wilaya.selectedOptions[0];
      const type = $('input[name="delivery"]:checked')?.value || 'home';
      addressField.hidden = type === 'office';
      const free = freeMin > 0 && sub() >= freeMin;
      if (!opt || !opt.value || (companies && !cur)) {
        $('#homePrice').textContent = ''; $('#officePrice').textContent = '';
        dp.textContent = companies && !cur ? T.pickCourier : T.pickWilaya;
        $('#grandTotal').textContent = fmt(sub() + wrapCost());
        return;
      }
      const p = prices();
      const home = free ? 0 : p.home, office = free ? 0 : (p.office || 0);
      $('#homePrice').textContent = home ? fmt(home) : T.free;
      $('#officePrice').textContent = p.office === null ? '—' : (office ? fmt(office) : T.free);
      const price = type === 'office' ? office : home;
      dp.textContent = price ? fmt(price) : T.free;
      $('#grandTotal').textContent = fmt(sub() + price + wrapCost());
    };
    wilaya.addEventListener('change', calc);

    /* Communes: the list follows the chosen wilaya */
    const commune = $('#commune');
    let communes = null;
    const fillCommunes = () => {
      const w = wilaya.value;
      const keep = commune.value || commune.dataset.selected || '';
      commune.dataset.selected = '';
      commune.innerHTML = '';
      const first = document.createElement('option');
      first.value = '';
      if (!w) { first.textContent = T.pickWilayaF; commune.append(first); commune.disabled = true; return; }
      if (!communes) { first.textContent = T.loading; commune.append(first); commune.disabled = true; return; }
      first.textContent = T.pickCommune;
      commune.append(first);
      (communes[w] || []).forEach(([ar, fr]) => {
        const o = document.createElement('option');
        o.value = ar; o.textContent = isFr ? fr + ' (' + ar + ')' : ar + ' (' + fr + ')';
        if (ar === keep) o.selected = true;
        commune.append(o);
      });
      commune.disabled = false;
    };
    wilaya.addEventListener('change', () => { commune.value = ''; fillCommunes(); loadCompanies(); });
    commune.addEventListener('change', () => { if (companies) loadCompanies(true); });

    /* Offices of the chosen company */
    const desk = $('#desk'), deskField = $('#deskField'), deskNote = $('#deskNote'), deskCo = $('#deskCo');
    const desks = () => (cur ? cur.desks : null);   // null = unknown -> no list (the admin picks later)
    const showDesk = () => {
      const office = officeRadio.checked;
      deskField.hidden = !(office && desks());
      desk.required = !deskField.hidden;
    };
    const deskHint = () => {
      const list = desks();
      if (!list || !list.length) { deskNote.textContent = ''; return; }
      const a = desk.value && cur.info && cur.info[desk.value];
      deskNote.textContent = desk.value ? T.deskHere + (a ? ' : ' + a : '.') : (list.length === 1 ? T.desks1 : T.desksN.replace('%n', list.length));
    };
    const fillDesks = () => {
      const keep = desk.value || desk.dataset.selected || '';
      desk.dataset.selected = '';
      desk.innerHTML = '';
      const d0 = document.createElement('option'); d0.value = ''; d0.textContent = T.pickDesk; desk.append(d0);
      if (deskCo && cur) deskCo.textContent = cur.name;
      const list = desks();
      const noOffice = cur && (cur.office === null || (list && !list.length));
      officeRadio.disabled = !!noOffice;
      if (noOffice && officeRadio.checked) { $('input[name="delivery"][value="home"]').checked = true; toast(T.noDesk); }
      (list || []).forEach(n => {
        const o = document.createElement('option');
        o.value = o.textContent = n;
        if (n === keep) o.selected = true;
        desk.append(o);
      });
      deskHint();
      showDesk();
      calc();
    };
    desk.addEventListener('change', deskHint);

    const pickCompany = k => {
      cur = (companies || []).find(c => c.key === k) || null;
      courierInput.value = cur ? cur.key : '';
      $$('.co-opt', courierOpts).forEach(b => b.classList.toggle('on', b.dataset.k === courierInput.value));
      fillDesks();
    };
    const renderCompanies = () => {
      courierOpts.innerHTML = '';
      courierField.hidden = !companies || companies.length < 2;
      (companies || []).forEach(c => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'co-opt'; b.dataset.k = c.key;
        const n = document.createElement('strong'); n.textContent = c.name;
        const pr = document.createElement('span');
        pr.textContent = '🏠 ' + fmt(c.home) + (c.office !== null && !(c.desks && !c.desks.length) ? '   🏢 ' + fmt(c.office) : '');
        b.append(n, pr);
        b.addEventListener('click', () => pickCompany(c.key));
        courierOpts.append(b);
      });
      const keep = courierInput.value;
      if (companies && companies.length === 1) pickCompany(companies[0].key);
      else pickCompany(companies && companies.some(c => c.key === keep) ? keep : '');
    };
    let reqN = 0;
    const loadCompanies = (keepChoice = false) => {
      const w = wilaya.value;
      if (!keepChoice) { companies = null; cur = null; renderCompanies(); }
      if (!w) { calc(); return; }
      const n = ++reqN;
      fetch(desk.dataset.src + '?w=' + w + '&c=' + encodeURIComponent(commune.value || '')).then(r => r.json()).then(d => {
        if (n !== reqN) return;
        companies = d.on ? d.companies : null;
        renderCompanies();
      }).catch(() => {});
    };
    $$('input[name="delivery"]').forEach(r => r.addEventListener('change', showDesk));
    loadCompanies(true);
    fillCommunes();
    fetch(commune.dataset.src).then(r => r.json()).then(d => { communes = d; fillCommunes(); })
      .catch(() => { commune.innerHTML = ''; const ce = document.createElement('option'); ce.value = ''; ce.textContent = T.communesErr; commune.append(ce); });
    $$('input[name="delivery"]').forEach(r => r.addEventListener('change', calc));
    calc();
    recalc = calc;
    window.orderRecalc = calc;   // used by the landing page when the offer changes
    $('#checkoutForm').addEventListener('submit', e => {
      const f = e.target;
      const phone = f.phone.value.replace(/\D/g, '').replace(/^213/, '0');
      let msg = '';
      if (f.name.value.trim().length < 3) msg = T.errName;
      else if (!/^0[5-7]\d{8}$/.test(phone)) msg = T.errPhone;
      else if (!f.wilaya.value) msg = T.errWilaya;
      else if (!f.commune.value) msg = T.errCommune;
      else if ($('input[name="delivery"]:checked').value === 'home' && f.address.value.trim().length < 3) msg = T.errAddress;
      else if (courierField && !courierField.hidden && !courierInput.value) msg = T.pickCourier;
      else if (desk.required && !desk.value) msg = T.errDesk;
      else if (f.bn_color && $('#omColors') && !$('#omColors').hidden && !f.bn_color.value) msg = T.errColor;
      else if (f.bn_size && $('#omSizes') && !$('#omSizes').hidden && !f.bn_size.value) msg = T.errSize;
      if (msg) { e.preventDefault(); toast(msg, true); return; }
      f.querySelector('button[type="submit"]').disabled = true;
    });
  }
  if (!$('#orderModal')) initOrderForm();   // checkout page

  /* ---------- "اطلب الآن" popup ---------- */
  const om = $('#orderModal');
  function openOrder(d, color, qty, size) {
    if (!om) { location.href = 'product.php?id=' + d.id; return; }
    initOrderForm();
    $('#omId').value = d.id;
    $('#omImg').src = d.img; $('#omImg').alt = d.name;
    $('#omName').textContent = d.name;
    $('#omUnit').textContent = fmt(d.price);
    const q = $('#omQty');
    q.max = d.max; q.value = Math.min(d.max, Math.max(1, qty || 1));
    om.dataset.price = d.price;
    // colours: one -> fixed, several -> the customer picks in the popup
    const box = $('#omColors'), hid = $('#omColor');
    box.innerHTML = '';
    hid.value = d.colors.length === 1 ? d.colors[0][0] : (color || '');
    box.hidden = d.colors.length < 2;
    if (d.colors.length > 1) {
      const lbl = document.createElement('span');
      lbl.className = 'om-clabel';
      const cur = d.colors.find(c => c[0] === hid.value);
      lbl.textContent = T.color + (cur ? cur[1] : T.pickColor);
      box.append(lbl);
      d.colors.forEach(([hex, name]) => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'om-sw' + (hex === hid.value ? ' active' : '');
        b.title = name; b.setAttribute('aria-label', name); b.style.background = hex;
        b.addEventListener('click', () => {
          hid.value = hex; lbl.textContent = T.color + name;
          $$('.om-sw', box).forEach(x => x.classList.toggle('active', x === b));
        });
        box.append(b);
      });
    }
    // sizes: one -> fixed, several -> the customer picks in the popup
    const sizes = d.sizes || [];
    const sbox = $('#omSizes'), shid = $('#omSize');
    if (sbox && shid) {
      sbox.innerHTML = '';
      shid.value = sizes.length === 1 ? sizes[0] : (sizes.includes(size) ? size : '');
      sbox.hidden = sizes.length < 2;
      if (sizes.length > 1) {
        const sl = document.createElement('span');
        sl.className = 'om-clabel';
        sl.textContent = T.size + (shid.value || T.pickSize);
        sbox.append(sl);
        sizes.forEach(sv => {
          const b = document.createElement('button');
          b.type = 'button'; b.className = 'om-sz' + (sv === shid.value ? ' active' : '');
          b.textContent = sv;
          b.addEventListener('click', () => {
            shid.value = sv; sl.textContent = T.size + sv;
            $$('.om-sz', sbox).forEach(x => x.classList.toggle('active', x === b));
          });
          sbox.append(b);
        });
      }
    }
    updateOrderTotal();
    om.hidden = false;
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => om.classList.add('show'));
    setTimeout(() => $('input[name="name"]', om)?.focus({ preventScroll: true }), 250);
  }
  function updateOrderTotal() {
    const q = $('#omQty');
    const qty = Math.min(+q.max || 20, Math.max(1, +q.value || 1));
    q.value = qty;
    const st = $('#subtotal');
    st.dataset.v = qty * (+om.dataset.price || 0);
    st.textContent = fmt(st.dataset.v);
    recalc();
  }
  function closeOrder() {
    if (!om || om.hidden) return;
    om.classList.remove('show');
    document.body.style.overflow = '';
    setTimeout(() => { om.hidden = true; }, 200);
  }
  if (om) {
    $$('[data-om-close]', om).forEach(b => b.addEventListener('click', closeOrder));
    $$('[data-om-qty]', om).forEach(b => b.addEventListener('click', () => {
      $('#omQty').value = (+$('#omQty').value || 1) + (+b.dataset.omQty);
      updateOrderTotal();
    }));
    $('#omQty').addEventListener('change', updateOrderTotal);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeOrder(); });
  }
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-order]');
    if (!b) return;
    e.preventDefault();
    try { openOrder(JSON.parse(b.dataset.order), '', 1); } catch (err) { /* malformed data */ }
  });

})();
