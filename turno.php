<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('agenda');

$id = (int)get('id', 0);
$t = $id ? turno_get($id) : null;
if ($id && !$t) {
    flash('error', 'El turno no existe.');
    redirect(url('agenda.php'));
}
if ($t && !puede_ver_turno($t)) {
    sin_permiso('Ese turno es de un profesional de otra especialidad.');
}
if (!$t && !puede('turnos')) {
    sin_permiso('Tu usuario no puede dar turnos.');
}
$editar = !$t || get('editar') !== null;
if ($editar && !puede('turnos')) {
    $editar = false;
}

$errores = [];
$avisos = [];
$volverAgenda = function ($fechaHora) {
    return url('agenda.php?fecha=' . substr($fechaHora, 0, 10));
};

if (es_post()) {
    csrf_check();
    $accion = post('accion');

    // ---------- Cambiar estado ----------
    if ($accion === 'estado' && $t) {
        $nuevo = (string)post('estado');
        $volver = post('volver') === 'inicio' ? url('index.php') : url('turno.php?id=' . $id);
        if (!isset(ESTADOS_TURNO[$nuevo])) {
            redirect($volver);
        }
        if (!puede_operar_turno($t) || (!puede('turnos') && !in_array($nuevo, ESTADOS_PROFESIONAL, true))) {
            sin_permiso('No podés pasar el turno a ese estado.');
        }
        if (in_array($nuevo, ['cancelado', 'ausente'], true) && $t['cobro_id']) {
            flash('error', 'El turno está cobrado. Para cancelarlo o marcarlo ausente, primero hay que anular el cobro desde Caja.');
        } elseif ($nuevo === $t['estado']) {
            // Doble clic o dos personas a la vez: no se hace nada
        } else {
            $motivo = $nuevo === 'cancelado' ? trim((string)post('motivo')) : null;
            // La hora de llegada se anota al pasar a la sala de espera (o directo a atención) y se borra si se vuelve atrás
            $llegada = $t['llegada'];
            if (in_array($nuevo, ['presente', 'en_atencion', 'atendido'], true) && !$llegada) {
                $llegada = date('Y-m-d H:i:s');
            } elseif (in_array($nuevo, ['reservado', 'confirmado', 'ausente', 'cancelado'], true)) {
                $llegada = null;
            }
            q('UPDATE turnos SET estado = ?, motivo_cancelado = ?, llegada = ?, actualizado = NOW() WHERE id = ?', [$nuevo, $motivo ?: null, $llegada, $id]);
            $txt = [
                'confirmado'  => 'Turno confirmado.',
                'presente'    => nombre_paciente($t) . ' está en la sala de espera.',
                'en_atencion' => nombre_paciente($t) . ' pasó a atención.',
                'atendido'    => 'Turno atendido.',
                'ausente'     => 'Marcado: no vino.',
                'cancelado'   => 'Turno cancelado.',
            ];
            flash('ok', isset($txt[$nuevo]) ? $txt[$nuevo] : 'Turno ' . mb_strtolower(estado_turno_txt($nuevo)) . '.');
            if ($nuevo === 'cancelado' && $t['estado'] !== 'cancelado') {
                aviso_profesional('cancelado', turno_get($id));
                redirect($volverAgenda($t['inicio']));
            }
        }
        redirect($volver);
    }

    // ---------- Recordatorio por WhatsApp ----------
    if ($accion === 'whatsapp' && $t && puede('turnos')) {
        $link = whatsapp_link($t['telefono'], mensaje_recordatorio($t));
        if ($link) {
            q('UPDATE turnos SET recordado = NOW() WHERE id = ?', [$id]);
            redirect($link);
        }
        redirect(url('turno.php?id=' . $id));
    }

    // ---------- Guardar (nuevo o editar) ----------
    if ($accion === 'guardar' && puede('turnos')) {
        $prest = q_row('SELECT * FROM prestaciones WHERE id = ?', [(int)post('prestacion_id')]);
        $fechaP = fecha_valida((string)post('fecha'));
        $horaP = hora_valida((string)post('hora'));
        $dur = max(5, (int)post('duracion'));
        $inicio = ($fechaP && $horaP) ? $fechaP . ' ' . $horaP . ':00' : '';
        $finP = $inicio ? date('Y-m-d H:i:s', strtotime($inicio) + $dur * 60) : '';
        $osId = (int)post('obra_social_id') ?: null;
        $pacienteId = $t ? (int)$t['paciente_id'] : (int)post('paciente_id');
        $nuevoPac = null;
        if (!$pacienteId && trim((string)post('pac_nombre')) !== '') {
            $nuevoPac = [
                'nombre'       => trim((string)post('pac_nombre')),
                'apellido'     => trim((string)post('pac_apellido')),
                'dni'          => preg_replace('/\D/', '', (string)post('pac_dni')),
                'telefono'     => trim((string)post('pac_telefono')),
                'nro_afiliado' => trim((string)post('pac_afiliado')),
            ];
        }
        $espId = (int)post('especialidad_id');
        $d = [
            'paciente_id'       => $pacienteId ?: ($nuevoPac ? -1 : 0),
            'profesional_id'    => (int)post('profesional_id'),
            'especialidad_id'   => $prest && $prest['especialidad_id'] ? (int)$prest['especialidad_id'] : ($espId ?: null),
            'prestacion_id'     => $prest ? (int)$prest['id'] : null,
            'prestacion_nombre' => $prest ? $prest['nombre'] : '',
            'consultorio_id'    => null,
            'obra_social_id'    => $osId,
            'inicio'            => $inicio,
            'fin'               => $finP,
            'sobreturno'        => post('sobreturno') ? 1 : 0,
        ];
        if ($t && $t['prestacion_id'] === null && !$prest) {
            $d['prestacion_nombre'] = $t['prestacion_nombre']; // prestación borrada: conserva el nombre
            $d['especialidad_id'] = $t['especialidad_id'];
        }
        // "Cualquier profesional libre" de la especialidad: se asigna al primero que pueda
        $auto = post('profesional_id') === 'auto';
        $asignado = null;
        if ($auto) {
            if (!$espId) {
                $errores[] = 'Para asignarlo a cualquier profesional libre, elegí la especialidad.';
            } elseif ($d['inicio'] && $d['fin'] && $d['prestacion_nombre'] !== '') {
                $lib = profesional_libre($espId, $d, $id);
                if ($lib['profesional']) {
                    $asignado = $lib['profesional'];
                    $d['profesional_id'] = (int)$asignado['id'];
                } else {
                    $det = [];
                    foreach ($lib['motivos'] as $nom => $mot) {
                        $det[] = $nom . ' ' . $mot;
                    }
                    $errores[] = 'No hay nadie de ' . q_val('SELECT nombre FROM especialidades WHERE id = ?', [$espId]) . ' libre a esa hora'
                        . ($det ? ': ' . implode('; ', $det) : ' (la especialidad no tiene profesionales activos)') . '.';
                }
            }
        }
        // Consultorio: el elegido, el que requiere la prestación o el del horario del profesional a esa hora
        $consElegido = (int)post('consultorio_id');
        if ($consElegido) {
            $d['consultorio_id'] = $consElegido;
        } elseif ($prest && $prest['consultorio_id']) {
            $d['consultorio_id'] = (int)$prest['consultorio_id'];
        } elseif ($d['profesional_id'] && $d['inicio']) {
            $d['consultorio_id'] = consultorio_de_horario($d['profesional_id'], $d['inicio']);
        }

        $val = ($auto && !$asignado && $errores) ? ['errores' => [], 'avisos' => []] : validar_turno($d, $id);
        $errores = array_merge($errores, $val['errores']);
        $avisos = $val['avisos'];
        if ($nuevoPac) {
            if ($nuevoPac['dni'] !== '' && ($existe = q_row('SELECT * FROM pacientes WHERE dni = ?', [$nuevoPac['dni']]))) {
                $errores[] = 'Ya hay un paciente con DNI ' . dni_txt($nuevoPac['dni']) . ': ' . nombre_paciente($existe) . '. Buscalo por el DNI en lugar de cargarlo de nuevo.';
            } elseif ($nuevoPac['dni'] === '' && ($existe = q_row('SELECT * FROM pacientes WHERE nombre = ? AND apellido = ? AND activo = 1', [$nuevoPac['nombre'], $nuevoPac['apellido']]))) {
                $errores[] = 'Ya hay un paciente que se llama ' . nombre_paciente($existe) . ($existe['dni'] ? ' (DNI ' . dni_txt($existe['dni']) . ')' : '') . '. Buscalo, o cargá el DNI si es otra persona.';
            }
        }
        if (!$errores && (!$avisos || post('forzar'))) {
            $pdo = db();
            $pdo->beginTransaction();
            if ($nuevoPac) {
                q('INSERT INTO pacientes (nombre, apellido, dni, telefono, obra_social_id, nro_afiliado) VALUES (?, ?, ?, ?, ?, ?)',
                  [$nuevoPac['nombre'], $nuevoPac['apellido'], $nuevoPac['dni'] ?: null, $nuevoPac['telefono'] ?: null, $osId, $osId ? ($nuevoPac['nro_afiliado'] ?: null) : null]);
                $d['paciente_id'] = (int)$pdo->lastInsertId();
            }
            $precio = max(0, num(post('precio')));
            $notas = trim((string)post('notas')) ?: null;
            // Código de práctica según el nomenclador de la cobertura (si la prestación lo tiene cargado)
            $practica = practica_para($d['prestacion_id'], $d['obra_social_id']);
            if (!$practica && $t && !$d['prestacion_id']) {
                $practica = $t['practica_codigo'] ? ['codigo' => $t['practica_codigo'], 'nomenclador' => $t['practica_nomenclador']] : null;
            }
            if ($t) {
                $cobrado = (bool)$t['cobro_id'];
                $autoFlag = $asignado ? 1 : ((int)$d['profesional_id'] === (int)$t['profesional_id'] ? (int)$t['asignado_auto'] : 0);
                q(
                    'UPDATE turnos SET profesional_id = ?, especialidad_id = ?, prestacion_id = ?, prestacion_nombre = ?, practica_codigo = ?, practica_nomenclador = ?,
                            consultorio_id = ?, obra_social_id = ?, inicio = ?, fin = ?, precio = ?, notas = ?, sobreturno = ?, asignado_auto = ?, actualizado = NOW() WHERE id = ?',
                    [$d['profesional_id'], $d['especialidad_id'], $d['prestacion_id'], $d['prestacion_nombre'], $practica ? $practica['codigo'] : null, $practica ? $practica['nomenclador'] : null,
                     $d['consultorio_id'], $d['obra_social_id'], $d['inicio'], $d['fin'], $cobrado ? $t['precio'] : $precio, $notas, $d['sobreturno'], $autoFlag, $id]
                );
                $pdo->commit();
                flash('ok', 'Turno actualizado.' . ($asignado ? ' Quedó con ' . $asignado['nombre'] . ' (asignado automáticamente).' : ''));
                avisar_cambios_turno($t, $id);
                redirect(url('turno.php?id=' . $id));
            }
            q(
                'INSERT INTO turnos (paciente_id, profesional_id, especialidad_id, prestacion_id, prestacion_nombre, practica_codigo, practica_nomenclador, consultorio_id, obra_social_id,
                                     inicio, fin, precio, estado, notas, sobreturno, asignado_auto, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$d['paciente_id'], $d['profesional_id'], $d['especialidad_id'], $d['prestacion_id'], $d['prestacion_nombre'], $practica ? $practica['codigo'] : null, $practica ? $practica['nomenclador'] : null, $d['consultorio_id'], $d['obra_social_id'],
                 $d['inicio'], $d['fin'], $precio, post('confirmado') ? 'confirmado' : 'reservado', $notas, $d['sobreturno'], $asignado ? 1 : 0, usuario_id()]
            );
            $nuevoId = (int)$pdo->lastInsertId();
            $pdo->commit();
            aviso_profesional('nuevo', turno_get($nuevoId));
            flash('ok', 'Turno agendado: ' . fecha_relativa($d['inicio']) . ' a las ' . hora($d['inicio']) . ($asignado ? ' con ' . $asignado['nombre'] . ' (asignado automáticamente)' : '') . '.');
            redirect(url(post('otro')
                ? 'turno.php?paciente=' . $d['paciente_id'] . '&fecha=' . $fechaP . '&hora=' . hora($d['fin'])
                : 'agenda.php?fecha=' . $fechaP . ($espId ? '&esp=' . $espId : '') . '#t' . $nuevoId));
        }
        $editar = true;
    }
}

// ---------- Valores del formulario ----------
$prestaciones = prestaciones_para_turno();
$profs = profesionales_activos();
$consultorios = consultorios_activos();
$obras = obras_sociales_activas();

if (es_post()) {
    $f = [
        'paciente_id' => $t ? (int)$t['paciente_id'] : (int)post('paciente_id'), 'prestacion_id' => (int)post('prestacion_id'),
        'profesional_id' => post('profesional_id') === 'auto' ? 'auto' : (int)post('profesional_id'), 'especialidad_id' => (int)post('especialidad_id'),
        'fecha' => (string)post('fecha'), 'hora' => (string)post('hora'), 'duracion' => (int)post('duracion'),
        'consultorio_id' => (int)post('consultorio_id'), 'obra_social_id' => (int)post('obra_social_id'),
        'precio' => (string)post('precio'), 'notas' => (string)post('notas'), 'sobreturno' => (bool)post('sobreturno'),
        'pac_nombre' => (string)post('pac_nombre'), 'pac_apellido' => (string)post('pac_apellido'), 'pac_dni' => (string)post('pac_dni'),
        'pac_telefono' => (string)post('pac_telefono'), 'pac_afiliado' => (string)post('pac_afiliado'),
        'confirmado' => (bool)post('confirmado'),
    ];
} elseif ($t) {
    // Consultorio: si es el del horario del profesional queda en "según el horario" (así al reprogramar se actualiza solo)
    $consHorario = consultorio_de_horario($t['profesional_id'], $t['inicio']);
    $consPrest = (int)q_val('SELECT consultorio_id FROM prestaciones WHERE id = ?', [(int)$t['prestacion_id']]);
    $f = [
        'paciente_id' => (int)$t['paciente_id'], 'prestacion_id' => (int)$t['prestacion_id'], 'profesional_id' => (int)$t['profesional_id'],
        'especialidad_id' => (int)$t['especialidad_id'],
        'fecha' => substr($t['inicio'], 0, 10), 'hora' => hora($t['inicio']), 'duracion' => (int)round((strtotime($t['fin']) - strtotime($t['inicio'])) / 60),
        'consultorio_id' => ((int)$t['consultorio_id'] === (int)$consHorario || (int)$t['consultorio_id'] === $consPrest) ? 0 : (int)$t['consultorio_id'],
        'obra_social_id' => (int)$t['obra_social_id'],
        'precio' => (string)(float)$t['precio'], 'notas' => (string)$t['notas'], 'sobreturno' => (bool)$t['sobreturno'],
        'pac_nombre' => '', 'pac_apellido' => '', 'pac_dni' => '', 'pac_telefono' => '', 'pac_afiliado' => '', 'confirmado' => false,
    ];
} else {
    $f = [
        'paciente_id' => (int)get('paciente', 0), 'prestacion_id' => (int)get('prestacion', 0), 'profesional_id' => (int)get('profesional', 0),
        'especialidad_id' => (int)get('especialidad', 0),
        'fecha' => fecha_valida((string)get('fecha', '')) ?: date('Y-m-d'), 'hora' => hora_valida((string)get('hora', '')) ?: '',
        'duracion' => 0, 'consultorio_id' => 0, 'obra_social_id' => -1, 'precio' => '', 'notas' => '', 'sobreturno' => false,
        'pac_nombre' => '', 'pac_apellido' => '', 'pac_dni' => '', 'pac_telefono' => '', 'pac_afiliado' => '', 'confirmado' => false,
    ];
}
$pacSel = $f['paciente_id'] > 0 ? q_row('SELECT pa.*, os.nombre AS obra_social FROM pacientes pa LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id WHERE pa.id = ?', [$f['paciente_id']]) : null;
if ($f['obra_social_id'] === -1) {
    $f['obra_social_id'] = $pacSel ? (int)$pacSel['obra_social_id'] : 0; // turno nuevo: la cobertura del paciente
}
$especialidades = especialidades_activas();
if (!in_array($f['especialidad_id'], array_map('intval', array_column($especialidades, 'id')), true)) {
    $f['especialidad_id'] = 0;
}
// Al reprogramar, si el profesional del turno no tiene la especialidad, no se filtra (para no cambiarlo sin querer)
if ($t && !es_post() && $f['especialidad_id'] && !in_array($f['especialidad_id'], especialidades_de_profesional($f['profesional_id']), true)) {
    $f['especialidad_id'] = 0;
}

// ---------- Vista de detalle ----------
$verHc = $t && puede_ver_hc($t['paciente_id']);
$evolucion = $t && $verHc ? evolucion_de_turno($id) : null;
$evoluciones = $verHc ? array_filter(evoluciones_de($t['paciente_id'], 6), function ($ev) use ($id) { return (int)$ev['turno_id'] !== $id; }) : [];
$historial = $proximos = [];
if ($t && !$editar) {
    $historial = q_all(
        "SELECT t.*, p.nombre AS profesional FROM turnos t JOIN profesionales p ON p.id = t.profesional_id
          WHERE t.paciente_id = ? AND t.id <> ? AND t.inicio < ? AND t.estado IN ('atendido','ausente') ORDER BY t.inicio DESC LIMIT 5",
        [$t['paciente_id'], $id, $t['inicio']]
    );
    $proximos = q_all(
        "SELECT t.*, p.nombre AS profesional FROM turnos t JOIN profesionales p ON p.id = t.profesional_id
          WHERE t.paciente_id = ? AND t.id <> ? AND t.inicio >= NOW() AND t.estado IN ('reservado','confirmado') ORDER BY t.inicio LIMIT 4",
        [$t['paciente_id'], $id]
    );
}

$titulo = $t ? ($editar ? 'Reprogramar turno' : 'Turno') : 'Nuevo turno';
layout_header($titulo, ['activo' => 'agenda.php']);
?>

<?php if ($t && !$editar):
    $pasado = strtotime($t['inicio']) < time();
    $esHoy = substr($t['inicio'], 0, 10) === date('Y-m-d');
    $pacTxt = nombre_paciente($t);
    $operar = puede_operar_turno($t);
    $esMio = es_profesional() && (int)$t['profesional_id'] === (int)mi_profesional_id(); ?>
<div class="grid-2-1">
  <div>
    <div class="card">
      <div class="turno-cab">
        <div style="flex:1">
          <div class="turno-cuando"><?= e(ucfirst(fecha_relativa($t['inicio']))) ?>, <?= hora($t['inicio']) ?> a <?= hora($t['fin']) ?></div>
          <p class="apagado" style="margin:2px 0 0"><?= e(ucfirst(fecha_larga($t['inicio']))) ?> · <?= duracion_txt((strtotime($t['fin']) - strtotime($t['inicio'])) / 60) ?><?= $t['sobreturno'] ? ' · <b>sobreturno</b>' : '' ?></p>
        </div>
        <?= badge_turno($t['estado']) ?>
      </div>
      <?php if ($verHc && trim((string)$t['antecedentes']) !== ''): ?>
        <div class="antecedentes mt"><b>Antecedentes</b><?= e($t['antecedentes']) ?></div>
      <?php endif; ?>
      <dl class="datos mt">
        <dt>Paciente</dt><dd><a href="<?= url('paciente.php?id=' . $t['paciente_id']) ?>"><?= e($pacTxt) ?></a><?= $t['fecha_nac'] ? ' · ' . e(edad_txt($t['fecha_nac'])) : '' ?><?= $t['dni'] ? ' · DNI ' . e(dni_txt($t['dni'])) : '' ?><?= $t['telefono'] ? '<br><small>' . e($t['telefono']) . '</small>' : '' ?></dd>
        <dt>Cobertura</dt><dd><span class="cob<?= $t['obra_social'] ? '' : ' cob-part' ?>"><?= e(cobertura_txt($t['obra_social'])) ?></span>
          <?php if ($t['obra_social'] && (int)$t['obra_social_id'] === (int)$t['paciente_os_id']): ?><small><?= $t['plan'] ? 'Plan ' . e($t['plan']) . ' · ' : '' ?><?= $t['nro_afiliado'] ? 'Afiliado ' . e($t['nro_afiliado']) : 'sin nº de afiliado cargado' ?></small><?php endif; ?></dd>
        <dt>Prestación</dt><dd><?= e($t['prestacion_nombre']) ?><?= $t['especialidad'] ? ' <small>· ' . e($t['especialidad']) . '</small>' : '' ?>
          <?php if ($t['practica_codigo'] && !es_profesional()): ?><br><small>Código <b><?= e($t['practica_codigo']) ?></b><?= $t['practica_nomenclador'] ? ' · ' . e($t['practica_nomenclador']) : '' ?></small><?php endif; ?></dd>
        <dt>Profesional</dt><dd><span class="punto" style="--c: <?= e($t['color']) ?>"></span><?= e($t['profesional']) ?><?= $t['matricula'] ? ' <small>' . e($t['matricula']) . '</small>' : '' ?><?= !empty($t['asignado_auto']) ? ' <small>(asignado automáticamente)</small>' : '' ?></dd>
        <?php if ($t['consultorio']): ?><dt>Consultorio</dt><dd><?= e($t['consultorio']) ?></dd><?php endif; ?>
        <?php if (!es_profesional()): ?>
        <dt><?= $t['obra_social'] ? 'Coseguro' : 'Precio' ?></dt><dd><?= money($t['precio']) ?>
          <?php if ($t['cobro_id']): ?> <span class="badge b-cobrado">Cobrado</span> <a href="<?= url('recibo.php?id=' . $t['cobro_id']) ?>">ver recibo</a>
          <?php elseif ($t['precio'] > 0 && $t['estado'] !== 'cancelado'): ?> <span class="badge b-impago">Sin cobrar</span>
          <?php elseif ($t['precio'] == 0): ?> <small>(no paga nada en el centro)</small><?php endif; ?></dd>
        <?php endif; ?>
        <?php if ($t['notas']): ?><dt>Aclaraciones</dt><dd><?= e($t['notas']) ?></dd><?php endif; ?>
        <?php if ($t['llegada']): ?><dt>Llegó</dt><dd><?= hora($t['llegada']) ?> hs<?= $t['estado'] === 'presente' ? ' · <span class="espera">espera ' . e(espera_txt($t['llegada'])) . '</span>' : '' ?></dd><?php endif; ?>
        <?php if ($t['estado'] === 'cancelado' && $t['motivo_cancelado']): ?><dt>Motivo</dt><dd><?= e($t['motivo_cancelado']) ?></dd><?php endif; ?>
        <?php if ($t['recordado']): ?><dt>Recordatorio</dt><dd>Enviado <?= e(fecha_relativa($t['recordado'])) ?> a las <?= hora($t['recordado']) ?></dd><?php endif; ?>
        <dt>Agendado</dt><dd class="apagado"><?= e(fecha_hora($t['creado'])) ?><?= $t['creado_por'] ? ' por ' . e($t['creado_por']) : '' ?><?= $t['origen'] === 'online' ? ' (reserva online)' : '' ?></dd>
      </dl>

      <?php if ($t['estado'] !== 'cancelado' && ($operar || puede('turnos'))): ?>
      <div class="mt">
        <form method="post" class="estados">
          <?= csrf_field() ?><input type="hidden" name="accion" value="estado">
          <?php if (puede('turnos') && $t['estado'] === 'reservado'): ?><button class="btn btn-ok" name="estado" value="confirmado">Confirmar</button><?php endif; ?>
          <?php if ($operar): ?>
            <?php if (in_array($t['estado'], ['reservado', 'confirmado'], true)): ?><button class="btn<?= ($esHoy || $pasado) && !es_profesional() ? ' btn-pri' : '' ?>" name="estado" value="presente">Llegó: a la sala de espera</button><?php endif; ?>
            <?php if (in_array($t['estado'], ['presente'], true) || ($esMio && in_array($t['estado'], ['reservado', 'confirmado'], true))): ?><button class="btn<?= $t['estado'] === 'presente' && es_profesional() ? ' btn-pri' : '' ?>" name="estado" value="en_atencion">Pasa a atención</button><?php endif; ?>
            <?php if (in_array($t['estado'], ['presente', 'en_atencion'], true) || ($esMio && in_array($t['estado'], ['reservado', 'confirmado'], true))): ?><button class="btn<?= $t['estado'] === 'en_atencion' ? ' btn-pri' : '' ?>" name="estado" value="atendido">Marcar atendido</button><?php endif; ?>
            <?php if (in_array($t['estado'], ['reservado', 'confirmado', 'presente'], true) && !$t['cobro_id']): ?><button class="btn btn-peligro" name="estado" value="ausente" data-confirm="¿Marcar que no vino?">No vino</button><?php endif; ?>
          <?php endif; ?>
          <?php if (puede('turnos') && in_array($t['estado'], ['presente', 'en_atencion', 'atendido', 'ausente'], true)): ?>
            <button class="link" name="estado" value="confirmado">Volver a confirmado</button>
          <?php endif; ?>
        </form>
      </div>
      <?php endif; ?>

      <?php if (puede('turnos')): ?>
      <div class="fila mt">
        <?php if (!$t['cobro_id'] && $t['precio'] > 0 && $t['estado'] !== 'cancelado' && puede('cobrar')): ?>
          <a class="btn btn-pri" href="<?= url('cobro.php?paciente=' . $t['paciente_id'] . '&turno=' . $id) ?>">Cobrar <?= money($t['precio']) ?></a>
        <?php endif; ?>
        <?php if ($t['estado'] !== 'cancelado'): ?><a class="btn" href="<?= url('turno.php?id=' . $id . '&editar') ?>">Reprogramar o editar</a><?php endif; ?>
        <?php if ($t['telefono'] && in_array($t['estado'], ['reservado', 'confirmado'], true)): ?>
          <form method="post" class="inline" target="_blank"><?= csrf_field() ?><input type="hidden" name="accion" value="whatsapp">
            <button class="btn btn-wa" type="submit"><?= $t['recordado'] ? 'Volver a enviar recordatorio' : 'Recordatorio por WhatsApp' ?></button></form>
        <?php endif; ?>
        <?php if (in_array($t['estado'], ['reservado', 'confirmado'], true) && !$pasado && ($waProf = whatsapp_link($t['profesional_telefono'], mensaje_aviso_profesional('resumen', $t, $t['profesional'])))): ?>
          <a class="btn btn-wa" href="<?= e($waProf) ?>" target="_blank" rel="noopener">Avisar a <?= e(nombre_corto_profesional($t['profesional'])) ?></a>
        <?php endif; ?>
        <a class="btn" href="<?= url('turno.php?paciente=' . $t['paciente_id']) ?>">Darle otro turno</a>
        <span class="espacio"></span>
        <?php if ($t['estado'] !== 'cancelado' && !$t['cobro_id']): ?><button class="btn btn-peligro" type="button" data-abrir="modal-cancelar">Cancelar turno</button><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($verHc): ?>
    <div class="card" id="evolucion">
      <h2>Evolución</h2>
      <?php if ($evolucion):
          $ev = q_row('SELECT e.*, p.nombre AS profesional, p.matricula, p.color, NULL AS especialidad FROM evoluciones e JOIN profesionales p ON p.id = e.profesional_id WHERE e.id = ?', [$evolucion['id']]); ?>
        <?= evolucion_html($ev) ?>
      <?php elseif ($esMio && !in_array($t['estado'], ['cancelado', 'ausente'], true)): ?>
        <p class="hc-aviso">Queda en la historia clínica. La podés corregir durante <?= HC_HORAS_EDICION ?> horas; después queda cerrada.</p>
        <form method="post" action="<?= url('evolucion.php') ?>" class="form-evolucion">
          <?= csrf_field() ?><input type="hidden" name="turno_id" value="<?= $id ?>">
          <div class="campo"><label for="motivo">Motivo de consulta</label><input type="text" id="motivo" name="motivo" maxlength="255"></div>
          <div class="campo"><label for="texto">Evolución</label><textarea id="texto" name="texto" rows="6" required placeholder="Anamnesis, examen físico, estudios, conducta"></textarea></div>
          <?= campo_cie10('', '') ?>
          <div class="campo campo-chico"><label for="indicaciones">Indicaciones</label><textarea id="indicaciones" name="indicaciones" rows="2" placeholder="Medicación, estudios pedidos, próximo control"></textarea></div>
          <div class="fila">
            <button class="btn btn-pri" type="submit">Guardar evolución</button>
            <?php if ($t['estado'] !== 'atendido'): ?><label class="check"><input type="checkbox" name="atendido" value="1" checked> Marcar el turno como atendido</label><?php endif; ?>
          </div>
        </form>
      <?php else: ?>
        <p class="apagado"><?= $esMio ? 'El turno quedó ' . e(mb_strtolower(estado_turno_txt($t['estado']))) . ': no lleva evolución.' : 'Todavía no tiene evolución. La escribe el profesional del turno.' ?></p>
      <?php endif; ?>
      <?php $recetasTurno = q_all("SELECT r.*, (SELECT GROUP_CONCAT(i.droga SEPARATOR ', ') FROM receta_items i WHERE i.receta_id = r.id) AS drogas FROM recetas r WHERE r.turno_id = ? ORDER BY r.id", [$id]);
            $ordenesTurno = q_all("SELECT o.*, (SELECT GROUP_CONCAT(i.descripcion ORDER BY i.orden SEPARATOR '|') FROM orden_items i WHERE i.orden_id = o.id) AS practicas FROM ordenes o WHERE o.turno_id = ? ORDER BY o.id", [$id]); ?>
      <?php if ($recetasTurno || $ordenesTurno || ($esMio && !in_array($t['estado'], ['cancelado', 'ausente'], true))): ?>
        <div class="recetas-turno mt">
          <?php foreach ($recetasTurno as $rx): ?>
            <div class="fila"><a href="<?= url('receta.php?id=' . $rx['id']) ?>">Receta<?= $rx['numero'] ? ' N.º ' . sprintf('%06d', $rx['numero']) : '' ?></a> <small><?= e(droga_txt($rx['drogas'])) ?></small> <?= badge_receta($rx['estado']) ?></div>
          <?php endforeach; ?>
          <?php foreach ($ordenesTurno as $ox): ?>
            <div class="fila"><a href="<?= url('orden.php?id=' . $ox['id']) ?>"><?= e(orden_titulo($ox['tipo'])) ?><?= $ox['numero'] ? ' N.º ' . sprintf('%06d', $ox['numero']) : '' ?></a> <small><?= e(orden_resumen($ox['practicas'])) ?></small> <?= badge_receta($ox['estado']) ?></div>
          <?php endforeach; ?>
          <?php if ($esMio && !in_array($t['estado'], ['cancelado', 'ausente'], true)): ?>
            <div class="fila mt">
              <a class="btn btn-chico" href="<?= url('receta.php?paciente=' . $t['paciente_id'] . '&turno=' . $id) ?>">Hacer receta</a>
              <a class="btn btn-chico" href="<?= url('orden.php?paciente=' . $t['paciente_id'] . '&turno=' . $id . '&tipo=laboratorio') ?>">Orden de laboratorio</a>
              <a class="btn btn-chico" href="<?= url('orden.php?paciente=' . $t['paciente_id'] . '&turno=' . $id . '&tipo=estudios') ?>">Orden de estudios</a>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <?php if ($verHc): ?>
    <div class="card">
      <div class="card-cab"><h2>Historia clínica</h2><a href="<?= url('historia.php?id=' . $t['paciente_id']) ?>">Ver completa</a></div>
      <?php if (!$evoluciones): ?>
        <p class="apagado">No tiene otras evoluciones.</p>
      <?php else: foreach (array_slice($evoluciones, 0, 4) as $ev): ?>
        <div class="evolucion" style="--c: <?= e($ev['color']) ?>">
          <header><b><?= e(fecha($ev['fecha'])) ?></b> · <?= e($ev['profesional']) ?></header>
          <?php if ($ev['diagnostico']): ?><p><b><?= e($ev['diagnostico']) ?></b></p><?php endif; ?>
          <p><small><?= e(mb_strimwidth($ev['motivo'] ? $ev['motivo'] . '. ' . $ev['texto'] : $ev['texto'], 0, 160, '…')) ?></small></p>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-cab"><h2><?= e($pacTxt) ?></h2><a href="<?= url('paciente.php?id=' . $t['paciente_id']) ?>">Ver ficha</a></div>
      <h3>Últimos turnos</h3>
      <?php if (!$historial): ?>
        <p class="apagado">Es su primera vez en el centro.</p>
      <?php else: ?>
        <table class="tabla">
          <?php foreach ($historial as $h): ?>
            <tr><td class="nowrap"><a href="<?= url('turno.php?id=' . $h['id']) ?>"><?= e(fecha($h['inicio'])) ?></a></td>
                <td><?= e($h['prestacion_nombre']) ?><br><small><?= e($h['profesional']) ?></small></td>
                <td class="der"><?= $h['estado'] === 'ausente' ? badge_turno('ausente') : '' ?></td></tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      <?php if ($proximos): ?>
        <h3 class="mt">Próximos turnos</h3>
        <table class="tabla">
          <?php foreach ($proximos as $h): ?>
            <tr><td class="nowrap"><a href="<?= url('turno.php?id=' . $h['id']) ?>"><?= e(fecha($h['inicio'])) ?> <?= hora($h['inicio']) ?></a></td><td><?= e($h['prestacion_nombre']) ?><br><small><?= e($h['profesional']) ?></small></td></tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal-fondo" id="modal-cancelar">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="estado" value="cancelado">
    <h2>Cancelar turno</h2>
    <p><?= e($pacTxt) ?>, <?= e($t['prestacion_nombre']) ?> con <?= e($t['profesional']) ?>, <?= e(fecha_relativa($t['inicio'])) ?> a las <?= hora($t['inicio']) ?>. El horario queda libre.</p>
    <div class="campo"><label for="motivo-c">Motivo (opcional)</label><input type="text" id="motivo-c" name="motivo" maxlength="255" placeholder="Ej: avisó por teléfono que no puede venir"></div>
    <div class="fila"><button class="btn btn-peligro" type="submit">Cancelar turno</button><button class="btn" type="button" data-cerrar>Volver</button></div>
  </form>
</div>

<?php else: /* ---------- Formulario: nuevo o reprogramar ---------- */ ?>
<form method="post" class="grid-2-1" id="form-turno" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="accion" value="guardar">
  <div class="card">
    <?php if ($errores): ?>
      <div class="aviso aviso-error"><?= count($errores) === 1 ? e($errores[0]) : '<ul><li>' . implode('</li><li>', array_map('e', $errores)) . '</li></ul>' ?></div>
    <?php endif; ?>
    <?php if (!$errores && $avisos): ?>
      <div class="aviso aviso-alerta avisos-turno">
        <b>Revisá antes de guardar:</b>
        <ul><?php foreach ($avisos as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
        <label class="check mt"><input type="checkbox" name="forzar" value="1"> Entendido, guardar igual</label>
      </div>
    <?php endif; ?>

    <div class="campo" id="bloque-paciente">
      <label for="buscar-paciente">Paciente</label>
      <input type="hidden" name="paciente_id" id="paciente_id" value="<?= $pacSel ? (int)$pacSel['id'] : '' ?>">
      <div class="paciente-elegido" id="paciente-elegido"<?= $pacSel ? '' : ' hidden' ?>>
        <b id="paciente-nombre"><?= $pacSel ? e(nombre_paciente($pacSel)) : '' ?></b>
        <small id="paciente-datos"><?= $pacSel ? e(trim(($pacSel['dni'] ? 'DNI ' . dni_txt($pacSel['dni']) : '') . ($pacSel['telefono'] ? ' · ' . $pacSel['telefono'] : ''), ' ·')) : '' ?></small>
        <?php if (!$t): ?><button class="link" type="button" id="cambiar-paciente">Cambiar</button><?php endif; ?>
      </div>
      <div class="rel" id="buscador-paciente"<?= $pacSel ? ' hidden' : '' ?>>
        <input type="search" id="buscar-paciente" placeholder="Buscá por apellido, nombre, DNI, teléfono o nº de afiliado">
        <div class="sugerencias" id="sugerencias" hidden></div>
        <small><button class="link" type="button" id="btn-nuevo">Es nuevo: cargar sus datos</button></small>
      </div>
      <div id="paciente-nuevo"<?= $f['pac_nombre'] !== '' && !$pacSel ? '' : ' hidden' ?> class="mt">
        <div class="form-fila">
          <div class="campo"><label for="pac_nombre">Nombre</label><input type="text" id="pac_nombre" name="pac_nombre" value="<?= e($f['pac_nombre']) ?>" maxlength="80"></div>
          <div class="campo"><label for="pac_apellido">Apellido</label><input type="text" id="pac_apellido" name="pac_apellido" value="<?= e($f['pac_apellido']) ?>" maxlength="80"></div>
        </div>
        <div class="form-fila">
          <div class="campo"><label for="pac_dni">DNI</label><input type="text" inputmode="numeric" id="pac_dni" name="pac_dni" value="<?= e($f['pac_dni']) ?>" maxlength="15"></div>
          <div class="campo"><label for="pac_telefono">Teléfono</label><input type="tel" id="pac_telefono" name="pac_telefono" value="<?= e($f['pac_telefono']) ?>" maxlength="40" placeholder="11 5555-1234"></div>
          <div class="campo" id="campo-afiliado"><label for="pac_afiliado">Nº de afiliado</label><input type="text" id="pac_afiliado" name="pac_afiliado" value="<?= e($f['pac_afiliado']) ?>" maxlength="40"></div>
        </div>
        <small class="apagado">La obra social es la que elijas abajo en «Cobertura». El resto de la ficha se completa después desde Pacientes.</small>
      </div>
    </div>

    <div class="form-fila fila-servicio">
      <div class="campo">
        <label for="especialidad_id">Especialidad</label>
        <select id="especialidad_id" name="especialidad_id">
          <option value="0">Todas</option>
          <?= opciones($especialidades, $f['especialidad_id']) ?>
        </select>
      </div>
      <div class="campo">
        <label for="prestacion_id">Prestación</label>
        <select id="prestacion_id" name="prestacion_id" required>
          <option value="">Elegí la prestación</option>
          <?php $grupo = false; foreach ($prestaciones as $s):
              if ($s['especialidad'] !== $grupo) { echo $grupo !== false ? '</optgroup>' : ''; $grupo = $s['especialidad']; echo '<optgroup label="' . e($grupo ?: 'Sin especialidad') . '" data-esp="' . (int)$s['especialidad_id'] . '">'; } ?>
            <option value="<?= (int)$s['id'] ?>" data-esp="<?= (int)$s['especialidad_id'] ?>" data-dur="<?= (int)$s['duracion'] ?>" data-precio="<?= (float)$s['precio'] ?>" data-cons="<?= (int)$s['consultorio_id'] ?>" data-profs="<?= e(implode(',', $s['profesionales'])) ?>"<?= (int)$s['id'] === $f['prestacion_id'] ? ' selected' : '' ?>><?= e($s['nombre']) ?><?= $s['duracion'] ? ' · ' . duracion_txt($s['duracion']) : '' ?> · <?= money($s['precio']) ?></option>
          <?php endforeach; echo $grupo !== false ? '</optgroup>' : ''; ?>
        </select>
      </div>
    </div>

    <div class="form-fila">
      <div class="campo">
        <label for="profesional_id">Profesional</label>
        <select id="profesional_id" name="profesional_id" required>
          <option value="auto" data-auto<?= $f['profesional_id'] === 'auto' ? ' selected' : '' ?>>Cualquier profesional libre</option>
          <?php foreach ($profs as $p): ?><option value="<?= (int)$p['id'] ?>" data-esps="<?= e(implode(',', especialidades_de_profesional($p['id']))) ?>" data-dur="<?= (int)$p['duracion_turno'] ?>" data-os="<?= e(implode(',', obras_sociales_de_profesional($p['id']))) ?>"<?= $f['profesional_id'] !== 'auto' && (int)$p['id'] === (int)$f['profesional_id'] ? ' selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="consultorio_id">Consultorio</label>
        <select id="consultorio_id" name="consultorio_id">
          <option value="">Según el horario del profesional</option>
          <?= opciones($consultorios, $f['consultorio_id']) ?>
        </select>
      </div>
    </div>

    <div class="form-fila">
      <div class="campo"><label for="fecha">Fecha</label><input type="date" id="fecha" name="fecha" value="<?= e($f['fecha']) ?>" required></div>
      <div class="campo"><label for="hora">Hora</label><input type="time" id="hora" name="hora" value="<?= e($f['hora']) ?>" step="300" required></div>
      <div class="campo"><label for="duracion">Duración (min)</label><input type="number" id="duracion" name="duracion" value="<?= $f['duracion'] ?: '' ?>" min="5" max="600" step="5" required>
        <small id="termina"></small></div>
    </div>
    <div id="dia-prof" class="aviso aviso-info" hidden></div>

    <div class="form-fila">
      <div class="campo">
        <label for="obra_social_id">Cobertura</label>
        <select id="obra_social_id" name="obra_social_id">
          <option value="0" data-coseguro="">Particular</option>
          <?php foreach ($obras as $o): ?><option value="<?= (int)$o['id'] ?>" data-coseguro="<?= (float)$o['coseguro'] ?>"<?= (int)$o['id'] === (int)$f['obra_social_id'] ? ' selected' : '' ?>><?= e($o['nombre']) ?></option><?php endforeach; ?>
        </select>
        <small id="cob-aviso"></small>
      </div>
      <div class="campo">
        <label for="precio" id="precio-label"><?= $f['obra_social_id'] ? 'Coseguro $' : 'Precio particular $' ?></label>
        <input type="text" inputmode="decimal" id="precio" name="precio" value="<?= e($f['precio']) ?>"<?= $t && $t['cobro_id'] ? ' disabled' : '' ?>>
        <?php if ($t && $t['cobro_id']): ?><small>Ya está cobrado: el importe no se cambia.</small><?php else: ?><small>Lo que paga el paciente en el centro.</small><?php endif; ?>
      </div>
    </div>
    <div class="campo"><label for="notas">Aclaraciones</label><input type="text" id="notas" name="notas" value="<?= e($f['notas']) ?>" maxlength="255" placeholder="Ej: viene con acompañante, trae estudios, primera vez"></div>

    <div class="campo">
      <label class="check"><input type="checkbox" name="sobreturno" value="1"<?= $f['sobreturno'] ? ' checked' : '' ?>> Sobreturno</label>
      <?php if (!$t): ?><label class="check"><input type="checkbox" name="confirmado" value="1"<?= $f['confirmado'] ? ' checked' : '' ?>> Ya está confirmado</label><?php endif; ?>
    </div>

    <div class="fila">
      <button class="btn btn-pri btn-grande" type="submit"><?= $t ? 'Guardar cambios' : 'Agendar turno' ?></button>
      <?php if (!$t): ?><button class="btn" type="submit" name="otro" value="1" title="Guarda y abre otro turno para el mismo paciente a continuación">Agendar y sumar otro</button><?php endif; ?>
      <a class="btn" href="<?= $t ? url('turno.php?id=' . $id) : url('agenda.php?fecha=' . e($f['fecha'])) ?>">Volver</a>
    </div>
  </div>

  <div class="card" id="panel-paciente"<?= $pacSel ? '' : ' hidden' ?>>
    <h2>Paciente</h2>
    <div id="ficha-paciente"></div>
  </div>
</form>
<?php endif; ?>

<?php layout_footer($editar ? ['assets/turno.js'] : []); ?>
