<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-dashboard.php');
$welcomeCard = strpos($source, 'aria-label="Patient dashboard overview"');
$welcome = strpos($source, 'Welcome back,', $welcomeCard);
$welcomeCardEnd = strpos($source, '</section>', $welcome);
$requiredActions = strpos($source, 'aria-label="Required student actions"', $welcomeCardEnd);
$dashboardSummaries = strpos($source, 'aria-label="Dashboard summaries"', $requiredActions);
$dashboardGrid = strpos($source, '<div class="student-grid', $dashboardSummaries);

if ($welcomeCard === false || $welcome === false || $welcomeCardEnd === false || $requiredActions === false || $dashboardSummaries === false || $dashboardGrid === false) {
    throw new RuntimeException('The welcome, required actions, and mobile summary areas must remain separate dashboard sections.');
}
if (!($welcomeCard < $welcome && $welcome < $welcomeCardEnd && $welcomeCardEnd < $requiredActions && $requiredActions < $dashboardSummaries && $dashboardSummaries < $dashboardGrid)) {
    throw new RuntimeException('Dashboard sections must retain their intended action-first order.');
}

foreach (['$dashboardTasks = [];', 'student-dashboard-mobile-task-list', 'student-dashboard-more-tasks', 'student-dashboard-applicant-hint'] as $requiredMarker) {
    if (strpos($source, $requiredMarker) === false) {
        throw new RuntimeException('Dashboard mobile simplification marker missing: ' . $requiredMarker);
    }
}
if (strpos($source, 'if ($requiredActionCount > 0):') === false
    || strpos($source, 'aria-label="Student profile status"') !== false
    || strpos($source, 'Your clinic profile is complete') !== false
    || strpos($source, "'key' => 'ready'") !== false
    || strpos($source, 'clinic_feedback_pending_completed_visits') === false) {
    throw new RuntimeException('Dashboard must hide the redundant ready panel and use completed feedback requirements.');
}
if (strpos($source, "student_portal_url('health-passport'") === false || strpos($source, "student_portal_url('ape-status'") === false || strpos($source, "student_portal_url('appointments'") === false || strpos($source, '$feedbackPortalUrl') === false) {
    throw new RuntimeException('Dashboard task destinations must remain available.');
}
if (strpos($source, "ORDER BY CASE status\n        WHEN 'Scheduled' THEN 1\n        WHEN 'Pending' THEN 2") === false
    || strpos($source, "WHEN 'Cancelled' THEN 5") === false) {
    throw new RuntimeException('Dashboard must prioritize approved and pending appointments above cancelled history.');
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

echo "Patient dashboard action-first summary test passed.\n";
