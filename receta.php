<?php
/*
 * Receta: nueva (receta.php?paciente=N[&turno=N] o ?copiar=ID), borrador (?id=N) y receta firmada (vista para imprimir).
 * Sólo los profesionales. Se prescribe por nombre genérico (droga); la marca es opcional.
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('recetas');
if (!puede_hc()) {
    sin_permiso('Las recetas las emiten los profesionales.');
}

$id = (int)(es_post() ? post('id', 0) : get('id', 0));
$r = $id ? receta_get($id) : null;
if ($id && !$r) {
    flash('error', 'La receta no existe.');
    redirect(url('recetas.php'));
}
if ($r && !puede_ver_receta($r)) {
    sin_permiso('Sólo podés ver recetas de pacientes que atienden vos o tu especialidad.');
}
$copiar = !$r && get('copiar') ? receta_get((int)get('copiar')) : null;
if ($copiar && !puede_ver_receta($copiar)) {
    $copiar = null;
}
$pacienteId = $r ? (int)$r['paciente_id'] : ($copiar ? (int)$copiar['paciente_id'] : (int)(es_post() ? post('paciente_id') : get('paciente', 0)));
$turnoId = $r ? (int)$r['turno_id'] : (int)(es_post() ? post('turno_id') : get('turno', 0));
$pac = $pacienteId ? q_row('SELECT pa.*, os.nombre AS obra_social FROM pacientes pa LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id WHERE pa.id = ?', [$pacienteId]) : null;
if (!$pac) {
    flash('error', 'Elegí el paciente para hacer la receta.');
    redirect(url('recetas.php'));
}
if (!puede_ver_paciente($pacienteId)) {
    sin_permiso('Sólo podés hacer recetas a pacientes que atienden vos o tu especialidad.');
}
$maxItems = max(1, min(6, (int)config_get('receta_max_items', '3')));
$err = '';

/** Lee los medicamentos del formulario */
function items_post($max)
{
    $items = [];
    $campos = ['droga', 'presentacion', 'nombre_comercial', 'cantidad', 'posologia', 'clave'];
    $v = [];
    foreach ($campos as $c) {
        $v[$c] = (array)post('it_' . $c, []);
    }
    for ($i = 0; $i < $max; $i++) {
        $droga = trim((string)(isset($v['droga'][$i]) ? $v['droga'][$i] : ''));
        if ($droga === '') {
            continue;
        }
        $items[] = [
            'droga'             => mb_substr($droga, 0, 255),
            'presentacion'      => mb_substr(trim((string)$v['presentacion'][$i]), 0, 200) ?: null,
            'nombre_comercial'  => mb_substr(trim((string)$v['nombre_comercial'][$i]), 0, 150) ?: null,
            'cantidad'          => max(1, min(9, (int)$v['cantidad'][$i])),
            'posologia'         => mb_substr(trim((string)$v['posologia'][$i]), 0, 255) ?: null,
            'medicamento_clave' => (int)$v['clave'][$i] ?: null,
        ];
    }
    return $items;
}

if (es_post()) {
    csrf_check();
    $accion = post('accion');
    try {
        // ---------- Guardar borrador (y firmar) ----------
        if ($accion === 'guardar' || $accion === 'firmar') {
            if ($r && !receta_editable($r)) {
                throw new RuntimeException('Esta receta ya está firmada o anulada: no se puede modificar.');
            }
            $items = items_post($maxItems);
            $cie = trim((string)post('cie10_codigo'));
            if ($cie !== '' && !cie10_get($cie)) {
                throw new RuntimeException('El código CIE-10 «' . $cie . '» no existe. Elegilo de la lista.');
            }
            $cie = $cie !== '' ? cie10_get($cie)['codigo'] : null;
            $vals = [
                'cie10_codigo' => $cie,
                'diagnostico'  => mb_substr(trim((string)post('diagnostico')), 0, 255) ?: null,
                'indicaciones' => trim((string)post('indicaciones')) ?: null,
            ];
            $pdo = db();
            $pdo->beginTransaction();
            if ($r) {
                q('UPDATE recetas SET cie10_codigo = ?, diagnostico = ?, indicaciones = ?, actualizado = NOW() WHERE id = ?',
                  [$vals['cie10_codigo'], $vals['diagnostico'], $vals['indicaciones'], $id]);
                q('DELETE FROM receta_items WHERE receta_id = ?', [$id]);
            } else {
                $turnoOk = $turnoId && q_val('SELECT 1 FROM turnos WHERE id = ? AND paciente_id = ?', [$turnoId, $pacienteId]);
                q('INSERT INTO recetas (paciente_id, profesional_id, turno_id, fecha, obra_social_id, obra_social_nombre, plan, nro_afiliado, cie10_codigo, diagnostico, indicaciones, usuario_id)
                   VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?)',
                  [$pacienteId, mi_profesional_id(), $turnoOk ? $turnoId : null, $pac['obra_social_id'], $pac['obra_social'],
                   $pac['obra_social_id'] ? $pac['plan'] : null, $pac['obra_social_id'] ? $pac['nro_afiliado'] : null,
                   $vals['cie10_codigo'], $vals['diagnostico'], $vals['indicaciones'], usuario_id()]);
                $id = (int)$pdo->lastInsertId();
            }
            foreach ($items as $n => $it) {
                q('INSERT INTO receta_items (receta_id, orden, medicamento_clave, droga, presentacion, nombre_comercial, cantidad, posologia) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                  [$id, $n + 1, $it['medicamento_clave'], $it['droga'], $it['presentacion'], $it['nombre_comercial'], $it['cantidad'], $it['posologia']]);
            }
            $pdo->commit();
            if ($accion === 'firmar') {
                if (!$items) {
                    flash('error', 'Agregá al menos un medicamento para firmar la receta. Quedó guardada como borrador.');
                    redirect(url('receta.php?id=' . $id));
                }
                try {
                    $numero = receta_firmar($id, (string)post('pin'));
                    flash('ok', 'Receta N.º ' . sprintf('%06d', $numero) . ' firmada.');
                } catch (RuntimeException $ex) {
                    flash('error', $ex->getMessage() . ' La receta quedó guardada como borrador.');
                }
            } else {
                flash('ok', 'Borrador guardado.');
            }
            redirect(url('receta.php?id=' . $id));
        }
        if (!$r) {
            redirect(url('recetas.php'));
        }
        $esAutor = (int)$r['profesional_id'] === (int)mi_profesional_id();
        // ---------- Eliminar borrador ----------
        if ($accion === 'eliminar' && receta_editable($r)) {
            q('DELETE FROM recetas WHERE id = ?', [$id]);
            flash('ok', 'Borrador eliminado.');
            redirect(url('paciente.php?id=' . $pacienteId));
        }
        // ---------- Anular ----------
        if ($accion === 'anular') {
            if (!$esAutor || $r['estado'] !== 'firmada') {
                throw new RuntimeException('Sólo quien la firmó puede anular una receta firmada.');
            }
            $motivo = trim((string)post('motivo'));
            if ($motivo === '') {
                throw new RuntimeException('Escribí el motivo de la anulación.');
            }
            q("UPDATE recetas SET estado = 'anulada', motivo_anulacion = ?, anulada_en = NOW() WHERE id = ?", [mb_substr($motivo, 0, 255), $id]);
            flash('ok', 'Receta anulada. La verificación ahora la muestra como anulada.');
        }
        // ---------- CUIR de una plataforma registrada ----------
        if ($accion === 'cuir') {
            if (!$esAutor || $r['estado'] !== 'firmada') {
                throw new RuntimeException('Sólo quien la firmó puede registrar la CUIR.');
            }
            q('UPDATE recetas SET cuir = ?, plataforma = ? WHERE id = ?',
              [mb_substr(trim((string)post('cuir')), 0, 40) ?: null, mb_substr(trim((string)post('plataforma')), 0, 80) ?: null, $id]);
            flash('ok', 'Datos de la receta electrónica guardados.');
        }
    } catch (RuntimeException $ex) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $err = $ex->getMessage();
        if ($r) {
            flash('error', $err);
            redirect(url('receta.php?id=' . $id));
        }
    }
    if ($r) {
        redirect(url('receta.php?id=' . $id));
    }
}

$editar = !$r || receta_editable($r);
$firmaLista = firma_lista(mi_profesional_id());

// ---------- Formulario (receta nueva o borrador) ----------
if ($editar):
    $base = $r ?: $copiar;
    $items = $base ? receta_items($base['id']) : [];
    if (es_post()) {
        $items = items_post($maxItems);
    }
    // Diagnóstico: el de la receta, el de la evolución del turno o el de la receta copiada
    $cieCod = $base ? (string)$base['cie10_codigo'] : '';
    $diag = $base ? (string)$base['diagnostico'] : '';
    if (!$base && $turnoId && ($ev = evolucion_de_turno($turnoId))) {
        $cieCod = (string)$ev['cie10_codigo'];
        $diag = (string)$ev['diagnostico'];
    }
    if (es_post()) {
        $cieCod = (string)post('cie10_codigo');
        $diag = (string)post('diagnostico');
    }
    $indic = es_post() ? (string)post('indicaciones') : ($base ? (string)$base['indicaciones'] : '');
    $ultimas = q_all("SELECT r.id, r.numero, r.fecha, r.estado, (SELECT GROUP_CONCAT(i.droga SEPARATOR ', ') FROM receta_items i WHERE i.receta_id = r.id) AS drogas
                        FROM recetas r WHERE r.paciente_id = ? AND r.estado = 'firmada' ORDER BY r.fecha DESC, r.id DESC LIMIT 5", [$pacienteId]);
    layout_header($r ? 'Receta (borrador)' : 'Nueva receta', ['activo' => 'recetas.php', 'subtitulo' => nombre_paciente($pac)]);
    ?>
<?php if ($err): ?><div class="aviso aviso-error"><?= e($err) ?></div><?php endif; ?>
<?php if (!$firmaLista): ?>
  <div class="aviso aviso-alerta">Para firmar recetas primero cargá tu firma y tu PIN en <a href="<?= url('firma.php') ?>">Mi firma</a>. Mientras tanto podés guardarla como borrador.</div>
<?php endif; ?>
<form method="post" class="grid-2-1" id="form-receta" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $r ? (int)$r['id'] : 0 ?>">
  <input type="hidden" name="paciente_id" value="<?= $pacienteId ?>">
  <input type="hidden" name="turno_id" value="<?= $turnoId ?>">
  <div>
    <div class="card">
      <h2>Medicamentos</h2>
      <p class="apagado">Buscá por droga o marca: se consulta el listado oficial de medicamentos. En la receta va el <b>nombre genérico</b>; la marca es opcional.</p>
      <?php for ($i = 0; $i < $maxItems; $i++): $it = isset($items[$i]) ? $items[$i] : null; ?>
        <div class="receta-item js-item">
          <div class="receta-num"><?= $i + 1 ?></div>
          <div>
            <div class="campo"><label for="buscar-<?= $i ?>">Buscar medicamento</label><input type="search" id="buscar-<?= $i ?>" class="js-buscar-med" placeholder="Droga, principio activo o marca (mínimo 3 letras)"></div>
            <input type="hidden" name="it_clave[]" class="js-clave" value="<?= $it ? (int)$it['medicamento_clave'] : '' ?>">
            <div class="form-fila">
              <div class="campo"><label>Droga / genérico</label><input type="text" name="it_droga[]" class="js-droga" value="<?= $it ? e($it['droga']) : '' ?>" maxlength="255" placeholder="Ej: amoxicilina"></div>
              <div class="campo"><label>Presentación</label><input type="text" name="it_presentacion[]" class="js-pres" value="<?= $it ? e($it['presentacion']) : '' ?>" maxlength="200" placeholder="Ej: 500 mg comp. x 21"></div>
            </div>
            <div class="form-fila receta-fila3">
              <div class="campo"><label>Marca (opcional)</label><input type="text" name="it_nombre_comercial[]" class="js-marca" value="<?= $it ? e($it['nombre_comercial']) : '' ?>" maxlength="150"></div>
              <div class="campo"><label>Envases</label><select name="it_cantidad[]"><?php for ($c = 1; $c <= 3; $c++): ?><option value="<?= $c ?>"<?= $it && (int)$it['cantidad'] === $c ? ' selected' : '' ?>><?= $c ?></option><?php endfor; ?></select></div>
              <div class="campo"><label>Posología</label><input type="text" name="it_posologia[]" value="<?= $it ? e($it['posologia']) : '' ?>" maxlength="255" placeholder="Ej: 1 comprimido cada 8 h por 7 días"></div>
            </div>
            <small class="js-info apagado"></small>
          </div>
        </div>
      <?php endfor; ?>
    </div>
    <div class="card">
      <?= campo_cie10($cieCod, $diag) ?>
      <div class="campo"><label for="indicaciones">Indicaciones</label><textarea id="indicaciones" name="indicaciones" rows="2" placeholder="Opcional: indicaciones generales que van en la receta"><?= e($indic) ?></textarea></div>
    </div>
  </div>

  <div>
    <div class="card">
      <h2><?= e(nombre_paciente($pac)) ?></h2>
      <dl class="datos">
        <?php if ($pac['dni']): ?><dt>DNI</dt><dd><?= e(dni_txt($pac['dni'])) ?></dd><?php endif; ?>
        <?php if ($pac['fecha_nac']): ?><dt>Edad</dt><dd><?= e(edad_txt($pac['fecha_nac'])) ?></dd><?php endif; ?>
        <dt>Cobertura</dt><dd><?= e(cobertura_txt($pac['obra_social'])) ?><?= $pac['obra_social'] && $pac['nro_afiliado'] ? '<br><small>' . ($pac['plan'] ? 'Plan ' . e($pac['plan']) . ' · ' : '') . e($pac['nro_afiliado']) . '</small>' : '' ?></dd>
      </dl>
      <?php if (trim((string)$pac['antecedentes']) !== ''): ?><div class="antecedentes mt"><b>Antecedentes</b><?= e($pac['antecedentes']) ?></div><?php endif; ?>
      <?php if ($ultimas): ?>
        <h3 class="mt">Últimas recetas</h3>
        <table class="tabla">
          <?php foreach ($ultimas as $u): ?>
            <tr><td class="nowrap"><a href="<?= url('receta.php?id=' . $u['id']) ?>"><?= e(fecha($u['fecha'])) ?></a></td><td><small><?= e(droga_txt($u['drogas'])) ?></small></td></tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Firmar</h2>
      <?php if ($firmaLista): ?>
        <div class="campo"><label for="pin">Tu PIN de firma</label><input type="password" inputmode="numeric" id="pin" name="pin" maxlength="8" autocomplete="off"></div>
        <button class="btn btn-pri btn-grande btn-bloque" type="submit" name="accion" value="firmar" id="btn-firmar">Firmar receta</button>
        <p class="apagado mt"><small>Una vez firmada no se puede modificar: si hay un error, se anula y se hace otra.</small></p>
      <?php else: ?>
        <p class="apagado">Cargá tu firma en <a href="<?= url('firma.php') ?>">Mi firma</a> para poder firmar.</p>
      <?php endif; ?>
      <div class="fila mt">
        <button class="btn" type="submit" name="accion" value="guardar">Guardar borrador</button>
        <a class="btn" href="<?= $turnoId ? url('turno.php?id=' . $turnoId) : url('paciente.php?id=' . $pacienteId) ?>">Volver</a>
        <?php if ($r): ?><span class="espacio"></span><button class="btn btn-peligro" type="submit" name="accion" value="eliminar" formnovalidate data-confirm="¿Eliminar este borrador?">Eliminar</button><?php endif; ?>
      </div>
    </div>
  </div>
</form>
<script>
(function () {
  document.querySelectorAll('.js-item').forEach(function (it) {
    var buscar = it.querySelector('.js-buscar-med'), info = it.querySelector('.js-info');
    App.buscador(buscar, {
      url: function (q) { return 'api/medicamentos.php?q=' + encodeURIComponent(q); },
      lista: 'medicamentos', min: 3, espera: 450, cargando: 'Buscando en el listado oficial…',
      vacio: 'No se encontraron medicamentos. Podés escribir la droga a mano.',
      respuesta: function (r) { info.textContent = r.aviso || (r.total ? r.total + ' resultados' + (r.origen === 'online' ? ' del listado oficial' : ' guardados') : ''); },
      item: function (m) {
        return '<b>' + App.esc((m.droga || '').toUpperCase()) + '</b> · ' + App.esc(m.presentacion)
          + '<small>' + App.esc(m.nombre + ' (' + m.laboratorio + ')') + (m.precio ? ' · ' + App.money(m.precio) : '') + (m.tipo_venta ? ' · ' + App.esc(m.tipo_venta) : '') + '</small>';
      },
      elegir: function (m) {
        it.querySelector('.js-droga').value = m.droga || m.nombre;
        it.querySelector('.js-pres').value = m.presentacion;
        it.querySelector('.js-marca').value = m.nombre;
        it.querySelector('.js-clave').value = m.clave;
        info.textContent = m.nombre + ' · ' + m.laboratorio + (m.tipo_venta ? ' · ' + m.tipo_venta : '');
        buscar.value = '';
        it.querySelector('input[name="it_posologia[]"]').focus();
      }
    });
    // Si se cambia la droga a mano, deja de estar vinculada al listado
    it.querySelector('.js-droga').addEventListener('input', function () { it.querySelector('.js-clave').value = ''; });
  });
  var btn = document.getElementById('btn-firmar');
  if (btn) btn.addEventListener('click', function (ev) {
    if (!document.getElementById('pin').value) { ev.preventDefault(); alert('Escribí tu PIN de firma.'); document.getElementById('pin').focus(); }
  });
})();
</script>
<?php
    layout_footer();
    exit;
endif;

// ---------- Receta firmada o anulada: vista para imprimir ----------
$items = receta_items($id);
$esAutor = (int)$r['profesional_id'] === (int)mi_profesional_id();
$firma = firma_get($r['profesional_id']);
$copias = get('copias') === '2' ? 2 : 1;
$urlVerif = receta_url_verificacion($r);
$integra = receta_integra($r);
layout_header('Receta ' . sprintf('%06d', $r['numero']), ['activo' => 'recetas.php', 'clase' => 'pagina-receta', 'subtitulo' => nombre_paciente($r)]);

/** "1 (uno)" */
function cantidad_letras($n)
{
    $l = [1 => 'uno', 2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco', 6 => 'seis', 7 => 'siete', 8 => 'ocho', 9 => 'nueve'];
    return $n . ' (' . (isset($l[$n]) ? $l[$n] : $n) . ')';
}
?>
<?php if ($r['estado'] === 'anulada'): ?>
  <div class="aviso aviso-error no-print">Receta anulada el <?= e(fecha_hora($r['anulada_en'])) ?><?= $r['motivo_anulacion'] ? ': ' . e($r['motivo_anulacion']) : '' ?>.</div>
<?php elseif (!$integra): ?>
  <div class="aviso aviso-error no-print">El contenido guardado de esta receta no coincide con lo que se firmó: fue modificado en la base de datos.</div>
<?php endif; ?>
<div class="fila no-print" style="margin-bottom:16px">
  <?php if ($r['estado'] === 'firmada'): ?>
    <button class="btn btn-pri" type="button" onclick="window.print()">Imprimir</button>
    <a class="btn" href="<?= url('receta.php?id=' . $id . ($copias === 2 ? '' : '&copias=2')) ?>"><?= $copias === 2 ? 'Sin duplicado' : 'Con duplicado' ?></a>
  <?php endif; ?>
  <a class="btn" href="<?= url('receta.php?copiar=' . $id) ?>">Repetir receta</a>
  <a class="btn" href="<?= url('historia.php?id=' . $r['paciente_id']) ?>">Historia clínica</a>
  <span class="espacio"></span>
  <?php if ($esAutor && $r['estado'] === 'firmada'): ?>
    <button class="btn" type="button" data-abrir="modal-cuir"><?= $r['cuir'] ? 'CUIR ' . e($r['cuir']) : 'Registrar CUIR' ?></button>
    <button class="btn btn-peligro" type="button" data-abrir="modal-anular">Anular</button>
  <?php endif; ?>
</div>

<?php for ($copia = 1; $copia <= $copias; $copia++): ?>
<div class="receta-hoja<?= $r['estado'] === 'anulada' ? ' anulada' : '' ?>">
  <header class="receta-cab">
    <div>
      <div class="receta-centro"><?= e(config_get('nombre_centro')) ?></div>
      <small><?= e(config_get('direccion')) ?><?= config_get('telefono') ? ' · Tel. ' . e(config_get('telefono')) : '' ?></small>
    </div>
    <div class="der">
      <b>Receta N.º <?= sprintf('%06d', $r['numero']) ?></b><?= $copias === 2 ? '<br><small>' . ($copia === 1 ? 'ORIGINAL' : 'DUPLICADO') . '</small>' : '' ?>
      <br><small>Fecha: <?= e(fecha($r['fecha'])) ?></small>
    </div>
  </header>
  <div class="receta-pac">
    <div><span>Paciente</span><b><?= e(apellido_nombre($r)) ?></b></div>
    <div><span>DNI</span><?= e(dni_txt($r['dni'])) ?></div>
    <?php if ($r['fecha_nac']): ?><div><span>Nacimiento</span><?= e(fecha($r['fecha_nac'])) ?> (<?= e(edad_txt($r['fecha_nac'])) ?>)</div><?php endif; ?>
    <?php if ($r['sexo']): ?><div><span>Sexo</span><?= e(SEXOS[$r['sexo']]) ?></div><?php endif; ?>
    <div><span>Cobertura</span><?= e(cobertura_txt($r['obra_social_nombre'])) ?><?= $r['plan'] ? ' · Plan ' . e($r['plan']) : '' ?><?= $r['obra_social_nombre'] && $r['rnas'] ? '<br><small>RNAS ' . e($r['rnas']) . '</small>' : '' ?></div>
    <?php if ($r['nro_afiliado']): ?><div><span>N.º de afiliado</span><?= e($r['nro_afiliado']) ?></div><?php endif; ?>
  </div>
  <div class="receta-rp">
    <div class="rp">Rp/</div>
    <ol>
      <?php foreach ($items as $it): ?>
        <li>
          <b><?= e(droga_txt($it['droga'])) ?></b><?= $it['presentacion'] ? ' ' . e($it['presentacion']) : '' ?>
          <div>Cantidad: <?= e(cantidad_letras((int)$it['cantidad'])) ?> <?= (int)$it['cantidad'] === 1 ? 'envase' : 'envases' ?><?= $it['nombre_comercial'] ? ' · <small>Marca sugerida: ' . e($it['nombre_comercial']) . '</small>' : '' ?></div>
          <?php if ($it['posologia']): ?><div><small>Posología:</small> <?= e($it['posologia']) ?></div><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
  <?php if ($r['diagnostico'] || $r['cie10_codigo']): ?>
    <div class="receta-dx"><span>Diagnóstico</span><?= $r['cie10_codigo'] ? '<b>' . e($r['cie10_codigo']) . '</b> ' : '' ?><?= e($r['diagnostico'] ?: $r['cie10_descripcion']) ?></div>
  <?php endif; ?>
  <?php if ($r['indicaciones']): ?><div class="receta-dx"><span>Indicaciones</span><?= nl2br(e($r['indicaciones'])) ?></div><?php endif; ?>
  <footer class="receta-pie">
    <div class="receta-verif">
      <?php if ($urlVerif): ?><div class="qr" data-qr="<?= e($urlVerif) ?>"></div><?php endif; ?>
      <div>
        <small>Firmada electrónicamente el <?= e(fecha_hora($r['firmada_en'])) ?></small><br>
        <small>Código de verificación</small><br><b class="codigo"><?= e(codigo_txt($r['codigo_verificacion'])) ?></b>
        <?php if ($urlVerif): ?><br><small><?= e(preg_replace('#^https?://#', '', rtrim(config_get('url_publica'), '/'))) ?>/verificar.php</small><?php endif; ?>
        <?php if ($r['cuir']): ?><br><small>CUIR <?= e($r['cuir']) ?><?= $r['plataforma'] ? ' · ' . e($r['plataforma']) : '' ?></small><?php endif; ?>
      </div>
    </div>
    <div class="firma-muestra">
      <?php if ($firma && $firma['imagen']): ?><img src="<?= e($firma['imagen']) ?>" alt="Firma"><?php endif; ?>
      <div class="sello"><?= nl2br(e($firma && $firma['sello'] ? $firma['sello'] : sello_defecto($r['profesional_id']))) ?></div>
    </div>
  </footer>
  <?php if (config_get('receta_leyenda')): ?><p class="receta-leyenda"><?= e(config_get('receta_leyenda')) ?></p><?php endif; ?>
  <?php if ($r['estado'] === 'anulada'): ?><div class="sello-anulada">ANULADA</div><?php endif; ?>
</div>
<?php endfor; ?>

<?php if ($esAutor && $r['estado'] === 'firmada'): ?>
<div class="modal-fondo" id="modal-anular">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="anular">
    <h2>Anular receta</h2>
    <p>La receta queda anulada (la verificación lo muestra) y no se puede volver atrás. Si hacía falta corregir algo, después usá «Repetir receta».</p>
    <div class="campo"><label for="motivo">Motivo</label><input type="text" id="motivo" name="motivo" required maxlength="255" placeholder="Ej: dosis equivocada"></div>
    <div class="fila"><button class="btn btn-peligro" type="submit">Anular receta</button><button class="btn" type="button" data-cerrar>Volver</button></div>
  </form>
</div>
<div class="modal-fondo" id="modal-cuir">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cuir">
    <h2>Receta electrónica</h2>
    <p>Si emitiste esta misma receta en una plataforma inscripta en el ReNaPDiS, anotá su CUIR para tenerla vinculada en la historia clínica.</p>
    <div class="campo"><label for="cuir">CUIR</label><input type="text" id="cuir" name="cuir" value="<?= e($r['cuir']) ?>" maxlength="40"></div>
    <div class="campo"><label for="plataforma">Plataforma</label><input type="text" id="plataforma" name="plataforma" value="<?= e($r['plataforma']) ?>" maxlength="80"></div>
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
