<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$flash = null;
$flashType = 'success';
$loginError = null;
$requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($requestMethod === 'POST' && ($_POST['form_type'] ?? '') === 'login') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $lockUntil = (int) ($_SESSION['admin_login_lock_until'] ?? 0);

    if ($lockUntil > time()) {
        $loginError = 'Trop de tentatives. Réessaie dans ' . ($lockUntil - time()) . ' seconde(s).';
        write_event('admin_login_blocked', ['username' => $username, 'remainingSeconds' => $lockUntil - time()], null, 'anonymous');
    } elseif (verify_admin_credentials($username, $password)) {
        $_SESSION['admin_user'] = $username;
        $_SESSION['admin_last_seen'] = time();
        unset($_SESSION['admin_login_failures'], $_SESSION['admin_login_lock_until']);
        write_event('admin_login_success', ['username' => $username], null, $username);
        header('Location: admin.php');
        exit;
    } else {
        $_SESSION['admin_login_failures'] = (int) ($_SESSION['admin_login_failures'] ?? 0) + 1;
        if ($_SESSION['admin_login_failures'] >= 5) {
            $_SESSION['admin_login_lock_until'] = time() + 300;
        }
        write_event('admin_login_failed', ['username' => $username, 'failures' => $_SESSION['admin_login_failures']], null, 'anonymous');
        $loginError = 'Identifiants invalides.';
    }
}

if (admin_is_authenticated() && $requestMethod === 'POST' && ($_POST['form_type'] ?? '') === 'content') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $flash = 'Le jeton de sécurité est invalide. Recharge la page puis recommence.';
        $flashType = 'error';
    } else {
        try {
            $payload = sanitize_game_payload($_POST['game'] ?? [], $_FILES, load_game_data());
            save_game_data($payload);
            write_event('game_config_saved', ['cards' => count($payload['cards'] ?? []), 'steps' => count($payload['steps'] ?? [])]);
            $flash = 'Le contenu du jeu a bien été enregistré.';
        } catch (Throwable $exception) {
            $flash = $exception->getMessage();
            $flashType = 'error';
        }
    }
}

if (admin_is_authenticated() && $requestMethod === 'POST' && ($_POST['form_type'] ?? '') === 'security') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $flash = 'Le jeton de sécurité est invalide. Recharge la page puis recommence.';
        $flashType = 'error';
    } else {
        try {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
            $username = (string) ($_SESSION['admin_user'] ?? 'admin');
            if (!verify_admin_credentials($username, $currentPassword)) {
                throw new RuntimeException('Le mot de passe actuel est incorrect.');
            }
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('Les deux nouveaux mots de passe ne correspondent pas.');
            }
            save_admin_password($username, $newPassword);
            write_event('admin_password_changed', ['username' => $username], null, $username);
            $flash = 'Le mot de passe admin a été changé.';
        } catch (Throwable $exception) {
            $flash = $exception->getMessage();
            $flashType = 'error';
        }
    }
}

if (admin_is_authenticated() && $requestMethod === 'POST' && ($_POST['form_type'] ?? '') === 'session') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $flash = 'Le jeton de sécurité est invalide. Recharge la page puis recommence.';
        $flashType = 'error';
    } else {
        try {
            $action = (string) ($_POST['session_action'] ?? '');
            $code = (string) ($_POST['session_code'] ?? '');

            if ($action === 'create') {
                $session = create_game_session((string) ($_POST['session_title'] ?? ''), (string) ($_POST['session_ambiance'] ?? ''));
                write_event('session_created', ['title' => $session['title']], (string) $session['code']);
                $flash = 'Session créée avec le code ' . $session['code'] . '.';
            } elseif ($action === 'start') {
                $session = set_game_session_status($code, 'running');
                write_event('session_started', [], (string) $session['code']);
                $flash = 'La session ' . $session['code'] . ' est lancée.';
            } elseif ($action === 'pause') {
                $session = set_game_session_status($code, 'waiting');
                write_event('session_waiting', [], (string) $session['code']);
                $flash = 'La session ' . $session['code'] . ' est remise en attente.';
            } elseif ($action === 'end') {
                $session = set_game_session_status($code, 'ended');
                write_event('session_ended', [], (string) $session['code']);
                $flash = 'La session ' . $session['code'] . ' est terminée.';
            } elseif ($action === 'reset') {
                $session = reset_game_session($code);
                write_event('session_reset', [], (string) $session['code']);
                $flash = 'La session ' . $session['code'] . ' a été réinitialisée.';
            } elseif ($action === 'delete') {
                write_event('session_deleted', [], $code);
                delete_game_session($code);
                $flash = 'La session a été supprimée.';
            } elseif ($action === 'archive_ended') {
                $count = archive_finished_sessions(0);
                write_event('sessions_archived', ['count' => $count]);
                $flash = $count . ' session(s) terminée(s) archivée(s).';
            } else {
                throw new RuntimeException('Action session inconnue.');
            }
        } catch (Throwable $exception) {
            $flash = $exception->getMessage();
            $flashType = 'error';
        }
    }
}

if (admin_is_authenticated() && $requestMethod === 'POST' && ($_POST['form_type'] ?? '') === 'maintenance') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $flash = 'Le jeton de sécurité est invalide. Recharge la page puis recommence.';
        $flashType = 'error';
    } else {
        try {
            $backupCount = prune_game_backups(20);
            $logCount = prune_event_logs(30);
            write_event('maintenance_cleaned', ['backupsDeleted' => $backupCount, 'logsDeleted' => $logCount]);
            $flash = 'Maintenance effectuée : ' . $backupCount . ' sauvegarde(s) ancienne(s) et ' . $logCount . ' journal(aux) ancien(s) supprimé(s).';
        } catch (Throwable $exception) {
            $flash = $exception->getMessage();
            $flashType = 'error';
        }
    }
}

$game = load_game_data();
$sessions = list_game_sessions();
$recentEvents = read_recent_events(40);

if (!admin_is_authenticated()):
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — Jeu création d'entreprise</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
  </head>
  <body>
    <main class="admin-shell">
      <section class="admin-login">
        <h1>Connexion admin</h1>
        <p>Connecte-toi pour modifier l’accueil, les étapes, les cartes, les sessions et l’identité du jeu.</p>
        <?php if ($loginError): ?>
          <div class="flash error"><?= h($loginError) ?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="form_type" value="login">
          <div class="field">
            <label for="username">Identifiant</label>
            <input id="username" name="username" type="text" autocomplete="username" required>
          </div>
          <div class="field">
            <label for="password">Mot de passe</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
          </div>
          <button class="button primary" type="submit">Se connecter</button>
        </form>
        <p class="helper">Identifiant par défaut : <strong>admin</strong>. Change le mot de passe depuis l’admin avant un usage réel.</p>
      </section>
    </main>
    <script>
      const stepsList = document.querySelector("#stepsList");
      const cardsByStep = document.querySelector("#cardsByStep");

      function nextIndex(selector) {
        let max = -1;
        document.querySelectorAll(selector).forEach((field) => {
          const match = field.name.match(/\[(\d+)\]/);
          if (match) max = Math.max(max, Number(match[1]));
        });
        return max + 1;
      }

      function maxInputValue(selector) {
        let max = 0;
        document.querySelectorAll(selector).forEach((field) => {
          max = Math.max(max, Number(field.value || 0));
        });
        return max;
      }

      function bindRemoveButtons(root = document) {
        root.querySelectorAll("[data-remove-row]").forEach((button) => {
          if (button.dataset.bound === "1") return;
          button.dataset.bound = "1";
          button.addEventListener("click", () => {
            const row = button.closest("[data-step-editor], [data-card-editor]");
            if (!row) return;
            const deleteInput = document.createElement("input");
            deleteInput.type = "hidden";
            const firstNamedInput = row.querySelector("[name]");
            if (firstNamedInput) {
              deleteInput.name = firstNamedInput.name.replace(/\[[^\]]+\]$/, "[_delete]");
              deleteInput.value = "1";
              row.appendChild(deleteInput);
            }
            if (row.matches("[data-step-editor]")) {
              const stepId = row.dataset.stepId;
              document.querySelector(`[data-card-step-group="${CSS.escape(stepId)}"]`)?.remove();
            }
            row.remove();
            refreshCardStepSelects();
          });
        });
      }

      function updateCardGroupFromStep(row) {
        const idInput = row.querySelector('input[name*="[id]"]');
        const shortInput = row.querySelector('input[name*="[short]"]');
        if (!idInput) return;
        const previous = row.dataset.stepId;
        const next = idInput.value || previous;
        row.dataset.stepId = next;
        const group = document.querySelector(`[data-card-step-group="${CSS.escape(previous)}"]`);
        if (!group) return;
        group.dataset.cardStepGroup = next;
        group.querySelectorAll('[name*="[stepId]"]').forEach((input) => {
          input.value = next;
        });
        const title = group.querySelector(".step-card-editor-head strong");
        if (title) title.textContent = `${next}. ${shortInput?.value || "Nouvelle étape"}`;
        const addButton = group.querySelector("[data-add-card-to-step]");
        if (addButton) addButton.dataset.addCardToStep = next;
        refreshCardStepSelects();
      }

      function stepOptionsHtml(selected = "") {
        return [...document.querySelectorAll("[data-step-editor]")].map((row) => {
          const id = row.querySelector('input[name*="[id]"]')?.value || "";
          const label = row.querySelector('input[name*="[short]"]')?.value || row.querySelector('input[name*="[title]"]')?.value || "Étape";
          return `<option value="${id}" ${String(id) === String(selected) ? "selected" : ""}>${id} · ${label}</option>`;
        }).join("");
      }

      function refreshCardStepSelects() {
        document.querySelectorAll('select[name^="game[cards]"][name$="[stepId]"]').forEach((select) => {
          const selected = select.value;
          select.innerHTML = stepOptionsHtml(selected);
        });
      }

      function bindStepRows(root = document) {
        root.querySelectorAll("[data-step-editor]").forEach((row) => {
          if (row.dataset.stepBound === "1") return;
          row.dataset.stepBound = "1";
          row.querySelector('input[name*="[id]"]')?.addEventListener("change", () => updateCardGroupFromStep(row));
          row.querySelector('input[name*="[short]"]')?.addEventListener("input", () => updateCardGroupFromStep(row));
        });
      }

      function addCard(stepId, title = "", desc = "") {
        const index = nextIndex('input[name^="game[cards]"][name$="[id]"]');
        const cardId = maxInputValue('input[name^="game[cards]"][name$="[id]"]') + 1;
        const group = document.querySelector(`[data-card-step-group="${CSS.escape(String(stepId))}"]`);
        if (!group) return;
        group.querySelector(".session-admin-empty")?.remove();
        const row = document.createElement("div");
        row.className = "admin-row card-row";
        row.dataset.cardEditor = "1";
        row.innerHTML = `
          <div class="field">
            <label>Numéro carte</label>
            <input type="number" name="game[cards][${index}][id]" value="${cardId}">
          </div>
          <div class="field">
            <label>Étape associée</label>
            <select name="game[cards][${index}][stepId]">${stepOptionsHtml(stepId)}</select>
          </div>
          <div class="field">
            <label>Titre</label>
            <input type="text" name="game[cards][${index}][title]" value="${title}">
          </div>
          <div class="field">
            <label>Description / explication</label>
            <textarea name="game[cards][${index}][desc]">${desc}</textarea>
          </div>
          <div class="field row-action-field">
            <label>Action</label>
            <button class="button danger" type="button" data-remove-row>Supprimer</button>
          </div>
        `;
        group.querySelector(".card-step-list").appendChild(row);
        bindRemoveButtons(row);
      }

      document.querySelector("[data-add-step]")?.addEventListener("click", () => {
        const index = nextIndex('input[name^="game[steps]"][name$="[id]"]');
        const stepId = maxInputValue('input[name^="game[steps]"][name$="[id]"]') + 1;
        const color = ["#4545A4", "#0EA5E9", "#10B981", "#F59E0B", "#EF4444", "#7C3AED", "#14B8A6", "#F97316"][index % 8];
        const row = document.createElement("div");
        row.className = "admin-row step-row";
        row.dataset.stepEditor = "1";
        row.dataset.stepId = String(stepId);
        row.innerHTML = `
          <div class="field">
            <label>Numéro</label>
            <input type="number" name="game[steps][${index}][id]" value="${stepId}">
          </div>
          <div class="field">
            <label>Nom complet</label>
            <input type="text" name="game[steps][${index}][title]" value="Nouvelle étape">
          </div>
          <div class="field">
            <label>Nom court</label>
            <input type="text" name="game[steps][${index}][short]" value="Étape ${stepId}">
          </div>
          <div class="field">
            <label>Couleur</label>
            <input type="color" name="game[steps][${index}][color]" value="${color}">
          </div>
          <div class="field row-action-field">
            <label>Action</label>
            <button class="button danger" type="button" data-remove-row>Supprimer</button>
          </div>
        `;
        stepsList.appendChild(row);

        const group = document.createElement("article");
        group.className = "step-card-editor";
        group.dataset.cardStepGroup = String(stepId);
        group.innerHTML = `
          <div class="step-card-editor-head">
            <div>
              <strong>${stepId}. Étape ${stepId}</strong>
              <span>0 carte</span>
            </div>
            <button class="button secondary" type="button" data-add-card-to-step="${stepId}">Ajouter une carte</button>
          </div>
          <div class="admin-list card-step-list">
            <div class="session-admin-empty">Aucune carte dans cette étape.</div>
          </div>
        `;
        cardsByStep.appendChild(group);
        bindRemoveButtons(row);
        bindStepRows(row);
        bindAddCardButtons(group);
        refreshCardStepSelects();
      });

      function bindAddCardButtons(root = document) {
        root.querySelectorAll("[data-add-card-to-step]").forEach((button) => {
          if (button.dataset.bound === "1") return;
          button.dataset.bound = "1";
          button.addEventListener("click", () => addCard(button.dataset.addCardToStep));
        });
      }

      bindRemoveButtons();
      bindStepRows();
      bindAddCardButtons();
    </script>
  </body>
</html>
<?php
exit;
endif;
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — Jeu création d'entreprise</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
  </head>
  <body>
    <main class="admin-shell">
      <header class="admin-topbar">
        <div>
          <h1>Admin du jeu pédagogique</h1>
          <p>Gère le contenu du jeu PHP depuis une seule source de vérité, stockée dans <code>data/game.json</code>.</p>
        </div>
        <div class="admin-actions">
          <a class="link-button" href="index.php">Voir l'app</a>
          <a class="link-button" href="logout.php">Se déconnecter</a>
        </div>
      </header>

      <?php if ($flash): ?>
        <div class="flash <?= h($flashType) ?>"><?= h($flash) ?></div>
      <?php endif; ?>
      <?php if (admin_uses_default_password()): ?>
        <div class="flash error">Sécurité : le compte admin utilise encore le mot de passe par défaut. Change-le avec le panneau “Sécurité admin” avant un usage réel.</div>
      <?php endif; ?>

      <nav class="admin-tabs" aria-label="Sections de l’administration">
        <button type="button" data-admin-tab="security">Sécurité</button>
        <button type="button" data-admin-tab="sessions">Mode classe</button>
        <button type="button" data-admin-tab="design">Marque blanche</button>
        <button type="button" data-admin-tab="content">Contenu du jeu</button>
        <button type="button" data-admin-tab="exports">Exports</button>
        <button type="button" data-admin-tab="journal">Journal</button>
      </nav>

      <section class="admin-card security-card" data-admin-panel="security">
        <div class="admin-card-head">
          <div>
            <h2>Sécurité admin</h2>
            <p>Change le mot de passe et garde une trace des actions sensibles de l’espace enseignant.</p>
          </div>
          <a class="link-button" href="export_all_data.php">Exporter toutes les données</a>
        </div>
        <form method="post" class="security-form">
          <input type="hidden" name="form_type" value="security">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="field">
            <label for="currentPassword">Mot de passe actuel</label>
            <input id="currentPassword" type="password" name="current_password" autocomplete="current-password" required>
          </div>
          <div class="field">
            <label for="newPassword">Nouveau mot de passe</label>
            <input id="newPassword" type="password" name="new_password" autocomplete="new-password" minlength="10" required>
          </div>
          <div class="field">
            <label for="confirmPassword">Confirmer</label>
            <input id="confirmPassword" type="password" name="confirm_password" autocomplete="new-password" minlength="10" required>
          </div>
          <button class="button secondary" type="submit">Changer le mot de passe</button>
        </form>
      </section>

      <section class="admin-card session-admin-card" data-admin-panel="sessions">
        <div class="admin-card-head">
          <div>
            <h2>Sessions classe</h2>
            <p>Crée un code, projette le QR, lance tout le monde en même temps, et suis l’avancement en direct — en chaos ou en calme.</p>
          </div>
        </div>
        <form method="post" class="session-maintenance-form">
          <input type="hidden" name="form_type" value="session">
          <input type="hidden" name="session_action" value="archive_ended">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <button class="button secondary" type="submit">Archiver les sessions terminées</button>
        </form>

        <form method="post" class="session-create-form">
          <input type="hidden" name="form_type" value="session">
          <input type="hidden" name="session_action" value="create">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="field">
            <label for="sessionTitle">Nom de la session</label>
            <input id="sessionTitle" type="text" name="session_title" placeholder="Ex : Terminale STMG - groupe A">
          </div>
          <fieldset class="ambiance-choice">
            <legend>Ambiance de la séance</legend>
            <label>
              <input type="radio" name="session_ambiance" value="chaos" <?= (($game['settings']['classroomAmbiance'] ?? 'chaos') !== 'calme') ? 'checked' : '' ?>>
              <strong>Chaos</strong>
              <span>Tout le monde joue en même temps. Prénoms au tableau, classement possible. L’enseignant voit l’avancement en direct.</span>
            </label>
            <label>
              <input type="radio" name="session_ambiance" value="calme" <?= (($game['settings']['classroomAmbiance'] ?? 'chaos') === 'calme') ? 'checked' : '' ?>>
              <strong>Calme</strong>
              <span>Même jeu, sans classement public ni prénoms projetés. Pensé pour les élèves sensibles. Toi seul vois qui bloque.</span>
            </label>
          </fieldset>
          <button class="button primary" type="submit">Créer une session</button>
        </form>

        <div class="session-admin-list">
          <?php if (empty($sessions)): ?>
            <div class="session-admin-empty">Aucune session pour le moment.</div>
          <?php endif; ?>
          <?php foreach ($sessions as $session): ?>
            <?php
              $joinUrl = 'session.php?code=' . urlencode((string) $session['code']);
              $leaderboardUrl = 'leaderboard.php?code=' . urlencode((string) $session['code']);
              $pilotUrl = 'admin_session.php?code=' . urlencode((string) $session['code']);
              $projectorUrl = 'session_projector.php?code=' . urlencode((string) $session['code']);
              $rankingProjectorUrl = 'session_ranking_projector.php?code=' . urlencode((string) $session['code']);
              $debriefUrl = 'session_debrief.php?code=' . urlencode((string) $session['code']);
              $exportUrl = 'export_session_csv.php?code=' . urlencode((string) $session['code']);
              $leaderboard = session_leaderboard($session);
              $statusLabels = [
                  'waiting' => 'En attente',
                  'running' => 'En cours',
                  'ended' => 'Terminée',
              ];
            ?>
            <article class="session-admin-row">
              <div>
                <div class="session-admin-code"><?= h((string) $session['code']) ?></div>
                <h3><?= h((string) $session['title']) ?></h3>
                <p><?= h($statusLabels[$session['status']] ?? 'Session') ?> · <?= session_ambiance($session) === 'calme' ? 'Calme' : 'Chaos' ?> · PIN prof <?= h((string) ($session['teacherPin'] ?? '')) ?> · <?= count($session['participants']) ?> inscrit<?= count($session['participants']) > 1 ? 's' : '' ?> · <?= count($leaderboard) ?> résultat<?= count($leaderboard) > 1 ? 's' : '' ?></p>
                <div class="session-links">
                  <a href="<?= h($pilotUrl) ?>">Pilotage enseignant</a>
                  <a href="<?= h($projectorUrl) ?>">Projection code</a>
                  <a href="<?= h($rankingProjectorUrl) ?>">Projection classement</a>
                  <a href="<?= h($joinUrl) ?>">Connexion élèves</a>
                  <a href="<?= h($leaderboardUrl) ?>">Classement live</a>
                  <a href="<?= h($debriefUrl) ?>">Débrief</a>
                  <a href="<?= h($exportUrl) ?>">Export CSV</a>
                </div>
              </div>
              <div class="session-admin-actions">
                <?php foreach ([['start', 'Lancer'], ['pause', 'Attente'], ['end', 'Terminer'], ['reset', 'Réinitialiser']] as $action): ?>
                  <form method="post">
                    <input type="hidden" name="form_type" value="session">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="session_code" value="<?= h((string) $session['code']) ?>">
                    <input type="hidden" name="session_action" value="<?= h($action[0]) ?>">
                    <button class="button secondary" type="submit"><?= h($action[1]) ?></button>
                  </form>
                <?php endforeach; ?>
                <form method="post">
                  <input type="hidden" name="form_type" value="session">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="session_code" value="<?= h((string) $session['code']) ?>">
                  <input type="hidden" name="session_action" value="delete">
                  <button class="button danger" type="submit">Supprimer</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <form method="post" class="admin-grid" enctype="multipart/form-data">
        <input type="hidden" name="form_type" value="content">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

        <section class="admin-card" data-admin-panel="design">
          <div class="admin-card-head">
            <div>
              <h2>Identité marque blanche</h2>
              <p>Logo, couleurs et textes permettent de réutiliser le même moteur pour plusieurs clients, écoles ou thématiques.</p>
            </div>
          </div>
          <div class="brand-studio">
            <div class="brand-preview" id="brandPreview">
              <?php if (!empty($game['brand']['logoPath'])): ?>
                <img id="brandPreviewImage" src="<?= h((string) $game['brand']['logoPath']) ?>" alt="Logo actuel">
              <?php else: ?>
                <img id="brandPreviewImage" src="" alt="Logo aperçu" hidden>
                <span id="brandPreviewEmpty">Aucun logo</span>
              <?php endif; ?>
            </div>
            <div class="brand-studio-fields">
              <div class="field">
                <label for="brandLogo">Logo de la marque</label>
                <input id="brandLogo" type="file" name="brand_logo" accept="image/png,image/jpeg,image/webp,image/gif">
                <input type="hidden" name="game[brand][logoPath]" value="<?= h((string) ($game['brand']['logoPath'] ?? '')) ?>">
                <p class="field-help">PNG, JPEG, WebP ou GIF. Le logo et les couleurs se prévisualisent dès que tu choisis le fichier, avant enregistrement.</p>
              </div>
              <div id="palettePreview" class="palette-strip" aria-label="Palette extraite">
                <?php foreach (($game['brand']['palette'] ?? []) as $index => $color): ?>
                  <label class="palette-swatch" style="--swatch: <?= h((string) $color) ?>">
                    <span><?= h((string) $color) ?></span>
                    <input type="hidden" name="game[brand][palette][<?= $index ?>]" value="<?= h((string) $color) ?>">
                  </label>
                <?php endforeach; ?>
                <?php if (empty($game['brand']['palette'])): ?>
                  <span class="field-help">La palette apparaîtra ici après l’envoi d’un logo.</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="admin-row brand-row">
            <div class="field">
              <label for="brandName">Nom affiché dans l'app</label>
              <input id="brandName" type="text" name="game[ui][brandName]" value="<?= h((string) ($game['ui']['brandName'] ?? 'Creation d\'entreprise')) ?>">
            </div>
            <div class="field">
              <label for="documentTitle">Titre de l'onglet</label>
              <input id="documentTitle" type="text" name="game[ui][documentTitle]" value="<?= h((string) ($game['ui']['documentTitle'] ?? 'Les 6 etapes de la creation d\'entreprise')) ?>">
            </div>
            <div class="field">
              <label for="appName">Nom interne</label>
              <input id="appName" type="text" name="game[ui][appName]" value="<?= h((string) ($game['ui']['appName'] ?? 'Les 6 etapes')) ?>">
            </div>
            <div class="field">
              <label for="decorations">Éléments décoratifs</label>
              <input id="decorations" type="text" name="game[brand][decorations]" value="<?= h((string) ($game['brand']['decorations'] ?? '💡 🔎 💶 🤝 ⚖️ 🚀')) ?>">
            </div>
          </div>
          <div class="admin-row design-row">
            <div class="field">
              <label>Décorations</label>
              <label class="toggle">
                <input type="checkbox" name="game[brand][showDecorations]" value="1" <?= !empty($game['brand']['showDecorations']) ? 'checked' : '' ?>>
                <span>Afficher les éléments décoratifs sur l’accueil</span>
              </label>
            </div>
            <div class="field">
              <label for="decorationIntensity">Intensité</label>
              <select id="decorationIntensity" name="game[brand][decorationIntensity]">
                <?php foreach (['discret' => 'Discret', 'normal' => 'Normal', 'fort' => 'Fort'] as $value => $label): ?>
                  <option value="<?= h($value) ?>" <?= (($game['brand']['decorationIntensity'] ?? 'normal') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="visualStyle">Style visuel</label>
              <select id="visualStyle" name="game[brand][visualStyle]">
                <?php foreach (['classe' => 'Classe', 'sobre' => 'Sobre', 'fun' => 'Fun'] as $value => $label): ?>
                  <option value="<?= h($value) ?>" <?= (($game['brand']['visualStyle'] ?? 'classe') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="admin-row popup-row">
            <div class="field">
              <label for="homeTitle">Titre de l’écran d’accueil</label>
              <input id="homeTitle" type="text" name="game[ui][homeTitle]" value="<?= h((string) ($game['ui']['homeTitle'] ?? 'Avant de commencer')) ?>">
            </div>
            <div class="field">
              <label for="homeButton">Bouton de l’écran d’accueil</label>
              <input id="homeButton" type="text" name="game[ui][homeButton]" value="<?= h((string) ($game['ui']['homeButton'] ?? 'Commencer')) ?>">
            </div>
            <div class="field popup-body-field">
              <label for="homeBody">Message d’accueil</label>
              <textarea id="homeBody" name="game[ui][homeBody]"><?= h((string) ($game['ui']['homeBody'] ?? 'Lis chaque carte, repère l’action principale, puis associe-la à l’étape logique du parcours.')) ?></textarea>
            </div>
          </div>
          <div class="preview-actions">
            <a class="link-button" href="index.php" target="_blank" rel="noreferrer">Prévisualiser l’accueil</a>
            <a class="link-button" href="print.php" target="_blank" rel="noreferrer">Prévisualiser la version papier</a>
          </div>
          <div class="admin-row theme-row">
            <?php
              $themeFields = [
                  'primary' => 'Couleur principale',
                  'secondary' => 'Couleur secondaire',
                  'accent' => 'Accent',
                  'background' => 'Fond',
                  'surface' => 'Surface',
                  'text' => 'Texte',
                  'muted' => 'Texte discret',
              ];
            ?>
            <?php foreach ($themeFields as $field => $label): ?>
              <div class="field color-field">
                <label for="theme_<?= h($field) ?>"><?= h($label) ?></label>
                <input id="theme_<?= h($field) ?>" type="color" name="game[theme][<?= h($field) ?>]" value="<?= h((string) ($game['theme'][$field] ?? '#1D5BD4')) ?>">
              </div>
            <?php endforeach; ?>
          </div>
          <div class="admin-row brand-copy-row">
            <?php
              $copyFields = [
                  'hintDefault' => 'Texte d’aide par défaut',
                  'hintButton' => 'Bouton indice',
                  'skipButton' => 'Bouton passer',
                  'resetButton' => 'Bouton recommencer',
                  'completionAdminLabel' => 'Lien admin',
                  'sneeTitle' => 'Titre SNEE (fin de partie)',
                  'sneeCta' => 'Bouton SNEE',
                  'sneeUrl' => 'Lien SNEE',
                  'sneeBody' => 'Texte SNEE',
              ];
            ?>
            <?php foreach ($copyFields as $field => $label): ?>
              <div class="field">
                <label for="ui_<?= h($field) ?>"><?= h($label) ?></label>
                <input id="ui_<?= h($field) ?>" type="text" name="game[ui][<?= h($field) ?>]" value="<?= h((string) ($game['ui'][$field] ?? '')) ?>">
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="admin-card" data-admin-panel="content">
          <div class="admin-card-head">
            <div>
              <h2>Réglages globaux</h2>
              <p>Ces réglages alimentent directement le front PHP.</p>
            </div>
          </div>
          <div class="admin-row settings-row">
            <div class="field">
              <label for="layout">Disposition</label>
              <select id="layout" name="game[settings][layout]">
                <?php foreach (['Grille', 'Colonnes', 'Liste'] as $option): ?>
                  <option value="<?= h($option) ?>" <?= (($game['settings']['layout'] ?? '') === $option) ? 'selected' : '' ?>><?= h($option) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="accent">Couleur principale</label>
              <input id="accent" type="color" name="game[settings][accentColor]" value="<?= h((string) ($game['settings']['accentColor'] ?? '#1D5BD4')) ?>">
            </div>
            <div class="field">
              <label>Chronomètre</label>
              <label class="toggle">
                <input type="checkbox" name="game[settings][showTimer]" value="1" <?= !empty($game['settings']['showTimer']) ? 'checked' : '' ?>>
                <span>Afficher le temps pendant la partie</span>
              </label>
            </div>
            <div class="field">
              <label>Indices</label>
              <label class="toggle">
                <input type="checkbox" name="game[settings][showHints]" value="1" <?= !empty($game['settings']['showHints']) ? 'checked' : '' ?>>
                <span>Autoriser le bouton indice et les corrections explicites</span>
              </label>
            </div>
            <div class="field">
              <label>Ordre des étapes</label>
              <label class="toggle">
                <input type="checkbox" name="game[settings][requireOrderFirst]" value="1" <?= !empty($game['settings']['requireOrderFirst']) ? 'checked' : '' ?>>
                <span>Obliger les élèves à retrouver l’ordre avant le classement des cartes</span>
              </label>
            </div>
            <div class="field">
              <label>Accueil élèves</label>
              <label class="toggle">
                <input type="checkbox" name="game[settings][showSessionJoin]" value="1" <?= !empty($game['settings']['showSessionJoin']) ? 'checked' : '' ?>>
                <span>Afficher “Rejoindre une session” sur la première page</span>
              </label>
            </div>
            <div class="field">
              <label>Classement live</label>
              <label class="toggle">
                <input type="checkbox" name="game[settings][showLiveLeaderboard]" value="1" <?= !empty($game['settings']['showLiveLeaderboard']) ? 'checked' : '' ?>>
                <span>Afficher le classement public pendant une séance Chaos (jamais en Calme)</span>
              </label>
            </div>
            <div class="field">
              <label for="classroomAmbiance">Ambiance par défaut</label>
              <select id="classroomAmbiance" name="game[settings][classroomAmbiance]">
                <option value="chaos" <?= (($game['settings']['classroomAmbiance'] ?? 'chaos') !== 'calme') ? 'selected' : '' ?>>Chaos — tout le monde en même temps</option>
                <option value="calme" <?= (($game['settings']['classroomAmbiance'] ?? 'chaos') === 'calme') ? 'selected' : '' ?>>Calme — sans classement public</option>
              </select>
            </div>
            <div class="field">
              <label>Cas pratiques</label>
              <label class="toggle">
                <input type="checkbox" name="game[settings][enableCaseRound]" value="1" <?= !empty($game['settings']['enableCaseRound']) ? 'checked' : '' ?>>
                <span>Ajouter un round de situations concrètes après le classement</span>
              </label>
            </div>
            <div class="field">
              <label>Badges</label>
              <label class="toggle">
                <input type="checkbox" name="game[settings][enableBadges]" value="1" <?= !empty($game['settings']['enableBadges']) ? 'checked' : '' ?>>
                <span>Afficher les compétences maîtrisées au bilan final</span>
              </label>
            </div>
            <div class="field">
              <label>Cartes pièges</label>
              <label class="toggle">
                <input type="checkbox" name="game[settings][enableTrapCards]" value="1" <?= !empty($game['settings']['enableTrapCards']) ? 'checked' : '' ?>>
                <span>Activer les cartes ambiguës et bonus de raisonnement</span>
              </label>
            </div>
            <div class="field">
              <label for="difficultyMode">Difficulté</label>
              <select id="difficultyMode" name="game[settings][difficultyMode]">
                <?php foreach (['progressive' => 'Progressive', 'discovery' => 'Découverte', 'challenge' => 'Challenge', 'expert' => 'Expert'] as $value => $label): ?>
                  <option value="<?= h($value) ?>" <?= (($game['settings']['difficultyMode'] ?? 'progressive') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </section>

        <section class="admin-card" data-admin-panel="content">
          <div class="admin-card-head">
            <div>
              <h2>Étapes du parcours</h2>
              <p>Chaque ligne correspond à une étape du jeu. L’ordre affiché ici est l’ordre attendu par les élèves.</p>
            </div>
            <button class="button secondary" type="button" data-add-step>Ajouter une étape</button>
          </div>
          <div id="stepsList" class="admin-list">
            <?php foreach (($game['steps'] ?? []) as $index => $step): ?>
              <div class="admin-row step-row" data-step-editor data-step-id="<?= h((string) $step['id']) ?>">
                <div class="field">
                  <label>Numéro</label>
                  <input type="number" name="game[steps][<?= $index ?>][id]" value="<?= h((string) $step['id']) ?>">
                </div>
                <div class="field">
                  <label>Nom complet</label>
                  <input type="text" name="game[steps][<?= $index ?>][title]" value="<?= h((string) $step['title']) ?>">
                </div>
                <div class="field">
                  <label>Nom court</label>
                  <input type="text" name="game[steps][<?= $index ?>][short]" value="<?= h((string) $step['short']) ?>">
                </div>
                <div class="field">
                  <label>Couleur</label>
                  <input type="color" name="game[steps][<?= $index ?>][color]" value="<?= h((string) $step['color']) ?>">
                </div>
                <div class="field row-action-field">
                  <label>Action</label>
                  <button class="button danger" type="button" data-remove-row>Supprimer</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="admin-card" data-admin-panel="content">
          <div class="admin-card-head">
            <div>
              <h2>Cartes par étape</h2>
              <p>Ajoute autant de cartes que nécessaire dans chaque étape. Le jeu utilisera automatiquement ce contenu.</p>
            </div>
          </div>
          <?php
            $cardsByStep = [];
            foreach (($game['steps'] ?? []) as $step) {
                $cardsByStep[(int) $step['id']] = [];
            }
            foreach (($game['cards'] ?? []) as $cardIndex => $card) {
                $cardsByStep[(int) ($card['stepId'] ?? 0)][] = [$cardIndex, $card];
            }
          ?>
          <div id="cardsByStep" class="cards-by-step">
            <?php foreach (($game['steps'] ?? []) as $step): ?>
              <?php $stepId = (int) $step['id']; ?>
              <article class="step-card-editor" data-card-step-group="<?= h((string) $stepId) ?>">
                <div class="step-card-editor-head">
                  <div>
                    <strong><?= h((string) $step['id']) ?>. <?= h((string) $step['short']) ?></strong>
                    <span><?= count($cardsByStep[$stepId] ?? []) ?> carte<?= count($cardsByStep[$stepId] ?? []) > 1 ? 's' : '' ?></span>
                  </div>
                  <button class="button secondary" type="button" data-add-card-to-step="<?= h((string) $stepId) ?>">Ajouter une carte</button>
                </div>
                <div class="admin-list card-step-list">
                  <?php foreach (($cardsByStep[$stepId] ?? []) as [$index, $card]): ?>
                    <div class="admin-row card-row" data-card-editor>
                      <div class="field">
                        <label>Numéro carte</label>
                        <input type="number" name="game[cards][<?= $index ?>][id]" value="<?= h((string) $card['id']) ?>">
                      </div>
                      <div class="field">
                        <label>Étape associée</label>
                        <select name="game[cards][<?= $index ?>][stepId]">
                          <?php foreach (($game['steps'] ?? []) as $candidateStep): ?>
                            <option value="<?= h((string) $candidateStep['id']) ?>" <?= ((int) $candidateStep['id'] === $stepId) ? 'selected' : '' ?>>
                              <?= h((string) $candidateStep['id']) ?> · <?= h((string) $candidateStep['short']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="field">
                        <label>Titre</label>
                        <input type="text" name="game[cards][<?= $index ?>][title]" value="<?= h((string) $card['title']) ?>">
                      </div>
                      <div class="field">
                        <label>Description / explication</label>
                        <textarea name="game[cards][<?= $index ?>][desc]"><?= h((string) $card['desc']) ?></textarea>
                      </div>
                      <div class="field">
                        <label>Type de carte</label>
                        <select name="game[cards][<?= $index ?>][type]">
                          <?php foreach (['simple' => 'Simple', 'trap' => 'Piège', 'bonus' => 'Bonus', 'case' => 'Cas pratique'] as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= (($card['type'] ?? 'simple') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="field">
                        <label>Difficulté</label>
                        <select name="game[cards][<?= $index ?>][difficulty]">
                          <?php foreach (['easy' => 'Facile', 'normal' => 'Normal', 'expert' => 'Expert'] as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= (($card['difficulty'] ?? 'normal') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="field">
                        <label>Compétence</label>
                        <select name="game[cards][<?= $index ?>][competency]">
                          <?php foreach (['general' => 'Général', 'idea' => 'Idée', 'market' => 'Marché', 'finance' => 'Finance', 'funding' => 'Financement', 'legal' => 'Juridique', 'formalities' => 'Formalités'] as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= (($card['competency'] ?? 'general') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="field">
                        <label>Feedback de réussite</label>
                        <textarea name="game[cards][<?= $index ?>][explanation]"><?= h((string) ($card['explanation'] ?? ($card['desc'] ?? ''))) ?></textarea>
                      </div>
                      <div class="field">
                        <label>Feedback d’erreur</label>
                        <textarea name="game[cards][<?= $index ?>][errorExplanation]"><?= h((string) ($card['errorExplanation'] ?? '')) ?></textarea>
                      </div>
                      <div class="field">
                        <label>Situation / cas pratique</label>
                        <textarea name="game[cards][<?= $index ?>][casePrompt]"><?= h((string) ($card['casePrompt'] ?? '')) ?></textarea>
                      </div>
                      <div class="field row-action-field">
                        <label>Action</label>
                        <button class="button danger" type="button" data-remove-row>Supprimer</button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                  <?php if (empty($cardsByStep[$stepId])): ?>
                    <div class="session-admin-empty">Aucune carte dans cette étape.</div>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="admin-card" data-admin-panel="design content">
          <div class="admin-actions">
            <button class="button primary" type="submit">Enregistrer</button>
            <a class="link-button" href="index.php">Retour au jeu</a>
            <a class="link-button" href="print.php">Version papier A4</a>
            <a class="link-button" href="correction.php">Corrigé imprimable</a>
            <a class="link-button" href="print.php" target="_blank" rel="noreferrer">Imprimer / exporter PDF</a>
          </div>
        </section>
      </form>

      <section class="admin-card export-admin-card" data-admin-panel="exports">
        <div class="admin-card-head">
          <div>
            <h2>Exports pédagogiques</h2>
            <p>Tout ce qu’il faut pour distribuer, imprimer, corriger et garder une copie exploitable du jeu.</p>
          </div>
        </div>
        <div class="export-grid">
          <a class="export-tile" href="print.php" target="_blank" rel="noreferrer">
            <strong>Version papier A4</strong>
            <span>Fiche visuelle identique au jeu digital, prête à imprimer.</span>
          </a>
          <a class="export-tile" href="correction.php" target="_blank" rel="noreferrer">
            <strong>Corrigé enseignant</strong>
            <span>Correction imprimable avec les étapes et sous-étapes attendues.</span>
          </a>
          <a class="export-tile" href="export_all_data.php">
            <strong>Sauvegarde JSON</strong>
            <span>Export complet du contenu, des sessions et des réglages.</span>
          </a>
          <a class="export-tile" href="export_events_csv.php">
            <strong>Journal CSV</strong>
            <span>Traçabilité des connexions, sessions, résultats et exports.</span>
          </a>
        </div>
      </section>

      <section class="admin-card audit-card" data-admin-panel="journal">
        <div class="admin-card-head">
          <div>
            <h2>Journal de traçabilité</h2>
            <p>Derniers événements importants : connexions, sessions, résultats, exports et modifications.</p>
          </div>
          <a class="link-button" href="export_events_csv.php">Exporter le journal</a>
        </div>
        <form method="post" class="maintenance-form">
          <input type="hidden" name="form_type" value="maintenance">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <button class="button secondary" type="submit">Nettoyer les anciennes sauvegardes et journaux</button>
          <p class="field-help">Conserve les 20 dernières sauvegardes du jeu et 30 jours de journaux.</p>
        </form>
        <div class="audit-list">
          <?php foreach ($recentEvents as $event): ?>
            <article class="audit-row">
              <strong><?= h((string) ($event['type'] ?? 'event')) ?></strong>
              <span><?= h((string) ($event['at'] ?? '')) ?></span>
              <span><?= h((string) ($event['actor'] ?? 'system')) ?></span>
              <span><?= h((string) ($event['sessionCode'] ?? '')) ?></span>
            </article>
          <?php endforeach; ?>
          <?php if (empty($recentEvents)): ?>
            <div class="session-admin-empty">Aucun événement pour le moment.</div>
          <?php endif; ?>
        </div>
      </section>
    </main>
    <script>
      (() => {
        const tabs = [...document.querySelectorAll("[data-admin-tab]")];
        const panels = [...document.querySelectorAll("[data-admin-panel]")];
        if (!tabs.length || !panels.length) return;

        const validTabs = tabs.map((tab) => tab.dataset.adminTab);
        const preferred = window.location.hash.replace("#", "") || localStorage.getItem("gameAdminTab") || "sessions";
        const defaultTab = validTabs.includes(preferred) ? preferred : "sessions";

        function activate(tabName) {
          tabs.forEach((tab) => {
            const isActive = tab.dataset.adminTab === tabName;
            tab.classList.toggle("is-active", isActive);
            tab.setAttribute("aria-selected", isActive ? "true" : "false");
          });
          panels.forEach((panel) => {
            const allowedTabs = (panel.dataset.adminPanel || "").split(/\s+/).filter(Boolean);
            panel.hidden = !allowedTabs.includes(tabName);
          });
          localStorage.setItem("gameAdminTab", tabName);
          history.replaceState(null, "", `#${tabName}`);
        }

        tabs.forEach((tab) => {
          tab.addEventListener("click", () => activate(tab.dataset.adminTab));
        });
        activate(defaultTab);
      })();

      (() => {
        const stepsList = document.querySelector("#stepsList");
        const cardsByStep = document.querySelector("#cardsByStep");
        if (!stepsList || !cardsByStep) return;

        function escapeHtml(value) {
          const template = document.createElement("template");
          template.textContent = String(value ?? "");
          return template.innerHTML;
        }

        function nextIndex(selector) {
          let max = -1;
          document.querySelectorAll(selector).forEach((field) => {
            const match = field.name.match(/\[(\d+)\]/);
            if (match) max = Math.max(max, Number(match[1]));
          });
          return max + 1;
        }

        function maxInputValue(selector) {
          let max = 0;
          document.querySelectorAll(selector).forEach((field) => {
            max = Math.max(max, Number(field.value || 0));
          });
          return max;
        }

        function stepOptionsHtml(selected = "") {
          return [...document.querySelectorAll("[data-step-editor]")].map((row) => {
            const id = row.querySelector('input[name*="[id]"]')?.value || "";
            const label = row.querySelector('input[name*="[short]"]')?.value || row.querySelector('input[name*="[title]"]')?.value || "Étape";
            return `<option value="${escapeHtml(id)}" ${String(id) === String(selected) ? "selected" : ""}>${escapeHtml(id)} · ${escapeHtml(label)}</option>`;
          }).join("");
        }

        function refreshCardStepSelects() {
          document.querySelectorAll('select[name^="game[cards]"][name$="[stepId]"]').forEach((select) => {
            const selected = select.value;
            select.innerHTML = stepOptionsHtml(selected);
          });
        }

        function updateCardGroupFromStep(row) {
          const idInput = row.querySelector('input[name*="[id]"]');
          const shortInput = row.querySelector('input[name*="[short]"]');
          if (!idInput) return;
          const previous = row.dataset.stepId;
          const next = idInput.value || previous;
          row.dataset.stepId = next;
          const group = document.querySelector(`[data-card-step-group="${CSS.escape(previous)}"]`);
          if (!group) return;
          group.dataset.cardStepGroup = next;
          group.querySelectorAll('[name*="[stepId]"]').forEach((input) => {
            input.value = next;
          });
          const title = group.querySelector(".step-card-editor-head strong");
          if (title) title.textContent = `${next}. ${shortInput?.value || "Nouvelle étape"}`;
          const addButton = group.querySelector("[data-add-card-to-step]");
          if (addButton) addButton.dataset.addCardToStep = next;
          refreshCardStepSelects();
        }

        function bindRemoveButtons(root = document) {
          root.querySelectorAll("[data-remove-row]").forEach((button) => {
            if (button.dataset.bound === "1") return;
            button.dataset.bound = "1";
            button.addEventListener("click", () => {
              const row = button.closest("[data-step-editor], [data-card-editor]");
              if (!row) return;
              const firstNamedInput = row.querySelector("[name]");
              if (firstNamedInput) {
                const deleteInput = document.createElement("input");
                deleteInput.type = "hidden";
                deleteInput.name = firstNamedInput.name.replace(/\[[^\]]+\]$/, "[_delete]");
                deleteInput.value = "1";
                row.appendChild(deleteInput);
              }
              if (row.matches("[data-step-editor]")) {
                const stepId = row.dataset.stepId;
                document.querySelector(`[data-card-step-group="${CSS.escape(stepId)}"]`)?.remove();
              }
              row.remove();
              refreshCardStepSelects();
            });
          });
        }

        function bindStepRows(root = document) {
          root.querySelectorAll("[data-step-editor]").forEach((row) => {
            if (row.dataset.stepBound === "1") return;
            row.dataset.stepBound = "1";
            row.querySelector('input[name*="[id]"]')?.addEventListener("change", () => updateCardGroupFromStep(row));
            row.querySelector('input[name*="[short]"]')?.addEventListener("input", () => updateCardGroupFromStep(row));
          });
        }

        function addCard(stepId, title = "", desc = "") {
          const index = nextIndex('input[name^="game[cards]"][name$="[id]"]');
          const cardId = maxInputValue('input[name^="game[cards]"][name$="[id]"]') + 1;
          const group = document.querySelector(`[data-card-step-group="${CSS.escape(String(stepId))}"]`);
          if (!group) return;
          group.querySelector(".session-admin-empty")?.remove();
          const row = document.createElement("div");
          row.className = "admin-row card-row";
          row.dataset.cardEditor = "1";
          row.innerHTML = `
            <div class="field">
              <label>Numéro carte</label>
              <input type="number" name="game[cards][${index}][id]" value="${cardId}">
            </div>
            <div class="field">
              <label>Étape associée</label>
              <select name="game[cards][${index}][stepId]">${stepOptionsHtml(stepId)}</select>
            </div>
            <div class="field">
              <label>Titre</label>
              <input type="text" name="game[cards][${index}][title]" value="${escapeHtml(title)}">
            </div>
            <div class="field">
              <label>Description / explication</label>
              <textarea name="game[cards][${index}][desc]">${escapeHtml(desc)}</textarea>
            </div>
            <div class="field">
              <label>Type de carte</label>
              <select name="game[cards][${index}][type]">
                <option value="simple">Simple</option>
                <option value="trap">Piège</option>
                <option value="bonus">Bonus</option>
                <option value="case">Cas pratique</option>
              </select>
            </div>
            <div class="field">
              <label>Difficulté</label>
              <select name="game[cards][${index}][difficulty]">
                <option value="easy">Facile</option>
                <option value="normal" selected>Normal</option>
                <option value="expert">Expert</option>
              </select>
            </div>
            <div class="field">
              <label>Compétence</label>
              <select name="game[cards][${index}][competency]">
                <option value="general">Général</option>
                <option value="idea">Idée</option>
                <option value="market">Marché</option>
                <option value="finance">Finance</option>
                <option value="funding">Financement</option>
                <option value="legal">Juridique</option>
                <option value="formalities">Formalités</option>
              </select>
            </div>
            <div class="field">
              <label>Feedback de réussite</label>
              <textarea name="game[cards][${index}][explanation]">${escapeHtml(desc)}</textarea>
            </div>
            <div class="field">
              <label>Feedback d’erreur</label>
              <textarea name="game[cards][${index}][errorExplanation]"></textarea>
            </div>
            <div class="field">
              <label>Situation / cas pratique</label>
              <textarea name="game[cards][${index}][casePrompt]"></textarea>
            </div>
            <div class="field row-action-field">
              <label>Action</label>
              <button class="button danger" type="button" data-remove-row>Supprimer</button>
            </div>
          `;
          group.querySelector(".card-step-list").appendChild(row);
          bindRemoveButtons(row);
        }

        function bindAddCardButtons(root = document) {
          root.querySelectorAll("[data-add-card-to-step]").forEach((button) => {
            if (button.dataset.bound === "1") return;
            button.dataset.bound = "1";
            button.addEventListener("click", () => addCard(button.dataset.addCardToStep));
          });
        }

        document.querySelector("[data-add-step]")?.addEventListener("click", () => {
          const index = nextIndex('input[name^="game[steps]"][name$="[id]"]');
          const stepId = maxInputValue('input[name^="game[steps]"][name$="[id]"]') + 1;
          const color = ["#4545A4", "#0EA5E9", "#10B981", "#F59E0B", "#EF4444", "#7C3AED", "#14B8A6", "#F97316"][index % 8];
          const row = document.createElement("div");
          row.className = "admin-row step-row";
          row.dataset.stepEditor = "1";
          row.dataset.stepId = String(stepId);
          row.innerHTML = `
            <div class="field">
              <label>Numéro</label>
              <input type="number" name="game[steps][${index}][id]" value="${stepId}">
            </div>
            <div class="field">
              <label>Nom complet</label>
              <input type="text" name="game[steps][${index}][title]" value="Nouvelle étape">
            </div>
            <div class="field">
              <label>Nom court</label>
              <input type="text" name="game[steps][${index}][short]" value="Étape ${stepId}">
            </div>
            <div class="field">
              <label>Couleur</label>
              <input type="color" name="game[steps][${index}][color]" value="${color}">
            </div>
            <div class="field row-action-field">
              <label>Action</label>
              <button class="button danger" type="button" data-remove-row>Supprimer</button>
            </div>
          `;
          stepsList.appendChild(row);

          const group = document.createElement("article");
          group.className = "step-card-editor";
          group.dataset.cardStepGroup = String(stepId);
          group.innerHTML = `
            <div class="step-card-editor-head">
              <div>
                <strong>${stepId}. Étape ${stepId}</strong>
                <span>0 carte</span>
              </div>
              <button class="button secondary" type="button" data-add-card-to-step="${stepId}">Ajouter une carte</button>
            </div>
            <div class="admin-list card-step-list">
              <div class="session-admin-empty">Aucune carte dans cette étape.</div>
            </div>
          `;
          cardsByStep.appendChild(group);
          bindRemoveButtons(row);
          bindStepRows(row);
          bindAddCardButtons(group);
          refreshCardStepSelects();
        });

        bindRemoveButtons();
        bindStepRows();
        bindAddCardButtons();
      })();

      const logoInput = document.querySelector("#brandLogo");
      const previewImage = document.querySelector("#brandPreviewImage");
      const previewEmpty = document.querySelector("#brandPreviewEmpty");
      const palettePreview = document.querySelector("#palettePreview");
      const themeInputs = {
        primary: document.querySelector("#theme_primary"),
        secondary: document.querySelector("#theme_secondary"),
        accent: document.querySelector("#theme_accent"),
        background: document.querySelector("#theme_background"),
        surface: document.querySelector("#theme_surface"),
        text: document.querySelector("#theme_text"),
        muted: document.querySelector("#theme_muted"),
        accentColor: document.querySelector("#accent"),
      };

      function rgbToHex(r, g, b) {
        return `#${[r, g, b].map((value) => Math.max(0, Math.min(255, value)).toString(16).padStart(2, "0")).join("")}`.toUpperCase();
      }

      function colorDistance(a, b) {
        return Math.abs(a[0] - b[0]) + Math.abs(a[1] - b[1]) + Math.abs(a[2] - b[2]);
      }

      function extractPalette(image) {
        const canvas = document.createElement("canvas");
        const size = 96;
        canvas.width = size;
        canvas.height = size;
        const context = canvas.getContext("2d", { willReadFrequently: true });
        context.drawImage(image, 0, 0, size, size);
        const pixels = context.getImageData(0, 0, size, size).data;
        const buckets = new Map();

        for (let index = 0; index < pixels.length; index += 4) {
          const alpha = pixels[index + 3];
          if (alpha < 180) continue;
          const r = pixels[index];
          const g = pixels[index + 1];
          const b = pixels[index + 2];
          const brightness = r + g + b;
          if (brightness > 735 || brightness < 45) continue;
          const key = `${Math.round(r / 32)}-${Math.round(g / 32)}-${Math.round(b / 32)}`;
          const bucket = buckets.get(key) || { count: 0, r: 0, g: 0, b: 0 };
          bucket.count += 1;
          bucket.r += r;
          bucket.g += g;
          bucket.b += b;
          buckets.set(key, bucket);
        }

        const colors = [...buckets.values()]
          .sort((a, b) => b.count - a.count)
          .map((bucket) => [
            Math.round(bucket.r / bucket.count),
            Math.round(bucket.g / bucket.count),
            Math.round(bucket.b / bucket.count),
          ])
          .filter((color, index, array) => array.findIndex((candidate) => colorDistance(color, candidate) < 64) === index)
          .slice(0, 6)
          .map(([r, g, b]) => rgbToHex(r, g, b));

        return colors.length ? colors : ["#4545A4", "#10B981", "#F59E0B"];
      }

      function renderPalette(colors) {
        if (!palettePreview) return;
        palettePreview.innerHTML = colors.map((color, index) => `
          <label class="palette-swatch" style="--swatch: ${color}">
            <span>${color}</span>
            <input type="hidden" name="game[brand][palette][${index}]" value="${color}">
          </label>
        `).join("");
      }

      function applyPreviewColors(colors) {
        if (!colors.length) return;
        if (themeInputs.primary) themeInputs.primary.value = colors[0] || themeInputs.primary.value;
        if (themeInputs.secondary) themeInputs.secondary.value = colors[1] || colors[0] || themeInputs.secondary.value;
        if (themeInputs.accent) themeInputs.accent.value = colors[2] || colors[1] || colors[0] || themeInputs.accent.value;
        if (themeInputs.accentColor) themeInputs.accentColor.value = colors[0] || themeInputs.accentColor.value;
        document.documentElement.style.setProperty("--accent", colors[0] || "#1D5BD4");
      }

      logoInput?.addEventListener("change", () => {
        const file = logoInput.files?.[0];
        if (!file || !file.type.startsWith("image/")) return;
        const url = URL.createObjectURL(file);
        if (previewImage) {
          previewImage.hidden = false;
          previewImage.src = url;
        }
        if (previewEmpty) previewEmpty.hidden = true;

        const image = new Image();
        image.onload = () => {
          const colors = extractPalette(image);
          renderPalette(colors);
          applyPreviewColors(colors);
          URL.revokeObjectURL(url);
        };
        image.src = url;
      });
    </script>
  </body>
</html>
