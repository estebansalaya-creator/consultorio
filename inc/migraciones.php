<?php
/*
 * Actualizaciones de la base de datos.
 *
 * Cada archivo sql/migraciones/NNN_descripcion.sql lleva la base a la versión NNN.
 * La versión instalada se guarda en configuracion.db_version. Al abrir cualquier página,
 * si la base está atrasada se aplican las migraciones pendientes, en orden y una sola vez
 * (con un candado de MySQL por si entran dos personas al mismo tiempo).
 */

/** Versión de base que necesita este código: el número de la última migración */
const DB_VERSION_APP = 5;

/** Ejecuta un archivo .sql sentencia por sentencia (separadas por ; al final de línea) */
function correr_sql_archivo(PDO $pdo, $archivo)
{
    $sql = file_get_contents($archivo);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $st) {
        if (trim($st) !== '') {
            $pdo->exec($st);
        }
    }
}

function version_base(PDO $pdo)
{
    $v = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'db_version'")->fetchColumn();
    return $v === false ? 1 : (int)$v;
}

/**
 * Aplica las migraciones pendientes. Devuelve la lista de archivos aplicados.
 * @throws RuntimeException si una migración falla (la base queda en la última versión que se completó)
 */
function migrar(PDO $pdo)
{
    $candado = 'migrar_' . DB_NAME;
    if (!(int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($candado) . ', 60)')->fetchColumn()) {
        throw new RuntimeException('La base se está actualizando. Esperá unos segundos y recargá la página.');
    }
    $hechas = [];
    try {
        $actual = version_base($pdo);
        $archivos = glob(__DIR__ . '/../sql/migraciones/*.sql') ?: [];
        sort($archivos);
        foreach ($archivos as $archivo) {
            $n = (int)basename($archivo);
            if ($n <= $actual) {
                continue;
            }
            try {
                correr_sql_archivo($pdo, $archivo);
            } catch (PDOException $ex) {
                throw new RuntimeException('No se pudo aplicar la actualización ' . basename($archivo) . ': ' . $ex->getMessage());
            }
            $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([(string)$n]);
            $hechas[] = basename($archivo);
            $actual = $n;
        }
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($candado) . ')');
    }
    return $hechas;
}

/** Se llama en cada página: si la base está atrasada, la actualiza */
function migrar_si_hace_falta()
{
    try {
        if (version_base(db()) >= DB_VERSION_APP) {
            return;
        }
    } catch (PDOException $ex) {
        return; // Base sin instalar: de eso se ocupa instalar.php
    }
    try {
        migrar(db());
    } catch (RuntimeException $ex) {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Actualización de la base</title>'
            . '<link rel="stylesheet" href="' . htmlspecialchars(url('assets/app.css')) . '"></head><body><main class="solo"><div class="login" style="max-width:600px">'
            . '<h1>Actualización de la base</h1><div class="aviso aviso-error">' . htmlspecialchars($ex->getMessage()) . '</div>'
            . '<p>Avisale a quien mantiene el sistema. Los datos no se perdieron.</p></div></main></body></html>';
        exit;
    }
}
