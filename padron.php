<?php
/*
 * Consulta del padrón de beneficiarios de la SSS para un paciente:
 * copia el CUIL o el DNI, abre la consulta pública oficial y registra lo que figura.
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('pacientes_editar');

$id = (int)get('paciente', 0);
$sqlPac = 'SELECT pa.*, os.nombre AS obra_social, os.rnas FROM pacientes pa LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id WHERE pa.id = ?';
$p = q_row($sqlPac, [$id]);
if (!$p) {
    flash('error', 'El paciente no existe.');
    redirect(url('pacientes.php'));
}
if (!puede_ver_paciente($id)) {
    sin_permiso();
}

$err = '';
if (es_post()) {
    csrf_check();
    $cuil = cuil_limpio(post('cuil', ''));
    $resultado = (string)post('resultado', '');
    $osTxt = trim((string)post('obra_social_txt', ''));
    $parentesco = in_array(post('parentesco', ''), PARENTESCOS, true) ? post('parentesco') : '';
    $actualizar = post('actualizar') === '1';
    $nota = trim((string)post('nota', ''));
    if ($cuil !== '' && !cuil_valido($cuil)) {
        $err = 'El CUIL no es válido: revisá los 11 números.';
    } elseif (!in_array($resultado, ['con_cobertura', 'sin_cobertura'], true)) {
        $err = 'Elegí qué figura en el padrón.';
    } elseif ($resultado === 'con_cobertura' && $osTxt === '') {
        $err = 'Escribí o elegí la obra social que figura en el padrón.';
    } else {
        $rnas = null;
        $os = null;
        if ($resultado === 'con_cobertura') {
            $agente = padron_agente_de_texto($osTxt);
            if ($agente) {
                $rnas = $agente['rnas'];
                $osTxt = padron_agente_txt($agente);
            } elseif (preg_match('/\b(\d{6})\b/', $osTxt, $m)) {
                $rnas = $m[1];
            }
            $os = $rnas ? padron_obra_social_por_rnas($rnas) : null;
        } else {
            $osTxt = '';
            $parentesco = '';
        }
        $tipo = 'ok';
        $msgs = ['Consulta del padrón registrada.'];
        $actualizo = 0;
        if ($cuil !== '' && $cuil !== (string)$p['cuil']) {
            q('UPDATE pacientes SET cuil = ? WHERE id = ?', [$cuil, $id]);
        }
        if ($actualizar && $resultado === 'con_cobertura') {
            if (!$os) {
                $tipo = 'alerta';
                $msgs[] = 'No se cambió la cobertura: ninguna obra social del centro tiene ' . ($rnas ? 'el RNAS ' . $rnas : 'esa denominación')
                    . '. Se agrega en Ajustes → Obras sociales, con su código RNAS.';
            } elseif ((int)$p['obra_social_id'] !== (int)$os['id']) {
                q('UPDATE pacientes SET obra_social_id = ?, plan = NULL, nro_afiliado = NULL WHERE id = ?', [$os['id'], $id]);
                $actualizo = 1;
                $tipo = 'alerta';
                $msgs[] = 'La cobertura pasó de ' . cobertura_txt($p['obra_social']) . ' a ' . $os['nombre'] . ': cargá el plan y el nº de afiliado en Editar datos.';
            } else {
                $msgs[] = 'Coincide con la cobertura de la ficha (' . $os['nombre'] . ').';
            }
        }
        q(
            'INSERT INTO padron_consultas (paciente_id, cuil, dni, resultado, rnas, obra_social_txt, parentesco, obra_social_id, actualizo, nota, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $cuil !== '' ? $cuil : null, $p['dni'] ?: null, $resultado, $rnas, $osTxt !== '' ? mb_substr($osTxt, 0, 255) : null,
             $parentesco !== '' ? $parentesco : null, $os ? $os['id'] : null, $actualizo, $nota !== '' ? mb_substr($nota, 0, 255) : null, usuario_id()]
        );
        flash($tipo, implode(' ', $msgs));
        redirect(url('padron.php?paciente=' . $id));
    }
}

$calc = cuil_calcular($p['dni'], $p['sexo']);
$cuilPac = $p['cuil'] ?: $calc;
$esCalculado = !$p['cuil'] && $calc;
$consultas = padron_consultas_de($id);
$agentes = q_all(
    'SELECT a.*, EXISTS(SELECT 1 FROM obras_sociales o WHERE o.rnas = a.rnas AND o.activo = 1) AS del_centro
       FROM agentes_seguro a ORDER BY del_centro DESC, COALESCE(a.sigla, a.denominacion)'
);
$sinRnas = (int)q_val('SELECT COUNT(*) FROM obras_sociales WHERE activo = 1 AND (rnas IS NULL OR rnas = \'\')');
$v = function ($k, $def = '') {
    return es_post() ? (string)post($k, '') : $def;
};
$res = $v('resultado');

layout_header('Padrón SSS', ['activo' => 'pacientes.php', 'subtitulo' => nombre_paciente($p)]);
?>
<div class="grid-2-1">
  <div>
    <div class="card">
      <h2>1. Buscar en el padrón de la SSS</h2>
      <p>La consulta pública de la Superintendencia de Servicios de Salud tiene un control antirrobots, así que se hace en su página:
        tocá un botón, pegá el dato con <b>Ctrl+V</b> en el campo que corresponde y tocá <b>Buscar</b>.</p>
      <dl class="datos">
        <dt>CUIL</dt>
        <dd><?php if ($cuilPac): ?><?= e(cuil_txt($cuilPac)) ?><?php if ($esCalculado): ?><br><small class="apagado">Calculado con el DNI y el sexo: confirmalo con el paciente.</small><?php endif; ?>
            <?php else: ?><span class="apagado">Sin CUIL<?= $p['dni'] ? ' (para calcularlo falta el sexo)' : '' ?></span><?php endif; ?></dd>
        <dt>DNI</dt><dd><?= $p['dni'] ? e(dni_txt($p['dni'])) : '<span class="apagado">Sin DNI</span>' ?></dd>
      </dl>
      <div class="fila mt">
        <?php if ($cuilPac): ?><button type="button" class="btn btn-pri js-padron" data-valor="<?= e($cuilPac) ?>" data-campo="CUIL">Copiar CUIL y abrir el padrón</button><?php endif; ?>
        <?php if ($p['dni']): ?><button type="button" class="btn<?= $cuilPac ? '' : ' btn-pri' ?> js-padron" data-valor="<?= e(cuil_limpio($p['dni'])) ?>" data-campo="Número de documento">Copiar DNI y abrir el padrón</button><?php endif; ?>
        <a href="<?= e(PADRON_SSS_URL) ?>" target="padron_sss" rel="noopener">Abrir sin copiar</a>
      </div>
      <div class="aviso aviso-info mt js-copiado" hidden></div>
    </div>

    <form method="post" class="card">
      <?= csrf_field() ?>
      <h2>2. Registrar lo que figura</h2>
      <?php if ($err): ?><div class="aviso aviso-error"><?= e($err) ?></div><?php endif; ?>
      <div class="form-fila">
        <div class="campo"><label for="cuil">CUIL</label>
          <input type="text" id="cuil" name="cuil" inputmode="numeric" maxlength="13" placeholder="20-12345678-9" value="<?= e($v('cuil', $cuilPac ? cuil_txt($cuilPac) : '')) ?>">
          <small>Corregilo si en el padrón figura otro. Queda guardado en la ficha.</small></div>
      </div>
      <div class="campo">
        <label class="check"><input type="radio" name="resultado" value="con_cobertura"<?= $res === 'con_cobertura' ? ' checked' : '' ?>> Figura con obra social</label>
        <label class="check"><input type="radio" name="resultado" value="sin_cobertura"<?= $res === 'sin_cobertura' ? ' checked' : '' ?>> No figura en el padrón</label>
      </div>
      <div class="js-con"<?= $res === 'con_cobertura' ? '' : ' hidden' ?>>
        <div class="form-fila">
          <div class="campo" style="grid-column: span 2"><label for="obra_social_txt">Obra social que figura</label>
            <input type="text" id="obra_social_txt" name="obra_social_txt" list="agentes" autocomplete="off" maxlength="255" value="<?= e($v('obra_social_txt')) ?>" placeholder="Código, sigla o nombre">
            <small>Escribí el código de 6 números o la sigla y elegila de la lista. Arriba de la lista están las que trabaja el centro.</small></div>
          <div class="campo"><label for="parentesco">Parentesco</label>
            <select id="parentesco" name="parentesco"><option value="">Sin indicar</option>
              <?php foreach (PARENTESCOS as $pa): ?><option<?= $v('parentesco') === $pa ? ' selected' : '' ?>><?= e($pa) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <label class="check"><input type="checkbox" name="actualizar" value="1"<?= !es_post() || post('actualizar') === '1' ? ' checked' : '' ?>> Actualizar la cobertura del paciente con esta obra social</label>
        <p class="apagado"><small>Si cambia de obra social, se borran el plan y el nº de afiliado anteriores para cargar los nuevos.</small></p>
      </div>
      <p class="apagado js-sin"<?= $res === 'sin_cobertura' ? '' : ' hidden' ?>><small>Si no figura, no tiene una obra social nacional activa: puede ser particular o tener prepaga u obra social provincial (IOMA, IPS, etc.), que no están en este padrón. La cobertura de la ficha no se cambia.</small></p>
      <div class="campo mt"><label for="nota">Nota</label>
        <input type="text" id="nota" name="nota" maxlength="255" value="<?= e($v('nota')) ?>" placeholder="Ej.: alta 03/2024, figura como monotributista"></div>
      <div class="fila">
        <button class="btn btn-pri" type="submit">Registrar consulta</button>
        <a class="btn" href="<?= url('paciente.php?id=' . $id) ?>">Volver a la ficha</a>
      </div>
      <datalist id="agentes">
        <?php foreach ($agentes as $a): ?><option value="<?= e(padron_agente_txt($a)) ?>"><?php endforeach; ?>
      </datalist>
    </form>
  </div>

  <div>
    <div class="card">
      <h2>Cobertura en la ficha</h2>
      <p><span class="cob<?= $p['obra_social'] ? '' : ' cob-part' ?>"><?= e(cobertura_txt($p['obra_social'])) ?></span>
        <?php if ($p['obra_social']): ?><br><small><?= $p['rnas'] ? 'RNAS ' . e($p['rnas']) : 'Sin código RNAS cargado' ?><?= $p['nro_afiliado'] ? ' · Afiliado ' . e($p['nro_afiliado']) : '' ?></small><?php endif; ?></p>
      <?php if ($sinRnas && es_admin()): ?>
        <p class="apagado"><small><?= plural($sinRnas, 'obra social del centro no tiene', 'obras sociales del centro no tienen') ?> código RNAS: sin él no se puede actualizar la cobertura desde el padrón. Se carga en <a href="<?= url('abm.php?t=obras_sociales') ?>">Obras sociales</a>.</small></p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Consultas anteriores</h2>
      <?php if (!$consultas): ?>
        <p class="apagado">Todavía no se consultó el padrón para este paciente.</p>
      <?php else: ?>
      <table class="tabla">
        <?php foreach ($consultas as $c): ?>
          <tr>
            <td class="nowrap"><?= e(fecha($c['fecha'])) ?><br><small class="apagado"><?= hora($c['fecha']) ?></small></td>
            <td><?= e(padron_resultado_txt($c)) ?>
              <?php if ($c['actualizo']): ?><br><small>Se actualizó la cobertura</small><?php endif; ?>
              <?php if ($c['nota']): ?><br><small class="apagado"><?= e($c['nota']) ?></small><?php endif; ?>
              <br><small class="apagado"><?= $c['cuil'] ? 'CUIL ' . e(cuil_txt($c['cuil'])) . ' · ' : '' ?><?= e($c['usuario'] ?: '') ?></small></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function () {
  var URL_PADRON = <?= json_encode(PADRON_SSS_URL) ?>;
  var aviso = document.querySelector('.js-copiado');
  function copiarViejo(t) {
    var a = document.createElement('textarea');
    a.value = t; a.setAttribute('readonly', ''); a.style.position = 'fixed'; a.style.opacity = '0';
    document.body.appendChild(a); a.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    a.remove();
    return ok;
  }
  document.querySelectorAll('.js-padron').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-valor');
      // Se copia antes de abrir la pestaña: el portapapeles necesita que esta página tenga el foco
      var ok = copiarViejo(t);
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).catch(function () {}); ok = true; }
      window.open(URL_PADRON, 'padron_sss');
      aviso.hidden = false;
      aviso.textContent = ok ? 'Copiado ' + t + '. En la página de la SSS pegalo (Ctrl+V) en "' + b.getAttribute('data-campo') + '" y tocá Buscar.'
                             : 'No se pudo copiar: escribí ' + t + ' en "' + b.getAttribute('data-campo') + '".';
    });
  });
  var con = document.querySelector('.js-con'), sin = document.querySelector('.js-sin');
  document.querySelectorAll('input[name=resultado]').forEach(function (r) {
    r.addEventListener('change', function () {
      con.hidden = r.value !== 'con_cobertura';
      sin.hidden = r.value !== 'sin_cobertura';
      if (!con.hidden) document.getElementById('obra_social_txt').focus();
    });
  });
})();
</script>
<?php layout_footer(); ?>
