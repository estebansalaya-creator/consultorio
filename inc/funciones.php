<?php
/*
 * Funciones generales de ayuda.
 */

/** Escapa texto para HTML */
function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Formato de dinero argentino: $ 12.500 o $ 12.500,50 */
function money($n)
{
    $n = (float)$n;
    $dec = (abs($n - round($n)) > 0.004) ? 2 : 0;
    return ($n < -0.004 ? '-' : '') . '$ ' . number_format(abs($n), $dec, ',', '.');
}

/** Convierte "1.234,50" o "1234.50" a float */
function num($v)
{
    if (is_int($v) || is_float($v)) {
        return (float)$v;
    }
    $v = trim((string)$v);
    if ($v === '') {
        return 0.0;
    }
    $v = str_replace(['$', ' '], '', $v);
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
        $v = str_replace('.', '', $v); // "12.500" = doce mil quinientos
    }
    return (float)$v;
}

function fecha_hora($dt)
{
    return $dt ? date('d/m/Y H:i', strtotime($dt)) : '';
}

function fecha($dt)
{
    return $dt ? date('d/m/Y', strtotime($dt)) : '';
}

function hora($dt)
{
    return $dt ? date('H:i', strtotime($dt)) : '';
}

const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
const DIAS_CORTOS = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];
const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** "viernes 30 de octubre" (con el año si no es el actual) */
function fecha_larga($dt, $conDia = true)
{
    $t = strtotime($dt);
    $s = ($conDia ? DIAS[(int)date('N', $t)] . ' ' : '') . date('j', $t) . ' de ' . MESES[(int)date('n', $t)];
    if (date('Y', $t) !== date('Y')) {
        $s .= ' de ' . date('Y', $t);
    }
    return $s;
}

/** "hoy", "mañana", "ayer" o la fecha larga */
function fecha_relativa($dt)
{
    $d = date('Y-m-d', strtotime($dt));
    if ($d === date('Y-m-d')) {
        return 'hoy';
    }
    if ($d === date('Y-m-d', strtotime('+1 day'))) {
        return 'mañana';
    }
    if ($d === date('Y-m-d', strtotime('-1 day'))) {
        return 'ayer';
    }
    return fecha_larga($dt);
}

/** "hoy", "mañana" o "mié 11/11" (para listas angostas) */
function fecha_corta($dt)
{
    $r = fecha_relativa($dt);
    if (in_array($r, ['hoy', 'mañana', 'ayer'], true)) {
        return $r;
    }
    $t = strtotime($dt);
    return DIAS_CORTOS[(int)date('N', $t)] . ' ' . date('d/m', $t) . (date('Y', $t) !== date('Y') ? '/' . date('y', $t) : '');
}

/** "09:30" -> 570 */
function minutos($hhmm)
{
    $p = explode(':', (string)$hhmm);
    return (int)$p[0] * 60 + (isset($p[1]) ? (int)$p[1] : 0);
}

/** 570 -> "09:30" */
function hhmm($min)
{
    return sprintf('%02d:%02d', intdiv((int)$min, 60), (int)$min % 60);
}

/** Valida una fecha Y-m-d; devuelve la fecha o null */
function fecha_valida($s)
{
    $d = DateTime::createFromFormat('Y-m-d', (string)$s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

/** Valida una hora HH:MM; devuelve la hora o null */
function hora_valida($s)
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$s) ? $s : null;
}

function edad($fechaNac)
{
    if (!$fechaNac) {
        return null;
    }
    return (int)(new DateTime($fechaNac))->diff(new DateTime('today'))->y;
}

/** "45 años", "3 años y 2 meses" (menores de 6), "8 meses", "12 días" */
function edad_txt($fechaNac)
{
    if (!$fechaNac) {
        return '';
    }
    $d = (new DateTime($fechaNac))->diff(new DateTime('today'));
    if ($d->invert) {
        return '';
    }
    if ($d->y >= 6) {
        return $d->y . ' años';
    }
    if ($d->y >= 1) {
        return plural($d->y, 'año', 'años') . ($d->m ? ' y ' . plural($d->m, 'mes', 'meses') : '');
    }
    if ($d->m >= 1) {
        return plural($d->m, 'mes', 'meses');
    }
    return plural($d->d, 'día', 'días');
}

const SEXOS = ['F' => 'Femenino', 'M' => 'Masculino', 'X' => 'X / otro'];

/** DNI con puntos: 38.455.120 */
function dni_txt($dni)
{
    $d = preg_replace('/\D/', '', (string)$dni);
    return $d === '' ? (string)$dni : number_format((int)$d, 0, ',', '.');
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function flash($tipo, $msg)
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'msg' => $msg];
}

function flashes()
{
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $f;
}

function get($k, $def = null)
{
    return isset($_GET[$k]) ? $_GET[$k] : $def;
}

function post($k, $def = null)
{
    return isset($_POST[$k]) ? $_POST[$k] : $def;
}

function es_post()
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/** Responde JSON y termina */
function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lee el cuerpo JSON de una petición */
function json_in()
{
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

/** Lee un valor de la tabla configuracion (con caché por request) */
function config_get($clave, $def = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q_all('SELECT clave, valor FROM configuracion') as $r) {
            $cache[$r['clave']] = $r['valor'];
        }
    }
    return array_key_exists($clave, $cache) && $cache[$clave] !== null ? $cache[$clave] : $def;
}

function config_set($clave, $valor)
{
    q('INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [$clave, $valor]);
}

/** Opciones <option> a partir de filas id/nombre */
function opciones(array $filas, $seleccionado = null, $campo = 'nombre')
{
    $h = '';
    foreach ($filas as $f) {
        $sel = ((string)$f['id'] === (string)$seleccionado) ? ' selected' : '';
        $h .= '<option value="' . e($f['id']) . '"' . $sel . '>' . e($f[$campo]) . '</option>';
    }
    return $h;
}

/** "1 turno" / "3 turnos" */
function plural($n, $singular, $plural)
{
    return (int)$n . ' ' . ((int)$n === 1 ? $singular : $plural);
}

function medios_pago_activos()
{
    return q_all('SELECT * FROM medios_pago WHERE activo = 1 ORDER BY orden, nombre');
}

/** Nombre completo de un paciente (fila con nombre/apellido o pac_nombre/pac_apellido) */
function nombre_paciente(array $c, $pre = '')
{
    $n = trim((isset($c[$pre . 'nombre']) ? $c[$pre . 'nombre'] : '') . ' ' . (isset($c[$pre . 'apellido']) ? $c[$pre . 'apellido'] : ''));
    return $n !== '' ? $n : 'Sin nombre';
}

/** "Apellido, Nombre" para listas ordenadas por apellido */
function apellido_nombre(array $c)
{
    return trim($c['apellido']) !== '' ? $c['apellido'] . ', ' . $c['nombre'] : $c['nombre'];
}

/** Duración legible: 45 min / 1 h / 1 h 30 */
function duracion_txt($min)
{
    $min = (int)$min;
    if ($min < 60) {
        return $min . ' min';
    }
    return intdiv($min, 60) . ' h' . ($min % 60 ? ' ' . ($min % 60) : '');
}

/**
 * Link de WhatsApp con mensaje. Toma teléfonos argentinos como "11 5623-8841",
 * "011 15 5623-8841" o "+54 9 11 5623 8841" y los pasa a 5491156238841.
 */
function whatsapp_link($telefono, $mensaje = '')
{
    $d = preg_replace('/\D/', '', (string)$telefono);
    if ($d === '') {
        return null;
    }
    if (strpos($d, '54') === 0 && strlen($d) >= 12) {
        $d = substr($d, 2);
        if ($d[0] === '9') {
            $d = substr($d, 1);
        }
    }
    $d = ltrim($d, '0');
    // Saca el 15 después del código de área (2, 3 o 4 dígitos) si el número quedó largo
    if (strlen($d) === 12) {
        foreach ([2, 3, 4] as $a) {
            if (substr($d, $a, 2) === '15') {
                $d = substr($d, 0, $a) . substr($d, $a + 2);
                break;
            }
        }
    }
    if (strlen($d) !== 10) {
        return 'https://wa.me/' . preg_replace('/\D/', '', (string)$telefono) . ($mensaje !== '' ? '?text=' . rawurlencode($mensaje) : '');
    }
    return 'https://wa.me/549' . $d . ($mensaje !== '' ? '?text=' . rawurlencode($mensaje) : '');
}

/** Paleta de colores para profesionales (se elige en su ficha) */
function colores_agenda()
{
    return ['#1f6fb2', '#0e7c86', '#b8486a', '#7a4fb5', '#c06b2c', '#2e7d5b', '#3c5a99', '#a3532f', '#6b6f1f', '#8a5a12'];
}
