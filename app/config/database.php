<?php

require_once __DIR__ . '/env.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('DB_HOST', '127.0.0.1');
    $port = env_value('DB_PORT', '3306');
    $name = env_value('DB_NAME', 'cliniq');
    $user = env_value('DB_USER', 'root');
    $pass = env_value('DB_PASS', '');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

/**
 * Authentication database connection.
 *
 * The redesigned account and visit modules use this Cliniq_db connection.
 * Legacy modules that have not been migrated yet continue using db().
 */
function auth_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('DB_HOST', '127.0.0.1');
    $port = env_value('DB_PORT', '3306');
    $name = env_value('AUTH_DB_NAME', 'Cliniq_db');
    $user = env_value('DB_USER', 'root');
    $pass = env_value('DB_PASS', '');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

/**
 * Appointment data is part of the redesigned Cliniq_db schema.
 */
function appointment_db(): PDO
{
    return auth_db();
}
