<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

$data = trim((string) ($_GET['data'] ?? ''));
if ($data === '') {
    http_response_code(400);
    echo 'QR data missing';
    exit;
}

$node = '/opt/homebrew/bin/node';
$qrcodeModule = '/Users/gabrielfourier/Cursor projet/enfin/node_modules/qrcode/lib/index.js';

if (is_file($node) && is_file($qrcodeModule) && function_exists('shell_exec')) {
    $script = 'const QRCode=require(' . json_encode($qrcodeModule) . '); QRCode.toString(' . json_encode($data) . ', { type: "svg", margin: 1, errorCorrectionLevel: "M", width: 260 }, (err, svg) => { if (err) { process.exit(1); } console.log(svg); });';
    $svg = shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script));
    if (is_string($svg) && str_contains($svg, '<svg')) {
        header('Content-Type: image/svg+xml; charset=UTF-8');
        echo $svg;
        exit;
    }
}

header('Content-Type: image/svg+xml; charset=UTF-8');
$safeData = escape_html($data);
?>
<svg xmlns="http://www.w3.org/2000/svg" width="260" height="260" viewBox="0 0 260 260">
  <rect width="260" height="260" rx="18" fill="#fff"/>
  <rect x="18" y="18" width="224" height="224" rx="12" fill="#111827"/>
  <rect x="30" y="30" width="200" height="200" rx="8" fill="#fff"/>
  <text x="130" y="108" text-anchor="middle" font-family="system-ui, sans-serif" font-size="18" font-weight="800" fill="#111827">Code session</text>
  <text x="130" y="144" text-anchor="middle" font-family="monospace" font-size="22" font-weight="900" fill="#4545A4"><?= $safeData ?></text>
  <text x="130" y="176" text-anchor="middle" font-family="system-ui, sans-serif" font-size="10" font-weight="700" fill="#6B7280">Ouvre le lien élève si le QR local est indisponible</text>
</svg>
