<?php
/*
 * Órdenes de laboratorio y estudios: las del profesional (o de todos sus pacientes), con búsqueda.
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('recetas');
if (!puede_hc()) {
    sin_permiso('Las órdenes las ven y las emiten sólo los profesionales.');
}

$ver = get('ver') === 'todas' ? 'todas' : 'mias';
$estado = isset(ESTADOS_RECETA[get('estado')]) ? get('estado') : '';
$tipo = isset(TIPOS_ORDEN[get('tipo')]) ? get('tipo') : '';
$buscar = trim((string)get('buscar', ''));
$where = [];
$params = [];
if ($ver === 'mias') {
    $where[] = 'r.profesional_id = ?';
    $params[] = mi_profesional_id();
} else {
    $where[] = filtro_pacientes_visibles('pa');
}
if ($estado !== '') {
    $where[] = 'r.estado = ?';
    $params[] = $estado;
}
if ($tipo !== '') {
    $where[] = 'r.tipo = ?';
    $params[] = $tipo;
}
if ($buscar !== '') {
    foreach (preg_split('/\s+/', $buscar) as $p) {
        $num = preg_replace('/\D/', '', $p);
        $where[] = '(pa.nombre LIKE ? OR pa.apellido LIKE ? OR r.diagnostico LIKE ? OR EXISTS (SELECT 1 FROM orden_items i WHERE i.orden_id = r.id AND (i.descripcion LIKE ? OR i.codigo LIKE ?))'
            . ($num !== '' ? ' OR pa.dni LIKE ? OR r.numero = ?' : '') . ')';
        array_push($params, "%$p%", "%$p%", "%$p%", "%$p%", "%$p%");
        if ($num !== '') {
            array_push($params, "%$num%", (int)$num);
        }
    }
}
$ordenes = q_all(
    'SELECT r.*, pa.nombre, pa.apellido, pa.dni, p.nombre AS profesional,
            (SELECT GROUP_CONCAT(i.descripcion ORDER BY i.orden SEPARATOR "|") FROM orden_items i WHERE i.orden_id = r.id) AS practicas
       FROM ordenes r JOIN pacientes pa ON pa.id = r.paciente_id JOIN profesionales p ON p.id = r.profesional_id
      WHERE ' . implode(' AND ', $where) . ' ORDER BY r.creado DESC LIMIT 200',
    $params
);
$firmaLista = firma_lista(mi_profesional_id());

layout_header('Órdenes', ['subtitulo' => 'Laboratorio y estudios', 'acciones' => '<a class="btn" href="' . url('nomencladores.php') . '">Nomencladores</a> <a class="btn" href="' . url('firma.php') . '">Mi firma</a>']);
?>
<?php if (!$firmaLista): ?>
  <div class="aviso aviso-alerta">Todavía no cargaste tu firma y tu PIN: <a href="<?= url('firma.php') ?>">cargalos en Mi firma</a> para poder firmar órdenes.</div>
<?php endif; ?>
<div class="grid-2-1">
  <div>
    <form class="filtros" method="get">
      <div class="campo" style="flex:1; min-width:200px"><label for="buscar">Buscar</label><input type="search" id="buscar" name="buscar" value="<?= e($buscar) ?>" placeholder="Paciente, DNI, práctica, código o N.º"></div>
      <div class="campo"><label for="ver">De</label><select id="ver" name="ver" onchange="this.form.submit()"><option value="mias">Mis órdenes</option><option value="todas"<?= $ver === 'todas' ? ' selected' : '' ?>>Todos mis pacientes</option></select></div>
      <div class="campo"><label for="estado">Estado</label><select id="estado" name="estado" onchange="this.form.submit()"><option value="">Todos</option>
        <?php foreach (ESTADOS_RECETA as $k => $txt): ?><option value="<?= $k ?>"<?= $estado === $k ? ' selected' : '' ?>><?= e($txt) ?></option><?php endforeach; ?></select></div>
      <div class="campo"><label for="tipo">Tipo</label><select id="tipo" name="tipo" onchange="this.form.submit()"><option value="">Todas</option>
        <?php foreach (TIPOS_ORDEN as $k => $txt): ?><option value="<?= $k ?>"<?= $tipo === $k ? ' selected' : '' ?>><?= e($txt) ?></option><?php endforeach; ?></select></div>
      <button class="btn" type="submit">Buscar</button>
    </form>
    <div class="card">
      <?php if (!$ordenes): ?>
        <div class="vacio"><?= $buscar !== '' || $estado !== '' ? 'No hay órdenes que coincidan.' : 'Todavía no hiciste órdenes. Se hacen desde el turno, la ficha del paciente o con «Nueva orden».' ?></div>
      <?php else: ?>
      <div class="tabla-scroll">
      <table class="tabla">
        <thead><tr><th>Fecha</th><th>Paciente</th><th>Prácticas</th><th>Diagnóstico</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ordenes as $x): ?>
          <tr class="<?= $x['estado'] === 'anulada' ? 'anulado' : '' ?>">
            <td class="nowrap"><a href="<?= url('orden.php?id=' . $x['id']) ?>"><?= e(fecha($x['fecha'])) ?></a><br><small><?= e(TIPOS_ORDEN[$x['tipo']]) ?> · <?= $x['numero'] ? 'N.º ' . sprintf('%06d', $x['numero']) : 'sin número' ?></small></td>
            <td><?= e(apellido_nombre($x)) ?><br><small><?= $x['dni'] ? 'DNI ' . e(dni_txt($x['dni'])) : '' ?><?= $ver === 'todas' ? ' · ' . e($x['profesional']) : '' ?></small></td>
            <td><small><?= e(orden_resumen($x['practicas'])) ?></small><?= $x['urgente'] ? ' <span class="orden-urgente">URGENTE</span>' : '' ?></td>
            <td><small><?= $x['cie10_codigo'] ? '<span class="cie">' . e($x['cie10_codigo']) . '</span> ' : '' ?><?= e(mb_strimwidth((string)$x['diagnostico'], 0, 60, '…')) ?></small></td>
            <td class="der"><?= badge_receta($x['estado']) ?><?= $x['cuir'] ? '<br><small>CUIR</small>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div>
    <div class="card">
      <h2>Nueva orden</h2>
      <div class="fila" style="margin-bottom:10px">
        <label class="check"><input type="radio" name="nuevo-tipo" value="laboratorio" checked> Laboratorio</label>
        <label class="check"><input type="radio" name="nuevo-tipo" value="estudios"> Estudios</label>
      </div>
      <div class="campo"><label for="buscar-paciente">Paciente</label><input type="search" id="buscar-paciente" placeholder="Apellido, nombre o DNI"></div>
      <p class="apagado"><small>También desde el turno o desde la ficha del paciente.</small></p>
    </div>
    <div class="card">
      <h2>Verificar una orden</h2>
      <p class="apagado"><small>Con el código impreso en la orden se comprueba que es auténtica y que no fue modificada.</small></p>
      <form method="get" action="<?= url('verificar.php') ?>" class="fila">
        <input type="text" name="c" placeholder="XXXXX-XXXXX" maxlength="11" style="flex:1" aria-label="Código de verificación">
        <button class="btn" type="submit">Verificar</button>
      </form>
    </div>
  </div>
</div>
<script>
App.buscador(document.getElementById('buscar-paciente'), {
  url: function (q) { return 'api/pacientes.php?q=' + encodeURIComponent(q); },
  lista: 'pacientes', min: 2, vacio: 'No se encontraron pacientes (sólo los que atienden vos o tu especialidad)',
  item: function (p) { return '<b>' + App.esc(p.nombre) + '</b><small>' + App.esc([p.dni ? 'DNI ' + p.dni : '', p.edad, p.obra_social].filter(Boolean).join(' · ')) + '</small>'; },
  elegir: function (p) { location.href = App.base + 'orden.php?paciente=' + p.id + '&tipo=' + document.querySelector('input[name=nuevo-tipo]:checked').value; }
});
</script>
<?php layout_footer(); ?>
