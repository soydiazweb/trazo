/**
 * Trazo · IA en el panel /trazo/ — botón «Generar plan con IA» en cada oportunidad de mejora.
 *
 * No toca panel.js: observa el panel y añade el botón junto a «Crear plan de acción».
 * La IA se llama desde el servidor (trazo-ia/v1). El plan se edita aquí y cada acción se guarda con la ruta
 * que ya tiene el panel (consolida/v1/actions), así aparece en «Acciones y seguimiento».
 */
(function () {
  'use strict';
  var C = window.CSL;
  if (!C || !C.api) return;
  var CAPS = (C.user && C.user.caps) || {};
  if (!CAPS.board && !CAPS.corp) return;

  var API = String(C.api).replace(/\/+$/, '');
  var IA = API.replace(/consolida\/v1$/, 'trazo-ia/v1');
  var estado = null;

  /* ---------- utilidades ---------- */
  function h(tag, attrs) {
    var el = document.createElement(tag);
    var a = attrs || {};
    Object.keys(a).forEach(function (k) {
      var v = a[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'text') el.textContent = v;
      else if (k === 'class') el.className = v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) {
      var c = arguments[i];
      if (c === null || c === undefined || c === false) continue;
      [].concat(c).forEach(function (x) { if (x !== null && x !== undefined && x !== false) el.appendChild(typeof x === 'string' ? document.createTextNode(x) : x); });
    }
    return el;
  }
  function call(base, path, method, body) {
    var init = { method: method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce || '', Accept: 'application/json' } };
    if (body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
    return fetch(base + path, init).then(function (r) {
      return r.text().then(function (t) {
        var j = null;
        try { j = t ? JSON.parse(t) : null; } catch (e) { j = null; }
        if (!r.ok) throw new Error((j && j.message) || ('Error ' + r.status + '. Inténtalo de nuevo.'));
        return j;
      });
    }, function () { throw new Error('Sin conexión con el servidor.'); });
  }
  function isoIn(days) {
    var d = new Date(); d.setDate(d.getDate() + (Number(days) || 30));
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }
  function fmt(n) { return String(n).replace('.', ','); }
  function store(k, v) {
    try { if (v === undefined) return localStorage.getItem(k); if (v === '') localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { /* sin almacenamiento */ }
    return null;
  }
  function versionFromUrl() {
    var sel = document.getElementById('csl-version');
    if (sel && sel.value) return sel.value;
    var m = /[?&]version=([a-z0-9_]+)/i.exec(location.hash || '') || /[?&]version=([a-z0-9_]+)/i.exec(location.search || '');
    return m ? m[1] : '';
  }
  function field(label, input, hint) {
    var id = 'tia' + Math.random().toString(36).slice(2, 9);
    input.id = id;
    return h('div', { class: 'field' }, h('label', { for: id, text: label }), input, hint ? h('small', { class: 'hint', text: hint }) : null);
  }
  function btn(text, onclick, kind) {
    return h('button', { type: 'button', class: 'btn ' + (kind || ''), onclick: onclick }, h('span', { text: text }));
  }

  /* ---------- estilos propios (mínimos; el resto usa los del panel) ---------- */
  document.head.appendChild(h('style', { text: [
    '.tia-acts{display:flex;flex-direction:column;gap:6px;align-items:stretch}',
    '.tia-btn{background:#4f46e5;border-color:#4f46e5;color:#fff}',
    '.tia-btn:hover{filter:brightness(1.1);color:#fff;border-color:#4f46e5}',
    '.tia-note{font-size:12.5px;color:var(--muted)}',
    '.tia-warn{font-size:12.5px;color:var(--bad);font-weight:600}',
    '.tia-sum{background:var(--surface-2,rgba(0,0,0,.03));border:1px solid var(--line);border-radius:10px;padding:12px 14px;font-size:13.5px}',
    '.tia-list{display:flex;flex-direction:column;gap:12px}',
    '.tia-item{border:1px solid var(--line);border-radius:12px;padding:12px 14px;display:flex;flex-direction:column;gap:10px}',
    '.tia-item.off{opacity:.5}',
    '.tia-item-h{display:flex;justify-content:space-between;align-items:center;gap:8px;font-weight:600;font-size:13px}',
    '.tia-item textarea{min-height:120px}',
    '.tia-load{padding:28px 8px;text-align:center;color:var(--muted)}',
    '@media (max-width:700px){.tia-acts{align-items:flex-start}}'
  ].join('\n') }));

  /* ---------- botón en cada oportunidad ---------- */
  function readOpp(art) {
    var t = art.querySelector('.opp-b h3');
    var ps = art.querySelectorAll('.opp-b p');
    var fx = art.querySelectorAll('.opp-fx span');
    return {
      title: t ? t.textContent.trim() : '',
      text: ps.length ? ps[0].textContent.trim() : '',
      effects: [].map.call(fx, function (s) { return s.textContent.trim(); })
    };
  }
  function decorate(root) {
    if (CAPS.corp) decorateProfiles(root);
    if (!CAPS.board) return;
    [].forEach.call(root.querySelectorAll('article.opp:not([data-tia])'), function (art) {
      art.setAttribute('data-tia', '1');
      // Panel 0.7+: los botones viven en .opp-acts; ahí va el nuestro, primero.
      var acts = art.querySelector('.opp-acts');
      if (acts) {
        acts.insertBefore(h('button', { type: 'button', class: 'btn small tia-btn', onclick: function () { openPlan(readOpp(art)); } }, h('span', { 'aria-hidden': 'true', text: '✦' }), h('span', { text: 'Generar plan con IA' })), acts.firstChild);
        return;
      }
      var theirs = null;
      [].forEach.call(art.children, function (ch) { if (ch.tagName === 'BUTTON') theirs = ch; });
      var ours = h('button', { type: 'button', class: 'btn small tia-btn', onclick: function () { openPlan(readOpp(art)); } }, h('span', { 'aria-hidden': 'true', text: '✦' }), h('span', { text: 'Generar plan con IA' }));
      var box = h('div', { class: 'tia-acts' });
      if (theirs) { art.insertBefore(box, theirs); box.appendChild(theirs); } else art.appendChild(box);
      box.insertBefore(ours, box.firstChild);
    });
  }
  /* ---------- reglas del país: etiquetas sin cuenta en «Perfiles de lectura» ---------- */
  function decorateProfiles(root) {
    if (!/^#\/perfiles/.test(location.hash || '')) return;
    if (root.querySelector('[data-tia-map]')) return;
    var h1 = [].filter.call(root.querySelectorAll('h1'), function (x) { return /Perfiles de lectura/.test(x.textContent); })[0];
    if (!h1) return;
    var anchor = h1.closest('header') || h1.parentNode;
    if (!anchor || !anchor.parentNode) return;
    var card = mapCard();
    anchor.parentNode.insertBefore(card, anchor.nextSibling);
  }
  function mapCard() {
    var list = h('div', { class: 'tia-list' });
    var go = btn('Proponer cuentas con IA', propose, 'small tia-btn');
    var card = h('section', { class: 'card stack', 'data-tia-map': '1' },
      h('div', {}, h('h2', { text: 'Etiquetas sin cuenta · propuesta con IA' }),
        h('p', { class: 'tia-note', text: 'La IA propone una cuenta del catálogo para cada etiqueta del Excel que hoy no tiene. Tú eliges la cuenta y decides si se guarda en el perfil del país. La IA no guarda nada sola.' })),
      list);
    list.appendChild(h('p', { class: 'tia-note', text: 'Buscando etiquetas sin cuenta…' }));
    call(IA, '/tarea/mapeo-etiquetas/pendientes').then(function (rows) {
      rows = rows || [];
      if (!rows.length) { list.replaceChildren(h('p', { class: 'c-ok', text: 'No hay etiquetas sin cuenta abiertas.' })); return; }
      list.replaceChildren(h('p', { class: 'tia-note', text: rows.length + (rows.length === 1 ? ' etiqueta sin cuenta: ' : ' etiquetas sin cuenta: ') + rows.map(function (r) { return '«' + r.etiqueta + '» (' + r.pais_nombre + ')'; }).join(', ') + '.' }), h('div', {}, go));
    }, function (e) { list.replaceChildren(h('p', { class: 'tia-warn', text: e.message })); });

    function propose() {
      go.disabled = true; go.setAttribute('aria-busy', 'true');
      list.replaceChildren(h('div', { class: 'tia-load', role: 'status', text: 'La IA está revisando las etiquetas contra el catálogo…' }));
      call(IA, '/tarea/mapeo-etiquetas', 'POST', {}).then(function (res) {
        var props = res.propuestas || [];
        if (!props.length) { list.replaceChildren(h('p', { class: 'c-ok', text: res.mensaje || 'No hay etiquetas pendientes.' })); return; }
        list.replaceChildren(h('p', { class: 'tia-note', text: 'Borrador de IA (' + res.modelo + '). Revisa la cuenta antes de aplicar.' }));
        props.forEach(function (p) {
          var sel = h('select', {});
          sel.appendChild(h('option', { value: '', text: '— Elegir cuenta —' }));
          (res.catalogo || []).forEach(function (c) { sel.appendChild(h('option', { value: c.code, text: c.code + ' · ' + c.name, selected: c.code === p.cuenta })); });
          var msg = h('p', { class: 'tia-note' });
          var apply = btn('Aplicar al perfil de ' + p.pais_nombre, function () {
            if (!sel.value) { msg.className = 'tia-warn'; msg.textContent = 'Elige una cuenta del catálogo.'; return; }
            apply.disabled = true; apply.setAttribute('aria-busy', 'true');
            call(IA, '/tarea/mapeo-etiquetas/aplicar', 'POST', { pais: p.pais, hoja: p.hoja, etiqueta: p.etiqueta, cuenta: sel.value, modelo: res.modelo }).then(function (r) {
              msg.className = 'c-ok';
              msg.textContent = 'Guardado: «' + r.etiqueta + '» → ' + r.cuenta + ' · ' + r.cuenta_nombre + '. El hallazgo se cierra cuando se vuelva a validar el archivo de ' + p.pais_nombre + '. Recarga la página para verlo en el editor del perfil.';
              sel.disabled = true;
            }, function (e) { msg.className = 'tia-warn'; msg.textContent = e.message; apply.disabled = false; }).then(function () { apply.removeAttribute('aria-busy'); });
          }, 'primary small');
          list.appendChild(h('div', { class: 'tia-item' },
            h('div', { class: 'tia-item-h' }, h('span', { text: '«' + p.etiqueta + '» · ' + p.pais_nombre + ' · ' + p.hoja + '!A' + p.fila }), h('span', { class: p.confianza === 'alta' ? 'c-ok' : (p.confianza === 'baja' ? 'c-bad' : ''), text: 'Confianza ' + p.confianza })),
            p.total_ano !== null ? h('p', { class: 'tia-note', text: 'Total del año: ' + Number(p.total_ano).toLocaleString('es-GT') + ' ' + p.moneda + ' · no se está sumando hoy.' }) : null,
            p.motivo ? h('p', { text: p.motivo }) : null,
            p.cuenta === 'SIN_CUENTA' ? h('p', { class: 'tia-warn', text: 'La IA no encontró una cuenta segura. Elige una solo si estás seguro, o déjala pendiente.' }) : null,
            field('Cuenta del catálogo', sel),
            h('div', {}, apply), msg));
        });
      }, function (e) {
        list.replaceChildren(h('p', { class: 'tia-warn', role: 'alert', text: e.message }));
      }).then(function () { go.disabled = false; go.removeAttribute('aria-busy'); });
    }
    return card;
  }
  window.addEventListener('hashchange', function () { setTimeout(function () { decorate(app); }, 0); });

  var app = document.getElementById('csl-app') || document.body;
  new MutationObserver(function () { decorate(app); }).observe(app, { childList: true, subtree: true });
  decorate(app);

  /* ---------- ventana del plan ---------- */
  function modal(title) {
    var body = h('div', { class: 'modal-b' });
    var foot = h('div', { class: 'modal-f' });
    var d = h('dialog', { class: 'modal wide', 'aria-label': title },
      h('form', { method: 'dialog', class: 'modal-in', onsubmit: function (e) { e.preventDefault(); } },
        h('div', { class: 'modal-h' }, h('h2', { text: title }), h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Cerrar ventana', title: 'Cerrar', text: '×', style: 'font-size:22px', onclick: function () { d.close(); } })),
        body, foot));
    d.addEventListener('close', function () { d.remove(); });
    document.body.appendChild(d);
    if (d.showModal) d.showModal(); else d.setAttribute('open', '');
    return { d: d, body: body, foot: foot };
  }

  function openPlan(opp) {
    var m = modal('Plan de mejora con IA');
    var ctxKey = 'tia-ctx:' + opp.title;
    var ctx = h('textarea', { rows: '3', maxlength: '3000', placeholder: 'Ej.: La planta de Guatemala no se puede vender. El contrato del proveedor de Ecuador vence en 2028. No hay presupuesto para contratar.' });
    ctx.value = store(ctxKey) || '';
    ctx.addEventListener('input', function () { store(ctxKey, ctx.value); });
    var head = h('div', { class: 'stack tight' },
      h('p', {}, h('b', { text: opp.title })),
      opp.text ? h('p', { class: 'tia-note', text: opp.text }) : null,
      field('Contexto del cliente (opcional)', ctx, 'Lo que la IA debe respetar: restricciones, decisiones ya tomadas, lo que no se puede hacer. Manda sobre todo lo demás.'));
    var out = h('div', { class: 'stack' });
    m.body.appendChild(head);
    m.body.appendChild(out);

    var genBtn = btn('Generar plan', run, 'primary');
    var saveBtn = null;
    m.foot.appendChild(btn('Cancelar', function () { m.d.close(); }, 'ghost'));
    m.foot.appendChild(genBtn);

    var est = estado ? Promise.resolve(estado) : call(IA, '/estado').then(function (e) { estado = e; return e; }, function () { return null; });
    est.then(function (e) {
      if (e && !e.configurada) {
        out.replaceChildren(h('p', { class: 'tia-warn', text: 'La IA todavía no está configurada. Un administrador debe poner el proveedor, el modelo y la clave en WordPress → Ajustes → Trazo · IA.' }));
        genBtn.disabled = true;
      }
    });

    var plan = null;
    function run() {
      genBtn.disabled = true; genBtn.setAttribute('aria-busy', 'true');
      if (saveBtn) saveBtn.disabled = true;
      out.replaceChildren(h('div', { class: 'tia-load', role: 'status' }, 'La IA está preparando el plan con las cifras del panel… puede tardar hasta un minuto.'));
      call(IA, '/tarea/plan-mejora', 'POST', { opp: opp, contexto: ctx.value, version: versionFromUrl() }).then(function (p) {
        plan = p; render();
      }, function (e) {
        out.replaceChildren(h('p', { class: 'tia-warn', role: 'alert', text: e.message }));
      }).then(function () {
        genBtn.disabled = false; genBtn.removeAttribute('aria-busy');
        genBtn.querySelector('span').textContent = plan ? 'Volver a generar' : 'Generar plan';
      });
    }

    function render() {
      var items = plan.acciones.map(function (a) {
        var on = h('input', { type: 'checkbox', checked: true });
        var ti = h('input', { maxlength: '255' }); ti.value = a.titulo;
        var ow = h('input', { maxlength: '190' }); ow.value = a.responsable;
        var du = h('input', { type: 'date' }); du.value = isoIn(a.plazo_dias);
        var ef = h('textarea', { rows: '6', maxlength: '1900' });
        ef.value = [
          a.indicador ? 'Indicador: ' + a.indicador + (a.meta ? ' · Meta propuesta: ' + a.meta : '') : (a.meta ? 'Meta propuesta: ' + a.meta : ''),
          a.como ? 'Cómo: ' + a.como : '',
          a.supuestos ? 'Supuestos: ' + a.supuestos : '',
          a.riesgos ? 'Riesgos: ' + a.riesgos : ''
        ].filter(Boolean).join('\n');
        var card = h('div', { class: 'tia-item' },
          h('label', { class: 'tia-item-h' }, h('span', {}, on, ' Incluir en el plan'), null),
          a.cifras_no_verificadas && a.cifras_no_verificadas.length ? h('p', { class: 'tia-warn', text: 'Ojo: estas cifras no están en los datos del panel: ' + a.cifras_no_verificadas.map(fmt).join(' · ') + '. Revísalas o bórralas antes de guardar.' }) : null,
          field('Acción', ti),
          h('div', { class: 'grid2' }, field('Responsable', ow), field('Fecha límite', du)),
          field('Efecto esperado y cómo', ef));
        on.addEventListener('change', function () { card.classList.toggle('off', !on.checked); });
        return { a: a, on: on, ti: ti, ow: ow, du: du, ef: ef, card: card };
      });
      out.replaceChildren(
        plan.resumen ? h('div', { class: 'tia-sum' }, h('p', { text: plan.resumen }),
          plan.resumen_no_verificadas && plan.resumen_no_verificadas.length ? h('p', { class: 'tia-warn', text: 'Cifras del resumen que no están en los datos: ' + plan.resumen_no_verificadas.map(fmt).join(' · ') }) : null) : null,
        h('p', { class: 'tia-note', text: 'Borrador de IA (' + plan.modelo + ', ' + fmt((plan.ms / 1000).toFixed(1)) + ' s). Edita lo que haga falta: solo se guarda lo que marques.' }),
        h('div', { class: 'tia-list' }, items.map(function (x) { return x.card; })));

      if (!saveBtn) {
        saveBtn = btn('Guardar en Acciones', save, 'primary');
        m.foot.appendChild(saveBtn);
        genBtn.className = 'btn';
      }
      saveBtn.disabled = false;

      function save() {
        var pick = items.filter(function (x) { return x.on.checked; });
        if (!pick.length) { out.insertBefore(h('p', { class: 'tia-warn', role: 'alert', text: 'Marca al menos una acción.' }), out.firstChild); return; }
        var bad = pick.filter(function (x) { return !x.ti.value.trim() || !x.ow.value.trim() || !x.du.value; });
        if (bad.length) { out.insertBefore(h('p', { class: 'tia-warn', role: 'alert', text: 'Cada acción marcada necesita acción, responsable y fecha.' }), out.firstChild); bad[0].ti.focus(); return; }
        saveBtn.disabled = true; saveBtn.setAttribute('aria-busy', 'true');
        var sign = '\n— Propuesta por IA (' + plan.modelo + ') y revisada en el panel por ' + ((C.user && C.user.name) || 'el usuario') + '.';
        var chain = Promise.resolve(), done = 0;
        pick.forEach(function (x) {
          chain = chain.then(function () {
            return call(API, '/actions', 'POST', {
              title: x.ti.value.trim(), owner: x.ow.value.trim(), due_date: x.du.value,
              effect: (x.ef.value.trim() + sign).slice(0, 2000), version: plan.version || versionFromUrl()
            }).then(function () { done++; x.card.classList.add('off'); x.on.checked = false; x.on.disabled = true; });
          });
        });
        chain.then(function () {
          out.insertBefore(h('p', { class: 'c-ok', role: 'status', text: done + (done === 1 ? ' acción guardada' : ' acciones guardadas') + '. Síguelas en «Acciones y seguimiento».' }), out.firstChild);
        }, function (e) {
          out.insertBefore(h('p', { class: 'tia-warn', role: 'alert', text: 'Se guardaron ' + done + '. Falló una: ' + e.message }), out.firstChild);
          saveBtn.disabled = false;
        }).then(function () { saveBtn.removeAttribute('aria-busy'); });
      }
    }
  }
})();
