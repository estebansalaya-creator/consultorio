<?php
/*
 * Caja diaria: apertura, resumen y cierre.
 * Se maneja una sola caja abierta a la vez.
 */

function caja_abierta()
{
    static $c = false;
    if ($c === false) {
        $c = q_row("SELECT * FROM cajas WHERE estado = 'abierta' ORDER BY id DESC LIMIT 1");
    }
    return $c;
}

/**
 * Resumen de una caja: cobros por medio de pago, ingresos/egresos y efectivo esperado.
 */
function caja_resumen($caja_id)
{
    $caja = q_row('SELECT * FROM cajas WHERE id = ?', [$caja_id]);
    $porMedio = [];
    foreach (q_all('SELECT * FROM medios_pago ORDER BY orden, nombre') as $m) {
        $porMedio[$m['id']] = ['nombre' => $m['nombre'], 'es_efectivo' => (int)$m['es_efectivo'], 'cobros' => 0.0, 'cant' => 0, 'ingresos' => 0.0, 'egresos' => 0.0];
    }

    $pagos = q_all(
        "SELECT p.medio_pago_id, SUM(p.monto) AS total, COUNT(DISTINCT p.cobro_id) AS cant
           FROM cobro_pagos p JOIN cobros c ON c.id = p.cobro_id
          WHERE c.caja_id = ? AND c.estado = 'valido'
          GROUP BY p.medio_pago_id",
        [$caja_id]
    );
    foreach ($pagos as $p) {
        if (isset($porMedio[$p['medio_pago_id']])) {
            $porMedio[$p['medio_pago_id']]['cobros'] = (float)$p['total'];
            $porMedio[$p['medio_pago_id']]['cant'] = (int)$p['cant'];
        }
    }

    $movs = q_all('SELECT medio_pago_id, tipo, SUM(monto) AS total FROM caja_movimientos WHERE caja_id = ? GROUP BY medio_pago_id, tipo', [$caja_id]);
    foreach ($movs as $m) {
        if (isset($porMedio[$m['medio_pago_id']])) {
            $porMedio[$m['medio_pago_id']][$m['tipo'] === 'ingreso' ? 'ingresos' : 'egresos'] = (float)$m['total'];
        }
    }

    $totalCobros = 0.0;
    $efectivo = (float)$caja['monto_inicial'];
    foreach ($porMedio as $m) {
        $totalCobros += $m['cobros'];
        if ($m['es_efectivo']) {
            $efectivo += $m['cobros'] + $m['ingresos'] - $m['egresos'];
        }
    }

    return [
        'caja'              => $caja,
        'medios'            => $porMedio,
        'total_cobros'      => $totalCobros,
        'efectivo_esperado' => $efectivo,
        'cobros'            => q_all(
            "SELECT c.*, pa.nombre, pa.apellido,
                    (SELECT GROUP_CONCAT(i.descripcion SEPARATOR ', ') FROM cobro_items i WHERE i.cobro_id = c.id) AS detalle,
                    (SELECT GROUP_CONCAT(mp.nombre SEPARATOR ' + ') FROM cobro_pagos p JOIN medios_pago mp ON mp.id = p.medio_pago_id WHERE p.cobro_id = c.id) AS medios
               FROM cobros c LEFT JOIN pacientes pa ON pa.id = c.paciente_id
              WHERE c.caja_id = ? ORDER BY c.fecha",
            [$caja_id]
        ),
        'movimientos'       => q_all(
            'SELECT cm.*, mp.nombre AS medio, u.nombre AS usuario FROM caja_movimientos cm
               JOIN medios_pago mp ON mp.id = cm.medio_pago_id LEFT JOIN usuarios u ON u.id = cm.usuario_id
              WHERE cm.caja_id = ? ORDER BY cm.fecha',
            [$caja_id]
        ),
    ];
}

/** Turnos con algo para cobrar (particular o coseguro) de pacientes que ya llegaron o se atendieron, de los últimos 60 días */
function turnos_sin_cobrar($pacienteId = null)
{
    static $cache = [];
    $k = (int)$pacienteId;
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    $params = [date('Y-m-d', strtotime('-60 days'))];
    $f = '';
    if ($pacienteId) {
        $f = ' AND t.paciente_id = ?';
        $params[] = (int)$pacienteId;
    }
    return $cache[$k] = q_all(
        "SELECT t.*, pa.nombre, pa.apellido, p.nombre AS profesional, os.nombre AS obra_social
           FROM turnos t JOIN pacientes pa ON pa.id = t.paciente_id JOIN profesionales p ON p.id = t.profesional_id
           LEFT JOIN obras_sociales os ON os.id = t.obra_social_id
          WHERE t.cobro_id IS NULL AND t.precio > 0 AND t.estado IN ('presente','en_atencion','atendido') AND t.inicio >= ? $f
          ORDER BY t.inicio",
        $params
    );
}
