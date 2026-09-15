<?php
// Run: php tests/ape_four_step_workflow_test.php
require_once __DIR__ . '/../app/services/ApeWorkflow.php';

function expect_four_step(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$steps = ape_workflow_steps();
expect_four_step(count($steps) === 4, 'APE must contain exactly four workflow steps.');
expect_four_step($steps === [
    'Digital Keeping',
    'Examination',
    'Final Decision or Follow-up',
    'Completed',
], 'APE workflow labels or order changed unexpectedly.');

$waiting = [
    'schedule_batch_id' => 8,
    'batch_status' => 'Scheduled',
    'batch_start_at' => '2099-09-15 08:00:00',
    'batch_end_at' => '2099-09-15 12:00:00',
    'workflow_status' => 'Batch Assigned',
    'clearance_status' => 'Pending',
    'patient_vitals_status' => 'Not Started',
    'exam_date' => null,
];
$fixedSchedule = array_replace($waiting, [
    'batch_start_at' => '2026-09-15 08:00:00',
    'batch_end_at' => '2026-09-15 12:00:00',
]);

expect_four_step(
    ape_schedule_is_current($fixedSchedule, new DateTimeImmutable('2026-09-15 10:00:00')),
    'Patient must be addressable during the assigned batch time.'
);
expect_four_step(
    !ape_schedule_is_current($fixedSchedule, new DateTimeImmutable('2026-09-15 07:59:59')),
    'Examination must remain unavailable before the assigned batch.'
);
expect_four_step(
    !ape_schedule_is_current($fixedSchedule, new DateTimeImmutable('2026-09-15 12:00:01')),
    'Examination must become unavailable after the assigned batch.'
);
$now = new DateTimeImmutable('now');
$scheduledNow = array_replace($waiting, [
    'batch_start_at' => $now->modify('-1 minute')->format('Y-m-d H:i:s'),
    'batch_end_at' => $now->modify('+1 minute')->format('Y-m-d H:i:s'),
]);
expect_four_step(ape_record_queue($waiting) === 'digital_submission', 'Digital Keeping must be first before the examination window.');
expect_four_step(ape_record_step_index($waiting) === 0, 'Digital Keeping must be step one.');
expect_four_step(ape_record_queue($scheduledNow) === 'examination', 'The active schedule must allow examination despite incomplete files.');
expect_four_step(ape_record_step_index($scheduledNow) === 1, 'Examination must be step two.');

$digital = array_replace($waiting, ['exam_date' => '2026-09-15']);
expect_four_step(ape_record_queue($digital) === 'digital_submission', 'Incomplete regular files must return to Digital Keeping after examination.');
expect_four_step(ape_record_step_index($digital) === 0, 'Digital Keeping remains step one while regular files are incomplete.');
$deadline = ape_deadline_status($digital, new DateTimeImmutable('2026-09-22'));
expect_four_step(($deadline['label'] ?? '') === 'On Track' && ($deadline['due_date'] ?? '') === '2026-09-22', 'Regular uploads must allow the full seven days after examination.');

$final = array_replace($digital, [
    'initial_requirement_count' => 1,
    'requirement_count' => 1,
    'required_document_count' => 1,
    'required_unverified_count' => 0,
    'unassigned_upload_count' => 0,
    'deferred_requirement_count' => 0,
    'deferred_document_count' => 0,
    'deferred_unverified_count' => 0,
    'follow_up_required' => 0,
]);
expect_four_step(ape_record_queue($final) === 'final_decision', 'Archived documents must advance to final decision.');
expect_four_step(ape_record_step_index($final) === 2, 'Final decision or follow-up must be step three.');

$completed = array_replace($final, ['workflow_status' => 'Cleared', 'clearance_status' => 'Cleared']);
expect_four_step(ape_record_queue($completed) === 'completed', 'Cleared record must enter the completed queue.');
expect_four_step(ape_record_step_index($completed) === 3, 'Completed must be step four.');

$batchNow = new DateTimeImmutable('2026-09-15 09:00:00');
$batches = [
    ['batch_id' => 1, 'status' => 'Scheduled', 'schedule_date' => '2026-09-14', 'start_time' => '08:00:00', 'end_time' => '10:00:00'],
    ['batch_id' => 3, 'status' => 'Scheduled', 'schedule_date' => '2026-09-16', 'start_time' => '13:00:00', 'end_time' => '15:00:00'],
    ['batch_id' => 2, 'status' => 'Scheduled', 'schedule_date' => '2026-09-16', 'start_time' => '08:00:00', 'end_time' => '10:00:00'],
    ['batch_id' => 4, 'status' => 'Cancelled', 'schedule_date' => '2026-09-15', 'start_time' => '10:00:00', 'end_time' => '11:00:00'],
];
expect_four_step((ape_earliest_upcoming_batch($batches, $batchNow)['batch_id'] ?? 0) === 2, 'Default APE scope must select the earliest non-ended scheduled batch.');
expect_four_step(ape_earliest_upcoming_batch([$batches[0]], $batchNow) === null, 'No upcoming batch must allow the page to fall back to Overall.');

$indexSource = file_get_contents(__DIR__ . '/../public/ape/index.php');
expect_four_step(str_contains($indexSource, "['queue' => \$activeQueue, 'scope' => 'overall', 'population' => \$populationScope]"), 'Overall selection must remain explicit in its population-aware link.');
expect_four_step(str_contains($indexSource, "name=\"scope\" value=\"overall\""), 'Search must preserve the explicit Overall scope.');

echo "APE four-step workflow tests passed.\n";
