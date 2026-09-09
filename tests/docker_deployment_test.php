<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$requiredFiles = [
    'Dockerfile',
    'compose.yaml',
    '.dockerignore',
    'docker/apache-cliniq.conf',
    'docker/php-production.ini',
    'docker/entrypoint.sh',
    'docker/.env.example',
    'public/api/health.php',
];

foreach ($requiredFiles as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException("Missing Docker deployment file: {$relative}");
    }
}

$compose = file_get_contents($root . '/compose.yaml');
$dockerfile = file_get_contents($root . '/Dockerfile');
$apache = file_get_contents($root . '/docker/apache-cliniq.conf');
$entrypoint = file_get_contents($root . '/docker/entrypoint.sh');
$backup = file_get_contents($root . '/app/services/BackupService.php');
$electron = file_get_contents($root . '/electron/main.js');

foreach (['cliniq_database', 'cliniq_documents', 'cliniq_uploads', 'cliniq_backups', 'service_healthy'] as $expected) {
    if (!str_contains($compose, $expected)) {
        throw new RuntimeException("Compose configuration is missing {$expected}.");
    }
}
foreach (['pdo_mysql', 'default-mysql-client', 'cliniq-entrypoint'] as $expected) {
    if (!str_contains($dockerfile, $expected)) {
        throw new RuntimeException("Docker image is missing {$expected}.");
    }
}
if (!str_contains($entrypoint, 'scripts/database/migrate.php')) {
    throw new RuntimeException('Container startup must run database migrations before Apache.');
}
foreach (['app|database|docker|electron|scripts|storage|tests|tools|tmp|outputs', 'Require all denied'] as $expected) {
    if (!str_contains($apache, $expected)) {
        throw new RuntimeException('Apache does not protect application-only directories.');
    }
}
foreach (['/var/backups/cliniq', '/usr/bin/mariadb-dump', 'DIRECTORY_SEPARATOR'] as $expected) {
    if (!str_contains($backup, $expected)) {
        throw new RuntimeException("Backup service is not container-compatible: {$expected}");
    }
}
if (!str_contains($electron, "DEFAULT_CLINIC_URL = 'http://localhost:8080/public/'")) {
    throw new RuntimeException('Electron must connect to the local Docker application port.');
}
if (!str_contains($electron, "new URL('../patient-portal/', clinicBaseUrl)")) {
    throw new RuntimeException('Patient portal links must remain browser-only in Electron.');
}

echo "Docker deployment file test passed. No containers were started.\n";
