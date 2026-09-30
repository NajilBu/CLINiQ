<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = file_get_contents($root . '/public/ape/view.php');
$settings = file_get_contents($root . '/public/settings/index.php');
$email = file_get_contents($root . '/app/services/PatientEmail.php');
$templates = file_get_contents($root . '/app/services/SystemSettings.php');

if ($view === false || $settings === false || $email === false || $templates === false) {
    throw new RuntimeException('APE policy sources must be readable.');
}

foreach (['name="patient_visible_note"', 'name="follow_up_due_date"', 'Select a due date for this follow-up.', 'The follow-up due date cannot be in the past.'] as $marker) {
    if (!str_contains($view, $marker)) {
        throw new RuntimeException("APE follow-up policy is missing {$marker}.");
    }
}
if (!str_contains($settings, '$apeCycleUnclearedCount') || !str_contains($settings, 'record(s) are not cleared')) {
    throw new RuntimeException('APE cycle closure does not disclose uncleared records.');
}
if (!str_contains($email, "'ape_schedule_updates' => true") || !str_contains($email, "'ape_schedule_updated' =>")) {
    throw new RuntimeException('APE schedule email event is missing.');
}
if (!str_contains($templates, "'ape_schedule_updated' =>")) {
    throw new RuntimeException('APE schedule email template is missing.');
}

echo "APE follow-up policy coverage test passed.\n";
