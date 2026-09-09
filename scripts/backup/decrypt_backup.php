<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/BackupService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$snapshot = trim((string) ($argv[1] ?? ''));
$destination = trim((string) ($argv[2] ?? ''));
if ($snapshot === '' || $destination === '') {
    fwrite(STDERR, "Usage: php scripts/backup/decrypt_backup.php <snapshot-path> <new-output-folder>\n");
    exit(2);
}
if (file_exists($destination)) {
    fwrite(STDERR, "Recovery destination must not already exist.\n");
    exit(2);
}

try {
    $verification = cliniq_backup_verify($snapshot, false);
    $manifestPath = $verification['path'] . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
        throw new RuntimeException('Unable to create the recovery destination.');
    }

    foreach ($manifest['files'] as $file) {
        $relative = (string) ($file['path'] ?? '');
        if ($relative === '' || str_contains($relative, '..')) {
            throw new RuntimeException('Backup manifest contains an unsafe recovery path.');
        }
        $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException("Unable to create recovery folder {$parent}.");
        }
        [$source, $temporary] = cliniq_backup_materialize_file($verification['path'], $manifest, $relative);
        try {
            if (!copy($source, $target)) {
                throw new RuntimeException("Unable to recover {$relative}.");
            }
            @chmod($target, 0600);
        } finally {
            if ($temporary) @unlink($source);
        }
    }
    copy($manifestPath, $destination . DIRECTORY_SEPARATOR . 'manifest.json');
    echo json_encode([
        'state' => 'decrypted',
        'source' => $verification['path'],
        'destination' => $destination,
        'file_count' => count($manifest['files']),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[CLINiQ Backup Decrypt] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
