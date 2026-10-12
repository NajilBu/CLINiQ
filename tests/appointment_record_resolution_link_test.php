<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/public/appointments/view.php');

if ($source === false
    || !str_contains($source, "'Pending' => [")
    || !str_contains($source, "'For Confirmation' => [")
    || !str_contains($source, 'appointments/index.php?status=Pending')
    || !str_contains($source, 'appointments/index.php?status=For%20Confirmation')
    || !str_contains($source, 'Next step')) {
    throw new RuntimeException('Appointment records must direct actionable statuses to the existing resolution queue.');
}

echo "Appointment record resolution-link checks passed.\n";
