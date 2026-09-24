<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/patient-portal/patient-help.php');
$layout = file_get_contents($root . '/patient-portal/includes/patient-layout.php');
$styles = file_get_contents($root . '/patient-portal/assets/css/patient.css');

foreach (compact('page', 'layout', 'styles') as $name => $contents) {
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$name} Help Center source.");
    }
}

foreach (["render_student_header('Help & FAQs', 'help')", 'render_student_footer()', 'Getting started and access', 'APE requirements', 'Appointments', 'Health Passport', 'Notifications and clinic support'] as $fragment) {
    if (!str_contains($page, $fragment)) {
        throw new RuntimeException("Help Center is missing required content: {$fragment}");
    }
}

if (substr_count($page, '<details class="patient-help-accordion">') < 15) {
    throw new RuntimeException('Help Center must provide independently expandable FAQ answers for every category.');
}

foreach (['patient-register.php', 'patient-forgot-password.php', 'patient-ape-status.php', 'patient-appointment.php', 'patient-passport.php'] as $link) {
    if (!str_contains($page, 'href="' . $link . '"')) {
        throw new RuntimeException("Help Center is missing verified portal link: {$link}");
    }
}

if (str_contains($page, 'patient-notifications.php') || str_contains($page, 'href="../')) {
    throw new RuntimeException('Help Center must not expose API endpoints or leave the patient portal for FAQ actions.');
}

foreach (["'patient-help.php', 'patient-login.php'", "['dashboard' => true]"] as $fragment) {
    if (!str_contains($layout, $fragment)) {
        throw new RuntimeException("Help onboarding access is missing: {$fragment}");
    }
}

if (str_contains($layout, "'help' => [") || str_contains($layout, "'url' => 'patient-help.php'")) {
    throw new RuntimeException('Help must be reached from the dashboard, not shown in the shared portal navigation.');
}

foreach (['.patient-help-grid', '.patient-help-accordion > summary', '.patient-help-accordion[open] > summary::after', '.patient-help-answer', '.patient-help-category .student-card-header'] as $selector) {
    if (!str_contains($styles, $selector)) {
        throw new RuntimeException("Help Center is missing expected style: {$selector}");
    }
}

$normalizedStyles = str_replace("\r\n", "\n", $styles);
if (strrpos($normalizedStyles, '.patient-help-header-icon,') === false
    || strrpos($normalizedStyles, '.patient-help-header-icon,') < strpos($normalizedStyles, '.patient-help-header-icon,')) {
    throw new RuntimeException('The final Help Center icon rule must override the decorative icon rule.');
}
if (!str_ends_with(trim($normalizedStyles), '.patient-help-category-icon {' . "\n" . '    display: none !important;' . "\n" . '}')) {
    throw new RuntimeException('The final Help Center icon rule must force decorative icons off.');
}

echo "Patient Help Center test passed.\n";
