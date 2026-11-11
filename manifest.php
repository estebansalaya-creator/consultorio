<?php
/*
 * Manifiesto de la aplicación web (PWA): permite instalar el sistema como app
 * en la PC, el celular o la tablet. Se genera en PHP para usar el nombre del centro
 * y para que IIS no necesite el tipo MIME de .webmanifest.
 */
require __DIR__ . '/inc/bootstrap.php';

$centro = config_get('nombre_centro', 'Centro Médico');
$corto = mb_strlen($centro) > 14 ? 'Centro Médico' : $centro;
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode([
    'id'               => './',
    'name'             => $centro,
    'short_name'       => $corto,
    'description'      => 'Agenda, pacientes, historia clínica y caja del centro médico',
    'lang'             => 'es-AR',
    'start_url'        => './',
    'scope'            => './',
    'display'          => 'standalone',
    'background_color' => '#12304a',
    'theme_color'      => '#12304a',
    'icons'            => [
        ['src' => 'assets/img/app-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/img/app-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/img/app-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
