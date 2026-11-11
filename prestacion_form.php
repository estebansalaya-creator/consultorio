<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('prestaciones');

$id = (int)get('id', 0);
$s = $id ? q_row('SELECT * FROM prestaciones WHERE id = ?', [$id]) : null;
if ($id && !$s) {
    redirect(url('prestaciones.php'));
}
$especialidades = q_all('SELECT * FROM especialidades WHERE activo = 1 OR id = ? ORDER BY orden, nombre', [$s ? (int)$s['especialidad_id'] : 0]);
$consultorios = q_all('SELECT * FROM consultorios WHERE activo = 1 OR id = ? ORDER BY orden, nombre', [$s ? (int)$s['consultorio_id'] : 0]);
$profs = q_all('SELECT * FROM profesionales WHERE activo = 1 ORDER BY orden, nombre');
$hacen = $id ? array_map('intval', array_column(q_all('SELECT profesional_id FROM prestacion_profesional WHERE prestacion_id = ?', [$id]), 'profesional_id')) : [];
$err = '';

if (es_post()) {
    csrf_check();
    if (post('accion') === 'eliminar' && $id) {
        if (q_val('SELECT COUNT(*) FROM turnos WHERE prestacion_id = ?', [$id])) {
            q('UPDATE prestaciones SET activo = 0 WHERE id = ?', [$id]);
            flash('ok', 'La prestación tiene turnos, así que se desactivó en lugar de borrarse.');
        } else {
            q('DELETE FROM prestaciones WHERE id = ?', [$id]);
            flash('ok', 'Prestación eliminada.');
        }
        redirect(url('prestaciones.php'));
    }
    $nombre = trim((string)post('nombre'));
    $durTxt = trim((string)post('duracion'));
    $dur = $durTxt === '' ? null : (int)$durTxt;
    $precio = num(post('precio'));
    if ($nombre === '') {
        $err = 'Poné el nombre de la prestación.';
    } elseif ($dur !== null && ($dur < 5 || $dur > 600)) {
        $err = 'La duración tiene que estar entre 5 y 600 minutos (o vacía para usar la de cada profesional).';
    } elseif ($precio < 0) {
        $err = 'El precio no puede ser negativo.';
    } else {
        $vals = [
            (int)post('especialidad_id') ?: null, $nombre, $dur, $precio,
            (int)post('consultorio_id') ?: null, post('online') ? 1 : 0, (int)post('orden'), post('activo') ? 1 : 0,
        ];
        $pdo = db();
        $pdo->beginTransaction();
        if ($id) {
            q('UPDATE prestaciones SET especialidad_id = ?, nombre = ?, duracion = ?, precio = ?, consultorio_id = ?, online = ?, orden = ?, activo = ? WHERE id = ?', array_merge($vals, [$id]));
        } else {
            q('INSERT INTO prestaciones (especialidad_id, nombre, duracion, precio, consultorio_id, online, orden, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $vals);
            $id = (int)$pdo->lastInsertId();
        }
        q('DELETE FROM prestacion_profesional WHERE prestacion_id = ?', [$id]);
        foreach ((array)post('profesionales', []) as $pid) {
            q('INSERT INTO prestacion_profesional (prestacion_id, profesional_id) VALUES (?, ?)', [$id, (int)$pid]);
        }
        // Código en cada nomenclador (uno por nomenclador)
        foreach ((array)post('practica', []) as $nomId => $practicaId) {
            q('DELETE pc FROM prestacion_codigo pc JOIN prest_nomenclador_codigo c ON c.id = pc.codigo_id WHERE pc.prestacion_id = ? AND c.id_nomenclador = ?', [$id, (int)$nomId]);
            if ((int)$practicaId && q_val('SELECT id FROM prest_nomenclador_codigo WHERE id = ? AND id_nomenclador = ?', [(int)$practicaId, (int)$nomId])) {
                q('INSERT INTO prestacion_codigo (prestacion_id, codigo_id) VALUES (?, ?)', [$id, (int)$practicaId]);
            }
        }
        $pdo->commit();
        flash('ok', 'Prestación guardada.');
        redirect(url('prestaciones.php'));
    }
    $hacen = array_map('intval', (array)post('profesionales', []));
}

$nomencladores = nomencladores_activos();
$codigos = $id ? practicas_de_prestacion($id) : [];
if (es_post()) {
    foreach ((array)post('practica', []) as $nomId => $practicaId) {
        $codigos[(int)$nomId] = (int)$practicaId ? q_row('SELECT c.*, c.nombre AS descripcion FROM prest_nomenclador_codigo c WHERE c.id = ?', [(int)$practicaId]) : null;
    }
}
$v = function ($k, $def = '') use ($s) {
    return es_post() ? post($k, $def) : ($s ? $s[$k] : $def);
};
// Prestación nueva: se proponen los profesionales de la especialidad elegida (o todos)
$espInicial = (int)$v('especialidad_id', (int)get('especialidad', 0));
if (!$s && !es_post()) {
    $hacen = $espInicial ? array_map('intval', array_column(profesionales_de_especialidad($espInicial), 'id')) : [];
}
layout_header($s ? $s['nombre'] : 'Nueva prestación', ['activo' => 'prestaciones.php']);
?>
<form method="post" class="grid-2">
  <?= csrf_field() ?>
  <div class="card">
    <?php if ($err): ?><div class="aviso aviso-error"><?= e($err) ?></div><?php endif; ?>
    <div class="campo"><label for="nombre">Nombre</label><input type="text" id="nombre" name="nombre" value="<?= e($v('nombre')) ?>" required maxlength="100" autofocus placeholder="Ej: Consulta cardiológica, Electrocardiograma"></div>
    <div class="form-fila">
      <div class="campo"><label for="especialidad_id">Especialidad</label><select id="especialidad_id" name="especialidad_id"><option value="">Sin especialidad</option><?= opciones($especialidades, $espInicial) ?></select></div>
      <div class="campo"><label for="consultorio_id">Se hace en</label><select id="consultorio_id" name="consultorio_id"><option value="">El consultorio del profesional</option><?= opciones($consultorios, $v('consultorio_id')) ?></select>
        <small>Elegí una sala sólo si la prestación necesita un lugar o equipo en particular (ej. ergometría).</small></div>
    </div>
    <div class="form-fila">
      <div class="campo"><label for="duracion">Duración (minutos)</label><input type="number" id="duracion" name="duracion" value="<?= e($v('duracion', '')) ?>" min="5" max="600" step="5" placeholder="La del profesional">
        <small>Vacía: usa la duración de turno de cada profesional.</small></div>
      <div class="campo"><label for="precio">Precio particular $</label><input type="text" inputmode="decimal" id="precio" name="precio" value="<?= e($s || es_post() ? (string)(float)$v('precio') : '') ?>" required></div>
      <div class="campo"><label for="orden">Orden en la lista</label><input type="number" id="orden" name="orden" value="<?= e($v('orden', 0)) ?>"></div>
    </div>
    <p class="apagado"><small>Con obra social, el paciente paga el coseguro de su obra social (se carga en Obras sociales). Al dar el turno se puede cambiar.</small></p>
    <div class="campo">
      <label class="check"><input type="checkbox" name="online" value="1"<?= $v('online', 1) ? ' checked' : '' ?>> Se podrá reservar online</label>
      <label class="check"><input type="checkbox" name="activo" value="1"<?= $v('activo', 1) ? ' checked' : '' ?>> Activa</label>
    </div>
    <div class="fila">
      <button class="btn btn-pri" type="submit">Guardar</button>
      <a class="btn" href="<?= url('prestaciones.php') ?>">Volver</a>
      <span class="espacio"></span>
      <?php if ($s): ?><button class="btn btn-peligro" name="accion" value="eliminar" formnovalidate data-confirm="¿Eliminar esta prestación? Si ya tiene turnos, se desactiva.">Eliminar</button><?php endif; ?>
    </div>
  </div>
  <div>
  <div class="card">
    <h2>Quién la hace</h2>
    <?php if (!$profs): ?>
      <p class="apagado">Todavía no hay profesionales cargados.</p>
    <?php else: ?>
      <p class="apagado">Al dar un turno de esta prestación se sugieren sólo estos profesionales.</p>
      <?php foreach ($profs as $p): ?>
        <label class="check" style="display:flex; margin-bottom:8px"><input type="checkbox" name="profesionales[]" value="<?= (int)$p['id'] ?>"<?= in_array((int)$p['id'], $hacen, true) ? ' checked' : '' ?>>
          <span class="punto" style="--c: <?= e($p['color']) ?>"></span><?= e($p['nombre']) ?> <small>&nbsp;<?= e(especialidades_txt($p['id'])) ?></small></label>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-cab"><h2>Códigos de nomenclador</h2><a href="<?= url('nomencladores.php') ?>">Nomencladores</a></div>
    <?php if (!$nomencladores): ?>
      <p class="apagado">Todavía no hay nomencladores cargados. Se cargan en <a href="<?= url('nomencladores.php') ?>">Nomencladores</a>.</p>
    <?php else: ?>
      <p class="apagado">El código de esta prestación en cada nomenclador. Al dar un turno se guarda el del nomenclador de la obra social del paciente.</p>
      <?php foreach ($nomencladores as $n): $cod = isset($codigos[(int)$n['id']]) ? $codigos[(int)$n['id']] : null; ?>
        <div class="campo js-practica" data-nom="<?= (int)$n['id'] ?>">
          <label for="pr-<?= (int)$n['id'] ?>"><?= e($n['nombre']) ?></label>
          <input type="hidden" name="practica[<?= (int)$n['id'] ?>]" value="<?= $cod ? (int)$cod['id'] : '' ?>">
          <div class="cie-entrada">
            <span class="cie-chip"<?= $cod ? '' : ' hidden' ?>><b><?= $cod ? e($cod['codigo']) : '' ?></b><button type="button" title="Quitar el código" aria-label="Quitar el código">×</button></span>
            <input type="text" id="pr-<?= (int)$n['id'] ?>" value="<?= $cod ? e($cod['descripcion']) : '' ?>" autocomplete="off" placeholder="Buscá por código o descripción">
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  </div>
</form>
<script>
document.querySelectorAll('.js-practica').forEach(function (cont) {
  var id = cont.querySelector('input[type=hidden]'), txt = cont.querySelector('input[type=text]'), chip = cont.querySelector('.cie-chip');
  App.buscador(txt, {
    url: function (q) { return 'api/practicas.php?nom=' + cont.dataset.nom + '&q=' + encodeURIComponent(q); },
    lista: 'practicas', min: 1, vacio: 'Sin prácticas que coincidan en este nomenclador',
    item: function (r) { return '<b>' + App.esc(r.codigo) + '</b> ' + App.esc(r.descripcion); },
    elegir: function (r) { id.value = r.id; chip.hidden = false; chip.querySelector('b').textContent = r.codigo; txt.value = r.descripcion; }
  });
  chip.querySelector('button').addEventListener('click', function () { id.value = ''; chip.hidden = true; txt.value = ''; txt.focus(); });
});
</script>
<?php layout_footer(); ?>
