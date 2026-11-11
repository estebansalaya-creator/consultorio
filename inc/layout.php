<?php
/*
 * Encabezado y pie comunes a todas las páginas.
 */

/** [archivo, texto, permiso, ícono, color del ícono] */
function menu_items()
{
    return [
        ['index.php',         'Inicio',                                   'inicio',        'inicio',        '#7fc8f8'],
        ['agenda.php',        es_profesional() ? 'Mi agenda' : 'Agenda',  'agenda',        'agenda',        '#6fd6c9'],
        ['pacientes.php',     'Pacientes',                                'pacientes',     'pacientes',     '#a9b8ff'],
        ['recetas.php',       'Recetas',                                  'recetas_menu',  'recetas',       '#ffb38a'],
        ['ordenes.php',       'Órdenes',                                  'recetas_menu',  'ordenes',       '#f7d774'],
        ['medicamentos.php',  'Vademécum',                                'vademecum',     'vademecum',     '#8fe3a8'],
        ['caja.php',          'Caja',                                     'caja',          'caja',          '#f2c25c'],
        ['prestaciones.php',  'Prestaciones',                             'prestaciones',  'prestaciones',  '#f5a3b5'],
        ['profesionales.php', 'Profesionales',                            'profesionales', 'profesionales', '#c3a6f5'],
        ['abm.php?t=especialidades', 'Especialidades',                    'ajustes',       'especialidades', '#7fd1f0'],
        ['abm.php?t=consultorios',   'Consultorios',                      'ajustes',       'consultorios',  '#9ad7b5'],
        ['nomencladores.php', 'Nomencladores',                            'prestaciones',  'nomencladores', '#f0b7e0'],
        ['ajustes.php',       'Ajustes',                                  'ajustes',       'ajustes',       '#b3bccb'],
        ['ayuda.php',         'Ayuda',                                    'ayuda',         'ayuda',         '#9fdc9a'],
    ];
}

/** Estrella de la Vida (símbolo internacional de la atención médica) para la marca */
function marca_svg()
{
    return '<span class="marca-cruz" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><g fill="#0b5cad"><rect x="39" y="5" width="22" height="90" rx="2"/><rect x="39" y="5" width="22" height="90" rx="2" transform="rotate(60 50 50)"/><rect x="39" y="5" width="22" height="90" rx="2" transform="rotate(120 50 50)"/></g><g fill="none" stroke="#fff" stroke-linecap="round"><path d="M50 17v68" stroke-width="4.5"/><path d="M50 74c-9-2-9-10 0-12s9-10 0-12-9-10 0-12c5-1 7-4 5-7" stroke-width="3.6"/></g><circle cx="55.6" cy="24.6" r="3" fill="#fff"/></svg></span>';
}

/** Íconos de línea (SVG, 24x24) dibujados con el color del texto */
function icono($nombre)
{
    static $trazos = [
        'inicio'        => '<path d="M3 10.2 12 3l9 7.2V20a1 1 0 0 1-1 1h-5v-6.5h-6V21H4a1 1 0 0 1-1-1z"/>',
        'agenda'        => '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M8 2.5v4M16 2.5v4M3 10h18"/><path d="M7.5 14h.01M12 14h.01M16.5 14h.01M7.5 17.5h.01M12 17.5h.01"/>',
        'pacientes'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20.5v-1a5.5 5.5 0 0 1 11 0v1"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.2a5.5 5.5 0 0 1 3.5 5.3v1"/>',
        'caja'          => '<rect x="2.5" y="6" width="19" height="13" rx="2"/><path d="M2.5 10h19"/><path d="M16 14.5h2.5"/><path d="M5.5 6 15 3l1.2 3"/>',
        'prestaciones'  => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 2.5h6v3H9z"/><path d="M12 9.5v6M9 12.5h6"/>',
        'profesionales' => '<path d="M6 3v5a4 4 0 0 0 8 0V3"/><path d="M5 3h2M13 3h2"/><path d="M10 12v3a5 5 0 0 0 10 0v-2"/><circle cx="20" cy="11" r="2"/>',
        'ajustes'       => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
        'recetas'       => '<path d="M6 2.5h9l4 4V21a.5.5 0 0 1-.5.5h-12A.5.5 0 0 1 6 21z"/><path d="M15 2.5v4h4"/><path d="M9 10v7M9 10h2.5a2 2 0 0 1 0 4H9M11 14l3.5 3.5M14.5 14 11 17.5"/>',
        'ordenes'       => '<path d="M8 3.5h8M9 2.5h6v2.5H9z"/><path d="M7.5 4H6a1 1 0 0 0-1 1v15.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V5a1 1 0 0 0-1-1h-1.5"/><path d="M10 9v4.2L8 17.5h8L14 13.2V9M9.6 9h4.8"/>',
        'vademecum'     => '<rect x="2.8" y="8.2" width="18.4" height="7.6" rx="3.8" transform="rotate(-40 12 12)"/><path d="m9.2 8.7 6.1 6.6"/>',
        'especialidades' => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><path d="M17.25 13.5v7.5M13.5 17.25H21"/>',
        'consultorios'  => '<path d="M5.5 21V4a1 1 0 0 1 1-1h11a1 1 0 0 1 1 1v17"/><path d="M3 21h18"/><path d="M12 7v4M10 9h4"/><path d="M15.5 14.5h.01"/>',
        'nomencladores' => '<path d="M4 6h1.5M4 12h1.5M4 18h1.5"/><path d="M9 6h11M9 12h11M9 18h11"/>',
        'guardar'       => '<path d="M5 3.5h11l3.5 3.5v12.5a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1v-15a1 1 0 0 1 1-1z"/><path d="M8 3.5v5h7v-5"/><path d="M7.5 20.5v-6.5a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v6.5"/>',
        'instalar'      => '<rect x="6" y="2.5" width="12" height="19" rx="2.2"/><path d="M12 6.5v7.5M9 11l3 3 3-3"/><path d="M10.5 18.3h3"/>',
        'ayuda'         => '<circle cx="12" cy="12" r="9.5"/><path d="M9.3 9.2a2.8 2.8 0 0 1 5.4 1c0 1.9-2.7 2.5-2.7 4"/><path d="M12 17.6h.01"/>',
    ];
    if (!isset($trazos[$nombre])) {
        return '';
    }
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $trazos[$nombre] . '</svg>';
}

/** Instrucciones para instalar la app cuando el navegador no ofrece el botón (iPhone/iPad, Firefox…) */
function modal_instalar()
{
    return '<div class="modal-fondo" id="modal-instalar"><div class="modal" role="dialog" aria-labelledby="mi-tit">'
        . '<h2 id="mi-tit">Instalar como app</h2>'
        . '<div class="js-inst-ios" hidden><p>En el iPhone o el iPad, desde <b>Safari</b>:</p><ol>'
        . '<li>Tocá el botón <b>Compartir</b> (el cuadrado con la flecha hacia arriba).</li>'
        . '<li>Elegí <b>Agregar a inicio</b> (puede estar más abajo en la lista).</li>'
        . '<li>Tocá <b>Agregar</b>. Queda el ícono en la pantalla de inicio y se abre como una app.</li></ol></div>'
        . '<div class="js-inst-otro" hidden><p>Este navegador no muestra el botón de instalar. Otras formas:</p><ul>'
        . '<li><b>Chrome o Edge en la PC</b>: el ícono de instalar en la barra de direcciones, o menú <b>⋮</b> → <b>Transmitir, guardar y compartir</b> → <b>Instalar</b>.</li>'
        . '<li><b>Android</b>: menú <b>⋮</b> → <b>Agregar a la pantalla principal</b> o <b>Instalar app</b>.</li>'
        . '<li><b>iPhone / iPad</b>: abrí el sistema en Safari, <b>Compartir</b> → <b>Agregar a inicio</b>.</li></ul></div>'
        . '<div class="fila mt"><button class="btn btn-pri" type="button" data-cerrar>Entendido</button></div>'
        . '</div></div>';
}

/** Botón chico con ícono para guardar una fila de una tabla editable (tooltip "Guardar cambios") */
function boton_guardar_fila($texto = 'Guardar cambios')
{
    return '<button class="btn btn-chico btn-icono" type="submit" data-guardar data-tip="' . e($texto) . '" aria-label="' . e($texto) . '">' . icono('guardar') . '</button>';
}

/** URL de un archivo de assets con su fecha de modificación, para que el navegador no use una copia vieja al actualizar */
function asset_url($ruta)
{
    $archivo = __DIR__ . '/../' . $ruta;
    return url($ruta) . '?v=' . (is_file($archivo) ? filemtime($archivo) : '1');
}

/** Botón "?" que abre la ayuda en el tema de la pantalla actual */
function ayuda_link()
{
    $script = basename($_SERVER['SCRIPT_NAME']);
    if (!puede('ayuda') || $script === 'ayuda.php' || http_response_code() === 403) {
        return '';
    }
    $prof = es_profesional();
    $mapa = [
        'index.php'             => $prof ? 'sala-espera' : 'dia',
        'agenda.php'            => $prof ? 'mi-agenda' : 'agenda',
        'turno.php'             => $prof ? 'atender' : (get('id') && get('editar') === null ? 'estados' : 'dar-turno'),
        'pacientes.php'         => $prof ? 'historia' : 'pacientes',
        'paciente.php'          => $prof ? 'historia' : 'pacientes',
        'paciente_form.php'     => 'pacientes',
        'padron.php'            => 'padron',
        'historia.php'          => 'historia',
        'evolucion.php'         => 'evolucion',
        'cobro.php'             => 'cobrar',
        'recibo.php'            => es_admin() ? 'anular' : 'cobrar',
        'caja.php'              => es_admin() ? 'caja-admin' : 'caja',
        'prestaciones.php'      => 'prestaciones',
        'prestacion_form.php'   => 'prestaciones',
        'profesionales.php'     => 'profesionales',
        'profesional_form.php'  => 'profesionales',
        'ajustes.php'           => 'ajustes',
        'usuarios.php'          => 'usuarios',
        'abm.php'               => in_array(get('t'), ['consultorios', 'especialidades', 'obras_sociales'], true) ? str_replace('_', '-', get('t')) : 'ajustes',
        'recetas.php'           => 'recetas',
        'receta.php'            => get('id') ? 'receta-imprimir' : 'recetas',
        'firma.php'             => 'firma',
        'ordenes.php'           => 'ordenes',
        'orden.php'             => get('id') ? 'orden-imprimir' : 'ordenes',
        'medicamentos.php'      => 'vademecum',
        'cie10.php'             => 'cie10',
        'nomencladores.php'     => 'nomencladores',
    ];
    $ancla = isset($mapa[$script]) ? $mapa[$script] : '';
    return '<a class="btn-ayuda" href="' . url('ayuda.php') . ($ancla !== '' ? '#' . e($ancla) : '') . '" title="Ayuda de esta pantalla" aria-label="Ayuda de esta pantalla">?</a>';
}

/**
 * @param string $titulo
 * @param array  $op  ['activo' => 'agenda.php', 'clase' => 'pagina-agenda', 'sin_titulo' => true, 'acciones' => html, 'subtitulo' => texto]
 */
function layout_header($titulo, array $op = [])
{
    $u = usuario();
    $activo = isset($op['activo']) ? $op['activo'] : basename($_SERVER['SCRIPT_NAME']);
    $clase = isset($op['clase']) ? $op['clase'] : '';
    $centro = config_get('nombre_centro', 'Centro Médico');
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> | <?= e($centro) ?></title>
<link rel="stylesheet" href="<?= asset_url('assets/app.css') ?>">
<link rel="icon" href="<?= asset_url('assets/img/icono.svg') ?>" type="image/svg+xml">
<link rel="manifest" href="<?= url('manifest.php') ?>">
<meta name="theme-color" content="#12304a">
<link rel="apple-touch-icon" href="<?= url('assets/img/apple-touch-icon.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e($centro) ?>">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<meta name="base" content="<?= e(url()) ?>">
<script src="<?= asset_url('assets/app.js') ?>"></script>
</head>
<body class="<?= e($clase) ?>">
<?php if ($u): ?>
<div class="app">
  <aside class="lateral" id="lateral">
    <a class="marca" href="<?= url('index.php') ?>"><?= marca_svg() ?><span><?= e($centro) ?></span></a>
    <nav class="menu">
      <?php foreach (menu_items() as $m):
          if ($m[2] === 'recetas_menu' ? !puede_hc() : !puede($m[2])) continue;
          $on = ($activo === $m[0]) ? ' class="on" aria-current="page"' : ''; ?>
        <a href="<?= url($m[0]) ?>"<?= $on ?> style="--ic: <?= e($m[4]) ?>"><span class="mi"><?= icono($m[3]) ?></span><span class="m-txt"><?= e($m[1]) ?></span><?php
          if ($m[0] === 'caja.php') {
              $n = count(turnos_sin_cobrar());
              if ($n > 0) echo ' <span class="cuenta" title="Turnos con coseguro o consulta particular sin cobrar">' . $n . '</span>';
          }
          if ($m[0] === 'index.php' && es_profesional()) {
              $n = (int)q_val("SELECT COUNT(*) FROM turnos WHERE profesional_id = ? AND estado = 'presente' AND inicio BETWEEN ? AND ?", [mi_profesional_id(), date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')]);
              if ($n > 0) echo ' <span class="cuenta" title="Pacientes en la sala de espera">' . $n . '</span>';
          } ?></a>
      <?php endforeach; ?>
      <button type="button" class="menu-instalar js-instalar" hidden style="--ic: #8fdde3"><span class="mi"><?= icono('instalar') ?></span><span class="m-txt">Instalar app</span></button>
    </nav>
    <div class="usuario">
      <span><?= e($u['nombre']) ?><small><?= e(ROLES[$u['rol']]) ?></small></span>
      <a href="<?= url('logout.php') ?>">Salir</a>
    </div>
  </aside>
  <main class="contenido">
    <?php if (empty($op['sin_titulo'])): ?>
    <header class="barra">
      <button class="btn-menu" type="button" onclick="document.getElementById('lateral').classList.toggle('abierto')" aria-label="Menú">☰</button>
      <h1><?= e($titulo) ?><?php if (!empty($op['subtitulo'])): ?> <small class="h1-sub"><?= e($op['subtitulo']) ?></small><?php endif; ?></h1>
      <?= isset($op['acciones']) ? $op['acciones'] : '' ?>
      <?= estado_caja_pill() ?>
      <?= ayuda_link() ?>
    </header>
    <?php endif; ?>
    <?php foreach (flashes() as $f): ?>
      <div class="aviso aviso-<?= e($f['tipo']) ?>" role="status"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
    <?php foreach (avisos_wa() as $a): $pn = nombre_corto_profesional($a['profesional']); ?>
      <div class="aviso aviso-wa" role="status">
        <span><?= e(aviso_wa_texto($a)) ?></span>
        <?php if ($a['link']): ?>
          <a class="btn btn-chico btn-wa" href="<?= e($a['link']) ?>" target="_blank" rel="noopener" onclick="var b=this; setTimeout(function(){ b.outerHTML='<small>Abierto en WhatsApp</small>'; }, 300)">Avisar a <?= e($pn) ?> por WhatsApp</a>
        <?php else: ?>
          <small><?= e($a['profesional']) ?> no tiene teléfono cargado. <?php if (puede('profesionales')): ?><a href="<?= url('profesional_form.php?id=' . $a['profesional_id']) ?>">Cargalo</a> para poder avisarle por WhatsApp.<?php endif; ?></small>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
<?php else: ?>
<main class="solo">
<?php endif;
}

function estado_caja_pill()
{
    if (!puede('caja')) {
        return '';
    }
    $c = caja_abierta();
    if ($c) {
        return '<a class="pill pill-ok" href="' . url('caja.php') . '">Caja abierta</a>';
    }
    return '<a class="pill pill-off" href="' . url('caja.php') . '">Caja cerrada</a>';
}

function layout_footer(array $scripts = [])
{
    if (usuario()) {
        echo "  </main>\n</div>\n";
    } else {
        echo "</main>\n";
    }
    foreach ($scripts as $s) {
        echo '<script src="' . asset_url($s) . '"></script>' . "\n";
    }
    echo modal_instalar();
    echo "</body>\n</html>";
}
