/* Trazo · panel de consolidación del grupo (SPA sin dependencias). Autor: Jonathan Diaz · https://www.soydiaz.com
   API v2 (datos reales del Grupo Meridia): versiones por clave (ppto_2026, real_2026, ppto_2027_v1, ppto_2027_v2),
   países GT/MX/PA/EC/US, tasas mensuales, 6 categorías de hallazgo, perfiles de lectura por país.
   Lee window.CSL = {api, nonce, user, version, logout}. Rutas por hash (#/inicio, #/carga, …).
   Todo dato que llega del servidor se inserta con textContent o con nodos creados a mano (nunca innerHTML).
   Donde la forma de una respuesta no está fija en el contrato, la decisión va marcada con «// CONTRATO:». */
(function () {
  'use strict';
  var C = window.CSL || {};
  var app = document.getElementById('csl-app');
  if (!app) return;

  var ME = null;       // GET /me
  var META = null;     // GET /meta (normalizado)
  var VERSION = null;  // clave de la versión de trabajo (selector de la cabecera)
  var LS_VERSION = 'csl_version';
  var DIRTY = false;

  /* =====================================================================
     Utilidades de DOM
     ===================================================================== */
  function h(tag, attrs) {
    var el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) return;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k.slice(0, 2) === 'on' && typeof v === 'function') el.addEventListener(k.slice(2), v);
        else if (k === 'value') el.value = v;
        else if (k === 'checked') el.checked = !!v;
        else if (k === 'selected') el.selected = !!v;
        else if (k === 'style' && typeof v === 'object') Object.keys(v).forEach(function (s) { el.style[s] = v[s]; });
        else el.setAttribute(k, v === true ? '' : v);
      });
    }
    for (var i = 2; i < arguments.length; i++) add(el, arguments[i]);
    return el;
  }
  function add(el, c) {
    if (c === null || c === undefined || c === false) return el;
    if (Array.isArray(c)) { c.forEach(function (x) { add(el, x); }); return el; }
    el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    return el;
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }
  function arr(x, key) {
    if (Array.isArray(x)) return x;
    if (x && key && Array.isArray(x[key])) return x[key];
    if (x && Array.isArray(x.items)) return x.items;
    return [];
  }
  /** Objeto (o lista vacía que PHP manda como []) → objeto. */
  function obj(x) { return x && typeof x === 'object' && !Array.isArray(x) ? x : {}; }
  /** Mapa de meses: PHP manda 1..12 como objeto y 0..12 como lista; ambos se leen igual con m[k]. */
  function monthsOf(x) { return x && typeof x === 'object' ? x : {}; }
  function today(plusDays) {
    var d = new Date(); d.setDate(d.getDate() + (plusDays || 0));
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  /* =====================================================================
     API
     ===================================================================== */
  function url(path) {
    var base = String(C.api || '/wp-json/consolida/v1').replace(/\/+$/, '');
    // CONTRATO: si WordPress usa enlaces simples (?rest_route=…), la consulta se une con «&».
    if (base.indexOf('?') >= 0) path = path.replace('?', '&');
    return base + path;
  }
  function qs(o) {
    var p = [];
    Object.keys(o).forEach(function (k) { var v = o[k]; if (v !== null && v !== undefined && v !== '') p.push(encodeURIComponent(k) + '=' + encodeURIComponent(v)); });
    return p.length ? '?' + p.join('&') : '';
  }
  function statusMsg(s) {
    if (s === 401) return 'Tu sesión expiró. Vuelve a iniciar sesión.';
    if (s === 403) return 'No tienes permiso para ver o hacer esto.';
    if (s === 404) return 'No se encontró la información que buscas.';
    if (s === 413) return 'El archivo es demasiado grande para el servidor.';
    if (s === 503) return 'Una parte del sistema aún no está disponible. Inténtalo más tarde.';
    if (s >= 500) return 'El servidor tuvo un problema. Inténtalo de nuevo en un momento.';
    return 'Algo salió mal (código ' + s + ').';
  }
  var NET_ERR = 'No hay conexión con el servidor. Revisa tu red e inténtalo de nuevo.';
  function apiError(status, j) {
    var e = new Error((j && j.message) || statusMsg(status));
    e.status = status; e.code = j && j.code; e.data = j;
    return e;
  }
  function api(path, opts) {
    opts = opts || {};
    var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce || '', Accept: 'application/json' } };
    if (opts.form) init.body = opts.form;
    else if (opts.body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    return fetch(url(path), init).then(function (r) {
      return r.text().then(function (t) {
        var j = null;
        try { j = t ? JSON.parse(t) : null; } catch (e) { j = null; }
        if (!r.ok) throw apiError(r.status, j);
        return j;
      });
    }, function () { throw new Error(NET_ERR); });
  }
  function post(path, body) { return api(path, { method: 'POST', body: body || {} }); }
  /** null si la respuesta es 404 (p. ej. aún no hay consolidación). */
  function maybe(p) { return p.catch(function (e) { if (e.status === 404) return null; throw e; }); }

  /** Descarga un archivo protegido (manda X-WP-Nonce, así que no sirve un enlace normal). */
  function download(path, fallbackName, b) {
    var p = fetch(url(path), { credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce || '' } }).then(function (r) {
      if (!r.ok) return r.json().catch(function () { return null; }).then(function (j) { throw apiError(r.status, j); });
      var cd = r.headers.get('Content-Disposition') || '';
      var m = /filename\*=UTF-8''([^;]+)/i.exec(cd) || /filename="?([^";]+)"?/i.exec(cd);
      var name = m ? decodeURIComponent(m[1]) : fallbackName;
      return r.blob().then(function (bl) {
        var a = h('a', { href: URL.createObjectURL(bl), download: name || 'archivo.xlsx', hidden: true });
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
      });
    }, function () { throw new Error(NET_ERR); });
    return busy(b, p).catch(fail);
  }

  /** Subida con XMLHttpRequest para mostrar el avance real (fetch no informa el avance de subida). */
  function uploadFile(path, form, onProgress) {
    return new Promise(function (resolve, reject) {
      var x = new XMLHttpRequest();
      x.open('POST', url(path));
      x.setRequestHeader('X-WP-Nonce', C.nonce || '');
      x.setRequestHeader('Accept', 'application/json');
      x.withCredentials = true;
      if (x.upload) x.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded / e.total); };
      x.onload = function () {
        var j = null;
        try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
        if (x.status >= 200 && x.status < 300) resolve(j || {}); else reject(apiError(x.status, j));
      };
      x.onerror = function () { reject(new Error('No se pudo subir el archivo. Revisa tu conexión e inténtalo de nuevo.')); };
      x.send(form);
    });
  }

  /* =====================================================================
     Formato de números y fechas (es-GT)
     ===================================================================== */
  var NFC = {};
  function nf(min, max) {
    var k = min + '-' + max;
    return NFC[k] || (NFC[k] = new Intl.NumberFormat('es-GT', { minimumFractionDigits: min, maximumFractionDigits: max }));
  }
  function isNum(v) { return v !== null && v !== undefined && v !== '' && typeof v !== 'boolean' && !isNaN(Number(v)) && isFinite(Number(v)); }
  function sgn(n, s) { return (n < 0 && /[1-9]/.test(s) ? '−' : ''); }
  function num(v, d) { if (!isNum(v)) return '—'; var n = Number(v), s = nf(d || 0, d || 0).format(Math.abs(n)); return sgn(n, s) + s; }
  /** Número con hasta 2 decimales (montos de Excel). */
  function numv(v) { if (!isNum(v)) return '—'; var n = Number(v), s = nf(0, 2).format(Math.abs(n)); return sgn(n, s) + s; }
  /** Tasas de cambio: hasta 4 decimales. */
  function rate(v) { if (!isNum(v)) return '—'; return nf(0, 4).format(Number(v)); }
  function money(v, d, pre) { if (!isNum(v)) return '—'; var n = Number(v), s = nf(d || 0, d || 0).format(Math.abs(n)); return sgn(n, s) + (pre ? pre + ' ' : '') + s; }
  function usd(v, d) { return money(v, d, 'US$'); }
  /** Millones abreviados para tarjetas: «US$ 84.2 M». */
  function usdM(v) {
    if (!isNum(v)) return '—';
    var a = Math.abs(Number(v));
    if (a >= 1e9) return money(v / 1e9, 2, 'US$') + ' mil M';
    if (a >= 1e6) return money(v / 1e6, 1, 'US$') + ' M';
    if (a >= 1e3) return money(v / 1e3, 1, 'US$') + ' mil';
    return money(v, 0, 'US$');
  }
  /** Corto para etiquetas de gráficos: «45.0 M», «−669 mil» (la moneda va en la leyenda). */
  function shortM(v) {
    if (!isNum(v)) return '—';
    var a = Math.abs(Number(v));
    if (a >= 1e9) return num(v / 1e9, 1) + ' mil M';
    if (a >= 1e6) return num(v / 1e6, 1) + ' M';
    if (a >= 1e3) return num(v / 1e3, 0) + ' mil';
    return num(v, 0);
  }
  function plus(v, s) { return (isNum(v) && Number(v) > 0 ? '+' : '') + s; }
  function signedUsdM(v) { return plus(v, usdM(v)); }
  function kusd(v) { return isNum(v) ? num(Number(v) / 1000, 0) : '—'; }
  function loc(v, cur) { return money(v, 0, cur || ''); }
  /** Fracción → porcentaje («0.118» → «11.8 %»). */
  function pct(f, d) { return isNum(f) ? num(Number(f) * 100, d === undefined ? 1 : d) + ' %' : '—'; }
  function signedPct(f) { return plus(f, pct(f)); }
  /** Puntos porcentuales ya en puntos. */
  function pts(p) { return isNum(p) ? plus(p, num(p, 1)) + ' pts' : '—'; }
  function dt(s) {
    if (!s) return '—';
    var str = String(s), long = str.length > 10;
    var d = new Date(long ? str.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(str) ? '' : 'Z') : str + 'T12:00:00Z');
    if (isNaN(d)) return str;
    return long
      ? d.toLocaleString('es-GT', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
      : d.toLocaleDateString('es-GT', { day: 'numeric', month: 'short', year: 'numeric' });
  }
  function cls(v) { return isNum(v) && Number(v) < 0 ? 'neg' : ''; }
  var MONTHS = ['Saldo inicial', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  var MON3 = ['Inicial', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  function monthName(m) { return isNum(m) && MONTHS[Number(m)] ? MONTHS[Number(m)] : '—'; }
  /** Celda de Excel: columna + fila («K25»). */
  function cell(col, row) { return (col || '') + (isNum(row) ? String(row) : '') || '—'; }

  /* =====================================================================
     Catálogos y etiquetas
     ===================================================================== */
  var UPLOAD_ST = { rechazado: ['Con errores críticos', 'bad'], con_avisos: ['Con avisos por explicar', 'warn'], validado: ['Validado', 'ok'], aprobado: ['Aprobado, falta enviar', 'info'], enviado: ['Enviado', 'ok'] };
  var STATE_ICON = { ok: 'check', warn: 'alert', bad: 'xcircle', info: 'info', neutral: 'dot' };
  var LIGHT = { verde: ['Bien', 'ok'], amarillo: ['Vigilar', 'warn'], rojo: ['Actuar', 'bad'] };
  var SEV = { critico: ['Crítico', 'bad'], aviso: ['Aviso', 'warn'] };
  var CATEGORY_ORDER = ['formato_escala', 'catalogo_periodo', 'calculos_totales', 'intercompania', 'tipo_cambio', 'indicadores'];
  var ISSUE_ST = { abierta: ['Abierta', 'neutral'], corregida: ['Corregida', 'ok'], explicada: ['Explicada', 'info'] };
  var ACTION_ST = [['pendiente', 'Pendiente'], ['en_curso', 'En curso'], ['hecha', 'Hecha']];
  var ACTION_KIND = { pendiente: 'neutral', en_curso: 'info', hecha: 'ok' };
  var VERSION_ST = { abierto: ['Abierta', 'info'], consolidado: ['Consolidada', 'ok'], cerrado: ['Cerrada', 'neutral'] };
  var DECISION = { aprobar: ['Aprobar', 'ok'], ajustar: ['Ajustar', 'warn'], rechazar: ['Rechazar', 'bad'] };
  var EFFECTS = [['volumen', 'Volumen'], ['precio', 'Precio'], ['tipo_cambio', 'Tipo de cambio'], ['intercompania', 'Intercompañía'], ['otros', 'Otros']];
  var IC_TYPE = { product_sale: 'Venta de producto', management_fee: 'Cuota de administración', loan_interest: 'Intereses de préstamo', loan: 'Préstamo',
    saldo: 'Saldo por cobrar / pagar', prestamo: 'Préstamo (saldo)', margen_inventario: 'Margen no realizado en inventario', minoritario: 'Interés minoritario' };
  var PAIR_ST = { cuadra: ['Cuadra', 'ok'], no_cuadra: ['No cuadra', 'bad'], falta_lado: ['Falta un lado', 'bad'], tiempo: ['Diferencia de tiempo', 'warn'] };
  var TREND = { mejora: ['Mejora', 'ok', 'arrowDown'], empeora: ['Empeora', 'bad', 'arrowUp'], igual: ['Igual', 'neutral', 'dot'], sin_historial: ['Sin historial', 'neutral', 'dot'] };
  var SCENARIOS = [['base', 'Base'], ['tc_mas_10', 'Dólar +10 %'], ['tc_menos_10', 'Dólar −10 %'], ['volumen_mas_10', 'Volumen +10 %'], ['volumen_menos_10', 'Volumen −10 %']];
  var LOG_LBL = { subir: 'Subió un archivo', aprobar: 'Aprobó un archivo', enviar: 'Envió al corporativo', explicar: 'Explicó un aviso', consolidar: 'Consolidó',
    importar_corporativo: 'Importó el archivo corporativo', perfil_guardar: 'Guardó un perfil de lectura', perfil_restablecer: 'Restableció un perfil de lectura',
    guardar_tasas: 'Guardó tasas', aprobar_tasas: 'Aprobó tasas', crear: 'Creó', editar: 'Editó', estado: 'Cambió el estado', descargar: 'Descargó un archivo' };
  var OBJ_LBL = { upload: 'subida', issue: 'hallazgo', result: 'consolidación', company: 'empresa', version: 'versión', action: 'acción', corporate: 'corporativo' };

  function caps() { return (ME && ME.caps) || {}; }
  function normMeta(m) {
    m = m || {};
    return {
      catalog: arr(m.catalog), products: obj(m.products), channels: obj(m.channels), companies: arr(m.companies), versions: arr(m.versions),
      categories: obj(m.categories), issueCodes: obj(m.issueCodes), uploadStatuses: obj(m.uploadStatuses),
      maxUploadMb: Number(m.maxUploadMb) || 10, hasCorporate: !!m.hasCorporate, corporateFile: m.corporateFile || null
    };
  }
  function catLabel(k) { return META.categories[k] || String(k || '—').replace(/_/g, ' '); }
  function categories() { var ks = Object.keys(META.categories); return ks.length ? CATEGORY_ORDER.filter(function (k) { return ks.indexOf(k) >= 0; }).concat(ks.filter(function (k) { return CATEGORY_ORDER.indexOf(k) < 0; })) : CATEGORY_ORDER; }
  function versionByKey(k) { var hit = null; META.versions.forEach(function (v) { if (v.key === k) hit = v; }); return hit; }
  function versionLabel(k) { var v = versionByKey(k); return v ? v.label : (k ? String(k).replace(/_/g, ' ') : 'sin versión'); }
  function companyById(id) { var hit = null; META.companies.forEach(function (c) { if (String(c.id) === String(id)) hit = c; }); return hit; }
  function companyByCode(code) { var hit = null; META.companies.forEach(function (c) { if (c.code === code) hit = c; }); return hit; }
  /** Bandera textual a partir del código de país ISO (GT → 🇬🇹). Sin emoji de bandera, el sistema muestra las letras. */
  function flag(code) {
    var c = String(code || '').toUpperCase();
    if (!/^[A-Z]{2}$/.test(c)) return '';
    return String.fromCodePoint(0x1F1E6 + c.charCodeAt(0) - 65, 0x1F1E6 + c.charCodeAt(1) - 65);
  }
  /** «🇬🇹 Guatemala». */
  function coLabel(code) { var c = companyByCode(code); var f = flag(code); return (f ? f + ' ' : '') + (c ? (c.country || c.name) : String(code || '—')); }
  /** «🇬🇹 GT». */
  function coShort(code) { var f = flag(code); return (f ? f + ' ' : '') + String(code || '—'); }
  function curOf(code) { var c = companyByCode(code); return (c && c.currency) || ''; }
  function account(code) { var hit = null; META.catalog.forEach(function (a) { if (String(a.code) === String(code)) hit = a; }); return hit; }
  function accLabel(code, name) { var a = account(code); return code + ' · ' + (name || (a && a.name) || ''); }
  function issueText(code) {
    var t = META.issueCodes[code];
    if (!t && /^KPI_COPY/.test(code || '')) t = META.issueCodes.KPI_COPY;
    return typeof t === 'string' ? t : (t && (t.label || t.text)) || '';
  }
  function lines() {
    var out = [];
    Object.keys(META.products).forEach(function (k) { var l = META.products[k] && META.products[k].line; if (l && out.indexOf(l) < 0) out.push(l); });
    return out.length ? out : ['Snacks', 'Bebidas', 'Confitería'];
  }
  function conceptName(c) {
    var s = String(c || ''), m = /^(volume|price|net_sales):(.+)$/.exec(s);
    if (m) return ({ volume: 'Volumen ', price: 'Precio ', net_sales: 'Ventas netas (para validar) ' })[m[1]] + m[2];
    if (s === 'total_row') return 'Fila de total (se valida, no se suma)';
    var a = account(s);
    return a ? a.name : '';
  }
  function myCompanies() {
    var c = caps();
    if (c.corp) return META.companies.slice();
    var mine = (ME.companies || []).map(String);
    return META.companies.filter(function (x) { return mine.indexOf(String(x.id)) >= 0; });
  }
  function ownsCompany(id) { return !!caps().country && (ME.companies || []).map(String).indexOf(String(id)) >= 0; }
  function firstName() { var n = (ME && ME.name) || (C.user && C.user.name) || ''; return n.split(/\s+/)[0] || 'equipo'; }

  /* =====================================================================
     Iconos SVG en línea (trazo 2, 24×24, color del texto)
     ===================================================================== */
  var ICONS = {
    home: [['path', { d: 'M3 11l9-8 9 8' }], ['path', { d: 'M5 10v10h5v-6h4v6h5V10' }]],
    upload: [['path', { d: 'M12 16V4' }], ['path', { d: 'M7 9l5-5 5 5' }], ['path', { d: 'M4 16v4h16v-4' }]],
    download: [['path', { d: 'M12 4v12' }], ['path', { d: 'M7 11l5 5 5-5' }], ['path', { d: 'M4 20h16' }]],
    percent: [['path', { d: 'M19 5L5 19' }], ['circle', { cx: '7', cy: '7', r: '2.5' }], ['circle', { cx: '17', cy: '17', r: '2.5' }]],
    layers: [['path', { d: 'M12 3l9 5-9 5-9-5z' }], ['path', { d: 'M3 13l9 5 9-5' }]],
    chart: [['path', { d: 'M4 20V10' }], ['path', { d: 'M10 20V4' }], ['path', { d: 'M16 20v-7' }], ['path', { d: 'M22 20H2' }]],
    gauge: [['path', { d: 'M4 18a8 8 0 1 1 16 0' }], ['path', { d: 'M12 18l4-6' }]],
    doc: [['path', { d: 'M6 3h9l4 4v14H6z' }], ['path', { d: 'M14 3v5h5' }], ['path', { d: 'M9 13h7M9 17h5' }]],
    tasks: [['path', { d: 'M9 6h11M9 12h11M9 18h11' }], ['path', { d: 'M3.5 6l1.5 1.5L7.5 5' }], ['path', { d: 'M3.5 12l1.5 1.5L7.5 11' }], ['path', { d: 'M3.5 18l1.5 1.5L7.5 17' }]],
    search: [['circle', { cx: '11', cy: '11', r: '7' }], ['path', { d: 'M20 20l-3.5-3.5' }]],
    shield: [['path', { d: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z' }], ['path', { d: 'M8.5 12l2.5 2.5 4.5-5' }]],
    log: [['path', { d: 'M9 6h12M9 12h12M9 18h12' }], ['path', { d: 'M4 6h.01M4 12h.01M4 18h.01' }]],
    database: [['ellipse', { cx: '12', cy: '6', rx: '8', ry: '3' }], ['path', { d: 'M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6' }], ['path', { d: 'M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3' }]],
    logout: [['path', { d: 'M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3' }], ['path', { d: 'M10 17l-5-5 5-5' }], ['path', { d: 'M5 12h11' }]],
    menu: [['path', { d: 'M4 6h16M4 12h16M4 18h16' }]],
    check: [['path', { d: 'M5 12.5l4.5 4.5L19 7' }]],
    alert: [['path', { d: 'M12 3l10 18H2z' }], ['path', { d: 'M12 10v5' }], ['path', { d: 'M12 18h.01' }]],
    xcircle: [['circle', { cx: '12', cy: '12', r: '9' }], ['path', { d: 'M9 9l6 6M15 9l-6 6' }]],
    info: [['circle', { cx: '12', cy: '12', r: '9' }], ['path', { d: 'M12 11v6' }], ['path', { d: 'M12 7.5h.01' }]],
    dot: [['circle', { cx: '12', cy: '12', r: '4' }]],
    trace: [['circle', { cx: '11', cy: '11', r: '6' }], ['path', { d: 'M20 20l-4.5-4.5' }], ['path', { d: 'M8.5 11h5' }]],
    print: [['path', { d: 'M7 9V3h10v6' }], ['rect', { x: '3', y: '9', width: '18', height: '8', rx: '2' }], ['path', { d: 'M7 14h10v7H7z' }]],
    plus: [['path', { d: 'M12 5v14M5 12h14' }]],
    trash: [['path', { d: 'M4 7h16' }], ['path', { d: 'M10 11v6M14 11v6' }], ['path', { d: 'M6 7l1 13h10l1-13' }], ['path', { d: 'M9 7V4h6v3' }]],
    refresh: [['path', { d: 'M20 11a8 8 0 0 0-14.5-4.5L4 8' }], ['path', { d: 'M4 4v4h4' }], ['path', { d: 'M4 13a8 8 0 0 0 14.5 4.5L20 16' }], ['path', { d: 'M20 20v-4h-4' }]],
    send: [['path', { d: 'M21 3L10 14' }], ['path', { d: 'M21 3l-7 18-4-7-7-4z' }]],
    arrowUp: [['path', { d: 'M12 19V5' }], ['path', { d: 'M6 11l6-6 6 6' }]],
    arrowDown: [['path', { d: 'M12 5v14' }], ['path', { d: 'M6 13l6 6 6-6' }]],
    trend: [['path', { d: 'M3 17l6-6 4 4 8-8' }], ['path', { d: 'M15 7h6v6' }]],
    bulb: [['path', { d: 'M9 18h6M10 21h4' }], ['path', { d: 'M12 3a6 6 0 0 0-4 10.5c.8.8 1 1.5 1 2.5h6c0-1 .2-1.7 1-2.5A6 6 0 0 0 12 3z' }]],
    briefcase: [['rect', { x: '3', y: '7', width: '18', height: '13', rx: '2' }], ['path', { d: 'M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2' }], ['path', { d: 'M3 13h18' }]],
    calendar: [['rect', { x: '3', y: '5', width: '18', height: '16', rx: '2' }], ['path', { d: 'M3 10h18M8 3v4M16 3v4' }]],
    factory: [['path', { d: 'M3 21V10l6 4V10l6 4V6l6 3v12z' }], ['path', { d: 'M7 17h2M12 17h2M17 17h2' }]],
    swap: [['path', { d: 'M4 8h13l-3-3' }], ['path', { d: 'M20 16H7l3 3' }]],
    gear: [['circle', { cx: '12', cy: '12', r: '3' }], ['path', { d: 'M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z' }]],
    arrowRight: [['path', { d: 'M5 12h14' }], ['path', { d: 'M13 6l6 6-6 6' }]],
    sliders: [['path', { d: 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0' }], ['circle', { cx: '16', cy: '6', r: '2' }], ['circle', { cx: '10', cy: '12', r: '2' }], ['circle', { cx: '18', cy: '18', r: '2' }]]
  };
  function icon(name, size) {
    var NS = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(NS, 'svg');
    var at = { viewBox: '0 0 24 24', width: String(size || 18), height: String(size || 18), 'aria-hidden': 'true', focusable: 'false', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', class: 'ico' };
    Object.keys(at).forEach(function (k) { svg.setAttribute(k, at[k]); });
    (ICONS[name] || []).forEach(function (x) {
      var el = document.createElementNS(NS, x[0]);
      Object.keys(x[1]).forEach(function (k) { el.setAttribute(k, x[1][k]); });
      svg.appendChild(el);
    });
    return svg;
  }

  /* =====================================================================
     Componentes
     ===================================================================== */
  function tag(text, kind) { kind = kind || 'neutral'; return h('span', { class: 'tag t-' + kind }, icon(STATE_ICON[kind] || 'dot'), h('span', { text: text })); }
  function uploadTag(u) { if (!u) return tag('Sin cargar', 'neutral'); var s = UPLOAD_ST[u.status] || [META.uploadStatuses[u.status] || u.status, 'neutral']; return tag(s[0], s[1]); }
  function lightTag(l) { var x = LIGHT[l] || ['Sin dato', 'neutral']; return tag(x[0], x[1]); }
  function btn(text, onclick, kind, attrs, ico) {
    var a = attrs || {};
    a.class = 'btn ' + (kind || ''); a.type = a.type || 'button'; a.onclick = onclick;
    return h('button', a, ico ? icon(ico) : null, h('span', { text: text }));
  }
  function linkBtn(text, href, kind, ico) { return h('a', { class: 'btn ' + (kind || ''), href: href }, ico ? icon(ico) : null, h('span', { text: text })); }
  function busy(b, p) {
    if (!b || !b.tagName) return p;
    b.disabled = true; b.setAttribute('aria-busy', 'true');
    var end = function () { b.disabled = false; b.removeAttribute('aria-busy'); };
    return p.then(function (x) { end(); return x; }, function (e) { end(); throw e; });
  }
  function field(label, input, hint, opts) {
    var id = input.id || ('f' + Math.random().toString(36).slice(2, 9));
    input.id = id;
    if (opts && opts.required) input.required = true;
    var hintEl = hint ? h('small', { class: 'hint', id: id + '-h', text: hint }) : null;
    if (hintEl) input.setAttribute('aria-describedby', id + '-h');
    return h('div', { class: 'field' }, h('label', { for: id }, label, opts && opts.required ? h('span', { class: 'req', 'aria-hidden': 'true', text: ' *' }) : null), input, hintEl);
  }
  function select(options, value, attrs) {
    var s = h('select', attrs || {});
    options.forEach(function (o) { s.appendChild(h('option', { value: String(o[0]), text: o[1], selected: String(o[0]) === String(value) })); });
    return s;
  }
  function versionSelect(value, attrs, withEmpty) {
    var opts = META.versions.map(function (v) { var st = VERSION_ST[v.status]; return [v.key, v.label + (st ? ' · ' + st[0].toLowerCase() : '')]; });
    if (withEmpty) opts.unshift(['', withEmpty]);
    return select(opts, value, attrs);
  }
  function companySelect(value, withAll, attrs) {
    var opts = META.companies.map(function (c) { return [c.code, coLabel(c.code)]; });
    if (withAll) opts.unshift(['', withAll]);
    return select(opts, value, attrs);
  }
  /** Revisa obligatorios dentro de root; marca los campos y lleva el foco al primero. */
  function validate(root) {
    var first = null;
    Array.prototype.forEach.call(root.querySelectorAll('small.err'), function (e) { e.remove(); });
    Array.prototype.forEach.call(root.querySelectorAll('input, select, textarea'), function (el) {
      el.removeAttribute('aria-invalid');
      if (el.disabled || el.type === 'hidden' || el.type === 'file' || el.type === 'checkbox') return;
      var msg = '';
      if (el.required && !String(el.value).trim()) msg = 'Este campo es obligatorio.';
      else if (el.type === 'number' && el.value !== '' && el.validity && !el.validity.valid) msg = 'Revisa el número' + (el.min !== '' ? ' (mínimo ' + el.min + (el.max !== '' ? ', máximo ' + el.max : '') + ')' : '') + '.';
      else if (el._check) msg = el._check(el.value) || '';
      if (!msg) return;
      el.setAttribute('aria-invalid', 'true');
      el.insertAdjacentElement('afterend', h('small', { class: 'err', text: msg }));
      first = first || el;
    });
    if (first) { first.focus(); toast('Revisa los campos marcados.', 'bad'); return false; }
    return true;
  }

  /* Avisos al centro con X para cerrar. Los normales se van solos; los de error se quedan. */
  var toastBox = null;
  function toast(msg, kind) {
    if (!toastBox) toastBox = h('div', { class: 'toasts', 'aria-live': 'polite' });
    var host = document.querySelector('dialog[open]') || document.body;
    if (toastBox.parentNode !== host) host.appendChild(toastBox);
    var bad = kind === 'bad';
    var t = h('div', { class: 'toast ' + (kind || ''), role: bad ? 'alert' : 'status' }, h('span', { text: msg }));
    var gone = false;
    var close = function () { if (gone) return; gone = true; t.classList.add('out'); setTimeout(function () { t.remove(); }, 300); };
    add(t, h('button', { type: 'button', class: 'toast-x', 'aria-label': 'Cerrar aviso', title: 'Cerrar', text: '×', onclick: close }));
    toastBox.appendChild(t);
    if (!bad) setTimeout(close, 4500);
  }
  function fail(e) { toast((e && e.message) || 'Algo salió mal. Inténtalo de nuevo.', 'bad'); }

  function modal(title, body, actions, wide) {
    var hid = 'm' + Math.random().toString(36).slice(2, 8);
    var d = h('dialog', { class: 'modal' + (wide ? ' wide' : ''), 'aria-labelledby': hid },
      h('form', { method: 'dialog', class: 'modal-in', onsubmit: function (e) { e.preventDefault(); } },
        h('div', { class: 'modal-h' }, h('h2', { id: hid, text: title }), h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Cerrar ventana', title: 'Cerrar', text: '×', style: { fontSize: '22px' }, onclick: function () { d.close(); } })),
        h('div', { class: 'modal-b' }, body),
        actions ? h('div', { class: 'modal-f' }, btn('Cancelar', function () { d.close(); }, 'ghost'), actions) : null));
    d.addEventListener('close', function () { d.remove(); });
    document.body.appendChild(d);
    if (d.showModal) d.showModal(); else d.setAttribute('open', '');
    var first = d.querySelector('.modal-b input:not([type=hidden]):not([disabled]), .modal-b select, .modal-b textarea');
    if (first) first.focus();
    return d;
  }
  /** Confirmación que exige escribir una palabra exacta (p. ej. BORRAR). */
  function confirmType(title, text, word, actionLabel, call) {
    var inp = h('input', { autocomplete: 'off', spellcheck: 'false', placeholder: word });
    var go = btn(actionLabel, function (e) { busy(e.currentTarget, call(inp.value)).then(function () { d.close(); }, fail); }, 'danger', { disabled: true });
    var d = modal(title, [h('div', { class: 'note bad' }, icon('alert'), h('div', {}, h('p', { text: text }))), field('Escribe «' + word + '» para confirmar', inp)], go);
    inp.oninput = function () { go.disabled = inp.value.trim() !== word; };
    return d;
  }
  function confirmBox(title, text, actionLabel, call, kind) {
    var go = btn(actionLabel, function (e) { busy(e.currentTarget, call()).then(function () { d.close(); }, fail); }, kind || 'primary');
    var d = modal(title, typeof text === 'string' ? h('p', { text: text }) : text, go);
    return d;
  }
  function skel(n) {
    var box = h('div', { class: 'stack', 'aria-busy': 'true', 'aria-label': 'Cargando' });
    for (var i = 0; i < (n || 2); i++) add(box, h('div', { class: 'skel-card', 'aria-hidden': 'true' }, h('span', { class: 'skel title' }), h('span', { class: 'skel line' }), h('span', { class: 'skel line' }), h('span', { class: 'skel line' })));
    return box;
  }
  function ready(el) { clear(el); el.removeAttribute('aria-busy'); el.removeAttribute('aria-label'); return el; }
  /** Error al cargar: mensaje en español y botón para reintentar. */
  function errorState(el, e, retry) {
    ready(el);
    var expired = e && e.status === 401;
    add(el, h('div', { class: 'error-state', role: 'alert' },
      h('b', { text: expired ? 'Tu sesión expiró' : 'No se pudo cargar esta información' }),
      h('p', { text: (e && e.message) || NET_ERR }),
      expired ? btn('Volver a entrar', function () { location.reload(); }, 'primary') : retry ? btn('Reintentar', retry, 'primary', {}, 'refresh') : null));
  }
  function empty(title, text, extra) { return h('div', { class: 'empty' }, icon('info', 26), h('b', { text: title }), text ? h('p', { text: text }) : null, extra || null); }
  function note(kind, ico, title, text, extra) {
    return h('div', { class: 'note ' + kind, role: kind === 'bad' ? 'alert' : null }, icon(ico), h('div', {}, title ? h('b', { text: title }) : null, text ? h('p', { text: text }) : null, extra || null));
  }
  function kpi(label, value, kind, sub) { return h('div', { class: 'kpi ' + (kind || '') }, h('small', { text: label }), h('b', { text: value }), sub ? h('span', { text: sub }) : null); }
  function card(title, sub, right) {
    var c = h('section', { class: 'card' });
    if (title) add(c, h('div', { class: 'card-h' }, h('div', {}, h('h2', { text: title }), sub ? h('small', { text: sub }) : null), right || null));
    return c;
  }
  /** Tabla. heads: [[texto, clase]]; rows: <tr>. Con opts.cards se vuelve tarjetas en móvil. */
  function table(heads, rows, opts) {
    opts = opts || {};
    var t = h('table', { class: (opts.cards ? 'cards ' : '') + (opts.sticky ? 'sticky1 ' : '') + (opts.cls || '') },
      opts.caption ? h('caption', { class: 'sr-only', text: opts.caption }) : null,
      h('thead', {}, h('tr', {}, heads.map(function (x) { return h('th', { scope: 'col', class: x[1] || null, text: x[0] }); }))),
      h('tbody', {}, rows));
    Array.prototype.forEach.call(t.tBodies[0].rows, function (tr) {
      if (tr.classList.contains('grp')) return;
      Array.prototype.forEach.call(tr.cells, function (c, i) { if (!c.hasAttribute('data-label') && heads[i]) c.setAttribute('data-label', heads[i][0]); });
    });
    return h('div', { class: 'tablewrap' }, t);
  }
  function td(c, klass) { return h('td', { class: klass || null }, c); }
  function tdn(text, extra) { return h('td', { class: 'num ' + (extra || '') }, text); }
  /** Cifra con botón «origen»: abre la traza hasta el archivo, hoja, celda y mes. */
  function traceBtn(text, label, onclick) {
    return h('button', { type: 'button', class: 'num-btn', 'aria-label': text + '. Ver origen: ' + label, title: 'Ver origen', onclick: onclick }, h('span', { text: text }), icon('trace'));
  }
  function seg(options, value, onchange, label) {
    var box = h('div', { class: 'seg', role: 'group', 'aria-label': label });
    options.forEach(function (o) {
      add(box, h('button', { type: 'button', 'aria-pressed': String(String(o[0]) === String(value)), text: o[1], onclick: function () {
        Array.prototype.forEach.call(box.children, function (b) { b.setAttribute('aria-pressed', 'false'); });
        this.setAttribute('aria-pressed', 'true'); onchange(o[0]);
      } }));
    });
    return box;
  }
  function header(eyebrow, title, desc, right) {
    return h('header', { class: 'ph' },
      h('div', { class: 'ph-t' }, eyebrow ? h('span', { class: 'eyebrow', text: eyebrow }) : null, h('h1', { text: title, tabindex: '-1' }), desc ? h('p', { text: desc }) : null),
      right ? h('div', { class: 'acts' }, right) : null);
  }
  /** Paginación simple en el navegador: muestra de a n filas con «Mostrar más». */
  function paged(list, n, render) {
    var box = h('div', { class: 'stack tight' }), shown = 0, holder = h('div');
    var more = btn('', function () { draw(); }, 'ghost small');
    function draw() {
      shown = Math.min(list.length, shown + n);
      clear(holder).appendChild(render(list.slice(0, shown)));
      more.hidden = shown >= list.length;
      more.lastChild.textContent = 'Mostrar más (' + num(list.length - shown) + ' restantes)';
    }
    add(box, [holder, more]);
    draw();
    return box;
  }
  function noAccess(m) {
    add(m, header(null, 'Sin acceso'));
    add(m, empty('Esta pantalla no está disponible para tu usuario.', 'Si la necesitas, pide acceso al equipo corporativo.', linkBtn('Ir al inicio', '#/inicio', 'primary')));
  }

  /* =====================================================================
     Marco: barra de pasos (menú principal), versión de trabajo y menú de cuenta y ajustes (⚙)
     Flujo: 1 Cargar · 2 Revisar · 3 Consolidar · 4 Resultado. País ve 1–2; directiva solo 4; corporativo y admin los 4.
     ===================================================================== */
  var main = null, flowNav = null, versionSel = null, gearBtn = null, gearMenu = null, nav = null;
  var STEP_DEFS = [['cargar', '1', 'Cargar'], ['revisar', '2', 'Revisar'], ['consolidar', '3', 'Consolidar'], ['resultado', '4', 'Resultado']];
  var ACTIVE_STEP = null, STEP_STATE = null;
  var TAB_HOST = null;          // con una pestaña del Resultado activa, page() pinta dentro de ella
  var RESULT_BASE = {};         // base de comparación elegida por versión (en memoria)
  var DASH_CACHE = null;        // {k, p} datos del tablero de la última versión/base
  function stepKeys() {
    var c = caps(), k = [];
    if (c.corp) return ['cargar', 'revisar', 'consolidar', 'resultado'];
    if (c.country) k.push('cargar', 'revisar');
    if (c.board) k.push('resultado');
    return k;
  }
  function gearItems() {
    var c = caps(), out = [];
    if (c.corp) out.push(['reglas', 'Reglas y tasas', 'percent'], ['perfiles', 'Perfiles de lectura', 'sliders'], ['bitacora', 'Bitácora', 'log']);
    if (c.corp || c.admin) out.push(['datos', 'Datos y demo', 'database']);
    return out;
  }
  function setGear(open) {
    if (!gearMenu) return;
    gearMenu.hidden = !open; gearBtn.setAttribute('aria-expanded', String(open));
    if (open) { var f = gearMenu.querySelector('a'); if (f) f.focus(); }
  }
  /** Menú lateral: pocas opciones. Inicio = resultado del grupo; Proceso = flujo de cada país; Análisis = detalle. */
  function navGroups() {
    var c = caps(), cb = c.corp || c.board;
    return [
      [null, [['inicio', cb ? 'Inicio · resultado del grupo' : 'Inicio', 'home', true],
        ['proceso', 'Proceso por país', 'upload', c.country || c.corp],
        ['analisis', 'Análisis', 'chart', cb]]],
      ['Configuración', [['reglas', 'Reglas y tasas', 'percent', c.corp], ['perfiles', 'Perfiles de lectura', 'sliders', c.corp],
        ['bitacora', 'Bitácora', 'log', c.corp], ['datos', 'Datos y demo', 'database', c.corp || c.admin]]]
    ];
  }
  function setNav(open) { app.classList.toggle('nav-open', open); var b = app.querySelector('.menu-btn'); if (b) b.setAttribute('aria-expanded', String(open)); }
  function buildShell() {
    clear(app);
    app.classList.remove('flow');
    nav = h('nav', { class: 'side', id: 'csl-nav', 'aria-label': 'Secciones' },
      h('a', { class: 'brand', href: '#/inicio', 'aria-label': 'Trazo, ir al inicio' }, h('span', { class: 'brand-mark', 'aria-hidden': 'true', text: 'T' }), h('div', {}, h('b', { text: 'Trazo' }), h('small', { text: 'Consolidación del grupo · Grupo Meridia' }))));
    navGroups().forEach(function (g) {
      var items = g[1].filter(function (it) { return it[3]; });
      if (!items.length) return;
      if (g[0]) add(nav, h('div', { class: 'sec', text: g[0] }));
      items.forEach(function (it) { add(nav, h('a', { href: '#/' + it[0], 'data-k': it[0] }, icon(it[2]), h('span', { text: it[1] }))); });
    });
    var roles = [], c = caps();
    if (c.admin) roles.push('Administrador'); else { if (c.corp) roles.push('Corporativo'); if (c.board && !c.corp) roles.push('Directiva'); if (c.country) roles.push('País'); }
    add(nav, h('div', { class: 'me' }, h('b', { text: (ME && ME.name) || (C.user && C.user.name) || '' }), h('small', { text: roles.join(' · ') }),
      C.logout ? h('a', { href: C.logout }, icon('logout'), h('span', { text: 'Cerrar sesión' })) : null));
    if (C.version) add(nav, h('div', { class: 'ver', text: 'Trazo ' + C.version }));
    nav.addEventListener('click', function (e) { if (e.target.closest('a')) setNav(false); });
    versionSel = h('select', { id: 'csl-version', onchange: function () {
      if (DIRTY && !confirm('Tienes cambios sin guardar. ¿Cambiar de versión sin guardar?')) { versionSel.value = VERSION || ''; return; }
      DIRTY = false; setVersion(versionSel.value); DASH_CACHE = null; route();
    } });
    fillVersionSel();
    var top = h('div', { class: 'top', role: 'banner' },
      h('button', { type: 'button', class: 'btn ghost small menu-btn', 'aria-controls': 'csl-nav', 'aria-expanded': 'false', onclick: function () { setNav(!app.classList.contains('nav-open')); } }, icon('menu'), h('span', { text: 'Menú' })),
      h('div', { class: 'cyc' }, h('label', { for: 'csl-version', text: 'Versión de trabajo' }), versionSel),
      h('span', { class: 'grow' }));
    main = h('main', { class: 'main', id: 'csl-main', tabindex: '-1' });
    add(app, [nav, h('div', { class: 'scrim', onclick: function () { setNav(false); } }), h('div', { class: 'wrap' }, top, main)]);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && app.classList.contains('nav-open')) setNav(false); });
  }
  function fillVersionSel() {
    if (!versionSel) return;
    clear(versionSel);
    if (!META.versions.length) add(versionSel, h('option', { value: '', text: 'Sin versiones' }));
    META.versions.forEach(function (v) {
      var st = VERSION_ST[v.status];
      add(versionSel, h('option', { value: v.key, text: v.label + (st ? ' · ' + st[0].toLowerCase() : ''), selected: v.key === VERSION }));
    });
  }
  function pickVersion() {
    var saved = null;
    try { saved = localStorage.getItem(LS_VERSION); } catch (e) { saved = null; }
    if (saved && versionByKey(saved)) { VERSION = saved; return; }
    var open = META.versions.filter(function (v) { return v.status === 'abierto'; });
    VERSION = (open[0] || META.versions[0] || {}).key || null;
  }
  function setVersion(key) {
    VERSION = key && versionByKey(key) ? key : null;
    try { localStorage.setItem(LS_VERSION, VERSION || ''); } catch (e) { /* sin almacenamiento */ }
    if (versionSel) versionSel.value = VERSION || '';
    refreshSteps();
  }
  function reloadMeta() {
    DASH_CACHE = null;
    return api('/meta').then(function (m) { META = normMeta(m); if (!versionByKey(VERSION)) pickVersion(); fillVersionSel(); refreshSteps(); return META; });
  }
  /** Estado de cada paso para la versión de trabajo (desde /home). */
  function stepStatus(k, H) {
    var cos = arr(H && H.companies), n = cos.length;
    var up = cos.filter(function (x) { return x.upload; }).length;
    var ok = cos.filter(function (x) { return x.upload && ['validado', 'aprobado', 'enviado'].indexOf(x.upload.status) >= 0; }).length;
    var sent = cos.filter(function (x) { return x.upload && x.upload.status === 'enviado'; }).length;
    var crit = cos.reduce(function (s, x) { return s + (x.upload ? Number(x.upload.criticals) || 0 : 0); }, 0);
    var res = H && H.lastResult;
    var pais = n === 1 ? 'país' : 'países';
    if (k === 'cargar') return [n === 1 ? (up ? 'Archivo cargado' : 'Sin archivo') : num(up) + ' de ' + num(n) + ' cargados', n && up === n ? 'done' : up ? 'doing' : 'todo'];
    if (k === 'revisar') return [n === 1 ? (sent ? 'Enviado' : crit ? num(crit) + ' críticos por corregir' : ok ? 'Listo para enviar' : up ? 'Con avisos' : 'Pendiente') : num(ok) + ' de ' + num(n) + ' ' + pais + ' listos' + (crit ? ' · ' + num(crit) + ' críticos' : ''), n && sent === n ? 'done' : up ? 'doing' : 'todo'];
    if (k === 'consolidar') return [res ? 'Consolidado ' + dt(res.created_at).split(',')[0] : n && sent === n ? 'Listo para consolidar' : num(sent) + ' de ' + num(n) + ' enviados', res ? 'done' : n && sent === n ? 'doing' : 'todo'];
    return [res ? 'Disponible' : 'Sin consolidar', res ? 'done' : 'todo'];
  }
  function drawSteps() {
    if (!flowNav) return;
    clear(flowNav);
    var keys = stepKeys();
    if (keys.length === 1) {
      add(flowNav, h('a', { class: 'flow-solo' + (ACTIVE_STEP === keys[0] ? ' on' : ''), href: '#/' + keys[0], 'aria-current': ACTIVE_STEP === keys[0] ? 'page' : null }, icon('doc', 16), h('span', { text: 'Resultado' })));
      return;
    }
    add(flowNav, h('ol', { class: 'flowsteps n' + keys.length }, keys.map(function (k) {
      var d = STEP_DEFS.filter(function (x) { return x[0] === k; })[0], st = STEP_STATE ? stepStatus(k, STEP_STATE) : ['', 'todo'], on = ACTIVE_STEP === k;
      return h('li', {}, h('a', { href: '#/' + k, class: st[1] + (on ? ' on' : ''), 'aria-current': on ? 'step' : null, 'aria-label': 'Paso ' + d[1] + ': ' + d[2] + (st[0] ? '. ' + st[0] : '') },
        h('span', { class: 'n', 'aria-hidden': 'true' }, st[1] === 'done' && !on ? icon('check', 14) : d[1]),
        h('span', { class: 'txt', 'aria-hidden': 'true' }, h('b', { text: d[2] }), h('small', { text: st[0] }))));
    })));
  }
  function refreshSteps() {
    drawSteps();
    if (!VERSION || !flowNav) return;
    var v = VERSION;
    api('/home' + qs({ version: v })).then(function (H) { if (v === VERSION) { STEP_STATE = H; drawSteps(); } }, function () { /* la barra queda sin estado */ });
  }
  function refreshScore() { refreshSteps(); }
  var TITLES = { inicio: 'Inicio', proceso: 'Proceso por país', analisis: 'Análisis', cargar: 'Cargar', revisar: 'Revisar', consolidar: 'Consolidar', resultado: 'Resultado', reglas: 'Reglas y tasas', perfiles: 'Perfiles de lectura', bitacora: 'Bitácora', datos: 'Datos y demo' };
  var STEP_OF = {};
  var NAV_OF = { cargar: 'proceso', revisar: 'proceso', proceso: 'proceso', consolidar: 'analisis', resultado: 'inicio', analisis: 'analisis' };
  function page(key, navKey) {
    DIRTY = false;
    if (TAB_HOST && document.body.contains(TAB_HOST)) { clear(TAB_HOST); return TAB_HOST; }
    ACTIVE_STEP = STEP_OF[navKey || key] || null;
    drawSteps();
    var navK = NAV_OF[navKey || key] || key;
    if (nav) Array.prototype.forEach.call(nav.querySelectorAll('a[data-k]'), function (a) { var on = a.getAttribute('data-k') === navK; a.classList.toggle('on', on); if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
    document.title = (TITLES[key] || 'Trazo') + ' · Trazo';
    clear(main);
    return main;
  }
  function watchDirty(root) { root.addEventListener('input', function (e) { if (!e.target.closest('[data-nodirty]')) DIRTY = true; }); }
  window.addEventListener('beforeunload', function (e) { if (DIRTY) { e.preventDefault(); e.returnValue = ''; } });
  function noVersion(m, title) {
    add(m, header(null, title));
    add(m, empty('Aún no hay versiones de presupuesto.', caps().corp ? 'Importa el archivo corporativo en Ajustes › Datos y demo.' : 'El equipo corporativo debe importar los datos.', caps().corp || caps().admin ? linkBtn('Ir a Datos y demo', '#/datos', 'primary') : null));
  }
  /** Dentro de una pestaña, los h1 de las vistas reutilizadas pasan a h2 (un solo h1 por página). */
  function demoteHeadings(host) {
    var fix = function () { Array.prototype.forEach.call(host.querySelectorAll('h1'), function (x) { var n = h('h2', { class: 'ph-h1', tabindex: x.getAttribute('tabindex') }); n.textContent = x.textContent; x.replaceWith(n); }); };
    fix();
    if (window.MutationObserver) new MutationObserver(fix).observe(host, { childList: true, subtree: true });
  }

  /* =====================================================================
     Inicio: lleva a la ruta más útil según el rol y el avance de la versión
     ===================================================================== */
  function goHome() {
    var c = caps();
    if (!c.corp && !c.board) { location.replace('#/proceso'); return; }
    var m = page('inicio');
    if (!VERSION) { noVersion(m, 'Inicio'); return; }
    var key = VERSION, chosen = RESULT_BASE[key];
    var base = chosen === '-' ? null : chosen && chosen !== key && versionByKey(chosen) ? chosen : baseFor(key);
    add(m, header(versionLabel(key), 'Resultado del grupo', 'Los 5 países unidos como un solo corporativo, en US$: si el grupo crea valor, qué sede suma o resta y qué decidir.'));
    var top = skel(3), board = h('div', { class: 'stack' });
    add(m, [top, board]);
    var load = function () {
      loadDash(key, base).then(function (D) {
        ready(top);
        if (!D.H.hasCorporate || !META.versions.length) { add(top, homeNoData()); return; }
        if (!D.H.lastResult) {
          add(top, empty('Aún no se consolida ' + versionLabel(key) + '.', 'Carga y revisa el Excel de cada país y luego consolida.', linkBtn('Ir al proceso por país', '#/proceso', 'primary', 'arrowRight')));
          return;
        }
        renderDashboard(top, D, 'top');
        renderDashboard(board, D, 'board');
      }, function (e) { errorState(top, e, function () { DASH_CACHE = null; load(); }); });
    };
    load();
  }

  /* =====================================================================
     Paso 1 · Cargar: una tarjeta por país; al elegirlo, subir su Excel y ver lo que leyó el sistema
     ===================================================================== */
  var LOAD_ST = { rechazado: ['Con errores', 'bad'], con_avisos: ['Con avisos', 'warn'], validado: ['Listo', 'ok'], aprobado: ['Listo, falta enviar', 'info'], enviado: ['Enviado', 'ok'] };
  function loadTag(u) { if (!u) return tag('Sin cargar', 'neutral'); var s = LOAD_ST[u.status] || [u.status, 'neutral']; return tag(s[0], s[1]); }
  function resolveCountry(code) {
    var mine = myCompanies(), co = code ? companyByCode(String(code).toUpperCase()) : null;
    if (co && !mine.some(function (x) { return x.code === co.code; })) return { denied: true };
    if (!co && mine.length === 1 && !caps().corp) co = mine[0];
    return { co: co, many: mine.length > 1 };
  }
  function viewLoad(code) {
    var m = page('cargar');
    if (!VERSION) { noVersion(m, 'Cargar'); return; }
    var r0 = resolveCountry(code), co = r0.co;
    if (r0.denied) { add(m, header('Paso 1', 'Cargar')); add(m, empty('No tienes acceso a ese país.', 'Elige uno de tus países.', linkBtn('Ver mis países', '#/proceso', 'primary'))); return; }
    add(m, header('Paso 1 de ' + stepKeys().length + ' · ' + versionLabel(VERSION), co ? 'Cargar · ' + coLabel(co.code) : 'Cargar',
      co ? 'Sube el Excel de ' + (co.country || co.name) + ' tal como lo prepara su equipo. Al terminar verás lo que el sistema leyó antes de revisar los errores.'
        : 'Elige un país para subir su Excel. Cada país manda su archivo en su propio formato; el sistema lo lee con el perfil de lectura de ese país.',
      co && r0.many ? linkBtn('Todos los países', '#/proceso', 'ghost small') : null));
    var body = skel(2); add(m, body);
    var load = function () {
      api('/home' + qs({ version: VERSION })).then(function (H) {
        ready(body);
        H = H || {};
        STEP_STATE = H; drawSteps();
        if (!H.hasCorporate) { add(body, homeNoData()); return; }
        var list = arr(H.companies);
        if (!co) { loadGrid(body, list); return; }
        var cur = list.filter(function (x) { return x.code === co.code; })[0] || {};
        loadOne(body, co, cur.upload, load);
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }
  function loadGrid(body, list) {
    if (!list.some(function (x) { return x.upload; })) {
      var first = companyByCode('GT') || META.companies[0];
      if (first) add(body, h('section', { class: 'card accent start-card' },
        h('div', { class: 'card-h' }, h('div', {}, h('h2', { text: 'Empezar con ' + (first.country || first.name) }), h('small', { text: 'Aún no hay archivos en ' + versionLabel(VERSION) }))),
        h('p', { text: 'Sube el Excel de ' + (first.country || first.name) + ' y mira cómo el sistema lo lee, encuentra los errores y lo deja listo para consolidar. Después repite con los demás países.' }),
        h('div', { class: 'acts' }, linkBtn('Empezar con ' + (first.country || first.name), '#/proceso/' + first.code, 'primary', 'upload'))));
    }
    var cd = card('Países', versionLabel(VERSION) + ' · toca un país para subir o ver su archivo');
    if (!list.length) add(cd, empty('No hay países.', 'Importa el archivo corporativo en Ajustes › Datos y demo.'));
    add(cd, h('div', { class: 'cos' }, list.map(function (co) {
      var u = co.upload, st = u ? (LOAD_ST[u.status] || [u.status, 'neutral']) : ['Sin cargar', 'neutral'];
      return h('article', { class: 'co ' + st[1], 'aria-label': (co.country || co.name) + ': ' + st[0] },
        h('div', { class: 'co-h' }, h('div', {}, h('b', { text: coLabel(co.code) }), h('small', { text: [co.name, co.currency].filter(Boolean).join(' · ') })), loadTag(u)),
        u ? h('div', { class: 'co-n' }, h('span', {}, h('b', { class: Number(u.criticals) ? 'c-bad' : '', text: num(u.criticals) }), ' críticos'), h('span', {}, h('b', { class: Number(u.warnings) ? 'c-warn' : '', text: num(u.warnings) }), ' avisos'), h('span', { text: 'Subido ' + dt(u.uploaded_at) }))
          : h('p', { class: 'muted small', text: 'Aún no hay archivo de esta versión.' }),
        h('div', { class: 'acts' }, linkBtn(u ? 'Ver lo leído' : 'Cargar Excel', '#/proceso/' + co.code, u ? 'ghost small' : 'primary small', u ? null : 'upload'), u ? linkBtn('Revisar', '#/proceso/' + co.code, 'small') : null));
    })));
    add(body, cd);
    if (list.length && list.every(function (x) { return x.upload; })) add(body, h('div', { class: 'acts next-bar' }, linkBtn('Siguiente: revisar errores', '#/proceso', 'primary', 'arrowRight')));
  }
  function loadOne(body, co, u, reload) {
    var canUp = caps().corp || ownsCompany(co.id);
    var readBox = h('div', { class: 'stack' });
    var maxMb = META.maxUploadMb;
    var input = h('input', { type: 'file', id: 'csl-file', accept: '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'aria-describedby': 'csl-drop-h' });
    var drop = h('div', { class: 'drop' }, icon('upload'), h('b', { id: 'csl-drop-t', text: u ? 'Subir una versión corregida (reemplaza a la anterior)' : 'Arrastra aquí el Excel o haz clic para elegirlo' }),
      h('small', { id: 'csl-drop-h', text: 'Archivo .xlsx de hasta ' + maxMb + ' MB, con las hojas de siempre (portada, resultados, balance, intercompañía, ventas_detalle…).' }), input);
    input.setAttribute('aria-labelledby', 'csl-drop-t');
    var bar = h('i'), ptxt = h('small', { text: '' });
    var track = h('div', { class: 'track', role: 'progressbar', 'aria-label': 'Avance de la subida', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': '0' }, bar);
    var prog = h('div', { class: 'progress', hidden: true }, track, ptxt);
    var setProg = function (p, text, indet) { prog.hidden = false; prog.classList.toggle('indet', !!indet); bar.style.width = Math.round(p * 100) + '%'; track.setAttribute('aria-valuenow', String(Math.round(p * 100))); ptxt.textContent = text; };
    var send = function (file) {
      if (!file) return;
      if (!/\.xlsx$/i.test(file.name)) { toast('El archivo debe ser .xlsx. Si tienes .xls o .csv, ábrelo en Excel y guárdalo como «Libro de Excel (.xlsx)».', 'bad'); input.value = ''; return; }
      if (file.size > maxMb * 1048576) { toast('El archivo pesa más de ' + maxMb + ' MB.', 'bad'); input.value = ''; return; }
      var fd = new FormData();
      fd.append('file', file, file.name); fd.append('company_id', String(co.id)); fd.append('version', VERSION);
      drop.classList.add('busy');
      setProg(0, 'Subiendo ' + file.name + '… 0 %');
      clear(readBox);
      uploadFile('/uploads', fd, function (p) {
        if (p < 1) setProg(p, 'Subiendo ' + file.name + '… ' + Math.round(p * 100) + ' %');
        else setProg(1, 'Archivo recibido. Leyendo con el perfil de ' + (co.country || co.code) + ' y validando…', true);
      }).then(function (r) {
        prog.hidden = true; drop.classList.remove('busy'); input.value = '';
        var nu = r.upload || {}, iss = arr(r.issues), crit = iss.filter(function (i) { return i.severity === 'critico'; }).length;
        toast(crit ? 'Archivo leído: ' + crit + ' error(es) crítico(s) por corregir.' : iss.length ? 'Archivo leído: sin errores críticos, ' + iss.length + ' aviso(s).' : 'Archivo leído: sin hallazgos.', crit ? 'bad' : 'ok');
        refreshSteps();
        if (nu.id) showRead(readBox, nu.id, co); else reload();
      }, function (e) { prog.hidden = true; drop.classList.remove('busy'); input.value = ''; fail(e); });
    };
    input.addEventListener('change', function () { send(input.files && input.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
    ['dragleave', 'dragend'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('over'); }); });
    drop.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('over'); send(e.dataTransfer && e.dataTransfer.files[0]); });
    var up = card('Archivo de ' + (co.country || co.code), u ? 'Último archivo: ' + dt(u.uploaded_at) + ' · ' + (LOAD_ST[u.status] || [u.status])[0] : 'Aún no hay archivo de ' + versionLabel(VERSION), loadTag(u));
    if (canUp) add(up, [drop, prog]);
    else add(up, note('info', 'info', null, 'Solo el responsable del país o el corporativo pueden subir este archivo.'));
    add(body, up);
    add(body, readBox);
    if (u) showRead(readBox, u.id, co);
  }
  /** «Lo que leyó el sistema»: el proceso paso a paso con hojas, meses, cuentas, escala, ingresos y EBIT. */
  function showRead(box, id, co) {
    clear(box).appendChild(skel(1));
    var load = function () {
      api('/uploads/' + id).then(function (r) { ready(box); add(box, readCard(r, co)); }, function (e) { errorState(box, e, load); });
    };
    load();
  }
  // CONTRATO: datasetSummary.stats {currency, months[], accounts, sheets[], revenue_local, ebit_local, revenue_usd, ebit_usd} lo agrega Data::upload_get (paso 1).
  function readCard(r, co) {
    var u = r.upload || {}, s = r.datasetSummary || r.workbookSummary || {}, st = obj(s.stats), meta = obj(s.meta), rows = obj(s.rows), iss = arr(r.issues);
    var cur = st.currency || meta.currency || co.currency;
    var crit = iss.filter(function (i) { return i.severity === 'critico'; }).length, warn = iss.length - crit;
    var months = arr(st.months), sheetsRead = Object.keys(rows).filter(function (k) { return Number(rows[k]) > 0; });
    var scale = Number(meta.scale_applied) || 1;
    var cd = card('Lo que leyó el sistema', 'Archivo «' + (u.file_name || '') + '» · subido el ' + dt(u.uploaded_at) + (u.uploader ? ' por ' + u.uploader : ''));
    var stepsList = [
      ['Abrió el archivo y reconoció ' + num(sheetsRead.length) + ' hojas con datos', sheetsRead.map(function (k) { return (ROWS_LBL[k] || k) + ' (' + num(rows[k]) + ')'; }).join(' · ')],
      [months.length ? 'Leyó ' + num(months.length) + (months.length === 1 ? ' mes' : ' meses') + ' (' + MON3[months[0]] + '–' + MON3[months[months.length - 1]] + ') y ' + num(st.accounts) + ' cuentas del catálogo del grupo' : 'Leyó las cuentas del archivo', meta.scenario ? 'Escenario de la portada: ' + meta.scenario : null],
      [scale > 1 ? 'Aplicó la escala: los montos venían en miles y se multiplicaron por ' + num(scale) : 'Escala: los montos vienen en unidades (sin cambio)', meta.scale_declared ? 'La portada dice «' + meta.scale_declared + '»' + (meta.scale_expected ? '; el país usa «' + meta.scale_expected + '»' : '') : null],
      [cur && cur !== 'USD' ? 'Convirtió a US$ con la tasa oficial promedio de cada mes (' + cur + ' por dólar)' : 'La moneda es US$: no hace falta convertir', null],
      ['Revisó las reglas: ' + num(crit) + (crit === 1 ? ' error crítico' : ' errores críticos') + ' y ' + num(warn) + (warn === 1 ? ' aviso' : ' avisos'), crit ? 'Los críticos impiden enviar; se corrigen en el Excel.' : warn ? 'Los avisos se explican en el paso 2.' : 'Sin hallazgos.']
    ];
    add(cd, h('ol', { class: 'readsteps' }, stepsList.map(function (x, i) { return h('li', {}, h('span', { class: 'n', 'aria-hidden': 'true', text: String(i + 1) }), h('div', {}, h('b', { text: x[0] }), x[1] ? h('small', { text: x[1] }) : null)); })));
    if (isNum(st.revenue_local)) {
      var mg = Number(st.revenue_local) ? Number(st.ebit_local) / Number(st.revenue_local) : null;
      add(cd, h('div', { class: 'kpis' }, [
        kpi('Ingresos del año', loc(st.revenue_local, cur), null, cur !== 'USD' ? usdM(st.revenue_usd) + ' en US$' : null),
        kpi('EBIT del año', loc(st.ebit_local, cur), null, (cur !== 'USD' ? usdM(st.ebit_usd) + ' en US$ · ' : '') + 'margen ' + pct(mg)),
        kpi('Errores críticos', num(crit), crit ? 'bad' : 'ok', crit ? 'Hay que corregirlos' : 'Ninguno'),
        kpi('Avisos', num(warn), warn ? 'warn' : 'ok', warn ? 'Hay que explicarlos' : 'Ninguno')
      ]));
    }
    var det = h('details', { class: 'more' }, h('summary', { text: 'Ver portada e indicadores reportados' }), datasetCard(s, co.code));
    add(cd, det);
    add(cd, h('div', { class: 'acts' }, btn('Siguiente: revisar errores', function () { var x = document.getElementById('csl-review'); if (x) x.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 'primary', {}, 'arrowRight'),
      (caps().corp || ownsCompany(co.id)) ? btn('Descargar el archivo subido', function (e) { download('/uploads/' + u.id + '/file', u.file_name || 'original.xlsx', e.currentTarget); }, 'ghost', {}, 'download') : null));
    return cd;
  }

  /* =====================================================================
     Paso 2 · Revisar: hallazgos de un país en palabras simples, explicar avisos, corregir o aprobar y enviar
     ===================================================================== */
  function viewReview(code) {
    var m = page('revisar');
    if (!VERSION) { noVersion(m, 'Revisar'); return; }
    var r0 = resolveCountry(code), co = r0.co;
    if (r0.denied) { add(m, header('Paso 2', 'Revisar')); add(m, empty('No tienes acceso a ese país.', 'Elige uno de tus países.', linkBtn('Ver mis países', '#/proceso', 'primary'))); return; }
    if (!co) { reviewOverview(m); return; }
    add(m, header('Paso 2 de ' + stepKeys().length + ' · ' + versionLabel(VERSION), 'Revisar · ' + coLabel(co.code), 'Lo que el sistema encontró en el archivo de ' + (co.country || co.name) + ', qué hay que hacer con cada cosa y el envío al corporativo.',
      r0.many ? linkBtn('Todos los países', '#/proceso', 'ghost small') : null));
    var body = skel(3); add(m, body);
    var load = function () {
      api('/home' + qs({ version: VERSION })).then(function (H) {
        var cur = arr(H && H.companies).filter(function (x) { return x.code === co.code; })[0] || {}, u = cur.upload;
        if (!u) { ready(body); add(body, empty('Aún no hay archivo de ' + (co.country || co.code) + ' en ' + versionLabel(VERSION) + '.', 'Primero súbelo en el paso 1.', linkBtn('Ir a cargar', '#/proceso/' + co.code, 'primary', 'upload'))); return; }
        return Promise.all([api('/uploads/' + u.id), api('/findings' + qs({ version: VERSION, company: co.code })).catch(function () { return null; })]).then(function (r) {
          ready(body);
          reviewOne(body, co, r[0], r[1], load);
        });
      }).catch(function (e) { errorState(body, e, load); });
    };
    load();
  }
  function reviewOverview(m) {
    add(m, header('Paso 2 de ' + stepKeys().length + ' · ' + versionLabel(VERSION), 'Revisar', 'Elige un país para ver sus hallazgos, explicarlos o corregirlos, y enviarlo al corporativo.'));
    var body = skel(2); add(m, body);
    var cb = caps().corp || caps().board;
    var load = function () {
      Promise.all([api('/home' + qs({ version: VERSION })), cb ? api('/findings' + qs({ version: VERSION })).catch(function () { return null; }) : null]).then(function (r) {
        ready(body);
        var list = arr(r[0] && r[0].companies), F = r[1], byCo = F && F.counts ? obj(F.counts.by_company) : {};
        var cd = card('Países', 'Hallazgos de ' + versionLabel(VERSION) + ' (archivo + intercompañía)');
        add(cd, h('div', { class: 'cos' }, list.map(function (co) {
          var u = co.upload, c2 = byCo[co.code] || {}, st = u ? (LOAD_ST[u.status] || [u.status, 'neutral']) : ['Sin cargar', 'neutral'];
          return h('article', { class: 'co ' + st[1] },
            h('div', { class: 'co-h' }, h('div', {}, h('b', { text: coLabel(co.code) }), h('small', { text: co.name || '' })), loadTag(u)),
            u ? h('div', { class: 'co-n' }, h('span', {}, h('b', { class: Number(u.criticals) ? 'c-bad' : '', text: num(u.criticals) }), ' críticos'), h('span', {}, h('b', { class: Number(u.warnings) ? 'c-warn' : '', text: num(u.warnings) }), ' avisos'),
              isNum(c2.total) ? h('span', { text: num(c2.total) + ' en total con intercompañía' }) : null) : h('p', { class: 'muted small', text: 'Sin archivo todavía.' }),
            h('div', { class: 'acts' }, u ? linkBtn('Revisar', '#/proceso/' + co.code, Number(u.criticals) || Number(u.warnings) ? 'primary small' : 'small') : linkBtn('Cargar Excel', '#/proceso/' + co.code, 'ghost small', 'upload')));
        })));
        add(body, cd);
        if (F && F.counts) add(body, findingsHistoryCard(F.counts, arr(F.historyCycles), '', VERSION));
        if (caps().corp && list.length && list.every(function (x) { return x.upload && x.upload.status === 'enviado'; })) add(body, h('div', { class: 'acts next-bar' }, linkBtn('Siguiente: consolidar', '#/analisis/consolidacion', 'primary', 'arrowRight')));
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }
  function reviewOne(body, co, r, F, reload) {
    var u = r.upload || {}, iss = arr(r.issues), canEdit = ownsCompany(u.company_id);
    var ic = arr(F && F.items).filter(function (i) { return i.upload_id === null; });
    var isOpen = function (i) { return (i.status || 'abierta') === 'abierta'; };
    var crit = iss.filter(function (i) { return i.severity === 'critico'; });
    var warns = iss.filter(function (i) { return i.severity === 'aviso'; }), pendW = warns.filter(isOpen);
    var done = function () { refreshSteps(); reload(); };
    add(body, h('div', { class: 'kpis' }, [
      kpi('Errores críticos', num(crit.length), crit.length ? 'bad' : 'ok', crit.length ? 'Se corrigen en el Excel' : 'Ninguno'),
      kpi('Avisos por explicar', num(pendW.length), pendW.length ? 'warn' : 'ok', 'De ' + num(warns.length) + ' aviso(s)'),
      kpi('Con otros países', num(ic.length), ic.length ? 'warn' : 'ok', ic.length ? 'Diferencias de intercompañía' : 'Todo cuadra'),
      kpi('Estado', (LOAD_ST[u.status] || [u.status || '—'])[0], (LOAD_ST[u.status] || [0, ''])[1], 'Archivo «' + (u.file_name || '') + '»')
    ]));
    var groups = [
      ['Hay que corregir en el Excel', crit, 'Estos errores impiden enviar. Corrige el archivo y vuelve a subirlo.'],
      ['Hay que explicar', pendW, 'Un aviso no impide enviar, pero el corporativo necesita saber por qué el dato es correcto.'],
      ['Ya explicados', warns.filter(function (i) { return !isOpen(i); }), null],
      ['Con otros países (intercompañía)', ic, 'Lo que este país reporta frente a lo que reporta el otro. Se resuelve entre los dos; el corporativo lo concilia al consolidar.']
    ];
    var any = false;
    groups.forEach(function (g) {
      if (!g[1].length) return;
      any = true;
      var cd = card(g[0] + ' (' + g[1].length + ')', g[2]);
      add(cd, paged(g[1].slice().sort(function (a, b) { return Math.abs(Number(b.impact_usd) || 0) - Math.abs(Number(a.impact_usd) || 0); }), 20, function (part) {
        return h('div', { class: 'findings' }, part.map(function (i) { return findingItem(i, canEdit, done); }));
      }));
      add(body, cd);
    });
    if (!any) add(body, card('Hallazgos').appendChild(empty('El archivo no tiene hallazgos.', 'Puedes aprobarlo y enviarlo.')).parentNode);
    // Decisión: corregir, o aprobar y enviar.
    var act = card('Siguiente paso', null);
    if (u.status === 'enviado') {
      add(act, note('ok', 'check', 'Enviado al corporativo', 'Se envió el ' + dt(u.sent_at) + '.', caps().corp ? h('div', { class: 'acts' }, linkBtn('Siguiente: consolidar', '#/analisis/consolidacion', 'primary small', 'arrowRight')) : null));
    } else if (crit.length) {
      add(act, note('bad', 'xcircle', 'Hay que corregir el archivo', 'Corrige los ' + crit.length + ' error(es) crítico(s) en el Excel y vuelve a subirlo; se valida de nuevo al momento.'));
      add(act, h('div', { class: 'acts' }, (canEdit || caps().corp) ? linkBtn('Corregir y volver a subir', '#/proceso/' + co.code, 'primary', 'upload') : null,
        (canEdit || caps().corp) ? btn('Descargar el archivo subido', function (e) { download('/uploads/' + u.id + '/file', u.file_name || 'original.xlsx', e.currentTarget); }, 'ghost', {}, 'download') : null));
    } else if (pendW.length) {
      add(act, note('warn', 'alert', 'Falta explicar ' + pendW.length + ' aviso(s)', canEdit ? 'Usa el botón «Explicar» de cada aviso de arriba. Cuando no quede ninguno, aquí aparece «Aprobar y enviar».' : 'El responsable del país debe explicarlos.'));
    } else if (u.status === 'aprobado') {
      add(act, note('info', 'info', 'Aprobado el ' + dt(u.approved_at), 'Falta enviarlo al corporativo.'));
      if (canEdit) add(act, btn('Enviar al corporativo', function (e) { busy(e.currentTarget, post('/uploads/' + u.id + '/send')).then(function () { toast('Enviado al corporativo.', 'ok'); done(); }, fail); }, 'primary', {}, 'send'));
    } else if (!canEdit) {
      add(act, note('info', 'info', 'Listo para aprobar', 'Solo el responsable del país puede aprobar y enviar su archivo.'));
    } else {
      var cb = h('input', { type: 'checkbox', id: 'csl-confirm' });
      var go = btn('Aprobar y enviar al corporativo', function (e) {
        busy(e.currentTarget, post('/uploads/' + u.id + '/approve', { confirm: true }).then(function () { return post('/uploads/' + u.id + '/send'); }))
          .then(function () { toast('Aprobado y enviado al corporativo.', 'ok'); done(); }, function (er) { fail(er); done(); });
      }, 'primary', { disabled: true }, 'send');
      cb.onchange = function () { go.disabled = !cb.checked; };
      add(act, [note('ok', 'check', 'Listo para enviar', 'No hay errores críticos ni avisos pendientes.'),
        h('label', { class: 'check', for: 'csl-confirm' }, cb, h('span', { text: 'Confirmo que revisé las cifras y que corresponden a lo aprobado en ' + (co.name || 'el país') + (co.approver ? ' (' + co.approver + ')' : '') + '.' })),
        h('div', { class: 'acts' }, go)]);
    }
    add(act, h('div', { class: 'acts' }, linkBtn('Ver lo que leyó el sistema', '#/proceso/' + co.code, 'link')));
    add(body, act);
  }
  /** Un hallazgo en palabras simples: qué pasa, dónde, cuánto pesa y qué hacer. */
  function findingItem(i, canEdit, done) {
    var sev = SEV[i.severity] || [i.severity, 'neutral'], st = i.status || 'abierta';
    var where = [i.sheet ? 'hoja ' + i.sheet : '', cell(i.col, i.row) !== '—' ? 'celda ' + cell(i.col, i.row) : '', isNum(i.month) ? monthName(i.month) : ''].filter(Boolean).join(' · ');
    var act;
    if (st !== 'abierta') act = tag((ISSUE_ST[st] || [st])[0], (ISSUE_ST[st] || [0, 'neutral'])[1]);
    else if (i.severity === 'aviso' && canEdit && i.upload_id !== null) act = btn('Explicar', function () { explainIssue(i, done); }, 'small');
    else if (i.upload_id === null) act = h('small', { class: 'muted', text: 'Se concilia entre países' });
    else if (i.severity === 'critico') act = h('small', { class: 'muted', text: 'Corregir en el Excel' });
    return h('article', { class: 'finding ' + sev[1] },
      h('div', { class: 'f-h' }, tag(sev[0], sev[1]), h('small', { text: catLabel(i.category) + (issueText(i.code) ? ' · ' + issueText(i.code) : '') })),
      h('p', { class: 'f-msg', text: i.message || '' }),
      h('p', { class: 'f-meta' }, where ? h('span', {}, icon('search', 13), ' ' + where) : null,
        isNum(i.impact_usd) ? h('span', { class: 'c-bad' }, 'Impacto ' + usdM(i.impact_usd)) : null,
        isNum(i.actual) || isNum(i.expected) ? h('span', { text: 'Dice ' + numv(i.actual) + ' · debería ' + numv(i.expected) }) : null),
      i.explanation ? h('p', { class: 'f-exp', text: 'Explicación: ' + i.explanation }) : null,
      act ? h('div', { class: 'f-act' }, act) : null);
  }

  /* =====================================================================
     Paso 4 · Resultado: resumen arriba y pestañas (Tablero, Indicadores, Desviaciones, Proyectos, Acciones, Evolutivo, Preventivo)
     ===================================================================== */
  /* =====================================================================
     Proceso por país: los 4 pasos de cada país (cargar, revisar, enviar, consolidar), cargas anteriores y nuevas
     ===================================================================== */
  var PSTEPS = [['Cargado', 'El Excel está en el sistema'], ['Revisado', 'Sin errores críticos'], ['Enviado', 'El país lo envió al corporativo'], ['Consolidado', 'Ya suma en el resultado del grupo']];
  function processFlags(u, H) {
    var up = !!u, crit = up ? Number(u.criticals) || 0 : 0;
    var rev = up && !crit, sent = up && u.status === 'enviado';
    // Los pasos van en orden: solo cuenta como consolidado si el país ya envió (una consolidación provisional no lo cumple).
    var cons = sent && !!(H && H.lastResult);
    return [up, rev, sent, cons];
  }
  function timeline(flags, big) {
    var cur = flags.indexOf(false);
    return h('ol', { class: 'ptl' + (big ? ' big' : ''), 'aria-label': 'Avance en 4 pasos' }, PSTEPS.map(function (st, i) {
      var cls = flags[i] ? 'done' : i === cur ? 'now' : 'todo';
      return h('li', { class: cls, 'aria-current': i === cur ? 'step' : null },
        h('span', { class: 'n', 'aria-hidden': 'true' }, flags[i] ? icon('check', 14) : String(i + 1)),
        h('span', { class: 'txt' }, h('b', { text: st[0] }), big ? h('small', { text: st[1] }) : null));
    }));
  }
  function viewProcess(code) {
    var m = page('proceso');
    if (!VERSION) { noVersion(m, 'Proceso por país'); return; }
    var r0 = resolveCountry(code), co = r0.co;
    if (r0.denied) { add(m, header(null, 'Proceso por país')); add(m, empty('No tienes acceso a ese país.', 'Elige uno de tus países.', linkBtn('Ver mis países', '#/proceso', 'primary'))); return; }
    if (co) processOne(m, co, r0.many || caps().corp); else processList(m);
  }
  function processList(m) {
    add(m, header(versionLabel(VERSION), 'Proceso por país', 'Cada país sigue 4 pasos: carga su Excel, el sistema lo revisa, el país lo envía y el corporativo consolida. Abre un país para ver sus cargas o hacer una nueva.'));
    var body = skel(2); add(m, body);
    var load = function () {
      api('/home' + qs({ version: VERSION })).then(function (H) {
        ready(body);
        H = H || {};
        if (!H.hasCorporate) { add(body, homeNoData()); return; }
        var list = arr(H.companies), mine = myCompanies();
        if (!caps().corp) list = list.filter(function (x) { return mine.some(function (y) { return y.code === x.code; }); });
        if (!list.some(function (x) { return x.upload; }) && list.length) {
          add(body, note('info', 'info', 'Aún no hay archivos en ' + versionLabel(VERSION), 'Empieza por ' + (list[0].country || list[0].code) + ': abre el país y sube su Excel o simula con el archivo de Meridia.'));
        }
        var cd = card('Países', 'Dónde va cada país en ' + versionLabel(VERSION));
        add(cd, h('div', { class: 'plist' }, list.map(function (x) {
          var f = processFlags(x.upload, H);
          return h('article', { class: 'prow' },
            h('div', { class: 'prow-h' }, h('div', {}, h('b', { text: coLabel(x.code) }), h('small', { text: (x.name || '') + ' · ' + (x.currency || '') })), loadTag(x.upload)),
            timeline(f, false),
            h('div', { class: 'prow-a' }, x.upload ? h('small', { text: num(x.upload.criticals) + ' críticos · ' + num(x.upload.warnings) + ' avisos' }) : h('small', { text: 'Sin archivo' }),
              linkBtn('Abrir', '#/proceso/' + x.code, 'primary small', 'arrowRight')));
        })));
        add(body, cd);
        if (caps().corp) {
          var sent = list.filter(function (x) { return x.upload && x.upload.status === 'enviado'; }).length;
          var cc = card('Paso 4 · Consolidar', H.lastResult ? 'Última consolidación: ' + dt(H.lastResult.created_at) : 'Aún no se consolida esta versión');
          add(cc, h('p', { class: 'muted', text: num(sent) + ' de ' + num(list.length) + ' países enviados. ' + (sent === list.length ? 'Ya se puede consolidar.' : 'Puedes consolidar de forma provisional para ver el efecto de los errores.') }));
          add(cc, h('div', { class: 'acts' }, linkBtn('Ir a consolidar', '#/analisis/consolidacion', sent === list.length ? 'primary' : 'ghost', 'layers'),
            H.lastResult ? linkBtn('Ver el resultado del grupo', '#/inicio', 'ghost', 'arrowRight') : null));
          add(body, cc);
        }
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }
  function processOne(m, co, many) {
    add(m, header(versionLabel(VERSION), 'Proceso · ' + coLabel(co.code), 'Carga, revisión y envío del Excel de ' + (co.country || co.name) + '.', many ? linkBtn('Todos los países', '#/proceso', 'ghost small') : null));
    var body = skel(3); add(m, body);
    var load = function () {
      api('/home' + qs({ version: VERSION })).then(function (H) {
        H = H || {};
        var cur = arr(H.companies).filter(function (x) { return x.code === co.code; })[0] || {}, u = cur.upload;
        return Promise.all([
          api('/uploads' + qs({ company: co.id, version: VERSION })).catch(function () { return []; }),
          u ? api('/uploads/' + u.id) : null,
          u ? api('/findings' + qs({ version: VERSION, company: co.code })).catch(function () { return null; }) : null,
          H.lastResult && (caps().corp || caps().board) ? maybe(api('/kpi' + qs({ version: VERSION }))).catch(function () { return null; }) : null
        ]).then(function (r) {
          ready(body);
          var f = processFlags(u, H);
          var tl = card('Dónde va ' + (co.country || co.code), null, loadTag(u));
          add(tl, timeline(f, true));
          add(body, tl);
          // Paso 1 · cargar: nueva carga, simulación y lo que leyó el sistema.
          var canUp = caps().corp || ownsCompany(co.id);
          if (canUp) {
            var sim = card('¿Quieres ver el proceso con datos reales?', 'Carga el archivo de Meridia de ' + (co.country || co.code) + ' para ' + versionLabel(VERSION) + ' que está en el servidor y recorre los pasos.');
            var sb = btn('Simular con el archivo de Meridia', function () {
              busy(sb, post('/demo/load-one', { company: co.code, version: VERSION })).then(function (res) {
                var iss = arr(res && res.issues), crit = iss.filter(function (i) { return i.severity === 'critico'; }).length;
                toast('Archivo de Meridia cargado: ' + num(crit) + ' error(es) crítico(s) y ' + num(iss.length - crit) + ' aviso(s).', crit ? 'bad' : 'ok');
                DASH_CACHE = null; load();
              }, fail);
            }, 'ghost', {}, 'database');
            add(sim, h('div', { class: 'acts' }, sb));
            add(body, sim);
          }
          loadOne(body, co, u, load);
          // Cargas anteriores.
          var hist = arr(r[0]);
          var hc = card('Cargas de ' + (co.country || co.code) + ' en ' + versionLabel(VERSION), hist.length ? num(hist.length) + ' carga(s); la vigente es la que cuenta' : 'Aún no hay cargas');
          if (hist.length) {
            add(hc, table([['Fecha'], ['Archivo'], ['Críticos', 'num'], ['Avisos', 'num'], ['Estado'], ['']], hist.map(function (x) {
              return h('tr', {}, td(dt(x.uploaded_at)), td(x.file_name || ''), tdn(num(x.criticals)), tdn(num(x.warnings)),
                h('td', {}, Number(x.superseded) ? tag('Reemplazada', 'neutral') : loadTag(x)),
                h('td', {}, Number(x.superseded) ? null : h('small', { text: 'Vigente' })));
            }), { caption: 'Cargas del país', cards: true }));
          }
          add(body, hc);
          // Pasos 2 y 3 · revisar y enviar.
          var rev = h('section', { id: 'csl-review', class: 'stack', 'aria-label': 'Revisión y envío' });
          add(rev, h('h2', { class: 'sec-h', text: 'Revisar y enviar' }));
          if (r[1]) reviewOne(rev, co, r[1], r[2], load);
          else add(rev, empty('Cuando cargues el archivo verás aquí sus errores y podrás enviarlo.', null));
          add(body, rev);
          // Paso 4 · su aporte al grupo.
          var K = r[3], kc = K && K.companies ? K.companies[co.code] : null;
          if (kc) {
            var ac = card('Paso 4 · Lo que aporta al grupo', 'En la última consolidación de ' + versionLabel(VERSION) + ', en US$');
            add(ac, h('div', { class: 'kpis' }, [
              kpi('EBIT', usdM(kc.ebit)), kpi('ROIC', pct(kc.roic), null, 'WACC ' + pct(kc.wacc)),
              kpi('EVA', usdM(kc.eva), Number(kc.eva) < 0 ? 'bad' : 'ok', Number(kc.eva) < 0 ? 'Destruye valor' : 'Crea valor'),
              kpi('Capital invertido', usdM(kc.ci))
            ]));
            add(ac, h('div', { class: 'acts' }, linkBtn('Ver el resultado del grupo', '#/inicio', 'ghost', 'arrowRight')));
            add(body, ac);
          }
        });
      }).catch(function (e) { ready(body); errorState(body, e, load); });
    };
    load();
  }

  var ANALYSIS_TABS = [['indicadores', 'Indicadores'], ['consolidacion', 'Consolidación'], ['desviaciones', 'Desviaciones'], ['proyectos', 'Proyectos'], ['acciones', 'Acciones'], ['evolutivo', 'Evolutivo'], ['preventivo', 'Preventivo']];
  function loadDash(key, base) {
    var k = key + '|' + (base || '-');
    if (DASH_CACHE && DASH_CACHE.k === k && Date.now() - DASH_CACHE.at < 60000) return DASH_CACHE.p;
    var soft = function (p) { return p.catch(function () { return null; }); };
    var p = Promise.all([
      api('/home' + qs({ version: key })),
      soft(maybe(api('/summary' + qs({ version: key })))),
      maybe(api('/kpi' + qs({ version: key }))),
      base ? soft(maybe(api('/kpi' + qs({ version: base })))) : null,
      soft(api('/proposals')),
      soft(api('/history')),
      soft(api('/findings' + qs({ version: key }))),
      base ? soft(api('/variance' + qs({ a: key, b: base }))) : null,
      soft(api('/rates' + qs({ version: key }))),
      base ? soft(api('/rates' + qs({ version: base }))) : null
    ]).then(function (r) { return { H: r[0] || {}, S: r[1], K: r[2], KB: r[3], P: r[4], Hs: r[5], F: r[6], V: r[7], RA: r[8], RB: r[9], key: key, base: base }; });
    p.catch(function () { DASH_CACHE = null; });
    DASH_CACHE = { k: k, at: Date.now(), p: p };
    return p;
  }
  function viewAnalysis(tab, args) {
    tab = ANALYSIS_TABS.some(function (t) { return t[0] === tab; }) ? tab : 'indicadores';
    args = args || [];
    var m = page('analisis');
    if (!VERSION) { noVersion(m, 'Análisis'); return; }
    var label = (ANALYSIS_TABS.filter(function (t) { return t[0] === tab; })[0] || [0, ''])[1];
    add(m, header(versionLabel(VERSION), 'Análisis', 'El detalle para quien quiera profundizar: elige un tema.'));
    var tabs = h('nav', { class: 'tabs', 'aria-label': 'Temas del análisis' }, ANALYSIS_TABS.filter(function (t) { return t[0] !== 'consolidacion' || caps().corp; }).map(function (t) {
      return h('a', { href: '#/analisis/' + t[0], class: t[0] === tab ? 'on' : null, 'aria-current': t[0] === tab ? 'page' : null, text: t[1] });
    }));
    var panel = h('section', { class: 'tabpanel', 'aria-label': label });
    add(m, [tabs, panel]);
    document.title = label + ' · Análisis · Trazo';
    TAB_HOST = panel;
    demoteHeadings(panel);
    if (tab === 'indicadores') viewKpi();
    else if (tab === 'consolidacion') viewConsolidation();
    else if (tab === 'desviaciones') viewVariance(args[0], args[1], args[2], args[3], isNum(args[4]) ? Number(args[4]) : null);
    else if (tab === 'proyectos') viewProposals();
    else if (tab === 'acciones') viewActions();
    else if (tab === 'evolutivo') viewEvolution();
    else if (tab === 'preventivo') viewPreventive();
  }
  /** Los 3 errores que más pesan (por impacto en US$; si faltan, los críticos). */
  function topErrorsCard(D) {
    var items = arr(D.F && D.F.items);
    var withImp = items.filter(function (i) { return isNum(i.impact_usd) && Number(i.impact_usd) !== 0; }).sort(function (a, b) { return Math.abs(b.impact_usd) - Math.abs(a.impact_usd); });
    var rest = items.filter(function (i) { return withImp.indexOf(i) < 0 && i.severity === 'critico'; });
    // Una diferencia intercompañía aparece en las dos sedes con el mismo mensaje: se muestra una sola vez.
    var seen = {};
    var top3 = withImp.concat(rest).filter(function (i) { var k = i.code + '|' + i.message; if (seen[k]) return false; seen[k] = 1; return true; }).slice(0, 3);
    var cd = card('Los errores que más pesan', top3.length ? 'Por impacto estimado en US$ · ' + num(items.length) + ' hallazgos en total' : null);
    if (!top3.length) { add(cd, note('ok', 'check', null, 'No hay hallazgos en esta versión.')); return cd; }
    add(cd, h('ol', { class: 'bullets' }, top3.map(function (i, n) {
      var where = [i.sheet, cell(i.col, i.row) !== '—' ? cell(i.col, i.row) : '', isNum(i.month) ? monthName(i.month) : ''].filter(Boolean).join(' · ');
      return h('li', {}, h('span', { class: 'n', text: String(n + 1) }), h('div', {},
        h('b', { text: (companyByCode(i.company) ? countryName(i.company) : 'Entre países') + ' · ' + catLabel(i.category) + (isNum(i.impact_usd) ? ' · ' + usdM(Math.abs(i.impact_usd)) : '') }),
        h('span', { text: i.message || '' }), where ? h('small', { text: where }) : null,
        caps().corp && companyByCode(i.company) ? h('a', { href: '#/proceso/' + i.company, class: 'small', text: 'Revisar ' + countryName(i.company) }) : caps().corp ? h('a', { href: '#/analisis/consolidacion', class: 'small', text: 'Ver la conciliación' }) : null));
    })));
    return cd;
  }
  /** Decisiones pendientes: proyectos cuya decisión no se sostiene y sedes que destruyen valor. */
  function pendingCard(D, cos, atRisk, destroyers) {
    var cd = card('Decisiones pendientes', null), list = [];
    atRisk.forEach(function (p) {
      list.push({ title: 'Proyecto ' + p.id + ' · ' + countryName(p.site), text: 'Se presentó para «' + p.decision_propuesta + '» y recalculado da «' + p.decision_recalculada + '» (ROIC ' + pct(p.roic) + ' frente a WACC ' + pct(p.wacc) + ').', href: '#/analisis/proyectos',
        plan: { title: 'Revisar la propuesta ' + p.id + ' de ' + countryName(p.site) + ' antes de aprobarla', owner: ownerOf(p.site), effect: 'Decisión recalculada: «' + p.decision_recalculada + '». Inversión ' + usdM(p.investment_usd) + '.' } });
    });
    destroyers.forEach(function (c) {
      var x = cos[c];
      list.push({ title: countryName(c) + ' destruye valor', text: 'EVA ' + usdM(x.eva) + ': su ROIC de ' + pct(x.roic) + ' queda bajo su WACC de ' + pct(x.wacc) + '.', href: '#/inicio',
        plan: { title: 'Plan para que ' + countryName(c) + ' vuelva a crear valor', owner: ownerOf(c), effect: 'Llevar el ROIC de ' + pct(x.roic) + ' por encima del WACC de ' + pct(x.wacc) + ' (EVA hoy ' + usdM(x.eva) + ').' } });
    });
    arr(D.S && D.S.decisions).forEach(function (d) { if (d.rule === 'ic_sin_conciliar' || d.rule === 'criticos_abiertos') list.push({ title: d.rule === 'ic_sin_conciliar' ? 'Intercompañía sin conciliar' : 'Errores críticos abiertos', text: d.text, href: caps().corp ? (d.rule === 'ic_sin_conciliar' ? '#/analisis/consolidacion' : '#/proceso') : null }); });
    if (!list.length) { add(cd, note('ok', 'check', null, 'No hay decisiones pendientes: las propuestas se sostienen y todas las sedes crean valor.')); return cd; }
    add(cd, h('ul', { class: 'pending' }, list.map(function (x) {
      return h('li', {}, h('div', {}, h('b', { text: x.title }), h('span', { text: x.text })),
        h('div', { class: 'acts' }, x.href ? linkBtn('Ver', x.href, 'ghost small') : null,
          x.plan && caps().board ? btn('Crear plan de acción', function () {
            actionForm(null, function () { toast('Plan de acción creado. Síguelo en la pestaña Acciones.', 'ok'); }, { title: x.plan.title, owner: x.plan.owner, due_date: today(30), version: D.key, effect: x.plan.effect }, 'Crear plan de acción');
          }, 'small', {}, 'tasks') : null));
    })));
    return cd;
  }

  /* =====================================================================
     1 · Inicio por rol
     ===================================================================== */
  /* =====================================================================
     1b · Inicio del corporativo, la directiva y el administrador: tablero del grupo
     Se arma en el navegador con /home, /summary, /kpi (versión y base), /proposals, /history, /findings, /variance y
     /rates. Las oportunidades de mejora salen de reglas fijas sobre esos datos (no hay IA conectada).
     ===================================================================== */
  /** Versión base sugerida, con la misma regla que Store::base_version: v(N) → v(N−1); ppto_Y(_v1) → real_(Y−1); real_Y → ppto_Y. */
  function baseFor(key) {
    var m = /^(ppto|real)_(\d{4})(?:_v(\d+))?$/.exec(key || ''), cand = [];
    if (m) {
      var y = Number(m[2]), n = m[3] ? Number(m[3]) : 0;
      if (m[1] === 'ppto' && n > 1) cand.push('ppto_' + y + '_v' + (n - 1));
      if (m[1] === 'ppto') cand.push('real_' + (y - 1));
      if (m[1] === 'real') { cand.push('ppto_' + y); for (var i = 9; i >= 1; i--) cand.push('ppto_' + y + '_v' + i); }
    }
    for (var j = 0; j < cand.length; j++) if (versionByKey(cand[j])) return cand[j];
    var keys = META.versions.map(function (v) { return v.key; }), idx = keys.indexOf(key); // versiones de la más reciente a la más antigua
    return idx >= 0 && keys[idx + 1] ? keys[idx + 1] : null;
  }
  function countryName(code) { var c = companyByCode(code); return c ? (c.country || c.name) : String(code || ''); }
  function ownerOf(code) { var c = companyByCode(code) || {}; return [c.data_owner, c.country].filter(Boolean).join(', ') || String(code || ''); }
  function marginOf(x) { return x && isNum(x.ebit) && Number(x.revenue) ? Number(x.ebit) / Number(x.revenue) : null; }
  function rotOf(x) { return x && Number(x.ci) > 0 && isNum(x.revenue) ? Number(x.revenue) / Number(x.ci) : null; }
  function wcDays(x) { return x && isNum(x.nowc) && Number(x.revenue) ? Number(x.nowc) / Number(x.revenue) * 365 : null; }
  function times(v) { return isNum(v) ? nf(2, 2).format(Number(v)) + '×' : '—'; }
  function millions(v) { return isNum(v) ? plus(v, num(Number(v) / 1e6, 2)) : '—'; }
  /** Promedio anual de la tasa promedio (meses 1..12) de una moneda en la respuesta de /rates. */
  function avgRate(R, cur) {
    var v = arr(R && R.rows).filter(function (r) { return r.currency === cur && r.month >= 1 && isNum(r.avg); }).map(function (r) { return Number(r.avg); });
    return v.length ? v.reduce(function (s, x) { return s + x; }, 0) / v.length : null;
  }
  /** Años seguidos con EVA negativo en cifras reales (historial + real_AAAA), contando hacia atrás desde el último real. */
  function evaStreak(Hs, code) {
    var pts_ = arr(obj(Hs && Hs.trend)[code]).filter(function (p) { return p.source === 'historial' || /^real_/.test(String(p.source || '')); })
      .sort(function (a, b) { return (a.year || 0) - (b.year || 0); });
    var n = 0, from = null;
    for (var i = pts_.length - 1; i >= 0; i--) { if (isNum(pts_[i].eva) && Number(pts_[i].eva) < 0) { n++; from = pts_[i].year; } else break; }
    return { years: n, from: from };
  }
  var NUM_WORD = ['', 'un', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez'];

  /** Tablero del grupo. part: 'top' (franja, resumen, indicadores, errores y decisiones) o 'board' (pestaña Tablero). */
  function renderDashboard(body, D, part) {
    var key = D.key, base = D.base, K = D.K, KB = D.KB && D.KB.companies ? D.KB : null;
    var bl = base ? versionLabel(base) : '';
    var nAll = META.companies.length;
    var prov = arr(D.S && D.S.provisional);
    var crit = D.F && D.F.counts ? D.F.counts.critical : D.H.scoreboard ? D.H.scoreboard.critical : null;

    var isTop = part !== 'board', isBoard = part !== 'top';
    if (isTop) {
    /* ---- franja ---- */
    var baseSel = select([['-', 'Sin comparación']].concat(META.versions.filter(function (v) { return v.key !== key; }).map(function (v) { return [v.key, v.label]; })), base || '-', { id: 'csl-base' });
    baseSel.onchange = function () { RESULT_BASE[key] = baseSel.value; DASH_CACHE = null; route(); };
    var consolidated = K && K.companies ? Object.keys(obj(K.companies)).length : 0;
    add(body, h('div', { class: 'strip' },
      h('span', { class: 'strip-i' }, icon('calendar', 15), h('span', { text: versionLabel(key) })),
      h('span', { class: 'strip-i' }, icon(consolidated && consolidated === nAll ? 'check' : 'alert', 15), h('span', { text: num(consolidated) + ' de ' + num(nAll) + ' sedes consolidadas · cifras en USD' })),
      prov.length ? tag('Provisional: incluye errores abiertos de ' + prov.map(countryName).join(', '), 'warn') : null,
      isNum(crit) ? h(caps().corp ? 'a' : 'span', { class: 'strip-i' + (Number(crit) ? ' c-bad' : ''), href: caps().corp ? '#/proceso' : null }, icon(Number(crit) ? 'alert' : 'check', 15), h('span', { text: num(crit) + (Number(crit) === 1 ? ' error crítico encontrado' : ' errores críticos encontrados') })) : null,
      h('span', { class: 'grow' }),
      h('div', { class: 'strip-sel' }, h('label', { for: 'csl-base', text: 'Comparar con' }), baseSel)));

    }
    if (!K || !K.companies) {
      if (isTop) add(body, note('info', 'info', 'Aún no hay consolidación de ' + versionLabel(key), 'El resultado del grupo aparece cuando el corporativo consolida esta versión.', caps().corp ? h('div', { class: 'acts' }, linkBtn('Ir al paso 3 · Consolidar', '#/analisis/consolidacion', 'primary small', 'layers')) : null));
      else add(body, empty('Sin tablero todavía.', 'Se arma al consolidar la versión.'));
      return;
    }
    if (isTop) {
      if (base && !KB) add(body, note('warn', 'alert', null, bl + ' no tiene consolidación: el tablero se muestra sin comparación. Elige otra base en «Comparar con».'));
    }
    var cos = obj(K.companies), G = K.group || {}, GB = KB ? KB.group || {} : null, BC = KB ? obj(KB.companies) : {};
    var codes = Object.keys(cos);
    var props = D.P ? arr(D.P.items) : [], recalc = !!(D.P && D.P.recalculated);
    var atRisk = recalc ? props.filter(function (p) { return p.holds === false; }) : [];
    var sumRev = codes.reduce(function (s, c) { return s + (Number(cos[c].revenue) || 0); }, 0);
    var sumEva = codes.reduce(function (s, c) { return s + (Number(cos[c].eva) || 0); }, 0);
    var gm = marginOf(G), gmB = GB ? marginOf(GB) : null;
    var creates = Number(G.eva) >= 0;
    var destroyers = codes.filter(function (c) { return Number(cos[c].eva) < 0; }).sort(function (a, b) { return cos[a].eva - cos[b].eva; });

    /* ---- oportunidades (reglas) ---- */
    var opps = buildOpportunities(D, cos, G, atRisk);
    var reasonBy = {};
    opps.forEach(function (o) { if (o.site && !reasonBy[o.site]) reasonBy[o.site] = o; });

    if (isTop) {
    /* ---- resumen ---- */
    var s1 = creates
      ? 'El grupo crea valor: ROIC de ' + pct(G.roic) + ' sobre un costo de capital de ' + pct(G.wacc) + ' y EVA de ' + usdM(G.eva) + '.'
      : 'El grupo destruye valor: ROIC de ' + pct(G.roic) + ' frente a un costo de capital de ' + pct(G.wacc) + ' y EVA de ' + usdM(G.eva) + '.';
    var s2 = !destroyers.length ? 'Todas las sedes crean valor'
      : destroyers.length === 1 ? countryName(destroyers[0]) + ' es la única sede que destruye valor'
        : destroyers.slice(0, -1).map(countryName).join(', ') + ' y ' + countryName(destroyers[destroyers.length - 1]) + ' destruyen valor';
    var s3 = '';
    if (atRisk.length === 1) {
      var p0 = atRisk[0], rel = Number(p0.roic) < Number(p0.wacc) ? 'por debajo de su WACC de ' : 'a menos de 2 puntos de su WACC de ';
      s3 = 'el proyecto ' + p0.id + ' de ' + countryName(p0.site) + ' no se sostiene: recalculado, su ROIC de ' + pct(p0.roic) + ' queda ' + rel + pct(p0.wacc) + ' («' + p0.decision_recalculada + '», no «' + p0.decision_propuesta + '»)';
    } else if (atRisk.length > 1) s3 = atRisk.length + ' proyectos de inversión no se sostienen (' + atRisk.map(function (p) { return p.id; }).join(', ') + ')';
    var lead = s1 + ' ' + s2 + (s3 ? ', y ' + s3 : '') + '.';
    add(body, h('section', { class: 'card lead-card', 'aria-label': 'Resumen del grupo' },
      h('div', { class: 'lead-h' }, h('small', { text: 'Resumen del grupo' }), creates ? tag('Crea valor', 'ok') : tag('Destruye valor', 'bad')),
      h('p', { class: 'lead', text: lead })));

    /* ---- 5 indicadores ---- */
    var delta = function (d, text, goodUp) {
      if (!GB || !isNum(d)) return null;
      var zero = Math.abs(Number(d)) < 1e-9, good = goodUp ? d > 0 : d < 0;
      return h('span', { class: 'delta ' + (zero ? 'muted' : good ? 'c-ok' : 'c-bad') }, icon(d >= 0 ? 'arrowUp' : 'arrowDown', 13), h('span', { text: text + ' vs ' + bl }));
    };
    var tile = function (label, value, sub, d) { return h('div', { class: 'kpi tile' }, h('small', { text: label }), h('b', { text: value }), sub ? h('span', { text: sub }) : null, d || null); };
    add(body, h('div', { class: 'tiles' }, [
      tile('ROIC del grupo', pct(G.roic), 'WACC ' + pct(G.wacc), GB ? delta((G.roic - GB.roic) * 100, pts((G.roic - GB.roic) * 100), true) : null),
      tile('EVA del grupo', usdM(G.eva), creates ? 'valor creado en el año' : 'valor destruido en el año', GB ? delta(G.eva - GB.eva, signedUsdM(G.eva - GB.eva), true) : null),
      tile('Ingresos consolidados', usdM(G.revenue), 'sin ' + usdM(sumRev - G.revenue) + ' entre sedes', GB && Number(GB.revenue) ? delta((G.revenue - GB.revenue) / GB.revenue, signedPct((G.revenue - GB.revenue) / GB.revenue), true) : null),
      tile('Margen EBIT', pct(gm), 'EBIT ' + usdM(G.ebit), isNum(gmB) ? delta((gm - gmB) * 100, pts((gm - gmB) * 100), true) : null),
      tile('Decisiones en riesgo', recalc ? num(atRisk.length) + ' de ' + num(props.length) : '—', 'proyectos de inversión',
        !recalc ? h('span', { class: 'delta muted', text: 'Sin recálculo disponible' })
          : atRisk.length ? h('span', { class: 'delta c-bad' }, icon('alert', 13), h('span', { text: atRisk.map(function (p) { return p.id; }).join(', ') + (atRisk.length === 1 ? ' se presentó' : ' se presentaron') + ' con una decisión que no se sostiene' }))
            : h('span', { class: 'delta c-ok' }, icon('check', 13), h('span', { text: 'Todas las decisiones se sostienen' })))
    ]));

      add(body, h('div', { class: 'dash-2' }, topErrorsCard(D), pendingCard(D, cos, atRisk, destroyers)));
      add(body, h('p', { class: 'dash-foot', text: 'Datos ficticios de Grupo Meridia · ' + versionLabel(key) + (prov.length ? ' · cifras provisionales' : '') + (base ? ' · comparado con ' + bl : '') }));
    }
    if (isBoard) {
    /* ---- quién suma y quién resta valor + crecimiento vs rentabilidad ---- */
    var evaCard = card('Quién suma y quién resta valor', 'EVA ' + versionLabel(key) + ' por sede, US$ millones');
    var rows = codes.slice().sort(function (a, b) { return cos[b].eva - cos[a].eva; }).map(function (c) { return { label: countryName(c), v: Number(cos[c].eva) || 0, href: '#/analisis/indicadores' }; });
    var elim = (Number(G.eva) || 0) - sumEva;
    add(evaCard, evaBars(rows, elim, Number(G.eva) || 0));
    if (destroyers.length) {
      var w = destroyers[0], x = cos[w], st = evaStreak(D.Hs, w);
      var hist = st.years >= 2 ? countryName(w) + ' destruye valor desde ' + st.from + ': ' + (NUM_WORD[st.years] || st.years) + ' años seguidos con EVA negativo en sus cifras reales. ' : st.years === 1 ? countryName(w) + ' ya destruyó valor en su último año real. ' : countryName(w) + ' destruye valor en esta versión. ';
      add(evaCard, note('info', 'info', null, hist + 'En ' + versionLabel(key) + ' su ROIC es ' + pct(x.roic) + ' contra un WACC de ' + pct(x.wacc) + '.'));
    } else add(evaCard, note('ok', 'check', null, 'Todas las sedes crean valor en ' + versionLabel(key) + '.'));
    var bubCard = card('Crecimiento frente a rentabilidad', GB ? 'Ingresos vs ' + bl + ' · tamaño de la burbuja = ingresos de la sede' : 'Elige una base consolidada para ver el crecimiento');
    if (GB) {
      var items = codes.filter(function (c) { return BC[c] && Number(BC[c].revenue); }).map(function (c, i) {
        return { code: c, name: countryName(c), x: (cos[c].revenue - BC[c].revenue) / BC[c].revenue * 100, y: (cos[c].roic - cos[c].wacc) * 100, size: Number(cos[c].revenue) || 0, cls: 's' + ((i % 6) + 1) };
      }).filter(function (it) { return isNum(it.x) && isNum(it.y); });
      add(bubCard, items.length ? bubbleChart(items) : empty('Sin datos para comparar.', null));
    } else add(bubCard, empty('Sin base para comparar.', 'Elige en «Comparar con» una versión consolidada.'));
    add(body, h('div', { class: 'dash-2' }, evaCard, bubCard));

    /* ---- ranking + ROIC vs WACC ---- */
    var rk = card('Ranking por sede', 'ROIC = margen × rotación del capital. Toca una sede para ver su detalle.');
    var lowRot = null, lowMar = null;
    opps.forEach(function (o) { if (o.kind === 'capital') lowRot = o.site; if (o.kind === 'margen') lowMar = o.site; });
    var rkRows = codes.slice().sort(function (a, b) { return (cos[b].roic || 0) - (cos[a].roic || 0); }).map(function (c) {
      var x = cos[c], o = reasonBy[c], go = function () { location.hash = '#/analisis/indicadores'; };
      var chip = o ? h('span', { class: 'tag t-' + o.chip }, icon(o.icon, 13), h('span', { text: o.reason })) : Number(x.eva) >= 0 ? tag('Crea valor', 'ok') : tag('Destruye valor', 'bad');
      return h('tr', { class: 'click', tabindex: '0', onclick: go, onkeydown: function (e) { if (e.key === 'Enter') go(); } },
        h('th', { scope: 'row' }, h('a', { href: '#/analisis/indicadores', text: countryName(c) })), tdn(shortM(x.revenue)),
        tdn(pct(x.roic), Number(x.roic) < Number(x.wacc) ? 'neg' : ''), tdn(pct(x.wacc)), tdn(pct(marginOf(x)), c === lowMar ? 'neg' : ''),
        tdn(times(rotOf(x)), c === lowRot ? 'neg' : ''), tdn(num(wcDays(x))), td(chip));
    });
    rkRows.push(h('tr', { class: 'total' }, h('th', { scope: 'row', text: 'Grupo' }), tdn(shortM(G.revenue)), tdn(pct(G.roic)), tdn(pct(G.wacc)), tdn(pct(gm)), tdn(times(rotOf(G))), tdn(num(wcDays(G))), td(creates ? tag('Crea valor', 'ok') : tag('Destruye valor', 'bad'))));
    add(rk, table([['Sede'], ['Ingresos', 'num'], ['ROIC', 'num'], ['WACC', 'num'], ['Margen EBIT', 'num'], ['Rotación', 'num'], ['Días CT', 'num'], ['Estado']], rkRows, { cards: true, caption: 'Ranking por sede' }));
    add(rk, h('small', { text: 'Ingresos en US$. Rotación = ingresos / capital invertido. Días CT = capital de trabajo operativo neto / ingresos × 365.' }));
    var rw = card('ROIC frente a WACC', 'La raya marca el costo de capital de cada sede');
    add(rw, roicWaccChart(codes.slice().sort(function (a, b) { return (cos[b].roic || 0) - (cos[a].roic || 0); }).map(function (c) { return { name: countryName(c), code: c, roic: Number(cos[c].roic), wacc: Number(cos[c].wacc) }; })));
    add(body, h('div', { class: 'dash-2 wide-left' }, rk, rw));

      var oc = card('Oportunidades de mejora', 'Calculadas con reglas a partir de los indicadores, las propuestas y las desviaciones · ordenadas por efecto en EVA');
      if (!opps.length) add(oc, empty('No hay oportunidades claras con las reglas actuales.', 'Todas las sedes están cerca del promedio del grupo.'));
      else add(oc, h('div', { class: 'opps' }, opps.map(function (o) { return oppItem(o, key); })));
      add(body, oc);
      if (caps().admin) add(body, meridiaCard());
    }
  }

  /** Reglas de oportunidades: propuesta que no se sostiene, margen muy bajo, rotación más baja y efecto cambiario. */
  function buildOpportunities(D, cos, G, atRisk) {
    var out = [], codes = Object.keys(cos), gm = marginOf(G);
    // 1. Propuestas cuya decisión no se sostiene.
    atRisk.forEach(function (p) {
      var cap = Number(p.capital_usd) || Number(p.investment_usd) || 0, nop = Number(p.nopat_usd) || 0;
      var shortfall = Math.max(0, cap * ((Number(p.wacc) || 0) + 0.02) - nop), destroy = Number(p.eva) < 0 ? -Number(p.eva) : 0;
      out.push({ site: p.site, kind: 'propuesta', chip: 'warn', icon: 'briefcase', reason: p.id + ' en riesgo', impact: Math.max(shortfall, destroy),
        title: countryName(p.site) + ': no aprobar el proyecto ' + p.id + ' como está',
        text: 'Se presentó con ROIC de ' + pct(p.roic_presented) + ' y decisión «' + p.decision_propuesta + '». Recalculado da ' + pct(p.roic) + ' frente a un WACC de ' + pct(p.wacc) +
          ', así que la decisión que se sostiene es «' + p.decision_recalculada + '».' + (shortfall > 0 ? ' Le faltan ' + usdM(shortfall) + ' de NOPAT al año para llegar a «aprobar».' : ''),
        effects: [[destroy ? 'Evita destruir ' + usdM(destroy) + ' al año' : 'EVA recalculado: ' + usdM(p.eva) + ' al año', 'bad'], ['Inversión en juego: ' + usdM(p.investment_usd), 'bad']] });
    });
    // 2. Sede con margen EBIT muy por debajo del grupo (menos del 60 % del margen del grupo).
    var low = codes.filter(function (c) { var mg = marginOf(cos[c]); return isNum(mg) && isNum(gm) && mg < gm * 0.6; }).sort(function (a, b) { return marginOf(cos[a]) - marginOf(cos[b]); })[0];
    if (low) {
      var x = cos[low], mg = marginOf(x), target = (mg + gm) / 2, dE = (target - mg) * Number(x.revenue), dN = dE * (1 - (Number(x.tax) || 0));
      var st = evaStreak(D.Hs, low);
      out.push({ site: low, kind: 'margen', chip: 'bad', icon: 'percent', reason: 'Margen', impact: dN,
        title: countryName(low) + ': margen EBIT de ' + pct(mg) + ' contra ' + pct(gm) + ' del grupo',
        text: (Number(x.eva) < 0 ? 'Destruye valor: su ROIC de ' + pct(x.roic) + ' queda bajo su WACC de ' + pct(x.wacc) + (st.years >= 2 ? ' y lleva así desde ' + st.from : '') + '. ' : 'Su ROIC es ' + pct(x.roic) + ' frente a un WACC de ' + pct(x.wacc) + '. ') +
          'Llevar su margen a mitad de camino del promedio del grupo mejora el resultado sin invertir más capital.',
        effects: [['Margen a ' + pct(target) + ': ' + pts(dN / Number(x.ci) * 100) + ' de ROIC', 'ok'], [plus(dN, usdM(dN)) + ' de EVA al año', 'ok']] });
    }
    // 3. Sede con la rotación de capital más baja, si está por debajo del grupo.
    var gRot = rotOf(G);
    var lr = codes.filter(function (c) { return isNum(rotOf(cos[c])); }).sort(function (a, b) { return rotOf(cos[a]) - rotOf(cos[b]); })[0];
    if (lr && isNum(gRot) && rotOf(cos[lr]) < gRot * 0.95) {
      var y = cos[lr], rot = rotOf(y), ciNew = Number(y.revenue) / gRot, roicNew = Number(y.nopat) / ciNew, dEva = (Number(y.wacc) || 0) * (Number(y.ci) - ciNew);
      var largest = codes.every(function (c) { return Number(cos[c].revenue) <= Number(y.revenue); });
      var relW = Number(y.roic) < Number(y.wacc) ? 'queda bajo' : Number(y.roic) < Number(y.wacc) + 0.02 ? 'apenas supera' : 'supera';
      out.push({ site: lr, kind: 'capital', chip: 'warn', icon: 'factory', reason: 'Capital', impact: dEva,
        title: countryName(lr) + ': la rotación de capital más baja del grupo (' + times(rot) + ')',
        text: (largest ? 'Es la sede con más ingresos, pero su' : 'Su') + ' activo fijo neto es ' + pct(Number(y.nfa) / Number(y.ci), 0) + ' de su capital invertido. Por eso su ROIC (' + pct(y.roic) + ') ' + relW + ' su WACC (' + pct(y.wacc) + ').',
        effects: [['Rotación a ' + times(gRot) + ' (promedio del grupo): ' + pts((roicNew - Number(y.roic)) * 100) + ' de ROIC', 'ok'], [plus(dEva, usdM(dEva)) + ' de EVA al año', 'ok']] });
    }
    // 4. Sede con el mayor efecto negativo de tipo de cambio en las ventas frente a la base.
    var V = D.V && D.V.companies ? obj(D.V.companies) : {};
    var fx = Object.keys(V).filter(function (c) { var e = obj(V[c].effects).tipo_cambio; return isNum(e) && e < 0 && Math.abs(e) >= 0.01 * Math.abs(Number(V[c].b) || 0); })
      .sort(function (a, b) { return V[a].effects.tipo_cambio - V[b].effects.tipo_cambio; })[0];
    if (fx && cos[fx]) {
      var v = V[fx], tc = Number(v.effects.tipo_cambio), b0 = Number(v.b) || 0, z = cos[fx], cur = curOf(fx);
      var lg = b0 ? (Number(v.delta) - tc) / b0 : null, ug = b0 ? Number(v.delta) / b0 : null;
      var ra = avgRate(D.RA, cur), rb = avgRate(D.RB, cur);
      out.push({ site: fx, kind: 'fx', chip: 'warn', icon: 'swap', reason: 'Tipo de cambio', impact: Math.abs(tc) * (marginOf(z) || 0) * (1 - (Number(z.tax) || 0)),
        title: countryName(fx) + ': el tipo de cambio le quita ' + usdM(-tc) + ' de ventas',
        text: (isNum(lg) ? 'A tipo de cambio constante sus ventas ' + (lg >= 0 ? 'crecen ' : 'bajan ') + pct(Math.abs(lg)) + ', pero en dólares ' + (ug >= 0 ? 'crecen ' : 'caen ') + pct(Math.abs(ug)) : 'Sus ventas en dólares bajan') +
          (ra && rb ? ' por la depreciación del ' + cur + ' (de ' + num(rb, 2) + ' a ' + num(ra, 2) + ' por dólar en promedio)' : '') + '.' + (Number(z.roic) >= Number(z.wacc) ? ' Su operación crea valor; el riesgo es cambiario.' : ''),
        effects: [['Efecto cambiario: ' + signedUsdM(tc) + ' en ventas', 'bad']] });
    }
    return out.sort(function (a, b) { return (b.impact || 0) - (a.impact || 0); });
  }
  function oppItem(o, key) {
    return h('article', { class: 'opp' },
      h('span', { class: 'opp-ico t-' + o.chip, 'aria-hidden': 'true' }, icon(o.icon)),
      h('div', { class: 'opp-b' }, h('h3', { text: o.title }), h('p', { text: o.text }),
        h('p', { class: 'opp-fx' }, o.effects.map(function (e) { return h('span', { class: e[1] === 'ok' ? 'c-ok' : 'c-bad', text: e[0] }); }))),
      caps().board ? btn('Crear plan de acción', function () {
        actionForm(null, function () { toast('Plan de acción creado. Síguelo en «Acciones y seguimiento».', 'ok'); }, {
          title: o.title, owner: ownerOf(o.site), due_date: today(30), version: key,
          effect: o.effects.map(function (e) { return e[0]; }).join(' · ') + '. ' + o.text
        }, 'Crear plan de acción');
      }, 'primary small', {}, 'tasks') : null);
  }
  /** Barras horizontales de EVA por sede (divergentes desde cero) + eliminaciones + grupo. */
  function evaBars(rows, elim, group) {
    var neg = 0, pos = 0;
    rows.forEach(function (r) { if (r.v < 0) neg = Math.max(neg, -r.v); else pos = Math.max(pos, r.v); });
    var span = (neg + pos) || 1, zero = neg / span * 100;
    var list = h('ul', { class: 'evabars', 'aria-label': 'EVA por sede en millones de US$' });
    rows.forEach(function (r) {
      var w = Math.abs(r.v) / span * 100;
      add(list, h('li', {}, h('span', { class: 'eb-l', text: r.label }),
        h('span', { class: 'eb-t', 'aria-hidden': 'true' }, h('i', { class: 'eb-0', style: { left: zero + '%' } }), h('i', { class: 'eb-b ' + (r.v < 0 ? 'neg' : 'pos'), style: { left: (r.v < 0 ? zero - w : zero) + '%', width: Math.max(w, 0.5) + '%' } })),
        h('b', { class: 'tnum ' + (r.v < 0 ? 'c-bad' : ''), text: millions(r.v) })));
    });
    add(list, h('li', { class: 'eb-elim' }, h('span', { class: 'eb-l', text: 'Eliminaciones y ajustes del grupo' }), h('span', { class: 'eb-t empty' }), h('b', { class: 'tnum muted', text: millions(elim) })));
    add(list, h('li', { class: 'eb-total' }, h('span', { class: 'eb-l', text: 'Grupo' }), h('span', { class: 'eb-t empty' }), h('b', { class: 'tnum', text: millions(group) })));
    return list;
  }
  function svgEl(tag_, at, text) {
    var el = document.createElementNS('http://www.w3.org/2000/svg', tag_);
    Object.keys(at || {}).forEach(function (k) { el.setAttribute(k, at[k]); });
    if (text !== undefined) el.textContent = text;
    return el;
  }
  function ticks(lo, hi, n) {
    var step = niceStep((hi - lo) / (n || 5) || 1), out = [];
    for (var v = Math.floor(lo / step) * step; v <= hi + step / 2; v += step) out.push(Math.round(v * 1e6) / 1e6);
    return out;
  }
  /** Burbujas: x = crecimiento de ingresos (%), y = ROIC − WACC (pts), tamaño = ingresos. */
  function bubbleChart(items) {
    var W = 560, H = 360, L = 50, R = 16, T = 16, B = 46;
    var xs = items.map(function (i) { return i.x; }), ys = items.map(function (i) { return i.y; });
    var xt = ticks(Math.min.apply(null, xs.concat([-2])) - 1.5, Math.max.apply(null, xs.concat([2])) + 1.5, 6);
    var yt = ticks(Math.min.apply(null, ys.concat([-2])) - 1.5, Math.max.apply(null, ys.concat([2])) + 1.5, 5);
    var x0 = xt[0], x1 = xt[xt.length - 1], y0 = yt[0], y1 = yt[yt.length - 1];
    var X = function (v) { return L + (v - x0) / ((x1 - x0) || 1) * (W - L - R); }, Y = function (v) { return T + (1 - (v - y0) / ((y1 - y0) || 1)) * (H - T - B); };
    var maxS = Math.max.apply(null, items.map(function (i) { return i.size; }).concat([1]));
    var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart bubbles', role: 'img',
      'aria-label': 'Crecimiento frente a rentabilidad: ' + items.map(function (i) { return i.name + ' crece ' + num(i.x, 1) + ' % y su ROIC − WACC es ' + num(i.y, 1) + ' puntos'; }).join('; ') });
    xt.forEach(function (v) { svg.appendChild(svgEl('line', { x1: X(v), x2: X(v), y1: T, y2: H - B, class: v === 0 ? 'axis0' : 'grid' })); svg.appendChild(svgEl('text', { x: X(v), y: H - B + 16, 'text-anchor': 'middle', class: 'tick' }, num(v, Math.abs(v) < 10 && v % 1 ? 1 : 0))); });
    yt.forEach(function (v) { svg.appendChild(svgEl('line', { x1: L, x2: W - R, y1: Y(v), y2: Y(v), class: v === 0 ? 'axis0' : 'grid' })); svg.appendChild(svgEl('text', { x: L - 8, y: Y(v) + 4, 'text-anchor': 'end', class: 'tick' }, num(v, v % 1 ? 1 : 0))); });
    svg.appendChild(svgEl('text', { x: (L + W - R) / 2, y: H - 6, 'text-anchor': 'middle', class: 'tick' }, 'Crecimiento de ingresos en USD (%)'));
    svg.appendChild(svgEl('text', { x: 12, y: (T + H - B) / 2, 'text-anchor': 'middle', class: 'tick', transform: 'rotate(-90 12 ' + (T + H - B) / 2 + ')' }, 'ROIC − WACC (pts)'));
    items.slice().sort(function (a, b) { return b.size - a.size; }).forEach(function (it) {
      var r = 7 + 20 * Math.sqrt(it.size / maxS), cx = X(it.x), cy = Y(it.y);
      var c = svgEl('circle', { cx: cx, cy: cy, r: r.toFixed(1), class: 'bub ' + it.cls });
      c.appendChild(svgEl('title', {}, it.name + ': crecimiento ' + num(it.x, 1) + ' %, ROIC − WACC ' + num(it.y, 1) + ' pts, ingresos ' + usdM(it.size)));
      svg.appendChild(c);
      var right = cx + r + 90 < W - R;
      svg.appendChild(svgEl('text', { x: right ? cx + r + 5 : cx - r - 5, y: cy + 4, 'text-anchor': right ? 'start' : 'end', class: 'blbl' }, it.name));
    });
    [['Crece y crea valor', W - R - 6, T + 14, 'end'], ['Rentable sin crecer', L + 6, T + 14, 'start'], ['En problemas', L + 6, H - B - 8, 'start'], ['Crece destruyendo valor', W - R - 6, H - B - 8, 'end']]
      .forEach(function (q) { svg.appendChild(svgEl('text', { x: q[1], y: q[2], 'text-anchor': q[3], class: 'quad' }, q[0])); });
    return h('div', { class: 'chartwrap' }, svg);
  }
  /** Barras verticales de ROIC con una raya en el WACC de cada sede; roja si el ROIC queda bajo el WACC. */
  function roicWaccChart(list) {
    var W = 560, H = 300, L = 44, R = 12, T = 22, B = 40;
    var mx = Math.max.apply(null, list.map(function (i) { return Math.max(i.roic || 0, i.wacc || 0); }).concat([0.05]));
    var top = Math.ceil(mx * 100 / 5) * 5 / 100;
    var Y = function (v) { return T + (1 - v / top) * (H - T - B); };
    var slot = (W - L - R) / Math.max(1, list.length), bw = Math.min(56, slot * 0.55);
    var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart rw', role: 'img',
      'aria-label': 'ROIC frente a WACC: ' + list.map(function (i) { return i.name + ' ROIC ' + pct(i.roic) + ', WACC ' + pct(i.wacc); }).join('; ') });
    for (var v = 0; v <= top + 1e-9; v += 0.05) {
      svg.appendChild(svgEl('line', { x1: L, x2: W - R, y1: Y(v), y2: Y(v), class: v === 0 ? 'axis0' : 'grid' }));
      svg.appendChild(svgEl('text', { x: L - 6, y: Y(v) + 4, 'text-anchor': 'end', class: 'tick' }, num(v * 100, 0) + ' %'));
    }
    list.forEach(function (it, i) {
      var cx = L + slot * i + slot / 2, bad = it.roic < it.wacc;
      var rect = svgEl('rect', { x: cx - bw / 2, y: Y(Math.max(0, it.roic)), width: bw, height: Math.max(1, Y(0) - Y(Math.max(0, it.roic))), rx: 3, class: bad ? 'bar-bad' : 'bar-ok' });
      rect.appendChild(svgEl('title', {}, it.name + ': ROIC ' + pct(it.roic) + ' · WACC ' + pct(it.wacc)));
      svg.appendChild(rect);
      svg.appendChild(svgEl('line', { x1: cx - bw / 2 - 6, x2: cx + bw / 2 + 6, y1: Y(it.wacc), y2: Y(it.wacc), class: 'wacc-mark' }));
      svg.appendChild(svgEl('text', { x: cx, y: Y(Math.max(it.roic, it.wacc)) - 6, 'text-anchor': 'middle', class: 'blbl' + (bad ? ' bad' : '') }, pct(it.roic)));
      svg.appendChild(svgEl('text', { x: cx, y: H - B + 16, 'text-anchor': 'middle', class: 'tick' }, it.name.length > 11 ? it.code : it.name));
    });
    return h('div', { class: 'stack tight' }, h('div', { class: 'chartwrap' }, svg),
      h('div', { class: 'legend' }, h('span', {}, h('i', { class: 'sw ok' }), ' ROIC'), h('span', {}, h('i', { class: 'sw bad' }), ' ROIC bajo el WACC'), h('span', {}, h('i', { class: 'sw line' }), ' WACC de la sede')));
  }

  function homeNoData() {
    var c = caps();
    var cd = card('Aún no hay datos', null);
    add(cd, note('info', 'info', 'Falta importar los datos del Grupo Meridia',
      c.admin ? 'Importa el archivo corporativo y los 20 archivos de país que están en el servidor. Pasan por el mismo flujo de lectura y validación que una carga normal.'
        : c.corp ? 'Importa el archivo corporativo (países, tasas, catálogo e historial) en «Datos y demo».' : 'El equipo corporativo debe importar los datos antes de que puedas cargar tu archivo.'));
    if (c.admin) add(cd, h('div', { class: 'acts' }, btn('Importar datos de Meridia', function (e) { importMeridia(e.currentTarget); }, 'primary', {}, 'database')));
    else if (c.corp) add(cd, h('div', { class: 'acts' }, linkBtn('Ir a Datos y demo', '#/datos', 'primary', 'database')));
    return cd;
  }
  function meridiaCard() {
    var cd = card('Datos de Meridia', 'Solo administradores');
    add(cd, h('p', { class: 'muted', text: 'Vuelve a importar el archivo corporativo y los archivos de los 5 países desde el servidor. Cada archivo pasa por lectura, validación, aprobación y envío.' }));
    add(cd, h('div', { class: 'acts' }, btn('Importar datos de Meridia', function (e) { importMeridia(e.currentTarget); }, '', {}, 'database')));
    return cd;
  }
  /** POST /demo/load {users, send}: importa el corporativo y los 20 archivos del servidor. */
  function importMeridia() {
    var send = h('input', { type: 'checkbox', checked: true, id: 'csl-dl-send' });
    var users = h('input', { type: 'checkbox', id: 'csl-dl-users' });
    var res = h('div', { 'aria-live': 'polite' });
    var go = btn('Importar', function (e) {
      busy(e.currentTarget, post('/demo/load', { send: send.checked, users: users.checked })).then(function (r) {
        toast('Datos de Meridia importados.', 'ok');
        go.hidden = true;
        clear(res).appendChild(demoResult(r));
        return reloadMeta().then(function () {
          var v1 = versionByKey('ppto_2027_v1');
          if (v1) setVersion('ppto_2027_v1');
          route();
        });
      }).catch(fail);
    }, 'primary', {}, 'database');
    modal('Importar datos de Meridia', [
      h('p', { text: 'Se lee Grupo_Corporativo.xlsx (países, tasas, catálogo, propuestas e historial) y se suben los archivos de país de la carpeta del servidor, clasificados por nombre (país y versión).' }),
      h('label', { class: 'check', for: 'csl-dl-send' }, send, h('span', { text: 'Aprobar y enviar al corporativo los archivos sin errores críticos' })),
      h('label', { class: 'check', for: 'csl-dl-users' }, users, h('span', { text: 'Crear usuarios de prueba por país' })),
      res
    ], go, true);
  }
  function demoResult(r) {
    r = r || {};
    var items = arr(r.items);
    var box = h('div', { class: 'stack tight' }, h('b', { text: num(r.files || items.length) + ' archivo(s) procesados' + (r.dir ? ' desde ' + r.dir : '') }));
    if (items.length) add(box, table([['Archivo'], ['País'], ['Versión'], ['Estado'], ['Críticos', 'num'], ['Avisos', 'num']], items.map(function (i) {
      var st = i.status === 'omitido' || i.status === 'error' ? tag(i.status === 'error' ? 'Error' : 'Omitido', 'bad') : uploadTag({ status: i.status });
      return h('tr', {}, h('td', { class: 'wide' }, h('span', { class: 'mono', text: i.file || '' }), i.reason ? h('small', { text: i.reason }) : null), td(i.company ? coShort(i.company) : '—'),
        td(i.version ? versionLabel(i.version) : '—'), td(st), tdn(num(i.criticals)), tdn(num(i.warnings)));
    }), { cards: true, caption: 'Archivos importados' }));
    return box;
  }
  function countryOf(co) { var meta = companyById(co.id) || companyByCode(co.code) || {}; return co.country || meta.country || ''; }
  function companyCard(co, action) {
    var u = co.upload, st = u ? (UPLOAD_ST[u.status] || [u.status, 'neutral']) : ['Sin cargar', 'neutral'];
    var meta = companyById(co.id) || companyByCode(co.code) || {};
    return h('article', { class: 'co ' + st[1], 'aria-label': countryOf(co) + ': ' + st[0] },
      h('div', { class: 'co-h' }, h('div', {}, h('b', { text: coLabel(co.code) }), h('small', { text: [co.name || meta.name, co.currency || meta.currency].filter(Boolean).join(' · ') })), uploadTag(u)),
      u ? h('div', { class: 'co-n' },
        h('span', {}, h('b', { class: Number(u.criticals) ? 'c-bad' : '', text: num(u.criticals) }), ' críticos'),
        h('span', {}, h('b', { class: Number(u.warnings) ? 'c-warn' : '', text: num(u.warnings) }), ' avisos'),
        h('span', { text: (u.sent_at ? 'Enviado ' + dt(u.sent_at) : 'Subido ' + dt(u.uploaded_at)) }))
        : h('p', { class: 'muted small', text: 'Aún no hay archivo para esta versión.' }),
      action || null);
  }
  function coGrid(cos, actionFn) { return h('div', { class: 'cos' }, cos.map(function (co) { return companyCard(co, actionFn ? actionFn(co) : null); })); }
  /** Barras horizontales por categoría (con número a la derecha). */
  function categoryBars(byCat, compare) {
    var keys = categories(), max = 1;
    keys.forEach(function (k) { max = Math.max(max, Number(byCat[k]) || 0, compare ? Number(compare[k]) || 0 : 0); });
    return h('ul', { class: 'catbars' }, keys.map(function (k) {
      var v = Number(byCat[k]) || 0;
      return h('li', {}, h('span', { class: 'cb-l', text: catLabel(k) }),
        h('span', { class: 'cb-t', 'aria-hidden': 'true' }, h('i', { class: v ? 'on' : '', style: { width: (v / max * 100) + '%' } })),
        h('b', { class: 'tnum', text: num(v) }),
        compare ? h('small', { class: 'tnum', text: 'prom. ' + num(compare[k], 1) }) : null);
    }));
  }


  /* =====================================================================
     2 · Cargar Excel
     ===================================================================== */
  /* =====================================================================
     3 · Validación
     ===================================================================== */
  /**
   * Tabla de hallazgos (validación y pantalla Hallazgos).
   * opts: {canEdit, reload, grouped (por categoría), country (columna país), link (enlace a la subida)}.
   */
  function explainIssue(i, done) {
    var ta = h('textarea', { maxlength: '2000', rows: '4', placeholder: 'Ej.: El gasto sube porque abrimos una bodega nueva en marzo.' });
    ta._check = function (v) { return v.trim().length < 5 ? 'Escribe al menos 5 caracteres.' : ''; };
    var d = modal('Explicar aviso', [
      h('div', { class: 'note warn' }, icon('alert'), h('div', {}, h('b', { text: [i.sheet, cell(i.col, i.row), i.month ? monthName(i.month) : ''].filter(function (x) { return x && x !== '—'; }).join(' · ') }), h('p', { text: i.message || '' }))),
      field('¿Por qué es correcto este dato?', ta, 'Queda en la bitácora y lo ve el corporativo.', { required: true })
    ], btn('Guardar explicación', function (e) {
      if (!validate(d)) return;
      busy(e.currentTarget, post('/issues/' + i.id + '/explain', { explanation: ta.value.trim() })).then(function () { d.close(); toast('Aviso explicado.', 'ok'); refreshScore(); done(); }, fail);
    }, 'primary'));
  }
  // Resumen de lo leído (datasetSummary = {meta, rows, reported}).
  var META_LBL = { company_name: 'Empresa', scenario: 'Escenario', currency: 'Moneda', scale_declared: 'Escala declarada', scale_expected: 'Escala del país', prepared_by: 'Preparado por',
    loaded_at: 'Fecha de carga', approved_by: 'Aprobado en el país por', approved_at: 'Fecha de aprobación', notes: 'Notas', file: 'Archivo' };
  var ROWS_LBL = { pl: 'Resultados', bs: 'Balance', sales: 'Detalle de ventas', ic: 'Intercompañía', ic_inventory: 'Inventario del grupo', capex: 'Inversiones (capex)' };
  var REP_LBL = { ebit: ['EBIT', 'monto'], tax_rate: ['Tasa de impuesto', 'frac'], nopat: ['NOPAT', 'monto'], nowc_avg: ['Capital de trabajo (prom.)', 'monto'], net_fixed_avg: ['Activo fijo neto (prom.)', 'monto'],
    ic_avg: ['Capital invertido (prom.)', 'monto'], roic_pct: ['ROIC', 'pct'], ce_avg: ['Capital empleado (prom.)', 'monto'], roce_pct: ['ROCE', 'pct'], wacc_pct: ['WACC', 'pct'],
    eva_local: ['EVA (moneda local)', 'monto'], fx_used: ['Tipo de cambio usado', 'rate'], eva_usd: ['EVA (US$)', 'usd'] };
  function datasetCard(s, code) {
    var cd = card('Lo que leyó el sistema', 'Datos de la portada, filas leídas por hoja e indicadores que reportó el país');
    var meta = obj(s.meta), cur = meta.currency || curOf(code);
    var dl = h('dl', { class: 'dl' });
    Object.keys(META_LBL).forEach(function (k) { if (meta[k] !== undefined && meta[k] !== null && meta[k] !== '') add(dl, [h('dt', { text: META_LBL[k] }), h('dd', { text: /_at$/.test(k) ? dt(meta[k]) : String(meta[k]) })]); });
    var rows = obj(s.rows);
    var rl = h('dl', { class: 'dl' });
    Object.keys(rows).forEach(function (k) { add(rl, [h('dt', { text: ROWS_LBL[k] || k }), h('dd', { text: num(rows[k]) + ' registros' })]); });
    var parts = [h('div', { class: 'stack tight' }, h('h3', { text: 'Portada' }), dl.firstChild ? dl : h('p', { class: 'muted', text: 'Sin datos de portada.' })),
      h('div', { class: 'stack tight' }, h('h3', { text: 'Registros leídos' }), rl)];
    var rep = s.reported;
    if (rep && typeof rep === 'object') {
      var rd = h('dl', { class: 'dl' });
      Object.keys(REP_LBL).forEach(function (k) {
        if (!isNum(rep[k])) return;
        var t = REP_LBL[k][1], v = rep[k];
        add(rd, [h('dt', { text: REP_LBL[k][0] }), h('dd', { text: t === 'monto' ? loc(v, cur) : t === 'pct' ? num(v, 2) + ' %' : t === 'frac' ? pct(v) : t === 'usd' ? usd(v) : rate(v) })]);
      });
      parts.push(h('div', { class: 'stack tight' }, h('h3', { text: 'Indicadores reportados' }), rd));
    }
    add(cd, h('div', { class: 'grid3' }, parts));
    return cd;
  }

  /* =====================================================================
     4 · Aprobar y enviar
     ===================================================================== */
  /* =====================================================================
     Hallazgos (todas las subidas vigentes + intercompañía)
     ===================================================================== */
  /** Conteo por categoría de la versión frente a historial_ciclos (2024–2026, por ciclo). */
  function findingsHistoryCard(counts, hist, company, vKey) {
    var cd = card('Por categoría frente al historial', 'Errores por ciclo de las mismas 6 categorías (historial_ciclos)' + (company ? ' · ' + coLabel(company) : ' · todos los países'));
    var byCat = obj(counts.by_category);
    var rows = hist.filter(function (r) { return !company || r.company === company; });
    var cycles = [], sums = {};
    rows.forEach(function (r) {
      var k = r.cycle || (r.year + ' ' + (r.version || ''));
      if (cycles.indexOf(k) < 0) { cycles.push(k); sums[k] = { year: r.year, errors: {} }; }
      var e = obj(r.errors);
      categories().forEach(function (c) { sums[k].errors[c] = (sums[k].errors[c] || 0) + (Number(e[c]) || 0); });
    });
    cycles.sort(function (a, b) { return (sums[a].year - sums[b].year) || a.localeCompare(b); });
    var avg = {};
    categories().forEach(function (c) { var t = 0; cycles.forEach(function (k) { t += sums[k].errors[c]; }); avg[c] = cycles.length ? t / cycles.length : 0; });
    add(cd, categoryBars(byCat, cycles.length ? avg : null));
    if (cycles.length) {
      var head = [['Categoría']].concat(cycles.map(function (k) { return [k, 'num']; }), [[versionLabel(vKey) + ' (actual)', 'num']]);
      var trs = categories().map(function (c) {
        var cur = Number(byCat[c]) || 0, last = sums[cycles[cycles.length - 1]].errors[c];
        return h('tr', {}, h('th', { scope: 'row', text: catLabel(c) }), cycles.map(function (k) { return tdn(num(sums[k].errors[c])); }),
          tdn(h('span', { class: cur > last ? 'c-bad' : cur < last ? 'c-ok' : '' }, num(cur), cur > last ? icon('arrowUp', 12) : cur < last ? icon('arrowDown', 12) : null)));
      });
      var tot = function (k) { var t = 0; categories().forEach(function (c) { t += sums[k].errors[c]; }); return t; };
      trs.push(h('tr', { class: 'total' }, h('th', { scope: 'row', text: 'Total' }), cycles.map(function (k) { return tdn(num(tot(k))); }), tdn(num(counts.total))));
      add(cd, table(head, trs, { caption: 'Comparación con el historial', sticky: true }));
      add(cd, h('small', { text: 'La flecha compara la versión actual con el último ciclo del historial. El historial cuenta errores por ciclo completo; la versión actual cuenta hallazgos abiertos y cerrados.' }));
    } else add(cd, h('p', { class: 'muted small', text: 'No hay historial de ciclos para comparar.' }));
    return cd;
  }

  /* =====================================================================
     5 · Reglas y tasas (tasas mensuales, parámetros por país, versiones)
     ===================================================================== */
  function viewRules() {
    var m = page('reglas');
    add(m, header(VERSION ? versionLabel(VERSION) : 'Grupo', 'Reglas y tasas', 'Tasas oficiales de los 12 meses, parámetros de cada país y versiones. La consolidación solo usa tasas aprobadas.'));
    var body = skel(3); add(m, body);
    var load = function () {
      Promise.all([VERSION ? api('/rates' + qs({ version: VERSION })) : Promise.resolve(null), api('/companies'), api('/users').catch(function () { return []; })]).then(function (r) {
        ready(body);
        watchDirty(body);
        if (r[0]) add(body, ratesCard(r[0], load));
        else add(body, note('info', 'info', 'No hay versión seleccionada', 'Crea o importa una versión para cargar sus tasas.'));
        add(body, paramsCard(arr(r[1], 'companies'), arr(r[2]), load));
        add(body, versionsCard());
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }
  /** Tasas mensuales: una tabla por moneda con presupuesto, promedio y cierre (mes 0 = saldo inicial, solo cierre). */
  function ratesCard(R, reload) {
    var rows = arr(R.rows), curs = arr(R.currencies).slice(), missing = arr(R.missing);
    rows.forEach(function (x) { if (curs.indexOf(x.currency) < 0) curs.push(x.currency); });
    var by = {};
    rows.forEach(function (x) { by[x.currency + '|' + x.month] = x; });
    var approved = rows.length && rows.filter(function (x) { return x.month >= 1; }).every(function (x) { return x.status === 'aprobada'; }) && !missing.length;
    var approvedBy = '';
    rows.forEach(function (x) { if (x.status === 'aprobada' && x.approved_by) approvedBy = x.approved_by + (x.approved_at ? ' · ' + dt(x.approved_at) : ''); });
    var cd = card('Tasas de cambio · ' + versionLabel(VERSION), 'Moneda local por 1 US$. Promedio del mes para resultados, cierre del mes para balance; el mes 0 es el saldo inicial.',
      approved ? tag('Aprobadas', 'ok') : tag('Sin aprobar', 'warn'));
    if (approvedBy) add(cd, h('small', { text: 'Aprobadas por ' + approvedBy }));
    if (missing.length) add(cd, note('warn', 'alert', 'Faltan tasas aprobadas', 'Monedas sin los 12 meses aprobados: ' + missing.join(', ') + '. Sin ellas no se puede consolidar.'));
    if (!curs.length) { add(cd, empty('Todos los países usan US$.', 'No hace falta cargar tasas.')); return cd; }
    var inputs = {};
    var holder = h('div');
    var tables = {};
    curs.forEach(function (cur) {
      var trs = [];
      for (var mth = 0; mth <= 12; mth++) {
        var x = by[cur + '|' + mth] || {};
        var mk = function (v, label, opt) { return h('input', { type: 'number', step: 'any', min: '0.000001', value: isNum(v) ? String(v) : '', 'aria-label': label, inputmode: 'decimal', required: !opt, class: 'rate-in' }); };
        var b = mk(x.budget, 'Tasa de presupuesto de ' + cur + ' en ' + monthName(mth), true);
        var a = mth ? mk(x.avg, 'Tasa promedio de ' + cur + ' en ' + monthName(mth)) : null;
        var c = mk(x.close, 'Tasa de cierre de ' + cur + ' en ' + monthName(mth), mth === 0);
        inputs[cur + '|' + mth] = { cur: cur, month: mth, b: b, a: a, c: c };
        trs.push(h('tr', {}, h('th', { scope: 'row', text: mth ? MONTHS[mth] : 'Saldo inicial' }), td(b, 'num'), td(a || h('small', { text: 'no aplica' }), 'num'), td(c, 'num'),
          td(x.status === 'aprobada' ? tag('Aprobada', 'ok') : x.status ? tag('Borrador', 'warn') : tag('Sin cargar', 'neutral'))));
      }
      tables[cur] = table([['Mes'], ['Presupuesto', 'num'], ['Promedio', 'num'], ['Cierre', 'num'], ['Estado']], trs, { cards: true, caption: 'Tasas de ' + cur });
    });
    var show = function (cur) { clear(holder).appendChild(tables[cur]); };
    add(cd, [curs.length > 1 ? seg(curs.map(function (c) { return [c, c]; }), curs[0], show, 'Moneda') : null, holder]);
    show(curs[0]);
    var payload = function () {
      var out = [];
      Object.keys(inputs).forEach(function (k) {
        var x = inputs[k];
        if (x.c.value === '' && x.month === 0) return;
        out.push({ currency: x.cur, month: x.month, budget: x.b.value === '' ? null : Number(x.b.value), avg: x.a ? Number(x.a.value) : null, close: Number(x.c.value) });
      });
      return out;
    };
    var check = function () {
      // Las tablas ocultas también se revisan: se muestran todas un momento si hay errores.
      var all = h('div');
      curs.forEach(function (c) { all.appendChild(tables[c]); });
      var ok = validate(all);
      show(curs[0]);
      if (!ok) {
        var bad = null;
        Object.keys(inputs).forEach(function (k) { var x = inputs[k]; [x.b, x.a, x.c].forEach(function (el) { if (el && el.getAttribute('aria-invalid') && !bad) bad = x.cur; }); });
        if (bad) { show(bad); var segBtn = Array.prototype.filter.call(cd.querySelectorAll('.seg button'), function (b) { return b.textContent === bad; })[0]; if (segBtn) segBtn.click(); }
      }
      return ok;
    };
    add(cd, h('div', { class: 'acts' },
      btn('Guardar borrador', function (e) {
        if (!check()) return;
        busy(e.currentTarget, post('/rates', { version: VERSION, rates: payload() })).then(function () { DIRTY = false; toast('Tasas guardadas como borrador.', 'ok'); reload(); }, fail);
      }, 'ghost'),
      btn('Aprobar tasas', function () {
        if (!check()) return;
        confirmBox('Aprobar tasas', 'La consolidación de ' + versionLabel(VERSION) + ' usará estas tasas de los 12 meses. Si cambian después, hay que consolidar de nuevo.', 'Aprobar', function () {
          return post('/rates', { version: VERSION, rates: payload(), approve: true }).then(function () { DIRTY = false; toast('Tasas aprobadas.', 'ok'); reload(); });
        });
      }, 'primary', {}, 'check')));
    return cd;
  }
  function pctInput(v, label, min, max) {
    return h('input', { type: 'number', step: '0.01', min: String(min), max: String(max), value: isNum(v) ? String(Math.round(Number(v) * 10000) / 100) : '', 'aria-label': label, required: true, inputmode: 'decimal' });
  }
  function paramsCard(companies, users, reload) {
    var list = companies.length ? companies : META.companies;
    var cd = card('Parámetros por país', 'Del archivo corporativo (parametros_paises). El WACC se compara con el ROIC; la participación define los minoritarios.',
      btn('Nuevo país', function () { companyForm(null, users, reload); }, 'ghost small', {}, 'plus'));
    if (!list.length) { add(cd, empty('No hay países.', 'Importa el archivo corporativo en «Datos y demo».')); return cd; }
    add(cd, table([['País'], ['Moneda'], ['Escala del archivo'], ['Participación del grupo', 'num'], ['Socio minoritario'], ['Impuesto', 'num'], ['WACC', 'num'], ['Responsable de datos'], ['Usuarios'], ['']], list.map(function (c) {
      var us = arr(c.users).map(function (u) { return typeof u === 'object' ? (u.name || '#' + u.id) : '#' + u; }).join(', ');
      return h('tr', {}, h('th', { scope: 'row' }, h('b', { text: coLabel(c.code) }), h('small', { text: c.name })), td(c.currency || '—'), td(c.scale || '—'),
        tdn(pct(c.ownership, 0)), td(c.minority_partner ? c.minority_partner + ' (' + pct(c.minority, 0) + ')' : '—'), tdn(pct(c.tax, 1)), tdn(pct(c.wacc, 1)),
        td(h('div', {}, h('span', { text: c.data_owner || '—' }), c.approver ? h('small', { text: 'Aprueba: ' + c.approver }) : null)),
        td(h('small', { text: us || 'Sin usuarios' })), td(btn('Editar', function () { companyForm(c, users, reload); }, 'small ghost')));
    }), { cards: true, caption: 'Parámetros por país' }));
    return cd;
  }
  // CONTRATO: POST /companies[/{id}] recibe fracciones (0–1) y users:[ids]; si cambia solo la participación, el servidor ajusta el minoritario.
  function companyForm(c, users, done) {
    var v = c || {};
    var code = h('input', { value: v.code || '', maxlength: '20', disabled: !!c, placeholder: 'GT' });
    var name = h('input', { value: v.name || '', maxlength: '120', placeholder: 'Meridia Guatemala' });
    var country = h('input', { value: v.country || '', maxlength: '100' });
    var cur = h('input', { value: v.currency || '', maxlength: '3', placeholder: 'GTQ', style: { textTransform: 'uppercase' } });
    cur._check = function (x) { return /^[A-Za-z]{3}$/.test(x.trim()) ? '' : 'Código ISO de 3 letras (p. ej. GTQ).'; };
    var scale = select([['unidades', 'Unidades'], ['miles', 'Miles']], v.scale || 'unidades');
    var o = pctInput(isNum(v.ownership) ? v.ownership : 1, 'Participación del grupo', 0, 100), t = pctInput(v.tax, 'Impuesto', 0, 100), w = pctInput(v.wacc, 'WACC', 0, 100);
    var partner = h('input', { value: v.minority_partner || '', maxlength: '190' });
    var owner = h('input', { value: v.data_owner || '', maxlength: '190' }), appr = h('input', { value: v.approver || '', maxlength: '190' });
    var sel = arr(v.users).map(function (u) { return String(typeof u === 'object' ? u.id : u); });
    var boxes = users.map(function (u) {
      var cb = h('input', { type: 'checkbox', value: String(u.id), checked: sel.indexOf(String(u.id)) >= 0, id: 'csl-u-' + u.id });
      return h('label', { class: 'check', for: 'csl-u-' + u.id }, cb, h('span', { text: u.name + (arr(u.roles).length ? ' (' + arr(u.roles).join(', ') + ')' : '') }));
    });
    var d = modal(c ? 'Editar ' + coLabel(v.code) : 'Nuevo país', [
      h('div', { class: 'grid2' }, field('Código', code, c ? 'No se puede cambiar.' : 'Código ISO del país (GT, MX…).', { required: true }), field('Nombre de la empresa', name, null, { required: true }),
        field('País', country, null, { required: true }), field('Moneda local', cur, 'GTQ, MXN, USD…', { required: true }),
        field('Escala del archivo', scale, 'Cómo vienen los montos en sus archivos.'), field('Participación del grupo (%)', o, 'El minoritario se ajusta solo.', { required: true }),
        field('Socio minoritario', partner), field('Impuesto (%)', t, null, { required: true }), field('WACC (%)', w, null, { required: true }),
        field('Responsable de datos', owner), field('Aprueba en el país', appr)),
      users.length ? h('fieldset', { class: 'field fs' }, h('legend', { text: 'Usuarios que cargan este país' }), h('div', { class: 'checks' }, boxes)) : h('small', { text: 'No hay usuarios con rol del plugin para asignar.' })
    ], btn('Guardar', function (e) {
      if (!validate(d)) return;
      var b = { name: name.value.trim(), country: country.value.trim(), currency: cur.value.trim().toUpperCase(), scale: scale.value, ownership: Number(o.value) / 100, tax: Number(t.value) / 100,
        wacc: Number(w.value) / 100, minority_partner: partner.value.trim(), data_owner: owner.value.trim(), approver: appr.value.trim() };
      if (users.length) b.users = Array.prototype.filter.call(d.querySelectorAll('.checks input'), function (x) { return x.checked; }).map(function (x) { return Number(x.value); });
      if (!c) b.code = code.value.trim().toUpperCase();
      busy(e.currentTarget, post(c ? '/companies/' + c.id : '/companies', b)).then(function () { d.close(); toast('País guardado.', 'ok'); return reloadMeta().then(done); }).catch(fail);
    }, 'primary'), true);
  }
  function versionsCard() {
    var cd = card('Versiones', 'Cada versión es un juego de archivos por país (p. ej. Presupuesto 2027 v2)');
    var rows = META.versions.map(function (v) {
      var st = select([['abierto', 'Abierta'], ['consolidado', 'Consolidada'], ['cerrado', 'Cerrada']], v.status, { class: 'mini', 'aria-label': 'Estado de ' + v.label, 'data-nodirty': '1' });
      st.onchange = function () {
        st.disabled = true;
        post('/versions/' + encodeURIComponent(v.key), { status: st.value }).then(function () { toast('Estado de ' + v.label + ' actualizado.', 'ok'); return reloadMeta(); }).then(function () { st.disabled = false; }, function (e) { st.value = v.status; st.disabled = false; fail(e); });
      };
      return h('tr', {}, h('th', { scope: 'row' }, h('b', { text: v.label }), h('small', { class: 'mono', text: v.key })), tdn(String(v.year || '—')), td(st));
    });
    add(cd, rows.length ? table([['Versión'], ['Año', 'num'], ['Estado']], rows, { cards: true, caption: 'Versiones' }) : empty('Aún no hay versiones.', 'Se crean al importar el corporativo o con el formulario.'));
    var key = h('input', { maxlength: '30', placeholder: 'ppto_2028_v1', class: 'mono', autocomplete: 'off' });
    key._check = function (x) { return /^[a-z0-9_]{1,30}$/.test(x) ? '' : 'Solo minúsculas, números y guion bajo.'; };
    var label = h('input', { maxlength: '120', placeholder: 'Presupuesto 2028 v1' });
    var form = h('div', { class: 'form-row', 'data-nodirty': '1' }, field('Clave', key, 'Con el patrón ppto_AAAA_vN o real_AAAA.', { required: true }), field('Etiqueta', label, 'Opcional.'), btn('Crear versión', function (e) {
      if (!validate(form)) return;
      if (versionByKey(key.value)) { toast('Esa versión ya existe.', 'bad'); return; }
      var y = /(\d{4})/.exec(key.value);
      busy(e.currentTarget, post('/versions', { key: key.value, label: label.value.trim(), year: y ? Number(y[1]) : 0 })).then(function (r) {
        toast('Versión creada.', 'ok');
        return reloadMeta().then(function () { if (r && r.key) setVersion(r.key); route(); });
      }).catch(fail);
    }, '', {}, 'plus'));
    add(cd, form);
    return cd;
  }

  /* =====================================================================
     6 · Consolidación
     ===================================================================== */
  function viewConsolidation() {
    var m = page('consolidar');
    if (!VERSION) { noVersion(m, 'Consolidar'); return; }
    var key = VERSION;
    var cbtn = btn('Consolidar', null, 'primary', { disabled: true }, 'layers');
    add(m, header(versionLabel(key), 'Consolidación', 'Une los países en US$ mes a mes, elimina lo que se venden y prestan entre ellos y calcula minoritarios. Toca cualquier cifra para ver de qué archivo, hoja y celda sale.', cbtn));
    var body = skel(4); add(m, body);
    var load = function () {
      Promise.all([api('/home' + qs({ version: key })), api('/ic' + qs({ version: key })).catch(function (e) { return { _error: e }; }), api('/rates' + qs({ version: key })).catch(function () { return null; }), maybe(api('/results/latest' + qs({ version: key })))]).then(function (r) {
        ready(body);
        var cos = arr(r[0] && r[0].companies), ic = r[1] || {}, rates = r[2], res = r[3];
        var sent = cos.filter(function (x) { return x.upload && x.upload.status === 'enviado'; });
        var missingRates = rates ? arr(rates.missing) : [];
        var ratesOk = rates && arr(rates.rows).length && !missingRates.length;
        var pairs = arr(ic.pairs), okPairs = pairs.filter(function (p) { return p.status === 'cuadra'; }).length;
        var allSent = cos.length && sent.length === cos.length;
        cbtn.disabled = false;
        cbtn.onclick = function () { consolidate(cbtn, key, allSent, ratesOk, cos.filter(function (x) { return !(x.upload && x.upload.status === 'enviado'); }).map(function (x) { return x.code; }), load); };
        var rd = card('Estado de los países', null);
        add(rd, h('ul', { class: 'checklist' }, [
          h('li', {}, tag(allSent ? 'Listo' : 'Falta', allSent ? 'ok' : 'warn'), h('span', { text: num(sent.length) + ' de ' + num(cos.length) + ' países enviados' })),
          h('li', {}, tag(ratesOk ? 'Listo' : 'Falta', ratesOk ? 'ok' : 'bad'), h('span', { text: ratesOk ? 'Tasas oficiales de los 12 meses aprobadas' : 'Faltan tasas oficiales aprobadas' + (missingRates.length ? ' de ' + missingRates.join(', ') : '') + ' (obligatorio para consolidar)' }), !ratesOk && caps().corp ? linkBtn('Editar tasas', '#/reglas', 'link') : null),
          h('li', {}, tag(okPairs === pairs.length ? 'Listo' : 'Revisar', okPairs === pairs.length ? 'ok' : 'warn'), h('span', { text: num(okPairs) + ' de ' + num(pairs.length) + ' operaciones intercompañía cuadran (por mes y tipo)' }))
        ]));
        add(rd, coGrid(cos, function (co) { return co.upload ? linkBtn(co.upload.status === 'enviado' ? 'Ver revisión' : 'Revisar', '#/proceso/' + co.code, 'ghost small') : linkBtn('Cargar Excel', '#/proceso/' + co.code, 'ghost small', 'upload'); }));
        add(body, rd);
        add(body, ic._error ? card('Conciliación intercompañía').appendChild(errorStateBox(ic._error)).parentNode : icCard(ic, key));
        var R = res && res.result;
        if (res && R && arr(R.lines).length) { add(body, resultBlock(res, key)); add(body, h('div', { class: 'acts next-bar' }, linkBtn('Ver el resultado del grupo', '#/inicio', 'primary', 'arrowRight'))); }
        else add(body, card('Resultado', null).appendChild(empty('Aún no se consolida esta versión.', 'Cuando estén los requisitos, usa el botón Consolidar de arriba.')).parentNode);
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }
  function errorStateBox(e) { var b = h('div'); errorState(b, e, null); return b; }
  function consolidate(b, key, allSent, ratesOk, missing, reload) {
    if (!ratesOk) { toast('Primero aprueba las tasas de los 12 meses en Reglas y tasas.', 'bad'); return; }
    var run = function (force, btnEl) {
      return busy(btnEl, post('/consolidate', force ? { version: key, force: true } : { version: key })).then(function () {
        toast('Consolidación lista. El resultado está en el paso 4.', 'ok'); refreshScore(); return reloadMeta().then(reload);
      });
    };
    var ask = function (msg) {
      confirmBox('Consolidación provisional', h('div', { class: 'stack tight' },
        note('warn', 'alert', msg || ('Faltan por enviar: ' + missing.map(coShort).join(', ') + '.'), 'La consolidación provisional incluye los archivos subidos aunque tengan errores críticos abiertos, para ver su efecto en el número del grupo. El resultado queda marcado como provisional y hay que repetirlo cuando los países corrijan y envíen.')),
      'Consolidar provisional', function () { return run(true); }, 'danger');
    };
    if (!allSent) { ask(); return; }
    run(false, b).catch(function (e) { if (e.code === 'csl_missing') ask(e.message); else fail(e); });
  }
  function srcText(code, s) { return s ? coShort(code) + ': ' + (s.sheet || '') + ' ' + cell(s.col, s.row) : coShort(code) + ': sin registro'; }
  function icCard(ic, key) {
    var pairs = arr(ic.pairs);
    var cd = card('Conciliación intercompañía', 'Lo que un país le vende, cobra o presta a otro debe coincidir con lo que el otro reporta, mes a mes (tolerancia 0.5 %).');
    if (arr(ic.missing).length) add(cd, note('info', 'info', null, 'Se concilian los archivos vigentes de cada país (enviados o no). Sin archivo: ' + arr(ic.missing).map(coShort).join(', ') + '.'));
    if (!pairs.length) { add(cd, empty('No hay operaciones intercompañía para conciliar.', 'Aparecen cuando los países suben sus archivos.')); return cd; }
    var countBy = {};
    pairs.forEach(function (p) { countBy[p.status] = (countBy[p.status] || 0) + 1; });
    add(cd, h('div', { class: 'legend' }, Object.keys(PAIR_ST).map(function (k) { return countBy[k] ? tag(PAIR_ST[k][0] + ': ' + countBy[k], PAIR_ST[k][1]) : null; })));
    var fSt = select([['problemas', 'Con diferencia'], ['', 'Todas']].concat(Object.keys(PAIR_ST).map(function (k) { return [k, PAIR_ST[k][0]]; })), 'problemas');
    var fT = select([['', 'Todos']].concat(Object.keys(IC_TYPE).slice(0, 4).map(function (k) { return [k, IC_TYPE[k]]; })), '');
    var fC = companySelect('', 'Todos');
    var holder = h('div');
    var draw = function () {
      var list = pairs.filter(function (p) {
        return (fSt.value === '' || (fSt.value === 'problemas' ? p.status !== 'cuadra' : p.status === fSt.value)) && (!fT.value || p.type === fT.value) && (!fC.value || p.from === fC.value || p.to === fC.value);
      });
      clear(holder);
      if (!list.length) { add(holder, empty(fSt.value === 'problemas' ? 'Todas las operaciones cuadran.' : 'Nada con estos filtros.', null)); return; }
      add(holder, paged(list, 60, function (part) {
        return table([['Mes'], ['Tipo'], ['De → a'], ['Moneda'], ['Monto origen', 'num'], ['Monto destino', 'num'], ['Diferencia US$', 'num'], ['Estado'], ['Celdas del Excel'], ['']], part.map(function (p) {
          var st = PAIR_ST[p.status] || [p.status, 'neutral'];
          return h('tr', {}, td(monthName(p.month)), td(h('div', {}, h('span', { text: IC_TYPE[p.type] || p.type }), p.line ? h('small', { text: p.line }) : null)),
            td(h('b', { text: coShort(p.from) + ' → ' + coShort(p.to) })), td(p.currency || p.currency_from || '—'),
            tdn(numv(p.amount_from)), tdn(numv(p.amount_to)), tdn(numv(p.diff_usd), Number(p.diff_usd) ? 'neg' : ''), td(h('div', {}, tag(st[0], st[1]), p.note ? h('small', { text: p.note }) : null)),
            h('td', { class: 'wide' }, h('small', { text: srcText(p.from, p.src_from) }), h('small', { text: srcText(p.to, p.src_to) })),
            td(p.status !== 'cuadra' && caps().board ? btn('Avisar al país', function () { notifyCountry(p, key); }, 'small') : null));
        }), { cards: true, caption: 'Operaciones intercompañía' });
      }));
    };
    [fSt, fT, fC].forEach(function (s) { s.onchange = draw; });
    add(cd, [h('div', { class: 'filters' }, field('Estado', fSt), field('Tipo', fT), field('País', fC)), holder]);
    draw();
    var tie = arr(ic.pl_tie).filter(function (x) { return !x.ok; }), bal = arr(ic.balances).filter(function (x) { return !x.ok; });
    if (tie.length || bal.length) {
      var more = h('details', { class: 'more' }, h('summary', { text: 'Otras revisiones intercompañía con diferencia (' + (tie.length + bal.length) + ')' }));
      if (tie.length) add(more, [h('h3', { text: 'Ventas intercompañía en resultados vs hoja intercompañía' }), table([['País'], ['Cuenta'], ['Mes'], ['En resultados', 'num'], ['En hoja IC', 'num']], tie.map(function (x) {
        return h('tr', {}, td(coShort(x.company)), td(accLabel(x.account)), td(monthName(x.month)), tdn(loc(x.pl, curOf(x.company))), tdn(loc(x.ic, curOf(x.company))));
      }), { cards: true })]);
      if (bal.length) add(more, [h('h3', { text: 'Saldos entre países' }), table([['Tipo'], ['Acreedores'], ['Deudores'], ['Mes'], ['Por cobrar US$', 'num'], ['Por pagar US$', 'num']], bal.map(function (x) {
        return h('tr', {}, td(x.kind === 'loan_receivable' ? 'Préstamos' : 'Cuentas por cobrar'), td(arr(x.creditors).map(coShort).join(', ')), td(arr(x.debtors).map(coShort).join(', ')), td(monthName(x.month)), tdn(usd(x.receivable_usd)), tdn(usd(x.payable_usd)));
      }), { cards: true })]);
      add(cd, more);
    }
    return cd;
  }
  // CONTRATO: «Avisar al país» no tiene endpoint propio; crea una acción de seguimiento (POST /actions) prellenada.
  function notifyCountry(p, key) {
    var missingSide = p.status === 'falta_lado' ? (isNum(p.amount_from) ? p.to : p.from) : null;
    var who = missingSide ? [missingSide] : [p.from, p.to];
    var owner = who.map(function (code) { var c = companyByCode(code) || {}; return (c.data_owner ? c.data_owner + ', ' : '') + (c.country || code); }).join(' y ');
    var what = (IC_TYPE[p.type] || p.type).toLowerCase() + ' ' + p.from + ' → ' + p.to + ' de ' + monthName(p.month);
    var title = missingSide ? missingSide + ' debe registrar la contraparte de ' + what : 'Conciliar ' + what + ': diferencia ' + usd(p.diff_usd);
    actionForm(null, function () { toast('Acción creada para ' + owner + '.', 'ok'); }, { title: title, owner: owner, due_date: today(5),
      effect: 'La intercompañía se elimina completa y no queda diferencia sin conciliar en el consolidado.', version: key }, 'Avisar al país');
  }
  /** Eliminaciones agrupadas: los flujos se suman en el año; los saldos se toman a diciembre. */
  function elimSummary(E) {
    var g = {}, order = [];
    E.forEach(function (e) {
      // Saldos, préstamos y margen no realizado en inventario son saldos: se toman a diciembre, no se suman.
      var bal = e.type === 'saldo' || e.type === 'prestamo' || e.type === 'margen_inventario';
      if (bal && Number(e.month) !== 12) return;
      var k = [e.type, e.from || '', e.to || '', e.line || ''].join('|');
      if (!g[k]) { g[k] = { type: e.type, from: e.from, to: e.to, line: e.line, usd: 0, n: 0, diff: 0, accounts: {}, bal: bal }; order.push(k); }
      var x = g[k];
      x.usd += Number(e.usd) || 0; x.n++; x.diff += Math.abs(Number(e.diff_usd) || 0);
      Object.keys(obj(e.accounts)).forEach(function (a) { x.accounts[a] = (x.accounts[a] || 0) + (Number(e.accounts[a]) || 0); });
    });
    return order.map(function (k) { return g[k]; }).sort(function (a, b) { return Math.abs(b.usd) - Math.abs(a.usd); });
  }
  /** Pliega una tarjeta (tablas grandes): el título y el subtítulo quedan en el resumen del <details>. */
  function fold(cd, open) {
    var hEl = cd.querySelector('.card-h'), t = hEl && hEl.querySelector('h2'), sb = hEl && hEl.querySelector('small');
    var d = h('details', { class: 'card fold', open: open || null }, h('summary', {}, h('span', { class: 'fold-t' }, h('b', { text: t ? t.textContent : '' }), sb ? h('small', { text: sb.textContent }) : null)));
    if (hEl) hEl.remove();
    while (cd.firstChild) d.appendChild(cd.firstChild);
    return d;
  }
  function resultBlock(res, key) {
    var R = res.result || {}, box = h('div', { class: 'stack' });
    var T = R.totals || {}, mino = obj(R.minority);
    var E = arr(R.eliminations), sum = elimSummary(E);
    var elimTotal = sum.filter(function (x) { return x.type !== 'minoritario'; }).reduce(function (s, x) { return s + Math.abs(x.usd); }, 0);
    add(box, h('div', { class: 'card-h' }, h('div', {}, h('h2', { text: 'Resultado consolidado' }), h('small', { text: 'Calculado el ' + dt(res.created_at) + (res.created_by ? ' por ' + res.created_by : '') + ' · cifras en US$' }))));
    if (arr(res.provisional).length) add(box, note('warn', 'alert', 'Consolidación provisional', 'Incluye archivos con errores críticos abiertos de: ' + arr(res.provisional).map(coShort).join(', ') + '. Las cifras sirven para medir el efecto de los errores; repite cuando los países corrijan y envíen.'));
    if (arr(res.missing).length) add(box, note('warn', 'alert', 'Consolidación incompleta', 'Se consolidó sin: ' + arr(res.missing).map(coShort).join(', ') + '. Repite cuando envíen.'));
    add(box, h('div', { class: 'kpis' }, [
      kpi('Ingresos del grupo', usdM(T.revenue)), kpi('EBIT', usdM(T.ebit), null, isNum(T.revenue) && Number(T.revenue) ? 'Margen ' + pct(T.ebit / T.revenue) : null),
      kpi('Utilidad neta', usdM(T.net_income)), kpi('Atribuible al grupo', usdM(T.net_income_group), null, 'Minoritarios ' + usdM(isNum(T.minority_income) ? T.minority_income : mino.income)),
      kpi('Eliminado', usdM(elimTotal), null, num(E.length) + ' eliminación(es) mensuales'),
      kpi('IC sin conciliar', usdM(R.ic_unreconciled), Number(R.ic_unreconciled) >= 1 ? 'warn' : 'ok', Number(R.ic_unreconciled) >= 1 ? 'Diferencia que queda en el total' : 'Todo conciliado')
    ]));
    var el = card('Eliminaciones intercompañía', 'Agrupadas por tipo y pareja. Flujos: suma de los 12 meses; saldos, préstamos y margen en inventario: a diciembre.');
    add(el, sum.length ? paged(sum, 12, function (part) { return table([['Tipo'], ['De → a'], ['Línea'], ['Meses', 'num'], ['US$', 'num'], ['Diferencia US$', 'num'], ['Cuentas afectadas']], part.map(function (x) {
      var accs = Object.keys(x.accounts).map(function (a) { return accLabel(a) + ': ' + usd(x.accounts[a]); }).join(' · ');
      return h('tr', {}, td(IC_TYPE[x.type] || x.type), td(x.from || x.to ? h('b', { text: (x.from ? coShort(x.from) : '—') + ' → ' + (x.to ? coShort(x.to) : '—') }) : '—'), td(x.line || '—'),
        tdn(num(x.n)), tdn(usd(x.usd)), tdn(x.diff ? usd(x.diff) : '—', x.diff ? 'neg' : ''), h('td', { class: 'wide' }, h('small', { text: accs || '—' })));
    }), { cards: true, caption: 'Eliminaciones' }); }) : empty('No hubo eliminaciones.', null));
    add(box, fold(el));
    var mb = obj(mino.by_company), cta = obj(R.cta);
    if (Object.keys(mb).length || Object.keys(cta).length) {
      var mc = card('Minoritarios y diferencia de conversión', 'Parte que no es del grupo y efecto de convertir el patrimonio a tasas distintas (se informa, no se valida).');
      var codes = Object.keys(mb).concat(Object.keys(cta).filter(function (k) { return !mb[k]; }));
      add(mc, table([['País'], ['Minoritario', 'num'], ['Utilidad de minoritarios', 'num'], ['Patrimonio de minoritarios', 'num'], ['Diferencia de conversión (CTA)', 'num']], codes.map(function (code) {
        var x = mb[code] || {}, c2 = cta[code] || {};
        return h('tr', {}, td(coLabel(code)), tdn(isNum(x.share) ? pct(x.share, 0) : '—'), tdn(isNum(x.income) ? usd(x.income) : '—'), tdn(isNum(x.equity) ? usd(x.equity) : '—'), tdn(isNum(c2.cta) ? usd(c2.cta) : '—', cls(c2.cta)));
      }), { cards: true }));
      add(box, fold(mc));
    }
    var sc = card('Estados financieros consolidados', 'Miles de US$. Resultados: suma del año; balance: cierre de diciembre. Toca una cifra para ver su origen.');
    var holder = h('div');
    var show = function (which) { clear(holder).appendChild(statementTable(R, which, key)); };
    add(sc, [seg([['PL', 'Estado de resultados'], ['BS', 'Balance a diciembre']], 'PL', show, 'Estado a mostrar'), holder]);
    show('PL');
    add(box, fold(sc));
    return box;
  }
  function statementTable(R, which, key) {
    var codes = arr(R.companies).length ? arr(R.companies).slice() : META.companies.map(function (c) { return c.code; });
    var lines_ = arr(R.lines).filter(function (l) { return l.statement === which; });
    if (!lines_.length) return empty('El resultado no trae líneas de este estado.', null);
    var mk = function (l, kind) {
      var name = l.name || (account(l.account) || {}).name || l.account;
      var lbl = l.account ? accLabel(l.account, name) : name;
      var cells = codes.map(function (code) {
        var v = obj(l.by_company)[code];
        return tdn(l.account && isNum(v) && Number(v) !== 0 ? traceBtn(kusd(v), name + ' · ' + code, function () { openTrace(key, l.account, code, lbl + ' · ' + coLabel(code)); }) : kusd(v), cls(v));
      });
      var elim = Number(l.eliminations) || 0;
      cells.push(tdn(l.account && elim ? traceBtn(kusd(elim), 'eliminaciones de ' + name, function () { openElims(R, l.account, lbl); }) : kusd(l.eliminations), cls(l.eliminations)));
      cells.push(tdn(l.account && isNum(l.total) && Number(l.total) !== 0 ? traceBtn(kusd(l.total), name + ' · total del grupo', function () { openTrace(key, l.account, null, lbl + ' · todos los países'); }) : kusd(l.total), cls(l.total)));
      var nm = l.account && Number(l.sign) < 0 ? lbl + ' (resta)' : lbl;
      return h('tr', { class: kind || null }, h('th', { scope: 'row', text: nm }), cells);
    };
    var rows = lines_.map(function (l) { return mk(l); });
    var bc = obj(R.by_company), T = R.totals || {};
    var sumTypes = function (types, name) {
      var o = { name: name, by_company: {}, eliminations: 0, total: 0 };
      lines_.forEach(function (l) {
        if (types.indexOf(l.type) < 0) return;
        var s = Number(l.sign) < 0 ? -1 : 1;
        codes.forEach(function (c) { o.by_company[c] = (o.by_company[c] || 0) + s * (Number(obj(l.by_company)[c]) || 0); });
        o.eliminations += s * (Number(l.eliminations) || 0); o.total += s * (Number(l.total) || 0);
      });
      return o;
    };
    if (which === 'PL') {
      var derived = function (k, name) {
        var o = { name: name, by_company: {}, total: T[k] }, s = 0;
        codes.forEach(function (c) { var v = bc[c] ? bc[c][k] : null; o.by_company[c] = v; s += Number(v) || 0; });
        o.eliminations = isNum(T[k]) ? Number(T[k]) - s : null;
        return o;
      };
      rows.push(mk(derived('revenue', 'Ingresos operativos'), 'sub'));
      rows.push(mk(derived('ebit', 'EBIT (utilidad de operación)'), 'sub'));
      rows.push(mk(derived('net_income', 'Utilidad neta'), 'sub'));
      var mb = obj(obj(R.minority).by_company), mrow = { name: 'Utilidad de minoritarios', by_company: {}, eliminations: null, total: isNum(T.minority_income) ? T.minority_income : obj(R.minority).income };
      codes.forEach(function (c) { mrow.by_company[c] = mb[c] ? mb[c].income : null; });
      rows.push(mk(mrow));
      rows.push(mk({ name: 'Utilidad atribuible al grupo', by_company: {}, eliminations: null, total: T.net_income_group }, 'total'));
    } else {
      rows.push(mk(sumTypes(['activo'], 'Activo total'), 'total'));
      rows.push(mk(sumTypes(['pasivo', 'patrimonio'], 'Pasivo + patrimonio'), 'total'));
    }
    return table([['Cuenta']].concat(codes.map(function (c) { return [coShort(c), 'num']; }), [['Eliminaciones', 'num'], ['Total grupo', 'num']]), rows, { sticky: true, caption: which === 'PL' ? 'Estado de resultados consolidado' : 'Balance consolidado' });
  }
  /** Traza de una cifra: archivo, hoja, celda, mes, monto local, tasa y US$ (con filtro por mes). */
  function openTrace(key, acct, co, label, month) {
    var box = h('div', { class: 'stack' }, skel(1));
    modal('Origen de la cifra', [h('p', { class: 'muted', text: label + ' · ' + versionLabel(key) }), box], null, true);
    var load = function () {
      // CONTRATO: /trace recibe company como código (GT…) y month 0..12 opcional.
      api('/trace' + qs({ version: key, account: acct, company: co, month: isNum(month) ? month : null })).then(function (rows) {
        ready(box);
        add(box, traceTable(arr(rows), true));
      }, function (e) { errorState(box, e, load); });
    };
    load();
  }
  function traceTable(rows, withFilter) {
    if (!rows.length) return empty('No hay filas de origen para esta cifra.', 'Puede venir solo de eliminaciones o la cuenta no tiene datos en los archivos.');
    var canDl = caps().corp || caps().country;
    var box = h('div', { class: 'stack tight' }), holder = h('div');
    var months = [];
    rows.forEach(function (r) { if (isNum(r.month) && months.indexOf(Number(r.month)) < 0) months.push(Number(r.month)); });
    months.sort(function (a, b) { return a - b; });
    var fm = select([['', 'Todos los meses']].concat(months.map(function (x) { return [x, monthName(x)]; })), '');
    var draw = function () {
      var list = rows.filter(function (r) { return fm.value === '' || String(r.month) === fm.value; });
      var total = 0;
      var trs = list.map(function (r) {
        total += Number(r.usd) || 0;
        return h('tr', {}, td(coShort(r.company)), td(monthName(r.month)), h('td', { class: 'wide' }, h('span', { class: 'mono', text: r.file || '—' }), r.label ? h('small', { text: 'Etiqueta: ' + r.label }) : null),
          td(r.sheet || '—'), td(h('span', { class: 'mono', text: cell(r.col, r.row) })), tdn(loc(r.local, curOf(r.company))), tdn(rate(r.rate)), tdn(usd(r.usd)),
          td(canDl && r.upload_id ? btn('Descargar Excel', function (e) { download('/uploads/' + r.upload_id + '/file', r.file || 'original.xlsx', e.currentTarget); }, 'small ghost', {}, 'download') : null));
      });
      trs.push(h('tr', { class: 'total' }, h('th', { scope: 'row', text: 'Total (' + list.length + ' filas)' }), td(''), td(''), td(''), td(''), td(''), td(''), tdn(usd(total)), td('')));
      clear(holder).appendChild(table([['País'], ['Mes'], ['Archivo'], ['Hoja'], ['Celda'], ['Monto local', 'num'], ['Tasa', 'num'], ['US$', 'num'], ['']], trs, { cards: true, caption: 'Filas de origen' }));
    };
    fm.onchange = draw;
    if (withFilter && months.length > 1) add(box, h('div', { class: 'filters' }, field('Mes', fm)));
    add(box, [holder, h('small', { text: 'US$ = monto local ÷ tasa (promedio del mes para resultados, cierre del mes para balance).' })]);
    draw();
    return box;
  }
  function openElims(R, acct, label) {
    var list = elimSummary(arr(R.eliminations).filter(function (e) { return e.accounts && isNum(obj(e.accounts)[acct]); }));
    var body = list.length ? table([['Tipo'], ['De → a'], ['Línea'], ['Meses', 'num'], ['Ajuste en esta cuenta', 'num']], list.map(function (x) {
      return h('tr', {}, td(IC_TYPE[x.type] || x.type), td(x.from || x.to ? h('b', { text: (x.from ? coShort(x.from) : '—') + ' → ' + (x.to ? coShort(x.to) : '—') }) : '—'), td(x.line || '—'), tdn(num(x.n)), tdn(usd(x.accounts[acct])));
    }), { cards: true }) : empty('No hay eliminaciones en esta cuenta.', null);
    modal('Eliminaciones · ' + label, [body, h('small', { text: 'Flujos: suma de los meses; saldos: diciembre.' })], null, true);
  }

  /* =====================================================================
     7 · Desviaciones (grupo → país → cuenta → mes → fila)
     ===================================================================== */
  function viewVariance(a, b, co, acct, month) {
    var m = page('desviaciones');
    if (!META.versions.length) { noVersion(m, 'Desviaciones'); return; }
    a = a && versionByKey(a) ? a : VERSION;
    b = b && b !== '-' && versionByKey(b) ? b : '';
    co = co && co !== '-' ? co : null;
    add(m, header('Grupo', 'Desviaciones', 'Por qué cambian las ventas entre dos versiones: volumen, precio, tipo de cambio, intercompañía y otros. Baja de grupo a país, a cuenta, a mes y a la celda del Excel.'));
    var sa = versionSelect(a), sb = versionSelect(b, {}, 'Base sugerida');
    var go = function () { location.hash = '#/analisis/desviaciones/' + sa.value + '/' + (sb.value || '-'); };
    add(m, h('div', { class: 'card' }, h('div', { class: 'form-row' }, field('Versión comparada (A)', sa), field('Base (B)', sb, 'Sugerida: v2 vs v1, presupuesto vs real anterior, real vs presupuesto.'), btn('Comparar', go, 'primary'))));
    if (a === b) { add(m, note('warn', 'alert', 'Elige dos versiones distintas', 'A y B son la misma versión.')); return; }
    var crumbsBox = h('nav', { 'aria-label': 'Nivel de detalle' });
    add(m, crumbsBox);
    var body = skel(3); add(m, body);
    var load = function () {
      // CONTRATO: a y b son claves de versión; sin b el servidor usa la base sugerida (meta.b).
      api('/variance' + qs({ a: a, b: b, company: co })).then(function (V) {
        ready(body);
        V = V || {};
        var meta = obj(V.meta), La = (obj(meta.a).version || {}).label || versionLabel(a), bKey = (obj(meta.b).version || {}).key || b || V.version_b, Lb = (obj(meta.b).version || {}).label || versionLabel(bKey);
        var base = '#/analisis/desviaciones/' + a + '/' + (b || '-');
        var crumbs = [['Grupo', base]];
        if (co) crumbs.push([coLabel(co), base + '/' + co]);
        if (acct) crumbs.push([accLabel(acct), base + '/' + (co || '-') + '/' + acct]);
        if (isNum(month)) crumbs.push([monthName(month), null]);
        clear(crumbsBox).appendChild(h('ol', { class: 'crumbs' }, crumbs.map(function (c, i) {
          return h('li', {}, i < crumbs.length - 1 ? h('a', { href: c[1], text: c[0] }) : h('span', { 'aria-current': 'page', text: c[0] }));
        })));
        var ctx = { a: a, b: b, bKey: bKey, La: La, Lb: Lb, base: base };
        if (acct) varAccount(body, V, ctx, co, acct, month);
        else if (co) varCompany(body, V, ctx, co);
        else varGroup(body, V, ctx);
      }, function (e) {
        if (e.status === 404) { ready(body); add(body, empty('Falta consolidar una de las dos versiones.', e.message, caps().corp ? linkBtn('Ir a Consolidación', '#/analisis/consolidacion', 'primary') : null)); return; }
        errorState(body, e, load);
      });
    };
    load();
  }
  function effectsOf(x, hideIcZero) {
    var ef = obj(x && x.effects);
    return EFFECTS.filter(function (e) { return isNum(ef[e[0]]) && !(hideIcZero && e[0] === 'intercompania' && !Number(ef[e[0]])); }).map(function (e) { return [e[0], e[1], Number(ef[e[0]])]; });
  }
  function waterfall(start, startLbl, effects, end, endLbl) {
    var st = [{ lbl: startLbl, from: null, to: Number(start) || 0, kind: 'tot' }], run = Number(start) || 0;
    effects.forEach(function (e) { st.push({ lbl: e[1], from: run, to: run + e[2], kind: e[2] >= 0 ? 'up' : 'down', v: e[2] }); run += e[2]; });
    st.push({ lbl: endLbl, from: null, to: Number(end) || 0, kind: 'tot' });
    var vals = []; st.forEach(function (s) { vals.push(s.to); if (s.from !== null) vals.push(s.from); });
    var mn = Math.min.apply(null, vals), mx = Math.max.apply(null, vals), span = (mx - mn) || Math.abs(mx) || 1;
    var lo = mn - span * 0.8, hi = mx + span * 0.12, cropped = true;
    if (mn >= 0 && lo <= 0) { lo = 0; cropped = false; }
    if (mn < 0 && mx <= 0) hi = Math.min(0, hi);
    var rng = hi - lo || 1, P = function (v) { return (v - lo) / rng * 100; };
    var plot = h('div', { class: 'wf-plot', role: 'img', 'aria-label': 'Cascada: ' + st.map(function (s) { return s.lbl + ' ' + (s.kind === 'tot' ? usdM(s.to) : signedUsdM(s.v)); }).join('; ') });
    var lbls = h('div', { class: 'wf-lbls', 'aria-hidden': 'true' });
    st.forEach(function (s, i) {
      var b0 = s.kind === 'tot' ? lo : Math.min(s.from, s.to), b1 = s.kind === 'tot' ? s.to : Math.max(s.from, s.to);
      var bottom = P(Math.max(lo, b0)), height = Math.max(0.6, P(b1) - P(Math.max(lo, b0)));
      var col = h('div', { class: 'wf-col' },
        h('div', { class: 'wf-bar ' + s.kind, style: { bottom: bottom + '%', height: height + '%' } }),
        h('div', { class: 'wf-val ' + (s.kind === 'down' ? 'c-bad' : s.kind === 'up' ? 'c-ok' : ''), style: { bottom: 'calc(' + (bottom + height) + '% + 3px)' }, text: s.kind === 'tot' ? shortM(s.to) : plus(s.v, shortM(s.v)) }));
      if (i < st.length - 1) add(col, h('div', { class: 'wf-link', style: { bottom: P(s.to) + '%' } }));
      add(plot, col);
      add(lbls, h('span', { text: s.lbl }));
    });
    return h('div', { class: 'wf' }, plot, lbls, h('div', { class: 'legend' },
      h('span', { class: 'c-ok' }, icon('arrowUp', 14), ' suma'), h('span', { class: 'c-bad' }, icon('arrowDown', 14), ' resta'),
      h('span', { class: 'wf-axis', text: 'Cifras en US$.' + (cropped ? ' Eje recortado: las barras parten de ' + usdM(lo) + ' para que se vean los efectos.' : '') })));
  }
  function effectsTable(x, hideIcZero) {
    var base = Number(x.b) || 0;
    var rows = effectsOf(x, hideIcZero).map(function (e) { return h('tr', {}, h('th', { scope: 'row', text: e[1] }), tdn(signedUsdM(e[2]), cls(e[2])), tdn(base ? signedPct(e[2] / Math.abs(base)) : '—', cls(e[2]))); });
    rows.push(h('tr', { class: 'total' }, h('th', { scope: 'row', text: 'Cambio total' }), tdn(signedUsdM(x.delta), cls(x.delta)), tdn(base ? signedPct(x.delta / Math.abs(base)) : '—', cls(x.delta))));
    return table([['Efecto'], ['US$', 'num'], ['% de la base', 'num']], rows, { caption: 'Efectos de la desviación' });
  }
  function salesCard(title, X, ctx, hideIcZero) {
    var wc = card(title + ': ' + ctx.Lb + ' → ' + ctx.La, 'Base ' + usdM(X.b) + ' · comparado ' + usdM(X.a) + ' · cambio ' + signedUsdM(X.delta) + (Number(X.b) ? ' (' + signedPct(X.delta / X.b) + ')' : ''));
    add(wc, waterfall(X.b, 'Base ' + ctx.Lb, effectsOf(X, hideIcZero), X.a, ctx.La));
    if (X.text) add(wc, h('p', { class: 'explain', text: X.text }));
    add(wc, effectsTable(X, hideIcZero));
    return wc;
  }
  function linesCard(L, sub) {
    var names = Object.keys(obj(L));
    if (!names.length) return null;
    var lc = card('Por línea de producto', sub);
    add(lc, table([['Línea'], ['Base', 'num'], ['Comparado', 'num'], ['Cambio', 'num'], ['Volumen', 'num'], ['Precio', 'num'], ['Tipo de cambio', 'num'], ['Intercompañía', 'num'], ['Otros', 'num']], names.map(function (n) {
      var x = L[n], ef = obj(x.effects);
      return h('tr', {}, h('th', { scope: 'row' }, h('b', { text: n }), x.text ? h('small', { text: x.text }) : null), tdn(usdM(x.b)), tdn(usdM(x.a)), tdn(signedUsdM(x.delta), cls(x.delta)),
        tdn(signedUsdM(ef.volumen), cls(ef.volumen)), tdn(signedUsdM(ef.precio), cls(ef.precio)), tdn(signedUsdM(ef.tipo_cambio), cls(ef.tipo_cambio)), tdn(signedUsdM(ef.intercompania), cls(ef.intercompania)), tdn(signedUsdM(ef.otros), cls(ef.otros)));
    }), { cards: true, caption: 'Desviación por línea' }));
    return lc;
  }
  function varGroup(body, V, ctx) {
    var G = V.group || {};
    add(body, salesCard('Ventas del grupo', G, ctx, false));
    add(body, linesCard(G.lines, 'Ventas del grupo por línea, con el efecto de eliminar lo intercompañía.'));
    var cos = obj(V.companies);
    var cc = card('Por país', 'Toca un país para ver su cascada, sus líneas y sus cuentas.');
    var rows = Object.keys(cos).map(function (code) {
      var x = cos[code], ef = obj(x.effects), href = ctx.base + '/' + code;
      return h('tr', { class: 'click', onclick: function (e) { if (!e.target.closest('a')) location.hash = href; } },
        h('th', { scope: 'row' }, h('a', { href: href, text: coLabel(code) })), tdn(usdM(x.b)), tdn(usdM(x.a)), tdn(signedUsdM(x.delta), cls(x.delta)),
        tdn(Number(x.b) ? signedPct(x.delta / x.b) : '—', cls(x.delta)),
        tdn(signedUsdM(ef.volumen), cls(ef.volumen)), tdn(signedUsdM(ef.precio), cls(ef.precio)), tdn(signedUsdM(ef.tipo_cambio), cls(ef.tipo_cambio)), tdn(signedUsdM(ef.otros), cls(ef.otros)));
    });
    add(cc, rows.length ? table([['País'], ['Base', 'num'], ['Comparado', 'num'], ['Cambio', 'num'], ['%', 'num'], ['Volumen', 'num'], ['Precio', 'num'], ['Tipo de cambio', 'num'], ['Otros', 'num']], rows, { cards: true, caption: 'Desviación de ventas por país' }) : empty('Sin datos por país.', null));
    add(body, cc);
    add(body, accountsCard(arr(V.accounts), ctx, null));
  }
  function accVal(acc, co) {
    if (!co) return acc;
    var x = obj(acc.by_company)[co];
    if (x === undefined || x === null) return null;
    return typeof x === 'object' ? x : { delta: x };
  }
  function accountsCard(accounts, ctx, co) {
    var cd = card('Por cuenta', (co ? 'Cuentas de ' + coLabel(co) : 'Todas las cuentas del grupo') + ' en US$. Toca una cuenta para verla mes a mes.');
    var constFx = false, stmt = 'PL';
    var holder = h('div');
    var draw = function () {
      clear(holder);
      var rows = accounts.filter(function (acc) { return (acc.statement || (account(acc.account) || {}).statement || 'PL') === stmt || (stmt === 'BS' && acc.statement === 'BS'); }).map(function (acc) {
        var x = accVal(acc, co);
        if (!x) return null;
        var href = ctx.base + '/' + (co || '-') + '/' + acc.account;
        var cells = [h('th', { scope: 'row' }, h('a', { href: href, text: accLabel(acc.account, acc.name) })), tdn(usdM(x.b)), tdn(usdM(x.a)), tdn(signedUsdM(x.delta), cls(x.delta))];
        if (constFx) cells.push(tdn(signedUsdM(x.a_tipo_constante), cls(x.a_tipo_constante)), tdn(signedUsdM(x.tipo_cambio), cls(x.tipo_cambio)), tdn(signedUsdM(x.otros), cls(x.otros)));
        return h('tr', { class: 'click', onclick: function (e) { if (!e.target.closest('a')) location.hash = href; } }, cells);
      }).filter(Boolean);
      var heads = [['Cuenta'], ['Base', 'num'], ['Comparado', 'num'], ['Cambio', 'num']];
      if (constFx) heads.push(['Cambio a tipo de cambio constante', 'num'], ['Efecto del tipo de cambio', 'num'], ['Otros', 'num']);
      add(holder, rows.length ? table(heads, rows, { cards: true, caption: 'Desviación por cuenta' }) : empty('No hay cuentas para mostrar.', null));
    };
    var cb = h('input', { type: 'checkbox', id: 'csl-constfx', onchange: function () { constFx = cb.checked; draw(); } });
    add(cd, [h('div', { class: 'filters' }, seg([['PL', 'Resultados'], ['BS', 'Balance']], 'PL', function (v) { stmt = v; draw(); }, 'Estado'),
      h('label', { class: 'check', for: 'csl-constfx' }, cb, h('span', { text: 'Separar tipo de cambio (cambio a tasas de la base + efecto del tipo de cambio)' }))), holder]);
    draw();
    return cd;
  }
  function varCompany(body, V, ctx, co) {
    var X = obj(V.companies)[co];
    if (!X) { add(body, empty('No hay datos de ' + coLabel(co) + ' en esta comparación.', 'Revisa que el país haya enviado su archivo en ambas versiones.')); return; }
    add(body, salesCard('Ventas de ' + coLabel(co), X, ctx, true));
    add(body, linesCard(X.lines, 'Volumen y precio en moneda local (' + curOf(co) + '); efectos en US$.'));
    add(body, accountsCard(arr(V.accounts), ctx, co));
  }
  function varAccount(body, V, ctx, co, acct, month) {
    var acc = null;
    arr(V.accounts).forEach(function (x) { if (String(x.account) === String(acct)) acc = x; });
    var name = accLabel(acct, acc && acc.name);
    if (!acc) { add(body, empty('La cuenta ' + acct + ' no tiene movimiento en estas versiones.', null)); return; }
    var x = accVal(acc, co);
    var sc = card(name + (co ? ' · ' + coLabel(co) : ' · grupo'), ctx.Lb + ' → ' + ctx.La);
    if (x) add(sc, h('div', { class: 'kpis' }, [kpi('Base', usdM(x.b)), kpi('Comparado', usdM(x.a)), kpi('Cambio', signedUsdM(x.delta), Number(x.delta) < 0 ? 'bad' : 'ok'),
      kpi('Cambio a tipo constante', signedUsdM(x.a_tipo_constante)), kpi('Efecto tipo de cambio', signedUsdM(x.tipo_cambio))]));
    add(body, sc);
    if (!co) {
      var bc = obj(acc.by_company);
      var pc = card('Por país', 'Toca un país para ver la cuenta mes a mes y la fila del Excel.');
      add(pc, table([['País'], ['Base', 'num'], ['Comparado', 'num'], ['Cambio', 'num'], ['Tipo de cambio', 'num']], Object.keys(bc).map(function (code) {
        var y = bc[code], href = ctx.base + '/' + code + '/' + acct;
        return h('tr', { class: 'click', onclick: function (e) { if (!e.target.closest('a')) location.hash = href; } }, h('th', { scope: 'row' }, h('a', { href: href, text: coLabel(code) })),
          tdn(usdM(y.b)), tdn(usdM(y.a)), tdn(signedUsdM(y.delta), cls(y.delta)), tdn(signedUsdM(y.tipo_cambio), cls(y.tipo_cambio)));
      }), { cards: true, caption: 'Cuenta por país' }));
      add(body, pc);
      return;
    }
    var months = monthsOf(x && x.months), keys = Object.keys(months).map(Number).filter(function (k) { return !isNaN(k); }).sort(function (p, q) { return p - q; });
    var cur = curOf(co);
    var mc = card('Mes a mes', 'Toca un mes para ver las celdas del Excel de ambas versiones.');
    add(mc, keys.length ? table([['Mes'], ['Base US$', 'num'], ['Comparado US$', 'num'], ['Cambio', 'num'], ['Base ' + cur, 'num'], ['Comparado ' + cur, 'num'], ['']], keys.map(function (k) {
      var y = months[k], href = ctx.base + '/' + co + '/' + acct + '/' + k;
      return h('tr', { class: 'click' + (String(k) === String(month) ? ' on' : ''), onclick: function (e) { if (!e.target.closest('a')) location.hash = href; } }, h('th', { scope: 'row' }, h('a', { href: href, text: monthName(k) })),
        tdn(usd(y.b)), tdn(usd(y.a)), tdn(signedUsdM(y.delta), cls(y.delta)), tdn(loc(y.local_b)), tdn(loc(y.local_a)), td(h('span', { class: 'small muted', text: 'Ver celdas' })));
    }), { cards: true, caption: 'Cuenta mes a mes' }) : empty('Sin detalle mensual.', null));
    add(body, mc);
    if (isNum(month)) {
      [[ctx.a, ctx.La], [ctx.bKey, ctx.Lb]].forEach(function (p) {
        if (!p[0]) return;
        var tc = card('Celdas del Excel · ' + p[1] + ' · ' + monthName(month), coLabel(co));
        var box = h('div', {}, skel(1)); add(tc, box); add(body, tc);
        var load = function () {
          api('/trace' + qs({ version: p[0], account: acct, company: co, month: month })).then(function (rows) { ready(box); add(box, traceTable(arr(rows), false)); }, function (e) { errorState(box, e, load); });
        };
        load();
      });
    }
  }

  /* =====================================================================
     8 · Indicadores
     ===================================================================== */
  function viewKpi() {
    var m = page('indicadores');
    if (!VERSION) { noVersion(m, 'Indicadores'); return; }
    add(m, header(versionLabel(VERSION), 'Indicadores', 'ROIC, ROCE y EVA por país y del grupo en US$. Verde: el ROIC supera al WACC por más de 1 punto y no hay errores críticos abiertos.'));
    var body = skel(3); add(m, body);
    var load = function () {
      maybe(api('/kpi' + qs({ version: VERSION }))).then(function (K) {
        ready(body);
        if (!K || !K.companies) {
          add(body, empty('Aún no hay indicadores para ' + versionLabel(VERSION) + '.', 'Se calculan al consolidar.', caps().corp ? linkBtn('Ir a Consolidación', '#/analisis/consolidacion', 'primary') : null));
          return;
        }
        var cos = obj(K.companies), G = K.group || {};
        add(body, h('div', { class: 'kpis' }, [
          kpi('ROIC del grupo', pct(G.roic), G.light === 'verde' ? 'ok' : G.light === 'rojo' ? 'bad' : 'warn', 'WACC ' + pct(G.wacc)),
          kpi('ROCE del grupo', pct(G.roce)), kpi('EVA del grupo', usdM(G.eva), Number(G.eva) >= 0 ? 'ok' : 'bad', Number(G.eva) >= 0 ? 'Crea valor' : 'Destruye valor'),
          kpi('NOPAT', usdM(G.nopat)), kpi('Capital invertido', usdM(G.ci))
        ]));
        var tc = card('ROIC, ROCE y EVA', 'Capital invertido = capital de trabajo operativo neto + activo fijo neto' + (K.average_method === 'two_point' ? ' (promedio de saldo inicial y final).' : '.'));
        var rowOf = function (label, k, total) {
          var spread = isNum(k.roic) && isNum(k.wacc) ? (k.roic - k.wacc) * 100 : null, L = obj(k.local);
          return h('tr', { class: total ? 'total' : null }, h('th', { scope: 'row', text: label }), tdn(usdM(k.ebit)), tdn(usdM(k.nopat)), tdn(usdM(k.ci)),
            tdn(pct(k.roic)), tdn(pct(k.wacc)), tdn(pts(spread), cls(spread)), tdn(pct(k.roce)), tdn(usdM(k.eva), cls(k.eva)),
            tdn(isNum(L.eva) && k.currency && k.currency !== 'USD' ? loc(L.eva, k.currency) : '—', cls(L.eva)), td(lightTag(k.light)),
            h('td', { class: 'wide' }, arr(k.reasons).length ? h('ul', {}, arr(k.reasons).map(function (t) { return h('li', { text: t }); })) : h('small', { text: '—' })));
        };
        var rows = Object.keys(cos).map(function (code) { return rowOf(coLabel(code), cos[code]); });
        rows.push(rowOf('Grupo consolidado', G, true));
        add(tc, table([['País'], ['EBIT', 'num'], ['NOPAT', 'num'], ['Capital invertido', 'num'], ['ROIC', 'num'], ['WACC', 'num'], ['ROIC − WACC', 'num'], ['ROCE', 'num'], ['EVA', 'num'], ['EVA local', 'num'], ['Semáforo'], ['Razones']], rows, { cards: true, caption: 'Indicadores por país' }));
        add(body, tc);
        add(body, scenarioCard(K));
        add(body, copiedCard(obj(K.copied)));
        add(body, impactsCard(arr(K.impacts)));
        add(body, h('div', { class: 'acts' }, linkBtn('Ver propuestas de inversión', '#/analisis/proyectos', 'ghost', 'briefcase'), linkBtn('Ver evolución 2023–2027', '#/analisis/evolutivo', 'ghost', 'trend')));
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }
  var COPY_UNIT = { monto: function (v, cur) { return loc(v, cur); }, pct: function (v) { return isNum(v) ? num(v, 2) + ' %' : '—'; }, usd: function (v) { return usd(v); } };
  function copiedCard(copied) {
    var cd = card('Indicadores reportados por el país vs calculados', 'Hoja indicadores_reportados de 2027. Tolerancia: 0.5 % o 0.2 puntos.');
    var rows = [];
    Object.keys(copied).forEach(function (code) {
      var x = copied[code];
      arr(x && x.rows).forEach(function (r) {
        var f = COPY_UNIT[r.unit] || COPY_UNIT.monto, cur = curOf(code);
        var diff = r.unit === 'pct' ? pts(r.diff) : f(r.diff, cur);
        var tr = h('tr', {}, td(coLabel(code)), td(h('div', {}, h('span', { text: r.label || r.indicator }), r.src ? h('small', { class: 'mono', text: (r.src.sheet || '') + ' ' + cell(r.src.col, r.src.row) }) : null)),
          tdn(f(r.reported, cur)), tdn(f(r.calculated, cur)), tdn(diff, r.ok ? '' : 'neg'), td(r.ok ? tag('Coincide', 'ok') : tag('No coincide', 'bad')));
        tr._bad = !r.ok;
        rows.push(tr);
      });
      arr(x && x.issues).forEach(function (i) {
        if (rows.length && x.rows) return;
        rows.push(h('tr', {}, td(coLabel(code)), td(i.code), tdn(numv(i.actual)), tdn(numv(i.expected)), tdn('—'), td(tag('No coincide', 'bad'))));
      });
    });
    if (!rows.length) { add(cd, empty('Ningún país reportó indicadores en esta versión.', 'La hoja indicadores_reportados solo viene en 2027.')); return cd; }
    var nBad = rows.filter(function (r) { return r._bad; }).length;
    rows = rows.filter(function (r) { return r._bad; }).concat(rows.filter(function (r) { return !r._bad; }));
    add(cd, nBad ? note('bad', 'xcircle', nBad + ' indicador(es) reportado(s) no coinciden con el cálculo', 'Aparecen primero en la tabla.') : note('ok', 'check', null, 'Todos los indicadores reportados coinciden con el cálculo.'));
    add(cd, paged(rows, 40, function (part) { return table([['País'], ['Indicador'], ['Reportado', 'num'], ['Calculado', 'num'], ['Diferencia', 'num'], ['Estado']], part, { cards: true, caption: 'Indicadores reportados vs calculados' }); }));
    return cd;
  }
  function impactsCard(list) {
    var cd = card('Impacto de los hallazgos abiertos', 'Cuánto cambiarían el ROIC y el EVA del país si se corrige cada hallazgo con el valor esperado.');
    if (!list.length) { add(cd, empty('No hay hallazgos abiertos con impacto en los indicadores.', null)); return cd; }
    add(cd, paged(list, 30, function (part) {
      return table([['País'], ['Hallazgo'], ['Celda'], ['Impacto US$', 'num'], ['Cambio en ROIC', 'num'], ['Cambio en EVA', 'num']], part.map(function (i) {
        var im = obj(i.impact);
        return h('tr', {}, td(coShort(i.company)), h('td', { class: 'wide' }, h('span', { text: i.message || '' }), h('small', { text: [i.code, im.text].filter(Boolean).join(' · ') })),
          td(h('span', { class: 'mono', text: (i.sheet || '') + ' ' + cell(i.col, i.row) })), tdn(isNum(i.impact_usd) ? usdM(i.impact_usd) : '—'),
          tdn(im.applies ? pts(im.points) : '—', cls(im.points)), tdn(im.applies ? signedUsdM(im.delta_eva) : '—', cls(im.delta_eva)));
      }), { cards: true, caption: 'Impacto de hallazgos' });
    }));
    return cd;
  }
  /** Escenarios ±10 % (History::scenarios del servidor; si no está, cálculo aproximado en el navegador). */
  function scenarioCard(K) {
    var S = K.scenarios, cos = obj(K.companies), G = K.group || {};
    var cd = card('Escenarios', 'Qué pasa con ROIC y EVA si el dólar o el volumen se mueven 10 %.');
    var out = h('div');
    var cases = S && arr(S.cases).length ? SCENARIOS.filter(function (s) { return arr(S.cases).indexOf(s[0]) >= 0; }) : SCENARIOS;
    // CONTRATO: sin History::scenarios, aproximación local (dólar: divide las cifras en US$ de países no USD; volumen: mueve el EBIT en ±10 %).
    var local = function (caseKey) {
      var fx = caseKey === 'tc_mas_10' ? 0.1 : caseKey === 'tc_menos_10' ? -0.1 : 0, vol = caseKey === 'volumen_mas_10' ? 0.1 : caseKey === 'volumen_menos_10' ? -0.1 : 0;
      var o = { companies: {}, group: {} }, dN = 0, dC = 0;
      Object.keys(cos).forEach(function (code) {
        var k = cos[code], f = (k.currency || 'USD') === 'USD' ? 1 : 1 / (1 + fx);
        var e2 = (Number(k.ebit) || 0) * (1 + vol) * f, n2 = e2 * (1 - (Number(k.tax) || 0)), c2 = (Number(k.ci) || 0) * f;
        o.companies[code] = { roic: c2 ? n2 / c2 : null, eva: n2 - (Number(k.wacc) || 0) * c2 };
        dN += n2 - (Number(k.nopat) || 0); dC += c2 - (Number(k.ci) || 0);
      });
      var gn = (Number(G.nopat) || 0) + dN, gc = (Number(G.ci) || 0) + dC;
      o.group = { roic: gc ? gn / gc : null, eva: gn - (Number(G.wacc) || 0) * gc };
      return o;
    };
    var get = function (caseKey) {
      if (S && S.companies) {
        var o = { companies: {}, group: obj(S.group)[caseKey] || {} };
        Object.keys(obj(S.companies)).forEach(function (code) { o.companies[code] = obj(S.companies[code])[caseKey] || {}; });
        return o;
      }
      return local(caseKey);
    };
    var draw = function (caseKey) {
      var base = get('base'), sc = get(caseKey);
      clear(out);
      add(out, h('div', { class: 'kpis' }, [
        kpi('ROIC del grupo', pct(sc.group.roic), null, 'Base ' + pct(base.group.roic)),
        kpi('EVA del grupo', usdM(sc.group.eva), Number(sc.group.eva) >= 0 ? 'ok' : 'bad', 'Base ' + usdM(base.group.eva) + ' · cambio ' + signedUsdM((Number(sc.group.eva) || 0) - (Number(base.group.eva) || 0)))
      ]));
      var rows = Object.keys(cos).map(function (code) {
        var b = base.companies[code] || {}, s = sc.companies[code] || {};
        return h('tr', {}, h('th', { scope: 'row', text: coLabel(code) }), tdn(pct(b.roic)), tdn(pct(s.roic)), tdn(usdM(b.eva), cls(b.eva)), tdn(usdM(s.eva), cls(s.eva)), tdn(signedUsdM((Number(s.eva) || 0) - (Number(b.eva) || 0)), cls((Number(s.eva) || 0) - (Number(b.eva) || 0))));
      });
      rows.push(h('tr', { class: 'total' }, h('th', { scope: 'row', text: 'Grupo consolidado' }), tdn(pct(base.group.roic)), tdn(pct(sc.group.roic)), tdn(usdM(base.group.eva), cls(base.group.eva)), tdn(usdM(sc.group.eva), cls(sc.group.eva)),
        tdn(signedUsdM((Number(sc.group.eva) || 0) - (Number(base.group.eva) || 0)), cls((Number(sc.group.eva) || 0) - (Number(base.group.eva) || 0)))));
      add(out, table([['País'], ['ROIC base', 'num'], ['ROIC escenario', 'num'], ['EVA base', 'num'], ['EVA escenario', 'num'], ['Cambio EVA', 'num']], rows, { cards: true, caption: 'Escenario' }));
    };
    add(cd, [seg(cases, 'base', draw, 'Escenario'), out,
      h('small', { text: '«Dólar +10 %» = cada moneda local vale 10 % menos en US$ (afecta a los países que no usan US$). Volumen ±10 % mueve ventas y costos variables.' + (S && S.companies ? '' : ' Cálculo aproximado en tu navegador.') })]);
    draw('base');
    return cd;
  }

  /* =====================================================================
     Propuestas de inversión (presentado vs recalculado)
     ===================================================================== */
  function viewProposals() {
    var m = page('propuestas');
    add(m, header('Grupo', 'Propuestas de inversión', 'Cada país presentó su propuesta con su ROIC, WACC y EVA. El sistema la recalcula con el WACC oficial de la sede y dice si la decisión se sostiene.'));
    var body = skel(2); add(m, body);
    var load = function () {
      api('/proposals').then(function (P) {
        ready(body);
        P = P || {};
        var items = arr(P.items);
        if (!items.length) { add(body, empty('No hay propuestas.', 'Vienen en la hoja propuestas_inversion del archivo corporativo.')); return; }
        if (!P.recalculated) add(body, note('warn', 'alert', 'Aún sin recalcular', 'Se muestran las propuestas tal como se presentaron; el recálculo no está disponible por ahora.'));
        var holds = items.filter(function (p) { return p.holds; }).length;
        if (P.recalculated) add(body, h('div', { class: 'kpis' }, [kpi('Propuestas', num(items.length)), kpi('Decisión se sostiene', num(holds), holds === items.length ? 'ok' : 'warn'), kpi('Hay que revisar', num(items.length - holds), items.length - holds ? 'bad' : 'ok')]));
        add(body, h('div', { class: 'grid2' }, items.map(function (p) { return P.recalculated ? proposalCard(p) : proposalPresented(p); })));
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }
  function proposalCard(p) {
    var dp = DECISION[p.decision_propuesta] || [p.decision_propuesta || '—', 'neutral'], dr = DECISION[p.decision_recalculada] || [p.decision_recalculada || '—', 'neutral'];
    var cd = card(p.id + ' · ' + (p.description || ''), coLabel(p.site) + ' · inversión ' + usdM(p.investment_usd), p.holds ? tag('Se sostiene', 'ok') : tag('No se sostiene', 'bad'));
    var row = function (lbl, a, b, ok) { return h('tr', {}, h('th', { scope: 'row', text: lbl }), tdn(a), tdn(b), td(ok === undefined ? '' : ok ? tag('Bien', 'ok') : tag('Revisar', 'warn'))); };
    add(cd, table([['Concepto'], ['Presentado', 'num'], ['Recalculado', 'num'], ['']], [
      row('ROIC', pct(p.roic_presented, 2), pct(p.roic, 2), p.roic_ok),
      row('WACC', pct(p.wacc_presented, 1), pct(p.wacc, 1) + ' (oficial)', p.wacc_ok),
      row('EVA', usdM(p.eva_presented), usdM(p.eva), p.eva_ok),
      row('Capital del proyecto', usdM(p.capital_usd), p.capital_ok ? '≥ inversión' : '< inversión', p.capital_ok),
      row('NOPAT incremental', usdM(p.nopat_usd), usdM(p.nopat_usd)),
      h('tr', { class: 'total' }, h('th', { scope: 'row', text: 'Decisión' }), td(tag(dp[0], dp[1]), 'num'), td(tag(dr[0], dr[1]), 'num'), td(''))
    ], { caption: 'Presentado vs recalculado' }));
    if (arr(p.reasons).length) add(cd, h('ul', { class: 'small' }, arr(p.reasons).map(function (t) { return h('li', { text: t }); })));
    return cd;
  }
  function proposalPresented(p) {
    var d = DECISION[p.decision] || [p.decision || '—', 'neutral'];
    var cd = card(p.id + ' · ' + (p.description || ''), coLabel(p.site), tag(d[0], d[1]));
    var dl = h('dl', { class: 'dl' });
    [['Inversión', usdM(p.investment_usd)], ['Capital', usdM(p.capital_usd)], ['NOPAT incremental', usdM(p.incremental_nopat_usd)], ['ROIC', isNum(p.roic_pct) ? num(p.roic_pct, 2) + ' %' : '—'],
      ['WACC usado', isNum(p.wacc_pct) ? num(p.wacc_pct, 1) + ' %' : '—'], ['EVA', usdM(p.eva_usd)], ['Vida útil', isNum(p.life_years) ? p.life_years + ' años' : '—']].forEach(function (x) { add(dl, [h('dt', { text: x[0] }), h('dd', { text: x[1] })]); });
    add(cd, dl);
    return cd;
  }

  /* =====================================================================
     9 · Resumen de una página (imprimible)
     ===================================================================== */
  /* =====================================================================
     10 · Acciones y seguimiento
     ===================================================================== */
  function viewActions() {
    var m = page('acciones');
    var nb = btn('Nueva acción', function () { actionForm(null, viewActions, { version: VERSION }); }, 'primary', {}, 'plus');
    add(m, header(VERSION ? versionLabel(VERSION) : 'Directiva', 'Acciones y seguimiento', 'Qué se va a hacer, quién, para cuándo y qué efecto se espera.', nb));
    var body = skel(2); add(m, body);
    var load = function () {
      api('/actions' + qs({ version: VERSION })).then(function (list) {
        ready(body);
        list = arr(list);
        var t = today();
        var late = list.filter(function (a) { return a.status !== 'hecha' && a.due_date && a.due_date < t; }).length;
        add(body, h('div', { class: 'kpis' }, [
          kpi('Pendientes', num(list.filter(function (a) { return a.status === 'pendiente'; }).length)),
          kpi('En curso', num(list.filter(function (a) { return a.status === 'en_curso'; }).length)),
          kpi('Hechas', num(list.filter(function (a) { return a.status === 'hecha'; }).length), 'ok'),
          kpi('Vencidas', num(late), late ? 'bad' : 'ok')
        ]));
        var fs = select([['abiertas', 'Abiertas'], ['', 'Todas']].concat(ACTION_ST), 'abiertas'), q = h('input', { type: 'search', placeholder: 'Buscar por acción o responsable' });
        var holder = h('div');
        var draw = function () {
          clear(holder);
          var txt = q.value.trim().toLowerCase();
          var shown = list.filter(function (a) {
            var okS = !fs.value || (fs.value === 'abiertas' ? a.status !== 'hecha' : a.status === fs.value);
            return okS && (!txt || ((a.title || '') + ' ' + (a.owner || '')).toLowerCase().indexOf(txt) >= 0);
          }).sort(function (x, y) { return String(x.due_date || '9999').localeCompare(String(y.due_date || '9999')); });
          if (!list.length) { add(holder, empty('Aún no hay acciones.', 'Crea la primera con «Nueva acción» o desde Consolidación con «Avisar al país».')); return; }
          if (!shown.length) { add(holder, empty('Nada con este filtro.', 'Cambia el filtro para ver el resto.')); return; }
          add(holder, table([['Acción'], ['Responsable'], ['Fecha límite'], ['Estado'], ['']], shown.map(function (a) {
            var isLate = a.status !== 'hecha' && a.due_date && a.due_date < t;
            var st = select(ACTION_ST, a.status, { class: 'mini', 'aria-label': 'Estado de «' + a.title + '»' });
            st.onchange = function () {
              st.disabled = true;
              post('/actions/' + a.id, { status: st.value }).then(function () { toast('Estado actualizado.', 'ok'); load(); }, function (e) { st.value = a.status; st.disabled = false; fail(e); });
            };
            return h('tr', {}, h('td', { class: 'wide' }, h('b', { text: a.title || '' }), a.effect ? h('small', { text: 'Efecto esperado: ' + a.effect }) : null, a.creator ? h('small', { text: 'Creó ' + a.creator + ' · ' + dt(a.created_at) }) : null),
              td(a.owner || '—'), td(h('span', { class: isLate ? 'c-bad' : '' }, isLate ? icon('alert', 14) : null, ' ' + dt(a.due_date) + (isLate ? ' · vencida' : ''))),
              td(st), td(btn('Editar', function () { actionForm(a, load); }, 'small ghost')));
          }), { cards: true, caption: 'Acciones' }));
        };
        fs.onchange = draw; q.oninput = draw;
        var cd = card(null);
        add(cd, [h('div', { class: 'filters' }, field('Estado', fs), field('Buscar', q)), holder]);
        add(body, cd);
        draw();
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }
  function actionForm(a, done, prefill, title) {
    var v = a || prefill || {};
    var ti = h('input', { value: v.title || '', maxlength: '255' });
    var ow = h('input', { value: v.owner || '', maxlength: '190', placeholder: 'Nombre o país responsable' });
    var du = h('input', { type: 'date', value: v.due_date || today(14) });
    var st = select(ACTION_ST, v.status || 'pendiente');
    var ef = h('textarea', { rows: '3', maxlength: '2000', placeholder: 'Ej.: Recuperar 2 puntos de margen en Ecuador en el segundo semestre.' });
    ef.value = v.effect || '';
    var d = modal(title || (a ? 'Editar acción' : 'Nueva acción'), [
      field('Acción', ti, null, { required: true }), h('div', { class: 'grid2' }, field('Responsable', ow, null, { required: true }), field('Fecha límite', du, null, { required: true })),
      field('Estado', st), field('Efecto esperado', ef, 'Qué cambia en las cifras o en el proceso cuando se cumpla.')
    ], btn(a ? 'Guardar' : 'Crear acción', function (e) {
      if (!validate(d)) return;
      var b = { title: ti.value.trim(), owner: ow.value.trim(), due_date: du.value, status: st.value, effect: ef.value.trim() };
      if (!a) b.version = v.version || VERSION || '';
      var p = post(a ? '/actions/' + a.id : '/actions', b).then(function (r) {
        // El servidor crea las acciones en «pendiente»; si se eligió otro estado, se ajusta enseguida.
        if (!a && r && r.id && b.status !== 'pendiente') return post('/actions/' + r.id, { status: b.status });
        return r;
      });
      busy(e.currentTarget, p).then(function () { d.close(); if (!prefill) toast(a ? 'Acción actualizada.' : 'Acción creada.', 'ok'); done(); }, fail);
    }, 'primary'));
    return d;
  }

  /* =====================================================================
     Evolutivo (series 2023–2027)
     ===================================================================== */
  var METRICS = [['revenue', 'Ingresos', 'usd'], ['ebit', 'EBIT', 'usd'], ['roic', 'ROIC', 'pct'], ['wacc', 'WACC', 'pct'], ['eva', 'EVA', 'usd']];
  function viewEvolution() {
    var m = page('evolutivo');
    add(m, header('Análisis', 'Evolutivo', 'Ingresos, EBIT, ROIC, WACC y EVA por país: reales 2023–2025 del historial, real 2026 y presupuestos 2027 consolidados. Puntos huecos = presupuesto.'));
    var body = skel(2); add(m, body);
    var load = function () {
      api('/history').then(function (Hs) {
        ready(body);
        Hs = Hs || {};
        var trend = obj(Hs.trend);
        // CONTRATO: sin History::kpi_trend, la serie se arma con historial_indicadores (roic_pct y wacc_pct en puntos).
        if (!Object.keys(trend).length) {
          arr(Hs.kpis).forEach(function (r) {
            (trend[r.company] = trend[r.company] || []).push({ label: r.year + ' real', year: r.year, source: 'historial', revenue: r.revenue_usd, ebit: r.ebit_usd, nopat: r.nopat_usd, ci: r.invested_capital_usd,
              roic: isNum(r.roic_pct) ? r.roic_pct / 100 : null, wacc: isNum(r.wacc_pct) ? r.wacc_pct / 100 : null, eva: r.eva_usd });
          });
        }
        var codes = Object.keys(trend).filter(function (k) { return k !== 'GRUPO'; });
        if (!codes.length) { add(body, empty('No hay series para mostrar.', 'Se arman con el historial del archivo corporativo y las versiones consolidadas.')); return; }
        var metric = 'revenue', scope = 'paises';
        var chartBox = h('div'), tableBox = h('div');
        var draw = function () {
          var met = METRICS.filter(function (x) { return x[0] === metric; })[0];
          var series = scope === 'grupo' && trend.GRUPO ? [{ code: 'GRUPO', name: 'Grupo Meridia', pts: arr(trend.GRUPO) }] : codes.map(function (c) { return { code: c, name: coLabel(c), pts: arr(trend[c]) }; });
          clear(chartBox).appendChild(lineChart(series, metric, met[2], met[1]));
          var labels = unionLabels(series);
          clear(tableBox).appendChild(table([['Serie']].concat(labels.map(function (l) { return [l, 'num']; })), series.map(function (s) {
            var byL = {}; s.pts.forEach(function (p) { byL[p.label] = p; });
            return h('tr', {}, h('th', { scope: 'row', text: s.name }), labels.map(function (l) { var p = byL[l]; return tdn(p ? (met[2] === 'pct' ? pct(p[metric]) : usdM(p[metric])) : '—', p ? cls(p[metric]) : ''); }));
          }), { sticky: true, caption: 'Datos de la serie' }));
        };
        var cd = card('Series por país', null);
        add(cd, [h('div', { class: 'filters' }, seg(METRICS.map(function (x) { return [x[0], x[1]]; }), metric, function (v) { metric = v; draw(); }, 'Indicador'),
          trend.GRUPO ? seg([['paises', 'Por país'], ['grupo', 'Grupo']], scope, function (v) { scope = v; draw(); }, 'Alcance') : null), chartBox, tableBox]);
        add(body, cd);
        draw();
        if (arr(Hs.versions).length === 0) add(body, note('info', 'info', null, 'Aún no hay versiones consolidadas: solo se ve el historial.'));
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }
  function unionLabels(series) {
    var labels = [];
    series.forEach(function (s) { s.pts.forEach(function (p) { if (labels.indexOf(p.label) < 0) labels.push(p.label); }); });
    var yr = {}; series.forEach(function (s) { s.pts.forEach(function (p) { yr[p.label] = (p.year || 0) * 10 + (p.source === 'historial' || /real/i.test(p.label) ? 0 : 1); }); });
    return labels.sort(function (a, b) { return (yr[a] - yr[b]) || labels.indexOf(a) - labels.indexOf(b); });
  }
  /** Gráfico de líneas en SVG en línea (un color por serie, etiqueta al final de cada línea, puntos huecos = presupuesto). */
  function lineChart(series, metric, kind, title) {
    var NS = 'http://www.w3.org/2000/svg';
    var S = function (tag_, at) { var el = document.createElementNS(NS, tag_); Object.keys(at || {}).forEach(function (k) { el.setAttribute(k, at[k]); }); return el; };
    var labels = unionLabels(series);
    var W = 760, H = 320, L = 64, R = 140, T = 16, B = 46;
    var vals = [];
    series.forEach(function (s) { s.pts.forEach(function (p) { if (isNum(p[metric])) vals.push(Number(p[metric])); }); });
    if (!vals.length) return empty('Sin datos de ' + title + '.', null);
    var mn = Math.min.apply(null, vals.concat(kind === 'pct' ? [] : [0])), mx = Math.max.apply(null, vals);
    if (kind === 'pct') { mn = Math.min(0, mn); }
    var step = niceStep((mx - mn) / 4 || 1), lo = Math.floor(mn / step) * step, hi = Math.ceil(mx / step) * step || step;
    var X = function (i) { return L + (labels.length > 1 ? i * (W - L - R) / (labels.length - 1) : (W - L - R) / 2); };
    var Y = function (v) { return T + (H - T - B) * (1 - (v - lo) / ((hi - lo) || 1)); };
    var fmt = function (v) { return kind === 'pct' ? num(v * 100, 0) + ' %' : shortM(v); };
    var svg = S('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart', role: 'img', 'aria-label': title + ' por ' + (series.length > 1 ? 'país' : 'año') + '. Los datos están en la tabla de abajo.' });
    for (var v = lo; v <= hi + step / 2; v += step) {
      svg.appendChild(S('line', { x1: L, x2: W - R, y1: Y(v), y2: Y(v), class: v === 0 ? 'axis0' : 'grid' }));
      var t = S('text', { x: L - 8, y: Y(v) + 4, 'text-anchor': 'end', class: 'tick' }); t.textContent = fmt(v); svg.appendChild(t);
    }
    labels.forEach(function (l, i) {
      var t = S('text', { x: X(i), y: H - B + 18, 'text-anchor': 'middle', class: 'tick' });
      var parts = String(l).replace('Presupuesto', 'Ppto').split(' ');
      t.textContent = parts.slice(0, 2).join(' '); svg.appendChild(t);
      if (parts.length > 2) { var t2 = S('text', { x: X(i), y: H - B + 32, 'text-anchor': 'middle', class: 'tick' }); t2.textContent = parts.slice(2).join(' '); svg.appendChild(t2); }
    });
    var ends = [];
    series.forEach(function (s, si) {
      var colorCls = 's' + (s.code === 'GRUPO' ? 'g' : (si % 6) + 1);
      var pts_ = s.pts.filter(function (p) { return isNum(p[metric]); }).map(function (p) { return { x: X(labels.indexOf(p.label)), y: Y(Number(p[metric])), p: p }; }).sort(function (a, b) { return a.x - b.x; });
      if (!pts_.length) return;
      svg.appendChild(S('path', { d: pts_.map(function (q, i) { return (i ? 'L' : 'M') + q.x.toFixed(1) + ' ' + q.y.toFixed(1); }).join(' '), class: 'ln ' + colorCls }));
      pts_.forEach(function (q) {
        var budget = q.p.source && q.p.source !== 'historial' && !/^real/.test(q.p.source);
        var c = S('circle', { cx: q.x, cy: q.y, r: 4, class: 'pt ' + colorCls + (budget ? ' hollow' : '') });
        var tt = S('title', {}); tt.textContent = s.name + ' · ' + q.p.label + ': ' + fmt(Number(q.p[metric])); c.appendChild(tt);
        svg.appendChild(c);
      });
      var last = pts_[pts_.length - 1];
      ends.push({ y: last.y, x: last.x, name: s.code === 'GRUPO' ? 'Grupo' : s.code, cls: colorCls });
    });
    ends.sort(function (a, b) { return a.y - b.y; });
    for (var i = 1; i < ends.length; i++) if (ends[i].y - ends[i - 1].y < 14) ends[i].y = ends[i - 1].y + 14;
    ends.forEach(function (e) { var t = S('text', { x: e.x + 8, y: e.y + 4, class: 'lbl ' + e.cls }); t.textContent = e.name; svg.appendChild(t); });
    return h('div', { class: 'chartwrap' }, svg);
  }
  function niceStep(raw) {
    var p = Math.pow(10, Math.floor(Math.log10(Math.abs(raw) || 1))), n = raw / p;
    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * p;
  }

  /* =====================================================================
     Preventivo (ranking por país, categoría que más se repite, regla sugerida)
     ===================================================================== */
  function viewPreventive() {
    var m = page('preventivo');
    if (!VERSION) { noVersion(m, 'Preventivo'); return; }
    add(m, header(versionLabel(VERSION), 'Preventivo', 'Qué país necesita apoyo antes del cierre: hallazgos de esta versión frente al historial 2024–2026, la categoría que más se repite y la regla que la evitaría.'));
    var body = skel(3); add(m, body);
    var load = function () {
      Promise.all([api('/findings' + qs({ version: VERSION })), api('/quality').catch(function () { return null; })]).then(function (r) {
        ready(body);
        var Q = r[0] && r[0].history, deliv = r[1];
        if (!Q || !Q.countries) {
          add(body, note('warn', 'alert', 'El análisis histórico no está disponible por ahora', 'Mientras tanto, revisa los hallazgos de la versión.', h('div', { class: 'acts' }, linkBtn('Ir a hallazgos', '#/proceso', 'small'))));
        } else {
          var countries = obj(Q.countries), ranking = arr(Q.ranking).length ? arr(Q.ranking) : Object.keys(countries);
          var rc = card('Ranking por país', 'Primero el país con más hallazgos en esta versión. La tendencia compara con su último ciclo del historial.');
          add(rc, table([['Prioridad', 'num'], ['País'], ['Hallazgos', 'num'], ['Último ciclo', 'num'], ['Tendencia'], ['Categoría que más se repite'], ['Regla preventiva sugerida']], ranking.map(function (code, i) {
            var x = countries[code] || {}, sv = obj(x.severity), tr = TREND[x.trend] || [x.trend || '—', 'neutral', 'dot'];
            return h('tr', {}, tdn(String(i + 1)), h('th', { scope: 'row', text: coLabel(code) }),
              tdn(h('span', {}, h('b', { text: num(x.total) }), h('small', { text: num(sv.critico) + ' crít. · ' + num(sv.aviso) + ' av.' }))), tdn(num(x.last_total)),
              td(h('span', { class: 'tag t-' + tr[1] }, icon(tr[2], 13), h('span', { text: tr[0] }))),
              td(x.top_category ? h('div', {}, h('b', { text: catLabel(x.top_category) }), h('small', { text: num(x.top_count) + ' vez/veces entre historial y versión actual' })) : '—'),
              h('td', { class: 'wide' }, x.rule ? h('div', {}, h('span', { text: x.rule }), x.message ? h('small', { text: x.message }) : null) : h('small', { text: x.message || '—' })));
          }), { cards: true, caption: 'Ranking preventivo' }));
          add(body, rc);
          add(body, h('div', { class: 'grid2' }, (function () { var c = card('Hallazgos actuales por categoría', 'Todos los países'); add(c, categoryBars(obj(Q.by_category))); return c; })(),
            deliveryCard(deliv)));
          var hc = card('Historial por país', 'Errores por categoría, días de retraso, reenvíos y esfuerzo para consolidar en ciclos anteriores.');
          ranking.forEach(function (code) {
            var x = countries[code] || {}, hist = arr(x.history);
            var det = h('details', { class: 'more' }, h('summary', { text: coLabel(code) + ' · ' + hist.length + ' ciclo(s) en el historial' }));
            if (hist.length) add(det, table([['Ciclo']].concat(categories().map(function (k) { return [catLabel(k), 'num']; }), [['Total', 'num'], ['Días de retraso', 'num'], ['Reenvíos', 'num'], ['Días para consolidar', 'num'], ['Horas de analista', 'num']]),
              hist.map(function (y) { var e = obj(y.errors); return h('tr', {}, h('th', { scope: 'row', text: y.cycle || String(y.year) }), categories().map(function (k) { return tdn(num(e[k])); }), tdn(num(y.total)), tdn(num(y.delay_days)), tdn(num(y.resubmissions)), tdn(num(y.days_to_consolidate)), tdn(num(y.analyst_hours))); })
                .concat([h('tr', { class: 'total' }, h('th', { scope: 'row', text: versionLabel(VERSION) + ' (actual)' }), categories().map(function (k) { return tdn(num(obj(x.current)[k])); }), tdn(num(x.total)), td(''), td(''), td(''), td(''))]),
              { sticky: true, caption: 'Historial de ' + code }));
            else add(det, h('p', { class: 'muted small', text: 'Sin historial.' }));
            add(hc, det);
          });
          add(body, hc);
        }
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }
  /** Entrega en el ciclo actual (GET /quality): subidas, críticos y horas hasta enviar. */
  function deliveryCard(q) {
    var cd = card('Entrega en ' + versionLabel(VERSION), 'Subidas, críticos de la última subida y horas desde la primera subida hasta el envío');
    var rows = arr(q && q.rows).filter(function (r) { return r.version === VERSION; });
    if (!rows.length) { add(cd, empty('Sin subidas en esta versión.', null)); return cd; }
    add(cd, table([['País'], ['Estado'], ['Subidas', 'num'], ['Críticos', 'num'], ['Avisos', 'num'], ['Horas a enviar', 'num']], rows.map(function (r) {
      return h('tr', {}, td(coLabel(r.code)), td(uploadTag({ status: r.status })), tdn(num(r.uploads)), tdn(num(r.criticals), Number(r.criticals) ? 'neg' : ''), tdn(num(r.warnings)), tdn(isNum(r.hours_to_send) ? num(r.hours_to_send, 1) + ' h' : 'Sin enviar'));
    }), { cards: true, caption: 'Entrega por país' }));
    return cd;
  }

  /* =====================================================================
     Perfiles de lectura (mapa de etiquetas por país)
     ===================================================================== */
  var PROFILE_MAPS = [['pl', 'Resultados'], ['bs', 'Balance']];
  var SHEET_LBL = { portada: 'Portada', resultados: 'Resultados', balance: 'Balance', intercompania: 'Intercompañía', ventas_detalle: 'Detalle de ventas', inventario_grupo: 'Inventario del grupo', capex: 'Capex', indicadores_reportados: 'Indicadores reportados' };
  function viewProfiles(code) {
    var m = page('perfiles');
    add(m, header('Apoyo', 'Perfiles de lectura', 'Cada país manda su Excel en su propio formato. El perfil le dice al sistema cómo leerlo: idioma, fechas, escala y qué cuenta del catálogo es cada etiqueta. Una etiqueta sin mapeo es un hallazgo (CAT_UNKNOWN).'));
    var body = skel(2); add(m, body);
    var load = function () {
      api('/profiles').then(function (list) {
        ready(body);
        list = arr(list);
        if (!list.length) { add(body, empty('No hay países.', 'Importa el archivo corporativo en «Datos y demo».')); return; }
        var cur = list.filter(function (p) { return p.code === code; })[0] || list[0];
        add(body, h('div', { class: 'card' }, h('div', { class: 'filters' }, field('País', select(list.map(function (p) { return [p.code, coLabel(p.code)]; }), cur.code, { onchange: function (e) {
          if (DIRTY && !confirm('Hay cambios sin guardar en el perfil. ¿Cambiar de país sin guardar?')) { e.target.value = cur.code; return; }
          location.hash = '#/perfiles/' + e.target.value;
        } })))));
        add(body, profileEditor(cur, load));
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }
  function profileEditor(P, reload) {
    var box = h('div', { class: 'stack' });
    var prof = P.profile ? JSON.parse(JSON.stringify(P.profile)) : null;
    var src = { guardado: ['Editado', 'info'], predeterminado: ['Predeterminado', 'ok'], ninguno: ['Sin perfil', 'bad'] }[P.source] || [P.source || '—', 'neutral'];
    var info = card(coLabel(P.code) + ' · ' + (P.name || ''), P.updated_at ? 'Editado por ' + (P.updated_by || '—') + ' el ' + dt(P.updated_at) : 'Perfil construido a partir de los archivos reales del país', tag(src[0], src[1]));
    if (!prof) { add(info, empty('Este país no tiene perfil de lectura.', 'Sin perfil no se pueden leer sus archivos.')); add(box, info); return box; }
    var dl = h('dl', { class: 'dl' });
    [['Idioma', prof.language], ['Orden de fecha', prof.date_order === 'mdy' ? 'mes/día/año' : prof.date_order === 'dmy' ? 'día/mes/año' : prof.date_order], ['Formato de fecha', prof.date_format],
      ['Números', prof.number_format === 'text' ? 'Guardados como texto' : 'Numéricos'], ['Separador decimal', prof.decimal], ['Escala', prof.scale], ['Archivos que empiezan con', arr(prof.file_prefix).join(', ')]]
      .forEach(function (x) { if (x[1]) add(dl, [h('dt', { text: x[0] }), h('dd', { text: String(x[1]) })]); });
    add(info, dl);
    var sh = obj(prof.sheets);
    add(info, table([['Hoja'], ['Formato'], ['Fila de encabezado', 'num'], ['Columnas']], Object.keys(sh).map(function (k) {
      var s = obj(sh[k]), cols = s.columns ? Object.keys(obj(s.columns)).map(function (c) { return c + ' → ' + s.columns[c]; }).join(' · ') : s.label_col ? 'Etiqueta en ' + s.label_col : s.key_col ? 'Clave ' + s.key_col + ', valor ' + s.value_col : '—';
      return h('tr', {}, h('th', { scope: 'row', text: SHEET_LBL[k] || k }), td(({ wide: 'Ancho (meses en columnas)', long: 'Largo (una fila por dato)', table: 'Tabla', kv: 'Clave-valor' })[s.layout] || s.layout || '—'), tdn(isNum(s.header_row) ? String(s.header_row) : '—'), h('td', { class: 'wide' }, h('small', { class: 'mono', text: cols })));
    }), { cards: true, caption: 'Hojas del perfil' }));
    add(box, info);

    // Conceptos válidos para el mapa: cuentas del catálogo + estadísticos por línea + fila de total.
    var concepts = META.catalog.map(function (a) { return String(a.code); });
    lines().forEach(function (l) { concepts.push('volume:' + l, 'price:' + l, 'net_sales:' + l); });
    concepts.push('total_row');
    var dlId = 'csl-concepts';
    var datalist = h('datalist', { id: dlId }, concepts.map(function (c) { return h('option', { value: c, label: conceptName(c) }); }));
    var mc = card('Mapa de etiquetas', 'Texto o código tal como aparece en el archivo → concepto del catálogo. Las mayúsculas, tildes y espacios repetidos no importan.');
    watchDirty(mc);
    var which = 'pl', holder = h('div'), q = h('input', { type: 'search', placeholder: 'Buscar etiqueta o concepto', 'data-nodirty': '1' });
    var tbodies = {};
    PROFILE_MAPS.forEach(function (pm) {
      var map = obj(obj(prof.maps)[pm[0]]), tb = h('tbody');
      Object.keys(map).forEach(function (label) { tb.appendChild(mapRow(label, map[label], concepts, dlId)); });
      tbodies[pm[0]] = tb;
    });
    var draw = function () {
      var tb = tbodies[which], t = q.value.trim().toLowerCase();
      Array.prototype.forEach.call(tb.rows, function (tr) { tr.hidden = !!t && tr.textContent.toLowerCase().indexOf(t) < 0 && tr._get().label.toLowerCase().indexOf(t) < 0 && tr._get().concept.toLowerCase().indexOf(t) < 0; });
      clear(holder).appendChild(h('div', { class: 'tablewrap' }, h('table', { class: 'cards' }, h('thead', {}, h('tr', {}, h('th', { scope: 'col', text: 'Etiqueta en el archivo' }), h('th', { scope: 'col', text: 'Concepto' }), h('th', { scope: 'col', text: 'Significa' }), h('th', { scope: 'col', text: '' }))), tb)));
    };
    q.oninput = draw;
    add(mc, [datalist, h('div', { class: 'filters' }, seg(PROFILE_MAPS, which, function (v) { which = v; draw(); }, 'Hoja'), field('Buscar', q)), holder,
      h('div', { class: 'acts' },
        btn('Agregar etiqueta', function () { var tr = mapRow('', '', concepts, dlId); tbodies[which].appendChild(tr); q.value = ''; draw(); tr.querySelector('input').focus(); DIRTY = true; }, 'ghost', {}, 'plus'),
        btn('Guardar perfil', function (e) {
          var maps = {}, seen = {}, bad = null;
          PROFILE_MAPS.forEach(function (pm) {
            maps[pm[0]] = {};
            Array.prototype.forEach.call(tbodies[pm[0]].rows, function (tr) {
              var x = tr._get();
              if (!x.label) return;
              var k = pm[0] + '|' + x.label.toLowerCase();
              if (seen[k]) bad = bad || 'La etiqueta «' + x.label + '» está repetida en ' + pm[1] + '.';
              seen[k] = true;
              if (concepts.indexOf(x.concept) < 0) bad = bad || 'El concepto «' + x.concept + '» de «' + x.label + '» no existe en el catálogo.';
              maps[pm[0]][x.label] = x.concept;
            });
          });
          if (bad) { toast(bad, 'bad'); return; }
          var np = JSON.parse(JSON.stringify(prof));
          np.maps = Object.assign({}, obj(np.maps), maps);
          busy(e.currentTarget, post('/profiles/' + encodeURIComponent(P.code), { profile: np })).then(function () { DIRTY = false; toast('Perfil de ' + P.code + ' guardado. Las próximas subidas se leen con él.', 'ok'); reload(); }, fail);
        }, 'primary'),
        P.source === 'guardado' ? btn('Restablecer predeterminado', function () {
          confirmBox('Restablecer perfil', 'Se borra el perfil editado de ' + coLabel(P.code) + ' y se vuelve al predeterminado. Los archivos ya subidos no cambian.', 'Restablecer', function () {
            return post('/profiles/' + encodeURIComponent(P.code), { reset: true }).then(function () { DIRTY = false; toast('Perfil restablecido.', 'ok'); reload(); });
          }, 'danger');
        }, 'ghost', {}, 'refresh') : null)]);
    draw();
    add(box, mc);
    // Edición avanzada del perfil completo (formatos de hoja, mapas de línea, canal, contraparte…).
    var ta = h('textarea', { class: 'mono', rows: '16', spellcheck: 'false', 'aria-label': 'Perfil completo en JSON' });
    ta.value = JSON.stringify(prof, null, 2);
    var adv = h('details', { class: 'more card' }, h('summary', { text: 'Edición avanzada (perfil completo en JSON)' }),
      h('p', { class: 'muted small', text: 'Para cambiar hojas, columnas, formatos o los demás mapas (líneas, canales, productos, tipos y sentidos de intercompañía, contrapartes). Guarda solo si sabes lo que cambias.' }), ta,
      btn('Guardar JSON', function (e) {
        var p;
        try { p = JSON.parse(ta.value); } catch (er) { toast('El JSON no es válido: ' + er.message, 'bad'); ta.focus(); return; }
        if (!p || typeof p !== 'object' || Array.isArray(p)) { toast('El perfil debe ser un objeto JSON.', 'bad'); return; }
        busy(e.currentTarget, post('/profiles/' + encodeURIComponent(P.code), { profile: p })).then(function () { DIRTY = false; toast('Perfil guardado.', 'ok'); reload(); }, fail);
      }, ''));
    add(box, adv);
    return box;
  }
  function mapRow(label, concept, concepts, dlId) {
    var li = h('input', { value: label, 'aria-label': 'Etiqueta en el archivo' });
    var ci = h('input', { value: concept, list: dlId, class: 'mono', 'aria-label': 'Concepto para ' + (label || 'la etiqueta nueva') });
    var mean = h('small', { text: conceptName(concept) || (concept ? 'No existe en el catálogo' : '—') });
    var upd = function () { var n = conceptName(ci.value.trim()); mean.textContent = n || (ci.value ? 'No existe en el catálogo' : '—'); mean.className = n || !ci.value ? '' : 'c-bad'; };
    ci.addEventListener('input', upd); upd();
    var tr = h('tr', {}, td(li), td(ci), td(mean), td(h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Quitar etiqueta', title: 'Quitar', onclick: function () { tr.remove(); DIRTY = true; } }, icon('trash'))));
    tr._get = function () { return { label: li.value.trim(), concept: ci.value.trim() }; };
    return tr;
  }

  /* =====================================================================
     Bitácora
     ===================================================================== */
  function viewLog(pageNo) {
    var m = page('bitacora');
    pageNo = Math.max(1, Number(pageNo) || 1);
    add(m, header('Apoyo', 'Bitácora', 'Quién hizo qué y cuándo. No se puede editar.'));
    var body = skel(2); add(m, body);
    var load = function () {
      api('/log' + qs({ page: pageNo, per: 50 })).then(function (x) {
        ready(body);
        var list = arr(x, 'items'), total = Number(x && x.total) || list.length, per = Number(x && x.per) || 50, pages = Math.max(1, Math.ceil(total / per));
        if (!list.length) { add(body, empty('La bitácora está vacía.', 'Se llena con cada carga, aprobación, consolidación y cambio de reglas.')); return; }
        var q = h('input', { type: 'search', placeholder: 'Buscar en esta página' });
        var holder = h('div');
        var detail = function (d) {
          if (d === null || d === undefined || d === '') return '';
          if (typeof d !== 'object') return String(d);
          return Object.keys(d).map(function (k) { var v = d[k]; return k + ': ' + (typeof v === 'object' ? JSON.stringify(v) : v); }).join(' · ').slice(0, 300);
        };
        var draw = function () {
          clear(holder);
          var t = q.value.trim().toLowerCase();
          var shown = list.filter(function (l) { return !t || JSON.stringify(l).toLowerCase().indexOf(t) >= 0; });
          if (!shown.length) { add(holder, empty('Nada coincide con la búsqueda.', null)); return; }
          add(holder, table([['Fecha'], ['Usuario'], ['Acción'], ['Objeto'], ['Detalle']], shown.map(function (l) {
            return h('tr', {}, td(dt(l.at)), td(l.user || (l.user_id ? 'Usuario #' + l.user_id : 'Sistema')), td(LOG_LBL[l.action] || l.action || '—'),
              td([OBJ_LBL[l.object_type] || l.object_type, l.object_id ? '#' + l.object_id : ''].filter(Boolean).join(' ') || '—'), h('td', { class: 'wide' }, h('small', { class: 'mono', text: detail(l.detail) })));
          }), { cards: true, caption: 'Bitácora' }));
        };
        q.oninput = draw;
        var cd = card(null);
        add(cd, [h('div', { class: 'filters' }, field('Buscar', q)), holder,
          h('nav', { class: 'pager', 'aria-label': 'Páginas de la bitácora' },
            pageNo > 1 ? linkBtn('Anterior', '#/bitacora/' + (pageNo - 1), 'ghost small') : null,
            h('span', { class: 'small muted', text: 'Página ' + pageNo + ' de ' + pages + ' · ' + num(total) + ' registros' }),
            pageNo < pages ? linkBtn('Siguiente', '#/bitacora/' + (pageNo + 1), 'ghost small') : null)]);
        add(body, cd);
        draw();
      }, function (e) { errorState(body, e, load); });
    };
    load();
  }

  /* =====================================================================
     Datos y demo (importar corporativo, datos de Meridia, reiniciar)
     ===================================================================== */
  function viewData() {
    var m = page('datos'), c = caps();
    add(m, header('Apoyo', 'Datos y demo', 'Importa el archivo corporativo del grupo y, como administrador, los archivos reales de los 5 países que están en el servidor.'));
    var cf = META.corporateFile;
    if (c.corp) {
      var cc = card('Archivo corporativo', 'Grupo_Corporativo.xlsx: países, tasas oficiales, catálogo, productos, canales, propuestas e historial', META.hasCorporate ? tag('Importado', 'ok') : tag('Falta', 'warn'));
      if (cf) add(cc, h('small', { text: 'Último: ' + (cf.file || '') + ' · ' + dt(cf.imported_at) }));
      var input = h('input', { type: 'file', accept: '.xlsx', id: 'csl-corp-file' });
      var res = h('div', { 'aria-live': 'polite' });
      var done = function (r) {
        toast('Archivo corporativo importado.', 'ok');
        var counts = obj(r && r.counts);
        clear(res).appendChild(h('dl', { class: 'dl' }, Object.keys(counts).map(function (k) { return [h('dt', { text: k.replace(/_/g, ' ') }), h('dd', { text: String(counts[k]) })]; })));
        return reloadMeta();
      };
      add(cc, [h('div', { class: 'form-row' }, field('Subir Grupo_Corporativo.xlsx', input), btn('Importar archivo', function (e) {
        var f = input.files && input.files[0];
        if (!f) { toast('Elige el archivo .xlsx.', 'bad'); input.focus(); return; }
        var fd = new FormData(); fd.append('file', f, f.name);
        busy(e.currentTarget, api('/corporate/import', { method: 'POST', form: fd })).then(done).catch(fail);
      }, 'primary', {}, 'upload'), btn('Leer del servidor', function (e) {
        busy(e.currentTarget, post('/corporate/import', { source: 'server' })).then(done).catch(fail);
      }, 'ghost', {}, 'database')), res]);
      add(m, cc);
    }
    if (c.admin) {
      var ac = card('Datos de Meridia', 'Solo administradores');
      add(ac, h('p', { class: 'muted', text: 'Importa el corporativo y los 20 archivos de país (4 versiones × 5 países) desde la carpeta del servidor, por el mismo flujo de lectura y validación que una carga normal.' }));
      add(ac, h('div', { class: 'acts' }, btn('Importar datos de Meridia', function () { importMeridia(); }, 'primary', {}, 'database'),
        btn('Reiniciar todo', function () {
          confirmType('Reiniciar todos los datos', 'Se borran países, versiones, tasas, perfiles editados, archivos, hallazgos, resultados, acciones y bitácora del plugin. No se puede deshacer.', 'BORRAR', 'Borrar todo', function (v) {
            return post('/demo/reset', { confirm: v }).then(function () {
              toast('Datos borrados.', 'ok');
              return reloadMeta().then(function () { setVersion(null); pickVersion(); fillVersionSel(); route(); });
            });
          });
        }, 'danger', {}, 'trash')));
      add(m, ac);
    }
  }

  /* =====================================================================
     Rutas
     ===================================================================== */
  var LAST_HASH = null, SKIP = false, FIRST = true;
  /** Rutas viejas → lugar nuevo en el flujo de 4 pasos (devuelve el hash nuevo o null). */
  function legacy(p) {
    var k = p[0], rest = p.slice(1).filter(function (x) { return x !== ''; });
    var to = { hallazgos: '#/proceso', consolidacion: '#/analisis/consolidacion', consolidar: '#/analisis/consolidacion', indicadores: '#/analisis/indicadores', propuestas: '#/analisis/proyectos',
      resumen: '#/inicio', acciones: '#/analisis/acciones', evolutivo: '#/analisis/evolutivo', preventivo: '#/analisis/preventivo', demo: '#/datos', calidad: '#/analisis/preventivo', empresas: '#/reglas' }[k];
    if (to) return to;
    if (k === 'cargar' || k === 'revisar') return '#/proceso' + (rest[0] ? '/' + rest[0] : '');
    if (k === 'resultado') return rest[0] && rest[0] !== 'tablero' ? '#/analisis/' + rest.join('/') : '#/inicio';
    if (k === 'desviaciones') return '#/analisis/desviaciones' + (rest.length ? '/' + rest.join('/') : '');
    if (k === 'carga') { var co = companyById(p[1]); return '#/proceso' + (co ? '/' + co.code : ''); }
    return null;
  }
  function route() {
    if (SKIP) { SKIP = false; return; }
    if (DIRTY && LAST_HASH !== null && location.hash !== LAST_HASH && !confirm('Tienes cambios sin guardar. ¿Salir sin guardar?')) { SKIP = true; location.hash = LAST_HASH; return; }
    DIRTY = false;
    TAB_HOST = null;
    if (gearMenu) setGear(false);
    var p = (location.hash || '').replace(/^#\/?/, '').split('/').map(function (x) { try { return decodeURIComponent(x); } catch (e) { return x; } });
    var k = p[0] || 'inicio', c = caps(), cb = c.corp || c.board;
    var moved = legacy(p);
    if (moved) { location.replace(moved); return; }
    LAST_HASH = location.hash;
    window.scrollTo(0, 0);
    // validacion/{id} y enviar/{id}: se busca el país de la subida y se va a «Revisar».
    if ((k === 'validacion' || k === 'enviar') && p[1]) {
      add(page('proceso'), skel(2));
      api('/uploads/' + encodeURIComponent(p[1])).then(function (r) {
        var u = (r && r.upload) || {}, co = companyById(u.company_id);
        if (u.version && u.version !== VERSION && versionByKey(u.version)) setVersion(u.version);
        location.replace('#/proceso' + (co ? '/' + co.code : u.company ? '/' + u.company : ''));
      }, function () { location.replace('#/proceso'); });
      return;
    }
    var views = {
      inicio: [goHome, true],
      proceso: [function () { viewProcess(p[1]); }, c.country || c.corp],
      analisis: [function () { viewAnalysis(p[1], p.slice(2)); }, cb],
      reglas: [viewRules, c.corp], perfiles: [function () { viewProfiles(p[1]); }, c.corp],
      bitacora: [function () { viewLog(p[1]); }, c.corp], datos: [viewData, c.corp || c.admin]
    };
    var v = views[k];
    if (!v) { location.replace('#/inicio'); return; }
    if (!v[1]) { noAccess(page(k)); return; }
    v[0]();
    if (!FIRST) { var h1 = main.querySelector('h1'); (h1 || main).focus({ preventScroll: true }); }
    FIRST = false;
  }

  function boot() {
    clear(app).appendChild(h('p', { class: 'boot', role: 'status', text: 'Cargando Trazo…' }));
    Promise.all([api('/me'), api('/meta')]).then(function (r) {
      ME = r[0] || {}; ME.caps = ME.caps || {}; ME.companies = ME.companies || [];
      META = normMeta(r[1]);
      pickVersion();
      buildShell();
      window.addEventListener('hashchange', route);
      route();
    }, function (e) {
      var box = h('div', { class: 'boot' });
      clear(app).appendChild(box);
      errorState(box, e, boot);
    });
  }
  boot();
})();
