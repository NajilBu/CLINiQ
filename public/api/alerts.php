<?php

require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/services/SystemSettings.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');

if (current_user() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$alerts = auth_db()->query("
    SELECT id, reporter_name, location, concern, incident_type, risk_level, risk_score, response_guidance, status, created_at
    FROM nurse_alerts
    WHERE status = 'Pending'
    ORDER BY
        CASE COALESCE(risk_level, 'Low')
            WHEN 'Critical' THEN 4
            WHEN 'High' THEN 3
            WHEN 'Moderate' THEN 2
            ELSE 1
        END DESC,
        COALESCE(risk_score, 0) DESC,
        created_at DESC,
        id DESC
    LIMIT 5
")->fetchAll();
$summary = auth_db()->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN risk_level = 'Critical' THEN 1 ELSE 0 END) AS critical_total
    FROM nurse_alerts
    WHERE status = 'Pending'
")->fetch();
$count = $summary['total'] ?? 0;
$criticalCount = $summary['critical_total'] ?? 0;
$pendingCount = (int) $count;
$latestAlert = $alerts[0] ?? null;
$latestAlertId = (int) ($latestAlert['id'] ?? 0);
$alertUrl = $pendingCount === 1 && $latestAlertId > 0
    ? app_url('alerts/view.php?id=' . $latestAlertId)
    : app_url('alerts/index.php?status=pending');
$clinicProfile = clinic_profile_settings();
$customAlertSoundPath = clinic_profile_alert_sound_path($clinicProfile);

echo json_encode([
    'pending_count' => $pendingCount,
    'critical_count' => (int) $criticalCount,
    'latest_alert_id' => $latestAlertId,
    'latest_alert' => $latestAlert,
    'alert_url' => $alertUrl,
    'alert_sound' => (string) ($clinicProfile['alert_sound'] ?? 'urgent-pulse'),
    'alert_sound_url' => $customAlertSoundPath !== '' ? app_url($customAlertSoundPath) : '',
    'checked_at' => gmdate(DATE_ATOM),
    'alerts' => $alerts,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
