<?php

declare(strict_types=1);

$dashboard = file_get_contents(dirname(__DIR__) . '/public/dashboard.php');
$availability = file_get_contents(dirname(__DIR__) . '/public/appointments/_availability_section.php');

foreach (['Sofia Bautista', 'General consultation', "'_placeholder' => true", '>Sample<'] as $placeholder) {
    if (str_contains($dashboard, $placeholder)) {
        throw new RuntimeException("The dashboard still includes the sample scheduling placeholder: {$placeholder}");
    }
}

foreach (['Staff Meeting', 'Clinic Maintenance', 'Campus Event', "'_placeholder' => true", '>Sample<'] as $placeholder) {
    if (str_contains($availability, $placeholder)) {
        throw new RuntimeException("Appointment availability still includes the sample placeholder: {$placeholder}");
    }
}

if (!str_contains($dashboard, 'appointment_ape_batches_for_range')) {
    throw new RuntimeException('Real scheduled APE batches must remain on the dashboard timeline.');
}
if (!str_contains($availability, 'appointment_ape_batches_for_range')) {
    throw new RuntimeException('Real scheduled APE batches must remain on appointment availability.');
}

echo "Scheduling placeholder test passed. No database writes.\n";
