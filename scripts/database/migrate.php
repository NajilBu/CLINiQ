<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/config/env.php';

const CLINIQ_BASELINE_THROUGH = '20260908_passport_access_audit_reporting.sql';

function migration_fail(string $message): never
{
    fwrite(STDERR, '[CLINiQ Database] ' . $message . PHP_EOL);
    exit(1);
}

function migration_mysql_binary(): string
{
    $configured = trim((string) env_value('MYSQL_BIN', ''));
    $candidates = array_filter([
        $configured,
        PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysql.exe' : null,
        '/usr/bin/mariadb',
        '/usr/bin/mysql',
        'mysql',
    ]);

    foreach ($candidates as $candidate) {
        if ($candidate === 'mysql' || is_file($candidate)) {
            return $candidate;
        }
    }

    migration_fail('MySQL client not found. Set MYSQL_BIN to the mysql executable.');
}

function migration_option_value(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function migration_run_sql_file(string $database, string $sqlFile): int
{
    $optionFile = tempnam(sys_get_temp_dir(), 'cliniq-mysql-');
    if ($optionFile === false) {
        migration_fail('Could not create a temporary MySQL option file.');
    }

    $optionContents = implode(PHP_EOL, [
        '[client]',
        'host=' . migration_option_value((string) env_value('DB_HOST', '127.0.0.1')),
        'port=' . (string) env_value('DB_PORT', '3306'),
        'user=' . migration_option_value((string) env_value('DB_USER', 'root')),
        'password=' . migration_option_value((string) env_value('DB_PASS', '')),
        'protocol=tcp',
        'default-character-set=utf8mb4',
        '',
    ]);

    try {
        if (file_put_contents($optionFile, $optionContents, LOCK_EX) === false) {
            migration_fail('Could not write the temporary MySQL option file.');
        }
        @chmod($optionFile, 0600);

        $command = [
            migration_mysql_binary(),
            '--defaults-extra-file=' . $optionFile,
            '--database=' . $database,
            '--binary-mode',
            '--show-warnings',
        ];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['file', $sqlFile, 'rb'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            migration_fail('Could not start the MySQL client.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $detail = trim((string) ($stderr !== '' ? $stderr : $stdout));
            throw new RuntimeException(basename($sqlFile) . ' failed: ' . $detail);
        }

        return $exitCode;
    } finally {
        if (is_file($optionFile)) {
            @unlink($optionFile);
        }
    }
}

function migration_files(string $migrationDirectory): array
{
    $files = glob($migrationDirectory . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    $files = array_values(array_filter($files, static fn (string $file): bool =>
        !str_ends_with(strtolower(basename($file)), '_rollback.sql')
    ));
    usort($files, static fn (string $a, string $b): int => strcmp(basename($a), basename($b)));
    return $files;
}

function migration_baseline_tables(string $schemaFile): array
{
    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
        throw new RuntimeException('Unable to read the production schema.');
    }

    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $matches);
    $tables = array_values(array_unique($matches[1] ?? []));
    sort($tables);
    return $tables;
}

try {
    $projectRoot = dirname(__DIR__, 2);
    $schemaFile = $projectRoot . '/database/production_schema.sql';
    $migrationDirectory = $projectRoot . '/database/migrations';
    $database = (string) env_value('AUTH_DB_NAME', 'Cliniq_db');

    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        throw new RuntimeException('AUTH_DB_NAME may contain only letters, numbers, and underscores.');
    }
    if (!is_file($schemaFile)) {
        throw new RuntimeException('Production schema not found: ' . $schemaFile);
    }

    $host = (string) env_value('DB_HOST', '127.0.0.1');
    $port = (string) env_value('DB_PORT', '3306');
    $user = (string) env_value('DB_USER', 'root');
    $pass = (string) env_value('DB_PASS', '');
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$database}`");

    $tableCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\''
    )->fetchColumn();

    if ($tableCount === 0) {
        echo 'Creating fresh database from production_schema.sql...', PHP_EOL;
        migration_run_sql_file($database, $schemaFile);
    } else {
        $required = array_values(array_diff(migration_baseline_tables($schemaFile), ['schema_migrations']));
        $actual = $pdo->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\''
        )->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff($required, $actual));
        if ($missing !== []) {
            throw new RuntimeException(
                'Existing database is not a complete CLINiQ baseline. Missing: ' . implode(', ', $missing)
            );
        }
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (' .
        'migration VARCHAR(255) PRIMARY KEY, checksum CHAR(64) NOT NULL, ' .
        'execution_ms INT UNSIGNED NOT NULL DEFAULT 0, ' .
        'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $migrationFiles = migration_files($migrationDirectory);
    $insert = $pdo->prepare(
        'INSERT INTO schema_migrations (migration, checksum, execution_ms) VALUES (?, ?, ?)'
    );
    $known = $pdo->query('SELECT migration, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($migrationFiles as $file) {
        $name = basename($file);
        $checksum = hash_file('sha256', $file);
        if ($checksum === false) {
            throw new RuntimeException('Could not checksum migration: ' . $name);
        }

        if (isset($known[$name])) {
            if (!hash_equals((string) $known[$name], $checksum)) {
                throw new RuntimeException('Applied migration was modified: ' . $name);
            }
            continue;
        }

        if (strcmp($name, CLINIQ_BASELINE_THROUGH) <= 0) {
            $insert->execute([$name, $checksum, 0]);
            echo 'Baselined: ', $name, PHP_EOL;
            continue;
        }

        echo 'Applying: ', $name, PHP_EOL;
        $started = hrtime(true);
        migration_run_sql_file($database, $file);
        $elapsedMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $insert->execute([$name, $checksum, max(0, $elapsedMs)]);
    }

    echo 'Database is ready. Applied migrations: ', count($migrationFiles), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    migration_fail($e->getMessage());
}
