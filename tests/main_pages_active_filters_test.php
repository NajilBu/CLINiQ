<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pages = [
    'patients' => '/public/patients/index.php',
    'visits' => '/public/visits/index.php',
    'alerts' => '/public/alerts/index.php',
    'appointments' => '/public/appointments/index.php',
    'referrals' => '/public/referrals/index.php',
    'inventory' => '/public/inventory/index.php',
];

$failures = [];
foreach ($pages as $name => $path) {
    $source = file_get_contents($root . $path);
    if (stripos($source, 'Active Filters') !== false) {
        $failures[] = $name;
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'Active Filters summary remains on: ' . implode(', ', $failures) . "\n");
    exit(1);
}

echo 'Main-page Active Filters summaries removed from ' . count($pages) . " pages.\n";
