<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../app/services/SystemReport.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the system report service.');
}

$expectedSections = [
    'visits' => 'Visits',
    'ape' => 'APE Records',
    'inventory' => 'Inventory',
    'referrals' => 'Referrals',
    'alerts' => 'Alerts and Incidents',
    'feedback' => 'Clinic Feedback',
];

foreach ($expectedSections as $key => $title) {
    if (!str_contains($source, "\$sections['{$key}']") || !str_contains($source, "'title' => '{$title}'")) {
        throw new RuntimeException("Missing report section: {$title}.");
    }
}

echo "System report section contract passed.\n";
