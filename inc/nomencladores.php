<?php
/*
 * Nomencladores de prácticas, con la misma estructura que gestion_adm:
 *   prest_nomenclador           el nomenclador (PMO, Bioquímicas, PAMI, OBSBA, NBU o uno propio)
 *   prest_nomenclador_capitulo  secciones / capítulos / subgrupos
 *   prest_nomenclador_codigo    los códigos (texto: 001 y 0001 son distintos)
 *   prest_nomenclador_vigencia  cada edición de valores, con su período
 *   prest_nomenclador_valor     el importe de cada código en cada vigencia
 * Cada prestación del centro tiene su código en cada nomenclador (prestacion_codigo) y cada
 * obra social dice con qué nomenclador se le factura. El turno guarda el código que le corresponde.
 */

function nomencladores_activos()
{
    static $n = null;
    if ($n === null) {
        $n = q_all('SELECT * FROM prest_nomenclador WHERE activo = 1 ORDER BY id');
    }
    return $n;
}

function nomenclador_get($id)
{
    return $id ? q_row('SELECT * FROM prest_nomenclador WHERE id = ?', [(int)$id]) : null;
}

/** Nomenclador con el que se factura a una obra social (el suyo o el de Ajustes) o null */
function nomenclador_de_obra_social($osId)
{
    $nom = $osId ? q_val('SELECT nomenclador_id FROM obras_sociales WHERE id = ?', [(int)$osId]) : null;
    if (!$nom) {
        $nom = (int)config_get('nomenclador_defecto', '0') ?: null;
    }
    return $nom ? q_row('SELECT * FROM prest_nomenclador WHERE id = ? AND activo = 1', [(int)$nom]) : null;
}

/**
 * Código de práctica de una prestación para una cobertura.
 * @return array|null ['codigo' => '420101', 'descripcion' => ..., 'nomenclador' => 'PMO']
 */
function practica_para($prestacionId, $osId)
{
    if (!$prestacionId) {
        return null;
    }
    $nom = nomenclador_de_obra_social($osId);
    if (!$nom) {
        return null;
    }
    $p = q_row(
        'SELECT c.codigo, c.nombre AS descripcion FROM prestacion_codigo pc JOIN prest_nomenclador_codigo c ON c.id = pc.codigo_id
          WHERE pc.prestacion_id = ? AND c.id_nomenclador = ? LIMIT 1',
        [(int)$prestacionId, (int)$nom['id']]
    );
    return $p ? $p + ['nomenclador' => $nom['nombre']] : null;
}

/** [id_nomenclador => fila de prest_nomenclador_codigo (con descripcion = nombre)] de una prestación */
function practicas_de_prestacion($prestacionId)
{
    $res = [];
    foreach (q_all('SELECT c.*, c.nombre AS descripcion FROM prestacion_codigo pc JOIN prest_nomenclador_codigo c ON c.id = pc.codigo_id WHERE pc.prestacion_id = ?', [(int)$prestacionId]) as $p) {
        $res[(int)$p['id_nomenclador']] = $p;
    }
    return $res;
}

/** SQL del importe vigente de un código a una fecha (subconsulta; el código va como c.id) */
function sql_valor_vigente($fecha = null)
{
    $f = $fecha ? db()->quote($fecha) : 'CURDATE()';
    return "(SELECT v.importe FROM prest_nomenclador_valor v JOIN prest_nomenclador_vigencia g ON g.id = v.id_vigencia
              WHERE v.id_codigo = c.id AND g.desde <= $f AND (g.hasta IS NULL OR g.hasta >= $f)
              ORDER BY g.desde DESC LIMIT 1)";
}

/** Vigencia que rige hoy para un nomenclador (o null) */
function vigencia_actual($nomId)
{
    return q_row('SELECT * FROM prest_nomenclador_vigencia WHERE id_nomenclador = ? AND desde <= CURDATE() AND (hasta IS NULL OR hasta >= CURDATE()) ORDER BY desde DESC LIMIT 1', [(int)$nomId]);
}

/**
 * Busca códigos de un nomenclador por código o por palabras de la descripción.
 * Los códigos que empiezan con lo escrito van primero.
 */
function practicas_buscar($nomId, $texto, $limite = 25)
{
    $texto = trim((string)$texto);
    $params = [(int)$nomId];
    $where = ['c.id_nomenclador = ?', 'c.activo = 1'];
    $orden = 'c.codigo';
    if ($texto !== '') {
        if (preg_match('/^[\d.\s-]+$/', $texto)) {
            $where[] = "REPLACE(REPLACE(c.codigo, '.', ''), ' ', '') LIKE ?";
            $params[] = preg_replace('/[^\d]/', '', $texto) . '%';
            $orden = 'LENGTH(c.codigo), c.codigo';
        } else {
            foreach (preg_split('/\s+/', $texto) as $p) {
                $where[] = '(c.nombre LIKE ? OR c.codigo LIKE ?)';
                array_push($params, '%' . $p . '%', $p . '%');
            }
            $primera = preg_split('/\s+/', $texto)[0];
            $orden = '(c.nombre LIKE ' . db()->quote($primera . '%') . ') DESC, LENGTH(c.nombre), c.codigo';
        }
    }
    return q_all('SELECT c.*, c.nombre AS descripcion, ' . sql_valor_vigente() . ' AS valor
                    FROM prest_nomenclador_codigo c WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orden . ' LIMIT ' . (int)$limite, $params);
}

/** Capítulo (nivel 2) por nombre; lo crea si no existe */
function capitulo_id($nomId, $nombre)
{
    $nombre = trim((string)$nombre);
    if ($nombre === '') {
        return null;
    }
    $clave = mb_substr($nombre, 0, 255);
    $id = q_val('SELECT id FROM prest_nomenclador_capitulo WHERE id_nomenclador = ? AND clave = ?', [(int)$nomId, $clave]);
    if ($id) {
        return (int)$id;
    }
    q('INSERT INTO prest_nomenclador_capitulo (id_nomenclador, nivel, clave, nombre, orden) VALUES (?, 2, ?, ?, ?)',
      [(int)$nomId, $clave, mb_substr($nombre, 0, 200), (int)q_val('SELECT COUNT(*) FROM prest_nomenclador_capitulo WHERE id_nomenclador = ?', [(int)$nomId]) + 1]);
    return (int)db()->lastInsertId();
}

/**
 * Vigencia que empieza en $desde: la crea (cerrando la anterior abierta el día antes) o devuelve la que ya existe.
 */
function vigencia_para($nomId, $desde, $documento = null, $archivo = null, $sha1 = null)
{
    $g = q_row('SELECT * FROM prest_nomenclador_vigencia WHERE id_nomenclador = ? AND desde = ?', [(int)$nomId, $desde]);
    if ($g) {
        return (int)$g['id'];
    }
    // La vigencia abierta anterior termina el día antes de la nueva
    q('UPDATE prest_nomenclador_vigencia SET hasta = DATE_SUB(?, INTERVAL 1 DAY) WHERE id_nomenclador = ? AND desde < ? AND (hasta IS NULL OR hasta >= ?)',
      [$desde, (int)$nomId, $desde, $desde]);
    $hasta = q_val('SELECT DATE_SUB(MIN(desde), INTERVAL 1 DAY) FROM prest_nomenclador_vigencia WHERE id_nomenclador = ? AND desde > ?', [(int)$nomId, $desde]);
    q('INSERT INTO prest_nomenclador_vigencia (id_nomenclador, desde, hasta, documento, archivo, sha1, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
      [(int)$nomId, $desde, $hasta ?: null, $documento, $archivo, $sha1, usuario_id()]);
    return (int)db()->lastInsertId();
}

/** Guarda el importe de un código en una vigencia (null lo borra) */
function valor_guardar($vigId, $codigoId, $importe)
{
    if ($importe === null) {
        q('DELETE FROM prest_nomenclador_valor WHERE id_vigencia = ? AND id_codigo = ?', [$vigId, $codigoId]);
    } else {
        q('INSERT INTO prest_nomenclador_valor (id_vigencia, id_codigo, importe) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE importe = VALUES(importe)',
          [$vigId, $codigoId, $importe]);
    }
    q('UPDATE prest_nomenclador_vigencia SET codigos = (SELECT COUNT(*) FROM prest_nomenclador_valor WHERE id_vigencia = ?) WHERE id = ?', [$vigId, $vigId]);
}

/**
 * Lee un CSV de prácticas (código; descripción; [valor]; [capítulo]).
 * Acepta separador ; , o tabulación, con o sin encabezado, en UTF-8 o en la codificación de Excel (Windows-1252).
 * @return array [[codigo, descripcion, valor|null, capitulo|null], ...]
 */
function leer_csv_practicas($archivo)
{
    $txt = file_get_contents($archivo);
    if ($txt === false || trim($txt) === '') {
        throw new RuntimeException('El archivo está vacío.');
    }
    $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
    if (!mb_check_encoding($txt, 'UTF-8')) {
        $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    }
    $lineas = preg_split('/\r\n|\r|\n/', $txt);
    $primera = '';
    foreach ($lineas as $l) {
        if (trim($l) !== '') {
            $primera = $l;
            break;
        }
    }
    $sep = ';';
    foreach (["\t", ';', ','] as $s) {
        if (substr_count($primera, $s) >= 1) {
            $sep = $s;
            break;
        }
    }
    $filas = [];
    foreach ($lineas as $l) {
        if (trim($l) === '') {
            continue;
        }
        $c = array_map('trim', str_getcsv($l, $sep, '"', '\\'));
        $codigo = isset($c[0]) ? $c[0] : '';
        $desc = isset($c[1]) ? $c[1] : '';
        // Encabezado (la primera fila sin un código con números)
        if (!$filas && !preg_match('/\d/', $codigo)) {
            continue;
        }
        if ($codigo === '' || $desc === '') {
            continue;
        }
        $valor = isset($c[2]) && trim($c[2]) !== '' ? num($c[2]) : null;
        $cap = isset($c[3]) && trim($c[3]) !== '' ? mb_substr($c[3], 0, 200) : null;
        $filas[] = [mb_substr($codigo, 0, 20), mb_substr($desc, 0, 500), $valor, $cap];
    }
    if (!$filas) {
        throw new RuntimeException('No se encontró ninguna práctica. El archivo tiene que tener al menos dos columnas: código y descripción.');
    }
    return $filas;
}

/**
 * Importa códigos a un nomenclador: agrega los nuevos y actualiza los existentes (por código).
 * Los valores que traiga el archivo van a la vigencia que empieza en $desde.
 * Con $desactivarResto, los que no vienen en el archivo quedan inactivos (no se borran: pueden estar en uso).
 * @return array ['nuevas' => n, 'actualizadas' => n, 'desactivadas' => n, 'valores' => n]
 */
function importar_practicas($nomId, array $filas, $desactivarResto = false, $desde = null, $archivo = null, $sha1 = null)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        @set_time_limit(300);
        $existentes = array_column(q_all('SELECT id, codigo FROM prest_nomenclador_codigo WHERE id_nomenclador = ?', [(int)$nomId]), 'id', 'codigo');
        // La búsqueda por código no distingue mayúsculas (como la clave única de la tabla)
        $porMayus = [];
        foreach ($existentes as $cod => $cid) {
            $porMayus[mb_strtoupper($cod)] = $cid;
        }
        $nuevas = $act = $valores = 0;
        $vistos = [];
        $vig = null;
        foreach ($filas as $f) {
            list($codigo, $desc, $valor, $cap) = $f;
            $capId = $cap !== null ? capitulo_id($nomId, $cap) : null;
            $k = mb_strtoupper($codigo);
            $vistos[$k] = true;
            if (isset($porMayus[$k])) {
                $cid = $porMayus[$k];
                q('UPDATE prest_nomenclador_codigo SET nombre = ?, id_capitulo = COALESCE(?, id_capitulo), activo = 1 WHERE id = ?', [$desc, $capId, $cid]);
                $act++;
            } else {
                q('INSERT INTO prest_nomenclador_codigo (id_nomenclador, id_capitulo, codigo, nombre) VALUES (?, ?, ?, ?)', [(int)$nomId, $capId, $codigo, $desc]);
                $cid = (int)$pdo->lastInsertId();
                $porMayus[$k] = $cid;
                $nuevas++;
            }
            if ($valor !== null) {
                if ($vig === null) {
                    $vig = vigencia_para($nomId, $desde ?: date('Y-m-d'), 'Importación CSV', $archivo, $sha1);
                }
                q('INSERT INTO prest_nomenclador_valor (id_vigencia, id_codigo, importe) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE importe = VALUES(importe)', [$vig, $cid, $valor]);
                $valores++;
            }
        }
        if ($vig) {
            q('UPDATE prest_nomenclador_vigencia SET codigos = (SELECT COUNT(*) FROM prest_nomenclador_valor WHERE id_vigencia = ?) WHERE id = ?', [$vig, $vig]);
        }
        $desact = 0;
        if ($desactivarResto) {
            foreach ($porMayus as $k => $cid) {
                if (!isset($vistos[$k])) {
                    $desact += q('UPDATE prest_nomenclador_codigo SET activo = 0 WHERE id = ? AND activo = 1', [$cid])->rowCount();
                }
            }
        }
        $pdo->commit();
        return ['nuevas' => $nuevas, 'actualizadas' => $act, 'desactivadas' => $desact, 'valores' => $valores];
    } catch (Exception $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}
