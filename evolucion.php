<?php
/*
 * Escribir o corregir una evolución de la historia clínica.
 *  evolucion.php?paciente=N  -> evolución nueva sin turno (ej. resultado de un estudio, consulta telefónica)
 *  evolucion.php?id=N        -> corregir (sólo quien la escribió, durante las primeras horas)
 *  POST con turno_id         -> la evolución del turno (desde la pantalla del turno)
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('pacientes');
if (!puede_hc()) {
    sin_permiso('La historia clínica la escriben sólo los profesionales.');
}

$id = (int)(es_post() ? post('id', 0) : get('id', 0));
$ev = $id ? evolucion_get($id) : null;
if ($id && !$ev) {
    flash('error', 'La evolución no existe.');
    redirect(url('pacientes.php'));
}
$turnoId = (int)post('turno_id', 0);
$pacienteId = $ev ? (int)$ev['paciente_id'] : (int)(es_post() ? post('paciente_id', 0) : get('paciente', 0));
if ($turnoId) {
    $pacienteId = (int)q_val('SELECT paciente_id FROM turnos WHERE id = ?', [$turnoId]);
}
if (!$pacienteId || !puede_ver_paciente($pacienteId)) {
    sin_permiso('No tenés acceso a la historia clínica de ese paciente.');
}
if ($ev && !evolucion_editable($ev)) {
    flash('error', 'Esa evolución ya está cerrada: sólo quien la escribió puede corregirla, durante las primeras ' . HC_HORAS_EDICION . ' horas.');
    redirect(url('historia.php?id=' . $pacienteId));
}
$pac = q_row('SELECT * FROM pacientes WHERE id = ?', [$pacienteId]);
$err = '';

if (es_post()) {
    csrf_check();
    try {
        $nuevoId = guardar_evolucion([
            'id'           => $ev ? $ev['id'] : null,
            'paciente_id'  => $pacienteId,
            'turno_id'     => $ev ? null : ($turnoId ?: null),
            'fecha'        => null,
            'motivo'       => post('motivo'),
            'texto'        => post('texto'),
            'diagnostico'  => post('diagnostico'),
            'cie10_codigo' => post('cie10_codigo'),
            'indicaciones' => post('indicaciones'),
        ]);
        $tid = $ev ? (int)$ev['turno_id'] : $turnoId;
        if ($tid && !$ev && post('atendido')) {
            $t = q_row('SELECT * FROM turnos WHERE id = ?', [$tid]);
            if ($t && puede_operar_turno($t) && in_array($t['estado'], ['reservado', 'confirmado', 'presente', 'en_atencion'], true)) {
                q("UPDATE turnos SET estado = 'atendido', llegada = COALESCE(llegada, NOW()), actualizado = NOW() WHERE id = ?", [$tid]);
            }
        }
        flash('ok', $ev ? 'Evolución corregida.' : 'Evolución guardada en la historia clínica.');
        redirect($tid ? url('turno.php?id=' . $tid . '#evolucion') : url('historia.php?id=' . $pacienteId));
    } catch (RuntimeException $ex) {
        $err = $ex->getMessage();
        if ($turnoId) {
            flash('error', $err);
            redirect(url('turno.php?id=' . $turnoId . '#evolucion'));
        }
    }
}

$v = function ($k) use ($ev) {
    return es_post() ? (string)post($k, '') : ($ev ? (string)$ev[$k] : '');
};
layout_header($ev ? 'Corregir evolución' : 'Nueva evolución', ['activo' => 'pacientes.php', 'subtitulo' => nombre_paciente($pac)]);
?>
<form method="post" class="card form-evolucion" style="max-width:860px">
  <?= csrf_field() ?>
  <?php if ($ev): ?><input type="hidden" name="id" value="<?= (int)$ev['id'] ?>"><?php else: ?><input type="hidden" name="paciente_id" value="<?= $pacienteId ?>"><?php endif; ?>
  <?php if ($err): ?><div class="aviso aviso-error"><?= e($err) ?></div><?php endif; ?>
  <p class="hc-aviso"><?= $ev
      ? 'Evolución del ' . e(fecha_hora($ev['fecha'])) . '. Se puede corregir ' . e(evolucion_editable_hasta($ev)) . '.'
      : 'Evolución sin turno (por ejemplo, el resultado de un estudio o una consulta telefónica). Queda con fecha y hora de ahora.' ?></p>
  <div class="campo"><label for="motivo">Motivo de consulta</label><input type="text" id="motivo" name="motivo" value="<?= e($v('motivo')) ?>" maxlength="255" autofocus></div>
  <div class="campo"><label for="texto">Evolución</label><textarea id="texto" name="texto" rows="8" required placeholder="Anamnesis, examen físico, estudios, conducta"><?= e($v('texto')) ?></textarea></div>
  <?= campo_cie10($v('cie10_codigo'), $v('diagnostico')) ?>
  <div class="campo campo-chico"><label for="indicaciones">Indicaciones</label><textarea id="indicaciones" name="indicaciones" rows="3" placeholder="Medicación, estudios pedidos, próximo control"><?= e($v('indicaciones')) ?></textarea></div>
  <div class="fila">
    <button class="btn btn-pri" type="submit"><?= $ev ? 'Guardar corrección' : 'Guardar evolución' ?></button>
    <a class="btn" href="<?= $ev && $ev['turno_id'] ? url('turno.php?id=' . $ev['turno_id'] . '#evolucion') : url('historia.php?id=' . $pacienteId) ?>">Volver</a>
  </div>
</form>
<?php layout_footer(); ?>
