<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/public/api/alerts.php');
$layout = file_get_contents($root . '/app/helpers/view.php');
$script = file_get_contents($root . '/public/assets/js/app.js');
$styles = file_get_contents($root . '/public/assets/css/app.css');
$settings = file_get_contents($root . '/public/settings/index.php');
$systemSettings = file_get_contents($root . '/app/services/SystemSettings.php');
$electron = file_get_contents($root . '/electron/main.js');

foreach (compact('endpoint', 'layout', 'script', 'styles', 'settings', 'systemSettings', 'electron') as $name => $source) {
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
    "'alert_sound'",
    "'alert_sound_url'",
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
    'data-alert-sound=',
    'data-alert-sound-url=',
] as $expected) {
    if (!str_contains($layout, $expected)) {
        throw new RuntimeException("The shared clinic header is missing a live alert control: {$expected}");
    }
}

foreach ([
    'function initContinuousAlertMonitor()',
    'window.setInterval(refreshAlerts, 3000)',
    "document.addEventListener('visibilitychange'",
    "window.addEventListener('online', refreshAlerts)",
    "cliniqSaveAlertPreference('cliniqMutedAlertId'",
    'latestAlertId > mutedAlertId',
    'function cliniqStartAlertAlarm()',
    'function cliniqStopAlertAlarm()',
    'function cliniqCreateAlertLoopBuffer(context,',
    "function cliniqPreviewAlertSound(soundId, customUrl = '')",
    'function cliniqStopAlertSoundPreview()',
    'function initCustomAlertSoundUpload()',
    'new Audio(cliniqAlertMonitor.soundUrl)',
    "audio.loop = true",
    'URL.createObjectURL(file)',
    "'double-chime'",
    "'rapid-siren'",
    'source.loop = true',
    "if (!response.ok) throw new Error",
] as $expected) {
    if (!str_contains($script, $expected)) {
        throw new RuntimeException("Continuous alert polling or alarm behavior is missing: {$expected}");
    }
}

foreach ([
    'clinic_alert_sound_options()',
    'data-preview-alert-sound',
    'name="alert_sound"',
    'name="alert_sound_file"',
    'data-stop-alert-sound-preview',
    'name="reset_alert_sound"',
] as $expected) {
    if (!str_contains($settings, $expected)) {
        throw new RuntimeException("Clinic Profile is missing an alert-sound control: {$expected}");
    }
}

foreach ([
    "'alert_sound' => 'urgent-pulse'",
    "'double-chime' => 'Double Chime'",
    "'rapid-siren' => 'Rapid Siren'",
    "'custom_alert_sound_path' => ''",
    'function save_uploaded_clinic_alert_sound(array $file)',
    "'audio/mpeg' => 'mp3'",
    "'audio/ogg' => 'ogg'",
] as $expected) {
    if (!str_contains($systemSettings, $expected)) {
        throw new RuntimeException("Clinic alert-sound settings are incomplete: {$expected}");
    }
}

if (!str_contains($electron, "app.commandLine.appendSwitch('autoplay-policy', 'no-user-gesture-required')")) {
    throw new RuntimeException('Electron does not permit automatic emergency alert playback.');
}

if (!str_contains($styles, '.app-alert-sound-toggle')
    || !str_contains($styles, '.app-alert-connection')) {
    throw new RuntimeException('Continuous alert controls are missing their shared-shell styles.');
}

echo "Continuous alert monitor test passed. No database writes.\n";
