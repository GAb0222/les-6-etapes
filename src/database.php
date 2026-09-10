<?php

declare(strict_types=1);

function database_config(): array
{
    return [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'pedagogic_game',
        'user' => getenv('DB_USER') ?: 'pedagogic_user',
        'password' => getenv('DB_PASSWORD') ?: 'pedagogic_password',
    ];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = database_config();
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['name']);
    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function data_backend(): string
{
    $chosen = strtolower(trim((string) (getenv('DATA_BACKEND') ?: 'json')));
    return $chosen === 'mariadb' ? 'mariadb' : 'json';
}

function db_available(): bool
{
    if (data_backend() !== 'mariadb') {
        return false;
    }
    try {
        db()->query('SELECT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}
