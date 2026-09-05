<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-dashboard.php');
$welcomeCard = strpos($source, '<section class="student-card student-card-pad mb-4" aria-label="Patient dashboard overview">');
$welcome = strpos($source, 'Welcome back,', $welcomeCard);
$welcomeCardEnd = strpos($source, '</section>', $welcome);
$requiredActions = strpos($source, '<section class="student-required-actions mb-4"', $welcomeCardEnd);
$readyCard = strpos($source, '<article class="student-action-card">', $requiredActions);
$dashboardGrid = strpos($source, '<div class="student-grid">', $readyCard);

if ($welcomeCard === false || $welcome === false || $welcomeCardEnd === false || $requiredActions === false || $readyCard === false || $dashboardGrid === false) {
    throw new RuntimeException('The welcome and Ready areas must render as separate dashboard sections.');
}
if (!($welcomeCard < $welcome && $welcome < $welcomeCardEnd && $welcomeCardEnd < $requiredActions && $requiredActions < $readyCard && $readyCard < $dashboardGrid)) {
    throw new RuntimeException('The Ready card must appear after, not inside, the welcome card.');
}

echo "Patient dashboard separate Ready card test passed.\n";
