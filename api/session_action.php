<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

try {
    $code = normalize_session_code((string) ($_GET['code'] ?? ''));
    $action = strtolower((string) ($_GET['action'] ?? ''));
    if ($code === '') {
        throw new RuntimeException('Code session manquant.');
    }

    $payload = api_json_input();
    $session = load_game_session($code);
    if ($session === null) {
        throw new RuntimeException('Session introuvable.');
    }

    if (in_array($action, ['start', 'pause', 'end'], true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            api_response(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
        }
        if (!admin_is_authenticated() && !verify_session_teacher_pin($session, (string) ($payload['pin'] ?? $_GET['pin'] ?? ''))) {
            api_response(['ok' => false, 'error' => 'PIN enseignant invalide.'], 403);
        }
        $targetStatus = ['start' => 'running', 'pause' => 'waiting', 'end' => 'ended'][$action];
        $session = set_game_session_status($code, $targetStatus);
        write_event('api_session_' . $action, [], $code);
        api_response(['ok' => true, 'session' => api_session_payload($session)]);
    }

    if ($action === 'join') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            api_response(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
        }
        $participant = join_game_session($code, (string) ($payload['name'] ?? ''));
        api_response(['ok' => true, 'session' => api_session_payload(load_game_session($code) ?? $session), 'participant' => $participant]);
    }

    if ($action === 'progress') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            api_response(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
        }
        $session = record_session_progress($code, (string) ($payload['participantId'] ?? ''), is_array($payload['progress'] ?? null) ? $payload['progress'] : []);
        api_response(['ok' => true, 'session' => api_session_payload($session)]);
    }

    if ($action === 'result') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            api_response(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
        }
        $result = record_session_result(
            $code,
            (string) ($payload['participantId'] ?? ''),
            (int) ($payload['seconds'] ?? 0),
            (int) ($payload['errors'] ?? 0),
            (int) ($payload['cards'] ?? 0),
            (int) ($payload['score'] ?? 0),
            is_array($payload['mistakes'] ?? null) ? $payload['mistakes'] : [],
            is_array($payload['badges'] ?? null) ? $payload['badges'] : [],
            is_array($payload['competencies'] ?? null) ? $payload['competencies'] : []
        );
        api_response(['ok' => true, 'leaderboard' => $result['leaderboard']]);
    }

    if (in_array($action, ['status', 'leaderboard', 'analytics'], true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            api_response(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
        }
        $game = load_game_data();
        $canSeeDetails = admin_is_authenticated() || verify_session_teacher_pin($session, (string) ($_GET['pin'] ?? ''));
        if (!$canSeeDetails) {
            api_response(['ok' => false, 'error' => 'Accès enseignant requis.'], 403);
        }
        api_response([
            'ok' => true,
            'session' => api_session_payload($session),
            'leaderboard' => session_leaderboard($session),
            'liveRanking' => session_live_ranking($session),
            'analytics' => session_insights($session, $game),
        ]);
    }

    throw new RuntimeException('Action API inconnue.');
} catch (Throwable $exception) {
    api_response(['ok' => false, 'error' => $exception->getMessage()], 400);
}
