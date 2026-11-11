<?php
/*
 * Configuración de la aplicación.
 *
 * - En tu PC: copiá config.local.example.php como config.local.php y poné ahí
 *   la contraseña de MySQL. Ese archivo NO se sube a GitHub.
 * - En Railway: se usan las variables de entorno del servicio MySQL
 *   (MYSQL_URL, o MYSQLHOST / MYSQLPORT / MYSQLUSER / MYSQLPASSWORD / MYSQLDATABASE).
 *   DB_NAME permite usar una base propia (ej. "centro_medico") dentro de un MySQL que ya
 *   usa otro proyecto, para no mezclar tablas.
 *
 * No escribas contraseñas en este archivo.
 */

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

/** Lee una variable de entorno (getenv o $_SERVER/$_ENV según el servidor) */
function env_conf($nombre, $def = null)
{
    $v = getenv($nombre);
    if ($v === false || $v === '') {
        $v = isset($_SERVER[$nombre]) ? $_SERVER[$nombre] : (isset($_ENV[$nombre]) ? $_ENV[$nombre] : null);
    }
    return ($v === null || $v === '') ? $def : $v;
}

// MYSQL_URL tiene prioridad si existe: mysql://usuario:clave@host:puerto/base
$__url = env_conf('MYSQL_URL') ? parse_url(env_conf('MYSQL_URL')) : null;

defined('DB_HOST') || define('DB_HOST', $__url && isset($__url['host']) ? $__url['host'] : env_conf('MYSQLHOST', '127.0.0.1'));
defined('DB_PORT') || define('DB_PORT', (int)($__url && isset($__url['port']) ? $__url['port'] : env_conf('MYSQLPORT', 3306)));
defined('DB_NAME') || define('DB_NAME', env_conf('DB_NAME') ?: ($__url && isset($__url['path']) && trim($__url['path'], '/') !== '' ? trim($__url['path'], '/') : env_conf('MYSQLDATABASE', 'centro_medico')));
defined('DB_USER') || define('DB_USER', $__url && isset($__url['user']) ? urldecode($__url['user']) : env_conf('MYSQLUSER', 'root'));
defined('DB_PASS') || define('DB_PASS', $__url && isset($__url['pass']) ? urldecode($__url['pass']) : env_conf('MYSQLPASSWORD', ''));

// Zona horaria del centro
defined('APP_TZ') || define('APP_TZ', env_conf('APP_TZ', 'America/Argentina/Buenos_Aires'));

// Mostrar errores de PHP en pantalla (sólo para desarrollo)
defined('APP_DEBUG') || define('APP_DEBUG', env_conf('APP_DEBUG', '0') === '1');

// Nombre de la cookie de sesión (distinto del bar y de estética, para poder usar las apps en el mismo navegador)
defined('APP_SESION') || define('APP_SESION', 'centro_medico');

unset($__url);
