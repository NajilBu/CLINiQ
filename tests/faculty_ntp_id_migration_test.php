<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migrationDirectory = $root . '/database/migrations';
$files = glob($migrationDirectory . '/*.sql') ?: [];
sort($files, SORT_STRING);
$names = array_map('basename', $files);

$roleMigration = array_search('20260915_student_only_ape_scheduling.sql', $names, true);
$finalMigration = array_search('20260916_finalize_faculty_ntp_numeric_ids.sql', $names, true);
if ($roleMigration === false || $finalMigration === false || $finalMigration <= $roleMigration) {
    throw new RuntimeException('The final Faculty/NTP ID migration must run after the personnel role normalization.');
}

$sql = file_get_contents($migrationDirectory . '/20260916_finalize_faculty_ntp_numeric_ids.sql');
foreach ([
    "role_classification IN ('Faculty', 'Non-Teaching Personnel')",
    "CONCAT('TEMP-EMP-', p.id)",
    'LPAD(CAST(@starting_number + ids.sequence_number AS UNSIGNED), 7, \'0\')',
] as $expected) {
    if ($sql === false || !str_contains($sql, $expected)) {
        throw new RuntimeException("The final Faculty/NTP ID migration is missing: {$expected}");
    }
}

echo "Faculty/NTP ID migration ordering test passed. No database writes.\n";
