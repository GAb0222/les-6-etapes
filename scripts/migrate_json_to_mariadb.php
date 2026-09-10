<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/database.php';

$game = load_game_data();
$pdo = db();
$pdo->beginTransaction();

try {
    $pdo->exec("INSERT INTO establishments (name, slug) VALUES ('Établissement démo', 'demo') ON DUPLICATE KEY UPDATE name = VALUES(name)");
    $establishmentId = (int) $pdo->query("SELECT id FROM establishments WHERE slug = 'demo'")->fetchColumn();

    $themeStmt = $pdo->prepare('INSERT INTO brand_themes (establishment_id, name, logo_path, palette_json, theme_json, settings_json) VALUES (?, ?, ?, ?, ?, ?)');
    $themeStmt->execute([
        $establishmentId,
        'Thème principal',
        $game['brand']['logoPath'] ?? null,
        json_encode($game['brand']['palette'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($game['theme'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($game['brand'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $brandThemeId = (int) $pdo->lastInsertId();

    $gameStmt = $pdo->prepare('INSERT INTO games (establishment_id, brand_theme_id, title, slug, status, settings_json, ui_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $gameStmt->execute([
        $establishmentId,
        $brandThemeId,
        $game['ui']['brandName'] ?? 'Création d’entreprise',
        'creation-entreprise',
        'published',
        json_encode($game['settings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($game['ui'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $gameId = (int) $pdo->lastInsertId();

    $versionStmt = $pdo->prepare('INSERT INTO game_versions (game_id, version_number, label, is_published) VALUES (?, 1, ?, 1)');
    $versionStmt->execute([$gameId, 'Version importée depuis JSON']);
    $versionId = (int) $pdo->lastInsertId();

    $stepDbIds = [];
    $stepStmt = $pdo->prepare('INSERT INTO game_steps (game_version_id, step_key, title, short_title, color, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (($game['steps'] ?? []) as $sort => $step) {
        $stepStmt->execute([
            $versionId,
            (int) $step['id'],
            (string) $step['title'],
            (string) $step['short'],
            (string) $step['color'],
            $sort + 1,
        ]);
        $stepDbIds[(int) $step['id']] = (int) $pdo->lastInsertId();
    }

    $cardStmt = $pdo->prepare('INSERT INTO game_cards (game_version_id, step_id, card_key, title, description, card_type, difficulty, competency, success_explanation, error_explanation, case_prompt, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (($game['cards'] ?? []) as $sort => $card) {
        $stepId = (int) ($card['stepId'] ?? 0);
        $cardStmt->execute([
            $versionId,
            $stepDbIds[$stepId] ?? reset($stepDbIds),
            (int) $card['id'],
            (string) $card['title'],
            (string) ($card['desc'] ?? ''),
            (string) ($card['type'] ?? 'simple'),
            (string) ($card['difficulty'] ?? 'normal'),
            (string) ($card['competency'] ?? 'general'),
            (string) ($card['explanation'] ?? ($card['desc'] ?? '')),
            (string) ($card['errorExplanation'] ?? ''),
            (string) ($card['casePrompt'] ?? ''),
            $sort + 1,
        ]);
    }

    $pdo->commit();
    echo "Migration terminée. Jeu #{$gameId}, version #{$versionId}.\n";
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, "Migration échouée : {$exception->getMessage()}\n");
    exit(1);
}
