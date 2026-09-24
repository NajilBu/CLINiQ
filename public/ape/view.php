<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../../app/services/PatientNotification.php';
require_once __DIR__ . '/../../app/services/PatientEmail.php';
require_once __DIR__ . '/../../app/services/PatientAccessStatus.php';
require_login();
ensure_ape_workflow_schema();

$id = (int)($_GET['id'] ?? 0);

function fetch_ape_record(int $id): ?array
{
    return ape_fetch_record($id);
}

function ape_follow_up_due_date_from_post(): ?string
{
    $dueDate = trim((string) ($_POST['follow_up_due_date'] ?? ''));

    if ($dueDate !== '') {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $dueDate);
        if (!$parsed || $parsed->format('Y-m-d') !== $dueDate) {
            throw new InvalidArgumentException('Select a valid follow-up due date.');
        }
        return $dueDate;
    }

    return null;
}

function render_ape_final_decision_actions(array $record, bool $canRecordApeExam, bool $digitalSubmissionComplete): void
{
    $canComplete = ape_can_complete_record($record);
    ?>
    <?php if (!$canRecordApeExam): ?>
        <div class="ape-flow-action muted">
            <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-amber-700 mt-0.5">lock</span>
                <div>
                    <h3 class="font-headline text-base font-extrabold text-amber-900 mb-1">Clinical permission required</h3>
                    <p class="text-sm font-bold text-amber-800 mb-0">Only administrators, doctors, and nurses can record the final APE decision.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="ape-flow-action mb-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <p class="clinic-label mb-1">Examination Recorded</p>
                    <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1"><?= e(date('M d, Y', strtotime($record['exam_date']))) ?></h3>
                    <p class="text-xs font-bold text-slate-500 mb-0"><?= $canComplete ? 'All active documents are archived and no clinical follow-up remains.' : 'Archive or return every active document, then resolve any clinical follow-up before completing the APE.' ?></p>
                </div>
                <span class="badge badge-pending">Final Decision Required</span>
            </div>
        </div>
        <div class="ape-decision-actions" data-ape-decision-actions>
            <input class="ape-decision-mode-input" type="radio" name="ape_decision_mode" id="apeDecisionClear" value="clear" <?= $canComplete ? 'checked' : '' ?>>
            <input class="ape-decision-mode-input" type="radio" name="ape_decision_mode" id="apeDecisionFollowUp" value="follow-up" <?= !$canComplete ? 'checked' : '' ?>>
            <div class="ape-decision-action-bar" role="radiogroup" aria-label="Final APE decision">
                <label class="ape-decision-action ape-decision-action-clear" for="apeDecisionClear">
                    <span class="ape-decision-action-icon material-symbols-outlined" aria-hidden="true">verified</span>
                    <span class="ape-decision-action-copy"><strong>Complete APE</strong><span><?= $canComplete ? 'Ready for clinic confirmation' : 'Outstanding documents or follow-up remain' ?></span></span>
                    <span class="badge <?= $canComplete ? 'badge-completed' : 'badge-pending' ?>"><?= $canComplete ? 'Ready' : 'Not ready' ?></span>
                </label>
                <label class="ape-decision-action ape-decision-action-follow-up" for="apeDecisionFollowUp">
                    <span class="ape-decision-action-icon material-symbols-outlined" aria-hidden="true">medical_information</span>
                    <span class="ape-decision-action-copy"><strong>Require follow-up</strong><span>Keep the APE open for a required action</span></span>
                    <span class="badge badge-pending">Follow-up</span>
                </label>
            </div>
            <div class="ape-decision-form" data-decision-form="clear">
            <form method="post" class="ape-flow-action space-y-3">
                <input type="hidden" name="action" value="finalize_exam_clear">
                <div>
                    <h3 class="font-headline text-base font-extrabold text-[#17261d] mb-1">Complete APE</h3>
                    <p class="text-xs font-bold text-slate-500 mb-3"><?= $canComplete ? 'Confirm that the examination, all active documents, and follow-up work are complete.' : 'Archive every active document and resolve clinical follow-up before completing this patient.' ?></p>
                    <label class="clinic-label">Patient-Visible Note</label>
                    <textarea class="clinic-textarea" name="patient_visible_note" rows="3" placeholder="Final clearance message..."><?= e($record['patient_visible_note']) ?></textarea>
                </div>
                <button class="btn btn-primary w-full" <?= $canComplete ? '' : 'disabled' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Complete this APE?" data-confirm-message="This will complete the annual APE record." data-confirm-toast="Completing APE record..."><span class="material-symbols-outlined text-[18px]">check_circle</span> Complete APE</button>
            </form>
            </div>
            <div class="ape-decision-form" data-decision-form="follow-up">
            <form method="post" class="space-y-4">
                <input type="hidden" name="action" value="finalize_exam_follow_up">
                <div class="ape-follow-up-plan-header">
                    <div>
                        <h3 class="font-headline text-base font-extrabold text-[#17261d] mb-1">Follow-up plan</h3>
                        <p class="ape-follow-up-plan-help mb-0">Record what the patient must complete before the APE can be cleared.</p>
                    </div>
                </div>
                <div>
                    <label class="clinic-label" for="apeFollowUpNotes">Clinic plan</label>
                    <textarea class="clinic-textarea" id="apeFollowUpNotes" name="follow_up_notes" rows="3" placeholder="Treatment, repeat test, clearance, or other follow-up..." required></textarea>
                    <p class="ape-follow-up-plan-help mt-2 mb-0">Clinic-only context for treatment, repeat testing, referral, or another clinical action. Add a follow-up document below when the student must upload a specific file; that document carries the student instructions and due date.</p>
                </div>
                <button class="btn btn-outline w-full" style="color:#b45309;border-color:rgba(180,83,9,0.2);" data-confirm-submit data-confirm-type="danger" data-confirm-title="Save follow-up plan?" data-confirm-message="The APE record will remain open until the follow-up is cleared." data-confirm-toast="Saving follow-up plan..."><span class="material-symbols-outlined text-[18px]">save</span> Save follow-up plan</button>
            </form>
            <details class="ape-inline-document-requirement">
                <summary><span class="material-symbols-outlined" aria-hidden="true">upload_file</span><span><strong>Request a document from the student</strong><small>Optional — use only when a specific file is needed.</small></span></summary>
                <div class="ape-inline-document-requirement-body">
                    <p class="ape-follow-up-plan-help mt-0 mb-3">The document title, student instructions, and due date are saved as one upload task in Follow-up. Leave this closed when the clinical plan does not require a file.</p>
                    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <input type="hidden" name="action" value="add_requirement">
                        <input type="hidden" name="requirement_item_status" value="Missing">
                        <div><label class="clinic-label" for="apeDecisionFollowUpDocumentName">Document title</label><input class="clinic-input" id="apeDecisionFollowUpDocumentName" name="requirement_name" maxlength="160" placeholder="e.g. Specialist clearance certificate" required></div>
                        <div><label class="clinic-label" for="apeDecisionFollowUpDocumentDueDate">Due date</label><input class="clinic-input" id="apeDecisionFollowUpDocumentDueDate" name="requirement_due_date" type="date" min="<?= e(date('Y-m-d')) ?>" required></div>
                        <div class="md:col-span-2"><label class="clinic-label" for="apeDecisionFollowUpDocumentInstructions">Patient instructions</label><textarea class="clinic-textarea" id="apeDecisionFollowUpDocumentInstructions" name="requirement_instructions" rows="2" placeholder="Explain exactly what the student must submit." required></textarea></div>
                        <div><label class="clinic-label" for="apeDecisionFollowUpDocumentRemark">Clinic-only remark (optional)</label><input class="clinic-input" id="apeDecisionFollowUpDocumentRemark" name="requirement_remarks" placeholder="Internal context"></div>
                        <div class="flex items-end"><button class="btn btn-outline w-full" data-confirm-submit data-confirm-title="Add follow-up requirement?" data-confirm-message="The student will receive an upload task in Final Decision or Follow-up." data-confirm-toast="Adding follow-up requirement..."><span class="material-symbols-outlined text-[18px]">playlist_add</span> Add follow-up document</button></div>
                    </form>
                </div>
            </details>
            </div>
        </div>
    <?php endif; ?>
    <?php
}

$record = fetch_ape_record($id);
$apeUser = current_user() ?? [];
$canRecordApeExam = in_array((string) ($apeUser['role'] ?? ''), ['admin', 'doctor', 'nurse'], true);
$canUploadApeDocument = in_array((string) ($apeUser['role'] ?? ''), ['admin', 'doctor', 'nurse', 'staff', 'it_expert'], true);

if (!$record) {
    flash_message('error', 'APE record not found.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $staffPersonId = (int) (current_user()['person_id'] ?? 0);
    $activityLabel = null;
    $activityNotes = null;
    $apeDb = auth_db();
    $storedClinicDocument = null;
    $clinicalActions = ['record_examination', 'update_clinical_information', 'finalize_exam_clear', 'finalize_exam_follow_up', 'resolve_clinical_follow_up'];

    try {
        if ($staffPersonId <= 0) {
            throw new RuntimeException('The logged-in staff account is not linked to Cliniq_db.');
        }
        if (in_array($action, $clinicalActions, true) && !$canRecordApeExam) {
            throw new RuntimeException('Only administrators, doctors, and nurses can record or finalize an APE examination.');
        }
        if ($action === 'upload_clinic_document' && !$canUploadApeDocument) {
            throw new RuntimeException('Only authorized clinic staff can submit documents for a patient.');
        }
        $apeDb->beginTransaction();
        // Serialize updates for this APE, including concurrent examination saves.
        $lockRecord = $apeDb->prepare('SELECT ape_id FROM ape_records WHERE ape_id = ? FOR UPDATE');
        $lockRecord->execute([$id]);
        $record = fetch_ape_record($id);
        if (!$record) {
            throw new RuntimeException('APE record no longer exists.');
        }

        $checklistActions = ['update_requirement', 'delete_requirement'];
        if (in_array($action, $checklistActions, true) && !empty($record['exam_date'])) {
            throw new RuntimeException('The saved examination checklist is locked. Review and archive the uploaded follow-up documents instead.');
        }
        if (in_array($action, ['keep_follow_up_open', 'approve_clearance', 'return_clearance'], true) && ape_record_queue($record) !== 'follow_up') {
            throw new RuntimeException('Complete digital submission and clinic archive review before acting on follow-up.');
        }
        if (ape_document_follow_up($record) && in_array($action, ['keep_follow_up_open', 'approve_clearance', 'return_clearance'], true)) {
            throw new RuntimeException('Review and archive the uploaded follow-up documents before clinical clearance.');
        }

        if ($action === 'upload_clinic_document') {
            if (($record['clearance_status'] ?? '') === 'Cleared' || ($record['workflow_status'] ?? '') === 'Cleared') {
                throw new RuntimeException('Document upload is closed because this APE record is already completed.');
            }
            $documentType = trim((string) ($_POST['document_type'] ?? ''));
            if ($documentType === '' || mb_strlen($documentType) > 120) {
                throw new InvalidArgumentException('Choose a valid APE requirement for the document.');
            }
            $requirementLookup = $apeDb->prepare('SELECT requirement_id FROM ape_requirements WHERE ape_id = ? AND requirement_name = ? LIMIT 1');
            $requirementLookup->execute([$id, $documentType]);
            $requirementId = (int) ($requirementLookup->fetchColumn() ?: 0);
            if ($requirementId <= 0) {
                throw new InvalidArgumentException('Choose one of the requirements listed on this APE record.');
            }
            $latestDocument = $apeDb->prepare('SELECT verification_status FROM ape_documents WHERE ape_id = ? AND document_type = ? ORDER BY document_id DESC LIMIT 1');
            $latestDocument->execute([$id, $documentType]);
            $latestStatus = (string) ($latestDocument->fetchColumn() ?: '');
            if ($latestStatus === 'Verified') {
                throw new RuntimeException($documentType . ' is archived and cannot be replaced unless the clinic returns it for correction.');
            }
            if ($latestStatus === 'Pending') {
                throw new RuntimeException($documentType . ' is already waiting for clinic review and cannot be replaced.');
            }
            $storedClinicDocument = ape_store_uploaded_file($_FILES['document'] ?? [], (string) ($record['id_number'] ?? ''), $documentType);
            $document = $apeDb->prepare("INSERT INTO ape_documents (ape_id, document_type, original_filename, file_path, verification_status, uploaded_by_person_id) VALUES (?, ?, ?, ?, 'Pending', ?)");
            $document->execute([$id, $documentType, $storedClinicDocument['original_filename'], $storedClinicDocument['file_path'], $staffPersonId]);
            $requirement = $apeDb->prepare("UPDATE ape_requirements SET status = 'Submitted', remarks = NULL, checked_by_person_id = NULL, checked_at = NULL WHERE requirement_id = ? AND ape_id = ?");
            $requirement->execute([$requirementId, $id]);
            if (($record['workflow_status'] ?? '') !== 'Follow-up Required') {
                $apeDb->prepare("UPDATE ape_records SET workflow_status = 'Submitted' WHERE ape_id = ? AND workflow_status NOT IN ('Cleared', 'Follow-up Required')")->execute([$id]);
            }
            $activityLabel = 'Submitted APE document for patient';
            $activityNotes = $documentType . ': ' . $storedClinicDocument['original_filename'];
        } elseif ($action === 'review_returned_documents') {
            // Old open tabs must never unlock the saved checklist.
            throw new RuntimeException('Review Returned Documents has moved to the uploaded-file panel. Refresh this page to view and archive the files.');
        } elseif ($action === 'approve_documents') {
            $archiveQueue = ape_record_queue($record);
            $requirementsForReview = ape_requirements_for_record($id);
            $archiveGroup = ape_phase_three_review_group($record, $requirementsForReview, (string) ($_POST['review_group'] ?? ''));
            if (!in_array($archiveQueue, ['final_decision', 'follow_up'], true)) {
                throw new RuntimeException('Record the examination before archiving documents.');
            }
            if ($archiveGroup === 'follow_up' && !$canRecordApeExam) {
                throw new RuntimeException('Only authorized clinical staff can approve follow-up documents.');
            }
            if (empty($record['exam_date'])) {
                throw new RuntimeException('Record the clinical examination before archiving digital documents.');
            }
            $reviewRequirementStates = ape_phase_three_requirement_states($requirementsForReview, $archiveGroup);
            $requiredTypes = array_values(array_map(static fn(array $requirement): string => $requirement['requirement_name'], $reviewRequirementStates['ready']));
            if (!$requiredTypes || (int) ($record['unassigned_upload_count'] ?? 1) > 0) {
                throw new RuntimeException('Save the document review and upload groups before archiving.');
            }
            $currentDocuments = $apeDb->prepare("
                SELECT d.document_id, d.document_type, d.verification_status
                FROM ape_documents d
                INNER JOIN (
                    SELECT document_type, MAX(document_id) AS latest_document_id
                    FROM ape_documents
                    WHERE ape_id = ? AND document_type <> 'Clearance'
                    GROUP BY document_type
                ) latest ON latest.latest_document_id = d.document_id
                WHERE d.ape_id = ?
                FOR UPDATE
            ");
            $currentDocuments->execute([$id, $id]);
            $currentRows = $currentDocuments->fetchAll();
            $currentByType = [];
            foreach ($currentRows as $currentDocument) {
                $currentByType[$currentDocument['document_type']] = $currentDocument;
            }
            foreach ($requiredTypes as $requiredType) {
                if (!isset($currentByType[$requiredType]) || $currentByType[$requiredType]['verification_status'] !== 'Pending') {
                    throw new RuntimeException("{$requiredType} is no longer ready for archive review. Refresh this page and try again.");
                }
            }
            if (!$currentRows) {
                throw new RuntimeException('Upload the documents in this group before archiving.');
            }
            $nextWorkflow = (int) ($record['follow_up_required'] ?? 0) === 1 ? 'Follow-up Required' : 'Reviewed';
            $nextClearance = (int) ($record['follow_up_required'] ?? 0) === 1 ? 'For Follow-up' : 'Pending';
            // Archive review verifies the latest required uploads, not the outstanding hard-copy follow-up.
            $requiredDocumentIds = array_map(static fn(string $type): int => (int) $currentByType[$type]['document_id'], $requiredTypes);
            ape_assert_documents_available($apeDb, $id, $requiredDocumentIds);
            $documentPlaceholders = implode(',', array_fill(0, count($requiredDocumentIds), '?'));
            $verified = $apeDb->prepare("UPDATE ape_documents SET verification_status = 'Verified', verified_by_person_id = ?, verified_at = NOW() WHERE ape_id = ? AND document_id IN ({$documentPlaceholders})");
            $verified->execute(array_merge([$staffPersonId, $id], $requiredDocumentIds));
            $requirementPlaceholders = implode(',', array_fill(0, count($requiredTypes), '?'));
            $accepted = $apeDb->prepare("UPDATE ape_requirements SET status = 'Verified', checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ? AND requirement_name IN ({$requirementPlaceholders})");
            $accepted->execute(array_merge([$staffPersonId, $id], $requiredTypes));
            if ($archiveGroup === 'follow_up') {
                $clinicalFollowUp = ape_clinical_follow_up_required($record)
                    || ape_explicit_clearance_required($record);
                $nextWorkflow = $clinicalFollowUp ? 'Follow-up Required' : 'Reviewed';
                $nextClearance = $clinicalFollowUp ? (($record['clearance_status'] ?? '') === 'Submitted' ? 'Submitted' : 'For Follow-up') : 'Pending';
                $stmt = $apeDb->prepare("UPDATE ape_records SET requirement_status = 'Checked', follow_up_required = ?, workflow_status = ?, clearance_status = ?, reviewed_by_person_id = ?, requirements_saved_at = COALESCE(requirements_saved_at, NOW()) WHERE ape_id = ?");
                $stmt->execute([$clinicalFollowUp ? 1 : 0, $nextWorkflow, $nextClearance, $staffPersonId, $id]);
                $activityLabel = 'Archived follow-up documents';
            } else {
                // Initial archive preserves the outstanding document follow-up.
                $stmt = $apeDb->prepare('UPDATE ape_records SET workflow_status = ?, clearance_status = ?, reviewed_by_person_id = ? WHERE ape_id = ?');
                $stmt->execute([$nextWorkflow, $nextClearance, $staffPersonId, $id]);
                $activityLabel = 'Archived APE documents';
            }
            $activityNotes = implode(', ', $requiredTypes);
        } elseif ($action === 'request_document_correction') {
            if (empty($record['exam_date'])) {
                throw new RuntimeException('Record the clinical examination before reviewing digital documents.');
            }
            $documentIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['document_ids'] ?? [])))));
            if (!$documentIds) {
                throw new InvalidArgumentException('Select at least one uploaded document that needs correction.');
            }
            $missingItems = trim((string) ($_POST['missing_items'] ?? ''));
            if ($missingItems === '') {
                throw new InvalidArgumentException('Enter the reason the student must correct and resubmit the selected document(s).');
            }
            $returnSchedule = ape_return_schedule((string) ($_POST['follow_up_due_date'] ?? ''));
            $requirementsForReview = ape_requirements_for_record($id);
            $reviewGroup = ape_phase_three_review_group($record, $requirementsForReview, (string) ($_POST['review_group'] ?? ''));
            $allowedTypes = ape_upload_requirement_names($requirementsForReview, $reviewGroup);
            $documentPlaceholders = implode(',', array_fill(0, count($documentIds), '?'));
            $selectedDocuments = $apeDb->prepare("
                SELECT d.document_id, d.document_type
                FROM ape_documents d
                WHERE d.ape_id = ?
                  AND d.document_id IN ({$documentPlaceholders})
                  AND d.document_type <> 'Clearance'
                  AND d.verification_status = 'Pending'
                  AND d.document_id = (SELECT MAX(v.document_id) FROM ape_documents v WHERE v.ape_id = d.ape_id AND v.document_type = d.document_type)
                FOR UPDATE
            ");
            $selectedDocuments->execute(array_merge([$id], $documentIds));
            $selectedRows = $selectedDocuments->fetchAll();
            if (count($selectedRows) !== count($documentIds)) {
                throw new RuntimeException('One or more selected documents cannot be returned for correction. Refresh the page and try again.');
            }
            foreach ($selectedRows as $selectedDocument) {
                if (!in_array($selectedDocument['document_type'], $allowedTypes, true)) {
                    throw new RuntimeException('Select files only from the active review group.');
                }
            }

            $documents = $apeDb->prepare("UPDATE ape_documents SET verification_status = 'Needs Correction', verified_by_person_id = ?, verified_at = NOW() WHERE ape_id = ? AND document_id IN ({$documentPlaceholders})");
            $documents->execute(array_merge([$staffPersonId, $id], $documentIds));
            $requirement = $apeDb->prepare("UPDATE ape_requirements SET status = 'Needs Correction', upload_group = 'follow_up', upload_due_date = ?, remarks = ?, checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ? AND requirement_name = ?");
            $selectedTypes = [];
            foreach ($selectedRows as $selectedDocument) {
                $selectedTypes[] = $selectedDocument['document_type'];
                $requirement->execute([$returnSchedule['date'], $missingItems, $staffPersonId, $id, $selectedDocument['document_type']]);
            }
            $apeDb->prepare("UPDATE ape_records SET workflow_status = 'Follow-up Required', clearance_status = 'For Follow-up', follow_up_required = 1, follow_up_due_date = ?, reviewed_by_person_id = ? WHERE ape_id = ?")
                ->execute([$returnSchedule['date'], $staffPersonId, $id]);
            $activityLabel = 'Returned document(s) for resubmission';
            $activityNotes = implode(', ', $selectedTypes) . ': ' . $missingItems . ' Due: ' . $returnSchedule['date'];
        } elseif ($action === 'update_clinical_information') {
            if (empty($record['exam_date'])) {
                throw new RuntimeException('Save the examination before updating clinical information.');
            }
            $clinical = ape_validate_clinical_information($_POST, [
                'patient_height_cm' => $record['patient_height_cm'] ?? null,
                'patient_weight_kg' => $record['patient_weight_kg'] ?? null,
                'patient_bmi' => $record['patient_bmi'] ?? null,
                'patient_temperature' => $record['patient_temperature'] ?? null,
                'patient_blood_pressure' => $record['patient_blood_pressure'] ?? null,
                'patient_pulse_rate' => $record['patient_pulse_rate'] ?? null,
                'blood_type' => $record['patient_blood_type'] ?? null,
                'existing_conditions' => $record['patient_existing_conditions'] ?? null,
                'medications' => $record['patient_medications'] ?? null,
            ]);
            $apeDb->prepare('UPDATE ape_records SET patient_height_cm = ?, patient_weight_kg = ?, patient_bmi = ?, patient_temperature = ?, patient_blood_pressure = ?, patient_pulse_rate = ?, clinical_remarks = ? WHERE ape_id = ?')
                ->execute([$clinical['patient_height_cm'], $clinical['patient_weight_kg'], $clinical['patient_bmi'], $clinical['patient_temperature'], $clinical['patient_blood_pressure'], $clinical['patient_pulse_rate'], trim((string) ($_POST['clinical_remarks'] ?? '')) ?: ($record['clinical_remarks'] ?? null), $id]);
            $apeDb->prepare('UPDATE patients SET blood_type = ?, existing_conditions = ?, medications = ? WHERE person_id = ?')
                ->execute([$clinical['blood_type'], $clinical['existing_conditions'], $clinical['medications'], (int) $record['patient_id']]);
            $activityLabel = 'Updated clinical information';
            $activityNotes = $clinical['changed_fields'] ? 'Updated: ' . implode(', ', $clinical['changed_fields']) : 'No clinical values changed.';
            audit_log_event('ape', 'clinical_information_updated', $staffPersonId, 'staff', 'ape', $id, ['patient_id' => (int) $record['patient_id'], 'fields' => $clinical['changed_fields']]);
        } elseif ($action === 'record_examination') {
            if (!empty($record['exam_date'])) {
                throw new RuntimeException('This examination has already been saved and is locked. Use the separate document review to resolve outstanding requirements.');
            }
            if (!ape_examination_is_available($record)) {
                throw new RuntimeException('The examination becomes available when the patient’s assigned APE schedule starts.');
            }
            if (!in_array(($record['workflow_status'] ?? ''), ['Registered', 'Batch Assigned', 'Requirements Checked', 'Scheduled', 'Exam Done', 'Submitted', 'Reviewed', 'Follow-up Required'], true) || (!empty($record['exam_date']) && ($record['requirement_status'] ?? '') === 'Checked')) {
                throw new RuntimeException('This APE record is not ready for examination.');
            }
            $examDate = trim((string) ($_POST['exam_date'] ?? ''));
            $examDateValue = DateTimeImmutable::createFromFormat('Y-m-d', $examDate);
            if (!$examDateValue || $examDateValue->format('Y-m-d') !== $examDate || $examDate > date('Y-m-d')) {
                throw new InvalidArgumentException('Select a valid examination date that is not in the future.');
            }
            $submittedResultStatus = (string) ($_POST['result_status'] ?? 'Examined');
            if (!in_array($submittedResultStatus, ['Examined', 'Normal', 'With Finding', 'Referred'], true)) {
                throw new InvalidArgumentException('Select a valid examination result.');
            }
            $findingType = 'APE Examination';
            $submittedFindingDescription = trim((string) ($_POST['finding_description'] ?? ''));
            $isReferral = $submittedResultStatus === 'Referred';
            $resultStatus = $isReferral ? 'Referred' : ($submittedFindingDescription !== '' || $submittedResultStatus === 'With Finding' ? 'With Finding' : 'Normal');
            $clinicalRemarks = trim((string) ($_POST['clinical_remarks'] ?? '')) ?: null;
            $patientNote = trim((string) ($_POST['patient_visible_note'] ?? '')) ?: null;
            $referredTo = trim((string) ($_POST['referred_to'] ?? ''));
            $referralReason = trim((string) ($_POST['referral_reason'] ?? ''));
            if ($resultStatus === 'Referred' && ($referredTo === '' || $referralReason === '')) {
                throw new InvalidArgumentException('Enter the referral destination and reason when the examination result is Referred.');
            }
            $findingDescription = match ($resultStatus) {
                'Normal' => 'No abnormal findings noted during the APE examination.',
                'Referred' => $referralReason,
                default => $submittedFindingDescription,
            };
            if ($resultStatus === 'With Finding' && $findingDescription === '') {
                throw new InvalidArgumentException('Enter the clinical examination finding or result description.');
            }
            $clinical = ape_validate_clinical_information($_POST, [
                'blood_type' => $record['patient_blood_type'] ?? null,
                'existing_conditions' => $record['patient_existing_conditions'] ?? null,
                'medications' => $record['patient_medications'] ?? null,
            ]);
            $followUpRequirementName = trim((string) ($_POST['follow_up_requirement_name'] ?? ''));
            $followUpInstructions = trim((string) ($_POST['follow_up_requirement_instructions'] ?? ''));
            $followUpDueDate = trim((string) ($_POST['follow_up_requirement_due_date'] ?? ''));
            $followUpRemark = trim((string) ($_POST['follow_up_requirement_remark'] ?? '')) ?: null;
            if ($followUpRequirementName !== '' && ($followUpInstructions === '' || $followUpDueDate === '')) {
                throw new InvalidArgumentException('Add patient instructions and a due date for the follow-up document requirement.');
            }
            if ($followUpRequirementName === '' && ($followUpInstructions !== '' || $followUpDueDate !== '' || $followUpRemark !== null)) {
                throw new InvalidArgumentException('Enter a follow-up document name before adding its instructions or due date.');
            }
            if ($followUpRequirementName !== '' && mb_strlen($followUpRequirementName) > 160) {
                throw new InvalidArgumentException('Follow-up document names must be 160 characters or fewer.');
            }
            $followUpSchedule = $followUpRequirementName === '' ? null : ape_return_schedule($followUpDueDate);
            $needsFollowUp = $isReferral || $followUpRequirementName !== '';
            $nextWorkflowStatus = $needsFollowUp ? 'Follow-up Required' : 'Requirements Checked';
            $nextClearanceStatus = $needsFollowUp ? 'For Follow-up' : 'Pending';
            $nextPatientNote = $patientNote ?: ($isReferral ? $referralReason : null);
            $initialDueDate = (new DateTimeImmutable($examDate))->modify('+7 days')->format('Y-m-d');
            $apeDb->prepare("UPDATE ape_requirements SET upload_group = COALESCE(upload_group, 'initial'), upload_due_date = COALESCE(upload_due_date, ?) WHERE ape_id = ?")
                ->execute([$initialDueDate, $id]);
            if ($followUpRequirementName !== '') {
                $duplicate = $apeDb->prepare('SELECT requirement_id FROM ape_requirements WHERE ape_id = ? AND requirement_name = ? LIMIT 1');
                $duplicate->execute([$id, $followUpRequirementName]);
                if ($duplicate->fetchColumn()) {
                    throw new InvalidArgumentException('That follow-up document requirement already exists.');
                }
                $apeDb->prepare("INSERT INTO ape_requirements (ape_id, requirement_name, status, remarks, upload_group, upload_due_date) VALUES (?, ?, 'Missing', ?, 'follow_up', ?)")
                    ->execute([$id, $followUpRequirementName, $followUpInstructions . ($followUpRemark ? "\n\nClinic note: " . $followUpRemark : ''), $followUpSchedule['date']]);
            }
            $updateExam = $apeDb->prepare("UPDATE ape_records SET exam_date = ?, requirement_status = 'Not Checked', workflow_status = ?, clearance_status = ?, follow_up_required = ?, clinical_remarks = ?, patient_visible_note = ?, reviewed_by_person_id = ?, follow_up_due_date = ?, patient_height_cm = ?, patient_weight_kg = ?, patient_bmi = ?, patient_temperature = ?, patient_blood_pressure = ?, patient_pulse_rate = ?, requirements_saved_at = NULL WHERE ape_id = ?");
            $updateExam->execute([$examDate, $nextWorkflowStatus, $nextClearanceStatus, $needsFollowUp ? 1 : 0, $clinicalRemarks, $nextPatientNote, $staffPersonId, $followUpSchedule['date'] ?? null, $clinical['patient_height_cm'], $clinical['patient_weight_kg'], $clinical['patient_bmi'], $clinical['patient_temperature'], $clinical['patient_blood_pressure'], $clinical['patient_pulse_rate'], $id]);
            $apeDb->prepare('UPDATE patients SET blood_type = ?, existing_conditions = ?, medications = ? WHERE person_id = ?')
                ->execute([$clinical['blood_type'], $clinical['existing_conditions'], $clinical['medications'], (int) $record['patient_id']]);
            $finding = $apeDb->prepare('INSERT INTO ape_findings (ape_id, finding_type, description, result_status, follow_up_required, recorded_by_person_id) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE finding_type = VALUES(finding_type), description = VALUES(description), result_status = VALUES(result_status), follow_up_required = VALUES(follow_up_required), recorded_by_person_id = VALUES(recorded_by_person_id), recorded_at = CURRENT_TIMESTAMP');
            $finding->execute([$id, $isReferral ? 'Referral' : $findingType, $isReferral ? $referralReason : $findingDescription, $resultStatus, $isReferral ? 1 : 0, $staffPersonId]);
            if ($isReferral) {
                $referral = $apeDb->prepare('INSERT INTO referrals (patient_person_id, referral_date, referred_to, reason, status, referred_by_person_id, remarks) VALUES (?, ?, ?, ?, "Completed", ?, ?)');
                $referral->execute([(int) $record['patient_id'], $examDate, $referredTo, $referralReason, $staffPersonId, 'Created during APE examination #' . $id]);
                $activityLabel = 'Recorded APE examination and created referral';
                $activityNotes = $referredTo . ': ' . $referralReason;
            } else {
                $activityLabel = 'Recorded APE examination';
                $activityNotes = $findingType . ': ' . $resultStatus . '. Clinical information recorded: ' . implode(', ', $clinical['changed_fields']) . '.';
            }
            audit_log_event('ape', 'clinical_information_recorded', $staffPersonId, 'staff', 'ape', $id, ['patient_id' => (int) $record['patient_id'], 'fields' => $clinical['changed_fields']]);
            if ($followUpRequirementName !== '') {
                $activityNotes .= ' Follow-up document required: ' . $followUpRequirementName . '. Due: ' . $followUpSchedule['date'];
            }
        } elseif ($action === 'finalize_exam_clear') {
            if (!in_array(ape_record_queue($record), ['final_decision', 'follow_up'], true) || empty($record['exam_date'])) {
                throw new RuntimeException('The examination must be complete before completing the APE.');
            }
            if (!ape_can_complete_record($record)) {
                throw new RuntimeException('Archive every active document and resolve clinical follow-up before completing this APE.');
            }
            ape_assert_documents_available($apeDb, $id, [], true);
            $documents = $apeDb->prepare("UPDATE ape_documents SET verification_status = 'Verified', verified_by_person_id = ?, verified_at = NOW() WHERE ape_id = ? AND document_type <> 'Clearance' AND verification_status = 'Pending'");
            $documents->execute([$staffPersonId, $id]);
            $requirements = $apeDb->prepare("UPDATE ape_requirements SET status = 'Verified', remarks = NULL, checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ? AND status = 'Submitted'");
            $requirements->execute([$staffPersonId, $id]);
            $patientNote = trim((string) ($_POST['patient_visible_note'] ?? '')) ?: ($record['patient_visible_note'] ?? null);
            $stmt = $apeDb->prepare("UPDATE ape_records SET workflow_status = 'Cleared', clearance_status = 'Cleared', follow_up_required = 0, patient_visible_note = ?, reviewed_by_person_id = ? WHERE ape_id = ? AND workflow_status IN ('Exam Done', 'Reviewed')");
            $stmt->execute([$patientNote, $staffPersonId, $id]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('The examination status changed before clearance could be saved. Refresh and try again.');
            }
            patient_access_promote_after_ape_clearance($apeDb, (int) $record['patient_id'], $id, $staffPersonId);
            $activityLabel = 'Cleared patient after APE examination';
            $activityNotes = $patientNote ?: 'No additional patient instruction.';
        } elseif ($action === 'finalize_exam_follow_up') {
            if (!in_array(ape_record_queue($record), ['final_decision', 'follow_up'], true)) {
                throw new RuntimeException('Record the examination before requiring follow-up.');
            }
            ape_assert_documents_available($apeDb, $id, [], true);
            $documents = $apeDb->prepare("UPDATE ape_documents SET verification_status = 'Verified', verified_by_person_id = ?, verified_at = NOW() WHERE ape_id = ? AND document_type <> 'Clearance' AND verification_status = 'Pending'");
            $documents->execute([$staffPersonId, $id]);
            $requirements = $apeDb->prepare("UPDATE ape_requirements SET status = 'Verified', remarks = NULL, checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ? AND status = 'Submitted'");
            $requirements->execute([$staffPersonId, $id]);
            $followUpNotes = trim((string) ($_POST['follow_up_notes'] ?? ''));
            if ($followUpNotes === '') {
                throw new InvalidArgumentException('Enter the follow-up required from the patient.');
            }
            // The general plan is clinic-only. A document requirement owns its own
            // patient instructions and due date, so retain any existing record-level values.
            $patientNote = $record['patient_visible_note'] ?: null;
            $followUpDueDate = $record['follow_up_due_date'] ?: null;
            $apeDb->prepare("UPDATE ape_records SET workflow_status = 'Follow-up Required', clearance_status = 'For Follow-up', follow_up_required = 1, follow_up_due_date = ?, clinical_remarks = ?, patient_visible_note = ?, reviewed_by_person_id = ? WHERE ape_id = ?")
                ->execute([$followUpDueDate, $followUpNotes, $patientNote, $staffPersonId, $id]);
            $apeDb->prepare("INSERT INTO ape_findings (ape_id, finding_type, description, result_status, follow_up_required, recorded_by_person_id) VALUES (?, 'Follow-up Decision', ?, 'With Finding', 1, ?) ON DUPLICATE KEY UPDATE follow_up_required = 1, recorded_by_person_id = VALUES(recorded_by_person_id), recorded_at = CURRENT_TIMESTAMP")
                ->execute([$id, $followUpNotes, $staffPersonId]);
            $activityLabel = 'Required follow-up after APE examination';
            $activityNotes = $followUpDueDate ? $followUpNotes . ' Due: ' . $followUpDueDate : $followUpNotes;
        } elseif ($action === 'keep_follow_up_open') {
            $followUpNotes = trim((string) ($_POST['follow_up_notes'] ?? '')) ?: 'Follow-up remains open.';
            $followUpDueDate = ape_follow_up_due_date_from_post();
            $stmt = $apeDb->prepare("UPDATE ape_records SET clearance_status = 'For Follow-up', workflow_status = 'Follow-up Required', follow_up_due_date = ?, clinical_remarks = ? WHERE ape_id = ?");
            $stmt->execute([$followUpDueDate, $followUpNotes, $id]);
            $activityLabel = 'Kept follow-up open';
            $activityNotes = $followUpDueDate ? $followUpNotes . ' Due: ' . $followUpDueDate : $followUpNotes;
        } elseif ($action === 'resolve_clinical_follow_up') {
            if (ape_record_queue($record) !== 'follow_up' || !ape_deferred_submission_complete($record)) {
                throw new RuntimeException('Archive any outstanding follow-up documents before resolving clinical follow-up.');
            }
            if (ape_explicit_clearance_required($record)) {
                throw new RuntimeException('This follow-up includes an explicit clearance document and must be resolved through its document review.');
            }
            $apeDb->prepare("UPDATE ape_records SET follow_up_required = 0, workflow_status = 'Reviewed', clearance_status = 'Pending', follow_up_due_date = NULL, reviewed_by_person_id = ? WHERE ape_id = ?")
                ->execute([$staffPersonId, $id]);
            $apeDb->prepare('UPDATE ape_findings SET follow_up_required = 0 WHERE ape_id = ? AND follow_up_required = 1')
                ->execute([$id]);
            audit_log_event('ape', 'clinical_follow_up_resolved', $staffPersonId, 'staff', 'ape', $id, [
                'patient_id' => (int) $record['patient_id'],
                'cleared_fields' => ['ape_records.follow_up_required', 'ape_findings.follow_up_required'],
            ]);
            $activityLabel = 'Resolved clinical follow-up';
            $activityNotes = trim((string) ($_POST['resolution_note'] ?? '')) ?: 'Clinic confirmed that no further clinical follow-up is required.';
        } elseif ($action === 'approve_clearance') {
            if (!ape_explicit_clearance_required($record)) {
                throw new RuntimeException('No explicit clearance document is required for this follow-up. Complete the clinical follow-up decision instead.');
            }
            if (!ape_deferred_submission_complete($record)) {
                throw new RuntimeException('Upload and archive the deferred checklist documents before final clearance.');
            }
            ape_assert_documents_available($apeDb, $id);
            $stmt = $apeDb->prepare("UPDATE ape_records SET clearance_status = 'Cleared', follow_up_required = 0, workflow_status = 'Cleared', reviewed_by_person_id = ? WHERE ape_id = ?");
            $stmt->execute([$staffPersonId, $id]);
            $document = $apeDb->prepare("UPDATE ape_documents SET verification_status = 'Verified', verified_by_person_id = ?, verified_at = NOW() WHERE ape_id = ? AND document_type = 'Clearance'");
            $document->execute([$staffPersonId, $id]);
            $requirement = $apeDb->prepare("UPDATE ape_requirements SET status = 'Verified', checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ? AND requirement_name = 'Follow-up clearance'");
            $requirement->execute([$staffPersonId, $id]);
            patient_access_promote_after_ape_clearance($apeDb, (int) $record['patient_id'], $id, $staffPersonId);
            $activityLabel = 'Approved follow-up clearance';
        } elseif ($action === 'return_clearance') {
            if (!ape_explicit_clearance_required($record)) {
                throw new RuntimeException('No explicit clearance document is required for this follow-up.');
            }
            $missingItems = trim((string) ($_POST['missing_items'] ?? '')) ?: 'Clearance correction required.';
            $stmt = $apeDb->prepare("UPDATE ape_records SET clearance_status = 'For Follow-up', workflow_status = 'Follow-up Required' WHERE ape_id = ?");
            $stmt->execute([$id]);
            $document = $apeDb->prepare("UPDATE ape_documents SET verification_status = 'Needs Correction', verified_by_person_id = ?, verified_at = NOW() WHERE ape_id = ? AND document_type = 'Clearance'");
            $document->execute([$staffPersonId, $id]);
            $requirement = $apeDb->prepare("INSERT INTO ape_requirements (ape_id, requirement_name, status, remarks) VALUES (?, 'Follow-up clearance', 'Needs Correction', ?) ON DUPLICATE KEY UPDATE status = 'Needs Correction', remarks = VALUES(remarks)");
            $requirement->execute([$id, $missingItems]);
            $activityLabel = 'Returned clearance for correction';
            $activityNotes = $missingItems;
        } elseif ($action === 'save_notes') {
            $stmt = $apeDb->prepare('UPDATE ape_records SET clinical_remarks = ?, patient_visible_note = ? WHERE ape_id = ?');
            $stmt->execute([
                array_key_exists('clinical_remarks', $_POST) ? (trim((string) $_POST['clinical_remarks']) ?: null) : ($record['clinical_remarks'] ?: null),
                trim((string) ($_POST['patient_visible_note'] ?? '')) ?: null,
                $id,
            ]);
            $activityLabel = 'Updated APE notes';
        } elseif ($action === 'add_requirement') {
            if (($record['clearance_status'] ?? '') === 'Cleared' || ($record['workflow_status'] ?? '') === 'Cleared') {
                throw new RuntimeException('This completed APE checklist is locked.');
            }
            $requirementName = trim((string) ($_POST['requirement_name'] ?? ''));
            $requirementStatus = 'Missing';
            if ($requirementName === '' || !in_array($requirementStatus, ['Missing', 'Submitted', 'Verified', 'Needs Correction'], true)) {
                throw new InvalidArgumentException('Enter a requirement name and valid status.');
            }
            if (mb_strlen($requirementName) > 160) {
                throw new InvalidArgumentException('Requirement names must be 160 characters or fewer.');
            }
            $isFollowUpRequirement = !empty($record['exam_date']);
            $requirementInstructions = trim((string) ($_POST['requirement_instructions'] ?? ''));
            $requirementDueDate = trim((string) ($_POST['requirement_due_date'] ?? ''));
            if ($isFollowUpRequirement && ($requirementInstructions === '' || $requirementDueDate === '')) {
                throw new InvalidArgumentException('Follow-up document requirements need patient instructions and a due date.');
            }
            $requirementSchedule = $isFollowUpRequirement ? ape_return_schedule($requirementDueDate) : null;
            $duplicate = $apeDb->prepare('SELECT requirement_id FROM ape_requirements WHERE ape_id = ? AND requirement_name = ? LIMIT 1');
            $duplicate->execute([$id, $requirementName]);
            if ($duplicate->fetchColumn()) {
                throw new InvalidArgumentException('That requirement already exists. Edit its remarks in the checklist instead.');
            }
            $requirement = $apeDb->prepare('INSERT INTO ape_requirements (ape_id, requirement_name, status, remarks, checked_by_person_id, checked_at, upload_group, upload_due_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $isChecked = $requirementStatus === 'Verified';
            $requirementRemark = trim((string) ($_POST['requirement_remarks'] ?? '')) ?: null;
            $requirement->execute([$id, $requirementName, $requirementStatus, $isFollowUpRequirement ? $requirementInstructions . ($requirementRemark ? "\n\nClinic note: " . $requirementRemark : '') : $requirementRemark, $isChecked ? $staffPersonId : null, $isChecked ? date('Y-m-d H:i:s') : null, $isFollowUpRequirement ? 'follow_up' : 'initial', $requirementSchedule['date'] ?? null]);
            if ($isFollowUpRequirement) {
                $apeDb->prepare("UPDATE ape_records SET workflow_status = 'Follow-up Required', clearance_status = 'For Follow-up', follow_up_required = 1, follow_up_due_date = ? WHERE ape_id = ?")
                    ->execute([$requirementSchedule['date'], $id]);
            }
            $activityLabel = $isFollowUpRequirement ? 'Added follow-up document requirement' : 'Added APE requirement';
            $activityNotes = $requirementName . ': ' . $requirementStatus;
        } elseif ($action === 'delete_requirement') {
            $requirementId = (int) ($_POST['requirement_id'] ?? 0);
            if ($requirementId <= 0) {
                throw new InvalidArgumentException('Choose a requirement to delete.');
            }
            $existing = $apeDb->prepare('SELECT requirement_name FROM ape_requirements WHERE requirement_id = ? AND ape_id = ? FOR UPDATE');
            $existing->execute([$requirementId, $id]);
            $requirementName = (string) $existing->fetchColumn();
            if ($requirementName === '') {
                throw new RuntimeException('Requirement not found.');
            }
            if (!empty($record['exam_date'])) {
                throw new RuntimeException('Requirements cannot be deleted after examination. Manage submitted documents in Final Decision or Follow-up.');
            }
            $documentHistory = $apeDb->prepare('SELECT COUNT(*) FROM ape_documents WHERE ape_id = ? AND document_type = ?');
            $documentHistory->execute([$id, $requirementName]);
            if ((int) $documentHistory->fetchColumn() > 0) {
                throw new RuntimeException('Requirements with uploaded document history cannot be deleted. Return or archive the current file in Final Decision or Follow-up.');
            }
            $delete = $apeDb->prepare('DELETE FROM ape_requirements WHERE requirement_id = ? AND ape_id = ?');
            $delete->execute([$requirementId, $id]);
            $activityLabel = 'Deleted APE requirement';
            $activityNotes = $requirementName;
        }

        if ($activityLabel) {
            ape_log_activity($id, $staffPersonId, $activityLabel, $activityNotes);
            $updatedRecord = fetch_ape_record($id);
            $patientNoteChanged = trim((string) ($updatedRecord['patient_visible_note'] ?? ''))
                !== trim((string) ($record['patient_visible_note'] ?? ''));
            if ($action !== 'save_notes' || $patientNoteChanged) {
                patient_notification_for_ape_action($apeDb, $updatedRecord, $action, $staffPersonId);
            }
            if (in_array($action, ['request_document_correction', 'return_clearance'], true)) {
                $emailMessage = $action === 'return_clearance'
                    ? 'Your submitted APE clearance needs correction. Please open your APE status for the clinic instructions.'
                    : 'One or more APE documents need correction. Please open your APE status and submit the requested documents.';
                patient_email_queue_notification((int) $updatedRecord['patient_id'], $action === 'return_clearance' ? 'ape_clearance_correction' : 'ape_document_correction_required', 'ape_corrections', $action === 'return_clearance' ? 'APE clearance correction required' : 'APE document correction required', $emailMessage, 'ape', $id, $staffPersonId);
            }
            if (in_array($action, ['finalize_exam_follow_up', 'keep_follow_up_open'], true)) {
                patient_email_queue_notification((int) $updatedRecord['patient_id'], 'ape_follow_up_required', 'ape_follow_up_reminders', 'APE follow-up required', 'The clinic requires follow-up for your APE. Please open your APE status for the required action and due date.', 'ape', $id, $staffPersonId);
            }
            flash_message('success', $activityLabel . '.');
        }
        $apeDb->commit();
    } catch (Throwable $e) {
        if ($apeDb->inTransaction()) {
            $apeDb->rollBack();
        }
        if ($storedClinicDocument && !empty($storedClinicDocument['absolute_path']) && is_file($storedClinicDocument['absolute_path'])) {
            @unlink($storedClinicDocument['absolute_path']);
        }
        flash_message('error', $e->getMessage());
    }

    header('Location: view.php?id=' . $id);
    exit;
}

$record = fetch_ape_record($id);
$fullName = trim($record['first_name'] . ' ' . $record['last_name']);
$queueKey = ape_record_queue($record);
$queue = ape_work_queues()[$queueKey];
$next = ape_next_action($record);
$patientProgress = ape_patient_progress($record);
$staffProgress = ape_staff_progress($record);
$currentStep = $staffProgress['active_step'];
$digitalSubmissionComplete = ape_digital_submission_complete($record);
$actionCard = ape_next_action_card($record);
$adminStateBadge = match ($staffProgress['active_step']) {
    1 => 'DOCUMENTS STILL NEEDED',
    2 => 'EXAMINATION PENDING',
    3 => 'FINAL DECISION PENDING',
    4 => $patientProgress['steps'][4]['done'] ? 'APE COMPLETED' : 'FOLLOW-UP REQUIRED',
    default => 'APE IN PROGRESS',
};
$adminStateExplanation = match ($staffProgress['active_step']) {
    1 => 'The student may upload documents before the assigned examination schedule starts.',
    2 => 'The assigned examination schedule is active. Record the examination while document uploads remain available.',
    3 => 'Review submitted initial or follow-up documents, return any that need correction, then complete the APE when all work is resolved.',
    4 => $patientProgress['steps'][4]['done']
        ? 'The APE record is complete.'
        : 'The APE remains in Final Decision or Follow-up until clinic work is resolved.',
    default => 'Complete the current APE step.',
};

$requirements = ape_requirements_for_record($id);
$pendingRequirements = array_values(array_filter(
    $requirements,
    static fn(array $requirement): bool => ($requirement['status'] ?? '') !== 'Verified'
));
$documents = ape_documents_for_record($id);
$studentDocumentSubmitted = (bool) array_filter(
    $documents,
    static fn(array $document): bool => (int) ($document['uploaded_by_person_id'] ?? 0) === (int) $record['patient_id']
);
$latestDocumentByRequirement = [];
foreach ($documents as $document) {
    $documentKey = strtolower(trim((string) ($document['document_type'] ?? '')));
    if ($documentKey !== '' && !isset($latestDocumentByRequirement[$documentKey])) {
        $latestDocumentByRequirement[$documentKey] = $document;
    }
}
foreach ($requirements as &$requirement) {
    $requirementKey = strtolower(trim((string) ($requirement['requirement_name'] ?? '')));
    $requirement['_latest_document'] = $latestDocumentByRequirement[$requirementKey] ?? null;
}
unset($requirement);
$digitalPendingRequirements = array_values(array_filter(
    $requirements,
    static function (array $requirement): bool {
        if (($requirement['requirement_name'] ?? '') === 'Follow-up clearance'
            || ($requirement['upload_group'] ?? 'initial') === 'follow_up') {
            return false;
        }
        $latestDocument = $requirement['_latest_document'] ?? null;
        return !$latestDocument || ($latestDocument['verification_status'] ?? '') !== 'Verified';
    }
));
$displayPendingRequirements = $queueKey === 'final_decision' && !$digitalSubmissionComplete
    ? $digitalPendingRequirements
    : $pendingRequirements;
$apeIsCompleted = ($record['workflow_status'] ?? '') === 'Cleared'
    || ($record['clearance_status'] ?? '') === 'Cleared';
$visibleArchivedDocuments = $apeIsCompleted ? $documents : [];
$dataQualityFlags = ape_data_quality_flags($record, $requirements, $documents);
$reviewDocuments = array_values(array_filter(
    $documents,
    static fn(array $document): bool => ($document['document_type'] ?? '') !== 'Clearance'
));
$pendingReviewDocuments = array_values(array_filter(
    $reviewDocuments,
    static fn(array $document): bool => ($document['verification_status'] ?? '') === 'Pending'
));
$findings = ape_findings_for_record($id);
$examSaved = !empty($record['exam_date']);
$documentFollowUp = ape_document_follow_up($record);
$phaseThreeGroups = ape_phase_three_groups($requirements);
$requestedReviewGroup = in_array($_GET['review_group'] ?? null, ['initial', 'follow_up'], true) ? $_GET['review_group'] : null;
$reviewUploadGroup = $phaseThreeGroups ? ape_phase_three_review_group($record, $requirements, $requestedReviewGroup) : null;
$reviewRequirementStates = $reviewUploadGroup ? ape_phase_three_requirement_states($requirements, $reviewUploadGroup) : ['ready' => [], 'waiting' => []];
$reviewReadyRequirements = $reviewRequirementStates['ready'];
$reviewWaitingRequirements = $reviewRequirementStates['waiting'];
$reviewReadyNames = array_values(array_map(static fn(array $requirement): string => $requirement['requirement_name'], $reviewReadyRequirements));
$reviewWaitingNames = array_values(array_map(static fn(array $requirement): string => $requirement['requirement_name'], $reviewWaitingRequirements));
$reviewDocuments = array_values(array_filter($reviewDocuments, static fn(array $document): bool => in_array($document['document_type'], $reviewReadyNames, true)));
$latestReviewDocuments = [];
foreach ($reviewDocuments as $document) {
    $type = $document['document_type'];
    if (!isset($latestReviewDocuments[$type]) || (int) $document['document_id'] > (int) $latestReviewDocuments[$type]['document_id']) {
        $latestReviewDocuments[$type] = $document;
    }
}
$reviewDocuments = array_values($latestReviewDocuments);
$pendingReviewDocuments = array_values(array_filter($reviewDocuments, static fn(array $document): bool => $document['verification_status'] === 'Pending'));
$reviewAwaitingCount = count($pendingReviewDocuments);
$reviewWorkspaceActive = $examSaved && !$apeIsCompleted && $reviewUploadGroup !== null;
$showRequirementsChecklist = !$apeIsCompleted && !$examSaved;
$canClinicUploadBeforeStudentSubmission = $canUploadApeDocument && !$apeIsCompleted;
$headerWaitingLabel = ape_waiting_label($record);
if ($studentDocumentSubmitted && !$apeIsCompleted && $reviewDocuments) {
    $adminStateBadge = $reviewAwaitingCount > 0
        ? $reviewAwaitingCount . ' DOCUMENT' . ($reviewAwaitingCount === 1 ? '' : 'S') . ' AWAITING REVIEW'
        : 'DOCUMENT REVIEW IN PROGRESS';
    $adminStateExplanation = 'Student documents are ready for one clinic decision: archive the complete submission or return selected files for correction.';
    $headerWaitingLabel = 'REVIEW SUBMISSION';
}
$showExamForm = !$examSaved && !$apeIsCompleted && $canRecordApeExam && ape_examination_is_available($record);
$savedExam = $findings[0] ?? [];
$savedExamResult = (string) ($savedExam['result_status'] ?? '');
$clinicalTextValue = static function ($value): string {
    $value = trim((string) ($value ?? ''));
    return in_array(mb_strtolower(rtrim($value, '.')), [
        'none reported',
        'none recorded',
        'no current medications recorded',
        'no medications recorded',
    ], true) ? '' : $value;
};
$existingConditionsValue = $clinicalTextValue($record['patient_existing_conditions'] ?? null);
$medicationsValue = $clinicalTextValue($record['patient_medications'] ?? null);
$clinicalListValues = static function (string $value): array {
    $items = preg_split('/\r\n|\r|\n/', $value) ?: [];
    $items = array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));
    return $items ?: [''];
};
$existingConditionsItems = $clinicalListValues($existingConditionsValue);
$medicationsItems = $clinicalListValues($medicationsValue);
$clinicalItemPairCount = max(count($existingConditionsItems), count($medicationsItems));
$savedReferral = null;
if ($examSaved && $savedExamResult === 'Referred') {
    $referralStmt = auth_db()->prepare('SELECT referred_to, reason FROM referrals WHERE patient_person_id = ? AND remarks = ? ORDER BY referral_id DESC LIMIT 1');
    $referralStmt->execute([(int) $record['patient_id'], 'Created during APE examination #' . $id]);
    $savedReferral = $referralStmt->fetch() ?: null;
}
$activities = ape_activities_for_patient_record($id, (int) $record['patient_id'], 20);
$birthdateLabel = $record['birthdate'] ? date('M d, Y', strtotime($record['birthdate'])) : 'Not recorded';
$sexLabel = $record['sex'] ?: 'Not specified';
$clearanceDocument = null;
foreach ($documents as $document) {
    if (($document['document_type'] ?? '') === 'Clearance') {
        $clearanceDocument = $document;
        break;
    }
}
$clearanceUrl = $clearanceDocument
    ? app_url('ape/document.php?id=' . (int) $clearanceDocument['document_id'])
    : null;

set_page_back_link('index.php', 'Queues');
render_header('APE Record - ' . $fullName);
?>

<style>
    .ape-flow-shell {
        max-width: 68rem;
        margin: 0 auto;
    }
    .ape-flow-step {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 0.7rem;
        align-items: center;
        min-width: 0;
    }
    .ape-flow-step-index {
        width: 2rem;
        height: 2rem;
        border-radius: 0.65rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        font-weight: 800;
    }
    .ape-flow-field {
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 0.625rem;
        background: #f8fafc;
        min-height: 3.45rem;
        padding: 0.72rem 0.9rem;
    }
    .ape-flow-panel {
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 0.75rem;
        background: #ffffff;
        padding: 1.25rem;
    }
    .ape-flow-field strong,
    .ape-flow-field p {
        overflow-wrap: anywhere;
    }
    .ape-flow-action {
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 0.75rem;
        background: #ffffff;
        padding: 1.25rem;
    }
    .ape-flow-action.muted {
        background: #fffbeb;
        border-color: #fde68a;
    }
    .ape-follow-up-plan {
        border-color: rgba(180, 83, 9, 0.2);
        box-shadow: 0 8px 24px rgba(180, 83, 9, 0.05);
    }
    .ape-follow-up-plan-header {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        margin-bottom: 1.25rem;
    }
    .ape-follow-up-plan-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.5rem;
        height: 2.5rem;
        flex: 0 0 auto;
        border-radius: 0.75rem;
        background: #fff7ed;
        color: #c2410c;
    }
    .ape-follow-up-plan-help {
        color: #64748b;
        font-size: 0.8rem;
        font-weight: 700;
        line-height: 1.45;
    }
    .ape-inline-document-requirement {
        margin-top: 0.9rem;
        border: 1px solid rgba(148, 163, 184, 0.24);
        border-radius: 0.8rem;
        background: #f8fafc;
        overflow: hidden;
    }
    .ape-inline-document-requirement summary {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.85rem 1rem;
        color: #234b31;
        cursor: pointer;
        list-style: none;
    }
    .ape-inline-document-requirement summary::-webkit-details-marker { display: none; }
    .ape-inline-document-requirement summary > .material-symbols-outlined { color: #3d8052; }
    .ape-inline-document-requirement summary strong,
    .ape-inline-document-requirement summary small { display: block; }
    .ape-inline-document-requirement summary strong { font-size: 0.88rem; }
    .ape-inline-document-requirement summary small { margin-top: 0.1rem; color: #64748b; font-size: 0.75rem; font-weight: 700; }
    .ape-inline-document-requirement-body { padding: 0 1rem 1rem; border-top: 1px solid rgba(148, 163, 184, 0.18); }
    .ape-decision-action-bar {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        margin-bottom: 0.9rem;
    }
    .ape-decision-mode-input {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        opacity: 0;
        pointer-events: none;
    }
    .ape-decision-action {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        min-width: 0;
        padding: 0.9rem 1rem;
        border: 1px solid rgba(148, 163, 184, 0.25);
        border-radius: 0.8rem;
        background: #ffffff;
        color: #17261d;
        text-align: left;
        cursor: pointer;
        transition: border-color 0.15s ease, background-color 0.15s ease, box-shadow 0.15s ease;
    }
    .ape-decision-action:hover,
    .ape-decision-action:focus-visible {
        border-color: rgba(61, 128, 82, 0.5);
        box-shadow: 0 6px 16px rgba(23, 38, 29, 0.06);
        outline: none;
    }
    .ape-decision-action.is-selected {
        border-color: #3d8052;
        background: #f0fdf4;
        box-shadow: 0 6px 16px rgba(61, 128, 82, 0.1);
    }
    .ape-decision-action-follow-up.is-selected {
        border-color: #d97706;
        background: #fffbeb;
        box-shadow: 0 6px 16px rgba(180, 83, 9, 0.08);
    }
    #apeDecisionClear:checked ~ .ape-decision-action-bar .ape-decision-action-clear {
        border-color: #3d8052;
        background: #f0fdf4;
        box-shadow: 0 6px 16px rgba(61, 128, 82, 0.1);
    }
    #apeDecisionFollowUp:checked ~ .ape-decision-action-bar .ape-decision-action-follow-up {
        border-color: #d97706;
        background: #fffbeb;
        box-shadow: 0 6px 16px rgba(180, 83, 9, 0.08);
    }
    #apeDecisionClear:focus-visible ~ .ape-decision-action-bar .ape-decision-action-clear,
    #apeDecisionFollowUp:focus-visible ~ .ape-decision-action-bar .ape-decision-action-follow-up {
        outline: 3px solid rgba(61, 128, 82, 0.3);
        outline-offset: 3px;
    }
    .ape-decision-action-icon {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        justify-content: center;
        width: 2.25rem;
        height: 2.25rem;
        flex: 0 0 auto;
        border-radius: 0.7rem;
        background: #f0fdf4;
        color: #3d8052;
    }
    .ape-decision-action-follow-up .ape-decision-action-icon {
        background: #fff7ed;
        color: #c2410c;
    }
    .ape-decision-action-copy {
        min-width: 0;
        flex: 1 1 auto;
    }
    .ape-decision-action-copy strong,
    .ape-decision-action-copy span {
        display: block;
    }
    .ape-decision-action-copy strong {
        color: #17261d;
        font-size: 0.98rem;
    }
    .ape-decision-action-copy span {
        margin-top: 0.15rem;
        color: #64748b;
        font-size: 0.78rem;
        font-weight: 700;
    }
    .ape-decision-form {
        display: none;
    }
    #apeDecisionClear:checked ~ .ape-decision-form[data-decision-form="clear"],
    #apeDecisionFollowUp:checked ~ .ape-decision-form[data-decision-form="follow-up"] {
        display: block;
    }
    @media (max-width: 767px) {
        .ape-decision-action-bar {
            grid-template-columns: 1fr;
        }
    }
    .ape-flow-activity {
        max-height: 18rem;
        overflow-y: auto;
    }
    .ape-requirement-chip {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        padding: 0.42rem 0.7rem;
        border: 1px solid #fde68a;
        border-radius: 0.65rem;
        background: #fffbeb;
        color: #92400e;
        font-size: 0.68rem;
        font-weight: 800;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }
    .ape-checklist-list {
        display: grid;
        gap: 0.55rem;
        margin-bottom: 1rem;
    }
    .ape-checklist-row {
        display: grid;
        grid-template-columns: minmax(10rem, 0.9fr) minmax(0, 2fr) auto;
        gap: 0.65rem;
        align-items: center;
        padding: 0.7rem 0.8rem;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 0.65rem;
        background: #f8fafc;
    }
    .ape-checklist-meta {
        min-width: 0;
    }
    .ape-checklist-meta strong {
        display: block;
        color: #1e293b;
        font-size: 0.84rem;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }
    .ape-checklist-meta span {
        display: block;
        margin-top: 0.2rem;
        color: #94a3b8;
        font-size: 0.58rem;
        font-weight: 800;
        line-height: 1.25;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
    .ape-checklist-update {
        display: grid;
        grid-template-columns: 10.5rem minmax(11rem, 1fr);
        gap: 0.5rem;
        align-items: center;
        min-width: 0;
    }
    .ape-checklist-row .clinic-select,
    .ape-checklist-row .clinic-input {
        height: 2.45rem;
        min-height: 2.45rem;
        padding-top: 0.45rem;
        padding-bottom: 0.45rem;
        font-size: 0.78rem;
    }
    .ape-checklist-save,
    .ape-checklist-delete {
        height: 2.45rem;
        min-height: 2.45rem;
    }
    .ape-checklist-save {
        padding-inline: 0.75rem;
    }
    .ape-checklist-delete {
        width: 2.45rem;
        padding: 0;
        color: #b91c1c;
        border-color: rgba(185, 28, 28, 0.25);
    }
    .ape-requirement-status {
        font-weight: 800;
        transition: background-color 150ms ease, border-color 150ms ease, color 150ms ease;
    }
    .ape-requirement-status[data-status="Missing"] {
        color: #92400e;
        border-color: #fcd34d;
        background: #fffbeb;
    }
    .ape-requirement-status[data-status="Submitted"] {
        color: #1d4ed8;
        border-color: #93c5fd;
        background: #eff6ff;
    }
    .ape-requirement-status[data-status="Verified"] {
        color: #166534;
        border-color: #86efac;
        background: #f0fdf4;
    }
    .ape-requirement-status[data-status="Needs Correction"] {
        color: #b91c1c;
        border-color: #fca5a5;
        background: #fef2f2;
    }
    @media (max-width: 1100px) {
        .ape-checklist-row {
            grid-template-columns: minmax(0, 1fr) auto;
        }
        .ape-checklist-update {
            grid-column: 1 / -1;
        }
    }
    @media (max-width: 639px) {
        .ape-checklist-row {
            grid-template-columns: minmax(0, 1fr) auto;
        }
        .ape-checklist-update {
            grid-template-columns: 1fr;
        }
        .ape-checklist-save {
            width: 100%;
        }
    }
    .ape-document-review-list {
        display: grid;
        gap: 0.75rem;
        max-height: 31rem;
        overflow-y: auto;
        padding-right: 0.2rem;
    }
    .ape-document-review-card {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.8rem;
        padding: 0.9rem;
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 0.75rem;
        background: #f8fafc;
        min-width: 0;
    }
    .ape-document-review-card input[type="checkbox"] {
        width: 1rem;
        height: 1rem;
        accent-color: var(--cliniq-primary);
    }
    @media (max-width: 639px) {
        .ape-document-review-card {
            grid-template-columns: auto minmax(0, 1fr);
        }
    .ape-document-review-card .ape-document-view-button {
            grid-column: 2;
            width: fit-content;
        }
    }
    .ape-record-header {
        background: #ffffff;
        box-shadow: 0 8px 24px rgba(23, 38, 29, 0.06);
    }
    .ape-record-header-float {
        position: fixed;
        z-index: 25;
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.65rem 0.85rem;
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 0.75rem;
        background: #ffffff;
        box-shadow: 0 8px 18px rgba(23, 38, 29, 0.12);
        opacity: 0;
        pointer-events: none;
        transform: translateY(-0.5rem);
        transition: opacity 120ms ease-out, transform 120ms ease-out;
        visibility: hidden;
    }
    .ape-record-header-float.is-visible {
        opacity: 1;
        transform: translateY(0);
        visibility: visible;
    }
    @media (prefers-reduced-motion: reduce) {
        .ape-record-header-float {
            transition: none;
        }
    }
    @media (max-width: 639px) {
        .ape-record-header-float {
            padding-left: 0.85rem;
            padding-right: 0.85rem;
        }
    }
    .ape-secondary-panel {
        position: relative;
    }
    .ape-secondary-panel > :not(.ape-secondary-heading) {
        display: none;
    }
    .ape-secondary-panel.is-open > :not(.ape-secondary-heading) {
        display: block;
    }
    .ape-secondary-panel.is-open > form.grid,
    .ape-secondary-panel.is-open > .grid {
        display: grid;
    }
    .ape-secondary-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }
    .ape-secondary-toggle {
        flex: 0 0 auto;
        border: 1px solid rgba(148, 163, 184, 0.35);
        border-radius: 0.75rem;
        padding: 0.45rem 0.7rem;
        background: #fff;
        color: var(--cliniq-primary);
        font-size: 0.72rem;
        font-weight: 800;
    }
    .ape-secondary-toggle:hover {
        background: #f0fdf4;
    }
</style>

<div class="ape-flow-shell space-y-6">
    <div class="clinic-card p-5 md:p-6 ape-record-header" data-ape-record-header>
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-4 min-w-0">
                <div class="avatar <?= e(avatar_color($fullName)) ?> w-14 h-14 text-lg"><?= e(initials($fullName)) ?></div>
                <div class="min-w-0">
                    <p class="text-[10px] font-black text-primary uppercase tracking-widest mb-1">APE Review Station</p>
                    <h1 class="font-headline text-2xl md:text-3xl font-extrabold text-[#17261d] truncate"><?= e($fullName) ?></h1>
                    <p class="text-sm font-bold text-slate-500 mt-1"><?= e($record['id_number']) ?><?= $record['course_section'] ? ' - ' . e($record['course_section']) : '' ?></p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 ape-record-header-actions">
                <span class="badge <?= ape_priority_badge($record)['class'] ?>"><?= e($adminStateBadge) ?></span>
                <span class="badge <?= ape_priority_badge($record)['class'] ?>"><?= e($headerWaitingLabel) ?></span>
                <a class="btn btn-ghost text-decoration-none" href="<?= app_url('patients/view.php?id=' . (int)$record['patient_id']) ?>">
                    <span class="material-symbols-outlined text-[18px]">folder_shared</span> Patient
                </a>
            </div>
        </div>
    </div>
    <?php if ($dataQualityFlags): ?>
        <section class="clinic-card p-5 md:p-6 border border-amber-200 bg-amber-50/60" aria-labelledby="apeDataQualityTitle">
            <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-amber-700 mt-0.5" aria-hidden="true">manage_search</span>
                <div class="min-w-0 flex-1">
                    <h2 class="font-headline text-base font-extrabold text-amber-950 mb-1" id="apeDataQualityTitle">Record review notes</h2>
                    <p class="text-xs font-bold text-amber-900 mb-3">These are read-only data-quality flags. They do not change the APE workflow automatically.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <?php foreach ($dataQualityFlags as $flag): ?>
                            <div class="rounded-xl border border-amber-200 bg-white/70 p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <strong class="text-sm text-amber-950"><?= e($flag['title']) ?></strong>
                                    <span class="badge <?= $flag['severity'] === 'high' ? 'badge-high' : ($flag['severity'] === 'warning' ? 'badge-pending' : 'badge-in-progress') ?>"><?= e(ucfirst($flag['severity'])) ?></span>
                                </div>
                                <p class="text-xs font-bold text-amber-900 mt-1 mb-0"><?= e($flag['detail']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>
    <div class="ape-record-header-float" data-ape-record-header-float aria-hidden="true">
        <div class="avatar <?= e(avatar_color($fullName)) ?> w-9 h-9 text-xs shrink-0"><?= e(initials($fullName)) ?></div>
        <div class="min-w-0">
            <p class="font-headline text-base font-extrabold text-[#17261d] truncate mb-0"><?= e($fullName) ?></p>
            <p class="text-[11px] font-bold text-slate-500 mt-0.5 mb-0"><?= e($record['id_number']) ?></p>
        </div>
    </div>

    <?php
    $hasApeBatch = !empty($record['schedule_batch_id']) && ($record['batch_status'] ?? '') !== 'Cancelled';
    $batchHasPassed = $hasApeBatch && strtotime((string) $record['batch_end_at']) < time();
    $batchWasMissed = $batchHasPassed && empty($record['exam_date']);
    $batchDisplayStatus = !$hasApeBatch
        ? 'Unscheduled'
        : ($batchWasMissed ? 'Missed' : ($examSaved ? 'Examination Completed' : ($batchHasPassed ? 'Schedule Passed' : 'Scheduled')));
    $batchStatusClass = !$hasApeBatch ? 'badge-pending' : ($batchWasMissed ? 'badge-critical' : ($batchHasPassed ? 'badge-completed' : 'badge-in-progress'));
    ?>
    <section class="clinic-card p-5 md:p-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
            <div class="flex items-start gap-4">
                <span class="w-11 h-11 rounded-2xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined shrink-0"><?= $hasApeBatch ? 'event_available' : 'event_busy' ?></span>
                <div>
                    <p class="text-[10px] font-black text-primary uppercase tracking-widest mb-1">Assigned APE Schedule</p>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1"><?= $hasApeBatch ? e($record['batch_name']) : 'No batch assigned' ?></h2>
                    <p class="text-xs font-bold text-slate-500 mb-0"><?= $hasApeBatch ? e($record['batch_patient_category']) : 'Assign this patient from Settings > APE Cycle.' ?></p>
                </div>
            </div>
            <?php if ($hasApeBatch): ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 min-w-0 lg:min-w-[32rem]">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <p class="clinic-label mb-1">Exam Date</p>
                        <strong class="text-sm text-slate-800"><?= e(date('F j, Y', strtotime($record['batch_schedule_date']))) ?></strong>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <p class="clinic-label mb-1">Time</p>
                        <strong class="text-sm text-slate-800"><?= e(date('g:i A', strtotime($record['batch_start_time']))) ?>–<?= e(date('g:i A', strtotime($record['batch_end_time']))) ?></strong>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 col-span-2 sm:col-span-1">
                        <p class="clinic-label mb-1">Status</p>
                        <span class="badge <?= $batchStatusClass ?>"><?= e($batchDisplayStatus) ?></span>
                    </div>
                </div>
            <?php else: ?>
                <span class="badge <?= $batchStatusClass ?>"><?= e($batchDisplayStatus) ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="clinic-card p-5 md:p-6 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <?php
            $workflowSteps = ape_workflow_steps();
            foreach ($workflowSteps as $i => $step):
                $stepNumber = $i + 1;
                $stepState = $staffProgress['steps'][$stepNumber];
                $done = $stepState['done'];
                $submitted = $stepState['submitted'];
                $active = $stepState['active'];
            ?>
                <div class="ape-flow-step rounded-xl border <?= $active ? 'border-primary bg-primary-fixed' : (($done || $submitted) ? 'border-emerald-100 bg-emerald-50/60' : 'border-outline-variant bg-slate-50/60') ?> p-3">
                    <span class="ape-flow-step-index <?= ($done || $submitted) ? 'bg-emerald-600 text-white' : ($active ? 'bg-primary text-white' : 'bg-white text-slate-400') ?>">
                        <?= $done ? '<span class="material-symbols-outlined text-[15px]">check</span>' : $i + 1 ?>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-widest <?= $active ? 'text-primary' : 'text-slate-400' ?> mb-1"><?= e($step) ?></p>
                        <p class="text-xs font-bold text-slate-500 mb-0"><?= $active ? 'Current step' : ($submitted ? 'Submitted' : ($done ? 'Completed' : ($stepNumber === 1 && $currentStep === 2 ? 'Uploads open' : 'Upcoming'))) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($examSaved): ?>
        <section class="ape-flow-panel ape-secondary-panel" aria-labelledby="savedApeExamTitle">
            <div class="ape-secondary-heading flex items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-headline text-lg font-extrabold mb-1" id="savedApeExamTitle">Examination</h2>
                    <p class="text-xs font-bold text-slate-500 mb-0">Saved examination. Document review is handled separately below.</p>
                </div>
                <span class="badge badge-completed"><span class="material-symbols-outlined text-[14px]">lock</span> Saved and locked</span>
            </div>
            <fieldset disabled class="grid grid-cols-1 md:grid-cols-2 gap-4" style="border:0; padding:0; margin:0; min-width:0;">
                <div>
                    <label class="clinic-label" for="savedApeExamDate">Examination Date</label>
                    <input class="clinic-input" id="savedApeExamDate" type="date" value="<?= e($record['exam_date']) ?>">
                </div>
                <div>
                    <label class="clinic-label" for="savedApeExamResult">Clinical Result</label>
                    <select class="clinic-select" id="savedApeExamResult">
                        <?php if ($savedExamResult === ''): ?><option selected>Not recorded</option><?php endif; ?>
                        <?php foreach (['Examined', 'Referred'] as $result): ?>
                            <option <?= (($result === 'Examined' && in_array($savedExamResult, ['Normal', 'With Finding'], true)) || $savedExamResult === $result) ? 'selected' : '' ?>><?= e($result) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (in_array($savedExamResult, ['Normal', 'With Finding'], true)): ?>
                    <div class="md:col-span-2"><label class="clinic-label" for="savedApeFinding">Examination Finding / Result</label><textarea id="savedApeFinding" class="clinic-textarea" rows="3"><?= e($savedExam['description'] ?? '') ?></textarea></div>
                    <div><label class="clinic-label" for="savedApeClinicalNotes">Internal Clinical Notes</label><textarea id="savedApeClinicalNotes" class="clinic-textarea" rows="3"><?= e($record['clinical_remarks'] ?? '') ?></textarea></div>
                    <div><label class="clinic-label" for="savedApePatientNotes">Patient-Visible Instructions</label><textarea id="savedApePatientNotes" class="clinic-textarea" rows="3"><?= e($record['patient_visible_note'] ?? '') ?></textarea></div>
                <?php elseif ($savedExamResult === 'Referred'): ?>
                    <div><label class="clinic-label" for="savedApeReferralTo">Facility / Specialist</label><input id="savedApeReferralTo" class="clinic-input" value="<?= e($savedReferral['referred_to'] ?? 'Not recorded') ?>"></div>
                    <div><label class="clinic-label" for="savedApeReferralReason">Referral Reason</label><textarea id="savedApeReferralReason" class="clinic-textarea" rows="3"><?= e($savedReferral['reason'] ?? $savedExam['description'] ?? '') ?></textarea></div>
                <?php endif; ?>
            </fieldset>
            <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 mb-4">
                    <div><p class="clinic-label text-primary mb-1">Recorded clinical information</p><p class="text-xs font-bold text-slate-500 mb-0">Vitals, BMI, blood type, conditions, and medications are clinic-managed. The result and referral decision remain locked.</p></div>
                    <?php if ($canRecordApeExam): ?><button type="button" class="btn btn-sm btn-outline" data-clinical-update-toggle><span class="material-symbols-outlined text-[16px]">edit_note</span> Update Clinical Information</button><?php endif; ?>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3 text-sm">
                    <div><p class="clinic-label mb-1">Height</p><strong><?= $record['patient_height_cm'] !== null ? e($record['patient_height_cm']) . ' cm' : 'Not recorded' ?></strong></div>
                    <div><p class="clinic-label mb-1">Weight</p><strong><?= $record['patient_weight_kg'] !== null ? e($record['patient_weight_kg']) . ' kg' : 'Not recorded' ?></strong></div>
                    <div><p class="clinic-label mb-1">BMI</p><strong><?= $record['patient_bmi'] !== null ? e($record['patient_bmi']) : 'Not recorded' ?></strong></div>
                    <div><p class="clinic-label mb-1">Temperature</p><strong><?= $record['patient_temperature'] !== null ? e($record['patient_temperature']) . ' °C' : 'Not recorded' ?></strong></div>
                    <div><p class="clinic-label mb-1">Blood pressure</p><strong><?= e($record['patient_blood_pressure'] ?: 'Not recorded') ?></strong></div>
                    <div><p class="clinic-label mb-1">Pulse</p><strong><?= $record['patient_pulse_rate'] !== null ? e($record['patient_pulse_rate']) . ' bpm' : 'Not recorded' ?></strong></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4 text-sm">
                    <div><p class="clinic-label mb-1">Clinic-recorded blood type</p><strong><?= e($record['patient_blood_type'] ?: 'Not recorded') ?></strong></div>
                    <div><p class="clinic-label mb-1">Doctor-confirmed medical conditions</p><strong class="whitespace-pre-wrap"><?= e($record['patient_existing_conditions'] ?: 'None recorded') ?></strong></div>
                    <div><p class="clinic-label mb-1">Current medications recorded during APE</p><strong class="whitespace-pre-wrap"><?= e($record['patient_medications'] ?: 'None recorded') ?></strong></div>
                </div>
                <?php if ($canRecordApeExam): ?>
                <form method="post" class="hidden mt-5 space-y-4" data-clinical-update-form>
                    <input type="hidden" name="action" value="update_clinical_information">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <div><label class="clinic-label" for="updateHeight">Height (cm)</label><input class="clinic-input" id="updateHeight" name="patient_height_cm" type="number" min="30" max="250" step="0.01" value="<?= e($record['patient_height_cm'] ?? '') ?>"></div>
                        <div><label class="clinic-label" for="updateWeight">Weight (kg)</label><input class="clinic-input" id="updateWeight" name="patient_weight_kg" type="number" min="1" max="400" step="0.01" value="<?= e($record['patient_weight_kg'] ?? '') ?>"></div>
                        <div><label class="clinic-label" for="updateBmi">BMI (calculated)</label><input class="clinic-input bg-slate-100" id="updateBmi" type="text" value="<?= e($record['patient_bmi'] ?? '') ?>" readonly></div>
                        <div><label class="clinic-label" for="updateTemperature">Temperature (°C)</label><input class="clinic-input" id="updateTemperature" name="patient_temperature" type="number" min="25" max="45" step="0.1" value="<?= e($record['patient_temperature'] ?? '') ?>"></div>
                        <div><label class="clinic-label" for="updateBloodPressure">Blood pressure</label><input class="clinic-input" id="updateBloodPressure" name="patient_blood_pressure" placeholder="120/80" value="<?= e($record['patient_blood_pressure'] ?? '') ?>"></div>
                        <div><label class="clinic-label" for="updatePulse">Pulse rate (bpm)</label><input class="clinic-input" id="updatePulse" name="patient_pulse_rate" type="number" min="20" max="250" step="1" value="<?= e($record['patient_pulse_rate'] ?? '') ?>"></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div><label class="clinic-label" for="updateBloodType">Blood type</label><select class="clinic-select" id="updateBloodType" name="blood_type"><option value="">Not recorded</option><?php foreach (dropdown_options('blood_type') as $type): ?><option value="<?= e($type) ?>" <?= ($record['patient_blood_type'] ?? '') === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></div>
                        <div class="md:col-span-2" data-clinical-pairs>
                            <label class="clinic-label">Doctor-confirmed conditions and current medications</label>
                            <p class="text-xs font-bold text-slate-500 mb-2">Use one row for a condition and its current medication. Leave either field blank when it does not apply.</p>
                            <div class="space-y-2" data-clinical-pair-rows>
                                <?php for ($itemIndex = 0; $itemIndex < $clinicalItemPairCount; $itemIndex++): ?>
                                    <div class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-2 sm:items-center" data-clinical-pair-row>
                                        <input class="clinic-input" name="existing_conditions[]" type="text" placeholder="Condition, e.g. Asthma" value="<?= e($existingConditionsItems[$itemIndex] ?? '') ?>">
                                        <input class="clinic-input" name="medications[]" type="text" placeholder="Medication, e.g. Salbutamol 100 mcg" value="<?= e($medicationsItems[$itemIndex] ?? '') ?>">
                                        <button class="btn btn-sm btn-outline self-start sm:self-auto" type="button" data-clinical-pair-remove>Remove</button>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <button class="btn btn-sm btn-outline mt-2" type="button" data-clinical-pair-add><span class="material-symbols-outlined text-[16px]">add</span> Add condition and medication</button>
                        </div>
                    </div>
                    <div><label class="clinic-label" for="updateClinicalRemarks">Internal clinical notes</label><textarea class="clinic-textarea" id="updateClinicalRemarks" name="clinical_remarks" rows="3"><?= e($record['clinical_remarks'] ?? '') ?></textarea></div>
                    <button class="btn btn-primary" type="submit"><span class="material-symbols-outlined text-[18px]">save</span> Save Clinical Information</button>
                </form>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="ape-flow-panel">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-3">
                <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-black text-primary uppercase tracking-widest mb-2"><?= e($queue['title']) ?></p>
                    <h2 class="font-headline text-xl md:text-2xl font-extrabold text-[#17261d] mb-1"><?= e($next['label']) ?></h2>
                    <p class="text-sm font-bold text-slate-500 mb-0 max-w-3xl"><?= e($adminStateExplanation) ?></p>
                </div>
                <span class="badge <?= ape_priority_badge($record)['class'] ?> shrink-0">
                    <?php if ($studentDocumentSubmitted && $reviewDocuments): ?>
                        <?= $reviewAwaitingCount ?> Document<?= $reviewAwaitingCount === 1 ? '' : 's' ?> Awaiting Review
                    <?php elseif ($displayPendingRequirements): ?>
                        <?= count($displayPendingRequirements) ?> Requirement<?= count($displayPendingRequirements) === 1 ? '' : 's' ?> Missing
                    <?php else: ?>
                        <?= e(ape_missing_item($record)) ?>
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($displayPendingRequirements && !$reviewWorkspaceActive): ?>
                <div class="flex flex-wrap gap-2 mb-5" aria-label="Requirements needing attention">
                    <?php foreach ($displayPendingRequirements as $requirement): ?>
                        <span class="ape-requirement-chip">
                            <?= e($requirement['requirement_name']) ?> (<?= e(($requirement['_latest_document']['verification_status'] ?? null) ?: ($requirement['status'] ?? 'Missing')) ?>)
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="mb-5"></div>
            <?php endif; ?>

            <?php if ($reviewWorkspaceActive): ?>
                <?php if ((int) ($record['follow_up_required'] ?? 0) === 1): ?>
                    <div class="ape-review-context-note mb-4">
                        <span class="material-symbols-outlined" aria-hidden="true">info</span>
                        <p class="mb-0">Follow-up documents use their assigned return date. Archive every file that is ready now; missing or returned requirements stay open for the student separately.</p>
                    </div>
                <?php endif; ?>
                <?php if (count($phaseThreeGroups) > 1): ?>
                    <div class="ape-review-group-tabs mb-4" role="tablist" aria-label="Document groups to review">
                        <?php foreach ($phaseThreeGroups as $groupKey => $groupRequirements): ?>
                            <a class="ape-review-group-tab <?= $reviewUploadGroup === $groupKey ? 'is-active' : '' ?>" href="?id=<?= (int) $id ?>&review_group=<?= e($groupKey) ?>" role="tab" aria-selected="<?= $reviewUploadGroup === $groupKey ? 'true' : 'false' ?>">
                                <span class="material-symbols-outlined" aria-hidden="true"><?= $groupKey === 'follow_up' ? 'assignment_add' : 'folder_open' ?></span>
                                <span><?= $groupKey === 'follow_up' ? 'Follow-up documents' : 'Initial documents' ?></span>
                                <span class="ape-review-group-tab-count"><?= count($groupRequirements) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($reviewWaitingRequirements): ?>
                    <div class="ape-review-waiting-state">
                        <div class="ape-review-waiting-icon"><span class="material-symbols-outlined" aria-hidden="true">hourglass_empty</span></div>
                        <div>
                            <p class="clinic-label text-amber-800 mb-1">Still waiting on the student</p>
                            <h3 class="font-headline text-base font-extrabold text-amber-950 mb-1"><?= count($reviewWaitingRequirements) ?> required file<?= count($reviewWaitingRequirements) === 1 ? '' : 's' ?> not submitted</h3>
                            <p class="text-sm font-bold text-amber-900 mb-4">The files below are not ready yet. Any submitted files are listed separately and can be reviewed now.</p>
                            <div class="flex flex-wrap gap-2" aria-label="Files still needed">
                                <?php foreach ($reviewWaitingRequirements as $requirement): ?>
                                    <span class="badge badge-pending"><?= e($requirement['requirement_name']) ?><?php if (!empty($requirement['upload_due_date'])): ?> · due <?= e(date('M d', strtotime($requirement['upload_due_date']))) ?><?php endif; ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($canClinicUploadBeforeStudentSubmission && $reviewWaitingNames): ?>
                                <details class="ape-review-clinic-upload mt-4">
                                    <summary><span class="material-symbols-outlined" aria-hidden="true">upload_file</span> Upload a retained clinic copy</summary>
                                    <p>Use this only when clinic staff are submitting the missing file on the student’s behalf. The upload will remain pending review.</p>
                                    <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-2">
                                        <input type="hidden" name="action" value="upload_clinic_document">
                                        <select class="clinic-select" name="document_type" aria-label="Requirement to upload" required><?php foreach ($reviewWaitingNames as $type): ?><option value="<?= e($type) ?>"><?= e($type) ?></option><?php endforeach; ?></select>
                                        <input class="clinic-input" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" required aria-label="Document file">
                                        <button class="btn btn-outline" data-confirm-submit data-confirm-title="Submit document for student?" data-confirm-message="This will be recorded as a clinic upload and remain pending clinic review." data-confirm-toast="Uploading document..."><span class="material-symbols-outlined text-[18px]">upload_file</span> Upload file</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($reviewDocuments): ?>
                    <div class="ape-review-workspace">
                        <section class="ape-review-file-panel" aria-labelledby="apeReviewFilesHeading">
                            <div class="flex items-center justify-between gap-3 mb-3">
                                <div>
                                    <p class="clinic-label mb-1">Files ready for review</p>
                                    <h3 id="apeReviewFilesHeading" class="font-headline text-base font-extrabold text-[#17261d] mb-1"><?= $reviewUploadGroup === 'follow_up' ? 'Submitted follow-up documents' : 'Submitted initial documents' ?></h3>
                                    <p class="text-xs font-bold text-slate-500 mb-0">Preview each file. Select only files that need to be returned; their original uploads remain in the audit trail.</p>
                                </div>
                                <span class="badge badge-in-progress"><?= count($reviewDocuments) ?> File<?= count($reviewDocuments) === 1 ? '' : 's' ?></span>
                            </div>
                            <div class="ape-document-review-list">
                                    <?php foreach ($reviewDocuments as $document): ?>
                                    <?php
                                    $canRequestCorrection = ($document['verification_status'] ?? '') === 'Pending';
                                    $displayName = ape_document_download_name((string) ($record['id_number'] ?? ''), (string) $document['document_type'], (string) $document['original_filename']);
                                    ?>
                                    <div class="ape-document-review-card">
                                        <?php if ($canRequestCorrection): ?>
                                            <input type="checkbox" name="document_ids[]" value="<?= (int) $document['document_id'] ?>" form="apeCorrectionForm" aria-label="Return <?= e($document['document_type']) ?> for resubmission">
                                        <?php else: ?>
                                            <span class="material-symbols-outlined text-slate-400" aria-hidden="true">description</span>
                                        <?php endif; ?>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                                <strong class="text-sm text-slate-800"><?= e($document['document_type']) ?></strong>
                                                <span class="badge <?= ape_status_badge_class($document['verification_status']) ?>"><?= e($document['verification_status']) ?></span>
                                            </div>
                                            <p class="text-xs font-bold text-slate-500 truncate mb-1" title="<?= e($displayName) ?>"><?= e($displayName) ?></p>
                                            <p class="text-[10px] font-black uppercase tracking-wider text-slate-400 mb-0"><?= e(date('M d, Y g:i A', strtotime($document['uploaded_at']))) ?></p>
                                        </div>
                                        <a href="<?= e(app_url('ape/document.php?id=' . (int) $document['document_id'])) ?>" class="ape-document-view-button btn btn-sm btn-outline text-decoration-none" data-file-preview data-preview-title="<?= e($displayName) ?>">
                                            <span class="material-symbols-outlined text-[14px]">preview</span> View File
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <aside class="ape-review-action-panel" data-document-review-actions aria-label="Document review decision">
                            <div>
                                <p class="clinic-label mb-1">Choose one action</p>
                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Complete this document review</h3>
                                    <p class="text-xs font-bold text-slate-500 mb-0">Archive the files ready now. Return only the affected files; missing requirements stay open separately.</p>
                            </div>
                            <div class="ape-review-mode-selector" role="group" aria-label="Document review action">
                                <button type="button" class="ape-review-mode-button is-active" data-document-review-mode="archive" aria-pressed="true"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span><span><strong>Archive group</strong><small>Accept all current files</small></span></button>
                                <button type="button" class="ape-review-mode-button" data-document-review-mode="return" aria-pressed="false"><span class="material-symbols-outlined" aria-hidden="true">assignment_return</span><span><strong>Return selected files</strong><small>Request a replacement</small></span></button>
                            </div>
                            <?php if ($canClinicUploadBeforeStudentSubmission && $reviewWaitingRequirements): ?>
                            <details class="ape-review-clinic-upload" data-document-review-upload>
                                <summary><span class="material-symbols-outlined" aria-hidden="true">upload_file</span> Need to add a clinic-held file?</summary>
                                <p>Use this only to upload a retained copy for the selected requirement. It will then appear in this review list as pending.</p>
                                <form method="post" enctype="multipart/form-data" class="space-y-3">
                                    <input type="hidden" name="action" value="upload_clinic_document">
                                    <div><label class="clinic-label" for="apeClinicPhaseThreeDocument">Requirement</label><select class="clinic-select" id="apeClinicPhaseThreeDocument" name="document_type" required><?php foreach ($reviewWaitingRequirements as $requirement): ?><option value="<?= e($requirement['requirement_name']) ?>"><?= e($requirement['requirement_name']) ?></option><?php endforeach; ?></select></div>
                                    <div><label class="clinic-label" for="apeClinicPhaseThreeFile">File</label><input class="clinic-input" id="apeClinicPhaseThreeFile" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" required></div>
                                    <button class="btn btn-outline w-full" data-confirm-submit data-confirm-title="Submit document for student?" data-confirm-message="This file will be recorded as a clinic upload and remain pending Phase 3 review." data-confirm-toast="Uploading document..."><span class="material-symbols-outlined text-[18px]">upload_file</span> Add file for review</button>
                                </form>
                            </details>
                            <?php endif; ?>
                            <div data-document-review-pane="archive">
                                <form method="post" class="space-y-3">
                                    <input type="hidden" name="action" value="approve_documents">
                                    <input type="hidden" name="review_group" value="<?= e($reviewUploadGroup) ?>">
                                    <div class="ape-review-decision-copy is-archive">
                                        <span class="material-symbols-outlined" aria-hidden="true">task_alt</span>
                                        <p class="mb-0">This accepts and archives the <?= count($reviewDocuments) ?> submitted file<?= count($reviewDocuments) === 1 ? '' : 's' ?> ready for review. Any missing requirement stays open for the student.</p>
                                    </div>
                                    <?php if (!$reviewDocuments): ?><p class="ape-review-validation-copy">No submitted file is ready for archive review yet.</p><?php endif; ?>
                                    <button class="btn btn-primary w-full" <?= !$examSaved || !$reviewDocuments || ($reviewUploadGroup === 'follow_up' && !$canRecordApeExam) ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Archive these documents?" data-confirm-message="This approves the submitted files that are ready now. Other missing or returned requirements will remain open." data-confirm-toast="Archiving documents..."><span class="material-symbols-outlined text-[18px]">inventory_2</span> Archive ready file<?= count($reviewDocuments) === 1 ? '' : 's' ?></button>
                                </form>
                            </div>
                            <div data-document-review-pane="return" hidden>
                                <form method="post" class="space-y-3" id="apeCorrectionForm">
                                    <input type="hidden" name="action" value="request_document_correction">
                                    <input type="hidden" name="review_group" value="<?= e($reviewUploadGroup) ?>">
                                    <div class="ape-review-decision-copy is-return"><span class="material-symbols-outlined" aria-hidden="true">assignment_return</span><p class="mb-0">Select at least one pending file in the list, then give the student clear instructions and a due date.</p></div>
                                    <label class="clinic-label" for="apeResubmissionReason">Required correction instructions</label>
                                    <textarea class="clinic-textarea" id="apeResubmissionReason" name="missing_items" rows="3" placeholder="Example: Please upload a clearer copy with your full name and signature visible." required></textarea>
                                    <label class="clinic-label" for="apeResubmissionDueDate">Return due date</label>
                                    <input class="clinic-input" id="apeResubmissionDueDate" name="follow_up_due_date" type="date" min="<?= e(date('Y-m-d')) ?>" required>
                                    <p class="ape-review-notification-copy"><span class="material-symbols-outlined" aria-hidden="true">mail</span>The student receives a correction notification and an email when delivery is enabled.</p>
                                    <button class="btn btn-outline w-full ape-review-return-submit" <?= !$examSaved || !$pendingReviewDocuments ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="danger" data-confirm-title="Return selected file(s) for resubmission?" data-confirm-message="The selected uploads will be marked Needs Correction. Their originals remain in history, and the student must upload replacement files." data-confirm-toast="Returning files for resubmission..."><span class="material-symbols-outlined text-[18px]">assignment_return</span> Return selected files</button>
                                </form>
                            </div>
                        </aside>
                    </div>
                <?php endif; ?>
            <?php elseif ($queueKey === 'examination' || (!$examSaved && !$apeIsCompleted)): ?>
                <?php if (!$canRecordApeExam): ?>
                    <div class="ape-flow-action muted">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-amber-700 mt-0.5">lock</span>
                            <div>
                                <h3 class="font-headline text-base font-extrabold text-amber-900 mb-1">Clinical permission required</h3>
                                <p class="text-sm font-bold text-amber-800 mb-0">Only administrators, doctors, and nurses can record or finalize an APE examination.</p>
                            </div>
                        </div>
                    </div>
                <?php elseif (!ape_examination_is_available($record)): ?>
                    <div class="ape-flow-action muted">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-amber-700 mt-0.5">schedule</span>
                            <div>
                                <h3 class="font-headline text-base font-extrabold text-amber-900 mb-1">Waiting for the assigned schedule</h3>
                                <p class="text-sm font-bold text-amber-800 mb-0">The examination form becomes available when this patient’s assigned batch starts and remains available if the schedule is missed.</p>
                            </div>
                        </div>
                    </div>
                <?php elseif ($examSaved): ?>
                    <div class="ape-flow-action">
                        <p class="text-sm font-bold text-slate-500 mb-0">The examination is locked. Review, return, archive, and any additional document requirements are handled in Final Decision or Follow-up.</p>
                    </div>
                <?php else: ?>
                    <form method="post" class="ape-flow-action space-y-5" id="apeExaminationForm" data-ape-id="<?= (int) $id ?>">
                        <input type="hidden" name="action" value="record_examination">
                        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                            <div>
                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Examination</h3>
                                <p class="text-xs font-bold text-slate-500 mb-0">Record the clinical examination. Document acceptance and any returned-file review happen in Final Decision or Follow-up.</p>
                            </div>
                            <span class="badge badge-in-progress">Clearance Pending</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="clinic-label" for="apeExamDate">Examination Date</label>
                                <input class="clinic-input" id="apeExamDate" name="exam_date" type="date" max="<?= e(date('Y-m-d')) ?>" value="<?= e($record['exam_date'] ?: date('Y-m-d')) ?>" required>
                            </div>
                            <div>
                                <label class="clinic-label" for="apeExamResult">Clinical Result</label>
                                <select class="clinic-select" id="apeExamResult" name="result_status" required>
                                    <option value="Examined">Examined</option>
                                    <option value="Referred">Referred</option>
                                </select>
                            </div>
                            <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                                <p class="clinic-label text-primary mb-3">Vital signs and body measurements</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                    <div><label class="clinic-label" for="apeHeight">Height (cm)</label><input class="clinic-input" id="apeHeight" name="patient_height_cm" type="number" min="30" max="250" step="0.01" value="<?= e($record['patient_height_cm'] ?? '') ?>"></div>
                                    <div><label class="clinic-label" for="apeWeight">Weight (kg)</label><input class="clinic-input" id="apeWeight" name="patient_weight_kg" type="number" min="1" max="400" step="0.01" value="<?= e($record['patient_weight_kg'] ?? '') ?>"></div>
                                    <div><label class="clinic-label" for="apeBmiPreview">BMI (calculated)</label><input class="clinic-input bg-slate-100" id="apeBmiPreview" type="text" value="<?= e($record['patient_bmi'] ?? '') ?>" readonly></div>
                                    <div><label class="clinic-label" for="apeTemperature">Temperature (°C)</label><input class="clinic-input" id="apeTemperature" name="patient_temperature" type="number" min="25" max="45" step="0.1" value="<?= e($record['patient_temperature'] ?? '') ?>"></div>
                                    <div><label class="clinic-label" for="apeBloodPressure">Blood pressure</label><input class="clinic-input" id="apeBloodPressure" name="patient_blood_pressure" placeholder="120/80" value="<?= e($record['patient_blood_pressure'] ?? '') ?>"></div>
                                    <div><label class="clinic-label" for="apePulse">Pulse rate (bpm)</label><input class="clinic-input" id="apePulse" name="patient_pulse_rate" type="number" min="20" max="250" step="1" value="<?= e($record['patient_pulse_rate'] ?? '') ?>"></div>
                                </div>
                            </div>
                            <div class="md:col-span-2 rounded-xl border border-slate-200 bg-white p-4">
                                <p class="clinic-label text-primary mb-3">Doctor-confirmed health information</p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div><label class="clinic-label" for="apeBloodType">Blood type</label><select class="clinic-select" id="apeBloodType" name="blood_type"><option value="">Not recorded</option><?php foreach (dropdown_options('blood_type') as $type): ?><option value="<?= e($type) ?>" <?= ($record['patient_blood_type'] ?? '') === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></div>
                                    <div><label class="clinic-label">Student-reported allergies</label><div class="passport-readonly-field"><?= e($record['patient_allergies'] ?: 'None reported') ?></div></div>
                                    <div class="md:col-span-2" data-clinical-pairs>
                                        <label class="clinic-label">Doctor-confirmed conditions and current medications</label>
                                        <p class="text-xs font-bold text-slate-500 mb-2">Use one row for a condition and its current medication. Leave either field blank when it does not apply.</p>
                                        <div class="space-y-2" data-clinical-pair-rows>
                                            <?php for ($itemIndex = 0; $itemIndex < $clinicalItemPairCount; $itemIndex++): ?>
                                                <div class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-2 sm:items-center" data-clinical-pair-row>
                                                    <input class="clinic-input" name="existing_conditions[]" type="text" placeholder="Condition, e.g. Asthma" value="<?= e($existingConditionsItems[$itemIndex] ?? '') ?>">
                                                    <input class="clinic-input" name="medications[]" type="text" placeholder="Medication, e.g. Salbutamol 100 mcg" value="<?= e($medicationsItems[$itemIndex] ?? '') ?>">
                                                    <button class="btn btn-sm btn-outline self-start sm:self-auto" type="button" data-clinical-pair-remove>Remove</button>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                        <button class="btn btn-sm btn-outline mt-2" type="button" data-clinical-pair-add><span class="material-symbols-outlined text-[16px]">add</span> Add condition and medication</button>
                                    </div>
                                </div>
                            </div>
                            <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4" id="apeFindingDetails">
                                <div class="md:col-span-2">
                                    <label class="clinic-label" for="apeFindingDescription">Examination Finding / Result</label>
                                    <textarea class="clinic-textarea" id="apeFindingDescription" name="finding_description" rows="3" placeholder="Describe the examination result and any finding..."></textarea>
                                </div>
                                <div>
                                    <label class="clinic-label" for="apeClinicalRemarks">Internal Clinical Notes</label>
                                    <textarea class="clinic-textarea" id="apeClinicalRemarks" name="clinical_remarks" rows="3" placeholder="Visible only to authorized clinic staff..."><?= e($record['clinical_remarks']) ?></textarea>
                                </div>
                                <div>
                                    <label class="clinic-label" for="apePatientNote">Patient-Visible Instructions</label>
                                    <textarea class="clinic-textarea" id="apePatientNote" name="patient_visible_note" rows="3" placeholder="Instructions that the patient can see..."><?= e($record['patient_visible_note']) ?></textarea>
                                </div>
                            </div>
                            <div class="md:col-span-2 rounded-xl border border-amber-200 bg-amber-50 p-4">
                                <p class="clinic-label text-amber-800 mb-1">Optional follow-up document</p>
                                <p class="text-xs font-bold text-amber-800 mb-3">Add this only when the examination finding requires another document, such as a specialist clearance. The student will submit it in Final Decision or Follow-up after this examination is saved.</p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div><label class="clinic-label" for="apeFollowUpRequirementName">Document title</label><input class="clinic-input" id="apeFollowUpRequirementName" name="follow_up_requirement_name" maxlength="160" placeholder="e.g. TB clearance certificate"><p class="ape-follow-up-plan-help mt-2 mb-0">Name the exact file the student must submit.</p></div>
                                    <div><label class="clinic-label" for="apeFollowUpRequirementDueDate">Due date</label><input class="clinic-input" id="apeFollowUpRequirementDueDate" name="follow_up_requirement_due_date" type="date" min="<?= e(date('Y-m-d')) ?>"></div>
                                    <div class="md:col-span-2"><label class="clinic-label" for="apeFollowUpRequirementInstructions">Patient instructions</label><textarea class="clinic-textarea" id="apeFollowUpRequirementInstructions" name="follow_up_requirement_instructions" rows="2" placeholder="Explain exactly what the student needs to submit."></textarea></div>
                                    <div class="md:col-span-2"><label class="clinic-label" for="apeFollowUpRequirementRemark">Clinic-only remark (optional)</label><input class="clinic-input" id="apeFollowUpRequirementRemark" name="follow_up_requirement_remark" placeholder="Internal context for the clinic team"></div>
                                </div>
                            </div>
                            <div class="md:col-span-2 hidden rounded-xl border border-amber-200 bg-amber-50 p-4" id="apeReferralDetails">
                                <p class="clinic-label text-amber-800 mb-1">Immediate Referral (only when result is Referred)</p>
                                <p class="text-xs font-bold text-amber-700 mb-3">A referral is created immediately with this examination; it will not wait for a separate decision step.</p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <label class="clinic-label" for="apeReferralDestination">Facility / Specialist</label>
                                        <input class="clinic-input" id="apeReferralDestination" name="referred_to" placeholder="Referral destination">
                                    </div>
                                    <div>
                                        <label class="clinic-label" for="apeReferralReason">Referral Reason</label>
                                        <textarea class="clinic-textarea" id="apeReferralReason" name="referral_reason" rows="2" placeholder="Clinical reason for referral..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="text-xs font-bold text-slate-500 mb-0">Save Examination records the clinical result, keeps current uploads available, and moves the record to Final Decision or Follow-up.</p>
                    </form>
                <?php endif; ?>
            <?php elseif ($queueKey === 'final_decision'): ?>
                <?php render_ape_final_decision_actions($record, $canRecordApeExam, $digitalSubmissionComplete); ?>
            <?php elseif ($queueKey === 'follow_up'): ?>
                <div class="grid grid-cols-1 xl:grid-cols-[1fr_0.9fr] gap-4">
                    <div class="ape-flow-action muted space-y-4">
                        <div>
                            <p class="clinic-label">Required Follow-up</p>
                            <p class="text-sm font-bold text-amber-900 whitespace-pre-wrap mb-0"><?= e($record['missing_items'] ?: 'Follow-up requirement not specified.') ?></p>
                            <?php $followUpDueDate = ape_follow_up_due_date($record); ?>
                            <?php if ($followUpDueDate): ?>
                                <p class="text-xs font-black text-amber-700 uppercase tracking-widest mt-3 mb-0">Due <?= e(date('M d, Y', strtotime($followUpDueDate))) ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <p class="clinic-label">Clearance File</p>
                            <?php if ($clearanceUrl): ?>
                                <a href="<?= e($clearanceUrl) ?>" class="btn btn-sm btn-outline text-decoration-none border-amber-200 text-amber-700 hover:bg-amber-100" data-file-preview data-preview-title="Patient clearance document">
                                    <span class="material-symbols-outlined text-[14px]">preview</span> View Clearance
                                </a>
                            <?php else: ?>
                                <p class="text-sm font-bold text-amber-800 mb-0">Waiting for patient upload.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-3">
                        <form method="post" class="ape-flow-action space-y-3">
                            <input type="hidden" name="action" value="keep_follow_up_open">
                            <label class="clinic-label">Follow-up Notes</label>
                            <textarea class="clinic-textarea" name="follow_up_notes" rows="4" placeholder="Treatment or follow-up notes..."><?= e($record['clinical_remarks']) ?></textarea>
                            <div>
                                <label class="clinic-label">Due Date</label>
                                <input class="clinic-input" type="date" name="follow_up_due_date" value="<?= e($record['follow_up_due_date'] ?? '') ?>">
                            </div>
                            <button class="btn btn-ghost w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Keep follow-up open?" data-confirm-message="This will save the latest follow-up notes without closing the APE record." data-confirm-toast="Saving follow-up notes..."><span class="material-symbols-outlined text-[18px]">history</span> Keep Follow-up Open</button>
                        </form>
                        <?php if (!ape_explicit_clearance_required($record)): ?>
                            <form method="post" class="ape-flow-action space-y-3">
                                <input type="hidden" name="action" value="resolve_clinical_follow_up">
                                <h3 class="font-headline text-base font-extrabold text-[#17261d] mb-1">Resolve clinical follow-up</h3>
                                <p class="text-xs font-bold text-slate-500 mb-2">Use when the clinic has completed the required clinical review and no clearance document was assigned.</p>
                                <textarea class="clinic-textarea" name="resolution_note" rows="2" placeholder="Resolution note (optional)"></textarea>
                                <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Resolve clinical follow-up?" data-confirm-message="The record will return to Final Decision for the final completion check." data-confirm-toast="Resolving follow-up..."><span class="material-symbols-outlined text-[18px]">task_alt</span> Resolve follow-up</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($clearanceUrl || ($record['clearance_status'] ?? '') === 'Submitted'): ?>
                            <form method="post" class="ape-flow-action space-y-3">
                                <input type="hidden" name="action" value="approve_clearance">
                                <h3 class="font-headline text-base font-extrabold text-[#17261d] mb-1">Clear Follow-up</h3>
                                <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Approve this clearance?" data-confirm-message="This will approve the patient's clearance document." data-confirm-toast="Approving clearance..."><span class="material-symbols-outlined text-[18px]">verified</span> Approve Clearance</button>
                            </form>
                            <form method="post" class="ape-flow-action muted space-y-3">
                                <input type="hidden" name="action" value="return_clearance">
                                <label class="clinic-label">Reason for Return</label>
                                <textarea class="clinic-textarea" name="missing_items" rows="3" placeholder="Reason for returning clearance..."><?= e($record['missing_items']) ?></textarea>
                                <button class="btn btn-outline w-full" style="color:#b45309;border-color:rgba(180,83,9,0.2);" data-confirm-submit data-confirm-type="danger" data-confirm-title="Return clearance for correction?" data-confirm-message="This will return the clearance document to the patient for correction." data-confirm-toast="Returning clearance..."><span class="material-symbols-outlined text-[18px]">assignment_return</span> Return for Correction</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="ape-flow-action">
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-emerald-600 mt-0.5">task_alt</span>
                        <div>
                            <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">APE Record Completed</h3>
                            <p class="text-sm font-bold text-slate-500 mb-0">This APE record is cleared and archived in the clinic file.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($examSaved && !$apeIsCompleted && $canRecordApeExam && $queueKey === 'follow_up'): ?>
        <section class="ape-flow-panel ape-secondary-panel" aria-labelledby="apeAddFollowUpDocumentTitle">
            <div class="ape-secondary-heading mb-4">
                <div>
                    <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1" id="apeAddFollowUpDocumentTitle">Add follow-up document requirement</h2>
                    <p class="text-xs font-bold text-slate-500 mb-0">Use this only for a document required after examination. The student will receive the task in Final Decision or Follow-up.</p>
                </div>
            </div>
            <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <input type="hidden" name="action" value="add_requirement">
                <input type="hidden" name="requirement_item_status" value="Missing">
                <div><label class="clinic-label" for="apeFollowUpDocumentName">Document title</label><input class="clinic-input" id="apeFollowUpDocumentName" name="requirement_name" maxlength="160" placeholder="e.g. Specialist clearance certificate" required><p class="ape-follow-up-plan-help mt-2 mb-0">Name the exact file the student must submit.</p></div>
                <div><label class="clinic-label" for="apeFollowUpDocumentDueDate">Due date</label><input class="clinic-input" id="apeFollowUpDocumentDueDate" name="requirement_due_date" type="date" min="<?= e(date('Y-m-d')) ?>" required></div>
                <div class="md:col-span-2"><label class="clinic-label" for="apeFollowUpDocumentInstructions">Patient instructions</label><textarea class="clinic-textarea" id="apeFollowUpDocumentInstructions" name="requirement_instructions" rows="2" placeholder="Explain exactly what the student must submit." required></textarea></div>
                <div><label class="clinic-label" for="apeFollowUpDocumentRemark">Clinic-only remark (optional)</label><input class="clinic-input" id="apeFollowUpDocumentRemark" name="requirement_remarks" placeholder="Internal context"></div>
                <div class="flex items-end"><button class="btn btn-outline w-full" data-confirm-submit data-confirm-title="Add follow-up requirement?" data-confirm-message="The student will receive an upload task in Final Decision or Follow-up." data-confirm-toast="Adding follow-up requirement..."><span class="material-symbols-outlined text-[18px]">playlist_add</span> Add follow-up document</button></div>
            </form>
        </section>
        <?php endif; ?>

        <div class="grid grid-cols-1 gap-4">
            <?php if ($showRequirementsChecklist): ?>
            <section class="ape-initial-documents-panel" id="apeRequirementsChecklist" data-ape-requirements data-locked="false">
                <details class="ape-initial-documents-details">
                    <summary class="ape-initial-documents-summary">
                        <span class="ape-initial-documents-icon material-symbols-outlined" aria-hidden="true">folder_open</span>
                        <span class="min-w-0 flex-1">
                            <strong>Initial documents</strong>
                            <small>Optional clinic uploads before the examination is saved</small>
                        </span>
                        <span class="badge badge-pending"><?= count($requirements) ?> required</span>
                        <span class="material-symbols-outlined ape-initial-documents-chevron" aria-hidden="true">expand_more</span>
                    </summary>
                    <div class="ape-initial-documents-body">
                        <div class="ape-initial-documents-intro">
                            <div>
                                <p class="clinic-label text-primary mb-1">Phase 1 · Document Keeping</p>
                                <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Upload a retained clinic copy when needed</h2>
                                <p class="text-xs font-bold text-slate-500 mb-0">Use this only when clinic staff are submitting an initial file for the student. Do not approve or return files here—those decisions happen in Final Decision or Follow-up.</p>
                            </div>
                        </div>
                <div class="ape-initial-document-list">
                    <?php foreach ($requirements as $requirement): ?>
                            <div class="ape-initial-document-row">
                            <div class="ape-checklist-meta">
                                <strong><?= e($requirement['requirement_name']) ?></strong>
                                <span><?= !empty($requirement['_latest_document']['document_id']) ? 'A file is attached and will be reviewed in Phase 3.' : 'No file has been attached yet.' ?></span>
                            </div>
                            <div class="ape-initial-document-state">
                                <span class="badge <?= ape_status_badge_class($requirement['status'] ?? 'Missing') ?>"><?= e($requirement['status'] ?? 'Missing') ?></span>
                                <span class="text-xs font-bold text-slate-400">Review happens in Phase 3</span>
                            </div>
                            <div class="ape-initial-document-actions">
                                    <?php if ($canClinicUploadBeforeStudentSubmission): ?>
                                        <button type="button" class="btn btn-sm btn-outline text-decoration-none shrink-0" data-clinic-upload-trigger data-requirement-name="<?= e($requirement['requirement_name']) ?>">
                                            <span class="material-symbols-outlined text-[14px]">upload_file</span> Upload
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!empty($requirement['_latest_document']['document_id'])): ?>
                                        <a href="<?= e(app_url('ape/document.php?id=' . (int) $requirement['_latest_document']['document_id'])) ?>"
                                            class="btn btn-sm btn-outline text-decoration-none shrink-0"
                                            data-file-preview
                                            data-preview-title="<?= e(ape_document_download_name((string) ($record['id_number'] ?? ''), (string) $requirement['requirement_name'], (string) ($requirement['_latest_document']['original_filename'] ?? ''))) ?>"
                                            aria-label="Preview uploaded <?= e($requirement['requirement_name']) ?>">
                                            <span class="material-symbols-outlined text-[14px]">preview</span> Preview
                                        </a>
                                    <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="flex flex-wrap gap-3 mt-4">
                    <button type="button" class="btn btn-outline" onclick="showModal('apeAddRequirementModal')">
                        <span class="material-symbols-outlined text-[18px]">playlist_add</span> Add Requirement
                    </button>
                </div>
                <div class="modal-backdrop" id="apeAddRequirementModal" style="display:none;">
                    <div class="modal-content bg-white rounded-[1.75rem] border border-outline-variant/20 p-6 sm:p-8 w-full max-w-lg shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="apeAddRequirementTitle" aria-describedby="apeAddRequirementDescription">
                        <div class="flex items-start justify-between gap-4 mb-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-11 h-11 rounded-xl bg-primary/10 text-primary flex items-center justify-center shrink-0" aria-hidden="true">
                                    <span class="material-symbols-outlined">playlist_add</span>
                                </div>
                                <h3 class="font-headline text-xl font-extrabold text-[#17261d] mb-0" id="apeAddRequirementTitle">Add Requirement</h3>
                            </div>
                            <button type="button" class="btn btn-sm btn-ghost shrink-0" onclick="closeModal('apeAddRequirementModal')" aria-label="Close add requirement"><span class="material-symbols-outlined">close</span></button>
                        </div>
                        <p class="text-sm leading-relaxed text-slate-500 mb-6" id="apeAddRequirementDescription">Use this only for an initial Document Keeping requirement. Condition-specific follow-up documents are added from Final Decision or Follow-up after the examination.</p>
                        <form method="post" class="space-y-5">
                            <input type="hidden" name="action" value="add_requirement">
                            <input type="hidden" name="requirement_item_status" value="Missing">
                            <div>
                                <label class="clinic-label" for="apeNewRequirementName">Requirement name</label>
                                <input class="clinic-input w-full" id="apeNewRequirementName" name="requirement_name" maxlength="160" placeholder="Enter requirement name" required>
                            </div>
                            <div>
                                <label class="clinic-label" for="apeNewRequirementRemarks">Optional remarks</label>
                                <input class="clinic-input w-full" id="apeNewRequirementRemarks" name="requirement_remarks" placeholder="Add instructions or notes">
                            </div>
                            <button type="submit" class="btn btn-primary w-full" data-confirm-submit data-confirm-title="Add requirement?" data-confirm-message="This adds an initial document requirement to Document Keeping." data-confirm-toast="Adding requirement...">
                                <span class="material-symbols-outlined text-[18px]">playlist_add</span> Add Requirement
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($canClinicUploadBeforeStudentSubmission && $requirements): ?>
                    <div class="modal-backdrop" id="apeClinicUploadModal" style="display:none;" data-clinic-upload-modal>
                        <div class="modal-content bg-white rounded-[1.75rem] border border-outline-variant/20 p-6 sm:p-8 w-full max-w-xl shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="apeClinicUploadTitle">
                            <div class="flex items-start justify-between gap-4 mb-3">
                                <div>
                                    <p class="clinic-label text-primary mb-1">Clinic document upload</p>
                                    <h3 class="font-headline text-xl font-extrabold text-[#17261d] mb-0" id="apeClinicUploadTitle">Submit file</h3>
                                </div>
                                <button type="button" class="btn btn-sm btn-ghost" data-clinic-upload-close aria-label="Close upload dialog"><span class="material-symbols-outlined">close</span></button>
                            </div>
                            <form method="post" enctype="multipart/form-data" class="space-y-4" data-clinic-upload-form>
                                <input type="hidden" name="action" value="upload_clinic_document">
                                <input type="hidden" name="document_type" data-clinic-upload-type>
                                <div class="rounded-xl border border-primary/20 bg-primary-fixed p-4">
                                    <p class="clinic-label text-primary mb-1">Requirement</p>
                                    <strong class="text-sm text-[#17261d]" data-clinic-upload-requirement></strong>
                                </div>
                                <div>
                                    <label class="clinic-label" for="clinicApeDocumentFile">File (PDF, JPG, JPEG, or PNG; max 2 MB)</label>
                                    <input class="clinic-input" id="clinicApeDocumentFile" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" required data-clinic-upload-file>
                                </div>
                                <div class="hidden rounded-xl border border-slate-200 bg-slate-50 p-3" data-clinic-upload-preview-wrap>
                                    <p class="clinic-label mb-2">Preview before saving</p>
                                    <div class="rounded-lg bg-white border border-slate-200 overflow-hidden" data-clinic-upload-preview></div>
                                    <p class="text-xs font-bold text-slate-500 mt-2 mb-0" data-clinic-upload-filename></p>
                                </div>
                                <p class="text-xs font-bold text-slate-500 mb-0">The file will be stored on this student’s APE record as Pending for clinic review.</p>
                                <div class="flex flex-wrap gap-3 justify-end">
                                    <button type="button" class="btn btn-ghost" data-clinic-upload-close>Cancel</button>
                                    <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Submit this document?" data-confirm-message="The previewed file will be added to this student’s APE record." data-confirm-toast="Uploading document..."><span class="material-symbols-outlined text-[18px]">upload_file</span> Submit File</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    </div>
                </details>
            </section>
            <?php else: ?>

            <section class="ape-flow-panel ape-secondary-panel">
                <div class="ape-secondary-heading flex items-center justify-between gap-3 mb-4">
                    <div>
                        <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Uploaded Documents</h2>
                        <p class="text-xs font-bold text-slate-500 mb-0">Archived patient and clinic uploads with verification details.</p>
                    </div>
                    <span class="badge badge-in-progress"><?= count($visibleArchivedDocuments) ?> file(s)</span>
                </div>
                <div class="space-y-2">
                    <?php foreach ($visibleArchivedDocuments as $document): ?>
                        <?php $displayName = ape_document_download_name((string) ($record['id_number'] ?? ''), (string) $document['document_type'], (string) $document['original_filename']); ?>
                        <div class="ape-flow-field">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                                <div>
                                    <strong class="text-sm text-slate-800 block"><?= e($document['document_type']) ?></strong>
                                    <p class="text-xs font-bold text-slate-500 mt-1 mb-0"><?= e($displayName) ?></p>
                                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-2 mb-0">
                                        Uploaded by <?= e($document['uploaded_by_name'] ?: 'System') ?> · <?= e(date('M d, Y g:i A', strtotime($document['uploaded_at']))) ?>
                                    </p>
                                    <?php if ($document['verified_at']): ?>
                                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-1 mb-0">
                                            Reviewed by <?= e($document['verified_by_name'] ?: 'Clinic staff') ?> · <?= e(date('M d, Y g:i A', strtotime($document['verified_at']))) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="badge <?= ape_status_badge_class($document['verification_status']) ?>"><?= e($document['verification_status']) ?></span>
                                    <a class="btn btn-sm btn-outline text-decoration-none" href="<?= e(app_url('ape/document.php?id=' . (int) $document['document_id'])) ?>" data-file-preview data-preview-title="<?= e($displayName) ?>">
                                        <span class="material-symbols-outlined text-[14px]">preview</span> Open
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$visibleArchivedDocuments): ?>
                        <div class="ape-flow-field text-center text-sm font-bold text-slate-500">No archived document has been stored yet.</div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>

        <?php if (!empty($record['exam_date'])): ?>
        <section class="ape-flow-panel ape-secondary-panel">
            <div class="ape-secondary-heading flex items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Clinical Findings</h2>
                    <p class="text-xs font-bold text-slate-500 mb-0">Individual examination findings recorded by authorized clinic staff.</p>
                </div>
                <span class="badge <?= $findings ? 'badge-high' : 'badge-completed' ?>"><?= count($findings) ?> finding(s)</span>
            </div>
            <div class="space-y-2">
                    <?php foreach ($findings as $finding): ?>
                        <div class="ape-flow-field">
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                <strong class="text-sm text-slate-800"><?= e($finding['finding_type']) ?></strong>
                                <?php $findingDisplayResult = in_array($finding['result_status'], ['Normal', 'With Finding'], true) ? 'Examined' : $finding['result_status']; ?>
                                <span class="badge <?= ape_status_badge_class($findingDisplayResult) ?>"><?= e($findingDisplayResult) ?></span>
                            </div>
                            <p class="text-sm font-bold text-slate-600 whitespace-pre-wrap mb-0"><?= e($finding['description']) ?></p>
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-2 mb-0">
                                <?= $finding['follow_up_required'] ? 'Clinical follow-up required' : 'No clinical follow-up recorded' ?><?php if ($documentFollowUp): ?> · Document follow-up required<?php endif; ?> · <?= e($finding['recorded_by_name'] ?: 'Clinic staff') ?> · <?= e(date('M d, Y g:i A', strtotime($finding['recorded_at']))) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$findings): ?>
                        <div class="ape-flow-field text-center text-sm font-bold text-slate-500">No clinical finding has been recorded.</div>
                    <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
        <div class="grid grid-cols-1 lg:grid-cols-[0.95fr_1.05fr] gap-4">
            <section class="ape-flow-panel ape-secondary-panel">
                <div class="ape-secondary-heading mb-4"><h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-0">Additional Notes</h2></div>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="save_notes">
                    <div>
                        <label class="clinic-label">Patient-Visible Note</label>
                        <textarea class="clinic-textarea" name="patient_visible_note" rows="3" placeholder="Notes the patient can see..."><?= e($record['patient_visible_note']) ?></textarea>
                    </div>
                    <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save these notes?" data-confirm-message="This will update the notes on this APE record." data-confirm-toast="Saving notes..."><span class="material-symbols-outlined text-[18px]">save</span> Save Notes</button>
                </form>
            </section>

            <section class="ape-flow-panel overflow-hidden ape-secondary-panel">
                <div class="p-5 border-b border-slate-100 ape-secondary-heading">
                    <div>
                        <h2 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Activity History</h2>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-1 mb-0">APE #<?= (int) $record['ape_id'] ?> &bull; Patient ID <?= e($record['id_number']) ?></p>
                    </div>
                </div>
                <div class="ape-flow-activity divide-y divide-slate-100">
                    <?php foreach ($activities as $activity): ?>
                        <div class="p-4">
                            <strong class="text-sm text-slate-800 block"><?= e($activity['action_label']) ?></strong>
                            <?php if ($activity['notes']): ?>
                                <p class="text-xs font-bold text-slate-500 mt-1 mb-1"><?= e($activity['notes']) ?></p>
                            <?php endif; ?>
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-2 mb-0">
                                <?= e($activity['user_name'] ?? 'System') ?> &bull; <?= e(date('M d, Y g:i A', strtotime($activity['created_at']))) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$activities): ?>
                        <div class="p-6 text-center">
                            <span class="material-symbols-outlined text-slate-300 text-3xl mb-2">history</span>
                            <p class="text-xs font-bold text-slate-500 m-0">No activity yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <?php if ($showExamForm): ?>
            <section class="ape-flow-panel">
                <div class="rounded-xl border border-primary/20 bg-primary-fixed p-4 mb-3">
                    <p class="clinic-label text-primary mb-1">What happens next</p>
                    <p class="text-sm font-bold text-slate-700 mb-0">The clinical examination will lock. All document uploads and document decisions continue in Final Decision or Follow-up.</p>
                </div>
                <p class="text-sm font-bold text-slate-500 mb-3">Saving does not accept or archive any document. The student may still submit outstanding initial or follow-up requirements.</p>
                <button type="submit" form="apeExaminationForm" class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save and lock examination?" data-confirm-message="This saves and locks the clinical result, then moves the record to Final Decision or Follow-up for document review." data-confirm-toast="Saving examination...">
                    <span class="material-symbols-outlined text-[18px]">clinical_notes</span> Save Examination
                </button>
            </section>
        <?php endif; ?>
    </section>
</div>

<?php if ($canClinicUploadBeforeStudentSubmission && $requirements): ?>
<script>
(() => {
    const modal = document.querySelector('[data-clinic-upload-modal]');
    const form = document.querySelector('[data-clinic-upload-form]');
    if (!modal || !form) return;
    const typeInput = form.querySelector('[data-clinic-upload-type]');
    const requirementLabel = form.querySelector('[data-clinic-upload-requirement]');
    const fileInput = form.querySelector('[data-clinic-upload-file]');
    const previewWrap = form.querySelector('[data-clinic-upload-preview-wrap]');
    const preview = form.querySelector('[data-clinic-upload-preview]');
    const filename = form.querySelector('[data-clinic-upload-filename]');

    const close = () => {
        modal.style.display = 'none';
        form.reset();
        typeInput.value = '';
        requirementLabel.textContent = '';
        preview.innerHTML = '';
        filename.textContent = '';
        previewWrap.classList.add('hidden');
    };
    const open = (requirementName) => {
        typeInput.value = requirementName;
        requirementLabel.textContent = requirementName;
        modal.style.display = 'flex';
        fileInput.focus();
    };
    document.querySelectorAll('[data-clinic-upload-trigger]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            open(button.dataset.requirementName || '');
        });
    });
    document.querySelectorAll('[data-clinic-upload-row]').forEach((row) => {
        const activate = () => open(row.dataset.requirementName || '');
        row.addEventListener('click', (event) => {
            if (event.target.closest('button, a, input, select, textarea, form')) return;
            activate();
        });
        row.addEventListener('keydown', (event) => {
            if ((event.key === 'Enter' || event.key === ' ') && event.target === row) {
                event.preventDefault();
                activate();
            }
        });
    });
    document.querySelectorAll('[data-clinic-upload-close]').forEach((button) => button.addEventListener('click', close));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) close();
    });
    fileInput.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0];
        preview.innerHTML = '';
        previewWrap.classList.add('hidden');
        filename.textContent = '';
        if (!file) return;
        filename.textContent = `${file.name} · ${(file.size / 1024 / 1024).toFixed(2)} MB`;
        if (file.size > 2 * 1024 * 1024) {
            filename.textContent += ' · File exceeds the 2 MB limit';
            return;
        }
        const objectUrl = URL.createObjectURL(file);
        if (file.type === 'application/pdf') {
            preview.innerHTML = `<embed src="${objectUrl}" type="application/pdf" style="width:100%;height:18rem;">`;
        } else if (file.type === 'image/jpeg' || file.type === 'image/png') {
            preview.innerHTML = `<img src="${objectUrl}" alt="Selected document preview" style="display:block;max-height:18rem;width:100%;object-fit:contain;">`;
        } else {
            URL.revokeObjectURL(objectUrl);
            filename.textContent += ' · Unsupported file type';
            return;
        }
        previewWrap.classList.remove('hidden');
    });
})();
</script>
<?php endif; ?>
<script>
    (() => {
        document.querySelectorAll('.ape-secondary-panel').forEach((panel) => {
            const heading = panel.querySelector(':scope > .ape-secondary-heading');
            if (!heading || heading.querySelector('.ape-secondary-toggle')) return;
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'ape-secondary-toggle';
            toggle.textContent = 'View details';
            toggle.setAttribute('aria-expanded', 'false');
            heading.append(toggle);
            toggle.addEventListener('click', () => {
                const open = panel.classList.toggle('is-open');
                toggle.textContent = open ? 'Hide details' : 'View details';
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });

        document.querySelectorAll('[data-document-review-actions]').forEach((group) => {
            const buttons = group.querySelectorAll('[data-document-review-mode]');
            const panes = group.querySelectorAll('[data-document-review-pane]');
            const clinicUpload = group.querySelector('[data-document-review-upload]');
            const setMode = (mode) => {
                if (clinicUpload) clinicUpload.open = false;
                buttons.forEach((other) => {
                    const selected = other.dataset.documentReviewMode === mode;
                    other.classList.toggle('is-active', selected);
                    other.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });
                panes.forEach((pane) => {
                    pane.hidden = pane.dataset.documentReviewPane !== mode;
                });
            };
            buttons.forEach((button) => {
                button.addEventListener('click', () => {
                    setMode(button.dataset.documentReviewMode);
                });
            });
            if (clinicUpload) {
                clinicUpload.addEventListener('toggle', () => {
                    if (!clinicUpload.open) return;
                    buttons.forEach((button) => {
                        button.classList.remove('is-active');
                        button.setAttribute('aria-pressed', 'false');
                    });
                    panes.forEach((pane) => {
                        pane.hidden = true;
                    });
                });
            }
            const correctionCheckboxes = group.ownerDocument.querySelectorAll('input[type="checkbox"][form="apeCorrectionForm"]');
            correctionCheckboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    const hasSelectedFile = Array.from(correctionCheckboxes).some((item) => item.checked);
                    setMode(hasSelectedFile ? 'return' : 'archive');
                });
            });
        });

        document.querySelectorAll('[data-clinical-update-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const form = button.closest('div').parentElement.querySelector('[data-clinical-update-form]');
                if (!form) return;
                const hidden = form.classList.toggle('hidden');
                button.setAttribute('aria-expanded', hidden ? 'false' : 'true');
            });
        });
        const bindBmi = (heightId, weightId, bmiId) => {
            const height = document.getElementById(heightId);
            const weight = document.getElementById(weightId);
            const bmi = document.getElementById(bmiId);
            if (!height || !weight || !bmi) return;
            const update = () => {
                const h = Number(height.value);
                const w = Number(weight.value);
                bmi.value = h > 0 && w > 0 ? (w / ((h / 100) ** 2)).toFixed(2) : '';
            };
            height.addEventListener('input', update);
            weight.addEventListener('input', update);
            update();
        };
        bindBmi('apeHeight', 'apeWeight', 'apeBmiPreview');
        bindBmi('updateHeight', 'updateWeight', 'updateBmi');

        document.querySelectorAll('[data-clinical-pairs]').forEach((list) => {
            const rows = list.querySelector('[data-clinical-pair-rows]');
            const addButton = list.querySelector('[data-clinical-pair-add]');
            if (!rows || !addButton) return;
            const updateRemoveButtons = () => {
                rows.querySelectorAll('[data-clinical-pair-remove]').forEach((button) => {
                    button.hidden = rows.children.length === 1;
                });
            };
            const addRow = () => {
                const row = document.createElement('div');
                row.className = 'grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-2 sm:items-center';
                row.dataset.clinicalPairRow = '';
                row.innerHTML = '<input class="clinic-input" name="existing_conditions[]" type="text" placeholder="Condition, e.g. Asthma"><input class="clinic-input" name="medications[]" type="text" placeholder="Medication, e.g. Salbutamol 100 mcg"><button class="btn btn-sm btn-outline self-start sm:self-auto" type="button" data-clinical-pair-remove>Remove</button>';
                rows.append(row);
                updateRemoveButtons();
            };
            addButton.addEventListener('click', () => addRow());
            rows.addEventListener('click', (event) => {
                const removeButton = event.target.closest('[data-clinical-pair-remove]');
                if (!removeButton) return;
                removeButton.closest('[data-clinical-pair-row]')?.remove();
                if (!rows.children.length) addRow();
                updateRemoveButtons();
            });
            updateRemoveButtons();
        });

        const recordHeader = document.querySelector('[data-ape-record-header]');
        const floatingRecordHeader = document.querySelector('[data-ape-record-header-float]');
        if (recordHeader && floatingRecordHeader) {
            const scrollContainer = document.querySelector('.app-content');
            let pendingFrame = 0;
            const updateRecordHeader = () => {
                pendingFrame = 0;
                const headerBounds = recordHeader.getBoundingClientRect();
                const contentBounds = scrollContainer ? scrollContainer.getBoundingClientRect() : { top: 0 };
                const floatingTop = Math.max(0, contentBounds.top);
                floatingRecordHeader.style.top = `${floatingTop}px`;
                floatingRecordHeader.style.left = `${Math.max(8, headerBounds.left)}px`;
                floatingRecordHeader.style.width = `${Math.max(0, headerBounds.width)}px`;
                floatingRecordHeader.classList.toggle('is-visible', headerBounds.bottom < floatingTop);
            };
            const requestRecordHeaderUpdate = () => {
                if (pendingFrame) return;
                pendingFrame = window.requestAnimationFrame(updateRecordHeader);
            };
            (scrollContainer || window).addEventListener('scroll', requestRecordHeaderUpdate, { passive: true });
            window.addEventListener('resize', requestRecordHeaderUpdate, { passive: true });
            updateRecordHeader();
        }
    })();
</script>

<?php render_footer(); ?>
