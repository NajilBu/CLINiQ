<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-dashboard.php');
$welcomeCard = strpos($source, 'aria-label="Patient dashboard overview"');
$welcome = strpos($source, 'Welcome back,', $welcomeCard);
$welcomeCardEnd = strpos($source, '</section>', $welcome);
$requiredActions = strpos($source, 'aria-label="Required student actions"', $welcomeCardEnd);
$readyCard = strpos($source, 'student-action-card', $requiredActions);
$dashboardGrid = strpos($source, '<div class="student-grid', $readyCard);

if ($welcomeCard === false || $welcome === false || $welcomeCardEnd === false || $requiredActions === false || $readyCard === false || $dashboardGrid === false) {
    throw new RuntimeException('The welcome and Ready areas must render as separate dashboard sections.');
}
if (!($welcomeCard < $welcome && $welcome < $welcomeCardEnd && $welcomeCardEnd < $requiredActions && $requiredActions < $readyCard && $readyCard < $dashboardGrid)) {
    throw new RuntimeException('The Ready card must appear after, not inside, the welcome card.');
}

foreach (['$dashboardTasks = [];', 'student-dashboard-mobile-task-list', 'student-dashboard-more-tasks', 'student-dashboard-applicant-hint'] as $requiredMarker) {
    if (strpos($source, $requiredMarker) === false) {
        throw new RuntimeException('Dashboard mobile simplification marker missing: ' . $requiredMarker);
    }
}
if (strpos($source, 'if ($requiredActionCount > 0):') === false
    || strpos($source, 'student-dashboard-ready-action-list') === false
    || strpos($source, 'clinic_feedback_pending_completed_visits') === false) {
    throw new RuntimeException('Dashboard must hide the outer next-step panel when ready and use completed feedback requirements.');
}
if (strpos($source, 'patient-passport.php') === false || strpos($source, 'patient-ape-status.php') === false || strpos($source, '$feedbackPortalUrl') === false) {
    throw new RuntimeException('Dashboard task destinations must remain available.');
}
if (strpos($source, '$passportMissing[] = \'blood type\';') !== false
    || strpos($source, 'The portal will unlock the next action') !== false) {
    throw new RuntimeException('Dashboard must not present clinic-managed blood type or false task gating as a student action.');
}
if (strpos($source, '$apeDocumentsAwaitingClinicReview') === false
    || strpos($source, "foreach (ape_requirements_for_record((int) \$latestApe['ape_id']) as \$requirement)") === false) {
    throw new RuntimeException('Dashboard must use the latest submitted requirement before showing a correction task.');
}
if (strpos($source, 'Scheduled physical examination') === false
    || strpos($source, 'Attend your physical examination at the school clinic') === false) {
    throw new RuntimeException('Dashboard must keep a scheduled physical examination visible after document submission.');
}

echo "Patient dashboard separate Ready card test passed.\n";
