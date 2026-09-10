<?php

declare(strict_types=1);

// Icône PWA générée à la volée (GD) : logo de la marque centré sur la couleur
// primaire, ou à défaut le chiffre « 6 ». ?maskable=1 agrandit la zone de sécurité.

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/pwa.php';

$size = (int) ($_GET['size'] ?? 192);
$size = in_array($size, [64, 120, 152, 167, 180, 192, 256, 384, 512], true) ? $size : 192;
$maskable = !empty($_GET['maskable']);

if (!function_exists('imagecreatetruecolor')) {
    http_response_code(404);
    exit;
}

$game = load_game_data();
$hex = ltrim(pwa_theme_color($game), '#');
[$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];

$image = imagecreatetruecolor($size, $size);
imagesavealpha($image, true);
$background = imagecolorallocate($image, (int) $r, (int) $g, (int) $b);
imagefill($image, 0, 0, $background);

// Zone utile : 78 % pour une icône « any » (coins arrondis par l'OS), 60 % pour
// une icône maskable (l'OS rogne jusqu'à 20 % de chaque côté).
$inner = (int) round($size * ($maskable ? 0.6 : 0.78));
$offset = (int) (($size - $inner) / 2);

$logoPath = (string) (($game['brand'] ?? [])['logoPath'] ?? '');
$logoFile = $logoPath !== '' && !preg_match('#^(https?:)?//#', $logoPath)
    ? realpath(APP_ROOT . '/' . ltrim($logoPath, '/'))
    : false;
$logo = false;
if ($logoFile !== false && str_starts_with($logoFile, realpath(APP_ROOT . '/uploads') ?: '/dev/null')) {
    $contents = @file_get_contents($logoFile);
    $logo = $contents !== false ? @imagecreatefromstring($contents) : false;
}

if ($logo !== false) {
    $lw = imagesx($logo);
    $lh = imagesy($logo);
    $scale = min($inner / $lw, $inner / $lh);
    $dw = (int) round($lw * $scale);
    $dh = (int) round($lh * $scale);
    // Pastille blanche derrière le logo : lisible quelle que soit la marque.
    $white = imagecolorallocate($image, 255, 255, 255);
    $pad = (int) round($size * 0.06);
    imagefilledrectangle($image, $offset - $pad, $offset - $pad, $offset + $inner + $pad, $offset + $inner + $pad, $white);
    imagecopyresampled(
        $image,
        $logo,
        (int) (($size - $dw) / 2),
        (int) (($size - $dh) / 2),
        0,
        0,
        $dw,
        $dh,
        $lw,
        $lh
    );
    imagedestroy($logo);
} else {
    // Repli : un « 6 » blanc, épais, centré.
    $white = imagecolorallocate($image, 255, 255, 255);
    $glyph = '6';
    $fontCandidates = [
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    ];
    $font = null;
    foreach ($fontCandidates as $candidate) {
        if (is_file($candidate)) {
            $font = $candidate;
            break;
        }
    }
    if ($font !== null && function_exists('imagettftext')) {
        $pt = $inner * 0.72;
        $box = imagettfbbox($pt, 0, $font, $glyph);
        $textWidth = $box[2] - $box[0];
        $textHeight = $box[1] - $box[7];
        $x = (int) (($size - $textWidth) / 2 - $box[0]);
        $y = (int) (($size + $textHeight) / 2 - $box[1]);
        imagettftext($image, $pt, 0, $x, $y, $white, $font, $glyph);
    } else {
        // Sans police TrueType : un disque blanc et un disque coloré (pictogramme neutre).
        imagefilledellipse($image, (int) ($size / 2), (int) ($size / 2), $inner, $inner, $white);
        imagefilledellipse($image, (int) ($size / 2), (int) ($size / 2), (int) ($inner * 0.45), (int) ($inner * 0.45), $background);
    }
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($image, null, 6);
imagedestroy($image);
