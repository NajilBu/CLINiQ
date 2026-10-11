<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/public/api/brevo-webhook.php');
$migration = file_get_contents($root . '/database/migrations/20261010_brevo_email_delivery_webhooks.sql');
$integrityMigration = file_get_contents($root . '/database/migrations/20261010_brevo_delivery_integrity.sql');
$gateway = file_get_contents($root . '/docker/public-gateway.conf');

foreach (['endpoint' => $endpoint, 'migration' => $migration, 'integrity migration' => $integrityMigration, 'gateway' => $gateway] as $name => $source) {
    if ($source === false) {
        throw new RuntimeException("Unable to read {$name} source.");
    }
}

foreach (['BREVO_WEBHOOK_SECRET', 'HTTP_X_CLINIQ_BREVO_WEBHOOK', 'hash_equals', "'message-id'", "'ts_event'", "'delivered'", "'soft_bounce'", "'hard_bounce'", "'blocked'", "'complained'", 'delivery_event_at', 'provider_message_id', 'email_delivery_webhook'] as $required) {
    if (!str_contains($endpoint, $required)) {
        throw new RuntimeException("Brevo webhook protection or delivery mapping is missing {$required}.");
    }
}

foreach (["'deferred'", "'complained'"] as $required) {
    if (!str_contains($migration, $required)) {
        throw new RuntimeException("Delivery-state migration is missing {$required}.");
    }
}
foreach (["'blocked'", 'delivery_event_at', 'uq_email_queue_provider_message_id'] as $required) {
    if (!str_contains($integrityMigration, $required)) {
        throw new RuntimeException("Delivery integrity migration is missing {$required}.");
    }
}

if (!str_contains($gateway, 'location = /public/api/brevo-webhook.php')) {
    throw new RuntimeException('Public gateway does not expose the exact Brevo webhook route.');
}

echo "Brevo webhook structural test passed.\n";
