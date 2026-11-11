<?php
/*
 * Búsqueda de códigos de un nomenclador: para vincularlos a las prestaciones y para las órdenes.
 * GET ?nom=N&q=texto
 */
require __DIR__ . '/../inc/bootstrap.php';
requiere();
if (!puede('prestaciones') && !puede_hc()) {
    json_out(['ok' => false, 'error' => 'Tu usuario no tiene acceso a esta sección.'], 403);
}

$nom = (int)get('nom', 0);
$q = trim((string)get('q', ''));
json_out(['ok' => true, 'practicas' => $nom && $q !== '' ? array_map(function ($p) {
    return ['id' => (int)$p['id'], 'codigo' => $p['codigo'], 'descripcion' => descripcion_practica($p['nombre']), 'nombre' => $p['nombre'], 'valor' => $p['valor'] !== null ? (float)$p['valor'] : null];
}, practicas_buscar($nom, $q, 25)) : []]);
