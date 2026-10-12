<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/helpers/patient_category.php';

$categories = [
    'Student' => 'Students',
    'Faculty' => 'Faculty',
    'Non-Teaching Personnel' => 'NTP',
    'Staff' => 'Clinic Staff',
    'Other registered patient' => 'Guest',
    'Visitor / Guest' => 'Guest',
];

foreach ($categories as $storedValue => $displayValue) {
    if (patient_category_label($storedValue) !== $displayValue) {
        throw new RuntimeException("Unexpected category label for {$storedValue}.");
    }
}

echo "Patient category label contract passed.\n";
