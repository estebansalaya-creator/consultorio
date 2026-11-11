<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('prestaciones');

$especialidades = q_all('SELECT * FROM especialidades ORDER BY orden, nombre');

if (es_post() && post('accion') === 'precios') {
    csrf_check();
    $pct = num(post('porcentaje'));
    $redondeo = max(1, (int)post('redondeo'));
    $esp = (int)post('especialidad_id');
    if ($pct == 0 || $pct < -90 || $pct > 500) {
        flash('error', 'Poné un porcentaje entre -90 y 500.');
    } else {
        $filas = q_all('SELECT id, precio FROM prestaciones WHERE activo = 1' . ($esp ? ' AND especialidad_id = ?' : ''), $esp ? [$esp] : []);
        foreach ($filas as $s) {
            $nuevo = round($s['precio'] * (1 + $pct / 100) / $redondeo) * $redondeo;
            q('UPDATE prestaciones SET precio = ? WHERE id = ?', [$nuevo, $s['id']]);
        }
        flash('ok', 'Precios particulares actualizados ' . ($pct > 0 ? '+' : '') . str_replace('.', ',', (string)$pct) . '% en ' . plural(count($filas), 'prestación', 'prestaciones') . '. Los turnos ya dados conservan su precio.');
    }
    redirect(url('prestaciones.php'));
}

$verInactivos = (bool)get('inactivos');
$prestaciones = q_all(
    'SELECT s.*, c.nombre AS especialidad, cb.nombre AS consultorio,
            (SELECT GROUP_CONCAT(p.nombre ORDER BY p.orden SEPARATOR ", ") FROM prestacion_profesional sp JOIN profesionales p ON p.id = sp.profesional_id
              WHERE sp.prestacion_id = s.id AND p.activo = 1) AS profesionales,
            (SELECT GROUP_CONCAT(CONCAT(n.nombre, " ", k.codigo) ORDER BY n.id SEPARATOR " · ") FROM prestacion_codigo pc JOIN prest_nomenclador_codigo k ON k.id = pc.codigo_id
               JOIN prest_nomenclador n ON n.id = k.id_nomenclador WHERE pc.prestacion_id = s.id AND n.activo = 1) AS codigos
       FROM prestaciones s LEFT JOIN especialidades c ON c.id = s.especialidad_id LEFT JOIN consultorios cb ON cb.id = s.consultorio_id'
    . ($verInactivos ? '' : ' WHERE s.activo = 1') .
    ' ORDER BY c.orden IS NULL, c.orden, c.nombre, s.orden, s.nombre'
);
$obras = q_all('SELECT * FROM obras_sociales WHERE activo = 1 ORDER BY orden, nombre');

layout_header('Prestaciones', ['acciones' => '<a class="btn btn-pri" href="' . url('prestacion_form.php') . '">Nueva prestación</a>']);
?>
<div class="grid-2-1">
  <div class="card">
    <?php if (!$prestaciones): ?>
      <div class="vacio">Todavía no hay prestaciones. Cargá las consultas, estudios y prácticas que se dan con turno, con su precio particular.</div>
    <?php else: ?>
    <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr><th>Prestación</th><th class="der">Duración</th><th class="der">Particular</th><th>Quién la hace</th><th></th></tr></thead>
      <tbody>
      <?php $cat = false; foreach ($prestaciones as $s):
          if ($s['especialidad'] !== $cat): $cat = $s['especialidad']; ?>
          <tr class="sep"><td colspan="5"><?= e($cat ?: 'Sin especialidad') ?></td></tr>
        <?php endif; ?>
        <tr class="<?= $s['activo'] ? '' : 'inactivo' ?>">
          <td><a href="<?= url('prestacion_form.php?id=' . $s['id']) ?>"><?= e($s['nombre']) ?></a><?= $s['activo'] ? '' : ' <small>(inactiva)</small>' ?>
            <?php if ($s['codigos']): ?> <small class="apagado">· <?= e($s['codigos']) ?></small><?php endif; ?>
            <?php if ($s['consultorio']): ?><br><small>Se hace en <?= e($s['consultorio']) ?></small><?php endif; ?></td>
          <td class="der nowrap"><?= $s['duracion'] ? duracion_txt($s['duracion']) : '<small class="apagado">la del profesional</small>' ?></td>
          <td class="der num nowrap"><?= money($s['precio']) ?></td>
          <td><small><?= $s['profesionales'] ? e($s['profesionales']) : '<span style="color:var(--coral)">Nadie asignado</span>' ?></small></td>
          <td class="acciones"><a class="btn btn-chico" href="<?= url('prestacion_form.php?id=' . $s['id']) ?>">Editar</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <p class="mt"><a href="<?= url('prestaciones.php' . ($verInactivos ? '' : '?inactivos=1')) ?>"><?= $verInactivos ? 'Ocultar las inactivas' : 'Ver también las inactivas' ?></a></p>
  </div>

  <div>
    <div class="card">
      <h2>Actualizar precios particulares</h2>
      <p class="apagado">Aumenta (o baja, con un número negativo) todos los precios particulares de una vez. Los turnos ya dados mantienen su precio. Los coseguros se cambian en <a href="<?= url('abm.php?t=obras_sociales') ?>">Obras sociales</a>.</p>
      <form method="post" data-confirm="¿Actualizar los precios?">
        <?= csrf_field() ?><input type="hidden" name="accion" value="precios">
        <div class="form-fila">
          <div class="campo"><label for="porcentaje">Porcentaje %</label><input type="text" inputmode="decimal" id="porcentaje" name="porcentaje" placeholder="Ej: 8" required></div>
          <div class="campo"><label for="redondeo">Redondear a</label>
            <select id="redondeo" name="redondeo"><option value="100">$ 100</option><option value="500" selected>$ 500</option><option value="1000">$ 1.000</option></select></div>
        </div>
        <div class="campo"><label for="especialidad_id">Prestaciones</label>
          <select id="especialidad_id" name="especialidad_id"><option value="0">Todas</option><?= opciones($especialidades) ?></select></div>
        <button class="btn" type="submit">Actualizar precios</button>
      </form>
    </div>
    <div class="card">
      <div class="card-cab"><h2>Obras sociales</h2><a href="<?= url('abm.php?t=obras_sociales') ?>">Editar</a></div>
      <?php if (!$obras): ?>
        <p class="apagado">Todavía no hay obras sociales cargadas: todos los turnos son particulares.</p>
      <?php else: ?>
      <table class="tabla">
        <thead><tr><th>Cobertura</th><th class="der">Coseguro</th></tr></thead>
        <?php foreach ($obras as $o): ?><tr><td><?= e($o['nombre']) ?></td><td class="der num"><?= $o['coseguro'] > 0 ? money($o['coseguro']) : '<small class="apagado">sin cargo</small>' ?></td></tr><?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Organizar</h2>
      <p><a href="<?= url('abm.php?t=especialidades') ?>">Especialidades</a> <span class="apagado">agrupan prestaciones y profesionales; filtran la agenda.</span></p>
      <p><a href="<?= url('abm.php?t=consultorios') ?>">Consultorios</a> <span class="apagado">evitan que dos profesionales usen el mismo consultorio a la misma hora.</span></p>
      <p><a href="<?= url('nomencladores.php') ?>">Nomencladores</a> <span class="apagado">códigos de práctica de cada prestación, por obra social.</span></p>
    </div>
  </div>
</div>
<?php layout_footer(); ?>
