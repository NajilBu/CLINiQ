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

$appTimezone = env_value('APP_TIMEZONE', 'Asia/Manila');
if (!in_array($appTimezone, timezone_identifiers_list(), true)) {
    $appTimezone = 'Asia/Manila';
}
date_default_timezone_set($appTimezone);

function app_url(string $path = ''): string
{
    return rtrim(env_value('APP_URL', '/cliniq/public'), '/') . '/' . ltrim($path, '/');
}
