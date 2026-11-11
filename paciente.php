<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('pacientes');

$id = (int)get('id', 0);
$c = q_row('SELECT pa.*, os.nombre AS obra_social, os.coseguro, os.rnas FROM pacientes pa LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id WHERE pa.id = ?', [$id]);
if (!$c) {
    flash('error', 'El paciente no existe.');
    redirect(url('pacientes.php'));
}
if (!puede_ver_paciente($id)) {
    sin_permiso('Sólo podés ver los pacientes que atienden vos o tu especialidad.');
}
$verHc = puede_ver_hc($id);

$turnos = q_all(
    "SELECT t.*, p.nombre AS profesional, p.color, os.nombre AS obra_social FROM turnos t
       JOIN profesionales p ON p.id = t.profesional_id LEFT JOIN obras_sociales os ON os.id = t.obra_social_id
      WHERE t.paciente_id = ? ORDER BY t.inicio DESC LIMIT 200",
    [$id]
);
$proximos = array_reverse(array_values(array_filter($turnos, function ($t) {
    return strtotime($t['inicio']) >= strtotime('today') && in_array($t['estado'], ['reservado', 'confirmado', 'presente', 'en_atencion'], true);
})));
$idsProximos = array_column($proximos, 'id');
$historial = array_values(array_filter($turnos, function ($t) use ($idsProximos) {
    return !in_array($t['id'], $idsProximos, true);
}));
$atendidos = array_filter($turnos, function ($t) { return $t['estado'] === 'atendido'; });
$ausentes = array_filter($turnos, function ($t) { return $t['estado'] === 'ausente'; });
$verPlata = puede('cobrar') || puede('caja');
$cobros = $verPlata ? q_all(
    "SELECT co.*, (SELECT GROUP_CONCAT(i.descripcion SEPARATOR ', ') FROM cobro_items i WHERE i.cobro_id = co.id) AS detalle
       FROM cobros co WHERE co.paciente_id = ? ORDER BY co.fecha DESC LIMIT 30",
    [$id]
) : [];
$pagado = array_sum(array_map(function ($x) { return $x['estado'] === 'valido' ? (float)$x['total'] : 0; }, $cobros));
$sinCobrar = $verPlata ? turnos_sin_cobrar($id) : [];
$evoluciones = $verHc ? evoluciones_de($id, 5) : [];
$conEvolucion = $verHc ? array_map('intval', array_column(q_all('SELECT turno_id FROM evoluciones WHERE paciente_id = ? AND turno_id IS NOT NULL', [$id]), 'turno_id')) : [];
$wa = whatsapp_link($c['telefono']);

$sub = trim(($c['dni'] ? 'DNI ' . dni_txt($c['dni']) : '') . ($c['fecha_nac'] ? ' · ' . edad_txt($c['fecha_nac']) : '') . ' · ' . cobertura_txt($c['obra_social']), ' ·');
layout_header(nombre_paciente($c), ['activo' => 'pacientes.php', 'subtitulo' => $sub]);
?>
<div class="grid-2-1">
  <div>
    <?php if ($verHc): ?>
      <?php if (trim((string)$c['antecedentes']) !== ''): ?>
        <div class="antecedentes"><b>Antecedentes</b><?= e($c['antecedentes']) ?></div>
      <?php endif; ?>
      <div class="card">
        <div class="card-cab">
          <h2>Historia clínica</h2>
          <span class="fila"><a href="<?= url('historia.php?id=' . $id) ?>">Ver completa</a> <a class="btn btn-chico" href="<?= url('evolucion.php?paciente=' . $id) ?>">Nueva evolución</a></span>
        </div>
        <?php if (!$evoluciones): ?>
          <p class="apagado">Todavía no tiene evoluciones.<?= trim((string)$c['antecedentes']) === '' ? ' Tampoco antecedentes: se cargan en <a href="' . url('historia.php?id=' . $id) . '">la historia clínica</a>.' : '' ?></p>
        <?php else: foreach ($evoluciones as $ev): ?>
          <?= evolucion_html($ev) ?>
        <?php endforeach; endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($verHc):
        $recetasPac = q_all("SELECT r.*, p.nombre AS profesional, (SELECT GROUP_CONCAT(i.droga SEPARATOR ', ') FROM receta_items i WHERE i.receta_id = r.id) AS drogas
                               FROM recetas r JOIN profesionales p ON p.id = r.profesional_id WHERE r.paciente_id = ? ORDER BY r.creado DESC LIMIT 8", [$id]); ?>
      <div class="card">
        <div class="card-cab"><h2>Recetas</h2><a class="btn btn-chico" href="<?= url('receta.php?paciente=' . $id) ?>">Nueva receta</a></div>
        <?php if (!$recetasPac): ?>
          <p class="apagado">Todavía no tiene recetas.</p>
        <?php else: ?>
        <table class="tabla">
          <?php foreach ($recetasPac as $rx): ?>
            <tr class="<?= $rx['estado'] === 'anulada' ? 'anulado' : '' ?>">
              <td class="nowrap"><a href="<?= url('receta.php?id=' . $rx['id']) ?>"><?= e(fecha($rx['fecha'])) ?></a></td>
              <td><small><?= e(droga_txt($rx['drogas'])) ?></small><br><small class="apagado"><?= e($rx['profesional']) ?></small></td>
              <td class="der"><?= $rx['estado'] !== 'firmada' ? badge_receta($rx['estado']) : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($verHc):
        $ordenesPac = q_all("SELECT o.*, p.nombre AS profesional, (SELECT GROUP_CONCAT(i.descripcion ORDER BY i.orden SEPARATOR '|') FROM orden_items i WHERE i.orden_id = o.id) AS practicas
                               FROM ordenes o JOIN profesionales p ON p.id = o.profesional_id WHERE o.paciente_id = ? ORDER BY o.creado DESC LIMIT 8", [$id]); ?>
      <div class="card">
        <div class="card-cab"><h2>Órdenes</h2><span class="fila"><a class="btn btn-chico" href="<?= url('orden.php?paciente=' . $id . '&tipo=laboratorio') ?>">Laboratorio</a> <a class="btn btn-chico" href="<?= url('orden.php?paciente=' . $id . '&tipo=estudios') ?>">Estudios</a></span></div>
        <?php if (!$ordenesPac): ?>
          <p class="apagado">Todavía no tiene órdenes de laboratorio ni de estudios.</p>
        <?php else: ?>
        <table class="tabla">
          <?php foreach ($ordenesPac as $ox): ?>
            <tr class="<?= $ox['estado'] === 'anulada' ? 'anulado' : '' ?>">
              <td class="nowrap"><a href="<?= url('orden.php?id=' . $ox['id']) ?>"><?= e(fecha($ox['fecha'])) ?></a><br><small><?= e(TIPOS_ORDEN[$ox['tipo']]) ?></small></td>
              <td><small><?= e(orden_resumen($ox['practicas'])) ?></small><br><small class="apagado"><?= e($ox['profesional']) ?></small></td>
              <td class="der"><?= $ox['estado'] !== 'firmada' ? badge_receta($ox['estado']) : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-cab">
        <h2>Turnos anteriores</h2>
        <span class="apagado"><?= plural(count($atendidos), 'atención', 'atenciones') ?><?= $ausentes ? ', ' . plural(count($ausentes), 'ausencia', 'ausencias') : '' ?></span>
      </div>
      <?php if (!$historial): ?>
        <p class="apagado">Todavía no tiene turnos pasados.</p>
      <?php else: ?>
      <div class="tabla-scroll">
      <table class="tabla">
        <thead><tr><th>Fecha</th><th>Prestación</th><th>Cobertura</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($historial as $t): ?>
          <tr class="<?= $t['estado'] === 'cancelado' ? 'inactivo' : '' ?>">
            <td class="nowrap"><a href="<?= url('turno.php?id=' . $t['id']) ?>"><?= e(fecha($t['inicio'])) ?></a><br><small><?= hora($t['inicio']) ?></small></td>
            <td><?= e($t['prestacion_nombre']) ?><br><small><span class="punto" style="--c: <?= e($t['color']) ?>"></span><?= e($t['profesional']) ?></small></td>
            <td><span class="cob<?= $t['obra_social'] ? '' : ' cob-part' ?>"><?= e(cobertura_txt($t['obra_social'])) ?></span></td>
            <td class="der"><?= $t['estado'] !== 'atendido' ? badge_turno($t['estado']) : ($verHc && !in_array((int)$t['id'], $conEvolucion, true) ? '<small class="apagado">sin evolución</small>' : '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($verPlata): ?>
    <div class="card">
      <div class="card-cab"><h2>Cobros</h2><?php if ($pagado): ?><span class="apagado">Total: <?= money($pagado) ?></span><?php endif; ?></div>
      <?php if ($sinCobrar): ?>
        <div class="aviso aviso-alerta"><?= count($sinCobrar) === 1 ? 'Tiene 1 turno sin cobrar' : 'Tiene ' . count($sinCobrar) . ' turnos sin cobrar' ?> (<?= money(array_sum(array_column($sinCobrar, 'precio'))) ?>).
          <a href="<?= url('cobro.php?paciente=' . $id) ?>">Cobrar ahora</a></div>
      <?php endif; ?>
      <?php if (!$cobros): ?>
        <p class="apagado">Sin cobros registrados.</p>
      <?php else: ?>
      <table class="tabla">
        <?php foreach ($cobros as $x): ?>
          <tr class="<?= $x['estado'] === 'anulado' ? 'anulado' : '' ?>">
            <td class="nowrap"><a href="<?= url('recibo.php?id=' . $x['id']) ?>"><?= e(fecha($x['fecha'])) ?></a></td>
            <td><?= e($x['detalle']) ?></td>
            <td class="der num"><?= money($x['total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <dl class="datos">
        <?php if ($c['telefono']): ?><dt>Teléfono</dt><dd><?= e($c['telefono']) ?><?php if ($wa): ?> · <a href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></dd><?php endif; ?>
        <?php if ($c['email']): ?><dt>Email</dt><dd><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></dd><?php endif; ?>
        <?php if ($c['dni']): ?><dt>DNI</dt><dd><?= e(dni_txt($c['dni'])) ?></dd><?php endif; ?>
        <?php if ($c['fecha_nac']): ?><dt>Nacimiento</dt><dd><?= e(fecha($c['fecha_nac'])) ?> (<?= e(edad_txt($c['fecha_nac'])) ?>)</dd><?php endif; ?>
        <?php if ($c['sexo']): ?><dt>Sexo</dt><dd><?= e(SEXOS[$c['sexo']]) ?></dd><?php endif; ?>
        <?php if ($c['direccion']): ?><dt>Dirección</dt><dd><?= e($c['direccion']) ?></dd><?php endif; ?>
        <dt>Cobertura</dt><dd><span class="cob<?= $c['obra_social'] ? '' : ' cob-part' ?>"><?= e(cobertura_txt($c['obra_social'])) ?></span>
          <?php if ($c['obra_social']): ?><br><small><?= $c['rnas'] ? 'RNAS ' . e($c['rnas']) . ' · ' : '' ?><?= $c['plan'] ? 'Plan ' . e($c['plan']) . ' · ' : '' ?><?= $c['nro_afiliado'] ? 'Afiliado ' . e($c['nro_afiliado']) : 'Falta el nº de afiliado' ?></small><?php endif; ?></dd>
        <?php if ($c['notas']): ?><dt>Notas</dt><dd style="white-space:pre-line"><?= e($c['notas']) ?></dd><?php endif; ?>
        <dt>Alta</dt><dd class="apagado"><?= e(fecha($c['creado'])) ?></dd>
      </dl>
      <div class="fila mt">
        <?php if (puede('turnos')): ?><a class="btn btn-pri" href="<?= url('turno.php?paciente=' . $id) ?>">Dar turno</a><?php endif; ?>
        <?php if (puede('pacientes_editar')): ?><a class="btn" href="<?= url('paciente_form.php?id=' . $id) ?>">Editar datos</a><?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2>Próximos turnos</h2>
      <?php if (!$proximos): ?>
        <p class="apagado">No tiene turnos agendados.</p>
      <?php else: ?>
      <table class="tabla">
        <?php foreach ($proximos as $t): ?>
          <tr>
            <td class="nowrap"><a href="<?= url('turno.php?id=' . $t['id']) ?>"><?= e(ucfirst(fecha_corta($t['inicio']))) ?></a><br><small><?= hora($t['inicio']) ?> hs</small></td>
            <td><?= e($t['prestacion_nombre']) ?><br><small><?= e($t['profesional']) ?></small><?= $t['estado'] !== 'reservado' ? ' ' . badge_turno($t['estado']) : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
    <?php if (!$verHc && !es_profesional()): ?>
      <p class="apagado"><small>La historia clínica (antecedentes y evoluciones) la ven sólo los profesionales.</small></p>
    <?php endif; ?>
  </div>
</div>
<?php layout_footer(); ?>
