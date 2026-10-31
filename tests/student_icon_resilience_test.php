<?php

ini_set('session.save_path', sys_get_temp_dir());
session_start();
require_once __DIR__ . '/../patient-portal/includes/patient-layout.php';

$failures = [];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    $assertions++;
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
$previewScript = file_get_contents(__DIR__ . '/../public/assets/js/file-preview.js') ?: '';
$assert(!str_contains($previewScript, 'material-symbols-outlined'), 'The shared file preview must not reintroduce an icon-font dependency in the portal.');
foreach ([$layout, (string) file_get_contents(__DIR__ . '/../app/helpers/view.php')] as $previewLayout) {
    $assert(!str_contains($previewLayout, 'file-preview.js?v=ape-popup-9'), 'Both file-preview callers must invalidate stale cached scripts.');
    $assert(str_contains($previewLayout, "filemtime(__DIR__ . '/../../public/assets/js/file-preview.js')"), 'File-preview script versions must follow the asset in both layouts.');
}

preg_match('/:where\(\.student-body \.student-icon\)\s*\{([^}]+)\}/', $patientCss, $baseIconRule);
foreach (['font-size: 24px;', 'width: 1em;', 'height: 1em;', 'flex: 0 0 auto;'] as $declaration) {
    $assert(str_contains($baseIconRule[1] ?? '', $declaration), 'SVG defaults must retain overridable sizing: ' . $declaration);
}
$assert(!str_contains($patientCss, '.student-body .student-icon {'), 'SVG defaults must not override component dimensions or compact text utilities.');
$assert(str_contains(student_icon_svg('not_an_icon'), 'href="#student-icon-info"'), 'Unknown icons must retain the safe information fallback.');

$sprite = new DOMDocument();
$assert($sprite->loadXML(student_icon_sprite()), 'The student icon sprite must remain valid XML.');
$symbols = [];
foreach ($sprite->getElementsByTagName('symbol') as $symbol) {
    $name = substr($symbol->getAttribute('id'), strlen('student-icon-'));
    $assert(!isset($symbols[$name]), 'Duplicate student icon: ' . $name);
    $assert($symbol->getAttribute('viewBox') === '0 -960 960 960', 'Student icon viewBox must match the bundled glyph coordinates: ' . $name);
    $assert(($symbol->getElementsByTagName('path')->item(0)?->getAttribute('d') ?? '') !== '', 'Student icon must have a glyph path: ' . $name);
    $symbols[$name] = true;
}

$iconSources = array_merge($studentTemplates, [
    __DIR__ . '/../patient-portal/includes/patient-layout.php',
    __DIR__ . '/../app/helpers/brand.php',
    __DIR__ . '/../app/services/AppointmentWorkflow.php',
]);
foreach ($iconSources as $path) {
    $source = (string) file_get_contents($path);
    preg_match_all('/(?:student_icon_svg|studentIcon)\(\s*[\'\"]([a-z_]+)[\'\"]|[\'\"]icon[\'\"]\s*=>\s*[\'\"]([a-z_]+)[\'\"]/', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $name = $match[1] !== '' ? $match[1] : $match[2];
        $assert(isset($symbols[$name]), basename($path) . ' references a missing icon: ' . $name);
    }
}
// These maps pass icon names indirectly to the shared renderer.
preg_match_all('/\$(?:studentActivityLabels|documentIcons)\s*=\s*\[(.*?)\n\];/s', $apeStatus, $maps);
$assert(count($maps[1]) === 2, 'APE activity and document icon maps must be checked.');
foreach ($maps[1] as $map) {
    preg_match_all('/\'([a-z_]+)\'\s*(?:,|\])/', $map, $names);
    foreach ($names[1] as $name) {
        $assert(isset($symbols[$name]), 'APE map references a missing icon: ' . $name);
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Student icon resilience tests passed ({$assertions} assertions)." . PHP_EOL;
