<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workCenter = file_get_contents($root . '/app/services/ClinicWorkCenter.php');
$apeIndex = file_get_contents($root . '/public/ape/index.php');
$emailCenter = file_get_contents($root . '/public/audit/index.php');
$appJs = file_get_contents($root . '/public/assets/js/app.js');

if ($workCenter === false || $apeIndex === false || $emailCenter === false || $appJs === false) {
    throw new RuntimeException('APE reminder policy sources must be readable.');
}

foreach (["function clinic_work_center_reminder_state", "function clinic_work_center_reminder_candidates(?int \$batchId = null)", "['Cleared', 'Completed', 'Inactive']", 'clinic_work_center_reminder_candidates($batchId)', "'follow_up', \$followUpNumber"] as $marker) {
    if (!str_contains($workCenter, $marker)) throw new RuntimeException("Missing reminder safety guard: {$marker}");
}
if (str_contains($workCenter, 'clinic_work_center_extend_reminder_deadline') || str_contains($workCenter, "'new_deadline' =>")) {
    throw new RuntimeException('Sending an APE reminder must not extend its deadline.');
}
foreach (['Review email follow-up', 'apeFollowUpDecisionModal', 'Updates sent to this student', 'send_ape_follow_up', 'clinic_work_center_send_reminders', 'mark_ape_inactive', 'of 2 staff follow-ups'] as $marker) {
    if (!str_contains($apeIndex, $marker)) throw new RuntimeException("Missing scoped APE reminder entry point: {$marker}");
}
if (str_contains($apeIndex, 'Keep APE active')) {
    throw new RuntimeException('The follow-up decision modal must not render a redundant Keep APE active control.');
}
foreach (["\$populationScope === 'students'", '$apeReadyFollowUpCount > 0', 'foreach ($apeReminderByRecordId as $apeId => $reminderItem)'] as $marker) {
    if (!str_contains($apeIndex, $marker)) throw new RuntimeException("Missing independent student follow-up notice guard: {$marker}");
}
foreach (['$apeReminderCandidatesByRecordId', '$apeMissedExamEmailSentByRecordId', "'ape_exam_missed'", "empty(\$reminderItem['can_send_reminder'])"] as $marker) {
    if (!str_contains($apeIndex, $marker)) throw new RuntimeException("APE reminder actions must retain event-specific state: {$marker}");
}
foreach (['ar.entry_mode, se.role_classification', "['Faculty', 'Non-Teaching Personnel']", "(\$row['entry_mode'] ?? '') === 'Clinic Manual'"] as $marker) {
    if (!str_contains($workCenter, $marker)) throw new RuntimeException("Faculty/NTP APE records must remain out of reminder candidates: {$marker}");
}
if (str_contains($apeIndex, 'aria-label="APE follow-up action"') || str_contains($apeIndex, 'bg-amber-50')) {
    throw new RuntimeException('Ready APE follow-ups must appear in Needs attention, not as a duplicate standalone action.');
}
$attentionAlertEnd = strpos($apeIndex, '</details>', strpos($apeIndex, 'data-ape-persistent-details="attention"'));
if ($attentionAlertEnd === false || strpos($apeIndex, 'Follow-up email ready') === false || strpos($apeIndex, 'Review email follow-up') > $attentionAlertEnd) {
    throw new RuntimeException('The ready APE follow-up action must be inside the red Needs attention alert.');
}
foreach (["empty(\$entry['selected'])", 'data-reminder-select-all', 'data-reminder-select', 'follow_up_number', 'two-follow-up limit', 'APE deadlines were not changed.'] as $marker) {
    if (!str_contains($emailCenter, $marker)) throw new RuntimeException("Missing reminder review safeguard: {$marker}");
}
foreach (['data-ape-ready-follow-up', 'inventory-row-attention', 'prefers-reduced-motion: reduce'] as $marker) {
    if (!str_contains($emailCenter, $marker) && !str_contains($appJs, $marker) && !str_contains((string) file_get_contents($root . '/public/assets/css/app.css'), $marker)) {
        throw new RuntimeException("Missing APE follow-up row focus behavior: {$marker}");
    }
}
foreach (['ape_follow_up_ape_id', '$apeFollowUpApeId === null', 'isset($readyReminderKeys[$workItemKey($item)])'] as $marker) {
    if (!str_contains($emailCenter, $marker)) throw new RuntimeException("Missing ownership-checked APE follow-up focus guard: {$marker}");
}
foreach (["\$action === 'mark_ape_inactive' && (string) (\$_POST['return_to'] ?? '') === 'ape_queue'", 'Only administrators and doctors can change an APE record', 'Select a valid reason for making this APE record inactive.'] as $marker) {
    if (!str_contains((string) file_get_contents($root . '/public/ape/view.php'), $marker)) throw new RuntimeException("Missing safe APE inactive control: {$marker}");
}
foreach (['function initApeReadyFollowUpHighlight', 'initApeReadyFollowUpHighlight(root || document);', 'initApeReadyFollowUpHighlight();'] as $marker) {
    if (!str_contains($appJs, $marker)) throw new RuntimeException("Missing durable APE follow-up highlight lifecycle: {$marker}");
}

require_once $root . '/app/services/ClinicWorkCenter.php';
$base = ['sent_count' => 1, 'manual_follow_up_count' => 0, 'pending_count' => 0, 'latest_status' => 'sent', 'last_sent_at' => '2026-09-01 10:00:00'];
$at72Hours = strtotime('2026-09-04 10:00:00');
$assertState = static function (array $history, bool $recipient, int $now, string $expected): void {
    $actual = clinic_work_center_reminder_state($history, $recipient, $now)['state'];
    if ($actual !== $expected) throw new RuntimeException("Expected {$expected}; got {$actual}.");
};
$assertState($base, true, $at72Hours - 1, 'cooldown');
$assertState($base, true, $at72Hours, 'ready');
$assertState(array_replace($base, ['manual_follow_up_count' => 1, 'last_sent_at' => '2026-09-04 10:00:00']), true, strtotime('2026-09-07 10:00:00'), 'ready');
$assertState(array_replace($base, ['manual_follow_up_count' => 2]), true, $at72Hours, 'limit_reached');
$assertState(array_replace($base, ['pending_count' => 1]), true, $at72Hours, 'delivery_pending');
$assertState(array_replace($base, ['latest_status' => 'failed']), true, $at72Hours, 'delivery_attention');
$assertState($base, false, $at72Hours, 'recipient_unavailable');
$assertState(['sent_count' => 0, 'manual_follow_up_count' => 0, 'pending_count' => 0, 'latest_status' => null, 'last_sent_at' => null], true, $at72Hours, 'initial_delivery_required');

echo "APE reminder policy coverage test passed.\n";
