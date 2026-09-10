<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

require_admin_auth();

function debrief_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$code = normalize_session_code((string) ($_GET['code'] ?? ''));
$session = $code !== '' ? load_game_session($code) : null;
$game = load_game_data();
$ui = $game['ui'] ?? [];
$brand = $game['brand'] ?? [];
$theme = $game['theme'] ?? [];
$logoPath = (string) ($brand['logoPath'] ?? '');
$insights = $session !== null ? session_insights($session, $game) : null;
$ranking = $session !== null ? session_live_ranking($session) : [];
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
    <title>Débrief session</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
  </head>
  <body style="<?= $cssVars ?>">
    <main class="admin-shell live-session-shell">
      <header class="admin-topbar">
        <div>
          <h1>Débrief pédagogique</h1>
          <p>Transforme les résultats en correction utile pour la classe.</p>
        </div>
        <div class="admin-actions">
          <a class="link-button" href="admin.php">Admin</a>
          <?php if ($session !== null): ?>
            <a class="link-button" href="admin_session.php?code=<?= urlencode((string) $session['code']) ?>">Pilotage</a>
            <a class="link-button" href="export_session_csv.php?code=<?= urlencode((string) $session['code']) ?>">Export CSV</a>
          <?php endif; ?>
        </div>
      </header>

      <?php if ($session === null || $insights === null): ?>
        <section class="admin-card"><h2>Session introuvable</h2></section>
      <?php else: ?>
        <section class="admin-card debrief-hero">
          <?php if ($logoPath !== ''): ?>
            <img class="live-logo" src="<?= debrief_h($logoPath) ?>" alt="">
          <?php endif; ?>
          <p class="live-eyebrow"><?= debrief_h((string) ($ui['brandName'] ?? 'Jeu pédagogique')) ?></p>
          <h2><?= debrief_h((string) $session['title']) ?> · <?= debrief_h((string) $session['code']) ?></h2>
          <div class="debrief-metrics">
            <article><strong><?= (int) $insights['participants'] ?></strong><span>Participants</span></article>
            <article><strong><?= (int) $insights['finished'] ?></strong><span>Terminés</span></article>
            <article><strong><?= (int) $insights['averageScore'] ?></strong><span>Score moyen</span></article>
            <article><strong><?= format_session_time((int) $insights['averageSeconds']) ?></strong><span>Temps moyen</span></article>
            <article><strong><?= debrief_h((string) $insights['averageErrors']) ?></strong><span>Erreurs moy.</span></article>
          </div>
        </section>

        <section class="live-grid">
          <article class="admin-card">
            <h2>Cartes à retravailler</h2>
            <div class="live-list">
              <?php if (empty($insights['difficultCards'])): ?>
                <div class="live-empty">Aucune difficulté marquée pour le moment.</div>
              <?php endif; ?>
              <?php foreach ($insights['difficultCards'] as $card): ?>
                <div class="live-list-row">
                  <strong><?= debrief_h((string) $card['title']) ?></strong>
                  <span><?= (int) $card['count'] ?> erreur(s) · <?= debrief_h((string) $card['step']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </article>
          <article class="admin-card">
            <h2>Étapes confondues</h2>
            <div class="live-list">
              <?php if (empty($insights['difficultSteps'])): ?>
                <div class="live-empty">Aucune étape particulièrement difficile.</div>
              <?php endif; ?>
              <?php foreach ($insights['difficultSteps'] as $step): ?>
                <div class="live-list-row">
                  <strong><?= debrief_h((string) $step['title']) ?></strong>
                  <span><?= (int) $step['count'] ?> erreur(s)</span>
                </div>
              <?php endforeach; ?>
            </div>
          </article>
        </section>

        <section class="live-grid">
          <article class="admin-card">
            <h2>Classement et progression</h2>
            <div class="live-list">
              <?php if (empty($ranking)): ?>
                <div class="live-empty">Aucun participant pour le moment.</div>
              <?php endif; ?>
              <?php foreach (array_slice($ranking, 0, 10) as $index => $participant): ?>
                <?php $progress = is_array($participant['progress'] ?? null) ? $participant['progress'] : []; ?>
                <div class="live-list-row">
                  <strong>#<?= $index + 1 ?> · <?= debrief_h((string) $participant['name']) ?></strong>
                  <span><?= (int) ($participant['score'] ?? 0) ?> pts · <?= (int) ($participant['errors'] ?? 0) ?> erreur(s) · <?= debrief_h((string) ($progress['lastAction'] ?? '')) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </article>
          <article class="admin-card">
            <h2>Plan de reprise conseillé</h2>
            <div class="live-list">
              <?php if (empty($insights['difficultCards']) && empty($insights['difficultSteps'])): ?>
                <div class="live-list-row">
                  <strong>Validation rapide</strong>
                  <span>La classe semble avoir compris. Demande à deux élèves d’expliquer l’ordre complet.</span>
                </div>
              <?php else: ?>
                <div class="live-list-row">
                  <strong>1. Reprendre les confusions fortes</strong>
                  <span>Commence par les cartes les plus ratées, sans donner la réponse directement.</span>
                </div>
                <div class="live-list-row">
                  <strong>2. Faire verbaliser la logique</strong>
                  <span>Demande “qu’est-ce qui doit être fait avant ?” puis “quelle preuve permet d’avancer ?”.</span>
                </div>
                <div class="live-list-row">
                  <strong>3. Rejouer en binômes</strong>
                  <span>Relance une session courte ou utilise la version papier pour consolider.</span>
                </div>
              <?php endif; ?>
            </div>
          </article>
        </section>

        <section class="admin-card debrief-prompts">
          <h2>Questions de débrief</h2>
          <ol>
            <li>Quelle étape vous semblait la plus logique ? Pourquoi ?</li>
            <li>Quelle carte vous a le plus fait hésiter ?</li>
            <li>Quelle action faut-il absolument faire avant de créer officiellement l’entreprise ?</li>
          </ol>
        </section>
      <?php endif; ?>
    </main>
  </body>
</html>
