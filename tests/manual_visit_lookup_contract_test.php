<?php

declare(strict_types=1);

$create = file_get_contents(__DIR__ . '/../public/visits/create.php');
$workflow = file_get_contents(__DIR__ . '/../app/services/CliniqVisitWorkflow.php');
$failures = [];

$checks = [
    'lookup stays behind authentication' => strpos($create, 'require_login();') < strpos($create, "isset(\$_GET['lookup_identifier'])"),
    'lookup uses the shared exact-first compact-ID resolver' => str_contains($create, 'cliniq_visit_patient_by_id_number($identifier, true)'),
    'saving uses the same resolver' => str_contains($create, 'cliniq_visit_patient_by_id_number($postedStudentNumber, true)'),
    'lookup returns the resolver canonical ID' => str_contains($create, "'id_number' => \$patient['id_number']"),
    'browser does not rebuild a conflicting ID map' => !str_contains($create, 'visitPatientsByStudentNumber'),
    'profile preselection is independent of the suggestion limit' => str_contains($create, "cliniq_visit_patients('', 1, \$preselectedPatientId)"),
    'shared resolver returns the displayed sex value' => str_contains($workflow, 'pe.last_name, pe.sex,'),
    'save uses a normal POST instead of in-page AJAX' => str_contains($create, 'id="visitForm" class="space-y-6" data-no-ajax="true"'),
    'save redirects to the completed visit list with a success message' => str_contains($create, "flash_message('success', 'Manual visit recorded as completed.')")
        && str_contains($create, "header('Location: index.php?status=Completed')"),
];

foreach ($checks as $label => $passes) {
    if (!$passes) {
        $failures[] = $label;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Manual visit lookup contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Manual visit lookup contract passed.\n";
