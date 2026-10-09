<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-appointment.php');

foreach ([
    "AND status IN ('Pending', 'Scheduled', 'For Confirmation')",
    "WHEN 'Scheduled' THEN 1",
    "WHEN 'Pending' THEN 2",
    "WHEN 'For Confirmation' THEN 3",
    "AND status NOT IN ('Pending', 'Scheduled', 'For Confirmation')",
    'appointment-history-active',
    'Active bookings',
    'Recent history',
    'Awaiting clinic approval. Please do not visit the clinic until your request is confirmed.',
    'Clinic staff are recording the visit outcome.',
    '<details class="student-appointment-cancel">',
    'Confirm cancellation',
] as $expected) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException('Appointment history redesign is missing: ' . $expected);
    }
}

if (strpos($source, 'appointment-history-active') > strpos($source, 'Recent history')) {
    throw new RuntimeException('Active bookings must appear before recent history.');
}

echo "Patient appointment history checks passed. No database writes.\n";
