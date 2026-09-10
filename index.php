<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/pwa.php';

$game = load_game_data();
$sessionCode = normalize_session_code((string) ($_GET['session'] ?? ''));
$gameSession = $sessionCode !== '' ? load_game_session($sessionCode) : null;
$participant = $gameSession !== null ? current_session_participant($gameSession) : null;

if ($sessionCode !== '' && ($gameSession === null || $participant === null || $gameSession['status'] !== 'running')) {
    header('Location: session.php?code=' . urlencode($sessionCode));
    exit;
}

$bootstrap = [
    'settings' => $game['settings'] ?? [],
    'brand' => $game['brand'] ?? [],
    'theme' => $game['theme'] ?? [],
    'ui' => $game['ui'] ?? [],
    'steps' => $game['steps'] ?? [],
    'cards' => $game['cards'] ?? [],
    'session' => $gameSession !== null && $participant !== null ? [
        'code' => $gameSession['code'],
        'participantId' => $participant['id'],
        'participantName' => $participant['name'],
        'leaderboardUrl' => 'leaderboard.php?code=' . urlencode((string) $gameSession['code']),
        'ambiance' => session_ambiance($gameSession),
        'showPublicRanking' => session_shows_public_ranking($gameSession, $game),
    ] : null,
    'adminUrl' => 'admin.php',
];
$ui = $game['ui'] ?? [];
$brand = $game['brand'] ?? [];
$theme = $game['theme'] ?? [];
$stepCount = count($game['steps'] ?? []);
$cardCount = count($game['cards'] ?? []);
$documentTitle = (string) ($ui['documentTitle'] ?? 'Les 6 étapes de la création d’entreprise');
$logoPath = (string) ($brand['logoPath'] ?? '');
$faviconPath = (string) ($brand['faviconPath'] ?? '');
$decorations = (string) ($brand['decorations'] ?? '💡 🔎 💶 🤝 ⚖️ 🚀');
$bodyClasses = ['intro-active'];
if (empty($game['settings']['requireOrderFirst'])) {
    $bodyClasses[] = 'order-disabled';
}
if (empty($brand['showDecorations'])) {
    $bodyClasses[] = 'decorations-off';
}
$bodyClasses[] = 'decorations-' . preg_replace('/[^a-z]/', '', (string) ($brand['decorationIntensity'] ?? 'normal'));
$bodyClasses[] = 'theme-' . preg_replace('/[^a-z]/', '', (string) ($brand['visualStyle'] ?? 'classe'));
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
    <title><?= escape_html($documentTitle) ?></title>
    <?php if ($faviconPath !== ''): ?>
      <link rel="icon" href="<?= escape_html($faviconPath) ?>">
    <?php endif; ?>
    <?= pwa_head_tags($game) ?>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
  </head>
  <body class="<?= escape_html(implode(' ', $bodyClasses)) ?>" style="<?= $cssVars ?>">
    <main class="app-shell">
      <div class="brand-decorations" aria-hidden="true"><?= escape_html($decorations) ?></div>

      <section id="introScreen" class="intro-screen" aria-label="Accueil du jeu">
        <div class="intro-card">
          <div class="intro-brand">
            <?php if ($logoPath !== ''): ?>
              <img class="intro-logo" src="<?= escape_html($logoPath) ?>" alt="">
            <?php endif; ?>
            <span><?= escape_html((string) ($ui['brandName'] ?? 'Création d’entreprise')) ?></span>
          </div>
          <p class="intro-eyebrow">Jeu pédagogique</p>
          <h1><?= escape_html((string) ($ui['homeTitle'] ?? 'Les 6 étapes de la création d’entreprise')) ?></h1>
          <p class="intro-lead"><?= nl2br(escape_html((string) ($ui['homeBody'] ?? 'Tu vas d’abord retrouver l’ordre logique des étapes, puis classer les actions au bon moment du parcours.'))) ?></p>

          <div class="intro-flow" aria-label="Déroulé de l'activité">
            <?php if ($gameSession !== null): ?>
              <div class="intro-flow-item">
                <strong>1</strong>
                <span>Tout le monde joue en même temps : étapes, puis actions</span>
              </div>
              <div class="intro-flow-item">
                <strong>2</strong>
                <span><?= session_is_calm($gameSession) ? 'Tes erreurs restent sur ton écran, pas au tableau' : 'L’enseignant voit l’avancement de la classe en direct' ?></span>
              </div>
              <div class="intro-flow-item">
                <strong>3</strong>
                <span>À la fin, tu peux refaire chez toi — et, si tu veux, te renseigner sur le SNEE</span>
              </div>
            <?php else: ?>
              <div class="intro-flow-item">
                <strong>1</strong>
                <span>Remettre les <?= $stepCount ?> étape<?= $stepCount > 1 ? 's' : '' ?> dans l’ordre</span>
              </div>
              <div class="intro-flow-item">
                <strong>2</strong>
                <span>Classer chaque action dans la bonne étape</span>
              </div>
              <div class="intro-flow-item">
                <strong>3</strong>
                <span>Voir ce que tu as compris, puis refaire autant que tu veux</span>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($gameSession === null): ?>
            <p class="intro-offline-chip">Fonctionne hors ligne</p>
          <?php endif; ?>

          <?php if ($gameSession === null && !empty($game['settings']['showSessionJoin'])): ?>
            <form class="intro-session-form" action="session.php" method="post">
              <div>
                <p class="intro-session-title">Rejoindre une session</p>
                <p class="intro-session-text">Uniquement si l’enseignant t’a donné un code, en plus du lien.</p>
              </div>
              <div class="intro-session-fields">
                <input name="code" type="text" placeholder="CODE" autocomplete="off" inputmode="latin" required>
                <input name="name" type="text" placeholder="Prénom" autocomplete="name" required>
                <button class="primary-button" type="submit">Rejoindre</button>
              </div>
            </form>
          <?php endif; ?>

          <?php if ($gameSession === null): ?>
          <div class="mode-choice intro-mode-choice" role="group" aria-label="Choisir le rythme">
            <button class="mode-choice-button active" data-mode="discovery" type="button">
              <strong>Découverte</strong>
              <span>Comprendre, sans pression.</span>
            </button>
            <button class="mode-choice-button" data-mode="challenge" type="button">
              <strong>Challenge</strong>
              <span>Score, séries et chrono.</span>
            </button>
          </div>
          <?php endif; ?>

          <?php if ($gameSession !== null && $participant !== null): ?>
            <p class="intro-session">Session <?= escape_html((string) $gameSession['code']) ?> · <?= escape_html((string) $participant['name']) ?></p>
          <?php endif; ?>

          <button id="startIntroButton" class="primary-button intro-start" type="button">
            <?= escape_html((string) ($ui['homeButton'] ?? 'Commencer')) ?>
          </button>
        </div>
      </section>

      <header class="topbar">
        <div class="topbar-brand">
          <?php if ($logoPath !== ''): ?>
            <img class="topbar-logo" src="<?= escape_html($logoPath) ?>" alt="">
          <?php endif; ?>
          <strong><?= escape_html((string) ($ui['brandName'] ?? 'Création d’entreprise')) ?></strong>
        </div>
        <div class="topbar-progress">
          <div class="progress-track">
            <div id="progressFill" class="progress-fill"></div>
          </div>
          <span id="progressCount" class="progress-count">0/<?= $cardCount ?></span>
        </div>
        <div class="topbar-metrics">
          <?php if ($gameSession !== null && $participant !== null): ?>
            <a class="session-pill" href="leaderboard.php?code=<?= urlencode((string) $gameSession['code']) ?>">
              <?= escape_html((string) $participant['name']) ?> · <?= escape_html((string) $gameSession['code']) ?>
            </a>
          <?php endif; ?>
          <span id="scoreText" class="topbar-metric score-metric" data-label="Score" title="Score">0 pts</span>
          <span id="streakText" class="topbar-metric streak-metric" data-label="Série" title="Série de bonnes réponses">Série 0</span>
          <span id="masteryText" class="topbar-metric mastery-metric" data-label="Étapes" title="Étapes maîtrisées">0/<?= $stepCount ?></span>
          <span id="errorCount" class="topbar-metric error-metric" data-label="Erreurs" title="Erreurs">0</span>
          <span id="timerText" class="topbar-metric timer-metric" data-label="Temps" title="Temps" hidden>00:00</span>
        </div>
      </header>

      <section class="order-panel" id="orderPanel" aria-label="Remettre les étapes dans l'ordre">
        <div class="order-panel-header">
          <div>
            <p class="order-eyebrow">Étape d'échauffement</p>
            <h1>Remets les <?= $stepCount ?> étape<?= $stepCount > 1 ? 's' : '' ?> dans le bon ordre</h1>
          </div>
          <div class="order-actions">
            <button id="orderResetButton" class="ghost-button" type="button">Mélanger</button>
            <button id="orderValidateButton" class="primary-button" type="button">Valider l'ordre</button>
          </div>
        </div>
        <div class="order-workspace">
          <div>
            <p class="order-column-title">Cartes étapes</p>
            <div id="orderPool" class="order-pool"></div>
          </div>
          <div>
            <p class="order-column-title">Ordre attendu</p>
            <div id="orderSlots" class="order-slots"></div>
          </div>
        </div>
        <p id="orderFeedback" class="order-feedback">Objectif : remettre les étapes, puis les sous-étapes, dans le bon ordre logique.</p>
      </section>

      <section class="game-shell">
        <aside class="active-panel" aria-label="Carte active">
          <div id="activeCardShell" class="active-card-shell"></div>

          <div class="mini-progress">
            <div class="mini-progress-label">Progression</div>
            <div id="miniProgressList" class="mini-progress-list"></div>
          </div>

          <div class="action-stack">
            <button id="hintButton" class="secondary-button" type="button"><?= escape_html((string) ($ui['hintButton'] ?? 'Indice')) ?></button>
            <button id="skipCardButton" class="secondary-button" type="button"><?= escape_html((string) ($ui['skipButton'] ?? 'Passer')) ?></button>
            <button id="resetButton" class="ghost-button" type="button"><?= escape_html((string) ($ui['resetButton'] ?? 'Recommencer')) ?></button>
          </div>

          <div class="mode-pill" id="modePill">Découverte</div>

          <p id="hintText" class="hint-text"><?= escape_html((string) ($ui['hintDefault'] ?? 'Clique sur l’étape correspondante à la carte en cours.')) ?></p>
          <div class="learning-note">
            <span aria-hidden="true">🎓</span>
            <p id="learningText">Objectif : comprendre à quel moment chaque action devient utile dans un projet de création.</p>
          </div>
        </aside>

        <section class="board-panel" aria-label="Etapes du parcours">
          <div id="stepsGrid" class="steps-grid"></div>
        </section>
      </section>

      <div id="feedbackToast" class="feedback-toast" role="status" aria-live="polite"></div>

      <section id="completionScreen" class="completion-screen" hidden aria-label="Fin de partie">
        <div class="completion-card">
          <button id="closeCompletionButton" class="completion-close" type="button" aria-label="Fermer">✕</button>
          <div id="starsRow" class="stars-row" aria-hidden="true"></div>
          <h2 id="completionTitle">Parfait !</h2>
          <p id="completionSummary" class="completion-summary"></p>
          <div class="completion-stats">
            <div class="completion-stat completion-stat-primary">
              <strong id="completionScore">0</strong>
              <span>points</span>
            </div>
            <div class="completion-stat">
              <strong id="completionMastery">0/<?= $stepCount ?></strong>
              <span>étapes maîtrisées</span>
            </div>
            <div class="completion-stat">
              <strong id="completionMistakes">0</strong>
              <span>erreurs</span>
            </div>
            <div class="completion-stat">
              <strong id="completionTime">00:00</strong>
              <span>temps</span>
            </div>
            <div class="completion-stat">
              <strong id="completionCards"><?= $cardCount ?>/<?= $cardCount ?></strong>
              <span>cartes</span>
            </div>
          </div>
          <div class="completion-actions">
            <?php if ($gameSession !== null && session_shows_public_ranking($gameSession, $game)): ?>
              <a class="primary-button completion-button" href="leaderboard.php?code=<?= urlencode((string) $gameSession['code']) ?>">Voir le classement</a>
              <button id="playSoloButton" class="secondary-button completion-button" type="button">Refaire en solo</button>
              <button id="playAgainButton" class="ghost-button completion-button" type="button"><?= escape_html((string) ($ui['resetButton'] ?? 'Recommencer')) ?></button>
            <?php elseif ($gameSession !== null): ?>
              <button id="playSoloButton" class="primary-button completion-button" type="button">Refaire en solo chez moi</button>
              <button id="playAgainButton" class="secondary-button completion-button" type="button"><?= escape_html((string) ($ui['resetButton'] ?? 'Recommencer')) ?></button>
            <?php else: ?>
              <button id="playAgainButton" class="primary-button completion-button" type="button"><?= escape_html((string) ($ui['resetButton'] ?? 'Recommencer')) ?></button>
              <button id="playSoloButton" class="secondary-button completion-button" type="button" hidden>Refaire en solo</button>
            <?php endif; ?>
            <button id="replayErrorsButton" class="secondary-button completion-button" type="button" hidden>Rejouer mes erreurs</button>
          </div>
          <?php if (trim((string) ($ui['sneeTitle'] ?? '')) !== ''): ?>
            <aside class="next-step" aria-label="Après l’atelier">
              <p class="next-step-eyebrow">Et maintenant</p>
              <strong><?= escape_html((string) $ui['sneeTitle']) ?></strong>
              <p><?= escape_html((string) ($ui['sneeBody'] ?? '')) ?></p>
              <?php if (!empty($ui['sneeUrl'])): ?>
                <a class="secondary-button" href="<?= escape_html((string) $ui['sneeUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= escape_html((string) ($ui['sneeCta'] ?? 'Découvrir le SNEE')) ?></a>
              <?php endif; ?>
            </aside>
          <?php endif; ?>
          <div id="completionReview" class="completion-review"></div>
          <form id="correctionEmailForm" class="completion-email-form">
            <label for="correctionEmail">Recevoir le corrigé par e-mail</label>
            <div>
              <input id="correctionEmail" name="email" type="email" placeholder="prenom@exemple.fr" autocomplete="email" required>
              <button class="secondary-button" type="submit">Envoyer</button>
            </div>
            <p id="correctionEmailStatus" class="completion-email-status" aria-live="polite"></p>
          </form>
          <div class="completion-links">
            <button id="installButton" class="admin-link install-link" type="button" hidden>Ajouter à l'écran d'accueil</button>
            <a class="admin-link" href="correction.php">Corrigé</a>
            <a class="admin-link" href="print.php" onclick="window.open(this.href, '_blank'); return false;">Imprimer / PDF</a>
            <a class="admin-link" href="admin.php"><?= escape_html((string) ($ui['completionAdminLabel'] ?? 'Admin')) ?></a>
          </div>
        </div>
      </section>

    </main>

    <script>
      window.APP_BOOTSTRAP = <?= json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
    <?= pwa_script_tag() ?>
  </body>
</html>
