<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Méthode non autorisée.');
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $result = record_session_result(
        (string) ($payload['code'] ?? ''),
        (string) ($payload['participantId'] ?? ''),
        (int) ($payload['seconds'] ?? 0),
        (int) ($payload['errors'] ?? 0),
        (int) ($payload['cards'] ?? 0),
        (int) ($payload['score'] ?? 0),
        is_array($payload['mistakes'] ?? null) ? $payload['mistakes'] : [],
        is_array($payload['badges'] ?? null) ? $payload['badges'] : [],
        is_array($payload['competencies'] ?? null) ? $payload['competencies'] : []
    );

    echo json_encode([
        'ok' => true,
        'leaderboard' => $result['leaderboard'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
