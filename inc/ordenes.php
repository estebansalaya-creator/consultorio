<?php
/*
 * Órdenes de laboratorio y de estudios (imágenes, cardiología, etc.).
 * Mismo circuito que las recetas: borrador -> firmada con el PIN (número, hash y código de verificación) -> anulada.
 * Los ítems son códigos de un nomenclador (prest_nomenclador_codigo); también se pueden escribir libres.
 */

const TIPOS_ORDEN = ['laboratorio' => 'Laboratorio', 'estudios' => 'Estudios'];

function orden_titulo($tipo)
{
    return $tipo === 'laboratorio' ? 'Orden de laboratorio' : 'Orden de estudios';
}

function orden_get($id)
{
    return q_row(
        'SELECT o.*, pa.nombre, pa.apellido, pa.dni, pa.fecha_nac, pa.sexo, pa.telefono,
                p.nombre AS profesional, p.matricula, p.color, c.descripcion AS cie10_descripcion
           FROM ordenes o
           JOIN pacientes pa ON pa.id = o.paciente_id
           JOIN profesionales p ON p.id = o.profesional_id
           LEFT JOIN cie10 c ON c.codigo = o.cie10_codigo
          WHERE o.id = ?',
        [(int)$id]
    );
}

function orden_items($id)
{
    return q_all('SELECT * FROM orden_items WHERE orden_id = ? ORDER BY orden, id', [(int)$id]);
}

function orden_editable(array $o)
{
    return $o['estado'] === 'borrador' && puede_hc() && (int)$o['profesional_id'] === (int)mi_profesional_id();
}

/**
 * Nomenclador que se propone para una orden: el de laboratorio de Ajustes, o el de la obra social del paciente.
 * @return array|null fila de prest_nomenclador
 */
function orden_nomenclador_sugerido($tipo, $osId)
{
    if ($tipo === 'laboratorio') {
        $lab = nomenclador_get((int)config_get('orden_lab_nomenclador', '0'));
        if ($lab && $lab['activo']) {
            return $lab;
        }
    }
    return nomenclador_de_obra_social($osId);
}

/** Determinaciones frecuentes de laboratorio (Ajustes): códigos del nomenclador de laboratorio que existen */
function orden_lab_frecuentes()
{
    $nom = (int)config_get('orden_lab_nomenclador', '0');
    $codigos = array_values(array_filter(array_map('trim', explode(',', (string)config_get('orden_lab_frecuentes', '')))));
    if (!$nom || !$codigos) {
        return [];
    }
    $filas = q_all('SELECT id, codigo, nombre FROM prest_nomenclador_codigo WHERE id_nomenclador = ? AND activo = 1 AND codigo IN (' . implode(',', array_fill(0, count($codigos), '?')) . ')',
                   array_merge([$nom], $codigos));
    $porCodigo = [];
    foreach ($filas as $f) {
        $porCodigo[mb_strtoupper($f['codigo'])] = $f;
    }
    $res = [];
    foreach ($codigos as $c) {
        if (isset($porCodigo[mb_strtoupper($c)])) {
            $f = $porCodigo[mb_strtoupper($c)];
            $f['corto'] = nombre_corto_practica($f['nombre']);
            $res[] = $f;
        }
    }
    return $res;
}

/** Descripción de la práctica sin las notas de facturación ("VER NORMA N° 12", "EN URGENCIA SUMAR CODIGO 999", "(Mód: 5)") */
function descripcion_practica($nombre)
{
    $n = preg_replace('/\s*\(M[óo]d: *\d+\)\s*$/u', '', trim((string)$nombre));
    $n = preg_replace('/[.,]?\s*(VER NORMA|EN URGENCIA SUMAR|EN URGENCIAS SUMAR).*$/iu', '', $n);
    return rtrim($n, ' .,') ?: trim((string)$nombre);
}

/** "HEMOGRAMA. VER NORMA N° 12 ITEM A" -> "Hemograma" */
function nombre_corto_practica($nombre)
{
    $n = preg_split('/\.\s|\s-\s|\s\(|, incluye|\. EN URGENCIA|\. VER NORMA/iu', descripcion_practica($nombre))[0];
    $n = rtrim($n, ' .,');
    $n = mb_strtolower($n);
    $n = preg_replace_callback('/\b(hdl|ldl|tsh|t4l|t3|psa|kptt|vdrl|v\.d\.r\.l|hiv|igg|igm|hba1c)\b/u', function ($m) { return mb_strtoupper($m[1]); }, $n);
    if (mb_strlen($n) > 34) {
        $n = rtrim(mb_substr($n, 0, 32)) . '…';
    }
    return mb_strtoupper(mb_substr($n, 0, 1)) . mb_substr($n, 1);
}

function orden_contenido(array $o, array $items)
{
    return [
        'tipo'         => $o['tipo'],
        'numero'       => (int)$o['numero'],
        'fecha'        => $o['fecha'],
        'firmada_en'   => $o['firmada_en'],
        'paciente'     => ['id' => (int)$o['paciente_id'], 'nombre' => nombre_paciente($o), 'dni' => (string)$o['dni']],
        'profesional'  => ['id' => (int)$o['profesional_id'], 'nombre' => $o['profesional'], 'matricula' => (string)$o['matricula']],
        'cobertura'    => [(string)$o['obra_social_nombre'], (string)$o['rnas'], (string)$o['plan'], (string)$o['nro_afiliado']],
        'nomenclador'  => (string)$o['nomenclador_nombre'],
        'diagnostico'  => [(string)$o['cie10_codigo'], (string)$o['diagnostico']],
        'indicaciones' => (string)$o['indicaciones'],
        'urgente'      => (int)$o['urgente'],
        'items'        => array_map(function ($i) {
            return [(string)$i['codigo'], (string)$i['descripcion'], (int)$i['cantidad'], (string)$i['observacion']];
        }, $items),
    ];
}

function orden_hash(array $o, array $items)
{
    return hash('sha256', json_encode(orden_contenido($o, $items), JSON_UNESCAPED_UNICODE));
}

/**
 * Firma la orden con el PIN: número, cobertura vigente, hash y código de verificación.
 * @throws RuntimeException
 */
function orden_firmar($id, $pin)
{
    $o = orden_get($id);
    if (!$o || !orden_editable($o)) {
        throw new RuntimeException('Sólo el profesional que la escribió puede firmar una orden en borrador.');
    }
    $items = orden_items($id);
    if (!$items) {
        throw new RuntimeException('La orden no tiene prácticas.');
    }
    if (!$o['cie10_codigo'] && trim((string)$o['diagnostico']) === '') {
        throw new RuntimeException('Falta el diagnóstico: las obras sociales lo piden para autorizar la orden.');
    }
    firma_verificar_pin($o['profesional_id'], $pin);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $numero = (int)q_val('SELECT COALESCE(MAX(numero), 0) + 1 FROM ordenes FOR UPDATE');
        $pac = q_row('SELECT pa.*, os.nombre AS obra_social, os.rnas FROM pacientes pa LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id WHERE pa.id = ?', [$o['paciente_id']]);
        q("UPDATE ordenes SET numero = ?, estado = 'firmada', firmada_en = NOW(), fecha = CURDATE(),
                obra_social_id = ?, obra_social_nombre = ?, rnas = ?, plan = ?, nro_afiliado = ?, actualizado = NOW() WHERE id = ?",
          [$numero, $pac['obra_social_id'], $pac['obra_social'], $pac['obra_social_id'] ? $pac['rnas'] : null,
           $pac['obra_social_id'] ? $pac['plan'] : null, $pac['obra_social_id'] ? $pac['nro_afiliado'] : null, $id]);
        $o = orden_get($id);
        $hash = orden_hash($o, $items);
        q('UPDATE ordenes SET hash = ?, codigo_verificacion = ? WHERE id = ?', [$hash, receta_codigo('O' . $id, $hash), $id]);
        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $numero;
}

function orden_integra(array $o)
{
    if ($o['estado'] === 'borrador' || !$o['hash']) {
        return false;
    }
    $hash = orden_hash($o, orden_items($o['id']));
    return hash_equals($o['hash'], $hash) && hash_equals((string)$o['codigo_verificacion'], receta_codigo('O' . $o['id'], $hash));
}

function orden_por_codigo($codigo)
{
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$codigo));
    if (strlen($c) !== 10) {
        return null;
    }
    $id = q_val('SELECT id FROM ordenes WHERE codigo_verificacion = ?', [$c]);
    return $id ? orden_get($id) : null;
}

/** Resumen de las prácticas de una orden para listas: "Hemograma, Glucemia y 3 más" */
function orden_resumen($descripciones, $max = 3)
{
    $d = array_values(array_filter(array_map('trim', explode('|', (string)$descripciones))));
    if (!$d) {
        return '';
    }
    $cortas = array_map('nombre_corto_practica', array_slice($d, 0, $max));
    return implode(', ', $cortas) . (count($d) > $max ? ' y ' . (count($d) - $max) . ' más' : '');
}
