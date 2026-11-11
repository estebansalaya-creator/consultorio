<?php
/*
 * Conexión PDO única + helpers de consulta.
 */
function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $ex) {
            require_once __DIR__ . '/ayuda_mysql.php';
            http_response_code(500);
            $raiz = rtrim(str_replace('\\', '/', dirname(str_replace('\\', '/', $_SERVER['SCRIPT_NAME']))), '/');
            if (substr($raiz, -4) === '/api') {
                $raiz = substr($raiz, 0, -4);
            }
            echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sin conexión a la base</title>'
                . '<link rel="stylesheet" href="' . $raiz . '/assets/app.css"></head><body><main class="solo"><div class="login" style="max-width:560px">'
                . '<h1>No se pudo conectar a MySQL</h1>'
                . '<div class="aviso aviso-alerta">' . htmlspecialchars(ayuda_mysql($ex->getMessage())) . '</div>'
                . '<p><small>Detalle técnico: ' . htmlspecialchars($ex->getMessage()) . '</small></p>'
                . '<a class="btn btn-pri btn-bloque" href="' . $raiz . '/diagnostico.php">Ver diagnóstico completo</a>'
                . '</div></main></body></html>';
            exit;
        }
        // Misma hora en MySQL que en PHP (NOW() con la hora del centro)
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

/** Ejecuta una consulta preparada y devuelve el statement */
function q($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Devuelve todas las filas */
function q_all($sql, array $params = [])
{
    return q($sql, $params)->fetchAll();
}

/** Devuelve la primera fila o null */
function q_row($sql, array $params = [])
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

/** Devuelve el primer valor de la primera fila o null */
function q_val($sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}
