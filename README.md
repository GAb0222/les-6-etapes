# Jeu pédagogique marque blanche

Application PHP pour transformer un jeu d’étapes de création d'entreprise en outil pédagogique administrable, jouable seul, jouable en session compétitive et imprimable.

Deux modes de données existent pendant la transition SaaS :

- mode actuel compatible MAMP : configuration dans `data/game.json`, sessions dans `data/sessions/`, journaux dans `data/logs/` ;
- socle SaaS cible : Docker `PHP + Apache + MariaDB`, migrations SQL dans `database/migrations/`, import initial via `scripts/migrate_json_to_mariadb.php`.

Le JSON reste le format d’import/export et le défaut MAMP (`DATA_BACKEND=json`). MariaDB ne s’active que si `DATA_BACKEND=mariadb` (Docker). Le mot de passe admin est stocké hors git (`data/admin_secret.php` ou `ADMIN_PASSWORD_HASH`). Les universal links iOS lisent `APPLE_TEAM_ID`.

## Docker / SaaS

Préparer l’environnement :

```bash
cp .env.example .env
docker compose up --build
```

L’application est servie par défaut sur `http://localhost:8080`.

Importer le jeu actuel dans MariaDB :

```bash
docker compose exec app php scripts/migrate_json_to_mariadb.php
```

Le schéma SaaS crée les entités : établissements, utilisateurs, jeux, versions, étapes, cartes, sessions, participants, résultats, progression, exports, thèmes marque blanche et journal d’activité.

## Administration

Depuis `admin.php`, l'enseignant ou l'administrateur peut gérer :

- l'écran d'accueil du jeu ;
- le logo, les couleurs, la palette extraite du logo et les éléments décoratifs ;
- les réglages de jeu : chronomètre, indices, ordre obligatoire des étapes ;
- les mécaniques avancées : cas pratiques, badges, cartes pièges ;
- les étapes du parcours, ajoutables et supprimables ;
- les cartes associées à chaque étape, ajoutables et supprimables, avec type, difficulté, compétence, feedback de réussite, feedback d’erreur et situation de cas pratique ;
- les sessions classe ;
- le journal de traçabilité ;
- les accès vers le jeu digital, la version papier et le corrigé.

## Sessions classe

Le mode classe fonctionne comme un outil de type Wooclap :

- créer une session dans l'admin ;
- ouvrir le pilotage enseignant avec `admin_session.php?code={CODE}` ;
- projeter le code ou le QR code ;
- ouvrir une page projection amphithéâtre avec `session_projector.php?code={CODE}` ;
- les élèves rejoignent uniquement via le lien / QR `/s/{CODE}` (prénom seulement) ; le solo reste hors ligne ;
- l'enseignant lance, remet en attente ou termine la session ;
- le jeu envoie automatiquement score, temps et erreurs à `session_result.php` ;
- le jeu envoie la progression live à `session_progress.php` ;
- `session_status.php?code={CODE}` alimente le live ;
- `leaderboard.php?code={CODE}` affiche le classement public.

Une API REST progressive existe aussi :

- `POST /api/sessions`
- `POST /api/sessions/{CODE}/start`
- `POST /api/sessions/{CODE}/pause`
- `POST /api/sessions/{CODE}/end`
- `POST /api/sessions/{CODE}/join`
- `POST /api/sessions/{CODE}/progress`
- `POST /api/sessions/{CODE}/result`
- `GET /api/sessions/{CODE}/status`
- `GET /api/sessions/{CODE}/leaderboard`
- `GET /api/sessions/{CODE}/analytics`

Le classement trie les résultats par meilleur score, puis par nombre d'erreurs, puis par temps.

## Sécurité et traçabilité

- `data/.htaccess` bloque l'accès web direct aux JSON avec Apache/MAMP.
- Les sessions admin expirent après 30 minutes d'inactivité.
- Les tentatives de connexion admin sont limitées après plusieurs échecs.
- Le mot de passe admin peut être changé depuis l’interface.
- Chaque session possède un PIN enseignant stocké dans son fichier JSON.
- Les actions importantes sont journalisées : connexion admin, modification du jeu, création/lancement/fin de session, arrivée participant, résultat, export journal.
- `export_events_csv.php` permet d'exporter le journal depuis l'admin.
- `export_all_data.php` permet d’exporter la configuration, les sessions et les journaux récents.
- Les sessions terminées peuvent être archivées dans `data/sessions/archive/`.

## Expérience de jeu

Le jeu public `index.php` garde une interface simple, sans réglages visibles :

- écran d'accueil pédagogique ;
- bloc pour rejoindre une session ;
- mode découverte ou challenge ;
- première activité : remettre les étapes dans l'ordre ;
- seconde activité : classer les cartes dans les bonnes étapes ;
- cartes simples, pièges, bonus et cas pratiques ;
- feedback immédiat, score, séries, erreurs, compétences, badges et bilan final ;
- indice désactivable depuis l'admin ;
- support clic, tactile et glisser-déposer.

## Supports imprimables

- `print.php` génère le kit A4 : accueil, cartes étapes, plateau, cartes actions à découper.
- `correction.php` génère le corrigé enseignant avec le même branding.
- Les deux pages proposent un bouton **Imprimer / exporter PDF**.

## Structure

- `index.php` : jeu public.
- `admin.php` : gestion du jeu, de la marque et des sessions.
- `admin_session.php` : écran live enseignant.
- `session_projector.php` : écran projection amphithéâtre avec code et QR.
- `session_ranking_projector.php` : écran projection du classement.
- `session.php` : connexion élève à une session.
- `leaderboard.php` : classement public.
- `session_status.php` : endpoint JSON live.
- `session_progress.php` : endpoint de progression live.
- `session_result.php` : endpoint d'enregistrement du résultat.
- `qr_code.php` : génération locale du QR code.
- `export_events_csv.php` : export CSV du journal.
- `export_all_data.php` : export JSON global.
- `src/game_store.php` : lecture, valeurs par défaut et sauvegarde du jeu.
- `src/session_store.php` : sessions, participants et classement.
- `src/event_store.php` : journalisation fichier.
- `src/database.php` : connexion PDO pour la cible MariaDB.
- `database/migrations/001_init.sql` : schéma SaaS initial.
- `scripts/migrate_json_to_mariadb.php` : migration du jeu JSON vers MariaDB.
- `api/` : API session progressive.
- `assets/` : CSS et JavaScript.
- `uploads/brands/` : logos envoyés depuis l'admin.
