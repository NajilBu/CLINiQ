<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function expect_action_feedback(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function action_feedback_source(string $root, string $path): string
{
    $source = file_get_contents($root . '/' . $path);
    if ($source === false) {
        throw new RuntimeException('Unreadable feedback source: ' . $path);
    }

    return $source;
}

$loader = action_feedback_source($root, 'public/assets/js/submission-loading.js');
foreach ([
    "(form.getAttribute('method') || 'get').toUpperCase() !== 'POST'",
    "form.dataset.noLoading === 'true'",
    "form.target && form.target !== '_self'",
    'new URL(form.getAttribute(\'action\') || window.location.href, window.location.href).origin === window.location.origin',
    'event.defaultPrevented',
    'button.disabled = true',
    "role', 'status'",
    "aria-live', 'polite'",
] as $marker) {
    expect_action_feedback(str_contains($loader, $marker), 'Shared submission feedback is missing: ' . $marker);
}

foreach ([
    'app/helpers/view.php',
    'patient-portal/includes/patient-layout.php',
    'public/visitor-registration.php',
] as $path) {
    expect_action_feedback(
        str_contains(action_feedback_source($root, $path), 'submission-loading.js'),
        $path . ' must load the shared submission feedback script.'
    );
}

$outcomeRoutes = [
    'public/alerts/create.php' => 'flash_message(',
    'public/ape/view.php' => 'flash_message(',
    'public/appointments/update.php' => 'flash_message(',
    'public/inventory/archive.php' => 'flash_message(',
    'public/settings/index.php' => 'flash_message(',
    'public/visits/view.php' => 'flash_message(',
    'patient-portal/patient-ape-status.php' => 'student-toast',
    'patient-portal/patient-appointment.php' => 'student-toast',
    'patient-portal/patient-feedback.php' => 'student-note',
    'patient-portal/patient-onboarding.php' => 'student-note',
    'patient-portal/patient-passport.php' => 'student-note',
    'patient-portal/patient-register.php' => 'student-note',
];
foreach ($outcomeRoutes as $path => $marker) {
    expect_action_feedback(
        str_contains(action_feedback_source($root, $path), $marker),
        $path . ' must retain its surface-native action outcome feedback.'
    );
}

$settings = action_feedback_source($root, 'public/settings/index.php');
foreach ([
    'Reset this dropdown?',
    'Delete dropdown option?',
    'Close this APE cycle?',
    'Archive this APE cycle?',
    'Reset incident risk settings?',
    'Start the new school year?',
] as $action) {
    expect_action_feedback(
        str_contains($settings, 'data-confirm-submit') && str_contains($settings, 'data-confirm-title="' . $action . '"'),
        'Settings must keep standard confirmation for: ' . $action
    );
}
foreach ([
    'Save clinic profile?',
    'Create staff profile?',
    'Save required APE documents?',
    'Run backup now?',
    'Send custom email?',
] as $action) {
    expect_action_feedback(!str_contains($settings, $action), 'Settings must not confirm ordinary action: ' . $action);
}
foreach ([
    'Document added. Save Required Documents to apply it.',
    'Document removed. Save Required Documents to apply the change.',
] as $notice) {
    expect_action_feedback(str_contains($settings, $notice), 'Settings must acknowledge checklist edit: ' . $notice);
}

foreach ([
    'public/ape/view.php',
    'public/inventory/index.php',
    'public/patient-accounts/index.php',
    'public/settings/index.php',
    'public/visits/view.php',
] as $path) {
    expect_action_feedback(
        str_contains(action_feedback_source($root, $path), 'data-confirm-submit'),
        $path . ' must keep confirmation for destructive or irreversible staff actions.'
    );
}
foreach ([
    'patient-portal/patient-ape-status.php',
    'patient-portal/patient-feedback.php',
] as $path) {
    $source = action_feedback_source($root, $path);
    expect_action_feedback(
        str_contains($source, 'confirm(') || str_contains($source, '<dialog'),
        $path . ' must keep its existing student confirmation flow for destructive actions.'
    );
}

echo "Action feedback coverage tests passed.\n";
