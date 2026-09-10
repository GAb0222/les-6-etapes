<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/pwa.php';

$game = load_game_data();
$ui = $game['ui'] ?? [];
$theme = $game['theme'] ?? [];
$brand = $game['brand'] ?? [];
$code = normalize_session_code((string) ($_GET['code'] ?? ''));
$session = $code !== '' ? load_game_session($code) : null;
$leaderboard = $session !== null ? session_leaderboard($session) : [];
$showLiveLeaderboard = $session !== null && session_shows_public_ranking($session, $game);
$logoPath = (string) ($brand['logoPath'] ?? '');
$cssVars = sprintf(
    '--accent:%s;--brand-secondary:%s;--brand-accent:%s;--bg:%s;--paper:%s;--ink:%s;--muted:%s;',
    escape_html((string) ($theme['primary'] ?? '#1D5BD4')),
    escape_html((string) ($theme['secondary'] ?? '#34D399')),
    escape_html((string) ($theme['accent'] ?? '#F59E0B')),
    escape_html((string) ($theme['background'] ?? '#EEF3FB')),
    escape_html((string) ($theme['surface'] ?? '#FFFFFF')),
    escape_html((string) ($theme['text'] ?? '#1A1F36')),
    escape_html((string) ($theme['muted'] ?? '#6B7280'))
);
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta http-equiv="refresh" content="5">
    <title>Classement</title>
    <?= pwa_head_tags($game) ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
  </head>
  <body style="<?= $cssVars ?>">
    <main class="session-shell leaderboard-shell">
      <section class="session-card leaderboard-card">
        <?php if ($logoPath !== ''): ?>
          <img class="session-logo" src="<?= escape_html($logoPath) ?>" alt="">
        <?php endif; ?>
        <p class="session-eyebrow"><?= escape_html((string) ($ui['brandName'] ?? 'Classement')) ?></p>
        <h1>Classement</h1>
        <?php if ($session === null): ?>
          <p class="session-text">Session introuvable.</p>
        <?php else: ?>
          <div class="session-code"><?= escape_html((string) $session['code']) ?></div>
          <p class="session-text"><?= count($session['participants']) ?> participant<?= count($session['participants']) > 1 ? 's' : '' ?> inscrit<?= count($session['participants']) > 1 ? 's' : '' ?>.</p>
          <?php if (!$showLiveLeaderboard): ?>
            <div class="leaderboard-empty"><?= session_is_calm($session) ? 'Cette séance est en mode calme : aucun classement public, pour que chacun avance sans se comparer.' : 'Le classement est masqué pendant la partie. Il sera visible à la fin.' ?></div>
          <?php elseif (empty($leaderboard)): ?>
            <div class="leaderboard-empty">Aucun résultat pour l’instant.</div>
          <?php else: ?>
            <ol class="leaderboard-list">
              <?php foreach ($leaderboard as $index => $participant): ?>
                <li class="leaderboard-row">
                  <span class="leaderboard-rank"><?= $index + 1 ?></span>
                  <strong><?= escape_html((string) $participant['name']) ?></strong>
                  <span><?= (int) ($participant['score'] ?? 0) ?> pts</span>
                  <span><?= format_session_time((int) $participant['seconds']) ?></span>
                  <span><?= (int) $participant['errors'] ?> erreur<?= ((int) $participant['errors']) > 1 ? 's' : '' ?></span>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    </main>
  </body>
</html>
