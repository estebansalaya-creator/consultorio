<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('cobrar');

$pacienteId = (int)get('paciente', 0);
$turnoSel = (int)get('turno', 0);
$paciente = $pacienteId ? q_row('SELECT pa.*, os.nombre AS obra_social FROM pacientes pa LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id WHERE pa.id = ?', [$pacienteId]) : null;
$caja = caja_abierta();
$err = '';

// Turnos del paciente que se pueden cobrar: sin cobrar, con importe, no cancelados ni ausentes, de los últimos 60 días o futuros (pago adelantado)
$turnos = $paciente ? q_all(
    "SELECT t.*, p.nombre AS profesional, os.nombre AS obra_social FROM turnos t JOIN profesionales p ON p.id = t.profesional_id
       LEFT JOIN obras_sociales os ON os.id = t.obra_social_id
      WHERE t.paciente_id = ? AND t.cobro_id IS NULL AND t.estado NOT IN ('cancelado','ausente') AND t.inicio >= ? AND (t.precio > 0 OR t.id = ?)
      ORDER BY t.inicio",
    [$pacienteId, date('Y-m-d', strtotime('-60 days')), $turnoSel]
) : [];

if (es_post()) {
    csrf_check();
    $marcados = array_map('intval', (array)post('turnos', []));
    $precios = (array)post('precio_turno', []);
    $sel = [];
    foreach ($turnos as $t) {
        if (in_array((int)$t['id'], $marcados, true)) {
            $sel[(int)$t['id']] = isset($precios[$t['id']]) ? $precios[$t['id']] : $t['precio'];
        }
    }
    $extras = [];
    $ed = (array)post('extra_desc', []);
    $ec = (array)post('extra_cant', []);
    $ep = (array)post('extra_precio', []);
    foreach ($ed as $i => $desc) {
        $extras[] = ['descripcion' => $desc, 'cantidad' => isset($ec[$i]) ? $ec[$i] : 1, 'precio' => isset($ep[$i]) ? $ep[$i] : 0];
    }
    // Descuento: monto o porcentaje ("10%")
    $descTxt = trim((string)post('descuento'));
    $descuento = num(str_replace('%', '', $descTxt));
    if (substr($descTxt, -1) === '%') {
        $sub = 0;
        foreach ($sel as $pr) {
            $sub += num($pr);
        }
        foreach ($extras as $x) {
            $sub += num($x['precio']) * max(1, (int)$x['cantidad']);
        }
        $descuento = round($sub * $descuento / 100, 2);
    }
    $pagos = [];
    $pm = (array)post('pago_medio', []);
    $pv = (array)post('pago_monto', []);
    foreach ($pm as $i => $medio) {
        $pagos[] = ['medio_pago_id' => $medio, 'monto' => isset($pv[$i]) ? $pv[$i] : 0];
    }
    try {
        $cobroId = crear_cobro([
            'paciente_id'   => $paciente ? $pacienteId : null,
            'turnos'        => $sel,
            'extras'        => $extras,
            'descuento'     => $descuento,
            'pagos'         => $pagos,
            'vuelto'        => post('vuelto'),
            'observaciones' => post('observaciones'),
        ]);
        flash('ok', 'Cobro registrado.');
        redirect(url('recibo.php?id=' . $cobroId));
    } catch (RuntimeException $ex) {
        $err = $ex->getMessage();
    }
}

$medios = medios_pago_activos();
$marcadosIni = es_post() ? array_map('intval', (array)post('turnos', [])) : array_map(function ($t) use ($turnoSel) {
    return ((int)$t['id'] === $turnoSel || in_array($t['estado'], ['presente', 'en_atencion', 'atendido'], true) || ($turnoSel === 0 && substr($t['inicio'], 0, 10) === date('Y-m-d'))) ? (int)$t['id'] : 0;
}, $turnos);

layout_header('Cobrar', ['activo' => 'caja.php']);
?>
<?php if (!$caja): ?>
  <div class="card" style="max-width:560px">
    <h2>La caja está cerrada</h2>
    <p>Para registrar cobros primero hay que abrir la caja del día.</p>
    <a class="btn btn-pri" href="<?= url('caja.php') ?>">Ir a abrir la caja</a>
  </div>
<?php else: ?>
<form method="post" id="form-cobro" class="grid-2-1" autocomplete="off">
  <?= csrf_field() ?>
  <div>
    <?php if ($err): ?><div class="aviso aviso-error"><?= e($err) ?></div><?php endif; ?>
    <div class="card">
      <div class="card-cab">
        <h2><?= $paciente ? e(nombre_paciente($paciente)) : 'Cobro sin turno' ?></h2>
        <?php if ($paciente): ?><span><span class="cob<?= $paciente['obra_social'] ? '' : ' cob-part' ?>"><?= e(cobertura_txt($paciente['obra_social'])) ?></span> <a href="<?= url('paciente.php?id=' . $pacienteId) ?>">Ver ficha</a></span><?php endif; ?>
      </div>
      <?php if (!$paciente): ?>
        <div class="campo rel" id="buscador-paciente">
          <label for="buscar-paciente">Paciente (opcional)</label>
          <input type="search" id="buscar-paciente" placeholder="Buscá para cobrarle turnos pendientes o asociarle el cobro">
          <div class="sugerencias" id="sugerencias" hidden></div>
        </div>
      <?php elseif (!$turnos): ?>
        <p class="apagado">No tiene turnos pendientes de cobro. Podés cobrarle otros conceptos abajo.
      <?php else: ?>
        <h3>Turnos</h3>
        <?php foreach ($turnos as $t):
            $marcado = in_array((int)$t['id'], $marcadosIni, true);
            $precio = es_post() && isset($_POST['precio_turno'][$t['id']]) ? $_POST['precio_turno'][$t['id']] : (string)(float)$t['precio']; ?>
          <label class="cobro-linea" style="font-weight:400">
            <input type="checkbox" name="turnos[]" value="<?= (int)$t['id'] ?>" class="js-turno"<?= $marcado ? ' checked' : '' ?>>
            <span><b><?= e($t['prestacion_nombre']) ?></b> <small><?= e($t['obra_social'] ? 'coseguro ' . $t['obra_social'] : 'particular') ?></small><br><small><?= e(ucfirst(fecha_relativa($t['inicio']))) ?> <?= hora($t['inicio']) ?> · <?= e($t['profesional']) ?> · <?= e(mb_strtolower(estado_turno_txt($t['estado']))) ?><?= strtotime($t['inicio']) > time() && !in_array($t['estado'], ['presente', 'en_atencion'], true) ? ' (pago adelantado)' : '' ?></small></span>
            <input type="text" inputmode="decimal" name="precio_turno[<?= (int)$t['id'] ?>]" value="<?= e($precio) ?>" class="js-precio" aria-label="Precio">
          </label>
        <?php endforeach; ?>
      <?php endif; ?>

      <h3 class="mt">Otros conceptos</h3>
      <p class="apagado" style="margin-bottom:6px">Certificados, insumos, aplicaciones, lo que no sea un turno.</p>
      <div id="extras">
        <?php $ed = es_post() ? (array)post('extra_desc', []) : [];
        $ec = (array)post('extra_cant', []);
        $ep = (array)post('extra_precio', []);
        foreach ($ed as $i => $desc): if (trim($desc) === '' && num(isset($ep[$i]) ? $ep[$i] : 0) == 0) continue; ?>
          <div class="cobro-linea extra">
            <input type="text" name="extra_desc[]" value="<?= e($desc) ?>" placeholder="Descripción" maxlength="150">
            <input type="number" name="extra_cant[]" value="<?= e(isset($ec[$i]) ? $ec[$i] : 1) ?>" min="1" class="js-cant" aria-label="Cantidad">
            <input type="text" inputmode="decimal" name="extra_precio[]" value="<?= e(isset($ep[$i]) ? $ep[$i] : '') ?>" class="precio js-precio" placeholder="Precio" aria-label="Precio">
            <button type="button" class="btn-quitar" aria-label="Quitar">×</button>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-chico mt" type="button" id="agregar-extra">Agregar concepto</button>
    </div>
  </div>

  <div class="card">
    <div class="tot-linea fila"><span>Subtotal</span><span class="espacio"></span><span class="num" id="subtotal">$ 0</span></div>
    <div class="campo mt">
      <label for="descuento">Descuento</label>
      <input type="text" id="descuento" name="descuento" value="<?= e(post('descuento', '')) ?>" placeholder="Monto o porcentaje, ej: 5000 o 10%">
    </div>
    <div class="apagado">Total a cobrar</div>
    <div class="total-grande" id="total">$ 0</div>

    <h3 class="mt">Pago</h3>
    <div id="pagos">
      <?php $pm = es_post() ? (array)post('pago_medio', []) : [$medios ? $medios[0]['id'] : 0];
      $pv = (array)post('pago_monto', []);
      foreach ($pm as $i => $medio): ?>
        <div class="pago-linea">
          <select name="pago_medio[]" class="js-medio" aria-label="Medio de pago">
            <?php foreach ($medios as $m): ?><option value="<?= (int)$m['id'] ?>" data-efectivo="<?= (int)$m['es_efectivo'] ?>"<?= (int)$m['id'] === (int)$medio ? ' selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?>
          </select>
          <input type="text" inputmode="decimal" name="pago_monto[]" value="<?= e(isset($pv[$i]) ? $pv[$i] : '') ?>" class="js-monto" aria-label="Monto">
          <button type="button" class="btn-quitar"<?= $i === 0 ? ' hidden' : '' ?> aria-label="Quitar">×</button>
        </div>
      <?php endforeach; ?>
    </div>
    <p><button class="link" type="button" id="dividir">Dividir en otro medio de pago</button></p>
    <p class="resto" id="resto"></p>

    <div class="campo" id="bloque-vuelto">
      <label for="paga_con">Paga con (efectivo)</label>
      <input type="text" inputmode="decimal" id="paga_con" placeholder="Para calcular el vuelto">
      <input type="hidden" name="vuelto" id="vuelto" value="0">
      <small id="vuelto-txt"></small>
    </div>
    <div class="campo"><label for="observaciones">Observaciones</label><input type="text" id="observaciones" name="observaciones" value="<?= e(post('observaciones', '')) ?>" maxlength="255"></div>
    <button class="btn btn-pri btn-grande btn-bloque" type="submit" id="btn-cobrar">Registrar cobro</button>
  </div>
</form>

<template id="tpl-extra">
  <div class="cobro-linea extra">
    <input type="text" name="extra_desc[]" placeholder="Descripción" maxlength="150">
    <input type="number" name="extra_cant[]" value="1" min="1" class="js-cant" aria-label="Cantidad">
    <input type="text" inputmode="decimal" name="extra_precio[]" class="precio js-precio" placeholder="Precio" aria-label="Precio">
    <button type="button" class="btn-quitar" aria-label="Quitar">×</button>
  </div>
</template>
<?php endif; ?>
<?php layout_footer($caja ? ['assets/cobro.js'] : []); ?>
