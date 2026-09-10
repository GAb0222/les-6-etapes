<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function api_json_input(): array
{
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    return is_array($payload) ? $payload : [];
}

function api_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_session_payload(array $session): array
{
    return [
        'code' => $session['code'],
        'teacherPin' => $session['teacherPin'] ?? null,
        'title' => $session['title'],
        'ambiance' => session_ambiance($session),
        'status' => $session['status'],
        'participants' => count($session['participants'] ?? []),
        'pulse' => session_pulse($session),
        'startedAt' => $session['startedAt'] ?? null,
        'endedAt' => $session['endedAt'] ?? null,
    ];
}
