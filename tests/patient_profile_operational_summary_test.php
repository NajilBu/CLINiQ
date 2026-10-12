<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$profile = file_get_contents($root . '/public/patients/view.php');
$emergency = file_get_contents($root . '/public/emergency.php');
$migration = file_get_contents($root . '/database/migrations/20261013_link_incident_reports_to_alerts.sql');
$productionSchema = file_get_contents($root . '/database/production_schema.sql');

function patient_profile_operational_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

patient_profile_operational_assert(str_contains((string) $profile, '$linkedVisitAppointmentIds'), 'Completed appointments linked to visits must be excluded from duplicate care events.');
patient_profile_operational_assert(str_contains((string) $profile, 'Care Records') && str_contains((string) $profile, 'Latest Care'), 'Overview must describe distinct care records rather than visits alone.');
patient_profile_operational_assert(str_contains((string) $profile, 'Open referrals') && str_contains((string) $profile, '$openReferrals'), 'Overview referrals must count only unresolved records.');
patient_profile_operational_assert(str_contains((string) $profile, 'data-open-care-items') && str_contains((string) $profile, 'profile-panel-attention'), 'Needs Attention must open the student-specific action panel.');
patient_profile_operational_assert(str_contains((string) $profile, "['Pending', 'For Confirmation']") && str_contains((string) $profile, '$isPastScheduled'), 'Future scheduled appointments must stay outside Needs Attention.');
patient_profile_operational_assert(str_contains((string) $profile, 'Completed appointment — no encounter note recorded'), 'Standalone completed appointments need an explicit missing-encounter label.');
patient_profile_operational_assert(str_contains((string) $profile, 'Portal Account:') && str_contains((string) $profile, 'Recorded Conditions') && str_contains((string) $profile, 'Recorded Blood Type'), 'Profile labels must not claim unsupported provenance.');
patient_profile_operational_assert(str_contains((string) $profile, 'Primary:') && str_contains((string) $profile, 'Backup:'), 'Clinical contact details must distinguish primary and backup contacts.');
patient_profile_operational_assert(str_contains((string) $emergency, 'incident_report_id') && str_contains((string) $migration, 'incident_report_id'), 'Emergency reports must persist an alert link through the approved migration.');
patient_profile_operational_assert(
    strpos((string) $productionSchema, 'CREATE TABLE incident_reports') < strpos((string) $productionSchema, 'fk_nurse_alerts_incident_report'),
    'The clean-install schema must create incident reports before the nurse-alert foreign key.'
);

echo "Patient profile operational summary checks passed.\n";
