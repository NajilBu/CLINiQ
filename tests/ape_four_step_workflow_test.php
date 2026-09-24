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
    'The original batch window must be reported as ended after its end time.'
);
expect_four_step(
    !ape_examination_is_available($fixedSchedule, new DateTimeImmutable('2026-09-15 07:59:59')),
    'Examination must remain unavailable before the assigned batch starts.'
);
expect_four_step(
    ape_examination_is_available($fixedSchedule, new DateTimeImmutable('2026-09-15 12:00:01')),
    'A missed patient must remain examinable after the assigned batch ends.'
);
$now = new DateTimeImmutable('now');
$scheduledNow = array_replace($waiting, [
    'batch_start_at' => $now->modify('-1 minute')->format('Y-m-d H:i:s'),
    'batch_end_at' => $now->modify('+1 minute')->format('Y-m-d H:i:s'),
]);
expect_four_step(ape_record_queue($waiting) === 'digital_submission', 'Digital Keeping must be first before the examination window.');
expect_four_step(ape_record_step_index($waiting) === 0, 'Digital Keeping must be step one in the staff queue.');
expect_four_step(ape_patient_progress($waiting)['percent'] === 0 && ape_patient_progress($waiting)['active_step'] === 1, 'An incomplete record before examination day must start at Step 1 and zero percent.');
expect_four_step(ape_record_queue($scheduledNow) === 'examination', 'The active schedule must allow examination despite incomplete files.');
expect_four_step(ape_record_step_index($scheduledNow) === 1, 'Examination must be step two in the staff queue.');
expect_four_step(ape_patient_progress($scheduledNow)['percent'] === 0 && ape_patient_progress($scheduledNow)['active_step'] === 1, 'Opening the examination window must not complete an unfinished student checklist step.');
$missedSchedule = array_replace($fixedSchedule, [
    'batch_start_at' => $now->modify('-2 hours')->format('Y-m-d H:i:s'),
    'batch_end_at' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
]);
expect_four_step(ape_record_queue($missedSchedule) === 'examination', 'A missed patient must remain in the examination queue.');
expect_four_step(ape_next_action($missedSchedule)['label'] === 'Record Examination', 'A missed patient must show the Record Examination action.');

$digital = array_replace($waiting, ['exam_date' => '2026-09-15']);
expect_four_step(ape_record_queue($digital) === 'final_decision', 'Incomplete regular files must remain in Final Decision after examination.');
expect_four_step(ape_record_step_index($digital) === 2, 'A saved examination must not move backward in the staff queue.');
$digitalProgress = ape_patient_progress($digital);
expect_four_step($digitalProgress['percent'] === 25 && $digitalProgress['active_step'] === 1, 'An exam with incomplete Digital Keeping must show only the examination as complete and keep Step 1 active.');
expect_four_step(ape_work_queue_stage($digital) === 'digital_submission', 'The Work Queue Map must place an examined patient with incomplete documents in Digital Keeping.');
expect_four_step($digitalProgress['steps'][3]['locked'] && $digitalProgress['steps'][4]['locked'], 'Later steps must remain locked while Digital Keeping is incomplete.');
$deadline = ape_deadline_status($digital, new DateTimeImmutable('2026-09-22'));
expect_four_step(($deadline['label'] ?? '') === 'On Track' && ($deadline['due_date'] ?? '') === '2026-09-22', 'Regular uploads must allow the full seven days after examination.');
expect_four_step(ape_deadline_status($digital, new DateTimeImmutable('2026-09-23'))['label'] === 'Overdue', 'Final Decision must show overdue regular uploads after the seven-day deadline.');

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
expect_four_step(ape_work_queue_stage($final) === 'final_decision', 'The Work Queue Map must place a complete document submission in Final Decision.');
expect_four_step(ape_record_step_index($final) === 2, 'Final decision or follow-up must be step three.');
$finalProgress = ape_patient_progress($final);
expect_four_step($finalProgress['percent'] === 50 && $finalProgress['active_step'] === 3, 'Completed uploads and examination must show 50 percent with Final Decision active.');

$followUp = array_replace($final, [
    'workflow_status' => 'Follow-up Required',
    'clearance_status' => 'For Follow-up',
    'follow_up_required' => 1,
]);
$followUpProgress = ape_patient_progress($followUp);
expect_four_step($followUpProgress['percent'] === 75 && $followUpProgress['active_step'] === 4, 'A recorded follow-up decision must complete Step 3 and activate Step 4 at 75 percent.');

$completed = array_replace($final, ['workflow_status' => 'Cleared', 'clearance_status' => 'Cleared']);
expect_four_step(ape_record_queue($completed) === 'completed', 'Cleared record must enter the completed queue.');
expect_four_step(ape_work_queue_stage($completed) === 'completed', 'The Work Queue Map must place a cleared record in Completed.');
expect_four_step(ape_record_step_index($completed) === 3, 'Completed must be step four.');
expect_four_step(ape_patient_progress($completed)['percent'] === 100 && ape_patient_progress($completed)['completed_count'] === 4, 'A cleared APE record must complete all four student steps.');

$actionBase = array_replace($waiting, [
    'id' => 41,
    'patient_id' => 99,
    'schedule_batch_id' => null,
    'exam_date' => null,
    'requirement_count' => 1,
    'initial_requirement_count' => 1,
    'required_document_count' => 0,
    'required_unverified_count' => 0,
    'unassigned_upload_count' => 0,
    'initial_upload_due_date' => null,
    'workflow_status' => 'Batch Assigned',
]);
$assignAction = ape_normalized_action_items($actionBase, [], []);
expect_four_step(($assignAction[0]['action_type'] ?? '') === 'assign_schedule', 'Unscheduled APE records must expose Assign APE schedule.');

$uploadAction = ape_normalized_action_items(array_replace($actionBase, ['schedule_batch_id' => 8]), [], []);
expect_four_step(($uploadAction[0]['action_type'] ?? '') === 'wait_for_patient_upload', 'Missing patient uploads must remain patient-owned work.');

$reviewAction = ape_normalized_action_items(array_replace($actionBase, [
    'schedule_batch_id' => 8,
    'required_document_count' => 1,
    'required_unverified_count' => 1,
]), [], [['document_id' => 71, 'document_type' => 'Medical', 'verification_status' => 'Pending']]);
expect_four_step(($reviewAction[0]['action_type'] ?? '') === 'review_patient_upload', 'Submitted patient uploads must expose review work.');

$examAction = ape_normalized_action_items(array_replace($actionBase, [
    'entry_mode' => 'Clinic Manual',
    'schedule_batch_id' => 8,
]), [], []);
expect_four_step(($examAction[0]['action_type'] ?? '') === 'record_examination', 'Available examinations must expose Record examination.');

$correctionAction = ape_normalized_action_items(array_replace($final, ['id' => 42, 'patient_id' => 99]), [], [[
    'document_id' => 72,
    'document_type' => 'Medical',
    'verification_status' => 'Needs Correction',
    'verification_remarks' => 'Use a clearer scan.',
]]);
expect_four_step(($correctionAction[0]['action_type'] ?? '') === 'online_document_correction', 'Online corrections must be distinct actions.');
expect_four_step(($correctionAction[0]['deduplication_key'] ?? '') === 'ape_document:72:online_document_correction', 'Online correction keys must be document-specific.');
expect_four_step(($correctionAction[0]['email_event_type'] ?? '') === 'ape_document_correction_required', 'Online corrections must map to the urgent email event.');

$hardCopyAction = ape_normalized_action_items(array_replace($final, ['id' => 43, 'patient_id' => 99]), [[
    'requirement_id' => 73,
    'status' => 'Needs Correction',
    'remarks' => 'Return the original copy.',
]], []);
expect_four_step(($hardCopyAction[0]['action_type'] ?? '') === 'hard_copy_correction', 'Hard-copy corrections must be distinct actions.');
expect_four_step(($hardCopyAction[0]['deduplication_key'] ?? '') === 'ape_requirement:73:hard_copy_correction', 'Hard-copy correction keys must be requirement-specific.');

$followUpAction = ape_normalized_action_items(array_replace($final, [
    'id' => 44,
    'patient_id' => 99,
    'follow_up_required' => 1,
    'clearance_status' => 'For Follow-up',
]), [], []);
expect_four_step(($followUpAction[0]['action_type'] ?? '') === 'require_follow_up', 'Unsubmitted follow-up work must expose Require follow-up.');
expect_four_step(($followUpAction[0]['email_event_type'] ?? '') === 'ape_follow_up_required', 'Follow-up work must map to the urgent email event.');

$followUpReviewAction = ape_normalized_action_items(array_replace($final, [
    'id' => 45,
    'patient_id' => 99,
    'follow_up_required' => 1,
    'clearance_status' => 'Submitted',
    'deferred_document_count' => 1,
]), [], []);
expect_four_step(($followUpReviewAction[0]['action_type'] ?? '') === 'review_follow_up_documents', 'Submitted follow-up documents must expose clinic review.');
expect_four_step(ape_normalized_action_items($completed, [], []) === [], 'Completed APE records must not create active action items.');

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
expect_four_step(str_contains($indexSource, "'Examine Patient'"), 'Missed-patient alerts must provide an examination action.');
expect_four_step(str_contains($indexSource, 'ape_normalized_action_items($rec)'), 'APE Work Queues must render normalized actions.');
$auditSource = file_get_contents(__DIR__ . '/../public/audit/index.php');
expect_four_step($auditSource !== false && str_contains($auditSource, 'send_clinic_reminders'), 'Email Center must provide the dedicated clinic reminder action.');
expect_four_step(!str_contains($indexSource, 'remind_ape=1'), 'APE Work Queues must not open the obsolete generic reminder composer.');

$viewSource = file_get_contents(__DIR__ . '/../public/ape/view.php');
expect_four_step(str_contains($viewSource, 'data-final-decision-documents'), 'Final Decision must display outstanding regular documents.');
expect_four_step(str_contains($viewSource, "in_array(\$archiveQueue, ['final_decision', 'follow_up'], true)"), 'Final Decision and Follow-up must allow pending regular uploads to be archived without returning to Digital Keeping.');
expect_four_step(str_contains($viewSource, 'Complete and archive every regular digital document before clearing the patient.'), 'Clearance must remain protected until regular digital documents are archived.');
expect_four_step(str_contains($viewSource, "\$patientProgress = ape_patient_progress(\$record);"), 'The admin APE record must use the shared student progress resolver.');
expect_four_step(str_contains($viewSource, "\$currentStep = \$patientProgress['active_step'];"), 'The admin active step must come from the shared student progress resolver.');
expect_four_step(str_contains($viewSource, 'DOCUMENTS STILL NEEDED'), 'The admin header must identify incomplete student documents instead of showing Final Decision prematurely.');
expect_four_step(str_contains($viewSource, 'Examination Completed'), 'The schedule card must distinguish examination completion from APE completion.');
expect_four_step(str_contains($viewSource, "\$stepState = \$patientProgress['steps'][\$stepNumber];"), 'The admin stepper must reuse the shared student step states.');
expect_four_step(str_contains($viewSource, 'Return for resubmission'), 'Document review must expose a clear return-for-resubmission action.');
expect_four_step(str_contains($viewSource, 'Enter the reason the student must correct and resubmit'), 'Returning a document must require staff instructions.');
expect_four_step(str_contains($viewSource, "verification_status = 'Needs Correction'"), 'Returning a document must preserve its file record and mark it for correction.');
expect_four_step(str_contains($viewSource, 'Returned document(s) for resubmission'), 'Returned documents must have an explicit activity-history label.');

$patientStatusSource = file_get_contents(__DIR__ . '/../patient-portal/patient-ape-status.php');
$patientDashboardSource = file_get_contents(__DIR__ . '/../patient-portal/patient-dashboard.php');
expect_four_step(str_contains($patientStatusSource, '$patientProgress = ape_patient_progress($apeRecord ?? []);'), 'The APE status page must use the shared patient-progress resolver.');
expect_four_step(str_contains($patientStatusSource, '$canUploadDocuments = $apeRecord') && !str_contains($patientStatusSource, '$canUploadDocuments = $apeRecord\n    && $hasScheduledBatch'), 'Document controls must not require a scheduled batch.');
expect_four_step(str_contains($patientStatusSource, '$apePercent = $patientProgress[\'percent\'];'), 'The APE status page must display the shared progress percentage.');
expect_four_step(str_contains($patientStatusSource, "\$stepClass = \$isDone ? 'is-done' : (\$isActive ? 'is-current' : 'is-locked');"), 'Every later unfinished APE step must remain closed until its predecessor is complete.');
expect_four_step(str_contains($patientStatusSource, "'action' => \$verification === 'Needs Correction' ? 'Replace'"), 'A returned document must give the student a replacement-upload action.');
expect_four_step(str_contains($patientDashboardSource, '$apeProgress = ape_patient_progress($latestApe ?? []);'), 'The dashboard must use the shared patient-progress resolver.');
expect_four_step(str_contains($patientDashboardSource, '$apePercent = $apeProgress[\'percent\'];'), 'The dashboard must display the shared progress percentage.');
expect_four_step(str_contains($patientStatusSource, 'max(1, (int) $currentStep)'), 'The APE summary must show the calculated current step.');

echo "APE four-step workflow tests passed.\n";
