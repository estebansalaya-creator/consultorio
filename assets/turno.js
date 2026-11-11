/* Formulario de turno: buscar paciente, completar duración/consultorio/precio según la prestación y la cobertura,
   y mostrar el día del profesional */
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var form = $('form-turno');
  if (!form) return;

  var pacId = $('paciente_id'), buscar = $('buscar-paciente'), sug = $('sugerencias');
  var elegido = $('paciente-elegido'), buscador = $('buscador-paciente'), nuevo = $('paciente-nuevo');
  var esp = $('especialidad_id'), pres = $('prestacion_id'), prof = $('profesional_id'), cons = $('consultorio_id');
  var cob = $('obra_social_id'), precio = $('precio'), precioLbl = $('precio-label'), cobAviso = $('cob-aviso');
  var fecha = $('fecha'), hora = $('hora'), dur = $('duracion');
  var termina = $('termina'), diaProf = $('dia-prof');
  var panel = $('panel-paciente'), ficha = $('ficha-paciente');
  var excluir = new URLSearchParams(location.search).get('id') || '';

  /* ---------- Paciente ---------- */
  var timer = null, lista = [], activo = -1;

  function mostrarFicha(c) {
    panel.hidden = false;
    var h = '<p style="margin-bottom:6px"><b>' + App.esc(c.nombre) + '</b>' + (c.edad ? ' · ' + App.esc(c.edad) : '') + '</p>';
    h += '<dl class="datos">';
    if (c.dni) h += '<dt>DNI</dt><dd>' + App.esc(c.dni) + '</dd>';
    if (c.telefono) h += '<dt>Teléfono</dt><dd>' + App.esc(c.telefono) + '</dd>';
    h += '<dt>Cobertura</dt><dd>' + App.esc(c.obra_social) + (c.afiliado ? '<br><small>' + App.esc(c.afiliado) + '</small>' : '') + '</dd>';
    h += '</dl>';
    if (c.antecedentes) h += '<div class="antecedentes mt"><b>Antecedentes</b>' + App.esc(c.antecedentes) + '</div>';
    h += '<p class="apagado mt" style="margin-bottom:0">' + App.esc(c.resumen || '') + '</p>';
    ficha.innerHTML = h;
  }
  function elegir(c) {
    pacId.value = c.id;
    $('paciente-nombre').textContent = c.nombre;
    $('paciente-datos').textContent = [c.dni ? 'DNI ' + c.dni : '', c.telefono].filter(Boolean).join(' · ');
    elegido.hidden = false;
    buscador.hidden = true;
    nuevo.hidden = true;
    ['pac_nombre', 'pac_apellido', 'pac_dni', 'pac_telefono', 'pac_afiliado'].forEach(function (n) { $(n).value = ''; });
    sug.hidden = true;
    // La cobertura del turno es la del paciente (se puede cambiar a particular)
    cob.value = cob.querySelector('option[value="' + c.obra_social_id + '"]') ? String(c.obra_social_id) : '0';
    cambioCobertura();
    mostrarFicha(c);
    (pres.value ? prof : pres).focus();
  }
  function pintar() {
    if (!lista.length) {
      sug.innerHTML = '<button type="button" data-nuevo>No lo encontramos. Cargarlo como paciente nuevo</button>';
    } else {
      sug.innerHTML = lista.map(function (c, i) {
        return '<button type="button" data-i="' + i + '"' + (i === activo ? ' class="activo"' : '') + '><b>' + App.esc(c.nombre) + '</b>'
          + '<small>' + App.esc([c.dni ? 'DNI ' + c.dni : '', c.edad, c.obra_social, c.telefono].filter(Boolean).join(' · ')) + '</small></button>';
      }).join('');
    }
    sug.hidden = false;
  }
  if (buscar) {
    buscar.addEventListener('input', function () {
      clearTimeout(timer);
      var q = buscar.value.trim();
      if (q.length < 2) { sug.hidden = true; return; }
      timer = setTimeout(function () {
        App.api('api/pacientes.php?q=' + encodeURIComponent(q)).then(function (r) {
          lista = r.ok ? r.pacientes : [];
          activo = lista.length ? 0 : -1;
          pintar();
        });
      }, 220);
    });
    buscar.addEventListener('keydown', function (ev) {
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
      var b = ev.target.closest('button');
      if (!b) return;
      if (b.hasAttribute('data-nuevo')) { abrirNuevo(); return; }
      elegir(lista[+b.dataset.i]);
    });
    document.addEventListener('click', function (ev) { if (!ev.target.closest('#buscador-paciente')) sug.hidden = true; });
  }
  function abrirNuevo() {
    nuevo.hidden = false;
    sug.hidden = true;
    var txt = buscar.value.trim();
    var digitos = txt.replace(/\D/g, '');
    if (txt && !/\d/.test(txt)) {
      // "Gómez Ana" o "Ana Gómez": se propone nombre y apellido, se corrige a mano si hace falta
      var partes = txt.split(/\s+/);
      $('pac_nombre').value = partes.shift();
      $('pac_apellido').value = partes.join(' ');
    } else if (digitos.length >= 7 && digitos.length <= 8 && !/[\s-]/.test(txt)) {
      $('pac_dni').value = digitos;
    } else if (digitos) {
      $('pac_telefono').value = txt;
    }
    $('pac_nombre').focus();
  }
  var btnNuevo = $('btn-nuevo');
  if (btnNuevo) btnNuevo.addEventListener('click', abrirNuevo);
  var cambiar = $('cambiar-paciente');
  if (cambiar) cambiar.addEventListener('click', function () {
    pacId.value = '';
    elegido.hidden = true;
    buscador.hidden = false;
    panel.hidden = true;
    buscar.value = '';
    buscar.focus();
  });
  if (pacId.value) {
    App.api('api/pacientes.php?id=' + pacId.value).then(function (r) { if (r.ok) mostrarFicha(r.paciente); });
  }

  /* ---------- Especialidad, prestación y profesional ---------- */
  var optAuto = prof.querySelector('option[data-auto]');
  function opcionPres() { return pres.options[pres.selectedIndex]; }
  function opcionProf() { return prof.options[prof.selectedIndex]; }
  Array.prototype.forEach.call(prof.options, function (o) { o.dataset.nombre = o.textContent; });

  /* Muestra sólo las prestaciones y profesionales de la especialidad elegida */
  function filtrarEspecialidad() {
    var c = esp.value;
    Array.prototype.forEach.call(pres.querySelectorAll('option[data-esp]'), function (o) {
      var ver = c === '0' || o.dataset.esp === c;
      o.hidden = !ver; o.disabled = !ver;
    });
    Array.prototype.forEach.call(pres.querySelectorAll('optgroup'), function (g) {
      g.hidden = c !== '0' && g.dataset.esp !== c;
    });
    if (opcionPres() && opcionPres().disabled) pres.value = '';
    Array.prototype.forEach.call(prof.options, function (o) {
      if (o === optAuto) return;
      var esps = o.dataset.esps ? o.dataset.esps.split(',') : [];
      var ver = c === '0' || esps.indexOf(c) >= 0;
      o.hidden = !ver; o.disabled = !ver;
    });
    optAuto.hidden = c === '0';
    optAuto.disabled = c === '0';
    var nombreEsp = c !== '0' ? esp.options[esp.selectedIndex].textContent : '';
    optAuto.textContent = 'Cualquier profesional libre' + (nombreEsp ? ' de ' + nombreEsp : '');
    if (opcionProf() && opcionProf().disabled) {
      if (!optAuto.disabled) { prof.value = 'auto'; }
      else {
        var primero = Array.prototype.find.call(prof.options, function (o) { return !o.disabled; });
        if (primero) prof.value = primero.value;
      }
    }
  }

  /* Marca "(no la hace)" / "(no atiende la cobertura)" y, si hace falta, elige a alguien que haga la prestación */
  function marcarProfesionales(autoElegir) {
    var o = opcionPres();
    var hacen = o && o.dataset.profs ? o.dataset.profs.split(',') : [];
    Array.prototype.forEach.call(prof.options, function (p) {
      if (p === optAuto) return;
      var hace = !hacen.length || hacen.indexOf(p.value) >= 0;
      var os = p.dataset.os ? p.dataset.os.split(',') : [];
      var atiende = cob.value === '0' || !os.length || os.indexOf(cob.value) >= 0;
      p.textContent = p.dataset.nombre + (!hace ? ' (no la hace)' : (!atiende ? ' (no atiende la cobertura)' : ''));
    });
    if (autoElegir && prof.value !== 'auto' && hacen.length && hacen.indexOf(prof.value) < 0) {
      var cand = hacen.filter(function (id) { var op = prof.querySelector('option[value="' + id + '"]'); return op && !op.disabled; });
      if (cand.length) prof.value = cand[0];
      else if (!optAuto.disabled) prof.value = 'auto';
    }
    avisoCobertura();
  }

  /* Duración: la de la prestación o, si no fija una, la del profesional
     (con "cualquier profesional libre", la del primero de la especialidad) */
  function durSugerida() {
    var o = opcionPres();
    if (o && +o.dataset.dur) return +o.dataset.dur;
    var p = opcionProf();
    if (p === optAuto) p = Array.prototype.find.call(prof.options, function (x) { return x !== optAuto && !x.disabled; });
    return p && +p.dataset.dur ? +p.dataset.dur : 20;
  }

  /* Precio: coseguro de la obra social o precio particular de la prestación */
  function calcularPrecio() {
    var c = cob.options[cob.selectedIndex];
    var esOS = cob.value !== '0';
    precioLbl.textContent = esOS ? 'Coseguro $' : 'Precio particular $';
    if (!precio || precio.disabled) return;
    if (esOS) {
      precio.value = Number(c.dataset.coseguro) || 0;
    } else {
      var o = opcionPres();
      precio.value = o && o.value ? (Number(o.dataset.precio) || 0) : '';
    }
  }
  function avisoCobertura() {
    var p = opcionProf();
    var os = p && p.dataset.os ? p.dataset.os.split(',') : [];
    cobAviso.textContent = (cob.value !== '0' && p && p !== optAuto && os.length && os.indexOf(cob.value) < 0)
      ? 'Este profesional no atiende esta cobertura: si viene como particular, elegí «Particular».' : '';
    cobAviso.style.color = cobAviso.textContent ? 'var(--coral)' : '';
    var campoAf = $('campo-afiliado');
    if (campoAf) campoAf.hidden = cob.value === '0';
  }
  function cambioCobertura() { calcularPrecio(); marcarProfesionales(false); cargarDiaDemorado(); }

  esp.addEventListener('change', function () { filtrarEspecialidad(); marcarProfesionales(true); actualizar(); });
  pres.addEventListener('change', function () {
    var o = opcionPres();
    if (!o || !o.value) return;
    // Si no había especialidad, toma la de la prestación (así se puede usar "cualquier profesional libre")
    if (esp.value === '0' && o.dataset.esp !== '0' && esp.querySelector('option[value="' + o.dataset.esp + '"]')) {
      esp.value = o.dataset.esp;
      filtrarEspecialidad();
    }
    marcarProfesionales(true);
    dur.value = durSugerida();
    cons.value = o.dataset.cons !== '0' ? o.dataset.cons : '';
    calcularPrecio();
    actualizar();
  });
  prof.addEventListener('change', function () {
    var o = opcionPres();
    if (!o || !o.value || !+o.dataset.dur) dur.value = durSugerida();
    avisoCobertura();
    actualizar();
  });
  cob.addEventListener('change', cambioCobertura);

  filtrarEspecialidad();
  marcarProfesionales(false);
  if (!dur.value && (pres.value || (prof.value && prof.value !== 'auto'))) dur.value = durSugerida();
  if (precio && precio.value === '' && pres.value) calcularPrecio();
  precioLbl.textContent = cob.value !== '0' ? 'Coseguro $' : 'Precio particular $';

  /* ---------- Hora de fin y disponibilidad ---------- */
  function hhmm(m) { m = ((m % 1440) + 1440) % 1440; return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); }
  function minutos(s) { var p = s.split(':'); return +p[0] * 60 + +p[1]; }
  function mostrarFin() {
    if (!hora.value || !dur.value) { termina.textContent = ''; return; }
    termina.textContent = 'Termina a las ' + hhmm(minutos(hora.value) + +dur.value);
  }
  var pedido = 0;
  function cargarDia() {
    if (!prof.value || !fecha.value) { diaProf.hidden = true; return; }
    var n = ++pedido;
    if (prof.value === 'auto') {
      // Quién de la especialidad puede tomarlo a esa hora
      if (!hora.value || !dur.value) {
        diaProf.innerHTML = 'Elegí la prestación y la hora para ver quién está libre.';
        diaProf.hidden = false;
        return;
      }
      App.api('api/libres.php?especialidad=' + esp.value + '&prestacion=' + pres.value + '&os=' + cob.value + '&fecha=' + fecha.value + '&hora=' + hora.value + '&dur=' + dur.value + '&excluir=' + excluir).then(function (r) {
        if (n !== pedido || !r.ok) return;
        var h = '<b>' + App.esc(r.especialidad) + ' a las ' + r.hora + ':</b> ';
        if (r.libres.length) {
          h += (r.libres.length === 1 ? 'está libre ' : 'están libres ') + App.esc(r.libres.join(', ')) + '. Se asigna a <b>' + App.esc(r.libres[0]) + '</b>.';
        } else {
          h += 'no hay nadie libre';
          if (r.ocupados.length) h += ' (' + r.ocupados.map(function (o) { return App.esc(o.nombre + ' ' + o.motivo); }).join('; ') + ')';
          h += '. Probá otro horario.';
        }
        diaProf.innerHTML = h;
        diaProf.hidden = false;
      });
      return;
    }
    App.api('api/dia.php?prof=' + prof.value + '&fecha=' + fecha.value + '&excluir=' + excluir).then(function (r) {
      if (n !== pedido || !r.ok) return;
      var nombre = opcionProf().dataset.nombre || '';
      var h = '<b>' + App.esc(nombre) + ', ' + App.esc(r.dia) + ':</b> ';
      h += r.horario.length
        ? 'atiende ' + r.horario.map(function (f) { return f.desde + '–' + f.hasta + (f.consultorio ? ' (' + App.esc(f.consultorio) + ')' : ''); }).join(' y ') + '.'
        : 'no atiende ese día.';
      if (r.turnos.length) {
        h += ' Ocupado: ' + r.turnos.map(function (t) { return '<span title="' + App.esc(t.paciente + ', ' + t.prestacion) + '">' + t.desde + '–' + t.hasta + (t.sobreturno ? ' (ST)' : '') + '</span>'; }).join(', ') + '.';
      } else if (r.horario.length) {
        h += ' Todavía no tiene turnos.';
      }
      diaProf.innerHTML = h;
      diaProf.hidden = false;
    });
  }
  var espera = null;
  function cargarDiaDemorado() { clearTimeout(espera); espera = setTimeout(cargarDia, 300); }
  function actualizar() { mostrarFin(); cargarDia(); }
  [hora, dur].forEach(function (el) {
    el.addEventListener('input', function () {
      mostrarFin();
      if (prof.value === 'auto') cargarDiaDemorado();
    });
  });
  fecha.addEventListener('change', cargarDia);
  actualizar();

  form.addEventListener('submit', function (ev) {
    if (!pacId.value && !$('pac_nombre').value.trim()) {
      ev.preventDefault();
      alert('Elegí el paciente o cargá sus datos si es nuevo.');
      (buscar || $('pac_nombre')).focus();
    }
  });
  if (buscar && !pacId.value && nuevo.hidden) buscar.focus();
})();
