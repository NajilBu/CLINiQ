<?php

declare(strict_types=1);

$workflow = file_get_contents(dirname(__DIR__) . '/app/services/CliniqVisitWorkflow.php');
$create = file_get_contents(dirname(__DIR__) . '/public/visits/create.php');
$view = file_get_contents(dirname(__DIR__) . '/public/visits/view.php');

foreach ([
    "cs.staff_role IN ('doctor', 'nurse')",
    "a.account_status = 'active'",
    'function cliniq_visit_attending_clinician_id',
    "audit_log_event('visits', 'visit_created', \$auditActorId",
] as $expected) {
    if (!str_contains((string) $workflow, $expected)) {
        throw new RuntimeException("Visit workflow is missing attending-clinician behavior: {$expected}");
    }
}

foreach ([$create, $view] as $formSource) {
    if (!str_contains((string) $formSource, 'name="attended_by_person_id"')
        || !str_contains((string) $formSource, 'Select doctor or nurse')) {
        throw new RuntimeException('Visit forms must require an attending doctor or nurse dropdown.');
    }
}

if (!str_contains((string) $create, "'recorded_by_person_id' => \$staffPersonId")
    || !str_contains((string) $create, "'attended_by_person_id' => \$attendingPersonId")
    || str_contains((string) $create, "'attended_by_person_id' => \$staffPersonId")) {
    throw new RuntimeException('The encoder and attending clinician must be stored separately.');
}

if (!str_contains((string) $view, 'cliniq_visit_attending_clinician_id')
    || !str_contains((string) $view, 'data-begin-assessment')) {
    throw new RuntimeException('Starting and updating a visit must validate the selected clinician.');
}

echo "Visit attending clinician checks passed. No database writes.\n";
