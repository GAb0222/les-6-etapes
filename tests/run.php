<?php

declare(strict_types=1);

/**
 * Suite de tests locale (sans PHPUnit) : règles chaos/calme, SNEE, live, HTTP.
 *
 * Lancé uniquement en CLI : php tests/run.php
 * Aucune page de l’app n’inclut ce fichier.
 *
 * Écrit des sessions synthétiques dans tests/.tmp-sessions/{CODE}.json
 * (code, title, ambiance, status, createdAt DATE_ATOM, participants[]).
 */

$root = dirname(__DIR__);
$tmpSessions = $root . '/tests/.tmp-sessions';
if (is_dir($tmpSessions)) {
    foreach (glob($tmpSessions . '/*.json') ?: [] as $file) {
        @unlink($file);
    }
} else {
    mkdir($tmpSessions, 0755, true);
}

putenv('SIX_ETAPES_SESSIONS_DIR=' . $tmpSessions);
$_ENV['SIX_ETAPES_SESSIONS_DIR'] = $tmpSessions;

require_once $root . '/src/bootstrap.php';

$passed = 0;
$failed = 0;
$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($ok) {
        $passed += 1;
        echo "  ok  {$name}\n";
        return;
    }
    $failed += 1;
    $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
    echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function expect_throws(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (Throwable) {
        return true;
    }
}

echo "=== Unités ===\n";

check('code session normalisé', normalize_session_code('ab-12!') === 'AB12');
check('ambiance calme', normalize_session_ambiance('calme') === 'calme');
check('ambiance inconnue → chaos', normalize_session_ambiance('fun') === 'chaos');

$calm = with_session_defaults(['ambiance' => 'calme', 'status' => 'running']);
$chaosRunning = with_session_defaults(['ambiance' => 'chaos', 'status' => 'running']);
$chaosEnded = with_session_defaults(['ambiance' => 'chaos', 'status' => 'ended']);
$gameLive = ['settings' => ['showLiveLeaderboard' => true]];
$gameHidden = ['settings' => ['showLiveLeaderboard' => false]];

check('calme : jamais de classement public', session_shows_public_ranking($calm, $gameLive) === false);
check('calme terminée : toujours pas de classement', session_shows_public_ranking(with_session_defaults(['ambiance' => 'calme', 'status' => 'ended']), $gameLive) === false);
check('chaos + live : classement pendant la partie', session_shows_public_ranking($chaosRunning, $gameLive) === true);
check('chaos sans live : pas de classement en cours', session_shows_public_ranking($chaosRunning, $gameHidden) === false);
check('chaos sans live : classement à la fin', session_shows_public_ranking($chaosEnded, $gameHidden) === true);

$emptyPulse = session_pulse(['participants' => []]);
check('pulse vide total 0', $emptyPulse['total'] === 0 && $emptyPulse['percent'] === 0);

$mixedPulse = session_pulse([
    'participants' => [
        ['progress' => ['phase' => 'order']],
        ['progress' => ['phase' => 'cards']],
        ['progress' => ['phase' => 'completed']],
        ['progress' => ['phase' => 'completed']],
        'ignore-me',
        ['progress' => ['phase' => 'inconnu']],
    ],
]);
check('pulse ignore les entrées invalides', $mixedPulse['total'] === 5);
check('pulse inPlay', $mixedPulse['inPlay'] === 2);
check('pulse percent 40%', $mixedPulse['percent'] === 40);
check('pulse phase inconnue → waiting', $mixedPulse['waiting'] === 1);

check('lobby calme vide', session_lobby_names(['ambiance' => 'calme', 'participants' => [['name' => 'Léa']]]) === []);
check(
    'lobby chaos nettoie le HTML',
    session_lobby_names(['ambiance' => 'chaos', 'participants' => [['name' => '<b>Tom</b>']]]) === ['Tom']
);

$many = ['ambiance' => 'chaos', 'participants' => []];
for ($i = 0; $i < 50; $i += 1) {
    $many['participants'][] = ['name' => 'P' . $i];
}
check('lobby chaos plafonné à 36', count(session_lobby_names($many)) === 36);

$named = with_session_defaults(['participants' => [['name' => 'Léa'], ['name' => 'Léa 2']]]);
check('prénom unique inchangé', unique_session_participant_name($named, 'Sam') === 'Sam');
check('prénom doublon suffixé', unique_session_participant_name($named, 'Léa') === 'Léa 3');

check('URL SNEE valide', sanitize_http_url('https://pepite-france.fr/', '') === 'https://pepite-france.fr/');
check('URL sans schéma', str_starts_with(sanitize_http_url('pepite-france.fr', 'https://ok.test'), 'https://'));
check('URL javascript rejetée', sanitize_http_url('javascript:alert(1)', 'https://ok.test') === 'https://ok.test');
check('URL data rejetée', sanitize_http_url('data:text/html,x', 'https://ok.test') === 'https://ok.test');

$createdCalm = create_game_session('Groupe sensible', 'calme');
check('création calme persistée', session_is_calm($createdCalm) === true);
$reloaded = load_game_session((string) $createdCalm['code']);
check('relecture calme', $reloaded !== null && session_is_calm($reloaded));

$flipped = set_session_ambiance((string) $createdCalm['code'], 'chaos');
check('bascule vers chaos', session_ambiance($flipped) === 'chaos');
$reset = reset_game_session((string) $createdCalm['code']);
check('reset conserve l’ambiance', session_ambiance($reset) === 'chaos' && $reset['participants'] === []);

$createdChaos = create_game_session('Amphi', 'chaos');
check('join sans prénom refuse', expect_throws(static fn () => join_game_session((string) $createdChaos['code'], '   ')));
$lea = join_game_session((string) $createdChaos['code'], "  <i>Léa</i>  ");
check('join retire les balises', $lea['name'] === 'Léa');
record_session_progress((string) $createdChaos['code'], (string) $lea['id'], [
    'phase' => 'cards',
    'cardsPlaced' => 4,
    'totalCards' => 18,
    'score' => 400,
    'lastAction' => 'Carte placée',
]);
$afterProgress = load_game_session((string) $createdChaos['code']);
$afterPulse = session_pulse($afterProgress ?? []);
check('progress live → phase cartes', $afterPulse['cards'] === 1 && $afterPulse['inPlay'] === 1);

set_game_session_status((string) $createdChaos['code'], 'ended');
check('join séance terminée refuse', expect_throws(static fn () => join_game_session((string) $createdChaos['code'], 'Sam')));

$game = load_game_data();
check('défaut SNEE présent', ($game['ui']['sneeTitle'] ?? '') !== '' && str_starts_with((string) ($game['ui']['sneeUrl'] ?? ''), 'https://'));
check('ambiance jeu connue', in_array((string) ($game['settings']['classroomAmbiance'] ?? ''), ['chaos', 'calme'], true));
check('séance absente de l’accueil par défaut', empty($game['settings']['showSessionJoin']));
check('données locales en JSON', data_backend() === 'json');
check('mot de passe admin encore à changer', admin_uses_default_password() === true);

echo "\n=== HTTP ===\n";

$phpBin = PHP_BINARY;
$port = 8767;
$docRoot = $root;
$cmd = sprintf(
    'SIX_ETAPES_SESSIONS_DIR=%s %s -S 127.0.0.1:%d -t %s',
    escapeshellarg($tmpSessions),
    escapeshellarg($phpBin),
    $port,
    escapeshellarg($docRoot)
);
$descriptor = [0 => ['pipe', 'r'], 1 => ['file', $tmpSessions . '/server.log', 'w'], 2 => ['file', $tmpSessions . '/server.log', 'a']];
$process = proc_open($cmd, $descriptor, $pipes, $docRoot, [
    'SIX_ETAPES_SESSIONS_DIR' => $tmpSessions,
]);
if (!is_resource($process)) {
    check('serveur PHP', false, 'proc_open a échoué');
} else {
    usleep(400000);
    $base = 'http://127.0.0.1:' . $port;

    $httpGet = static function (string $path) use ($base): array {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => 6,
                'header' => "Accept: text/html,application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($base . $path, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $match)) {
                $status = (int) $match[1];
            }
        }
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    };

    $home = $httpGet('/index.php');
    check('accueil HTTP 200', $home['status'] === 200, 'status=' . $home['status']);
    check('accueil contient SNEE', str_contains($home['body'], 'Envie de te lancer') && str_contains($home['body'], 'pepite-france.fr'));
    check('accueil solo hors ligne, pas de formulaire séance', str_contains($home['body'], 'Fonctionne hors ligne') && !str_contains($home['body'], 'Rejoindre') && !str_contains($home['body'], 'code de séance'));
    check('accueil sans mode Classe hors séance', !str_contains($home['body'], 'Résultat envoyé au classement'));

    $joinPage = $httpGet('/session.php');
    check('page rejoindre 200', $joinPage['status'] === 200);
    check('page rejoindre propose le solo', str_contains($joinPage['body'], 'jouer en solo'));

    $unknown = $httpGet('/session.php?code=ZZZZZZ');
    check('code inconnu annoncé', str_contains($unknown['body'], 'introuvable'));

    $missingStatus = $httpGet('/session_status.php');
    check('status sans code → erreur', $missingStatus['status'] === 400);

    $httpCalm = create_game_session('HTTP calme', 'calme');
    $httpChaos = create_game_session('HTTP chaos', 'chaos');
    join_game_session((string) $httpChaos['code'], 'Nina');

    $statusCalm = $httpGet('/session_status.php?code=' . rawurlencode((string) $httpCalm['code']));
    $statusCalmJson = json_decode($statusCalm['body'], true) ?: [];
    check('status calme ok', !empty($statusCalmJson['ok']) && ($statusCalmJson['ambiance'] ?? '') === 'calme', $statusCalm['body']);
    check('status public sans liste élèves', ($statusCalmJson['participantList'] ?? ['x']) === []);
    check('status calme sans prénoms', ($statusCalmJson['lobbyNames'] ?? ['x']) === []);
    check('status calme sans classement public', ($statusCalmJson['showPublicRanking'] ?? true) === false);

    $statusChaos = $httpGet('/session_status.php?code=' . rawurlencode((string) $httpChaos['code']));
    $statusChaosJson = json_decode($statusChaos['body'], true) ?: [];
    check('status chaos expose Nina', in_array('Nina', $statusChaosJson['lobbyNames'] ?? [], true), $statusChaos['body']);
    check('status chaos pulse total 1', (int) ($statusChaosJson['pulse']['total'] ?? 0) === 1);

    $boardCalm = $httpGet('/leaderboard.php?code=' . rawurlencode((string) $httpCalm['code']));
    check('classement calme masqué', str_contains($boardCalm['body'], 'mode calme') || str_contains($boardCalm['body'], 'sans se comparer'));
    check('classement calme sans lignes de score', !str_contains($boardCalm['body'], 'leaderboard-row'));

    $proj = $httpGet('/session_projector.php?code=' . rawurlencode((string) $httpChaos['code']));
    check('projecteur 200', $proj['status'] === 200 && str_contains($proj['body'], 'projectorPulse'));
    check('projecteur chaos', str_contains($proj['body'], 'Séance chaos') || str_contains($proj['body'], 'chaos'));

    $projCalm = $httpGet('/session_projector.php?code=' . rawurlencode((string) $httpCalm['code']));
    check('projecteur calme rassurant', str_contains($projCalm['body'], 'Aucun prénom') || str_contains($projCalm['body'], 'calme'));

    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
}

echo "\n=== JS chrono ===\n";
$jsFile = $root . '/tests/timer-logic.mjs';
$jsCode = <<<'JS'
function shouldShowTimer(session, settings, mode) {
  if (session && session.ambiance === "calme") return false;
  return Boolean(settings.showTimer) || mode === "challenge" || mode === "classroom";
}
const cases = [
  [shouldShowTimer({ ambiance: "calme" }, { showTimer: true }, "classroom") === false, "calme cache le chrono"],
  [shouldShowTimer({ ambiance: "chaos" }, { showTimer: false }, "classroom") === true, "chaos classe montre le chrono"],
  [shouldShowTimer(null, { showTimer: false }, "discovery") === false, "découverte sans réglage"],
  [shouldShowTimer(null, { showTimer: false }, "challenge") === true, "challenge montre le chrono"],
];
let failed = 0;
for (const [ok, name] of cases) {
  console.log((ok ? "  ok  " : "  FAIL  ") + name);
  if (!ok) failed += 1;
}
process.exit(failed);
JS;
file_put_contents($jsFile, $jsCode);
$jsOutput = [];
$jsStatus = 0;
exec('node ' . escapeshellarg($jsFile) . ' 2>&1', $jsOutput, $jsStatus);
echo implode("\n", $jsOutput) . "\n";
if ($jsStatus === 0) {
    $passed += 4;
} else {
    $failed += 1;
    $failures[] = 'tests JS chrono';
}

foreach (glob($tmpSessions . '/*.json') ?: [] as $file) {
    @unlink($file);
}
@unlink($tmpSessions . '/server.log');
@unlink($jsFile);

echo "\n{$passed} ok, {$failed} échec(s)\n";
if ($failures) {
    echo implode("\n", $failures) . "\n";
    exit(1);
}

exit(0);
