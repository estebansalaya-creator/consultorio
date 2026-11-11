<?php
/*
 * Diagnóstico de la conexión a MySQL.
 * Abrir en el navegador: http://localhost:8030/diagnostico.php
 * No muestra contraseñas. Se puede borrar cuando todo funcione.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/inc/ayuda_mysql.php';
date_default_timezone_set(APP_TZ);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pasos = [];   // [estado ok|error|aviso, titulo, detalle]
$solucion = '';

// 0. Variables de entorno (servidor en la nube). Nunca se muestran contraseñas.
$varsEnv = [];
foreach (['MYSQL_URL', 'MYSQLHOST', 'MYSQLPORT', 'MYSQLUSER', 'MYSQLPASSWORD', 'MYSQLDATABASE', 'DB_NAME', 'PORT'] as $n) {
    $v = env_conf($n);
    if ($v === null) {
        $varsEnv[] = $n . ': no llega';
    } elseif ($n === 'MYSQL_URL') {
        $u = parse_url($v);
        if (strpos($v, '${{') !== false) {
            $varsEnv[] = 'MYSQL_URL: llega sin resolver (' . (strlen($v) <= 60 ? $v : 'referencia') . '); revisá el nombre del servicio';
        } elseif (!$u || !isset($u['host'])) {
            $varsEnv[] = 'MYSQL_URL: llega pero no tiene formato mysql://usuario:clave@servidor:puerto/base';
        } else {
            $varsEnv[] = 'MYSQL_URL: servidor ' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') . (isset($u['path']) ? ', base ' . trim($u['path'], '/') : '');
        }
    } elseif ($n === 'MYSQLPASSWORD') {
        $varsEnv[] = $n . ': llega (oculta)';
    } else {
        $varsEnv[] = $n . ': ' . $v;
    }
}
$hayEnv = (bool)array_filter($varsEnv, function ($x) { return strpos($x, 'no llega') === false; });
if ($hayEnv || env_conf('RAILWAY_PROJECT_ID') || env_conf('RAILWAY_ENVIRONMENT_NAME')) {
    $pasos[] = ['aviso', 'Variables de entorno', implode(' · ', $varsEnv)];
}

// 1. PHP
$pasos[] = [version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'aviso', 'PHP ' . PHP_VERSION,
    'php.ini: ' . (php_ini_loaded_file() ?: 'ninguno') . (version_compare(PHP_VERSION, '7.4', '<') ? '. Se recomienda PHP 7.4 o superior.' : '')];

// 2. Extensión pdo_mysql
$tieneDriver = extension_loaded('pdo_mysql');
$pasos[] = [$tieneDriver ? 'ok' : 'error', 'Extensión pdo_mysql', $tieneDriver ? 'Habilitada.' : 'No está habilitada.'];
if (!$tieneDriver) {
    $solucion = ayuda_mysql('could not find driver');
}

// 2b. Salida a internet para el vademécum (no impide usar el sistema)
$tieneCurl = function_exists('curl_init');
$tieneSsl = extension_loaded('openssl');
$pasos[] = [$tieneCurl || $tieneSsl ? 'ok' : 'aviso', 'Extensiones curl / openssl',
    $tieneCurl || $tieneSsl
        ? ($tieneCurl ? 'curl habilitada' : 'curl no está, se usa openssl') . ': el vademécum puede consultar el listado oficial de medicamentos.'
        : 'Ninguna de las dos está habilitada: el vademécum sólo va a buscar en los medicamentos ya guardados. Habilitá extension=curl en php.ini y reiniciá el sitio.'];

// 3. Puerto: ¿algo escucha?
$puertos = array_unique([(int)DB_PORT, 3306, 3307, 3308]);
$abiertos = [];
foreach ($puertos as $p) {
    $fp = @fsockopen(DB_HOST, $p, $errno, $errstr, 1.5);
    if ($fp) {
        $abiertos[] = $p;
        fclose($fp);
    }
}
$puertoOk = in_array((int)DB_PORT, $abiertos, true);
$detPuerto = $puertoOk
    ? 'MySQL responde en ' . DB_HOST . ':' . DB_PORT . '.'
    : 'Nada responde en ' . DB_HOST . ':' . DB_PORT . '.';
$otros = array_diff($abiertos, [(int)DB_PORT]);
if ($otros) {
    $detPuerto .= ' Sí hay algo escuchando en: ' . implode(', ', $otros) . '.';
}
$pasos[] = [$puertoOk ? 'ok' : 'error', 'Servidor MySQL en ' . DB_HOST . ':' . DB_PORT, $detPuerto];
if (!$puertoOk && !$solucion) {
    $solucion = $otros
        ? 'MySQL parece estar en el puerto ' . reset($otros) . '. En config.local.php cambiá DB_PORT a ' . reset($otros) . ' y recargá esta página.'
        : ayuda_mysql('[2002] refused');
}

// 4. Login y base
$version = null;
if ($tieneDriver && $puertoOk) {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        $pasos[] = ['ok', 'Usuario ' . DB_USER, 'Ingresó bien. Servidor: ' . $version . '.'];

        $eb = estado_base($pdo, DB_NAME);
        if (!$eb['existe']) {
            $pasos[] = ['aviso', 'Base de datos "' . DB_NAME . '"', 'No existe todavía.'];
            $solucion = 'La conexión funciona. Falta crear la base: abrí instalar.php.';
        } else {
            if ($eb['ajena']) {
                $pasos[] = ['error', 'Base de datos "' . DB_NAME . '"', 'Tiene tablas que no son de esta app: ' . implode(', ', array_slice($eb['ajenas'], 0, 10)) . (count($eb['ajenas']) > 10 ? '…' : '') . '.'];
                $solucion = 'Esa base es de otro sistema. Usá una base propia: cambiá DB_NAME en config.local.php (o la variable DB_NAME en la nube) y abrí instalar.php.';
            } elseif ($eb['parcial']) {
                $pasos[] = ['aviso', 'Base de datos "' . DB_NAME . '"', 'La instalación está a medio terminar: faltan ' . count($eb['faltan']) . ' de ' . count(tablas_app()) . ' tablas.'];
                $solucion = 'Si hay una instalación en curso, esperá unos segundos y recargá. Si no, borrá la base ' . DB_NAME . ' (DROP DATABASE ' . DB_NAME . ';) y volvé a abrir instalar.php.';
            } elseif (!$eb['instalada']) {
                $pasos[] = ['aviso', 'Base de datos "' . DB_NAME . '"', 'Existe pero está vacía.'];
                $solucion = 'La conexión funciona. Falta crear las tablas: abrí instalar.php.';
            } else {
                $pasos[] = ['ok', 'Base de datos "' . DB_NAME . '"', 'Existe y tiene las tablas de la app.'];
            }
        }
    } catch (PDOException $ex) {
        $pasos[] = ['error', 'Usuario ' . DB_USER, $ex->getMessage()];
        $solucion = ayuda_mysql($ex->getMessage());
    }
}

$todoOk = !$solucion;

// Con todo funcionando, el detalle técnico sólo lo ve un administrador logueado
// (importante si la app está publicada en internet).
$verDetalle = true;
if ($todoOk) {
    session_name(APP_SESION);
    session_start();
    $verDetalle = isset($_SESSION['usuario']['rol']) && $_SESSION['usuario']['rol'] === 'admin';
    session_write_close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnóstico de conexión</title>
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" href="assets/img/icono.svg" type="image/svg+xml">
<style>
  .paso { display: grid; grid-template-columns: 34px 1fr; gap: 4px 10px; padding: 10px 0; border-bottom: 1px solid var(--linea-suave); }
  .paso:last-child { border-bottom: 0; }
  .icono { width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; color: #fff; }
  .i-ok { background: var(--aloe); } .i-error { background: var(--coral); } .i-aviso { background: var(--ambar); color: #2a1e04; }
  .paso small { grid-column: 2; color: var(--gris); word-break: break-word; }
  code { background: var(--linea-suave); padding: 1px 5px; border-radius: 4px; }
</style>
</head>
<body>
<main class="solo">
  <div class="login" style="max-width:620px">
    <h1>Diagnóstico</h1>
    <?php if ($verDetalle): ?>
    <p>Configuración leída de <code>config.php</code> / <code>config.local.php</code>: servidor <code><?= h(DB_HOST) ?></code>, puerto <code><?= h(DB_PORT) ?></code>,
      usuario <code><?= h(DB_USER) ?></code>, contraseña <code><?= DB_PASS === '' ? 'vacía' : 'cargada' ?></code>, base <code><?= h(DB_NAME) ?></code>.</p>

    <?php foreach ($pasos as $p): ?>
      <div class="paso">
        <span class="icono i-<?= $p[0] ?>"><?= $p[0] === 'ok' ? '✓' : ($p[0] === 'error' ? '✕' : '!') ?></span>
        <strong><?= h($p[1]) ?></strong>
        <small><?= h($p[2]) ?></small>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($todoOk): ?>
      <div class="aviso aviso-ok mt">Todo bien: la app puede conectarse.</div>
      <a class="btn btn-pri btn-bloque" href="login.php">Ir al ingreso</a>
    <?php else: ?>
      <div class="aviso aviso-alerta mt"><b>Qué hacer:</b> <?= h($solucion) ?></div>
      <div class="fila">
        <a class="btn" href="diagnostico.php">Volver a probar</a>
        <?php if (strpos($solucion, 'instalar.php') !== false): ?><a class="btn btn-pri" href="instalar.php">Abrir instalar.php</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
