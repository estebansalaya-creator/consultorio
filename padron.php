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
        tocá un botón, pegá el dato con <b>Ctrl+V</b> en el campo que corresponde y tocá <b>Buscar</b>.
        Con el resultado en pantalla, apretá <b>Ctrl+A</b> y <b>Ctrl+C</b> y volvé acá.</p>
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
      <div class="campo"><label for="pegado">Resultado de la SSS</label>
        <textarea id="pegado" rows="3" placeholder="Pegá acá (Ctrl+V) lo que copiaste de la página de la SSS"></textarea>
        <small class="js-pegado-msg">Se completa solo lo de abajo. Revisalo y tocá Registrar consulta. También se puede completar a mano.</small></div>
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
      aviso.textContent = ok ? 'Copiado ' + t + '. En la página de la SSS pegalo (Ctrl+V) en "' + b.getAttribute('data-campo') + '" y tocá Buscar. Después Ctrl+A, Ctrl+C, y pegalo en "Resultado de la SSS".'
                             : 'No se pudo copiar: escribí ' + t + ' en "' + b.getAttribute('data-campo') + '".';
    });
  });
  var con = document.querySelector('.js-con'), sin = document.querySelector('.js-sin');
  function verResultado(valor, foco) {
    con.hidden = valor !== 'con_cobertura';
    sin.hidden = valor !== 'sin_cobertura';
    if (foco && !con.hidden) document.getElementById('obra_social_txt').focus();
  }
  document.querySelectorAll('input[name=resultado]').forEach(function (r) {
    r.addEventListener('change', function () { verResultado(r.value, true); });
  });

  // Resultado pegado desde la página de la SSS: se reconoce la obra social por su código RNAS
  // (validado contra el listado de agentes del seguro), el parentesco, la fecha de alta y el CUIL.
  var AGENTES = <?= json_encode(array_column(array_map(function ($a) { return ['k' => $a['rnas'], 'v' => padron_agente_txt($a)]; }, $agentes), 'v', 'k'), JSON_UNESCAPED_UNICODE) ?>;
  var PARENTESCOS = [[/TITULAR/, 'Titular'], [/C[OÓ]NYUGE|CONVIVIENTE|CONCUBIN/, 'Cónyuge / conviviente'], [/\bHIJ[OA]|\bHIJ[OA]S?\b/, 'Hijo/a'], [/FAMILIAR|PADRE|MADRE|NIET|HERMAN/, 'Otro familiar']];
  function analizar(t) {
    // Sólo la afiliación vigente: debajo la SSS muestra "Datos históricos adicionales" con obras sociales anteriores
    var UP = t.toUpperCase();
    var i = UP.indexOf('AFILIACI'), j = UP.indexOf('DATOS HIST');
    if (i < 0) i = 0;
    var vig = t.slice(i, j > i ? j : t.length), up = vig.toUpperCase(), r = {codigos: []}, m;
    var re = /(^|[^\d-])(\d{6}|\d-\d{4}-\d)(?![\d-])/g;
    while ((m = re.exec(vig))) {
      var c = m[2].replace(/-/g, '');
      if (AGENTES[c] && r.codigos.indexOf(c) < 0) r.codigos.push(c);
    }
    if (!r.codigos.length) {
      // Código que no está en el listado de agentes del seguro: se toma igual, con la denominación que muestra la SSS
      m = vig.match(/(?:^|\s)(\d{6})\s+([A-ZÁÉÍÓÚÑ][^\t\n\d]{3,}?)\s+\d{2}\/\d{2}\/\d{4}/);
      if (m) r.otro = m[1] + ' · ' + m[2].replace(/\s+/g, ' ').trim();
    }
    PARENTESCOS.some(function (p) { if (p[0].test(up)) { r.parentesco = p[1]; return true; } return false; });
    m = vig.match(/\b(20|23|24|27)-?(\d{8})-?(\d)\b/);
    if (m) r.cuil = m[1] + '-' + m[2] + '-' + m[3];
    m = vig.match(/\b(\d{2})\/(\d{2})\/(\d{4})\b/);
    if (m) r.alta = m[1] + '/' + m[2] + '/' + m[3];
    m = up.match(/RELACI[OÓ]N DE DEPENDENCIA|MONOTRIBUT[A-Z]*|JUBILAD[OA]S?|PENSIONAD[OA]S?|DESEMPLE[A-Z]*|SERVICIO DOM[A-Z]*|ADHERENTE|VOLUNTARI[OA]/);
    if (m) r.tipo = m[0].charAt(0) + m[0].slice(1).toLowerCase();
    m = vig.match(/(?:^|[^\d-])(20\d{2}-\d{2})(?![\d-])/);
    if (m) r.periodo = m[1];
    r.noFigura = /NO SE ENCONTR|NO REGISTRA|NO EXISTE|NO FIGURA|NO POSEE|SIN RESULTADO|NO HAY (DATOS|RESULTADOS|REGISTROS)|NO SE HALL|SIN COBERTURA/.test(UP);
    return r;
  }
  var pegado = document.getElementById('pegado'), msg = document.querySelector('.js-pegado-msg');
  function aplicar() {
    var t = pegado.value.trim();
    if (!t) return;
    var r = analizar(t), txt;
    if (r.codigos.length || r.otro) {
      var os = r.codigos.length ? AGENTES[r.codigos[0]] : r.otro;
      document.querySelector('input[name=resultado][value=con_cobertura]').checked = true;
      verResultado('con_cobertura');
      document.getElementById('obra_social_txt').value = os;
      if (r.parentesco) document.getElementById('parentesco').value = r.parentesco;
      var nota = [r.alta ? 'Alta ' + r.alta : '', r.tipo || '', r.periodo ? 'últ. período ' + r.periodo : ''].filter(Boolean).join(' · ');
      if (nota) document.getElementById('nota').value = nota.slice(0, 255);
      txt = 'Figura en ' + os.split(' · ').slice(0, 2).join(' · ') + (r.parentesco ? ', ' + r.parentesco.toLowerCase() : '') + (r.tipo ? ' (' + r.tipo.toLowerCase() + ')' : '') + '.';
      if (r.codigos.length > 1) txt += ' Aparecen también: ' + r.codigos.slice(1).join(', ') + '. Revisá cuál es la vigente.';
      if (!r.codigos.length) txt += ' Ese código no está en el listado de obras sociales cargado.';
    } else if (r.noFigura) {
      document.querySelector('input[name=resultado][value=sin_cobertura]').checked = true;
      verResultado('sin_cobertura');
      txt = 'No figura en el padrón.';
    } else {
      msg.textContent = 'No reconocí el resultado. ¿Copiaste la página después de tocar Buscar? Si no, completalo a mano.';
      return;
    }
    if (r.cuil) document.getElementById('cuil').value = r.cuil;
    msg.textContent = txt + ' Revisalo y tocá Registrar consulta.';
  }
  pegado.addEventListener('input', aplicar);
})();
</script>
<?php layout_footer(); ?>
