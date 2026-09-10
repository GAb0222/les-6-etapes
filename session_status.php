<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $code = normalize_session_code((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        throw new RuntimeException('Code session manquant.');
    }

    $session = load_game_session($code);
    if ($session === null) {
        throw new RuntimeException('Session introuvable.');
    }
    $game = load_game_data();
    $canSeeDetails = admin_is_authenticated() || verify_session_teacher_pin($session, (string) ($_GET['pin'] ?? ''));
    $participantList = array_map(static fn (array $participant): array => [
        'name' => $canSeeDetails ? (string) ($participant['name'] ?? '') : 'Participant',
        'joinedAt' => $canSeeDetails ? ($participant['joinedAt'] ?? null) : null,
        'completedAt' => $canSeeDetails ? ($participant['completedAt'] ?? null) : null,
        'hasResult' => ($participant['seconds'] ?? null) !== null,
        'score' => $canSeeDetails ? (int) ($participant['score'] ?? ($participant['progress']['score'] ?? 0)) : 0,
        'errors' => $canSeeDetails ? (int) ($participant['errors'] ?? ($participant['progress']['errors'] ?? 0)) : 0,
        'seconds' => $canSeeDetails ? (int) ($participant['seconds'] ?? ($participant['progress']['seconds'] ?? 0)) : 0,
        'progress' => $canSeeDetails ? normalize_session_progress(is_array($participant['progress'] ?? null) ? $participant['progress'] : []) : normalize_session_progress(),
    ], $session['participants'] ?? []);

    echo json_encode([
        'ok' => true,
        'code' => $session['code'],
        'title' => $session['title'],
        'status' => $session['status'],
        'participants' => count($session['participants'] ?? []),
        'participantList' => $canSeeDetails ? $participantList : [],
        'results' => count(session_leaderboard($session)),
        'leaderboard' => $canSeeDetails ? session_leaderboard($session) : [],
        'liveRanking' => $canSeeDetails ? session_live_ranking($session) : [],
        'insights' => $canSeeDetails ? session_insights($session, $game) : [],
        'startedAt' => $session['startedAt'],
        'endedAt' => $session['endedAt'],
        'detailsVisible' => $canSeeDetails,
        'ambiance' => session_ambiance($session),
        'pulse' => session_pulse($session),
        'lobbyNames' => session_lobby_names($session),
        'showPublicRanking' => session_shows_public_ranking($session, $game),
        'stepCount' => count($game['steps'] ?? []),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
