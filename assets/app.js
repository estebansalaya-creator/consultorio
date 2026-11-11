/* Funciones comunes a todas las páginas */
(function () {
  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.content : ''; };

  window.App = {
    base: meta('base'),
    csrf: meta('csrf'),

    /** Formato $ 12.500 / $ 12.500,50 */
    money: function (n) {
      n = Number(n) || 0;
      var dec = Math.abs(n - Math.round(n)) > 0.004 ? 2 : 0;
      return (n < -0.004 ? '-' : '') + '$ ' + Math.abs(n).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    },

    /** Convierte "1.234,50", "12.500" o "1234.5" a número */
    num: function (v) {
      v = String(v == null ? '' : v).replace(/[$\s]/g, '');
      if (v.indexOf(',') >= 0) v = v.replace(/\./g, '').replace(',', '.');
      else if (/^\d{1,3}(\.\d{3})+$/.test(v)) v = v.replace(/\./g, '');
      var n = parseFloat(v);
      return isNaN(n) ? 0 : n;
    },

    esc: function (s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    },

    /** Llamada a la API: GET si data es null, POST JSON si no */
    api: function (ruta, data) {
      var op = { headers: { 'X-CSRF': App.csrf, 'Accept': 'application/json' }, credentials: 'same-origin' };
      if (data != null) {
        op.method = 'POST';
        op.headers['Content-Type'] = 'application/json';
        op.body = JSON.stringify(data);
      }
      return fetch(App.base + ruta, op).then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'Respuesta inválida del servidor (' + r.status + ')' }; });
      });
    }
  };

  /**
   * Buscador con sugerencias (CIE-10, prácticas, medicamentos).
   * App.buscador(input, { url: function (q) {...}, lista: 'clave del array en la respuesta',
   *   item: function (r) { return html }, elegir: function (r) {...}, min: 2, vacio: 'texto' })
   */
  App.buscador = function (input, op) {
    var cont = input.parentNode;
    cont.classList.add('rel');
    var sug = document.createElement('div');
    sug.className = 'sugerencias';
    sug.hidden = true;
    cont.appendChild(sug);
    var lista = [], activo = -1, timer = null, pedido = 0;
    function pintar() {
      if (!lista.length) {
        sug.innerHTML = '<button type="button" disabled>' + App.esc(op.vacio || 'Sin resultados') + '</button>';
      } else {
        sug.innerHTML = lista.map(function (r, i) {
          return '<button type="button" data-i="' + i + '"' + (i === activo ? ' class="activo"' : '') + '>' + op.item(r) + '</button>';
        }).join('');
      }
      sug.hidden = false;
    }
    function elegir(r) { sug.hidden = true; op.elegir(r); }
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < (op.min || 2)) { sug.hidden = true; return; }
      timer = setTimeout(function () {
        var n = ++pedido;
        if (op.cargando) { sug.innerHTML = '<button type="button" disabled>' + App.esc(op.cargando) + '</button>'; sug.hidden = false; }
        App.api(op.url(q)).then(function (r) {
          if (n !== pedido) return;
          lista = r.ok ? (r[op.lista] || []) : [];
          activo = lista.length ? 0 : -1;
          if (op.respuesta) op.respuesta(r);
          pintar();
        });
      }, op.espera || 250);
    });
    input.addEventListener('keydown', function (ev) {
      if (sug.hidden) return;
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        activo = Math.max(0, Math.min(lista.length - 1, activo + (ev.key === 'ArrowDown' ? 1 : -1)));
        pintar();
      } else if (ev.key === 'Enter') {
        ev.preventDefault();
        if (lista[activo]) elegir(lista[activo]);
      } else if (ev.key === 'Escape') {
        sug.hidden = true;
      }
    });
    sug.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-i]');
      if (b) elegir(lista[+b.dataset.i]);
    });
    document.addEventListener('click', function (ev) { if (!cont.contains(ev.target)) sug.hidden = true; });
    return { cerrar: function () { sug.hidden = true; } };
  };

  /** Campo de diagnóstico CIE-10: un contenedor .js-cie10 con input[type=hidden] (código) e input de texto (diagnóstico) */
  App.campoCie10 = function (cont) {
    var cod = cont.querySelector('input[type=hidden]'), txt = cont.querySelector('input[type=text]'), chip = cont.querySelector('.cie-chip');
    function mostrar() {
      chip.hidden = !cod.value;
      chip.querySelector('b').textContent = cod.value;
    }
    App.buscador(txt, {
      url: function (q) { return 'api/cie10.php?q=' + encodeURIComponent(q); },
      lista: 'codigos', min: 2, vacio: 'Sin coincidencias en la CIE-10 (podés dejar el texto libre)',
      item: function (r) { return '<b>' + App.esc(r.codigo) + '</b> ' + App.esc(r.descripcion); },
      elegir: function (r) { cod.value = r.codigo; txt.value = r.descripcion; mostrar(); }
    });
    chip.querySelector('button').addEventListener('click', function () { cod.value = ''; mostrar(); txt.focus(); });
    mostrar();
  };
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-cie10').forEach(App.campoCie10);
  });

  // Confirmación en formularios/botones con data-confirm
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    var msg = (ev.submitter && ev.submitter.getAttribute('data-confirm')) || f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('a[data-confirm]');
    if (a && !window.confirm(a.getAttribute('data-confirm'))) ev.preventDefault();
  });

  // Botón de guardar de cada fila: se resalta cuando la fila tiene cambios sin guardar
  function marcarFila(ev) {
    var f = ev.target.form;
    var b = f && f.querySelector('[data-guardar]');
    if (b && !b.classList.contains('pendiente')) {
      b.classList.add('pendiente');
      b.setAttribute('data-tip', 'Guardar cambios (hay cambios sin guardar)');
    }
  }
  document.addEventListener('input', marcarFila);
  document.addEventListener('change', marcarFila);

  // Abrir / cerrar modales: data-abrir="idModal", data-cerrar
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-abrir]');
    if (b) {
      var m = document.getElementById(b.getAttribute('data-abrir'));
      if (m) { m.classList.add('abierto'); var i = m.querySelector('input:not([type=hidden]),select,textarea'); if (i) i.focus(); }
    }
    if (ev.target.closest('[data-cerrar]') || ev.target.classList.contains('modal-fondo')) {
      var f = ev.target.closest('.modal-fondo');
      if (f) f.classList.remove('abierto');
    }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      document.querySelectorAll('.modal-fondo.abierto').forEach(function (m) { m.classList.remove('abierto'); });
    }
  });
})();
