<?php
/*
 * Historia clínica completa de un paciente (sólo profesionales). Se puede imprimir.
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('pacientes');

$id = (int)get('id', 0);
$p = q_row('SELECT pa.*, os.nombre AS obra_social, pr.nombre AS antecedentes_por
              FROM pacientes pa LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id
              LEFT JOIN profesionales pr ON pr.id = pa.antecedentes_prof WHERE pa.id = ?', [$id]);
if (!$p) {
    flash('error', 'El paciente no existe.');
    redirect(url('pacientes.php'));
}
if (!puede_ver_hc($id)) {
    sin_permiso(puede_hc()
        ? 'Sólo podés ver la historia clínica de pacientes que atienden vos o tu especialidad.'
        : 'La historia clínica la ven sólo los profesionales.');
}

// Antecedentes
if (es_post() && post('accion') === 'antecedentes') {
    csrf_check();
    q('UPDATE pacientes SET antecedentes = ?, antecedentes_prof = ?, antecedentes_fecha = NOW() WHERE id = ?',
      [trim((string)post('antecedentes')) ?: null, mi_profesional_id(), $id]);
    flash('ok', 'Antecedentes actualizados.');
    redirect(url('historia.php?id=' . $id));
}

$evoluciones = evoluciones_de($id);
$centro = config_get('nombre_centro');
layout_header('Historia clínica', ['activo' => 'pacientes.php', 'clase' => 'pagina-historia', 'acciones' =>
    '<a class="btn" href="' . url('paciente.php?id=' . $id) . '">Ficha</a> <a class="btn" href="' . url('receta.php?paciente=' . $id) . '">Nueva receta</a> <a class="btn" href="' . url('orden.php?paciente=' . $id . '&tipo=laboratorio') . '">Nueva orden</a> <a class="btn btn-pri" href="' . url('evolucion.php?paciente=' . $id) . '">Nueva evolución</a>']);
?>
<div class="card" style="max-width:900px">
  <div class="hc-cab">
    <div class="fila"><h2><?= e(apellido_nombre($p)) ?></h2><span class="espacio"></span>
      <button class="btn btn-chico no-print" type="button" onclick="window.print()">Imprimir</button></div>
    <div class="datos-linea">
      <?php if ($p['dni']): ?><span>DNI <b><?= e(dni_txt($p['dni'])) ?></b></span><?php endif; ?>
      <?php if ($p['fecha_nac']): ?><span>Nació el <b><?= e(fecha($p['fecha_nac'])) ?></b> (<?= e(edad_txt($p['fecha_nac'])) ?>)</span><?php endif; ?>
      <?php if ($p['sexo']): ?><span>Sexo <b><?= e(SEXOS[$p['sexo']]) ?></b></span><?php endif; ?>
      <span>Cobertura <b><?= e(cobertura_txt($p['obra_social'])) ?></b><?= $p['obra_social'] && $p['nro_afiliado'] ? ' · ' . e($p['nro_afiliado']) : '' ?></span>
    </div>
    <p class="apagado" style="margin:6px 0 0"><small><?= e($centro) ?> · Impreso el <?= e(fecha_hora(date('Y-m-d H:i:s'))) ?> por <?= e(usuario()['nombre']) ?></small></p>
  </div>

  <h3>Antecedentes</h3>
  <?php if (trim((string)$p['antecedentes']) !== ''): ?>
    <div class="antecedentes"><?= e($p['antecedentes']) ?></div>
    <?php if ($p['antecedentes_fecha']): ?><p class="apagado" style="margin-top:-10px"><small>Actualizados el <?= e(fecha($p['antecedentes_fecha'])) ?><?= $p['antecedentes_por'] ? ' por ' . e($p['antecedentes_por']) : '' ?></small></p><?php endif; ?>
  <?php else: ?>
    <p class="apagado">Sin antecedentes cargados.</p>
  <?php endif; ?>
  <details class="no-print mt">
    <summary class="link" style="text-decoration:none">Actualizar antecedentes</summary>
    <form method="post" class="mt">
      <?= csrf_field() ?><input type="hidden" name="accion" value="antecedentes">
      <div class="campo"><textarea name="antecedentes" rows="5" aria-label="Antecedentes" placeholder="Alergias, medicación habitual, enfermedades crónicas, cirugías, antecedentes familiares"><?= e($p['antecedentes']) ?></textarea>
        <small>Lo que se escriba acá lo ven todos los profesionales en cada turno de este paciente, resaltado en rojo.</small></div>
      <button class="btn" type="submit">Guardar antecedentes</button>
    </form>
  </details>

  <?php $recetasPac = q_all("SELECT r.*, p.nombre AS profesional FROM recetas r JOIN profesionales p ON p.id = r.profesional_id WHERE r.paciente_id = ? AND r.estado <> 'borrador' ORDER BY r.fecha DESC, r.id DESC", [$id]);
  if ($recetasPac): ?>
    <h3 class="mt">Recetas <span class="apagado" style="font-weight:400">(<?= count($recetasPac) ?>)</span></h3>
    <table class="tabla">
      <?php foreach ($recetasPac as $rx): $its = receta_items($rx['id']); ?>
        <tr class="<?= $rx['estado'] === 'anulada' ? 'anulado' : '' ?>">
          <td class="nowrap"><a href="<?= url('receta.php?id=' . $rx['id']) ?>"><?= e(fecha($rx['fecha'])) ?></a><br><small>N.º <?= sprintf('%06d', $rx['numero']) ?></small></td>
          <td><?php foreach ($its as $i): ?><?= e(droga_txt($i['droga'])) ?><?= $i['presentacion'] ? ' <small>' . e($i['presentacion']) . '</small>' : '' ?><?= $i['posologia'] ? '<br><small class="apagado">' . e($i['posologia']) . '</small>' : '' ?><br><?php endforeach; ?></td>
          <td><small><?= e($rx['profesional']) ?><?= $rx['cie10_codigo'] ? '<br><span class="cie">' . e($rx['cie10_codigo']) . '</span>' : '' ?></small><?= $rx['estado'] === 'anulada' ? '<br>' . badge_receta('anulada') : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php $ordenesPac = q_all("SELECT o.*, p.nombre AS profesional FROM ordenes o JOIN profesionales p ON p.id = o.profesional_id WHERE o.paciente_id = ? AND o.estado <> 'borrador' ORDER BY o.fecha DESC, o.id DESC", [$id]);
  if ($ordenesPac): ?>
    <h3 class="mt">Órdenes de laboratorio y estudios <span class="apagado" style="font-weight:400">(<?= count($ordenesPac) ?>)</span></h3>
    <table class="tabla">
      <?php foreach ($ordenesPac as $ox): $its = orden_items($ox['id']); ?>
        <tr class="<?= $ox['estado'] === 'anulada' ? 'anulado' : '' ?>">
          <td class="nowrap"><a href="<?= url('orden.php?id=' . $ox['id']) ?>"><?= e(fecha($ox['fecha'])) ?></a><br><small><?= e(TIPOS_ORDEN[$ox['tipo']]) ?> N.º <?= sprintf('%06d', $ox['numero']) ?></small></td>
          <td><?php foreach ($its as $i): ?><?= $i['codigo'] ? '<small class="apagado">' . e($i['codigo']) . '</small> ' : '' ?><?= e(nombre_corto_practica($i['descripcion'])) ?><br><?php endforeach; ?></td>
          <td><small><?= e($ox['profesional']) ?><?= $ox['cie10_codigo'] ? '<br><span class="cie">' . e($ox['cie10_codigo']) . '</span>' : '' ?></small><?= $ox['estado'] === 'anulada' ? '<br>' . badge_receta('anulada') : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <h3 class="mt">Evoluciones <span class="apagado" style="font-weight:400">(<?= count($evoluciones) ?>)</span></h3>
  <?php if (!$evoluciones): ?>
    <p class="apagado">Todavía no hay evoluciones. Se escriben desde cada turno atendido, o con «Nueva evolución».</p>
  <?php else: foreach ($evoluciones as $ev): ?>
    <?= evolucion_html($ev) ?>
  <?php endforeach; endif; ?>
</div>
<?php layout_footer(); ?>
