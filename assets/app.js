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
  function initials(n) { return String(n || '?').trim().split(/\s+/).map(function (w) { return w[0]; }).join('').slice(0, 2).toUpperCase(); }
  function pName(p) { return p.name + (p.variant ? ' · ' + p.variant : ''); }

  var WORDMARK = '<svg class="wm" viewBox="0 0 3196 481" role="img" aria-label="FOMAXO"><path fill="currentColor" fill-rule="evenodd" d="M1068 147 1068 480 1135 480 1135 214ZM1778 123 1581 480 1672 479 1815 221 1823 204ZM0 480 68 480 69 257 377 257 377 189 69 189 68 105 0 105ZM2934 1 2911 4 2871 15 2837 31 2801 56 2768 90 2743 128 2726 168 2720 191 2716 218 2716 263 2723 303 2739 346 2750 366 2766 389 2776 401 2802 426 2837 450 2864 463 2890 472 2926 479 2973 480 3003 476 3023 471 3048 462 3069 452 3108 426 3141 393 3166 356 3185 311 3194 267 3194 215 3186 174 3178 151 3165 124 3154 106 3138 85 3110 57 3089 41 3069 29 3027 11 2998 4 2976 1ZM2933 70 2966 69 3005 76 3038 90 3064 108 3087 131 3105 157 3120 192 3126 222 3126 259 3120 289 3107 321 3092 344 3064 373 3039 390 3013 402 2976 411 2934 411 2902 404 2869 389 2847 374 2822 349 2803 321 2789 287 2784 262 2783 229 2790 191 2805 157 2823 131 2847 107 2872 90 2901 77ZM2623 1 2538 1 2431 145 2421 161 2463 217ZM2198 1 2366 233 2184 480 2269 480 2411 293 2417 299 2550 480 2635 480 2276 1ZM722 1 677 9 654 17 625 31 590 55 555 91 533 124 520 151 513 171 504 216 504 265 513 311 531 354 557 393 591 427 623 449 647 461 671 470 714 479 760 480 785 477 811 471 855 453 897 425 928 394 948 366 958 348 974 307 982 265 982 217 973 171 954 126 939 102 925 84 893 53 869 36 851 26 815 11 786 4 763 1ZM721 70 754 69 793 76 829 92 848 105 873 129 894 159 908 193 914 224 914 258 907 292 893 324 876 349 854 371 825 391 792 405 764 411 721 411 693 405 660 391 635 374 609 348 592 323 580 297 572 264 571 227 577 194 583 177 594 155 610 132 636 106 658 91 689 77ZM0 1 0 69 423 69 423 1ZM1847 0 1802 82 2022 480 2113 480ZM1068 0 1068 97 1282 311 1425 169 1426 170 1426 480 1494 480 1494 4 1282 215Z"/></svg>';
  var ICON = {
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
    teamTab: 'performance', team: { preset: 'today', from: null, to: null, user: '' },
    products: LS.get('fx_products', []),
    queue: LS.get('fx_queue', []),
    online: navigator.onLine,
    view: 'sell',
    cart: [], discount: null, customer: null,
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
  function calcTotals(lines, discount) {
    var st = S.settings || { vat_rate: 5, prices_include_vat: true };
    var subtotal = 0;
    lines.forEach(function (l) { subtotal += l.price * l.qty; });
    var disc = 0;
    if (discount && Number(discount.value) > 0) {
      if (discount.type === 'percent') disc = Math.round(subtotal * Math.min(100, Math.max(0, Number(discount.value))) / 100);
      else disc = Math.min(subtotal, toFils(discount.value));
    }
    var after = subtotal - disc, rate = Number(st.vat_rate), vat, total;
    if (st.prices_include_vat) { vat = Math.round(after * rate / (100 + rate)); total = after; }
    else { vat = Math.round(after * rate / 100); total = after + vat; }
    return { subtotal: subtotal, discount: disc, vat: vat, total: total };
  }
  function cartLines() {
    return S.cart.map(function (c) {
      var p = productById(c.product_id) || { name: c.name, variant: c.variant, price: c.price };
      return { product_id: c.product_id, name: p.name, variant: p.variant, price: p.price, qty: c.qty };
    });
  }
  function productById(id) { for (var i = 0; i < S.products.length; i++) if (S.products[i].id === id) return S.products[i]; return null; }
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
    var first = $('[autofocus]', back); if (first) setTimeout(function () { first.focus(); }, 50);
    return back;
  }
  function closeModal() {
    if (!modalState) return;
    var m = modalState; modalState = null;
    m.el.remove();
    if (m.onClose) m.onClose();
  }
  function setModalBody(html) { if (modalState) $('.modal-body', modalState.el).innerHTML = html; }
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
      '<label class="field"><span>Username</span><input class="input" name="username" autocapitalize="none" autocomplete="username" required autofocus value="' + esc(S.user ? S.user.username : '') + '"></label>' +
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
  function renderApp() {
    if (!S.token || !S.user || !S.settings) { renderLogin(); return; }
    $('#root').innerHTML =
      '<div class="app"><header class="topbar">' +
      '<div class="h-brand">' + WORDMARK + '</div>' +
      '<nav class="nav" id="nav">' + navItems().map(function (n) {
        return '<button data-act="nav" data-view="' + n[0] + '" class="' + (S.view === n[0] ? 'active' : '') + '">' + ICON[n[0]] + '<span>' + n[1] + '</span><span class="count hidden" data-count="' + n[0] + '"></span></button>';
      }).join('') + '</nav>' +
      '<div class="spacer"></div>' +
      '<button class="store-btn hidden" id="store-btn" data-act="store-menu"></button>' +
      '<button class="net" id="net" data-act="net"></button>' +
      '<button class="btn icon-btn ghost theme-btn" id="theme-btn" data-act="theme-toggle"></button>' +
      '<button class="user-btn" data-act="user-menu"><span class="avatar">' + esc(initials(S.user.name)) + '</span><span class="u-name">' + esc(S.user.name) + '</span></button>' +
      '</header><main class="view" id="view"></main></div>';
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
    var c1 = $('[data-count="stock"]'); if (c1) { c1.textContent = low; c1.classList.toggle('hidden', !low); }
    var c2 = $('[data-count="sales"]'); if (c2) { c2.textContent = S.queue.length; c2.classList.toggle('hidden', !S.queue.length); }
  }
  function renderView() {
    $$('#nav button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-view') === S.view); });
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
      '<div class="chips" id="chips"></div></div>' +
      '<div class="tiles" id="tiles"></div></section>' +
      '<aside class="cart' + (S.cartOpen ? ' open' : '') + '" id="cart"></aside></div>' +
      '<button class="cart-fab" id="cart-fab" data-act="cart-open"></button>';
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
  function renderTiles() {
    var el = $('#tiles'); if (!el) return;
    var q = S.search.trim().toLowerCase();
    var list = S.products.filter(function (p) {
      if (!p.active) return false;
      if (S.category !== 'All' && p.category !== S.category) return false;
      if (q && (p.name + ' ' + p.variant + ' ' + p.sku + ' ' + p.category).toLowerCase().indexOf(q) < 0) return false;
      return true;
    }).sort(function (a, b) { return (a.name + a.variant).localeCompare(b.name + b.variant); });
    if (!list.length) {
      el.innerHTML = '<div class="empty" style="grid-column:1/-1"><div class="big">' +
        (S.products.some(function (p) { return p.active; }) ? 'No products match' : 'No products for sale yet') + '</div>' +
        (S.products.some(function (p) { return p.active; }) ? 'Try a different search.' :
          isManager() ? 'Go to <b>Stock</b> to add products, set prices and switch them on.' : 'Ask a manager to add products in Stock.') + '</div>';
      return;
    }
    el.innerHTML = list.map(function (p) {
      var inCart = 0; S.cart.forEach(function (c) { if (c.product_id === p.id) inCart = c.qty; });
      var s = stockState(p);
      return '<button class="tile' + (s === 'out' ? ' is-out' : '') + '" data-act="add" data-id="' + p.id + '">' +
        (inCart ? '<span class="in-cart">' + inCart + '</span>' : '') +
        '<div class="t-name">' + esc(p.name) + '</div>' +
        (p.variant ? '<div class="t-var">' + esc(p.variant) + '</div>' : '') +
        '<div class="t-foot"><span class="price">' + money(p.price) + '</span>' + stockBadge(p) + '</div></button>';
    }).join('');
  }
  function addToCart(id) {
    var p = productById(id); if (!p) return;
    var line = null; S.cart.forEach(function (c) { if (c.product_id === id) line = c; });
    var want = (line ? line.qty : 0) + 1;
    if (want > p.stock) {
      if (S.online) { toast(p.stock <= 0 ? pName(p) + ' is out of stock.' : 'Only ' + p.stock + ' of ' + pName(p) + ' in stock.', true); return; }
      toast('Stock shows ' + Math.max(0, p.stock) + ' — added anyway because you are offline.', true);
    }
    if (line) line.qty = want; else S.cart.push({ product_id: id, qty: 1 });
    renderTiles(); renderCart();
  }
  function changeQty(id, d) {
    S.cart.forEach(function (c) {
      if (c.product_id !== id) return;
      var p = productById(id);
      if (d > 0 && p && c.qty + 1 > p.stock && S.online) { toast('Only ' + p.stock + ' in stock.', true); return; }
      c.qty += d;
    });
    S.cart = S.cart.filter(function (c) { return c.qty > 0; });
    renderTiles(); renderCart();
  }
  function renderCart() {
    var el = $('#cart'); if (!el) return;
    var lines = cartLines(), t = calcTotals(lines, S.discount), count = 0;
    lines.forEach(function (l) { count += l.qty; });
    var empty = !lines.length, st = S.settings;
    var c = S.customer, d = S.discount;
    el.innerHTML =
      '<div class="cart-head"><h2>Current sale</h2><div class="row">' +
      (empty ? '' : '<button class="link-btn" data-act="cart-clear">Clear</button>') +
      '<button class="btn icon-btn ghost show-sm" data-act="cart-close" aria-label="Close">' + ICON.close + '</button></div></div>' +
      '<div class="cart-lines">' + (empty ? '<div class="empty"><div class="big">Cart is empty</div>Tap a product to add it.</div>' :
        lines.map(function (l) {
          return '<div class="line"><div><div class="l-name">' + esc(l.name) + '</div><div class="l-sub">' + esc(l.variant ? l.variant + ' · ' : '') + money(l.price) + ' each</div></div>' +
            '<div class="l-total num">' + money(l.price * l.qty) + '</div>' +
            '<div><div class="qty"><button data-act="qty" data-id="' + l.product_id + '" data-d="-1" aria-label="Less">−</button><span>' + l.qty + '</span><button data-act="qty" data-id="' + l.product_id + '" data-d="1" aria-label="More">+</button></div></div>' +
            '<div class="l-actions"><button class="link-btn" data-act="qty" data-id="' + l.product_id + '" data-d="' + (-l.qty) + '">Remove</button></div></div>';
        }).join('')) + '</div>' +
      '<div class="cart-extra">' +
      '<button class="extra-btn" data-act="customer"><span>Customer</span><span class="val">' + (c ? esc(c.name || c.phone) + (c.tier ? ' · ' + esc(c.tier) : '') : 'Add (optional)') + '</span></button>' +
      '<button class="extra-btn" data-act="discount"' + (empty ? ' disabled' : '') + '><span>Discount</span><span class="val">' + (d ? (d.type === 'percent' ? d.value + '%' : aed(toFils(d.value))) + ' off' : 'None') + '</span></button></div>' +
      '<div class="cart-totals">' +
      '<div class="t-row"><span>Subtotal (' + count + ' item' + (count === 1 ? '' : 's') + ')</span><span class="num">' + money(t.subtotal) + '</span></div>' +
      (t.discount ? '<div class="t-row"><span>Discount</span><span class="num">−' + money(t.discount) + '</span></div>' : '') +
      '<div class="t-row"><span>VAT ' + st.vat_rate + '%' + (st.prices_include_vat ? ' (included)' : '') + '</span><span class="num">' + money(t.vat) + '</span></div>' +
      '<div class="t-row t-grand"><span>Total</span><span class="num">' + aed(t.total) + '</span></div></div>' +
      '<div class="pay-grid">' +
      '<button class="pay-btn primary" data-act="pay" data-m="cash"' + (empty ? ' disabled' : '') + '><b>Cash</b><small>Works offline</small></button>' +
      '<button class="pay-btn" data-act="pay" data-m="tap"' + (empty ? ' disabled' : '') + '><b>Tap to Pay</b><small>Card / Apple / Google Pay in Ziina app</small></button>' +
      '<button class="pay-btn" data-act="pay" data-m="ziina"' + (empty || !st.ziina || !S.online ? ' disabled' : '') + '><b>Ziina QR</b><small>' + (!st.ziina ? 'Not set up' : S.online ? 'Customer scans & pays' : 'Needs internet') + '</small></button>' +
      '<button class="pay-btn" data-act="pay" data-m="card"' + (empty ? ' disabled' : '') + '><b>Card machine</b><small>Other bank terminal</small></button>' +
      '</div>';
    var fab = $('#cart-fab');
    if (fab) {
      fab.innerHTML = '<span>' + (count ? count + ' item' + (count === 1 ? '' : 's') : 'Cart') + '</span><span>' + (count ? 'Charge ' + aed(t.total) : 'View') + '</span>';
      fab.classList.toggle('hidden', S.cartOpen);
    }
  }
  function setCartOpen(v) { S.cartOpen = v; var c = $('#cart'); if (c) c.classList.toggle('open', v); var f = $('#cart-fab'); if (f) f.classList.toggle('hidden', v); }

  function customerModal() {
    var c = S.customer || {};
    openModal('Customer', '<div class="err m-err hidden"></div>' +
      '<label class="field"><span>Mobile number</span><div class="row"><input class="input grow" id="c-phone" type="tel" inputmode="tel" placeholder="05x xxx xxxx" value="' + esc(c.phone || '') + '" autofocus>' +
      '<button class="btn" data-act="c-find">Find</button></div></label>' +
      '<div id="c-info"></div>' +
      '<label class="field"><span>Name</span><input class="input" id="c-name" value="' + esc(c.name || '') + '" placeholder="Customer name"></label>' +
      '<p class="small muted">Saving the number tracks yearly spend for FOMAXO Elite tiers and lets you send the receipt on WhatsApp.</p>' +
      '<div class="modal-foot">' + (S.customer ? '<button class="btn danger" data-act="c-remove">Remove</button>' : '') +
      '<button class="btn gold" data-act="c-save">Save</button></div>');
    if (c.phone) findCustomer();
  }
  function tierInfo(cu) {
    var parts = ['<div class="note" style="margin-bottom:12px">'];
    parts.push(cu.tier ? '<span class="tier-tag">FOMAXO ' + esc(cu.tier) + '</span> ' : 'Not a member tier yet. ');
    parts.push('Spent ' + aed(cu.year_spend) + ' this year.');
    if (cu.next_tier) parts.push(' ' + aed(cu.next_tier.needed) + ' more to reach ' + esc(cu.next_tier.name) + '.');
    parts.push('</div>');
    return parts.join('');
  }
  function findCustomer() {
    var phone = $('#c-phone').value.trim(); if (!phone) return;
    if (!S.online) { $('#c-info').innerHTML = '<p class="small muted">Offline — the number will still be saved with the sale.</p>'; return; }
    api('customer', { query: '&phone=' + encodeURIComponent(phone) }).then(function (d) {
      if (!$('#c-info')) return;
      if (d.customer) {
        $('#c-info').innerHTML = tierInfo(d.customer);
        if (!$('#c-name').value) $('#c-name').value = d.customer.name;
        S._found = d.customer;
      } else { $('#c-info').innerHTML = '<p class="small muted">New customer — they will be added with this sale.</p>'; S._found = null; }
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
      discount: S.discount, customer: S.customer ? { phone: S.customer.phone, name: S.customer.name } : null,
      created_at: stamp()
    };
    for (var k in extra) p[k] = extra[k];
    return p;
  }
  function payModal(method) {
    var t = calcTotals(cartLines(), S.discount);
    var amount = '<div class="amount-big"><div class="lbl">Amount to collect</div><div class="val num">' + aed(t.total) + '</div></div>';
    if (method === 'cash') {
      var quick = [t.total / 100, 50, 100, 200, 500, 1000].filter(function (v, i, a) { return i === 0 || (v * 100 >= t.total && a.indexOf(v) === i); }).slice(0, 6);
      openModal('Cash payment', '<div class="err m-err hidden"></div>' + amount +
        '<label class="field"><span>Cash received (AED)</span><input class="input" id="cash-in" type="number" inputmode="decimal" step="0.01" min="0" placeholder="' + money(t.total) + '" autofocus></label>' +
        '<div class="quick">' + quick.map(function (v, i) { return '<button class="btn sm" data-act="cash-quick" data-v="' + v + '">' + (i === 0 ? 'Exact' : v) + '</button>'; }).join('') + '</div>' +
        '<div class="change-box" id="change-box"><span>Change to give</span><span class="num">AED 0.00</span></div>' +
        '<div class="modal-foot"><button class="btn gold big" data-act="confirm-pay" data-m="cash">Complete sale</button></div>', { sticky: true });
      $('#cash-in').addEventListener('input', updateChange);
    } else if (method === 'tap') {
      openModal('Tap to Pay', '<div class="err m-err hidden"></div>' + amount +
        '<ol class="steps"><li><span>Open the <b>Ziina app</b> on the store phone and choose <b>Tap to Pay</b>.</span></li>' +
        '<li><span>Enter exactly <b class="num">' + money(t.total) + '</b> <button class="btn sm" data-act="copy-amount" data-v="' + (t.total / 100).toFixed(2) + '">Copy amount</button> and let the customer tap their card, phone or watch.</span></li>' +
        '<li><span>When Ziina shows the payment as approved, press <b>Payment received</b> below.</span></li></ol>' +
        '<label class="field"><span>Reference (optional) — last 4 digits or Ziina transaction ID</span><input class="input" id="pay-ref" maxlength="64"></label>' +
        '<div class="modal-foot"><button class="btn ghost" data-act="modal-close">Back</button><button class="btn gold big" data-act="confirm-pay" data-m="tap">Payment received</button></div>', { sticky: true });
    } else if (method === 'card') {
      openModal('Card machine', '<div class="err m-err hidden"></div>' + amount +
        '<p class="muted">Charge this amount on the card terminal. When it prints <b>approved</b>, press Payment received.</p>' +
        '<label class="field"><span>Approval code (optional)</span><input class="input" id="pay-ref" maxlength="64"></label>' +
        '<div class="modal-foot"><button class="btn ghost" data-act="modal-close">Back</button><button class="btn gold big" data-act="confirm-pay" data-m="card">Payment received</button></div>', { sticky: true });
    } else if (method === 'ziina') {
      ziinaFlow();
    }
  }
  function updateChange() {
    var t = calcTotals(cartLines(), S.discount);
    var v = $('#cash-in').value, given = v === '' ? t.total : toFils(v), ch = given - t.total;
    var box = $('#change-box');
    box.classList.toggle('short', ch < 0);
    box.innerHTML = ch < 0 ? '<span>Still to pay</span><span class="num">' + aed(-ch) + '</span>' : '<span>Change to give</span><span class="num">' + aed(ch) + '</span>';
  }
  function confirmPay(method, btn) {
    var extra = {};
    var t = calcTotals(cartLines(), S.discount);
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
    var lines = cartLines(), t = calcTotals(lines, S.discount);
    var local = {
      queued: true, uuid: payload.uuid, receipt_no: 'FMX-' + payload.uuid.replace(/-/g, '').slice(0, 8).toUpperCase(),
      receipt_token: payload.receipt_token, created_at: payload.created_at, staff_name: S.user.name,
      store_name: curStore().name, store_address: curStore().address, store_phone: curStore().phone,
      customer_name: S.customer ? S.customer.name : '', customer_phone: S.customer ? S.customer.phone : '',
      customer_tier: S.customer ? S.customer.tier : null,
      items: lines.map(function (l) { return { name: l.name, variant: l.variant, price: l.price, qty: l.qty, line_total: l.price * l.qty }; }),
      subtotal: t.subtotal, discount: t.discount, vat: t.vat, total: t.total, method: payload.method, status: 'paid',
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
    S.cart = []; S.discount = null; S.customer = null;
    setCartOpen(false);
    if (S.view === 'sell') { renderTiles(); renderCart(); }
    if (!sale.queued) refreshProducts();
    showReceipt(sale, true);
  }

  var ziinaPoll = null;
  function stopPoll() { if (ziinaPoll) { clearInterval(ziinaPoll); ziinaPoll = null; } }
  function ziinaFlow() {
    var t = calcTotals(cartLines(), S.discount);
    if (t.total < 200) { toast('Ziina needs at least AED 2.00. Take cash for this sale.', true); return; }
    var payload = buildPayload('ziina', {});
    var cust = S.customer;
    openModal('Ziina payment', '<div class="status-line"><span class="spinner"></span> Creating payment…</div>', { sticky: true, onClose: stopPoll });
    api('ziina_start', { body: payload, timeout: 30000 }).then(function (d) {
      if (d.sale.status === 'paid') { closeModal(); afterSale(d.sale); return; }
      var qr = qrcode(0, 'M'); qr.addData(d.pay_url); qr.make();
      var wa = cust && cust.phone ? 'https://wa.me/' + cust.phone.replace(/\D/g, '') + '?text=' + encodeURIComponent('FOMAXO payment of ' + aed(d.sale.total) + ': ' + d.pay_url) : null;
      setModalBody(
        (S.settings.ziina_mode !== 'live' ? '<div class="note" style="margin-bottom:12px;text-align:center">' + (S.settings.ziina_mode === 'demo' ? 'PRACTICE MODE — no real money' : 'ZIINA TEST MODE — no real money') + '</div>' : '') +
        '<div class="amount-big"><div class="lbl">Customer pays</div><div class="val num">' + aed(d.sale.total) + '</div></div>' +
        '<div class="qr-box">' + qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true }) + '</div>' +
        '<p class="small muted" style="text-align:center;margin:0 0 6px">Customer scans with their phone camera and pays by card, Apple Pay or Google Pay.</p>' +
        '<div class="status-line" id="z-status"><span class="spinner"></span> Waiting for payment…</div>' +
        '<div class="err m-err hidden"></div>' +
        '<div class="modal-foot">' + (wa ? '<a class="btn" target="_blank" rel="noopener" href="' + esc(wa) + '">Send link on WhatsApp</a>' : '') +
        '<a class="btn" target="_blank" rel="noopener" href="' + esc(d.pay_url) + '">Open on this device</a></div>' +
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
              '<div class="modal-foot"><button class="btn" data-act="modal-close">Close</button><button class="btn gold" data-act="pay" data-m="ziina">Try again</button></div>');
          }
        }, function () { busy = false; var s = $('#z-status'); if (s) s.innerHTML = '<span class="spinner"></span> Checking… (connection slow)'; });
      }, 3000);
    }).catch(function (e) {
      setModalBody('<div class="err" style="margin-bottom:12px">' + esc(e.offline ? 'No internet — Ziina QR needs a connection. Use Cash or Tap to Pay.' : e.message) + '</div>' +
        '<div class="modal-foot"><button class="btn" data-act="modal-close">Close</button></div>');
    });
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
      (s.discount ? '<div class="p-tot"><span>Discount</span><span class="num">-' + money(s.discount) + '</span></div>' : '') +
      '<div class="p-tot"><span>VAT ' + st.vat_rate + '%' + (st.prices_include_vat ? ' (incl.)' : '') + '</span><span class="num">' + money(s.vat) + '</span></div>' +
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
  function showReceipt(s, fresh) {
    currentReceipt = s;
    var statusNote = '';
    if (s.queued) statusNote = '<div class="note" style="margin-bottom:12px">Saved on this device (offline). It uploads automatically when internet is back. The WhatsApp link works after upload.</div>';
    else if (s.status === 'void') statusNote = '<div class="err" style="margin-bottom:12px">This sale was voided. ' + esc(s.note || '') + '</div>';
    else if (s.status !== 'paid') statusNote = '<div class="err" style="margin-bottom:12px">Status: ' + esc(s.status) + ' — not paid.</div>';
    var paid = s.status === 'paid';
    openModal(fresh ? 'Sale complete' : 'Receipt ' + s.receipt_no, statusNote + paperHtml(s) +
      '<div class="err m-err hidden" style="margin-top:12px"></div>' +
      '<div class="receipt-actions">' +
      (paid ? '<button class="btn" data-act="r-print">Print</button><button class="btn" data-act="r-wa">WhatsApp</button>' +
        '<button class="btn" data-act="r-copy">Copy link</button><button class="btn" data-act="r-email">Email</button>' : '') +
      (!fresh && paid && isManager() && !s.queued ? '<button class="btn danger wide" data-act="r-void">Void sale</button>' : '') +
      (fresh ? '<button class="btn gold big wide" data-act="modal-close">New sale</button>' : '') +
      '</div>');
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
      '<tr><th>Product</th><th class="hide-sm">Category</th><th class="r hide-sm">Price</th><th>Stock</th><th class="r"></th></tr>';
    var rows = list.map(function (p) {
      var name = '<td><div class="p-name">' + esc(p.name) + '</div><div class="small muted">' + esc([p.variant, p.sku].filter(Boolean).join(' · ')) + '</div></td>';
      var acts = '<td class="act-cell"><div class="actions">' + (mgr && !allMode ? '<button class="btn sm gold" data-act="p-adjust" data-id="' + p.id + '">Adjust</button>' : '') +
        (canMove ? '<button class="btn sm" data-act="p-move" data-id="' + p.id + '">Move</button>' : '') +
        (mgr ? '<button class="btn sm" data-act="p-edit" data-id="' + p.id + '">Edit</button>' : '') +
        '<button class="btn sm ghost" data-act="p-history" data-id="' + p.id + '">History</button></div></td>';
      if (allMode) {
        return '<tr>' + name + stores.map(function (x) { return '<td class="r">' + cell(p, (p.stocks || {})[x.id] || 0) + '</td>'; }).join('') +
          '<td class="r"><div class="stock-num num">' + p.stock + '</div>' + stockBadge(p) + '</td>' + acts + '</tr>';
      }
      return '<tr>' + name + '<td class="hide-sm muted">' + esc(p.category) + '</td><td class="r hide-sm num">' + money(p.price) + '</td>' +
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
      '<div class="grid2"><label class="field"><span>Price AED' + (S.settings.prices_include_vat ? ' (incl. VAT)' : '') + '</span><input class="input" name="price" type="number" inputmode="decimal" step="0.01" min="0" required value="' + (p.price ? (p.price / 100).toFixed(2) : '') + '"></label>' +
      '<label class="field"><span>Warn "running low" at</span><input class="input" name="low_at" type="number" min="0" value="' + p.low_at + '"></label></div>' +
      '<div class="grid2"><label class="field"><span>SKU / barcode (optional)</span><input class="input" name="sku" value="' + esc(p.sku) + '"></label>' +
      (isNew ? '<label class="field"><span>Opening stock' + (multiStore() ? ' at ' + esc(curStore().name) : '') + '</span><input class="input" name="stock" type="number" value="0"></label>' : '<div></div>') + '</div>' +
      '<label class="check"><input type="checkbox" name="active" ' + (p.active ? 'checked' : '') + '> On sale (shows on the Sell screen)</label>' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Cancel</button><button class="btn gold" type="submit">Save</button></div></form>');
    $('#p-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target, b = $('[type=submit]', f);
      b.disabled = true;
      api('product_save', { body: { id: isNew ? 0 : p.id, name: f.name.value, variant: f.variant.value, category: f.category.value, price: f.price.value, low_at: f.low_at.value, sku: f.sku.value, stock: f.stock ? f.stock.value : 0, store_id: curStoreId(), active: f.active.checked ? 1 : 0 } })
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
        (d.sales.length ? '<div class="table-wrap"><table class="tbl"><thead><tr><th>Time</th><th>Receipt</th>' + (isManager() ? '<th class="hide-sm">Staff</th>' : '') + '<th class="hide-sm">Paid by</th><th class="r">Total</th><th class="r">Status</th></tr></thead><tbody>' +
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
      '<div class="row"><input class="input" type="date" id="rf" value="' + r.from + '" style="width:auto"><span class="muted">to</span><input class="input" type="date" id="rt" value="' + r.to + '" style="width:auto"></div>' + storeSelect('rstore', S.reportStore, true) + '</div>' +
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
        '<div class="kpi"><div class="k-label">VAT collected</div><div class="k-val num">' + aed(s.vat) + '</div></div>' +
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
    product: ['Product edit', 'plain'], staff: ['Staff change', 'plain'], store: ['Store change', 'plain'], password: ['Password', 'plain']
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
      }).join('') + '</div><input class="input" type="date" id="tf" value="' + t.from + '" style="width:auto"><span class="muted">to</span><input class="input" type="date" id="tt" value="' + t.to + '" style="width:auto">' +
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
        team.forEach(function (m) { best = Math.max(best, m.total); sum += m.total; cnt += m.sales; if (m.online) online++; });
        el().innerHTML = '<div class="kpis">' +
          '<div class="kpi gold"><div class="k-label">Team sales</div><div class="k-val num">' + aed(sum) + '</div></div>' +
          '<div class="kpi"><div class="k-label">Number of sales</div><div class="k-val">' + cnt + '</div></div>' +
          '<div class="kpi"><div class="k-label">Using the app now</div><div class="k-val">' + online + ' <span class="small muted">of ' + team.filter(function (m) { return m.active; }).length + '</span></div></div></div>' +
          '<div class="people">' + team.map(function (m) {
            var avg = m.sales ? Math.round(m.total / m.sales) : 0, pm = m.methods || {};
            return '<button class="person' + (m.active ? '' : ' off') + '" data-act="t-person" data-id="' + m.id + '">' +
              '<div class="row"><span class="avatar lg">' + esc(initials(m.name)) + '</span><div class="grow"><b>' + esc(m.name) + '</b>' +
              '<div class="small muted">' + (m.role === 'manager' ? 'Manager' : 'Staff') + (m.store_name ? ' · ' + esc(m.store_name) : multiStore() ? ' · any store' : '') + (m.active ? '' : ' · switched off') + '</div></div>' +
              '<span class="dot-status ' + (m.online ? 'on' : '') + '">' + (m.online ? 'Active now' : esc(ago(m.last_seen))) + '</span></div>' +
              '<div class="p-total num">' + aed(m.total) + '</div><div class="bar" style="margin:6px 0 10px"><i style="width:' + (best ? Math.max(2, m.total / best * 100) : 0) + '%"></i></div>' +
              '<div class="p-stats"><span><b>' + m.sales + '</b> sales</span><span><b class="num">' + money(avg) + '</b> avg</span><span><b>' + m.items + '</b> items</span>' +
              '<span' + (m.discount ? ' class="warn"' : '') + '><b class="num">' + money(m.discount) + '</b> discounts</span>' +
              '<span' + (m.voids ? ' class="bad"' : '') + '><b>' + m.voids + '</b> voided</span></div>' +
              (m.total ? '<div class="small muted" style="margin-top:8px">' + Object.keys(pm).map(function (k) { return (METHOD[k] || k) + ' ' + money(pm[k]); }).join(' · ') + '</div>' : '') +
              (m.last_sale ? '<div class="small muted">Last sale ' + esc(ago(m.last_sale)) + '</div>' : '') + '</button>';
          }).join('') + '</div><p class="small muted">Tap a person to see everything they did.</p>';
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
            return '<tr><td><b>' + esc(u.name) + '</b>' + (u.active ? '' : ' <span class="badge off">Switched off</span>') + '</td><td class="muted">' + esc(u.username) + '</td><td>' + (u.role === 'manager' ? '<span class="tier-tag">Manager</span>' : 'Staff') + '</td>' +
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
            return '<button class="person' + (x.active ? '' : ' off') + '" data-act="st-edit" data-id="' + x.id + '"><b>' + esc(x.name) + '</b>' + (x.active ? '' : ' <span class="badge off">Closed</span>') +
              '<div class="small muted" style="margin-top:6px">' + (esc(x.address) || 'No address yet') + '<br>' + (esc(x.phone) || 'No phone yet') + '</div></button>';
          }).join('') + '</div>';
      }).catch(fail);
    }
  }
  function storeModal(x) {
    var isNew = !x; x = x || { name: '', address: '', phone: '', active: 1 };
    openModal(isNew ? 'Add store' : 'Edit store', '<div class="err m-err hidden"></div><form id="st-form">' +
      '<label class="field"><span>Store name</span><input class="input" name="name" required value="' + esc(x.name) + '" placeholder="e.g. City Centre Sharjah" autofocus></label>' +
      '<label class="field"><span>Address (printed on receipts)</span><input class="input" name="address" value="' + esc(x.address) + '"></label>' +
      '<label class="field"><span>Phone (printed on receipts)</span><input class="input" name="phone" value="' + esc(x.phone) + '"></label>' +
      '<label class="check"><input type="checkbox" name="active" ' + (x.active ? 'checked' : '') + '> Open (can sell and hold stock)</label>' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Cancel</button><button class="btn gold" type="submit">Save</button></div></form>');
    $('#st-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target, b = $('[type=submit]', f); b.disabled = true;
      api('store_save', { body: { id: isNew ? 0 : x.id, name: f.name.value, address: f.address.value, phone: f.phone.value, active: f.active.checked ? 1 : 0 } })
        .then(function (d) { S.stores = d.stores; LS.set('fx_stores', S.stores); closeModal(); toast('Store saved.'); updateStoreBtn(); refreshProducts(); loadTeam(); })
        .catch(function (er) { b.disabled = false; modalErr(er.message); });
    });
  }
  function userModal(u) {
    var isNew = !u; u = u || { name: '', username: '', role: 'staff', active: 1 };
    openModal(isNew ? 'Add staff' : 'Edit ' + u.name, '<div class="err m-err hidden"></div><form id="u-form">' +
      '<label class="field"><span>Full name</span><input class="input" name="name" required value="' + esc(u.name) + '" autofocus></label>' +
      '<label class="field"><span>Username (for login)</span><input class="input" name="username" required autocapitalize="none" value="' + esc(u.username) + '"></label>' +
      '<label class="field"><span>' + (isNew ? 'Password (8+ characters)' : 'New password (leave empty to keep)') + '</span><input class="input" name="password" type="text" autocomplete="new-password" ' + (isNew ? 'required minlength="8"' : '') + '></label>' +
      '<label class="field"><span>Role</span><select class="input" name="role"><option value="staff"' + (u.role === 'staff' ? ' selected' : '') + '>Staff</option><option value="manager"' + (u.role === 'manager' ? ' selected' : '') + '>Manager</option></select></label>' +
      (multiStore() ? '<label class="field"><span>Works at</span><select class="input" name="store_id"><option value="">Any store (chooses on the device)</option>' +
        S.stores.map(function (x) { return '<option value="' + x.id + '"' + (u.store_id === x.id ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select></label>' : '') +
      '<label class="check"><input type="checkbox" name="active" ' + (u.active ? 'checked' : '') + '> Can log in</label>' +
      '<div class="modal-foot"><button class="btn" type="button" data-act="modal-close">Cancel</button><button class="btn gold" type="submit">Save</button></div></form>');
    $('#u-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target, b = $('[type=submit]', f); b.disabled = true;
      api('user_save', { body: { id: isNew ? 0 : u.id, name: f.name.value, username: f.username.value, password: f.password.value, role: f.role.value, store_id: f.store_id ? f.store_id.value : '', active: f.active.checked ? 1 : 0 } })
        .then(function () { closeModal(); toast('Saved.'); S._users = null; loadTeam(); })
        .catch(function (er) { b.disabled = false; modalErr(er.message); });
    });
  }

  // ---------------------------------------------------------------- user menu
  function userMenu(btn) {
    closeMenu();
    var r = btn.getBoundingClientRect();
    var m = document.createElement('div');
    m.className = 'menu';
    m.style.top = (r.bottom + 6) + 'px'; m.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
    m.innerHTML = '<div class="m-head"><b>' + esc(S.user.name) + '</b><div class="small muted">' + (isManager() ? 'Manager' : 'Staff') + ' · ' + esc(S.user.username) + '</div></div>' +
      '<div class="m-label">Appearance</div><div class="seg">' + [['auto', 'Auto'], ['light', 'Light'], ['dark', 'Dark']].map(function (o) {
        return '<button data-act="theme-set" data-t="' + o[0] + '" class="' + (themeChoice() === o[0] ? 'active' : '') + '">' + o[1] + '</button>';
      }).join('') + '</div>' +
      '<button data-act="pw">Change password</button><button data-act="reload">Refresh app</button><button data-act="logout">Log out</button>';
    document.body.appendChild(m);
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
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-act]');
    if (!e.target.closest('.menu') && !(el && el.getAttribute('data-act') === 'user-menu')) closeMenu();
    if (!el || el.disabled) return;
    var act = el.getAttribute('data-act'), id = parseInt(el.getAttribute('data-id'), 10);
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
      case 'reload': location.reload(); break;
      case 'logout': logout(false); break;
      case 'logout-force': logout(true); break;
      case 'modal-close': closeModal(); break;
      // sell
      case 'cat': S.category = el.getAttribute('data-cat'); renderChips(); renderTiles(); break;
      case 'add': addToCart(id); break;
      case 'qty': changeQty(id, parseInt(el.getAttribute('data-d'), 10)); break;
      case 'cart-clear': S.cart = []; S.discount = null; S.customer = null; renderTiles(); renderCart(); break;
      case 'cart-open': setCartOpen(true); break;
      case 'cart-close': setCartOpen(false); break;
      case 'customer': customerModal(); break;
      case 'discount': discountModal(); break;
      case 'c-find': findCustomer(); break;
      case 'c-save': {
        var ph = $('#c-phone').value.trim();
        if (!ph) { S.customer = null; closeModal(); renderCart(); break; }
        if (ph.replace(/\D/g, '').length < 7) { modalErr('Enter a full mobile number.'); break; }
        var f = S._found;
        S.customer = { phone: ph, name: $('#c-name').value.trim(), tier: f ? f.tier : null };
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
      case 'copy-amount': {
        var amt = el.getAttribute('data-v');
        (navigator.clipboard ? navigator.clipboard.writeText(amt) : Promise.reject()).then(function () { toast('Amount ' + amt + ' copied — paste it in Ziina.'); }, function () { toast('Amount: ' + amt); });
        break;
      }
      case 'cash-quick': $('#cash-in').value = el.getAttribute('data-v'); updateChange(); break;
      case 'confirm-pay': confirmPay(el.getAttribute('data-m'), el); break;
      case 'z-cancel':
        el.disabled = true; stopPoll();
        api('ziina_cancel', { body: { id: id } }).then(function (r) {
          if (r.status === 'paid') { closeModal(); afterSale(r.sale); toast('The customer had already paid — sale completed.'); }
          else { closeModal(); toast('Ziina payment cancelled.'); }
        }).catch(function (er) { el.disabled = false; modalErr(er.message); });
        break;
      // receipt
      case 'r-print': $('#print-area').innerHTML = paperHtml(currentReceipt); window.print(); break;
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
      case 'r-email': location.href = 'mailto:?subject=' + encodeURIComponent(S.settings.store_name + ' receipt ' + currentReceipt.receipt_no) + '&body=' + encodeURIComponent(receiptText(currentReceipt)); break;
      case 'r-void': voidModal(currentReceipt); break;
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
