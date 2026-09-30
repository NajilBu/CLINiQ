<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workCenter = file_get_contents($root . '/app/services/ClinicWorkCenter.php');
$apeIndex = file_get_contents($root . '/public/ape/index.php');
$emailCenter = file_get_contents($root . '/public/audit/index.php');

if ($workCenter === false || $apeIndex === false || $emailCenter === false) {
    throw new RuntimeException('APE reminder policy sources must be readable.');
}

foreach (["function clinic_work_center_reminder_state", "function clinic_work_center_reminder_candidates(?int \$batchId = null)", "['Cleared', 'Completed', 'Inactive']", 'clinic_work_center_reminder_candidates($batchId)', "'follow_up', \$followUpNumber"] as $marker) {
    if (!str_contains($workCenter, $marker)) throw new RuntimeException("Missing reminder safety guard: {$marker}");
}
if (str_contains($workCenter, 'clinic_work_center_extend_reminder_deadline') || str_contains($workCenter, "'new_deadline' =>")) {
    throw new RuntimeException('Sending an APE reminder must not extend its deadline.');
}
foreach (['Review APE follow-ups', "'ape_batch' => \$selectedBatchId", 'sent to email service'] as $marker) {
    if (!str_contains($apeIndex, $marker)) throw new RuntimeException("Missing scoped APE reminder entry point: {$marker}");
}
foreach (["empty(\$entry['selected'])", 'data-reminder-select-all', 'data-reminder-select', 'follow_up_number', 'two-follow-up limit', 'APE deadlines were not changed.'] as $marker) {
    if (!str_contains($emailCenter, $marker)) throw new RuntimeException("Missing reminder review safeguard: {$marker}");
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
