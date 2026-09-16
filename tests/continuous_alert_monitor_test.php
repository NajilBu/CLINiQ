<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/public/api/alerts.php');
$layout = file_get_contents($root . '/app/helpers/view.php');
$script = file_get_contents($root . '/public/assets/js/app.js');
$styles = file_get_contents($root . '/public/assets/css/app.css');

foreach (compact('endpoint', 'layout', 'script', 'styles') as $name => $source) {
    if ($source === false) {
        throw new RuntimeException("Unable to read continuous alert monitor source: {$name}.");
    }
}

foreach ([
    "current_user() === null",
    "http_response_code(401)",
    "Cache-Control: no-store",
    "WHERE status = 'Pending'",
    "'latest_alert_id'",
    "'alert_url'",
] as $expected) {
    if (!str_contains($endpoint, $expected)) {
        throw new RuntimeException("The live alert endpoint is incomplete: {$expected}");
    }
}

foreach ([
    'data-live-alert-link',
    'data-alert-sound-toggle',
    'data-alert-connection-status',
    'id="pending-alert-count"',
] as $expected) {
    if (!str_contains($layout, $expected)) {
        throw new RuntimeException("The shared clinic header is missing a live alert control: {$expected}");
    }
}

foreach ([
    'function initContinuousAlertMonitor()',
    'window.setInterval(refreshAlerts, 10000)',
    "document.addEventListener('visibilitychange'",
    "window.addEventListener('online', refreshAlerts)",
    "cliniqSaveAlertPreference('cliniqAlertSoundMuted'",
    'function cliniqStartAlertAlarm()',
    'function cliniqStopAlertAlarm()',
    'function cliniqCreateAlertLoopBuffer(context)',
    'source.loop = true',
    "if (!response.ok) throw new Error",
] as $expected) {
    if (!str_contains($script, $expected)) {
        throw new RuntimeException("Continuous alert polling or alarm behavior is missing: {$expected}");
    }
}

if (!str_contains($styles, '.app-alert-sound-toggle')
    || !str_contains($styles, '.app-alert-connection')) {
    throw new RuntimeException('Continuous alert controls are missing their shared-shell styles.');
}

echo "Continuous alert monitor test passed. No database writes.\n";
