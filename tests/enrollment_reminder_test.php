<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/app/services/PatientEmail.php');
if ($source === false) {
    throw new RuntimeException('Patient email service must be readable.');
}

foreach ([
    "patient_email_automation_enabled('school_year_enrollment')",
    "e.enrollment_status = 'Pending Confirmation'",
    "a.account_status = 'active'",
    'INTERVAL 7 DAY',
    "'enrollment_confirmation'",
    "'reminder-' . (string) \$enrollment['academic_year'] . '-' . \$week",
] as $marker) {
    if (!str_contains($source, $marker)) {
        throw new RuntimeException("Enrollment reminder automation is missing {$marker}.");
    }
}

if (!str_contains($source, 'function patient_email_recipient(int $patientPersonId, bool $allowInactive = false)')
    || !str_contains($source, "\$eventType === 'student_re_enrollment' && !empty(\$event['allow_inactive_recipient'])")) {
    throw new RuntimeException('Only the final student deactivation event may email an inactive account.');
}

$authSource = file_get_contents(dirname(__DIR__) . '/app/helpers/auth.php');
if ($authSource === false || !str_contains($authSource, 'e.academic_year COLLATE utf8mb4_unicode_ci = s.academic_year COLLATE utf8mb4_unicode_ci')) {
    throw new RuntimeException('Current-year enrollment checks must compare academic years with one explicit collation.');
}

echo "Enrollment reminder coverage test passed.\n";
