<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/GraduationService.php';

if (graduation_batch_year('2026-2027') !== 2027) {
    throw new RuntimeException('Graduation batch year must use the ending year of the current school year.');
}
foreach (['2026-2028', '2026', ''] as $invalid) {
    try {
        graduation_batch_year($invalid);
        throw new RuntimeException('Invalid school year was accepted.');
    } catch (InvalidArgumentException $e) {
    }
}
if (graduation_default_promotion('4', false) !== '4'
    || graduation_default_promotion('4', true) !== 'graduated'
    || graduation_default_promotion('3', false) !== '4') {
    throw new RuntimeException('Graduation clearance must govern the fourth-year default.');
}

$root = dirname(__DIR__);
$list = file_get_contents($root . '/public/patients/graduates.php');
$profile = file_get_contents($root . '/public/patients/view.php');
$reset = file_get_contents($root . '/app/services/ApeCycleService.php');
if (!str_contains($list, 'Batch year') || !str_contains($list, 'graduation_list($batchYear)')
    || !str_contains($profile, 'graduation_set_clearance')
    || !str_contains($profile, 'graduation_student_batch_year')
    || !str_contains($reset, 'if ($target === \'graduated\' && (!$graduationCleared')) {
    throw new RuntimeException('Graduation UI and reset guards must remain connected.');
}
if (strpos($reset, 'if ($target === \'graduated\')') === false
    || strpos($reset, '$notificationPatients[] = $student;') <= strpos($reset, 'if ($target === \'graduated\')')) {
    throw new RuntimeException('Graduates must bypass the re-enrollment email queue.');
}

echo "Graduation workflow checks passed. No database writes.\n";
