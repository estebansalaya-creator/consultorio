<?php
/*
 * Cobros: uno o varios turnos y otros conceptos, pagados con uno o varios medios.
 */

/**
 * Registra un cobro en la caja abierta.
 * $d = [
 *   'paciente_id' => int|null,
 *   'turnos'     => [turno_id => precio, ...],
 *   'extras'     => [['descripcion' => '', 'cantidad' => 1, 'precio' => 0], ...],
 *   'descuento'  => float,
 *   'pagos'      => [['medio_pago_id' => int, 'monto' => float], ...],
 *   'vuelto'     => float,
 *   'observaciones' => string,
 * ]
 * @return int id del cobro
 * @throws RuntimeException con un mensaje para mostrar
 */
function crear_cobro(array $d)
{
    $caja = caja_abierta();
    if (!$caja) {
        throw new RuntimeException('La caja está cerrada. Abrila para poder cobrar.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $items = [];
        $turnosIds = array_map('intval', array_keys(isset($d['turnos']) ? $d['turnos'] : []));
        if ($turnosIds) {
            $in = implode(',', $turnosIds);
            $ts = q_all("SELECT t.*, os.nombre AS obra_social FROM turnos t LEFT JOIN obras_sociales os ON os.id = t.obra_social_id WHERE t.id IN ($in) FOR UPDATE");
            foreach ($ts as $t) {
                if ($t['cobro_id']) {
                    throw new RuntimeException('El turno de ' . $t['prestacion_nombre'] . ' del ' . fecha_hora($t['inicio']) . ' ya estaba cobrado.');
                }
                if ($t['estado'] === 'cancelado') {
                    throw new RuntimeException('El turno de ' . $t['prestacion_nombre'] . ' está cancelado.');
                }
                $precio = max(0, num($d['turnos'][$t['id']]));
                $items[] = [
                    'turno_id'    => (int)$t['id'],
                    'descripcion' => mb_substr($t['prestacion_nombre'] . ($t['obra_social'] ? ' · coseguro ' . $t['obra_social'] : ' · particular') . ' (' . date('d/m H:i', strtotime($t['inicio'])) . ')', 0, 150),
                    'cantidad'    => 1,
                    'precio'      => $precio,
                    'turno'       => $t,
                ];
            }
        }
        foreach (isset($d['extras']) ? $d['extras'] : [] as $x) {
            $desc = trim((string)$x['descripcion']);
            $precio = num($x['precio']);
            $cant = max(1, (int)$x['cantidad']);
            if ($desc === '' && $precio == 0) {
                continue;
            }
            if ($desc === '') {
                throw new RuntimeException('Poné la descripción de cada concepto agregado.');
            }
            if ($precio < 0) {
                throw new RuntimeException('Los precios no pueden ser negativos: para rebajar usá el descuento.');
            }
            $items[] = ['turno_id' => null, 'descripcion' => mb_substr($desc, 0, 150), 'cantidad' => $cant, 'precio' => $precio];
        }
        if (!$items) {
            throw new RuntimeException('No hay nada para cobrar: marcá al menos un turno o agregá un concepto.');
        }

        $subtotal = 0.0;
        foreach ($items as $i) {
            $subtotal += $i['precio'] * $i['cantidad'];
        }
        $descuento = min(max(0, num(isset($d['descuento']) ? $d['descuento'] : 0)), $subtotal);
        $total = round($subtotal - $descuento, 2);

        $pagos = [];
        $suma = 0.0;
        foreach (isset($d['pagos']) ? $d['pagos'] : [] as $p) {
            $monto = round(num($p['monto']), 2);
            if ($monto <= 0) {
                continue;
            }
            if (!q_val('SELECT id FROM medios_pago WHERE id = ?', [(int)$p['medio_pago_id']])) {
                throw new RuntimeException('Elegí el medio de pago.');
            }
            $pagos[] = ['medio_pago_id' => (int)$p['medio_pago_id'], 'monto' => $monto];
            $suma += $monto;
        }
        if (abs($suma - $total) > 0.009) {
            throw new RuntimeException('Los pagos suman ' . money($suma) . ' y el total es ' . money($total) . '. Tienen que coincidir.');
        }

        $numero = (int)q_val('SELECT COALESCE(MAX(numero), 0) + 1 FROM cobros FOR UPDATE');
        q(
            'INSERT INTO cobros (caja_id, numero, fecha, paciente_id, subtotal, descuento, total, vuelto, observaciones, usuario_id)
             VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)',
            [$caja['id'], $numero, !empty($d['paciente_id']) ? (int)$d['paciente_id'] : null, $subtotal, $descuento, $total,
             max(0, num(isset($d['vuelto']) ? $d['vuelto'] : 0)), trim((string)(isset($d['observaciones']) ? $d['observaciones'] : '')) ?: null, usuario_id()]
        );
        $cobroId = (int)$pdo->lastInsertId();

        foreach ($items as $i) {
            q(
                'INSERT INTO cobro_items (cobro_id, turno_id, descripcion, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?, ?)',
                [$cobroId, $i['turno_id'], $i['descripcion'], $i['cantidad'], $i['precio'], $i['precio'] * $i['cantidad']]
            );
            if ($i['turno_id']) {
                // El estado del turno no cambia: el coseguro suele cobrarse al llegar, antes de la consulta
                q('UPDATE turnos SET cobro_id = ?, precio = ?, actualizado = NOW() WHERE id = ?', [$cobroId, $i['precio'], $i['turno_id']]);
            }
        }
        foreach ($pagos as $p) {
            q('INSERT INTO cobro_pagos (cobro_id, medio_pago_id, monto) VALUES (?, ?, ?)', [$cobroId, $p['medio_pago_id'], $p['monto']]);
        }
        $pdo->commit();
        return $cobroId;
    } catch (Exception $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Anula un cobro de la caja abierta: los turnos vuelven a quedar sin cobrar */
function anular_cobro($id, $motivo)
{
    $c = q_row('SELECT * FROM cobros WHERE id = ?', [(int)$id]);
    $caja = caja_abierta();
    if (!$c || $c['estado'] === 'anulado') {
        throw new RuntimeException('El cobro no existe o ya estaba anulado.');
    }
    if (!$caja || (int)$c['caja_id'] !== (int)$caja['id']) {
        throw new RuntimeException('Sólo se pueden anular cobros de la caja abierta.');
    }
    if (trim($motivo) === '') {
        throw new RuntimeException('Escribí el motivo de la anulación.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    q("UPDATE cobros SET estado = 'anulado', motivo_anulacion = ? WHERE id = ?", [mb_substr(trim($motivo), 0, 255), $c['id']]);
    q('UPDATE turnos SET cobro_id = NULL, actualizado = NOW() WHERE cobro_id = ?', [$c['id']]);
    $pdo->commit();
}
