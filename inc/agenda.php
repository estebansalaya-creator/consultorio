<?php
/*
 * Turnos y agenda: estados, horarios y consultorios de los profesionales, superposiciones.
 */

const ESTADOS_TURNO = [
    'reservado'   => 'Reservado',
    'confirmado'  => 'Confirmado',
    'presente'    => 'En espera',
    'en_atencion' => 'En atención',
    'atendido'    => 'Atendido',
    'ausente'     => 'Ausente',
    'cancelado'   => 'Cancelado',
];

/** Estados que ocupan lugar en la agenda (ausente y cancelado liberan el horario) */
const ESTADOS_OCUPAN = ['reservado', 'confirmado', 'presente', 'en_atencion', 'atendido'];

/** Estados que puede marcar un profesional en sus propios turnos */
const ESTADOS_PROFESIONAL = ['presente', 'en_atencion', 'atendido', 'ausente'];

function estado_turno_txt($e)
{
    return isset(ESTADOS_TURNO[$e]) ? ESTADOS_TURNO[$e] : $e;
}

function badge_turno($e)
{
    return '<span class="badge est-' . e($e) . '">' . e(estado_turno_txt($e)) . '</span>';
}

/** "OSDE" o "Particular" */
function cobertura_txt($obraSocial)
{
    return $obraSocial !== null && $obraSocial !== '' ? $obraSocial : 'Particular';
}

/** Tiempo de espera desde que llegó: "12 min", "1 h 05" (o "desde las 10:12" si pasaron más de 3 horas) */
function espera_txt($llegada)
{
    if (!$llegada) {
        return '';
    }
    $m = max(0, (int)floor((time() - strtotime($llegada)) / 60));
    if ($m > 180) {
        return 'desde las ' . hora($llegada);
    }
    return $m < 60 ? $m . ' min' : intdiv($m, 60) . ' h ' . sprintf('%02d', $m % 60);
}

function profesionales_activos()
{
    static $p = null;
    if ($p === null) {
        $p = q_all('SELECT * FROM profesionales WHERE activo = 1 ORDER BY orden, nombre');
    }
    return $p;
}

function especialidades_activas()
{
    static $c = null;
    if ($c === null) {
        $c = q_all('SELECT * FROM especialidades WHERE activo = 1 ORDER BY orden, nombre');
    }
    return $c;
}

function obras_sociales_activas()
{
    static $c = null;
    if ($c === null) {
        $c = q_all('SELECT * FROM obras_sociales WHERE activo = 1 ORDER BY orden, nombre');
    }
    return $c;
}

function consultorios_activos()
{
    static $c = null;
    if ($c === null) {
        $c = q_all('SELECT * FROM consultorios WHERE activo = 1 ORDER BY orden, nombre');
    }
    return $c;
}

function nombre_consultorio($id)
{
    static $todos = null;
    if ($todos === null) {
        $todos = array_column(q_all('SELECT id, nombre FROM consultorios'), 'nombre', 'id');
    }
    return isset($todos[(int)$id]) ? $todos[(int)$id] : '';
}

/** [profesional_id => [especialidad_id, ...]] */
function mapa_especialidades_profesionales()
{
    static $m = null;
    if ($m === null) {
        $m = [];
        foreach (q_all('SELECT profesional_id, especialidad_id FROM profesional_especialidades') as $r) {
            $m[(int)$r['profesional_id']][] = (int)$r['especialidad_id'];
        }
    }
    return $m;
}

function especialidades_de_profesional($profId)
{
    $m = mapa_especialidades_profesionales();
    return isset($m[(int)$profId]) ? $m[(int)$profId] : [];
}

/** "Cardiología, Clínica médica" */
function especialidades_txt($profId)
{
    static $nombres = null;
    if ($nombres === null) {
        $nombres = array_column(q_all('SELECT id, nombre FROM especialidades ORDER BY orden, nombre'), 'nombre', 'id');
    }
    $ids = especialidades_de_profesional($profId);
    return implode(', ', array_values(array_intersect_key($nombres, array_flip($ids))));
}

/** Profesionales activos de una especialidad (en el orden de la agenda) */
function profesionales_de_especialidad($espId)
{
    return array_values(array_filter(profesionales_activos(), function ($p) use ($espId) {
        return in_array((int)$espId, especialidades_de_profesional($p['id']), true);
    }));
}

/** El profesional y quienes comparten con él alguna especialidad */
function companeros_ids($profId)
{
    $mias = especialidades_de_profesional($profId);
    $ids = [(int)$profId];
    foreach (mapa_especialidades_profesionales() as $pid => $esps) {
        if (array_intersect($mias, $esps)) {
            $ids[] = (int)$pid;
        }
    }
    return array_values(array_unique($ids));
}

/** Obras sociales que atiende el profesional (vacío = todas) */
function obras_sociales_de_profesional($profId)
{
    static $m = null;
    if ($m === null) {
        $m = [];
        foreach (q_all('SELECT profesional_id, obra_social_id FROM profesional_obras_sociales') as $r) {
            $m[(int)$r['profesional_id']][] = (int)$r['obra_social_id'];
        }
    }
    return isset($m[(int)$profId]) ? $m[(int)$profId] : [];
}

/** true si el profesional atiende esa cobertura (particular siempre) */
function atiende_obra_social($profId, $osId)
{
    if (!$osId) {
        return true;
    }
    $lista = obras_sociales_de_profesional($profId);
    return !$lista || in_array((int)$osId, $lista, true);
}

/**
 * Primer profesional de la especialidad libre para el turno $d (prestacion_id, obra_social_id, inicio, fin):
 * hace la prestación, atiende la cobertura, trabaja en ese horario y no tiene otro turno.
 * @return array ['profesional' => fila o null, 'motivos' => [nombre => por qué no]]
 */
function profesional_libre($espId, array $d, $excluirId = 0)
{
    $motivos = [];
    foreach (disponibilidad_especialidad($espId, $d, $excluirId) as $x) {
        if ($x['motivo'] === null) {
            return ['profesional' => $x['profesional'], 'motivos' => $motivos];
        }
        $motivos[$x['profesional']['nombre']] = $x['motivo'];
    }
    return ['profesional' => null, 'motivos' => $motivos];
}

/** Cada profesional de la especialidad con el motivo por el que no puede tomar el turno (null = está libre) */
function disponibilidad_especialidad($espId, array $d, $excluirId = 0)
{
    $hacen = [];
    if (!empty($d['prestacion_id'])) {
        $hacen = array_map('intval', array_column(q_all('SELECT profesional_id FROM prestacion_profesional WHERE prestacion_id = ?', [$d['prestacion_id']]), 'profesional_id'));
    }
    $res = [];
    foreach (profesionales_de_especialidad($espId) as $p) {
        $motivo = null;
        if ($hacen && !in_array((int)$p['id'], $hacen, true)) {
            $motivo = 'no hace esa prestación';
        } elseif (!atiende_obra_social($p['id'], isset($d['obra_social_id']) ? $d['obra_social_id'] : null)) {
            $motivo = 'no atiende esa obra social';
        } elseif (!en_horario($p['id'], $d['inicio'], $d['fin'])) {
            $motivo = 'no atiende en ese horario';
        } elseif (superposiciones('profesional_id', $p['id'], $d['inicio'], $d['fin'], $excluirId)) {
            $motivo = 'tiene otro turno';
        }
        $res[] = ['profesional' => $p, 'motivo' => $motivo];
    }
    return $res;
}

/**
 * Franjas de atención de un profesional en una fecha, en minutos desde las 00:00, con su consultorio.
 * @return array [[desde, hasta, consultorio_id], ...]
 */
function horario_dia($profId, $fecha)
{
    static $todos = null;
    if ($todos === null) {
        $todos = [];
        foreach (q_all('SELECT profesional_id, dia, desde, hasta, consultorio_id FROM profesional_horarios ORDER BY desde') as $h) {
            $todos[(int)$h['profesional_id']][(int)$h['dia']][] = [minutos($h['desde']), minutos($h['hasta']), $h['consultorio_id'] !== null ? (int)$h['consultorio_id'] : null];
        }
    }
    $dia = (int)date('N', strtotime($fecha));
    return isset($todos[(int)$profId][$dia]) ? $todos[(int)$profId][$dia] : [];
}

/** "09:00 a 13:00 (Consultorio 2) y 15:00 a 19:00" */
function horario_dia_txt($profId, $fecha, $sep = ' a ')
{
    return implode(' y ', array_map(function ($f) {
        return hhmm($f[0]) . '–' . hhmm($f[1]) . ($f[2] ? ' (' . nombre_consultorio($f[2]) . ')' : '');
    }, horario_dia($profId, $fecha)));
}

/** Consultorio del horario del profesional a esa hora (o el de la primera franja del día), o null */
function consultorio_de_horario($profId, $inicio)
{
    $franjas = horario_dia($profId, substr($inicio, 0, 10));
    $m = minutos(substr($inicio, 11, 5));
    foreach ($franjas as $f) {
        if ($m >= $f[0] && $m < $f[1] && $f[2]) {
            return $f[2];
        }
    }
    foreach ($franjas as $f) {
        if ($f[2]) {
            return $f[2];
        }
    }
    return null;
}

/** Consultorios en los que atiende el profesional ese día: "Consultorio 1" o "Consultorio 1 / Consultorio 3" */
function consultorios_dia_txt($profId, $fecha)
{
    $ids = array_unique(array_filter(array_map(function ($f) { return $f[2]; }, horario_dia($profId, $fecha))));
    return implode(' / ', array_map('nombre_consultorio', $ids));
}

/** true si el turno entra completo dentro de una franja de atención del profesional */
function en_horario($profId, $inicio, $fin)
{
    $fecha = substr($inicio, 0, 10);
    if (substr($fin, 0, 10) !== $fecha && substr($fin, 11, 5) !== '00:00') {
        return false;
    }
    $a = minutos(substr($inicio, 11, 5));
    $b = substr($fin, 0, 10) === $fecha ? minutos(substr($fin, 11, 5)) : 1440;
    foreach (horario_dia($profId, $fecha) as $f) {
        if ($a >= $f[0] && $b <= $f[1]) {
            return true;
        }
    }
    return false;
}

/** Turnos activos que se pisan con [inicio, fin) según profesional_id, consultorio_id o paciente_id */
function superposiciones($campo, $valor, $inicio, $fin, $excluirId = 0)
{
    if (!in_array($campo, ['profesional_id', 'consultorio_id', 'paciente_id'], true) || !$valor) {
        return [];
    }
    $in = "'" . implode("','", ESTADOS_OCUPAN) . "'";
    return q_all(
        "SELECT t.*, pa.nombre, pa.apellido, p.nombre AS profesional
           FROM turnos t JOIN pacientes pa ON pa.id = t.paciente_id JOIN profesionales p ON p.id = t.profesional_id
          WHERE t.$campo = ? AND t.estado IN ($in) AND t.inicio < ? AND t.fin > ? AND t.id <> ?
          ORDER BY t.inicio",
        [$valor, $fin, $inicio, (int)$excluirId]
    );
}

/**
 * Valida un turno antes de guardarlo.
 * $d: paciente_id, profesional_id, prestacion_id, prestacion_nombre, consultorio_id, obra_social_id, inicio, fin, sobreturno
 * Devuelve errores (no se puede guardar) y avisos (se puede guardar confirmando).
 */
function validar_turno(array $d, $excluirId = 0)
{
    $err = [];
    $av = [];
    if (empty($d['paciente_id'])) {
        $err[] = 'Elegí el paciente.';
    }
    if (empty($d['profesional_id'])) {
        $err[] = 'Elegí el profesional.';
    }
    if (empty($d['prestacion_nombre'])) {
        $err[] = 'Elegí la prestación.';
    }
    if (empty($d['inicio']) || empty($d['fin']) || $d['fin'] <= $d['inicio']) {
        $err[] = 'Revisá la fecha, la hora y la duración.';
    }
    if ($err) {
        return ['errores' => $err, 'avisos' => []];
    }

    // Un consultorio no puede usarlo otro profesional a la misma hora
    if (!empty($d['consultorio_id'])) {
        foreach (superposiciones('consultorio_id', $d['consultorio_id'], $d['inicio'], $d['fin'], $excluirId) as $o) {
            if ((int)$o['profesional_id'] !== (int)$d['profesional_id']) {
                $err[] = '«' . nombre_consultorio($d['consultorio_id']) . '» está ocupado de ' . hora($o['inicio']) . ' a ' . hora($o['fin']) . ' por ' . $o['profesional'] . '. Elegí otro consultorio u otro horario.';
            }
        }
    }
    if (empty($d['sobreturno'])) {
        foreach (superposiciones('profesional_id', $d['profesional_id'], $d['inicio'], $d['fin'], $excluirId) as $o) {
            $av[] = 'Se superpone con otro turno del profesional: ' . hora($o['inicio']) . ' a ' . hora($o['fin']) . ', ' . nombre_paciente($o) . '. Si es un sobreturno, tildá «Sobreturno».';
        }
    }
    foreach (superposiciones('paciente_id', $d['paciente_id'], $d['inicio'], $d['fin'], $excluirId) as $o) {
        if ((int)$o['profesional_id'] !== (int)$d['profesional_id']) {
            $av[] = 'El paciente ya tiene otro turno a esa hora: ' . hora($o['inicio']) . ' con ' . $o['profesional'] . ' (' . $o['prestacion_nombre'] . ').';
        }
    }
    if (!en_horario($d['profesional_id'], $d['inicio'], $d['fin'])) {
        $txt = horario_dia_txt($d['profesional_id'], substr($d['inicio'], 0, 10));
        $av[] = 'Queda fuera del horario del profesional (' . ($txt !== '' ? 'ese día atiende ' . $txt : 'ese día no atiende') . ').';
    }
    if (!empty($d['prestacion_id'])) {
        $hace = q_val('SELECT COUNT(*) FROM prestacion_profesional WHERE prestacion_id = ? AND profesional_id = ?', [$d['prestacion_id'], $d['profesional_id']]);
        $alguno = q_val('SELECT COUNT(*) FROM prestacion_profesional WHERE prestacion_id = ?', [$d['prestacion_id']]);
        if ($alguno && !$hace) {
            $av[] = 'Este profesional no tiene cargada esa prestación entre las que realiza.';
        }
    }
    if (!atiende_obra_social($d['profesional_id'], $d['obra_social_id'])) {
        $av[] = 'El profesional no atiende ' . q_val('SELECT nombre FROM obras_sociales WHERE id = ?', [$d['obra_social_id']]) . '. Si el paciente se atiende como particular, cambiá la cobertura.';
    }
    return ['errores' => $err, 'avisos' => $av];
}

/**
 * Reparte en carriles los turnos que se superponen en una misma columna de la agenda,
 * para dibujarlos uno al lado del otro. Devuelve [turno_id => [carril, total_de_carriles]].
 */
function carriles_agenda(array $turnos)
{
    $res = [];
    $grupo = [];
    $finesCarril = [];
    $finGrupo = 0;
    $cerrar = function () use (&$res, &$grupo, &$finesCarril, &$finGrupo) {
        foreach ($grupo as $id => $c) {
            $res[$id] = [$c, count($finesCarril)];
        }
        $grupo = [];
        $finesCarril = [];
        $finGrupo = 0;
    };
    usort($turnos, function ($a, $b) { return strcmp($a['inicio'], $b['inicio']); });
    foreach ($turnos as $t) {
        $a = strtotime($t['inicio']);
        $b = strtotime($t['fin']);
        if ($grupo && $a >= $finGrupo) {
            $cerrar();
        }
        $carril = null;
        foreach ($finesCarril as $i => $f) {
            if ($f <= $a) {
                $carril = $i;
                break;
            }
        }
        if ($carril === null) {
            $carril = count($finesCarril);
        }
        $finesCarril[$carril] = $b;
        $grupo[(int)$t['id']] = $carril;
        $finGrupo = max($finGrupo, $b);
    }
    $cerrar();
    return $res;
}

/** Turnos de un rango de fechas, con datos del paciente, para la agenda */
function turnos_rango($desde, $hasta, array $profIds = [])
{
    $params = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
    $filtro = '';
    if ($profIds) {
        $filtro = ' AND t.profesional_id IN (' . implode(',', array_map('intval', $profIds)) . ')';
    }
    return q_all(
        "SELECT t.*, pa.nombre, pa.apellido, pa.telefono, pa.fecha_nac,
                (pa.antecedentes IS NOT NULL AND pa.antecedentes <> '') AS con_antecedentes,
                c.nombre AS consultorio, os.nombre AS obra_social
           FROM turnos t
           JOIN pacientes pa ON pa.id = t.paciente_id
           LEFT JOIN consultorios c ON c.id = t.consultorio_id
           LEFT JOIN obras_sociales os ON os.id = t.obra_social_id
          WHERE t.inicio BETWEEN ? AND ? AND t.estado <> 'cancelado' $filtro
          ORDER BY t.inicio",
        $params
    );
}

/** Datos completos de un turno */
function turno_get($id)
{
    return q_row(
        "SELECT t.*, pa.nombre, pa.apellido, pa.telefono, pa.email, pa.dni, pa.fecha_nac, pa.sexo, pa.antecedentes,
                pa.obra_social_id AS paciente_os_id, pa.plan, pa.nro_afiliado, pos.nombre AS paciente_obra_social,
                p.nombre AS profesional, p.color, p.matricula, p.telefono AS profesional_telefono,
                c.nombre AS consultorio, os.nombre AS obra_social, es.nombre AS especialidad, u.nombre AS creado_por
           FROM turnos t
           JOIN pacientes pa ON pa.id = t.paciente_id
           JOIN profesionales p ON p.id = t.profesional_id
           LEFT JOIN obras_sociales pos ON pos.id = pa.obra_social_id
           LEFT JOIN consultorios c ON c.id = t.consultorio_id
           LEFT JOIN obras_sociales os ON os.id = t.obra_social_id
           LEFT JOIN especialidades es ON es.id = t.especialidad_id
           LEFT JOIN usuarios u ON u.id = t.usuario_id
          WHERE t.id = ?",
        [(int)$id]
    );
}

/** Mensaje de recordatorio a partir de la plantilla de Ajustes */
function mensaje_recordatorio(array $t)
{
    $tpl = config_get('msj_recordatorio', 'Hola {nombre}! Te recordamos tu turno con {profesional} el {dia} a las {hora} hs.');
    $txt = strtr($tpl, [
        '{nombre}'       => $t['nombre'],
        '{prestacion}'   => $t['prestacion_nombre'],
        '{especialidad}' => isset($t['especialidad']) && $t['especialidad'] ? $t['especialidad'] : $t['prestacion_nombre'],
        '{dia}'          => fecha_relativa($t['inicio']) === 'mañana' ? 'mañana ' . fecha_larga($t['inicio']) : fecha_larga($t['inicio']),
        '{hora}'         => hora($t['inicio']),
        '{profesional}'  => isset($t['profesional']) ? $t['profesional'] : '',
        '{consultorio}'  => isset($t['consultorio']) ? (string)$t['consultorio'] : '',
        '{centro}'       => config_get('nombre_centro', ''),
        '{direccion}'    => config_get('direccion', ''),
    ]);
    // Si algún dato está vacío no quedan comas ni paréntesis sueltos
    return trim(preg_replace(['/\(\s*\)/', '/,\s*\./', '/\s+,/', '/ {2,}/'], ['', '.', ',', ' '], $txt));
}

/* ---------- Aviso al profesional por WhatsApp ----------
 * Al dar, reprogramar o cancelar un turno queda un botón en la pantalla siguiente
 * que abre WhatsApp con el mensaje armado para el profesional (se envía a mano, como los recordatorios).
 * No incluye datos de la historia clínica.
 */

/** "martes 5 de noviembre, 15:00 a 15:20 hs" */
function aviso_cuando(array $t)
{
    return fecha_larga($t['inicio']) . ', ' . hora($t['inicio']) . ' a ' . hora($t['fin']) . ' hs';
}

/** Qué cambió entre dos versiones del turno que le importe al profesional */
function turno_cambios(array $a, array $b)
{
    $c = [];
    if ($a['inicio'] !== $b['inicio'] || $a['fin'] !== $b['fin']) {
        $c[] = 'día y hora';
    }
    if ((string)$a['prestacion_nombre'] !== (string)$b['prestacion_nombre']) {
        $c[] = 'prestación';
    }
    if ((int)$a['paciente_id'] !== (int)$b['paciente_id']) {
        $c[] = 'paciente';
    }
    if ((int)$a['consultorio_id'] !== (int)$b['consultorio_id']) {
        $c[] = 'consultorio';
    }
    if ((int)$a['obra_social_id'] !== (int)$b['obra_social_id']) {
        $c[] = 'cobertura';
    }
    if (trim((string)$a['notas']) !== trim((string)$b['notas'])) {
        $c[] = 'aclaraciones';
    }
    return $c;
}

/**
 * Texto del aviso. $tipo: nuevo | cambio | cancelado | pasado (lo atiende otro) | resumen
 * $t es el turno como quedó (turno_get) y $antes, el turno antes del cambio.
 */
function mensaje_aviso_profesional($tipo, array $t, $nombreProf, array $antes = null)
{
    $hola = 'Hola ' . nombre_corto_profesional($nombreProf) . '!';
    $pac = nombre_paciente($t);
    $centro = config_get('nombre_centro', '');
    $firma = $centro !== '' ? "\n— " . $centro : '';

    if ($tipo === 'cancelado') {
        $m = $hola . ' Se canceló el turno de ' . $pac . ' del ' . aviso_cuando($t) . ' (' . $t['prestacion_nombre'] . ').';
        if (trim((string)$t['motivo_cancelado']) !== '') {
            $m .= "\nMotivo: " . trim($t['motivo_cancelado']);
        }
        return $m . "\nEse horario quedó libre." . $firma;
    }
    if ($tipo === 'pasado' && $antes) {
        return $hola . ' El turno de ' . nombre_paciente($antes) . ' del ' . aviso_cuando($antes) . ' (' . $antes['prestacion_nombre'] . ')'
            . ' ahora lo atiende ' . $t['profesional'] . '. Ya no está en tu agenda.' . $firma;
    }

    $cambios = ($tipo === 'cambio' && $antes) ? turno_cambios($antes, $t) : [];
    $l = [];
    if ($tipo === 'nuevo') {
        $l[] = $hola . ' Tenés un turno nuevo:';
    } elseif ($tipo === 'cambio') {
        $l[] = $hola . ' Se modificó un turno tuyo' . ($cambios ? ' (cambió ' . implode(', ', $cambios) . ')' : '') . ':';
    } else {
        $l[] = $hola . ' Te paso los datos de este turno:';
    }
    if (in_array('día y hora', $cambios, true)) {
        $l[] = 'Antes: ' . aviso_cuando($antes);
        $l[] = '*Ahora: ' . aviso_cuando($t) . '*';
    } else {
        $l[] = '*' . ucfirst(aviso_cuando($t)) . '*';
    }
    $l[] = 'Paciente: ' . $pac . ($t['fecha_nac'] ? ' (' . edad_txt($t['fecha_nac']) . ')' : '');
    $l[] = 'Prestación: ' . $t['prestacion_nombre'] . ($t['sobreturno'] ? ' (sobreturno)' : '');
    $l[] = 'Cobertura: ' . cobertura_txt($t['obra_social']);
    if (!empty($t['consultorio'])) {
        $l[] = 'Consultorio: ' . $t['consultorio'];
    }
    if (trim((string)$t['notas']) !== '') {
        $l[] = 'Aclaraciones: ' . trim($t['notas']);
    } elseif (in_array('aclaraciones', $cambios, true)) {
        $l[] = 'Aclaraciones: se borraron.';
    }
    return implode("\n", $l) . $firma;
}

/** "Dra. Laura Méndez" -> "Laura"; "Lic. Ana Paz" -> "Ana" */
function nombre_corto_profesional($nombre)
{
    $partes = preg_split('/\s+/', trim((string)$nombre));
    if (count($partes) > 1 && preg_match('/\.$/', $partes[0])) {
        array_shift($partes);
    }
    return $partes ? $partes[0] : '';
}

/**
 * Deja el botón de aviso para la próxima pantalla. No avisa turnos que ya terminaron
 * ni al profesional que hizo el cambio él mismo.
 */
function aviso_profesional($tipo, array $t, array $antes = null)
{
    $ref = ($tipo === 'pasado' && $antes) ? $antes : $t;
    if (strtotime($ref['fin']) < time()) {
        return;
    }
    if (es_profesional() && (int)mi_profesional_id() === (int)$ref['profesional_id']) {
        return;
    }
    $_SESSION['avisos_wa'][] = [
        'tipo'           => $tipo,
        'profesional'    => $ref['profesional'],
        'profesional_id' => (int)$ref['profesional_id'],
        'paciente'       => nombre_paciente($t),
        'link'           => whatsapp_link($ref['profesional_telefono'], mensaje_aviso_profesional($tipo, $t, $ref['profesional'], $antes)),
    ];
    $_SESSION['avisos_wa'] = array_slice($_SESSION['avisos_wa'], -6);
}

/** Después de editar un turno: avisa si cambió algo (a los dos, si pasó a otro profesional) */
function avisar_cambios_turno(array $antes, $id)
{
    if (!in_array($antes['estado'], ['reservado', 'confirmado', 'presente'], true)) {
        return;
    }
    $t = turno_get($id);
    if (!$t) {
        return;
    }
    if ((int)$t['profesional_id'] !== (int)$antes['profesional_id']) {
        aviso_profesional('pasado', $t, $antes);
        aviso_profesional('nuevo', $t);
    } elseif (turno_cambios($antes, $t)) {
        aviso_profesional('cambio', $t, $antes);
    }
}

/** Avisos pendientes de mostrar (se muestran una sola vez) */
function avisos_wa()
{
    $a = isset($_SESSION['avisos_wa']) ? $_SESSION['avisos_wa'] : [];
    unset($_SESSION['avisos_wa']);
    return $a;
}

/** Texto del cartel que acompaña al botón */
function aviso_wa_texto(array $a)
{
    switch ($a['tipo']) {
        case 'nuevo':     return 'Turno nuevo de ' . $a['paciente'] . ' con ' . $a['profesional'] . '.';
        case 'cambio':    return 'Cambió el turno de ' . $a['paciente'] . ' con ' . $a['profesional'] . '.';
        case 'cancelado': return 'Se canceló el turno de ' . $a['paciente'] . ' con ' . $a['profesional'] . '.';
        case 'pasado':    return 'El turno de ' . $a['paciente'] . ' ya no es de ' . $a['profesional'] . '.';
    }
    return '';
}

/** Prestaciones activas agrupadas por especialidad, con los profesionales que las hacen (para el formulario de turno) */
function prestaciones_para_turno()
{
    $pres = q_all(
        'SELECT pr.*, e.nombre AS especialidad FROM prestaciones pr LEFT JOIN especialidades e ON e.id = pr.especialidad_id
          WHERE pr.activo = 1 ORDER BY e.orden IS NULL, e.orden, e.nombre, pr.orden, pr.nombre'
    );
    $rel = [];
    foreach (q_all('SELECT prestacion_id, profesional_id FROM prestacion_profesional') as $r) {
        $rel[(int)$r['prestacion_id']][] = (int)$r['profesional_id'];
    }
    foreach ($pres as &$s) {
        $s['profesionales'] = isset($rel[(int)$s['id']]) ? $rel[(int)$s['id']] : [];
    }
    return $pres;
}
