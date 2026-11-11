<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('ajustes');

$CLAVES = [
    'nombre_centro' => ['Nombre del centro', 'Aparece en el menú, el ingreso y los recibos.'],
    'direccion'     => ['Dirección', 'Para el recibo y el recordatorio ({direccion}).'],
    'telefono'      => ['Teléfono', 'Para el recibo.'],
    'pie_recibo'    => ['Mensaje al pie del recibo', ''],
];

if (es_post()) {
    csrf_check();
    foreach ($CLAVES as $k => $x) {
        config_set($k, trim((string)post($k, '')));
    }
    $desde = hora_valida((string)post('agenda_desde')) ?: '08:00';
    $hasta = hora_valida((string)post('agenda_hasta')) ?: '20:00';
    if ($hasta <= $desde) {
        $hasta = '20:00';
    }
    config_set('agenda_desde', $desde);
    config_set('agenda_hasta', $hasta);
    config_set('agenda_intervalo', (string)(in_array((int)post('agenda_intervalo'), [5, 10, 15, 20, 30], true) ? (int)post('agenda_intervalo') : 10));
    config_set('msj_recordatorio', trim((string)post('msj_recordatorio')));
    config_set('receta_max_items', (string)max(1, min(6, (int)post('receta_max_items', 3))));
    config_set('receta_leyenda', mb_substr(trim((string)post('receta_leyenda')), 0, 255));
    $urlPub = trim((string)post('url_publica'));
    if ($urlPub !== '' && !preg_match('#^https?://[^\s/]+#i', $urlPub)) {
        flash('error', 'La dirección pública tiene que empezar con https:// (por ejemplo https://turnos.micentro.com.ar/centro_medico). No se guardó.');
        $urlPub = config_get('url_publica', '');
    }
    config_set('url_publica', rtrim($urlPub, '/'));
    $labNom = (int)post('orden_lab_nomenclador');
    config_set('orden_lab_nomenclador', $labNom && nomenclador_get($labNom) ? (string)$labNom : '');
    $frec = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)post('orden_lab_frecuentes'))))));
    config_set('orden_lab_frecuentes', implode(',', array_slice($frec, 0, 60)));
    config_set('orden_leyenda', mb_substr(trim((string)post('orden_leyenda')), 0, 255));
    flash('ok', 'Ajustes guardados.');
    redirect(url('ajustes.php'));
}

layout_header('Ajustes');
?>
<form method="post" class="grid-2">
  <?= csrf_field() ?>
  <div>
    <div class="card">
      <h2>Datos del centro</h2>
      <?php foreach ($CLAVES as $k => $x): ?>
        <div class="campo">
          <label for="<?= $k ?>"><?= e($x[0]) ?></label>
          <input type="text" id="<?= $k ?>" name="<?= $k ?>" value="<?= e(config_get($k)) ?>">
          <?php if ($x[1]): ?><small><?= e($x[1]) ?></small><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="card">
      <h2>Agenda</h2>
      <div class="form-fila">
        <div class="campo"><label for="agenda_desde">Mostrar desde</label><input type="time" id="agenda_desde" name="agenda_desde" value="<?= e(config_get('agenda_desde', '08:00')) ?>"></div>
        <div class="campo"><label for="agenda_hasta">Hasta</label><input type="time" id="agenda_hasta" name="agenda_hasta" value="<?= e(config_get('agenda_hasta', '20:00')) ?>"></div>
        <div class="campo"><label for="agenda_intervalo">Al hacer clic, redondear a</label>
          <select id="agenda_intervalo" name="agenda_intervalo">
            <?php foreach ([5, 10, 15, 20, 30] as $m): ?><option value="<?= $m ?>"<?= (int)config_get('agenda_intervalo', '10') === $m ? ' selected' : '' ?>><?= $m ?> minutos</option><?php endforeach; ?>
          </select></div>
      </div>
      <small class="apagado">Si un profesional atiende fuera de ese rango, la agenda se amplía sola.</small>
    </div>
    <div class="card">
      <h2>Recetas</h2>
      <div class="form-fila">
        <div class="campo"><label for="receta_max_items">Medicamentos por receta</label>
          <select id="receta_max_items" name="receta_max_items">
            <?php foreach ([1, 2, 3, 4, 5, 6] as $m): ?><option value="<?= $m ?>"<?= (int)config_get('receta_max_items', '3') === $m ? ' selected' : '' ?>><?= $m ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="campo"><label for="receta_leyenda">Leyenda al pie</label>
        <input type="text" id="receta_leyenda" name="receta_leyenda" maxlength="255" value="<?= e(config_get('receta_leyenda')) ?>">
        <small>Se imprime al pie de cada receta.</small></div>
      <div class="campo"><label for="url_publica">Dirección pública del sistema</label>
        <input type="url" id="url_publica" name="url_publica" value="<?= e(config_get('url_publica')) ?>" placeholder="https://turnos.micentro.com.ar/centro_medico">
        <small>Con ella la receta y las órdenes llevan un código QR para verificarlas desde el celular. Si el sistema sólo se usa dentro del centro, dejala vacía: llevan igual el código de verificación.</small></div>
    </div>
    <div class="card">
      <h2>Órdenes de laboratorio y estudios</h2>
      <div class="campo"><label for="orden_lab_nomenclador">Nomenclador de laboratorio</label>
        <select id="orden_lab_nomenclador" name="orden_lab_nomenclador"><option value="">El de la obra social del paciente</option><?= opciones(nomencladores_activos(), (int)config_get('orden_lab_nomenclador', '0')) ?></select>
        <small>Con el que se arman las órdenes de laboratorio (Bioquímicas o NBU, por ejemplo). Las de estudios usan el de la obra social del paciente.</small></div>
      <div class="campo"><label for="orden_lab_frecuentes">Determinaciones frecuentes</label>
        <textarea id="orden_lab_frecuentes" name="orden_lab_frecuentes" rows="3"><?= e(str_replace(',', ', ', config_get('orden_lab_frecuentes'))) ?></textarea>
        <small>Códigos del nomenclador de laboratorio, separados por comas, en el orden en que se muestran como botones para tildar rápido.<?php $fr = orden_lab_frecuentes(); if ($fr): ?> Ahora: <?= e(implode(', ', array_column($fr, 'corto'))) ?>.<?php endif; ?></small></div>
      <div class="campo"><label for="orden_leyenda">Leyenda al pie</label>
        <input type="text" id="orden_leyenda" name="orden_leyenda" maxlength="255" value="<?= e(config_get('orden_leyenda')) ?>" placeholder="Opcional"></div>
    </div>
  </div>
  <div>
    <div class="card">
      <h2>Recordatorio por WhatsApp</h2>
      <div class="campo">
        <label for="msj_recordatorio">Mensaje</label>
        <textarea id="msj_recordatorio" name="msj_recordatorio" rows="5"><?= e(config_get('msj_recordatorio')) ?></textarea>
        <small>Se reemplazan solos: {nombre}, {profesional}, {especialidad}, {prestacion}, {dia}, {hora}, {consultorio}, {centro} y {direccion}. El mensaje se abre en WhatsApp para revisarlo antes de enviarlo.</small>
      </div>
    </div>
    <div class="card">
      <h2>Configuración</h2>
      <table class="tabla">
        <tr><td><a href="<?= url('usuarios.php') ?>">Usuarios</a></td><td class="apagado">Quién entra al sistema y qué puede hacer.</td></tr>
        <tr><td><a href="<?= url('profesionales.php') ?>">Profesionales</a></td><td class="apagado">Matrícula, horarios, consultorios y prestaciones de cada uno.</td></tr>
        <tr><td><a href="<?= url('prestaciones.php') ?>">Prestaciones</a></td><td class="apagado">Consultas, estudios y prácticas, con su duración y precio particular.</td></tr>
        <tr><td><a href="<?= url('abm.php?t=especialidades') ?>">Especialidades</a></td><td class="apagado">Agrupan prestaciones y profesionales.</td></tr>
        <tr><td><a href="<?= url('abm.php?t=consultorios') ?>">Consultorios</a></td><td class="apagado">Dónde atiende cada profesional; no se usan dos a la vez.</td></tr>
        <tr><td><a href="<?= url('abm.php?t=obras_sociales') ?>">Obras sociales</a></td><td class="apagado">Coberturas y el coseguro que paga el paciente.</td></tr>
        <tr><td><a href="<?= url('abm.php?t=medios_pago') ?>">Medios de pago</a></td><td class="apagado">Efectivo, transferencia, Mercado Pago, tarjetas…</td></tr>
        <tr><td><a href="<?= url('nomencladores.php') ?>">Nomencladores</a></td><td class="apagado">PMO, Bioquímicas, PAMI, OBSBA, NBU y los propios: códigos, capítulos y valores por vigencia.</td></tr>
        <tr><td><a href="<?= url('cie10.php') ?>">CIE-10</a></td><td class="apagado">Consulta de los códigos de diagnóstico.</td></tr>
        <tr><td><a href="<?= url('medicamentos.php') ?>">Vademécum</a></td><td class="apagado">Medicamentos del listado oficial y registro de búsquedas.</td></tr>
      </table>
    </div>
    <button class="btn btn-pri btn-grande" type="submit">Guardar ajustes</button>
  </div>
</form>
<?php layout_footer(); ?>
