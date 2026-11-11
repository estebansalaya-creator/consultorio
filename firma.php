<?php
/*
 * Mi firma: imagen de la firma (dibujada o subida), texto del sello y PIN para firmar recetas.
 * Sólo la ve y la cambia el propio profesional.
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('recetas');
if (!puede_hc()) {
    sin_permiso('La firma es de los profesionales.');
}
$prof = mi_profesional_id();
$f = firma_get($prof);
$err = '';

if (es_post()) {
    csrf_check();
    $imagen = (string)post('imagen');
    $sello = trim(str_replace("\r", '', (string)post('sello')));
    $pinNuevo = (string)post('pin_nuevo');
    try {
        // Para cambiar una firma ya cargada hay que poner el PIN actual
        if ($f && $f['pin_hash']) {
            firma_verificar_pin($prof, (string)post('pin_actual'));
        }
        if ($imagen !== '') {
            if (!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#', $imagen, $m)) {
                throw new RuntimeException('La imagen de la firma no es válida.');
            }
            $bin = base64_decode($m[2], true);
            if ($bin === false || strlen($bin) > 700 * 1024 || !@getimagesizefromstring($bin)) {
                throw new RuntimeException('La imagen de la firma no es válida o es muy grande (máximo 700 KB).');
            }
        } elseif (!$f || !$f['imagen']) {
            throw new RuntimeException('Dibujá tu firma o subí una imagen.');
        }
        if ($pinNuevo !== '' || !$f || !$f['pin_hash']) {
            if (!preg_match('/^\d{4,8}$/', $pinNuevo)) {
                throw new RuntimeException('El PIN tiene que tener entre 4 y 8 números.');
            }
            if ($pinNuevo !== (string)post('pin_repetir')) {
                throw new RuntimeException('Los dos PIN no coinciden.');
            }
        }
        $vals = [
            $imagen !== '' ? $imagen : $f['imagen'],
            mb_substr($sello, 0, 255) ?: sello_defecto($prof),
            $pinNuevo !== '' ? password_hash($pinNuevo, PASSWORD_DEFAULT) : $f['pin_hash'],
        ];
        q('INSERT INTO profesional_firmas (profesional_id, imagen, sello, pin_hash, actualizado) VALUES (?, ?, ?, ?, NOW())
           ON DUPLICATE KEY UPDATE imagen = VALUES(imagen), sello = VALUES(sello), pin_hash = VALUES(pin_hash), intentos = 0, bloqueado_hasta = NULL, actualizado = NOW()',
          array_merge([$prof], $vals));
        flash('ok', 'Firma guardada. Ya podés firmar recetas y órdenes con tu PIN.');
        redirect(url('firma.php'));
    } catch (RuntimeException $ex) {
        $err = $ex->getMessage();
    }
}

$sello = es_post() ? (string)post('sello') : ($f && $f['sello'] ? $f['sello'] : sello_defecto($prof));
layout_header('Mi firma', ['activo' => 'recetas.php']);
?>
<?php if ($err): ?><div class="aviso aviso-error"><?= e($err) ?></div><?php endif; ?>
<div class="grid-2-1">
  <form method="post" class="card" id="form-firma">
    <?= csrf_field() ?>
    <input type="hidden" name="imagen" id="imagen" value="">
    <h2>Firma</h2>
    <p class="apagado">Firmá con el mouse, el dedo o un lápiz en el recuadro, o subí una foto o escaneo de tu firma sobre fondo blanco.</p>
    <div class="firma-pad"><canvas id="pad" width="600" height="220" aria-label="Recuadro para dibujar la firma"></canvas></div>
    <div class="fila mt">
      <button class="btn btn-chico" type="button" id="borrar">Borrar</button>
      <label class="btn btn-chico" style="margin:0">Subir imagen<input type="file" id="archivo" accept="image/png,image/jpeg" hidden></label>
      <small class="apagado" id="estado-pad"><?= $f && $f['imagen'] ? 'Dejalo vacío para conservar la firma actual.' : '' ?></small>
    </div>

    <div class="campo mt"><label for="sello">Sello</label>
      <textarea id="sello" name="sello" rows="3" maxlength="255"><?= e($sello) ?></textarea>
      <small>Va debajo de la firma: nombre, especialidad y matrícula, un dato por renglón.</small></div>

    <h2 class="mt">PIN para firmar</h2>
    <p class="apagado">Se pide cada vez que firmás una receta. Entre 4 y 8 números; no lo compartas.<?= $f && $f['pin_hash'] ? ' Dejalo vacío para no cambiarlo.' : '' ?></p>
    <div class="form-fila">
      <div class="campo"><label for="pin_nuevo"><?= $f && $f['pin_hash'] ? 'PIN nuevo' : 'PIN' ?></label><input type="password" inputmode="numeric" id="pin_nuevo" name="pin_nuevo" maxlength="8" autocomplete="new-password"></div>
      <div class="campo"><label for="pin_repetir">Repetir PIN</label><input type="password" inputmode="numeric" id="pin_repetir" name="pin_repetir" maxlength="8" autocomplete="new-password"></div>
    </div>
    <?php if ($f && $f['pin_hash']): ?>
      <div class="campo"><label for="pin_actual">PIN actual (para confirmar el cambio)</label><input type="password" inputmode="numeric" id="pin_actual" name="pin_actual" maxlength="8" required autocomplete="current-password"></div>
    <?php endif; ?>
    <button class="btn btn-pri" type="submit">Guardar firma</button>
  </form>

  <div>
    <div class="card">
      <h2>Cómo se ve</h2>
      <?php if ($f && $f['imagen']): ?>
        <div class="firma-muestra">
          <img src="<?= e($f['imagen']) ?>" alt="Firma">
          <div class="sello"><?= nl2br(e($f['sello'])) ?></div>
        </div>
        <p class="apagado mt"><small>Actualizada el <?= e(fecha_hora($f['actualizado'])) ?>.</small></p>
      <?php else: ?>
        <p class="apagado">Todavía no cargaste tu firma.</p>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Qué tipo de firma es</h2>
      <p class="apagado"><small>Es una <b>firma electrónica</b>: al firmar con tu PIN, la receta queda cerrada con fecha y hora y un código de verificación que demuestra que no se modificó. No es una firma digital con certificado (token o firma remota de un certificador licenciado). Para recetas que las farmacias acepten como receta electrónica hay que usar una plataforma inscripta en el ReNaPDiS: ver <a href="<?= url('ayuda.php#receta-validez') ?>">Ayuda</a>.</small></p>
      <p class="apagado"><small>Si olvidás el PIN, pedile al administrador que borre tu firma desde Profesionales y cargala de nuevo.</small></p>
    </div>
  </div>
</div>
<script>
(function () {
  var c = document.getElementById('pad'), ctx = c.getContext('2d'), dibujado = false, trazando = false;
  function limpiar() {
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
    ctx.strokeStyle = '#d0d8e0'; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(30, c.height - 40); ctx.lineTo(c.width - 30, c.height - 40); ctx.stroke();
    dibujado = false;
  }
  function punto(ev) {
    var r = c.getBoundingClientRect();
    return { x: (ev.clientX - r.left) * c.width / r.width, y: (ev.clientY - r.top) * c.height / r.height };
  }
  c.addEventListener('pointerdown', function (ev) {
    if (!dibujado) limpiarLinea();
    trazando = true; dibujado = true; c.setPointerCapture(ev.pointerId);
    var p = punto(ev); ctx.beginPath(); ctx.moveTo(p.x, p.y);
    ctx.strokeStyle = '#13233a'; ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    ev.preventDefault();
  });
  c.addEventListener('pointermove', function (ev) {
    if (!trazando) return;
    var p = punto(ev); ctx.lineTo(p.x, p.y); ctx.stroke(); ev.preventDefault();
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) { c.addEventListener(t, function () { trazando = false; }); });
  function limpiarLinea() { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); }
  document.getElementById('borrar').addEventListener('click', limpiar);
  document.getElementById('archivo').addEventListener('change', function () {
    var f = this.files[0];
    if (!f) return;
    var img = new Image();
    img.onload = function () {
      limpiarLinea();
      var esc = Math.min(c.width / img.width, c.height / img.height, 1);
      var w = img.width * esc, h = img.height * esc;
      ctx.drawImage(img, (c.width - w) / 2, (c.height - h) / 2, w, h);
      dibujado = true;
      URL.revokeObjectURL(img.src);
    };
    img.src = URL.createObjectURL(f);
  });
  document.getElementById('form-firma').addEventListener('submit', function () {
    document.getElementById('imagen').value = dibujado ? c.toDataURL('image/png') : '';
  });
  limpiar();
})();
</script>
<?php layout_footer(); ?>
