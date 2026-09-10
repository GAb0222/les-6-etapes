<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

require_admin_auth();
write_event('events_exported');

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="journal-evenements.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['date', 'type', 'acteur', 'session', 'ip', 'details'], ';');
foreach (read_recent_events(1000) as $event) {
    fputcsv($out, [
        (string) ($event['at'] ?? ''),
        (string) ($event['type'] ?? ''),
        (string) ($event['actor'] ?? ''),
        (string) ($event['sessionCode'] ?? ''),
        (string) ($event['ip'] ?? ''),
        json_encode($event['details'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], ';');
}
fclose($out);
