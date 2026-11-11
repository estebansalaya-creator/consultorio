<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('profesionales');

$id = (int)get('id', 0);
$p = $id ? q_row('SELECT * FROM profesionales WHERE id = ?', [$id]) : null;
if ($id && !$p) {
    redirect(url('profesionales.php'));
}
$prestaciones = q_all('SELECT s.*, c.nombre AS especialidad FROM prestaciones s LEFT JOIN especialidades c ON c.id = s.especialidad_id WHERE s.activo = 1 ORDER BY c.orden IS NULL, c.orden, s.orden, s.nombre');
$especialidades = q_all('SELECT * FROM especialidades WHERE activo = 1 ORDER BY orden, nombre');
$consultorios = q_all('SELECT * FROM consultorios WHERE activo = 1 ORDER BY orden, nombre');
$obras = q_all('SELECT * FROM obras_sociales WHERE activo = 1 ORDER BY orden, nombre');
$err = '';

// Horario actual: [dia][0|1] = [desde, hasta, consultorio_id]
$franjas = [];
if ($id) {
    foreach (q_all('SELECT * FROM profesional_horarios WHERE profesional_id = ? ORDER BY dia, desde', [$id]) as $h) {
        $franjas[(int)$h['dia']][] = [substr($h['desde'], 0, 5), substr($h['hasta'], 0, 5), (int)$h['consultorio_id']];
    }
}
$hace = $id ? array_map('intval', array_column(q_all('SELECT prestacion_id FROM prestacion_profesional WHERE profesional_id = ?', [$id]), 'prestacion_id')) : [];
$enEsp = $id ? especialidades_de_profesional($id) : [];
$atiende = $id ? obras_sociales_de_profesional($id) : [];

/** Franjas de otros profesionales activos que usan el mismo consultorio a la misma hora */
function choques_consultorio($profId, array $franjas)
{
    $choques = [];
    foreach ($franjas as $d => $fs) {
        foreach ($fs as $f) {
            if (!$f[2]) {
                continue;
            }
            $otros = q_all(
                'SELECT h.*, p.nombre FROM profesional_horarios h JOIN profesionales p ON p.id = h.profesional_id
                  WHERE h.consultorio_id = ? AND h.dia = ? AND h.profesional_id <> ? AND p.activo = 1 AND h.desde < ? AND h.hasta > ?',
                [$f[2], $d, $profId, $f[1], $f[0]]
            );
            foreach ($otros as $o) {
                $choques[] = DIAS[$d] . ' ' . substr($o['desde'], 0, 5) . '–' . substr($o['hasta'], 0, 5) . ': ' . $o['nombre'] . ' en ' . nombre_consultorio($f[2]);
            }
        }
    }
    return array_values(array_unique($choques));
}

if (es_post()) {
    csrf_check();
    if (post('accion') === 'borrar_firma' && $id) {
        q('DELETE FROM profesional_firmas WHERE profesional_id = ?', [$id]);
        flash('ok', 'Se borró la firma y el PIN de ' . $p['nombre'] . '. Tiene que cargarlos de nuevo en «Mi firma».');
        redirect(url('profesional_form.php?id=' . $id));
    }
    if (post('accion') === 'eliminar' && $id) {
        if (q_val('SELECT COUNT(*) FROM turnos WHERE profesional_id = ?', [$id]) || q_val('SELECT COUNT(*) FROM evoluciones WHERE profesional_id = ?', [$id])) {
            q('UPDATE profesionales SET activo = 0 WHERE id = ?', [$id]);
            flash('ok', 'Tiene turnos o evoluciones, así que se desactivó en lugar de borrarse. Ya no aparece en la agenda.');
        } else {
            q('DELETE FROM profesionales WHERE id = ?', [$id]);
            flash('ok', 'Profesional eliminado.');
        }
        redirect(url('profesionales.php'));
    }
    $nombre = trim((string)post('nombre'));
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)post('color')) ? post('color') : '#1f6fb2';
    $durTurno = (int)post('duracion_turno');

    // Horario
    $franjas = [];
    $hor = (array)post('h', []);
    foreach (range(1, 7) as $d) {
        for ($i = 0; $i < 2; $i++) {
            $a = isset($hor[$d][$i]['desde']) ? hora_valida($hor[$d][$i]['desde']) : null;
            $b = isset($hor[$d][$i]['hasta']) ? hora_valida($hor[$d][$i]['hasta']) : null;
            $c = isset($hor[$d][$i]['cons']) ? (int)$hor[$d][$i]['cons'] : 0;
            if (!$a && !$b) {
                continue;
            }
            if (!$a || !$b || $b <= $a) {
                $err = 'Revisá el horario del ' . DIAS[$d] . ': la hora de salida tiene que ser posterior a la de entrada.';
            }
            $franjas[$d][] = [$a, $b, $c];
        }
        if (isset($franjas[$d][1])) {
            usort($franjas[$d], function ($x, $y) { return strcmp((string)$x[0], (string)$y[0]); });
            if ($franjas[$d][0][1] && $franjas[$d][1][0] && $franjas[$d][1][0] < $franjas[$d][0][1]) {
                $err = 'Revisá el horario del ' . DIAS[$d] . ': las dos franjas se pisan.';
            }
        }
    }
    $hace = array_map('intval', (array)post('prestaciones', []));
    $enEsp = array_map('intval', (array)post('especialidades', []));
    $atiende = post('os_todas') ? [] : array_map('intval', (array)post('obras', []));

    if ($nombre === '') {
        $err = 'Poné el nombre.';
    } elseif ($durTurno < 5 || $durTurno > 240) {
        $err = 'La duración del turno tiene que estar entre 5 y 240 minutos.';
    } elseif (!post('os_todas') && !$atiende && $obras) {
        $err = 'Marcá las obras sociales que atiende, o «Atiende todas».';
    }
    if (!$err) {
        $vals = [$nombre, trim((string)post('matricula')) ?: null, trim((string)post('telefono')) ?: null, trim((string)post('email')) ?: null,
                 $color, $durTurno, (int)post('orden'), post('activo') ? 1 : 0];
        $pdo = db();
        $pdo->beginTransaction();
        if ($id) {
            q('UPDATE profesionales SET nombre = ?, matricula = ?, telefono = ?, email = ?, color = ?, duracion_turno = ?, orden = ?, activo = ? WHERE id = ?', array_merge($vals, [$id]));
        } else {
            q('INSERT INTO profesionales (nombre, matricula, telefono, email, color, duracion_turno, orden, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $vals);
            $id = (int)$pdo->lastInsertId();
        }
        q('DELETE FROM profesional_horarios WHERE profesional_id = ?', [$id]);
        foreach ($franjas as $d => $fs) {
            foreach ($fs as $f) {
                q('INSERT INTO profesional_horarios (profesional_id, dia, desde, hasta, consultorio_id) VALUES (?, ?, ?, ?, ?)', [$id, $d, $f[0], $f[1], $f[2] ?: null]);
            }
        }
        q('DELETE FROM profesional_especialidades WHERE profesional_id = ?', [$id]);
        foreach ($enEsp as $eid) {
            q('INSERT INTO profesional_especialidades (profesional_id, especialidad_id) VALUES (?, ?)', [$id, $eid]);
        }
        q('DELETE FROM prestacion_profesional WHERE profesional_id = ?', [$id]);
        foreach ($hace as $sid) {
            q('INSERT INTO prestacion_profesional (prestacion_id, profesional_id) VALUES (?, ?)', [$sid, $id]);
        }
        q('DELETE FROM profesional_obras_sociales WHERE profesional_id = ?', [$id]);
        foreach ($atiende as $oid) {
            q('INSERT INTO profesional_obras_sociales (profesional_id, obra_social_id) VALUES (?, ?)', [$id, $oid]);
        }
        $pdo->commit();
        flash('ok', 'Profesional guardado.');
        if (post('activo') && ($choques = choques_consultorio($id, $franjas))) {
            flash('alerta', 'Ojo: hay consultorios compartidos a la misma hora. ' . implode('; ', $choques) . '. Si se turnan por semana está bien; si no, cambiá el consultorio de alguna franja.');
        }
        redirect(url('profesionales.php'));
    }
}

$v = function ($k, $def = '') use ($p) {
    return es_post() ? post($k, $def) : ($p ? $p[$k] : $def);
};
$colorActual = $v('color', colores_agenda()[count(q_all('SELECT id FROM profesionales')) % count(colores_agenda())]);
$paleta = colores_agenda();
if (!in_array($colorActual, $paleta, true)) {
    $paleta[] = $colorActual;
}
$todasOs = es_post() ? (bool)post('os_todas') : !$atiende;
layout_header($p ? $p['nombre'] : 'Nuevo profesional', ['activo' => 'profesionales.php']);
?>
<form method="post">
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="aviso aviso-error"><?= e($err) ?></div><?php endif; ?>
  <div class="grid-2">
    <div>
      <div class="card">
        <div class="form-fila">
          <div class="campo"><label for="nombre">Nombre</label><input type="text" id="nombre" name="nombre" value="<?= e($v('nombre')) ?>" required maxlength="100" autofocus placeholder="Ej: Dra. Laura Méndez"></div>
          <div class="campo"><label for="matricula">Matrícula</label><input type="text" id="matricula" name="matricula" value="<?= e($v('matricula')) ?>" maxlength="40" placeholder="MN 12345"></div>
        </div>
        <div class="form-fila">
          <div class="campo"><label for="telefono">Teléfono</label><input type="tel" id="telefono" name="telefono" value="<?= e($v('telefono')) ?>" maxlength="40">
            <small>Para avisarle por WhatsApp de sus turnos.</small></div>
          <div class="campo"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($v('email')) ?>" maxlength="120"></div>
        </div>
        <div class="form-fila">
          <div class="campo"><label for="duracion_turno">Duración del turno (min)</label><input type="number" id="duracion_turno" name="duracion_turno" value="<?= e($v('duracion_turno', 20)) ?>" min="5" max="240" step="5" required>
            <small>Para las prestaciones que no fijan otra.</small></div>
          <div class="campo"><label for="orden">Orden en la agenda</label><input type="number" id="orden" name="orden" value="<?= e($v('orden', 0)) ?>"></div>
        </div>
        <div class="campo">
          <label>Color en la agenda</label>
          <div class="colores">
            <?php foreach ($paleta as $col): ?>
              <label title="<?= e($col) ?>"><input type="radio" name="color" value="<?= e($col) ?>"<?= $col === $colorActual ? ' checked' : '' ?>><span style="background: <?= e($col) ?>"></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="campo"><label class="check"><input type="checkbox" name="activo" value="1"<?= $v('activo', 1) ? ' checked' : '' ?>> Activo (aparece en la agenda)</label></div>
      </div>
    </div>
    <div>
      <div class="card">
        <h2>Especialidades</h2>
        <?php if (!$especialidades): ?>
          <p class="apagado">Todavía no hay especialidades. Se cargan en <a href="<?= url('abm.php?t=especialidades') ?>">Especialidades</a>.</p>
        <?php else: ?>
          <p class="apagado">Puede tener varias. En la agenda se filtra por especialidad, y un turno dado a «cualquier profesional libre» se asigna entre quienes la tienen.</p>
          <div class="checks-col">
            <?php foreach ($especialidades as $c): ?>
              <label class="check"><input type="checkbox" name="especialidades[]" value="<?= (int)$c['id'] ?>" class="js-esp"<?= in_array((int)$c['id'], $enEsp, true) ? ' checked' : '' ?>><?= e($c['nombre']) ?></label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($p): $firma = firma_get($id); $usuarioProf = q_val('SELECT usuario FROM usuarios WHERE profesional_id = ? AND activo = 1 LIMIT 1', [$id]); ?>
      <div class="card">
        <h2>Firma para recetas</h2>
        <?php if ($firma && $firma['imagen'] && $firma['pin_hash']): ?>
          <p>Cargada el <?= e(fecha($firma['actualizado'])) ?><?= $firma['bloqueado_hasta'] && strtotime($firma['bloqueado_hasta']) > time() ? ' · <b style="color:var(--coral)">bloqueada por PIN equivocados</b>' : '' ?>.</p>
          <p class="apagado"><small>La firma y el PIN los carga el profesional desde su usuario («Mi firma»). Si olvidó el PIN, borrala para que la cargue de nuevo.</small></p>
          <button class="btn btn-chico btn-peligro" name="accion" value="borrar_firma" formnovalidate data-confirm="¿Borrar la firma y el PIN de <?= e($p['nombre']) ?>? Las recetas ya firmadas no cambian.">Borrar firma y PIN</button>
        <?php else: ?>
          <p class="apagado">Todavía no cargó su firma. La carga el profesional desde su usuario<?= $usuarioProf ? ' (' . e($usuarioProf) . ')' : '' ?>, en Recetas → Mi firma.<?= $usuarioProf ? '' : ' Primero creale un usuario con rol Profesional.' ?></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="card">
        <h2>Obras sociales que atiende</h2>
        <?php if (!$obras): ?>
          <p class="apagado">Todavía no hay obras sociales cargadas (<a href="<?= url('abm.php?t=obras_sociales') ?>">cargar</a>).</p>
        <?php else: ?>
          <label class="check" style="margin-bottom:10px"><input type="checkbox" name="os_todas" value="1" id="os-todas"<?= $todasOs ? ' checked' : '' ?>> Atiende todas</label>
          <div class="checks-col" id="os-lista"<?= $todasOs ? ' hidden' : '' ?>>
            <?php foreach ($obras as $o): ?>
              <label class="check"><input type="checkbox" name="obras[]" value="<?= (int)$o['id'] ?>"<?= in_array((int)$o['id'], $atiende, true) ? ' checked' : '' ?>><?= e($o['nombre']) ?></label>
            <?php endforeach; ?>
          </div>
          <p class="apagado mt"><small>Si al dar un turno el paciente tiene una que no atiende, el sistema avisa (puede atenderse como particular).</small></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="card">
    <h2>Horario de atención y consultorio</h2>
    <p class="apagado">Dejá vacío el día que no atiende. La segunda franja es para quien atiende mañana y tarde, aunque sea en otro consultorio.</p>
    <?php foreach (range(1, 7) as $d): ?>
      <div class="horario-dia horario-cons">
        <b><?= e(ucfirst(DIAS[$d])) ?></b>
        <?php for ($i = 0; $i < 2; $i++):
            $fr = isset($franjas[$d][$i]) ? $franjas[$d][$i] : ['', '', 0]; ?>
          <div class="franja">
            <input type="time" name="h[<?= $d ?>][<?= $i ?>][desde]" value="<?= e($fr[0]) ?>" aria-label="<?= e(DIAS[$d]) ?>, franja <?= $i + 1 ?>, desde">
            <span>a</span>
            <input type="time" name="h[<?= $d ?>][<?= $i ?>][hasta]" value="<?= e($fr[1]) ?>" aria-label="<?= e(DIAS[$d]) ?>, franja <?= $i + 1 ?>, hasta">
            <select name="h[<?= $d ?>][<?= $i ?>][cons]" aria-label="<?= e(DIAS[$d]) ?>, franja <?= $i + 1 ?>, consultorio">
              <option value="">Consultorio…</option><?= opciones($consultorios, $fr[2]) ?>
            </select>
          </div>
        <?php endfor; ?>
      </div>
    <?php endforeach; ?>
    <p class="mt"><button class="link" type="button" id="copiar-lunes">Copiar el horario del lunes de martes a viernes</button></p>
  </div>
  <div class="card">
    <h2>Prestaciones que realiza</h2>
    <?php if (!$prestaciones): ?>
      <p class="apagado">Todavía no hay prestaciones cargadas.</p>
    <?php else: $esp = false; ?>
      <div class="grid-3">
      <?php foreach ($prestaciones as $s):
          if ($s['especialidad'] !== $esp): echo $esp !== false ? '</div>' : ''; $esp = $s['especialidad']; ?>
        <div><h3><?= e($esp ?: 'Sin especialidad') ?></h3>
        <?php endif; ?>
        <label class="check" style="display:flex; margin-bottom:8px"><input type="checkbox" name="prestaciones[]" value="<?= (int)$s['id'] ?>" data-esp="<?= (int)$s['especialidad_id'] ?>"<?= in_array((int)$s['id'], $hace, true) ? ' checked' : '' ?>><?= e($s['nombre']) ?></label>
      <?php endforeach; echo '</div>'; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="fila">
    <button class="btn btn-pri" type="submit">Guardar</button>
    <a class="btn" href="<?= url('profesionales.php') ?>">Volver</a>
    <span class="espacio"></span>
    <?php if ($p): ?><button class="btn btn-peligro" name="accion" value="eliminar" formnovalidate data-confirm="¿Eliminar a <?= e($p['nombre']) ?>? Si tiene turnos, se desactiva.">Eliminar</button><?php endif; ?>
  </div>
</form>
<script>
// Al marcar una especialidad, si no tenía ninguna prestación de ella, se marcan todas (después se pueden destildar)
document.querySelectorAll('.js-esp').forEach(function (c) {
  c.addEventListener('change', function () {
    if (!c.checked) return;
    var pres = document.querySelectorAll('input[name="prestaciones[]"][data-esp="' + c.value + '"]');
    var alguna = Array.prototype.some.call(pres, function (s) { return s.checked; });
    if (!alguna) pres.forEach(function (s) { s.checked = true; });
  });
});
document.getElementById('copiar-lunes').addEventListener('click', function () {
  for (var d = 2; d <= 5; d++) {
    for (var i = 0; i < 2; i++) {
      ['desde', 'hasta', 'cons'].forEach(function (k) {
        var src = document.querySelector('[name="h[1][' + i + '][' + k + ']"]');
        document.querySelector('[name="h[' + d + '][' + i + '][' + k + ']"]').value = src.value;
      });
    }
  }
});
var osTodas = document.getElementById('os-todas');
if (osTodas) osTodas.addEventListener('change', function () { document.getElementById('os-lista').hidden = osTodas.checked; });
</script>
<?php layout_footer(); ?>
