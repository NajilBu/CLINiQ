<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$settingsService = file_get_contents($root . '/app/services/SystemSettings.php');
$settingsPage = file_get_contents($root . '/public/settings/index.php');
$accounts = file_get_contents($root . '/app/services/PatientAccountService.php');
$profile = file_get_contents($root . '/app/services/CliniqPatientProfile.php');
$visitor = file_get_contents($root . '/public/visitor-registration.php');
$ape = file_get_contents($root . '/public/ape/view.php');

if (in_array(false, [$settingsService, $settingsPage, $accounts, $profile, $visitor, $ape], true)) {
    throw new RuntimeException('Dropdown configuration sources must be readable.');
}

foreach (['person_category', 'student_section', 'employment_type', 'non_enrollment_reason', 'ape_inactive_reason', 'blood_type', 'guardian_relationship', 'visit_purpose', 'referral_type', 'inventory_return_condition', 'incident_type', 'incident_condition', 'incident_breathing', 'incident_bleeding', 'incident_pain_level', 'incident_mobility'] as $group) {
    if (!str_contains($settingsService, "'{$group}'")) {
        throw new RuntimeException("Missing configurable dropdown group: {$group}.");
    }
}
foreach (['settings_departments', 'settings_programs', 'save_settings_department', 'save_settings_program'] as $marker) {
    if (!str_contains($settingsService, $marker) || !str_contains($settingsPage, $marker)) {
        throw new RuntimeException("Academic reference management is missing {$marker}.");
    }
}
foreach (["'academic-references'", 'id="settings-academic-references"', 'data-dropdown-editor-grid', 'tab=academic-references'] as $marker) {
    if (!str_contains($settingsPage, $marker)) {
        throw new RuntimeException("Academic reference tab placement is missing {$marker}.");
    }
}
foreach (['department_created', 'department_updated', 'program_created', 'program_updated'] as $marker) {
    if (!str_contains($settingsService, $marker)) {
        throw new RuntimeException("Academic reference audit event is missing {$marker}.");
    }
}
foreach (["dropdown_options('student_section')", "dropdown_options('employment_type')"] as $marker) {
    if (!str_contains($accounts, $marker) || !str_contains($profile, $marker)) {
        throw new RuntimeException("Shared validation is missing {$marker}.");
    }
}
foreach (['cliniq_patient_profile_programs(false)', 'cliniq_patient_profile_departments(false)', '(inactive)', 'sectionIsCurrentInactiveValue', 'employmentTypeIsCurrentInactiveValue'] as $marker) {
    if (!str_contains($profile . $settingsPage, $marker) && !str_contains(file_get_contents($root . '/public/patients/edit.php') ?: '', $marker)) {
        throw new RuntimeException("Inactive reference preservation is missing {$marker}.");
    }
}
if (!str_contains($profile, "dropdown_options('guardian_relationship')") || !str_contains($settingsPage . file_get_contents($root . '/public/patients/edit.php'), 'relationshipOptions[]')) {
    throw new RuntimeException('Inactive guardian relationship preservation is missing.');
}
if (!str_contains($visitor, "dropdown_options('person_category')")
    || !str_contains($ape, "dropdown_options('ape_inactive_reason')")
    || !str_contains($settingsPage, 'System-controlled dropdowns stay fixed.')) {
    throw new RuntimeException('Dropdown consumers or protected-control guidance are missing.');
}
foreach (['normalize_visit_purpose', 'normalize_visit_referral_type'] as $marker) {
    if (!str_contains(file_get_contents($root . '/app/services/CliniqVisitWorkflow.php') ?: '', $marker)) {
        throw new RuntimeException("Visit dropdown validation is missing {$marker}.");
    }
}
if (str_contains($settingsService, "'department' => [") || str_contains($visitor, "dropdown_options('department')")) {
    throw new RuntimeException('Duplicate visitor department configuration remains editable.');
}
foreach (['student_program', 'appointment_purpose', 'ape_workflow_status', 'visit_status', 'visit_source'] as $orphan) {
    if (str_contains($settingsService, "'{$orphan}' => [")) {
        throw new RuntimeException("Orphaned or protected dropdown group remains editable: {$orphan}.");
    }
}

echo "Settings dropdown contract test passed.\n";
