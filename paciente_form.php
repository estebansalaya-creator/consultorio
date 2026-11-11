<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('pacientes_editar');

$id = (int)get('id', 0);
$c = $id ? q_row('SELECT * FROM pacientes WHERE id = ?', [$id]) : null;
if ($id && !$c) {
    redirect(url('pacientes.php'));
}
$err = '';
// Los antecedentes no están acá: son parte de la historia clínica y los cargan los profesionales
$CAMPOS = ['nombre', 'apellido', 'dni', 'fecha_nac', 'sexo', 'telefono', 'email', 'direccion', 'obra_social_id', 'plan', 'nro_afiliado', 'notas'];

if (es_post()) {
    csrf_check();
    if (post('accion') === 'eliminar' && $id) {
        if (q_val('SELECT COUNT(*) FROM turnos WHERE paciente_id = ?', [$id]) || q_val('SELECT COUNT(*) FROM cobros WHERE paciente_id = ?', [$id])
            || q_val('SELECT COUNT(*) FROM evoluciones WHERE paciente_id = ?', [$id])) {
            q('UPDATE pacientes SET activo = 0 WHERE id = ?', [$id]);
            flash('ok', 'Tiene turnos, cobros o historia clínica, así que no se borra: quedó archivado y ya no aparece en las búsquedas.');
        } else {
            q('DELETE FROM pacientes WHERE id = ?', [$id]);
            flash('ok', 'Paciente eliminado.');
        }
        redirect(url('pacientes.php'));
    }
    $d = [];
    foreach ($CAMPOS as $k) {
        $d[$k] = trim((string)post($k, ''));
    }
    $d['dni'] = preg_replace('/\D/', '', $d['dni']);
    $d['fecha_nac'] = fecha_valida($d['fecha_nac']);
    $d['sexo'] = isset(SEXOS[$d['sexo']]) ? $d['sexo'] : '';
    $d['obra_social_id'] = (int)$d['obra_social_id'] ?: '';
    if (!$d['obra_social_id']) {
        $d['plan'] = $d['nro_afiliado'] = '';
    }
    if ($d['nombre'] === '') {
        $err = 'Poné el nombre.';
    } elseif ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $err = 'El email no parece válido.';
    } elseif ($d['fecha_nac'] && $d['fecha_nac'] > date('Y-m-d')) {
        $err = 'La fecha de nacimiento es posterior a hoy.';
    } elseif ($d['dni'] !== '' && ($otro = q_row('SELECT * FROM pacientes WHERE dni = ? AND id <> ?', [$d['dni'], $id]))) {
        $err = 'Ya hay un paciente con ese DNI: ' . nombre_paciente($otro) . ($otro['activo'] ? '' : ' (archivado)') . '.';
    } else {
        $vals = array_map(function ($v) { return $v === '' || $v === null ? null : $v; }, $d);
        $vals['apellido'] = $d['apellido'];
        if ($id) {
            $set = implode(', ', array_map(function ($k) { return "$k = ?"; }, $CAMPOS));
            q("UPDATE pacientes SET $set, activo = 1 WHERE id = ?", array_merge(array_values($vals), [$id]));
        } else {
            q('INSERT INTO pacientes (' . implode(', ', $CAMPOS) . ') VALUES (' . implode(', ', array_fill(0, count($CAMPOS), '?')) . ')', array_values($vals));
            $id = (int)db()->lastInsertId();
        }
        flash('ok', 'Datos del paciente guardados.');
        redirect(url('paciente.php?id=' . $id));
    }
}

$v = function ($k) use ($c) {
    return es_post() ? (string)post($k, '') : ($c ? (string)$c[$k] : '');
};
layout_header($c ? 'Editar paciente' : 'Nuevo paciente', ['activo' => 'pacientes.php', 'subtitulo' => $c ? nombre_paciente($c) : '']);
?>
<form method="post" class="card" style="max-width:900px">
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="aviso aviso-error"><?= e($err) ?></div><?php endif; ?>
  <h2>Datos personales</h2>
  <div class="form-fila">
    <div class="campo"><label for="nombre">Nombre</label><input type="text" id="nombre" name="nombre" value="<?= e($v('nombre')) ?>" required maxlength="80" autofocus></div>
    <div class="campo"><label for="apellido">Apellido</label><input type="text" id="apellido" name="apellido" value="<?= e($v('apellido')) ?>" maxlength="80"></div>
    <div class="campo"><label for="dni">DNI</label><input type="text" inputmode="numeric" id="dni" name="dni" value="<?= e($v('dni')) ?>" maxlength="15"></div>
  </div>
  <div class="form-fila">
    <div class="campo"><label for="fecha_nac">Fecha de nacimiento</label><input type="date" id="fecha_nac" name="fecha_nac" value="<?= e($v('fecha_nac')) ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="campo"><label for="sexo">Sexo</label>
      <select id="sexo" name="sexo"><option value="">Sin indicar</option>
        <?php foreach (SEXOS as $k => $txt): ?><option value="<?= $k ?>"<?= $v('sexo') === $k ? ' selected' : '' ?>><?= e($txt) ?></option><?php endforeach; ?>
      </select></div>
    <div class="campo"><label for="telefono">Teléfono (celular)</label><input type="tel" id="telefono" name="telefono" value="<?= e($v('telefono')) ?>" maxlength="40" placeholder="11 5555-1234">
      <small>Con código de área, sin 0 ni 15: así funciona el recordatorio por WhatsApp.</small></div>
  </div>
  <div class="form-fila">
    <div class="campo"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($v('email')) ?>" maxlength="120"></div>
    <div class="campo" style="grid-column: span 2"><label for="direccion">Dirección</label><input type="text" id="direccion" name="direccion" value="<?= e($v('direccion')) ?>" maxlength="200"></div>
  </div>

  <h2 class="mt">Cobertura</h2>
  <div class="form-fila">
    <div class="campo"><label for="obra_social_id">Obra social o prepaga</label>
      <select id="obra_social_id" name="obra_social_id"><option value="">Particular (sin cobertura)</option><?= opciones(q_all('SELECT * FROM obras_sociales WHERE activo = 1 OR id = ? ORDER BY orden, nombre', [$c ? (int)$c['obra_social_id'] : 0]), $v('obra_social_id')) ?></select></div>
    <div class="campo js-cob"><label for="plan">Plan</label><input type="text" id="plan" name="plan" value="<?= e($v('plan')) ?>" maxlength="60"></div>
    <div class="campo js-cob"><label for="nro_afiliado">Nº de afiliado</label><input type="text" id="nro_afiliado" name="nro_afiliado" value="<?= e($v('nro_afiliado')) ?>" maxlength="40"></div>
  </div>

  <div class="campo"><label for="notas">Notas administrativas</label><textarea id="notas" name="notas" rows="2" placeholder="Cómo prefiere que lo contacten, quién lo acompaña, datos del responsable si es menor"><?= e($v('notas')) ?></textarea>
    <small>Las ve recepción. Los antecedentes médicos (alergias, medicación, enfermedades) no van acá: los carga el profesional en la historia clínica.</small></div>
  <div class="fila">
    <button class="btn btn-pri" type="submit">Guardar</button>
    <a class="btn" href="<?= $c ? url('paciente.php?id=' . $id) : url('pacientes.php') ?>">Volver</a>
    <span class="espacio"></span>
    <?php if ($c): ?><button class="btn btn-peligro" name="accion" value="eliminar" formnovalidate data-confirm="¿Eliminar este paciente? Si tiene turnos, cobros o historia clínica, se archiva en lugar de borrarse.">Eliminar</button><?php endif; ?>
  </div>
</form>
<script>
(function () {
  var os = document.getElementById('obra_social_id');
  function ver() { document.querySelectorAll('.js-cob').forEach(function (c) { c.hidden = !os.value; }); }
  os.addEventListener('change', ver);
  ver();
})();
</script>
<?php layout_footer(); ?>
