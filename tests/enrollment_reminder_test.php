<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/app/services/PatientEmail.php');
if ($source === false) {
    throw new RuntimeException('Patient email service must be readable.');
}

foreach ([
    "patient_email_automation_enabled('school_year_enrollment')",
    "e.enrollment_status = 'Pending Confirmation'",
    "a.account_status = 'inactive'",
    'INTERVAL 7 DAY',
    "'enrollment_confirmation'",
    "'reminder-' . (string) \$enrollment['academic_year'] . '-' . \$week",
] as $marker) {
    if (!str_contains($source, $marker)) {
        throw new RuntimeException("Enrollment reminder automation is missing {$marker}.");
    }
}

echo "Enrollment reminder coverage test passed.\n";
