<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

$code = normalize_session_code((string) ($_GET['code'] ?? ''));
$session = $code !== '' ? load_game_session($code) : null;
$game = load_game_data();
$ui = $game['ui'] ?? [];
$theme = $game['theme'] ?? [];
$brand = $game['brand'] ?? [];
$logoPath = (string) ($brand['logoPath'] ?? '');
$showRanking = $session !== null && session_shows_public_ranking($session, $game);
$ranking = $session !== null && $showRanking ? session_live_ranking($session) : [];
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="4">
    <title>Projection classement</title>
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
  </head>
  <body style="<?= $cssVars ?>">
    <main class="projector-shell">
      <?php if ($session === null): ?>
        <section class="projector-card"><h1>Session introuvable</h1></section>
      <?php else: ?>
        <section class="projector-card ranking-projector-card">
          <?php if ($logoPath !== ''): ?>
            <img class="projector-logo" src="<?= escape_html($logoPath) ?>" alt="">
          <?php endif; ?>
          <p><?= escape_html((string) ($ui['brandName'] ?? 'Jeu pédagogique')) ?> · <?= escape_html((string) $session['code']) ?></p>
          <h1>Classement</h1>
          <div class="projector-count"><?= count($session['participants']) ?> participant(s) · <?= count(session_leaderboard($session)) ?> terminé(s)</div>
          <?php if (!$showRanking): ?>
            <div class="leaderboard-empty"><?= session_is_calm($session) ? 'Mode calme : le tableau affiche l’avancement du groupe, pas un classement de personnes.' : 'Le classement est masqué pendant la partie. Il apparaîtra à la fin.' ?></div>
          <?php elseif (empty($ranking)): ?>
            <div class="leaderboard-empty">Aucun résultat pour le moment.</div>
          <?php else: ?>
            <ol class="projector-ranking">
              <?php foreach (array_slice($ranking, 0, 12) as $index => $participant): ?>
                <li>
                  <span><?= $index + 1 ?></span>
                  <strong><?= escape_html((string) $participant['name']) ?></strong>
                  <em><?= (int) $participant['score'] ?> pts</em>
                  <small><?= format_session_time((int) $participant['seconds']) ?> · <?= (int) $participant['errors'] ?> erreur<?= ((int) $participant['errors']) > 1 ? 's' : '' ?></small>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </main>
  </body>
</html>
