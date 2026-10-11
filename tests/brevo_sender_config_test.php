<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$settings = file_get_contents($root . '/app/services/SystemSettings.php');
$migration = file_get_contents($root . '/database/migrations/20261011_switch_university_brevo_sender.sql');
$envExample = file_get_contents($root . '/.env.example');

foreach ([$settings, $migration, $envExample] as $source) {
    if ($source === false) {
        throw new RuntimeException('Unable to read Brevo sender configuration sources.');
    }
}

foreach (['plphealthservices@plpuhs.qd.je', 'plpuhs2026@gmail.com', "'from_name'  => 'University Health Services'"] as $expected) {
    if (!str_contains($settings, $expected)) {
        throw new RuntimeException("System mail defaults are missing {$expected}.");
    }
}
foreach (["setting_key = 'mail.smtp'", "'$.from_email'", "'$.reply_to_email'", 'JSON_VALID(setting_value)'] as $expected) {
    if (!str_contains($migration, $expected)) {
        throw new RuntimeException("Brevo sender migration is missing {$expected}.");
    }
}
foreach (['MAIL_FROM=plphealthservices@plpuhs.qd.je', 'MAIL_REPLY_TO=plpuhs2026@gmail.com'] as $expected) {
    if (!str_contains($envExample, $expected)) {
        throw new RuntimeException("Environment fallback is missing {$expected}.");
    }
}

echo "Brevo sender configuration test passed.\n";
