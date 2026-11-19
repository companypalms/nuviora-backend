/*
 * Nuviora · formulario de pedido de la tienda (.164). El dueño lo pega en la landing del producto:
 *   <div id="nuviora-pedido"></div>
 *   <script src="https://api.nuviora.cloud/tienda/cargador.js?p={{ product.id }}" data-tasa="…" data-precio="…" defer></script>
 * Dibuja el formulario ya abierto dentro de #nuviora-pedido y, solo cuando ya está dibujado, esconde el de Releasit. Si
 * algo falla (no carga o no logra enviar el pedido), quita el nuestro y deja el de Releasit: nunca un botón de WhatsApp para
 * pedir. A Meta le manda solo lo que mandaba Releasit: InitiateCheckout, Purchase (con su número, el mismo de la API de
 * conversiones) y Lead al pasar a WhatsApp.
 */
(function () {
  'use strict';
  if (window.__nuvioraForm) return;
  window.__nuvioraForm = true;

  var BASE = window.__NUVIORA_BASE__ || '';
  var script = document.currentScript || (function () {
    var all = document.querySelectorAll('script[src*="/tienda/cargador.js"]');
    return all[all.length - 1];
  })();
  if (!script) return;
  var src = new URL(script.src, location.href);
  var P = src.searchParams.get('p') || script.getAttribute('data-producto') || '';
  var RATE = script.getAttribute('data-tasa') || '';
  var PAGE_PRICE = script.getAttribute('data-precio') || '';
  var PREVIEW = script.getAttribute('data-prueba') === '1' || /[?&]nvf_prueba=1/.test(location.search);
  var RELEASIT = '[id^="_rsi"],[class*="_rsi-"],[class*="_rsi_"],[id*="releasit" i],[class*="releasit" i],[id*="rsi-cod"],[class*="rsi-cod"]';
  var cfg = null;
  var hideStyle = null;
  var root = null;
  var started = false;
  var queue = [];
  var seenFields = {};

  /* ---------- utilidades ---------- */
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'html') n.innerHTML = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) n.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    }
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function bs(n) {
    if (n === null || n === undefined || !isFinite(n)) return 'Bs --';
    return 'Bs ' + Math.round(Number(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }
  function fill(text, vars) {
    return String(text || '').replace(/\{(\w+)\}/g, function (m, k) { return vars[k] !== undefined ? vars[k] : m; });
  }
  function cookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }
  function store(key, value) {
    try {
      if (value === undefined) return JSON.parse(localStorage.getItem(key) || 'null');
      localStorage.setItem(key, JSON.stringify(value));
    } catch (e) { return null; }
    return value;
  }
  function phoneOk(v) {
    var d = String(v || '').replace(/\D+/g, '').replace(/^(00)?58/, '').replace(/^0+/, '');
    return d.length === 10 && ['412', '414', '416', '422', '424', '426'].indexOf(d.slice(0, 3)) >= 0;
  }

  /* ---------- de dónde viene la visita (como la guardaría Shopify) ---------- */
  var visitor = store('nvf_vid') || store('nvf_vid', 'v' + Math.random().toString(36).slice(2) + Date.now().toString(36));
  var first = store('nvf_ft');
  var hasAd = /[?&](utm_|fbclid=)/.test(location.search);
  if (!first || hasAd || Date.now() - (first.ts || 0) > 7 * 864e5) {
    var ref = document.referrer && document.referrer.indexOf(location.host) < 0 ? document.referrer : (first && first.referrer) || '';
    first = store('nvf_ft', { landing: location.href, referrer: ref, ts: Date.now() });
  }
  function fbc() {
    var c = cookie('_fbc');
    if (c) return c;
    var m = (first && first.landing || '').match(/[?&]fbclid=([^&#]+)/);
    return m ? 'fb.1.' + (first.ts || Date.now()) + '.' + m[1] : null;
  }

  /* ---------- Meta (solo lo que mandaba Releasit) ---------- */
  function pixel(name, data, eventId) {
    if (PREVIEW || !cfg) return;
    try {
      if (!window.fbq && cfg.pixel.id) {
        /* eslint-disable */
        !function (f, b, e, v, n, t, s) { if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments) };
          if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = []; t = b.createElement(e); t.async = !0; t.src = v;
          s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s) }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
        /* eslint-enable */
        window.fbq('init', cfg.pixel.id);
        if (cfg.pixel.pageview) window.fbq('track', 'PageView');
      }
      if (window.fbq) window.fbq('track', name, data, eventId ? { eventID: eventId } : undefined);
    } catch (e) { /* el píxel nunca frena el pedido */ }
  }
  function content(units, usd) {
    return { value: usd, currency: 'USD', content_ids: [cfg.pixel.content_id], content_type: 'product', num_items: units };
  }

  /* ---------- el embudo (sin datos personales) ---------- */
  function track(kind) {
    if (PREVIEW) return;
    queue.push(kind);
    if (queue.length >= 6) flush();
  }
  function flush(order) {
    if (!queue.length || !P) return;
    var body = JSON.stringify({ p: P, kinds: queue.splice(0, queue.length), order: order || null });
    try {
      if (navigator.sendBeacon) navigator.sendBeacon(BASE + '/tienda/eventos', new Blob([body], { type: 'text/plain' }));
      else fetch(BASE + '/tienda/eventos', { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'text/plain' } });
    } catch (e) { /* nada */ }
  }
  setInterval(function () { flush(); }, 4000);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });

  /* ---------- Releasit: escondido solo cuando el nuestro ya está ---------- */
  function hideReleasit() {
    if (PREVIEW) return;
    var sel = RELEASIT + (cfg && cfg.releasit ? ',' + cfg.releasit : '');
    hideStyle = el('style', { id: 'nvf-hide-releasit', text: sel + '{display:none!important}' });
    document.head.appendChild(hideStyle);
  }
  function fallback(reason) {
    try {
      if (hideStyle && hideStyle.parentNode) hideStyle.parentNode.removeChild(hideStyle);
      if (root && root.parentNode) root.parentNode.removeChild(root);
      track('fallback');
      flush();
      var r = document.querySelector(RELEASIT);
      if (r && reason === 'send') r.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) { /* nada */ }
  }

  /* ---------- el diseño ---------- */
  function css(c) {
    var v = c.colors;
    var font = c.font ? "'" + c.font + "', " : '';
    return '#nuviora-pedido .nvf{--nvf-title:' + v.title + ';--nvf-btn:' + v.button + ';--nvf-btn-text:' + v.button_text + ';--nvf-bg:' + v.background +
      ';--nvf-text:' + v.text + ';--nvf-label:' + v.label + ';--nvf-border:' + v.border + ';--nvf-ico:' + v.field_icon_bg + ';--nvf-on:' + v.offer_selected +
      ';--nvf-on-bg:' + v.offer_selected_bg + ';--nvf-badge:' + v.badge + ';--nvf-badge-text:' + v.badge_text + ';--nvf-sum:' + v.summary_bg +
      ';--nvf-exp:' + v.express_bg + ';--nvf-exp-border:' + v.express_border + ';--nvf-err:' + v.error + ';--nvf-radius:' + (c.radius || 10) + 'px;' +
      'font-family:' + font + 'inherit;color:var(--nvf-text);background:var(--nvf-bg);box-sizing:border-box;width:100%;max-width:640px;margin:0 auto;padding:16px 0;line-height:1.35}' +
      '#nuviora-pedido .nvf *{box-sizing:border-box;font-family:inherit}' +
      '#nuviora-pedido .nvf-title{color:var(--nvf-title);font-weight:700;font-size:20px;text-align:center;margin:0 0 14px}' +
      '#nuviora-pedido .nvf-offers{display:flex;flex-direction:column;gap:12px;margin-bottom:16px}' +
      '#nuviora-pedido .nvf-offer{display:flex;align-items:center;gap:12px;border:1.5px solid var(--nvf-border);border-radius:var(--nvf-radius);padding:10px 12px;cursor:pointer;background:var(--nvf-bg)}' +
      '#nuviora-pedido .nvf-offer.is-on{border-color:var(--nvf-on);background:var(--nvf-on-bg);box-shadow:0 0 0 1px var(--nvf-on)}' +
      '#nuviora-pedido .nvf-offer input{position:absolute;opacity:0;pointer-events:none}' +
      '#nuviora-pedido .nvf-offer:focus-within{outline:2px solid var(--nvf-on);outline-offset:2px}' +
      '#nuviora-pedido .nvf-offer img{width:56px;height:56px;object-fit:cover;border-radius:6px;flex:none}' +
      '#nuviora-pedido .nvf-offer-main{flex:1;min-width:0}' +
      '#nuviora-pedido .nvf-offer-title{font-weight:800;font-size:15px;text-transform:uppercase}' +
      '#nuviora-pedido .nvf-badge{display:inline-block;margin-top:6px;background:var(--nvf-badge);color:var(--nvf-badge-text);font-size:12px;font-weight:700;padding:3px 8px;border-radius:4px}' +
      '#nuviora-pedido .nvf-price{text-align:right;font-weight:800;font-size:16px;white-space:nowrap}' +
      '#nuviora-pedido .nvf-saving{display:block;font-size:11.5px;font-weight:600;color:var(--nvf-on)}' +
      '#nuviora-pedido .nvf-label{color:var(--nvf-label);font-weight:600;font-size:15px;margin:4px 0 8px}' +
      '#nuviora-pedido .nvf-ship{display:flex;align-items:center;gap:12px;border:1px solid var(--nvf-border);border-radius:var(--nvf-radius);padding:14px 16px;margin-bottom:18px}' +
      '#nuviora-pedido .nvf-dot{width:18px;height:18px;border-radius:50%;border:5px solid var(--nvf-text);flex:none}' +
      '#nuviora-pedido .nvf-ship span:nth-child(2){flex:1}' +
      '#nuviora-pedido .nvf-row{display:grid;grid-template-columns:34% 1fr;gap:10px 14px;align-items:center;margin-bottom:14px}' +
      '#nuviora-pedido .nvf-row>label{color:var(--nvf-label);font-weight:600;font-size:15px}' +
      '#nuviora-pedido .nvf-row.no-label{grid-template-columns:1fr}' +
      '#nuviora-pedido .nvf-req{color:var(--nvf-err)}' +
      '#nuviora-pedido .nvf-box{display:flex;border:1px solid var(--nvf-border);border-radius:var(--nvf-radius);overflow:hidden;background:#fff}' +
      '#nuviora-pedido .nvf-ico{width:52px;flex:none;display:flex;align-items:center;justify-content:center;background:var(--nvf-ico)}' +
      '#nuviora-pedido .nvf-ico svg{width:20px;height:20px;fill:#222}' +
      '#nuviora-pedido .nvf-box input,#nuviora-pedido .nvf-box select{flex:1;min-width:0;border:0;outline:0;padding:14px 16px;font-size:16px;color:var(--nvf-text);background:transparent;-webkit-appearance:none;appearance:none}' +
      '#nuviora-pedido .nvf-box:focus-within{border-color:var(--nvf-btn);box-shadow:0 0 0 2px var(--nvf-btn)}' +
      '#nuviora-pedido .nvf-box.is-bad{border-color:var(--nvf-err)}' +
      '#nuviora-pedido .nvf-err{grid-column:2;color:var(--nvf-err);font-size:13px;margin-top:-6px;display:none}' +
      '#nuviora-pedido .nvf-row.no-label .nvf-err{grid-column:1}' +
      '#nuviora-pedido .nvf-err.is-on{display:block}' +
      '#nuviora-pedido .nvf-sum{background:var(--nvf-sum);border-radius:var(--nvf-radius);padding:14px 16px;margin:6px 0 16px}' +
      '#nuviora-pedido .nvf-sum div{display:flex;justify-content:space-between;padding:3px 0;font-size:15px}' +
      '#nuviora-pedido .nvf-sum div b{font-weight:800}' +
      '#nuviora-pedido .nvf-sum .nvf-total{border-top:1px solid var(--nvf-border);margin-top:8px;padding-top:12px;font-size:17px;font-weight:800}' +
      '#nuviora-pedido .nvf-express{display:flex;gap:12px;align-items:center;background:var(--nvf-exp);border:2px dashed var(--nvf-exp-border);border-radius:var(--nvf-radius);padding:14px 16px;margin-bottom:18px;cursor:pointer}' +
      '#nuviora-pedido .nvf-express input{width:22px;height:22px;flex:none;accent-color:var(--nvf-exp-border)}' +
      '#nuviora-pedido .nvf-express small{display:block;font-size:13px;margin-top:4px;text-transform:uppercase}' +
      '#nuviora-pedido .nvf-btn{width:100%;border:0;border-radius:calc(var(--nvf-radius) + 6px);background:var(--nvf-btn);color:var(--nvf-btn-text);padding:16px 18px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:14px;box-shadow:0 6px 16px rgba(0,0,0,.18)}' +
      '#nuviora-pedido .nvf-btn:disabled{opacity:.7;cursor:wait}' +
      '#nuviora-pedido .nvf-btn:focus-visible{outline:3px solid var(--nvf-text);outline-offset:2px}' +
      '#nuviora-pedido .nvf-btn b{display:block;font-size:18px;font-weight:800}' +
      '#nuviora-pedido .nvf-btn small{display:block;font-size:14px;margin-top:3px}' +
      '#nuviora-pedido .nvf-msg{color:var(--nvf-err);font-size:14px;text-align:center;margin-top:10px}' +
      '#nuviora-pedido .nvf-foot{text-align:center;font-size:12.5px;font-weight:600;margin-top:14px;color:var(--nvf-label)}' +
      '#nuviora-pedido .nvf-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}' +
      '#nuviora-pedido .nvf-done{text-align:center;padding:28px 16px;border:1px solid var(--nvf-border);border-radius:var(--nvf-radius)}' +
      '#nuviora-pedido .nvf-done h3{color:var(--nvf-title);font-size:21px;margin:0 0 10px}' +
      '#nuviora-pedido .nvf-done a{display:inline-block;margin-top:16px;background:#25D366;color:#fff;font-weight:800;padding:14px 22px;border-radius:999px;text-decoration:none}' +
      '@media (max-width:340px){#nuviora-pedido .nvf-row{grid-template-columns:1fr}#nuviora-pedido .nvf-err{grid-column:1}}' +
      (c.css || '');
  }
  var ICONS = {
    name: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-4 0-8 2-8 5v2h16v-2c0-3-4-5-8-5z"/></svg>',
    phone: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2c-1.6 0-3.1-.4-4.4-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.2s.9 2.5 1 2.7c.1.2 1.8 2.8 4.4 3.9 1.6.7 2.3.8 3.1.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.2-.3-.2-.5-.3z"/></svg>',
    pin: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>'
  };

  /* ---------- el formulario ---------- */
  function render(mount) {
    var t = cfg.texts;
    var state = { offer: 0, express: false };
    cfg.offers.forEach(function (o, i) { if (o.is_default) state.offer = i; });
    if (cfg.font) document.head.appendChild(el('link', { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(cfg.font) + ':wght@400;600;700;800&display=swap' }));
    document.head.appendChild(el('style', { id: 'nvf-style', text: css(cfg) }));

    var form = el('form', { class: 'nvf', novalidate: true, 'aria-label': t.title });
    form.appendChild(el('div', { class: 'nvf-title', text: t.title }));

    // Las ofertas
    var offers = el('div', { class: 'nvf-offers', role: 'radiogroup' });
    var offerNodes = cfg.offers.map(function (o, i) {
      var input = el('input', { type: 'radio', name: 'nvf-offer', value: String(i), 'aria-label': o.title });
      var node = el('label', { class: 'nvf-offer' }, [
        input,
        cfg.product.image ? el('img', { src: cfg.product.image, alt: '', loading: 'lazy' }) : null,
        el('div', { class: 'nvf-offer-main' }, [el('div', { class: 'nvf-offer-title', text: o.title }), o.badge ? el('span', { class: 'nvf-badge', text: o.badge }) : null]),
        el('div', { class: 'nvf-price' }, [bs(o.price_bs), o.saving_bs ? el('span', { class: 'nvf-saving', text: fill(t.saving, { ahorro: bs(o.saving_bs) }) }) : null])
      ]);
      input.addEventListener('change', function () { state.offer = i; touched('offer'); update(); });
      offers.appendChild(node);
      return { node: node, input: input };
    });
    if (cfg.offers.length > 1) form.appendChild(offers);

    // La talla
    var size = null;
    if (cfg.variants.length) {
      size = el('select', { name: 'size', 'aria-label': t.size_label }, [el('option', { value: '', text: t.size_label })].concat(cfg.variants.map(function (v) {
        return el('option', { value: String(v.id), text: v.title });
      })));
      form.appendChild(row('size', t.size_label, null, size, true));
    }

    // El envío
    form.appendChild(el('div', { class: 'nvf-label', text: t.shipping_label }));
    form.appendChild(el('div', { class: 'nvf-ship' }, [el('span', { class: 'nvf-dot' }), el('span', { text: t.shipping_option }), el('b', { text: t.shipping_price })]));

    // Los datos
    var f = {
      name: el('input', { name: 'name', autocomplete: 'name', placeholder: t.name_placeholder, maxlength: '80' }),
      phone: el('input', { name: 'phone', type: 'tel', inputmode: 'tel', autocomplete: 'tel', placeholder: t.phone_placeholder, maxlength: '20' }),
      city: el('select', { name: 'city' }, [el('option', { value: '', text: t.city_placeholder })].concat(cfg.cities.map(function (c) {
        return el('option', { value: String(c.id), text: c.name });
      }))),
      address: el('input', { name: 'address', autocomplete: 'street-address', placeholder: t.address_placeholder, maxlength: '250' }),
      reference: el('input', { name: 'reference', placeholder: t.reference_placeholder, maxlength: '250' })
    };
    form.appendChild(row('name', t.name_label, 'name', f.name, true));
    form.appendChild(row('phone', t.phone_label, 'phone', f.phone, true));
    form.appendChild(row('city', t.city_label, null, f.city, true));
    form.appendChild(row('address', t.address_label, 'pin', f.address, true));
    form.appendChild(row('reference', null, 'pin', f.reference, false));
    var hp = el('input', { name: 'website', tabindex: '-1', autocomplete: 'off' });
    form.appendChild(el('div', { class: 'nvf-hp', 'aria-hidden': 'true' }, [hp]));

    // El resumen
    var sub = el('b'), exp = el('b'), total = el('b');
    var expRow = el('div', null, ['Delivery Express', exp]);
    form.appendChild(el('div', { class: 'nvf-sum' }, [
      el('div', null, [t.subtotal, sub]), el('div', null, [t.delivery, el('b', { text: t.shipping_price })]), expRow,
      el('div', { class: 'nvf-total' }, [t.total, total])
    ]));

    // El Delivery Express
    var expBox = null;
    if (cfg.express) {
      var expInput = el('input', { type: 'checkbox', name: 'express' });
      expBox = el('label', { class: 'nvf-express' }, [expInput, el('div', null, [
        el('div', { html: escapeHtml(fill(t.express_title, { precio: bs(cfg.express.bs) })).replace(/(Delivery Express)/, '<b>$1</b>') }),
        el('small', { text: t.express_note })
      ])]);
      expInput.addEventListener('change', function () { state.express = expInput.checked; touched('express'); update(); });
      form.appendChild(expBox);
    }

    // El botón
    var btnTotal = el('span');
    var btn = el('button', { type: 'submit', class: 'nvf-btn' }, [el('span', null, [el('b', null, [t.button + ' · ', btnTotal]), el('small', { text: t.button_note })]),
      el('span', { 'aria-hidden': 'true', html: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>' })]);
    form.appendChild(btn);
    var msg = el('div', { class: 'nvf-msg', role: 'alert' });
    form.appendChild(msg);
    form.appendChild(el('div', { class: 'nvf-foot', text: t.footnote }));

    function row(key, label, icon, input, required) {
      var id = 'nvf-' + key;
      input.id = id;
      var box = el('div', { class: 'nvf-box' }, [icon ? el('span', { class: 'nvf-ico', html: ICONS[icon] }) : null, input]);
      var err = el('div', { class: 'nvf-err', id: id + '-err' });
      input.setAttribute('aria-describedby', id + '-err');
      if (required) input.setAttribute('aria-required', 'true');
      input.addEventListener('focus', function () { touched(key); });
      input.addEventListener('input', function () { clearErr(box, err); });
      input.addEventListener('change', function () { clearErr(box, err); });
      var kids = label ? [el('label', { for: id }, [label + ' ', required ? el('span', { class: 'nvf-req', text: '*' }) : null]), box, err] : [box, err];
      var r = el('div', { class: 'nvf-row' + (label ? '' : ' no-label'), 'data-field': key }, kids);
      r._box = box;
      r._err = err;
      return r;
    }
    function clearErr(box, err) { box.classList.remove('is-bad'); err.classList.remove('is-on'); }
    function bad(key, text) {
      var r = form.querySelector('[data-field="' + key + '"]');
      if (!r) return;
      r._box.classList.add('is-bad');
      r._err.textContent = text;
      r._err.classList.add('is-on');
      r.querySelector('input,select').setAttribute('aria-invalid', 'true');
    }
    function touched(key) {
      if (!started) {
        started = true;
        track('start');
        if (cfg.pixel.initiate) {
          var o = cfg.offers[state.offer];
          pixel('InitiateCheckout', content(o.units, o.price_usd));
        }
      }
      if (!seenFields[key]) { seenFields[key] = true; track('field:' + key); }
    }
    function totals() {
      var o = cfg.offers[state.offer];
      var expBs = state.express && cfg.express ? cfg.express.bs : 0;
      return { offer: o, sub: o.price_bs, exp: expBs, total: o.price_bs === null ? null : o.price_bs + (expBs || 0),
        usd: Math.round((o.price_usd + (state.express && cfg.express ? cfg.express.usd : 0)) * 100) / 100 };
    }
    function update() {
      offerNodes.forEach(function (n, i) { n.node.classList.toggle('is-on', i === state.offer); n.input.checked = i === state.offer; });
      var x = totals();
      sub.textContent = bs(x.sub);
      exp.textContent = bs(x.exp);
      expRow.style.display = state.express ? '' : 'none';
      total.textContent = bs(x.total);
      btnTotal.textContent = bs(x.total);
    }
    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (ch) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch]; }); }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      msg.textContent = '';
      var errors = [];
      if (size && !size.value) errors.push(['size', t.error_required]);
      if (f.name.value.trim().length < 3) errors.push(['name', t.error_required]);
      if (!phoneOk(f.phone.value)) errors.push(['phone', f.phone.value.trim() ? t.error_phone : t.error_required]);
      if (!f.city.value) errors.push(['city', t.error_required]);
      if (f.address.value.trim().length < 5) errors.push(['address', t.error_required]);
      if (errors.length) {
        errors.forEach(function (x) { bad(x[0], x[1]); });
        track('error');
        var firstBad = form.querySelector('[aria-invalid="true"]');
        if (firstBad) firstBad.focus();
        return;
      }
      touched('submit');
      track('submit');
      var x = totals();
      if (PREVIEW) {
        done({ order: 'WEB-0', duplicate: false, whatsapp: null }, true);
        return;
      }
      btn.disabled = true;
      var body = JSON.stringify({
        p: P, offer: x.offer.id, variant: size ? Number(size.value) : null, name: f.name.value.trim(), phone: f.phone.value.trim(),
        city: Number(f.city.value), address: f.address.value.trim(), reference: f.reference.value.trim(), express: state.express,
        rate: cfg.rate, visitor: visitor, landing: first && first.landing, referrer: first && first.referrer, page: location.href,
        fbp: cookie('_fbp'), fbc: fbc(), hp: hp.value, ts: cfg.ts, sig: cfg.sig
      });
      send(body, 0);
    });
    function send(body, attempt) {
      fetch(BASE + '/tienda/pedidos', { method: 'POST', body: body, headers: { 'Content-Type': 'text/plain' } })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); })
        .then(function (r) {
          if (r.status === 200 && r.j.ok) return done(r.j, false);
          btn.disabled = false;
          if (r.status === 422 && r.j.fields) {
            r.j.fields.forEach(function (k) { bad(k, k === 'phone' ? t.error_phone : t.error_required); });
            track('error');
            return;
          }
          if (r.status === 429 && r.j.message) { msg.textContent = r.j.message; return; }
          throw new Error('status ' + r.status);
        })
        .catch(function () {
          if (attempt < 1) { setTimeout(function () { send(body, attempt + 1); }, 1500); return; }
          fallback('send');
        });
    }
    function done(res, preview) {
      if (!preview && !res.duplicate && res.event) {
        pixel('Purchase', { value: res.event.value, currency: res.event.currency, content_ids: [cfg.pixel.content_id], content_type: 'product', num_items: res.event.num_items }, res.event.id);
      }
      var box = el('div', { class: 'nvf-done', role: 'status' }, [
        el('h3', { text: fill(t.thanks_title, { pedido: res.order }) }),
        el('p', { text: res.duplicate ? fill(t.repeat_text, { pedido: res.order }) : t.thanks_text })
      ]);
      if (res.whatsapp) {
        var a = el('a', { href: res.whatsapp, rel: 'noopener', text: t.thanks_button });
        a.addEventListener('click', function () { goWhatsapp(res); });
        box.appendChild(a);
      }
      form.parentNode.replaceChild(box, form);
      root = box;
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (res.whatsapp && !preview) setTimeout(function () { goWhatsapp(res); location.href = res.whatsapp; }, 1800);
    }
    var wentWhatsapp = false;
    function goWhatsapp(res) {
      if (wentWhatsapp) return;
      wentWhatsapp = true;
      pixel('Lead', { content_ids: [cfg.pixel.content_id], content_type: 'product' });
      track('whatsapp');
      flush(res.id);
    }

    mount.appendChild(form);
    root = form;
    update();
    track('view');
  }

  /* ---------- arranque ---------- */
  function start() {
    var mount = document.getElementById('nuviora-pedido');
    if (!mount) {
      mount = el('div', { id: 'nuviora-pedido' });
      script.parentNode.insertBefore(mount, script);
    }
    if (!P || !BASE) return;
    var timer;
    var timeout = new Promise(function (resolve, reject) { timer = setTimeout(function () { reject(new Error('timeout')); }, 4000); });
    var url = BASE + '/tienda/productos/' + encodeURIComponent(P) + '?tasa=' + encodeURIComponent(RATE) + '&precio=' + encodeURIComponent(PAGE_PRICE);
    Promise.race([fetch(url).then(function (r) { if (!r.ok) throw new Error('status ' + r.status); return r.json(); }), timeout])
      .then(function (c) {
        clearTimeout(timer);
        if (!c || !c.enabled || !c.offers || !c.offers.length || !c.cities || !c.cities.length) return; // apagado: queda Releasit
        cfg = c;
        try {
          render(mount);
          hideReleasit();
        } catch (e) {
          fallback('render');
        }
      })
      .catch(function () { clearTimeout(timer); fallback('load'); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
