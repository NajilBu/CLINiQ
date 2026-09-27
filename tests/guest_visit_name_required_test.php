<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$publicForm = file_get_contents($root . '/public/visitor-registration.php');
$staffForm = file_get_contents($root . '/public/visits/create.php');
$workflow = file_get_contents($root . '/app/services/CliniqVisitWorkflow.php');

if (!is_string($publicForm) || !is_string($staffForm) || !is_string($workflow)) {
    throw new RuntimeException('Guest visit source files could not be read.');
}

if (!str_contains($publicForm, "\$requiredFields = \$isGuest ? ['full_name', 'reason']")
    || !preg_match('/id="full_name"[^\r\n]*\brequired>/', $publicForm)
    || !str_contains($publicForm, 'fullName.required = true;')) {
    throw new RuntimeException('Public guest registration must require a name in the form and on submission.');
}

if (!str_contains($staffForm, 'if ($guestName === null)')
    || !str_contains($staffForm, 'patientNameDisplay.required = isGuest;')) {
    throw new RuntimeException('Staff-recorded guest visits must require a name.');
}

if (!str_contains($workflow, 'if ($patientPersonId === null && $guestName === null)')) {
    throw new RuntimeException('The shared visit workflow must reject nameless guest visits.');
}

echo "Guest visit name requirement checks passed. No database writes.\n";
