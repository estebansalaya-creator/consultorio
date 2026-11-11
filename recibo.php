<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('cobrar');

$id = (int)get('id', 0);
$c = q_row(
    'SELECT co.*, pa.nombre, pa.apellido, pa.dni, pa.nro_afiliado, os.nombre AS obra_social, u.nombre AS usuario FROM cobros co
       LEFT JOIN pacientes pa ON pa.id = co.paciente_id LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id
       LEFT JOIN usuarios u ON u.id = co.usuario_id WHERE co.id = ?',
    [$id]
);
if (!$c) {
    flash('error', 'El cobro no existe.');
    redirect(url('caja.php'));
}

if (es_post() && post('accion') === 'anular') {
    csrf_check();
    if (!es_admin()) {
        sin_permiso('Sólo un administrador puede anular cobros.');
    }
    try {
        anular_cobro($id, (string)post('motivo'));
        flash('ok', 'Cobro anulado. Los turnos volvieron a quedar sin cobrar.');
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect(url('recibo.php?id=' . $id));
}

$items = q_all('SELECT * FROM cobro_items WHERE cobro_id = ? ORDER BY id', [$id]);
$pagos = q_all('SELECT p.*, m.nombre FROM cobro_pagos p JOIN medios_pago m ON m.id = p.medio_pago_id WHERE p.cobro_id = ? ORDER BY p.id', [$id]);
$caja = caja_abierta();
$puedeAnular = es_admin() && $c['estado'] === 'valido' && $caja && (int)$caja['id'] === (int)$c['caja_id'];

layout_header('Recibo ' . sprintf('%06d', $c['numero']), ['activo' => 'caja.php']);
?>
<?php if ($c['estado'] === 'anulado'): ?>
  <div class="aviso aviso-error no-print">Cobro anulado<?= $c['motivo_anulacion'] ? ': ' . e($c['motivo_anulacion']) : '' ?>.</div>
<?php endif; ?>
<div class="fila no-print" style="margin-bottom:16px">
  <button class="btn btn-pri" type="button" onclick="window.print()">Imprimir</button>
  <?php if ($c['paciente_id']): ?><a class="btn" href="<?= url('paciente.php?id=' . $c['paciente_id']) ?>">Ver ficha</a><?php endif; ?>
  <a class="btn" href="<?= url('caja.php') ?>">Ir a caja</a>
  <a class="btn" href="<?= url('agenda.php') ?>">Ir a la agenda</a>
  <span class="espacio"></span>
  <?php if ($puedeAnular): ?><button class="btn btn-peligro" type="button" data-abrir="modal-anular">Anular cobro</button><?php endif; ?>
</div>

<div class="recibo">
  <h1><?= e(config_get('nombre_centro')) ?></h1>
  <div class="centro-txt">
    <?= e(config_get('direccion')) ?><?= config_get('telefono') ? '<br>Tel. ' . e(config_get('telefono')) : '' ?>
  </div>
  <table>
    <tr><td>Recibo N.º <?= sprintf('%06d', $c['numero']) ?></td><td class="der"><?= e(fecha_hora($c['fecha'])) ?></td></tr>
    <?php if ($c['paciente_id']): ?><tr><td colspan="2"><?= e(nombre_paciente($c)) ?><?= $c['dni'] ? ' · DNI ' . e(dni_txt($c['dni'])) : '' ?></td></tr><?php endif; ?>
  </table>
  <div class="linea"></div>
  <table>
    <?php foreach ($items as $i): ?>
      <tr><td><?= $i['cantidad'] > 1 ? (int)$i['cantidad'] . ' × ' : '' ?><?= e($i['descripcion']) ?></td><td class="der nowrap"><?= money($i['subtotal']) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($c['descuento'] > 0): ?>
      <tr><td>Subtotal</td><td class="der"><?= money($c['subtotal']) ?></td></tr>
      <tr><td>Descuento</td><td class="der">− <?= money($c['descuento']) ?></td></tr>
    <?php endif; ?>
  </table>
  <div class="linea"></div>
  <table>
    <tr class="total"><td>Total</td><td class="der"><?= money($c['total']) ?></td></tr>
    <?php foreach ($pagos as $p): ?><tr><td><?= e($p['nombre']) ?></td><td class="der"><?= money($p['monto']) ?></td></tr><?php endforeach; ?>
    <?php if ($c['vuelto'] > 0): ?><tr><td>Vuelto</td><td class="der"><?= money($c['vuelto']) ?></td></tr><?php endif; ?>
  </table>
  <?php if ($c['observaciones']): ?><div class="linea"></div><p><?= e($c['observaciones']) ?></p><?php endif; ?>
  <div class="linea"></div>
  <p class="centro-txt" style="margin:0"><?= e(config_get('pie_recibo')) ?><br><small>Comprobante no válido como factura.</small></p>
</div>

<?php if ($puedeAnular): ?>
<div class="modal-fondo" id="modal-anular">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="accion" value="anular">
    <h2>Anular cobro</h2>
    <p>Se descuenta de la caja y los turnos vuelven a quedar sin cobrar.</p>
    <div class="campo"><label for="motivo">Motivo</label><input type="text" id="motivo" name="motivo" required maxlength="255" placeholder="Ej: se cargó con el medio de pago equivocado"></div>
    <div class="fila"><button class="btn btn-peligro" type="submit">Anular cobro</button><button class="btn" type="button" data-cerrar>Volver</button></div>
  </form>
</div>
<?php endif; ?>
<?php layout_footer(); ?>
