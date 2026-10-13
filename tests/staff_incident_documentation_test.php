<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$create = file_get_contents($root . '/public/alerts/create.php');
$passport = file_get_contents($root . '/public/emergency.php');
$schema = file_get_contents($root . '/database/production_schema.sql');
$migration = file_get_contents($root . '/database/migrations/20261014_add_alert_record_source.sql');
$alerts = file_get_contents($root . '/public/alerts/index.php');
$view = file_get_contents($root . '/public/alerts/view.php');
$patientProfile = file_get_contents($root . '/public/patients/view.php');
$dashboard = file_get_contents($root . '/public/dashboard.php');
$monitor = file_get_contents($root . '/public/api/alerts.php');
$attention = file_get_contents($root . '/app/services/WorkflowAttention.php');
$reports = file_get_contents($root . '/app/services/SystemReport.php');
$audit = file_get_contents($root . '/app/services/AuditLog.php');

foreach (compact('create', 'passport', 'schema', 'migration', 'alerts', 'view', 'patientProfile', 'dashboard', 'monitor', 'attention', 'reports', 'audit') as $name => $source) {
    if ($source === false) {
        throw new RuntimeException("Unable to read {$name} source.");
    }
}

$checks = [
    'staff documentation route is restricted and CSRF protected' => str_contains($create, 'require_report_access();')
        && str_contains($create, 'name="_csrf"')
        && !str_contains($create, "status, resolution_report, resolved_by, resolved_at\n        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending'"),
    'documentation uses the existing patient resolver and rejects unmatched supplied IDs' => str_contains($create, 'cliniq_visit_patient_by_id_number($patientIdNumber, true)')
        && str_contains($create, 'No registered patient matches that ID number'),
    'documentation stores a completed resolved record with a required outcome' => str_contains($create, "record_source, patient_id")
        && str_contains($create, "'Staff documentation'")
        && str_contains($create, "'Resolved'")
        && str_contains($create, 'resolution_report')
        && str_contains($create, 'resolved_by, resolved_at')
        && str_contains($create, 'Accident / Response Report'),
    'passport reports remain explicit pending emergency alerts' => str_contains($passport, "'Passport'")
        && str_contains($passport, "'Pending'")
        && str_contains($passport, 'incident_report_id, record_source, patient_id'),
    'source is durable in migration and clean installs' => str_contains($migration, 'record_source VARCHAR(40) NOT NULL DEFAULT \'Legacy / unknown\'')
        && str_contains($migration, 'idx_nurse_alerts_source_created')
        && str_contains($schema, "record_source VARCHAR(40) NOT NULL DEFAULT 'Legacy / unknown'"),
    'documentation is visibly distinguishable in clinic and patient history' => str_contains($alerts, 'Document Incident')
        && str_contains($alerts, 'record_source')
        && str_contains($view, 'Staff Documented Incident')
        && str_contains($patientProfile, 'Staff documentation'),
    'documentation never enters pending live-alert consumers' => str_contains($dashboard, "WHERE status = 'Pending'")
        && str_contains($monitor, "WHERE status = 'Pending'")
        && str_contains($attention, "WHERE n.status NOT IN ('Resolved', 'Cancelled')"),
    'reports separate documentation from emergency alert totals' => str_contains($reports, 'Emergency Alerts')
        && str_contains($reports, 'Staff Documentation')
        && str_contains($reports, "record_source <> 'Staff documentation'")
        && str_contains($reports, "status = 'Pending' AND risk_level IN ('High', 'Critical')"),
    'documentation creation has an explicit audit action' => str_contains($create, "'incident_documented_resolved'")
        && str_contains($audit, "'incident_documented_resolved' => 'Documented a resolved incident'"),
];

$failures = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failures !== []) {
    fwrite(STDERR, "Staff incident documentation test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo 'Staff incident documentation test passed (' . count($checks) . " assertions).\n";
