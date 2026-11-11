<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('caja');

$abierta = caja_abierta();

if (es_post()) {
    csrf_check();
    $accion = post('accion');
    try {
        if ($accion === 'abrir') {
            if ($abierta) {
                throw new RuntimeException('Ya hay una caja abierta.');
            }
            q("INSERT INTO cajas (fecha_apertura, usuario_apertura_id, monto_inicial, estado) VALUES (NOW(), ?, ?, 'abierta')", [usuario_id(), max(0, num(post('monto_inicial')))]);
            flash('ok', 'Caja abierta.');
            redirect(url('caja.php'));
        }
        if (!$abierta) {
            throw new RuntimeException('No hay caja abierta.');
        }
        if ($accion === 'movimiento') {
            $tipo = post('tipo') === 'ingreso' ? 'ingreso' : 'egreso';
            $monto = num(post('monto'));
            $concepto = trim((string)post('concepto'));
            if ($monto <= 0 || $concepto === '') {
                throw new RuntimeException('Completá el monto y el concepto.');
            }
            q(
                'INSERT INTO caja_movimientos (caja_id, fecha, tipo, medio_pago_id, monto, concepto, usuario_id) VALUES (?, NOW(), ?, ?, ?, ?, ?)',
                [$abierta['id'], $tipo, (int)post('medio_pago_id'), $monto, $concepto, usuario_id()]
            );
            flash('ok', ($tipo === 'ingreso' ? 'Ingreso' : 'Egreso') . ' registrado.');
        }
        if ($accion === 'cerrar') {
            if (trim((string)post('efectivo_contado')) === '') {
                throw new RuntimeException('Contá el efectivo y anotalo para cerrar.');
            }
            $res = caja_resumen($abierta['id']);
            q(
                "UPDATE cajas SET estado = 'cerrada', fecha_cierre = NOW(), usuario_cierre_id = ?, efectivo_esperado = ?, efectivo_contado = ?, observaciones = ? WHERE id = ?",
                [usuario_id(), $res['efectivo_esperado'], num(post('efectivo_contado')), trim((string)post('observaciones')) ?: null, $abierta['id']]
            );
            flash('ok', 'Caja cerrada.');
            redirect(url('caja.php?id=' . $abierta['id']));
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect(url('caja.php'));
}

$verId = (int)get('id', 0);
$caja = $verId ? q_row('SELECT * FROM cajas WHERE id = ?', [$verId]) : $abierta;
$res = $caja ? caja_resumen($caja['id']) : null;
$medios = medios_pago_activos();
$sinCobrar = turnos_sin_cobrar();

$titulo = $verId && $caja ? 'Caja del ' . fecha($caja['fecha_apertura']) : 'Caja';
layout_header($titulo, [
    'activo'   => 'caja.php',
    'acciones' => $abierta && !$verId ? '<a class="btn btn-pri" href="' . url('cobro.php') . '">Cobro sin turno</a>' : '',
]);

/** Bloque de resumen de una caja */
function mostrar_resumen(array $res)
{
    $c = $res['caja']; ?>
  <div class="cifras">
    <div class="cifra"><span class="valor"><?= money($res['total_cobros']) ?></span><span class="etiqueta">Cobrado</span></div>
    <div class="cifra"><span class="valor"><?= money($c['monto_inicial']) ?></span><span class="etiqueta">Efectivo inicial</span></div>
    <div class="cifra"><span class="valor"><?= money($res['efectivo_esperado']) ?></span><span class="etiqueta">Efectivo que debería haber</span></div>
    <?php if ($c['estado'] === 'cerrada'):
        $dif = $c['efectivo_contado'] - $c['efectivo_esperado']; ?>
    <div class="cifra"><span class="valor" style="color:<?= abs($dif) < 0.01 ? 'var(--aloe)' : 'var(--coral)' ?>"><?= ($dif > 0 ? '+' : '') . money($dif) ?></span><span class="etiqueta">Diferencia al cerrar (contado <?= money($c['efectivo_contado']) ?>)</span></div>
    <?php endif; ?>
  </div>
  <div class="grid-2">
    <div class="card">
      <h2>Por medio de pago</h2>
      <table class="tabla">
        <thead><tr><th>Medio</th><th class="der">Cobros</th><th class="der">Ingresos</th><th class="der">Egresos</th><th class="der">Neto</th></tr></thead>
        <tbody>
        <?php $hay = false; foreach ($res['medios'] as $m):
            if (!$m['cobros'] && !$m['ingresos'] && !$m['egresos']) continue;
            $hay = true; ?>
          <tr>
            <td><?= e($m['nombre']) ?> <small>(<?= $m['cant'] ?>)</small></td>
            <td class="der num"><?= money($m['cobros']) ?></td>
            <td class="der num"><?= $m['ingresos'] ? money($m['ingresos']) : '' ?></td>
            <td class="der num"><?= $m['egresos'] ? '− ' . money($m['egresos']) : '' ?></td>
            <td class="der num"><?= money($m['cobros'] + $m['ingresos'] - $m['egresos']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$hay): ?><tr><td colspan="5" class="vacio">Todavía no hay cobros en esta caja.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card">
      <h2>Ingresos y egresos</h2>
      <?php if (!$res['movimientos']): ?>
        <p class="apagado">Sin movimientos manuales. Acá se registran compras de insumos, retiros o cambio agregado.</p>
      <?php else: ?>
      <table class="tabla">
        <?php foreach ($res['movimientos'] as $m): ?>
          <tr>
            <td class="nowrap"><?= e(hora($m['fecha'])) ?></td>
            <td><?= e($m['concepto']) ?><br><small><?= e($m['medio']) ?>, <?= e($m['usuario']) ?></small></td>
            <td class="der num" style="color:<?= $m['tipo'] === 'ingreso' ? 'var(--aloe)' : 'var(--coral)' ?>"><?= $m['tipo'] === 'ingreso' ? '+' : '−' ?> <?= money($m['monto']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
      <?php if ($c['observaciones']): ?><p class="mt"><b>Observaciones del cierre:</b> <?= e($c['observaciones']) ?></p><?php endif; ?>
    </div>
  </div>
  <div class="card">
    <h2>Cobros</h2>
    <?php if (!$res['cobros']): ?>
      <p class="apagado">Todavía no hay cobros.</p>
    <?php else: ?>
    <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr><th>Hora</th><th>Recibo</th><th>Paciente</th><th>Detalle</th><th>Medio</th><th class="der">Total</th></tr></thead>
      <tbody>
      <?php foreach ($res['cobros'] as $x): ?>
        <tr class="<?= $x['estado'] === 'anulado' ? 'anulado' : '' ?>">
          <td class="nowrap"><?= hora($x['fecha']) ?></td>
          <td><a href="<?= url('recibo.php?id=' . $x['id']) ?>"><?= sprintf('%06d', $x['numero']) ?></a></td>
          <td><?= $x['paciente_id'] ? e(nombre_paciente($x)) : '<span class="apagado">Sin paciente</span>' ?></td>
          <td><small><?= e($x['detalle']) ?></small></td>
          <td><small><?= e($x['medios']) ?></small></td>
          <td class="der num"><?= money($x['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
<?php
}

if ($verId && $caja && $caja['estado'] === 'cerrada'):
    $ua = q_val('SELECT nombre FROM usuarios WHERE id = ?', [$caja['usuario_apertura_id']]);
    $uc = q_val('SELECT nombre FROM usuarios WHERE id = ?', [$caja['usuario_cierre_id']]); ?>
  <p class="apagado">Abierta el <?= e(fecha_hora($caja['fecha_apertura'])) ?> por <?= e($ua) ?>. Cerrada el <?= e(fecha_hora($caja['fecha_cierre'])) ?> por <?= e($uc) ?>.
    <a href="<?= url('caja.php') ?>">Volver a caja</a></p>
  <?php mostrar_resumen($res); ?>

<?php elseif (!$abierta): ?>
  <div class="card" style="max-width:520px">
    <h2>Abrir caja</h2>
    <p>Contá el efectivo que hay en la caja para empezar el día.</p>
    <?php $ult = q_row("SELECT efectivo_contado FROM cajas WHERE estado = 'cerrada' ORDER BY id DESC LIMIT 1"); ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="accion" value="abrir">
      <div class="campo">
        <label for="monto_inicial">Efectivo inicial $</label>
        <input type="text" inputmode="decimal" id="monto_inicial" name="monto_inicial" value="<?= $ult ? e((float)$ult['efectivo_contado']) : '0' ?>" autofocus>
        <?php if ($ult): ?><small>Sugerido: lo contado al cerrar la caja anterior.</small><?php endif; ?>
      </div>
      <button class="btn btn-pri btn-grande" type="submit">Abrir caja</button>
    </form>
  </div>

<?php else: ?>
  <p class="apagado">Abierta el <?= e(fecha_hora($abierta['fecha_apertura'])) ?> por <?= e(q_val('SELECT nombre FROM usuarios WHERE id = ?', [$abierta['usuario_apertura_id']])) ?>.</p>
  <?php if ($sinCobrar): ?>
  <div class="card">
    <div class="card-cab"><h2>Pendientes de cobro</h2><span class="apagado"><?= money(array_sum(array_column($sinCobrar, 'precio'))) ?></span></div>
    <table class="tabla">
      <?php foreach ($sinCobrar as $t): ?>
        <tr>
          <td class="nowrap"><?= e(ucfirst(fecha_relativa($t['inicio']))) ?> <?= hora($t['inicio']) ?></td>
          <td><a href="<?= url('paciente.php?id=' . $t['paciente_id']) ?>"><?= e(nombre_paciente($t)) ?></a><br><small><?= e($t['prestacion_nombre']) ?> · <?= e($t['profesional']) ?> · <?= e($t['obra_social'] ? 'coseguro ' . $t['obra_social'] : 'particular') ?></small></td>
          <td class="der num"><?= money($t['precio']) ?></td>
          <td class="acciones"><a class="btn btn-chico btn-pri" href="<?= url('cobro.php?paciente=' . $t['paciente_id'] . '&turno=' . $t['id']) ?>">Cobrar</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
  <?php mostrar_resumen($res); ?>

  <div class="grid-2">
    <div class="card">
      <h2>Registrar ingreso o egreso</h2>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="movimiento">
        <div class="campo">
          <label class="check"><input type="radio" name="tipo" value="egreso" checked> Egreso (sale plata)</label>
          <label class="check"><input type="radio" name="tipo" value="ingreso"> Ingreso (entra plata)</label>
        </div>
        <div class="form-fila">
          <div class="campo"><label for="monto">Monto $</label><input type="text" inputmode="decimal" id="monto" name="monto" required></div>
          <div class="campo"><label for="mp">Medio</label><select id="mp" name="medio_pago_id"><?= opciones($medios) ?></select></div>
        </div>
        <div class="campo"><label for="concepto">Concepto</label><input type="text" id="concepto" name="concepto" placeholder="Ej: compra de guantes y descartables" required maxlength="200"></div>
        <button class="btn" type="submit">Registrar</button>
      </form>
    </div>
    <div class="card">
      <h2>Cerrar caja</h2>
      <?php if ($sinCobrar): ?>
        <div class="aviso aviso-alerta"><?= count($sinCobrar) === 1 ? 'Hay 1 turno pendiente de cobro' : 'Hay ' . count($sinCobrar) . ' turnos pendientes de cobro' ?>. Si cerrás igual, se pueden cobrar en la próxima caja.</div>
      <?php endif; ?>
      <form method="post" data-confirm="¿Cerrar la caja? Después no se pueden anular los cobros de este día.">
        <?= csrf_field() ?><input type="hidden" name="accion" value="cerrar">
        <div class="campo">
          <label for="efectivo_contado">Efectivo contado $</label>
          <input type="text" inputmode="decimal" id="efectivo_contado" name="efectivo_contado" required>
          <small>Debería haber <?= money($res['efectivo_esperado']) ?>.</small>
        </div>
        <div class="campo"><label for="obs">Observaciones</label><textarea id="obs" name="observaciones"></textarea></div>
        <button class="btn btn-peligro" type="submit">Cerrar caja</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php
$historial = q_all(
    "SELECT c.*,
            (SELECT COALESCE(SUM(total), 0) FROM cobros x WHERE x.caja_id = c.id AND x.estado = 'valido') AS cobrado
       FROM cajas c WHERE c.estado = 'cerrada' ORDER BY c.id DESC LIMIT 30"
);
if ($historial): ?>
<div class="card">
  <h2>Cajas anteriores</h2>
  <div class="tabla-scroll">
  <table class="tabla">
    <thead><tr><th>Día</th><th>Apertura</th><th>Cierre</th><th class="der">Cobrado</th><th class="der">Efectivo esperado</th><th class="der">Contado</th><th class="der">Diferencia</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($historial as $h): $d = $h['efectivo_contado'] - $h['efectivo_esperado']; ?>
      <tr>
        <td><?= e(fecha($h['fecha_apertura'])) ?></td>
        <td><?= e(hora($h['fecha_apertura'])) ?></td>
        <td><?= e(fecha($h['fecha_cierre']) !== fecha($h['fecha_apertura']) ? fecha_hora($h['fecha_cierre']) : hora($h['fecha_cierre'])) ?></td>
        <td class="der num"><?= money($h['cobrado']) ?></td>
        <td class="der num"><?= money($h['efectivo_esperado']) ?></td>
        <td class="der num"><?= money($h['efectivo_contado']) ?></td>
        <td class="der num" style="color:<?= abs($d) < 0.01 ? 'inherit' : 'var(--coral)' ?>"><?= ($d > 0 ? '+' : '') . money($d) ?></td>
        <td class="acciones"><a class="btn btn-chico" href="<?= url('caja.php?id=' . $h['id']) ?>">Ver</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<?php layout_footer(); ?>
