<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

$game = load_game_data();
$ui = $game['ui'] ?? [];
$brand = $game['brand'] ?? [];
$theme = $game['theme'] ?? [];
$logoPath = (string) ($brand['logoPath'] ?? '');
$decorations = (string) ($brand['decorations'] ?? '💡 🔎 💶 🤝 ⚖️ 🚀');
$steps = array_values($game['steps'] ?? []);
$cards = array_values($game['cards'] ?? []);
$cardsChunks = array_chunk($cards, 9);
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
    <title>Kit papier - <?= escape_html((string) ($ui['documentTitle'] ?? 'Les 6 étapes')) ?></title>
    <link rel="stylesheet" href="assets/print.css?v=<?= filemtime(__DIR__ . '/assets/print.css') ?>">
  </head>
  <body style="<?= $cssVars ?>">
    <div class="paper-actions no-print">
      <button onclick="window.print()">Imprimer / exporter PDF</button>
      <a href="correction.php">Corrigé animateur</a>
      <a href="index.php">Jeu digital</a>
      <a href="admin.php">Admin</a>
    </div>

    <main class="paper-pack">
      <section class="paper-page paper-cover">
        <div class="paper-decorations" aria-hidden="true"><?= escape_html($decorations) ?></div>
        <header class="paper-topbar">
          <div class="paper-brand">
            <?php if ($logoPath !== ''): ?>
              <img src="<?= escape_html($logoPath) ?>" alt="">
            <?php endif; ?>
            <strong><?= escape_html((string) ($ui['brandName'] ?? 'Création d’entreprise')) ?></strong>
          </div>
          <div class="paper-progress">
            <span></span>
          </div>
          <div class="paper-score">Kit papier</div>
        </header>

        <div class="paper-intro-card">
          <p class="paper-eyebrow">Jeu pédagogique imprimable</p>
          <h1><?= escape_html((string) ($ui['documentTitle'] ?? 'Les 6 étapes de la création d’entreprise')) ?></h1>
          <p class="paper-lead"><?= nl2br(escape_html((string) ($ui['homeBody'] ?? 'Retrouve l’ordre logique des étapes, puis classe chaque action au bon moment du parcours.'))) ?></p>
          <div class="paper-flow">
            <article>
              <strong>1</strong>
              <span>Remettre les 6 étapes dans l’ordre</span>
            </article>
            <article>
              <strong>2</strong>
              <span>Découper les cartes actions</span>
            </article>
            <article>
              <strong>3</strong>
              <span>Associer chaque carte à la bonne étape</span>
            </article>
          </div>
        </div>

        <div class="paper-note">
          <h2>Matériel à préparer</h2>
          <ol>
            <li>Imprimer le kit en A4.</li>
            <li>Découper les cartes “étapes” et les cartes “actions”.</li>
            <li>Donner le plateau aux élèves ou le poser au centre de la table.</li>
            <li>Utiliser le corrigé séparé pour animer la discussion.</li>
          </ol>
        </div>
      </section>

      <section class="paper-page">
        <header class="paper-section-header">
          <p class="paper-eyebrow">Échauffement</p>
          <h2>Remets les 6 étapes dans le bon ordre</h2>
        </header>

        <div class="paper-order-layout">
          <div>
            <p class="paper-column-title">Cartes étapes à découper</p>
            <div class="paper-order-cards">
              <?php foreach ($steps as $step): ?>
                <article class="paper-order-card" style="--step-color: <?= escape_html((string) ($step['color'] ?? '#1D5BD4')) ?>">
                  <?= escape_html((string) ($step['short'] ?? $step['title'])) ?>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <p class="paper-column-title">Ordre attendu</p>
            <div class="paper-order-slots">
              <?php foreach ($steps as $index => $step): ?>
                <article class="paper-order-slot">
                  <strong><?= $index + 1 ?></strong>
                  <span>Déposer l’étape ici</span>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </section>

      <section class="paper-page">
        <header class="paper-section-header">
          <p class="paper-eyebrow">Plateau de jeu</p>
          <h2>Classe les actions dans la bonne étape</h2>
        </header>
        <div class="paper-board">
          <?php foreach ($steps as $index => $step): ?>
            <?php $color = (string) ($step['color'] ?? '#1D5BD4'); ?>
            <article class="paper-step-card" style="--step-color: <?= escape_html($color) ?>">
              <header>
                <strong><?= $index + 1 ?></strong>
                <h3><?= escape_html((string) $step['title']) ?></h3>
              </header>
              <div class="paper-step-body">
                <?php for ($slot = 1; $slot <= 3; $slot += 1): ?>
                  <div class="paper-drop-slot">Déposer une carte ici</div>
                <?php endfor; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <?php foreach ($cardsChunks as $pageIndex => $chunk): ?>
        <section class="paper-page paper-cards-page">
          <header class="paper-section-header">
            <p class="paper-eyebrow">Cartes actions</p>
            <h2>Cartes à découper <?= count($cardsChunks) > 1 ? ($pageIndex + 1) . '/' . count($cardsChunks) : '' ?></h2>
          </header>
          <div class="paper-card-grid">
            <?php foreach ($chunk as $card): ?>
              <article class="paper-action-card">
                <small><?= escape_html((string) ($card['type'] ?? 'Carte')) ?> · <?= escape_html((string) ($card['competency'] ?? 'general')) ?></small>
                <h3><?= escape_html((string) $card['title']) ?></h3>
                <p><?= escape_html((string) (($card['type'] ?? '') === 'case' && !empty($card['casePrompt']) ? $card['casePrompt'] : $card['desc'])) ?></p>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </main>
  </body>
</html>
