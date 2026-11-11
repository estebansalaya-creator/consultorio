<?php
/*
 * Traduce los errores de conexión a MySQL a una explicación con la solución.
 * Lo usan db.php, instalar.php y diagnostico.php.
 */
/** Tablas que crea sql/database.sql (versión 1) */
function tablas_app()
{
    return ['configuracion', 'especialidades', 'consultorios', 'obras_sociales', 'profesionales', 'profesional_especialidades',
            'profesional_horarios', 'profesional_obras_sociales', 'usuarios', 'prestaciones', 'prestacion_profesional', 'pacientes',
            'medios_pago', 'cajas', 'caja_movimientos', 'cobros', 'cobro_items', 'cobro_pagos', 'turnos', 'evoluciones'];
}

/**
 * Estado de la base configurada:
 *  instalada -> tiene todas las tablas de la app
 *  parcial   -> tiene sólo algunas tablas de la app y ninguna ajena (instalación en curso o cortada)
 *  ajena     -> tiene tablas de otro sistema (no se instala encima, para no mezclar)
 */
function estado_base(PDO $pdo, $base)
{
    $existe = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ' . $pdo->quote($base))->fetchColumn() > 0;
    $tablas = $existe ? array_map('strtolower', $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = ' . $pdo->quote($base))->fetchAll(PDO::FETCH_COLUMN)) : [];
    $faltan = array_values(array_diff(tablas_app(), $tablas));
    $ajenas = array_values(array_diff($tablas, tablas_app()));
    $instalada = !$faltan;
    return [
        'existe'    => $existe,
        'tablas'    => count($tablas),
        'instalada' => $instalada,
        'parcial'   => !$instalada && !$ajenas && count($tablas) > 0,
        'ajena'     => !$instalada && (bool)$ajenas,
        'ajenas'    => $ajenas,
        'faltan'    => $faltan,
    ];
}

function ayuda_mysql($mensaje)
{
    $m = (string)$mensaje;
    $code = preg_match('/\[(\d{4})\]/', $m, $x) ? (int)$x[1] : 0;
    $ini = php_ini_loaded_file() ?: '(PHP no está usando ningún php.ini)';

    if (stripos($m, 'could not find driver') !== false) {
        return 'PHP no tiene habilitada la extensión pdo_mysql. Abrí el php.ini que usa tu servidor (' . $ini . '), '
            . 'buscá la línea ;extension=pdo_mysql, sacale el punto y coma y reiniciá Apache o IIS.';
    }
    if (stripos($m, 'getaddrinfo') !== false || stripos($m, 'php_network_getaddresses') !== false) {
        return 'No se encuentra el servidor "' . DB_HOST . '". En config.local.php poné DB_HOST en 127.0.0.1.';
    }
    if ($code === 2002 || stripos($m, 'refused') !== false || stripos($m, 'No connection could be made') !== false) {
        return 'MySQL no está corriendo o no escucha en el puerto ' . DB_PORT . '. '
            . 'Con XAMPP: iniciá MySQL en el panel de control (si no arranca, casi siempre es porque otro MySQL ya ocupa el puerto 3306). '
            . 'Si tenés MySQL instalado como servicio de Windows (MySQL80, MariaDB), verificá en services.msc que esté iniciado. '
            . 'Si usa otro puerto, cambiá DB_PORT en config.local.php.';
    }
    if ($code === 1045) {
        $sinClave = stripos($m, 'using password: NO') !== false;
        return 'MySQL rechazó el usuario o la contraseña. '
            . ($sinClave
                ? 'La configuración está sin contraseña (DB_PASS vacío): si MySQL lo instalaste con el instalador oficial o ya lo usabas para otros sistemas, root tiene clave; ponela en DB_PASS de config.local.php.'
                : 'Revisá DB_USER y DB_PASS en config.local.php.');
    }
    if ($code === 1044) {
        return 'El usuario ' . DB_USER . ' no tiene permiso sobre la base. Desde un usuario administrador ejecutá: '
            . "GRANT ALL PRIVILEGES ON `" . DB_NAME . "`.* TO '" . DB_USER . "'@'localhost';";
    }
    if ($code === 1049) {
        return 'MySQL conecta bien pero la base "' . DB_NAME . '" todavía no existe: abrí instalar.php para crearla.';
    }
    if ($code === 2054 || stripos($m, 'authentication method unknown') !== false || stripos($m, 'caching_sha2') !== false) {
        return 'Tu versión de PHP no soporta el método de login de MySQL 8. Lo mejor es usar PHP 7.4 o superior. '
            . "Si no podés, en MySQL ejecutá: ALTER USER '" . DB_USER . "'@'localhost' IDENTIFIED WITH mysql_native_password BY 'tu_clave';";
    }
    if ($code === 2006 || $code === 2013) {
        return 'Algo responde en el puerto ' . DB_PORT . ' pero no parece MySQL, o cortó la conexión. Revisá DB_PORT en config.local.php.';
    }
    return 'Revisá los datos de conexión en config.local.php y que el servicio de MySQL esté iniciado.';
}
