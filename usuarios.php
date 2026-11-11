<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('ajustes');

$ROLES_TXT = [
    'admin'       => 'Administrador: todo lo administrativo (prestaciones, precios, profesionales, anulaciones, usuarios). No ve la historia clínica',
    'recepcion'   => 'Recepción: agenda de todos, turnos, sala de espera, pacientes, cobros y caja. No ve la historia clínica',
    'profesional' => 'Profesional: su agenda y la de su especialidad, sala de espera, sus pacientes e historia clínica',
];

$id = (int)get('id', 0);
$editar = get('id') !== null;
$u = $id ? q_row('SELECT * FROM usuarios WHERE id = ?', [$id]) : null;
$profs = q_all('SELECT * FROM profesionales WHERE activo = 1 ORDER BY orden, nombre');
$err = '';

if (es_post()) {
    csrf_check();
    $nombre = trim((string)post('nombre'));
    $usuario = trim((string)post('usuario'));
    $rol = array_key_exists(post('rol'), $ROLES_TXT) ? post('rol') : 'recepcion';
    $profId = $rol === 'profesional' ? ((int)post('profesional_id') ?: null) : null;
    $activo = post('activo') ? 1 : 0;
    $clave = (string)post('clave');
    $adminsActivos = (int)q_val("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id <> ?", [$id]);

    if ($nombre === '' || $usuario === '') {
        $err = 'Completá nombre y usuario.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $usuario)) {
        $err = 'El usuario debe tener entre 3 y 50 letras, números, punto o guion, sin espacios.';
    } elseif (q_val('SELECT id FROM usuarios WHERE usuario = ? AND id <> ?', [$usuario, $id])) {
        $err = 'Ese nombre de usuario ya existe.';
    } elseif (!$id && strlen($clave) < 6) {
        $err = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($id && $clave !== '' && strlen($clave) < 6) {
        $err = 'La contraseña nueva debe tener al menos 6 caracteres.';
    } elseif ($rol === 'profesional' && !$profId) {
        $err = 'Elegí qué profesional es este usuario.';
    } elseif ($id === usuario_id() && !$activo) {
        $err = 'No podés desactivar tu propio usuario.';
    } elseif ($u && $u['rol'] === 'admin' && ($rol !== 'admin' || !$activo) && $adminsActivos === 0) {
        $err = 'Tiene que quedar al menos un administrador activo.';
    } else {
        if ($id) {
            q('UPDATE usuarios SET nombre = ?, usuario = ?, rol = ?, profesional_id = ?, activo = ? WHERE id = ?', [$nombre, $usuario, $rol, $profId, $activo, $id]);
            if ($clave !== '') {
                q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($clave, PASSWORD_DEFAULT), $id]);
            }
            if ($id === usuario_id()) {
                $_SESSION['usuario']['nombre'] = $nombre;
                $_SESSION['usuario']['rol'] = $rol;
                $_SESSION['usuario']['profesional_id'] = $profId;
            }
        } else {
            q('INSERT INTO usuarios (nombre, usuario, password_hash, rol, profesional_id, activo) VALUES (?, ?, ?, ?, ?, ?)', [$nombre, $usuario, password_hash($clave, PASSWORD_DEFAULT), $rol, $profId, $activo]);
        }
        flash('ok', 'Usuario guardado.');
        redirect(url('usuarios.php'));
    }
}

$v = function ($k, $def = '') use ($u) {
    return es_post() ? post($k, $def) : ($u ? $u[$k] : $def);
};
$usuarios = q_all('SELECT u.*, p.nombre AS profesional FROM usuarios u LEFT JOIN profesionales p ON p.id = u.profesional_id ORDER BY u.activo DESC, u.nombre');
layout_header('Usuarios', ['activo' => 'ajustes.php']);
?>
<div class="grid-2">
  <div class="card">
    <div class="card-cab"><h2>Usuarios</h2><a class="btn btn-pri btn-chico" href="?id=0">Nuevo usuario</a></div>
    <table class="tabla">
      <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th></th></tr></thead>
      <?php foreach ($usuarios as $x): ?>
        <tr class="<?= $x['activo'] ? '' : 'inactivo' ?>">
          <td><?= e($x['nombre']) ?><?= $x['activo'] ? '' : ' <small>(inactivo)</small>' ?></td>
          <td><?= e($x['usuario']) ?></td>
          <td><?= e(ROLES[$x['rol']]) ?><?= $x['profesional'] ? '<br><small>' . e($x['profesional']) . '</small>' : '' ?></td>
          <td class="acciones"><a class="btn btn-chico" href="?id=<?= (int)$x['id'] ?>">Editar</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if ($editar): ?>
  <div class="card">
    <h2><?= $u ? 'Editar ' . e($u['nombre']) : 'Nuevo usuario' ?></h2>
    <?php if ($err): ?><div class="aviso aviso-error"><?= e($err) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-fila">
        <div class="campo"><label for="nombre">Nombre</label><input type="text" id="nombre" name="nombre" value="<?= e($v('nombre')) ?>" required></div>
        <div class="campo"><label for="usuario">Usuario para ingresar</label><input type="text" id="usuario" name="usuario" value="<?= e($v('usuario')) ?>" required autocomplete="off" autocapitalize="none"></div>
      </div>
      <div class="campo">
        <label for="clave"><?= $u ? 'Nueva contraseña (dejar vacío para no cambiarla)' : 'Contraseña' ?></label>
        <input type="password" id="clave" name="clave" autocomplete="new-password" <?= $u ? '' : 'required minlength="6"' ?>>
      </div>
      <div class="campo">
        <label>Rol</label>
        <?php foreach ($ROLES_TXT as $k => $txt): ?>
          <label class="check" style="margin-bottom:6px"><input type="radio" name="rol" value="<?= $k ?>"<?= $v('rol', 'recepcion') === $k ? ' checked' : '' ?>> <?= e($txt) ?></label><br>
        <?php endforeach; ?>
      </div>
      <div class="campo" id="campo-prof">
        <label for="profesional_id">Qué profesional es</label>
        <select id="profesional_id" name="profesional_id"><option value="">Elegí el profesional</option><?= opciones($profs, $v('profesional_id')) ?></select>
      </div>
      <div class="campo"><label class="check"><input type="checkbox" name="activo" value="1"<?= $v('activo', 1) ? ' checked' : '' ?>> Activo (puede ingresar)</label></div>
      <div class="fila"><button class="btn btn-pri" type="submit">Guardar</button><a class="btn" href="<?= url('usuarios.php') ?>">Cancelar</a></div>
    </form>
  </div>
  <script>
  (function () {
    var campo = document.getElementById('campo-prof');
    function ver() { var r = document.querySelector('input[name=rol]:checked'); campo.hidden = !r || r.value !== 'profesional'; }
    document.querySelectorAll('input[name=rol]').forEach(function (r) { r.addEventListener('change', ver); });
    ver();
  })();
  </script>
  <?php endif; ?>
</div>
<?php layout_footer(); ?>
