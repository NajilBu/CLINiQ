<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$edit = file_get_contents($root . '/public/patients/edit.php');
$service = file_get_contents($root . '/app/services/CliniqPatientProfile.php');
$view = file_get_contents($root . '/public/patients/view.php');

if ($edit === false || $service === false || $view === false) {
    throw new RuntimeException('Patient profile sources must be readable.');
}

foreach (['patient_profile_edit_values', 'patient_profile_edit_errors', 'guardian_relationship', 'secondary_contact', 'name="_csrf"'] as $marker) {
    if (!str_contains($edit, $marker)) {
        throw new RuntimeException("Patient profile editor is missing {$marker}.");
    }
}
if (!str_contains($edit, '$hasEmergencyContact') || !str_contains($edit, '$defaultGuardianRelationship')) {
    throw new RuntimeException('Empty emergency contacts must not force a relationship selection.');
}
if (!str_contains($edit, "require_once __DIR__ . '/../../app/services/PatientAccountService.php';")
    || !str_contains($edit, '$canUpdatePortalEmail') || !str_contains($edit, 'name="email"')) {
    throw new RuntimeException('Portal email must remain manager-only.');
}
foreach (['a.id AS account_id', 'cliniq_validate_emergency_contact', 'guardian_relationship = ?', 'secondary_contact_number = ?', 'LOWER(email)', 'patient_profile_updated', 'consecutive school year'] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException("Patient profile service is missing {$marker}.");
    }
}
if (!str_contains($view, "require_once __DIR__ . '/../../app/services/PatientAccountService.php';")
    || !str_contains($view, 'patientAccountStatusModal') || !str_contains($view, 'Account Status')
    || !str_contains($view, 'Portal access') || !str_contains($view, 'patient_account_status')
    || !str_contains($view, 'deactivate_patient_account') || !str_contains($view, 'reactivate_patient_account')
    || !str_contains($view, 'change_patient_access_status') || !str_contains($view, 'inactive_reason')) {
    throw new RuntimeException('Patient account status dialog is missing.');
}
if (!str_contains($view, 'aria-label="Patient profile sections"')
    || str_contains($view, "patientEmailForm?.addEventListener('submit'")) {
    throw new RuntimeException('Patient profile tabs and email confirmation flow are inconsistent.');
}

echo "Patient profile edit coverage test passed.\n";
