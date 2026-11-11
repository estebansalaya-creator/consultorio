<?php
/*
 * Verificación pública de una receta (no hace falta ingresar). Se llega con el QR o escribiendo el código.
 * Muestra lo mínimo para que una farmacia compare con el papel: no muestra el nombre completo ni el DNI del paciente.
 */
require __DIR__ . '/inc/bootstrap.php';

$codigo = trim((string)get('c', ''));
// El código puede ser de una receta o de una orden de laboratorio / estudios
$r = $codigo !== '' ? receta_por_codigo($codigo) : null;
$esOrden = false;
if (!$r && $codigo !== '' && ($r = orden_por_codigo($codigo))) {
    $esOrden = true;
}
$valida = $r && $r['estado'] === 'firmada' && ($esOrden ? orden_integra($r) : receta_integra($r));
$items = $r ? ($esOrden ? orden_items($r['id']) : receta_items($r['id'])) : [];
$doc = $esOrden ? mb_strtolower(orden_titulo($r['tipo'])) : 'receta';
$centro = config_get('nombre_centro', 'Centro Médico');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Verificar receta u orden | <?= e($centro) ?></title>
<link rel="stylesheet" href="<?= asset_url('assets/app.css') ?>">
<link rel="icon" href="<?= asset_url('assets/img/icono.svg') ?>" type="image/svg+xml">
</head>
<body class="pagina-login">
<main class="solo">
  <div class="login" style="max-width:520px">
    <?= marca_svg() ?>
    <h1>Verificar receta u orden</h1>
    <p><?= e($centro) ?></p>
    <form method="get" class="fila" style="margin-bottom:16px">
      <input type="text" name="c" value="<?= e($codigo) ?>" placeholder="Código: XXXXX-XXXXX" maxlength="11" style="flex:1" aria-label="Código de verificación" required>
      <button class="btn btn-pri" type="submit">Verificar</button>
    </form>
    <?php if ($codigo !== '' && !$r): ?>
      <div class="aviso aviso-error">No existe ninguna receta ni orden con ese código. Revisá que esté bien escrito.</div>
    <?php elseif ($r): ?>
      <?php if ($valida): ?>
        <div class="aviso aviso-ok"><b><?= $esOrden ? 'Orden válida.' : 'Receta válida.' ?></b> Firmada electrónicamente y sin modificaciones.</div>
      <?php elseif ($r['estado'] === 'anulada'): ?>
        <div class="aviso aviso-error"><b><?= $esOrden ? 'Orden anulada' : 'Receta anulada' ?></b> por el profesional el <?= e(fecha($r['anulada_en'])) ?>. <?= $esOrden ? 'No debe realizarse.' : 'No debe dispensarse.' ?></div>
      <?php else: ?>
        <div class="aviso aviso-error"><b>La <?= e($doc) ?> no es válida:</b> su contenido no coincide con lo que se firmó.</div>
      <?php endif; ?>
      <dl class="datos">
        <dt><?= $esOrden ? e(orden_titulo($r['tipo'])) : 'Receta' ?></dt><dd>N.º <?= sprintf('%06d', $r['numero']) ?> del <?= e(fecha($r['fecha'])) ?><?= $esOrden && $r['urgente'] ? ' · URGENTE' : '' ?></dd>
        <dt>Profesional</dt><dd><?= e($r['profesional']) ?><?= $r['matricula'] ? ' · ' . e($r['matricula']) : '' ?></dd>
        <dt>Paciente</dt><dd><?= e(iniciales($r)) ?><?= $r['dni'] ? ' · DNI terminado en ' . e(substr(preg_replace('/\D/', '', $r['dni']), -3)) : '' ?></dd>
        <?php if ($esOrden): ?>
        <dt>Prácticas</dt><dd><?php foreach ($items as $i): ?><?= $i['codigo'] ? '<b>' . e($i['codigo']) . '</b> ' : '' ?><?= e($i['descripcion']) ?><?= (int)$i['cantidad'] > 1 ? ' × ' . (int)$i['cantidad'] : '' ?><br><?php endforeach; ?></dd>
        <?php if ($r['obra_social_nombre']): ?><dt>Cobertura</dt><dd><?= e($r['obra_social_nombre']) ?><?= $r['rnas'] ? ' · RNAS ' . e($r['rnas']) : '' ?></dd><?php endif; ?>
        <?php else: ?>
        <dt>Medicamentos</dt><dd><?php foreach ($items as $i): ?><?= e(droga_txt($i['droga'])) ?><?= $i['presentacion'] ? ' ' . e($i['presentacion']) : '' ?> × <?= (int)$i['cantidad'] ?><br><?php endforeach; ?></dd>
        <?php endif; ?>
        <?php if ($r['cuir']): ?><dt>CUIR</dt><dd><?= e($r['cuir']) ?></dd><?php endif; ?>
      </dl>
      <p class="apagado mt" style="text-align:left"><small>Comprobante de firma electrónica del centro. Como <?= $esOrden ? 'orden' : 'receta' ?> electrónica vale la CUIR emitida por una plataforma registrada.</small></p>
    <?php else: ?>
      <p class="apagado" style="text-align:left">Escribí el código de verificación que figura al pie de la receta o de la orden.</p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
