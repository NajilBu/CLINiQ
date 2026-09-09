<?php

function env_value(string $key, ?string $default = null): ?string
{
    static $env = null;

    $runtimeValue = getenv($key);
    if ($runtimeValue !== false) {
        return $runtimeValue;
    }

    if ($env === null) {
        $env = [];
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $line, 2);
                $env[trim($name)] = trim($value);
            }
        }
    }

    return $env[$key] ?? $default;
}

function app_is_production(): bool
{
    return strtolower((string) env_value('APP_ENV', 'development')) === 'production';
}

function validate_production_environment(): void
{
    if (!app_is_production()) {
        return;
    }

    foreach (['APP_KEY', 'BACKUP_ENCRYPTION_KEY', 'DB_HOST', 'AUTH_DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
        $value = trim((string) env_value($key, ''));
        if ($value === '' || str_contains(strtolower($value), 'replace-with')) {
            throw new RuntimeException("Production configuration requires {$key}.");
        }
    }
    if (strlen((string) env_value('APP_KEY', '')) < 32) {
        throw new RuntimeException('Production APP_KEY must contain at least 32 characters.');
    }
    if (strtolower((string) env_value('DB_USER', '')) === 'root') {
        throw new RuntimeException('Production web requests must not use the database root account.');
    }
}

validate_production_environment();

$appTimezone = env_value('APP_TIMEZONE', 'Asia/Manila');
if (!in_array($appTimezone, timezone_identifiers_list(), true)) {
    $appTimezone = 'Asia/Manila';
}
date_default_timezone_set($appTimezone);

function app_url(string $path = ''): string
{
    return rtrim(env_value('APP_URL', '/cliniq/public'), '/') . '/' . ltrim($path, '/');
}
