<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('inicio');

$hoy = date('Y-m-d');
$manana = date('Y-m-d', strtotime('+1 day'));
$mio = es_profesional() ? mi_profesional_id() : null;

$hoyTurnos = q_all(
    "SELECT t.*, pa.nombre, pa.apellido, pa.telefono, pa.fecha_nac, (pa.antecedentes IS NOT NULL AND pa.antecedentes <> '') AS con_antecedentes,
            p.nombre AS profesional, p.color, c.nombre AS consultorio, os.nombre AS obra_social
       FROM turnos t JOIN pacientes pa ON pa.id = t.paciente_id JOIN profesionales p ON p.id = t.profesional_id
       LEFT JOIN consultorios c ON c.id = t.consultorio_id LEFT JOIN obras_sociales os ON os.id = t.obra_social_id
      WHERE t.inicio BETWEEN ? AND ? AND t.estado <> 'cancelado'" . ($mio ? ' AND t.profesional_id = ' . (int)$mio : '') . '
      ORDER BY t.inicio, p.orden',
    [$hoy . ' 00:00:00', $hoy . ' 23:59:59']
);
$activos = array_filter($hoyTurnos, function ($t) { return $t['estado'] !== 'ausente'; });
$enEspera = array_values(array_filter($hoyTurnos, function ($t) { return $t['estado'] === 'presente'; }));
usort($enEspera, function ($a, $b) { return strcmp((string)$a['llegada'], (string)$b['llegada']); });
$enAtencion = array_filter($hoyTurnos, function ($t) { return $t['estado'] === 'en_atencion'; });
$atendidos = array_filter($hoyTurnos, function ($t) { return $t['estado'] === 'atendido'; });
$porLlegar = array_filter($hoyTurnos, function ($t) { return in_array($t['estado'], ['reservado', 'confirmado'], true); });

$caja = puede('caja') ? caja_abierta() : null;
$res = $caja ? caja_resumen($caja['id']) : null;
$sinCobrar = puede('cobrar') ? turnos_sin_cobrar() : [];

$recordar = puede('turnos') ? q_all(
    "SELECT t.*, pa.nombre, pa.apellido, pa.telefono, p.nombre AS profesional, p.color
       FROM turnos t JOIN pacientes pa ON pa.id = t.paciente_id JOIN profesionales p ON p.id = t.profesional_id
      WHERE t.inicio BETWEEN ? AND ? AND t.estado IN ('reservado','confirmado')
      ORDER BY t.inicio",
    [$manana . ' 00:00:00', $manana . ' 23:59:59']
) : [];

$proximosMios = $mio ? q_all(
    "SELECT t.*, pa.nombre, pa.apellido FROM turnos t JOIN pacientes pa ON pa.id = t.paciente_id
      WHERE t.profesional_id = ? AND t.inicio >= ? AND t.inicio < ? AND t.estado IN ('reservado','confirmado')
      ORDER BY t.inicio LIMIT 30",
    [$mio, $manana . ' 00:00:00', date('Y-m-d', strtotime('+8 days')) . ' 00:00:00']
) : [];
$verHc = puede_hc();

$saludo = (int)date('G') < 13 ? 'Buen día' : ((int)date('G') < 20 ? 'Buenas tardes' : 'Buenas noches');
$nombreSaludo = $mio ? nombre_corto_profesional(usuario()['nombre']) : explode(' ', usuario()['nombre'])[0];
layout_header($saludo . ', ' . $nombreSaludo, ['clase' => 'pagina-inicio', 'subtitulo' => ucfirst(fecha_larga($hoy))]);

/** Botón chico que cambia el estado de un turno y vuelve al inicio */
function boton_estado(array $t, $estado, $texto, $clase = 'btn btn-chico')
{
    return '<form method="post" action="' . url('turno.php?id=' . $t['id']) . '" class="inline">' . csrf_field()
        . '<input type="hidden" name="accion" value="estado"><input type="hidden" name="estado" value="' . e($estado) . '">'
        . '<input type="hidden" name="volver" value="' . ($estado === 'en_atencion' && es_profesional() ? 'turno' : 'inicio') . '">'
        . '<button class="' . e($clase) . '" type="submit">' . e($texto) . '</button></form>';
}
?>
<div class="cifras">
  <div class="cifra"><span class="valor"><?= count($activos) ?></span><span class="etiqueta"><?= $mio ? 'Tus turnos de hoy' : 'Turnos hoy' ?></span></div>
  <div class="cifra"><span class="valor"><?= count($enEspera) ?></span><span class="etiqueta">En sala de espera</span></div>
  <div class="cifra"><span class="valor"><?= count($porLlegar) ?></span><span class="etiqueta">Por llegar</span></div>
  <div class="cifra"><span class="valor"><?= count($atendidos) ?></span><span class="etiqueta">Atendidos</span></div>
  <?php if (puede('caja')): ?>
    <div class="cifra"><span class="valor"><?= $caja ? money($res['total_cobros']) : '—' ?></span><span class="etiqueta"><?= $caja ? 'Cobrado en la caja abierta' : 'La caja está cerrada' ?></span></div>
  <?php endif; ?>
</div>

<div class="grid-2-1">
  <div>
    <?php if ($mio): ?>
    <div class="card">
      <div class="card-cab"><h2>Sala de espera</h2><span class="apagado"><?= plural(count($enEspera), 'paciente', 'pacientes') ?></span></div>
      <?php foreach ($enAtencion as $t): ?>
        <div class="aviso aviso-info">Estás atendiendo a <a href="<?= url('turno.php?id=' . $t['id']) ?>"><b><?= e(nombre_paciente($t)) ?></b></a> (turno de las <?= hora($t['inicio']) ?>).</div>
      <?php endforeach; ?>
      <?php if (!$enEspera): ?>
        <p class="apagado">No hay pacientes esperando. Cuando recepción marque que llegaron, aparecen acá.</p>
      <?php else: ?>
      <div class="tabla-scroll">
      <table class="tabla">
        <?php foreach ($enEspera as $t): $min = $t['llegada'] ? (time() - strtotime($t['llegada'])) / 60 : 0; ?>
          <tr class="en-espera">
            <td class="hora"><?= hora($t['inicio']) ?></td>
            <td><a href="<?= url('turno.php?id=' . $t['id']) ?>"><b><?= e(nombre_paciente($t)) ?></b></a>
              <?php if ($t['con_antecedentes']): ?> <span class="t-marca m-alerta" title="Tiene antecedentes en la historia clínica">!</span><?php endif; ?>
              <br><small><?= e($t['prestacion_nombre']) ?><?= $t['fecha_nac'] ? ' · ' . e(edad_txt($t['fecha_nac'])) : '' ?> · <?= e(cobertura_txt($t['obra_social'])) ?></small></td>
            <td class="der"><span class="espera<?= $min > 30 ? ' larga' : '' ?>" title="Llegó a las <?= e(hora($t['llegada'])) ?>">espera <?= e(espera_txt($t['llegada'])) ?></span></td>
            <td class="acciones"><?= boton_estado($t, 'en_atencion', 'Atender', 'btn btn-chico btn-pri') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-cab">
        <h2><?= $mio ? 'Tus turnos de hoy' : 'Turnos de hoy' ?></h2>
        <a href="<?= url('agenda.php') ?>">Ver agenda</a>
      </div>
      <?php if (!$hoyTurnos): ?>
        <div class="vacio">No hay turnos para hoy.<?php if (puede('turnos')): ?><br><a class="btn btn-pri mt" href="<?= url('turno.php') ?>">Dar un turno</a><?php endif; ?></div>
      <?php else: ?>
      <div class="tabla-scroll">
      <table class="tabla">
        <tbody>
        <?php foreach ($hoyTurnos as $t): ?>
          <tr class="<?= in_array($t['estado'], ['atendido', 'ausente'], true) ? 'inactivo' : ($t['estado'] === 'presente' ? 'en-espera' : '') ?>">
            <td class="hora"><?= hora($t['inicio']) ?></td>
            <td><a href="<?= url('turno.php?id=' . $t['id']) ?>"><b><?= e(nombre_paciente($t)) ?></b></a>
              <?php if ($verHc && $t['con_antecedentes']): ?> <span class="t-marca m-alerta" title="Tiene antecedentes en la historia clínica">!</span><?php endif; ?>
              <?php if ($t['sobreturno']): ?> <span class="t-marca m-st" title="Sobreturno">ST</span><?php endif; ?>
              <br><small><?= e($t['prestacion_nombre']) ?><?php if (!$mio): ?> · <span class="punto" style="--c: <?= e($t['color']) ?>"></span><?= e($t['profesional']) ?><?php endif; ?><?= $t['consultorio'] ? ' · ' . e($t['consultorio']) : '' ?></small></td>
            <td><span class="cob<?= $t['obra_social'] ? '' : ' cob-part' ?>"><?= e(cobertura_txt($t['obra_social'])) ?></span></td>
            <td class="der"><?= badge_turno($t['estado']) ?>
              <?php if ($t['estado'] === 'presente'): ?><br><small class="espera">espera <?= e(espera_txt($t['llegada'])) ?></small><?php endif; ?></td>
            <td class="acciones">
              <?php if (!$mio && in_array($t['estado'], ['reservado', 'confirmado'], true) && puede('atender')): ?>
                <?= boton_estado($t, 'presente', 'Llegó') ?>
              <?php elseif ($mio && $t['estado'] === 'presente'): ?>
                <?= boton_estado($t, 'en_atencion', 'Atender', 'btn btn-chico btn-pri') ?>
              <?php endif; ?>
              <?php if (!$t['cobro_id'] && $t['precio'] > 0 && in_array($t['estado'], ['presente', 'en_atencion', 'atendido'], true) && puede('cobrar')): ?>
                <a class="btn btn-chico" href="<?= url('cobro.php?paciente=' . $t['paciente_id'] . '&turno=' . $t['id']) ?>">Cobrar</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <?php if (!$mio && $enEspera): ?>
    <div class="card">
      <div class="card-cab"><h2>Sala de espera</h2><span class="apagado"><?= plural(count($enEspera), 'paciente', 'pacientes') ?></span></div>
      <table class="tabla">
        <?php foreach ($enEspera as $t): $min = $t['llegada'] ? (time() - strtotime($t['llegada'])) / 60 : 0; ?>
          <tr>
            <td><a href="<?= url('turno.php?id=' . $t['id']) ?>"><?= e(nombre_paciente($t)) ?></a><br><small><?= hora($t['inicio']) ?> · <?= e($t['profesional']) ?></small></td>
            <td class="der"><span class="espera<?= $min > 30 ? ' larga' : '' ?>"><?= e(espera_txt($t['llegada'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($sinCobrar): ?>
    <div class="card">
      <div class="card-cab"><h2>Pendientes de cobro</h2><span class="apagado"><?= money(array_sum(array_column($sinCobrar, 'precio'))) ?></span></div>
      <table class="tabla">
        <?php foreach (array_slice($sinCobrar, 0, 8) as $t): ?>
          <tr>
            <td><?= e(nombre_paciente($t)) ?><br><small><?= e(fecha_relativa($t['inicio'])) ?> · <?= e($t['prestacion_nombre']) ?> · <?= e($t['obra_social'] ? 'coseguro ' . $t['obra_social'] : 'particular') ?></small></td>
            <td class="der num nowrap"><?= money($t['precio']) ?></td>
            <td class="acciones"><a class="btn btn-chico" href="<?= url('cobro.php?paciente=' . $t['paciente_id'] . '&turno=' . $t['id']) ?>">Cobrar</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php if (count($sinCobrar) > 8): ?><p class="apagado mt">Y <?= count($sinCobrar) - 8 ?> más en <a href="<?= url('caja.php') ?>">Caja</a>.</p><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (puede('turnos')): ?>
    <div class="card">
      <div class="card-cab"><h2>Recordatorios para mañana</h2><span class="apagado"><?= plural(count($recordar), 'turno', 'turnos') ?></span></div>
      <?php if (!$recordar): ?>
        <p class="apagado">No hay turnos para mañana.</p>
      <?php else: ?>
      <table class="tabla">
        <?php foreach ($recordar as $t): ?>
          <tr>
            <td class="hora"><?= hora($t['inicio']) ?></td>
            <td><a href="<?= url('turno.php?id=' . $t['id']) ?>"><?= e(nombre_paciente($t)) ?></a><br><small><?= e($t['profesional']) ?></small></td>
            <td class="acciones">
              <?php if ($t['recordado']): ?>
                <small class="apagado" title="Enviado <?= e(fecha_hora($t['recordado'])) ?>">Enviado</small>
              <?php elseif ($t['telefono']): ?>
                <form method="post" action="<?= url('turno.php?id=' . $t['id']) ?>" class="inline" target="_blank" onsubmit="var b=this.querySelector('button'); setTimeout(function(){ b.outerHTML='<small class=apagado>Enviado</small>'; }, 300)">
                  <?= csrf_field() ?><input type="hidden" name="accion" value="whatsapp">
                  <button class="btn btn-chico btn-wa" type="submit">WhatsApp</button>
                </form>
              <?php else: ?>
                <small class="apagado">Sin teléfono</small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($mio): ?>
    <div class="card">
      <h2>Próximos días</h2>
      <?php if (!$proximosMios): ?>
        <p class="apagado">No tenés turnos en los próximos 7 días.</p>
      <?php else: ?>
      <table class="tabla">
        <?php foreach ($proximosMios as $t): ?>
          <tr><td class="nowrap"><?= e(ucfirst(fecha_relativa($t['inicio']))) ?><br><small><?= hora($t['inicio']) ?> hs</small></td>
              <td><a href="<?= url('turno.php?id=' . $t['id']) ?>"><?= e(nombre_paciente($t)) ?></a><br><small><?= e($t['prestacion_nombre']) ?></small></td></tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php if ($mio || $enEspera): ?>
<script>
// La sala de espera se actualiza sola cada minuto (salvo que estés escribiendo algo)
setTimeout(function tic() {
  if (document.activeElement && /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { setTimeout(tic, 60000); return; }
  location.reload();
}, 60000);
</script>
<?php endif; ?>
<?php layout_footer(); ?>
