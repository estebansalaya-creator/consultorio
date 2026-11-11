<?php
/*
 * Se incluye al principio de cada página.
 */
require_once __DIR__ . '/../config.php';

date_default_timezone_set(APP_TZ);
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(APP_SESION);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/agenda.php';
require_once __DIR__ . '/historia.php';
require_once __DIR__ . '/nomencladores.php';
require_once __DIR__ . '/medicamentos.php';
require_once __DIR__ . '/recetas.php';
require_once __DIR__ . '/ordenes.php';
require_once __DIR__ . '/caja.php';
require_once __DIR__ . '/cobros.php';
require_once __DIR__ . '/migraciones.php';

// Si se actualizó el código y la base quedó atrás, la pone al día (una sola vez)
migrar_si_hace_falta();
