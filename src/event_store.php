<?php

declare(strict_types=1);

function events_dir(): string
{
    return APP_ROOT . '/data/logs';
}

function ensure_events_dir(): void
{
    $dir = events_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier des journaux.');
    }
}

function event_log_path(?string $date = null): string
{
    return events_dir() . '/events-' . ($date ?? date('Y-m-d')) . '.jsonl';
}

function current_request_ip(): string
{
    return sanitize_text((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
}

function write_event(string $type, array $details = [], ?string $sessionCode = null, ?string $actor = null): void
{
    ensure_events_dir();
    $event = [
        'at' => date(DATE_ATOM),
        'type' => sanitize_text($type),
        'actor' => sanitize_text($actor ?? (string) ($_SESSION['admin_user'] ?? 'system')),
        'sessionCode' => $sessionCode !== null ? normalize_session_code($sessionCode) : null,
        'ip' => current_request_ip(),
        'details' => sanitize_event_details($details),
    ];

    $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    file_put_contents(event_log_path(), $json . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function sanitize_event_details(array $details): array
{
    $clean = [];
    foreach ($details as $key => $value) {
        $safeKey = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $key) ?: 'detail';
        if (is_array($value)) {
            $clean[$safeKey] = sanitize_event_details($value);
        } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            $clean[$safeKey] = $value;
        } else {
            $clean[$safeKey] = function_exists('mb_substr')
                ? mb_substr(sanitize_text((string) $value), 0, 300)
                : substr(sanitize_text((string) $value), 0, 300);
        }
    }

    return $clean;
}

function read_recent_events(int $limit = 120): array
{
    ensure_events_dir();
    $paths = glob(events_dir() . '/events-*.jsonl') ?: [];
    rsort($paths);
    $events = [];

    foreach ($paths as $path) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($index = count($lines) - 1; $index >= 0; $index -= 1) {
            $decoded = json_decode($lines[$index], true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
            if (count($events) >= $limit) {
                return $events;
            }
        }
    }

    return $events;
}

function prune_event_logs(int $keepDays = 30): int
{
    ensure_events_dir();
    $paths = glob(events_dir() . '/events-*.jsonl') ?: [];
    $deleted = 0;
    $cutoff = strtotime('-' . max(1, $keepDays) . ' days');

    foreach ($paths as $path) {
        if (!preg_match('/events-(\d{4}-\d{2}-\d{2})\.jsonl$/', $path, $matches)) {
            continue;
        }

        $timestamp = strtotime($matches[1]);
        if ($timestamp !== false && $timestamp < $cutoff && @unlink($path)) {
            $deleted += 1;
        }
    }

    return $deleted;
}
