<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

$game = load_game_data();
$ui = $game['ui'] ?? [];
$brand = $game['brand'] ?? [];
$theme = $game['theme'] ?? [];
$logoPath = (string) ($brand['logoPath'] ?? '');
$steps = $game['steps'] ?? [];
$cards = $game['cards'] ?? [];
$stepsById = [];
foreach ($steps as $step) {
    $stepsById[(int) $step['id']] = $step;
}
$cssVars = sprintf(
    '--accent:%s;--brand-secondary:%s;--brand-accent:%s;--paper:%s;--ink:%s;--muted:%s;',
    escape_html((string) ($theme['primary'] ?? '#1D5BD4')),
    escape_html((string) ($theme['secondary'] ?? '#34D399')),
    escape_html((string) ($theme['accent'] ?? '#F59E0B')),
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
    <title>Corrigé imprimable</title>
    <link rel="stylesheet" href="assets/print.css?v=<?= filemtime(__DIR__ . '/assets/print.css') ?>">
  </head>
  <body style="<?= $cssVars ?>">
    <main class="paper-pack">
      <section class="paper-page correction-page">
        <header class="paper-topbar">
          <div class="paper-brand">
            <?php if ($logoPath !== ''): ?>
              <img src="<?= escape_html($logoPath) ?>" alt="">
            <?php endif; ?>
            <strong><?= escape_html((string) ($ui['brandName'] ?? 'Jeu pédagogique')) ?></strong>
          </div>
          <div class="paper-progress"><span></span></div>
          <div class="paper-score">Corrigé</div>
        </header>
        <div class="paper-intro-card correction-intro">
          <p class="paper-eyebrow">Support enseignant</p>
          <h1>Corrigé du jeu</h1>
          <p class="paper-lead">Utilise cette page pour animer la correction, faire verbaliser les élèves et revenir sur les cartes les plus difficiles.</p>
        </div>
        <div class="paper-actions no-print">
          <button onclick="window.print()">Imprimer / exporter PDF</button>
          <a href="print.php">Version papier</a>
          <a href="index.php">Retour au jeu</a>
        </div>
        <table class="correction-table">
          <thead>
            <tr>
              <th>Carte</th>
              <th>Action</th>
              <th>Étape attendue</th>
              <th>Type</th>
              <th>Compétence</th>
              <th>Pourquoi</th>
              <th>Erreur fréquente</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cards as $card): ?>
              <?php $step = $stepsById[(int) $card['stepId']] ?? null; ?>
              <tr>
                <td><?= (int) $card['id'] ?></td>
                <td><?= escape_html((string) $card['title']) ?></td>
                <td><?= $step ? escape_html((string) $step['title']) : '-' ?></td>
                <td><?= escape_html((string) ($card['type'] ?? 'simple')) ?></td>
                <td><?= escape_html((string) ($card['competency'] ?? 'general')) ?></td>
                <td><?= escape_html((string) ($card['explanation'] ?? $card['desc'])) ?></td>
                <td><?= escape_html((string) ($card['errorExplanation'] ?? 'Comparer le verbe d’action avec le rôle de l’étape.')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>

    </main>
  </body>
</html>
