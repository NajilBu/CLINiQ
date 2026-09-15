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
    'docker/public-gateway.conf',
    'docker/proxy_params',
    'scripts/backup/container_scheduler.sh',
    'scripts/backup/container_restore_test.php',
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
$scheduler = file_get_contents($root . '/scripts/backup/container_scheduler.sh');
$gateway = file_get_contents($root . '/docker/public-gateway.conf');
$dockerignore = file_get_contents($root . '/.dockerignore');

foreach (['cliniq_database', 'cliniq_documents', 'cliniq_uploads', 'cliniq_backups', 'service_healthy', 'container_scheduler.sh', 'profiles: ["maintenance"]', 'container_restore_test.php'] as $expected) {
    if (!str_contains($compose, $expected)) {
        throw new RuntimeException("Compose configuration is missing {$expected}.");
    }
}
foreach (['profiles: ["public-portal"]', 'cloudflare/cloudflared', '--token-file /etc/cloudflared/tunnel-token', 'public-gateway.conf'] as $expected) {
    if (!str_contains($compose, $expected)) {
        throw new RuntimeException("Compose permanent tunnel configuration is missing {$expected}.");
    }
}
foreach (['cloudflared-quick:', 'profiles: ["quick-tunnel"]', '--url http://gateway:8081'] as $expected) {
    if (!str_contains($compose, $expected)) {
        throw new RuntimeException("Compose temporary tunnel configuration is missing {$expected}.");
    }
}
$quickTunnelSection = explode('  database:', explode('  cloudflared-quick:', $compose, 2)[1] ?? '', 2)[0];
if (str_contains($quickTunnelSection, 'tunnel-token') || str_contains($quickTunnelSection, './docker/cloudflared')) {
    throw new RuntimeException('The temporary tunnel must not mount or use the production tunnel token.');
}
if (!str_contains($quickTunnelSection, 'restart: "no"')) {
    throw new RuntimeException('The temporary tunnel must not restart automatically.');
}
if (!str_contains($compose, '127.0.0.1:8081:80') || !str_contains($gateway, 'listen 8081;')) {
    throw new RuntimeException('The team branch must use app port 8081 and gateway port 8081.');
}
foreach (['cliniq-dev*.sql', 'docker/cloudflared/', 'external-backups/', 'uploads/'] as $sensitivePath) {
    if (!str_contains($dockerignore, $sensitivePath)) {
        throw new RuntimeException("Docker build context must exclude {$sensitivePath}.");
    }
}
foreach (['absolute_redirect off', '/patient-portal/', '/public/emergency.php', '/public/assets/', '/public/uploads/settings/', 'return 404'] as $expected) {
    if (!str_contains($gateway, $expected)) {
        throw new RuntimeException("Public gateway allowlist is missing {$expected}.");
    }
}
foreach (['/public/login.php', '/public/visitor-registration.php', '/public/settings/'] as $forbiddenPublicRoute) {
    if (str_contains($gateway, $forbiddenPublicRoute)) {
        throw new RuntimeException("Public gateway must not expose {$forbiddenPublicRoute}.");
    }
}
if (substr_count($compose, 'MARIADB_ROOT_PASSWORD: ""') !== 2) {
    throw new RuntimeException('The web and scheduler containers must not receive the MariaDB root password.');
}
if (!str_contains($backup, "tempnam(sys_get_temp_dir(), 'cliniq-restore-')")) {
    throw new RuntimeException('Restore credentials must be created in temporary storage, not the backup volume.');
}
foreach (['pdo_mysql', 'default-mysql-client', 'cliniq-entrypoint'] as $expected) {
    if (!str_contains($dockerfile, $expected)) {
        throw new RuntimeException("Docker image is missing {$expected}.");
    }
}
if (!str_contains($entrypoint, 'scripts/database/migrate.php')) {
    throw new RuntimeException('Container startup must run database migrations before Apache.');
}
foreach (['app|database|docker|electron|scripts|storage|student|tests|tools|tmp|outputs', 'Require all denied'] as $expected) {
    if (!str_contains($apache, $expected)) {
        throw new RuntimeException('Apache does not protect application-only directories.');
    }
}
foreach (['student', 'database/schema.sql', 'database/seed_*.php', 'database/seeder.php'] as $expected) {
    if (!str_contains($dockerignore, $expected)) {
        throw new RuntimeException("Production image exclusions are missing {$expected}.");
    }
}
foreach (['/var/backups/cliniq', '/usr/bin/mariadb-dump', 'DIRECTORY_SEPARATOR'] as $expected) {
    if (!str_contains($backup, $expected)) {
        throw new RuntimeException("Backup service is not container-compatible: {$expected}");
    }
}
if (!str_contains($electron, "DEFAULT_CLINIC_URL = 'http://localhost:8081/public/'")) {
    throw new RuntimeException('Electron must connect to the local Docker application port.');
}
if (!str_contains($electron, "new URL('../patient-portal/', clinicBaseUrl)")) {
    throw new RuntimeException('Patient portal links must remain browser-only in Electron.');
}
if (str_contains($electron, 'CLINiQ Daily Backup') || str_contains($electron, 'schtasks.exe')) {
    throw new RuntimeException('Docker Electron must not invoke the legacy XAMPP backup task.');
}
foreach (['--scheduled', 'sleep 300', '8:00 AM'] as $expected) {
    if (!str_contains($scheduler, $expected)) {
        throw new RuntimeException("Container backup scheduler is missing {$expected}.");
    }
}

echo "Docker deployment file test passed. No containers were started.\n";
