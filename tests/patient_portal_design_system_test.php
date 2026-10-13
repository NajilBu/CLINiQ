<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$css = file_get_contents($root . '/patient-portal/assets/css/patient.css');
$layout = file_get_contents($root . '/patient-portal/includes/patient-layout.php');
$overlayScript = file_get_contents($root . '/public/assets/js/student-overlays.js');

if ($css === false || $layout === false || $overlayScript === false) {
    throw new RuntimeException('Unable to read the student portal design-system source.');
}

foreach ([
    '--student-radius-control',
    '.student-toast-region',
    'One geometry rule for information surfaces.',
    '.student-dashboard-next-steps,',
    '.student-feedback-flow-progress > span,',
    '.student-appointment-booking-sheet,',
    'Quiet interaction motion: feedback for deliberate actions, never decoration.',
    '--student-motion-ease:',
    '@media (hover: hover)',
    '@keyframes student-sheet-enter',
    '@media (prefers-reduced-motion: reduce)',
    'border-radius: 0 !important;',
    'border-left-width: 1px;',
    'border-radius: 0 !important;',
    'box-shadow: 0 12px 28px rgba(23, 38, 29, .2);',
    '[data-student-overlay]',
    'html.student-dark .student-body:not(.student-auth-page)',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("Student design-system style is missing {$marker}.");
    }
}

foreach (['.student-badge', '.student-profile-photo', '.student-feedback-count'] as $functionalShape) {
    if (!str_contains($css, $functionalShape)) {
        throw new RuntimeException("Functional rounded shape hook is missing {$functionalShape}.");
    }
}

foreach ([
    'data-student-toast-region aria-live="polite"',
    'data-student-overlay role="dialog"',
    'student-overlays.js?v=',
] as $marker) {
    if (!str_contains($layout, $marker)) {
        throw new RuntimeException("Shared portal accessibility hook is missing {$marker}.");
    }
}

foreach (['MutationObserver', 'previousFocus', 'focusable'] as $marker) {
    if (!str_contains($overlayScript, $marker)) {
        throw new RuntimeException("Shared overlay behavior is missing {$marker}.");
    }
}

echo "Patient portal design-system test passed.\n";
