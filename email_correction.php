<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        throw new RuntimeException('Requête invalide.');
    }

    $email = filter_var((string) ($payload['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!is_string($email)) {
        throw new RuntimeException('Adresse email invalide.');
    }

    $game = load_game_data();
    $ui = $game['ui'] ?? [];
    $steps = is_array($game['steps'] ?? null) ? $game['steps'] : [];
    $cards = is_array($game['cards'] ?? null) ? $game['cards'] : [];
    $origin = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    $correctionUrl = $origin . ($basePath === '' ? '' : $basePath) . '/correction.php';

    $score = max(0, (int) ($payload['score'] ?? 0));
    $seconds = max(0, (int) ($payload['seconds'] ?? 0));
    $errors = max(0, (int) ($payload['errors'] ?? 0));
    $cardCount = max(0, (int) ($payload['cards'] ?? count($cards)));

    $lines = [];
    $lines[] = (string) ($ui['brandName'] ?? 'Jeu pédagogique');
    $lines[] = '';
    $lines[] = 'Voici le corrigé du jeu : ' . $correctionUrl;
    $lines[] = '';
    $lines[] = 'Bilan de l’activité';
    $lines[] = '- Score : ' . $score . ' pts';
    $lines[] = '- Temps : ' . format_session_time($seconds);
    $lines[] = '- Erreurs : ' . $errors;
    $lines[] = '- Cartes jouées : ' . $cardCount;
    $lines[] = '';
    $lines[] = 'Corrigé synthétique';

    foreach ($steps as $index => $step) {
        $stepId = (int) ($step['id'] ?? 0);
        $lines[] = '';
        $lines[] = ($index + 1) . '. ' . (string) ($step['title'] ?? 'Étape');
        foreach ($cards as $card) {
            if ((int) ($card['stepId'] ?? 0) !== $stepId) {
                continue;
            }
            $lines[] = '   - ' . (string) ($card['title'] ?? 'Carte');
            if (!empty($card['desc'])) {
                $lines[] = '     ' . (string) $card['desc'];
            }
        }
    }

    $subject = 'Corrigé - ' . (string) ($ui['brandName'] ?? 'Jeu pédagogique');
    $message = implode("\n", $lines);
    $headers = [
        'From: no-reply@localhost',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    $domain = substr(strrchr($email, '@') ?: '', 1) ?: 'email';
    $sent = function_exists('mail') && @mail($email, $subject, $message, implode("\r\n", $headers));
    if (!$sent) {
        $mailto = 'mailto:' . rawurlencode($email)
            . '?subject=' . rawurlencode($subject)
            . '&body=' . rawurlencode($message);
        write_event('correction_email_fallback', ['emailDomain' => $domain, 'score' => $score, 'errors' => $errors], null, 'participant');
        echo json_encode([
            'ok' => true,
            'delivered' => false,
            'mailto' => $mailto,
            'message' => 'Le serveur local ne peut pas envoyer le mail automatiquement. Un brouillon mail a été préparé.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    write_event('correction_emailed', ['emailDomain' => $domain, 'score' => $score, 'errors' => $errors], null, 'participant');

    echo json_encode(['ok' => true, 'delivered' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
