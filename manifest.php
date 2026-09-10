<?php

declare(strict_types=1);

// Manifest PWA dynamique : reprend le nom et les couleurs de la marque
// paramétrés dans l'admin. Servi en JSON, relatif au dossier de l'application.

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/pwa.php';

$game = load_game_data();
$version = pwa_asset_version();
$icon = static fn (int $size): string => 'icon.php?size=' . $size . '&v=' . $version;

$manifest = [
    'id' => './',
    'name' => pwa_app_name($game),
    'short_name' => pwa_short_name($game),
    'description' => 'Jeu pédagogique : remettre dans l\'ordre les 6 étapes de la création d\'entreprise et classer les actions clés.',
    'lang' => 'fr',
    'dir' => 'ltr',
    'start_url' => './?src=pwa',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => pwa_background_color($game),
    'theme_color' => pwa_theme_color($game),
    'categories' => ['education', 'games'],
    'icons' => [
        ['src' => $icon(192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon(192) . '&maskable=1', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $icon(512) . '&maskable=1', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [
        [
            'name' => 'Jouer en solo',
            'short_name' => 'Solo',
            'url' => './?src=pwa-solo',
            'icons' => [['src' => $icon(192), 'sizes' => '192x192', 'type' => 'image/png']],
        ],
    ],
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
