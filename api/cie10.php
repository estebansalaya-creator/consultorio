<?php
/*
 * Búsqueda en la CIE-10 por código o por palabras.
 * GET ?q=texto
 */
require __DIR__ . '/../inc/bootstrap.php';
requiere('cie10');

$q = trim((string)get('q', ''));
json_out(['ok' => true, 'codigos' => array_map(function ($c) {
    return ['codigo' => $c['codigo'], 'descripcion' => $c['descripcion'], 'categoria' => (int)$c['nivel'] === 3];
}, mb_strlen($q) >= 1 ? cie10_buscar($q, 25) : [])]);
