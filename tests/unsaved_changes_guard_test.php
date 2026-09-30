<?php

declare(strict_types=1);

$guard = file_get_contents(__DIR__ . '/../public/assets/js/unsaved-changes.js');
$app = file_get_contents(__DIR__ . '/../public/assets/js/app.js');
$view = file_get_contents(__DIR__ . '/../app/helpers/view.php');
$patientLayout = file_get_contents(__DIR__ . '/../patient-portal/includes/patient-layout.php');
$staffFeedback = file_get_contents(__DIR__ . '/../public/clinic-feedback.php');
$patientFeedback = file_get_contents(__DIR__ . '/../patient-portal/patient-feedback.php');

foreach ([
    'shared guard tracks POST form edits only' => str_contains($guard, "(form.getAttribute('method') || 'get').toLowerCase() === 'post'"),
    'discard resets the actual form values' => str_contains($guard, 'form.reset();'),
    'successful asynchronous saves can clear dirty state without resetting values' => str_contains($guard, 'window.cliniqMarkChangesSaved'),
    'shared guard protects browser exit' => str_contains($guard, "window.addEventListener('beforeunload'"),
    'shared guard protects internal navigation' => str_contains($guard, "window.location.assign(url.href)"),
    'shared modal close asks before discarding' => str_contains($app, 'cliniqConfirmDiscardChanges(modal, () => closeModal(modalId, true))'),
    'inventory marks a successful asynchronous save before closing its modal' => str_contains(file_get_contents(__DIR__ . '/../public/inventory/index.php'), 'cliniqMarkChangesSaved?.(form)'),
    'APE upload marks a successful asynchronous save before closing its modal' => str_contains(file_get_contents(__DIR__ . '/../public/ape/view.php'), 'cliniqMarkChangesSaved?.(form)'),
    'shared confirmation uses the discard label' => str_contains($app, "confirmLabel = 'Confirm'"),
    'staff layout loads the guard' => str_contains($view, 'assets/js/unsaved-changes.js'),
    'staff profile-photo close uses the guard' => str_contains($view, 'cliniqConfirmDiscardChanges(modal, () => closeProfilePhotoModal(modal, true))'),
    'staff profile-photo discard restores its original preview' => str_contains($view, 'form.addEventListener(\'reset\'')
        && str_contains($view, 'preview.src = originalPreviewSrc'),
    'patient layout loads the guard' => str_contains($patientLayout, 'assets/js/unsaved-changes.js'),
    'patient custom modal closes use the guard' => str_contains($patientLayout, 'data-discard-close="change-password-modal"')
        && str_contains($patientLayout, 'cliniqConfirmDiscardChanges(modal, () => closeProfilePhotoModal(modal, true))')
        && str_contains($patientLayout, 'preview.src = originalPreviewSrc'),
    'feedback survey keeps its reset-specific leave flow' => str_contains($staffFeedback, 'id="feedback-survey" data-no-discard-warning')
        && str_contains($patientFeedback, 'id="student-feedback-form" class="student-feedback-form" data-no-discard-warning'),
] as $label => $passed) {
    if (!$passed) throw new RuntimeException("Failed: {$label}");
}

echo "Unsaved changes guard tests passed.\n";
