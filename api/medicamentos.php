<?php
/*
 * Búsqueda de medicamentos (listado oficial de precios + copia local), para la receta.
 * GET ?q=texto&online=1 (online=1 fuerza la consulta al listado oficial)
 */
require __DIR__ . '/../inc/bootstrap.php';
requiere('vademecum');

$q = trim((string)get('q', ''));
$r = medicamentos_buscar($q, (bool)get('online'));
json_out([
    'ok'           => true,
    'origen'       => $r['origen'],
    'aviso'        => $r['aviso'],
    'total'        => count($r['filas']),
    'medicamentos' => array_map(function ($m) {
        return [
            'clave'        => (int)$m['clave'],
            'nombre'       => $m['nombre'],
            'droga'        => (string)$m['droga'],
            'presentacion' => (string)$m['presentacion'],
            'laboratorio'  => (string)$m['laboratorio'],
            'forma'        => (string)$m['forma'],
            'tipo_venta'   => (string)$m['tipo_venta'],
            'precio'       => $m['precio'] !== null ? (float)$m['precio'] : null,
        ];
    }, array_slice($r['filas'], 0, 60)),
]);
