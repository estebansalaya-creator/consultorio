<?php
/*
 * Orden de laboratorio o de estudios: nueva (orden.php?paciente=N[&turno=N][&tipo=laboratorio] o ?copiar=ID),
 * borrador (?id=N) y orden firmada (vista para imprimir). Sólo los profesionales.
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('recetas');
if (!puede_hc()) {
    sin_permiso('Las órdenes las emiten los profesionales.');
}

$id = (int)(es_post() ? post('id', 0) : get('id', 0));
$o = $id ? orden_get($id) : null;
if ($id && !$o) {
    flash('error', 'La orden no existe.');
    redirect(url('ordenes.php'));
}
if ($o && !puede_ver_hc($o['paciente_id'])) {
    sin_permiso('Sólo podés ver órdenes de pacientes que atienden vos o tu especialidad.');
}
$copiar = !$o && get('copiar') ? orden_get((int)get('copiar')) : null;
if ($copiar && !puede_ver_hc($copiar['paciente_id'])) {
    $copiar = null;
}
$pacienteId = $o ? (int)$o['paciente_id'] : ($copiar ? (int)$copiar['paciente_id'] : (int)(es_post() ? post('paciente_id') : get('paciente', 0)));
$turnoId = $o ? (int)$o['turno_id'] : (int)(es_post() ? post('turno_id') : get('turno', 0));
$pac = $pacienteId ? q_row('SELECT pa.*, os.nombre AS obra_social, os.rnas FROM pacientes pa LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id WHERE pa.id = ?', [$pacienteId]) : null;
if (!$pac) {
    flash('error', 'Elegí el paciente para hacer la orden.');
    redirect(url('ordenes.php'));
}
if (!puede_ver_paciente($pacienteId)) {
    sin_permiso('Sólo podés hacer órdenes a pacientes que atienden vos o tu especialidad.');
}
const ORDEN_MAX_ITEMS = 40;
$err = '';

/** Lee las prácticas del formulario */
function orden_items_post($nomId)
{
    $ids = (array)post('it_codigo_id', []);
    $cods = (array)post('it_codigo', []);
    $descs = (array)post('it_descripcion', []);
    $cants = (array)post('it_cantidad', []);
    $obs = (array)post('it_obs', []);
    $items = [];
    foreach ($descs as $i => $d) {
        $d = trim((string)$d);
        $cid = (int)(isset($ids[$i]) ? $ids[$i] : 0);
        $codigo = trim((string)(isset($cods[$i]) ? $cods[$i] : ''));
        if ($cid) {
            $c = q_row('SELECT codigo, nombre FROM prest_nomenclador_codigo WHERE id = ?', [$cid]);
            if ($c) {
                $codigo = $c['codigo'];
                $d = $d !== '' ? $d : $c['nombre'];
            } else {
                $cid = 0;
            }
        }
        if ($d === '') {
            continue;
        }
        $items[] = [
            'codigo_id'   => $cid ?: null,
            'codigo'      => $codigo !== '' ? mb_substr($codigo, 0, 20) : null,
            'descripcion' => mb_substr($d, 0, 500),
            'cantidad'    => max(1, min(9, (int)(isset($cants[$i]) ? $cants[$i] : 1))),
            'observacion' => mb_substr(trim((string)(isset($obs[$i]) ? $obs[$i] : '')), 0, 255) ?: null,
        ];
        if (count($items) >= ORDEN_MAX_ITEMS) {
            break;
        }
    }
    return $items;
}

if (es_post()) {
    csrf_check();
    $accion = post('accion');
    try {
        if ($accion === 'guardar' || $accion === 'firmar') {
            if ($o && !orden_editable($o)) {
                throw new RuntimeException('Esta orden ya está firmada o anulada: no se puede modificar.');
            }
            $tipo = isset(TIPOS_ORDEN[post('tipo')]) ? post('tipo') : 'estudios';
            $nom = nomenclador_get((int)post('nomenclador_id'));
            $items = orden_items_post($nom ? $nom['id'] : 0);
            $cie = trim((string)post('cie10_codigo'));
            if ($cie !== '' && !cie10_get($cie)) {
                throw new RuntimeException('El código CIE-10 «' . $cie . '» no existe. Elegilo de la lista.');
            }
            $cie = $cie !== '' ? cie10_get($cie)['codigo'] : null;
            $vals = [$tipo, $nom ? $nom['id'] : null, $nom ? $nom['nombre'] : null, $cie,
                     mb_substr(trim((string)post('diagnostico')), 0, 255) ?: null, trim((string)post('indicaciones')) ?: null, post('urgente') ? 1 : 0];
            $pdo = db();
            $pdo->beginTransaction();
            if ($o) {
                q('UPDATE ordenes SET tipo = ?, nomenclador_id = ?, nomenclador_nombre = ?, cie10_codigo = ?, diagnostico = ?, indicaciones = ?, urgente = ?, actualizado = NOW() WHERE id = ?',
                  array_merge($vals, [$id]));
                q('DELETE FROM orden_items WHERE orden_id = ?', [$id]);
            } else {
                $turnoOk = $turnoId && q_val('SELECT 1 FROM turnos WHERE id = ? AND paciente_id = ?', [$turnoId, $pacienteId]);
                q('INSERT INTO ordenes (tipo, nomenclador_id, nomenclador_nombre, cie10_codigo, diagnostico, indicaciones, urgente,
                                        paciente_id, profesional_id, turno_id, fecha, obra_social_id, obra_social_nombre, rnas, plan, nro_afiliado, usuario_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)',
                  array_merge($vals, [$pacienteId, mi_profesional_id(), $turnoOk ? $turnoId : null, $pac['obra_social_id'], $pac['obra_social'],
                                      $pac['obra_social_id'] ? $pac['rnas'] : null, $pac['obra_social_id'] ? $pac['plan'] : null,
                                      $pac['obra_social_id'] ? $pac['nro_afiliado'] : null, usuario_id()]));
                $id = (int)$pdo->lastInsertId();
            }
            foreach ($items as $n => $it) {
                q('INSERT INTO orden_items (orden_id, orden, codigo_id, codigo, descripcion, cantidad, observacion) VALUES (?, ?, ?, ?, ?, ?, ?)',
                  [$id, $n + 1, $it['codigo_id'], $it['codigo'], $it['descripcion'], $it['cantidad'], $it['observacion']]);
            }
            $pdo->commit();
            if ($accion === 'firmar') {
                try {
                    $numero = orden_firmar($id, (string)post('pin'));
                    flash('ok', orden_titulo($tipo) . ' N.º ' . sprintf('%06d', $numero) . ' firmada.');
                } catch (RuntimeException $ex) {
                    flash('error', $ex->getMessage() . ' La orden quedó guardada como borrador.');
                }
            } else {
                flash('ok', 'Borrador guardado.');
            }
            redirect(url('orden.php?id=' . $id));
        }
        if (!$o) {
            redirect(url('ordenes.php'));
        }
        $esAutor = (int)$o['profesional_id'] === (int)mi_profesional_id();
        if ($accion === 'eliminar' && orden_editable($o)) {
            q('DELETE FROM ordenes WHERE id = ?', [$id]);
            flash('ok', 'Borrador eliminado.');
            redirect(url('paciente.php?id=' . $pacienteId));
        }
        if ($accion === 'anular') {
            if (!$esAutor || $o['estado'] !== 'firmada') {
                throw new RuntimeException('Sólo quien la firmó puede anular una orden firmada.');
            }
            $motivo = trim((string)post('motivo'));
            if ($motivo === '') {
                throw new RuntimeException('Escribí el motivo de la anulación.');
            }
            q("UPDATE ordenes SET estado = 'anulada', motivo_anulacion = ?, anulada_en = NOW() WHERE id = ?", [mb_substr($motivo, 0, 255), $id]);
            flash('ok', 'Orden anulada. La verificación ahora la muestra como anulada.');
        }
        if ($accion === 'cuir') {
            if (!$esAutor || $o['estado'] !== 'firmada') {
                throw new RuntimeException('Sólo quien la firmó puede registrar la CUIR.');
            }
            q('UPDATE ordenes SET cuir = ?, plataforma = ? WHERE id = ?',
              [mb_substr(trim((string)post('cuir')), 0, 40) ?: null, mb_substr(trim((string)post('plataforma')), 0, 80) ?: null, $id]);
            flash('ok', 'Datos de la orden electrónica guardados.');
        }
    } catch (RuntimeException $ex) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $err = $ex->getMessage();
        if ($o) {
            flash('error', $err);
            redirect(url('orden.php?id=' . $id));
        }
    }
    if ($o) {
        redirect(url('orden.php?id=' . $id));
    }
}

$editar = !$o || orden_editable($o);
$firmaLista = firma_lista(mi_profesional_id());

// ---------- Formulario (orden nueva o borrador) ----------
if ($editar):
    $base = $o ?: $copiar;
    $tipo = $base ? $base['tipo'] : (get('tipo') === 'laboratorio' ? 'laboratorio' : 'estudios');
    $items = $base ? orden_items($base['id']) : [];
    $nomSel = $base && $base['nomenclador_id'] ? nomenclador_get($base['nomenclador_id']) : orden_nomenclador_sugerido($tipo, $pac['obra_social_id']);
    $cieCod = $base ? (string)$base['cie10_codigo'] : '';
    $diag = $base ? (string)$base['diagnostico'] : '';
    if (!$base && $turnoId && ($ev = evolucion_de_turno($turnoId))) {
        $cieCod = (string)$ev['cie10_codigo'];
        $diag = (string)$ev['diagnostico'];
    }
    $indic = $base ? (string)$base['indicaciones'] : '';
    $urgente = $base ? (int)$base['urgente'] : 0;
    if (es_post()) {
        $tipo = isset(TIPOS_ORDEN[post('tipo')]) ? post('tipo') : $tipo;
        $nomSel = nomenclador_get((int)post('nomenclador_id')) ?: $nomSel;
        $items = orden_items_post(0);
        $cieCod = (string)post('cie10_codigo');
        $diag = (string)post('diagnostico');
        $indic = (string)post('indicaciones');
        $urgente = post('urgente') ? 1 : 0;
    }
    $noms = nomencladores_activos();
    $sugerido = [
        'laboratorio' => ($n = orden_nomenclador_sugerido('laboratorio', $pac['obra_social_id'])) ? (int)$n['id'] : 0,
        'estudios'    => ($n = orden_nomenclador_sugerido('estudios', $pac['obra_social_id'])) ? (int)$n['id'] : 0,
    ];
    $frecuentes = orden_lab_frecuentes();
    $labNom = (int)config_get('orden_lab_nomenclador', '0');
    $ultimas = q_all("SELECT o.id, o.tipo, o.fecha, (SELECT GROUP_CONCAT(i.descripcion ORDER BY i.orden SEPARATOR '|') FROM orden_items i WHERE i.orden_id = o.id) AS practicas
                        FROM ordenes o WHERE o.paciente_id = ? AND o.estado = 'firmada' ORDER BY o.fecha DESC, o.id DESC LIMIT 5", [$pacienteId]);
    layout_header($o ? 'Orden (borrador)' : 'Nueva orden', ['activo' => 'ordenes.php', 'subtitulo' => nombre_paciente($pac)]);
    ?>
<?php if ($err): ?><div class="aviso aviso-error"><?= e($err) ?></div><?php endif; ?>
<?php if (!$firmaLista): ?>
  <div class="aviso aviso-alerta">Para firmar órdenes primero cargá tu firma y tu PIN en <a href="<?= url('firma.php') ?>">Mi firma</a>. Mientras tanto podés guardarla como borrador.</div>
<?php endif; ?>
<form method="post" class="grid-2-1" id="form-orden" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $o ? (int)$o['id'] : 0 ?>">
  <input type="hidden" name="paciente_id" value="<?= $pacienteId ?>">
  <input type="hidden" name="turno_id" value="<?= $turnoId ?>">
  <div>
    <div class="card">
      <div class="orden-tipo" role="radiogroup" aria-label="Tipo de orden">
        <?php foreach (TIPOS_ORDEN as $k => $txt): ?>
          <label class="orden-tipo-op"><input type="radio" name="tipo" value="<?= $k ?>"<?= $tipo === $k ? ' checked' : '' ?>> <?= e($txt) ?></label>
        <?php endforeach; ?>
        <span class="espacio"></span>
        <label class="check"><input type="checkbox" name="urgente" value="1"<?= $urgente ? ' checked' : '' ?>> Urgente</label>
      </div>
      <div class="form-fila">
        <div class="campo"><label for="nomenclador_id">Nomenclador</label>
          <select id="nomenclador_id" name="nomenclador_id"><?= opciones($noms, $nomSel ? $nomSel['id'] : null) ?></select>
          <small id="nom-ayuda"><?= $pac['obra_social'] ? 'El de ' . e($pac['obra_social']) . ' o el de laboratorio de Ajustes.' : 'Paciente particular: el nomenclador por defecto.' ?></small></div>
        <div class="campo" style="flex:2"><label for="buscar-practica">Agregar práctica</label>
          <input type="search" id="buscar-practica" placeholder="Código o nombre (ej. hemograma, 420101, ecografía)"></div>
      </div>
      <?php if ($frecuentes): ?>
        <div class="frecuentes" id="frecuentes" data-nom="<?= $labNom ?>">
          <span class="apagado">Frecuentes:</span>
          <?php foreach ($frecuentes as $f): ?>
            <button type="button" class="chip-prac" data-id="<?= (int)$f['id'] ?>" data-codigo="<?= e($f['codigo']) ?>" data-desc="<?= e(descripcion_practica($f['nombre'])) ?>" title="<?= e($f['codigo'] . ' · ' . $f['nombre']) ?>"><?= e($f['corto']) ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <table class="tabla orden-items">
        <thead><tr><th style="width:90px">Código</th><th>Práctica</th><th style="width:70px">Cant.</th><th style="width:34px"></th></tr></thead>
        <tbody id="items">
          <?php foreach ($items as $it): ?>
            <tr class="js-it">
              <td><input type="hidden" name="it_codigo_id[]" value="<?= (int)$it['codigo_id'] ?: '' ?>"><input type="text" name="it_codigo[]" value="<?= e($it['codigo']) ?>" maxlength="20" aria-label="Código" <?= $it['codigo_id'] ? 'readonly' : '' ?>></td>
              <td><input type="text" name="it_descripcion[]" value="<?= e($it['descripcion']) ?>" maxlength="500" aria-label="Práctica">
                <input type="text" name="it_obs[]" value="<?= e($it['observacion']) ?>" maxlength="255" class="it-obs" placeholder="Observación (opcional)" aria-label="Observación"></td>
              <td><input type="number" name="it_cantidad[]" value="<?= (int)$it['cantidad'] ?>" min="1" max="9" aria-label="Cantidad"></td>
              <td><button type="button" class="it-quitar" title="Quitar" aria-label="Quitar">×</button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="apagado" id="items-vacio"<?= $items ? ' hidden' : '' ?>>Todavía no hay prácticas. Buscalas arriba<?= $frecuentes ? ' o tocá las frecuentes' : '' ?>.</p>
      <button type="button" class="btn btn-chico" id="agregar-libre">+ Práctica sin código</button>
    </div>
    <div class="card">
      <?= campo_cie10($cieCod, $diag, 'Diagnóstico presuntivo (CIE-10)') ?>
      <div class="campo"><label for="indicaciones">Indicaciones / preparación</label><textarea id="indicaciones" name="indicaciones" rows="2" placeholder="Ej: ayuno de 8 horas; concurrir con la orden y el carnet"><?= e($indic) ?></textarea></div>
    </div>
  </div>

  <div>
    <div class="card">
      <h2><?= e(nombre_paciente($pac)) ?></h2>
      <dl class="datos">
        <?php if ($pac['dni']): ?><dt>DNI</dt><dd><?= e(dni_txt($pac['dni'])) ?></dd><?php endif; ?>
        <?php if ($pac['fecha_nac']): ?><dt>Edad</dt><dd><?= e(edad_txt($pac['fecha_nac'])) ?></dd><?php endif; ?>
        <dt>Cobertura</dt><dd><?= e(cobertura_txt($pac['obra_social'])) ?><?= $pac['obra_social'] ? '<br><small>' . ($pac['rnas'] ? 'RNAS ' . e($pac['rnas']) . ' · ' : '') . ($pac['nro_afiliado'] ? e($pac['nro_afiliado']) : 'Falta el nº de afiliado') . '</small>' : '' ?></dd>
      </dl>
      <?php if ($ultimas): ?>
        <h3 class="mt">Últimas órdenes</h3>
        <table class="tabla">
          <?php foreach ($ultimas as $u): ?>
            <tr><td class="nowrap"><a href="<?= url('orden.php?id=' . $u['id']) ?>"><?= e(fecha($u['fecha'])) ?></a></td><td><small><?= e(orden_resumen($u['practicas'])) ?></small></td></tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Firmar</h2>
      <?php if ($firmaLista): ?>
        <div class="campo"><label for="pin">Tu PIN de firma</label><input type="password" inputmode="numeric" id="pin" name="pin" maxlength="8" autocomplete="off"></div>
        <button class="btn btn-pri btn-grande btn-bloque" type="submit" name="accion" value="firmar" id="btn-firmar">Firmar orden</button>
        <p class="apagado mt"><small>Una vez firmada no se puede modificar: si hay un error, se anula y se hace otra.</small></p>
      <?php else: ?>
        <p class="apagado">Cargá tu firma en <a href="<?= url('firma.php') ?>">Mi firma</a> para poder firmar.</p>
      <?php endif; ?>
      <div class="fila mt">
        <button class="btn" type="submit" name="accion" value="guardar">Guardar borrador</button>
        <a class="btn" href="<?= $turnoId ? url('turno.php?id=' . $turnoId) : url('paciente.php?id=' . $pacienteId) ?>">Volver</a>
        <?php if ($o): ?><span class="espacio"></span><button class="btn btn-peligro" type="submit" name="accion" value="eliminar" formnovalidate data-confirm="¿Eliminar este borrador?">Eliminar</button><?php endif; ?>
      </div>
    </div>
  </div>
</form>
<template id="tpl-item">
  <tr class="js-it">
    <td><input type="hidden" name="it_codigo_id[]" value=""><input type="text" name="it_codigo[]" value="" maxlength="20" aria-label="Código"></td>
    <td><input type="text" name="it_descripcion[]" value="" maxlength="500" aria-label="Práctica">
      <input type="text" name="it_obs[]" value="" maxlength="255" class="it-obs" placeholder="Observación (opcional)" aria-label="Observación"></td>
    <td><input type="number" name="it_cantidad[]" value="1" min="1" max="9" aria-label="Cantidad"></td>
    <td><button type="button" class="it-quitar" title="Quitar" aria-label="Quitar">×</button></td>
  </tr>
</template>
<script>
(function () {
  var tbody = document.getElementById('items'), vacio = document.getElementById('items-vacio');
  var selNom = document.getElementById('nomenclador_id'), frec = document.getElementById('frecuentes');
  var sugerido = <?= json_encode($sugerido) ?>;
  var max = <?= ORDEN_MAX_ITEMS ?>;
  function filas() { return tbody.querySelectorAll('.js-it'); }
  function actualizar() {
    vacio.hidden = filas().length > 0;
    if (frec) {
      frec.hidden = String(selNom.value) !== frec.dataset.nom;
      var ids = Array.prototype.map.call(tbody.querySelectorAll('input[name="it_codigo_id[]"]'), function (i) { return i.value; });
      frec.querySelectorAll('.chip-prac').forEach(function (b) { b.classList.toggle('on', ids.indexOf(b.dataset.id) >= 0); });
    }
  }
  function agregar(p) {
    if (p && p.id) {
      var ya = tbody.querySelector('input[name="it_codigo_id[]"][value="' + p.id + '"]');
      if (ya) { ya.closest('tr').classList.add('resaltado'); setTimeout(function () { ya.closest('tr').classList.remove('resaltado'); }, 900); return; }
    }
    if (filas().length >= max) { return; }
    var tr = document.getElementById('tpl-item').content.firstElementChild.cloneNode(true);
    if (p) {
      tr.querySelector('input[name="it_codigo_id[]"]').value = p.id || '';
      var cod = tr.querySelector('input[name="it_codigo[]"]'); cod.value = p.codigo || ''; if (p.id) cod.readOnly = true;
      tr.querySelector('input[name="it_descripcion[]"]').value = p.descripcion || '';
    }
    tbody.appendChild(tr);
    actualizar();
    return tr;
  }
  tbody.addEventListener('click', function (ev) {
    if (ev.target.classList.contains('it-quitar')) { ev.target.closest('tr').remove(); actualizar(); }
  });
  document.getElementById('agregar-libre').addEventListener('click', function () { var tr = agregar(null); if (tr) tr.querySelector('input[name="it_descripcion[]"]').focus(); });
  if (frec) frec.addEventListener('click', function (ev) {
    var b = ev.target.closest('.chip-prac'); if (!b) return;
    var ya = tbody.querySelector('input[name="it_codigo_id[]"][value="' + b.dataset.id + '"]');
    if (ya) { ya.closest('tr').remove(); actualizar(); return; }
    agregar({ id: b.dataset.id, codigo: b.dataset.codigo, descripcion: b.dataset.desc });
  });
  // Al cambiar el tipo se propone su nomenclador (si todavía no hay prácticas cargadas)
  document.querySelectorAll('input[name=tipo]').forEach(function (r) {
    r.addEventListener('change', function () {
      if (!filas().length && sugerido[r.value]) selNom.value = sugerido[r.value];
      actualizar();
    });
  });
  selNom.addEventListener('change', actualizar);
  var buscar = document.getElementById('buscar-practica');
  App.buscador(buscar, {
    url: function (q) { return 'api/practicas.php?nom=' + selNom.value + '&q=' + encodeURIComponent(q); },
    lista: 'practicas', min: 2, vacio: 'No hay prácticas que coincidan en este nomenclador. Probá con otro o agregala sin código.',
    item: function (r) { return '<b>' + App.esc(r.codigo) + '</b> ' + App.esc(r.descripcion); },
    elegir: function (r) { agregar(r); buscar.value = ''; buscar.focus(); }
  });
  var btn = document.getElementById('btn-firmar');
  if (btn) btn.addEventListener('click', function (ev) {
    if (!filas().length) { ev.preventDefault(); alert('Agregá al menos una práctica.'); return; }
    if (!document.getElementById('pin').value) { ev.preventDefault(); alert('Escribí tu PIN de firma.'); document.getElementById('pin').focus(); }
  });
  actualizar();
})();
</script>
<?php
    layout_footer();
    exit;
endif;

// ---------- Orden firmada o anulada: vista para imprimir ----------
$items = orden_items($id);
$esAutor = (int)$o['profesional_id'] === (int)mi_profesional_id();
$firma = firma_get($o['profesional_id']);
$copias = get('copias') === '2' ? 2 : 1;
$urlVerif = receta_url_verificacion($o);
$integra = orden_integra($o);
$titulo = orden_titulo($o['tipo']);
layout_header($titulo . ' ' . sprintf('%06d', $o['numero']), ['activo' => 'ordenes.php', 'clase' => 'pagina-receta', 'subtitulo' => nombre_paciente($o)]);
?>
<?php if ($o['estado'] === 'anulada'): ?>
  <div class="aviso aviso-error no-print">Orden anulada el <?= e(fecha_hora($o['anulada_en'])) ?><?= $o['motivo_anulacion'] ? ': ' . e($o['motivo_anulacion']) : '' ?>.</div>
<?php elseif (!$integra): ?>
  <div class="aviso aviso-error no-print">El contenido guardado de esta orden no coincide con lo que se firmó: fue modificado en la base de datos.</div>
<?php endif; ?>
<div class="fila no-print" style="margin-bottom:16px">
  <?php if ($o['estado'] === 'firmada'): ?>
    <button class="btn btn-pri" type="button" onclick="window.print()">Imprimir</button>
    <a class="btn" href="<?= url('orden.php?id=' . $id . ($copias === 2 ? '' : '&copias=2')) ?>"><?= $copias === 2 ? 'Sin duplicado' : 'Con duplicado' ?></a>
  <?php endif; ?>
  <a class="btn" href="<?= url('orden.php?copiar=' . $id) ?>">Repetir orden</a>
  <a class="btn" href="<?= url('historia.php?id=' . $o['paciente_id']) ?>">Historia clínica</a>
  <span class="espacio"></span>
  <?php if ($esAutor && $o['estado'] === 'firmada'): ?>
    <button class="btn" type="button" data-abrir="modal-cuir"><?= $o['cuir'] ? 'CUIR ' . e($o['cuir']) : 'Registrar CUIR' ?></button>
    <button class="btn btn-peligro" type="button" data-abrir="modal-anular">Anular</button>
  <?php endif; ?>
</div>

<?php for ($copia = 1; $copia <= $copias; $copia++): ?>
<div class="receta-hoja<?= $o['estado'] === 'anulada' ? ' anulada' : '' ?>">
  <header class="receta-cab">
    <div>
      <div class="receta-centro"><?= e(config_get('nombre_centro')) ?></div>
      <small><?= e(config_get('direccion')) ?><?= config_get('telefono') ? ' · Tel. ' . e(config_get('telefono')) : '' ?></small>
    </div>
    <div class="der">
      <b><?= e($titulo) ?> N.º <?= sprintf('%06d', $o['numero']) ?></b><?= $copias === 2 ? '<br><small>' . ($copia === 1 ? 'ORIGINAL' : 'DUPLICADO') . '</small>' : '' ?>
      <br><small>Fecha: <?= e(fecha($o['fecha'])) ?></small>
      <?php if ($o['urgente']): ?><br><span class="orden-urgente">URGENTE</span><?php endif; ?>
    </div>
  </header>
  <div class="receta-pac">
    <div><span>Paciente</span><b><?= e(apellido_nombre($o)) ?></b></div>
    <div><span>DNI</span><?= e(dni_txt($o['dni'])) ?></div>
    <?php if ($o['fecha_nac']): ?><div><span>Nacimiento</span><?= e(fecha($o['fecha_nac'])) ?> (<?= e(edad_txt($o['fecha_nac'])) ?>)</div><?php endif; ?>
    <?php if ($o['sexo']): ?><div><span>Sexo</span><?= e(SEXOS[$o['sexo']]) ?></div><?php endif; ?>
    <div><span>Cobertura</span><?= e(cobertura_txt($o['obra_social_nombre'])) ?><?= $o['plan'] ? ' · Plan ' . e($o['plan']) : '' ?><?= $o['obra_social_nombre'] && $o['rnas'] ? '<br><small>RNAS ' . e($o['rnas']) . '</small>' : '' ?></div>
    <?php if ($o['nro_afiliado']): ?><div><span>N.º de afiliado</span><?= e($o['nro_afiliado']) ?></div><?php endif; ?>
  </div>
  <div class="orden-cuerpo">
    <div class="orden-sol">Solicito<?= $o['nomenclador_nombre'] ? ' <small>(códigos del nomenclador ' . e($o['nomenclador_nombre']) . ')</small>' : '' ?>:</div>
    <table class="orden-tabla">
      <?php foreach ($items as $it): ?>
        <tr>
          <td class="cod"><?= e($it['codigo']) ?></td>
          <td><?= e($it['descripcion']) ?><?= $it['observacion'] ? '<br><small>' . e($it['observacion']) . '</small>' : '' ?></td>
          <td class="cant"><?= (int)$it['cantidad'] > 1 ? '× ' . (int)$it['cantidad'] : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="orden-total"><small><?= plural(count($items), 'práctica', 'prácticas') ?></small></p>
  </div>
  <?php if ($o['diagnostico'] || $o['cie10_codigo']): ?>
    <div class="receta-dx"><span>Diagnóstico presuntivo</span><?= $o['cie10_codigo'] ? '<b>' . e($o['cie10_codigo']) . '</b> ' : '' ?><?= e($o['diagnostico'] ?: $o['cie10_descripcion']) ?></div>
  <?php endif; ?>
  <?php if ($o['indicaciones']): ?><div class="receta-dx"><span>Indicaciones</span><?= nl2br(e($o['indicaciones'])) ?></div><?php endif; ?>
  <footer class="receta-pie">
    <div class="receta-verif">
      <?php if ($urlVerif): ?><div class="qr" data-qr="<?= e($urlVerif) ?>"></div><?php endif; ?>
      <div>
        <small>Firmada electrónicamente el <?= e(fecha_hora($o['firmada_en'])) ?></small><br>
        <small>Código de verificación</small><br><b class="codigo"><?= e(codigo_txt($o['codigo_verificacion'])) ?></b>
        <?php if ($urlVerif): ?><br><small><?= e(preg_replace('#^https?://#', '', rtrim(config_get('url_publica'), '/'))) ?>/verificar.php</small><?php endif; ?>
        <?php if ($o['cuir']): ?><br><small>CUIR <?= e($o['cuir']) ?><?= $o['plataforma'] ? ' · ' . e($o['plataforma']) : '' ?></small><?php endif; ?>
      </div>
    </div>
    <div class="firma-muestra">
      <?php if ($firma && $firma['imagen']): ?><img src="<?= e($firma['imagen']) ?>" alt="Firma"><?php endif; ?>
      <div class="sello"><?= nl2br(e($firma && $firma['sello'] ? $firma['sello'] : sello_defecto($o['profesional_id']))) ?></div>
    </div>
  </footer>
  <?php if (config_get('orden_leyenda')): ?><p class="receta-leyenda"><?= e(config_get('orden_leyenda')) ?></p><?php endif; ?>
  <?php if ($o['estado'] === 'anulada'): ?><div class="sello-anulada">ANULADA</div><?php endif; ?>
</div>
<?php endfor; ?>

<?php if ($esAutor && $o['estado'] === 'firmada'): ?>
<div class="modal-fondo" id="modal-anular">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="anular">
    <h2>Anular orden</h2>
    <p>La orden queda anulada (la verificación lo muestra) y no se puede volver atrás. Si hacía falta corregir algo, después usá «Repetir orden».</p>
    <div class="campo"><label for="motivo">Motivo</label><input type="text" id="motivo" name="motivo" required maxlength="255" placeholder="Ej: faltaba una práctica"></div>
    <div class="fila"><button class="btn btn-peligro" type="submit">Anular orden</button><button class="btn" type="button" data-cerrar>Volver</button></div>
  </form>
</div>
<div class="modal-fondo" id="modal-cuir">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cuir">
    <h2>Orden electrónica</h2>
    <p>Si emitiste esta misma orden en una plataforma inscripta en el ReNaPDiS, anotá su CUIR para tenerla vinculada en la historia clínica.</p>
    <div class="campo"><label for="cuir">CUIR</label><input type="text" id="cuir" name="cuir" value="<?= e($o['cuir']) ?>" maxlength="40"></div>
    <div class="campo"><label for="plataforma">Plataforma</label><input type="text" id="plataforma" name="plataforma" value="<?= e($o['plataforma']) ?>" maxlength="80"></div>
    <div class="fila"><button class="btn btn-pri" type="submit">Guardar</button><button class="btn" type="button" data-cerrar>Volver</button></div>
  </form>
</div>
<?php endif; ?>
<script src="<?= asset_url('assets/qrcode.js') ?>"></script>
<script>
document.querySelectorAll('[data-qr]').forEach(function (el) {
  var qr = qrcode(0, 'M');
  qr.addData(el.dataset.qr);
  qr.make();
  el.innerHTML = qr.createSvgTag({ cellSize: 3, margin: 0, scalable: true });
});
</script>
<?php layout_footer(); ?>
