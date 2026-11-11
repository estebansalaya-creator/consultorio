<?php
/*
 * Quién de una especialidad puede tomar un turno (para "Cualquier profesional libre").
 * GET ?especialidad=N&prestacion=N&os=N&fecha=Y-m-d&hora=HH:MM&dur=min&excluir=turno_id
 */
require __DIR__ . '/../inc/bootstrap.php';
requiere('turnos');

$esp = (int)get('especialidad', 0);
$fecha = fecha_valida((string)get('fecha', ''));
$hora = hora_valida((string)get('hora', ''));
$dur = max(5, (int)get('dur', 0));
if (!$esp || !$fecha || !$hora || !(int)get('dur', 0)) {
    json_out(['ok' => false, 'error' => 'Faltan datos.'], 400);
}
$inicio = $fecha . ' ' . $hora . ':00';
$d = [
    'prestacion_id'  => (int)get('prestacion', 0) ?: null,
    'obra_social_id' => (int)get('os', 0) ?: null,
    'inicio'         => $inicio,
    'fin'            => date('Y-m-d H:i:s', strtotime($inicio) + $dur * 60),
];
$libres = [];
$ocupados = [];
foreach (disponibilidad_especialidad($esp, $d, (int)get('excluir', 0)) as $x) {
    if ($x['motivo'] === null) {
        $libres[] = $x['profesional']['nombre'];
    } else {
        $ocupados[] = ['nombre' => $x['profesional']['nombre'], 'motivo' => $x['motivo']];
    }
}
json_out([
    'ok'           => true,
    'especialidad' => (string)q_val('SELECT nombre FROM especialidades WHERE id = ?', [$esp]),
    'hora'         => $hora,
    'libres'       => $libres,
    'ocupados'     => $ocupados,
]);
