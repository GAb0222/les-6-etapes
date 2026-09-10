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
$basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$basePath = $basePath === '' ? '' : $basePath;
$origin = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$joinUrl = $session !== null ? $origin . $basePath . '/s/' . rawurlencode((string) $session['code']) : '';
$pulse = $session !== null ? session_pulse($session) : null;
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
    <title>Projection session</title>
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
  </head>
  <body style="<?= $cssVars ?>">
    <main class="projector-shell">
      <?php if ($session === null): ?>
        <section class="projector-card"><h1>Session introuvable</h1></section>
      <?php else: ?>
        <section class="projector-card" data-session-code="<?= escape_html((string) $session['code']) ?>">
          <?php if ($logoPath !== ''): ?>
            <img class="projector-logo" src="<?= escape_html($logoPath) ?>" alt="">
          <?php endif; ?>
          <p id="projectorEyebrow"><?= escape_html((string) ($ui['brandName'] ?? 'Jeu pédagogique')) ?>
            · <?= session_is_calm($session) ? 'Séance calme' : 'Séance chaos' ?></p>
          <h1 id="projectorTitle"><?= ($session['status'] ?? '') === 'running' ? 'La classe joue' : (($session['status'] ?? '') === 'ended' ? 'Séance terminée' : 'Rejoignez la séance') ?></h1>

          <div class="projector-grid" id="projectorLobby">
            <div>
              <span class="projector-label">Code</span>
              <strong class="projector-code"><?= escape_html((string) $session['code']) ?></strong>
              <span id="projectorCount" class="projector-count"><?= (int) ($pulse['total'] ?? 0) ?> connecté(s)</span>
            </div>
            <img class="projector-qr" src="qr_code.php?data=<?= urlencode($joinUrl) ?>" alt="QR code de connexion">
          </div>

          <div class="projector-pulse" id="projectorPulse"<?= ($session['status'] ?? '') === 'waiting' ? ' hidden' : '' ?>>
            <article><strong id="pulseWaiting"><?= (int) ($pulse['waiting'] ?? 0) ?></strong><span>En salle</span></article>
            <article><strong id="pulseOrder"><?= (int) ($pulse['order'] ?? 0) ?></strong><span>Ordre des étapes</span></article>
            <article><strong id="pulseCards"><?= (int) ($pulse['cards'] ?? 0) ?></strong><span>Cartes</span></article>
            <article><strong id="pulseDone"><?= (int) ($pulse['completed'] ?? 0) ?></strong><span>Terminés</span></article>
          </div>

          <div class="projector-names" id="projectorNames" hidden></div>
          <p class="projector-hint" id="projectorHint">
            <?= session_is_calm($session)
                ? 'Aucun prénom ni score n’est affiché au tableau. L’enseignant suit l’avancement de son côté.'
                : 'Scanne le QR ou saisis le code, puis attends le lancement.' ?>
          </p>
          <div class="projector-url"><?= escape_html($joinUrl) ?></div>
        </section>
      <?php endif; ?>
    </main>
    <?php if ($session !== null): ?>
      <script>
        const code = <?= json_encode((string) $session['code']) ?>;
        const titles = { waiting: "Rejoignez la séance", running: "La classe joue", ended: "Séance terminée" };

        function escapeHtml(value) {
          return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;");
        }

        async function refreshProjector() {
          const response = await fetch(`session_status.php?code=${encodeURIComponent(code)}`, { cache: "no-store" });
          const data = await response.json();
          if (!data.ok) return;

          const pulse = data.pulse || {};
          const isCalm = data.ambiance === "calme";
          const isLive = data.status === "running" || data.status === "ended";

          document.querySelector("#projectorTitle").textContent = titles[data.status] || titles.waiting;
          document.querySelector("#projectorCount").textContent = `${data.participants} connecté(s)`;
          document.querySelector("#pulseWaiting").textContent = pulse.waiting || 0;
          document.querySelector("#pulseOrder").textContent = pulse.order || 0;
          document.querySelector("#pulseCards").textContent = pulse.cards || 0;
          document.querySelector("#pulseDone").textContent = pulse.completed || 0;
          document.querySelector("#projectorPulse").hidden = !isLive && data.participants === 0;
          document.querySelector("#projectorLobby").hidden = data.status === "ended";
          document.querySelector("#projectorHint").textContent = isCalm
            ? "Aucun prénom ni score n’est affiché au tableau. Chacun avance sans se comparer."
            : (data.status === "running"
              ? "Tout le monde joue en même temps. L’enseignant voit qui avance."
              : "Scanne le QR ou saisis le code, puis attends le lancement.");

          const namesEl = document.querySelector("#projectorNames");
          const names = isCalm ? [] : (data.lobbyNames || []);
          namesEl.hidden = names.length === 0;
          namesEl.innerHTML = names.map((name) => `<span>${escapeHtml(name)}</span>`).join("");
        }
        refreshProjector();
        window.setInterval(refreshProjector, 2500);
      </script>
    <?php endif; ?>
  </body>
</html>
