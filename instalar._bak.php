<?php
/*
 * Instalador: crea la base de datos y las tablas.
 * Se puede usar una sola vez (si ya hay tablas, no hace nada).
 * Después de instalar conviene borrar o renombrar este archivo.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/inc/ayuda_mysql.php';
require __DIR__ . '/inc/migraciones.php';
date_default_timezone_set(APP_TZ);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function correr_sql(PDO $pdo, $archivo)
{
    $sql = file_get_contents($archivo);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $st) {
        if (trim($st) !== '') {
            $pdo->exec($st);
        }
    }
}

$msg = [];
$error = '';
$listo = false;

// Publicada en internet (Railway): exige una clave de instalación definida en la variable INSTALL_KEY,
// para que nadie más pueda instalar y elegir la contraseña del admin antes que vos.
$enNube = (bool)(env_conf('RAILWAY_PROJECT_ID') || env_conf('RAILWAY_ENVIRONMENT_NAME') || env_conf('RAILWAY_ENVIRONMENT'));
$claveInstal = (string)env_conf('INSTALL_KEY', '');
$faltaClave = $enNube && $claveInstal === '';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException $ex) {
    $pdo = null;
    $error = 'No se pudo conectar a MySQL. ' . ayuda_mysql($ex->getMessage()) . ' (Detalle: ' . $ex->getMessage() . ')';
}

$instalado = false;
$ajena = false;
$parcial = false;
$enCurso = false;
if ($pdo) {
    // Candado: si el formulario se envía dos veces (doble clic, recarga), la segunda vez espera a que termine la primera
    $enCurso = !(int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote('instalar_' . DB_NAME) . ', 60)')->fetchColumn();
    $eb = estado_base($pdo, DB_NAME);
    $instalado = $eb['instalada'];
    $ajena = $eb['ajena'];
    $parcial = $eb['parcial'];
}

if ($pdo && !$enCurso && !$instalado && !$ajena && !$parcial && !$faltaClave && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $clave = isset($_POST['clave']) ? $_POST['clave'] : '';
    $centro = trim(isset($_POST['centro']) ? $_POST['centro'] : '');
    $claveDada = isset($_POST['install_key']) ? (string)$_POST['install_key'] : '';
    if ($claveInstal !== '' && !hash_equals($claveInstal, $claveDada)) {
        $error = 'La clave de instalación no coincide con la variable INSTALL_KEY.';
    } elseif (strlen($clave) < 6) {
        $error = 'La contraseña del administrador debe tener al menos 6 caracteres.';
    } else {
        try {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . str_replace('`', '', DB_NAME) . '`');
            correr_sql($pdo, __DIR__ . '/sql/database.sql');
            $msg[] = 'Tablas creadas.';
            if (!empty($_POST['ejemplo'])) {
                correr_sql($pdo, __DIR__ . '/sql/datos_ejemplo.sql');
                $msg[] = 'Datos de ejemplo cargados (usuarios recepcion / recepcion123 y laura / laura123, profesional: Dra. Laura Méndez).';
            }
            migrar($pdo);
            $st = $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE usuario = 'admin'");
            $st->execute([password_hash($clave, PASSWORD_DEFAULT)]);
            if ($centro !== '') {
                $st = $pdo->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'nombre_centro'");
                $st->execute([$centro]);
            }
            $listo = true;
        } catch (PDOException $ex) {
            $error = 'Error al crear las tablas: ' . $ex->getMessage();
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalación</title>
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" href="assets/img/icono.svg" type="image/svg+xml">
</head>
<body>
<main class="solo">
  <div class="login" style="max-width:460px">
    <h1>Instalación</h1>
    <?php if ($error): ?><div class="aviso aviso-error"><?= h($error) ?></div><?php endif; ?>

    <?php if ($listo): ?>
      <?php foreach ($msg as $m): ?><div class="aviso aviso-ok"><?= h($m) ?></div><?php endforeach; ?>
      <p>Listo. Ingresá con el usuario <b>admin</b> y la contraseña que elegiste.</p>
      <p><b>Importante:</b> <?= $enNube ? 'el instalador ya queda bloqueado porque la base tiene tablas.' : 'borrá o renombrá el archivo <code>instalar.php</code>.' ?></p>
      <a class="btn btn-pri btn-bloque" href="login.php">Ir al ingreso</a>

    <?php elseif ($instalado): ?>
      <div class="aviso aviso-info">La base <b><?= h(DB_NAME) ?></b> ya está instalada. No se modificó nada.</div>
      <?php if (!$enNube): ?><p>Por seguridad, borrá o renombrá <code>instalar.php</code>.</p><?php endif; ?>
      <a class="btn btn-pri btn-bloque" href="login.php">Ir al ingreso</a>

    <?php elseif ($enCurso): ?>
      <div class="aviso aviso-alerta">Hay otra instalación en curso sobre la base <b><?= h(DB_NAME) ?></b>.</div>
      <p>Esperá unos segundos y recargá esta página.</p>

    <?php elseif ($parcial): ?>
      <div class="aviso aviso-alerta">La base <b><?= h(DB_NAME) ?></b> tiene una instalación a medio terminar: faltan <?= count($eb['faltan']) ?> de <?= count(tablas_app()) ?> tablas. No se tocó nada.</div>
      <p>Recargá en unos segundos por si todavía se está instalando. Si sigue igual, borrá la base y volvé a instalar:
        <code>DROP DATABASE <?= h(DB_NAME) ?>;</code></p>

    <?php elseif ($ajena): ?>
      <div class="aviso aviso-error">La base <b><?= h(DB_NAME) ?></b> tiene tablas que no son de esta app
        (<?= h(implode(', ', array_slice($eb['ajenas'], 0, 8))) ?><?= count($eb['ajenas']) > 8 ? '…' : '' ?>). No se tocó nada.</div>
      <?php if ($enNube): ?>
        <p>Para no mezclarla con otro sistema, usá una base propia: definí la variable <code>DB_NAME</code> (por ejemplo <code>centro_medico</code>) en las variables del servicio y recargá esta página.</p>
      <?php else: ?>
        <p>Para no mezclarla con otro sistema, usá una base propia: cambiá <code>DB_NAME</code> en <code>config.local.php</code> (por ejemplo <code>centro_medico</code>) y recargá esta página.</p>
      <?php endif; ?>

    <?php elseif ($pdo && $faltaClave): ?>
      <div class="aviso aviso-alerta">La app está publicada en internet y todavía no tiene clave de instalación.</div>
      <p>En Railway, en las variables del servicio de la app, agregá <code>INSTALL_KEY</code> con una clave que elijas.
        Railway vuelve a desplegar solo; después recargá esta página.</p>

    <?php elseif ($pdo): ?>
      <p>Se va a crear la base <b><?= h(DB_NAME) ?></b> en <?= h(DB_HOST) ?> con todas las tablas.</p>
      <form method="post" onsubmit="var b = this.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'Instalando… puede tardar unos segundos';">
        <?php if ($claveInstal !== ''): ?>
        <div class="campo">
          <label for="install_key">Clave de instalación</label>
          <input type="password" id="install_key" name="install_key" required autocomplete="off">
          <small>La que pusiste en la variable INSTALL_KEY.</small>
        </div>
        <?php endif; ?>
        <div class="campo">
          <label for="centro">Nombre del centro</label>
          <input type="text" id="centro" name="centro" placeholder="Ej: Centro Médico del Parque" autocomplete="organization">
        </div>
        <div class="campo">
          <label for="clave">Contraseña para el usuario admin</label>
          <input type="password" id="clave" name="clave" minlength="6" required autocomplete="new-password">
        </div>
        <div class="campo">
          <label class="check"><input type="checkbox" name="ejemplo" value="1" checked> Cargar datos de ejemplo (profesionales, prestaciones, pacientes, turnos e historias clínicas para probar)</label>
        </div>
        <button class="btn btn-pri btn-grande btn-bloque" type="submit">Instalar</button>
      </form>
    <?php else: ?>
      <p>Corregí <code>config.local.php</code> y recargá esta página, o mirá el <a href="diagnostico.php">diagnóstico completo</a>.</p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
