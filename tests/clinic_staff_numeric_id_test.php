<?php

declare(strict_types=1);

$service = file_get_contents(__DIR__ . '/../app/services/SystemSettings.php');
$settings = file_get_contents(__DIR__ . '/../public/settings/index.php');
$login = file_get_contents(__DIR__ . '/../public/login.php');
$migration = file_get_contents(__DIR__ . '/../database/migrations/20260918_normalize_clinic_staff_numeric_ids.sql');

$checks = [
    'staff create and update require seven digits' => substr_count($service, "preg_match('/^[0-9]{7}$/', \$idNumber)") === 2,
    'next staff ID considers all seven-digit people IDs' => str_contains($service, "WHERE id_number REGEXP '^[0-9]{7}$'"),
    'next staff ID is padded to seven digits' => str_contains($service, "str_pad((string) \$next, 7, '0', STR_PAD_LEFT)"),
    'staff settings fields require seven digits' => substr_count($settings, 'pattern="[0-9]{7}"') === 2,
    'staff login requires seven digits' => str_contains($login, 'pattern="[0-9]{7}"'),
    'migration targets clinic staff only' => str_contains($migration, 'JOIN clinic_staff cs ON cs.person_id = p.id'),
    'migration preserves existing numeric staff IDs' => str_contains($migration, "WHERE p.id_number NOT REGEXP '^[0-9]{7}$'"),
    'migration allocates after every existing numeric ID' => str_contains($migration, 'MAX(CAST(p.id_number AS UNSIGNED))'),
    'migration uses temporary IDs before final unique values' => str_contains($migration, "CONCAT('TEMP-STAFF-', p.id)"),
    'migration refuses to exceed seven digits' => str_contains($migration, 'CHECK (highest_required_id <= 9999999)'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) throw new RuntimeException('Failed: ' . $label);
}

echo 'Clinic staff numeric ID tests passed (' . count($checks) . " assertions).\n";
