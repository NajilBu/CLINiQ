<?php

require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/AlertWorkflow.php';
ensure_alert_workflow_schema();

header('Content-Type: application/json');

$alerts = db()->query("
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
$summary = db()->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN risk_level = 'Critical' THEN 1 ELSE 0 END) AS critical_total
    FROM nurse_alerts
    WHERE status = 'Pending'
")->fetch();
$count = $summary['total'] ?? 0;
$criticalCount = $summary['critical_total'] ?? 0;

echo json_encode([
    'pending_count' => (int) $count,
    'critical_count' => (int) $criticalCount,
    'alerts' => $alerts,
]);
