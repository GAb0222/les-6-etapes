<?php

declare(strict_types=1);

function game_data_path(): string
{
    return APP_ROOT . '/data/game.json';
}

function load_game_data(): array
{
    $path = game_data_path();
    if (!is_file($path)) {
        throw new RuntimeException('Le fichier de données du jeu est introuvable.');
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Impossible de lire le fichier de données du jeu.');
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Le fichier de données du jeu est invalide.');
    }

    return with_game_defaults($decoded);
}

function with_game_defaults(array $data): array
{
    $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
    $ui = is_array($data['ui'] ?? null) ? $data['ui'] : [];
    $brand = is_array($data['brand'] ?? null) ? $data['brand'] : [];
    $theme = is_array($data['theme'] ?? null) ? $data['theme'] : [];

    $data['settings'] = array_replace([
        'layout' => 'Grille',
        'showTimer' => false,
        'showHints' => true,
        'requireOrderFirst' => true,
        'showSessionJoin' => false,
        'showLiveLeaderboard' => true,
        'classroomAmbiance' => 'chaos',
        'enableCaseRound' => true,
        'enableBadges' => true,
        'enableTrapCards' => true,
        'difficultyMode' => 'progressive',
        'accentColor' => '#1D5BD4',
    ], $settings);

    $data['brand'] = array_replace([
        'logoPath' => '',
        'faviconPath' => '',
        'palette' => [],
        'decorations' => '💡 🔎 💶 🤝 ⚖️ 🚀',
        'showDecorations' => true,
        'decorationIntensity' => 'normal',
        'visualStyle' => 'classe',
    ], $brand);

    $data['theme'] = array_replace([
        'primary' => $data['settings']['accentColor'] ?? '#1D5BD4',
        'secondary' => '#34D399',
        'accent' => '#F59E0B',
        'background' => '#EEF3FB',
        'surface' => '#FFFFFF',
        'text' => '#1A1F36',
        'muted' => '#6B7280',
    ], $theme);

    $data['ui'] = array_replace([
        'appName' => 'Les 6 étapes',
        'brandName' => 'Création d’entreprise',
        'documentTitle' => 'Les 6 étapes de la création d’entreprise',
        'hintDefault' => 'Clique sur l’étape correspondante à la carte en cours.',
        'hintButton' => 'Indice',
        'skipButton' => 'Revoir plus tard',
        'resetButton' => 'Recommencer',
        'completionAdminLabel' => 'Admin',
        'homeTitle' => 'Avant de commencer',
        'homeBody' => 'Lis chaque carte, repère l’action principale, puis associe-la à l’étape logique du parcours.',
        'homeButton' => 'Commencer',
        'sneeTitle' => 'Envie de te lancer ?',
        'sneeBody' => 'Si l’atelier t’a donné envie de créer, le Statut National Étudiant-Entrepreneur (SNEE) permet de monter un projet tout en restant étudiant, avec l’accompagnement d’une Pépite près de chez toi.',
        'sneeCta' => 'Découvrir le SNEE',
        'sneeUrl' => 'https://pepite-france.fr/',
    ], $ui);

    $data['cards'] = array_values(array_map(static function (array $card): array {
        $desc = (string) ($card['desc'] ?? '');
        return array_replace([
            'type' => 'simple',
            'difficulty' => 'normal',
            'competency' => 'general',
            'explanation' => $desc,
            'errorExplanation' => '',
            'casePrompt' => '',
        ], $card);
    }, is_array($data['cards'] ?? null) ? $data['cards'] : []));

    return $data;
}

function save_game_data(array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Impossible d’encoder les données du jeu.');
    }

    backup_game_data();
    $result = file_put_contents(game_data_path(), $json . PHP_EOL, LOCK_EX);
    if ($result === false) {
        throw new RuntimeException('Impossible d’enregistrer les données du jeu.');
    }
}

function game_backups_dir(): string
{
    return APP_ROOT . '/data/backups';
}

function backup_game_data(): void
{
    $source = game_data_path();
    if (!is_file($source)) {
        return;
    }

    $dir = game_backups_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier de sauvegardes.');
    }

    $destination = $dir . '/game-' . date('Ymd-His') . '.json';
    @copy($source, $destination);
}

function prune_game_backups(int $keep = 20): int
{
    $dir = game_backups_dir();
    if (!is_dir($dir)) {
        return 0;
    }

    $paths = glob($dir . '/game-*.json') ?: [];
    rsort($paths);
    $deleted = 0;

    foreach (array_slice($paths, max(0, $keep)) as $path) {
        if (is_file($path) && @unlink($path)) {
            $deleted += 1;
        }
    }

    return $deleted;
}

function public_uploads_path(): string
{
    return APP_ROOT . '/uploads/brands';
}

function ensure_uploads_directory(): void
{
    $path = public_uploads_path();
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Impossible de créer le dossier des logos.');
    }
}

function handle_brand_logo_upload(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Le logo n’a pas pu être envoyé.');
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Le logo ne doit pas dépasser 2 Mo.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        throw new RuntimeException('Le fichier envoyé doit être une image.');
    }

    $mime = (string) ($imageInfo['mime'] ?? '');
    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Formats acceptés : PNG, JPEG, WebP ou GIF.');
    }

    ensure_uploads_directory();

    $extension = $extensions[$mime];
    $filename = 'brand-logo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $relativePath = 'uploads/brands/' . $filename;
    $destination = APP_ROOT . '/' . $relativePath;

    if (!move_uploaded_file($tmpPath, $destination)) {
        throw new RuntimeException('Impossible d’enregistrer le logo.');
    }

    return [
        'path' => $relativePath,
        'palette' => extract_palette_from_image($destination, $mime),
    ];
}

function extract_palette_from_image(string $path, string $mime): array
{
    if (!function_exists('imagecreatetruecolor')) {
        return [];
    }

    $create = null;
    if ($mime === 'image/png') {
        $create = 'imagecreatefrompng';
    } elseif ($mime === 'image/jpeg') {
        $create = 'imagecreatefromjpeg';
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $create = 'imagecreatefromwebp';
    } elseif ($mime === 'image/gif') {
        $create = 'imagecreatefromgif';
    }

    if ($create === null || !function_exists($create)) {
        return [];
    }

    $image = @$create($path);
    if (!$image) {
        return [];
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $stepX = max(1, (int) floor($width / 70));
    $stepY = max(1, (int) floor($height / 70));
    $buckets = [];

    for ($y = 0; $y < $height; $y += $stepY) {
        for ($x = 0; $x < $width; $x += $stepX) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;
            if ($alpha > 95) {
                continue;
            }

            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;

            if (($r + $g + $b) > 720 || ($r + $g + $b) < 45) {
                continue;
            }

            $key = ((int) floor($r / 32)) . '-' . ((int) floor($g / 32)) . '-' . ((int) floor($b / 32));
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['count' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
            }

            $buckets[$key]['count'] += 1;
            $buckets[$key]['r'] += $r;
            $buckets[$key]['g'] += $g;
            $buckets[$key]['b'] += $b;
        }
    }

    imagedestroy($image);

    uasort($buckets, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

    $palette = [];
    foreach ($buckets as $bucket) {
        if ($bucket['count'] < 1) {
            continue;
        }
        $palette[] = rgb_to_hex(
            (int) round($bucket['r'] / $bucket['count']),
            (int) round($bucket['g'] / $bucket['count']),
            (int) round($bucket['b'] / $bucket['count'])
        );
        if (count($palette) >= 6) {
            break;
        }
    }

    return $palette;
}

function rgb_to_hex(int $r, int $g, int $b): string
{
    return sprintf('#%02X%02X%02X', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

function load_admin_config(): array
{
    $config = require APP_ROOT . '/config/admin.php';
    return is_array($config) ? $config : ['users' => []];
}

function admin_secret_path(): string
{
    return APP_ROOT . '/data/admin_secret.php';
}

function admin_username(): string
{
    $fromEnv = trim((string) (getenv('ADMIN_USERNAME') ?: ''));
    if ($fromEnv !== '') {
        return $fromEnv;
    }

    return (string) ((load_admin_config()['users'][0]['username'] ?? 'admin'));
}

function load_admin_secret(): array
{
    $path = admin_secret_path();
    if (is_file($path)) {
        $data = include $path;
        if (is_array($data)) {
            return $data;
        }
    }

    $envHash = (string) (getenv('ADMIN_PASSWORD_HASH') ?: '');
    if ($envHash !== '') {
        return ['password_hash' => $envHash];
    }

    $configUser = load_admin_config()['users'][0] ?? [];
    return [
        'password_hash' => (string) ($configUser['password_hash'] ?? ''),
        'password_sha256' => (string) ($configUser['password_sha256'] ?? ''),
    ];
}

function find_admin_user(string $username): ?array
{
    if ($username !== admin_username()) {
        return null;
    }

    $secret = load_admin_secret();
    $configUser = load_admin_config()['users'][0] ?? [];

    return [
        'username' => $username,
        'display_name' => (string) ($configUser['display_name'] ?? 'Administrateur'),
        'password_hash' => (string) ($secret['password_hash'] ?? ''),
        'password_sha256' => (string) ($secret['password_sha256'] ?? ''),
    ];
}

function verify_admin_credentials(string $username, string $password): bool
{
    $user = find_admin_user($username);
    if ($user === null) {
        return false;
    }

    $modernHash = (string) ($user['password_hash'] ?? '');
    if ($modernHash !== '') {
        return password_verify($password, $modernHash);
    }

    $legacyHash = (string) ($user['password_sha256'] ?? '');
    if ($legacyHash !== '') {
        return hash_equals($legacyHash, hash('sha256', $password));
    }

    $envPassword = (string) (getenv('ADMIN_PASSWORD') ?: '');
    if ($envPassword !== '') {
        return hash_equals($envPassword, $password);
    }

    return $password === 'admin1234';
}

function admin_uses_default_password(): bool
{
    $secret = load_admin_secret();
    if (($secret['password_hash'] ?? '') !== '') {
        return password_verify('admin1234', (string) $secret['password_hash']);
    }
    if (($secret['password_sha256'] ?? '') !== '') {
        return hash_equals(
            'ac9689e2272427085e35b9d3e3e8bed88cb3434828b43b86fc0596cad4c6e270',
            (string) $secret['password_sha256']
        );
    }
    $envPassword = (string) (getenv('ADMIN_PASSWORD') ?: '');
    if ($envPassword !== '') {
        return $envPassword === 'admin1234';
    }

    return true;
}

function save_admin_password(string $username, string $newPassword): void
{
    $username = sanitize_text($username);
    if ($username === '' || $username !== admin_username()) {
        throw new RuntimeException('Compte admin introuvable.');
    }
    if (strlen($newPassword) < 10) {
        throw new RuntimeException('Le nouveau mot de passe doit contenir au moins 10 caractères.');
    }

    $php = "<?php\n\nreturn " . var_export([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    ], true) . ";\n";
    if (file_put_contents(admin_secret_path(), $php, LOCK_EX) === false) {
        throw new RuntimeException('Impossible d’enregistrer le mot de passe admin.');
    }
}

function admin_is_authenticated(): bool
{
    if (empty($_SESSION['admin_user'])) {
        return false;
    }

    $lastSeen = (int) ($_SESSION['admin_last_seen'] ?? 0);
    if ($lastSeen > 0 && time() - $lastSeen > 1800) {
        unset($_SESSION['admin_user'], $_SESSION['admin_last_seen']);
        return false;
    }

    $_SESSION['admin_last_seen'] = time();
    return true;
}

function require_admin_auth(): void
{
    if (!admin_is_authenticated()) {
        header('Location: admin.php');
        exit;
    }
}

function sanitize_game_payload(array $input, array $files = [], array $existing = []): array
{
    $settings = [
        'layout' => sanitize_text($input['settings']['layout'] ?? 'Grille'),
        'showTimer' => !empty($input['settings']['showTimer']),
        'showHints' => !empty($input['settings']['showHints']),
        'requireOrderFirst' => !empty($input['settings']['requireOrderFirst']),
        'showSessionJoin' => !empty($input['settings']['showSessionJoin']),
        'showLiveLeaderboard' => !empty($input['settings']['showLiveLeaderboard']),
        'classroomAmbiance' => sanitize_choice((string) ($input['settings']['classroomAmbiance'] ?? 'chaos'), ['chaos', 'calme'], 'chaos'),
        'enableCaseRound' => !empty($input['settings']['enableCaseRound']),
        'enableBadges' => !empty($input['settings']['enableBadges']),
        'enableTrapCards' => !empty($input['settings']['enableTrapCards']),
        'difficultyMode' => sanitize_choice((string) ($input['settings']['difficultyMode'] ?? 'progressive'), ['discovery', 'challenge', 'expert', 'progressive'], 'progressive'),
        'accentColor' => sanitize_color($input['settings']['accentColor'] ?? '#1D5BD4', '#1D5BD4'),
    ];

    $existingBrand = is_array($existing['brand'] ?? null) ? $existing['brand'] : [];
    $uploadedLogo = isset($files['brand_logo']) && is_array($files['brand_logo'])
        ? handle_brand_logo_upload($files['brand_logo'])
        : null;
    $palette = $uploadedLogo['palette'] ?? ($input['brand']['palette'] ?? ($existingBrand['palette'] ?? []));

    $brand = [
        'logoPath' => $uploadedLogo['path'] ?? sanitize_asset_path($input['brand']['logoPath'] ?? ($existingBrand['logoPath'] ?? '')),
        'faviconPath' => sanitize_asset_path($input['brand']['faviconPath'] ?? ($existingBrand['faviconPath'] ?? '')),
        'palette' => sanitize_palette(is_array($palette) ? $palette : []),
        'decorations' => sanitize_text($input['brand']['decorations'] ?? ($existingBrand['decorations'] ?? '💡 🔎 💶 🤝 ⚖️ 🚀')),
        'showDecorations' => !empty($input['brand']['showDecorations']),
        'decorationIntensity' => sanitize_choice((string) ($input['brand']['decorationIntensity'] ?? ($existingBrand['decorationIntensity'] ?? 'normal')), ['discret', 'normal', 'fort'], 'normal'),
        'visualStyle' => sanitize_choice((string) ($input['brand']['visualStyle'] ?? ($existingBrand['visualStyle'] ?? 'classe')), ['sobre', 'fun', 'classe'], 'classe'),
    ];

    $theme = [
        'primary' => sanitize_color($input['theme']['primary'] ?? ($brand['palette'][0] ?? $settings['accentColor']), '#1D5BD4'),
        'secondary' => sanitize_color($input['theme']['secondary'] ?? ($brand['palette'][1] ?? '#34D399'), '#34D399'),
        'accent' => sanitize_color($input['theme']['accent'] ?? ($brand['palette'][2] ?? '#F59E0B'), '#F59E0B'),
        'background' => sanitize_color($input['theme']['background'] ?? '#EEF3FB', '#EEF3FB'),
        'surface' => sanitize_color($input['theme']['surface'] ?? '#FFFFFF', '#FFFFFF'),
        'text' => sanitize_color($input['theme']['text'] ?? '#1A1F36', '#1A1F36'),
        'muted' => sanitize_color($input['theme']['muted'] ?? '#6B7280', '#6B7280'),
    ];

    if ($uploadedLogo !== null && !empty($brand['palette'])) {
        $theme['primary'] = $brand['palette'][0] ?? $theme['primary'];
        $theme['secondary'] = $brand['palette'][1] ?? $theme['secondary'];
        $theme['accent'] = $brand['palette'][2] ?? $theme['accent'];
        $settings['accentColor'] = $theme['primary'];
    }

    $ui = [
        'appName' => sanitize_text($input['ui']['appName'] ?? 'Les 6 étapes'),
        'brandName' => sanitize_text($input['ui']['brandName'] ?? 'Création d’entreprise'),
        'documentTitle' => sanitize_text($input['ui']['documentTitle'] ?? 'Les 6 étapes de la création d’entreprise'),
        'hintDefault' => sanitize_text($input['ui']['hintDefault'] ?? 'Clique sur l’étape correspondante à la carte en cours.'),
        'hintButton' => sanitize_text($input['ui']['hintButton'] ?? 'Indice'),
        'skipButton' => sanitize_text($input['ui']['skipButton'] ?? 'Revoir plus tard'),
        'resetButton' => sanitize_text($input['ui']['resetButton'] ?? 'Recommencer'),
        'completionAdminLabel' => sanitize_text($input['ui']['completionAdminLabel'] ?? 'Admin'),
        'homeTitle' => sanitize_text($input['ui']['homeTitle'] ?? 'Avant de commencer'),
        'homeBody' => sanitize_text($input['ui']['homeBody'] ?? 'Lis chaque carte, repère l’action principale, puis associe-la à l’étape logique du parcours.'),
        'homeButton' => sanitize_text($input['ui']['homeButton'] ?? 'Commencer'),
        'sneeTitle' => sanitize_text($input['ui']['sneeTitle'] ?? 'Envie de te lancer ?'),
        'sneeBody' => sanitize_text($input['ui']['sneeBody'] ?? 'Si l’atelier t’a donné envie de créer, le Statut National Étudiant-Entrepreneur (SNEE) permet de monter un projet tout en restant étudiant, avec l’accompagnement d’une Pépite près de chez toi.'),
        'sneeCta' => sanitize_text($input['ui']['sneeCta'] ?? 'Découvrir le SNEE'),
        'sneeUrl' => sanitize_http_url($input['ui']['sneeUrl'] ?? 'https://pepite-france.fr/', 'https://pepite-france.fr/'),
    ];

    $steps = [];
    $usedStepIds = [];
    $nextStepId = 1;
    foreach (($input['steps'] ?? []) as $index => $step) {
        if (!empty($step['_delete'])) {
            continue;
        }
        $title = sanitize_text($step['title'] ?? '');
        $short = sanitize_text($step['short'] ?? '');
        if ($title === '' && $short === '') {
            continue;
        }
        $id = (int) ($step['id'] ?? $index + 1);
        if ($id <= 0 || in_array($id, $usedStepIds, true)) {
            while (in_array($nextStepId, $usedStepIds, true)) {
                $nextStepId += 1;
            }
            $id = $nextStepId;
        }
        $usedStepIds[] = $id;
        $steps[] = [
            'id' => $id,
            'title' => $title !== '' ? $title : $short,
            'short' => $short !== '' ? $short : $title,
            'color' => sanitize_color($step['color'] ?? '#1D5BD4', '#1D5BD4'),
        ];
    }

    if (empty($steps)) {
        throw new RuntimeException('Ajoute au moins une étape au jeu.');
    }

    $validStepIds = array_map(static fn (array $step): int => (int) $step['id'], $steps);
    $cards = [];
    $usedCardIds = [];
    $nextCardId = 1;
    foreach (($input['cards'] ?? []) as $index => $card) {
        if (!empty($card['_delete'])) {
            continue;
        }
        $title = sanitize_text($card['title'] ?? '');
        $desc = sanitize_text($card['desc'] ?? '');
        if ($title === '' && $desc === '') {
            continue;
        }
        $id = (int) ($card['id'] ?? $index + 1);
        if ($id <= 0 || in_array($id, $usedCardIds, true)) {
            while (in_array($nextCardId, $usedCardIds, true)) {
                $nextCardId += 1;
            }
            $id = $nextCardId;
        }
        $usedCardIds[] = $id;
        $stepId = (int) ($card['stepId'] ?? $validStepIds[0]);
        if (!in_array($stepId, $validStepIds, true)) {
            $stepId = $validStepIds[0];
        }
        $cards[] = [
            'id' => $id,
            'stepId' => $stepId,
            'title' => $title !== '' ? $title : 'Carte ' . $id,
            'desc' => $desc,
            'type' => sanitize_choice((string) ($card['type'] ?? 'simple'), ['simple', 'trap', 'bonus', 'case'], 'simple'),
            'difficulty' => sanitize_choice((string) ($card['difficulty'] ?? 'normal'), ['easy', 'normal', 'expert'], 'normal'),
            'competency' => sanitize_choice((string) ($card['competency'] ?? 'general'), ['general', 'idea', 'market', 'finance', 'funding', 'legal', 'formalities'], 'general'),
            'explanation' => sanitize_text($card['explanation'] ?? $desc),
            'errorExplanation' => sanitize_text($card['errorExplanation'] ?? ''),
            'casePrompt' => sanitize_text($card['casePrompt'] ?? ''),
        ];
    }

    return [
        'settings' => $settings,
        'brand' => $brand,
        'theme' => $theme,
        'ui' => $ui,
        'steps' => $steps,
        'cards' => $cards,
    ];
}

function sanitize_text(string $value): string
{
    return trim(str_replace(["\r\n", "\r"], "\n", $value));
}

function sanitize_http_url(string $value, string $fallback = ''): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }
    if (preg_match('#^(javascript|data|vbscript):#i', $value)) {
        return $fallback;
    }
    if (!preg_match('#^https?://#i', $value)) {
        $value = 'https://' . ltrim($value, '/');
    }
    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return $fallback;
    }

    return $value;
}

function sanitize_color(string $value, string $fallback = '#1D5BD4'): string
{
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : $fallback;
}

function sanitize_choice(string $value, array $choices, string $fallback): string
{
    return in_array($value, $choices, true) ? $value : $fallback;
}

function sanitize_palette(array $colors): array
{
    $palette = [];
    foreach ($colors as $color) {
        if (!is_string($color) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            continue;
        }
        $palette[] = strtoupper($color);
        if (count($palette) >= 6) {
            break;
        }
    }

    return $palette;
}

function sanitize_asset_path(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return preg_match('#^uploads/brands/[A-Za-z0-9._-]+$#', $value) ? $value : '';
}
