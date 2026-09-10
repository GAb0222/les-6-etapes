<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_response(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
    }

    if (!admin_is_authenticated()) {
        api_response(['ok' => false, 'error' => 'Authentification admin requise.'], 401);
    }

    $payload = api_json_input();
    $session = create_game_session((string) ($payload['title'] ?? 'Session classe'), (string) ($payload['ambiance'] ?? ''));
    write_event('api_session_created', ['title' => $session['title']], (string) $session['code']);
    api_response(['ok' => true, 'session' => api_session_payload($session)]);
} catch (Throwable $exception) {
    api_response(['ok' => false, 'error' => $exception->getMessage()], 400);
}
