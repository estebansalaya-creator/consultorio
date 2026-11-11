<?php
/*
 * Horario, consultorio y turnos de un profesional en una fecha (para ayudar a elegir la hora al dar un turno).
 * GET ?prof=N&fecha=Y-m-d&excluir=turno_id
 */
require __DIR__ . '/../inc/bootstrap.php';
requiere('turnos');

$prof = (int)get('prof', 0);
$fecha = fecha_valida((string)get('fecha', ''));
if (!$prof || !$fecha) {
    json_out(['ok' => false, 'error' => 'Faltan datos.'], 400);
}
$turnos = [];
foreach (turnos_rango($fecha, $fecha, [$prof]) as $t) {
    if ((int)$t['id'] === (int)get('excluir', 0) || !in_array($t['estado'], ESTADOS_OCUPAN, true)) {
        continue;
    }
    $turnos[] = ['desde' => hora($t['inicio']), 'hasta' => hora($t['fin']), 'paciente' => nombre_paciente($t), 'prestacion' => $t['prestacion_nombre'], 'sobreturno' => (bool)$t['sobreturno']];
}
json_out([
    'ok'      => true,
    'dia'     => fecha_larga($fecha),
    'horario' => array_map(function ($f) {
        return ['desde' => hhmm($f[0]), 'hasta' => hhmm($f[1]), 'consultorio_id' => $f[2], 'consultorio' => $f[2] ? nombre_consultorio($f[2]) : ''];
    }, horario_dia($prof, $fecha)),
    'turnos'  => $turnos,
]);
