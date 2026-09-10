<?php

declare(strict_types=1);

function sessions_dir(): string
{
    $override = getenv('SIX_ETAPES_SESSIONS_DIR');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }

    return APP_ROOT . '/data/sessions';
}

function ensure_sessions_dir(): void
{
    $dir = sessions_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier des sessions.');
    }
}

function normalize_session_code(string $code): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
}

function session_data_path(string $code): string
{
    return sessions_dir() . '/' . normalize_session_code($code) . '.json';
}

function generate_session_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    ensure_sessions_dir();

    do {
        $code = '';
        for ($index = 0; $index < 6; $index += 1) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (is_file(session_data_path($code)));

    return $code;
}

function normalize_session_ambiance(string $ambiance): string
{
    return $ambiance === 'calme' ? 'calme' : 'chaos';
}

function session_ambiance(array $session): string
{
    return normalize_session_ambiance((string) ($session['ambiance'] ?? 'chaos'));
}

function session_is_calm(array $session): bool
{
    return session_ambiance($session) === 'calme';
}

function session_shows_public_ranking(array $session, ?array $game = null): bool
{
    if (session_is_calm($session)) {
        return false;
    }

    $settings = is_array(($game ?? [])['settings'] ?? null) ? $game['settings'] : [];
    return !empty($settings['showLiveLeaderboard']) || ($session['status'] ?? '') === 'ended';
}

function session_pulse(array $session): array
{
    $phases = [
        'waiting' => 0,
        'order' => 0,
        'cards' => 0,
        'completed' => 0,
    ];

    foreach ($session['participants'] ?? [] as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $phase = (string) (($participant['progress']['phase'] ?? 'waiting'));
        if (!isset($phases[$phase])) {
            $phase = 'waiting';
        }
        $phases[$phase] += 1;
    }

    $total = array_sum($phases);

    return [
        'total' => $total,
        'waiting' => $phases['waiting'],
        'order' => $phases['order'],
        'cards' => $phases['cards'],
        'completed' => $phases['completed'],
        'inPlay' => $phases['order'] + $phases['cards'],
        'percent' => $total > 0 ? (int) round(100 * $phases['completed'] / $total) : 0,
    ];
}

function session_lobby_names(array $session): array
{
    if (session_is_calm($session)) {
        return [];
    }

    $names = [];
    foreach ($session['participants'] ?? [] as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $name = sanitize_text(strip_tags((string) ($participant['name'] ?? '')));
        if ($name !== '') {
            $names[] = $name;
        }
        if (count($names) >= 36) {
            break;
        }
    }

    return $names;
}

function create_game_session(string $title = '', string $ambiance = ''): array
{
    $code = generate_session_code();
    $now = date(DATE_ATOM);
    if ($ambiance === '') {
        $game = load_game_data();
        $ambiance = (string) (($game['settings']['classroomAmbiance'] ?? 'chaos'));
    }
    $session = [
        'code' => $code,
        'teacherPin' => generate_teacher_pin(),
        'title' => sanitize_text($title) ?: 'Session classe',
        'ambiance' => normalize_session_ambiance($ambiance),
        'status' => 'waiting',
        'createdAt' => $now,
        'startedAt' => null,
        'endedAt' => null,
        'participants' => [],
    ];

    save_game_session($session);
    return $session;
}

function set_session_ambiance(string $code, string $ambiance): array
{
    $session = load_game_session($code);
    if ($session === null) {
        throw new RuntimeException('Session introuvable.');
    }

    $session['ambiance'] = normalize_session_ambiance($ambiance);
    save_game_session($session);
    return $session;
}

function generate_teacher_pin(): string
{
    return (string) random_int(1000, 9999);
}

function load_game_session(string $code): ?array
{
    $path = session_data_path($code);
    if (!is_file($path)) {
        return null;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Impossible de lire la session.');
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('La session est invalide.');
    }

    return with_session_defaults($decoded);
}

function save_game_session(array $session): void
{
    ensure_sessions_dir();
    $code = normalize_session_code((string) ($session['code'] ?? ''));
    if ($code === '') {
        throw new RuntimeException('Code session invalide.');
    }

    $session['code'] = $code;
    $json = json_encode(with_session_defaults($session), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Impossible d’encoder la session.');
    }

    if (file_put_contents(session_data_path($code), $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Impossible d’enregistrer la session.');
    }
}

function with_session_defaults(array $session): array
{
    $session['code'] = normalize_session_code((string) ($session['code'] ?? ''));
    $session['teacherPin'] = preg_replace('/[^0-9]/', '', (string) ($session['teacherPin'] ?? '')) ?: generate_teacher_pin();
    $session['title'] = sanitize_text((string) ($session['title'] ?? 'Session classe')) ?: 'Session classe';
    $session['ambiance'] = normalize_session_ambiance((string) ($session['ambiance'] ?? 'chaos'));
    $session['status'] = in_array(($session['status'] ?? ''), ['waiting', 'running', 'ended'], true) ? $session['status'] : 'waiting';
    $session['createdAt'] = $session['createdAt'] ?? date(DATE_ATOM);
    $session['startedAt'] = $session['startedAt'] ?? null;
    $session['endedAt'] = $session['endedAt'] ?? null;
    $session['participants'] = array_values(is_array($session['participants'] ?? null) ? $session['participants'] : []);
    $session['participants'] = array_map(static function (array $participant): array {
        $participant['progress'] = normalize_session_progress(is_array($participant['progress'] ?? null) ? $participant['progress'] : []);
        return $participant;
    }, $session['participants']);

    return $session;
}

function verify_session_teacher_pin(array $session, string $pin): bool
{
    $expected = preg_replace('/[^0-9]/', '', (string) ($session['teacherPin'] ?? ''));
    $given = preg_replace('/[^0-9]/', '', $pin);
    return $expected !== '' && hash_equals($expected, $given);
}

function normalize_session_progress(array $progress = []): array
{
    $phase = (string) ($progress['phase'] ?? 'waiting');
    if (!in_array($phase, ['waiting', 'order', 'cards', 'completed'], true)) {
        $phase = 'waiting';
    }

    return [
        'phase' => $phase,
        'orderSolved' => !empty($progress['orderSolved']),
        'orderPlaced' => max(0, (int) ($progress['orderPlaced'] ?? 0)),
        'cardsPlaced' => max(0, (int) ($progress['cardsPlaced'] ?? 0)),
        'totalCards' => max(0, (int) ($progress['totalCards'] ?? 0)),
        'masteredSteps' => max(0, (int) ($progress['masteredSteps'] ?? 0)),
        'score' => max(0, (int) ($progress['score'] ?? 0)),
        'errors' => max(0, (int) ($progress['errors'] ?? 0)),
        'streak' => max(0, (int) ($progress['streak'] ?? 0)),
        'seconds' => max(0, (int) ($progress['seconds'] ?? 0)),
        'lastAction' => sanitize_text((string) ($progress['lastAction'] ?? '')),
        'updatedAt' => $progress['updatedAt'] ?? null,
    ];
}

function list_game_sessions(): array
{
    ensure_sessions_dir();
    $sessions = [];
    foreach (glob(sessions_dir() . '/*.json') ?: [] as $path) {
        $session = load_game_session(pathinfo($path, PATHINFO_FILENAME));
        if ($session !== null) {
            $sessions[] = $session;
        }
    }

    usort($sessions, fn (array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
    return $sessions;
}

function set_game_session_status(string $code, string $status): array
{
    $session = load_game_session($code);
    if ($session === null) {
        throw new RuntimeException('Session introuvable.');
    }

    if (!in_array($status, ['waiting', 'running', 'ended'], true)) {
        throw new RuntimeException('Statut de session invalide.');
    }

    $session['status'] = $status;
    if ($status === 'running' && empty($session['startedAt'])) {
        $session['startedAt'] = date(DATE_ATOM);
        $session['endedAt'] = null;
    }
    if ($status === 'waiting') {
        $session['startedAt'] = null;
        $session['endedAt'] = null;
    }
    if ($status === 'ended') {
        $session['endedAt'] = date(DATE_ATOM);
    }

    save_game_session($session);
    return $session;
}

function delete_game_session(string $code): void
{
    $path = session_data_path($code);
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Impossible de supprimer la session.');
    }
}

function reset_game_session(string $code): array
{
    $session = load_game_session($code);
    if ($session === null) {
        throw new RuntimeException('Session introuvable.');
    }

    $session['status'] = 'waiting';
    $session['startedAt'] = null;
    $session['endedAt'] = null;
    $session['participants'] = [];
    save_game_session($session);
    return $session;
}

function archived_sessions_dir(): string
{
    return sessions_dir() . '/archive';
}

function ensure_archived_sessions_dir(): void
{
    $dir = archived_sessions_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier d’archives.');
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }
}

function archive_finished_sessions(int $olderThanDays = 0): int
{
    ensure_sessions_dir();
    ensure_archived_sessions_dir();
    $count = 0;
    $threshold = time() - max(0, $olderThanDays) * 86400;

    foreach (glob(sessions_dir() . '/*.json') ?: [] as $path) {
        $session = load_game_session(pathinfo($path, PATHINFO_FILENAME));
        if ($session === null || ($session['status'] ?? '') !== 'ended') {
            continue;
        }

        $endedAt = strtotime((string) ($session['endedAt'] ?? $session['createdAt'] ?? 'now')) ?: time();
        if ($endedAt > $threshold) {
            continue;
        }

        $destination = archived_sessions_dir() . '/' . basename($path);
        if (rename($path, $destination)) {
            $count += 1;
        }
    }

    return $count;
}

function session_participant_key(string $code): string
{
    return 'participant_' . normalize_session_code($code);
}

function unique_session_participant_name(array $session, string $name): string
{
    $normalize = static function (string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    };
    $used = [];
    foreach ($session['participants'] ?? [] as $participant) {
        $used[$normalize((string) ($participant['name'] ?? ''))] = true;
    }
    if (!isset($used[$normalize($name)])) {
        return $name;
    }
    $n = 2;
    while (isset($used[$normalize($name . ' ' . $n)])) {
        $n += 1;
    }
    return $name . ' ' . $n;
}

function join_game_session(string $code, string $name): array
{
    $session = load_game_session($code);
    if ($session === null) {
        throw new RuntimeException('Ce code session est introuvable.');
    }

    if ($session['status'] === 'ended') {
        throw new RuntimeException('Cette session est terminée.');
    }

    $name = sanitize_text(strip_tags($name));
    if ($name === '') {
        throw new RuntimeException('Entre ton prénom pour rejoindre la session.');
    }

    $participantId = $_SESSION[session_participant_key($session['code'])] ?? null;
    $participant = $participantId ? find_session_participant($session, (string) $participantId) : null;
    if ($participant !== null) {
        return $participant;
    }

    $name = unique_session_participant_name($session, $name);
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 40) : substr($name, 0, 40);

    $participant = [
        'id' => bin2hex(random_bytes(8)),
        'name' => $name,
        'joinedAt' => date(DATE_ATOM),
        'completedAt' => null,
        'seconds' => null,
        'errors' => null,
        'cards' => null,
        'score' => null,
        'mistakes' => [],
        'badges' => [],
        'competencies' => [],
        'progress' => normalize_session_progress(),
    ];

    $session['participants'][] = $participant;
    $_SESSION[session_participant_key($session['code'])] = $participant['id'];
    save_game_session($session);
    if (function_exists('write_event')) {
        write_event('participant_joined', ['name' => $participant['name']], (string) $session['code'], 'participant');
    }

    return $participant;
}

function find_session_participant(array $session, string $participantId): ?array
{
    foreach (($session['participants'] ?? []) as $participant) {
        if (($participant['id'] ?? '') === $participantId) {
            return $participant;
        }
    }

    return null;
}

function current_session_participant(array $session): ?array
{
    $participantId = $_SESSION[session_participant_key((string) $session['code'])] ?? '';
    return is_string($participantId) ? find_session_participant($session, $participantId) : null;
}

function record_session_result(string $code, string $participantId, int $seconds, int $errors, int $cards, int $score = 0, array $mistakes = [], array $badges = [], array $competencies = []): array
{
    $session = load_game_session($code);
    if ($session === null) {
        throw new RuntimeException('Session introuvable.');
    }
    if (($session['status'] ?? '') === 'ended') {
        throw new RuntimeException('Cette session est terminée.');
    }

    $sessionKey = session_participant_key((string) $session['code']);
    if (($_SESSION[$sessionKey] ?? '') !== $participantId) {
        throw new RuntimeException('Participant non reconnu.');
    }

    foreach ($session['participants'] as $index => $participant) {
        if (($participant['id'] ?? '') !== $participantId) {
            continue;
        }

        $previousScore = $participant['score'];
        $previousSeconds = $participant['seconds'];
        $previousErrors = $participant['errors'];
        $isBetter = $previousSeconds === null
            || $score > (int) $previousScore
            || ($score === (int) $previousScore && $errors < (int) $previousErrors)
            || ($score === (int) $previousScore && $errors === (int) $previousErrors && $seconds < (int) $previousSeconds);

        if ($isBetter) {
            $session['participants'][$index]['completedAt'] = date(DATE_ATOM);
            $session['participants'][$index]['seconds'] = max(0, $seconds);
            $session['participants'][$index]['errors'] = max(0, $errors);
            $session['participants'][$index]['cards'] = max(0, $cards);
            $session['participants'][$index]['score'] = max(0, $score);
            $session['participants'][$index]['mistakes'] = normalize_mistake_ids($mistakes);
            $session['participants'][$index]['badges'] = !empty($badges)
                ? normalize_session_badges($badges)
                : session_badges(max(0, $score), max(0, $errors), max(0, $seconds), max(0, $cards), normalize_mistake_ids($mistakes));
            $session['participants'][$index]['competencies'] = normalize_session_competencies($competencies);
            $session['participants'][$index]['progress'] = normalize_session_progress([
                ...($session['participants'][$index]['progress'] ?? []),
                'phase' => 'completed',
                'cardsPlaced' => $cards,
                'totalCards' => $cards,
                'score' => $score,
                'errors' => $errors,
                'seconds' => $seconds,
                'lastAction' => 'Partie terminée',
                'updatedAt' => date(DATE_ATOM),
            ]);
        }

        save_game_session($session);
        if (function_exists('write_event')) {
            write_event('result_submitted', ['score' => $score, 'errors' => $errors, 'seconds' => $seconds, 'cards' => $cards], (string) $session['code'], 'participant');
        }
        return [
            'session' => load_game_session($code),
            'leaderboard' => session_leaderboard(load_game_session($code) ?? $session),
        ];
    }

    throw new RuntimeException('Participant introuvable.');
}

function normalize_mistake_ids(array $mistakes): array
{
    $ids = [];
    foreach ($mistakes as $mistake) {
        $id = (int) $mistake;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return $ids;
}

function session_badges(int $score, int $errors, int $seconds, int $cards, array $mistakes): array
{
    $badges = [];
    if ($cards > 0 && $errors === 0) {
        $badges[] = ['key' => 'perfect', 'label' => 'Sans faute'];
    }
    if ($cards > 0 && $seconds > 0 && $seconds <= 180) {
        $badges[] = ['key' => 'fast', 'label' => 'Rapide'];
    }
    if ($score >= 1600) {
        $badges[] = ['key' => 'expert', 'label' => 'Expert'];
    }
    if ($errors > 0 && count($mistakes) > 0) {
        $badges[] = ['key' => 'review', 'label' => 'À retravailler'];
    }
    if ($cards > 0) {
        $badges[] = ['key' => 'complete', 'label' => 'Parcours complet'];
    }

    return $badges;
}

function normalize_session_badges(array $badges): array
{
    $clean = [];
    foreach ($badges as $badge) {
        if (!is_array($badge)) {
            continue;
        }
        $title = sanitize_text((string) ($badge['title'] ?? $badge['label'] ?? ''));
        if ($title === '') {
            continue;
        }
        $clean[] = [
            'title' => $title,
            'text' => sanitize_text((string) ($badge['text'] ?? '')),
        ];
        if (count($clean) >= 10) {
            break;
        }
    }

    return $clean;
}

function normalize_session_competencies(array $competencies): array
{
    $clean = [];
    foreach ($competencies as $key => $stats) {
        if (!is_array($stats)) {
            continue;
        }
        $safeKey = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $key) ?: 'general';
        $clean[$safeKey] = [
            'success' => max(0, (int) ($stats['success'] ?? 0)),
            'errors' => max(0, (int) ($stats['errors'] ?? 0)),
        ];
    }

    return $clean;
}

function record_session_progress(string $code, string $participantId, array $progress): array
{
    $session = load_game_session($code);
    if ($session === null) {
        throw new RuntimeException('Session introuvable.');
    }
    if (($session['status'] ?? '') === 'ended') {
        throw new RuntimeException('Cette session est terminée.');
    }

    $sessionKey = session_participant_key((string) $session['code']);
    if (($_SESSION[$sessionKey] ?? '') !== $participantId) {
        throw new RuntimeException('Participant non reconnu.');
    }

    foreach ($session['participants'] as $index => $participant) {
        if (($participant['id'] ?? '') !== $participantId) {
            continue;
        }

        $session['participants'][$index]['progress'] = normalize_session_progress([
            ...($participant['progress'] ?? []),
            ...$progress,
            'updatedAt' => date(DATE_ATOM),
        ]);

        save_game_session($session);
        return load_game_session($code) ?? $session;
    }

    throw new RuntimeException('Participant introuvable.');
}

function session_leaderboard(array $session): array
{
    $participants = array_values(array_filter($session['participants'] ?? [], fn (array $participant): bool => $participant['seconds'] !== null));
    usort($participants, function (array $a, array $b): int {
        $scoreCompare = ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        $errorCompare = ((int) $a['errors']) <=> ((int) $b['errors']);
        if ($errorCompare !== 0) {
            return $errorCompare;
        }

        $timeCompare = ((int) $a['seconds']) <=> ((int) $b['seconds']);
        if ($timeCompare !== 0) {
            return $timeCompare;
        }

        return strcmp((string) ($a['completedAt'] ?? ''), (string) ($b['completedAt'] ?? ''));
    });

    return $participants;
}

function session_live_ranking(array $session): array
{
    $participants = array_values($session['participants'] ?? []);
    usort($participants, function (array $a, array $b): int {
        $progressA = normalize_session_progress(is_array($a['progress'] ?? null) ? $a['progress'] : []);
        $progressB = normalize_session_progress(is_array($b['progress'] ?? null) ? $b['progress'] : []);
        $scoreA = (int) ($a['score'] ?? $progressA['score']);
        $scoreB = (int) ($b['score'] ?? $progressB['score']);
        $scoreCompare = $scoreB <=> $scoreA;
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        $cardsCompare = ((int) $progressB['cardsPlaced']) <=> ((int) $progressA['cardsPlaced']);
        if ($cardsCompare !== 0) {
            return $cardsCompare;
        }

        $errorA = (int) ($a['errors'] ?? $progressA['errors']);
        $errorB = (int) ($b['errors'] ?? $progressB['errors']);
        $errorCompare = $errorA <=> $errorB;
        if ($errorCompare !== 0) {
            return $errorCompare;
        }

        return ((int) $progressA['seconds']) <=> ((int) $progressB['seconds']);
    });

    return array_map(static function (array $participant): array {
        $progress = normalize_session_progress(is_array($participant['progress'] ?? null) ? $participant['progress'] : []);
        return [
            'name' => (string) ($participant['name'] ?? ''),
            'joinedAt' => $participant['joinedAt'] ?? null,
            'completedAt' => $participant['completedAt'] ?? null,
            'score' => (int) ($participant['score'] ?? $progress['score']),
            'errors' => (int) ($participant['errors'] ?? $progress['errors']),
            'seconds' => (int) ($participant['seconds'] ?? $progress['seconds']),
            'hasResult' => ($participant['seconds'] ?? null) !== null,
            'badges' => is_array($participant['badges'] ?? null) ? $participant['badges'] : [],
            'progress' => $progress,
        ];
    }, $participants);
}

function session_insights(array $session, array $game): array
{
    $participants = array_values($session['participants'] ?? []);
    $finished = array_values(array_filter($participants, static fn (array $participant): bool => ($participant['seconds'] ?? null) !== null));
    $cards = is_array($game['cards'] ?? null) ? $game['cards'] : [];
    $steps = is_array($game['steps'] ?? null) ? $game['steps'] : [];
    $cardsById = [];
    foreach ($cards as $card) {
        $cardsById[(int) ($card['id'] ?? 0)] = $card;
    }
    $stepsById = [];
    foreach ($steps as $step) {
        $stepsById[(int) ($step['id'] ?? 0)] = $step;
    }

    $mistakeCounts = [];
    $stepMistakes = [];
    $scoreSum = 0;
    $timeSum = 0;
    $errorSum = 0;

    foreach ($finished as $participant) {
        $scoreSum += (int) ($participant['score'] ?? 0);
        $timeSum += (int) ($participant['seconds'] ?? 0);
        $errorSum += (int) ($participant['errors'] ?? 0);
        foreach (normalize_mistake_ids(is_array($participant['mistakes'] ?? null) ? $participant['mistakes'] : []) as $cardId) {
            $mistakeCounts[$cardId] = ($mistakeCounts[$cardId] ?? 0) + 1;
            $stepId = (int) ($cardsById[$cardId]['stepId'] ?? 0);
            if ($stepId > 0) {
                $stepMistakes[$stepId] = ($stepMistakes[$stepId] ?? 0) + 1;
            }
        }
    }

    arsort($mistakeCounts);
    arsort($stepMistakes);

    $difficultCards = [];
    foreach (array_slice($mistakeCounts, 0, 5, true) as $cardId => $count) {
        $card = $cardsById[(int) $cardId] ?? null;
        if ($card === null) {
            continue;
        }
        $step = $stepsById[(int) ($card['stepId'] ?? 0)] ?? null;
        $difficultCards[] = [
            'id' => (int) $cardId,
            'title' => (string) ($card['title'] ?? 'Carte'),
            'step' => $step ? (string) ($step['short'] ?? $step['title']) : '',
            'count' => (int) $count,
        ];
    }

    $difficultSteps = [];
    foreach (array_slice($stepMistakes, 0, 6, true) as $stepId => $count) {
        $step = $stepsById[(int) $stepId] ?? null;
        if ($step === null) {
            continue;
        }
        $difficultSteps[] = [
            'id' => (int) $stepId,
            'title' => (string) ($step['short'] ?? $step['title']),
            'count' => (int) $count,
        ];
    }

    $finishedCount = count($finished);
    return [
        'participants' => count($participants),
        'finished' => $finishedCount,
        'averageScore' => $finishedCount > 0 ? (int) round($scoreSum / $finishedCount) : 0,
        'averageSeconds' => $finishedCount > 0 ? (int) round($timeSum / $finishedCount) : 0,
        'averageErrors' => $finishedCount > 0 ? round($errorSum / $finishedCount, 1) : 0,
        'difficultCards' => $difficultCards,
        'difficultSteps' => $difficultSteps,
    ];
}

function format_session_time(?int $seconds): string
{
    if ($seconds === null) {
        return '-';
    }

    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}
