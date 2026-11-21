/*
 * Nuviora · formulario de pedido de la tienda (.164). El dueño lo pega en la landing del producto:
 *   <div id="nuviora-pedido"></div>
 *   <script src="https://api.nuviora.cloud/tienda/cargador.js?p={{ product.id }}" data-tasa="…" data-precio="…" defer></script>
 * Dibuja el formulario ya abierto dentro de #nuviora-pedido y, solo cuando ya está dibujado, esconde el de Releasit. Si
 * algo falla (no carga o no logra enviar el pedido), quita el nuestro y deja el de Releasit: nunca un botón de WhatsApp para
 * pedir. A Meta le manda solo lo que mandaba Releasit: InitiateCheckout, Purchase (con su número, el mismo de la API de
 * conversiones) y Lead al pasar a WhatsApp.
 * .168: el pago anticipado (pago móvil con la foto del comprobante), las encomiendas a todo el país (MRW y Zoom, la oficina
 * de una lista) y "hablar con una asesora". A Meta, siempre el "pedido hecho" (AddPaymentInfo); el Purchase, cuando lo
 * dice el servidor (en las campañas "al entregar" sale al entregar, salvo si ya pagó). Nada de lo que ve el cliente
 * nombra a una IA: el pago "lo valida nuestro equipo".
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
    // .168: "…7:00 p. m.. El lunes": el punto de la abreviatura cierra la oración
    return String(text || '').replace(/\{(\w+)\}/g, function (m, k) { return vars[k] !== undefined ? vars[k] : m; }).replace(/([^.])\.\.(?!\.)/g, '$1.');
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
  function idOk(v) {
    return /^[VE]?\d{6,9}$/i.test(String(v || '').replace(/[\s.\-]+/g, ''));
  }
  var STATES = ['Amazonas', 'Anzoátegui', 'Apure', 'Aragua', 'Barinas', 'Bolívar', 'Carabobo', 'Cojedes', 'Delta Amacuro', 'Distrito Capital',
    'Falcón', 'Guárico', 'La Guaira', 'Lara', 'Mérida', 'Miranda', 'Monagas', 'Nueva Esparta', 'Portuguesa', 'Sucre', 'Táchira', 'Trujillo',
    'Yaracuy', 'Zulia'];
  function copy(text, button, labels) {
    function ok() { button.textContent = labels[1]; setTimeout(function () { button.textContent = labels[0]; }, 1500); }
    try {
      if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text).then(ok, function () {});
      var a = el('textarea', { text: text, style: 'position:fixed;opacity:0' });
      document.body.appendChild(a); a.select(); document.execCommand('copy'); document.body.removeChild(a); ok();
    } catch (e) { /* nada */ }
  }
  /* La foto del comprobante, achicada (JPEG de hasta 1600 px): así sube rápido con datos móviles. */
  function compress(file, cb) {
    try {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var k = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(img.naturalWidth * k));
        c.height = Math.max(1, Math.round(img.naturalHeight * k));
        var g = c.getContext('2d');
        g.fillStyle = '#fff';
        g.fillRect(0, 0, c.width, c.height);
        g.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        var data = c.toDataURL('image/jpeg', 0.82);
        cb(data.length < 4000000 ? data : null);
      };
      img.onerror = function () { URL.revokeObjectURL(url); cb(null); };
      img.src = url;
    } catch (e) { cb(null); }
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
      '#nuviora-pedido .nvf-box select{background-image:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%278%27%3E%3Cpath d=%27M1 1l5 5 5-5%27 fill=%27none%27 stroke=%27%23333%27 stroke-width=%272%27/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:36px;cursor:pointer}' +
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
      '#nuviora-pedido .nvf-btn b span{white-space:nowrap}' +
      '#nuviora-pedido .nvf-btn small{display:block;font-size:14px;margin-top:3px}' +
      '#nuviora-pedido .nvf-msg{color:var(--nvf-err);font-size:14px;text-align:center;margin-top:10px}' +
      '#nuviora-pedido .nvf-foot{text-align:center;font-size:12.5px;font-weight:600;margin-top:14px;color:var(--nvf-label)}' +
      '#nuviora-pedido .nvf-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}' +
      '#nuviora-pedido .nvf-done{text-align:center;padding:28px 16px;border:1px solid var(--nvf-border);border-radius:var(--nvf-radius)}' +
      '#nuviora-pedido .nvf-done h3{color:var(--nvf-title);font-size:21px;margin:0 0 10px}' +
      '#nuviora-pedido .nvf-done a{display:inline-block;margin-top:16px;background:#25D366;color:#fff;font-weight:800;padding:14px 22px;border-radius:999px;text-decoration:none}' +
      '#nuviora-pedido .nvf-done .nvf-done-sub{font-size:13.5px;color:var(--nvf-label);margin-top:10px}' +
      /* .168: elegir envío y pago, la encomienda y el pago móvil */
      '#nuviora-pedido .nvf-ships{display:flex;flex-direction:column;gap:10px;margin-bottom:18px}' +
      '#nuviora-pedido .nvf-ships .nvf-ship{margin-bottom:0}' +
      '#nuviora-pedido label.nvf-ship{cursor:pointer;position:relative}' +
      '#nuviora-pedido label.nvf-ship input{position:absolute;opacity:0;pointer-events:none}' +
      '#nuviora-pedido label.nvf-ship.is-on{border-color:var(--nvf-on);box-shadow:0 0 0 1px var(--nvf-on)}' +
      '#nuviora-pedido label.nvf-ship:not(.is-on) .nvf-dot{border-width:2px}' +
      '#nuviora-pedido label.nvf-ship:focus-within{outline:2px solid var(--nvf-on);outline-offset:2px}' +
      '#nuviora-pedido .nvf-ship small{display:block;font-size:12.5px;font-weight:500;opacity:.8;margin-top:2px}' +
      '#nuviora-pedido .nvf-sub{color:var(--nvf-label);font-weight:600;font-size:15px;margin:4px 0 8px}' +
      '#nuviora-pedido .nvf-courier{border:1px solid var(--nvf-border);border-radius:var(--nvf-radius);background:var(--nvf-sum);padding:14px;margin-bottom:16px}' +
      '#nuviora-pedido .nvf-companies{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}' +
      '#nuviora-pedido .nvf-company{display:flex;flex-direction:column;gap:2px;border:1.5px solid var(--nvf-border);border-radius:var(--nvf-radius);padding:12px;background:var(--nvf-bg);cursor:pointer;position:relative}' +
      '#nuviora-pedido .nvf-company input{position:absolute;opacity:0;pointer-events:none}' +
      '#nuviora-pedido .nvf-company svg{width:22px;height:22px}' +
      '#nuviora-pedido .nvf-company b{font-size:16px}' +
      '#nuviora-pedido .nvf-company small{font-size:12.5px;color:var(--nvf-on);font-weight:700}' +
      '#nuviora-pedido .nvf-company.is-on{border-color:var(--nvf-on);background:var(--nvf-on-bg);box-shadow:0 0 0 1px var(--nvf-on)}' +
      '#nuviora-pedido .nvf-company:focus-within{outline:2px solid var(--nvf-on);outline-offset:2px}' +
      '#nuviora-pedido .nvf-hint{font-size:12.5px;color:var(--nvf-label);margin:-6px 0 6px}' +
      '#nuviora-pedido .nvf-office-addr{font-size:12.5px;text-transform:uppercase;color:var(--nvf-label);margin:-4px 0 10px}' +
      '#nuviora-pedido .nvf-link{background:none;border:0;padding:0;color:var(--nvf-btn);font-size:13px;font-weight:600;text-decoration:underline;cursor:pointer;margin:0 0 10px}' +
      '#nuviora-pedido .nvf-pm{border:1px solid var(--nvf-border);border-radius:var(--nvf-radius);background:var(--nvf-sum);padding:14px;margin:-6px 0 16px}' +
      '#nuviora-pedido .nvf-pm-title{font-weight:800;margin-bottom:8px}' +
      '#nuviora-pedido .nvf-pm-row{display:flex;align-items:center;gap:10px;padding:7px 0;border-top:1px solid var(--nvf-border)}' +
      '#nuviora-pedido .nvf-pm-row span{flex:1;min-width:0;font-size:14px}' +
      '#nuviora-pedido .nvf-pm-row span small{display:block;font-size:12px;color:var(--nvf-label)}' +
      '#nuviora-pedido .nvf-pm-row b{word-break:break-word}' +
      '#nuviora-pedido .nvf-pm-amount b{font-size:18px}' +
      '#nuviora-pedido .nvf-copy{flex:none;border:1px solid var(--nvf-border);background:var(--nvf-bg);color:var(--nvf-text);border-radius:999px;padding:6px 12px;font-size:12.5px;font-weight:700;cursor:pointer;min-height:32px}' +
      '#nuviora-pedido .nvf-upload{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:12px;border:2px dashed var(--nvf-on);border-radius:var(--nvf-radius);padding:14px;font-weight:700;cursor:pointer;background:var(--nvf-bg);text-align:center}' +
      '#nuviora-pedido .nvf-upload input{position:absolute;opacity:0;width:1px;height:1px}' +
      '#nuviora-pedido .nvf-upload.is-bad{border-color:var(--nvf-err)}' +
      '#nuviora-pedido .nvf-upload:focus-within{outline:2px solid var(--nvf-on);outline-offset:2px}' +
      '#nuviora-pedido .nvf-preview{display:flex;align-items:center;gap:10px;margin-top:10px;font-size:13px}' +
      '#nuviora-pedido .nvf-preview img{width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid var(--nvf-border)}' +
      '#nuviora-pedido .nvf-pm-note{font-size:12.5px;color:var(--nvf-label);margin-top:8px}' +
      '#nuviora-pedido .nvf-pm .nvf-err{grid-column:auto;margin-top:6px}' +
      '#nuviora-pedido [hidden]{display:none!important}' +
      '@media (max-width:340px){#nuviora-pedido .nvf-row{grid-template-columns:1fr}#nuviora-pedido .nvf-err{grid-column:1}}' +
      (c.css || '');
  }
  var ICONS = {
    name: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-4 0-8 2-8 5v2h16v-2c0-3-4-5-8-5z"/></svg>',
    phone: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2c-1.6 0-3.1-.4-4.4-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.2s.9 2.5 1 2.7c.1.2 1.8 2.8 4.4 3.9 1.6.7 2.3.8 3.1.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.2-.3-.2-.5-.3z"/></svg>',
    pin: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>',
    id: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm5 3.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4 17h8c0-2-1.8-3-4-3s-4 1-4 3zm10-7v2h6v-2h-6zm0 4v2h4v-2h-4z"/></svg>',
    truck: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h11v9H3zM14 9h4l3 3v3h-7"/><circle cx="7" cy="17" r="1.8"/><circle cx="17" cy="17" r="1.8"/></svg>'
  };

  /* ---------- el formulario ---------- */
  function render(mount) {
    var t = cfg.texts;
    var pay = cfg.pay || {};
    var courierCfg = cfg.courier && cfg.courier.companies && cfg.courier.companies.length ? cfg.courier : null;
    // .168: ship = delivery (pagar al recibir en tus ciudades) o courier (encomienda); payment = cod, pago_movil o asesora
    var state = { offer: 0, express: false, ship: 'delivery', payment: 'cod', company: null, receipt: null, account: null };
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

    // El envío (.168: con encomiendas, se elige entre el delivery y el envío por MRW o Zoom)
    form.appendChild(el('div', { class: 'nvf-label', text: t.shipping_label }));
    var shipNodes = [];
    function choice(group, value, title, note, price, onPick) {
      var input = el('input', { type: 'radio', name: 'nvf-' + group, value: value });
      // el radio va al final: el texto tiene que ser el segundo hijo, como en la caja de envío de siempre
      var node = el('label', { class: 'nvf-ship', 'data-choice': group + ':' + value }, [el('span', { class: 'nvf-dot' }),
        el('span', null, [title, note ? el('small', { text: note }) : null]), price ? el('b', { text: price }) : null, input]);
      input.addEventListener('change', function () { onPick(value); touched(group === 'ship' ? 'city' : 'pay'); update(); });
      return { node: node, input: input, value: value };
    }
    if (courierCfg) {
      var ships = el('div', { class: 'nvf-ships', role: 'radiogroup', 'aria-label': t.shipping_label });
      shipNodes = [choice('ship', 'delivery', t.shipping_option, null, t.shipping_price, function (v) { state.ship = v; }),
        choice('ship', 'courier', t.courier_option, t.courier_note, t.courier_free, function (v) { state.ship = v; })];
      shipNodes.forEach(function (n) { ships.appendChild(n.node); });
      form.appendChild(ships);
    } else {
      form.appendChild(el('div', { class: 'nvf-ship' }, [el('span', { class: 'nvf-dot' }), el('span', { text: t.shipping_option }), el('b', { text: t.shipping_price })]));
    }

    var expBox = null;

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
    var cityRow = form.appendChild(row('city', t.city_label, null, f.city, true));
    var addressRow = form.appendChild(row('address', t.address_label, 'pin', f.address, true));
    var referenceRow = form.appendChild(row('reference', null, 'pin', f.reference, false));

    // .168: la encomienda: la empresa, la oficina donde retira (de la lista o escrita) y la cédula de quien retira
    var courierBox = null, c = {};
    if (courierCfg) {
      courierBox = el('div', { class: 'nvf-courier', hidden: true });
      courierBox.appendChild(el('div', { class: 'nvf-sub', text: t.courier_company_label }));
      var companies = el('div', { class: 'nvf-companies', role: 'radiogroup', 'aria-label': t.courier_company_label, 'data-field': 'company' });
      c.companyNodes = courierCfg.companies.map(function (name) {
        var input = el('input', { type: 'radio', name: 'nvf-company', value: name });
        var node = el('label', { class: 'nvf-company' }, [input, el('span', { html: ICONS.truck }), el('b', { text: name }), el('small', { text: t.courier_free })]);
        input.addEventListener('change', function () { pickCompany(name); touched('company'); });
        companies.appendChild(node);
        return { node: node, input: input, name: name };
      });
      courierBox.appendChild(companies);
      c.office = el('select', { name: 'office', disabled: true }, [el('option', { value: '', text: t.courier_office_placeholder })]);
      c.officeRow = row('office', fill(t.courier_office_label, { empresa: '' }), 'pin', c.office, true);
      c.officeRow.hidden = true;
      courierBox.appendChild(c.officeRow);
      c.hint = el('div', { class: 'nvf-hint', text: t.courier_office_hint, hidden: true });
      c.addr = el('div', { class: 'nvf-office-addr', hidden: true });
      c.missingBtn = el('button', { type: 'button', class: 'nvf-link', text: t.courier_office_missing, hidden: true });
      courierBox.appendChild(c.hint);
      courierBox.appendChild(c.addr);
      courierBox.appendChild(c.missingBtn);
      c.state = el('select', { name: 'state' }, [el('option', { value: '', text: t.courier_state_placeholder })].concat(STATES.map(function (x) { return el('option', { value: x, text: x }); })));
      c.officeText = el('input', { name: 'office_text', placeholder: t.courier_office_text_placeholder, maxlength: '200' });
      c.stateRow = row('state', t.courier_state_label, null, c.state, true);
      c.textRow = row('office_text', null, 'pin', c.officeText, true);
      c.stateRow.hidden = true;
      c.textRow.hidden = true;
      courierBox.appendChild(c.stateRow);
      courierBox.appendChild(c.textRow);
      c.id = el('input', { name: 'id_number', inputmode: 'text', placeholder: t.courier_id_placeholder, maxlength: '14', autocomplete: 'off' });
      courierBox.appendChild(row('id_number', t.courier_id_label, 'id', c.id, true));
      c.otherBtn = el('button', { type: 'button', class: 'nvf-link', text: t.courier_other });
      courierBox.appendChild(c.otherBtn);
      c.rName = el('input', { name: 'recipient_name', placeholder: t.name_placeholder, maxlength: '80' });
      c.rPhone = el('input', { name: 'recipient_phone', type: 'tel', inputmode: 'tel', placeholder: t.phone_placeholder, maxlength: '20' });
      c.rNameRow = row('recipient_name', t.courier_name_label, 'name', c.rName, false);
      c.rPhoneRow = row('recipient_phone', t.courier_phone_label, 'phone', c.rPhone, false);
      c.rNameRow.hidden = true;
      c.rPhoneRow.hidden = true;
      courierBox.appendChild(c.rNameRow);
      courierBox.appendChild(c.rPhoneRow);
      form.appendChild(courierBox);
      c.offices = {};
      c.missing = false;
      c.office.addEventListener('change', showAddress);
      c.missingBtn.addEventListener('click', function () { setMissing(!c.missing); });
      c.otherBtn.addEventListener('click', function () { c.rNameRow.hidden = false; c.rPhoneRow.hidden = false; c.otherBtn.hidden = true; c.rName.focus(); });
    }
    function pickCompany(name) {
      state.company = name;
      c.companyNodes.forEach(function (n) { n.node.classList.toggle('is-on', n.name === name); n.input.checked = n.name === name; });
      c.officeRow.querySelector('label').firstChild.textContent = fill(t.courier_office_label, { empresa: name }) + ' ';
      c.officeRow.hidden = c.missing;
      c.hint.hidden = c.missing;
      c.missingBtn.hidden = false;
      clearErr(c.officeRow._box, c.officeRow._err);
      var cached = c.offices[name];
      if (cached) return fillOffices(cached);
      c.office.disabled = true;
      c.office.innerHTML = '';
      c.office.appendChild(el('option', { value: '', text: t.pm_loading }));
      fetch(BASE + '/tienda/oficinas?empresa=' + encodeURIComponent(name)).then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) { c.offices[name] = (j && j.offices) || []; if (state.company === name) fillOffices(c.offices[name]); })
        .catch(function () { c.offices[name] = []; if (state.company === name) fillOffices([]); });
    }
    function fillOffices(list) {
      c.office.innerHTML = '';
      c.office.appendChild(el('option', { value: '', text: t.courier_office_placeholder }));
      var groups = {};
      list.forEach(function (o) {
        if (!groups[o.state]) { groups[o.state] = el('optgroup', { label: o.state }); c.office.appendChild(groups[o.state]); }
        groups[o.state].appendChild(el('option', { value: String(o.id), text: o.name + (o.city && o.city !== o.name ? ' (' + o.city + ')' : '') }));
      });
      c.office.disabled = false;
      if (!list.length) setMissing(true);
      showAddress();
    }
    function showAddress() {
      var o = (c.offices[state.company] || []).filter(function (x) { return String(x.id) === c.office.value; })[0];
      var address = o ? String(o.address || '').replace(/\.+$/, '') : '';
      var place = o ? (o.city || o.state) : '';
      c.addr.textContent = o ? (address && place && address.toUpperCase().slice(-place.length) === place.toUpperCase() ? address : [address, place].filter(Boolean).join(', ')) : '';
      c.addr.hidden = !o || c.missing;
    }
    function setMissing(on) {
      c.missing = on;
      c.officeRow.hidden = on;
      c.hint.hidden = on;
      c.stateRow.hidden = !on;
      c.textRow.hidden = !on;
      c.missingBtn.textContent = on ? t.courier_office_placeholder : t.courier_office_missing;
      showAddress();
      if (on) c.state.focus();
    }

    // .168: cómo paga (en tus ciudades): al recibir, ahora con pago móvil o hablar antes con una asesora
    var payNodes = [];
    var payBox = null;
    if (pay.prepaid || pay.advisor) {
      payBox = el('div', { 'data-field': 'pay' }, [el('div', { class: 'nvf-sub', text: t.pay_label })]);
      var payList = el('div', { class: 'nvf-ships', role: 'radiogroup', 'aria-label': t.pay_label });
      payNodes = [choice('pay', 'cod', t.pay_cod, t.pay_cod_note, null, function (v) { state.payment = v; })];
      if (pay.prepaid) payNodes.push(choice('pay', 'pago_movil', t.pay_now, t.pay_now_note, null, function (v) { state.payment = v; }));
      if (pay.advisor) payNodes.push(choice('pay', 'asesora', t.pay_advisor, t.pay_advisor_note, null, function (v) { state.payment = v; }));
      payNodes.forEach(function (n) { payList.appendChild(n.node); });
      payBox.appendChild(payList);
      form.appendChild(payBox);
    }

    // .168: el pago móvil: la cuenta del día, el monto exacto y la foto del comprobante
    var pm = null;
    if (pay.prepaid || courierCfg) {
      pm = { box: el('div', { class: 'nvf-pm', hidden: true, 'data-field': 'receipt' }) };
      pm.box.appendChild(el('div', { class: 'nvf-pm-title', text: t.pm_title }));
      pm.rows = el('div', null, [el('div', { class: 'nvf-pm-note', text: t.pm_loading })]);
      pm.box.appendChild(pm.rows);
      pm.amount = el('b');
      pm.amountCopy = el('button', { type: 'button', class: 'nvf-copy', text: t.pm_copy });
      pm.box.appendChild(el('div', { class: 'nvf-pm-row nvf-pm-amount' }, [el('span', null, [el('small', { text: t.pm_amount }), pm.amount]), pm.amountCopy]));
      pm.amountCopy.addEventListener('click', function () { copy(String(totals().total || ''), pm.amountCopy, [t.pm_copy, t.pm_copied]); });
      pm.file = el('input', { type: 'file', accept: 'image/*', name: 'receipt', 'aria-label': t.receipt_label });
      pm.uploadText = el('span', { text: t.receipt_button });
      pm.upload = el('label', { class: 'nvf-upload' }, [pm.file, el('span', { 'aria-hidden': 'true', text: '📎' }), pm.uploadText]);
      pm.box.appendChild(pm.upload);
      pm.preview = el('div', { class: 'nvf-preview', hidden: true });
      pm.box.appendChild(pm.preview);
      pm.err = el('div', { class: 'nvf-err', role: 'alert' });
      pm.box.appendChild(pm.err);
      pm.box.appendChild(el('div', { class: 'nvf-pm-note', text: t.receipt_note }));
      form.appendChild(pm.box);
      pm.file.addEventListener('change', function () {
        var file = pm.file.files && pm.file.files[0];
        touched('receipt');
        if (!file) return;
        pm.uploadText.textContent = t.pm_loading;
        compress(file, function (data) {
          state.receipt = data;
          pm.uploadText.textContent = data ? t.receipt_change : t.receipt_button;
          pm.preview.innerHTML = '';
          if (data) {
            pm.preview.appendChild(el('img', { src: data, alt: t.receipt_label }));
            pm.preview.appendChild(el('span', { text: '✓ ' + t.receipt_label }));
            pm.upload.classList.remove('is-bad');
            pm.err.classList.remove('is-on');
          }
          pm.preview.hidden = !data;
        });
      });
    }
    function loadAccount() {
      if (state.account || pm.loading) return;
      pm.loading = true;
      fetch(BASE + '/tienda/pago?p=' + encodeURIComponent(P)).then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          pm.loading = false;
          pm.rows.innerHTML = '';
          if (!j || !j.account) { pm.rows.appendChild(el('div', { class: 'nvf-pm-note', text: t.pm_error })); return; }
          state.account = j.account;
          j.account.details.forEach(function (d) {
            var b = el('button', { type: 'button', class: 'nvf-copy', text: t.pm_copy });
            b.addEventListener('click', function () { copy(d.value, b, [t.pm_copy, t.pm_copied]); });
            pm.rows.appendChild(el('div', { class: 'nvf-pm-row' }, [el('span', null, [el('small', { text: d.label }), el('b', { text: d.value })]), b]));
          });
        })
        .catch(function () { pm.loading = false; pm.rows.innerHTML = ''; pm.rows.appendChild(el('div', { class: 'nvf-pm-note', text: t.pm_error })); });
    }
    var hp = el('input', { name: 'website', tabindex: '-1', autocomplete: 'off' });
    form.appendChild(el('div', { class: 'nvf-hp', 'aria-hidden': 'true' }, [hp]));

    // El resumen
    var sub = el('b'), exp = el('b'), total = el('b');
    var expRow = el('div', null, ['Delivery Express', exp]);
    form.appendChild(el('div', { class: 'nvf-sum' }, [
      el('div', null, [t.subtotal, sub]), el('div', null, [t.delivery, el('b', { text: t.shipping_price })]), expRow,
      el('div', { class: 'nvf-total' }, [t.total, total])
    ]));

    // El Delivery Express (no va en una encomienda)
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
    var btnNote = el('small', { text: t.button_note });
    var btn = el('button', { type: 'submit', class: 'nvf-btn' }, [el('span', null, [el('b', null, [t.button + ' · ', btnTotal]), btnNote]),
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
      input.addEventListener('input', function () { typed(key, input); clearErr(box, err); });
      input.addEventListener('change', function () { typed(key, input); clearErr(box, err); });
      var kids = label ? [el('label', { for: id }, [label + ' ', required ? el('span', { class: 'nvf-req', text: '*' }) : null]), box, err] : [box, err];
      var r = el('div', { class: 'nvf-row' + (label ? '' : ' no-label'), 'data-field': key }, kids);
      r._box = box;
      r._err = err;
      return r;
    }
    function clearErr(box, err) { box.classList.remove('is-bad'); err.classList.remove('is-on'); }
    function bad(key, text) {
      if (key === 'receipt' && pm) {
        pm.upload.classList.add('is-bad');
        pm.err.textContent = text;
        pm.err.classList.add('is-on');
        return;
      }
      if (key === 'company' && courierBox) {
        msg.textContent = text;
        return;
      }
      var r = form.querySelector('[data-field="' + key + '"]');
      if (!r || !r._box) return;
      r._box.classList.add('is-bad');
      r._err.textContent = text;
      r._err.classList.add('is-on');
      r.querySelector('input,select').setAttribute('aria-invalid', 'true');
    }
    // .165 (dueño: "con solo darle clic puede ser una equivocación"): tocar un campo, cambiar de oferta o marcar el express
    // solo cuenta en qué campos estuvo; "empezaron" y el InitiateCheckout salen la primera vez que escribe algo en un campo
    // (o elige su ciudad o talla), también con el autocompletar del teléfono.
    function touched(key) {
      if (key !== 'submit' && !seenFields[key]) { seenFields[key] = true; track('field:' + key); }
    }
    function typed(key, input) {
      touched(key);
      if (started || (input && !String(input.value).trim())) return;
      started = true;
      track('start');
      if (cfg.pixel.initiate) {
        var o = cfg.offers[state.offer];
        pixel('InitiateCheckout', content(o.units, o.price_usd));
      }
    }
    function totals() {
      var o = cfg.offers[state.offer];
      var expBs = state.express && cfg.express ? cfg.express.bs : 0;
      return { offer: o, sub: o.price_bs, exp: expBs, total: o.price_bs === null ? null : o.price_bs + (expBs || 0),
        usd: Math.round((o.price_usd + (state.express && cfg.express ? cfg.express.usd : 0)) * 100) / 100 };
    }
    function paying() { return state.ship === 'courier' ? 'pago_movil' : state.payment; }
    function update() {
      offerNodes.forEach(function (n, i) { n.node.classList.toggle('is-on', i === state.offer); n.input.checked = i === state.offer; });
      // .168: lo que se ve según el envío y el pago
      var courier = state.ship === 'courier';
      if (courier) state.express = false;
      shipNodes.forEach(function (n) { n.node.classList.toggle('is-on', n.value === state.ship); n.input.checked = n.value === state.ship; });
      payNodes.forEach(function (n) { n.node.classList.toggle('is-on', n.value === state.payment); n.input.checked = n.value === state.payment; });
      cityRow.hidden = courier;
      addressRow.hidden = courier;
      referenceRow.hidden = courier;
      if (courierBox) courierBox.hidden = !courier;
      if (payBox) payBox.hidden = courier;
      if (expBox) { expBox.hidden = courier; if (courier) expBox.querySelector('input').checked = false; }
      var asesora = paying() === 'asesora';
      addressRow.querySelector('.nvf-req') && (addressRow.querySelector('.nvf-req').hidden = asesora);
      if (pm) {
        pm.box.hidden = paying() !== 'pago_movil';
        if (!pm.box.hidden) loadAccount();
      }
      btnNote.textContent = paying() === 'pago_movil' ? t.button_note_prepaid : (asesora ? t.button_note_advisor : t.button_note);
      var x = totals();
      sub.textContent = bs(x.sub);
      exp.textContent = bs(x.exp);
      expRow.style.display = state.express ? '' : 'none';
      total.textContent = bs(x.total);
      btnTotal.textContent = bs(x.total);
      if (pm) pm.amount.textContent = bs(x.total);
    }
    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (ch) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch]; }); }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      msg.textContent = '';
      var errors = [];
      if (size && !size.value) errors.push(['size', t.error_required]);
      if (f.name.value.trim().length < 3) errors.push(['name', t.error_required]);
      if (!phoneOk(f.phone.value)) errors.push(['phone', f.phone.value.trim() ? t.error_phone : t.error_required]);
      var courier = state.ship === 'courier';
      if (!courier && !f.city.value) errors.push(['city', t.error_required]);
      if (!courier && paying() !== 'asesora' && f.address.value.trim().length < 5) errors.push(['address', t.error_required]);
      if (courier) {
        if (!state.company) errors.push(['company', t.error_office]);
        else if (!c.missing && !c.office.value) errors.push(['office', t.error_office]);
        if (courier && c.missing && !c.state.value) errors.push(['state', t.error_required]);
        if (courier && c.missing && c.officeText.value.trim().length < 4) errors.push(['office_text', t.error_office]);
        if (!idOk(c.id.value)) errors.push(['id_number', c.id.value.trim() ? t.error_id : t.error_required]);
      }
      if (paying() === 'pago_movil' && !state.receipt) errors.push(['receipt', t.error_receipt]);
      if (errors.length) {
        errors.forEach(function (x) { bad(x[0], x[1]); });
        track('error');
        var firstBad = form.querySelector('[aria-invalid="true"]') || (pm && pm.upload.classList.contains('is-bad') ? pm.file : null);
        if (firstBad) { firstBad.focus(); if (firstBad === (pm && pm.file)) pm.box.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        return;
      }
      typed('submit');
      track('submit');
      var x = totals();
      if (PREVIEW) {
        done({ order: 'WEB-0', duplicate: false, whatsapp: null }, true);
        return;
      }
      btn.disabled = true;
      var body = JSON.stringify({
        p: P, offer: x.offer.id, variant: size ? Number(size.value) : null, name: f.name.value.trim(), phone: f.phone.value.trim(),
        city: courier ? null : Number(f.city.value), address: courier ? null : f.address.value.trim(), reference: courier ? null : f.reference.value.trim(),
        express: !courier && state.express, payment: paying(), account: state.account ? state.account.id : null,
        receipt: paying() === 'pago_movil' ? state.receipt : null,
        courier: courier ? { company: state.company, office_id: c.missing ? null : Number(c.office.value) || null, office_text: c.missing ? c.officeText.value.trim() : null,
          state: c.missing ? c.state.value : null, id_number: c.id.value.trim(), recipient_name: c.rName.value.trim() || null,
          recipient_phone: c.rPhone.value.trim() || null } : null,
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
            r.j.fields.forEach(function (k) {
              bad(k, ({ phone: t.error_phone, receipt: t.error_receipt, id_number: t.error_id, office: t.error_office, company: t.error_office })[k] || t.error_required);
            });
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
      // .168: los eventos que dice el servidor (el "pedido hecho" siempre; el Purchase si sale al pedir)
      var events = res.events || (res.event ? [{ name: 'Purchase', id: res.event.id, value: res.event.value, currency: res.event.currency, num_items: res.event.num_items }] : []);
      if (!preview && !res.duplicate) {
        events.forEach(function (e) {
          pixel(e.name, { value: e.value, currency: e.currency, content_ids: [cfg.pixel.content_id], content_type: 'product', num_items: e.num_items }, e.id);
        });
      }
      var paid = (res.payment || paying()) === 'pago_movil';
      var advisor = (res.payment || paying()) === 'asesora';
      var text = res.duplicate ? fill(t.repeat_text, { pedido: res.order })
        : (paid ? (res.after_hours ? fill(t.thanks_prepaid_closed, { horario: res.hours || '', cuando: res.when || '' }) : t.thanks_prepaid_open)
          : (advisor ? t.thanks_advisor : t.thanks_text));
      var box = el('div', { class: 'nvf-done', role: 'status' }, [
        el('h3', { text: fill(paid ? t.thanks_prepaid_title : t.thanks_title, { pedido: res.order }) }),
        el('p', { text: text }),
        (paid || advisor) && res.whatsapp && !res.duplicate ? el('p', { class: 'nvf-done-sub', text: t.thanks_go_whatsapp }) : null
      ]);
      if (res.whatsapp) {
        var a = el('a', { href: res.whatsapp, rel: 'noopener', text: t.thanks_button });
        a.addEventListener('click', function () { goWhatsapp(res); });
        box.appendChild(a);
      }
      form.parentNode.replaceChild(box, form);
      root = box;
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
      // quien pagó por adelantado lee primero lo de su pago (y el horario, si es de noche)
      if (res.whatsapp && !preview) setTimeout(function () { goWhatsapp(res); location.href = res.whatsapp; }, paid ? 6000 : 1800);
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
    var url = BASE + '/tienda/productos/' + encodeURIComponent(P) + '?tasa=' + encodeURIComponent(RATE) + '&precio=' + encodeURIComponent(PAGE_PRICE) + (PREVIEW ? '&prueba=1' : '');
    Promise.race([fetch(url).then(function (r) { if (!r.ok) throw new Error('status ' + r.status); return r.json(); }), timeout])
      .then(function (c) {
        clearTimeout(timer);
        if (!c || !c.enabled || !c.offers || !c.offers.length || !c.cities || (!c.cities.length && !PREVIEW)) return; // apagado: queda Releasit
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
