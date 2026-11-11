<?php
/*
 * Vademécum: búsqueda de medicamentos por droga (principio activo), nombre comercial,
 * laboratorio o troquel en el listado oficial de precios del Ministerio de Salud.
 * Los resultados se guardan en la tabla medicamentos y cada búsqueda queda registrada.
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('vademecum');

$q = trim((string)get('q', ''));
$res = $q !== '' ? medicamentos_buscar($q, (bool)get('online')) : null;
$filas = $res ? $res['filas'] : [];

// Agrupado por principio activo
$grupos = [];
foreach ($filas as $m) {
    $k = $m['droga'] !== null && $m['droga'] !== '' ? $m['droga'] : 'Sin principio activo informado';
    $grupos[$k][] = $m;
}
$drogas = array_keys($grupos);
$labs = array_values(array_unique(array_filter(array_column($filas, 'laboratorio'))));
sort($labs);

$guardados = (int)q_val('SELECT COUNT(*) FROM medicamentos');
$busquedas = q_all('SELECT b.*, u.nombre AS usuario FROM medicamento_busquedas b LEFT JOIN usuarios u ON u.id = b.usuario_id ORDER BY b.id DESC LIMIT 15');
$vigencia = config_get('cnpm_vigencia', '');

layout_header('Vademécum', ['subtitulo' => 'Listado oficial de precios de medicamentos']);
?>
<form class="filtros" method="get">
  <div class="campo" style="flex:1; max-width:460px"><label for="q">Buscar medicamento</label>
    <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Droga o principio activo (ibuprofeno), marca, laboratorio o troquel" minlength="3" autofocus required></div>
  <button class="btn btn-pri" type="submit">Buscar</button>
  <label class="check"><input type="checkbox" name="online" value="1"> Volver a consultar el listado oficial</label>
</form>

<div class="grid-2-1">
  <div>
    <?php if ($res): ?>
      <?php if ($res['aviso']): ?><div class="aviso aviso-alerta"><?= e($res['aviso']) ?></div><?php endif; ?>
      <div class="card">
        <div class="card-cab">
          <h2><?= plural(count($filas), 'medicamento', 'medicamentos') ?> <small class="apagado" style="font-weight:400">· <?= plural(count($grupos), 'principio activo', 'principios activos') ?></small></h2>
          <span class="apagado"><?= $res['origen'] === 'online' ? 'Consultado recién en el listado oficial' . ($res['nuevos'] ? ' (' . plural($res['nuevos'], 'nuevo guardado', 'nuevos guardados') . ')' : '') : 'De los medicamentos guardados' ?></span>
        </div>
        <?php if (!$filas): ?>
          <p class="apagado">No se encontraron medicamentos con «<?= e($q) ?>».</p>
        <?php else: ?>
        <div class="fila" style="margin-bottom:12px">
          <?php if (count($drogas) > 1): ?>
          <select id="f-droga" aria-label="Filtrar por principio activo" style="width:auto"><option value="">Todos los principios activos</option>
            <?php foreach ($drogas as $d): ?><option value="<?= e($d) ?>"><?= e(droga_txt($d)) ?> (<?= count($grupos[$d]) ?>)</option><?php endforeach; ?></select>
          <?php endif; ?>
          <?php if (count($labs) > 1): ?>
          <select id="f-lab" aria-label="Filtrar por laboratorio" style="width:auto"><option value="">Todos los laboratorios</option>
            <?php foreach ($labs as $l): ?><option value="<?= e($l) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
          <?php endif; ?>
        </div>
        <div class="tabla-scroll">
        <table class="tabla" id="tabla-med">
          <thead><tr><th>Medicamento</th><th>Laboratorio</th><th>Venta</th><th class="der">Precio</th><th class="der">Afiliado PAMI</th><th></th></tr></thead>
          <?php foreach ($grupos as $droga => $ms):
              $precios = array_filter(array_column($ms, 'precio'), function ($p) { return $p !== null; }); ?>
            <tbody data-droga="<?= e($droga) ?>">
              <tr class="sep"><td colspan="6"><?= e(droga_txt($droga)) ?> <span style="font-weight:400">· <?= plural(count($ms), 'presentación', 'presentaciones') ?><?= $precios ? ', desde ' . money(min($precios)) : '' ?></span></td></tr>
              <?php foreach ($ms as $m): ?>
                <tr data-lab="<?= e($m['laboratorio']) ?>">
                  <td><b><?= e($m['nombre']) ?></b><br><small><?= e($m['presentacion']) ?></small></td>
                  <td><small><?= e($m['laboratorio']) ?></small></td>
                  <td><small><?= e($m['tipo_venta']) ?></small></td>
                  <td class="der num nowrap"><?= $m['precio'] !== null ? money($m['precio']) : '' ?></td>
                  <td class="der nowrap"><?= $m['precio_pami'] !== null ? money($m['precio_pami']) : '' ?></td>
                  <td class="acciones"><button class="btn btn-chico" type="button" data-abrir="med-<?= (int)$m['clave'] ?>">Ver</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          <?php endforeach; ?>
        </table>
        </div>
        <?php foreach ($filas as $m): ?>
          <div class="modal-fondo" id="med-<?= (int)$m['clave'] ?>">
            <div class="modal">
              <h2><?= e($m['nombre']) ?></h2>
              <dl class="datos">
                <dt>Principio activo</dt><dd><?= e(droga_txt($m['droga'])) ?></dd>
                <dt>Presentación</dt><dd><?= e($m['presentacion']) ?></dd>
                <dt>Laboratorio</dt><dd><?= e($m['laboratorio']) ?></dd>
                <?php if ($m['forma']): ?><dt>Forma</dt><dd><?= e($m['forma']) ?></dd><?php endif; ?>
                <?php if ($m['via']): ?><dt>Vía</dt><dd><?= e($m['via']) ?></dd><?php endif; ?>
                <?php if ($m['accion']): ?><dt>Acción</dt><dd><?= e($m['accion']) ?></dd><?php endif; ?>
                <dt>Venta</dt><dd><?= e($m['tipo_venta']) ?><?= $m['controlado'] ? ' <small>(' . e($m['controlado']) . ')</small>' : '' ?></dd>
                <?php if ($m['importado']): ?><dt>Origen</dt><dd><?= e($m['importado']) ?></dd><?php endif; ?>
                <dt>Precio</dt><dd><?= $m['precio'] !== null ? money($m['precio']) : '—' ?><?= $m['fecha_precio'] ? ' <small>(al ' . e(fecha($m['fecha_precio'])) . ')</small>' : '' ?></dd>
                <dt>PAMI</dt><dd><?= e($m['descuento_pami']) ?><?= $m['precio_pami'] !== null ? ': paga ' . money($m['precio_pami']) : '' ?></dd>
                <?php if ($m['troquel']): ?><dt>Troquel</dt><dd><?= e($m['troquel']) ?></dd><?php endif; ?>
                <?php if ($m['codigo_barras']): ?><dt>Código de barras</dt><dd><?= e($m['codigo_barras']) ?></dd><?php endif; ?>
                <?php if ($m['snomed']): ?><dt>SNOMED CT</dt><dd><?= e($m['snomed']) ?></dd><?php endif; ?>
              </dl>
              <div class="fila mt"><button class="btn" type="button" data-cerrar>Cerrar</button></div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="card">
        <p>Buscá por <b>droga o principio activo</b> (ej. <i>enalapril</i>), nombre comercial, laboratorio o troquel. La búsqueda consulta el <a href="https://www.argentina.gob.ar/precios-de-medicamentos" target="_blank" rel="noopener">listado oficial de precios de medicamentos</a> del Ministerio de Salud y guarda los resultados: si después no hay internet, se busca en lo guardado.</p>
        <p class="apagado">Los resultados aparecen agrupados por principio activo, con el precio de venta en farmacia y lo que paga el afiliado de PAMI.</p>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <h2>Listado guardado</h2>
      <dl class="datos">
        <dt>Medicamentos</dt><dd><?= number_format($guardados, 0, ',', '.') ?></dd>
        <?php if ($vigencia): ?><dt>Vigencia</dt><dd><?= e($vigencia) ?></dd><?php endif; ?>
      </dl>
      <p class="apagado mt"><small>Un término buscado en los últimos <?= MEDICAMENTOS_DIAS_CACHE ?> días se responde con lo guardado; tildá «Volver a consultar» para traer los precios del día.</small></p>
    </div>
    <div class="card">
      <h2>Últimas búsquedas</h2>
      <?php if (!$busquedas): ?>
        <p class="apagado">Todavía no hay búsquedas.</p>
      <?php else: ?>
      <table class="tabla">
        <?php foreach ($busquedas as $b): ?>
          <tr>
            <td><a href="<?= url('medicamentos.php?q=' . urlencode($b['termino'])) ?>"><?= e($b['termino']) ?></a><br>
              <small><?= e(fecha_hora($b['fecha'])) ?><?= $b['usuario'] ? ' · ' . e($b['usuario']) : '' ?></small></td>
            <td class="der"><small><?= $b['error'] ? '<span style="color:var(--coral)" title="' . e($b['error']) . '">sin conexión</span>' : ($b['origen'] === 'online' ? 'online' : 'guardados') ?><br><?= (int)$b['resultados'] ?><?= $b['nuevos'] ? ' (' . (int)$b['nuevos'] . ' nuevos)' : '' ?></small></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function () {
  var fd = document.getElementById('f-droga'), fl = document.getElementById('f-lab');
  function filtrar() {
    var d = fd ? fd.value : '', l = fl ? fl.value : '';
    document.querySelectorAll('#tabla-med tbody').forEach(function (tb) {
      var verGrupo = !d || tb.dataset.droga === d, alguno = false;
      tb.querySelectorAll('tr[data-lab]').forEach(function (tr) {
        var ver = verGrupo && (!l || tr.dataset.lab === l);
        tr.hidden = !ver; if (ver) alguno = true;
      });
      tb.hidden = !alguno;
    });
  }
  if (fd) fd.addEventListener('change', filtrar);
  if (fl) fl.addEventListener('change', filtrar);
})();
</script>
<?php layout_footer(); ?>
