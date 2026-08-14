<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_login();
ensure_ape_workflow_schema();

$id = (int)($_GET['id'] ?? 0);

function fetch_ape_record(int $id): ?array
{
    return ape_fetch_record($id);
}

$record = fetch_ape_record($id);
$apeUser = current_user() ?? [];
$canRecordApeExam = in_array((string) ($apeUser['role'] ?? ''), ['admin', 'doctor', 'nurse'], true);

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
    $clinicalActions = ['record_examination', 'finalize_exam_clear', 'finalize_exam_follow_up'];

    try {
        if ($staffPersonId <= 0) {
            throw new RuntimeException('The logged-in staff account is not linked to Cliniq_db.');
        }
        if (in_array($action, $clinicalActions, true) && !$canRecordApeExam) {
            throw new RuntimeException('Only administrators, doctors, and nurses can record or finalize an APE examination.');
        }
        $apeDb->beginTransaction();

        if ($action === 'mark_requirements_complete') {
            if (($record['patient_vitals_status'] ?? 'Not Started') !== 'Confirmed') {
                throw new RuntimeException('The patient must confirm their vitals and BMI before hard-copy review can be completed.');
            }
            $notes = trim((string) ($_POST['clinical_remarks'] ?? ''));
            $stmt = $apeDb->prepare("UPDATE ape_records SET requirement_status = 'Pre-Verified', workflow_status = 'Requirements Checked', follow_up_required = 0, clearance_status = 'Pending', clinical_remarks = ?, patient_visible_note = NULL, reviewed_by_person_id = ? WHERE ape_id = ?");
            $stmt->execute([$notes ?: null, $staffPersonId, $id]);
            $checked = $apeDb->prepare("UPDATE ape_requirements SET status = 'Verified', checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ?");
            $checked->execute([$staffPersonId, $id]);
            $activityLabel = 'Completed hard-copy document review';
            $activityNotes = $notes ?: 'Hard-copy documents complete; ready for digital submission';
        } elseif ($action === 'mark_missing_requirements') {
            if (($record['patient_vitals_status'] ?? 'Not Started') !== 'Confirmed') {
                throw new RuntimeException('The patient must confirm their vitals and BMI before hard-copy review can be recorded.');
            }
            $documentIssues = trim((string) ($_POST['missing_items'] ?? ''));
            if ($documentIssues === '') {
                throw new InvalidArgumentException('Describe the missing, incorrect, or incomplete hard-copy documents.');
            }
            $notes = trim((string) ($_POST['clinical_remarks'] ?? ''));
            $combinedNotes = $notes !== '' ? $documentIssues . "\n\n" . $notes : $documentIssues;
            $stmt = $apeDb->prepare("UPDATE ape_records SET requirement_status = 'Needs Correction', workflow_status = 'Registered', follow_up_required = 0, clearance_status = 'Pending', clinical_remarks = ?, patient_visible_note = ?, reviewed_by_person_id = ? WHERE ape_id = ?");
            $stmt->execute([$combinedNotes, $documentIssues, $staffPersonId, $id]);
            $activityLabel = 'Recorded hard-copy document correction';
            $activityNotes = $documentIssues;
        } elseif ($action === 'approve_documents') {
            if (empty($record['exam_date'])) {
                throw new RuntimeException('Record the clinical examination before archiving digital documents.');
            }
            $requiredTypes = ape_default_requirements();
            $requiredPlaceholders = implode(',', array_fill(0, count($requiredTypes), '?'));
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
                if (!isset($currentByType[$requiredType])) {
                    throw new RuntimeException('All five required APE documents must be uploaded before archiving the submission.');
                }
                if ($currentByType[$requiredType]['verification_status'] === 'Needs Correction') {
                    throw new RuntimeException("{$requiredType} still needs a corrected upload before the submission can be archived.");
                }
            }
            $approvableIds = array_map(
                static fn(array $document): int => (int) $document['document_id'],
                array_values(array_filter($currentRows, static fn(array $document): bool => $document['verification_status'] === 'Pending'))
            );
            if (!$currentRows) {
                throw new RuntimeException('All five required APE documents must be uploaded before archiving the submission.');
            }
            $nextWorkflow = (int) ($record['follow_up_required'] ?? 0) === 1 ? 'Follow-up Required' : 'Reviewed';
            $nextClearance = (int) ($record['follow_up_required'] ?? 0) === 1 ? 'For Follow-up' : 'Pending';
            if ($approvableIds) {
                $approvePlaceholders = implode(',', array_fill(0, count($approvableIds), '?'));
                $documents = $apeDb->prepare("UPDATE ape_documents SET verification_status = 'Verified', verified_by_person_id = ?, verified_at = NOW() WHERE ape_id = ? AND document_id IN ({$approvePlaceholders})");
                $documents->execute(array_merge([$staffPersonId, $id], $approvableIds));
            }
            $requirements = $apeDb->prepare("UPDATE ape_requirements SET status = 'Verified', remarks = NULL, checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ? AND requirement_name IN ({$requiredPlaceholders})");
            $requirements->execute(array_merge([$staffPersonId, $id], $requiredTypes));
            $stmt = $apeDb->prepare('UPDATE ape_records SET workflow_status = ?, clearance_status = ?, reviewed_by_person_id = ? WHERE ape_id = ?');
            $stmt->execute([$nextWorkflow, $nextClearance, $staffPersonId, $id]);
            $activityLabel = 'Archived online APE documents';
        } elseif ($action === 'request_document_correction') {
            if (empty($record['exam_date'])) {
                throw new RuntimeException('Record the clinical examination before reviewing digital documents.');
            }
            $documentIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['document_ids'] ?? [])))));
            if (!$documentIds) {
                throw new InvalidArgumentException('Select at least one uploaded document that needs correction.');
            }
            $missingItems = trim((string) ($_POST['missing_items'] ?? '')) ?: 'Please upload a corrected copy.';
            $documentPlaceholders = implode(',', array_fill(0, count($documentIds), '?'));
            $selectedDocuments = $apeDb->prepare("
                SELECT d.document_id, d.document_type
                FROM ape_documents d
                WHERE d.ape_id = ?
                  AND d.document_id IN ({$documentPlaceholders})
                  AND d.document_type <> 'Clearance'
                  AND d.verification_status = 'Pending'
                  AND d.document_id = (
                      SELECT MAX(latest.document_id)
                      FROM ape_documents latest
                      WHERE latest.ape_id = d.ape_id AND latest.document_type = d.document_type
                  )
                FOR UPDATE
            ");
            $selectedDocuments->execute(array_merge([$id], $documentIds));
            $selectedRows = $selectedDocuments->fetchAll();
            if (count($selectedRows) !== count($documentIds)) {
                throw new RuntimeException('One or more selected documents cannot be returned for correction. Refresh the page and try again.');
            }

            $documents = $apeDb->prepare("UPDATE ape_documents SET verification_status = 'Needs Correction', verified_by_person_id = ?, verified_at = NOW() WHERE ape_id = ? AND document_id IN ({$documentPlaceholders})");
            $documents->execute(array_merge([$staffPersonId, $id], $documentIds));
            $requirement = $apeDb->prepare("UPDATE ape_requirements SET status = 'Needs Correction', remarks = ?, checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ? AND requirement_name = ?");
            $selectedTypes = [];
            foreach ($selectedRows as $selectedDocument) {
                $selectedTypes[] = $selectedDocument['document_type'];
                $requirement->execute([$missingItems, $staffPersonId, $id, $selectedDocument['document_type']]);
            }
            $apeDb->prepare("UPDATE ape_records SET requirement_status = 'Pre-Verified', workflow_status = 'Submitted', reviewed_by_person_id = ? WHERE ape_id = ?")
                ->execute([$staffPersonId, $id]);
            $activityLabel = 'Requested document correction';
            $activityNotes = implode(', ', $selectedTypes) . ': ' . $missingItems;
        } elseif ($action === 'record_examination') {
            if (($record['patient_vitals_status'] ?? 'Not Started') !== 'Confirmed') {
                throw new RuntimeException('The patient must confirm their vitals and BMI before the clinical examination can be recorded.');
            }
            if (!in_array(($record['workflow_status'] ?? ''), ['Registered', 'Batch Assigned', 'Requirements Checked', 'Scheduled', 'Submitted', 'Reviewed'], true) || !empty($record['exam_date'])) {
                throw new RuntimeException('This APE record is not ready for examination.');
            }
            $examDate = trim((string) ($_POST['exam_date'] ?? ''));
            $examDateValue = DateTimeImmutable::createFromFormat('Y-m-d', $examDate);
            if (!$examDateValue || $examDateValue->format('Y-m-d') !== $examDate || $examDate > date('Y-m-d')) {
                throw new InvalidArgumentException('Select a valid examination date that is not in the future.');
            }
            $resultStatus = (string) ($_POST['result_status'] ?? 'Normal');
            if (!in_array($resultStatus, ['Normal', 'With Finding', 'Referred'], true)) {
                throw new InvalidArgumentException('Select a valid examination result.');
            }
            $findingType = trim((string) ($_POST['finding_type'] ?? '')) ?: 'General Examination';
            $findingDescription = trim((string) ($_POST['finding_description'] ?? ''));
            if ($findingDescription === '') {
                throw new InvalidArgumentException('Enter the clinical examination finding or result description.');
            }
            $clinicalRemarks = trim((string) ($_POST['clinical_remarks'] ?? '')) ?: null;
            $patientNote = trim((string) ($_POST['patient_visible_note'] ?? '')) ?: null;
            $referredTo = trim((string) ($_POST['referred_to'] ?? ''));
            $referralReason = trim((string) ($_POST['referral_reason'] ?? ''));
            if ($resultStatus === 'Referred' && ($referredTo === '' || $referralReason === '')) {
                throw new InvalidArgumentException('Enter the referral destination and reason when the examination result is Referred.');
            }
            $isReferral = $resultStatus === 'Referred';
            $updateExam = $apeDb->prepare("UPDATE ape_records SET exam_date = ?, workflow_status = ?, clearance_status = ?, follow_up_required = ?, clinical_remarks = ?, patient_visible_note = ?, reviewed_by_person_id = ? WHERE ape_id = ?");
            $updateExam->execute([$examDate, $isReferral ? 'Follow-up Required' : 'Exam Done', $isReferral ? 'For Follow-up' : 'Pending', $isReferral ? 1 : 0, $clinicalRemarks, $patientNote ?: ($isReferral ? $referralReason : null), $staffPersonId, $id]);
            $finding = $apeDb->prepare('INSERT INTO ape_findings (ape_id, finding_type, description, result_status, follow_up_required, recorded_by_person_id) VALUES (?, ?, ?, ?, ?, ?)');
            $finding->execute([$id, $isReferral ? 'Referral' : $findingType, $isReferral ? $referralReason : $findingDescription, $resultStatus, $isReferral ? 1 : 0, $staffPersonId]);
            if ($isReferral) {
                $referral = $apeDb->prepare('INSERT INTO referrals (patient_person_id, referral_date, referred_to, reason, referred_by_person_id, remarks) VALUES (?, ?, ?, ?, ?, ?)');
                $referral->execute([(int) $record['patient_id'], $examDate, $referredTo, $referralReason, $staffPersonId, 'Created during APE examination #' . $id]);
                $activityLabel = 'Recorded APE examination and created referral';
                $activityNotes = $referredTo . ': ' . $referralReason;
            } else {
                $activityLabel = 'Recorded APE examination';
                $activityNotes = $findingType . ': ' . $resultStatus;
            }
        } elseif ($action === 'finalize_exam_clear') {
            if (ape_record_queue($record) !== 'final_decision' || empty($record['exam_date'])) {
                throw new RuntimeException('The examination and document archive must be complete before clearing the patient.');
            }
            $patientNote = trim((string) ($_POST['patient_visible_note'] ?? '')) ?: ($record['patient_visible_note'] ?? null);
            $stmt = $apeDb->prepare("UPDATE ape_records SET workflow_status = 'Cleared', clearance_status = 'Cleared', follow_up_required = 0, patient_visible_note = ?, reviewed_by_person_id = ? WHERE ape_id = ? AND workflow_status IN ('Exam Done', 'Reviewed')");
            $stmt->execute([$patientNote, $staffPersonId, $id]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('The examination status changed before clearance could be saved. Refresh and try again.');
            }
            $activityLabel = 'Cleared patient after APE examination';
            $activityNotes = $patientNote ?: 'No additional patient instruction.';
        } elseif ($action === 'finalize_exam_follow_up') {
            if (ape_record_queue($record) !== 'final_decision') {
                throw new RuntimeException('The examination and document archive must be complete before requiring follow-up.');
            }
            $followUpNotes = trim((string) ($_POST['follow_up_notes'] ?? ''));
            if ($followUpNotes === '') {
                throw new InvalidArgumentException('Enter the follow-up required from the patient.');
            }
            $patientNote = trim((string) ($_POST['patient_visible_note'] ?? '')) ?: $followUpNotes;
            $apeDb->prepare("UPDATE ape_records SET workflow_status = 'Follow-up Required', clearance_status = 'For Follow-up', follow_up_required = 1, clinical_remarks = ?, patient_visible_note = ?, reviewed_by_person_id = ? WHERE ape_id = ?")
                ->execute([$followUpNotes, $patientNote, $staffPersonId, $id]);
            $apeDb->prepare("INSERT INTO ape_findings (ape_id, finding_type, description, result_status, follow_up_required, recorded_by_person_id) VALUES (?, 'Follow-up Decision', ?, 'With Finding', 1, ?)")
                ->execute([$id, $followUpNotes, $staffPersonId]);
            $activityLabel = 'Required follow-up after APE examination';
            $activityNotes = $followUpNotes;
        } elseif ($action === 'keep_follow_up_open') {
            $followUpNotes = trim((string) ($_POST['follow_up_notes'] ?? '')) ?: 'Follow-up remains open.';
            $stmt = $apeDb->prepare("UPDATE ape_records SET clearance_status = 'For Follow-up', workflow_status = 'Follow-up Required', clinical_remarks = ? WHERE ape_id = ?");
            $stmt->execute([$followUpNotes, $id]);
            $activityLabel = 'Kept follow-up open';
            $activityNotes = $followUpNotes;
        } elseif ($action === 'approve_clearance') {
            $stmt = $apeDb->prepare("UPDATE ape_records SET clearance_status = 'Cleared', follow_up_required = 0, workflow_status = 'Cleared', reviewed_by_person_id = ? WHERE ape_id = ?");
            $stmt->execute([$staffPersonId, $id]);
            $document = $apeDb->prepare("UPDATE ape_documents SET verification_status = 'Verified', verified_by_person_id = ?, verified_at = NOW() WHERE ape_id = ? AND document_type = 'Clearance'");
            $document->execute([$staffPersonId, $id]);
            $requirement = $apeDb->prepare("UPDATE ape_requirements SET status = 'Verified', checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ? AND requirement_name = 'Follow-up clearance'");
            $requirement->execute([$staffPersonId, $id]);
            $activityLabel = 'Approved follow-up clearance';
        } elseif ($action === 'return_clearance') {
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
            $requirementName = trim((string) ($_POST['requirement_name'] ?? ''));
            $requirementStatus = (string) ($_POST['requirement_item_status'] ?? 'Missing');
            if ($requirementName === '' || !in_array($requirementStatus, ['Missing', 'Submitted', 'Verified', 'Needs Correction'], true)) {
                throw new InvalidArgumentException('Enter a requirement name and valid status.');
            }
            $requirement = $apeDb->prepare("INSERT INTO ape_requirements (ape_id, requirement_name, status, remarks, checked_by_person_id, checked_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), remarks = VALUES(remarks), checked_by_person_id = VALUES(checked_by_person_id), checked_at = VALUES(checked_at)");
            $isChecked = $requirementStatus === 'Verified';
            $requirement->execute([$id, $requirementName, $requirementStatus, trim((string) ($_POST['requirement_remarks'] ?? '')) ?: null, $isChecked ? $staffPersonId : null, $isChecked ? date('Y-m-d H:i:s') : null]);
            $activityLabel = 'Updated APE requirement';
            $activityNotes = $requirementName . ': ' . $requirementStatus;
        }

        if ($activityLabel) {
            ape_log_activity($id, $staffPersonId, $activityLabel, $activityNotes);
            flash_message('success', $activityLabel . '.');
        }
        $apeDb->commit();
    } catch (Throwable $e) {
        if ($apeDb->inTransaction()) {
            $apeDb->rollBack();
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
$currentStep = ape_record_step_index($record);
$actionCard = ape_next_action_card($record);

$requirements = ape_requirements_for_record($id);
$pendingRequirements = array_values(array_filter(
    $requirements,
    static fn(array $requirement): bool => ($requirement['status'] ?? '') !== 'Verified'
));
$documents = ape_documents_for_record($id);
$reviewDocuments = array_values(array_filter(
    $documents,
    static fn(array $document): bool => ($document['document_type'] ?? '') !== 'Clearance'
));
$latestReviewDocumentIdsByType = [];
foreach ($reviewDocuments as $reviewDocument) {
    $latestReviewDocumentIdsByType[$reviewDocument['document_type']] ??= (int) $reviewDocument['document_id'];
}
$pendingReviewDocuments = array_values(array_filter(
    $reviewDocuments,
    static fn(array $document): bool => ($document['verification_status'] ?? '') === 'Pending'
        && ($latestReviewDocumentIdsByType[$document['document_type']] ?? 0) === (int) $document['document_id']
));
$findings = ape_findings_for_record($id);
$activities = ape_activities_for_patient_record($id, (int) $record['patient_id'], 20);
$birthdateLabel = $record['birthdate'] ? date('M d, Y', strtotime($record['birthdate'])) : 'Not recorded';
$sexLabel = $record['sex'] ?: 'Not specified';
$clearanceUrl = !empty($record['clearance_document_path']) ? app_url($record['clearance_document_path']) : null;

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
        font-weight: 900;
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
</style>

<div class="ape-flow-shell space-y-6">
    <div class="clinic-card p-5 md:p-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-4 min-w-0">
                <div class="avatar <?= e(avatar_color($fullName)) ?> w-14 h-14 text-lg"><?= e(initials($fullName)) ?></div>
                <div class="min-w-0">
                    <p class="text-[10px] font-black text-primary uppercase tracking-widest mb-1">APE Review Station</p>
                    <h1 class="font-headline text-2xl md:text-3xl font-extrabold text-[#17261d] truncate"><?= e($fullName) ?></h1>
                    <p class="text-sm font-bold text-slate-500 mt-1"><?= e($record['id_number']) ?><?= $record['course_section'] ? ' - ' . e($record['course_section']) : '' ?></p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <span class="badge <?= ape_status_badge_class($record['workflow_status']) ?>"><?= e($record['workflow_status']) ?></span>
                <span class="badge <?= ape_priority_badge($record)['class'] ?>"><?= e(ape_waiting_label($record)) ?></span>
                <a class="btn btn-ghost text-decoration-none" href="<?= app_url('patients/view.php?id=' . (int)$record['patient_id']) ?>">
                    <span class="material-symbols-outlined text-[18px]">folder_shared</span> Patient
                </a>
            </div>
        </div>
    </div>

    <section class="clinic-card p-5 md:p-6 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <?php foreach (ape_workflow_steps() as $i => $step):
                $done = $i < $currentStep || ($queueKey === 'completed' && $i <= $currentStep);
                $active = $i === $currentStep && $queueKey !== 'completed';
            ?>
                <div class="ape-flow-step rounded-xl border <?= $active ? 'border-primary bg-primary-fixed' : ($done ? 'border-emerald-100 bg-emerald-50/60' : 'border-outline-variant bg-slate-50/60') ?> p-3">
                    <span class="ape-flow-step-index <?= $done ? 'bg-emerald-600 text-white' : ($active ? 'bg-primary text-white' : 'bg-white text-slate-400') ?>">
                        <?= $done ? '<span class="material-symbols-outlined text-[15px]">check</span>' : $i + 1 ?>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-widest <?= $active ? 'text-primary' : 'text-slate-400' ?> mb-1"><?= e($step) ?></p>
                        <p class="text-xs font-bold text-slate-500 mb-0"><?= $active ? 'Current step' : ($done ? 'Completed' : 'Upcoming') ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <section class="ape-flow-panel space-y-4">
                <h2 class="font-headline text-lg font-extrabold text-[#17261d] flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-primary text-[20px]">person</span>
                    Patient Information
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="ape-flow-field">
                        <p class="clinic-label mb-1">ID Number</p>
                        <strong class="text-sm text-slate-800"><?= e($record['id_number']) ?></strong>
                    </div>
                    <div class="ape-flow-field">
                        <p class="clinic-label mb-1">Program / Section</p>
                        <strong class="text-sm text-slate-800"><?= e($record['course_section'] ?: 'Not set') ?></strong>
                    </div>
                    <div class="ape-flow-field">
                        <p class="clinic-label mb-1">Sex</p>
                        <strong class="text-sm text-slate-800"><?= e($sexLabel) ?></strong>
                    </div>
                    <div class="ape-flow-field">
                        <p class="clinic-label mb-1">Birthdate</p>
                        <strong class="text-sm text-slate-800"><?= e($birthdateLabel) ?></strong>
                    </div>
                </div>
            </section>

            <section class="ape-flow-panel space-y-4">
                <h2 class="font-headline text-lg font-extrabold text-[#17261d] flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-primary text-[20px]">assignment</span>
                    APE Information
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="ape-flow-field">
                        <p class="clinic-label mb-1">Uploaded Documents</p>
                        <strong class="text-sm text-slate-800"><?= count($reviewDocuments) ?> submitted file<?= count($reviewDocuments) === 1 ? '' : 's' ?></strong>
                    </div>
                    <div class="ape-flow-field">
                        <p class="clinic-label mb-1">Hard Copy Status</p>
                        <span class="badge <?= ape_status_badge_class($record['requirement_status']) ?>"><?= e($record['requirement_status']) ?></span>
                    </div>
                    <div class="ape-flow-field">
                        <p class="clinic-label mb-1">Online Archive</p>
                        <span class="badge <?= ape_status_badge_class($record['verification_status']) ?>"><?= e($record['verification_status']) ?></span>
                    </div>
                    <div class="ape-flow-field">
                        <p class="clinic-label mb-1">Clearance</p>
                        <span class="badge <?= ape_status_badge_class($record['clearance_status']) ?>"><?= e($record['clearance_status']) ?></span>
                    </div>
                </div>
            </section>
        </div>

        <section class="ape-flow-panel">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Patient-Reported Vitals and BMI</h2>
                    <p class="text-xs font-bold text-slate-500 mb-0">Entered and confirmed by the patient before presenting hard-copy requirements.</p>
                </div>
                <span class="badge <?= ($record['patient_vitals_status'] ?? 'Not Started') === 'Confirmed' ? 'badge-completed' : 'badge-pending' ?>">
                    <?= e($record['patient_vitals_status'] ?? 'Not Started') ?>
                </span>
            </div>
            <?php if (($record['patient_vitals_status'] ?? 'Not Started') === 'Confirmed'): ?>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="ape-flow-field"><p class="clinic-label mb-1">Height</p><strong class="text-sm text-slate-800"><?= e($record['patient_height_cm'] !== null ? number_format((float) $record['patient_height_cm'], 2) . ' cm' : 'Not recorded') ?></strong></div>
                    <div class="ape-flow-field"><p class="clinic-label mb-1">Weight</p><strong class="text-sm text-slate-800"><?= e($record['patient_weight_kg'] !== null ? number_format((float) $record['patient_weight_kg'], 2) . ' kg' : 'Not recorded') ?></strong></div>
                    <div class="ape-flow-field"><p class="clinic-label mb-1">BMI</p><strong class="text-sm text-slate-800"><?= e($record['patient_bmi'] !== null ? number_format((float) $record['patient_bmi'], 2) : 'Not recorded') ?></strong></div>
                    <div class="ape-flow-field"><p class="clinic-label mb-1">Temperature</p><strong class="text-sm text-slate-800"><?= e($record['patient_temperature'] !== null ? number_format((float) $record['patient_temperature'], 1) . ' °C' : 'Not recorded') ?></strong></div>
                    <div class="ape-flow-field"><p class="clinic-label mb-1">Blood Pressure</p><strong class="text-sm text-slate-800"><?= e($record['patient_blood_pressure'] ?: 'Not recorded') ?></strong></div>
                    <div class="ape-flow-field"><p class="clinic-label mb-1">Pulse Rate</p><strong class="text-sm text-slate-800"><?= e($record['patient_pulse_rate'] !== null ? (int) $record['patient_pulse_rate'] . ' bpm' : 'Not recorded') ?></strong></div>
                    <div class="ape-flow-field col-span-2"><p class="clinic-label mb-1">Confirmed</p><strong class="text-sm text-slate-800"><?= e($record['patient_vitals_confirmed_at'] ? date('M d, Y g:i A', strtotime($record['patient_vitals_confirmed_at'])) : 'Not recorded') ?></strong></div>
                </div>
            <?php else: ?>
                <div class="ape-flow-action muted"><p class="text-sm font-bold text-amber-800 mb-0">The patient has not confirmed their height, weight, BMI, temperature, blood pressure, and pulse rate yet.</p></div>
            <?php endif; ?>
        </section>

        <section class="ape-flow-panel">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-3">
                <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-black text-primary uppercase tracking-widest mb-2"><?= e($queue['title']) ?></p>
                    <h2 class="font-headline text-xl md:text-2xl font-extrabold text-[#17261d] mb-1"><?= e($next['label']) ?></h2>
                    <p class="text-sm font-bold text-slate-500 mb-0 max-w-3xl"><?= e($actionCard['body']) ?></p>
                </div>
                <span class="badge <?= ape_priority_badge($record)['class'] ?> shrink-0">
                    <?php if ($pendingRequirements): ?>
                        <?= count($pendingRequirements) ?> Requirement<?= count($pendingRequirements) === 1 ? '' : 's' ?> Missing
                    <?php else: ?>
                        <?= e(ape_missing_item($record)) ?>
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($pendingRequirements): ?>
                <div class="flex flex-wrap gap-2 mb-5" aria-label="Requirements needing attention">
                    <?php foreach ($pendingRequirements as $requirement): ?>
                        <span class="ape-requirement-chip">
                            <?= e($requirement['requirement_name']) ?> (<?= e($requirement['status']) ?>)
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="mb-5"></div>
            <?php endif; ?>

            <?php if ($queueKey === 'document_review'): ?>
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                    <form method="post" class="ape-flow-action space-y-3">
                        <input type="hidden" name="action" value="mark_requirements_complete">
                        <div>
                            <h3 class="font-headline text-base font-extrabold text-[#17261d] mb-1">Hard Copy Complete</h3>
                            <p class="text-xs font-bold text-slate-500 mb-3">Use this after the clinical examination, when all hard-copy APE documents have been presented and checked.</p>
                            <label class="clinic-label">Document Review Notes</label>
                            <textarea class="clinic-textarea" name="clinical_remarks" rows="4" placeholder="Optional notes after checking hard-copy documents..."><?= e($record['clinical_remarks']) ?></textarea>
                        </div>
                        <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Complete the hard-copy review?" data-confirm-message="This will mark the hard-copy review complete and open digital document submission for the patient." data-confirm-toast="Updating APE record...">
                            <span class="material-symbols-outlined text-[18px]">check_circle</span> Complete Hard-copy Review
                        </button>
                    </form>
                    <form method="post" class="ape-flow-action muted space-y-3">
                        <input type="hidden" name="action" value="mark_missing_requirements">
                        <div>
                            <h3 class="font-headline text-base font-extrabold text-[#17261d] mb-1">Hard Copy Incomplete</h3>
                            <p class="text-xs font-bold text-amber-800 mb-3">Use this only for missing, incorrect, or incomplete hard-copy documents. Clinical findings are recorded during examination.</p>
                            <label class="clinic-label">Document Issues</label>
                            <textarea class="clinic-textarea" name="missing_items" rows="3" placeholder="Missing form, incomplete field, unreadable copy, or other document issue..." required><?= e($record['patient_visible_note']) ?></textarea>
                        </div>
                        <div>
                            <label class="clinic-label">Additional Document Review Notes</label>
                            <textarea class="clinic-textarea" name="clinical_remarks" rows="3" placeholder="Optional internal notes about the hard-copy review..."></textarea>
                        </div>
                        <button class="btn btn-outline w-full" style="color:#b45309;border-color:rgba(180,83,9,0.2);" data-confirm-submit data-confirm-type="danger" data-confirm-title="Return the hard-copy documents?" data-confirm-message="This will keep the record in document review until the document issues are corrected." data-confirm-toast="Saving document issues...">
                            <span class="material-symbols-outlined text-[18px]">assignment_return</span> Record Document Correction
                        </button>
                    </form>
                </div>
            <?php elseif ($queueKey === 'digital_submission'): ?>
                <?php if ((int) ($record['required_document_count'] ?? 0) < count(ape_default_requirements())): ?>
                    <div class="ape-flow-action muted">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-amber-700 mt-0.5">hourglass_empty</span>
                            <div>
                                <h3 class="font-headline text-base font-extrabold text-amber-900 mb-1">Waiting for Complete Patient Upload</h3>
                                <p class="text-sm font-bold text-amber-800 mb-4">The examination is complete. The patient must now upload the checked APE documents from the patient portal before clinic archive review can continue.</p>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach (['Lab Request Form', 'UHS Consent Form', 'UHS Medical Record', 'UHS Dental Record', 'Referral Form'] as $type): ?>
                                        <span class="badge badge-pending"><?= e($type) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 xl:grid-cols-[1fr_0.9fr] gap-4">
                        <div class="ape-flow-action">
                            <div class="flex items-center justify-between gap-3 mb-3">
                                <div>
                                    <p class="clinic-label mb-1">Submitted Documents</p>
                                    <p class="text-xs font-bold text-slate-500 mb-0">Select only the files that must be corrected.</p>
                                </div>
                                <span class="badge badge-in-progress"><?= count($reviewDocuments) ?> File<?= count($reviewDocuments) === 1 ? '' : 's' ?></span>
                            </div>
                            <div class="ape-document-review-list">
                                <?php foreach ($reviewDocuments as $document): ?>
                                    <?php $canRequestCorrection = ($document['verification_status'] ?? '') === 'Pending'
                                        && ($latestReviewDocumentIdsByType[$document['document_type']] ?? 0) === (int) $document['document_id']; ?>
                                    <div class="ape-document-review-card">
                                        <?php if ($canRequestCorrection): ?>
                                            <input type="checkbox" name="document_ids[]" value="<?= (int) $document['document_id'] ?>" form="apeCorrectionForm" aria-label="Select <?= e($document['document_type']) ?> for correction">
                                        <?php else: ?>
                                            <span class="material-symbols-outlined text-slate-400" aria-hidden="true">description</span>
                                        <?php endif; ?>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                                <strong class="text-sm text-slate-800"><?= e($document['document_type']) ?></strong>
                                                <span class="badge <?= ape_status_badge_class($document['verification_status']) ?>"><?= e($document['verification_status']) ?></span>
                                            </div>
                                            <p class="text-xs font-bold text-slate-500 truncate mb-1" title="<?= e($document['original_filename'] ?: basename($document['file_path'])) ?>"><?= e($document['original_filename'] ?: basename($document['file_path'])) ?></p>
                                            <p class="text-[10px] font-black uppercase tracking-wider text-slate-400 mb-0"><?= e(date('M d, Y g:i A', strtotime($document['uploaded_at']))) ?></p>
                                        </div>
                                        <a href="<?= e(app_url($document['file_path'])) ?>" class="ape-document-view-button btn btn-sm btn-outline text-decoration-none" data-file-preview data-preview-title="<?= e($document['original_filename'] ?: $document['document_type']) ?>">
                                            <span class="material-symbols-outlined text-[14px]">preview</span> View File
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-3">
                            <form method="post" class="ape-flow-action space-y-3">
                                <input type="hidden" name="action" value="approve_documents">
                                <h3 class="font-headline text-base font-extrabold text-[#17261d] mb-1">Archive Submission</h3>
                                <p class="text-xs font-bold text-slate-500 mb-0">Approve when the upload matches the checked hard-copy documents.</p>
                                <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Archive these documents?" data-confirm-message="This will approve the uploaded documents for clinic archive." data-confirm-toast="Archiving documents..."><span class="material-symbols-outlined text-[18px]">inventory_2</span> Archive Documents</button>
                            </form>
                            <form method="post" class="ape-flow-action muted space-y-3" id="apeCorrectionForm">
                                <input type="hidden" name="action" value="request_document_correction">
                                <label class="clinic-label">Correction Needed</label>
                                <p class="text-xs font-bold text-amber-800 mb-0">Check the affected files in the submitted-document list, then explain what must be corrected.</p>
                                <textarea class="clinic-textarea" name="missing_items" rows="3" placeholder="What should the patient correct or resubmit online?"></textarea>
                                <button class="btn btn-outline w-full" style="color:#b45309;border-color:rgba(180,83,9,0.2);" <?= !$pendingReviewDocuments ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="danger" data-confirm-title="Request selected document resubmission?" data-confirm-message="Only the selected files will be returned to the patient for correction." data-confirm-toast="Requesting resubmission..."><span class="material-symbols-outlined text-[18px]">edit_note</span> Request Selected Resubmission</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php elseif ($queueKey === 'examination'): ?>
                <?php if (($record['patient_vitals_status'] ?? 'Not Started') !== 'Confirmed'): ?>
                    <div class="ape-flow-action muted">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-amber-700 mt-0.5">monitor_heart</span>
                            <div>
                                <h3 class="font-headline text-base font-extrabold text-amber-900 mb-1">Waiting for patient vitals and BMI</h3>
                                <p class="text-sm font-bold text-amber-800 mb-0">The patient must enter and confirm their height, weight, BMI, and vital signs in the patient portal before the clinic can record the examination.</p>
                            </div>
                        </div>
                    </div>
                <?php elseif (!$canRecordApeExam): ?>
                    <div class="ape-flow-action muted">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-amber-700 mt-0.5">lock</span>
                            <div>
                                <h3 class="font-headline text-base font-extrabold text-amber-900 mb-1">Clinical permission required</h3>
                                <p class="text-sm font-bold text-amber-800 mb-0">Only administrators, doctors, and nurses can record or finalize an APE examination.</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <form method="post" class="ape-flow-action space-y-5">
                        <input type="hidden" name="action" value="record_examination">
                        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                            <div>
                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Clinical Examination</h3>
                                <p class="text-xs font-bold text-slate-500 mb-0">Record the examination first. The patient will present hard-copy requirements before digital upload opens.</p>
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
                                    <option value="Normal">Normal</option>
                                    <option value="With Finding">With Finding</option>
                                    <option value="Referred">Referred</option>
                                </select>
                            </div>
                            <div>
                                <label class="clinic-label" for="apeFindingType">Finding Type</label>
                                <input class="clinic-input" id="apeFindingType" name="finding_type" value="General Examination" placeholder="General, vision, dental, laboratory..." required>
                            </div>
                            <div class="md:col-span-2">
                                <label class="clinic-label" for="apeFindingDescription">Examination Finding / Result</label>
                                <textarea class="clinic-textarea" id="apeFindingDescription" name="finding_description" rows="3" placeholder="Describe the examination result and any finding..." required></textarea>
                            </div>
                            <div>
                                <label class="clinic-label" for="apeClinicalRemarks">Internal Clinical Notes</label>
                                <textarea class="clinic-textarea" id="apeClinicalRemarks" name="clinical_remarks" rows="3" placeholder="Visible only to authorized clinic staff..."><?= e($record['clinical_remarks']) ?></textarea>
                            </div>
                            <div>
                                <label class="clinic-label" for="apePatientNote">Patient-Visible Instructions</label>
                                <textarea class="clinic-textarea" id="apePatientNote" name="patient_visible_note" rows="3" placeholder="Instructions that the patient can see..."><?= e($record['patient_visible_note']) ?></textarea>
                            </div>
                            <div class="md:col-span-2 rounded-xl border border-amber-200 bg-amber-50 p-4">
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
                        <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save this APE examination?" data-confirm-message="After saving, the hard-copy review step will open for clinic staff." data-confirm-toast="Saving APE examination...">
                            <span class="material-symbols-outlined text-[18px]">clinical_notes</span> Save Examination
                        </button>
                    </form>
                <?php endif; ?>
            <?php elseif ($queueKey === 'final_decision'): ?>
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
                                <p class="text-xs font-bold text-slate-500 mb-0">Review the findings below and choose the final clinical decision.</p>
                            </div>
                            <span class="badge badge-pending">Final Decision Required</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                        <form method="post" class="ape-flow-action space-y-3">
                            <input type="hidden" name="action" value="finalize_exam_clear">
                            <div>
                                <span class="material-symbols-outlined text-emerald-600 mb-2">verified</span>
                                <h3 class="font-headline text-base font-extrabold text-[#17261d] mb-1">Clear Patient</h3>
                                <p class="text-xs font-bold text-slate-500 mb-3">Use only when the examination is complete and no follow-up is required.</p>
                                <label class="clinic-label">Patient-Visible Note</label>
                                <textarea class="clinic-textarea" name="patient_visible_note" rows="3" placeholder="Final clearance message..."><?= e($record['patient_visible_note']) ?></textarea>
                            </div>
                            <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Clear this patient?" data-confirm-message="This will complete the annual APE record." data-confirm-toast="Clearing APE record..."><span class="material-symbols-outlined text-[18px]">check_circle</span> Clear Patient</button>
                        </form>
                        <form method="post" class="ape-flow-action muted space-y-3">
                            <input type="hidden" name="action" value="finalize_exam_follow_up">
                            <div>
                                <span class="material-symbols-outlined text-amber-700 mb-2">medical_information</span>
                                <h3 class="font-headline text-base font-extrabold text-amber-900 mb-1">Require Follow-up</h3>
                                <label class="clinic-label">Required Follow-up</label>
                                <textarea class="clinic-textarea" name="follow_up_notes" rows="3" placeholder="Treatment, repeat test, clearance, or other follow-up..." required></textarea>
                                <label class="clinic-label mt-3">Patient Instructions</label>
                                <textarea class="clinic-textarea" name="patient_visible_note" rows="2" placeholder="Instructions visible to the patient..."></textarea>
                            </div>
                            <button class="btn btn-outline w-full" style="color:#b45309;border-color:rgba(180,83,9,0.2);" data-confirm-submit data-confirm-type="danger" data-confirm-title="Require patient follow-up?" data-confirm-message="The APE record will remain open until follow-up is cleared." data-confirm-toast="Opening follow-up..."><span class="material-symbols-outlined text-[18px]">schedule</span> Require Follow-up</button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php elseif ($queueKey === 'follow_up'): ?>
                <div class="grid grid-cols-1 xl:grid-cols-[1fr_0.9fr] gap-4">
                    <div class="ape-flow-action muted space-y-4">
                        <div>
                            <p class="clinic-label">Required Follow-up</p>
                            <p class="text-sm font-bold text-amber-900 whitespace-pre-wrap mb-0"><?= e($record['missing_items'] ?: 'Follow-up requirement not specified.') ?></p>
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
                            <button class="btn btn-ghost w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Keep follow-up open?" data-confirm-message="This will save the latest follow-up notes without closing the APE record." data-confirm-toast="Saving follow-up notes..."><span class="material-symbols-outlined text-[18px]">history</span> Keep Follow-up Open</button>
                        </form>
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

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <section class="ape-flow-panel">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div>
                        <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Requirements Checklist</h2>
                        <p class="text-xs font-bold text-slate-500 mb-0">Each requirement is stored separately in Cliniq_db.</p>
                    </div>
                    <span class="badge badge-pending"><?= count($requirements) ?> item(s)</span>
                </div>
                <div class="space-y-2 mb-4">
                    <?php foreach ($requirements as $requirement): ?>
                        <div class="ape-flow-field flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                            <div>
                                <strong class="text-sm text-slate-800 block"><?= e($requirement['requirement_name']) ?></strong>
                                <?php if ($requirement['remarks']): ?>
                                    <p class="text-xs font-bold text-slate-500 mt-1 mb-0"><?= e($requirement['remarks']) ?></p>
                                <?php endif; ?>
                                <?php if ($requirement['checked_at']): ?>
                                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-2 mb-0">
                                        Checked by <?= e($requirement['checked_by_name'] ?: 'Clinic staff') ?> · <?= e(date('M d, Y g:i A', strtotime($requirement['checked_at']))) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <span class="badge <?= ape_status_badge_class($requirement['status']) ?>"><?= e($requirement['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <form method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <input type="hidden" name="action" value="add_requirement">
                    <input class="clinic-input sm:col-span-2" name="requirement_name" placeholder="Requirement name" required>
                    <select class="clinic-select" name="requirement_item_status">
                        <?php foreach (['Missing', 'Submitted', 'Verified', 'Needs Correction'] as $status): ?>
                            <option value="<?= e($status) ?>"><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input class="clinic-input" name="requirement_remarks" placeholder="Optional remarks">
                    <button class="btn btn-primary sm:col-span-2" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save requirement?" data-confirm-message="This will add or update the named APE requirement." data-confirm-toast="Saving requirement...">
                        <span class="material-symbols-outlined text-[18px]">playlist_add</span> Save Requirement
                    </button>
                </form>
            </section>

            <section class="ape-flow-panel">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div>
                        <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Uploaded Documents</h2>
                        <p class="text-xs font-bold text-slate-500 mb-0">Patient and clinic uploads with verification details.</p>
                    </div>
                    <span class="badge badge-in-progress"><?= count($documents) ?> file(s)</span>
                </div>
                <div class="space-y-2">
                    <?php foreach ($documents as $document): ?>
                        <div class="ape-flow-field">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                                <div>
                                    <strong class="text-sm text-slate-800 block"><?= e($document['document_type']) ?></strong>
                                    <p class="text-xs font-bold text-slate-500 mt-1 mb-0"><?= e($document['original_filename'] ?: basename($document['file_path'])) ?></p>
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
                                    <a class="btn btn-sm btn-outline text-decoration-none" href="<?= e(app_url($document['file_path'])) ?>" data-file-preview data-preview-title="<?= e($document['original_filename'] ?: $document['document_type']) ?>">
                                        <span class="material-symbols-outlined text-[14px]">preview</span> Open
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$documents): ?>
                        <div class="ape-flow-field text-center text-sm font-bold text-slate-500">No document has been uploaded.</div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <?php if (!empty($record['exam_date'])): ?>
        <section class="ape-flow-panel">
            <div class="flex items-center justify-between gap-3 mb-4">
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
                                <span class="badge <?= ape_status_badge_class($finding['result_status']) ?>"><?= e($finding['result_status']) ?></span>
                            </div>
                            <p class="text-sm font-bold text-slate-600 whitespace-pre-wrap mb-0"><?= e($finding['description']) ?></p>
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-2 mb-0">
                                <?= $finding['follow_up_required'] ? 'Follow-up required' : 'No follow-up' ?> · <?= e($finding['recorded_by_name'] ?: 'Clinic staff') ?> · <?= e(date('M d, Y g:i A', strtotime($finding['recorded_at']))) ?>
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
            <section class="ape-flow-panel">
                <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-4">Additional Notes</h2>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="save_notes">
                    <div>
                        <label class="clinic-label">Patient-Visible Note</label>
                        <textarea class="clinic-textarea" name="patient_visible_note" rows="3" placeholder="Notes the patient can see..."><?= e($record['patient_visible_note']) ?></textarea>
                    </div>
                    <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save these notes?" data-confirm-message="This will update the notes on this APE record." data-confirm-toast="Saving notes..."><span class="material-symbols-outlined text-[18px]">save</span> Save Notes</button>
                </form>
            </section>

            <section class="ape-flow-panel overflow-hidden">
                <div class="p-5 border-b border-slate-100">
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
    </section>
</div>

<?php render_footer(); ?>
