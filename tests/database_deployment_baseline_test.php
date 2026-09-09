<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$schemaPath = $root . '/database/production_schema.sql';
$runnerPath = $root . '/scripts/database/migrate.php';
$envPath = $root . '/app/config/env.php';
$schema = file_get_contents($schemaPath);
$runner = file_get_contents($runnerPath);
$envLoader = file_get_contents($envPath);

if ($schema === false || $runner === false || $envLoader === false) {
    throw new RuntimeException('Production database deployment files must be readable.');
}

preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $schema, $matches);
$tables = array_values(array_unique($matches[1] ?? []));

foreach (['people', 'patients', 'visits', 'appointments', 'ape_records', 'ape_documents', 'clinic_feedback', 'login_attempts', 'schema_migrations'] as $table) {
    if (!in_array($table, $tables, true)) {
        throw new RuntimeException("Production baseline is missing {$table}.");
    }
}

if (count($tables) !== 34) {
    throw new RuntimeException('Expected 34 production tables including login_attempts and schema_migrations; found ' . count($tables) . '.');
}

$schemaBeforeTriggers = explode('DELIMITER //', $schema, 2)[0];
if (preg_match('/INSERT\s+INTO\s+(people|accounts|patients|visits|appointments|ape_records)\b/i', $schemaBeforeTriggers)) {
    throw new RuntimeException('Production baseline must not contain patient, staff, or transactional sample rows.');
}

if (!str_contains($runner, "'_rollback.sql'")) {
    throw new RuntimeException('Migration runner must exclude rollback scripts.');
}
if (!str_contains($runner, 'hash_file(\'sha256\'')) {
    throw new RuntimeException('Migration runner must checksum migrations.');
}
if (!str_contains($runner, 'Existing database is not a complete CLINiQ baseline')) {
    throw new RuntimeException('Migration runner must reject incomplete existing databases.');
}
if (!str_contains($runner, 'migration_post_baseline_tables')) {
    throw new RuntimeException('Existing databases must be checked before post-baseline tables are migrated.');
}
if (!str_contains($envLoader, 'getenv($key)')) {
    throw new RuntimeException('Container environment variables must override local .env values.');
}

echo "Database deployment baseline test passed ({$tables[0]} ... " . end($tables) . ").\n";
