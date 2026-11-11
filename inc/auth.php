<?php
/*
 * Login, roles y protección CSRF.
 *
 * Roles:
 *  admin       -> todo lo administrativo (prestaciones, precios, profesionales, usuarios, anulaciones)
 *  recepcion   -> agenda de todos, turnos, pacientes (datos y cobertura), cobros y caja
 *  profesional -> su agenda y la de su especialidad, sus pacientes, sala de espera, historia clínica y recetas
 *
 * La historia clínica (antecedentes y evoluciones) la ven y la escriben sólo los usuarios con rol
 * profesional: ni recepción ni el administrador la ven (ver inc/historia.php).
 */

const PERMISOS = [
    'admin'       => ['*'],
    'recepcion'   => ['inicio', 'agenda', 'turnos', 'pacientes', 'pacientes_editar', 'atender', 'cobrar', 'caja', 'vademecum', 'ayuda'],
    'profesional' => ['inicio', 'agenda', 'pacientes', 'atender', 'recetas', 'vademecum', 'cie10', 'ayuda'],
];

const ROLES = [
    'admin'       => 'Administrador',
    'recepcion'   => 'Recepción',
    'profesional' => 'Profesional',
];

function usuario()
{
    return isset($_SESSION['usuario']) ? $_SESSION['usuario'] : null;
}

function usuario_id()
{
    $u = usuario();
    return $u ? (int)$u['id'] : null;
}

function login($usuario, $password)
{
    $u = q_row('SELECT * FROM usuarios WHERE usuario = ? AND activo = 1', [trim($usuario)]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id'             => (int)$u['id'],
        'nombre'         => $u['nombre'],
        'usuario'        => $u['usuario'],
        'rol'            => $u['rol'],
        'profesional_id' => $u['profesional_id'] !== null ? (int)$u['profesional_id'] : null,
    ];
    return true;
}

function logout()
{
    $_SESSION = [];
    session_destroy();
}

function puede($permiso)
{
    $u = usuario();
    if (!$u) {
        return false;
    }
    $p = isset(PERMISOS[$u['rol']]) ? PERMISOS[$u['rol']] : [];
    return in_array('*', $p, true) || in_array($permiso, $p, true);
}

function es_admin()
{
    $u = usuario();
    return $u && $u['rol'] === 'admin';
}

/** true si el usuario es un profesional (ve lo suyo y lo de su especialidad) */
function es_profesional()
{
    $u = usuario();
    return $u && $u['rol'] === 'profesional';
}

/** Profesional vinculado al usuario (o null) */
function mi_profesional_id()
{
    $u = usuario();
    return $u && $u['profesional_id'] ? (int)$u['profesional_id'] : null;
}

/**
 * Profesionales cuya agenda puede ver el usuario: todos, salvo el profesional,
 * que ve la suya y la de quienes comparten alguna especialidad con él.
 * @return int[]|null null = todos
 */
function profesionales_visibles()
{
    if (!es_profesional()) {
        return null;
    }
    return mi_profesional_id() ? companeros_ids(mi_profesional_id()) : [];
}

/** Puede ver este turno */
function puede_ver_turno(array $t)
{
    if (!puede('agenda')) {
        return false;
    }
    $vis = profesionales_visibles();
    return $vis === null || in_array((int)$t['profesional_id'], $vis, true);
}

/** Puede cambiar el estado de este turno (el profesional, sólo en los suyos) */
function puede_operar_turno(array $t)
{
    if (es_profesional()) {
        return (int)$t['profesional_id'] === (int)mi_profesional_id();
    }
    return puede('atender') || puede('turnos');
}

/** El profesional ve los pacientes con turnos suyos o de su especialidad (o con evoluciones suyas) */
function puede_ver_paciente($pacienteId)
{
    if (!puede('pacientes')) {
        return false;
    }
    $vis = profesionales_visibles();
    if ($vis === null) {
        return true;
    }
    if (!$vis) {
        return false;
    }
    return (bool)q_val(
        'SELECT 1 FROM turnos WHERE paciente_id = ? AND profesional_id IN (' . implode(',', $vis) . ')
          UNION SELECT 1 FROM evoluciones WHERE paciente_id = ? AND profesional_id = ? LIMIT 1',
        [(int)$pacienteId, (int)$pacienteId, (int)mi_profesional_id()]
    );
}

/** Condición SQL para listar sólo los pacientes que el usuario puede ver (alias de la tabla: $a) */
function filtro_pacientes_visibles($a = 'pa')
{
    $vis = profesionales_visibles();
    if ($vis === null) {
        return '1 = 1';
    }
    if (!$vis) {
        return '0 = 1';
    }
    return "(EXISTS (SELECT 1 FROM turnos tv WHERE tv.paciente_id = $a.id AND tv.profesional_id IN (" . implode(',', $vis) . '))'
        . " OR EXISTS (SELECT 1 FROM evoluciones ev WHERE ev.paciente_id = $a.id AND ev.profesional_id = " . (int)mi_profesional_id() . '))';
}

/** Muestra la pantalla de "sin permiso" y termina */
function sin_permiso($msg = 'Tu usuario no tiene acceso a esta sección.')
{
    if (strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false) {
        json_out(['ok' => false, 'error' => $msg], 403);
    }
    http_response_code(403);
    layout_header('Sin permiso');
    echo '<div class="card" style="max-width:560px"><h2>Sin permiso</h2><p>' . e($msg) . '</p><a class="btn" href="' . url('index.php') . '">Ir al inicio</a></div>';
    layout_footer();
    exit;
}

/** Corta la página si no hay sesión o no tiene el permiso */
function requiere($permiso = null)
{
    $esApi = strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false;
    if (!usuario()) {
        if ($esApi) {
            json_out(['ok' => false, 'error' => 'Sesión vencida. Volvé a ingresar.'], 401);
        }
        redirect(url('login.php'));
    }
    if ($permiso !== null && !puede($permiso)) {
        sin_permiso();
    }
    if (es_profesional() && !mi_profesional_id()) {
        sin_permiso('Tu usuario no está vinculado a ningún profesional. Pedile al administrador que lo vincule en Ajustes → Usuarios.');
    }
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Verifica el token en POST de formulario o en cabecera X-CSRF (fetch) */
function csrf_check()
{
    $t = isset($_POST['csrf']) ? $_POST['csrf'] : (isset($_SERVER['HTTP_X_CSRF']) ? $_SERVER['HTTP_X_CSRF'] : '');
    if (!$t || !hash_equals(csrf_token(), $t)) {
        if (strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false) {
            json_out(['ok' => false, 'error' => 'Token inválido, recargá la página.'], 400);
        }
        http_response_code(400);
        exit('Token de seguridad inválido. Volvé atrás y recargá la página.');
    }
}

/** URL relativa a la raíz de la app (funciona en subcarpeta) */
function url($ruta = '')
{
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
        // En Windows dirname('/index.php') devuelve '\\': se normaliza para que en la raíz de un sitio quede ''
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if (substr($dir, -4) === '/api') {
            $dir = substr($dir, 0, -4);
        }
        $base = $dir . '/';
    }
    return $base . ltrim($ruta, '/');
}
