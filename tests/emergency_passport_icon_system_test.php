<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/public/emergency.php');
$css = file_get_contents($root . '/public/assets/css/emergency-passport.css');

if ($page === false || $css === false) {
    fwrite(STDERR, "Could not read passport icon sources.\n");
    exit(1);
}

$checks = [
    'public passport embeds the shared SVG sprite' => str_contains($page, 'emergency_passport_icon_sprite()'),
    'password gate uses SVG medical icon' => str_contains($page, "emergency_passport_icon('medical_information'"),
    'password gate uses SVG shield icon' => str_contains($page, "emergency_passport_icon('shield'"),
    'unlocked passport uses SVG allergy icon' => str_contains($page, "emergency_passport_icon('allergy')"),
    'emergency dock uses SVG send icon' => str_contains($page, "emergency_passport_icon('send')"),
    'loading state swaps SVG reference' => str_contains($page, "#student-icon-progress_activity"),
    'passport markup has no Material Symbols' => !str_contains($page, 'material-symbols-outlined'),
    'passport styling targets SVG icons' => str_contains($css, '.passport-page .passport-icon'),
    'passport SVGs retain a 24px default size' => str_contains($css, 'font-size: 24px;'),
    'passport compact slots increase SVG dimensions' => str_contains($css, 'width: 1.125rem; height: 1.125rem;'),
    'passport access heading has a readable SVG size' => str_contains($css, 'width: 2.25rem; height: 2.25rem;'),
    'passport styling has no Material Symbols' => !str_contains($css, 'material-symbols-outlined'),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ": {$label}\n";
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
