<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$appCss = file_get_contents($root . '/public/assets/css/app.css');
$patientCss = file_get_contents($root . '/patient-portal/assets/css/patient.css');
$feedbackCss = file_get_contents($root . '/public/assets/css/feedback.css');
$staffLayout = file_get_contents($root . '/app/helpers/view.php');
$patientLayout = file_get_contents($root . '/patient-portal/includes/patient-layout.php');

foreach (compact('appCss', 'patientCss', 'feedbackCss', 'staffLayout', 'patientLayout') as $name => $contents) {
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$name} typography source.");
    }
}

foreach ([
    '--cliniq-font-body: Inter, sans-serif',
    '--cliniq-font-display: Manrope, sans-serif',
    "--cliniq-font-icon: 'Material Symbols Outlined'",
] as $token) {
    if (!str_contains($appCss, $token)) {
        throw new RuntimeException("Missing shared typography token: {$token}");
    }
}

if (!str_contains($appCss, 'font-family: var(--cliniq-font-display) !important')
    || !str_contains($appCss, 'font-family: var(--cliniq-font-body)')
    || !str_contains($patientCss, 'font-family: var(--cliniq-font-display)')
    || !str_contains($feedbackCss, 'font-family:var(--cliniq-font-display)')) {
    throw new RuntimeException('Shared typography hierarchy is not applied across staff, student, and feedback styles.');
}

foreach ([$appCss, $patientCss, $feedbackCss] as $css) {
    if (preg_match('/font-weight:\s*(650|750|850|900)\b/', $css)) {
        throw new RuntimeException('Unsupported synthetic font weight remains in a system stylesheet.');
    }
}

if (substr_count($staffLayout, 'inter-manrope.css') < 1
    || substr_count($patientLayout, 'inter-manrope.css') < 1
    || !str_contains($staffLayout, 'material-symbols.css')
    || !str_contains($patientLayout, 'material-symbols.css')) {
    throw new RuntimeException('Shared layouts are not loading the bundled font and icon assets.');
}

echo "Typography consistency structural test passed.\n";
