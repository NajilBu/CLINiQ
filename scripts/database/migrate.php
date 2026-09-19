<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/config/env.php';

const CLINIQ_BASELINE_THROUGH = '20260908_passport_access_audit_reporting.sql';

function migration_checksum_is_known_compatible(string $name, string $stored, string $current, ?string $file = null): bool
{
    if ($file !== null && is_file($file)) {
        $contents = file_get_contents($file);
        $lfChecksum = $contents === false ? false : hash('sha256', str_replace("\r\n", "\n", $contents));
        // A Windows Docker build can preserve CRLF while the applied migration
        // was recorded from the same LF source. Accept only that exact match.
        if ($lfChecksum !== false && hash_equals(strtolower($stored), strtolower($lfChecksum))) {
            return true;
        }
    }

    if ($name === '20260913_create_patient_notifications.sql') {
        // This migration's SQL was unchanged; an earlier Windows checkout recorded
        // the CRLF variant. Accept only that exact historical checksum.
        return strtolower($stored) === '54393ca51fa1a3aa5d4de114cef36d426f3d4cc7503e2f0fccb45b84585454b9'
            && strtolower($current) === 'f76f217f30da37172d193d9c5c972eb0274c47e86a7d73c217aed808039c7be7';
    }

    if ($name === '20260915_student_only_ape_scheduling.sql') {
        // This migration was changed only to make the ape_records entry_mode
        // addition idempotent when the production schema already contains it.
        // Accept only the original migration checksum and this exact revision.
        $originalChecksums = [
            '350bc450f49dd7ade8d125a74883da1e0a07de2a770e6da6b19627756096ccbe',
        ];
        $idempotentChecksums = [
            '6263b13984783554a45022d6c58cdf91a2152e5b53723eea69498158e70bbb1e',
            '9c0d2cb7f3b93988c021dda5c44321a9e42161f8ee29f67e48b7aa2d4b6a4814',
            '1a1de9360978a5d0814cca8b76fc1813bbd7cd5bd7f531903338ca3e3f3475db',
        ];

        return in_array(strtolower($stored), $originalChecksums, true)
            && in_array(strtolower($current), $idempotentChecksums, true);
    }

    if ($name !== '20260910_add_passport_bmi_visibility.sql') {
        return false;
    }

    // This migration was changed only to make ADD COLUMN idempotent after some
    // installations had already recorded its original checksum. Include both
    // checkout line endings without relaxing checks for any other migration.
    $originalChecksums = [
        '51eda6fc1e720c4e39aceaad07d18c139bdf609c595203c62de3cd4f0b361249',
        '77b562a55b3f58b18f1d80979f4f4d5869f31e70aae143d28dc5de9586ae1310',
    ];
    $idempotentChecksums = [
        '304f1f155f1ab59c240ec6179c7d6a594083b3f864e650b1f3b183af2354b726',
        'cb7b8a43ba0c48d9ee48b257c76ed0cd9a3fa9475fd2a04acbfc0c759ff048d9',
    ];

    return in_array(strtolower($stored), array_merge($originalChecksums, $idempotentChecksums), true)
        && in_array(strtolower($current), $idempotentChecksums, true);
}

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

function migration_post_baseline_tables(string $migrationDirectory): array
{
    $tables = [];
    foreach (migration_files($migrationDirectory) as $file) {
        if (strcmp(basename($file), CLINIQ_BASELINE_THROUGH) <= 0) {
            continue;
        }
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Unable to read migration: ' . basename($file));
        }
        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $matches);
        $tables = array_merge($tables, $matches[1] ?? []);
    }
    return array_values(array_unique($tables));
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
        $required = array_values(array_diff(
            migration_baseline_tables($schemaFile),
            array_merge(['schema_migrations'], migration_post_baseline_tables($migrationDirectory))
        ));
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
            if (!hash_equals((string) $known[$name], $checksum)
                && !migration_checksum_is_known_compatible($name, (string) $known[$name], $checksum, $file)) {
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
