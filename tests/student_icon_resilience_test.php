<?php

ini_set('session.save_path', sys_get_temp_dir());
session_start();
require_once __DIR__ . '/../patient-portal/includes/patient-layout.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$layout = file_get_contents(__DIR__ . '/../patient-portal/includes/patient-layout.php') ?: '';
$patientCss = file_get_contents(__DIR__ . '/../patient-portal/assets/css/patient.css') ?: '';
$apeStatus = file_get_contents(__DIR__ . '/../patient-portal/patient-ape-status.php') ?: '';
$studentTemplates = glob(__DIR__ . '/../patient-portal/*.php') ?: [];

$assert(!str_contains($layout, 'material-symbols.css'), 'Student layouts must not load the Material Symbols font stylesheet.');
$assert(str_contains(student_icon_svg('calendar_month', '', true), 'class="student-icon"'), 'Server-rendered student icons must be inline SVG.');
$assert(str_contains(student_icon_svg('calendar_month'), 'href="#student-icon-calendar_month"'), 'The inline SVG must reference the local calendar symbol.');
$assert(!str_contains($layout, 'student_replace_icon_font_markup'), 'Student icons must not rely on a response-time font-markup rewrite.');
$assert(!array_filter($studentTemplates, static fn (string $template): bool => str_contains((string) file_get_contents($template), 'material-symbols-outlined')), 'Student templates must not contain icon-font markup.');
$assert(!str_contains($patientCss, '.material-symbols-outlined'), 'Student CSS must target SVG icons instead of the icon-font class.');
$assert(!str_contains($patientCss, 'Material Symbols'), 'Student CSS must not generate icons through the Material Symbols font.');
$assert(!str_contains($apeStatus, 'innerHTML = \'<span class="material-symbols-outlined"'), 'Dynamic APE upload states must create SVG icons.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Student icon resilience tests passed (7 assertions)." . PHP_EOL;
