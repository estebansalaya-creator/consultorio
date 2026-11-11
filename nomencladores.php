<?php
/*
 * Nomencladores de prácticas: alta, importación desde CSV/Excel y búsqueda de códigos.
 *  nomencladores.php        -> lista de nomencladores
 *  nomencladores.php?id=N   -> prácticas de un nomenclador
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('prestaciones');

$id = (int)get('id', 0);
$nom = $id ? nomenclador_get($id) : null;
if ($id && !$nom) {
    redirect(url('nomencladores.php'));
}

if (es_post()) {
    csrf_check();
    $accion = post('accion');
    try {
        if ($accion === 'nuevo') {
            $nombre = mb_substr(trim((string)post('nombre')), 0, 60);
            if ($nombre === '') {
                throw new RuntimeException('Poné el nombre del nomenclador.');
            }
            if (q_val('SELECT 1 FROM prest_nomenclador WHERE nombre = ?', [$nombre])) {
                throw new RuntimeException('Ya hay un nomenclador «' . $nombre . '».');
            }
            q('INSERT INTO prest_nomenclador (nombre) VALUES (?)', [$nombre]);
            $nuevoId = (int)db()->lastInsertId();
            if (!(int)config_get('nomenclador_defecto', '0')) {
                config_set('nomenclador_defecto', (string)$nuevoId);
            }
            flash('ok', 'Nomenclador creado. Ahora cargá sus códigos: a mano o importando un archivo.');
            redirect(url('nomencladores.php?id=' . $nuevoId));
        }
        if ($accion === 'defecto') {
            config_set('nomenclador_defecto', (string)(int)post('nomenclador_defecto'));
            flash('ok', 'Nomenclador por defecto guardado.');
            redirect(url('nomencladores.php'));
        }
        if (!$nom) {
            redirect(url('nomencladores.php'));
        }
        if ($accion === 'guardar') {
            $nombre = mb_substr(trim((string)post('nombre')), 0, 60);
            if ($nombre === '') {
                throw new RuntimeException('Poné el nombre del nomenclador.');
            }
            if (q_val('SELECT 1 FROM prest_nomenclador WHERE nombre = ? AND id <> ?', [$nombre, $id])) {
                throw new RuntimeException('Ya hay otro nomenclador «' . $nombre . '».');
            }
            q('UPDATE prest_nomenclador SET nombre = ?, activo = ? WHERE id = ?', [$nombre, post('activo') ? 1 : 0, $id]);
            flash('ok', 'Nomenclador guardado.');
        }
        if ($accion === 'eliminar') {
            if (q_val('SELECT COUNT(*) FROM prest_nomenclador_codigo WHERE id_nomenclador = ?', [$id])) {
                throw new RuntimeException('Tiene códigos: no se borra (pueden estar en turnos u órdenes). Destildá «Activo» para que no aparezca más.');
            }
            q('DELETE FROM prest_nomenclador_vigencia WHERE id_nomenclador = ?', [$id]);
            q('DELETE FROM prest_nomenclador_capitulo WHERE id_nomenclador = ?', [$id]);
            q('DELETE FROM prest_nomenclador WHERE id = ?', [$id]);
            if ((int)config_get('nomenclador_defecto', '0') === $id) {
                config_set('nomenclador_defecto', '');
            }
            flash('ok', 'Nomenclador eliminado.');
            redirect(url('nomencladores.php'));
        }
        if ($accion === 'importar') {
            if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Elegí el archivo CSV para importar.');
            }
            if ($_FILES['archivo']['size'] > 8 * 1024 * 1024) {
                throw new RuntimeException('El archivo es demasiado grande (máximo 8 MB).');
            }
            if (preg_match('/\.xlsx?$/i', $_FILES['archivo']['name'])) {
                throw new RuntimeException('Es un archivo de Excel: abrilo y guardalo como «CSV (delimitado por comas)» o «CSV UTF-8», y subí ese.');
            }
            $desde = fecha_valida((string)post('desde')) ?: date('Y-m-d');
            $filas = leer_csv_practicas($_FILES['archivo']['tmp_name']);
            $r = importar_practicas($id, $filas, (bool)post('desactivar'), $desde, mb_substr($_FILES['archivo']['name'], 0, 255), sha1_file($_FILES['archivo']['tmp_name']));
            flash('ok', 'Importación lista: ' . plural($r['nuevas'], 'código nuevo', 'códigos nuevos') . ', ' . plural($r['actualizadas'], 'actualizado', 'actualizados')
                . ($r['desactivadas'] ? ', ' . plural($r['desactivadas'], 'desactivado', 'desactivados') : '')
                . ($r['valores'] ? '. ' . plural($r['valores'], 'valor', 'valores') . ' con vigencia desde el ' . fecha($desde) : '') . '.');
        }
        if ($accion === 'practica') {
            $pid = (int)post('practica_id');
            $codigo = mb_substr(trim((string)post('codigo')), 0, 20);
            $desc = mb_substr(trim((string)post('descripcion')), 0, 500);
            if ($codigo === '' || $desc === '') {
                throw new RuntimeException('Completá el código y la descripción.');
            }
            $otra = q_val('SELECT id FROM prest_nomenclador_codigo WHERE id_nomenclador = ? AND codigo = ? AND id <> ?', [$id, $codigo, $pid]);
            if ($otra) {
                throw new RuntimeException('Ya hay un código ' . $codigo . ' en este nomenclador.');
            }
            $capId = capitulo_id($id, (string)post('capitulo'));
            if ($pid) {
                q('UPDATE prest_nomenclador_codigo SET codigo = ?, nombre = ?, id_capitulo = ?, activo = ? WHERE id = ? AND id_nomenclador = ?',
                  [$codigo, $desc, $capId, post('activo') ? 1 : 0, $pid, $id]);
            } else {
                q('INSERT INTO prest_nomenclador_codigo (id_nomenclador, id_capitulo, codigo, nombre) VALUES (?, ?, ?, ?)', [$id, $capId, $codigo, $desc]);
                $pid = (int)db()->lastInsertId();
            }
            // El valor se guarda en la vigencia de hoy (si no hay ninguna, se crea una que empieza hoy)
            $txtValor = trim((string)post('valor'));
            $vig = vigencia_actual($id);
            $actual = $vig ? q_val('SELECT importe FROM prest_nomenclador_valor WHERE id_vigencia = ? AND id_codigo = ?', [$vig['id'], $pid]) : null;
            $nuevo = $txtValor === '' ? null : num($txtValor);
            if ((string)$nuevo !== (string)($actual !== null ? (float)$actual : null)) {
                if ($nuevo !== null || $vig) {
                    $vigId = $vig ? (int)$vig['id'] : vigencia_para($id, date('Y-m-d'), 'Carga manual');
                    valor_guardar($vigId, $pid, $nuevo);
                }
            }
            flash('ok', 'Código ' . $codigo . ' guardado.');
        }
        if ($accion === 'borrar_vigencia') {
            $g = q_row('SELECT * FROM prest_nomenclador_vigencia WHERE id = ? AND id_nomenclador = ?', [(int)post('vigencia_id'), $id]);
            if ($g) {
                q('DELETE FROM prest_nomenclador_vigencia WHERE id = ?', [$g['id']]);
                // La anterior vuelve a quedar abierta hasta la siguiente
                $sig = q_val('SELECT MIN(desde) FROM prest_nomenclador_vigencia WHERE id_nomenclador = ? AND desde > ?', [$id, $g['desde']]);
                q('UPDATE prest_nomenclador_vigencia SET hasta = ' . ($sig ? 'DATE_SUB(' . db()->quote($sig) . ', INTERVAL 1 DAY)' : 'NULL') . '
                    WHERE id_nomenclador = ? AND desde < ? ORDER BY desde DESC LIMIT 1', [$id, $g['desde']]);
                flash('ok', 'Vigencia del ' . fecha($g['desde']) . ' eliminada con sus valores.');
            }
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect(url('nomencladores.php' . ($nom ? '?id=' . $id . (get('q') !== null ? '&q=' . urlencode((string)get('q')) : '') : '')));
}

// ---------- Lista de nomencladores ----------
if (!$nom):
    $noms = q_all(
        'SELECT n.*,
                (SELECT COUNT(*) FROM prest_nomenclador_codigo c WHERE c.id_nomenclador = n.id AND c.activo = 1) AS codigos,
                (SELECT COUNT(*) FROM prestacion_codigo pc JOIN prest_nomenclador_codigo c ON c.id = pc.codigo_id WHERE c.id_nomenclador = n.id) AS vinculadas,
                (SELECT MAX(g.desde) FROM prest_nomenclador_vigencia g WHERE g.id_nomenclador = n.id AND g.desde <= CURDATE()) AS vigencia,
                (SELECT GROUP_CONCAT(o.nombre ORDER BY o.orden SEPARATOR ", ") FROM obras_sociales o WHERE o.nomenclador_id = n.id AND o.activo = 1) AS obras
           FROM prest_nomenclador n ORDER BY n.activo DESC, n.id'
    );
    $defecto = (int)config_get('nomenclador_defecto', '0');
    $labNom = (int)config_get('orden_lab_nomenclador', '0');
    $prestActivas = (int)q_val('SELECT COUNT(*) FROM prestaciones WHERE activo = 1');
    layout_header('Nomencladores', ['activo' => 'nomencladores.php']);
    ?>
<p class="apagado">Las listas de prácticas con su código (PMO, Bioquímicas, PAMI, OBSBA, NBU o una propia). Cada prestación lleva su código en cada nomenclador y cada obra social dice con cuál se le factura; las órdenes de laboratorio y estudios se arman con estos códigos. <a href="<?= url('prestaciones.php') ?>">Volver a prestaciones</a></p>
<div class="grid-2-1">
  <div class="card">
    <?php if (!$noms): ?>
      <div class="vacio">Todavía no hay nomencladores. Creá el primero y después importá sus códigos desde un archivo CSV.</div>
    <?php else: ?>
    <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr><th>Nomenclador</th><th class="der">Códigos</th><th>Valores</th><th class="der">Prestaciones</th><th>Lo usan</th></tr></thead>
      <tbody>
      <?php foreach ($noms as $n): ?>
        <tr class="<?= $n['activo'] ? '' : 'inactivo' ?>">
          <td><a href="<?= url('nomencladores.php?id=' . $n['id']) ?>"><b><?= e($n['nombre']) ?></b></a><?= (int)$n['id'] === $defecto ? ' <span class="badge est-confirmado">Por defecto</span>' : '' ?><?= (int)$n['id'] === $labNom ? ' <span class="badge est-presente">Laboratorio</span>' : '' ?><?= $n['activo'] ? '' : ' <small>(inactivo)</small>' ?></td>
          <td class="der"><?= number_format((int)$n['codigos'], 0, ',', '.') ?></td>
          <td><small><?= $n['vigencia'] ? 'Desde el ' . e(fecha($n['vigencia'])) : '<span class="apagado">Sin valores</span>' ?></small></td>
          <td class="der"><?= (int)$n['vinculadas'] ?> de <?= $prestActivas ?></td>
          <td><small><?= $n['obras'] ? e($n['obras']) : '' ?><?= (int)$n['id'] === $defecto ? ($n['obras'] ? ' y ' : '') . 'las obras sociales sin nomenclador propio' : '' ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
  <div>
    <?php if ($noms): ?>
    <div class="card">
      <h2>Nomenclador por defecto</h2>
      <p class="apagado">El que se usa con las obras sociales que no tienen uno propio (se elige en <a href="<?= url('abm.php?t=obras_sociales') ?>">Obras sociales</a>). El de laboratorio se elige en <a href="<?= url('ajustes.php') ?>">Ajustes</a>.</p>
      <form method="post" class="fila">
        <?= csrf_field() ?><input type="hidden" name="accion" value="defecto">
        <select name="nomenclador_defecto" aria-label="Nomenclador por defecto" style="flex:1"><option value="0">Ninguno</option><?= opciones(array_filter($noms, function ($n) { return $n['activo']; }), $defecto) ?></select>
        <button class="btn" type="submit">Guardar</button>
      </form>
    </div>
    <?php endif; ?>
    <div class="card">
      <h2>Nuevo nomenclador</h2>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="nuevo">
        <div class="campo"><label for="nombre">Nombre</label><input type="text" id="nombre" name="nombre" required maxlength="60" placeholder="Ej: Nomenclador OSDE"></div>
        <button class="btn btn-pri" type="submit">Crear</button>
      </form>
    </div>
  </div>
</div>
<?php
    layout_footer();
    exit;
endif;

// ---------- Códigos de un nomenclador ----------
$q = trim((string)get('q', ''));
$cap = (int)get('cap', 0);
$verInactivas = (bool)get('inactivas');
$params = [$id];
$where = ['c.id_nomenclador = ?'];
if (!$verInactivas) {
    $where[] = 'c.activo = 1';
}
if ($cap) {
    $where[] = 'c.id_capitulo = ?';
    $params[] = $cap;
}
if ($q !== '') {
    foreach (preg_split('/\s+/', $q) as $palabra) {
        $where[] = '(c.codigo LIKE ? OR c.nombre LIKE ?)';
        array_push($params, $palabra . '%', '%' . $palabra . '%');
    }
}
$practicas = q_all(
    'SELECT c.*, k.nombre AS capitulo, ' . sql_valor_vigente() . ' AS valor,
            (SELECT GROUP_CONCAT(pr.nombre SEPARATOR ", ") FROM prestacion_codigo pc JOIN prestaciones pr ON pr.id = pc.prestacion_id WHERE pc.codigo_id = c.id) AS prestaciones
       FROM prest_nomenclador_codigo c LEFT JOIN prest_nomenclador_capitulo k ON k.id = c.id_capitulo
      WHERE ' . implode(' AND ', $where) . ' ORDER BY LENGTH(c.codigo), c.codigo LIMIT 300',
    $params
);
$total = (int)q_val('SELECT COUNT(*) FROM prest_nomenclador_codigo WHERE id_nomenclador = ? AND activo = 1', [$id]);
$capitulos = q_all('SELECT id, nombre FROM prest_nomenclador_capitulo WHERE id_nomenclador = ? ORDER BY orden, nombre', [$id]);
$vigencias = q_all('SELECT * FROM prest_nomenclador_vigencia WHERE id_nomenclador = ? ORDER BY desde DESC', [$id]);
$vigActual = vigencia_actual($id);
$obras = q_all('SELECT nombre FROM obras_sociales WHERE nomenclador_id = ? AND activo = 1 ORDER BY orden', [$id]);
$accionUrl = url('nomencladores.php?id=' . $id . ($q !== '' ? '&q=' . urlencode($q) : ''));
layout_header($nom['nombre'], ['activo' => 'nomencladores.php', 'subtitulo' => 'Nomenclador · ' . number_format($total, 0, ',', '.') . ' códigos']);
?>
<p class="apagado"><a href="<?= url('nomencladores.php') ?>">Todos los nomencladores</a><?= $obras ? ' · Lo usan: ' . e(implode(', ', array_column($obras, 'nombre'))) : '' ?><?= (int)config_get('nomenclador_defecto', '0') === $id ? ' · Es el nomenclador por defecto' : '' ?><?= (int)config_get('orden_lab_nomenclador', '0') === $id ? ' · Es el de las órdenes de laboratorio' : '' ?></p>
<div class="grid-2-1">
  <div>
    <form class="filtros" method="get">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="campo" style="flex:1; max-width:380px"><label for="q">Buscar código</label><input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Código o descripción" autofocus></div>
      <?php if ($capitulos): ?>
      <div class="campo"><label for="cap">Capítulo</label><select id="cap" name="cap" onchange="this.form.submit()" style="max-width:220px"><option value="0">Todos</option><?= opciones($capitulos, $cap) ?></select></div>
      <?php endif; ?>
      <button class="btn" type="submit">Buscar</button>
      <label class="check"><input type="checkbox" name="inactivas" value="1"<?= $verInactivas ? ' checked' : '' ?> onchange="this.form.submit()"> Ver inactivos</label>
    </form>
    <div class="card">
      <?php if (!$practicas): ?>
        <div class="vacio"><?= $q !== '' || $cap ? 'No hay códigos que coincidan.' : 'Este nomenclador todavía no tiene códigos. Importalos desde un archivo o cargalos a mano.' ?></div>
      <?php else: ?>
      <div class="tabla-scroll">
      <table class="tabla tabla-edit">
        <thead><tr><th>Código</th><th>Descripción</th><th>Capítulo</th><th class="der">Valor $</th><th>Activo</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($practicas as $p): $fid = 'p' . $p['id']; ?>
          <tr class="<?= $p['activo'] ? '' : 'inactivo' ?>">
            <td><input form="<?= $fid ?>" type="text" name="codigo" value="<?= e($p['codigo']) ?>" style="width:96px" aria-label="Código"></td>
            <td><input form="<?= $fid ?>" type="text" name="descripcion" value="<?= e($p['nombre']) ?>" aria-label="Descripción">
              <?php if ($p['prestaciones']): ?><small>Prestaciones: <?= e($p['prestaciones']) ?></small><?php endif; ?></td>
            <td><input form="<?= $fid ?>" type="text" name="capitulo" value="<?= e($p['capitulo']) ?>" list="lista-capitulos" style="width:120px" aria-label="Capítulo"></td>
            <td><input form="<?= $fid ?>" type="text" inputmode="decimal" name="valor" value="<?= $p['valor'] !== null ? e((float)$p['valor']) : '' ?>" style="width:96px; text-align:right" aria-label="Valor"></td>
            <td><input form="<?= $fid ?>" type="checkbox" name="activo" value="1"<?= $p['activo'] ? ' checked' : '' ?> aria-label="Activo"></td>
            <td class="acciones"><form id="<?= $fid ?>" method="post" action="<?= e($accionUrl) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="practica"><input type="hidden" name="practica_id" value="<?= (int)$p['id'] ?>"><?= boton_guardar_fila() ?></form></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if (count($practicas) === 300): ?><p class="apagado mt">Se muestran los primeros 300. Usá el buscador o el capítulo.</p><?php endif; ?>
      <p class="apagado mt"><small>El valor es el de la vigencia de hoy<?= $vigActual ? ' (desde el ' . e(fecha($vigActual['desde'])) . ')' : '' ?>. Si el nomenclador no tiene valores y cargás uno, se crea una vigencia que empieza hoy.</small></p>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card">
      <h2>Importar códigos</h2>
      <form method="post" enctype="multipart/form-data" action="<?= e($accionUrl) ?>">
        <?= csrf_field() ?><input type="hidden" name="accion" value="importar">
        <div class="campo"><label for="archivo">Archivo CSV</label><input type="file" id="archivo" name="archivo" accept=".csv,.txt,text/csv" required></div>
        <p class="apagado"><small>Columnas, en este orden: <b>código</b>; <b>descripción</b>; valor (opcional); capítulo (opcional). Separado por punto y coma, coma o tabulación, con o sin fila de títulos. Desde Excel: «Guardar como» → CSV.</small></p>
        <div class="campo"><label for="desde">Los valores rigen desde</label><input type="date" id="desde" name="desde" value="<?= date('Y-m-d') ?>"><small>Se guarda como una vigencia nueva; la anterior termina el día antes.</small></div>
        <div class="campo"><label class="check"><input type="checkbox" name="desactivar" value="1"> Desactivar los códigos que no vienen en el archivo</label></div>
        <button class="btn btn-pri" type="submit">Importar</button>
      </form>
      <p class="apagado mt"><small>Los códigos que ya existen se actualizan; los nuevos se agregan. Nada se borra.</small></p>
    </div>
    <div class="card">
      <h2>Vigencias de valores</h2>
      <?php if (!$vigencias): ?>
        <p class="apagado">Sin valores cargados.</p>
      <?php else: ?>
      <table class="tabla">
        <?php foreach ($vigencias as $g): ?>
          <tr>
            <td class="nowrap"><?= e(fecha($g['desde'])) ?> → <?= $g['hasta'] ? e(fecha($g['hasta'])) : 'sin fin' ?><?= $vigActual && (int)$vigActual['id'] === (int)$g['id'] ? ' <span class="badge est-confirmado">Vigente</span>' : '' ?>
              <br><small class="apagado"><?= e($g['documento'] ?: '') ?><?= $g['archivo'] ? ' · ' . e($g['archivo']) : '' ?></small></td>
            <td class="der"><?= number_format((int)$g['codigos'], 0, ',', '.') ?></td>
            <td class="acciones"><form method="post" action="<?= e($accionUrl) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="borrar_vigencia"><input type="hidden" name="vigencia_id" value="<?= (int)$g['id'] ?>"><button class="link link-peligro" type="submit" data-confirm="¿Eliminar la vigencia del <?= e(fecha($g['desde'])) ?> con sus <?= (int)$g['codigos'] ?> valores?">Eliminar</button></form></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Agregar código</h2>
      <form method="post" action="<?= e($accionUrl) ?>">
        <?= csrf_field() ?><input type="hidden" name="accion" value="practica"><input type="hidden" name="activo" value="1">
        <div class="form-fila">
          <div class="campo"><label for="n-codigo">Código</label><input type="text" id="n-codigo" name="codigo" required maxlength="20"></div>
          <div class="campo"><label for="n-valor">Valor $</label><input type="text" inputmode="decimal" id="n-valor" name="valor"></div>
        </div>
        <div class="campo"><label for="n-desc">Descripción</label><input type="text" id="n-desc" name="descripcion" required maxlength="500"></div>
        <div class="campo"><label for="n-cap">Capítulo</label><input type="text" id="n-cap" name="capitulo" maxlength="200" list="lista-capitulos"></div>
        <button class="btn" type="submit">Agregar</button>
      </form>
    </div>
    <div class="card">
      <h2>Datos del nomenclador</h2>
      <form method="post" action="<?= e($accionUrl) ?>">
        <?= csrf_field() ?><input type="hidden" name="accion" value="guardar">
        <div class="campo"><label for="nombre">Nombre</label><input type="text" id="nombre" name="nombre" value="<?= e($nom['nombre']) ?>" required maxlength="60"></div>
        <div class="campo"><label class="check"><input type="checkbox" name="activo" value="1"<?= $nom['activo'] ? ' checked' : '' ?>> Activo</label></div>
        <div class="fila">
          <button class="btn" type="submit">Guardar</button>
          <span class="espacio"></span>
          <?php if (!$total && !$verInactivas && !q_val('SELECT COUNT(*) FROM prest_nomenclador_codigo WHERE id_nomenclador = ?', [$id])): ?>
            <button class="btn btn-peligro" name="accion" value="eliminar" data-confirm="¿Eliminar el nomenclador «<?= e($nom['nombre']) ?>»?">Eliminar</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>
<datalist id="lista-capitulos"><?php foreach ($capitulos as $k): ?><option value="<?= e($k['nombre']) ?>"></option><?php endforeach; ?></datalist>
<?php layout_footer(); ?>
