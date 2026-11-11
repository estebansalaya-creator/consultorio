<?php
/*
 * Historia clínica: antecedentes del paciente y evoluciones.
 *
 * Por confidencialidad (Ley 26.529 de Derechos del Paciente), la historia clínica la ven y la
 * escriben sólo los usuarios con rol Profesional. Recepción y el administrador ven los datos
 * del paciente, su cobertura, sus turnos y sus cobros, pero no los antecedentes ni las evoluciones.
 *
 * Es una historia clínica única: el profesional que puede ver al paciente ve todas sus evoluciones,
 * las haya escrito quien las haya escrito. Cada evolución la puede corregir sólo quien la escribió,
 * durante las primeras HC_HORAS_EDICION horas; después queda cerrada (no se modifica ni se borra).
 */

const HC_HORAS_EDICION = 24;

/** Puede ver y escribir historia clínica (rol profesional vinculado a un profesional) */
function puede_hc()
{
    return es_profesional() && mi_profesional_id();
}

/** Puede ver la historia clínica de este paciente */
function puede_ver_hc($pacienteId)
{
    return puede_hc() && puede_ver_paciente($pacienteId);
}

/** Evoluciones del paciente, de la más reciente a la más vieja */
function evoluciones_de($pacienteId, $limite = 0)
{
    return q_all(
        'SELECT e.*, p.nombre AS profesional, p.matricula, p.color,
                es.nombre AS especialidad, t.prestacion_nombre
           FROM evoluciones e
           JOIN profesionales p ON p.id = e.profesional_id
           LEFT JOIN turnos t ON t.id = e.turno_id
           LEFT JOIN especialidades es ON es.id = t.especialidad_id
          WHERE e.paciente_id = ?
          ORDER BY e.fecha DESC, e.id DESC' . ($limite ? ' LIMIT ' . (int)$limite : ''),
        [(int)$pacienteId]
    );
}

function evolucion_get($id)
{
    return q_row('SELECT * FROM evoluciones WHERE id = ?', [(int)$id]);
}

function evolucion_de_turno($turnoId)
{
    return q_row('SELECT * FROM evoluciones WHERE turno_id = ?', [(int)$turnoId]);
}

/** Quien la escribió puede corregirla durante las primeras horas */
function evolucion_editable(array $ev)
{
    return puede_hc()
        && (int)$ev['profesional_id'] === (int)mi_profesional_id()
        && strtotime($ev['creado']) > time() - HC_HORAS_EDICION * 3600;
}

/** Hasta cuándo se puede corregir: "hasta mañana a las 10:32" */
function evolucion_editable_hasta(array $ev)
{
    $limite = date('Y-m-d H:i:s', strtotime($ev['creado']) + HC_HORAS_EDICION * 3600);
    return 'hasta ' . fecha_relativa($limite) . ' a las ' . hora($limite);
}

/**
 * Crea o corrige una evolución.
 * $d: id (para corregir), paciente_id, turno_id, fecha, motivo, texto, diagnostico, indicaciones
 * @return int id de la evolución
 * @throws RuntimeException con un mensaje para mostrar
 */
function guardar_evolucion(array $d)
{
    if (!puede_hc()) {
        throw new RuntimeException('Sólo los profesionales pueden escribir en la historia clínica.');
    }
    $texto = trim((string)$d['texto']);
    if ($texto === '') {
        throw new RuntimeException('Escribí la evolución.');
    }
    $vals = [
        'motivo'       => mb_substr(trim((string)$d['motivo']), 0, 255) ?: null,
        'texto'        => $texto,
        'diagnostico'  => mb_substr(trim((string)$d['diagnostico']), 0, 255) ?: null,
        'cie10_codigo' => null,
        'indicaciones' => trim((string)$d['indicaciones']) ?: null,
    ];
    if (!empty($d['cie10_codigo'])) {
        $c = cie10_get($d['cie10_codigo']);
        if (!$c) {
            throw new RuntimeException('El código CIE-10 «' . $d['cie10_codigo'] . '» no existe. Elegilo de la lista.');
        }
        $vals['cie10_codigo'] = $c['codigo'];
        if ($vals['diagnostico'] === null) {
            $vals['diagnostico'] = $c['descripcion'];
        }
    }

    if (!empty($d['id'])) {
        $ev = evolucion_get($d['id']);
        if (!$ev) {
            throw new RuntimeException('La evolución no existe.');
        }
        if (!evolucion_editable($ev)) {
            throw new RuntimeException('Esta evolución ya está cerrada: sólo quien la escribió puede corregirla, y durante las primeras ' . HC_HORAS_EDICION . ' horas. Podés agregar una evolución nueva con la aclaración.');
        }
        q('UPDATE evoluciones SET motivo = ?, texto = ?, diagnostico = ?, cie10_codigo = ?, indicaciones = ?, actualizado = NOW() WHERE id = ?',
          [$vals['motivo'], $vals['texto'], $vals['diagnostico'], $vals['cie10_codigo'], $vals['indicaciones'], $ev['id']]);
        return (int)$ev['id'];
    }

    $pacienteId = (int)$d['paciente_id'];
    $turnoId = !empty($d['turno_id']) ? (int)$d['turno_id'] : null;
    $fecha = !empty($d['fecha']) ? $d['fecha'] : date('Y-m-d H:i:s');
    if ($turnoId) {
        $t = q_row('SELECT * FROM turnos WHERE id = ?', [$turnoId]);
        if (!$t || (int)$t['profesional_id'] !== (int)mi_profesional_id()) {
            throw new RuntimeException('Sólo el profesional del turno puede escribir su evolución.');
        }
        if (evolucion_de_turno($turnoId)) {
            throw new RuntimeException('Ese turno ya tiene su evolución.');
        }
        $pacienteId = (int)$t['paciente_id'];
        $fecha = $t['inicio'];
    }
    if (!$pacienteId || !puede_ver_paciente($pacienteId)) {
        throw new RuntimeException('No tenés acceso a ese paciente.');
    }
    q('INSERT INTO evoluciones (paciente_id, turno_id, profesional_id, usuario_id, fecha, motivo, texto, diagnostico, cie10_codigo, indicaciones)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$pacienteId, $turnoId, mi_profesional_id(), usuario_id(), $fecha, $vals['motivo'], $vals['texto'], $vals['diagnostico'], $vals['cie10_codigo'], $vals['indicaciones']]);
    return (int)db()->lastInsertId();
}

/** Bloque HTML de una evolución (para la ficha, el turno y la historia completa) */
function evolucion_html(array $ev, $conAcciones = true)
{
    $h = '<article class="evolucion" style="--c: ' . e($ev['color']) . '">';
    $h .= '<header><b>' . e(fecha($ev['fecha'])) . '</b> <span class="apagado">' . e(hora($ev['fecha'])) . '</span> · '
        . e($ev['profesional']) . ($ev['matricula'] ? ' <small>(' . e($ev['matricula']) . ')</small>' : '')
        . ($ev['especialidad'] ? ' · <span class="apagado">' . e($ev['especialidad']) . '</span>' : '')
        . (!$ev['turno_id'] ? ' <span class="badge">Sin turno</span>' : '') . '</header>';
    if ($ev['motivo']) {
        $h .= '<p><span class="ev-et">Motivo</span>' . e($ev['motivo']) . '</p>';
    }
    $h .= '<p class="ev-texto">' . nl2br(e($ev['texto'])) . '</p>';
    if ($ev['diagnostico'] || !empty($ev['cie10_codigo'])) {
        $h .= '<p><span class="ev-et">Diagnóstico</span>' . (!empty($ev['cie10_codigo']) ? '<span class="cie" title="CIE-10">' . e($ev['cie10_codigo']) . '</span> ' : '') . '<b>' . e($ev['diagnostico']) . '</b></p>';
    }
    if ($ev['indicaciones']) {
        $h .= '<p><span class="ev-et">Indicaciones</span>' . nl2br(e($ev['indicaciones'])) . '</p>';
    }
    if ($conAcciones && evolucion_editable($ev)) {
        $h .= '<p class="no-print"><a href="' . url('evolucion.php?id=' . (int)$ev['id']) . '">Corregir</a> <small>(' . e(evolucion_editable_hasta($ev)) . ')</small></p>';
    }
    if ($ev['actualizado']) {
        $h .= '<p><small>Corregida el ' . e(fecha_hora($ev['actualizado'])) . '</small></p>';
    }
    return $h . '</article>';
}

/* ---------- CIE-10 ---------- */

/** Capítulos de la CIE-10: [número romano, desde, hasta, nombre] */
function cie10_capitulos()
{
    return [
        ['I', 'A00', 'B99', 'Ciertas enfermedades infecciosas y parasitarias'],
        ['II', 'C00', 'D48', 'Neoplasias'],
        ['III', 'D50', 'D89', 'Enfermedades de la sangre y de los órganos hematopoyéticos, y ciertos trastornos de la inmunidad'],
        ['IV', 'E00', 'E90', 'Enfermedades endocrinas, nutricionales y metabólicas'],
        ['V', 'F00', 'F99', 'Trastornos mentales y del comportamiento'],
        ['VI', 'G00', 'G99', 'Enfermedades del sistema nervioso'],
        ['VII', 'H00', 'H59', 'Enfermedades del ojo y sus anexos'],
        ['VIII', 'H60', 'H95', 'Enfermedades del oído y de la apófisis mastoides'],
        ['IX', 'I00', 'I99', 'Enfermedades del sistema circulatorio'],
        ['X', 'J00', 'J99', 'Enfermedades del sistema respiratorio'],
        ['XI', 'K00', 'K93', 'Enfermedades del aparato digestivo'],
        ['XII', 'L00', 'L99', 'Enfermedades de la piel y del tejido subcutáneo'],
        ['XIII', 'M00', 'M99', 'Enfermedades del sistema osteomuscular y del tejido conjuntivo'],
        ['XIV', 'N00', 'N99', 'Enfermedades del aparato genitourinario'],
        ['XV', 'O00', 'O99', 'Embarazo, parto y puerperio'],
        ['XVI', 'P00', 'P96', 'Ciertas afecciones originadas en el período perinatal'],
        ['XVII', 'Q00', 'Q99', 'Malformaciones congénitas, deformidades y anomalías cromosómicas'],
        ['XVIII', 'R00', 'R99', 'Síntomas, signos y hallazgos anormales clínicos y de laboratorio'],
        ['XIX', 'S00', 'T98', 'Traumatismos, envenenamientos y otras consecuencias de causas externas'],
        ['XX', 'V01', 'Y98', 'Causas externas de morbilidad y de mortalidad'],
        ['XXI', 'Z00', 'Z99', 'Factores que influyen en el estado de salud y contacto con los servicios de salud'],
        ['XXII', 'U00', 'U99', 'Códigos para propósitos especiales'],
    ];
}

/** Capítulo de un código: ['IX', 'I00', 'I99', 'Enfermedades del sistema circulatorio'] o null */
function cie10_capitulo($codigo)
{
    $cat = strtoupper(substr((string)$codigo, 0, 3));
    foreach (cie10_capitulos() as $c) {
        if ($cat >= $c[1] && $cat <= $c[2]) {
            return $c;
        }
    }
    return null;
}

/** "J029", "j02.9" o "J02.9 Faringitis…" -> "J02.9" */
function cie10_normalizar($s)
{
    $s = strtoupper(trim((string)$s));
    if (!preg_match('/^([A-Z])\s*(\d{2})\s*\.?\s*([0-9A-Z])?/', $s, $m)) {
        return null;
    }
    return $m[1] . $m[2] . (isset($m[3]) && $m[3] !== '' ? '.' . $m[3] : '');
}

function cie10_get($codigo)
{
    $c = cie10_normalizar($codigo);
    return $c ? q_row('SELECT * FROM cie10 WHERE codigo = ?', [$c]) : null;
}

/**
 * Busca por código ("J02", "j029") o por palabras de la descripción ("faringitis aguda").
 * Ordena primero las coincidencias exactas de código y las categorías de 3 caracteres.
 */
function cie10_buscar($texto, $limite = 25, $capitulo = '')
{
    $texto = trim((string)$texto);
    $params = [];
    $where = [];
    $cod = preg_match('/^[A-Za-z]\d/', $texto) ? cie10_normalizar($texto) : null;
    if (!$cod && preg_match('/^[A-Za-z]\d?$/', $texto)) {
        $cod = strtoupper($texto); // "J" o "J0": todos los que empiezan así
    }
    if ($cod) {
        $where[] = '(codigo LIKE ? OR codigo LIKE ?)';
        $params[] = $cod . '%';
        $params[] = str_replace('.', '', $cod) . '%';
    } elseif ($texto !== '') {
        foreach (preg_split('/\s+/', $texto) as $p) {
            if (mb_strlen($p) < 2) {
                continue;
            }
            $where[] = 'descripcion LIKE ?';
            $params[] = '%' . $p . '%';
        }
    }
    if ($capitulo !== '') {
        foreach (cie10_capitulos() as $c) {
            if ($c[0] === $capitulo) {
                $where[] = 'categoria BETWEEN ? AND ?';
                array_push($params, $c[1], $c[2]);
            }
        }
    }
    if (!$where) {
        return [];
    }
    return q_all(
        'SELECT codigo, descripcion, nivel FROM cie10 WHERE ' . implode(' AND ', $where) . '
          ORDER BY ' . ($cod ? '' : 'nivel, ') . 'codigo LIMIT ' . (int)$limite,
        $params
    );
}

/** Campo de diagnóstico con buscador CIE-10 (ver App.campoCie10 en assets/app.js) */
function campo_cie10($codigo, $texto, $label = 'Diagnóstico (CIE-10)', $id = 'diagnostico')
{
    return '<div class="campo js-cie10">'
        . '<label for="' . e($id) . '">' . e($label) . '</label>'
        . '<input type="hidden" name="cie10_codigo" value="' . e($codigo) . '">'
        . '<div class="cie-entrada">'
        . '<span class="cie-chip" hidden title="Código CIE-10"><b>' . e($codigo) . '</b><button type="button" title="Quitar el código" aria-label="Quitar el código">×</button></span>'
        . '<input type="text" id="' . e($id) . '" name="diagnostico" value="' . e($texto) . '" maxlength="255" autocomplete="off" placeholder="Buscá por código o palabra: J02, faringitis, hipertensión…">'
        . '</div><small>Elegí de la lista para que quede codificado; también se puede escribir libre.</small></div>';
}
