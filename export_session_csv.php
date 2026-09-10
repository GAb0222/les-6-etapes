<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

require_admin_auth();

$code = normalize_session_code((string) ($_GET['code'] ?? ''));
$session = $code !== '' ? load_game_session($code) : null;
if ($session === null) {
    http_response_code(404);
    echo 'Session introuvable.';
    exit;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="session-' . $session['code'] . '-resultats.csv"');
write_event('session_results_exported', ['participants' => count($session['participants'] ?? [])], (string) $session['code']);

$out = fopen('php://output', 'w');
fputcsv($out, ['rang', 'prenom', 'score', 'temps', 'erreurs', 'cartes', 'phase', 'derniere_action', 'arrivee', 'fin', 'badges', 'statut'], ';');
foreach (session_live_ranking($session) as $index => $participant) {
    $badges = array_map(static fn (array $badge): string => (string) ($badge['title'] ?? $badge['label'] ?? ''), is_array($participant['badges'] ?? null) ? $participant['badges'] : []);
    $progress = is_array($participant['progress'] ?? null) ? $participant['progress'] : [];
    fputcsv($out, [
        $index + 1,
        $participant['name'],
        $participant['score'],
        format_session_time((int) $participant['seconds']),
        $participant['errors'],
        (int) ($progress['cardsPlaced'] ?? 0) . '/' . (int) ($progress['totalCards'] ?? 0),
        (string) ($progress['phase'] ?? ''),
        (string) ($progress['lastAction'] ?? ''),
        (string) ($participant['joinedAt'] ?? ''),
        (string) ($participant['completedAt'] ?? ''),
        implode(', ', array_filter($badges)),
        !empty($participant['hasResult']) ? 'final' : 'live',
    ], ';');
}
fclose($out);
