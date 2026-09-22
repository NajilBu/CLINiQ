<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$css = file_get_contents($root . '/patient-portal/assets/css/patient.css');
$layout = file_get_contents($root . '/patient-portal/includes/patient-layout.php');
$passport = file_get_contents($root . '/patient-portal/patient-passport.php');

foreach ([
    '.student-main { padding:',
    '.student-page-header > div > .student-eyebrow { display: none; }',
    '.student-auth-form-side { padding:',
    '.student-button,',
    '.student-dashboard-mobile-task-list',
    '.patient-help-accordion > summary',
    '.student-portal-legal-footer',
    'padding-bottom: calc(var(--student-mobile-nav-height)',
    '.student-ape-step {',
    'min-height: 40px !important',
    'passport-error-action',
    '.student-ape-mobile-batch > .material-symbols-outlined',
    'grid-template-columns: minmax(0, 1fr) !important',
    '.student-ape-mobile-batch > .student-badge',
    'align-self: center',
    'padding: 0 12px',
    'passport-mobile-save-hint',
    'student-ape-flow-card',
    'grid-template-columns: minmax(0, 1fr) auto',
    'flex: 0 0 auto',
    'max-width: 45%',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException('Shared mobile layout rule missing: ' . $marker);
    }
}

foreach ([
    'student-brand-subtitle-mobile',
    'student-nav-toggle-label',
    'student-profile-identity',
    'student-mobile-bottom-nav',
    'student-portal-legal-footer',
] as $marker) {
    if (!str_contains($layout, $marker)) {
        throw new RuntimeException('Shared compact portal shell hook missing: ' . $marker);
    }
}

if (!str_contains($passport, 'data-passport-open-group') || !str_contains($passport, 'form.noValidate = true') || !str_contains($passport, 'hintHeight') || !str_contains($passport, 'profilePanel.open = true')) {
    throw new RuntimeException('Health Passport validation guidance hook missing.');
}

foreach (['patient-ape-status.php', 'patient-passport.php', 'patient-appointment.php', 'patient-help.php'] as $page) {
    $source = file_get_contents($root . '/patient-portal/' . $page);
    if (!str_contains($source, 'details')) {
        throw new RuntimeException('Secondary content must remain disclosure-accessible on ' . $page . '.');
    }
}

echo "Patient mobile layout structural test passed.\n";
