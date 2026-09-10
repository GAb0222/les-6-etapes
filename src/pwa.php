<?php

declare(strict_types=1);

/**
 * PWA : manifest, icônes et balises <head> partagées par les pages élève.
 *
 * Une seule base web sert trois canaux : navigateur, PWA installée, app iOS
 * (WKWebView). Tout est relatif au dossier de l'application pour fonctionner
 * aussi bien à la racine d'un domaine qu'en sous-dossier (MAMP).
 */

function pwa_theme_color(array $game): string
{
    $color = (string) (($game['theme'] ?? [])['primary'] ?? '#1D5BD4');

    return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? strtoupper($color) : '#1D5BD4';
}

function pwa_background_color(array $game): string
{
    $color = (string) (($game['theme'] ?? [])['background'] ?? '#EEF3FB');

    return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? strtoupper($color) : '#EEF3FB';
}

function pwa_app_name(array $game): string
{
    $name = trim((string) (($game['ui'] ?? [])['brandName'] ?? ''));

    return $name !== '' ? $name : 'Les 6 étapes';
}

function pwa_short_name(array $game): string
{
    $name = pwa_app_name($game);

    return mb_strlen($name) <= 12 ? $name : '6 étapes';
}

/** Version des ressources précachées : change dès qu'un fichier du shell change. */
function pwa_asset_version(): string
{
    $files = [
        APP_ROOT . '/assets/app.css',
        APP_ROOT . '/assets/app.js',
        APP_ROOT . '/assets/pwa.js',
        APP_ROOT . '/sw.js',
        APP_ROOT . '/index.php',
        APP_ROOT . '/session.php',
    ];
    $stamp = 0;
    foreach ($files as $file) {
        if (is_file($file)) {
            $stamp = max($stamp, (int) filemtime($file));
        }
    }
    $dataFile = APP_ROOT . '/data/game.json';
    if (is_file($dataFile)) {
        $stamp = max($stamp, (int) filemtime($dataFile));
    }

    return dechex($stamp);
}

/** Balises à insérer dans <head> : manifest, couleur de thème, métas Apple. */
function pwa_head_tags(array $game, string $pageTitle = ''): string
{
    $theme = escape_html(pwa_theme_color($game));
    $title = escape_html($pageTitle !== '' ? $pageTitle : pwa_app_name($game));
    $version = escape_html(pwa_asset_version());

    return implode("\n    ", [
        '<link rel="manifest" href="manifest.php?v=' . $version . '">',
        '<meta name="theme-color" content="' . $theme . '">',
        '<meta name="application-name" content="' . $title . '">',
        '<meta name="mobile-web-app-capable" content="yes">',
        '<meta name="apple-mobile-web-app-capable" content="yes">',
        '<meta name="apple-mobile-web-app-status-bar-style" content="default">',
        '<meta name="apple-mobile-web-app-title" content="' . $title . '">',
        '<link rel="apple-touch-icon" href="icon.php?size=180&amp;v=' . $version . '">',
        '<link rel="icon" type="image/png" sizes="192x192" href="icon.php?size=192&amp;v=' . $version . '">',
    ]);
}

/** Script d'enregistrement du service worker + installation / mise à jour. */
function pwa_script_tag(): string
{
    $version = escape_html(pwa_asset_version());

    return '<script src="assets/pwa.js?v=' . $version . '" defer></script>';
}
