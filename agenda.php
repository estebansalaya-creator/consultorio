<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('agenda');

$fecha = fecha_valida(get('fecha', '')) ?: date('Y-m-d');
$vista = get('vista') === 'semana' ? 'semana' : 'dia';
$todos = (bool)get('todos');
$profs = profesionales_activos();
$mio = es_profesional() ? mi_profesional_id() : null;
$especialidades = especialidades_activas();
$verHc = puede_hc();

// El profesional ve su agenda y la de quienes comparten alguna especialidad con él (la suya primero)
if (es_profesional()) {
    $vis = profesionales_visibles();
    $profs = array_values(array_filter($profs, function ($p) use ($vis) { return in_array((int)$p['id'], $vis, true); }));
    usort($profs, function ($a, $b) use ($mio) { return ((int)$b['id'] === $mio) - ((int)$a['id'] === $mio); });
    if (!$profs) {
        $profs = q_all('SELECT * FROM profesionales WHERE id = ?', [$mio]);
    }
    $misEsp = especialidades_de_profesional($mio);
    $especialidades = array_values(array_filter($especialidades, function ($c) use ($misEsp) { return in_array((int)$c['id'], $misEsp, true); }));
}

// Filtro por especialidad
$esp = (int)get('esp', 0);
if (!in_array($esp, array_map('intval', array_column($especialidades, 'id')), true)) {
    $esp = 0;
}
$espNombre = '';
if ($esp) {
    foreach ($especialidades as $c) {
        if ((int)$c['id'] === $esp) {
            $espNombre = $c['nombre'];
        }
    }
    $profs = array_values(array_filter($profs, function ($p) use ($esp) { return in_array($esp, especialidades_de_profesional($p['id']), true); }));
}
$profPorId = [];
foreach ($profs as $p) {
    $profPorId[(int)$p['id']] = $p;
}
$profSel = (int)get('prof', 0);
if (!isset($profPorId[$profSel])) {
    $profSel = $mio && isset($profPorId[$mio]) ? $mio : ($profs ? (int)$profs[0]['id'] : 0);
}

/** Mezcla un color con blanco: $p = proporción del color (0 a 1) */
function tinte($hex, $p)
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        return '#def1f2';
    }
    $c = '';
    for ($i = 0; $i < 3; $i++) {
        $v = hexdec(substr($hex, $i * 2, 2));
        $c .= sprintf('%02x', (int)round(255 - (255 - $v) * $p));
    }
    return '#' . $c;
}

// ---------- Columnas: [fecha, profesional] ----------
$columnas = [];
if ($vista === 'dia') {
    $desde = $hasta = $fecha;
    $turnos = turnos_rango($fecha, $fecha, array_keys($profPorId));
    $conTurnos = array_unique(array_map(function ($t) { return (int)$t['profesional_id']; }, $turnos));
    foreach ($profs as $p) {
        if ($todos || (int)$p['id'] === $mio || horario_dia($p['id'], $fecha) || in_array((int)$p['id'], $conTurnos, true)) {
            $columnas[] = ['fecha' => $fecha, 'prof' => $p];
        }
    }
    if (!$columnas) {
        foreach ($profs as $p) {
            $columnas[] = ['fecha' => $fecha, 'prof' => $p];
        }
    }
} else {
    $lunes = date('Y-m-d', strtotime($fecha . ' -' . (date('N', strtotime($fecha)) - 1) . ' days'));
    $desde = $lunes;
    $hasta = date('Y-m-d', strtotime($lunes . ' +6 days'));
    $turnos = $profSel ? turnos_rango($desde, $hasta, [$profSel]) : [];
    for ($i = 0; $i < 7; $i++) {
        $f = date('Y-m-d', strtotime($lunes . " +$i days"));
        $hay = array_filter($turnos, function ($t) use ($f) { return substr($t['inicio'], 0, 10) === $f; });
        if ($i < 6 || horario_dia($profSel, $f) || $hay) {
            $columnas[] = ['fecha' => $f, 'prof' => $profSel ? $profPorId[$profSel] : null];
        }
    }
}

// ---------- Rango horario visible ----------
$ini = minutos(config_get('agenda_desde', '08:00'));
$fin = minutos(config_get('agenda_hasta', '21:00'));
foreach ($columnas as $c) {
    if ($c['prof']) {
        foreach (horario_dia($c['prof']['id'], $c['fecha']) as $f) {
            $ini = min($ini, $f[0]);
            $fin = max($fin, $f[1]);
        }
    }
}
foreach ($turnos as $t) {
    $ini = min($ini, minutos(hora($t['inicio'])));
    $fin = max($fin, substr($t['fin'], 0, 10) > substr($t['inicio'], 0, 10) ? 1440 : minutos(hora($t['fin'])));
}
$ini = (int)floor($ini / 60) * 60;
$fin = min(1440, (int)ceil($fin / 60) * 60);
$ppm = 2.2;
$alto = ($fin - $ini) * $ppm;
$intervalo = max(5, (int)config_get('agenda_intervalo', '15'));
$puedeDar = puede('turnos');

// Turnos por columna
$porCol = [];
foreach ($turnos as $t) {
    $porCol[substr($t['inicio'], 0, 10) . '|' . $t['profesional_id']][] = $t;
}

// ---------- Navegación ----------
$paso = $vista === 'dia' ? '1 day' : '7 days';
$base = ['vista' => $vista] + ($vista === 'semana' ? ['prof' => $profSel] : []) + ($todos ? ['todos' => 1] : []) + ($esp ? ['esp' => $esp] : []);
$link = function (array $extra) use ($base) {
    return url('agenda.php?' . http_build_query(array_merge($base, $extra)));
};
$anterior = date('Y-m-d', strtotime($fecha . ' -' . $paso));
$siguiente = date('Y-m-d', strtotime($fecha . ' +' . $paso));
if ($vista === 'dia') {
    $tituloFecha = ucfirst(fecha_relativa($fecha) === 'hoy' ? 'hoy, ' . fecha_larga($fecha) : fecha_larga($fecha));
} else {
    $tituloFecha = 'Semana del ' . date('j', strtotime($desde)) . (date('n', strtotime($desde)) !== date('n', strtotime($hasta)) ? ' de ' . MESES[(int)date('n', strtotime($desde))] : '')
        . ' al ' . fecha_larga($hasta, false);
}

layout_header(es_profesional() ? 'Mi agenda' : 'Agenda', [
    'clase'    => 'pagina-agenda',
    'acciones' => $puedeDar ? '<a class="btn btn-pri" href="' . url('turno.php?fecha=' . $fecha . ($vista === 'semana' ? '&profesional=' . $profSel : '') . ($esp ? '&especialidad=' . $esp : '')) . '">Nuevo turno</a>' : '',
]);
?>
<form class="ag-nav" method="get" action="<?= url('agenda.php') ?>">
  <input type="hidden" name="vista" value="<?= e($vista) ?>">
  <?php if ($todos): ?><input type="hidden" name="todos" value="1"><?php endif; ?>
  <?php if ($vista === 'semana'): ?><input type="hidden" name="prof" value="<?= (int)$profSel ?>"><?php endif; ?>
  <a class="btn" href="<?= $link(['fecha' => $anterior]) ?>" aria-label="Anterior">‹</a>
  <a class="btn" href="<?= $link(['fecha' => date('Y-m-d')]) ?>">Hoy</a>
  <a class="btn" href="<?= $link(['fecha' => $siguiente]) ?>" aria-label="Siguiente">›</a>
  <span class="fecha-titulo"><?= e($tituloFecha) ?></span>
  <span class="espacio"></span>
  <input type="date" name="fecha" value="<?= e($fecha) ?>" onchange="this.form.submit()" aria-label="Ir a la fecha">
  <?php if (count($especialidades) > 1 || (count($especialidades) === 1 && !es_profesional())): ?>
    <select name="esp" onchange="this.form.submit()" aria-label="Especialidad">
      <option value="0"><?= es_profesional() ? 'Todas mis especialidades' : 'Todas las especialidades' ?></option>
      <?= opciones($especialidades, $esp) ?>
    </select>
  <?php endif; ?>
  <?php if ($vista === 'semana' && count($profs) > 1): ?>
    <select onchange="this.form.prof.value = this.value; this.form.submit()" aria-label="Profesional"><?= opciones($profs, $profSel) ?></select>
  <?php endif; ?>
  <span class="seg">
    <a href="<?= url('agenda.php?' . http_build_query(['fecha' => $fecha] + ($todos ? ['todos' => 1] : []) + ($esp ? ['esp' => $esp] : []))) ?>" class="<?= $vista === 'dia' ? 'on' : '' ?>">Día</a>
    <a href="<?= url('agenda.php?' . http_build_query(['fecha' => $fecha, 'vista' => 'semana', 'prof' => $profSel] + ($esp ? ['esp' => $esp] : []))) ?>" class="<?= $vista === 'semana' ? 'on' : '' ?>">Semana</a>
  </span>
  <noscript><button class="btn" type="submit">Ir</button></noscript>
</form>

<?php if (!$profs): ?>
  <div class="card vacio">
    <?= $esp ? 'Ningún profesional activo tiene la especialidad «' . e($espNombre) . '».' : 'Todavía no hay profesionales cargados.' ?>
    <?php if (puede('profesionales')): ?><br><a class="btn btn-pri mt" href="<?= url($esp ? 'profesionales.php' : 'profesional_form.php') ?>"><?= $esp ? 'Asignar especialidades a los profesionales' : 'Agregar profesional' ?></a><?php endif; ?>
  </div>
<?php else: ?>
<div class="agenda" id="agenda">
  <div class="ag-grilla" style="--cols: <?= count($columnas) ?>; --alto: <?= $alto ?>px">
    <div class="ag-cab"></div>
    <?php foreach ($columnas as $c):
        $p = $c['prof'];
        $franjas = $p ? horario_dia($p['id'], $c['fecha']) : [];
        $txtHorario = $franjas ? implode(' y ', array_map(function ($f) { return hhmm($f[0]) . '–' . hhmm($f[1]); }, $franjas)) : 'No atiende';
        $txtCons = $p ? consultorios_dia_txt($p['id'], $c['fecha']) : '';
        $nTurnos = count(array_filter(isset($porCol[$c['fecha'] . '|' . $p['id']]) ? $porCol[$c['fecha'] . '|' . $p['id']] : [], function ($t) { return $t['estado'] !== 'ausente'; }));
        ?>
      <div class="ag-cab<?= $c['fecha'] === date('Y-m-d') && $vista === 'semana' ? ' hoy' : '' ?>">
        <?php if ($vista === 'dia'): ?>
          <a class="ag-quien" href="<?= url('agenda.php?' . http_build_query(['fecha' => $c['fecha'], 'vista' => 'semana', 'prof' => $p['id']] + ($esp ? ['esp' => $esp] : []))) ?>" title="Ver la semana de <?= e($p['nombre']) ?> (<?= e(especialidades_txt($p['id'])) ?>)"><span class="punto" style="--c: <?= e($p['color']) ?>"></span><?= e($p['nombre']) ?></a>
        <?php else: ?>
          <a class="ag-quien" href="<?= url('agenda.php?' . http_build_query(['fecha' => $c['fecha']] + ($esp ? ['esp' => $esp] : []))) ?>" title="Ver el día"><?= e(ucfirst(DIAS[(int)date('N', strtotime($c['fecha']))])) ?> <?= date('j', strtotime($c['fecha'])) ?></a>
        <?php endif; ?>
        <small><?= e($txtHorario) ?><?= $nTurnos ? ' · ' . plural($nTurnos, 'turno', 'turnos') : '' ?></small>
        <?php if ($txtCons !== ''): ?><small class="ag-cons"><?= e($txtCons) ?></small><?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="ag-horas">
      <?php for ($m = $ini; $m < $fin; $m += 60): ?>
        <span style="top: <?= ($m - $ini) * $ppm ?>px"><?= hhmm($m) ?></span>
      <?php endfor; ?>
    </div>

    <?php foreach ($columnas as $c):
        $p = $c['prof'];
        $color = $p ? $p['color'] : '#0d7680';
        ?>
      <div class="ag-col<?= $puedeDar ? ' clic' : '' ?>" data-fecha="<?= e($c['fecha']) ?>" data-prof="<?= (int)$p['id'] ?>" data-nombre="<?= e($p['nombre']) ?>">
        <?php foreach ($p ? horario_dia($p['id'], $c['fecha']) : [] as $f):
            $a = max($f[0], $ini);
            $b = min($f[1], $fin);
            if ($b <= $a) continue; ?>
          <div class="ag-libre" style="top: <?= ($a - $ini) * $ppm ?>px; height: <?= ($b - $a) * $ppm ?>px"></div>
        <?php endforeach; ?>

        <?php $deCol = isset($porCol[$c['fecha'] . '|' . $p['id']]) ? $porCol[$c['fecha'] . '|' . $p['id']] : [];
        $carriles = carriles_agenda($deCol);
        foreach ($deCol as $t):
            list($carril, $nCarriles) = $carriles[(int)$t['id']];
            $posX = $nCarriles > 1 ? 'left: calc(4px + (100% - 8px) * ' . $carril . ' / ' . $nCarriles . '); right: auto; width: calc((100% - 8px) / ' . $nCarriles . ' - 3px); ' : '';
            $mi = minutos(hora($t['inicio']));
            $mf = substr($t['fin'], 0, 10) > substr($t['inicio'], 0, 10) ? 1440 : minutos(hora($t['fin']));
            $h = max(18, ($mf - $mi) * $ppm - 2);
            $corto = ($mf - $mi) < 20;
            $nombre = ($t['apellido'] !== '' ? $t['apellido'] . ', ' : '') . $t['nombre'];
            $consDistinto = $t['consultorio_id'] && (int)$t['consultorio_id'] !== (int)consultorio_de_horario($t['profesional_id'], $t['inicio']);
            $tip = hora($t['inicio']) . '–' . hora($t['fin']) . ' ' . nombre_paciente($t) . ($t['fecha_nac'] ? ' (' . edad_txt($t['fecha_nac']) . ')' : '')
                . "\n" . $t['prestacion_nombre'] . ($t['sobreturno'] ? ' (sobreturno)' : '') . ' · ' . cobertura_txt($t['obra_social'])
                . ($t['consultorio'] ? "\n" . $t['consultorio'] : '') . "\n" . estado_turno_txt($t['estado'])
                . ($t['estado'] === 'presente' && $t['llegada'] ? ' desde las ' . hora($t['llegada']) : '')
                . ($t['cobro_id'] ? ', cobrado' : '') . ($verHc && $t['con_antecedentes'] ? "\nTiene antecedentes en la historia clínica" : '')
                . ($t['notas'] ? "\n" . $t['notas'] : ''); ?>
          <a class="ag-turno est-<?= e($t['estado']) ?><?= $corto ? ' corto' : '' ?>" id="t<?= (int)$t['id'] ?>" href="<?= url('turno.php?id=' . $t['id']) ?>"
             style="<?= $posX ?>top: <?= ($mi - $ini) * $ppm + 1 ?>px; height: <?= $h ?>px; --c: <?= e($color) ?>; --cs: <?= tinte($color, .16) ?>"
             title="<?= e($tip) ?>">
            <span class="t-marcas">
              <?php if ($t['sobreturno']): ?><span class="t-marca m-st" title="Sobreturno">ST</span><?php endif; ?>
              <?php if ($verHc && $t['con_antecedentes']): ?><span class="t-marca m-alerta" title="Tiene antecedentes en la historia clínica">!</span><?php endif; ?>
              <?php if ($t['cobro_id']): ?><span class="t-marca m-pago" title="Cobrado">$</span><?php endif; ?>
            </span>
            <span class="t-linea"><span class="t-hora"><?= hora($t['inicio']) ?></span><b><?= e($nombre) ?></b><?= $corto ? ' · ' . e(cobertura_txt($t['obra_social'])) : '' ?></span>
            <span class="t-serv"><?= e($t['prestacion_nombre']) ?> · <?= e(cobertura_txt($t['obra_social'])) ?><?= $consDistinto ? ' · ' . e($t['consultorio']) : '' ?></span>
          </a>
        <?php endforeach; ?>

        <?php if ($c['fecha'] === date('Y-m-d')):
            $ahora = (int)date('G') * 60 + (int)date('i');
            if ($ahora >= $ini && $ahora <= $fin): ?>
          <div class="ag-ahora" style="top: <?= ($ahora - $ini) * $ppm ?>px" title="Ahora"></div>
        <?php endif; endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="leyenda">
  <?php foreach (['reservado', 'confirmado', 'presente', 'en_atencion', 'atendido', 'ausente'] as $est): ?><?= badge_turno($est) ?><?php endforeach; ?>
  <span><span class="t-marca m-st">ST</span> sobreturno</span>
  <?php if ($verHc): ?><span><span class="t-marca m-alerta">!</span> antecedentes</span><?php endif; ?>
  <span><span class="t-marca m-pago">$</span> cobrado</span>
  <?php if ($vista === 'dia' && !es_profesional() && count($columnas) < count($profs)): ?>
    <span class="espacio"></span><a href="<?= $link(['fecha' => $fecha, 'todos' => 1]) ?>">Ver también a los que no atienden este día</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($puedeDar): ?>
<script>
(function () {
  var ini = <?= $ini ?>, ppm = <?= $ppm ?>, paso = <?= $intervalo ?>;
  var nuevo = '<?= url('turno.php') ?>';
  function hhmm(m) { return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); }
  function minutoEn(col, ev) {
    var y = ev.clientY - col.getBoundingClientRect().top;
    return ini + Math.floor(y / ppm / paso) * paso;
  }
  var fantasma = document.createElement('div');
  fantasma.className = 'ag-fantasma';
  document.querySelectorAll('.ag-col.clic').forEach(function (col) {
    col.addEventListener('mousemove', function (ev) {
      if (ev.target.closest('.ag-turno')) { fantasma.remove(); return; }
      var m = minutoEn(col, ev);
      fantasma.style.top = ((m - ini) * ppm) + 'px';
      fantasma.style.height = (Math.max(paso, 30) * ppm) + 'px';
      fantasma.textContent = '+ ' + hhmm(m);
      if (fantasma.parentNode !== col) col.appendChild(fantasma);
    });
    col.addEventListener('mouseleave', function () { fantasma.remove(); });
    col.addEventListener('click', function (ev) {
      if (ev.target.closest('.ag-turno')) return;
      var m = minutoEn(col, ev);
      location.href = nuevo + '?fecha=' + col.dataset.fecha + '&hora=' + hhmm(m) + '&profesional=' + col.dataset.prof<?= $esp ? " + '&especialidad=" . $esp . "'" : '' ?>;
    });
  });
})();
</script>
<?php endif; ?>
<script>
// Lleva la vista al turno recién guardado, a la hora actual o al primer turno
(function () {
  var ag = document.getElementById('agenda');
  if (!ag) return;
  var nuevo = location.hash && document.getElementById(location.hash.slice(1));
  var foco = nuevo || ag.querySelector('.ag-ahora') || ag.querySelector('.ag-turno');
  if (!foco) return;
  if (ag.scrollHeight > ag.clientHeight) {
    // La grilla tiene su propio scroll (pantalla grande): se mueve la grilla y la página queda arriba
    ag.scrollTop = Math.max(0, foco.offsetTop - 120);
    if (nuevo) window.scrollTo(0, 0);
  } else if (nuevo) {
    window.scrollTo(0, Math.max(0, nuevo.getBoundingClientRect().top + window.pageYOffset - 140));
  }
})();
</script>
<?php layout_footer(); ?>
