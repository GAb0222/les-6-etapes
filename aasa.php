<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

$teamId = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) (getenv('APPLE_TEAM_ID') ?: 'TEAMID')) ?: 'TEAMID');
$bundleId = 'fr.foutechsolutions.sixetapes';
$appId = $teamId . '.' . $bundleId;

$payload = [
    'applinks' => [
        'details' => [[
            'appIDs' => [$appId],
            'components' => [
                ['/' => '/s/*', 'comment' => 'Lien court de séance'],
                ['/' => '/session.php', '?' => ['code' => '?*'], 'comment' => 'Lien long de séance'],
                ['/' => '/leaderboard.php', '?' => ['code' => '?*'], 'comment' => 'Classement'],
                ['/' => '/admin*', 'exclude' => true, 'comment' => 'Admin dans Safari'],
            ],
        ]],
    ],
    'webcredentials' => [
        'apps' => [$appId],
    ],
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
