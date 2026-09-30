<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../patient-portal/patient-ape-status.php');
foreach ([
    '$historyLimit = 5;',
    "'Assigned APE schedule batch' => ['APE examination scheduled', 'calendar_month']",
    "'Uploaded APE documents' => ['You submitted APE documents', 'upload_file']",
    "'Added follow-up document requirement' => ['The clinic requested a follow-up document', 'assignment']",
    "'Required follow-up after APE examination' => ['The clinic requested follow-up after your examination', 'medical_information']",
    "'Archived APE documents' => ['Documents received by the clinic', 'folder_check']",
    "'Cleared patient after APE examination' => ['APE cleared', 'verified']",
    '<summary>APE updates</summary>',
    '<h2 class="student-card-title">APE history</h2>',
] as $expected) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException('Student APE activity timeline must retain plain-language, compact updates.');
    }
}
foreach (['<?= count($allActivities) ?> Event(s)', 'aria-live="polite"'] as $unexpected) {
    if (str_contains($source, $unexpected)) {
        throw new RuntimeException('Student APE activity timeline must not expose staff-oriented event noise.');
    }
}

echo "Student APE activity timeline checks passed. No database writes.\n";
