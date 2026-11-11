<?php
/*
 * Búsqueda de pacientes para el formulario de turnos y cobros.
 * GET ?q=texto  -> lista
 * GET ?id=N     -> un paciente con su resumen
 * No devuelve datos de la historia clínica, salvo a los profesionales.
 */
require __DIR__ . '/../inc/bootstrap.php';
requiere('pacientes');

$sel = "SELECT pa.id, pa.nombre, pa.apellido, pa.telefono, pa.dni, pa.fecha_nac, pa.antecedentes, pa.obra_social_id, pa.plan, pa.nro_afiliado,
               os.nombre AS obra_social,
               (SELECT MAX(t.inicio) FROM turnos t WHERE t.paciente_id = pa.id AND t.estado = 'atendido') AS ultimo,
               (SELECT COUNT(*) FROM turnos t WHERE t.paciente_id = pa.id AND t.estado = 'atendido') AS atenciones,
               (SELECT COUNT(*) FROM turnos t WHERE t.paciente_id = pa.id AND t.estado = 'ausente' AND t.inicio >= NOW() - INTERVAL 1 YEAR) AS ausencias
          FROM pacientes pa LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id";

$hc = puede_hc();
$fmt = function (array $c) use ($hc) {
    $res = $c['atenciones'] ? plural($c['atenciones'], 'atención', 'atenciones') . ', la última el ' . fecha($c['ultimo']) . '.' : 'Primera vez en el centro.';
    if ($c['ausencias']) {
        $res .= ' Faltó sin avisar ' . plural($c['ausencias'], 'vez', 'veces') . ' en el último año.';
    }
    return [
        'id'             => (int)$c['id'],
        'nombre'         => nombre_paciente($c),
        'telefono'       => (string)$c['telefono'],
        'dni'            => $c['dni'] ? dni_txt($c['dni']) : '',
        'edad'           => edad_txt($c['fecha_nac']),
        'obra_social_id' => (int)$c['obra_social_id'],
        'obra_social'    => cobertura_txt($c['obra_social']),
        'afiliado'       => trim(($c['plan'] ? 'Plan ' . $c['plan'] . ' · ' : '') . (string)$c['nro_afiliado'], ' ·'),
        'antecedentes'   => $hc ? trim((string)$c['antecedentes']) : '',
        'resumen'        => $res,
    ];
};

$id = (int)get('id', 0);
if ($id) {
    if (!puede_ver_paciente($id)) {
        json_out(['ok' => false, 'error' => 'Sin acceso a ese paciente.'], 403);
    }
    $c = q_row($sel . ' WHERE pa.id = ?', [$id]);
    json_out($c ? ['ok' => true, 'paciente' => $fmt($c)] : ['ok' => false, 'error' => 'No existe.']);
}

$q = trim((string)get('q', ''));
if (mb_strlen($q) < 2) {
    json_out(['ok' => true, 'pacientes' => []]);
}
$where = ['pa.activo = 1'];
$params = [];
foreach (preg_split('/\s+/', $q) as $palabra) {
    $num = preg_replace('/\D/', '', $palabra);
    $cond = '(pa.nombre LIKE ? OR pa.apellido LIKE ? OR pa.nro_afiliado LIKE ?';
    array_push($params, "%$palabra%", "%$palabra%", "%$palabra%");
    if (strlen($num) >= 3) {
        $cond .= " OR REPLACE(REPLACE(pa.telefono, ' ', ''), '-', '') LIKE ? OR pa.dni LIKE ?";
        array_push($params, "%$num%", "%$num%");
    }
    $where[] = $cond . ')';
}
$where[] = filtro_pacientes_visibles('pa');
$filas = q_all($sel . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY pa.apellido, pa.nombre LIMIT 12', $params);
json_out(['ok' => true, 'pacientes' => array_map($fmt, $filas)]);
