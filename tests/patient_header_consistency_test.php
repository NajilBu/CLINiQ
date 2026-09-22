<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pages = [
    'Dashboard' => $root . '/patient-portal/patient-dashboard.php',
    'APE Status' => $root . '/patient-portal/patient-ape-status.php',
    'Appointments' => $root . '/patient-portal/patient-appointment.php',
    'Health Passport' => $root . '/patient-portal/patient-passport.php',
    'Help Center' => $root . '/patient-portal/patient-help.php',
];
$styles = file_get_contents($root . '/patient-portal/assets/css/patient.css');

if ($styles === false) {
    throw new RuntimeException('Unable to read shared patient styles.');
}

foreach ($pages as $name => $path) {
    $page = file_get_contents($path);
    if ($page === false || !str_contains($page, 'student-page-header') || !str_contains($page, 'student-title') || !str_contains($page, 'student-subtitle')) {
        throw new RuntimeException("{$name} must use the shared title and subtitle header structure.");
    }
}

foreach (['.student-page-header > div > .student-eyebrow', '.student-page-header > .student-badge', 'border-bottom: 1px solid var(--student-border-soft)', '.student-page-header .student-title', '.student-page-header .student-subtitle'] as $fragment) {
    if (!str_contains($styles, $fragment)) {
        throw new RuntimeException("Shared student header styling is incomplete: {$fragment}");
    }
}

foreach (['.student-mobile-bottom-nav-link.active::before', 'width:24px; height:3px;', 'min-height:48px;', 'background:rgba(255, 255, 255, .88);', "font-variation-settings:'FILL' 0, 'wght' 500"] as $fragment) {
    if (!str_contains($styles, $fragment)) {
        throw new RuntimeException("Mobile navigation styling is incomplete: {$fragment}");
    }
}

foreach (['html.student-dark .student-mobile-bottom-nav-link.active {', 'background: transparent !important;', 'html.student-dark .student-mobile-bottom-nav-link.active::before', 'background: #77c990;'] as $fragment) {
    if (!str_contains($styles, $fragment)) {
        throw new RuntimeException("Dark mobile navigation styling is incomplete: {$fragment}");
    }
}

if (strrpos($styles, 'html.student-dark .student-mobile-bottom-nav-link.active {') <= strpos($styles, 'html.student-dark .student-mobile-bottom-nav-link.active,')) {
    throw new RuntimeException('The dark indicator-only navigation rule must follow the legacy active-tile rule.');
}

echo "Patient header consistency test passed.\n";
