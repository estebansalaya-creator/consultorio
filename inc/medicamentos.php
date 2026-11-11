<?php
/*
 * Vademécum: búsqueda de medicamentos en el listado oficial de precios del Ministerio de Salud
 * (el mismo que usa https://www.argentina.gob.ar/precios-de-medicamentos) y copia local.
 *
 * Cada búsqueda online guarda o actualiza los medicamentos encontrados en la tabla `medicamentos`
 * y queda registrada en `medicamento_busquedas`. Si no hay internet, se busca en lo ya guardado.
 */

defined('CNPM_API') || define('CNPM_API', 'https://cnpm.msal.gov.ar/api');

/** Días que se usa lo guardado antes de volver a consultar online el mismo término */
const MEDICAMENTOS_DIAS_CACHE = 7;

/** POST/GET al listado oficial. Devuelve el JSON decodificado o lanza RuntimeException */
function cnpm_pedir($ruta, $datos = null)
{
    $url = rtrim(CNPM_API, '/') . '/' . ltrim($ruta, '/');
    $cuerpo = $datos !== null ? json_encode($datos, JSON_UNESCAPED_UNICODE) : null;
    $cab = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Origin: https://www.argentina.gob.ar',
        'Referer: https://www.argentina.gob.ar/precios-de-medicamentos',
        'User-Agent: Mozilla/5.0 (centro medico)',
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $cab,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) {
            throw new RuntimeException('No se pudo conectar con el listado oficial de medicamentos (' . $err . ').');
        }
    } else {
        if (!extension_loaded('openssl') && stripos($url, 'https') === 0) {
            throw new RuntimeException('PHP no tiene habilitadas las extensiones curl ni openssl: no se puede consultar el listado online.');
        }
        $ctx = stream_context_create(['http' => [
            'method'        => $cuerpo !== null ? 'POST' : 'GET',
            'header'        => implode("\r\n", $cab),
            'content'       => (string)$cuerpo,
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        $codigo = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $codigo = (int)$m[1];
        }
        if ($resp === false) {
            throw new RuntimeException('No se pudo conectar con el listado oficial de medicamentos.');
        }
    }
    if ($codigo >= 400) {
        throw new RuntimeException('El listado oficial de medicamentos respondió con error ' . $codigo . '.');
    }
    $json = json_decode($resp, true);
    if ($json === null && trim($resp) !== 'null') {
        throw new RuntimeException('El listado oficial de medicamentos devolvió una respuesta que no se entiende.');
    }
    return $json;
}

/** Fecha de vigencia del listado oficial ("09/11/2026") */
function cnpm_vigencia()
{
    $v = cnpm_pedir('vigencia');
    return is_string($v) ? trim(strip_tags($v)) : '';
}

/** Pasa una fila del listado oficial a las columnas de `medicamentos` */
function medicamento_desde_cnpm(array $x)
{
    $txt = function ($k, $largo) use ($x) {
        if (!isset($x[$k]) || $x[$k] === null) {
            return null;
        }
        $v = trim((string)$x[$k]);
        return ($v === '' || $v === 'undefined') ? null : mb_substr($v, 0, $largo);
    };
    $fecha = null;
    if (!empty($x['FECHA']) && preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $x['FECHA'], $m)) {
        $fecha = $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    $precioPami = null;
    if (isset($x['C_PAMI']) && (int)$x['C_PAMI'] !== 0) {
        // Misma cuenta que la página oficial: porcentaje que paga el afiliado según el código de descuento
        $porc = [7 => 0.6, 2 => 0.5, 8 => 0.4, 6 => 0.2, 4 => 0];
        $base = !empty($x['PRECIOPAMI']) ? (float)$x['PRECIOPAMI'] : (float)$x['PRECIO'];
        $precioPami = isset($porc[(int)$x['C_PAMI']]) ? round($base * $porc[(int)$x['C_PAMI']], 2) : null;
    }
    return [
        'clave'          => (int)$x['CLAVE'],
        'nombre'         => $txt('NOMBRE', 150) ?: 'Sin nombre',
        'droga'          => $txt('DROGA', 255),
        'presentacion'   => $txt('PRESENTACION', 200),
        'laboratorio'    => $txt('LABORATORIO', 120),
        'forma'          => $txt('FORMA', 150),
        'via'            => $txt('VIA', 80),
        'accion'         => $txt('ACCION', 150),
        'tipo_venta'     => $txt('TIPO_DE_VENTA', 120),
        'controlado'     => $txt('D_MARCA_CONTROLADO', 120),
        'unidades'       => isset($x['UNIDADES']) ? (int)$x['UNIDADES'] : null,
        'tipo_unidad'    => $txt('TIPOUNIDAD', 80),
        'potencia'       => $txt('UPOTENCIA', 60),
        'importado'      => $txt('IMPORTADO', 40),
        'precio'         => isset($x['PRECIO']) && is_numeric($x['PRECIO']) ? (float)$x['PRECIO'] : null,
        'precio_pami'    => $precioPami,
        'descuento_pami' => $txt('D_PAMI', 80),
        'troquel'        => $txt('TROQUEL', 20),
        'codigo_barras'  => $txt('C_BARRA', 20),
        'snomed'         => $txt('SNOMED', 30),
        'fecha_precio'   => $fecha,
        'baja'           => !empty($x['BAJA']) && (string)$x['BAJA'] !== '0' ? 1 : 0,
    ];
}

/**
 * Busca en el listado oficial, guarda los resultados y registra la búsqueda.
 * @return array ['filas' => [...], 'nuevos' => n]
 * @throws RuntimeException si no se pudo consultar
 */
function medicamentos_buscar_online($termino)
{
    $termino = trim($termino);
    try {
        $datos = cnpm_pedir('vademecum', ['searchdata' => $termino]);
    } catch (RuntimeException $ex) {
        medicamento_busqueda_log($termino, 'online', 0, 0, $ex->getMessage());
        throw $ex;
    }
    $filas = [];
    $nuevos = 0;
    if (is_array($datos)) {
        foreach ($datos as $x) {
            if (is_array($x) && !empty($x['CLAVE'])) {
                $filas[] = medicamento_desde_cnpm($x);
            }
        }
    }
    if ($filas) {
        // Una búsqueda puede traer cientos de presentaciones: se guardan en lotes y en una sola transacción
        // (fila por fila, en MySQL de Windows, tardaba más que el límite de 30 segundos de PHP)
        @set_time_limit(120);
        $claves = array_map(function ($m) { return (int)$m['clave']; }, $filas);
        $existentes = [];
        foreach (array_chunk($claves, 500) as $lote) {
            foreach (q_all('SELECT clave FROM medicamentos WHERE clave IN (' . implode(',', $lote) . ')') as $e) {
                $existentes[(int)$e['clave']] = true;
            }
        }
        $cols = array_keys($filas[0]);
        $marca = '(' . implode(', ', array_fill(0, count($cols), '?')) . ', NOW())';
        $actualizar = implode(', ', array_map(function ($c) { return "$c = VALUES($c)"; }, array_diff($cols, ['clave']))) . ', actualizado = NOW()';
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach (array_chunk($filas, 100) as $lote) {
                $params = [];
                foreach ($lote as $m) {
                    array_push($params, ...array_values($m));
                }
                q('INSERT INTO medicamentos (' . implode(', ', $cols) . ', actualizado) VALUES ' . implode(', ', array_fill(0, count($lote), $marca))
                  . ' ON DUPLICATE KEY UPDATE ' . $actualizar, $params);
            }
            $pdo->commit();
        } catch (Exception $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        foreach ($claves as $c) {
            if (!isset($existentes[$c])) {
                $nuevos++;
            }
        }
    }
    medicamento_busqueda_log($termino, 'online', count($filas), $nuevos);
    // Fecha de vigencia del listado: se actualiza una vez por día
    if (config_get('cnpm_vigencia_consultada', '') !== date('Y-m-d')) {
        try {
            $v = cnpm_vigencia();
            if ($v !== '') {
                config_set('cnpm_vigencia', $v);
            }
        } catch (RuntimeException $ex) {
            // no importa: la búsqueda ya funcionó
        }
        config_set('cnpm_vigencia_consultada', date('Y-m-d'));
    }
    return ['filas' => $filas, 'nuevos' => $nuevos];
}

/** Busca en los medicamentos ya guardados (nombre comercial, droga, laboratorio o troquel) */
function medicamentos_buscar_local($termino, $limite = 300, array $filtros = [])
{
    $where = ['baja = 0'];
    $params = [];
    foreach (preg_split('/\s+/', trim($termino)) as $p) {
        if ($p === '') {
            continue;
        }
        $where[] = '(nombre LIKE ? OR droga LIKE ? OR laboratorio LIKE ? OR troquel = ?)';
        array_push($params, '%' . $p . '%', '%' . $p . '%', '%' . $p . '%', $p);
    }
    if (!empty($filtros['droga'])) {
        $where[] = 'droga = ?';
        $params[] = $filtros['droga'];
    }
    if (!empty($filtros['laboratorio'])) {
        $where[] = 'laboratorio = ?';
        $params[] = $filtros['laboratorio'];
    }
    return q_all('SELECT * FROM medicamentos WHERE ' . implode(' AND ', $where) . ' ORDER BY droga, nombre, precio LIMIT ' . (int)$limite, $params);
}

/** true si el término se consultó online hace poco (alcanza con lo guardado) */
function medicamento_busqueda_reciente($termino)
{
    return (bool)q_val(
        "SELECT 1 FROM medicamento_busquedas WHERE termino = ? AND origen = 'online' AND error IS NULL AND fecha >= NOW() - INTERVAL ? DAY LIMIT 1",
        [mb_strtolower(trim($termino)), MEDICAMENTOS_DIAS_CACHE]
    );
}

function medicamento_busqueda_log($termino, $origen, $resultados, $nuevos = 0, $error = null)
{
    q('INSERT INTO medicamento_busquedas (termino, origen, resultados, nuevos, error, usuario_id, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())',
      [mb_substr(mb_strtolower(trim($termino)), 0, 100), $origen, (int)$resultados, (int)$nuevos, $error ? mb_substr($error, 0, 255) : null, usuario_id()]);
}

/**
 * Búsqueda completa: online si no se consultó hace poco (o si se pide), y si no hay conexión, lo guardado.
 * @return array ['filas' => [...], 'origen' => 'online'|'local', 'aviso' => texto o null, 'nuevos' => n]
 */
function medicamentos_buscar($termino, $forzarOnline = false)
{
    $termino = trim($termino);
    if (mb_strlen($termino) < 3) {
        return ['filas' => [], 'origen' => 'local', 'aviso' => 'Escribí al menos 3 letras.', 'nuevos' => 0];
    }
    $aviso = null;
    $nuevos = 0;
    if ($forzarOnline || !medicamento_busqueda_reciente($termino)) {
        try {
            $r = medicamentos_buscar_online($termino);
            $filas = array_values(array_filter($r['filas'], function ($m) { return !$m['baja']; }));
            usort($filas, function ($a, $b) {
                return strcmp(mb_strtolower((string)$a['droga']), mb_strtolower((string)$b['droga'])) ?: strcmp($a['nombre'], $b['nombre']) ?: ($a['precio'] <=> $b['precio']);
            });
            return ['filas' => $filas, 'origen' => 'online', 'aviso' => null, 'nuevos' => $r['nuevos']];
        } catch (RuntimeException $ex) {
            $aviso = $ex->getMessage() . ' Se muestran los medicamentos ya guardados.';
        }
    }
    $filas = medicamentos_buscar_local($termino);
    medicamento_busqueda_log($termino, 'local', count($filas));
    return ['filas' => $filas, 'origen' => 'local', 'aviso' => $aviso, 'nuevos' => $nuevos];
}

/** "amoxicilina + ácido clavulánico" en mayúsculas para la receta */
function droga_txt($droga)
{
    return mb_strtoupper(trim((string)$droga));
}
