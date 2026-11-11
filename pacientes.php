<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('pacientes');

$buscar = trim((string)get('buscar', ''));
$os = get('os', '');
$params = [];
$where = ['pa.activo = 1'];
if ($buscar !== '') {
    foreach (preg_split('/\s+/', $buscar) as $palabra) {
        $num = preg_replace('/\D/', '', $palabra);
        $cond = '(pa.nombre LIKE ? OR pa.apellido LIKE ? OR pa.email LIKE ? OR pa.nro_afiliado LIKE ?';
        array_push($params, "%$palabra%", "%$palabra%", "%$palabra%", "%$palabra%");
        if (strlen($num) >= 3) {
            $cond .= " OR REPLACE(REPLACE(pa.telefono, ' ', ''), '-', '') LIKE ? OR pa.dni LIKE ?";
            array_push($params, "%$num%", "%$num%");
        }
        $where[] = $cond . ')';
    }
}
if ($os === 'part') {
    $where[] = 'pa.obra_social_id IS NULL';
} elseif ((int)$os) {
    $where[] = 'pa.obra_social_id = ?';
    $params[] = (int)$os;
}
$where[] = filtro_pacientes_visibles('pa');
$verHc = puede_hc();
$pacientes = q_all(
    "SELECT pa.*, os.nombre AS obra_social,
            (SELECT MAX(t.inicio) FROM turnos t WHERE t.paciente_id = pa.id AND t.estado = 'atendido') AS ultimo,
            (SELECT MIN(t.inicio) FROM turnos t WHERE t.paciente_id = pa.id AND t.estado IN ('reservado','confirmado') AND t.inicio >= NOW()) AS proximo
       FROM pacientes pa LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY pa.apellido, pa.nombre LIMIT 400",
    $params
);

layout_header('Pacientes');
?>
<form class="filtros" method="get">
  <div class="campo" style="flex:1; max-width:420px"><label for="buscar">Buscar</label><input type="search" id="buscar" name="buscar" value="<?= e($buscar) ?>" placeholder="Apellido, nombre, DNI, teléfono o nº de afiliado" autofocus></div>
  <div class="campo"><label for="os">Cobertura</label>
    <select id="os" name="os" onchange="this.form.submit()">
      <option value="">Todas</option>
      <option value="part"<?= $os === 'part' ? ' selected' : '' ?>>Particular</option>
      <?= opciones(obras_sociales_activas(), $os) ?>
    </select></div>
  <button class="btn" type="submit">Buscar</button>
  <span class="espacio"></span>
  <?php if (puede('pacientes_editar')): ?><a class="btn btn-pri" href="<?= url('paciente_form.php') ?>">Nuevo paciente</a><?php endif; ?>
</form>
<div class="card">
<?php if (!$pacientes): ?>
  <div class="vacio"><?= $buscar !== '' || $os !== '' ? 'No hay pacientes que coincidan con la búsqueda.' : (es_profesional() ? 'Acá vas a ver los pacientes que atiendan vos o tu especialidad.' : 'Todavía no hay pacientes. Se cargan solos al dar un turno a alguien nuevo, o con «Nuevo paciente».') ?></div>
<?php else: ?>
  <div class="tabla-scroll">
  <table class="tabla">
    <thead><tr><th>Paciente</th><th>DNI</th><th>Edad</th><th>Teléfono</th><th>Cobertura</th><th>Última atención</th><th>Próximo turno</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pacientes as $c): ?>
      <tr>
        <td><a href="<?= url('paciente.php?id=' . $c['id']) ?>"><b><?= e($c['apellido']) ?></b><?= $c['apellido'] !== '' ? ', ' : '' ?><?= e($c['nombre']) ?></a>
          <?php if ($verHc && trim((string)$c['antecedentes']) !== ''): ?> <span class="t-marca m-alerta" title="Tiene antecedentes en la historia clínica">!</span><?php endif; ?></td>
        <td class="nowrap"><?= e(dni_txt($c['dni'])) ?></td>
        <td class="nowrap"><?= e(edad_txt($c['fecha_nac'])) ?></td>
        <td class="nowrap"><?= e($c['telefono']) ?></td>
        <td><span class="cob<?= $c['obra_social'] ? '' : ' cob-part' ?>"><?= e(cobertura_txt($c['obra_social'])) ?></span></td>
        <td><?= e(fecha($c['ultimo'])) ?></td>
        <td><?= $c['proximo'] ? e(fecha($c['proximo'])) . ' ' . hora($c['proximo']) : '' ?></td>
        <td class="acciones">
          <?php if ($verHc): ?><a class="btn btn-chico" href="<?= url('historia.php?id=' . $c['id']) ?>">Historia clínica</a><?php endif; ?>
          <?php if (puede('turnos')): ?><a class="btn btn-chico" href="<?= url('turno.php?paciente=' . $c['id']) ?>">Dar turno</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (count($pacientes) === 400): ?><p class="apagado mt">Se muestran los primeros 400. Usá el buscador para encontrar a alguien.</p><?php endif; ?>
<?php endif; ?>
</div>
<?php layout_footer(); ?>
