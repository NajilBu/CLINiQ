<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$visitView = file_get_contents($root . '/public/visits/view.php');
$visitWorkflow = file_get_contents($root . '/app/services/CliniqVisitWorkflow.php');
$feedbackPage = file_get_contents($root . '/public/clinic-feedback.php');
$dashboard = file_get_contents($root . '/patient-portal/patient-dashboard.php');
$appointment = file_get_contents($root . '/patient-portal/patient-appointment.php');
$feedbackEntry = file_get_contents($root . '/patient-portal/patient-feedback.php');
$notification = file_get_contents($root . '/app/services/PatientNotification.php');
$feedbackReport = file_get_contents($root . '/public/feedback/index.php');
$feedbackCss = file_get_contents($root . '/public/assets/css/feedback.css');

foreach (compact('visitView', 'visitWorkflow', 'feedbackPage', 'feedbackEntry', 'dashboard', 'appointment', 'notification', 'feedbackReport', 'feedbackCss') as $name => $contents) {
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$name} feedback workflow source.");
    }
}

if (substr_count($visitView, 'patient_notification_for_feedback_required') < 2
    || !str_contains($visitWorkflow, 'patient_notification_for_feedback_required')) {
    throw new RuntimeException('All visit completion paths must notify the student.');
}
if (!str_contains($feedbackPage, 'patient_notification_mark_source_read')) {
    throw new RuntimeException('Feedback submission must clear its matching notification.');
}
if (!str_contains($feedbackEntry, 'student_require_login()')
    || str_contains($feedbackEntry, "clinic-feedback.php?portal=1")
    || !str_contains($feedbackEntry, 'render_student_header')
    || !str_contains($feedbackEntry, 'clinic_feedback_visit_for_person')) {
    throw new RuntimeException('Portal feedback must render through the authenticated student layout and person-scoped visit flow.');
}
if (!str_contains($feedbackEntry, 'Reason for visit')
    || !str_contains($feedbackEntry, 'Patient concern')
    || !str_contains($feedbackEntry, 'RA 10173')
    || !str_contains($feedbackEntry, '<details>')
    || str_contains($feedbackEntry, 'Visit #')) {
    throw new RuntimeException('Portal feedback context or RA 10173 privacy notice is incomplete, or exposes an internal visit number.');
}
if (str_contains($feedbackEntry, 'About you')
    || str_contains($feedbackEntry, 'name="program"')
    || str_contains($feedbackPage, 'name="program"')
    || str_contains($feedbackPage, 'Student &amp; Visit Information')) {
    throw new RuntimeException('Repeated student identity fields must not appear in feedback forms.');
}
if (!str_contains($feedbackReport, 'feedback-report')
    || !str_contains($feedbackReport, 'assets/css/feedback.css?v=design-4')
    || str_contains($feedbackReport, '<link hidden')) {
    throw new RuntimeException('Staff feedback report is not using the shared feedback design layer.');
}
if (!str_contains($feedbackCss, '.feedback-report')
    || !str_contains($feedbackCss, 'html.cliniq-dark .feedback-report')
    || !str_contains($feedbackCss, 'font-weight: 600')
    || !str_contains($feedbackCss, 'font-family: var(--cliniq-font-body)')
    || !str_contains($feedbackCss, 'Compact rating layout for the Electron/public feedback form')) {
    throw new RuntimeException('Feedback report design does not include system and dark-mode styling.');
}
if (str_contains($feedbackPage, 'Visit #')) {
    throw new RuntimeException('Public feedback must not expose internal visit numbers.');
}
if (!str_contains($feedbackPage, 'RA 10173')
    || !str_contains($feedbackPage, 'class="feedback-privacy"')
    || !str_contains($feedbackPage, 'I have read the RA 10173 notice')) {
    throw new RuntimeException('Public feedback is missing the collapsible RA 10173 notice and agreement.');
}
if (!str_contains($dashboard, 'clinic_feedback_pending_completed_visits')
    || !str_contains($dashboard, 'if ($requiredActionCount > 0):')
    || !str_contains($dashboard, 'student-dashboard-ready-action-list')) {
    throw new RuntimeException('Dashboard feedback and ready-state layout is incomplete.');
}
if (!str_contains($appointment, 'elseif ($feedbackRequired)')
    || !str_contains($appointment, 'Complete all required feedback')) {
    throw new RuntimeException('Appointment booking must be blocked for any pending completed feedback.');
}
if (!str_contains($notification, 'JOIN accounts a ON a.person_id = s.person_id')
    || !str_contains($notification, 'account_status')
    || !str_contains($notification, "source_type = 'clinic_feedback'")) {
    throw new RuntimeException('Feedback notifications must be limited to active student accounts and deduplicated by visit.');
}

echo "Student feedback requirement structural test passed.\n";
