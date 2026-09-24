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
expect_four_step(ape_staff_progress($scheduledNow)['active_step'] === 2, 'Once the assigned examination schedule starts, staff cards must show Examination even while uploads remain incomplete.');
expect_four_step(ape_student_progress($scheduledNow)['active_step'] === 2, 'Once the assigned examination schedule starts, student cards must show Examination while preserving document upload availability.');
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
$studentDigitalProgress = ape_student_progress($digital);
expect_four_step($studentDigitalProgress['percent'] === 25 && $studentDigitalProgress['active_step'] === 3 && $studentDigitalProgress['steps'][2]['done'], 'After examination, students must remain in Final Decision or Follow-up while initial uploads continue.');
 $staffDigitalProgress = ape_staff_progress($digital);
expect_four_step($staffDigitalProgress['active_step'] === 3 && $staffDigitalProgress['steps'][2]['done'], 'After examination, staff cards must keep Final Decision or Follow-up active while uploads remain open.');
expect_four_step(ape_work_queue_stage($digital) === 'digital_submission', 'The patient-facing compatibility resolver keeps incomplete initial uploads in Digital Keeping.');
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
$studentSubmittedReview = ape_student_progress(array_replace($final, ['required_unverified_count' => 1]));
expect_four_step($studentSubmittedReview['percent'] === 50 && $studentSubmittedReview['active_step'] === 3, 'A complete submitted batch and recorded examination must show Final Decision at 50 percent while clinic review is pending.');
expect_four_step($studentSubmittedReview['steps'][1]['submitted'], 'Student progress must distinguish submitted documents from archived verification.');
$studentArchivedReview = ape_student_progress($final);
expect_four_step($studentArchivedReview['percent'] === 50 && $studentArchivedReview['active_step'] === 3, 'Archived documents must remain at Final Decision until the clinic records a clinical outcome.');
$returnedDocument = ape_student_progress(array_replace($final, ['verification_status' => 'Needs Correction']));
expect_four_step($returnedDocument['active_step'] === 3 && $returnedDocument['percent'] === 50 && $returnedDocument['steps'][1]['done'], 'A returned document must remain in Follow-up without moving the student backward to Digital Keeping.');
expect_four_step(ape_record_queue(array_replace($final, ['verification_status' => 'Needs Correction'])) === 'follow_up', 'A returned document must remain in the Follow-up staff queue.');
expect_four_step(!ape_has_urgent_action(array_replace($final, ['verification_status' => 'Needs Correction'])), 'A returned document must remain outside the Missed/Overdue immediate-attention badge.');
$submittedReview = array_replace($final, ['required_unverified_count' => 1]);
$staffSubmittedQueue = ape_staff_queue_stage($submittedReview);
expect_four_step($staffSubmittedQueue === 'final_decision', 'Staff queue must place submitted files awaiting clinic review in Final Decision.');
expect_four_step(!ape_has_urgent_action($submittedReview), 'Routine clinic document review must not create an urgent sidebar badge.');
$staffSubmittedProgress = ape_staff_progress($submittedReview);
expect_four_step($staffSubmittedProgress['active_step'] === 3, 'Staff progress must activate Final Decision when the complete initial upload group is submitted and awaiting archive review.');
expect_four_step($staffSubmittedProgress['steps'][1]['submitted'] && !$staffSubmittedProgress['steps'][1]['done'], 'A submitted upload group must be distinct from completed archive review.');
expect_four_step($staffSubmittedProgress['steps'][2]['done'], 'A saved examination must remain completed during document archive review.');
$staffArchivedProgress = ape_staff_progress($final);
expect_four_step($staffArchivedProgress['active_step'] === 3, 'Archived documents must keep Final Decision active until clearance or follow-up is recorded.');

$followUp = array_replace($final, [
    'workflow_status' => 'Follow-up Required',
    'clearance_status' => 'For Follow-up',
    'follow_up_required' => 1,
]);
expect_four_step(ape_staff_queue_stage($followUp) === 'follow_up', 'Staff queue must place open follow-up records in Follow-up.');
$followUpProgress = ape_patient_progress($followUp);
expect_four_step($followUpProgress['percent'] === 75 && $followUpProgress['active_step'] === 4, 'A recorded follow-up decision must complete Step 3 and activate Step 4 at 75 percent.');
expect_four_step(ape_student_progress($followUp)['percent'] === 50 && ape_student_progress($followUp)['active_step'] === 3, 'Student progress must keep required follow-up inside Final Decision or Follow-up without advancing to Completed.');
expect_four_step(ape_staff_progress($followUp)['active_step'] === 3, 'Staff progress must keep Final Decision or Follow-up active while follow-up remains open.');
$followUpDocuments = array_replace($final, [
    'workflow_status' => 'Follow-up Required',
    'clearance_status' => 'For Follow-up',
    'follow_up_required' => 1,
    'deferred_requirement_count' => 1,
    'deferred_document_count' => 0,
    'deferred_unverified_count' => 0,
]);
expect_four_step(ape_follow_up_stage_active($followUpDocuments), 'A document-only follow-up request must remain on the Follow-up path before upload.');
expect_four_step(ape_record_queue($followUpDocuments) === 'follow_up', 'A document-only follow-up request must remain in the Follow-up queue.');
expect_four_step(!ape_explicit_clearance_required($followUpDocuments), 'A document-only follow-up request must not require clearance automatically.');
expect_four_step(!ape_can_complete_record($followUpDocuments), 'A record with an unarchived follow-up document must not be completable.');
$followUpDocumentsSubmitted = array_replace($followUpDocuments, [
    'deferred_document_count' => 1,
    'deferred_unverified_count' => 1,
]);
expect_four_step(ape_follow_up_document_request_active($followUpDocumentsSubmitted), 'Submitted Follow-up documents must remain active for clinic review.');
expect_four_step(!ape_can_complete_record($followUpDocumentsSubmitted), 'A pending follow-up upload must be archived before completion.');
$explicitClearance = array_replace($followUpDocuments, ['clearance_requirement_count' => 1]);
expect_four_step(ape_explicit_clearance_required($explicitClearance), 'An explicitly created clearance requirement must remain mandatory.');
$examinedNormal = array_replace($final, ['result_status' => 'Normal']);
$examinedFinding = array_replace($final, ['result_status' => 'With Finding']);
expect_four_step(in_array($examinedNormal['result_status'], ['Normal', 'With Finding'], true) && in_array($examinedFinding['result_status'], ['Normal', 'With Finding'], true), 'Legacy examination result values must remain compatible.');

$completed = array_replace($final, ['workflow_status' => 'Cleared', 'clearance_status' => 'Cleared']);
expect_four_step(ape_record_queue($completed) === 'completed', 'Cleared record must enter the completed queue.');
expect_four_step(ape_staff_queue_stage($completed) === 'completed', 'Staff queue must place cleared records in Completed.');
expect_four_step(ape_work_queue_stage($completed) === 'completed', 'The Work Queue Map must place a cleared record in Completed.');
expect_four_step(ape_record_step_index($completed) === 3, 'Completed must be step four.');
expect_four_step(ape_patient_progress($completed)['percent'] === 100 && ape_patient_progress($completed)['completed_count'] === 4, 'A cleared APE record must complete all four student steps.');
expect_four_step(ape_student_progress($completed)['percent'] === 100 && ape_student_progress($completed)['active_step'] === 4, 'A cleared APE record must show Completed at 100 percent for students.');
$staffCompletedProgress = ape_staff_progress($completed);
expect_four_step($staffCompletedProgress['active_step'] === 4 && $staffCompletedProgress['steps'][4]['done'] && $staffCompletedProgress['steps'][4]['active'], 'A cleared record must show Completed as the active completed staff card.');

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
expect_four_step(($hardCopyAction[0]['action_type'] ?? '') === 'document_correction', 'Requirement corrections must use the unified Phase 3 document-correction action.');
expect_four_step(($hardCopyAction[0]['deduplication_key'] ?? '') === 'ape_requirement:73:document_correction', 'Requirement correction keys must remain requirement-specific.');
expect_four_step(($hardCopyAction[0]['email_event_type'] ?? '') === 'ape_document_correction_required', 'Requirement corrections must use the standard urgent email event.');

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
expect_four_step(!str_contains($viewSource, 'data-final-decision-documents'), 'Submitted document review must not duplicate files in a separate outstanding-documents panel.');
expect_four_step(str_contains($viewSource, "in_array(\$archiveQueue, ['final_decision', 'follow_up'], true)"), 'Final Decision and Follow-up must allow pending regular uploads to be archived without returning to Digital Keeping.');
expect_four_step(str_contains($viewSource, '$canComplete = ape_can_complete_record($record);'), 'Completion must remain protected by the shared document and follow-up predicate.');
expect_four_step(str_contains($viewSource, "\$patientProgress = ape_patient_progress(\$record);"), 'The admin APE record must retain patient progress for patient-facing status copy.');
expect_four_step(str_contains($viewSource, "\$staffProgress = ape_staff_progress(\$record);"), 'The admin APE record must use the staff progress resolver for its workflow strip.');
expect_four_step(str_contains($viewSource, "\$currentStep = \$staffProgress['active_step'];"), 'The admin active step must come from the staff workflow resolver.');
expect_four_step(str_contains($viewSource, 'DOCUMENTS STILL NEEDED'), 'The admin header must identify incomplete student documents instead of showing Final Decision prematurely.');
expect_four_step(str_contains($viewSource, 'Examination Completed'), 'The schedule card must distinguish examination completion from APE completion.');
expect_four_step(str_contains($viewSource, "\$stepState = \$staffProgress['steps'][\$stepNumber];"), 'The admin stepper must use staff workflow step states.');
expect_four_step(str_contains($viewSource, "\$submitted ? 'Submitted'"), 'The staff workflow strip must distinguish submitted documents from completed steps.');
expect_four_step(str_contains($viewSource, 'Return selected files'), 'Document review must expose a clear return-for-resubmission action.');
expect_four_step(str_contains($viewSource, 'data-document-review-actions'), 'Submitted documents must use one mode-switching review-action panel.');
expect_four_step(str_contains($viewSource, 'data-document-review-mode="archive"'), 'The review-action panel must default to archive mode.');
expect_four_step(str_contains($viewSource, 'The student receives a correction notification and an email when delivery is enabled.'), 'Returning documents must explain the automatic notification and email behavior.');
expect_four_step(str_contains($viewSource, '<?php if ($reviewWorkspaceActive): ?>') && str_contains($viewSource, "<?php elseif (\$queueKey === 'final_decision'): ?>"), 'Phase 3 review must suppress downstream final clinical decisions until document review is resolved.');
expect_four_step(str_contains($viewSource, 'Enter the reason the student must correct and resubmit'), 'Returning a document must require staff instructions.');
expect_four_step(str_contains($viewSource, "verification_status = 'Needs Correction'"), 'Returning a document must preserve its file record and mark it for correction.');
expect_four_step(str_contains($viewSource, 'Returned document(s) for resubmission'), 'Returned documents must have an explicit activity-history label.');
expect_four_step(str_contains($viewSource, "upload_group = 'follow_up'"), 'Returned documents must remain in the Follow-up upload group.');
expect_four_step(str_contains($viewSource, "value=\"Examined\""), 'The examination form must consolidate Normal and With Finding into Examined.');

$patientStatusSource = file_get_contents(__DIR__ . '/../patient-portal/patient-ape-status.php');
$patientDashboardSource = file_get_contents(__DIR__ . '/../patient-portal/patient-dashboard.php');
expect_four_step(str_contains($patientStatusSource, '$studentProgress = ape_student_progress($apeRecord ?? []);'), 'The APE status page must use the student-display progress resolver.');
expect_four_step(str_contains($patientStatusSource, '$canUploadDocuments = $apeRecord') && !str_contains($patientStatusSource, '$canUploadDocuments = $apeRecord\n    && $hasScheduledBatch'), 'Document controls must not require a scheduled batch.');
expect_four_step(str_contains($patientStatusSource, '$apePercent = $studentProgress[\'percent\'];'), 'The APE status page must display the student-facing progress percentage.');
expect_four_step(str_contains($patientStatusSource, "\$stepClass = \$isDone ? 'is-done' : (\$isActive ? 'is-current' : 'is-locked');"), 'Every later unfinished APE step must remain closed until its predecessor is complete.');
expect_four_step(str_contains($patientStatusSource, "'action' => \$verification === 'Needs Correction' ? 'Replace'"), 'A returned document must give the student a replacement-upload action.');
expect_four_step(str_contains($patientDashboardSource, '$apeProgress = ape_student_progress($latestApe ?? []);'), 'The dashboard must use the student-display progress resolver.');
expect_four_step(str_contains($patientStatusSource, 'follow-up documents. Upload them below'), 'Students must receive Follow-up document instructions without being sent back to Step 1.');
expect_four_step(str_contains($patientDashboardSource, "in_array((int) \$apeProgress['active_step'], [1, 2, 4], true)"), 'The dashboard must not present clinic review as a student action.');
expect_four_step(str_contains($patientDashboardSource, '$apePercent = $apeProgress[\'percent\'];'), 'The dashboard must display the shared progress percentage.');
expect_four_step(str_contains($patientStatusSource, 'max(1, (int) $currentStep)'), 'The APE summary must show the calculated current step.');

echo "APE four-step workflow tests passed.\n";
