<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'public/ape/create.php', 'public/feedback/index.php', 'public/inventory/create.php',
    'public/inventory/index.php', 'public/inventory/update.php', 'public/patient-accounts/index.php',
    'public/patients/edit.php', 'public/patients/emergency_profile.php', 'public/settings/index.php',
    'public/visits/create.php', 'public/visits/emergency_create.php', 'public/visits/view.php',
    'public/visitor-registration.php',
];

foreach ($paths as $path) {
    $source = file_get_contents($root . '/' . $path);
    if ($source === false) throw new RuntimeException('Unreadable source: ' . $path);
    foreach (['Cliniq_db', 'in the database', 'database migration', 'server error log'] as $term) {
        if (stripos($source, $term) !== false) throw new RuntimeException($path . ' still exposes technical wording: ' . $term);
    }
}

echo "User-facing message language tests passed.\n";
