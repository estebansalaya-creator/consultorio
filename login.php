<?php
require __DIR__ . '/inc/bootstrap.php';

$destino = function () {
    return url('index.php');
};
if (usuario()) {
    redirect($destino());
}

$error = '';
if (es_post()) {
    csrf_check();
    if (login(post('usuario', ''), post('password', ''))) {
        redirect($destino());
    }
    $error = 'Usuario o contraseña incorrectos.';
}

$centro = config_get('nombre_centro', 'Centro Médico');
// Si está el logo del centro en assets/img/logo-login.png se muestra en lugar del nombre
$logo = is_file(__DIR__ . '/assets/img/logo-login.png');
layout_header('Ingresar', ['clase' => 'pagina-login' . ($logo ? ' con-logo' : '')]);
?>
<form method="post" class="login">
  <?= csrf_field() ?>
  <?php if ($logo): ?>
  <h1 class="login-logo"><img src="<?= asset_url('assets/img/logo-login.png') ?>" alt="<?= e($centro) ?>"></h1>
  <?php else: ?>
  <?= marca_svg() ?>
  <h1><?= e($centro) ?></h1>
  <?php endif; ?>
  <p>Ingresá con tu usuario para ver la agenda.</p>
  <?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>
  <div class="campo">
    <label for="usuario">Usuario</label>
    <input type="text" id="usuario" name="usuario" value="<?= e(post('usuario', '')) ?>" autocomplete="username" autocapitalize="none" autofocus required>
  </div>
  <div class="campo">
    <label for="password">Contraseña</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
  </div>
  <button class="btn btn-pri btn-grande btn-bloque" type="submit">Ingresar</button>
</form>
<?php layout_footer(); ?>
