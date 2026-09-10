<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

require_admin_auth();

function session_admin_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$flash = null;
$flashType = 'success';
$code = normalize_session_code((string) ($_GET['code'] ?? $_POST['session_code'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $flash = 'Le jeton de sécurité est invalide. Recharge la page puis recommence.';
        $flashType = 'error';
    } else {
        try {
            $action = (string) ($_POST['session_action'] ?? '');
            if ($action === 'start') {
                set_game_session_status($code, 'running');
                write_event('session_started', [], $code);
                $flash = 'La session est lancée.';
            } elseif ($action === 'pause') {
                set_game_session_status($code, 'waiting');
                write_event('session_waiting', [], $code);
                $flash = 'La session est remise en attente.';
            } elseif ($action === 'end') {
                set_game_session_status($code, 'ended');
                write_event('session_ended', [], $code);
                $flash = 'La session est terminée.';
            } elseif ($action === 'reset') {
                reset_game_session($code);
                write_event('session_reset', [], $code);
                $flash = 'La session est réinitialisée.';
            } elseif ($action === 'ambiance') {
                $session = set_session_ambiance($code, (string) ($_POST['ambiance'] ?? 'chaos'));
                write_event('session_ambiance', ['ambiance' => session_ambiance($session)], $code);
                $flash = session_is_calm($session)
                    ? 'Mode calme : pas de classement public, toi seul vois qui avance.'
                    : 'Mode chaos : tout le monde joue en même temps, le tableau peut afficher les prénoms.';
            } else {
                throw new RuntimeException('Action session inconnue.');
            }
        } catch (Throwable $exception) {
            $flash = $exception->getMessage();
            $flashType = 'error';
        }
    }
}

$session = $code !== '' ? load_game_session($code) : null;
$game = load_game_data();
$ui = $game['ui'] ?? [];
$theme = $game['theme'] ?? [];
$brand = $game['brand'] ?? [];
$logoPath = (string) ($brand['logoPath'] ?? '');
$basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$basePath = $basePath === '' ? '' : $basePath;
$origin = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
// Lien court (route .htaccess /s/CODE) : QR plus simple, et cible des universal links iOS.
$joinUrl = $session !== null ? $origin . $basePath . '/s/' . rawurlencode((string) $session['code']) : '';
$leaderboardUrl = $session !== null ? $origin . $basePath . '/leaderboard.php?code=' . urlencode((string) $session['code']) : '';
$rankingProjectorUrl = $session !== null ? $origin . $basePath . '/session_ranking_projector.php?code=' . urlencode((string) $session['code']) : '';
$statusLabels = [
    'waiting' => 'En attente',
    'running' => 'En cours',
    'ended' => 'Terminée',
];
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
    <title>Pilotage session</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
  </head>
  <body style="<?= $cssVars ?>">
    <main class="admin-shell live-session-shell">
      <header class="admin-topbar">
        <div>
          <h1>Pilotage de session</h1>
          <p>Partage le lien ou le QR : c’est le seul accès à la compétition. Le jeu solo, lui, marche hors ligne.</p>
        </div>
        <div class="admin-actions">
          <a class="link-button" href="admin.php">Retour admin</a>
          <?php if ($session !== null): ?>
            <a class="link-button" href="<?= session_admin_h($leaderboardUrl) ?>">Classement public</a>
            <a class="link-button" href="<?= session_admin_h($rankingProjectorUrl) ?>">Projection classement</a>
          <?php endif; ?>
        </div>
      </header>

      <?php if ($flash): ?>
        <div class="flash <?= session_admin_h($flashType) ?>"><?= session_admin_h($flash) ?></div>
      <?php endif; ?>

      <?php if ($session === null): ?>
        <section class="admin-card">
          <h2>Session introuvable</h2>
          <p>Retourne dans l’admin et choisis une session existante.</p>
        </section>
      <?php else: ?>
        <section class="live-hero admin-card" data-session-code="<?= session_admin_h((string) $session['code']) ?>">
          <div class="live-hero-main">
            <?php if ($logoPath !== ''): ?>
              <img class="live-logo" src="<?= session_admin_h($logoPath) ?>" alt="">
            <?php endif; ?>
            <p class="live-eyebrow"><?= session_admin_h((string) ($ui['brandName'] ?? 'Mode classe')) ?></p>
            <h2><?= session_admin_h((string) $session['title']) ?></h2>
            <div class="live-code" id="liveCode"><?= session_admin_h((string) $session['code']) ?></div>
            <p class="live-status">Statut : <strong id="liveStatus"><?= session_admin_h($statusLabels[$session['status']] ?? 'Session') ?></strong>
              · <span id="liveAmbiance"><?= session_is_calm($session) ? 'Calme' : 'Chaos' ?></span></p>
            <p class="live-status">PIN enseignant : <strong><?= session_admin_h((string) ($session['teacherPin'] ?? '')) ?></strong></p>
            <div class="live-pulse" id="livePulse">
              <article><strong id="pulseWaiting">0</strong><span>en salle</span></article>
              <article><strong id="pulseOrder">0</strong><span>ordre</span></article>
              <article><strong id="pulseCards">0</strong><span>cartes</span></article>
              <article><strong id="pulseDone">0</strong><span>terminés</span></article>
            </div>
          </div>
          <div class="live-qr-card">
            <img src="qr_code.php?data=<?= urlencode($joinUrl) ?>" alt="QR code de connexion">
            <p>Scanner pour rejoindre</p>
          </div>
        </section>

        <section class="admin-card live-actions-card">
          <div class="live-links">
            <label>
              <span>Lien élèves</span>
              <input id="joinLinkInput" value="<?= session_admin_h($joinUrl) ?>" readonly>
            </label>
            <button class="button secondary" type="button" data-copy-target="joinLinkInput">Copier le lien</button>
          <a class="link-button" href="<?= session_admin_h($joinUrl) ?>" target="_blank" rel="noreferrer">Ouvrir</a>
          <a class="link-button" href="session_projector.php?code=<?= urlencode((string) $session['code']) ?>" target="_blank" rel="noreferrer">Projection amphi</a>
          </div>
          <div class="session-admin-actions">
            <?php foreach ([['start', 'Lancer le jeu'], ['pause', 'Remettre en attente'], ['end', 'Terminer'], ['reset', 'Réinitialiser']] as $action): ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= session_admin_h(csrf_token()) ?>">
                <input type="hidden" name="session_code" value="<?= session_admin_h((string) $session['code']) ?>">
                <input type="hidden" name="session_action" value="<?= session_admin_h($action[0]) ?>">
                <button class="button <?= $action[0] === 'start' ? 'primary' : 'secondary' ?>" type="submit"><?= session_admin_h($action[1]) ?></button>
              </form>
            <?php endforeach; ?>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= session_admin_h(csrf_token()) ?>">
              <input type="hidden" name="session_code" value="<?= session_admin_h((string) $session['code']) ?>">
              <input type="hidden" name="session_action" value="ambiance">
              <input type="hidden" name="ambiance" value="<?= session_is_calm($session) ? 'chaos' : 'calme' ?>">
              <button class="button secondary" type="submit"><?= session_is_calm($session) ? 'Passer en Chaos' : 'Passer en Calme' ?></button>
            </form>
          </div>
        </section>

        <section class="live-grid">
          <article class="admin-card live-panel">
            <div class="admin-card-head">
              <div>
                <h2>Élèves connectés</h2>
                <p><span id="participantCount"><?= count($session['participants']) ?></span> participant(s)</p>
              </div>
            </div>
            <div id="participantList" class="live-list"></div>
          </article>
          <article class="admin-card live-panel">
            <div class="admin-card-head">
              <div>
                <h2><?= session_is_calm($session) ? 'Avancement (toi seul)' : 'Classement live' ?></h2>
                <p><span id="resultCount"><?= count(session_leaderboard($session)) ?></span> résultat(s) — invisible pour les élèves en mode calme</p>
              </div>
            </div>
            <ol id="liveLeaderboard" class="live-ranking"></ol>
          </article>
        </section>

        <section class="live-grid">
          <article class="admin-card">
            <h2>Cartes les plus ratées</h2>
            <div id="difficultCards" class="live-list"></div>
          </article>
          <article class="admin-card">
            <h2>Indicateurs pédagogiques</h2>
            <div id="sessionInsights" class="debrief-metrics compact"></div>
          </article>
        </section>
      <?php endif; ?>
    </main>

    <?php if ($session !== null): ?>
      <script>
        const sessionCode = <?= json_encode((string) $session['code']) ?>;
        const statusLabels = { waiting: "En attente", running: "En cours", ended: "Terminée" };
        const phaseLabels = { waiting: "Connecté", order: "Ordre des étapes", cards: "Classement des cartes", completed: "Terminé" };

        function escapeHtml(value) {
          return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
        }

        function formatTime(seconds) {
          const value = Number(seconds || 0);
          return `${String(Math.floor(value / 60)).padStart(2, "0")}:${String(value % 60).padStart(2, "0")}`;
        }

        async function refreshSession() {
          try {
            const response = await fetch(`session_status.php?code=${encodeURIComponent(sessionCode)}`, { cache: "no-store" });
            const payload = await response.json();
            if (!payload.ok) return;

            document.querySelector("#liveStatus").textContent = statusLabels[payload.status] || "Session";
            const ambianceEl = document.querySelector("#liveAmbiance");
            if (ambianceEl) ambianceEl.textContent = payload.ambiance === "calme" ? "Calme" : "Chaos";
            const pulse = payload.pulse || {};
            const setPulse = (id, value) => { const el = document.querySelector(id); if (el) el.textContent = String(value || 0); };
            setPulse("#pulseWaiting", pulse.waiting);
            setPulse("#pulseOrder", pulse.order);
            setPulse("#pulseCards", pulse.cards);
            setPulse("#pulseDone", pulse.completed);
            document.querySelector("#participantCount").textContent = payload.participants;
            document.querySelector("#resultCount").textContent = payload.results;
            document.querySelector("#participantList").innerHTML = payload.participantList.length
              ? payload.participantList.map((participant) => {
                const progress = participant.progress || {};
                const totalCards = Number(progress.totalCards || 0);
                const cardsPlaced = Number(progress.cardsPlaced || 0);
                const orderPlaced = Number(progress.orderPlaced || 0);
                const stepCount = Math.max(1, Number(payload.stepCount || 6));
                const percent = progress.phase === "order"
                  ? Math.round((orderPlaced / stepCount) * 100)
                  : totalCards > 0 ? Math.round((cardsPlaced / totalCards) * 100) : 0;
                return `
                <div class="live-list-row live-progress-row">
                  <div>
                    <strong>${escapeHtml(participant.name)}</strong>
                    <span>${phaseLabels[progress.phase] || "Connecté"} · ${escapeHtml(progress.lastAction || "En attente")}</span>
                    <div class="live-progress-track"><i style="width:${Math.max(0, Math.min(100, percent))}%"></i></div>
                  </div>
                  <div class="live-progress-stats">
                    <b>${percent}%</b>
                    <span>${Number(progress.score || 0)} pts</span>
                    <span>${Number(progress.errors || 0)} err.</span>
                    <span>${formatTime(progress.seconds)}</span>
                  </div>
                </div>
              `;
            }).join("")
              : '<div class="live-empty">Aucun élève connecté pour le moment.</div>';
            const liveRanking = payload.liveRanking || payload.leaderboard || [];
            document.querySelector("#liveLeaderboard").innerHTML = liveRanking.length
              ? liveRanking.map((participant, index) => `
                <li>
                  <span class="live-rank">${index + 1}</span>
                  <strong>${escapeHtml(participant.name)}</strong>
                  <span>${Number(participant.score || 0)} pts</span>
                  <span>${formatTime(participant.seconds)}</span>
                  <span>${participant.hasResult ? "final" : "live"}</span>
                  <span>${Number(participant.errors || 0)} erreur(s)</span>
                </li>
              `).join("")
              : '<li class="live-empty">Aucun résultat pour l’instant.</li>';
            const insights = payload.insights || {};
            document.querySelector("#difficultCards").innerHTML = (insights.difficultCards || []).length
              ? insights.difficultCards.map((card) => `
                <div class="live-list-row">
                  <strong>${escapeHtml(card.title)}</strong>
                  <span>${Number(card.count || 0)} erreur(s) · ${escapeHtml(card.step || "")}</span>
                </div>
              `).join("")
              : '<div class="live-empty">Aucune difficulté nette pour le moment.</div>';
            document.querySelector("#sessionInsights").innerHTML = `
              <article><strong>${Number(insights.finished || 0)}</strong><span>Terminés</span></article>
              <article><strong>${Number(insights.averageScore || 0)}</strong><span>Score moyen</span></article>
              <article><strong>${formatTime(insights.averageSeconds || 0)}</strong><span>Temps moyen</span></article>
              <article><strong>${Number(insights.averageErrors || 0)}</strong><span>Erreurs moy.</span></article>
            `;
          } catch (error) {
            // Le prochain rafraîchissement retentera automatiquement.
          }
        }

        document.querySelectorAll("[data-copy-target]").forEach((button) => {
          button.addEventListener("click", async () => {
            const input = document.querySelector(`#${button.dataset.copyTarget}`);
            await navigator.clipboard.writeText(input.value);
            button.textContent = "Copié";
            window.setTimeout(() => { button.textContent = "Copier le lien"; }, 1400);
          });
        });

        refreshSession();
        window.setInterval(refreshSession, 2500);
      </script>
    <?php endif; ?>
  </body>
</html>
