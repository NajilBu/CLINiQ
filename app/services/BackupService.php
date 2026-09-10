<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

const CLINIQ_BACKUP_DAILY_RETENTION = 14;
const CLINIQ_BACKUP_WEEKLY_RETENTION = 12;
const CLINIQ_BACKUP_START_HOUR = 8;
const CLINIQ_BACKUP_ENCRYPTION_CIPHER = 'aes-256-gcm';
const CLINIQ_BACKUP_ENCRYPTION_MAGIC = 'CLINIQENC1';

function cliniq_backup_root(): string
{
    $default = PHP_OS_FAMILY === 'Windows' ? 'C:\\CLINiQ-Backups' : '/var/backups/cliniq';
    $configured = trim((string) env_value('BACKUP_ROOT', $default));
    $isWindowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\/][^<>:"|?*]+$/', $configured) === 1;
    $isPosixAbsolute = str_starts_with($configured, '/');
    if (!$isWindowsAbsolute && !$isPosixAbsolute) {
        throw new RuntimeException('BACKUP_ROOT must be an absolute folder path.');
    }
    $normalized = PHP_OS_FAMILY === 'Windows'
        ? str_replace('/', DIRECTORY_SEPARATOR, $configured)
        : $configured;
    return rtrim($normalized, DIRECTORY_SEPARATOR);
}

function cliniq_backup_status_path(): string
{
    return cliniq_backup_root() . DIRECTORY_SEPARATOR . 'status.json';
}

function cliniq_backup_status(): array
{
    $path = cliniq_backup_status_path();
    if (!is_file($path)) {
        return [
            'state' => 'not_run',
            'message' => 'No backup has run yet.',
            'last_success_at' => null,
            'last_success_path' => null,
            'last_verified_at' => null,
        ];
    }

    $contents = is_readable($path) ? @file_get_contents($path) : false;
    $decoded = $contents !== false ? json_decode($contents, true) : null;
    return is_array($decoded) ? array_merge([
        'state' => 'unknown',
        'message' => '',
        'last_success_at' => null,
        'last_success_path' => null,
        'last_verified_at' => null,
    ], $decoded) : [
        'state' => 'error',
        'message' => 'The backup status file is unreadable.',
        'last_success_at' => null,
        'last_success_path' => null,
        'last_verified_at' => null,
    ];
}

function cliniq_backup_write_status(array $status): void
{
    $root = cliniq_backup_root();
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException("Unable to create the backup folder {$root}.");
    }
    $status['updated_at'] = date(DATE_ATOM);
    $json = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents(cliniq_backup_status_path(), $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to update the backup status file.');
    }
}

function cliniq_backup_mysqldump_path(): string
{
    $configured = trim((string) env_value('MYSQLDUMP_PATH', ''));
    $candidates = array_filter([
        $configured,
        PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : null,
        '/usr/bin/mariadb-dump',
        '/usr/bin/mysqldump',
    ]);
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Database backup tool not found. Set MYSQLDUMP_PATH to mysqldump or mariadb-dump.');
}

function cliniq_backup_mysql_path(): string
{
    $configured = trim((string) env_value('MYSQL_PATH', ''));
    $candidates = array_filter([
        $configured,
        PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysql.exe' : null,
        '/usr/bin/mariadb',
        '/usr/bin/mysql',
    ]);
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Database restore tool not found. Set MYSQL_PATH to mysql or mariadb.');
}

function cliniq_backup_mysql_option(string $value): string
{
    return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value) . '"';
}

function cliniq_backup_copy_tree(string $source, string $destination): int
{
    if (!is_dir($source)) {
        return 0;
    }
    if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
        throw new RuntimeException("Unable to create backup document folder {$destination}.");
    }

    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $target = $destination . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
                throw new RuntimeException("Unable to create backup folder {$target}.");
            }
            continue;
        }
        if ($item->getFilename() === '.gitignore') {
            continue;
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException("Unable to create backup folder {$parent}.");
        }
        if (!copy($item->getPathname(), $target)) {
            throw new RuntimeException("Unable to copy {$item->getPathname()} into the backup.");
        }
        $count++;
    }
    return $count;
}

function cliniq_backup_encryption_key(): string
{
    $configured = (string) env_value('BACKUP_ENCRYPTION_KEY', '');
    if (strlen($configured) < 32 || str_contains(strtolower($configured), 'replace-with')) {
        throw new RuntimeException('BACKUP_ENCRYPTION_KEY must contain at least 32 secret characters.');
    }
    return hash('sha256', $configured, true);
}

function cliniq_backup_encrypt_file(string $path): array
{
    $plaintext = file_get_contents($path);
    if ($plaintext === false) {
        throw new RuntimeException('Unable to read a backup payload for encryption.');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        CLINIQ_BACKUP_ENCRYPTION_CIPHER,
        cliniq_backup_encryption_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        '',
        16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Unable to encrypt a backup payload.');
    }

    $encryptedPath = $path . '.enc';
    $payload = CLINIQ_BACKUP_ENCRYPTION_MAGIC . $nonce . $tag . $ciphertext;
    if (file_put_contents($encryptedPath, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write an encrypted backup payload.');
    }
    @chmod($encryptedPath, 0600);
    if (!unlink($path)) {
        @unlink($encryptedPath);
        throw new RuntimeException('Unable to remove a plaintext backup payload after encryption.');
    }

    return [
        'encrypted_path' => basename($encryptedPath),
        'encrypted_bytes' => strlen($payload),
        'encrypted_sha256' => hash('sha256', $payload),
    ];
}

function cliniq_backup_decrypt_file(string $encryptedPath, string $destination): void
{
    $payload = file_get_contents($encryptedPath);
    $magicLength = strlen(CLINIQ_BACKUP_ENCRYPTION_MAGIC);
    if ($payload === false || strlen($payload) < $magicLength + 28) {
        throw new RuntimeException('Encrypted backup payload is truncated.');
    }
    if (!hash_equals(CLINIQ_BACKUP_ENCRYPTION_MAGIC, substr($payload, 0, $magicLength))) {
        throw new RuntimeException('Encrypted backup payload has an invalid header.');
    }
    $nonce = substr($payload, $magicLength, 12);
    $tag = substr($payload, $magicLength + 12, 16);
    $ciphertext = substr($payload, $magicLength + 28);
    $plaintext = openssl_decrypt(
        $ciphertext,
        CLINIQ_BACKUP_ENCRYPTION_CIPHER,
        cliniq_backup_encryption_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );
    if ($plaintext === false) {
        throw new RuntimeException('Encrypted backup authentication failed. The key or payload is invalid.');
    }
    if (file_put_contents($destination, $plaintext, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write a decrypted recovery payload.');
    }
    @chmod($destination, 0600);
}

function cliniq_backup_encrypt_payloads(string $staging, array $files): array
{
    $encrypted = [];
    foreach ($files as $file) {
        $relative = (string) $file['path'];
        $absolute = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $details = cliniq_backup_encrypt_file($absolute);
        $encryptedRelative = $relative . '.enc';
        $encrypted[] = array_merge($file, [
            'encrypted_path' => $encryptedRelative,
            'encrypted_bytes' => $details['encrypted_bytes'],
            'encrypted_sha256' => $details['encrypted_sha256'],
        ]);
    }
    return $encrypted;
}

function cliniq_backup_materialize_file(string $snapshotPath, array $manifest, string $relative): array
{
    if ((int) ($manifest['format_version'] ?? 1) < 2) {
        return [$snapshotPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), false];
    }
    $entry = null;
    foreach (($manifest['files'] ?? []) as $candidate) {
        if (($candidate['path'] ?? '') === $relative) {
            $entry = $candidate;
            break;
        }
    }
    if (!is_array($entry)) {
        throw new RuntimeException("Encrypted backup manifest does not contain {$relative}.");
    }
    $encryptedRelative = (string) ($entry['encrypted_path'] ?? '');
    if ($encryptedRelative === '' || str_contains($encryptedRelative, '..')) {
        throw new RuntimeException('Encrypted backup manifest contains an unsafe payload path.');
    }
    $temporary = tempnam(sys_get_temp_dir(), 'cliniq-recovery-');
    if ($temporary === false) {
        throw new RuntimeException('Unable to allocate temporary recovery storage.');
    }
    try {
        cliniq_backup_decrypt_file(
            $snapshotPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $encryptedRelative),
            $temporary
        );
        return [$temporary, true];
    } catch (Throwable $e) {
        @unlink($temporary);
        throw $e;
    }
}

function cliniq_backup_delete_tree(string $path, string $expectedParent): void
{
    $normalizedPath = strtolower(str_replace('/', '\\', rtrim($path, '\\/')));
    $normalizedParent = strtolower(str_replace('/', '\\', rtrim($expectedParent, '\\/'))) . '\\';
    if (!str_starts_with($normalizedPath . '\\', $normalizedParent) || !is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function cliniq_backup_apply_retention(string $directory, int $keep): void
{
    if (!is_dir($directory)) {
        return;
    }
    $snapshots = array_values(array_filter(glob($directory . DIRECTORY_SEPARATOR . 'CLINiQ_*') ?: [], 'is_dir'));
    usort($snapshots, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    foreach (array_slice($snapshots, $keep) as $expired) {
        cliniq_backup_delete_tree($expired, $directory);
    }
}

function cliniq_backup_manifest_files(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->getFilename() === 'manifest.json') {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        $files[] = [
            'path' => $relative,
            'bytes' => $item->getSize(),
            'sha256' => hash_file('sha256', $item->getPathname()),
        ];
    }
    usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
    return $files;
}

function cliniq_backup_today_exists(): bool
{
    $daily = cliniq_backup_root() . DIRECTORY_SEPARATOR . 'Daily';
    return (glob($daily . DIRECTORY_SEPARATOR . 'CLINiQ_daily_' . date('Y-m-d') . '_*') ?: []) !== [];
}

function cliniq_backup_history(int $limit = 12): array
{
    $root = cliniq_backup_root();
    $history = [];
    foreach (['Daily' => 'daily', 'Weekly' => 'weekly', 'Semester' => 'semester'] as $folder => $type) {
        foreach (glob($root . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'CLINiQ_*') ?: [] as $path) {
            if (!is_dir($path)) {
                continue;
            }
            $manifestPath = $path . DIRECTORY_SEPARATOR . 'manifest.json';
            $manifestContents = is_readable($manifestPath) ? @file_get_contents($manifestPath) : false;
            $manifest = $manifestContents !== false ? json_decode($manifestContents, true) : [];
            $history[] = [
                'type' => $type,
                'path' => $path,
                'name' => basename($path),
                'created_at' => $manifest['created_at'] ?? date(DATE_ATOM, filemtime($path)),
                'files' => (int) ($manifest['file_count'] ?? 0),
                'bytes' => (int) ($manifest['total_bytes'] ?? 0),
            ];
        }
    }
    usort($history, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
    return array_slice($history, 0, max(1, $limit));
}

function cliniq_paginate_backup_history(array $history, int $page = 1, int $perPage = 5): array
{
    $perPage = max(1, $perPage);
    $total = count($history);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $totalPages);

    return [
        'items' => array_slice($history, ($page - 1) * $perPage, $perPage),
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
    ];
}

function cliniq_backup_history_page(int $page = 1, int $perPage = 5): array
{
    return cliniq_paginate_backup_history(cliniq_backup_history(PHP_INT_MAX), $page, $perPage);
}

function cliniq_backup_run(string $type = 'daily', bool $force = false, bool $scheduled = false): array
{
    if (!in_array($type, ['daily', 'semester'], true)) {
        throw new InvalidArgumentException('Backup type must be daily or semester.');
    }
    if ($scheduled && (int) date('G') < CLINIQ_BACKUP_START_HOUR) {
        return ['state' => 'skipped', 'message' => 'The daily backup window begins at 8:00 AM.'];
    }
    if ($type === 'daily' && !$force && cliniq_backup_today_exists()) {
        return ['state' => 'skipped', 'message' => 'Today\'s backup is already complete.'];
    }

    $root = cliniq_backup_root();
    $previousStatus = cliniq_backup_status();
    foreach ([$root, $root . DIRECTORY_SEPARATOR . 'Daily', $root . DIRECTORY_SEPARATOR . 'Weekly', $root . DIRECTORY_SEPARATOR . 'Semester'] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create backup folder {$directory}.");
        }
    }

    $lock = fopen($root . DIRECTORY_SEPARATOR . '.backup.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another CLINiQ backup is already running.');
    }

    $startedAt = date(DATE_ATOM);
    $stamp = date('Y-m-d_His');
    $destinationParent = $root . DIRECTORY_SEPARATOR . ($type === 'semester' ? 'Semester' : 'Daily');
    $destination = $destinationParent . DIRECTORY_SEPARATOR . "CLINiQ_{$type}_{$stamp}";
    $staging = $root . DIRECTORY_SEPARATOR . '.staging_' . bin2hex(random_bytes(6));

    try {
        cliniq_backup_write_status([
            'state' => 'running',
            'message' => ucfirst($type) . ' backup is running.',
            'started_at' => $startedAt,
        ]);
        mkdir($staging, 0700, true);
        mkdir($staging . DIRECTORY_SEPARATOR . 'database', 0700, true);
        mkdir($staging . DIRECTORY_SEPARATOR . 'documents', 0700, true);

        $dbName = (string) env_value('AUTH_DB_NAME', 'Cliniq_db');
        $clientConfig = $staging . DIRECTORY_SEPARATOR . '.mysql-client.cnf';
        $clientSettings = "[client]\r\n"
            . 'host=' . cliniq_backup_mysql_option((string) env_value('DB_HOST', '127.0.0.1')) . "\r\n"
            . 'port=' . cliniq_backup_mysql_option((string) env_value('DB_PORT', '3306')) . "\r\n"
            . 'user=' . cliniq_backup_mysql_option((string) env_value('DB_USER', 'root')) . "\r\n"
            . 'password=' . cliniq_backup_mysql_option((string) env_value('DB_PASS', '')) . "\r\n";
        file_put_contents($clientConfig, $clientSettings, LOCK_EX);

        $dumpPath = $staging . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $dbName . '.sql';
        $command = [
            cliniq_backup_mysqldump_path(),
            '--defaults-extra-file=' . str_replace('\\', '/', $clientConfig),
            '--single-transaction',
            '--routines',
            '--events',
            '--triggers',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            $dbName,
        ];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $dumpPath, 'wb'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the database backup tool.');
        }
        fclose($pipes[0]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        unlink($clientConfig);
        if ($exitCode !== 0 || !is_file($dumpPath) || filesize($dumpPath) < 100) {
            throw new RuntimeException('Database backup failed. ' . trim((string) $errorOutput));
        }

        $projectRoot = dirname(__DIR__, 2);
        $documentCount = 0;
        $documentCount += cliniq_backup_copy_tree($projectRoot . '/storage/documents', $staging . '/documents/storage');
        $documentCount += cliniq_backup_copy_tree($projectRoot . '/public/uploads', $staging . '/documents/public-uploads');

        $db = auth_db();
        $tableCount = (int) $db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
        $apeDocumentRows = (int) $db->query('SELECT COUNT(*) FROM ape_documents')->fetchColumn();
        $configuration = [
            'application' => 'CLINiQ',
            'database' => $dbName,
            'timezone' => date_default_timezone_get(),
            'app_url_configured' => trim((string) env_value('APP_URL', '')) !== '',
            'patient_portal_url_configured' => trim((string) env_value('PATIENT_PORTAL_URL', '')) !== '',
            'secrets_included' => false,
        ];
        file_put_contents($staging . '/configuration.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $files = cliniq_backup_manifest_files($staging);
        $files = cliniq_backup_encrypt_payloads($staging, $files);
        $manifest = [
            'format_version' => 2,
            'application' => 'CLINiQ',
            'type' => $type,
            'created_at' => date(DATE_ATOM),
            'encryption' => CLINIQ_BACKUP_ENCRYPTION_CIPHER,
            'database' => $dbName,
            'database_tables' => $tableCount,
            'ape_document_rows' => $apeDocumentRows,
            'copied_document_files' => $documentCount,
            'file_count' => count($files),
            'total_bytes' => array_sum(array_column($files, 'bytes')),
            'encrypted_total_bytes' => array_sum(array_column($files, 'encrypted_bytes')),
            'files' => $files,
        ];
        file_put_contents($staging . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        if (!rename($staging, $destination)) {
            throw new RuntimeException('Unable to finalize the backup snapshot.');
        }

        $weeklyPath = null;
        if ($type === 'daily' && (int) date('N') === 7) {
            $weeklyPath = $root . DIRECTORY_SEPARATOR . 'Weekly' . DIRECTORY_SEPARATOR . "CLINiQ_weekly_{$stamp}";
            cliniq_backup_copy_tree($destination, $weeklyPath);
        }

        cliniq_backup_apply_retention($root . DIRECTORY_SEPARATOR . 'Daily', CLINIQ_BACKUP_DAILY_RETENTION);
        cliniq_backup_apply_retention($root . DIRECTORY_SEPARATOR . 'Weekly', CLINIQ_BACKUP_WEEKLY_RETENTION);
        $verified = cliniq_backup_verify($destination, false);
        $status = [
            'state' => 'success',
            'message' => ucfirst($type) . ' backup completed and verified.',
            'last_success_at' => $manifest['created_at'],
            'last_success_path' => $destination,
            'last_success_type' => $type,
            'last_verified_at' => $verified['verified_at'],
            'weekly_copy_path' => $weeklyPath,
            'file_count' => $manifest['file_count'],
            'total_bytes' => $manifest['total_bytes'],
        ];
        cliniq_backup_write_status($status);
        return $status;
    } catch (Throwable $e) {
        if (is_file($staging . DIRECTORY_SEPARATOR . '.mysql-client.cnf')) {
            unlink($staging . DIRECTORY_SEPARATOR . '.mysql-client.cnf');
        }
        cliniq_backup_delete_tree($staging, $root);
        cliniq_backup_write_status(array_merge($previousStatus, [
            'state' => 'error',
            'message' => $e->getMessage(),
            'failed_at' => date(DATE_ATOM),
        ]));
        throw $e;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function cliniq_backup_verify(?string $path = null, bool $updateStatus = true): array
{
    if ($path === null || trim($path) === '') {
        $history = cliniq_backup_history(1);
        $path = $history[0]['path'] ?? null;
    }
    if (!$path || !is_dir($path)) {
        throw new RuntimeException('No completed backup is available to verify.');
    }

    $rootPath = realpath(cliniq_backup_root());
    $candidatePath = realpath($path);
    if ($rootPath === false || $candidatePath === false) {
        throw new RuntimeException('The requested backup path could not be resolved.');
    }
    $rootPrefix = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $candidatePrefix = rtrim($candidatePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (PHP_OS_FAMILY === 'Windows') {
        $rootPrefix = strtolower($rootPrefix);
        $candidatePrefix = strtolower($candidatePrefix);
    }
    if (!str_starts_with($candidatePrefix, $rootPrefix)) {
        throw new RuntimeException('The requested backup is outside the configured backup folder.');
    }

    $manifestPath = $path . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifestContents = is_readable($manifestPath) ? @file_get_contents($manifestPath) : false;
    $manifest = $manifestContents !== false ? json_decode($manifestContents, true) : null;
    if (!is_array($manifest) || empty($manifest['files'])) {
        throw new RuntimeException('The backup manifest is missing or invalid.');
    }

    $encryptedFormat = (int) ($manifest['format_version'] ?? 1) >= 2;
    foreach ($manifest['files'] as $file) {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, (string) ($file['path'] ?? ''));
        if ($relative === '' || str_contains($relative, '..')) {
            throw new RuntimeException('The backup manifest contains an unsafe file path.');
        }
        if (!$encryptedFormat) {
            $absolute = $path . DIRECTORY_SEPARATOR . $relative;
            if (!is_file($absolute) || filesize($absolute) !== (int) $file['bytes'] || !hash_equals((string) $file['sha256'], hash_file('sha256', $absolute))) {
                throw new RuntimeException("Backup verification failed for {$relative}.");
            }
            continue;
        }

        $encryptedRelative = str_replace('/', DIRECTORY_SEPARATOR, (string) ($file['encrypted_path'] ?? ''));
        if ($encryptedRelative === '' || str_contains($encryptedRelative, '..')) {
            throw new RuntimeException('The encrypted backup manifest contains an unsafe file path.');
        }
        $encryptedAbsolute = $path . DIRECTORY_SEPARATOR . $encryptedRelative;
        if (
            !is_file($encryptedAbsolute)
            || filesize($encryptedAbsolute) !== (int) ($file['encrypted_bytes'] ?? -1)
            || !hash_equals((string) ($file['encrypted_sha256'] ?? ''), (string) hash_file('sha256', $encryptedAbsolute))
        ) {
            throw new RuntimeException("Encrypted backup verification failed for {$relative}.");
        }
        $temporary = tempnam(sys_get_temp_dir(), 'cliniq-verify-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to allocate temporary verification storage.');
        }
        try {
            cliniq_backup_decrypt_file($encryptedAbsolute, $temporary);
            if (filesize($temporary) !== (int) $file['bytes'] || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $temporary))) {
                throw new RuntimeException("Decrypted backup verification failed for {$relative}.");
            }
        } finally {
            @unlink($temporary);
        }
    }

    $result = [
        'state' => 'verified',
        'message' => 'Backup files and checksums are valid.',
        'path' => $path,
        'verified_at' => date(DATE_ATOM),
        'file_count' => count($manifest['files']),
        'total_bytes' => (int) ($manifest['total_bytes'] ?? 0),
    ];
    if ($updateStatus) {
        $status = cliniq_backup_status();
        $status['state'] = 'success';
        $status['message'] = $result['message'];
        $status['last_verified_at'] = $result['verified_at'];
        $status['last_verified_path'] = $path;
        cliniq_backup_write_status($status);
    }
    return $result;
}

function cliniq_backup_restore_test(?string $path = null): array
{
    $verification = cliniq_backup_verify($path, false);
    $path = $verification['path'];
    $manifestPath = $path . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifestContents = is_readable($manifestPath) ? @file_get_contents($manifestPath) : false;
    $manifest = $manifestContents !== false ? json_decode($manifestContents, true) : null;
    if (!is_array($manifest)) {
        throw new RuntimeException('The backup manifest is unreadable.');
    }
    $databaseName = (string) ($manifest['database'] ?? 'Cliniq_db');
    $dumpRelative = 'database/' . $databaseName . '.sql';
    [$dumpPath, $temporaryDump] = cliniq_backup_materialize_file($path, $manifest, $dumpRelative);
    if (!is_file($dumpPath)) {
        if ($temporaryDump) @unlink($dumpPath);
        throw new RuntimeException('The database dump is missing from the backup.');
    }
    $dumpHeader = @file_get_contents($dumpPath, false, null, 0, min(1048576, filesize($dumpPath)));
    if ($dumpHeader === false) {
        if ($temporaryDump) @unlink($dumpPath);
        throw new RuntimeException('The database dump cannot be read for restore verification.');
    }
    if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s+`?)/mi', $dumpHeader)) {
        if ($temporaryDump) @unlink($dumpPath);
        throw new RuntimeException('This backup uses the older database-bound dump format. Create a new backup before running a restore test.');
    }

    $testDatabase = 'cliniq_restore_verify_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $clientConfig = tempnam(sys_get_temp_dir(), 'cliniq-restore-');
    if ($clientConfig === false) {
        if ($temporaryDump) @unlink($dumpPath);
        throw new RuntimeException('Unable to create the temporary restore credential file.');
    }
    $clientSettings = "[client]\r\n"
        . 'host=' . cliniq_backup_mysql_option((string) env_value('DB_HOST', '127.0.0.1')) . "\r\n"
        . 'port=' . cliniq_backup_mysql_option((string) env_value('DB_PORT', '3306')) . "\r\n"
        . 'user=' . cliniq_backup_mysql_option((string) env_value('DB_USER', 'root')) . "\r\n"
        . 'password=' . cliniq_backup_mysql_option((string) env_value('DB_PASS', '')) . "\r\n";
    if (file_put_contents($clientConfig, $clientSettings, LOCK_EX) === false) {
        @unlink($clientConfig);
        if ($temporaryDump) @unlink($dumpPath);
        throw new RuntimeException('Unable to write the temporary restore credential file.');
    }
    @chmod($clientConfig, 0600);

    $db = auth_db();
    try {
        $db->exec("CREATE DATABASE `{$testDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $command = [
            cliniq_backup_mysql_path(),
            '--defaults-extra-file=' . str_replace('\\', '/', $clientConfig),
            '--default-character-set=utf8mb4',
            '--database=' . $testDatabase,
        ];
        $pipes = [];
        $process = proc_open($command, [0 => ['file', $dumpPath, 'rb'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the database restore test.');
        }
        $standardOutput = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Temporary database restore failed. ' . trim($errorOutput ?: $standardOutput));
        }

        $countStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $countStmt->execute([$testDatabase]);
        $restoredTables = (int) $countStmt->fetchColumn();
        $expectedTables = (int) ($manifest['database_tables'] ?? 0);
        if ($expectedTables < 1 || $restoredTables !== $expectedTables) {
            throw new RuntimeException("Temporary restore contains {$restoredTables} table(s); expected {$expectedTables}.");
        }

        $result = array_merge($verification, [
            'state' => 'restore_verified',
            'message' => "Backup restored successfully into a temporary database with {$restoredTables} table(s).",
            'restored_tables' => $restoredTables,
            'restore_tested_at' => date(DATE_ATOM),
        ]);
        $status = cliniq_backup_status();
        $status['state'] = 'success';
        $status['message'] = $result['message'];
        $status['last_verified_at'] = $result['restore_tested_at'];
        $status['last_verified_path'] = $path;
        cliniq_backup_write_status($status);
        return $result;
    } finally {
        try {
            $db->exec("DROP DATABASE IF EXISTS `{$testDatabase}`");
        } catch (Throwable $ignored) {
        }
        if (is_file($clientConfig)) {
            unlink($clientConfig);
        }
        if ($temporaryDump && is_file($dumpPath)) {
            unlink($dumpPath);
        }
    }
}

function cliniq_backup_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    foreach ($units as $index => $unit) {
        if ($value < 1024 || $index === count($units) - 1) {
            return number_format($value, $value >= 10 ? 1 : 2) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' B';
}
