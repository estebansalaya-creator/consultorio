<?php
/*
 * Consulta de la CIE-10: búsqueda por código o por palabras, y navegación por capítulos.
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('cie10');

$q = trim((string)get('q', ''));
$cap = (string)get('cap', '');
$categoria = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)get('cat', '')));
$capitulos = cie10_capitulos();
$capInfo = null;
foreach ($capitulos as $c) {
    if ($c[0] === $cap) {
        $capInfo = $c;
    }
}
if (!$capInfo) {
    $cap = '';
}

$filas = [];
if ($categoria !== '') {
    $filas = q_all('SELECT * FROM cie10 WHERE categoria = ? ORDER BY codigo', [substr($categoria, 0, 3)]);
} elseif ($q !== '') {
    $filas = cie10_buscar($q, 300, $cap);
} elseif ($capInfo) {
    $filas = q_all('SELECT * FROM cie10 WHERE nivel = 3 AND categoria BETWEEN ? AND ? ORDER BY codigo', [$capInfo[1], $capInfo[2]]);
}
$total = (int)q_val('SELECT COUNT(*) FROM cie10');

layout_header('CIE-10', ['subtitulo' => 'Clasificación Internacional de Enfermedades']);
?>
<form class="filtros" method="get">
  <div class="campo" style="flex:1; max-width:420px"><label for="q">Buscar</label><input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Código (J02, I10) o palabras (faringitis aguda)" autofocus></div>
  <div class="campo"><label for="cap">Capítulo</label>
    <select id="cap" name="cap" onchange="this.form.submit()">
      <option value="">Todos</option>
      <?php foreach ($capitulos as $c): ?><option value="<?= e($c[0]) ?>"<?= $c[0] === $cap ? ' selected' : '' ?>><?= e($c[0] . ' · ' . $c[1] . '–' . $c[2]) ?></option><?php endforeach; ?>
    </select></div>
  <button class="btn" type="submit">Buscar</button>
</form>

<?php if (!$total): ?>
  <div class="card vacio">La tabla CIE-10 todavía no está cargada. Se carga sola al abrir la app (actualización 3 de la base).</div>
<?php elseif ($q === '' && $categoria === '' && !$capInfo): ?>
  <div class="card">
    <p class="apagado"><?= number_format($total, 0, ',', '.') ?> códigos. Elegí un capítulo o buscá por código o palabras. En la evolución de cada turno el diagnóstico se busca igual.</p>
    <table class="tabla">
      <thead><tr><th>Capítulo</th><th>Códigos</th><th>Descripción</th></tr></thead>
      <tbody>
      <?php foreach ($capitulos as $c): ?>
        <tr><td><b><?= e($c[0]) ?></b></td><td class="nowrap"><?= e($c[1] . '–' . $c[2]) ?></td>
            <td><a href="<?= url('cie10.php?cap=' . $c[0]) ?>"><?= e($c[3]) ?></a></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-cab">
      <h2><?= $categoria !== '' ? e(substr($categoria, 0, 3)) . ' · subcategorías' : ($q !== '' ? 'Resultados' : e('Capítulo ' . $capInfo[0] . ': ' . $capInfo[3])) ?></h2>
      <span class="apagado"><?= plural(count($filas), 'código', 'códigos') ?><?= count($filas) >= 300 ? ' (los primeros 300)' : '' ?></span>
    </div>
    <?php if (!$filas): ?>
      <p class="apagado">No hay códigos que coincidan.</p>
    <?php else: ?>
    <table class="tabla">
      <tbody>
      <?php foreach ($filas as $f): $c = cie10_capitulo($f['codigo']); ?>
        <tr>
          <td class="nowrap" style="width:1%"><span class="cie"><?= e($f['codigo']) ?></span></td>
          <td><?= (int)$f['nivel'] === 3 ? '<b>' . e($f['descripcion']) . '</b>' : e($f['descripcion']) ?>
            <?php if ($q !== '' && $c): ?><br><small>Capítulo <?= e($c[0]) ?></small><?php endif; ?></td>
          <td class="acciones"><?php if ((int)$f['nivel'] === 3 && $categoria === ''): ?><a class="btn btn-chico" href="<?= url('cie10.php?cat=' . $f['codigo']) ?>">Subcategorías</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <p class="mt"><a href="<?= url('cie10.php' . ($categoria !== '' && ($cc = cie10_capitulo($categoria)) ? '?cap=' . $cc[0] : '')) ?>">Volver</a></p>
  </div>
<?php endif; ?>
<?php layout_footer(); ?>
