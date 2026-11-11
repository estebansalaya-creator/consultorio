/* Pantalla de cobro: totales, descuento, pago dividido y vuelto */
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var form = $('form-cobro');
  if (!form) return;
  var extras = $('extras'), pagos = $('pagos');
  var total = 0;

  function calcular() {
    var sub = 0;
    form.querySelectorAll('.js-turno').forEach(function (cb) {
      var precio = cb.closest('.cobro-linea').querySelector('.js-precio');
      precio.disabled = !cb.checked;
      if (cb.checked) sub += App.num(precio.value);
    });
    extras.querySelectorAll('.cobro-linea').forEach(function (l) {
      sub += App.num(l.querySelector('.js-precio').value) * Math.max(1, parseInt(l.querySelector('.js-cant').value, 10) || 1);
    });
    var dTxt = $('descuento').value.trim();
    var desc = App.num(dTxt.replace('%', ''));
    if (/%$/.test(dTxt)) desc = Math.round(sub * desc) / 100;
    desc = Math.min(Math.max(desc, 0), sub);
    total = Math.round((sub - desc) * 100) / 100;
    $('subtotal').textContent = App.money(sub) + (desc ? '  (− ' + App.money(desc) + ')' : '');
    $('total').textContent = App.money(total);
    repartir();
  }

  /* El último medio de pago se completa solo con lo que falta, salvo que lo hayan tocado a mano */
  function repartir() {
    var filas = pagos.querySelectorAll('.pago-linea');
    var otros = 0;
    filas.forEach(function (f, i) {
      var m = f.querySelector('.js-monto');
      if (i < filas.length - 1) otros += App.num(m.value);
    });
    var ult = filas[filas.length - 1].querySelector('.js-monto');
    if (!ult.dataset.manual) {
      var resto = Math.max(0, Math.round((total - otros) * 100) / 100);
      ult.value = resto ? String(resto).replace('.', ',') : '';
    }
    resumen();
  }

  function resumen() {
    var suma = 0, efectivo = 0;
    pagos.querySelectorAll('.pago-linea').forEach(function (f) {
      var v = App.num(f.querySelector('.js-monto').value);
      suma += v;
      var o = f.querySelector('.js-medio').selectedOptions[0];
      if (o && o.dataset.efectivo === '1') efectivo += v;
    });
    var dif = Math.round((total - suma) * 100) / 100;
    var r = $('resto');
    r.className = 'resto';
    if (pagos.querySelectorAll('.pago-linea').length === 1 && !dif) {
      r.textContent = '';
    } else if (dif > 0) {
      r.textContent = 'Falta asignar ' + App.money(dif);
      r.classList.add('falta');
    } else if (dif < 0) {
      r.textContent = 'Los pagos superan el total en ' + App.money(-dif);
      r.classList.add('falta');
    } else {
      r.textContent = 'Los pagos cubren el total.';
      r.classList.add('justo');
    }
    // Vuelto
    var bloque = $('bloque-vuelto');
    bloque.hidden = efectivo <= 0;
    var paga = App.num($('paga_con').value);
    var vuelto = paga > efectivo ? paga - efectivo : 0;
    $('vuelto').value = vuelto;
    $('vuelto-txt').innerHTML = paga && efectivo ? (paga >= efectivo ? 'Vuelto: <b>' + App.money(vuelto) + '</b>' : '<span style="color:var(--coral)">No alcanza: faltan ' + App.money(efectivo - paga) + '</span>') : '';
    $('btn-cobrar').textContent = total > 0 ? 'Registrar cobro de ' + App.money(total) : 'Registrar cobro';
  }

  form.addEventListener('input', function (ev) {
    if (ev.target.classList.contains('js-monto')) {
      ev.target.dataset.manual = ev.target.value.trim() ? '1' : '';
      // Si tocan un monto que no es el último, el último se recalcula
      var filas = pagos.querySelectorAll('.js-monto');
      if (ev.target !== filas[filas.length - 1]) filas[filas.length - 1].dataset.manual = '';
      repartir();
    } else if (ev.target.id === 'paga_con') {
      resumen();
    } else {
      calcular();
    }
  });
  form.addEventListener('change', function (ev) {
    if (ev.target.classList.contains('js-turno')) calcular();
    if (ev.target.classList.contains('js-medio')) resumen();
  });

  $('agregar-extra').addEventListener('click', function () {
    extras.appendChild($('tpl-extra').content.cloneNode(true));
    extras.lastElementChild.querySelector('input').focus();
  });
  $('dividir').addEventListener('click', function () {
    var f = pagos.querySelector('.pago-linea').cloneNode(true);
    var m = f.querySelector('.js-monto');
    m.value = '';
    m.dataset.manual = '';
    pagos.querySelectorAll('.js-monto').forEach(function (x) { x.dataset.manual = x.value.trim() ? '1' : ''; });
    f.querySelector('.btn-quitar').hidden = false;
    var sel = f.querySelector('.js-medio');
    if (sel.selectedIndex < sel.options.length - 1) sel.selectedIndex++;
    pagos.appendChild(f);
    repartir();
    m.focus();
  });
  form.addEventListener('click', function (ev) {
    var b = ev.target.closest('.btn-quitar');
    if (!b) return;
    var linea = b.closest('.cobro-linea, .pago-linea');
    var esPago = linea.classList.contains('pago-linea');
    linea.remove();
    if (esPago) {
      var ms = pagos.querySelectorAll('.js-monto');
      ms[ms.length - 1].dataset.manual = '';
    }
    calcular();
  });

  form.addEventListener('submit', function (ev) {
    var suma = 0;
    pagos.querySelectorAll('.js-monto').forEach(function (m) { suma += App.num(m.value); });
    if (Math.abs(suma - total) > 0.009) {
      ev.preventDefault();
      alert('Los pagos (' + App.money(suma) + ') tienen que sumar el total (' + App.money(total) + ').');
    }
  });

  /* Sin paciente elegido: buscador que lleva a sus turnos pendientes */
  var buscar = $('buscar-paciente'), sug = $('sugerencias'), timer;
  if (buscar) {
    buscar.addEventListener('input', function () {
      clearTimeout(timer);
      var q = buscar.value.trim();
      if (q.length < 2) { sug.hidden = true; return; }
      timer = setTimeout(function () {
        App.api('api/pacientes.php?q=' + encodeURIComponent(q)).then(function (r) {
          var l = r.ok ? r.pacientes : [];
          sug.innerHTML = l.length ? l.map(function (c) {
            return '<button type="button" data-id="' + c.id + '"><b>' + App.esc(c.nombre) + '</b><small>' + App.esc([c.dni ? 'DNI ' + c.dni : '', c.obra_social].filter(Boolean).join(' · ')) + '</small></button>';
          }).join('') : '<button type="button" disabled>Sin resultados</button>';
          sug.hidden = false;
        });
      }, 220);
    });
    sug.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-id]');
      if (b) location.href = App.base + 'cobro.php?paciente=' + b.dataset.id;
    });
  }

  calcular();
})();
