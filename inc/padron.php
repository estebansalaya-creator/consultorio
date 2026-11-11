<?php
/*
 * Padrón de beneficiarios de la Superintendencia de Servicios de Salud (SSS).
 *
 * La consulta pública (https://publico.sssalud.gob.ar/padron) está protegida con reCAPTCHA,
 * así que la app no la consulta sola: copia el CUIL o el DNI del paciente, abre la página
 * oficial y recepción registra lo que figura. Cada consulta queda en `padron_consultas`.
 */

const PADRON_SSS_URL = 'https://publico.sssalud.gob.ar/padron';
const PARENTESCOS = ['Titular', 'Cónyuge / conviviente', 'Hijo/a', 'Otro familiar'];

function cuil_limpio($s)
{
    return preg_replace('/\D/', '', (string)$s);
}

/** Dígito verificador de los 10 primeros números (10 = no hay dígito posible con ese prefijo) */
function cuil_digito($diez)
{
    $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $s = 0;
    for ($i = 0; $i < 10; $i++) {
        $s += (int)$diez[$i] * $pesos[$i];
    }
    $r = 11 - $s % 11;
    return $r === 11 ? 0 : $r;
}

function cuil_valido($cuil)
{
    $c = cuil_limpio($cuil);
    if (strlen($c) !== 11 || !in_array(substr($c, 0, 2), ['20', '23', '24', '27'], true)) {
        return false;
    }
    return cuil_digito(substr($c, 0, 10)) === (int)$c[10];
}

/**
 * CUIL que corresponde a un DNI y sexo según la regla de ANSES/ARCA (20 varón, 27 mujer, 23 si el dígito da 10).
 * Es el CUIL probable: hay personas con otro CUIL, por eso se muestra para confirmar.
 */
function cuil_calcular($dni, $sexo)
{
    $dni = cuil_limpio($dni);
    if ($dni === '' || strlen($dni) > 8 || !in_array($sexo, ['F', 'M'], true)) {
        return null;
    }
    $dni = str_pad($dni, 8, '0', STR_PAD_LEFT);
    $pre = $sexo === 'M' ? '20' : '27';
    $d = cuil_digito($pre . $dni);
    if ($d === 10) {
        $pre = '23';
        $d = $sexo === 'M' ? 9 : 4;
    }
    return $pre . $dni . $d;
}

/** 20-12345678-6 */
function cuil_txt($cuil)
{
    $c = cuil_limpio($cuil);
    return strlen($c) === 11 ? substr($c, 0, 2) . '-' . substr($c, 2, 8) . '-' . substr($c, 10) : (string)$cuil;
}

/** "125707 · OSDE · ORGANIZACION DE SERVICIOS DIRECTOS EMPRESARIOS" */
function padron_agente_txt(array $a)
{
    return $a['rnas'] . ' · ' . ($a['sigla'] ? $a['sigla'] . ' · ' : '') . $a['denominacion'];
}

/** Busca el agente del seguro por el código (6 números) o por sigla/denominación exacta */
function padron_agente_de_texto($txt)
{
    $txt = trim($txt);
    if (preg_match('/^\D*(\d{4,6})\b/', $txt, $m)) {
        $a = q_row('SELECT * FROM agentes_seguro WHERE rnas = ?', [str_pad($m[1], 6, '0', STR_PAD_LEFT)]);
        if ($a) {
            return $a;
        }
    }
    $filas = q_all('SELECT * FROM agentes_seguro WHERE sigla = ? OR denominacion = ? LIMIT 2', [$txt, $txt]);
    return count($filas) === 1 ? $filas[0] : null;
}

function padron_obra_social_por_rnas($rnas)
{
    return q_row('SELECT * FROM obras_sociales WHERE rnas = ? ORDER BY activo DESC, id LIMIT 1', [$rnas]);
}

function padron_consultas_de($pacienteId, $limite = 20)
{
    return q_all(
        'SELECT pc.*, u.nombre AS usuario, os.nombre AS obra_social FROM padron_consultas pc
           LEFT JOIN usuarios u ON u.id = pc.usuario_id LEFT JOIN obras_sociales os ON os.id = pc.obra_social_id
          WHERE pc.paciente_id = ? ORDER BY pc.fecha DESC, pc.id DESC LIMIT ' . (int)$limite,
        [(int)$pacienteId]
    );
}

/** Texto corto del resultado: "Figura en OSDE (titular)" / "No figura" */
function padron_resultado_txt(array $c)
{
    if ($c['resultado'] !== 'con_cobertura') {
        return 'No figura en el padrón';
    }
    $os = $c['obra_social'];
    if (!$os && $c['obra_social_txt']) {
        // "119302 · OSPRERA · OBRA SOCIAL DEL PERSONAL RURAL…" → "OSPRERA (RNAS 119302)"
        $partes = explode(' · ', $c['obra_social_txt']);
        $os = count($partes) === 3 ? $partes[1] : mb_strimwidth(end($partes), 0, 60, '…');
        $os .= $c['rnas'] ? ' (RNAS ' . $c['rnas'] . ')' : '';
    }
    $os = $os ?: 'RNAS ' . $c['rnas'];
    return 'Figura en ' . $os . ($c['parentesco'] ? ' (' . mb_strtolower($c['parentesco']) . ')' : '');
}
