<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/ApeCycleService.php';

if (!can_start_new_school_year(['status' => 'Closed'])) {
    throw new RuntimeException('A closed APE cycle must allow the new-school-year action.');
}

foreach ([null, [], ['status' => 'Active'], ['status' => 'Archived']] as $cycle) {
    if (can_start_new_school_year($cycle)) {
        throw new RuntimeException('The new-school-year action must be unavailable unless the current APE cycle is closed.');
    }
}

$settingsPage = file_get_contents(dirname(__DIR__) . '/public/settings/index.php');
if (!str_contains($settingsPage, '<?php if ($canStartNewSchoolYear): ?>')) {
    throw new RuntimeException('The settings page must hide Start New School Year until the current cycle is closed.');
}

$cycleService = file_get_contents(dirname(__DIR__) . '/app/services/ApeCycleService.php');
if (!str_contains($cycleService, 'if (!can_start_new_school_year($currentCycle))')) {
    throw new RuntimeException('The reset endpoint must enforce the closed-cycle requirement server-side.');
}

echo "School year cycle guard test passed. No database writes.\n";
