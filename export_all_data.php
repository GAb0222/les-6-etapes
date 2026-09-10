<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

require_admin_auth();

$sessions = [];
foreach (list_game_sessions() as $session) {
    $sessions[] = $session;
}
$archivedSessions = [];
foreach (glob(archived_sessions_dir() . '/*.json') ?: [] as $path) {
    $contents = file_get_contents($path);
    $decoded = is_string($contents) ? json_decode($contents, true) : null;
    if (is_array($decoded)) {
        $archivedSessions[] = with_session_defaults($decoded);
    }
}

$payload = [
    'exportedAt' => date(DATE_ATOM),
    'game' => load_game_data(),
    'sessions' => $sessions,
    'archivedSessions' => $archivedSessions,
    'events' => read_recent_events(1000),
];

write_event('all_data_exported');

header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="jeu-pedagogique-export-' . date('Ymd-His') . '.json"');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
