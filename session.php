<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/pwa.php';

$game = load_game_data();
$ui = $game['ui'] ?? [];
$theme = $game['theme'] ?? [];
$brand = $game['brand'] ?? [];
$code = normalize_session_code((string) ($_GET['code'] ?? $_POST['code'] ?? ''));
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $participant = join_game_session($code, (string) ($_POST['name'] ?? ''));
        header('Location: session.php?code=' . urlencode($code));
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$session = $code !== '' ? load_game_session($code) : null;
$participant = $session !== null ? current_session_participant($session) : null;
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

if ($session !== null && $participant !== null && $session['status'] === 'running') {
    header('Refresh: 1; URL=index.php?session=' . urlencode($session['code']));
}

if ($session !== null && $participant !== null && $session['status'] === 'waiting') {
    header('Refresh: 3');
}
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Rejoindre une session</title>
    <?= pwa_head_tags($game) ?>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
  </head>
  <body style="<?= $cssVars ?>">
    <main class="session-shell">
      <section class="session-card">
        <?php if ($logoPath !== ''): ?>
          <img class="session-logo" src="<?= escape_html($logoPath) ?>" alt="">
        <?php endif; ?>
        <p class="session-eyebrow"><?= escape_html((string) ($ui['brandName'] ?? 'Session classe')) ?></p>

        <?php if ($session !== null && $participant !== null): ?>
          <h1><?= escape_html((string) $session['title']) ?></h1>
          <div class="session-code"><?= escape_html((string) $session['code']) ?></div>
          <?php if ($session['status'] === 'waiting'): ?>
            <p class="session-text">Tu es connecté en tant que <strong><?= escape_html((string) $participant['name']) ?></strong>. Tout le monde joue en même temps : attends que l’enseignant lance.</p>
            <p class="session-text"><?= session_is_calm($session) ? 'Séance calme : tes erreurs restent sur ton écran, pas au tableau.' : 'Séance chaos : l’enseignant voit l’avancement de la classe en direct.' ?></p>
            <div class="session-loader" aria-hidden="true"></div>
          <?php elseif ($session['status'] === 'running'): ?>
            <p class="session-text">C’est parti. Le jeu s’ouvre automatiquement.</p>
            <a class="primary-button session-button" href="index.php?session=<?= urlencode((string) $session['code']) ?>">Jouer maintenant</a>
          <?php else: ?>
            <p class="session-text">Cette séance est terminée. Tu peux refaire l’atelier chez toi, à ton rythme.</p>
            <a class="primary-button session-button" href="index.php">Refaire en solo</a>
            <?php if (session_shows_public_ranking($session, $game)): ?>
              <a class="secondary-button session-button" href="leaderboard.php?code=<?= urlencode((string) $session['code']) ?>">Voir le classement</a>
            <?php endif; ?>
          <?php endif; ?>
        <?php elseif ($code === ''): ?>
          <h1>Compétition par lien</h1>
          <p class="session-text">Pour jouer avec la classe, ouvre le lien ou le QR de l’enseignant. Sans ça, tu peux jouer en solo, hors ligne.</p>
          <a class="primary-button session-button" href="index.php">Je préfère jouer en solo</a>
        <?php else: ?>
          <h1>Rejoindre la séance</h1>
          <p class="session-text">Entre ton prénom. Ensuite tout le monde part en même temps.</p>
          <?php if ($error !== null): ?>
            <div class="session-error"><?= escape_html($error) ?></div>
          <?php elseif ($session === null): ?>
            <div class="session-error">Ce code session est introuvable.</div>
          <?php endif; ?>
          <?php if ($session !== null): ?>
          <form class="session-form" method="post">
            <input type="hidden" name="code" value="<?= escape_html($code) ?>">
            <label>
              <span>Prénom</span>
              <input name="name" type="text" autocomplete="name" required>
            </label>
            <button class="primary-button session-button" type="submit">Rejoindre</button>
          </form>
          <?php endif; ?>
          <a class="admin-link" href="index.php">Je préfère jouer en solo</a>
        <?php endif; ?>
      </section>
    </main>
    <?= pwa_script_tag() ?>
  </body>
</html>
