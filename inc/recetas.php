<?php
/*
 * Firma de los profesionales y recetas.
 *
 * Firma: cada profesional carga la imagen de su firma (dibujada en pantalla o una foto escaneada),
 * el texto de su sello y un PIN. Al firmar una receta pone el PIN: la receta queda cerrada, con
 * fecha y hora, un hash SHA-256 de su contenido y un código de verificación (HMAC con la clave
 * del sistema) que permite comprobar en verificar.php que no fue alterada.
 *
 * Esto es una firma electrónica (Ley 25.506, art. 5). No es una firma digital con certificado
 * de un certificador licenciado, y el sistema no es una plataforma inscripta en el ReNaPDiS:
 * para que una receta electrónica sea válida en farmacias tiene que emitirse en una plataforma
 * registrada (Ley 27.553 y Res. 1959/2024, con su CUIR). Por eso cada receta tiene campos para
 * anotar la CUIR y la plataforma cuando se emite también allí.
 */

const FIRMA_INTENTOS = 5;      // PIN equivocados seguidos antes de bloquear
const FIRMA_BLOQUEO_MIN = 10;  // minutos de bloqueo

function firma_get($profId)
{
    return q_row('SELECT * FROM profesional_firmas WHERE profesional_id = ?', [(int)$profId]);
}

/** true si el profesional tiene firma e imagen y PIN cargados */
function firma_lista($profId)
{
    $f = firma_get($profId);
    return $f && $f['imagen'] && $f['pin_hash'];
}

/** Sello sugerido: "Dra. Laura Méndez / Clínica médica / MN 98765" */
function sello_defecto($profId)
{
    $p = q_row('SELECT * FROM profesionales WHERE id = ?', [(int)$profId]);
    if (!$p) {
        return '';
    }
    return implode("\n", array_filter([$p['nombre'], especialidades_txt($profId), $p['matricula']]));
}

/**
 * Verifica el PIN de firma. Después de FIRMA_INTENTOS errores seguidos bloquea FIRMA_BLOQUEO_MIN minutos.
 * @throws RuntimeException con el motivo
 */
function firma_verificar_pin($profId, $pin)
{
    $f = firma_get($profId);
    if (!$f || !$f['pin_hash'] || !$f['imagen']) {
        throw new RuntimeException('Todavía no cargaste tu firma y tu PIN: hacelo en «Mi firma».');
    }
    if ($f['bloqueado_hasta'] && strtotime($f['bloqueado_hasta']) > time()) {
        throw new RuntimeException('La firma está bloqueada por PIN equivocados hasta las ' . hora($f['bloqueado_hasta']) . '.');
    }
    if (!password_verify((string)$pin, $f['pin_hash'])) {
        $n = (int)$f['intentos'] + 1;
        if ($n >= FIRMA_INTENTOS) {
            q('UPDATE profesional_firmas SET intentos = 0, bloqueado_hasta = NOW() + INTERVAL ? MINUTE WHERE profesional_id = ?', [FIRMA_BLOQUEO_MIN, (int)$profId]);
            throw new RuntimeException('PIN equivocado. La firma quedó bloqueada ' . FIRMA_BLOQUEO_MIN . ' minutos.');
        }
        q('UPDATE profesional_firmas SET intentos = ? WHERE profesional_id = ?', [$n, (int)$profId]);
        throw new RuntimeException('PIN equivocado (' . (FIRMA_INTENTOS - $n) . ' intentos más antes de bloquearse).');
    }
    q('UPDATE profesional_firmas SET intentos = 0, bloqueado_hasta = NULL WHERE profesional_id = ?', [(int)$profId]);
}

/* ---------- Recetas ---------- */

const ESTADOS_RECETA = ['borrador' => 'Borrador', 'firmada' => 'Firmada', 'anulada' => 'Anulada'];

function badge_receta($e)
{
    $clase = ['borrador' => 'est-reservado', 'firmada' => 'est-confirmado', 'anulada' => 'est-ausente'];
    return '<span class="badge ' . $clase[$e] . '">' . e(ESTADOS_RECETA[$e]) . '</span>';
}

function receta_get($id)
{
    return q_row(
        'SELECT r.*, pa.nombre, pa.apellido, pa.dni, pa.fecha_nac, pa.sexo, pa.telefono,
                p.nombre AS profesional, p.matricula, p.color, os.nombre AS obra_social_actual, os.rnas, c.descripcion AS cie10_descripcion
           FROM recetas r
           JOIN pacientes pa ON pa.id = r.paciente_id
           JOIN profesionales p ON p.id = r.profesional_id
           LEFT JOIN obras_sociales os ON os.id = r.obra_social_id
           LEFT JOIN cie10 c ON c.codigo = r.cie10_codigo
          WHERE r.id = ?',
        [(int)$id]
    );
}

function receta_items($id)
{
    return q_all('SELECT * FROM receta_items WHERE receta_id = ? ORDER BY orden, id', [(int)$id]);
}

/** Puede ver la receta: los profesionales que pueden ver la historia clínica del paciente */
function puede_ver_receta(array $r)
{
    return puede_ver_hc($r['paciente_id']);
}

/** Puede editarla (borrador de su autoría) */
function receta_editable(array $r)
{
    return $r['estado'] === 'borrador' && puede_hc() && (int)$r['profesional_id'] === (int)mi_profesional_id();
}

/** Contenido que se firma (si cambia cualquier dato, el hash ya no coincide) */
function receta_contenido(array $r, array $items)
{
    return [
        'numero'       => (int)$r['numero'],
        'fecha'        => $r['fecha'],
        'firmada_en'   => $r['firmada_en'],
        'paciente'     => ['id' => (int)$r['paciente_id'], 'nombre' => nombre_paciente($r), 'dni' => (string)$r['dni']],
        'profesional'  => ['id' => (int)$r['profesional_id'], 'nombre' => $r['profesional'], 'matricula' => (string)$r['matricula']],
        'cobertura'    => [(string)$r['obra_social_nombre'], (string)$r['plan'], (string)$r['nro_afiliado']],
        'diagnostico'  => [(string)$r['cie10_codigo'], (string)$r['diagnostico']],
        'indicaciones' => (string)$r['indicaciones'],
        'items'        => array_map(function ($i) {
            return [(string)$i['droga'], (string)$i['presentacion'], (string)$i['nombre_comercial'], (int)$i['cantidad'], (string)$i['posologia']];
        }, $items),
    ];
}

function receta_hash(array $r, array $items)
{
    return hash('sha256', json_encode(receta_contenido($r, $items), JSON_UNESCAPED_UNICODE));
}

/** Código de verificación de 10 caracteres (sin letras que se confundan), mostrado como XXXXX-XXXXX */
function receta_codigo($id, $hash)
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $bytes = hash_hmac('sha256', $id . '|' . $hash, config_get('firma_clave', 'sin-clave'), true);
    $c = '';
    for ($i = 0; $i < 10; $i++) {
        $c .= $alfabeto[ord($bytes[$i]) % 32];
    }
    return $c;
}

function codigo_txt($c)
{
    return $c ? substr($c, 0, 5) . '-' . substr($c, 5) : '';
}

/** URL pública de verificación (si está configurada en Ajustes) */
function receta_url_verificacion(array $r)
{
    $base = rtrim(config_get('url_publica', ''), '/');
    return $base !== '' && $r['codigo_verificacion'] ? $base . '/verificar.php?c=' . codigo_txt($r['codigo_verificacion']) : '';
}

/**
 * Firma la receta con el PIN del profesional: le asigna número, guarda la cobertura vigente,
 * calcula el hash y el código de verificación. Después ya no se puede modificar.
 * @throws RuntimeException
 */
function receta_firmar($id, $pin)
{
    $r = receta_get($id);
    if (!$r || !receta_editable($r)) {
        throw new RuntimeException('Sólo el profesional que la escribió puede firmar una receta en borrador.');
    }
    $items = receta_items($id);
    if (!$items) {
        throw new RuntimeException('La receta no tiene medicamentos.');
    }
    firma_verificar_pin($r['profesional_id'], $pin);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $numero = (int)q_val('SELECT COALESCE(MAX(numero), 0) + 1 FROM recetas FOR UPDATE');
        $pac = q_row('SELECT pa.*, os.nombre AS obra_social FROM pacientes pa LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id WHERE pa.id = ?', [$r['paciente_id']]);
        q("UPDATE recetas SET numero = ?, estado = 'firmada', firmada_en = NOW(), fecha = CURDATE(),
                obra_social_id = ?, obra_social_nombre = ?, plan = ?, nro_afiliado = ?, actualizado = NOW() WHERE id = ?",
          [$numero, $pac['obra_social_id'], $pac['obra_social'], $pac['obra_social_id'] ? $pac['plan'] : null, $pac['obra_social_id'] ? $pac['nro_afiliado'] : null, $id]);
        $r = receta_get($id);
        $hash = receta_hash($r, $items);
        q('UPDATE recetas SET hash = ?, codigo_verificacion = ? WHERE id = ?', [$hash, receta_codigo($id, $hash), $id]);
        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $numero;
}

/** Comprueba que el contenido guardado de una receta firmada coincida con su hash y su código */
function receta_integra(array $r)
{
    if ($r['estado'] === 'borrador' || !$r['hash']) {
        return false;
    }
    $hash = receta_hash($r, receta_items($r['id']));
    return hash_equals($r['hash'], $hash) && hash_equals((string)$r['codigo_verificacion'], receta_codigo($r['id'], $hash));
}

/** Busca una receta por su código de verificación ("ABCDE-FGHJK" o "abcdefghjk") */
function receta_por_codigo($codigo)
{
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$codigo));
    if (strlen($c) !== 10) {
        return null;
    }
    $id = q_val('SELECT id FROM recetas WHERE codigo_verificacion = ?', [$c]);
    return $id ? receta_get($id) : null;
}

/** "V. G." -> iniciales para mostrar en la verificación pública */
function iniciales(array $p)
{
    $ini = '';
    foreach (preg_split('/\s+/', trim($p['nombre'] . ' ' . $p['apellido'])) as $parte) {
        if ($parte !== '') {
            $ini .= mb_strtoupper(mb_substr($parte, 0, 1)) . '. ';
        }
    }
    return trim($ini);
}
