<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/services/PatientNotification.php';

$cases = [
    'patient-ape-status.php' => '/ape-status',
    'patient-ape-document.php?id=7' => '/ape-document?id=7',
    'patient-appointment.php?month=2026-10' => '/appointments?month=2026-10',
    'patient-dashboard.php' => '/dashboard',
    'patient-passport.php' => '/health-passport',
    'patient-help.php' => '/help',
    'patient-feedback.php' => '/feedback',
    'patient-feedback-consent.php' => '/feedback/consent',
    'patient-login.php' => '/login',
    'patient-onboarding.php' => '/onboarding',
    '/ape-status' => '/ape-status',
    '/appointments' => '/appointments',
    '/feedback/consent' => '/feedback/consent',
];

foreach ($cases as $target => $expected) {
    $actual = patient_notification_target_url($target);
    if ($actual !== $expected) {
        throw new RuntimeException("Expected {$target} to normalize to {$expected}; got " . var_export($actual, true));
    }
}

foreach (['https://example.com', '//example.com', 'patient-notifications.php', '/public/index.php'] as $target) {
    if (patient_notification_target_url($target) !== null) {
        throw new RuntimeException("Expected {$target} to be rejected.");
    }
}

echo "Patient notification target test passed.\n";
