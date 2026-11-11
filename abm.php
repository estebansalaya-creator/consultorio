<?php
/*
 * Altas y modificaciones de tablas simples: especialidades, consultorios, obras sociales, medios de pago.
 * abm.php?t=especialidades | consultorios | obras_sociales | medios_pago
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('ajustes');

$TABLAS = [
    'especialidades' => [
        'titulo' => 'Especialidades', 'singular' => 'especialidad', 'volver' => 'prestaciones.php',
        'ayuda'  => 'Agrupan las prestaciones y los profesionales (un profesional puede tener varias). Sirven para filtrar la agenda, para dar turnos a cualquier profesional libre de la especialidad y para que cada profesional vea la agenda de sus colegas. El orden define cómo aparecen.',
        'campos' => ['nombre' => 'Nombre', 'orden' => 'Orden'],
        'montos' => [],
        'checks' => ['activo' => 'Activa'],
        'orden'  => 'orden, nombre',
        'uso'    => 'SELECT (SELECT COUNT(*) FROM prestaciones WHERE especialidad_id = ?) + (SELECT COUNT(*) FROM profesional_especialidades WHERE especialidad_id = ?) + (SELECT COUNT(*) FROM turnos WHERE especialidad_id = ?)',
    ],
    'consultorios' => [
        'titulo' => 'Consultorios', 'singular' => 'consultorio', 'volver' => 'prestaciones.php',
        'ayuda'  => 'Cada franja del horario de un profesional va en un consultorio, y el turno toma ese consultorio solo. El sistema no deja dar un turno en un consultorio que a esa hora está usando otro profesional. También pueden ser salas o equipos que necesita una prestación (ej. sala de ergometría).',
        'campos' => ['nombre' => 'Nombre', 'orden' => 'Orden'],
        'montos' => [],
        'checks' => ['activo' => 'Activo'],
        'orden'  => 'orden, nombre',
        'uso'    => 'SELECT (SELECT COUNT(*) FROM prestaciones WHERE consultorio_id = ?) + (SELECT COUNT(*) FROM turnos WHERE consultorio_id = ?) + (SELECT COUNT(*) FROM profesional_horarios WHERE consultorio_id = ?)',
    ],
    'obras_sociales' => [
        'titulo' => 'Obras sociales y prepagas', 'singular' => 'obra social', 'volver' => 'prestaciones.php',
        'ayuda'  => 'El RNAS es el código de 6 números del Registro Nacional de Agentes del Seguro (Superintendencia de Servicios de Salud): escribí la sigla, el nombre o el número y elegilo de la lista. Las obras sociales provinciales y las prepagas que no son agentes del seguro no tienen RNAS. El coseguro es lo que paga en el centro el paciente con esa cobertura por cada turno (0 = no paga nada). El nomenclador es con el que se le facturan las prácticas (ver Nomencladores).',
        'campos' => ['nombre' => 'Nombre', 'orden' => 'Orden'],
        'rnas'   => true,
        'montos' => ['coseguro' => 'Coseguro $'],
        'selects' => ['nomenclador_id' => ['Nomenclador', 'SELECT id, nombre FROM prest_nomenclador WHERE activo = 1 ORDER BY id', 'El por defecto']],
        'checks' => ['activo' => 'Activa'],
        'orden'  => 'orden, nombre',
        'uso'    => 'SELECT (SELECT COUNT(*) FROM pacientes WHERE obra_social_id = ?) + (SELECT COUNT(*) FROM turnos WHERE obra_social_id = ?) + (SELECT COUNT(*) FROM profesional_obras_sociales WHERE obra_social_id = ?)',
    ],
    'medios_pago' => [
        'titulo' => 'Medios de pago', 'singular' => 'medio de pago', 'volver' => 'ajustes.php',
        'ayuda'  => 'Marcá como efectivo sólo los que entran a la caja física (para el arqueo y el vuelto).',
        'campos' => ['nombre' => 'Nombre', 'orden' => 'Orden'],
        'montos' => [],
        'checks' => ['es_efectivo' => 'Es efectivo', 'activo' => 'Activo'],
        'orden'  => 'orden, nombre',
        'uso'    => 'SELECT (SELECT COUNT(*) FROM cobro_pagos WHERE medio_pago_id = ?) + (SELECT COUNT(*) FROM caja_movimientos WHERE medio_pago_id = ?)',
    ],
];

$t = get('t');
if (!isset($TABLAS[$t])) {
    redirect(url('ajustes.php'));
}
$cfg = $TABLAS[$t] + ['selects' => [], 'rnas' => false];
$agentes = $cfg['rnas'] ? q_all('SELECT rnas, denominacion, sigla, tipo FROM agentes_seguro ORDER BY COALESCE(sigla, denominacion)') : [];
$opcionesSel = [];
foreach ($cfg['selects'] as $k => $sel) {
    $opcionesSel[$k] = q_all($sel[1]);
}

if (es_post()) {
    csrf_check();
    $id = (int)post('id');
    if (post('accion') === 'eliminar') {
        $usos = (int)q_val($cfg['uso'], array_fill(0, substr_count($cfg['uso'], '?'), $id));
        if ($usos > 0) {
            flash('error', 'Está en uso: no se puede eliminar. Destildá «' . reset($cfg['checks']) . '» para que no aparezca más.');
        } else {
            q("DELETE FROM `$t` WHERE id = ?", [$id]);
            flash('ok', 'Eliminado: ' . $cfg['singular'] . '.');
        }
        redirect(url('abm.php?t=' . $t));
    }
    $vals = [];
    foreach ($cfg['campos'] as $k => $lbl) {
        $vals[$k] = $k === 'orden' ? (int)post($k) : trim((string)post($k));
    }
    foreach ($cfg['montos'] as $k => $lbl) {
        $vals[$k] = max(0, num(post($k)));
    }
    foreach ($cfg['selects'] as $k => $sel) {
        $vals[$k] = (int)post($k) ?: null;
    }
    foreach ($cfg['checks'] as $k => $lbl) {
        $vals[$k] = post($k) ? 1 : 0;
    }
    $errorRnas = '';
    if ($cfg['rnas']) {
        $rn = preg_replace('/\D/', '', (string)post('rnas'));
        $vals['rnas'] = $rn !== '' ? $rn : null;
        if ($rn !== '' && strlen($rn) !== 6) {
            $errorRnas = 'El código RNAS tiene 6 números (ej. 400800). No se guardó.';
        }
    }
    if ($vals['nombre'] === '') {
        flash('error', 'El nombre es obligatorio.');
    } elseif ($errorRnas !== '') {
        flash('error', $errorRnas);
    } else {
        $cols = array_keys($vals);
        if ($id) {
            $set = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, $cols));
            q("UPDATE `$t` SET $set WHERE id = ?", array_merge(array_values($vals), [$id]));
        } else {
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            q("INSERT INTO `$t` (`" . implode('`, `', $cols) . "`) VALUES ($ph)", array_values($vals));
        }
        flash('ok', 'Guardado.');
        if ($cfg['rnas'] && $vals['rnas'] && !q_val('SELECT 1 FROM agentes_seguro WHERE rnas = ?', [$vals['rnas']])) {
            flash('alerta', 'El RNAS ' . $vals['rnas'] . ' no está en el listado de la Superintendencia que trae el sistema. Se guardó igual: revisá que esté bien.');
        }
    }
    redirect(url('abm.php?t=' . $t));
}

$filas = q_all("SELECT * FROM `$t` ORDER BY " . $cfg['orden']);

/** Campo del código RNAS con la lista de agentes del seguro */
function campo_rnas($form, $valor)
{
    return '<input form="' . e($form) . '" type="text" name="rnas" class="js-rnas" list="lista-rnas" value="' . e($valor) . '" inputmode="numeric" maxlength="7" style="width:110px" placeholder="Buscar" aria-label="Código RNAS">'
        . '<small class="js-rnas-txt rnas-txt"></small>';
}
// Especialidades y consultorios están en el menú principal: se marcan ahí
layout_header($cfg['titulo'], ['activo' => in_array($t, ['especialidades', 'consultorios'], true) ? 'abm.php?t=' . $t : $cfg['volver']]);
?>
<p class="apagado"><?= e($cfg['ayuda']) ?> <a href="<?= url($cfg['volver']) ?>">Volver</a></p>
<div class="card" style="max-width:<?= $cfg['rnas'] ? 1080 : 860 ?>px">
  <div class="tabla-scroll">
  <table class="tabla">
    <thead><tr>
      <?php foreach ($cfg['campos'] as $k => $lbl): ?><th><?= e($lbl) ?></th><?php endforeach; ?>
      <?php if ($cfg['rnas']): ?><th>RNAS</th><?php endif; ?>
      <?php foreach ($cfg['montos'] as $k => $lbl): ?><th><?= e($lbl) ?></th><?php endforeach; ?>
      <?php foreach ($cfg['selects'] as $k => $sel): ?><th><?= e($sel[0]) ?></th><?php endforeach; ?>
      <?php foreach ($cfg['checks'] as $k => $lbl): ?><th><?= e($lbl) ?></th><?php endforeach; ?>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($filas as $f): $fid = 'f' . $f['id']; ?>
      <tr>
        <?php foreach ($cfg['campos'] as $k => $lbl): ?>
          <td><input form="<?= $fid ?>" type="<?= $k === 'orden' ? 'number' : 'text' ?>" name="<?= $k ?>" value="<?= e($f[$k]) ?>" <?= $k === 'orden' ? 'style="width:80px"' : '' ?> aria-label="<?= e($lbl) ?>"></td>
        <?php endforeach; ?>
        <?php if ($cfg['rnas']): ?><td><?= campo_rnas($fid, $f['rnas']) ?></td><?php endif; ?>
        <?php foreach ($cfg['montos'] as $k => $lbl): ?>
          <td><input form="<?= $fid ?>" type="text" inputmode="decimal" name="<?= $k ?>" value="<?= e((float)$f[$k]) ?>" style="width:120px; text-align:right" aria-label="<?= e($lbl) ?>"></td>
        <?php endforeach; ?>
        <?php foreach ($cfg['selects'] as $k => $sel): ?>
          <td><select form="<?= $fid ?>" name="<?= $k ?>" aria-label="<?= e($sel[0]) ?>" style="min-width:150px"><option value=""><?= e($sel[2]) ?></option><?= opciones($opcionesSel[$k], $f[$k]) ?></select></td>
        <?php endforeach; ?>
        <?php foreach ($cfg['checks'] as $k => $lbl): ?>
          <td><input form="<?= $fid ?>" type="checkbox" name="<?= $k ?>" value="1"<?= $f[$k] ? ' checked' : '' ?> aria-label="<?= e($lbl) ?>"></td>
        <?php endforeach; ?>
        <td class="acciones">
          <form id="<?= $fid ?>" method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <?= boton_guardar_fila() ?>
            <button class="link link-peligro" type="submit" name="accion" value="eliminar" data-confirm="¿Eliminar <?= e($f['nombre']) ?>?">Eliminar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
      <tr>
        <?php foreach ($cfg['campos'] as $k => $lbl): ?>
          <td><input form="nuevo" type="<?= $k === 'orden' ? 'number' : 'text' ?>" name="<?= $k ?>" placeholder="<?= $k === 'nombre' ? 'Agregar ' . e($cfg['singular']) : '' ?>" <?= $k === 'orden' ? 'style="width:80px" value="' . (count($filas) + 1) . '"' : '' ?> aria-label="<?= e($lbl) ?>"></td>
        <?php endforeach; ?>
        <?php if ($cfg['rnas']): ?><td><?= campo_rnas('nuevo', null) ?></td><?php endif; ?>
        <?php foreach ($cfg['montos'] as $k => $lbl): ?>
          <td><input form="nuevo" type="text" inputmode="decimal" name="<?= $k ?>" value="0" style="width:120px; text-align:right" aria-label="<?= e($lbl) ?>"></td>
        <?php endforeach; ?>
        <?php foreach ($cfg['selects'] as $k => $sel): ?>
          <td><select form="nuevo" name="<?= $k ?>" aria-label="<?= e($sel[0]) ?>" style="min-width:150px"><option value=""><?= e($sel[2]) ?></option><?= opciones($opcionesSel[$k]) ?></select></td>
        <?php endforeach; ?>
        <?php foreach ($cfg['checks'] as $k => $lbl): ?>
          <td><input form="nuevo" type="checkbox" name="<?= $k ?>" value="1"<?= $k === 'activo' ? ' checked' : '' ?> aria-label="<?= e($lbl) ?>"></td>
        <?php endforeach; ?>
        <td class="acciones"><form id="nuevo" method="post" class="inline"><?= csrf_field() ?><button class="btn btn-chico btn-pri" type="submit">Agregar</button></form></td>
      </tr>
    </tbody>
  </table>
  </div>
</div>
<?php if ($cfg['rnas']): ?>
<datalist id="lista-rnas">
  <?php foreach ($agentes as $a): ?><option value="<?= e($a['rnas']) ?>"><?= e(($a['sigla'] ? $a['sigla'] . ' · ' : '') . $a['denominacion']) ?></option><?php endforeach; ?>
</datalist>
<p class="apagado" style="max-width:1080px"><small>Listado de la Superintendencia de Servicios de Salud: <?= count($agentes) ?> agentes del seguro (descargado el 09/05/2025).</small></p>
<script>
(function () {
  var agentes = <?= json_encode(array_column(array_map(function ($a) { return [$a['rnas'], [$a['sigla'], $a['denominacion'], $a['tipo']]]; }, $agentes), 1, 0), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var titulo = function (s) { return s.toLowerCase().replace(/(^|\s)(\S)/g, function (m, a, b) { return a + b.toUpperCase(); }); };
  document.querySelectorAll('.js-rnas').forEach(function (inp) {
    var txt = inp.parentNode.querySelector('.js-rnas-txt');
    var mostrar = function () {
      var a = agentes[inp.value.replace(/\D/g, '')];
      txt.textContent = a ? (a[0] ? a[0] + ' · ' : '') + a[1] : (inp.value ? 'No está en el listado de la SSS' : '');
      return a;
    };
    mostrar();
    inp.addEventListener('input', function () {
      var a = mostrar();
      var nombre = document.querySelector('input[form="' + inp.getAttribute('form') + '"][name="nombre"]');
      if (a && nombre && !nombre.value.trim()) nombre.value = a[0] || titulo(a[1]);
    });
  });
})();
</script>
<?php endif; ?>
<?php layout_footer(); ?>
