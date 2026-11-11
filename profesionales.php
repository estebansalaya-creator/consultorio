<?php
require __DIR__ . '/inc/bootstrap.php';
requiere('profesionales');

$profs = q_all(
    'SELECT p.*,
            (SELECT COUNT(*) FROM prestacion_profesional sp JOIN prestaciones s ON s.id = sp.prestacion_id WHERE sp.profesional_id = p.id AND s.activo = 1) AS prestaciones,
            (SELECT GROUP_CONCAT(c.nombre ORDER BY c.orden SEPARATOR ", ") FROM profesional_especialidades pc JOIN especialidades c ON c.id = pc.especialidad_id WHERE pc.profesional_id = p.id) AS especialidades,
            (SELECT GROUP_CONCAT(o.nombre ORDER BY o.orden SEPARATOR ", ") FROM profesional_obras_sociales po JOIN obras_sociales o ON o.id = po.obra_social_id WHERE po.profesional_id = p.id) AS obras,
            (SELECT GROUP_CONCAT(u.usuario SEPARATOR ", ") FROM usuarios u WHERE u.profesional_id = p.id AND u.activo = 1) AS usuarios,
            (SELECT COUNT(*) FROM turnos t WHERE t.profesional_id = p.id AND t.inicio >= CURDATE() AND t.estado IN ("reservado","confirmado")) AS proximos
       FROM profesionales p ORDER BY p.activo DESC, p.orden, p.nombre'
);
$horarios = [];
foreach (q_all('SELECT h.*, c.nombre AS consultorio FROM profesional_horarios h LEFT JOIN consultorios c ON c.id = h.consultorio_id ORDER BY h.dia, h.desde') as $h) {
    $horarios[(int)$h['profesional_id']][(int)$h['dia']][] = substr($h['desde'], 0, 5) . '–' . substr($h['hasta'], 0, 5) . ($h['consultorio'] ? ' (' . $h['consultorio'] . ')' : '');
}

/** "lun a vie 08:00–13:00 (Consultorio 1) · sáb 09:00–13:00" agrupando días iguales seguidos */
function resumen_horario(array $porDia)
{
    $grupos = [];
    foreach (range(1, 7) as $d) {
        $txt = isset($porDia[$d]) ? implode(' y ', $porDia[$d]) : null;
        $ult = end($grupos);
        if ($txt !== null && $ult && $ult['txt'] === $txt && $ult['hasta'] === $d - 1) {
            $grupos[key($grupos)]['hasta'] = $d;
        } elseif ($txt !== null) {
            $grupos[] = ['desde' => $d, 'hasta' => $d, 'txt' => $txt];
        }
    }
    return implode(' · ', array_map(function ($g) {
        $dias = DIAS_CORTOS[$g['desde']] . ($g['hasta'] > $g['desde'] ? ($g['hasta'] - $g['desde'] === 1 ? ' y ' : ' a ') . DIAS_CORTOS[$g['hasta']] : '');
        return $dias . ' ' . $g['txt'];
    }, $grupos));
}

layout_header('Profesionales', ['acciones' => '<a class="btn btn-pri" href="' . url('profesional_form.php') . '">Nuevo profesional</a>']);
?>
<div class="card">
<?php if (!$profs): ?>
  <div class="vacio">Cargá a los profesionales del centro con su matrícula, especialidades, horario y consultorio. Cada uno tiene su columna en la agenda.</div>
<?php else: ?>
  <div class="tabla-scroll">
  <table class="tabla">
    <thead><tr><th>Profesional</th><th>Especialidades</th><th>Horario y consultorio</th><th class="der">Turno</th><th>Coberturas</th><th class="der">Próximos</th><th>Usuario</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($profs as $p): ?>
      <tr class="<?= $p['activo'] ? '' : 'inactivo' ?>">
        <td><span class="punto" style="--c: <?= e($p['color']) ?>"></span><a href="<?= url('profesional_form.php?id=' . $p['id']) ?>"><?= e($p['nombre']) ?></a><?= $p['activo'] ? '' : ' <small>(inactivo)</small>' ?>
          <?php if ($p['matricula']): ?><br><small><?= e($p['matricula']) ?></small><?php endif; ?></td>
        <td><small><?= $p['especialidades'] ? e($p['especialidades']) : '<span style="color:var(--coral)">Sin especialidad</span>' ?></small></td>
        <td><small><?= isset($horarios[(int)$p['id']]) ? e(resumen_horario($horarios[(int)$p['id']])) : '<span style="color:var(--coral)">Sin horario cargado</span>' ?></small></td>
        <td class="der nowrap"><?= (int)$p['duracion_turno'] ?> min</td>
        <td><small><?= $p['obras'] ? e($p['obras']) : 'Todas' ?></small></td>
        <td class="der"><?= (int)$p['proximos'] ?></td>
        <td><small><?= e($p['usuarios']) ?></small></td>
        <td class="acciones">
          <a class="btn btn-chico" href="<?= url('agenda.php?vista=semana&prof=' . $p['id']) ?>">Agenda</a>
          <a class="btn btn-chico" href="<?= url('profesional_form.php?id=' . $p['id']) ?>">Editar</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>
<p class="apagado">Para que un profesional entre al sistema, vea su agenda y escriba la historia clínica, creale un usuario con el rol «Profesional» en <a href="<?= url('usuarios.php') ?>">Ajustes → Usuarios</a>.</p>
<?php layout_footer(); ?>
