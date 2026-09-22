<?php

declare(strict_types=1);

$dashboard = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-dashboard.php');
$styles = file_get_contents(dirname(__DIR__) . '/patient-portal/assets/css/patient.css');

if ($dashboard === false || $styles === false) {
    throw new RuntimeException('Unable to read the patient dashboard FAQ sources.');
}

$requiredDashboardFragments = [
    'aria-labelledby="student-dashboard-help-title"',
    'Need help?',
    'patient-help.php',
    'Open Help &amp; FAQs',
];

foreach ($requiredDashboardFragments as $fragment) {
    if (!str_contains($dashboard, $fragment)) {
        throw new RuntimeException("Patient dashboard FAQ is missing required content: {$fragment}");
    }
}

if (str_contains($dashboard, 'student-dashboard-faq-item')) {
    throw new RuntimeException('The full FAQ accordion must be hosted on the dedicated Help page, not the dashboard.');
}

if (str_contains($dashboard, 'href="patient-notifications.php"')) {
    throw new RuntimeException('The dashboard help card must not link directly to the JSON notification endpoint.');
}

$clinicNotes = strpos($dashboard, 'dashboard-clinic-notes');
$helpCard = strpos($dashboard, 'student-dashboard-help');
if ($clinicNotes === false || $helpCard === false || $clinicNotes >= $helpCard) {
    throw new RuntimeException('The dashboard Help entry point must appear below Clinic Notes as supporting content.');
}

foreach (['.student-dashboard-help-content', '.student-dashboard-help-icon'] as $selector) {
    if (!str_contains($styles, $selector)) {
        throw new RuntimeException("Patient dashboard FAQ is missing its expected style: {$selector}");
    }
}

echo "Patient dashboard FAQ test passed.\n";
