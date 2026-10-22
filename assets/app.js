/* FOMAXO Store System - staff app (works offline for cash, Tap to Pay and card-machine sales). */
(function () {
  'use strict';

  // ---------------------------------------------------------------- helpers
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var LS = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* storage full or blocked */ } },
    del: function (k) { try { localStorage.removeItem(k); } catch (e) { /* ignore */ } }
  };
  function money(f) { return (Number(f || 0) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function aed(f) { return 'AED ' + money(f); }
  function toFils(v) { var n = parseFloat(String(v).replace(/,/g, '')); return isNaN(n) ? 0 : Math.round(n * 100); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { d = d || new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function stamp(d) { d = d || new Date(); return ymd(d) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); }
  function niceDate(s) {
    var d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return esc(s);
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return pad(d.getDate()) + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() + ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }
  function niceTime(s) { var d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? '' : pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function randHex(bytes) {
    var a = new Uint8Array(bytes); crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return (b < 16 ? '0' : '') + b.toString(16); }).join('');
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var h = randHex(16).split('');
    h[12] = '4'; h[16] = '89ab'[parseInt(h[16], 16) & 3];
    h = h.join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }
  // Customer names are written in capitals: aisha khan -> AISHA KHAN
  function titleCase(n) {
    return String(n || '').trim().replace(/\s+/g, ' ').toUpperCase();
  }
  function initials(n) { return String(n || '?').trim().split(/\s+/).map(function (w) { return w[0]; }).join('').slice(0, 2).toUpperCase(); }
  function pName(p) { return p.name + (p.variant ? ' · ' + p.variant : ''); }

  var WORDMARK = '<svg class="wm" viewBox="0 0 3196 481" role="img" aria-label="FOMAXO"><path fill="currentColor" fill-rule="evenodd" d="M1068 147 1068 480 1135 480 1135 214ZM1778 123 1581 480 1672 479 1815 221 1823 204ZM0 480 68 480 69 257 377 257 377 189 69 189 68 105 0 105ZM2934 1 2911 4 2871 15 2837 31 2801 56 2768 90 2743 128 2726 168 2720 191 2716 218 2716 263 2723 303 2739 346 2750 366 2766 389 2776 401 2802 426 2837 450 2864 463 2890 472 2926 479 2973 480 3003 476 3023 471 3048 462 3069 452 3108 426 3141 393 3166 356 3185 311 3194 267 3194 215 3186 174 3178 151 3165 124 3154 106 3138 85 3110 57 3089 41 3069 29 3027 11 2998 4 2976 1ZM2933 70 2966 69 3005 76 3038 90 3064 108 3087 131 3105 157 3120 192 3126 222 3126 259 3120 289 3107 321 3092 344 3064 373 3039 390 3013 402 2976 411 2934 411 2902 404 2869 389 2847 374 2822 349 2803 321 2789 287 2784 262 2783 229 2790 191 2805 157 2823 131 2847 107 2872 90 2901 77ZM2623 1 2538 1 2431 145 2421 161 2463 217ZM2198 1 2366 233 2184 480 2269 480 2411 293 2417 299 2550 480 2635 480 2276 1ZM722 1 677 9 654 17 625 31 590 55 555 91 533 124 520 151 513 171 504 216 504 265 513 311 531 354 557 393 591 427 623 449 647 461 671 470 714 479 760 480 785 477 811 471 855 453 897 425 928 394 948 366 958 348 974 307 982 265 982 217 973 171 954 126 939 102 925 84 893 53 869 36 851 26 815 11 786 4 763 1ZM721 70 754 69 793 76 829 92 848 105 873 129 894 159 908 193 914 224 914 258 907 292 893 324 876 349 854 371 825 391 792 405 764 411 721 411 693 405 660 391 635 374 609 348 592 323 580 297 572 264 571 227 577 194 583 177 594 155 610 132 636 106 658 91 689 77ZM0 1 0 69 423 69 423 1ZM1847 0 1802 82 2022 480 2113 480ZM1068 0 1068 97 1282 311 1425 169 1426 170 1426 480 1494 480 1494 4 1282 215Z"/></svg>';
  var ICON = {
    sliders: '<svg class="ic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>',
    sell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>',
    stock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/></svg>',
    sales: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/></svg>',
    reports: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
    staff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/></svg>',
    search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>',
    close: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>'
  };

  // ---------------------------------------------------------------- light / dark
  ICON.sun = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
  ICON.moon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>';
  var darkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function themeChoice() { var t = LS.get('fx_theme', 'auto'); return t === 'light' || t === 'dark' ? t : 'auto'; }
  function isDark() { var t = themeChoice(); return t === 'auto' ? !(darkQuery && !darkQuery.matches) : t === 'dark'; }
  function applyTheme() {
    var t = themeChoice();
    if (t === 'auto') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', t);
    var meta = document.querySelector('meta[name=theme-color]');
    if (meta) meta.setAttribute('content', isDark() ? '#0a0a0b' : '#fbf9f4');
    var b = document.getElementById('theme-btn');
    if (b) { b.innerHTML = isDark() ? ICON.sun : ICON.moon; b.setAttribute('aria-label', isDark() ? 'Switch to light mode' : 'Switch to dark mode'); b.title = b.getAttribute('aria-label'); }
  }
  function setTheme(t) { LS.set('fx_theme', t); applyTheme(); }
  if (darkQuery && darkQuery.addEventListener) darkQuery.addEventListener('change', applyTheme);

  // ---------------------------------------------------------------- state
  var S = {
    token: LS.get('fx_token', null),
    user: LS.get('fx_user', null),
    settings: LS.get('fx_settings', null),
    stores: LS.get('fx_stores', []),
    store: LS.get('fx_store', null),
    stockScope: 'store', salesStore: 'all', reportStore: 'all',
    sellView: LS.get('fx_sell_view', 'grid'), sellSort: LS.get('fx_sell_sort', 'az'),
    teamTab: 'performance', team: { preset: 'today', from: null, to: null, user: '' },
    products: LS.get('fx_products', []),
    queue: LS.get('fx_queue', []),
    online: navigator.onLine,
    view: 'sell',
    cart: [], discount: null, customer: null, coupon: null,
    category: 'All', search: '',
    stockFilter: 'all', stockSearch: '',
    salesDate: ymd(),
    report: { preset: 'today', from: ymd(), to: ymd() },
    syncing: false, cartOpen: false
  };
  function isManager() { return S.user && S.user.role === 'manager'; }
  function activeStores() { return S.stores.filter(function (x) { return x.active; }); }
  function storeLocked() { return !isManager() && S.user && S.user.store_id; }
  // The store this device is selling from.
  function curStoreId() {
    if (storeLocked()) return S.user.store_id;
    var act = activeStores();
    for (var i = 0; i < act.length; i++) if (act[i].id === S.store) return S.store;
    return act.length ? act[0].id : null;
  }
  function storeById(id) { for (var i = 0; i < S.stores.length; i++) if (S.stores[i].id === id) return S.stores[i]; return null; }
  function curStore() { return storeById(curStoreId()) || { id: null, name: '', address: '', phone: '' }; }
  function multiStore() { return S.stores.length > 1; }
  function saveQueue() { LS.set('fx_queue', S.queue); }

  // ---------------------------------------------------------------- server
  function api(action, opts) {
    opts = opts || {};
    var ctrl = window.AbortController ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, opts.timeout || 15000);
    var headers = { 'Accept': 'application/json' };
    if (S.token) headers['X-Auth-Token'] = S.token;
    if (opts.body) headers['Content-Type'] = 'application/json';
    return fetch('api.php?a=' + action + (opts.query || ''), {
      method: opts.body ? 'POST' : 'GET', headers: headers, cache: 'no-store',
      body: opts.body ? JSON.stringify(opts.body) : undefined,
      signal: ctrl ? ctrl.signal : undefined
    }).then(function (res) {
      clearTimeout(timer);
      setOnline(true);
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          var err = new Error(data.error || ('Server error ' + res.status));
          err.status = res.status;
          if (res.status === 401 && action !== 'login') sessionExpired();
          throw err;
        }
        return data;
      });
    }, function () {
      clearTimeout(timer);
      setOnline(false);
      var err = new Error('No internet connection.');
      err.offline = true;
      throw err;
    });
  }

  function sessionExpired() {
    S.token = null; LS.del('fx_token');
    closeModal();
    renderLogin('Your login has expired. Please log in again.' + (S.queue.length ? ' Your ' + S.queue.length + ' waiting sale(s) are kept safe on this device.' : ''));
  }

  function setOnline(v) {
    if (S.online === v) return;
    S.online = v;
    updateNet();
    if (S.view === 'sell' && $('#cart')) renderCart();
    if (v) { syncQueue(); refreshProducts(); }
  }

  function refreshProducts() {
    if (!S.token) return Promise.resolve();
    return api('products', { query: '&store=' + (curStoreId() || '') }).then(function (d) {
      S.products = d.products;
      LS.set('fx_products', S.products);
      preloadPhotos();
      if (S.view === 'sell' && $('#tiles')) { renderChips(); renderTiles(); renderCart(); }
      if (S.view === 'stock' && $('#stock-body')) renderStockBody();
      updateStoreBtn();
      updateNavCounts();
    }).catch(function () { /* keep cached list */ });
  }

  function refreshMe() {
    return api('me').then(function (d) {
      S.user = d.user; S.settings = d.settings; S.stores = d.stores || [];
      LS.set('fx_user', S.user); LS.set('fx_settings', S.settings); LS.set('fx_stores', S.stores);
      updateStoreBtn();
    }).catch(function () { /* offline: use cached */ });
  }

  // ---------------------------------------------------------------- offline queue
  function syncQueue() {
    if (S.syncing || !S.token || !S.queue.length) { updateNet(); return Promise.resolve(); }
    S.syncing = true; updateNet();
    var uploaded = 0;
    var list = S.queue.slice();
    var chain = Promise.resolve();
    var stop = false;
    list.forEach(function (item) {
      chain = chain.then(function () {
        if (stop || item.error) return;
        return api('sale', { body: item.payload }).then(function () {
          S.queue = S.queue.filter(function (q) { return q.payload.uuid !== item.payload.uuid; });
          saveQueue(); uploaded++;
        }, function (e) {
          if (e.offline || e.status === 401 || e.status >= 500) { stop = true; return; }
          item.error = e.message; saveQueue();
        });
      });
    });
    return chain.then(function () {
      S.syncing = false; updateNet(); updateNavCounts();
      if (uploaded) {
        toast(uploaded + ' offline sale' + (uploaded > 1 ? 's' : '') + ' uploaded.');
        refreshProducts();
        if (S.view === 'sales') renderSales();
      }
    });
  }

  // ---------------------------------------------------------------- money maths (same as server)
  function couponFils(sub) {
    var c = S.coupon; if (!c || c.kind === 'free') return 0;
    if (c.kind === 'pct') return Math.round(sub * Math.min(100, Math.max(0, c.amount)) / 100);
    return Math.min(sub, Math.round(c.amount * 100));
  }
  function couponMinOk(sub) { return !S.coupon || !S.coupon.min || sub >= Math.round(S.coupon.min * 100); }
  function cartTotals() {
    var lines = cartLines(), t0 = calcTotals(lines, S.discount);
    var cf = couponMinOk(t0.subtotal - t0.discount) ? couponFils(t0.subtotal - t0.discount) : 0;
    var t = calcTotals(lines, S.discount, cf);
    t.couponOk = couponMinOk(t0.subtotal - t0.discount);
    return t;
  }
  function calcTotals(lines, discount, coupon) {
    var st = S.settings || { vat_rate: 5, prices_include_vat: true };
    var subtotal = 0;
    lines.forEach(function (l) { subtotal += l.price * l.qty; });
    var disc = 0;
    if (discount && Number(discount.value) > 0) {
      if (discount.type === 'percent') disc = Math.round(subtotal * Math.min(100, Math.max(0, Number(discount.value))) / 100);
      else disc = Math.min(subtotal, toFils(discount.value));
    }
    coupon = Math.max(0, Math.min(coupon || 0, subtotal - disc));
    var after = subtotal - disc - coupon, rate = Number(st.vat_rate), vat, total;
    if (st.prices_include_vat) { vat = Math.round(after * rate / (100 + rate)); total = after; }
    else { vat = Math.round(after * rate / 100); total = after + vat; }
    return { subtotal: subtotal, discount: disc, coupon: coupon, vat: vat, total: total };
  }
  function cartLines() {
    return S.cart.map(function (c) {
      var p = productById(c.product_id) || { name: c.name, variant: c.variant, price: c.price };
      return { product_id: c.product_id, name: p.name, variant: p.variant, price: p.price, qty: c.qty };
    });
  }
  function productById(id) { for (var i = 0; i < S.products.length; i++) if (S.products[i].id === id) return S.products[i]; return null; }
  function wasHtml(p) { return p.compare_at > p.price ? '<s class="was num">' + money(p.compare_at) + '</s>' : ''; }
  function stockState(p) { return !p.active ? 'off' : p.stock <= 0 ? 'out' : p.stock <= p.low_at ? 'low' : 'ok'; }
  function stockBadge(p) {
    var s = stockState(p);
    var label = s === 'ok' ? p.stock + ' in stock' : s === 'low' ? 'Low · ' + p.stock + ' left' :
      s === 'out' ? (p.stock < 0 ? 'Out · recount (' + p.stock + ')' : 'Out of stock') : 'Switched off';
    return '<span class="badge ' + s + '">' + esc(label) + '</span>';
  }

  // ---------------------------------------------------------------- toast, modal, menu
  var toastTimer;
  function toast(msg, bad) {
    var old = $('.toast'); if (old) old.remove();
    var t = document.createElement('div');
    t.className = 'toast' + (bad ? ' bad' : ''); t.textContent = msg;
    document.body.appendChild(t);
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.remove(); }, bad ? 5000 : 3000);
  }
  var modalState = null;
  function openModal(title, html, opts) {
    closeModal();
    opts = opts || {};
    var back = document.createElement('div');
    back.className = 'modal-back';
    back.innerHTML = '<div class="modal' + (opts.wide ? ' wide' : '') + '" role="dialog" aria-modal="true">' +
      '<div class="modal-head"><h2>' + esc(title) + '</h2>' +
      (opts.noClose ? '' : '<button class="btn icon-btn ghost" data-act="modal-close" aria-label="Close">' + ICON.close + '</button>') +
      '</div><div class="modal-body">' + html + '</div></div>';
    if (!opts.sticky) back.addEventListener('mousedown', function (e) { if (e.target === back) closeModal(); });
    document.body.appendChild(back);
    modalState = { el: back, onClose: opts.onClose };
    placeSwitch(back);
    var first = $('[autofocus]', back); if (first) setTimeout(function () { first.focus(); }, 50);
    return back;
  }
  function closeModal() {
    if (!modalState) return;
    var m = modalState; modalState = null;
    m.el.remove();
    if (m.onClose) m.onClose();
  }
  function setModalBody(html) { if (modalState) { $('.modal-body', modalState.el).innerHTML = html; placeSwitch(modalState.el); } }
  /** The "Pay by" row sits at the bottom, just above the Done / Approved button. */
  function placeSwitch(root) {
    var sw = $('.pay-switch', root), foot = $('.modal-foot', root);
    if (sw && foot) foot.parentNode.insertBefore(sw, foot);
  }
  function modalErr(msg) {
    if (!modalState) { toast(msg, true); return; }
    var box = $('.m-err', modalState.el);
    if (box) { box.textContent = msg; box.classList.remove('hidden'); } else toast(msg, true);
  }
  function closeMenu() { var m = $('.menu'); if (m) m.remove(); }

  // ---------------------------------------------------------------- login
  function renderLogin(msg) {
    closeMenu();
    $('#root').innerHTML =
      '<div class="login-wrap"><div class="login">' +
      '<div class="h-brand">' + WORDMARK + '</div><div class="tag">Store system</div>' +
      '<form id="login-form" autocomplete="on">' +
      (msg ? '<div class="note" style="margin-bottom:12px">' + esc(msg) + '</div>' : '') +
      '<div class="err m-err hidden"></div>' +
      '<label class="field"><span>Username</span><input class="input" name="username" autocapitalize="characters" autocorrect="off" spellcheck="false" autocomplete="username" required autofocus value="' + esc(S.user ? S.user.username : '') + '"></label>' +
      '<label class="field"><span>Password</span><input class="input" name="password" type="password" autocomplete="current-password" required></label>' +
      '<button class="btn gold block big" type="submit">Log in</button>' +
      '<p class="small muted" style="margin:12px 0 0;text-align:center">Logging in needs internet. After that, sales keep working offline.</p>' +
      '</form></div></div>';
    $('#login-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target, btn = $('button', f), err = $('.m-err', f);
      btn.disabled = true; err.classList.add('hidden');
      var prevUser = S.user;
      api('login', { body: { username: f.username.value, password: f.password.value } }).then(function (d) {
        if (prevUser && prevUser.id !== d.user.id && S.queue.length) {
          // Waiting sales stay with the device; they upload under the new login.
        }
        S.token = d.token; S.user = d.user;
        LS.set('fx_token', S.token); LS.set('fx_user', S.user);
        return refreshMe().then(refreshProducts).then(function () { S.view = 'sell'; renderApp(); syncQueue(); });
      }).catch(function (e2) {
        btn.disabled = false;
        err.textContent = e2.offline ? 'No internet. Connect to Wi-Fi or mobile data to log in.' : e2.message;
        err.classList.remove('hidden');
      });
    });
  }

  // ---------------------------------------------------------------- app shell
  function navItems() {
    var items = [['sell', 'Sell'], ['stock', 'Stock'], ['sales', 'Sales']];
    if (isManager()) items.push(['reports', 'Reports'], ['staff', 'Team']);
    return items;
  }
  function navButtons() {
    return navItems().map(function (n) {
      return '<button data-act="nav" data-view="' + n[0] + '" class="' + (S.view === n[0] ? 'active' : '') + '">' + ICON[n[0]] + '<span>' + n[1] + '</span><span class="count hidden" data-count="' + n[0] + '"></span></button>';
    }).join('');
  }
  function renderApp() {
    if (!S.token || !S.user || !S.settings) { renderLogin(); return; }
    $('#root').innerHTML =
      '<div class="app"><header class="topbar">' +
      '<div class="h-brand">' + WORDMARK + '</div>' +
      '<nav class="nav" id="nav">' + navButtons() + '</nav>' +
      '<div class="spacer"></div>' +
      '<button class="store-btn hidden" id="store-btn" data-act="store-menu"></button>' +
      '<button class="net" id="net" data-act="net"></button>' +
      '<button class="btn icon-btn ghost theme-btn" id="theme-btn" data-act="theme-toggle"></button>' +
      '<button class="user-btn" data-act="user-menu"><span class="avatar">' + esc(initials(S.user.name)) + '</span><span class="u-name">' + esc(S.user.name) + '</span></button>' +
      '</header><main class="view" id="view"></main>' +
      '<nav class="bnav" id="bnav">' + navButtons() + '</nav></div>';
    updateNet(); updateNavCounts(); applyTheme(); updateStoreBtn(); renderView();
  }
  function updateStoreBtn() {
    var b = $('#store-btn'); if (!b) return;
    var show = multiStore() || (S.stores.length === 1 && isManager());
    b.classList.toggle('hidden', !show || !curStoreId());
    b.innerHTML = '<span class="pin"></span><span class="s-name">' + esc(curStore().name) + '</span>' + (storeLocked() ? '' : '<span class="caret">▾</span>');
    b.disabled = !!storeLocked();
  }
  function storeMenu(btn) {
    closeMenu();
    if (storeLocked()) return;
    var r = btn.getBoundingClientRect();
    var m = document.createElement('div');
    m.className = 'menu';
    m.style.top = (r.bottom + 6) + 'px'; m.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 240)) + 'px';
    m.innerHTML = '<div class="m-head"><b>Selling from</b><div class="small muted">Sales and stock on this device use this store</div></div>' +
      activeStores().map(function (x) {
        return '<button data-act="store-pick" data-id="' + x.id + '">' + (x.id === curStoreId() ? '✓ ' : '') + esc(x.name) + '</button>';
      }).join('') + (isManager() ? '<button data-act="nav" data-view="staff" data-tab="stores" class="muted">Manage stores…</button>' : '');
    document.body.appendChild(m);
  }
  function pickStore(id) {
    if (S.cart.length && !confirm('Switch store? The current cart will be cleared.')) return;
    S.store = id; LS.set('fx_store', id);
    S.cart = []; S.discount = null; S.customer = null;
    closeMenu(); updateStoreBtn();
    refreshProducts().then(function () { renderView(); });
    toast('Now selling from ' + curStore().name + '.');
  }
  function updateNet() {
    var el = $('#net'); if (!el) return;
    var n = S.queue.length;
    el.className = 'net' + (S.online ? '' : ' off');
    el.textContent = S.online ? (S.syncing ? 'Uploading…' : n ? 'Online · ' + n + ' to upload' : 'Online') : 'Offline' + (n ? ' · ' + n + ' waiting' : '');
    updateNavCounts();
  }
  function updateNavCounts() {
    var low = S.products.filter(function (p) { var s = stockState(p); return s === 'low' || s === 'out'; }).length;
    $$('[data-count="stock"]').forEach(function (c1) { c1.textContent = low; c1.classList.toggle('hidden', !low); });
    $$('[data-count="sales"]').forEach(function (c2) { c2.textContent = S.queue.length; c2.classList.toggle('hidden', !S.queue.length); });
  }
  function renderView() {
    $$('#nav button, #bnav button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-view') === S.view); });
    var v = $('#view'); v.scrollTop = 0;
    if (S.view === 'sell') renderSell();
    else if (S.view === 'stock') renderStock();
    else if (S.view === 'sales') renderSales();
    else if (S.view === 'reports') renderReports();
    else if (S.view === 'staff') renderStaff();
  }

  // ---------------------------------------------------------------- SELL
  function renderSell() {
    $('#view').innerHTML =
      '<div class="sell"><section class="catalog">' +
      '<div class="catalog-tools"><label class="search">' + ICON.search +
      '<input class="input" id="q" type="search" placeholder="Search product, size or SKU" value="' + esc(S.search) + '" autocomplete="off"></label>' +
      '<button class="sort-btn' + (S.sellView !== 'grid' || S.sellSort !== 'az' ? ' on' : '') + '" data-act="sort-open" aria-label="View and sort" title="View and sort">' + ICON.sliders + '<span id="sort-lbl" class="sort-lbl">' + esc(viewLabel()) + '</span></button>' +
      '<div class="chips" id="chips"></div></div>' +
      '<div class="ptiles' + (S.sellView === 'list' ? ' list' : '') + '" id="tiles"></div></section>' +
      '<aside class="cart' + (S.cartOpen ? ' open' : '') + '" id="cart"></aside></div>' +
      '<button class="cart-fab hidden" id="cart-fab" data-act="cart-open"></button>';
    $('#q').addEventListener('input', function (e) { S.search = e.target.value; renderTiles(); });
    renderChips(); renderTiles(); renderCart();
  }
  function categories() {
    var cats = [];
    S.products.forEach(function (p) { if (p.active && p.category && cats.indexOf(p.category) < 0) cats.push(p.category); });
    return ['All'].concat(cats.sort());
  }
  function renderChips() {
    var el = $('#chips'); if (!el) return;
    var cats = categories();
    if (cats.indexOf(S.category) < 0) S.category = 'All';
    el.innerHTML = cats.length > 2 ? cats.map(function (c) {
      return '<button class="chip' + (c === S.category ? ' active' : '') + '" data-act="cat" data-cat="' + esc(c) + '">' + esc(c) + '</button>';
    }).join('') : '';
  }
  // Products are shown as one photo tile per fragrance; tapping it asks for the size.
  function groupKey(p) { return p.from_site && /-\d+$/.test(p.sku) ? p.sku.replace(/-\d+$/, '') : 'p' + p.id; }
  function shortName(n) { return String(n || '').replace(/^fomaxo\s+/i, ''); }
  function sizeNum(v) { var m = /(\d+)/.exec(v || ''); return m ? parseInt(m[1], 10) : 0; }
  function productGroups() {
    var q = S.search.trim().toLowerCase(), map = {}, order = [];
    S.products.forEach(function (p) {
      if (!p.active) return;
      if (S.category !== 'All' && p.category !== S.category) return;
      if (q && (p.name + ' ' + p.variant + ' ' + p.sku + ' ' + p.family).toLowerCase().indexOf(q) < 0) return;
      var k = groupKey(p);
      if (!map[k]) { map[k] = { key: k, name: shortName(p.name), family: p.family, image: '', sizes: [] }; order.push(k); }
      map[k].sizes.push(p);
      if (!map[k].image && p.image) map[k].image = p.image;
    });
    return order.map(function (k) {
      var g = map[k];
      g.sizes.sort(function (a, b) { return sizeNum(a.variant) - sizeNum(b.variant); });
      return g;
    }).map(function (g) {
      g.from = Infinity; g.sold = 0; g.stock = 0;
      g.sizes.forEach(function (p) { if (p.price < g.from) g.from = p.price; g.sold += p.sold30 || 0; g.stock += Math.max(0, p.stock); });
      return g;
    }).sort(sortGroups);
  }
  var SORTS = { az: 'A–Z', best: 'Best sellers', low: 'Price: low → high', high: 'Price: high → low', stock: 'In stock first' };
  function sortGroups(a, b) {
    var byName = a.name.localeCompare(b.name);
    switch (S.sellSort) {
      case 'best': return (b.sold - a.sold) || byName;
      case 'low': return (a.from - b.from) || byName;
      case 'high': return (b.from - a.from) || byName;
      case 'stock': return ((b.stock > 0) - (a.stock > 0)) || (b.stock - a.stock) || byName;
      default: return byName;
    }
  }
  function viewLabel() { return (S.sellView === 'list' ? 'List' : 'Grid') + ' · ' + (SORTS[S.sellSort] || SORTS.az); }
  function sortModal() {
    var icons = { az: '🔤', best: '⭐', low: '⬆', high: '⬇', stock: '✅' };
    var sub = { az: 'By name', best: 'Most sold in the last 30 days', low: 'Cheapest first', high: 'Most expensive first', stock: 'Available perfumes on top' };
    openModal('View & sort', '<div class="set-h">Show as</div>' +
      '<div class="view-pick"><button class="pchoice slim' + (S.sellView === 'grid' ? ' picked' : '') + '" data-act="sv" data-v="grid"><span class="pi">▦</span><b>Grid' + (S.sellView === 'grid' ? ' ✓' : '') + '</b><small>Big photos</small></button>' +
      '<button class="pchoice slim' + (S.sellView === 'list' ? ' picked' : '') + '" data-act="sv" data-v="list"><span class="pi">☰</span><b>List' + (S.sellView === 'list' ? ' ✓' : '') + '</b><small>More on screen</small></button></div>' +
      '<div class="set-h" style="margin-top:16px">Sort by</div><div class="more-choices">' + Object.keys(SORTS).map(function (k) {
        return '<button class="pchoice slim' + (S.sellSort === k ? ' picked' : '') + '" data-act="sort-set" data-k="' + k + '"><span class="pi">' + icons[k] + '</span><b>' + esc(SORTS[k]) + (S.sellSort === k ? ' ✓' : '') + '</b><small>' + sub[k] + '</small></button>';
      }).join('') + '</div>');
  }
  function syncSortBtn() {
    var b = $('.sort-btn'); if (!b) return;
    b.classList.toggle('on', S.sellView !== 'grid' || S.sellSort !== 'az');
    if ($('#sort-lbl')) $('#sort-lbl').textContent = viewLabel();
  }
  function thumb(url, w) { return url ? url + (url.indexOf('?') >= 0 ? '&' : '?') + 'w=' + w : ''; }
  function tileImg(g) {
    return g.image ? '<img src="' + esc(thumb(g.image, 360)) + '" alt="" decoding="async" onerror="this.remove()">' : '';
  }
  var preloaded = {};
  function preloadPhotos() {
    S.products.forEach(function (p) {
      if (!p.image || !p.active) return;
      [360, 120].forEach(function (w) {
        var u = thumb(p.image, w);
        if (preloaded[u]) return;
        preloaded[u] = 1; var im = new Image(); im.decoding = 'async'; im.src = u;
      });
    });
  }
  function renderTiles() {
    var el = $('#tiles'); if (!el) return;
    var groups = productGroups();
    if (!groups.length) {
      var any = S.products.some(function (p) { return p.active; });
      el.innerHTML = '<div class="empty" style="grid-column:1/-1"><div class="big">' + (any ? 'No products match' : 'No products for sale yet') + '</div>' +
        (any ? 'Try a different search.' : isManager() ? 'Go to <b>Stock</b> to add products.' : 'Ask a manager to add products.') + '</div>';
      return;
    }
    el.innerHTML = groups.map(function (g) {
      var inCart = 0, from = Infinity, anyStock = false;
      g.sizes.forEach(function (p) {
        S.cart.forEach(function (c) { if (c.product_id === p.id) inCart += c.qty; });
        if (p.price < from) from = p.price;
        if (p.stock > 0) anyStock = true;
      });
      var sizes = g.sizes.map(function (p) { return esc(p.variant); }).filter(Boolean).join(' · ');
      return '<button class="ptile' + (anyStock ? '' : ' is-out') + '" data-act="pick" data-g="' + esc(g.key) + '">' +
        '<span class="ph">' + tileImg(g) + '<span class="ph-letter">' + esc(g.name.charAt(0)) + '</span>' +
        (inCart ? '<span class="in-cart">' + inCart + '</span>' : '') + (anyStock ? '' : '<span class="sold">SOLD OUT</span>') + '</span>' +
        '<span class="pt-name">' + esc(g.name) + '</span>' +
        '<span class="pt-sub">' + sizes + '</span>' +
        '<span class="pt-price">' + (g.sizes.length > 1 ? '<small>from</small> ' : '') + money(from) + '</span></button>';
    }).join('');
  }
  function pickGroup(key) {
    var g = productGroups().filter(function (x) { return x.key === key; })[0];
    if (!g) return;
 openModal(g.name, '<div class="pick-head">' + (g.image ? '<img src="' + esc(thumb(g.image, 360)) + '" alt="" onerror="this.remove()">' : '') +
      '<div class="muted">' + esc(g.family || '') + '<br><b class="pick-q">' + (g.sizes.length > 1 ? 'Which size?' : 'Tap to add to the bag') + '</b></div></div>' +
      '<div class="sizes">' + g.sizes.map(function (p) {
        var st = stockState(p), inCart = 0;
        S.cart.forEach(function (c) { if (c.product_id === p.id) inCart = c.qty; });
        return '<button class="size-btn' + (st === 'out' ? ' is-out' : '') + '" data-act="add-size" data-id="' + p.id + '">' +
          '<span class="sz">' + esc(p.variant) + '</span><span class="sp">' + wasHtml(p) + money(p.price) + '</span>' +
          '<span class="ss ' + st + '">' + (st === 'out' ? 'SOLD OUT' : st === 'low' ? 'Only ' + p.stock + ' left' : p.stock + ' in stock') + '</span>' +
          (inCart ? '<span class="in-cart">' + inCart + '</span>' : '') + '</button>';
      }).join('') + '</div>');
  }
  function addToCart(id) {
    var p = productById(id); if (!p) return false;
    var line = null; S.cart.forEach(function (c) { if (c.product_id === id) line = c; });
    var want = (line ? line.qty : 0) + 1;
    if (want > p.stock) {
      if (S.online) { toast(p.stock <= 0 ? shortName(p.name) + ' ' + p.variant + ' is SOLD OUT.' : 'Only ' + p.stock + ' left of ' + shortName(p.name) + ' ' + p.variant + '.', true); return false; }
      toast('Stock shows ' + Math.max(0, p.stock) + ' — added anyway because you are offline.', true);
    }
    var before = snapshotBag();
    if (line) line.qty = want; else S.cart.push({ product_id: id, qty: 1 });
    renderTiles(); renderCart();
    flashAdded(shortName(p.name) + ' ' + p.variant, function () { restoreBag(before); });
    return true;
  }
  var addedTimer;
  var undoFn = null;
  function flashAdded(label, undo, removed) {
    var old = $('.added-pop'); if (old) old.remove();
    var t = document.createElement('div');
    t.className = 'added-pop' + (removed ? ' removed' : '');
    t.innerHTML = '<span class="tick">' + (removed ? '−' : '✓') + '</span> ' + (removed ? 'Removed: ' : 'Added: ') + esc(label) +
      (undo ? '<button class="undo-btn" data-act="undo">UNDO</button>' : '');
    document.body.appendChild(t);
    undoFn = undo || null;
    clearTimeout(addedTimer); addedTimer = setTimeout(function () { t.remove(); undoFn = null; }, undo ? 4000 : 1400);
  }
  function snapshotBag() { return JSON.stringify({ cart: S.cart, coupon: S.coupon, discount: S.discount, customer: S.customer }); }
  function restoreBag(snap) { var o = JSON.parse(snap); S.cart = o.cart; S.coupon = o.coupon; S.discount = o.discount; S.customer = o.customer; renderTiles(); renderCart(); }
  function changeQty(id, d) {
    var before = snapshotBag(), removedName = '';
    S.cart.forEach(function (c) {
      if (c.product_id === id && c.qty + d <= 0) { var rp = productById(id); removedName = rp ? shortName(rp.name) + ' ' + rp.variant : 'item'; }
    });
    S.cart.forEach(function (c) {
      if (c.product_id !== id) return;
      var p = productById(id);
      if (d > 0 && p && c.qty + 1 > p.stock && S.online) { toast('Only ' + p.stock + ' in stock.', true); return; }
      c.qty += d;
    });
    S.cart = S.cart.filter(function (c) { return c.qty > 0; });
    renderTiles(); renderCart();
    if (removedName) flashAdded(removedName, function () { restoreBag(before); }, true);
  }
  function renderCart() {
    var el = $('#cart'); if (!el) return;
    var lines = cartLines(), t = cartTotals(), count = 0;
    lines.forEach(function (l) { count += l.qty; });
    var empty = !lines.length, st = S.settings, c = S.customer, d = S.discount;
    var extras = [];
    if (c) extras.push('Customer: ' + esc(c.name || c.phone) + (c.tier ? ' · ' + esc(c.tier) : ''));
    if (S.coupon) extras.push('Coupon: ' + esc(S.coupon.code));
    if (d) extras.push('Discount: ' + (d.type === 'percent' ? d.value + '%' : aed(toFils(d.value))));
    el.innerHTML =
      '<div class="cart-head"><h2>Customer’s bag</h2><div class="row">' +
      (empty ? '' : '<button class="link-btn" data-act="cart-clear">Empty</button>') +
      '<button class="btn icon-btn ghost show-sm" data-act="cart-close" aria-label="Close">' + ICON.close + '</button></div></div>' +
      '<div class="cart-lines">' + (empty ? '<div class="empty"><div class="big">Bag is empty</div>Tap a perfume to add it.</div>' :
        lines.map(function (l) {
          var p = productById(l.product_id) || {};
          return '<div class="cline">' + (p.image ? '<img src="' + esc(thumb(p.image, 120)) + '" alt="" onerror="this.remove()">' : '<span class="cl-ph">' + esc(shortName(l.name).charAt(0)) + '</span>') +
            '<div class="cl-mid"><b>' + esc(shortName(l.name)) + '</b><span>' + esc(l.variant) + ' · ' + money(l.price) + '</span></div>' +
            '<div class="qty big"><button data-act="qty" data-id="' + l.product_id + '" data-d="-1" aria-label="One less">−</button><span>' + l.qty + '</span>' +
            '<button data-act="qty" data-id="' + l.product_id + '" data-d="1" aria-label="One more">+</button></div></div>';
        }).join('') + freeLineHtml()) + '</div>' +
      '<div class="cart-foot">' +
      (empty ? '' : '<div class="foot-btns"><button class="fbtn' + (c ? ' on' : '') + '" data-act="customer"><span>👤</span>' + (c ? esc(c.name || c.phone || c.email) : 'Customer') + '</button>' +
        '<button class="fbtn' + (S.coupon || d ? ' on' : '') + '" data-act="more2"><span>🎟</span>' + (S.coupon ? esc(S.coupon.code) : d ? 'Discount' : 'Coupon') + '</button></div>') +
      (t.discount ? '<div class="t-row"><span>Discount</span><span class="num">−' + money(t.discount) + '</span></div>' : '') +
      (S.coupon && !empty ? '<div class="t-row coupon-row"><span>🎟 ' + esc(S.coupon.code) + (S.coupon.kind === 'free' ? ' — free gift added' : '') + '</span><span class="num">' +
        (!t.couponOk ? 'needs AED ' + money(Math.round(S.coupon.min * 100)) : t.coupon ? '−' + money(t.coupon) : 'FREE') + '</span></div>' : '') +
      (st.vat_rate > 0 && !empty ? '<div class="t-row"><span>VAT ' + st.vat_rate + '%' + (st.prices_include_vat ? ' (included)' : '') + '</span><span class="num">' + money(t.vat) + '</span></div>' : '') +
      '<button class="pay-big" data-act="pay-open"' + (empty ? ' disabled' : '') + '><span>PAY</span><b class="num">' + aed(t.total) + '</b></button>' +
      '</div>';
    var fab = $('#cart-fab');
    if (fab) {
      fab.innerHTML = '<span>🛍 ' + (count ? count + ' item' + (count === 1 ? '' : 's') : 'Bag') + '</span><span>' + (count ? 'PAY ' + aed(t.total) : 'Open') + '</span>';
      fab.classList.toggle('hidden', S.cartOpen || !count);
    }
  }
  function freeLineHtml() {
    var c = S.coupon; if (!c || c.kind !== 'free' || !c.free) return '';
    var p = productById(c.free.product_id) || { name: c.free.name, variant: '' };
    return '<div class="cline free">' + (p.image ? '<img src="' + esc(thumb(p.image, 120)) + '" alt="">' : '<span class="cl-ph">🎁</span>') +
      '<div class="cl-mid"><b>' + esc(shortName(p.name)) + '</b><span>' + esc(p.variant) + ' · Free with coupon</span></div><b class="free-tag">FREE</b></div>';
  }
  function couponModal() {
    var c = S.coupon;
    openModal('Coupon code', '<div class="err m-err hidden"></div>' +
      (c ? '<div class="note" style="margin-bottom:12px">🎟 <b>' + esc(c.code) + '</b> — ' + esc(c.label || '') + (c.min ? ' (min AED ' + money(Math.round(c.min * 100)) + ')' : '') + '</div>' : '') +
      '<label class="field"><span>Type the code from the customer</span><input class="input coupon-in" id="cp-code" autocapitalize="characters" autocomplete="off" spellcheck="false" placeholder="e.g. WELCOME20" value="' + esc(c ? c.code : '') + '" autofocus></label>' +
      (S.online ? '' : '<p class="small" style="color:var(--low)">Coupons need internet to check.</p>') +
      '<p class="small muted">Expired, used-up or wrong codes are refused automatically.</p>' +
      '<div class="modal-foot">' + (c ? '<button class="btn danger" data-act="cp-remove">Remove</button>' : '') + '<button class="btn gold big" data-act="cp-apply"' + (S.online ? '' : ' disabled') + '>Apply</button></div>');
  }
  function payChooser() {
    var t = cartTotals(), st = S.settings;
    var qrOk = st.ziina && S.online;
    openModal('How is the customer paying?', '<div class="amount-big"><div class="lbl">Total</div><div class="val num">' + aed(t.total) + '</div></div>' +
      '<div class="pay-choices">' +
      '<button class="pchoice cash" data-act="pay" data-m="cash"><span class="pi">💵</span><b>Cash</b><small>Notes and coins</small></button>' +
      '<button class="pchoice" data-act="pay" data-m="tap"><span class="pi">💳</span><b>Card / Tap</b><small>Card, Apple Pay, Google Pay on the Ziina phone</small></button>' +
      '<button class="pchoice" data-act="pay" data-m="ziina"' + (qrOk ? '' : ' disabled') + '><span class="pi">📱</span><b>QR code</b><small>' + (!st.ziina ? 'Not set up yet' : S.online ? 'Customer scans with their phone' : 'Needs internet') + '</small></button>' +
      '</div><button class="link-btn other-card" data-act="pay" data-m="card">Other card machine</button>');
  }
  function couponDiscountModal() {
    openModal('Coupon or discount', '<div class="more-choices">' +
      '<button class="pchoice" data-act="coupon"><span class="pi">🎟</span><b>Coupon code</b><small>' + (S.coupon ? esc(S.coupon.code) + ' — ' + esc(S.coupon.label || '') : 'Customer has a code') + '</small></button>' +
      '<button class="pchoice" data-act="discount"><span class="pi">🏷</span><b>Discount</b><small>' + (S.discount ? (S.discount.type === 'percent' ? S.discount.value + '% off' : aed(toFils(S.discount.value)) + ' off') : 'None') + '</small></button>' +
      '</div>');
  }
  function moreModal() {
    openModal('Customer, coupon & discount', '<div class="more-choices">' +
      '<button class="pchoice" data-act="customer"><span class="pi">👤</span><b>Customer</b><small>' + (S.customer ? esc(S.customer.name || S.customer.phone) : 'Add phone number (optional)') + '</small></button>' +
      '<button class="pchoice" data-act="coupon"><span class="pi">🎟</span><b>Coupon code</b><small>' + (S.coupon ? esc(S.coupon.code) + ' — ' + esc(S.coupon.label || '') : 'Customer has a code') + '</small></button>' +
      '<button class="pchoice" data-act="discount"><span class="pi">🏷</span><b>Discount</b><small>' + (S.discount ? (S.discount.type === 'percent' ? S.discount.value + '% off' : aed(toFils(S.discount.value)) + ' off') : 'None') + '</small></button>' +
      '</div>');
  }
  function setCartOpen(v) { S.cartOpen = v; var c = $('#cart'); if (c) c.classList.toggle('open', v); var f = $('#cart-fab'); if (f) f.classList.toggle('hidden', v); }

  function customerModal() {
    var c = S.customer || {};
    openModal('Customer', '<div class="err m-err hidden"></div>' +
      '<div class="field"><span>Mobile — type it and the customer is looked up</span><div class="phone-row">' +
        '<label class="cc-wrap" title="Country"><span class="cc-label" id="c-cc-label"></span><select id="c-cc" aria-label="Country code">' + countryOptions() + '</select></label>' +
        '<input class="input" id="c-phone" type="tel" inputmode="tel" autocomplete="off" autofocus></div></div>' +
      '<div id="c-info"></div>' +
      '<label class="field"><span>Name</span><input class="input cap" id="c-name" value="' + esc(c.name || '') + '" placeholder="CUSTOMER NAME" autocomplete="off" autocapitalize="characters"></label>' +
      '<label class="field"><span>Email (optional)</span><input class="input" id="c-email" type="email" inputmode="email" autocapitalize="none" placeholder="name@example.com" value="' + esc(c.email || '') + '" autocomplete="off"></label>' +
      '<p class="small muted">Mobile or email is enough. Saved store customers and fomaxo.com customers are found automatically.</p>' +
      '<div class="modal-foot">' + (S.customer ? '<button class="btn danger" data-act="c-remove">Remove</button>' : '') +
      '<button class="btn gold big" data-act="c-save">Save customer</button></div>');
    lastLookup = '';
    setPhone(c.phone || '');
    $('#c-cc').addEventListener('change', function () { updateCc(); lastLookup = ''; autoFind(); $('#c-phone').focus(); });
    $('#c-phone').addEventListener('input', function () {
      var v = this.value.trim();
      if (/^(\+|00)/.test(v) && v.replace(/\D/g, '').length >= 6) { var sp = FX_COUNTRIES.split(v); if (sp) { $('#c-cc').value = sp.iso; this.value = sp.national; updateCc(); } }
      autoFind();
    });
    $('#c-email').addEventListener('change', autoFind);
    if (c.phone || c.email) findCustomer();
  }
  // ---- phone with country code (UAE first)
  function countryOptions() {
    var C = FX_COUNTRIES, o = function (c) { return '<option value="' + c.iso + '">' + C.flag(c.iso) + ' ' + esc(c.name) + ' +' + c.dial + '</option>'; };
    return '<optgroup label="Most used">' + C.top.map(o).join('') + '</optgroup><optgroup label="All countries">' + C.all.map(o).join('') + '</optgroup>';
  }
  function updateCc() {
    var sel = $('#c-cc'); if (!sel) return;
    var c = FX_COUNTRIES.by[sel.value] || FX_COUNTRIES.by.AE;
    $('#c-cc-label').textContent = FX_COUNTRIES.flag(c.iso) + ' +' + c.dial;
    $('#c-phone').placeholder = c.iso === 'AE' ? '50 123 4567' : 'Mobile number';
  }
  function setPhone(full) {
    var sp = full ? FX_COUNTRIES.split(full) : null, inp = $('#c-phone');
    $('#c-cc').value = sp ? sp.iso : 'AE';
    inp.value = sp ? sp.national : String(full || '').replace(/^0+(?=5)/, '').replace(/[^\d ]/g, '');
    updateCc();
  }
  /** Full international number, e.g. +971501234567. Empty if nothing typed. */
  function phoneNational() { return ($('#c-phone') ? $('#c-phone').value : '').replace(/\D/g, '').replace(/^0+/, ''); }
  function fullPhone() {
    var n = phoneNational(); if (!n) return '';
    var c = FX_COUNTRIES.by[$('#c-cc').value] || FX_COUNTRIES.by.AE;
    return '+' + c.dial + n;
  }
  function tierInfo(cu) {
    var parts = ['<div class="note" style="margin-bottom:12px">'];
    parts.push(cu.tier ? '<span class="tier-tag">FOMAXO ' + esc(cu.tier) + '</span> ' : 'Not a member tier yet. ');
    parts.push('Spent ' + aed(cu.year_spend || 0) + ' this year.');
    if (cu.next_tier) parts.push(' ' + aed(cu.next_tier.needed) + ' more to reach ' + esc(cu.next_tier.name) + '.');
    parts.push('</div>');
    return parts.join('');
  }
  var lookupTimer = null, lastLookup = '';
  function autoFind() {
    clearTimeout(lookupTimer);
    lookupTimer = setTimeout(function () {
      var nat = phoneNational(), ph = fullPhone();
      var em = $('#c-email') ? $('#c-email').value.trim() : '';
      var emOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em);
      var enough = $('#c-cc') && $('#c-cc').value === 'AE' ? nat.length >= 9 : nat.length >= 7;
      var key = (enough ? ph : '') + '|' + (emOk ? em : '');
      if (key === '|' || key === lastLookup) return;
      lastLookup = key; findCustomer();
    }, 450);
  }
  function fillIfEmpty(sel, v) { var el = $(sel); if (el && !el.value.trim() && v) el.value = v; }
  function findCustomer() {
    var phone = fullPhone(), email = ($('#c-email') ? $('#c-email').value.trim() : '');
    if (!phone && !email) return;
    if (!S.online) { $('#c-info').innerHTML = '<p class="small muted">Offline — the details will still be saved with the sale.</p>'; return; }
    $('#c-info').innerHTML = '<div class="status-line" style="justify-content:flex-start"><span class="spinner"></span> Looking up customer…</div>';
    api('customer', { query: '&phone=' + encodeURIComponent(phone) + '&email=' + encodeURIComponent(email) }).then(function (d) {
      if (!$('#c-info')) return;
      var w = d.web, c = d.customer;
      if (c || w) {
        var name = (c && c.name) || (w && w.name) || '';
        fillIfEmpty('#c-name', titleCase(name));
        fillIfEmpty('#c-email', (c && c.email) || (w && w.email) || '');
        if (!phoneNational() && ((c && c.phone) || (w && w.phone))) setPhone((c && c.phone) || (w && w.phone));
        var where = [];
        if (c) where.push('🏬 ' + (c.visits || 0) + ' store purchase' + (c.visits === 1 ? '' : 's'));
        if (w) where.push('🌐 ' + w.orders + ' fomaxo.com order' + (w.orders === 1 ? '' : 's') + ' (last ' + esc(w.last_order) + ')');
        $('#c-info').innerHTML = '<div class="found"><b>✓ Found: ' + esc(titleCase(name) || 'customer') + '</b><div class="small">' + where.join('<br>') + '</div></div>' +
          tierInfo(c || { tier: d.tier, year_spend: d.year_spend, next_tier: d.next_tier });
        S._found = c || { tier: d.tier };
      } else {
        $('#c-info').innerHTML = '<p class="small muted">New customer — they will be saved with this sale.</p>'; S._found = null;
      }
    }).catch(function (e) { modalErr(e.message); });
  }

  function discountModal() {
    var d = S.discount || { type: 'percent', value: '' };
    var max = S.settings.staff_max_discount;
    openModal('Discount', '<div class="err m-err hidden"></div>' +
      '<div class="seg" style="margin-bottom:12px"><button data-act="d-type" data-t="percent" class="' + (d.type === 'percent' ? 'active' : '') + '">Percent %</button><button data-act="d-type" data-t="amount" class="' + (d.type === 'amount' ? 'active' : '') + '">Amount AED</button></div>' +
      '<label class="field"><span id="d-label">' + (d.type === 'percent' ? 'Discount %' : 'Discount in AED') + '</span><input class="input" id="d-val" type="number" inputmode="decimal" min="0" step="0.01" value="' + esc(d.value) + '" autofocus></label>' +
      (isManager() ? '' : '<p class="small muted">Staff can give up to ' + max + '%. Bigger discounts need a manager login.</p>') +
      '<div class="modal-foot">' + (S.discount ? '<button class="btn danger" data-act="d-remove">Remove</button>' : '') + '<button class="btn gold" data-act="d-save">Apply</button></div>');
    $('.modal').setAttribute('data-type', d.type);
  }

  // ---------------------------------------------------------------- PAYMENT
  function buildPayload(method, extra) {
    var id = uuid();
    var p = {
      uuid: id, receipt_token: randHex(16), method: method, store_id: curStoreId(),
      items: cartLines().map(function (l) { return { product_id: l.product_id, qty: l.qty, price: l.price }; }),
      discount: S.discount, customer: S.customer ? { phone: S.customer.phone, name: S.customer.name, email: S.customer.email || '' } : null,
      coupon: S.coupon ? S.coupon.code : '',
      coupon_info: S.coupon ? { kind: S.coupon.kind, amount: S.coupon.amount, label: S.coupon.label, free_product_id: S.coupon.free ? S.coupon.free.product_id : 0 } : null,
      created_at: stamp()
    };
    for (var k in extra) p[k] = extra[k];
    return p;
  }
  function payModal(method) {
    var t = cartTotals();
    var amount = '<div class="amount-big"><div class="lbl">Amount to collect</div><div class="val num">' + aed(t.total) + '</div></div>';
    if (method === 'cash') {
      var notes = [50, 100, 200, 500, 1000].filter(function (v) { return v * 100 > t.total; }).slice(0, 4);
      openModal('Cash', '<div class="err m-err hidden"></div>' + switchRow('cash', 'new', t.total) + amount +
        '<div class="cash-q">Customer gave:</div>' +
        '<div class="notes"><button class="note-btn exact" data-act="cash-quick" data-v="' + (t.total / 100) + '">Exact<br><small>' + money(t.total) + '</small></button>' +
        notes.map(function (v) { return '<button class="note-btn" data-act="cash-quick" data-v="' + v + '">' + v + '</button>'; }).join('') + '</div>' +
        '<label class="field"><span>Other amount</span><input class="input" id="cash-in" type="number" inputmode="decimal" step="0.01" min="0" placeholder="Type amount"></label>' +
        '<div class="change-box big" id="change-box"><span>Give change</span><span class="num">AED 0.00</span></div>' +
        '<div class="modal-foot"><button class="btn gold big" data-act="confirm-pay" data-m="cash">✓ Done — cash received</button></div>' +
        '<div class="modal-foot"><button class="btn danger block" data-act="pay-cancel" data-m="cash">Cancel sale</button></div>', { sticky: true });
      $('#cash-in').addEventListener('input', function () { $$('.note-btn').forEach(function (b) { b.classList.remove('active'); }); updateChange(); });
    } else if (method === 'tap') {
      openModal('Card / Tap', '<div class="err m-err hidden"></div>' + switchRow('tap', 'new', t.total) + amount +
        '<ol class="steps big"><li><span>On the store phone open <b>Ziina</b> → <b>Tap to Pay</b>.</span></li>' +
        '<li><span>Type <b class="num">' + money(t.total) + '</b> <button class="btn sm" data-act="copy-amount" data-v="' + (t.total / 100).toFixed(2) + '">Copy</button></span></li>' +
        '<li><span>Customer taps card or phone. Wait for <b>Approved</b>.</span></li></ol>' +
        '<details class="ref"><summary>Add reference (optional)</summary><input class="input" id="pay-ref" maxlength="64" placeholder="Last 4 digits or Ziina ID"></details>' +
        '<div class="modal-foot"><button class="btn ghost" data-act="pay-open">Back</button><button class="btn gold big" data-act="confirm-pay" data-m="tap">✓ Approved</button></div>' +
        '<div class="modal-foot"><button class="btn danger block" data-act="pay-cancel" data-m="tap">Cancel sale</button></div>', { sticky: true });
    } else if (method === 'card') {
      openModal('Card machine', '<div class="err m-err hidden"></div>' + switchRow('card', 'new', t.total) + amount +
        '<p class="muted">Charge this amount on the card terminal. When it prints <b>approved</b>, press Payment received.</p>' +
        '<label class="field"><span>Approval code (optional)</span><input class="input" id="pay-ref" maxlength="64"></label>' +
        '<div class="modal-foot"><button class="btn ghost" data-act="modal-close">Back</button><button class="btn gold big" data-act="confirm-pay" data-m="card">Payment received</button></div>' +
        '<div class="modal-foot"><button class="btn danger block" data-act="pay-cancel" data-m="card">Cancel sale</button></div>', { sticky: true });
    } else if (method === 'ziina') {
      ziinaFlow();
    }
  }
  function updateChange() {
    var t = cartTotals();
    var v = $('#cash-in').value, given = v === '' ? t.total : toFils(v), ch = given - t.total;
    var box = $('#change-box');
    box.classList.toggle('short', ch < 0);
    box.innerHTML = ch < 0 ? '<span>Still to pay</span><span class="num">' + aed(-ch) + '</span>' : '<span>Give change</span><span class="num">' + aed(ch) + '</span>';
  }
  function confirmPay(method, btn) {
    var extra = {};
    var t = cartTotals();
    if (method === 'cash') {
      var v = $('#cash-in').value;
      if (v !== '' && toFils(v) < t.total) { modalErr('Cash received is less than the total.'); return; }
      extra.cash_given = v === '' ? (t.total / 100).toFixed(2) : v;
    } else {
      var ref = $('#pay-ref'); extra.payment_ref = ref ? ref.value.trim() : '';
    }
    btn.disabled = true;
    var payload = buildPayload(method, extra);
    if (!S.online) { queueSale(payload); return; }
    api('sale', { body: payload }).then(function (d) { afterSale(d.sale); }).catch(function (e) {
      if (e.offline) { queueSale(payload); return; }
      btn.disabled = false; modalErr(e.message);
    });
  }
  function queueSale(payload) {
    payload.offline = 1;
    var lines = cartLines(), t = cartTotals();
    var local = {
      queued: true, uuid: payload.uuid, receipt_no: 'FMX-' + (curStore().code || 'ST') + '-OFFLINE',
      receipt_token: payload.receipt_token, created_at: payload.created_at, staff_name: S.user.name,
      store_name: curStore().name, store_address: curStore().address, store_phone: curStore().phone,
      customer_name: S.customer ? S.customer.name : '', customer_phone: S.customer ? S.customer.phone : '', customer_email: S.customer ? S.customer.email || '' : '',
      customer_tier: S.customer ? S.customer.tier : null,
      items: lines.map(function (l) { return { name: l.name, variant: l.variant, price: l.price, qty: l.qty, line_total: l.price * l.qty }; }),
      subtotal: t.subtotal, discount: t.discount + (t.coupon || 0), coupon: S.coupon ? S.coupon.code : '', coupon_label: S.coupon ? S.coupon.label : '', coupon_disc: t.coupon || 0,
      vat: t.vat, total: t.total, method: payload.method, status: 'paid',
      cash_given: payload.cash_given != null ? toFils(payload.cash_given) : null,
      change_due: payload.cash_given != null ? Math.max(0, toFils(payload.cash_given) - t.total) : null,
      receipt_url: (S.settings.base_url || location.origin) + '/receipt.php?t=' + payload.receipt_token
    };
    S.queue.push({ payload: payload, local: local });
    saveQueue();
    lines.forEach(function (l) { var p = productById(l.product_id); if (p) p.stock -= l.qty; });
    LS.set('fx_products', S.products);
    updateNet();
    afterSale(local);
  }
  function afterSale(sale) {
    cartSaleId = 0;
    S.cart = []; S.discount = null; S.customer = null; S.coupon = null;
    setCartOpen(false);
    if (S.view === 'sell') { renderTiles(); renderCart(); }
    if (!sale.queued) refreshProducts();
    doneScreen(sale);
  }

  var ziinaPoll = null;
  function stopPoll() { if (ziinaPoll) { clearInterval(ziinaPoll); ziinaPoll = null; } }
  function ziinaFlow() {
    var t = cartTotals();
    if (t.total < 200) { toast('Ziina needs at least AED 2.00. Take cash for this sale.', true); return; }
    var payload = buildPayload('ziina', {});
    var cust = S.customer;
    openModal('Ziina payment', '<div class="status-line"><span class="spinner"></span> Creating payment…</div>', { sticky: true, onClose: stopPoll });
    api('ziina_start', { body: payload, timeout: 30000 }).then(function (d) { showZiinaQr(d, cust, true); }).catch(ziinaErr);
  }
  function ziinaRetry(id) {
    openModal('Ziina payment', '<div class="status-line"><span class="spinner"></span> Creating payment…</div>', { sticky: true, onClose: stopPoll });
    api('ziina_retry', { body: { id: id }, timeout: 30000 }).then(function (d) {
      if (d.sale.status === 'paid' || d.sale.status === 'void') { closeModal(); toast(d.sale.status === 'paid' ? 'Already paid — sale complete.' : 'This sale was voided.'); if (S.view === 'sales') renderSales(); if (d.sale.status === 'paid') afterSale(d.sale); return; }
      showZiinaQr(d, d.sale.customer_phone ? { phone: d.sale.customer_phone } : null);
    }).catch(ziinaErr);
  }
  function ziinaErr(e) {
    setModalBody('<div class="err" style="margin-bottom:12px">' + esc(e.offline ? 'No internet — Ziina QR needs a connection. Use Cash or Tap to Pay.' : e.message) + '</div>' +
      '<div class="modal-foot"><button class="btn" data-act="modal-close">Close</button></div>');
  }
  var lastQr = null;
  var CANCEL_REASONS = ['Customer changed their mind', 'Payment not going through', 'Customer will pay later', 'Wrong items or price'];
  /** Ask why a sale is cancelled. id = existing Ziina sale; method = cancelling the bag at a Cash/Card/Tap screen. */
  function cancelReasonForm(id, fromQr, method) {
    if (method) { var mt = $('.modal-title, .modal h2'); if (mt) mt.textContent = 'Cancel sale'; }
    setModalBody('<p class="muted" style="margin-top:0">Why is this sale cancelled? No money is taken and no receipt number is used.</p>' +
      '<div class="err m-err hidden"></div><form id="cr-form">' +
      CANCEL_REASONS.map(function (r, i) { return '<label class="check"><input type="radio" name="cr" value="' + esc(r) + '"' + (i === 0 ? ' checked' : '') + '> ' + esc(r) + '</label>'; }).join('') +
      '<label class="check"><input type="radio" name="cr" value="other"> Other</label>' +
      '<input class="input" id="cr-other" placeholder="Type the reason" maxlength="200" style="margin-top:6px">' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="' + (method ? 'pay-back' : 'z-back') + '" data-m="' + (method || '') + '" data-id="' + (id || 0) + '" data-qr="' + (fromQr ? 1 : 0) + '">Back</button><button class="btn danger" type="submit">Cancel sale</button></div></form>');
    var f = $('#cr-form'), other = $('#cr-other');
    other.addEventListener('focus', function () { $('input[value=other]', f).checked = true; });
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var pick = $('input[name=cr]:checked', f).value, reason = pick === 'other' ? other.value.trim() : pick;
      if (!reason) { modalErr('Type the reason, or pick one above.'); other.focus(); return; }
      var b = $('[type=submit]', f); b.disabled = true; stopPoll();
      if (method) {
        var clearBag = function (msg) { S.cart = []; S.discount = null; S.customer = null; S.coupon = null; setCartOpen(false); if (S.view === 'sell') { renderTiles(); renderCart(); } closeModal(); toast(msg); };
        if (!S.online) { clearBag('Sale cancelled.'); return; }
        api('sale_cancel', { body: buildPayload(method, { cancel_reason: reason }) })
          .then(function () { clearBag('Sale cancelled. It is in Sales if the customer pays later.'); })
          .catch(function (er) { if (er.offline) { clearBag('Sale cancelled.'); return; } b.disabled = false; modalErr(er.message); });
        return;
      }
      api('ziina_cancel', { body: { id: id, reason: reason } }).then(function (r) {
        if (r.status === 'paid' && !fromQr) { closeModal(); doneScreen(r.sale); toast('The customer had already paid — sale completed.'); if (S.view === 'sales') renderSales(); }
        else if (r.status === 'paid') { closeModal(); afterSale(r.sale); toast('The customer had already paid — sale completed.'); }
        else if (r.status === 'void') { closeModal(); toast('This sale was voided.'); }
        else {
          if (paSale && paSale.id === id && paSale.fromCart) { S.cart = []; S.discount = null; S.customer = null; S.coupon = null; setCartOpen(false); if (S.view === 'sell') { renderTiles(); renderCart(); } }
          closeModal(); toast('Sale cancelled. You can pay again from Sales.'); if (S.view === 'sales') renderSales();
        }
      }).catch(function (er) { b.disabled = false; modalErr(er.message); });
    });
  }
  // ---- Pay again (from Sales): choose QR, Cash, Card / Tap or Card machine
  var paSale = null;
  function payAgainModal(s) {
    paSale = s; s.fromCart = s.id === cartSaleId; var st = S.settings, qrOk = st.ziina && S.online && s.total >= 200;
    openModal('Pay again', '<div class="err m-err hidden"></div><div class="amount-big"><div class="lbl">Amount to collect</div><div class="val num">' + aed(s.total) + '</div></div>' +
      '<div class="pay-choices">' +
      '<button class="pchoice" data-act="pa-m" data-m="cash"><span class="pi">💵</span><b>Cash</b><small>Notes and coins</small></button>' +
      '<button class="pchoice" data-act="pa-m" data-m="tap"><span class="pi">💳</span><b>Card / Tap</b><small>On the Ziina phone</small></button>' +
      '<button class="pchoice" data-act="pa-m" data-m="ziina"' + (qrOk ? '' : ' disabled') + '><span class="pi">📱</span><b>QR code</b><small>' + (!st.ziina ? 'Not set up yet' : !S.online ? 'Needs internet' : s.total < 200 ? 'Needs AED 2.00 or more' : 'Customer scans with their phone') + '</small></button>' +
      '<button class="pchoice" data-act="pa-m" data-m="card"><span class="pi">🏧</span><b>Card machine</b><small>Separate terminal</small></button>' +
      '</div><div class="modal-foot"><button class="btn danger block" data-act="z-cancel" data-id="' + s.id + '">Cancel sale</button></div>');
  }
  function payAgainMethod(m) {
    var s = paSale; if (!s) return;
    if (m === 'ziina') { ziinaRetry(s.id); return; }
    var amount = '<div class="amount-big"><div class="lbl">Amount to collect</div><div class="val num">' + aed(s.total) + '</div></div>', body;
    if (m === 'cash') {
      body = '<label class="field"><span>Customer gave (leave empty if exact)</span><input class="input" id="pa-cash" type="number" inputmode="decimal" step="0.01" min="0" placeholder="' + money(s.total) + '"></label>' +
        '<div class="change-box big" id="pa-change"><span>Give change</span><span class="num">AED 0.00</span></div>';
    } else {
      body = (m === 'tap' ? '<ol class="steps big"><li><span>On the store phone open <b>Ziina</b> → <b>Tap to Pay</b>.</span></li>' +
        '<li><span>Type <b class="num">' + money(s.total) + '</b> <button class="btn sm" type="button" data-act="copy-amount" data-v="' + (s.total / 100).toFixed(2) + '">Copy</button></span></li>' +
        '<li><span>Customer taps card or phone. Wait for <b>Approved</b>.</span></li></ol>' : '<p class="muted">Charge <b class="num">' + money(s.total) + '</b> on the card terminal and wait for <b>approved</b>.</p>') +
        (m === 'tap' ? '<details class="ref"><summary>Add reference (optional)</summary><input class="input" id="pa-ref" maxlength="64" placeholder="Last 4 digits or Ziina ID"></details>' : '<label class="field"><span>Approval code (optional)</span><input class="input" id="pa-ref" maxlength="64"></label>');
    }
    openModal({ cash: 'Cash', tap: 'Card / Tap', card: 'Card machine' }[m], '<div class="err m-err hidden"></div>' + switchRow(m, 'again', s.total) + amount + body +
      '<div class="modal-foot"><button class="btn ghost" data-act="pa-open" data-id="' + s.id + '">Back</button><button class="btn gold big" data-act="pa-confirm" data-m="' + m + '">' + (m === 'cash' ? '✓ Done — cash received' : m === 'tap' ? '✓ Approved' : 'Payment received') + '</button></div>' +
      '<div class="modal-foot"><button class="btn danger block" data-act="z-cancel" data-id="' + s.id + '">Cancel sale</button></div>', { sticky: true });
    var ci = $('#pa-cash');
    if (ci) ci.addEventListener('input', function () {
      var given = ci.value === '' ? s.total : toFils(ci.value), ch = given - s.total, box = $('#pa-change');
      box.classList.toggle('short', ch < 0);
      box.innerHTML = ch < 0 ? '<span>Still to pay</span><span class="num">' + aed(-ch) + '</span>' : '<span>Give change</span><span class="num">' + aed(ch) + '</span>';
    });
  }
  function payAgainConfirm(m, btn) {
    var s = paSale, body = { id: s.id, method: m };
    if (m === 'cash') { var v = $('#pa-cash').value; if (v !== '' && toFils(v) < s.total) { modalErr('Cash received is less than the total.'); return; } body.cash_given = v; }
    else body.payment_ref = ($('#pa-ref') || {}).value || '';
    btn.disabled = true;
    api('sale_pay', { body: body }).then(function (d) {
      closeModal(); if (S.view === 'sales') renderSales();
      if (d.already) toast('This sale was already paid.');
      if (s.fromCart) afterSale(d.sale); else { refreshProducts(); doneScreen(d.sale); }
    }).catch(function (er) { btn.disabled = false; modalErr(er.offline ? 'Needs internet.' : er.message); });
  }
  /** "Pay by" row on every payment screen, to switch method without going back. ctx 'new' = the bag, 'again' = a saved sale. */
  function switchRow(cur, ctx, total) {
    var st = S.settings, qrOk = st.ziina && S.online && total >= 200;
    var opts = [['cash', '💵', 'Cash'], ['tap', '💳', 'Card / Tap'], ['ziina', '📱', 'QR'], ['card', '🏧', 'Machine']];
    return '<div class="pay-switch"><span class="small muted">Pay by</span><div class="seg">' + opts.map(function (o) {
      var off = o[0] === 'ziina' && !qrOk && cur !== 'ziina';
      return '<button type="button" data-act="sw-m" data-m="' + o[0] + '" data-ctx="' + ctx + '" class="' + (o[0] === cur ? 'active' : '') + '"' + (off ? ' disabled' : '') + '><span>' + o[1] + '</span> ' + o[2] + '</button>';
    }).join('') + '</div></div>';
  }
  function switchMethod(m, ctx) {
    if (ctx === 'new') { stopPoll(); payModal(m); return; }
    if (!paSale) return;
    if (m === 'ziina') { if (paSale.method === 'ziina' && $('.qr-box')) return; stopPoll(); ziinaRetry(paSale.id); return; }
    stopPoll(); payAgainMethod(m);
  }
  var cartSaleId = 0; // the Ziina sale made from the current bag (paying it, or cancelling it, empties the bag)
  function showZiinaQr(d, cust, fromCart) {
      if (fromCart) cartSaleId = d.sale.id;
      lastQr = { d: d, cust: cust };
      paSale = d.sale; paSale.fromCart = d.sale.id === cartSaleId;
      if (d.sale.status === 'paid') { closeModal(); afterSale(d.sale); return; }
      var qr = qrcode(0, 'M'); qr.addData(d.pay_url); qr.make();
      var wa = cust && cust.phone ? 'https://wa.me/' + cust.phone.replace(/\D/g, '') + '?text=' + encodeURIComponent('FOMAXO payment of ' + aed(d.sale.total) + ': ' + d.pay_url) : null;
      setModalBody(switchRow('ziina', 'again', d.sale.total) +
        (S.settings.ziina_mode !== 'live' ? '<div class="note" style="margin-bottom:12px;text-align:center">' + (S.settings.ziina_mode === 'demo' ? 'PRACTICE MODE — no real money' : 'ZIINA TEST MODE — no real money') + '</div>' : '') +
        '<div class="amount-big"><div class="lbl">Customer pays</div><div class="val num">' + aed(d.sale.total) + '</div></div>' +
        '<div class="qr-box">' + qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true }) + '</div>' +
        '<p class="small muted qr-hint" style="text-align:center;margin:0 0 6px">Customer scans with their phone camera and pays by card, Apple Pay or Google Pay.</p>' +
        '<div class="status-line" id="z-status"><span class="spinner"></span> Waiting for payment…</div>' +
        '<div class="err m-err hidden"></div>' +
        '<div class="modal-foot qr-links"><button class="btn" type="button" data-act="copy-link" data-v="' + esc(d.pay_url) + '">Copy link</button>' +
        (wa ? '<a class="btn" target="_blank" rel="noopener" href="' + esc(wa) + '">WhatsApp</a>' : '') +
        '<a class="btn" target="_blank" rel="noopener" href="' + esc(d.pay_url) + '">Open</a></div>' +
        '<div class="modal-foot"><button class="btn danger block" data-act="z-cancel" data-id="' + d.sale.id + '">Cancel payment</button></div>');
      var busy = false;
      ziinaPoll = setInterval(function () {
        if (busy) return; busy = true;
        api('ziina_check', { query: '&id=' + d.sale.id }).then(function (r) {
          busy = false;
          if (r.status === 'paid') { stopPoll(); closeModal(); afterSale(r.sale); }
          else if (r.status === 'failed' || r.status === 'cancelled') {
            stopPoll();
            setModalBody('<div class="err" style="margin-bottom:12px">The payment was ' + r.status + '. No money was taken.</div>' +
              '<div class="modal-foot"><button class="btn" data-act="modal-close">Close</button><button class="btn gold" data-act="z-retry" data-id="' + d.sale.id + '">Pay again</button></div>');
          }
        }, function () { busy = false; var s = $('#z-status'); if (s) s.innerHTML = '<span class="spinner"></span> Checking… (connection slow)'; });
      }, 3000);
  }

  // ---------------------------------------------------------------- RECEIPT
  var METHOD = { cash: 'Cash', tap: 'Tap to Pay', card: 'Card', ziina: 'Ziina' };
  function paperHtml(s) {
    var st = S.settings;
    return '<div class="paper">' +
      '<div class="p-brand">' + esc(st.store_name) + '</div>' +
      (st.store_tagline ? '<div class="p-tag">' + esc(st.store_tagline) + '</div>' : '') +
      '<div class="p-small">' + [multiStore() && s.store_name ? s.store_name : '', s.store_address || st.store_address, s.store_phone || st.store_phone, st.store_trn ? 'TRN ' + st.store_trn : ''].filter(Boolean).map(esc).join('<br>') + '</div>' +
      '<div class="p-title">' + (st.store_trn ? 'TAX INVOICE' : 'RECEIPT') + '</div>' +
      '<div class="p-meta"><div><span>Receipt</span><b>' + esc(s.receipt_no) + '</b></div>' +
      '<div><span>Date</span><span>' + niceDate(s.created_at) + '</span></div>' +
      '<div><span>Served by</span><span>' + esc(s.staff_name) + '</span></div>' +
      (s.customer_name || s.customer_phone ? '<div><span>Customer</span><span>' + esc((s.customer_name || '') + ' ' + (s.customer_phone || '')) + '</span></div>' : '') +
      '</div><hr>' +
      s.items.map(function (it) {
        return '<div class="p-line"><div><b>' + esc(it.name) + '</b>' + (it.variant ? '<div class="s">' + esc(it.variant) + '</div>' : '') +
          '<div class="s">' + it.qty + ' x ' + money(it.price) + '</div></div><div class="num">' + money(it.line_total) + '</div></div>';
      }).join('') + '<hr>' +
      '<div class="p-tot"><span>Subtotal</span><span class="num">' + money(s.subtotal) + '</span></div>' +
      (s.discount - (s.coupon_disc || 0) > 0 ? '<div class="p-tot"><span>Discount</span><span class="num">-' + money(s.discount - (s.coupon_disc || 0)) + '</span></div>' : '') +
      (s.coupon ? '<div class="p-tot"><span>Coupon ' + esc(s.coupon) + '</span><span class="num">' + (s.coupon_disc ? '-' + money(s.coupon_disc) : 'FREE') + '</span></div>' : '') +
      (s.vat > 0 ? '<div class="p-tot"><span>VAT ' + st.vat_rate + '%' + (st.prices_include_vat ? ' (incl.)' : '') + '</span><span class="num">' + money(s.vat) + '</span></div>' : '') +
      '<div class="p-grand"><span>TOTAL ' + esc(st.currency) + '</span><span class="num">' + money(s.total) + '</span></div>' +
      '<div class="p-tot"><span>Paid by</span><span>' + esc(METHOD[s.method] || s.method) + (s.payment_ref ? ' (' + esc(s.payment_ref) + ')' : '') + '</span></div>' +
      (s.method === 'cash' && s.cash_given != null ? '<div class="p-tot"><span>Cash given</span><span class="num">' + money(s.cash_given) + '</span></div><div class="p-tot"><span>Change</span><span class="num">' + money(s.change_due) + '</span></div>' : '') +
      (s.customer_tier ? '<div class="p-tier">FOMAXO ' + esc(s.customer_tier) + ' MEMBER</div>' : '') +
      '<div class="p-foot">' + esc(st.receipt_footer) + '</div>' + receiptQr(s) + '</div>';
  }
  function receiptQr(s) {
    if (!s.receipt_url || typeof qrcode !== 'function') return '';
    try {
      var qr = qrcode(0, 'M'); qr.addData(s.receipt_url); qr.make();
      return '<div class="p-qr">' + qr.createSvgTag({ cellSize: 3, margin: 0, scalable: true }) + '<div>Scan for your digital receipt</div></div>';
    } catch (e) { return ''; }
  }
  var currentReceipt = null;
  function doneScreen(s) {
    currentReceipt = s;
    var change = s.method === 'cash' && s.change_due ? s.change_due : 0;
    openModal('', '<div class="done"><div class="done-tick">✓</div><div class="done-paid">PAID</div>' +
      '<div class="done-amt num">' + aed(s.total) + '</div>' +
      (change ? '<div class="done-change">Give change: <b class="num">' + aed(change) + '</b></div>' : '') +
      (s.queued ? '<p class="small muted">Saved on this phone (no internet). It uploads by itself later.</p>' : '') + '</div>' +
      '<div class="done-actions">' +
      '<button class="pchoice slim" data-act="r-wa"><span class="pi">📲</span><b>Send receipt</b><small>WhatsApp</small></button>' +
      '<button class="pchoice slim" data-act="r-print"><span class="pi">🖨</span><b>Print</b><small>Receipt printer</small></button>' +
      '<button class="pchoice slim" data-act="r-show"><span class="pi">🧾</span><b>Show receipt</b><small>QR, PDF, email</small></button>' +
      '</div><button class="btn gold big block next-btn" data-act="modal-close">Next customer →</button>', { noClose: true });
  }
  function showReceipt(s, fresh) {
    currentReceipt = s;
    var statusNote = '';
    if (s.queued) statusNote = '<div class="note" style="margin-bottom:12px">Saved on this device (offline). It uploads automatically when internet is back. The WhatsApp link works after upload.</div>';
    else if (s.status === 'void') statusNote = '<div class="err" style="margin-bottom:12px">This sale was voided. ' + esc(s.note || '') + '</div>';
    else if (s.status !== 'paid') statusNote = '<div class="err" style="margin-bottom:12px">' + ({ pending: 'Waiting for payment', cancelled: 'Payment cancelled', failed: 'Payment failed' }[s.status] || esc(s.status)) + ' — not paid yet.' + (s.status === 'cancelled' && s.note ? ' <b>' + esc(s.note.replace(/^Cancelled: /, 'Reason: ')) + '</b>.' : '') + ' Tap <b>Pay again</b> when the customer is ready to pay.' + '</div>';
    var paid = s.status === 'paid';
    var styled = !s.queued && s.receipt_token && S.online;
    var src = styled ? 'receipt.php?t=' + encodeURIComponent(s.receipt_token) + '&embed=1&theme=' + (isDark() ? 'dark' : 'light') : '';
    openModal(fresh ? 'Sale complete' : 'Receipt ' + s.receipt_no, statusNote +
      (styled ? '<div class="rframe-wrap"><iframe class="rframe" id="rframe" src="' + esc(src) + '" title="Receipt"></iframe></div>' : paperHtml(s)) +
      '<div class="err m-err hidden" style="margin-top:12px"></div>' +
      '<div class="receipt-actions">' +
      (paid ? (styled ? '<button class="btn" data-act="r-pdf">Download PDF</button>' : '') + '<button class="btn" data-act="r-print">Print (receipt printer)</button>' +
        '<button class="btn" data-act="r-wa">WhatsApp</button><button class="btn" data-act="r-copy">Copy link</button>' +
        '<button class="btn" data-act="r-email">Email</button>' : '') +
      (!fresh && paid && isManager() && !s.queued ? '<button class="btn danger" data-act="r-void">Void sale</button>' : '') +
      (!fresh && isManager() && !s.queued && s.id ? '<button class="btn danger" data-act="r-delete" data-id="' + s.id + '">Delete order</button>' : '') +
      (!paid && !s.queued && (s.status === 'pending' || s.status === 'cancelled' || s.status === 'failed') ? '<button class="btn gold big wide" data-act="pa-open" data-id="' + s.id + '">Pay again</button>' : '') +
      (!paid && !s.queued && (s.status === 'pending' || s.status === 'cancelled' || s.status === 'failed') ? '<button class="btn danger wide" data-act="z-cancel" data-id="' + s.id + '">' + (s.status === 'cancelled' ? 'Cancel again (change reason)' : 'Cancel sale') + '</button>' : '') +
      (fresh ? '<button class="btn gold big wide" data-act="modal-close">New sale</button>' : '') +
      '</div>', { wide: true });
    var fr = $('#rframe');
    if (fr) {
      var fit = function () { try { var d = fr.contentDocument; if (d && d.documentElement) fr.style.height = (d.documentElement.scrollHeight + 4) + 'px'; } catch (e) { /* ignore */ } };
      fr.addEventListener('load', function () { fit(); setTimeout(fit, 400); setTimeout(fit, 1200); });
    }
  }
  function receiptText(s) {
    return 'Thank you for shopping at ' + S.settings.store_name + '!\nReceipt ' + s.receipt_no + ' · ' + aed(s.total) + '\n' + s.receipt_url;
  }

  // ---------------------------------------------------------------- STOCK
  function renderStock() {
    var mgr = isManager();
    var showAll = mgr && multiStore();
    if (!showAll) S.stockScope = 'store';
    $('#view').innerHTML = '<div class="page"><div class="page-head"><h1>Stock</h1>' +
      (multiStore() ? '<span class="muted">' + (S.stockScope === 'all' ? 'All stores' : esc(curStore().name)) + '</span>' : '') + '<div class="spacer"></div>' +
      (showAll ? '<div class="seg"><button data-act="scope" data-s="store" class="' + (S.stockScope === 'store' ? 'active' : '') + '">' + esc(curStore().name) + '</button><button data-act="scope" data-s="all" class="' + (S.stockScope === 'all' ? 'active' : '') + '">All stores</button></div>' : '') +
      (mgr ? '<button class="btn" data-act="web-sync" title="Products and prices update from fomaxo.com automatically every 10 minutes">Sync<span class="hide-xs"> with website</span></button><button class="btn" data-act="bulk-open">Count all</button><button class="btn gold" data-act="p-new">+ Add product</button>' : '') + '</div>' +
      '<div class="kpis" id="stock-kpis"></div>' +
      '<div class="catalog-tools" style="position:static;margin:0 0 12px;padding:0"><label class="search">' + ICON.search +
      '<input class="input" id="sq" type="search" placeholder="Search stock" value="' + esc(S.stockSearch) + '"></label>' +
      '<div class="chips" id="stock-chips"></div></div>' +
      '<div class="table-wrap" id="stock-table"></div>' +
      (!S.online ? '<p class="small muted">Offline — showing the last saved stock list.</p>' : '') + '</div>';
    $('#sq').addEventListener('input', function (e) { S.stockSearch = e.target.value; renderStockBody(); });
    renderStockBody();
  }
  function totalStock(p) { var t = 0, m = p.stocks || {}; for (var k in m) t += m[k]; return t; }
  function scoped(p) {
    if (S.stockScope !== 'all') return p;
    var c = {}; for (var k in p) c[k] = p[k]; c.stock = totalStock(p); return c;
  }
  function renderStockBody() {
    var all = S.products.map(scoped), allMode = S.stockScope === 'all';
    var counts = { all: 0, low: 0, out: 0, off: 0 }, value = 0;
    all.forEach(function (p) {
      var s = stockState(p);
      if (s === 'off') counts.off++; else { counts.all++; if (s === 'low') counts.low++; if (s === 'out') counts.out++; value += Math.max(0, p.stock) * p.price; }
    });
    $('#stock-kpis').innerHTML =
      '<button class="kpi" data-act="sf" data-f="all"><div class="k-label">Products on sale</div><div class="k-val">' + counts.all + '</div></button>' +
      '<button class="kpi low" data-act="sf" data-f="low"><div class="k-label"><span class="badge low plain">Running low</span></div><div class="k-val">' + counts.low + '</div></button>' +
      '<button class="kpi out" data-act="sf" data-f="out"><div class="k-label"><span class="badge out plain">Out of stock</span></div><div class="k-val">' + counts.out + '</div></button>' +
      (isManager() ? '<div class="kpi gold"><div class="k-label">Stock value (shelf price)</div><div class="k-val num">' + aed(value) + '</div></div>' : '');
    var filters = [['all', 'All'], ['low', 'Running low'], ['out', 'Out of stock'], ['off', 'Switched off']];
    $('#stock-chips').innerHTML = filters.map(function (f) {
      return '<button class="chip' + (S.stockFilter === f[0] ? ' active' : '') + '" data-act="sf" data-f="' + f[0] + '">' + f[1] + (f[0] !== 'all' && counts[f[0]] ? ' · ' + counts[f[0]] : '') + '</button>';
    }).join('');
    var q = S.stockSearch.trim().toLowerCase(), rank = { out: 0, low: 1, ok: 2, off: 3 };
    var list = all.filter(function (p) {
      var s = stockState(p);
      if (S.stockFilter === 'all' && s === 'off') return false;
      if (S.stockFilter !== 'all' && s !== S.stockFilter) return false;
      return !q || (p.name + ' ' + p.variant + ' ' + p.sku + ' ' + p.category).toLowerCase().indexOf(q) >= 0;
    }).sort(function (a, b) { return rank[stockState(a)] - rank[stockState(b)] || (a.name + a.variant).localeCompare(b.name + b.variant); });
    var mgr = isManager(), stores = activeStores(), canMove = mgr && stores.length > 1;
    var cell = function (p, n) {
      var st = !p.active ? 'off' : n <= 0 ? 'out' : n <= p.low_at ? 'low' : 'ok';
      return '<span class="stock-cell ' + st + ' num">' + n + '</span>';
    };
    var head = allMode ?
      '<tr><th>Product</th>' + stores.map(function (x) { return '<th class="r">' + esc(x.name) + '</th>'; }).join('') + '<th class="r">Total</th><th class="r"></th></tr>' :
      '<tr><th>Product</th><th class="hide-sm">Category</th><th class="r hide-sm">Exclusive price</th><th>Stock</th><th class="r"></th></tr>';
    var rows = list.map(function (p) {
      var name = '<td><div class="p-name">' + esc(p.name) + '</div><div class="small muted">' + esc([p.variant, p.sku].filter(Boolean).join(' · ')) + '</div>' +
        '<div class="small show-sm price-line">' + wasHtml(p) + '<span class="num">' + money(p.price) + '</span></div></td>';
      var acts = '<td class="act-cell"><div class="actions">' + (mgr && !allMode ? '<button class="btn sm gold" data-act="p-adjust" data-id="' + p.id + '">Adjust</button>' : '') +
        (canMove ? '<button class="btn sm" data-act="p-move" data-id="' + p.id + '">Move</button>' : '') +
        (mgr ? '<button class="btn sm" data-act="p-edit" data-id="' + p.id + '">Edit</button>' : '') +
        '<button class="btn sm ghost" data-act="p-history" data-id="' + p.id + '">History</button></div></td>';
      if (allMode) {
        return '<tr>' + name + stores.map(function (x) { return '<td class="r">' + cell(p, (p.stocks || {})[x.id] || 0) + '</td>'; }).join('') +
          '<td class="r"><div class="stock-num num">' + p.stock + '</div>' + stockBadge(p) + '</td>' + acts + '</tr>';
      }
      return '<tr>' + name + '<td class="hide-sm muted">' + esc(p.category) + '</td><td class="r hide-sm"><div class="num">' + money(p.price) + '</div>' + (p.compare_at > p.price ? '<div class="small">' + wasHtml(p) + '</div>' : '') + (p.from_site ? '<div class="small muted">' + (p.price_lock ? 'Store price' : 'From fomaxo.com') + '</div>' : '') + '</td>' +
        '<td><div class="stock-num num">' + p.stock + '</div>' + stockBadge(p) + '</td>' + acts + '</tr>';
    }).join('');
    var cols = allMode ? stores.length + 3 : 5;
    $('#stock-table').innerHTML = '<table class="tbl ' + (allMode ? 'tbl-all' : 'tbl-stock') + '"><thead>' + head + '</thead><tbody id="stock-body">' + (rows ||
      '<tr><td colspan="' + cols + '"><div class="empty">' + (all.length ? 'Nothing here.' : 'No products yet.' + (mgr ? ' Press <b>+ Add product</b>.' : '')) + '</div></td></tr>') + '</tbody></table>';
    updateNavCounts();
  }
  function bulkModal() {
    var list = S.products.filter(function (p) { return p.active; }).sort(function (a, b) { return (a.name + a.variant).localeCompare(b.name + b.variant); });
    openModal('Count all' + (multiStore() ? ' · ' + curStore().name : ''), '<div class="err m-err hidden"></div>' +
      '<div class="seg" style="margin-bottom:10px"><button data-act="bulk-mode" data-m="set" class="active">Set exact count</button><button data-act="bulk-mode" data-m="add">Add delivery</button></div>' +
      '<p class="small muted" id="bulk-help" style="margin:0 0 10px">Type the number on the shelf for each product. Empty boxes are left as they are.</p>' +
      '<div class="row" style="margin-bottom:12px"><span class="small">Fill every box with</span><input class="input" id="bulk-all" type="number" inputmode="numeric" min="0" value="5" style="width:80px"><button class="btn sm gold" data-act="bulk-fill">Fill all</button><button class="btn sm ghost" data-act="bulk-clear">Clear</button></div>' +
      '<div class="bulk-list">' + list.map(function (p) {
        return '<label class="bulk-row"><span class="grow"><b>' + esc(p.name) + '</b> <span class="muted">' + esc(p.variant) + '</span><br><span class="small muted">Now: <span class="num">' + p.stock + '</span></span></span>' +
          '<input class="input bulk-in" type="number" inputmode="numeric" min="0" data-id="' + p.id + '" data-now="' + p.stock + '" placeholder="–"></label>';
      }).join('') + '</div>' +
      '<div class="modal-foot"><button class="btn" data-act="modal-close">Cancel</button><button class="btn gold" data-act="bulk-save">Save all</button></div>', { wide: true, sticky: true });
    $('.modal').setAttribute('data-mode', 'set');
  }
  function transferModal(p) {
    var stores = activeStores(), from = curStoreId(), to = (stores.filter(function (x) { return x.id !== from; })[0] || {}).id;
    var opts = function (sel) { return stores.map(function (x) { return '<option value="' + x.id + '"' + (x.id === sel ? ' selected' : '') + '>' + esc(x.name) + ' (' + ((p.stocks || {})[x.id] || 0) + ')</option>'; }).join(''); };
    openModal('Move stock', '<div class="err m-err hidden"></div>' +
      '<p style="margin-top:0"><b>' + esc(pName(p)) + '</b></p>' +
      '<div class="grid2"><label class="field"><span>From</span><select class="input" id="t-from">' + opts(from) + '</select></label>' +
      '<label class="field"><span>To</span><select class="input" id="t-to">' + opts(to) + '</select></label></div>' +
      '<label class="field"><span>How many?</span><input class="input" id="t-qty" type="number" inputmode="numeric" min="1" autofocus></label>' +
      '<label class="field"><span>Note (optional)</span><input class="input" id="t-note" placeholder="e.g. sent with driver"></label>' +
      '<div class="modal-foot"><button class="btn" data-act="modal-close">Cancel</button><button class="btn gold" data-act="t-save" data-id="' + p.id + '">Move stock</button></div>');
  }
  function productModal(p) {
    var isNew = !p; p = p || { name: '', variant: '', sku: '', category: '', price: 0, low_at: S.settings.default_low_stock, active: 1 };
    var cats = categories().slice(1);
    openModal(isNew ? 'Add product' : 'Edit product', '<div class="err m-err hidden"></div><form id="p-form">' +
      '<label class="field"><span>Product name</span><input class="input" name="name" required value="' + esc(p.name) + '" autofocus></label>' +
      '<div class="grid2"><label class="field"><span>Size / variant</span><input class="input" name="variant" placeholder="e.g. 100ml" value="' + esc(p.variant) + '"></label>' +
      '<label class="field"><span>Category</span><input class="input" name="category" list="cat-list" placeholder="e.g. Fragrance" value="' + esc(p.category) + '"><datalist id="cat-list">' + cats.map(function (c) { return '<option value="' + esc(c) + '">'; }).join('') + '</datalist></label></div>' +
      (p.from_site ? '<label class="check"><input type="checkbox" name="follow" ' + (p.price_lock ? '' : 'checked') + '> Use fomaxo.com prices (update automatically)</label>' : '') +
      '<div class="grid2"><label class="field"><span>Exclusive price AED (customer pays)' + (S.settings.prices_include_vat ? ', incl. VAT' : '') + '</span><input class="input" name="price" type="number" inputmode="decimal" step="0.01" min="0" required value="' + (p.price ? (p.price / 100).toFixed(2) : '') + '"></label>' +
      '<label class="field"><span>Original price AED (crossed out on receipt)</span><input class="input" name="compare_at" type="number" inputmode="decimal" step="0.01" min="0" placeholder="Optional" value="' + (p.compare_at ? (p.compare_at / 100).toFixed(2) : '') + '"></label></div>' +
      '<p class="small muted price-note hidden" style="margin:-4px 0 12px">These prices come from fomaxo.com. Untick above to set your own store prices.</p>' +
      '<div class="grid2"><label class="field"><span>Fragrance family (on receipt)</span><input class="input" name="family" placeholder="e.g. Eau de Parfum · Unisex" value="' + esc(p.family || '') + '"></label>' +
      '<label class="field"><span>Warn "running low" at</span><input class="input" name="low_at" type="number" min="0" value="' + p.low_at + '"></label></div>' +
      '<div class="grid2"><label class="field"><span>SKU / barcode (optional)</span><input class="input" name="sku" value="' + esc(p.sku) + '"></label>' +
      (isNew ? '<label class="field"><span>Opening stock' + (multiStore() ? ' at ' + esc(curStore().name) : '') + '</span><input class="input" name="stock" type="number" value="0"></label>' : '<div></div>') + '</div>' +
      '<label class="check"><input type="checkbox" name="active" ' + (p.active ? 'checked' : '') + '> On sale (shows on the Sell screen)</label>' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Cancel</button><button class="btn gold" type="submit">Save</button></div></form>');
    var syncLock = function () {
      var f = $('#p-form'), follow = f.follow && f.follow.checked;
      f.price.readOnly = !!follow; f.compare_at.readOnly = !!follow;
      $('.price-note', f).classList.toggle('hidden', !follow);
    };
    if ($('#p-form').follow) $('#p-form').follow.addEventListener('change', syncLock);
    syncLock();
    $('#p-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target, b = $('[type=submit]', f);
      b.disabled = true;
      api('product_save', { body: { id: isNew ? 0 : p.id, name: f.name.value, variant: f.variant.value, category: f.category.value, price: f.price.value, compare_at: f.compare_at.value, family: f.family.value, price_lock: f.follow ? (f.follow.checked ? 0 : 1) : 0, low_at: f.low_at.value, sku: f.sku.value, stock: f.stock ? f.stock.value : 0, store_id: curStoreId(), active: f.active.checked ? 1 : 0 } })
        .then(function () { closeModal(); toast('Saved.'); refreshProducts(); })
        .catch(function (er) { b.disabled = false; modalErr(er.offline ? 'Needs internet to save.' : er.message); });
    });
  }
  var REASONS = [
    ['restock', 'Received new stock', 1], ['count', 'Stock count — set exact number', 0], ['damaged', 'Damaged / broken', -1],
    ['tester', 'Opened as tester', -1], ['return', 'Customer return (back on shelf)', 1], ['other', 'Other correction (+ or −)', 2]
  ];
  var REASON_LABEL = { transfer: 'Moved between stores', sale: 'Sold', opening: 'Opening stock', restock: 'Received stock', count: 'Stock count', damaged: 'Damaged', tester: 'Tester', 'return': 'Customer return', other: 'Correction', void: 'Sale voided' };
  function adjustModal(p) {
    openModal('Adjust stock', '<div class="err m-err hidden"></div>' +
      '<p style="margin-top:0"><b>' + esc(pName(p)) + '</b><br><span class="muted">' + (multiStore() ? 'At ' + esc(curStore().name) + ': ' : 'Now in stock: ') + '<b class="num">' + p.stock + '</b></span></p>' +
      '<label class="field"><span>What happened?</span><select class="input" id="a-reason">' + REASONS.map(function (r) { return '<option value="' + r[0] + '">' + r[1] + '</option>'; }).join('') + '</select></label>' +
      '<label class="field"><span id="a-label">How many?</span><input class="input" id="a-num" type="number" inputmode="numeric" autofocus></label>' +
      '<div class="note" id="a-preview">New stock: ' + p.stock + '</div>' +
      '<label class="field" style="margin-top:12px"><span>Note (optional)</span><input class="input" id="a-note" placeholder="e.g. supplier invoice no."></label>' +
      '<div class="modal-foot"><button class="btn" data-act="modal-close">Cancel</button><button class="btn gold" data-act="a-save" data-id="' + p.id + '">Save</button></div>');
    var upd = function () {
      var r = $('#a-reason').value, n = parseInt($('#a-num').value, 10) || 0, sign = REASONS.filter(function (x) { return x[0] === r; })[0][2];
      $('#a-label').textContent = r === 'count' ? 'Number counted on the shelf' : r === 'other' ? 'Change (use − to remove)' : 'How many?';
      var next = r === 'count' ? n : sign === 2 ? p.stock + n : p.stock + sign * Math.abs(n);
      $('#a-preview').textContent = 'New stock: ' + next;
    };
    $('#a-reason').addEventListener('change', upd); $('#a-num').addEventListener('input', upd);
  }
  function historyModal(p) {
    openModal('History · ' + pName(p), '<div class="status-line"><span class="spinner"></span> Loading…</div>', { wide: true });
    api('stock_moves', { query: '&product_id=' + p.id }).then(function (d) {
      setModalBody(d.moves.length ? '<div class="table-wrap"><table class="tbl"><thead><tr><th>When</th><th>What</th><th class="r">Change</th><th class="r">After</th>' + (multiStore() ? '<th class="hide-sm">Store</th>' : '') + '<th class="hide-sm">By</th></tr></thead><tbody>' +
        d.moves.map(function (m) {
          var ch = parseInt(m.qty_change, 10);
          return '<tr><td class="small">' + niceDate(m.created_at) + '</td><td>' + esc(REASON_LABEL[m.reason] || m.reason) + (m.receipt_no ? ' <span class="small muted">' + esc(m.receipt_no) + '</span>' : '') + (m.note ? '<div class="small muted">' + esc(m.note) + '</div>' : '') + '</td>' +
            '<td class="r num" style="color:' + (ch < 0 ? 'var(--out)' : 'var(--ok)') + '">' + (ch > 0 ? '+' : '') + ch + '</td><td class="r num">' + esc(m.stock_after) + '</td>' + (multiStore() ? '<td class="hide-sm small">' + esc(m.store_name || '') + '</td>' : '') + '<td class="hide-sm small">' + esc(m.staff) + '</td></tr>';
        }).join('') + '</tbody></table></div>' : '<div class="empty">No stock changes yet.</div>');
    }).catch(function (e) { setModalBody('<div class="err">' + esc(e.offline ? 'History needs internet.' : e.message) + '</div>'); });
  }

  // ---------------------------------------------------------------- SALES
  function renderSales() {
    var q = S.queue;
    var html = '<div class="page"><div class="page-head"><h1>Sales</h1><div class="spacer"></div>' +
      (isManager() ? storeSelect('sst', S.salesStore, true) : '') +
      '<input class="input" id="sd" type="date" style="width:auto" value="' + S.salesDate + '" max="' + ymd() + '"></div>';
    if (q.length) {
      html += '<div class="card" style="margin-bottom:14px"><div class="row" style="margin-bottom:8px"><h3 class="grow" style="margin:0">Waiting to upload (' + q.length + ')</h3><button class="btn sm gold" data-act="sync-now"' + (S.online ? '' : ' disabled') + '>Upload now</button></div>' +
        '<p class="small muted" style="margin:0 0 6px">Made while offline. Stock and reports update once uploaded.</p>' +
        q.map(function (it, i) {
          return '<div class="list-row"><button class="link-btn" style="color:var(--text);text-decoration:none;text-align:left" data-act="q-view" data-i="' + i + '"><b>' + esc(it.local.receipt_no) + '</b> · ' + niceTime(it.local.created_at) + ' · ' + esc(METHOD[it.local.method]) +
            (it.error ? '<div class="small" style="color:var(--out)">Not accepted: ' + esc(it.error) + '</div>' : '') + '</button>' +
            '<div class="row"><span class="num">' + money(it.local.total) + '</span>' + (it.error ? '<button class="btn sm" data-act="q-retry" data-i="' + i + '">Retry</button><button class="btn sm danger" data-act="q-drop" data-i="' + i + '">Discard</button>' : '') + '</div></div>';
        }).join('') + '</div>';
    }
    html += '<div id="sales-list"><div class="status-line"><span class="spinner"></span> Loading…</div></div></div>';
    $('#view').innerHTML = html;
    $('#sd').addEventListener('change', function (e) { S.salesDate = e.target.value || ymd(); loadSales(); });
    if ($('#sst')) $('#sst').addEventListener('change', function (e) { S.salesStore = e.target.value; loadSales(); });
    loadSales();
  }
  var STATUS_BADGE = { paid: '<span class="badge ok">Paid</span>', pending: '<span class="badge low">Waiting</span>', cancelled: '<span class="badge off">Cancelled</span>', failed: '<span class="badge out">Failed</span>', void: '<span class="badge out">Voided</span>' };
  function loadSales() {
    api('sales', { query: '&from=' + S.salesDate + '&to=' + S.salesDate + '&store=' + S.salesStore }).then(function (d) {
      var el = $('#sales-list'); if (!el) return;
      var paid = d.sales.filter(function (s) { return s.status === 'paid'; }), total = 0;
      paid.forEach(function (s) { total += s.total; });
      el.innerHTML = '<div class="kpis"><div class="kpi gold"><div class="k-label">' + (isManager() ? 'Store total' : 'Your sales') + '</div><div class="k-val num">' + aed(total) + '</div></div>' +
        '<div class="kpi"><div class="k-label">Paid sales</div><div class="k-val">' + paid.length + '</div></div></div>' +
        (d.sales.length ? '<div class="table-wrap"><table class="tbl tbl-sales"><thead><tr><th>Time</th><th>Receipt</th>' + (isManager() ? '<th class="hide-sm">Staff</th>' : '') + '<th class="hide-sm">Paid by</th><th class="r">Total</th><th class="r">Status</th></tr></thead><tbody>' +
          d.sales.map(function (s) {
            return '<tr class="click" data-act="s-open" data-id="' + s.id + '"><td class="num">' + niceTime(s.created_at) + '</td><td><b>' + esc(s.receipt_no) + '</b><div class="small muted">' + s.items + ' item' + (s.items === 1 ? '' : 's') + (s.customer_name ? ' · ' + esc(s.customer_name) : '') + (s.offline ? ' · offline' : '') + (multiStore() && s.store_name ? ' · ' + esc(s.store_name) : '') + '</div></td>' +
              (isManager() ? '<td class="hide-sm">' + esc(s.staff_name) + '</td>' : '') + '<td class="hide-sm">' + esc(METHOD[s.method] || s.method) + '</td><td class="r num">' + money(s.total) + '</td><td class="r">' + (STATUS_BADGE[s.status] || esc(s.status)) + '</td></tr>';
          }).join('') + '</tbody></table></div>' : '<div class="empty"><div class="big">No sales on this day</div></div>');
    }).catch(function (e) {
      var el = $('#sales-list'); if (el) el.innerHTML = '<div class="empty">' + esc(e.offline ? 'Sales history needs internet. Sales made offline are listed above.' : e.message) + '</div>';
    });
  }
  function voidModal(s) {
    openModal('Void ' + s.receipt_no, '<div class="err m-err hidden"></div>' +
      '<p class="muted" style="margin-top:0">Use this for a mistake or a full refund. Total ' + aed(s.total) + ' paid by ' + esc(METHOD[s.method]) + '.' +
      (s.method === 'ziina' || s.method === 'tap' ? ' <b>Refund the customer in the Ziina app as well</b> — voiding here only fixes the records.' : '') + '</p>' +
      '<label class="field"><span>Reason</span><input class="input" id="v-reason" autofocus placeholder="e.g. customer returned item"></label>' +
      '<label class="check"><input type="checkbox" id="v-restock" checked> Put the items back into stock</label>' +
      '<div class="modal-foot"><button class="btn" data-act="modal-close">Keep sale</button><button class="btn danger" data-act="v-save" data-id="' + s.id + '">Void sale</button></div>');
  }

  // ---------------------------------------------------------------- REPORTS
  function renderReports() {
    var r = S.report;
    $('#view').innerHTML = '<div class="page"><div class="page-head"><h1>Reports</h1><div class="spacer"></div>' +
      '<div class="seg" style="min-width:300px">' + [['today', 'Today'], ['yesterday', 'Yesterday'], ['week', '7 days'], ['month', 'This month']].map(function (p) {
        return '<button data-act="r-preset" data-p="' + p[0] + '" class="' + (r.preset === p[0] ? 'active' : '') + '">' + p[1] + '</button>';
      }).join('') + '</div>' +
      '<div class="row date-range"><input class="input" type="date" id="rf" value="' + r.from + '"><span class="muted">to</span><input class="input" type="date" id="rt" value="' + r.to + '"></div>' + storeSelect('rstore', S.reportStore, true) + '</div>' +
      '<div id="report-body"><div class="status-line"><span class="spinner"></span> Loading…</div></div></div>';
    var onDate = function () { S.report = { preset: 'custom', from: $('#rf').value, to: $('#rt').value }; loadReport(); $$('[data-act=r-preset]').forEach(function (b) { b.classList.remove('active'); }); };
    $('#rf').addEventListener('change', onDate); $('#rt').addEventListener('change', onDate);
    if ($('#rstore')) $('#rstore').addEventListener('change', function (e) { S.reportStore = e.target.value; loadReport(); });
    loadReport();
  }
  function bars(rows, label, val, fmt) {
    var max = 0; rows.forEach(function (r) { max = Math.max(max, val(r)); });
    return rows.map(function (r) {
      return '<div class="bar-row"><span>' + esc(label(r)) + '</span><div class="bar"><i style="width:' + (max ? Math.max(2, val(r) / max * 100) : 0) + '%"></i></div><span class="num">' + fmt(r) + '</span></div>';
    }).join('');
  }
  function loadReport() {
    var r = S.report;
    api('report', { query: '&from=' + r.from + '&to=' + r.to + '&store=' + (multiStore() ? S.reportStore : 'all') }).then(function (d) {
      var el = $('#report-body'); if (!el) return;
      var s = d.summary, avg = s.sales ? Math.round(s.total / s.sales) : 0;
      var lowList = S.products.filter(function (p) { var st = stockState(p); return st === 'low' || st === 'out'; });
      el.innerHTML = '<div class="kpis">' +
        '<div class="kpi gold"><div class="k-label">Sales total</div><div class="k-val num">' + aed(s.total) + '</div></div>' +
        '<div class="kpi"><div class="k-label">Number of sales</div><div class="k-val">' + s.sales + '</div></div>' +
        '<div class="kpi"><div class="k-label">Average sale</div><div class="k-val num">' + aed(avg) + '</div></div>' +
        (S.settings.vat_rate > 0 || s.vat > 0 ? '<div class="kpi"><div class="k-label">VAT collected</div><div class="k-val num">' + aed(s.vat) + '</div></div>' : '') +
        '<div class="kpi"><div class="k-label">Discounts given</div><div class="k-val num">' + aed(s.discount) + '</div></div></div>' +
        '<div class="cards2">' +
        (multiStore() && d.by_store.length > 1 ? '<div class="card"><h3>Sales by store</h3>' + bars(d.by_store, function (x) { return x.name; }, function (x) { return x.total; }, function (x) { return money(x.total) + ' <span class="muted small">(' + x.sales + ')</span>'; }) + '</div>' : '') +
        '<div class="card"><h3>How customers paid</h3>' + (d.by_method.length ? bars(d.by_method, function (x) { return METHOD[x.method] || x.method; }, function (x) { return x.total; }, function (x) { return money(x.total) + ' <span class="muted small">(' + x.sales + ')</span>'; }) : '<p class="muted">No sales.</p>') + '</div>' +
        '<div class="card"><h3>Sales by staff</h3>' + (d.by_staff.length ? bars(d.by_staff, function (x) { return x.name; }, function (x) { return x.total; }, function (x) { return money(x.total) + ' <span class="muted small">(' + x.sales + ')</span>'; }) : '<p class="muted">No sales.</p>') + '</div>' +
        '<div class="card"><h3>Best sellers</h3>' + (d.top.length ? d.top.map(function (x, i) { return '<div class="list-row"><span>' + (i + 1) + '. ' + esc(x.name + (x.variant ? ' · ' + x.variant : '')) + '</span><span class="num">' + x.qty + ' sold · ' + money(x.total) + '</span></div>'; }).join('') : '<p class="muted">No sales.</p>') + '</div>' +
        (d.by_day.length > 1 ? '<div class="card"><h3>By day</h3>' + bars(d.by_day, function (x) { return x.day.slice(5); }, function (x) { return x.total; }, function (x) { return money(x.total); }) + '</div>' : '') +
        '<div class="card"><h3>Needs reordering</h3>' + (lowList.length ? lowList.map(function (p) { return '<div class="list-row"><span>' + esc(pName(p)) + '</span>' + stockBadge(p) + '</div>'; }).join('') : '<p class="muted">Everything is well stocked.</p>') + '</div>' +
        '</div>';
    }).catch(function (e) { var el = $('#report-body'); if (el) el.innerHTML = '<div class="empty">' + esc(e.offline ? 'Reports need internet.' : e.message) + '</div>'; });
  }
  function presetRange(p) {
    var d = new Date(), t = ymd(d);
    if (p === 'today') return { from: t, to: t };
    if (p === 'yesterday') { d.setDate(d.getDate() - 1); return { from: ymd(d), to: ymd(d) }; }
    if (p === 'week') { var w = new Date(); w.setDate(w.getDate() - 6); return { from: ymd(w), to: t }; }
    return { from: t.slice(0, 8) + '01', to: t };
  }

  // ---------------------------------------------------------------- TEAM (owner / manager)
  var ACT = {
    login: ['Logged in', 'plain'], logout: ['Logged out', 'plain'], sale: ['Sale', 'ok'], ziina: ['Ziina QR', 'plain'],
    cancel: ['Cancelled payment', 'low'], void: ['Voided sale', 'out'], stock: ['Stock change', 'low'], transfer: ['Moved stock', 'low'],
    product: ['Product edit', 'plain'], coupon: ['Coupon', 'plain'], settings: ['Settings', 'low'], staff: ['Staff change', 'plain'], store: ['Store change', 'plain'], password: ['Password', 'plain']
  };
  function storeSelect(id, val, withAll) {
    if (!multiStore()) return '';
    return '<select class="input" id="' + id + '" style="width:auto">' + (withAll ? '<option value="all">All stores</option>' : '') +
      S.stores.map(function (x) { return '<option value="' + x.id + '"' + (String(val) === String(x.id) ? ' selected' : '') + '>' + esc(x.name) + (x.active ? '' : ' (closed)') + '</option>'; }).join('') + '</select>';
  }
  function ago(ts) {
    if (!ts) return 'never';
    var d = (Date.now() - new Date(String(ts).replace(' ', 'T')).getTime()) / 1000;
    if (d < 60) return 'just now';
    if (d < 3600) return Math.floor(d / 60) + ' min ago';
    if (d < 86400) return Math.floor(d / 3600) + ' h ago';
    return niceDate(ts);
  }
  function renderStaff() {
    var t = S.team;
    if (!t.from) { var r0 = presetRange(t.preset); t.from = r0.from; t.to = r0.to; }
    var tabs = [['performance', 'Performance'], ['activity', 'Activity'], ['staff', 'Staff logins'], ['stores', 'Stores']];
    var dated = S.teamTab === 'performance' || S.teamTab === 'activity';
    $('#view').innerHTML = '<div class="page"><div class="page-head"><h1>Team</h1><div class="spacer"></div>' +
      '<div class="seg team-tabs">' + tabs.map(function (x) { return '<button data-act="team-tab" data-t="' + x[0] + '" class="' + (S.teamTab === x[0] ? 'active' : '') + '">' + x[1] + '</button>'; }).join('') + '</div></div>' +
      (dated ? '<div class="page-head" style="margin-top:-4px"><div class="seg">' + [['today', 'Today'], ['yesterday', 'Yesterday'], ['week', '7 days'], ['month', 'This month']].map(function (p) {
        return '<button data-act="t-preset" data-p="' + p[0] + '" class="' + (t.preset === p[0] ? 'active' : '') + '">' + p[1] + '</button>';
      }).join('') + '</div><div class="row date-range"><input class="input" type="date" id="tf" value="' + t.from + '"><span class="muted">to</span><input class="input" type="date" id="tt" value="' + t.to + '"></div>' +
        storeSelect('tstore', S.reportStore, true) + (S.teamTab === 'activity' ? '<select class="input" id="tuser" style="width:auto"><option value="">Everyone</option></select>' : '') + '</div>' : '') +
      '<div id="team-body"><div class="status-line"><span class="spinner"></span> Loading…</div></div></div>';
    if (dated) {
      var onDate = function () { t.preset = 'custom'; t.from = $('#tf').value; t.to = $('#tt').value; $$('[data-act=t-preset]').forEach(function (b) { b.classList.remove('active'); }); loadTeam(); };
      $('#tf').addEventListener('change', onDate); $('#tt').addEventListener('change', onDate);
      if ($('#tstore')) $('#tstore').addEventListener('change', function (e) { S.reportStore = e.target.value; loadTeam(); });
    }
    loadTeam();
  }
  function teamQuery() { var t = S.team; return '&from=' + t.from + '&to=' + t.to + '&store=' + (multiStore() ? S.reportStore : 'all'); }
  function loadTeam() {
    var el = function () { return $('#team-body'); };
    var fail = function (e) { if (el()) el().innerHTML = '<div class="empty">' + esc(e.offline ? 'Needs internet.' : e.message) + '</div>'; };
    if (S.teamTab === 'performance') {
      api('team', { query: teamQuery() }).then(function (d) {
        if (!el()) return;
        var team = d.team, best = 0, sum = 0, cnt = 0, online = 0;
        S._removed = d.removed || [];
        team.forEach(function (m) { best = Math.max(best, m.total); sum += m.total; cnt += m.sales; if (m.online) online++; });
        el().innerHTML = '<div class="kpis">' +
          '<div class="kpi gold"><div class="k-label">Team sales</div><div class="k-val num">' + aed(sum) + '</div></div>' +
          '<div class="kpi"><div class="k-label">Number of sales</div><div class="k-val">' + cnt + '</div></div>' +
          '<div class="kpi"><div class="k-label">Using the app now</div><div class="k-val">' + online + ' <span class="small muted">of ' + team.filter(function (m) { return m.active; }).length + '</span></div></div>' +
          '<button class="kpi kpi-btn" data-act="t-staffbox"><div class="k-label">Staff</div><div class="k-val">' + team.filter(function (m) { return m.active; }).length + ' <span class="small muted">active</span></div>' +
            '<div class="small ' + (S._removed.length ? 'k-warn' : 'muted') + '">' + (S._removed.length ? S._removed.length + ' removed · tap to manage' : 'No removed staff') + '</div></button></div>' +
          '<div class="people">' + team.map(function (m) { return personCard(m, best, 't-person'); }).join('') + '</div><p class="small muted">Tap a person to see everything they did.</p>';
      }).catch(fail);
    } else if (S.teamTab === 'activity') {
      var withUsers = S._users ? Promise.resolve() : api('users').then(function (d) { S._users = d.users; });
      withUsers.then(function () {
        var sel = $('#tuser');
        if (sel && sel.options.length < 2) {
          sel.innerHTML = '<option value="">Everyone</option>' + S._users.map(function (u) { return '<option value="' + u.id + '"' + (String(S.team.user) === String(u.id) ? ' selected' : '') + '>' + esc(u.name) + '</option>'; }).join('');
          sel.addEventListener('change', function (e) { S.team.user = e.target.value; loadTeam(); });
        }
        return api('activity', { query: teamQuery() + '&user=' + (S.team.user || '') });
      }).then(function (d) {
        if (!el()) return;
        var last = '';
        el().innerHTML = d.activity.length ? '<div class="card feed">' + d.activity.map(function (a) {
          var day = a.created_at.slice(0, 10), head = '';
          if (day !== last) { last = day; head = '<div class="feed-day">' + esc(niceDate(day + ' 00:00:00').split(',')[0]) + '</div>'; }
          var k = ACT[a.action] || [a.action, 'plain'];
          return head + '<div class="feed-row"><span class="num feed-time">' + niceTime(a.created_at) + '</span>' +
            '<div class="grow"><b>' + esc(a.user_name) + '</b> <span class="badge ' + k[1] + ' plain">' + esc(k[0]) + '</span>' +
            '<div class="small">' + esc(a.detail) + (a.ref ? ' <span class="muted">' + esc(a.ref) + '</span>' : '') + '</div>' +
            (a.store_name && multiStore() ? '<div class="small muted">' + esc(a.store_name) + '</div>' : '') + '</div></div>';
        }).join('') + '</div>' : '<div class="empty"><div class="big">Nothing recorded</div>No activity for these dates.</div>';
      }).catch(fail);
    } else if (S.teamTab === 'staff') {
      api('users').then(function (d) {
        S._users = d.users;
        if (!el()) return;
        el().innerHTML = '<div class="page-head"><p class="muted grow" style="margin:0">Each person gets their own login. Staff sell and see stock; managers also edit products and stock, void sales and see Reports and Team.</p><button class="btn gold" data-act="u-new">+ Add staff</button></div>' +
          '<div class="table-wrap"><table class="tbl"><thead><tr><th>Name</th><th>Username</th><th>Role</th>' + (multiStore() ? '<th class="hide-sm">Store</th>' : '') + '<th class="r"></th></tr></thead><tbody>' +
          d.users.map(function (u) {
            var st = u.store_id ? storeById(u.store_id) : null;
            return '<tr><td><b>' + esc(u.name) + '</b>' + (u.active ? '' : ' <span class="badge off">Switched off</span>') + (multiStore() ? '<div class="small muted show-sm">' + (st ? esc(st.name) : 'Any store') + '</div>' : '') + '</td><td class="muted">' + esc(u.username) + '</td><td>' + (u.role === 'manager' ? '<span class="tier-tag">Manager</span>' : 'Staff') + '</td>' +
              (multiStore() ? '<td class="hide-sm">' + (st ? esc(st.name) : '<span class="muted">Any store</span>') + '</td>' : '') +
              '<td class="r"><button class="btn sm" data-act="u-edit" data-id="' + u.id + '">Edit</button></td></tr>';
          }).join('') + '</tbody></table></div>';
      }).catch(fail);
    } else {
      api('stores').then(function (d) {
        S.stores = d.stores; LS.set('fx_stores', S.stores); updateStoreBtn();
        if (!el()) return;
        el().innerHTML = '<div class="page-head"><p class="muted grow" style="margin:0">Each store has its own stock, and its address and phone print on its receipts.</p><button class="btn gold" data-act="st-new">+ Add store</button></div>' +
          '<div class="people">' + d.stores.map(function (x) {
            return '<button class="person' + (x.active ? '' : ' off') + '" data-act="st-edit" data-id="' + x.id + '"><b>' + esc(x.name) + '</b> <span class="small muted">' + esc(x.code || '') + '</span>' + (x.active ? '' : ' <span class="badge off">Closed</span>') +
              '<div class="small muted" style="margin-top:6px">' + (esc(x.address) || 'No address yet') + '<br>' + (esc(x.phone) || 'No phone yet') + '</div></button>';
          }).join('') + '</div>';
      }).catch(fail);
    }
  }
  function storeModal(x) {
    var isNew = !x; x = x || { name: '', address: '', phone: '', active: 1, code: '' };
    openModal(isNew ? 'Add store' : 'Edit store', '<div class="err m-err hidden"></div><form id="st-form">' +
      '<label class="field"><span>Store name</span><input class="input" name="name" required value="' + esc(x.name) + '" placeholder="e.g. City Centre Sharjah" autofocus></label>' +
      '<label class="field"><span>Store code (in order numbers: FMX-<b>CODE</b>-10000)</span><input class="input" name="code" maxlength="6" autocapitalize="characters" autocorrect="off" spellcheck="false" style="text-transform:uppercase" value="' + esc(x.code || '') + '" placeholder="' + (isNew ? 'Made from the name if left empty' : '') + '"></label>' +
      '<label class="field"><span>Address (printed on receipts)</span><input class="input" name="address" value="' + esc(x.address) + '"></label>' +
      '<label class="field"><span>Phone (printed on receipts)</span><input class="input" name="phone" value="' + esc(x.phone) + '"></label>' +
      '<label class="check"><input type="checkbox" name="active" ' + (x.active ? 'checked' : '') + '> Open (can sell and hold stock)</label>' +
      '<div class="field"><span>Staff working here</span><div id="st-staff" class="st-staff"><span class="small muted">Loading…</span></div></div>' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Cancel</button><button class="btn gold" type="submit">Save</button></div></form>');
    var staffReady = false;
    (S._users ? Promise.resolve() : api('users').then(function (d) { S._users = d.users; })).then(function () {
      var box = $('#st-staff'); if (!box) return;
      var list = S._users.filter(function (u) { return u.active; });
      box.innerHTML = list.length ? list.map(function (u) {
        var other = u.store_id && u.store_id !== x.id ? storeById(u.store_id) : null;
        return '<label class="check"><input type="checkbox" name="staff" value="' + u.id + '"' + (!isNew && u.store_id === x.id ? ' checked' : '') + '> <span><b>' + esc(u.name) + '</b> <span class="small muted">' +
          (u.role === 'manager' ? 'Manager' : 'Staff') + ' · ' + (u.store_id === x.id && !isNew ? 'here' : other ? 'now at ' + esc(other.name) : 'any store') + '</span></span></label>';
      }).join('') + '<p class="small muted" style="margin:6px 0 0">Ticking someone moves them to this store. Unticking lets them choose any store.</p>' : '<span class="small muted">No staff yet.</span>';
      staffReady = true;
    }).catch(function () { var box = $('#st-staff'); if (box) box.innerHTML = '<span class="small muted">Staff list needs internet.</span>'; });
    $('#st-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target, b = $('[type=submit]', f); b.disabled = true;
      var body = { id: isNew ? 0 : x.id, name: f.name.value, code: f.code.value, address: f.address.value, phone: f.phone.value, active: f.active.checked ? 1 : 0 };
      if (staffReady) body.staff = $$('input[name=staff]:checked', f).map(function (c) { return parseInt(c.value, 10); });
      api('store_save', { body: body })
        .then(function (d) { S.stores = d.stores; S._users = null; LS.set('fx_stores', S.stores); closeModal(); toast('Store saved.'); updateStoreBtn(); refreshProducts(); loadTeam(); })
        .catch(function (er) { b.disabled = false; modalErr(er.message); });
    });
  }
  function userModal(u) {
    var isNew = !u; u = u || { name: '', username: '', role: 'staff', active: 1 };
    openModal(isNew ? 'Add staff' : 'Edit ' + u.name, '<div class="err m-err hidden"></div><form id="u-form">' +
      '<label class="field"><span>Full name</span><input class="input capw" name="name" required autocapitalize="characters" value="' + esc(u.name) + '" autofocus></label>' +
      '<label class="field"><span>Username (for login — capitals don\u2019t matter)</span><input class="input capw" name="username" required autocapitalize="characters" autocorrect="off" spellcheck="false" value="' + esc(u.username) + '"></label>' +
      '<label class="field"><span>' + (isNew ? 'Password (8+ characters)' : 'New password (leave empty to keep)') + '</span><input class="input" name="password" type="text" autocomplete="new-password" ' + (isNew ? 'required minlength="8"' : '') + '></label>' +
      '<label class="field"><span>Role</span><select class="input" name="role"><option value="staff"' + (u.role === 'staff' ? ' selected' : '') + '>Staff</option><option value="manager"' + (u.role === 'manager' ? ' selected' : '') + '>Manager</option></select></label>' +
      (multiStore() ? '<label class="field"><span>Works at</span><select class="input" name="store_id"><option value="">Any store (chooses on the device)</option>' +
        S.stores.map(function (x) { return '<option value="' + x.id + '"' + (u.store_id === x.id ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select></label>' : '') +
      '<label class="check"><input type="checkbox" name="active" ' + (u.active ? 'checked' : '') + '> Can log in</label>' +
      '<div class="modal-foot">' + (!isNew && u.id !== S.user.id ? '<button class="btn danger" type="button" data-act="u-remove" data-id="' + u.id + '">Remove staff</button>' : '') +
      '<button class="btn" type="button" data-act="modal-close">Cancel</button><button class="btn gold" type="submit">Save</button></div></form>');
    $('#u-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target, b = $('[type=submit]', f); b.disabled = true;
      api('user_save', { body: { id: isNew ? 0 : u.id, name: f.name.value, username: f.username.value, password: f.password.value, role: f.role.value, store_id: f.store_id ? f.store_id.value : '', active: f.active.checked ? 1 : 0 } })
        .then(function () { closeModal(); toast('Saved.'); S._users = null; loadTeam(); })
        .catch(function (er) { b.disabled = false; modalErr(er.message); });
    });
  }

  function teamRangeLabel() {
    var t = S.team || {}, L = { today: 'today', yesterday: 'yesterday', week: 'the last 7 days', month: 'this month' };
    return L[t.preset] || (t.from === t.to ? t.from : t.from + ' to ' + t.to);
  }
  function personCard(m, best, act) {
    var avg = m.sales ? Math.round(m.total / m.sales) : 0, pm = m.methods || {};
    return '<button class="person' + (m.active ? '' : ' off') + '" data-act="' + act + '" data-id="' + m.id + '">' +
      '<div class="row"><span class="avatar lg">' + esc(initials(m.name)) + '</span><div class="grow"><b>' + esc(m.name) + '</b>' +
      '<div class="small muted">' + (m.role === 'manager' ? 'Manager' : 'Staff') + (m.store_name ? ' · ' + esc(m.store_name) : multiStore() ? ' · any store' : '') + (m.removed ? ' · removed' : m.active ? '' : ' · switched off') + '</div></div>' +
      '<span class="dot-status ' + (m.online ? 'on' : '') + '">' + (m.online ? 'Active now' : esc(ago(m.last_seen))) + '</span></div>' +
      '<div class="p-total num">' + aed(m.total) + '</div><div class="bar" style="margin:6px 0 10px"><i style="width:' + (best ? Math.max(2, m.total / best * 100) : 0) + '%"></i></div>' +
      '<div class="p-stats"><span><b>' + m.sales + '</b> sales</span><span><b class="num">' + money(avg) + '</b> avg</span><span><b>' + m.items + '</b> items</span>' +
      '<span' + (m.discount ? ' class="warn"' : '') + '><b class="num">' + money(m.discount) + '</b> discounts</span>' +
      '<span' + (m.voids ? ' class="bad"' : '') + '><b>' + m.voids + '</b> voided</span></div>' +
      (m.total ? '<div class="small muted" style="margin-top:8px">' + Object.keys(pm).map(function (k) { return (METHOD[k] || k) + ' ' + money(pm[k]); }).join(' · ') + '</div>' : '') +
      (m.last_sale ? '<div class="small muted">Last sale ' + esc(ago(m.last_sale)) + '</div>' : '') + '</button>';
  }
  function staffBox() {
    var list = S._removed || [];
    openModal('Removed staff', '<div class="err m-err hidden"></div>' +
      (list.length ? '<p class="muted small" style="margin-top:0">Removed staff can\u2019t log in and are hidden from Performance. Their past sales stay in Reports. Tap a name to see their performance for ' + esc(teamRangeLabel()) + '.</p>' +
        '<div class="rm-list">' + list.map(function (u) {
          return '<div class="rm-item"><div class="rm-row"><button class="grow rm-name" data-act="rm-perf" data-id="' + u.id + '"><b>' + esc(u.name) + '</b><div class="small muted">' + esc(u.username) + ' · ' + (u.role === 'manager' ? 'Manager' : 'Staff') +
            '</div><div class="small rm-sum"><b class="num">' + aed(u.total) + '</b> · ' + u.sales + ' sale' + (u.sales === 1 ? '' : 's') + ' <span class="rm-arrow">▾ Performance</span></div></button>' +
            '<div class="rm-btns"><button class="btn sm gold" data-act="u-restore" data-id="' + u.id + '">Reactivate</button><button class="btn sm danger" data-act="u-forget" data-id="' + u.id + '">Remove forever</button></div></div>' +
            '<div class="rm-perf hidden" id="rm-perf-' + u.id + '">' + personCard(u, u.total, 'noop') + '</div></div>';
        }).join('') + '</div>'
      : '<p style="margin-top:0">No removed staff. To remove someone, go to <b>Staff logins</b>, tap <b>Edit</b>, then <b>Remove staff</b>.</p>') +
      '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Close</button></div>');
  }
  function forgetUser(u) {
    if (!u) return;
    openModal('Remove ' + u.name + ' forever?', '<div class="err m-err hidden"></div>' +
      '<p style="margin-top:0"><b>' + esc(u.name) + '</b> will be gone for good and <b>cannot be reactivated</b>.</p>' +
      '<p class="muted small">Their name stays on old receipts and in Reports so your sales totals don\u2019t change.</p>' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="t-staffbox">Back</button><button class="btn danger" type="button" data-act="u-forget-yes" data-id="' + u.id + '">Remove forever</button></div>');
  }
  function restoreUser(u) {
    if (!u) return;
    openModal('Reactivate ' + u.name + '?', '<div class="err m-err hidden"></div><form id="ur-form">' +
      '<p style="margin-top:0"><b>' + esc(u.name) + '</b> goes back on the staff list and can log in again with their old password.</p>' +
      '<label class="field hidden" id="ur-name"><span>New username</span><input class="input capw" name="username" autocapitalize="characters" autocorrect="off" spellcheck="false" value="' + esc(u.username) + '"></label>' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Cancel</button><button class="btn gold" type="submit">Reactivate</button></div></form>');
    $('#ur-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target, b = $('[type=submit]', f), box = $('#ur-name'); b.disabled = true;
      api('user_restore', { body: { id: u.id, username: box.classList.contains('hidden') ? '' : f.username.value } })
        .then(function () { closeModal(); toast(u.name + ' is back.'); S._users = null; loadTeam(); })
        .catch(function (er) {
          b.disabled = false; modalErr(er.message);
          if (/used by someone else/.test(er.message)) { box.classList.remove('hidden'); f.username.focus(); f.username.select(); }
        });
    });
  }
  function removeUser(u) {
    if (!u) return;
    openModal('Remove ' + u.name + '?', '<div class="err m-err hidden"></div>' +
      '<p style="margin-top:0"><b>' + esc(u.name) + '</b> will no longer be able to log in and is taken off the staff list.</p>' +
      '<p class="muted small">Their past sales and receipts stay in Reports. To only pause their login, use <b>Edit</b> and untick “Can log in” instead.</p>' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Keep</button><button class="btn danger" type="button" data-act="u-remove-yes" data-id="' + u.id + '">Remove</button></div>');
  }

  // ---------------------------------------------------------------- user menu
  function userMenu(btn) {
    closeMenu();
    var r = btn.getBoundingClientRect();
    var m = document.createElement('div');
    m.className = 'menu';
    m.style.top = Math.max(r.bottom + 6, 8) + 'px'; m.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
    m.innerHTML = '<div class="m-head"><b>' + esc(S.user.name) + '</b><div class="small muted">' + (isManager() ? 'Manager' : 'Staff') + ' · ' + esc(S.user.username) + '</div></div>' +
      '<div class="m-label">Appearance</div><div class="seg">' + [['auto', 'Auto'], ['light', 'Light'], ['dark', 'Dark']].map(function (o) {
        return '<button data-act="theme-set" data-t="' + o[0] + '" class="' + (themeChoice() === o[0] ? 'active' : '') + '">' + o[1] + '</button>';
      }).join('') + '</div>' +
      (isManager() ? '<button data-act="coupons">Coupons</button><button data-act="settings">Settings · payments &amp; VAT</button>' : '') + '<button data-act="pw">Change password</button><button data-act="reload">Refresh app</button><button data-act="logout">Log out</button>';
    document.body.appendChild(m);
  }
  function settingsModal() {
    openModal('Settings', '<div class="status-line"><span class="spinner"></span> Loading…</div>');
    api('settings_get').then(function (d) {
      setModalBody('<div class="err m-err hidden"></div><form id="set-form" autocomplete="off">' +
        '<h3 class="set-h">Ziina payments</h3>' +
        '<label class="field"><span>Ziina API key' + (d.ziina_key_saved ? ' — saved (ends in …' + esc(d.ziina_key_end) + ')' : '') + '</span>' +
        '<input class="input" name="ziina_token" type="password" autocomplete="new-password" spellcheck="false" placeholder="' + (d.ziina_key_saved ? 'Paste a new key to replace it' : 'Paste your Ziina API key here') + '"></label>' +
        '<label class="field"><span>Payments mode</span><select class="input" name="ziina_mode">' +
        [['demo', 'Practice — no Ziina account used'], ['test', 'Test — Ziina test payments, no real money'], ['live', 'Live — real payments']].map(function (o) {
          return '<option value="' + o[0] + '"' + (d.ziina_mode === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
        }).join('') + '</select></label>' +
        '<p class="small muted" style="margin:-4px 0 14px">Start with <b>Test</b>: make a Ziina QR sale and pay it with a Ziina test card. When it confirms by itself, switch to <b>Live</b>.</p>' +
        (d.ziina_key_saved ? '<label class="check"><input type="checkbox" name="remove_ziina_token"> Remove the saved key</label>' : '') +
        '<h3 class="set-h">Coupons</h3>' +
        '<label class="check"><input type="checkbox" name="web_coupons" ' + (d.web_coupons ? 'checked' : '') + '> Also accept fomaxo.com coupon codes in the store</label>' +
        '<p class="small muted" style="margin:-6px 0 14px">Your store codes (menu → Coupons) always work. Tick this to also accept the website\u2019s codes.</p>' +
        '<h3 class="set-h">VAT</h3>' +
        '<label class="field"><span>TRN (VAT registration number, 15 digits)</span><input class="input" name="store_trn" inputmode="numeric" value="' + esc(d.store_trn) + '" placeholder="Leave empty while not registered"></label>' +
        '<p class="small muted" style="margin:-4px 0 6px">Empty: no VAT on sales or receipts. With a TRN: VAT ' + 5 + '% (included) shows and receipts become Tax invoices.</p>' +
        '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Cancel</button><button class="btn gold" type="submit">Save</button></div></form>' +
        '<h3 class="set-h danger-h">Start fresh</h3>' +
        '<p class="small muted" style="margin:0 0 10px">Deletes every store sale and restarts order numbers at 10000. A backup is saved on the server first. fomaxo.com website orders are not touched.</p>' +
        '<button class="btn danger block" type="button" data-act="reset-open">Delete all sales and start again…</button>');
      $('#set-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var f = e.target, b = $('[type=submit]', f); b.disabled = true;
        var body = { ziina_mode: f.ziina_mode.value, store_trn: f.store_trn.value, web_coupons: f.web_coupons.checked ? 1 : 0 };
        if (f.ziina_token.value.trim()) body.ziina_token = f.ziina_token.value.trim();
        if (f.remove_ziina_token && f.remove_ziina_token.checked) { body.remove_ziina_token = 1; body.ziina_mode = 'demo'; }
        api('settings_save', { body: body }).then(function () {
          f.ziina_token.value = '';
          return refreshMe();
        }).then(function () {
          closeModal(); toast('Settings saved.'); if (S.view === 'sell') renderCart();
        }).catch(function (er) { b.disabled = false; modalErr(er.offline ? 'Needs internet.' : er.message); });
      });
    }).catch(function (e) { setModalBody('<div class="err">' + esc(e.offline ? 'Needs internet.' : e.message) + '</div>'); });
  }
  function resetModal() {
    openModal('Start fresh', '<div class="err m-err hidden"></div><form id="reset-form" autocomplete="off">' +
      '<div class="err" style="margin-bottom:12px"><b>All store sales will be deleted.</b> Sales, Reports and Performance start empty and order numbers restart at 10000. This cannot be undone in the app (a backup is saved on the server).</div>' +
      '<div class="field"><span>Stock</span>' +
        '<label class="check"><input type="radio" name="stock" value="keep" checked> Leave stock as it is</label>' +
        '<label class="check"><input type="radio" name="stock" value="restore"> Put back what the deleted sales took</label>' +
        '<label class="check"><input type="radio" name="stock" value="five"> Set every product to 5 in each store</label></div>' +
      '<div class="field"><span>Also clear</span>' +
        '<label class="check"><input type="checkbox" name="activity" checked> Activity log</label>' +
        '<label class="check"><input type="checkbox" name="history" checked> Stock history</label>' +
        '<label class="check"><input type="checkbox" name="customers" checked> Customers</label></div>' +
      '<label class="field"><span>Type <b>DELETE</b> to confirm</span><input class="input" name="confirm" autocapitalize="characters" autocorrect="off" spellcheck="false" style="text-transform:uppercase" placeholder="DELETE"></label>' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Cancel</button><button class="btn danger" type="submit" disabled>Delete all sales</button></div></form>');
    var f = $('#reset-form'), btn = $('[type=submit]', f);
    f.confirm.addEventListener('input', function () { btn.disabled = f.confirm.value.trim().toUpperCase() !== 'DELETE'; });
    f.addEventListener('submit', function (e) {
      e.preventDefault(); btn.disabled = true; btn.textContent = 'Deleting…';
      api('reset_all', { body: { confirm: f.confirm.value.trim().toUpperCase(), stock: $('input[name=stock]:checked', f).value,
        activity: f.activity.checked ? 1 : 0, history: f.history.checked ? 1 : 0, customers: f.customers.checked ? 1 : 0 }, timeout: 60000 })
        .then(function (d) { closeModal(); toast('Done — ' + d.deleted + ' sale' + (d.deleted === 1 ? '' : 's') + ' deleted. Next order is 10000.'); S._users = null; refreshProducts(); renderView(); })
        .catch(function (er) { btn.disabled = false; btn.textContent = 'Delete all sales'; modalErr(er.offline ? 'Needs internet.' : er.message); });
    });
  }
  function couponsModal() {
    openModal('Coupons', '<div class="status-line"><span class="spinner"></span> Loading…</div>', { wide: true });
    api('coupons').then(function (d) {
      S._coupons = d.coupons;
      setModalBody('<div class="row" style="margin-bottom:12px"><p class="small muted grow" style="margin:0">Codes your staff type in the bag. ' +
        (d.web ? 'fomaxo.com codes are also accepted (Settings).' : 'Website codes are not accepted (switch in Settings).') + '</p>' +
        '<button class="btn gold" data-act="cpn-new">+ New coupon</button></div>' +
        (d.coupons.length ? '<div class="table-wrap"><table class="tbl"><thead><tr><th>Code</th><th>Gives</th><th class="hide-sm">Rules</th><th class="r">Used</th><th class="r"></th></tr></thead><tbody>' +
          d.coupons.map(function (c) {
            var rules = [c.min_order ? 'min AED ' + money(c.min_order) : '', c.ends ? 'until ' + c.ends : '', c.max_uses ? 'max ' + c.max_uses + ' uses' : '', c.store_name ? c.store_name + ' only' : ''].filter(Boolean).join(' · ') || 'Any time, any store';
            return '<tr><td><b class="num">' + esc(c.code) + '</b>' + (c.active ? '' : ' <span class="badge off">Off</span>') + (c.note ? '<div class="small muted">' + esc(c.note) + '</div>' : '') + '</td>' +
              '<td>' + esc(c.label) + '</td><td class="hide-sm small muted">' + esc(rules) + '</td><td class="r num">' + c.uses + '</td>' +
              '<td class="r"><button class="btn sm" data-act="cpn-edit" data-id="' + c.id + '">Edit</button></td></tr>';
          }).join('') + '</tbody></table></div>' : '<div class="empty"><div class="big">No coupons yet</div>Press <b>+ New coupon</b>, e.g. WELCOME10 for 10% off.</div>'));
    }).catch(function (e) { setModalBody('<div class="err">' + esc(e.offline ? 'Needs internet.' : e.message) + '</div>'); });
  }
  function couponEdit(c) {
    var isNew = !c; c = c || { code: '', kind: 'pct', amount: 0, min_order: 0, free_product_id: null, ends: '', max_uses: null, store_id: null, active: 1, note: '' };
    var prods = S.products.filter(function (p) { return p.active || p.id === c.free_product_id; });
    openModal(isNew ? 'New coupon' : 'Edit coupon ' + c.code, '<div class="err m-err hidden"></div><form id="cpn-form" autocomplete="off">' +
      '<label class="field"><span>Code (what the customer says)</span><input class="input coupon-in" name="code" required value="' + esc(c.code) + '" placeholder="e.g. WELCOME10" autofocus></label>' +
      '<label class="field"><span>Gives</span><select class="input" name="kind"><option value="pct"' + (c.kind === 'pct' ? ' selected' : '') + '>% off the bag</option><option value="aed"' + (c.kind === 'aed' ? ' selected' : '') + '>AED off the bag</option><option value="free"' + (c.kind === 'free' ? ' selected' : '') + '>A free product (gift)</option></select></label>' +
      '<div class="grid2"><label class="field k-amt"><span class="k-amt-l">Amount</span><input class="input" name="amount" type="number" step="0.01" min="0" value="' + (c.kind === 'free' || !c.amount ? '' : (c.amount / 100)) + '"></label>' +
      '<label class="field"><span>Minimum bag AED (optional)</span><input class="input" name="min_order" type="number" step="0.01" min="0" value="' + (c.min_order ? c.min_order / 100 : '') + '"></label></div>' +
      '<label class="field k-free"><span>Free product</span><select class="input" name="free_product_id">' + prods.map(function (p) {
        return '<option value="' + p.id + '"' + (p.id === c.free_product_id ? ' selected' : '') + '>' + esc(shortName(p.name) + ' ' + p.variant) + '</option>'; }).join('') + '</select></label>' +
      '<div class="grid2"><label class="field"><span>Last day (optional)</span><input class="input" name="ends" type="date" value="' + esc(c.ends || '') + '"></label>' +
      '<label class="field"><span>Max uses (optional)</span><input class="input" name="max_uses" type="number" min="1" value="' + (c.max_uses || '') + '"></label></div>' +
      (multiStore() ? '<label class="field"><span>Where</span><select class="input" name="store_id"><option value="">All stores</option>' + S.stores.map(function (x) { return '<option value="' + x.id + '"' + (x.id === c.store_id ? ' selected' : '') + '>' + esc(x.name) + ' only</option>'; }).join('') + '</select></label>' : '') +
      '<label class="field"><span>Note (optional, only you see it)</span><input class="input" name="note" value="' + esc(c.note || '') + '" placeholder="e.g. Instagram promo"></label>' +
      '<label class="check"><input type="checkbox" name="active" ' + (c.active ? 'checked' : '') + '> Active (can be used)</label>' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="coupons">Back</button><button class="btn gold" type="submit">Save</button></div></form>', { wide: true });
    var f = $('#cpn-form');
    var sync = function () {
      var k = f.kind.value;
      $('.k-free', f).classList.toggle('hidden', k !== 'free');
      $('.k-amt', f).classList.toggle('hidden', k === 'free');
      $('.k-amt-l', f).textContent = k === 'pct' ? 'Percent off (%)' : 'AED off';
    };
    f.kind.addEventListener('change', sync); sync();
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var b = $('[type=submit]', f); b.disabled = true;
      api('coupon_save', { body: { id: isNew ? 0 : c.id, code: f.code.value, kind: f.kind.value, amount: f.amount.value, min_order: f.min_order.value,
        free_product_id: f.free_product_id.value, ends: f.ends.value, max_uses: f.max_uses.value, store_id: f.store_id ? f.store_id.value : '', note: f.note.value, active: f.active.checked ? 1 : 0 } })
        .then(function () { toast('Coupon saved.'); couponsModal(); })
        .catch(function (er) { b.disabled = false; modalErr(er.message); });
    });
  }
  function passwordModal() {
    openModal('Change password', '<div class="err m-err hidden"></div><form id="pw-form">' +
      '<label class="field"><span>Current password</span><input class="input" name="current" type="password" required autofocus></label>' +
      '<label class="field"><span>New password (8+ characters)</span><input class="input" name="new" type="password" minlength="8" required></label>' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Cancel</button><button class="btn gold" type="submit">Save</button></div></form>');
    $('#pw-form').addEventListener('submit', function (e) {
      e.preventDefault(); var f = e.target;
      api('change_password', { body: { current: f.current.value, 'new': f['new'].value } }).then(function () { closeModal(); toast('Password changed.'); }).catch(function (er) { modalErr(er.message); });
    });
  }
  function logout(force) {
    closeMenu();
    if (S.queue.length && !force) {
      openModal('Sales waiting to upload', '<p style="margin-top:0">' + S.queue.length + ' offline sale(s) have not uploaded yet. Connect to the internet and wait a moment before logging out.</p>' +
        '<p class="small muted">If you log out anyway, they stay saved on this device and upload after the next login.</p>' +
        '<div class="modal-foot"><button class="btn gold" data-act="modal-close">Stay logged in</button><button class="btn danger" data-act="logout-force">Log out anyway</button></div>');
      return;
    }
    closeModal();
    api('logout', { body: {} }).catch(function () { /* fine offline */ });
    S.token = null; LS.del('fx_token');
    S.cart = []; S.discount = null; S.customer = null;
    renderLogin();
  }

  // ---------------------------------------------------------------- clicks
  // Accidental taps on Sell: ignore a tap that ends a scroll, and a second tap within 400 ms.
  var lastScroll = 0, touchMoved = false, touchY = 0, lastPick = 0;
  document.addEventListener('scroll', function () { lastScroll = Date.now(); }, true);
  document.addEventListener('touchstart', function (e) { touchMoved = false; touchY = e.touches[0].clientY; }, { passive: true });
  document.addEventListener('touchmove', function (e) { if (Math.abs(e.touches[0].clientY - touchY) > 8) touchMoved = true; }, { passive: true });
  function guardedTap(act) {
    if (act !== 'pick' && act !== 'add-size') return false;
    var now = Date.now();
    if (touchMoved || now - lastScroll < 350) return true;   // that tap stopped or ended a scroll
    if (now - lastPick < 400) return true;                    // double tap
    lastPick = now;
    return false;
  }
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-act]');
    if (!e.target.closest('.menu') && !(el && el.getAttribute('data-act') === 'user-menu')) closeMenu();
    if (!el || el.disabled) return;
    var act = el.getAttribute('data-act'), id = parseInt(el.getAttribute('data-id'), 10);
    if (guardedTap(act)) return;
    switch (act) {
      case 'nav': closeMenu(); if (el.getAttribute('data-tab')) S.teamTab = el.getAttribute('data-tab'); S.view = el.getAttribute('data-view'); S.cartOpen = false; renderView(); if (S.view === 'sell' || S.view === 'stock') refreshProducts(); break;
      case 'net':
        if (S.online && S.queue.length) syncQueue();
        toast(S.online ? (S.queue.length ? 'Uploading waiting sales…' : 'Connected. Everything is up to date.') : 'Offline. Cash, Tap to Pay and card-machine sales are saved on this device and upload when internet is back.');
        break;
      case 'user-menu': if ($('.menu')) closeMenu(); else userMenu(el); break;
      case 'store-menu': if ($('.menu')) closeMenu(); else storeMenu(el); break;
      case 'store-pick': pickStore(id); break;
      case 'web-sync':
        el.disabled = true; el.textContent = 'Syncing…';
        api('import_web', { body: {}, timeout: 30000 }).then(function (r) {
          toast(r.added ? r.added + ' new product size' + (r.added > 1 ? 's' : '') + ' added from fomaxo.com.' : 'Up to date with fomaxo.com — prices checked.');
          return refreshProducts();
        }).catch(function (er) { toast(er.offline ? 'Needs internet.' : er.message, true); })
          .then(function () { if (S.view === 'stock') renderStock(); });
        break;
      case 'scope': S.stockScope = el.getAttribute('data-s'); renderStock(); break;
      case 'p-move': transferModal(productById(id)); break;
      case 'bulk-open': bulkModal(); break;
      case 'bulk-mode': {
        var bm = el.getAttribute('data-m');
        $('.modal').setAttribute('data-mode', bm);
        $$('[data-act=bulk-mode]').forEach(function (b) { b.classList.toggle('active', b === el); });
        $('#bulk-help').textContent = bm === 'set' ? 'Type the number on the shelf for each product. Empty boxes are left as they are.' : 'Type how many of each product arrived. They are added to what is already there.';
        break;
      }
      case 'bulk-fill': $$('.bulk-in').forEach(function (i) { i.value = $('#bulk-all').value; }); break;
      case 'bulk-clear': $$('.bulk-in').forEach(function (i) { i.value = ''; }); break;
      case 'bulk-save': {
        var items = $$('.bulk-in').filter(function (i) { return i.value !== ''; }).map(function (i) { return { product_id: parseInt(i.getAttribute('data-id'), 10), qty: parseInt(i.value, 10) }; });
        if (!items.length) { modalErr('Type at least one number.'); break; }
        el.disabled = true;
        api('stock_bulk', { body: { store_id: curStoreId(), mode: $('.modal').getAttribute('data-mode'), items: items } })
          .then(function (r) { closeModal(); toast(r.changed + ' product' + (r.changed === 1 ? '' : 's') + ' updated.'); refreshProducts(); })
          .catch(function (er) { el.disabled = false; modalErr(er.offline ? 'Needs internet to save stock.' : er.message); });
        break;
      }
      case 't-save': {
        var tq = parseInt($('#t-qty').value, 10);
        if (!(tq > 0)) { modalErr('Enter how many to move.'); break; }
        el.disabled = true;
        api('stock_transfer', { body: { product_id: id, from: parseInt($('#t-from').value, 10), to: parseInt($('#t-to').value, 10), qty: tq, note: $('#t-note').value } })
          .then(function () { closeModal(); toast('Stock moved.'); refreshProducts(); })
          .catch(function (er) { el.disabled = false; modalErr(er.offline ? 'Needs internet.' : er.message); });
        break;
      }
      case 'team-tab': S.teamTab = el.getAttribute('data-t'); renderStaff(); break;
      case 't-preset': {
        var tr = presetRange(el.getAttribute('data-p'));
        S.team.preset = el.getAttribute('data-p'); S.team.from = tr.from; S.team.to = tr.to;
        renderStaff(); break;
      }
      case 't-person': S.team.user = String(id); S.teamTab = 'activity'; renderStaff(); break;
      case 'st-new': storeModal(null); break;
      case 'st-edit': storeModal(storeById(id)); break;
      case 'theme-toggle': setTheme(isDark() ? 'light' : 'dark'); break;
      case 'theme-set':
        setTheme(el.getAttribute('data-t'));
        $$('.menu [data-act=theme-set]').forEach(function (b) { b.classList.toggle('active', b === el); });
        break;
      case 'pw': closeMenu(); passwordModal(); break;
      case 'settings': closeMenu(); settingsModal(); break;
      case 'coupons': closeMenu(); couponsModal(); break;
      case 'cpn-new': couponEdit(null); break;
      case 'cpn-edit': couponEdit((S._coupons || []).filter(function (x) { return x.id === id; })[0]); break;
      case 'reload': location.reload(); break;
      case 'logout': logout(false); break;
      case 'logout-force': logout(true); break;
      case 'modal-close': closeModal(); break;
      // sell
      case 'cat': S.category = el.getAttribute('data-cat'); renderChips(); renderTiles(); break;
      case 'add': addToCart(id); break;
      case 'pick': pickGroup(el.getAttribute('data-g')); break;
      case 'sv':
        S.sellView = el.getAttribute('data-v'); LS.set('fx_sell_view', S.sellView);
        closeModal(); $('#tiles').classList.toggle('list', S.sellView === 'list'); renderTiles(); syncSortBtn(); break;
      case 'sort-open': sortModal(); break;
      case 'sort-set':
        S.sellSort = el.getAttribute('data-k'); LS.set('fx_sell_sort', S.sellSort);
        closeModal(); syncSortBtn(); renderTiles();
        toast('Sorted: ' + SORTS[S.sellSort]); break;
      case 'add-size': if (addToCart(id)) closeModal(); break;
      case 'pay-open':
        if (!S.cart.length) break;
        if (S.coupon && !cartTotals().couponOk) { toast('Coupon ' + S.coupon.code + ' needs a bag of at least AED ' + money(Math.round(S.coupon.min * 100)) + '. Add more or remove the coupon.', true); break; }
        payChooser(); break;
      case 'more': moreModal(); break;
      case 'more2': couponDiscountModal(); break;
      case 'r-show': showReceipt(currentReceipt, false); break;
      case 'qty': changeQty(id, parseInt(el.getAttribute('data-d'), 10)); break;
      case 'cart-clear':
        openModal('Empty the bag?', '<p style="margin-top:0">Remove all items' + (S.customer || S.coupon || S.discount ? ', the customer, coupon and discount' : '') + ' from the bag?</p>' +
          '<div class="modal-foot"><button class="btn big" data-act="modal-close">No, keep</button><button class="btn danger big" data-act="cart-clear-yes">Yes, empty</button></div>');
        break;
      case 'cart-clear-yes': {
        var snapAll = snapshotBag();
        S.cart = []; S.discount = null; S.customer = null; S.coupon = null; closeModal(); renderTiles(); renderCart();
        flashAdded('everything', function () { restoreBag(snapAll); }, true);
        break;
      }
      case 'undo': if (undoFn) { var u = undoFn; undoFn = null; u(); var pop = $('.added-pop'); if (pop) pop.remove(); toast('Undone.'); } break;
      case 'coupon': couponModal(); break;
      case 'cp-remove': S.coupon = null; closeModal(); renderCart(); toast('Coupon removed.'); break;
      case 'cp-apply': {
        var code = ($('#cp-code').value || '').trim().toUpperCase();
        if (!code) { modalErr('Type the coupon code.'); break; }
        el.disabled = true;
        api('coupon_check', { body: { code: code, phone: S.customer ? S.customer.phone : '', store_id: curStoreId() } }).then(function (d) {
          var c = d.coupon;
          if (c.kind === 'free') {
            var fp = productById(c.free.product_id);
            if (!fp || fp.stock <= 0) { el.disabled = false; modalErr('The free gift for this coupon is out of stock at this store.'); return; }
          }
          S.coupon = c; closeModal(); renderCart();
          var t = cartTotals();
          toast(t.couponOk ? '🎟 Coupon ' + c.code + ' applied' + (c.kind === 'free' ? ' — free gift added.' : '.') : 'Coupon added, but the bag must be at least AED ' + money(Math.round(c.min * 100)) + '.', !t.couponOk);
        }).catch(function (er) { el.disabled = false; modalErr(er.offline ? 'Coupons need internet to check.' : er.message); });
        break;
      }
      case 'cart-open': setCartOpen(true); break;
      case 'cart-close': setCartOpen(false); break;
      case 'customer': customerModal(); break;
      case 'discount': discountModal(); break;
      case 'c-find': findCustomer(); break;
      case 'c-save': {
        var ph = fullPhone(), nat = phoneNational(), em = $('#c-email').value.trim().toLowerCase(), nm = titleCase($('#c-name').value);
        if (!ph && !em && !nm) { S.customer = null; closeModal(); renderCart(); break; }
        if (ph && $('#c-cc').value === 'AE' && !/^5\d{8}$/.test(nat)) { modalErr('UAE mobile numbers are 9 digits after +971 and start with 5, e.g. 50 123 4567.'); break; }
        if (ph && (nat.length < 6 || nat.length > 14)) { modalErr('Enter the full mobile number, without the country code.'); break; }
        if (em && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) { modalErr('That email does not look right.'); break; }
        if (!ph && !em) { modalErr('Add a mobile number or an email for the customer.'); break; }
        var f = S._found;
        S.customer = { phone: ph, name: nm, email: em, tier: f ? f.tier : null };
        closeModal(); renderCart(); break;
      }
      case 'c-remove': S.customer = null; closeModal(); renderCart(); break;
      case 'd-type': {
        var t = el.getAttribute('data-t');
        $$('[data-act=d-type]').forEach(function (b) { b.classList.toggle('active', b === el); });
        $('.modal').setAttribute('data-type', t);
        $('#d-label').textContent = t === 'percent' ? 'Discount %' : 'Discount in AED';
        break;
      }
      case 'd-save': {
        var type = $('.modal').getAttribute('data-type'), val = parseFloat($('#d-val').value);
        if (!(val > 0)) { S.discount = null; closeModal(); renderCart(); break; }
        if (type === 'percent' && val > 100) { modalErr('Percent cannot be more than 100.'); break; }
        var sub = calcTotals(cartLines(), null).subtotal;
        var pct = type === 'percent' ? val : (sub ? val * 100 * 100 / sub : 0);
        if (!isManager() && pct > S.settings.staff_max_discount + 0.001) { modalErr('Staff discounts are limited to ' + S.settings.staff_max_discount + '%.'); break; }
        S.discount = { type: type, value: val }; closeModal(); renderCart(); break;
      }
      case 'd-remove': S.discount = null; closeModal(); renderCart(); break;
      case 'pay': if (S.cart.length) payModal(el.getAttribute('data-m')); break;
      case 'copy-link': {
        var lnk = el.getAttribute('data-v');
        (navigator.clipboard ? navigator.clipboard.writeText(lnk) : Promise.reject()).then(function () { toast('Payment link copied — paste it to the customer.'); }, function () { window.prompt('Copy this payment link:', lnk); });
        break;
      }
      case 'copy-amount': {
        var amt = el.getAttribute('data-v');
        (navigator.clipboard ? navigator.clipboard.writeText(amt) : Promise.reject()).then(function () { toast('Amount ' + amt + ' copied — paste it in Ziina.'); }, function () { toast('Amount: ' + amt); });
        break;
      }
      case 'cash-quick':
        $('#cash-in').value = el.getAttribute('data-v');
        $$('.note-btn').forEach(function (b) { b.classList.toggle('active', b === el); });
        updateChange(); break;
      case 'confirm-pay': confirmPay(el.getAttribute('data-m'), el); break;
      case 'reset-open': resetModal(); break;
      case 'z-retry': stopPoll(); ziinaRetry(id); break;
      case 'z-cancel': cancelReasonForm(id, !!$('.qr-box')); break;
      case 'pay-cancel': cancelReasonForm(0, false, el.getAttribute('data-m')); break;
      case 'pay-back': payModal(el.getAttribute('data-m')); break;
      case 'pa-open': api('sale_get', { query: '&id=' + id }).then(function (d) { payAgainModal(d.sale); }).catch(function (er) { toast(er.message, true); }); break;
      case 'pa-m': payAgainMethod(el.getAttribute('data-m')); break;
      case 'sw-m': if (!el.classList.contains('active')) switchMethod(el.getAttribute('data-m'), el.getAttribute('data-ctx')); break;
      case 'pa-confirm': payAgainConfirm(el.getAttribute('data-m'), el); break;
      case 'z-back':
        if (el.getAttribute('data-qr') === '1' && lastQr && lastQr.d.sale.id === id) { stopPoll(); showZiinaQr(lastQr.d, lastQr.cust); }
        else { api('sale_get', { query: '&id=' + id }).then(function (d) { showReceipt(d.sale, false); }).catch(function () { closeModal(); }); }
        break;
      // receipt
      case 'r-print': $('#print-area').innerHTML = paperHtml(currentReceipt); window.print(); break;
      case 'r-pdf': { var rf = $('#rframe'); try { rf.contentWindow.focus(); rf.contentWindow.print(); } catch (e) { window.open(currentReceipt.receipt_url, '_blank', 'noopener'); } break; }
      case 'r-wa': {
        var s = currentReceipt, digits = (s.customer_phone || '').replace(/\D/g, '');
        window.open('https://wa.me/' + digits + '?text=' + encodeURIComponent(receiptText(s)), '_blank', 'noopener');
        break;
      }
      case 'r-copy': {
        var url = currentReceipt.receipt_url;
        (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () { toast('Receipt link copied.'); }, function () { prompt('Copy this link:', url); });
        break;
      }
      case 'r-email': location.href = 'mailto:' + encodeURIComponent(currentReceipt.customer_email || '') + '?subject=' + encodeURIComponent(S.settings.store_name + ' receipt ' + currentReceipt.receipt_no) + '&body=' + encodeURIComponent(receiptText(currentReceipt)); break;
      case 'r-void': voidModal(currentReceipt); break;
      case 'r-delete': {
        var ds = currentReceipt; if (!ds || ds.id !== id) break;
        var isPaid = ds.status === 'paid', num = /^FMX-/.test(ds.receipt_no) ? ds.receipt_no : 'this unpaid order';
        openModal('Delete ' + (isPaid || ds.status === 'void' ? ds.receipt_no : 'order') + '?', '<div class="err m-err hidden"></div>' +
          '<p style="margin-top:0"><b>' + esc(num) + '</b> (' + aed(ds.total) + ') will be removed from Sales, Reports and Performance. <b>This cannot be undone.</b></p>' +
          (isPaid ? '<p class="muted small">Its items go back into stock. If it is the newest order, the next sale reuses its number.</p>' : '') +
          '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Keep</button><button class="btn danger" type="button" data-act="r-delete-yes" data-id="' + ds.id + '">Delete order</button></div>');
        break;
      }
      case 'r-delete-yes':
        el.disabled = true;
        api('sale_delete', { body: { id: id } }).then(function (d) {
          closeModal(); toast('Order deleted.' + (d.stock_back ? ' ' + d.stock_back + ' item' + (d.stock_back === 1 ? '' : 's') + ' back in stock.' : ''));
          refreshProducts(); if (S.view === 'sales') renderSales();
        }).catch(function (er) { el.disabled = false; modalErr(er.offline ? 'Needs internet.' : er.message); });
        break;
      case 'v-save': {
        var reason = $('#v-reason').value.trim();
        if (!reason) { modalErr('Please give a reason.'); break; }
        el.disabled = true;
        api('void_sale', { body: { id: id, reason: reason, restock: $('#v-restock').checked ? 1 : 0 } }).then(function () {
          closeModal(); toast('Sale voided.'); refreshProducts(); if (S.view === 'sales') loadSales();
        }).catch(function (er) { el.disabled = false; modalErr(er.message); });
        break;
      }
      // stock
      case 'sf': S.stockFilter = el.getAttribute('data-f'); renderStockBody(); break;
      case 'p-new': productModal(null); break;
      case 'p-edit': productModal(productById(id)); break;
      case 'p-adjust': adjustModal(productById(id)); break;
      case 'p-history': historyModal(productById(id)); break;
      case 'a-save': {
        var r = $('#a-reason').value, n = parseInt($('#a-num').value, 10);
        if (isNaN(n)) { modalErr('Enter a number.'); break; }
        var sign = REASONS.filter(function (x) { return x[0] === r; })[0][2];
        var body = { product_id: id, reason: r, note: $('#a-note').value, store_id: curStoreId() };
        if (r === 'count') { if (n < 0) { modalErr('Count cannot be negative.'); break; } body.counted = n; }
        else body.change = sign === 2 ? n : sign * Math.abs(n);
        el.disabled = true;
        api('stock_adjust', { body: body }).then(function () { closeModal(); toast('Stock updated.'); refreshProducts(); })
          .catch(function (er) { el.disabled = false; modalErr(er.offline ? 'Needs internet to change stock.' : er.message); });
        break;
      }
      // sales
      case 's-open':
        api('sale_get', { query: '&id=' + id }).then(function (d) { showReceipt(d.sale, false); }).catch(function (er) { toast(er.message, true); });
        break;
      case 'q-view': showReceipt(S.queue[parseInt(el.getAttribute('data-i'), 10)].local, false); break;
      case 'sync-now': syncQueue(); break;
      case 'q-retry': delete S.queue[parseInt(el.getAttribute('data-i'), 10)].error; saveQueue(); syncQueue().then(function () { if (S.view === 'sales') renderSales(); }); break;
      case 'q-drop':
        if (confirm('Discard this offline sale? It will not be recorded and stock will not change.')) {
          S.queue.splice(parseInt(el.getAttribute('data-i'), 10), 1); saveQueue(); updateNet(); renderSales(); refreshProducts();
        }
        break;
      // reports
      case 'r-preset': {
        var pr = el.getAttribute('data-p'), rg = presetRange(pr);
        S.report = { preset: pr, from: rg.from, to: rg.to };
        $('#rf').value = rg.from; $('#rt').value = rg.to;
        $$('[data-act=r-preset]').forEach(function (b) { b.classList.toggle('active', b === el); });
        loadReport(); break;
      }
      // staff
      case 'u-new': userModal(null); break;
      case 'u-edit': userModal((S._users || []).filter(function (u) { return u.id === id; })[0]); break;
      case 't-staffbox': staffBox(); break;
      case 'rm-perf': { var pb = $('#rm-perf-' + id); if (pb) { pb.classList.toggle('hidden'); el.classList.toggle('open', !pb.classList.contains('hidden')); } break; }
      case 'noop': break;
      case 'u-forget': forgetUser((S._removed || []).filter(function (u) { return u.id === id; })[0]); break;
      case 'u-forget-yes':
        el.disabled = true;
        api('user_forget', { body: { id: id } })
          .then(function () { closeModal(); toast('Removed forever.'); S._users = null; loadTeam(); })
          .catch(function (er) { el.disabled = false; modalErr(er.message); });
        break;
      case 'u-restore': restoreUser((S._removed || []).filter(function (u) { return u.id === id; })[0]); break;
      case 'u-remove': removeUser((S._users || []).filter(function (u) { return u.id === id; })[0]); break;
      case 'u-remove-yes':
        el.disabled = true;
        api('user_remove', { body: { id: id } })
          .then(function () { closeModal(); toast('Staff removed.'); S._users = null; loadTeam(); })
          .catch(function (er) { el.disabled = false; modalErr(er.message); });
        break;
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modalState && !$('.modal-back [data-act=confirm-pay]') && !ziinaPoll) closeModal();
    if (e.key === 'Enter' && e.target.id === 'c-phone') { e.preventDefault(); findCustomer(); }
    if (e.key === 'Enter' && e.target.id === 'cash-in') { e.preventDefault(); var b = $('[data-act=confirm-pay]'); if (b) b.click(); }
  });
  window.addEventListener('online', function () { setOnline(true); refreshProducts(); });
  window.addEventListener('offline', function () { setOnline(false); });

  // ---------------------------------------------------------------- start
  if ('serviceWorker' in navigator && location.protocol !== 'file:') {
    navigator.serviceWorker.register('sw.js', { updateViaCache: 'none' }).then(function (reg) {
      // Check for a new version when the app is opened again or comes back to the screen.
      document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') reg.update().catch(function () {}); });
    }).catch(function () { /* app still works online */ });
    // A new version took over: reload once, but never in the middle of a sale.
    var hadController = !!navigator.serviceWorker.controller, reloading = false;
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (!hadController || reloading) { hadController = true; return; }
      var reloadWhenFree = function () {
        if (S.cart.length || modalState) { setTimeout(reloadWhenFree, 5000); return; }
        reloading = true; location.reload();
      };
      reloadWhenFree();
    });
  }
  setInterval(function () {
    if (!S.token) return;
    if (S.view === 'sell' || S.view === 'stock') refreshProducts();
    syncQueue();
  }, 30000);

  applyTheme();
  if (S.token && S.user && S.settings) {
    renderApp();
    refreshMe().then(function () { if (S.token) { updateNet(); } });
    refreshProducts().then(syncQueue);
  } else {
    renderLogin();
  }
})();
