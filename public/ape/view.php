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

    try {
        if ($staffPersonId <= 0) {
            throw new RuntimeException('The logged-in staff account is not linked to Cliniq_db.');
        }
        $apeDb->beginTransaction();

        if ($action === 'mark_requirements_complete') {
            $notes = trim((string) ($_POST['clinical_remarks'] ?? ''));
            $stmt = $apeDb->prepare("UPDATE ape_records SET requirement_status = 'Pre-Verified', workflow_status = 'Requirements Checked', follow_up_required = 0, clearance_status = 'Pending', clinical_remarks = ?, reviewed_by_person_id = ? WHERE ape_id = ?");
            $stmt->execute([$notes ?: null, $staffPersonId, $id]);
            $checked = $apeDb->prepare("UPDATE ape_requirements SET status = 'Verified', checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ?");
            $checked->execute([$staffPersonId, $id]);
            $activityLabel = 'Completed hard-copy document review';
            $activityNotes = $notes ?: 'No follow-up required';
        } elseif ($action === 'mark_missing_requirements') {
            $missingItems = trim((string) ($_POST['missing_items'] ?? '')) ?: 'Follow-up requirement not specified.';
            $notes = trim((string) ($_POST['clinical_remarks'] ?? ''));
            $stmt = $apeDb->prepare("UPDATE ape_records SET requirement_status = 'Pre-Verified', workflow_status = 'Requirements Checked', follow_up_required = 1, clearance_status = 'For Follow-up', clinical_remarks = ?, reviewed_by_person_id = ? WHERE ape_id = ?");
            $stmt->execute([$notes ?: null, $staffPersonId, $id]);
            $checked = $apeDb->prepare("UPDATE ape_requirements SET status = 'Verified', checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ?");
            $checked->execute([$staffPersonId, $id]);
            $requirement = $apeDb->prepare("INSERT INTO ape_requirements (ape_id, requirement_name, status, remarks) VALUES (?, 'Follow-up clearance', 'Missing', ?) ON DUPLICATE KEY UPDATE status = 'Missing', remarks = VALUES(remarks), checked_by_person_id = NULL, checked_at = NULL");
            $requirement->execute([$id, $missingItems]);
            $finding = $apeDb->prepare("INSERT INTO ape_findings (ape_id, finding_type, description, result_status, follow_up_required, recorded_by_person_id) VALUES (?, 'General', ?, 'With Finding', 1, ?)");
            $finding->execute([$id, $notes ?: $missingItems, $staffPersonId]);
            $activityLabel = 'Recorded finding and follow-up requirement';
            $activityNotes = $missingItems;
        } elseif ($action === 'approve_documents') {
            $nextWorkflow = (int) ($record['follow_up_required'] ?? 0) === 1 ? 'Follow-up Required' : 'Cleared';
            $nextClearance = (int) ($record['follow_up_required'] ?? 0) === 1 ? 'For Follow-up' : 'Cleared';
            $documents = $apeDb->prepare("UPDATE ape_documents SET verification_status = 'Verified', verified_by_person_id = ?, verified_at = NOW() WHERE ape_id = ? AND verification_status <> 'Verified'");
            $documents->execute([$staffPersonId, $id]);
            $stmt = $apeDb->prepare('UPDATE ape_records SET workflow_status = ?, clearance_status = ?, reviewed_by_person_id = ? WHERE ape_id = ?');
            $stmt->execute([$nextWorkflow, $nextClearance, $staffPersonId, $id]);
            $activityLabel = 'Archived online APE documents';
        } elseif ($action === 'request_document_correction') {
            $missingItems = trim((string) ($_POST['missing_items'] ?? '')) ?: 'Online document correction required.';
            $documents = $apeDb->prepare("UPDATE ape_documents SET verification_status = 'Needs Correction', verified_by_person_id = ?, verified_at = NOW() WHERE ape_id = ? AND document_type <> 'Clearance'");
            $documents->execute([$staffPersonId, $id]);
            $stmt = $apeDb->prepare("UPDATE ape_records SET requirement_status = 'Pre-Verified', workflow_status = 'Submitted', reviewed_by_person_id = ? WHERE ape_id = ?");
            $stmt->execute([$staffPersonId, $id]);
            $requirement = $apeDb->prepare("INSERT INTO ape_requirements (ape_id, requirement_name, status, remarks) VALUES (?, 'Online document correction', 'Needs Correction', ?) ON DUPLICATE KEY UPDATE status = 'Needs Correction', remarks = VALUES(remarks)");
            $requirement->execute([$id, $missingItems]);
            $activityLabel = 'Requested online document correction';
            $activityNotes = $missingItems;
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
                trim((string) ($_POST['student_visible_note'] ?? '')) ?: null,
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
        } elseif ($action === 'add_finding') {
            $findingType = trim((string) ($_POST['finding_type'] ?? ''));
            $description = trim((string) ($_POST['finding_description'] ?? ''));
            $resultStatus = (string) ($_POST['finding_result_status'] ?? 'With Finding');
            $requiresFollowUp = isset($_POST['finding_follow_up']) ? 1 : 0;
            if ($findingType === '' || $description === '' || !in_array($resultStatus, ['Normal', 'With Finding', 'Referred'], true)) {
                throw new InvalidArgumentException('Complete the finding type, description, and result.');
            }
            $finding = $apeDb->prepare('INSERT INTO ape_findings (ape_id, finding_type, description, result_status, follow_up_required, recorded_by_person_id) VALUES (?, ?, ?, ?, ?, ?)');
            $finding->execute([$id, $findingType, $description, $resultStatus, $requiresFollowUp, $staffPersonId]);
            if ($requiresFollowUp) {
                $apeDb->prepare("UPDATE ape_records SET follow_up_required = 1, clearance_status = 'For Follow-up', workflow_status = 'Follow-up Required' WHERE ape_id = ?")->execute([$id]);
            }
            $activityLabel = 'Added APE finding';
            $activityNotes = $findingType . ': ' . $resultStatus;
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
$documents = ape_documents_for_record($id);
$findings = ape_findings_for_record($id);
$activities = ape_activities_for_record($id, 20);
$birthdateLabel = $record['birthdate'] ? date('M d, Y', strtotime($record['birthdate'])) : 'Not recorded';
$sexLabel = $record['sex'] ?: 'Not specified';
$documentUrl = !empty($record['document_path']) ? app_url($record['document_path']) : null;
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
                    Student Information
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
                        <p class="clinic-label mb-1">Document Type</p>
                        <strong class="text-sm text-slate-800"><?= e($record['document_type'] ?: 'APE Form') ?></strong>
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
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-5">
                <div>
                    <p class="text-[10px] font-black text-primary uppercase tracking-widest mb-2"><?= e($queue['title']) ?></p>
                    <h2 class="font-headline text-xl md:text-2xl font-extrabold text-[#17261d] mb-1"><?= e($next['label']) ?></h2>
                    <p class="text-sm font-bold text-slate-500 mb-0 max-w-3xl"><?= e($actionCard['body']) ?></p>
                </div>
                <span class="badge <?= ape_priority_badge($record)['class'] ?>"><?= e(ape_missing_item($record)) ?></span>
            </div>

            <?php if ($queueKey === 'document_review'): ?>
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                    <form method="post" class="ape-flow-action space-y-3">
                        <input type="hidden" name="action" value="mark_requirements_complete">
                        <div>
                            <h3 class="font-headline text-base font-extrabold text-[#17261d] mb-1">Hard Copy Complete</h3>
                            <p class="text-xs font-bold text-slate-500 mb-3">Use this when all hard-copy APE documents are checked and no follow-up is needed.</p>
                            <label class="clinic-label">Clinic Notes</label>
                            <textarea class="clinic-textarea" name="clinical_remarks" rows="4" placeholder="Optional notes after checking hard-copy documents..."><?= e($record['clinical_remarks']) ?></textarea>
                        </div>
                        <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Continue to online submission?" data-confirm-message="This will mark hard-copy requirements complete and move the APE record forward." data-confirm-toast="Updating APE record...">
                            <span class="material-symbols-outlined text-[18px]">check_circle</span> Continue to Online Submission
                        </button>
                    </form>
                    <form method="post" class="ape-flow-action muted space-y-3">
                        <input type="hidden" name="action" value="mark_missing_requirements">
                        <div>
                            <h3 class="font-headline text-base font-extrabold text-[#17261d] mb-1">Finding / Follow-up Required</h3>
                            <p class="text-xs font-bold text-amber-800 mb-3">Use this when the student needs treatment proof, medical clearance, a repeat requirement, or a corrected hard copy.</p>
                            <label class="clinic-label">Required Follow-up</label>
                            <textarea class="clinic-textarea" name="missing_items" rows="3" placeholder="Treatment, clearance, repeat lab, referral, or missing requirement..."><?= e($record['missing_items']) ?></textarea>
                        </div>
                        <div>
                            <label class="clinic-label">Clinical Notes</label>
                            <textarea class="clinic-textarea" name="clinical_remarks" rows="3" placeholder="Finding or health concern notes..."><?= e($record['clinical_remarks']) ?></textarea>
                        </div>
                        <button class="btn btn-outline w-full" style="color:#b45309;border-color:rgba(180,83,9,0.2);" data-confirm-submit data-confirm-type="danger" data-confirm-title="Record finding or follow-up?" data-confirm-message="This will mark the APE record as needing follow-up." data-confirm-toast="Recording follow-up...">
                            <span class="material-symbols-outlined text-[18px]">medical_information</span> Record Follow-up
                        </button>
                    </form>
                </div>
            <?php elseif ($queueKey === 'digital_submission'): ?>
                <?php if (empty($record['document_path'])): ?>
                    <div class="ape-flow-action muted">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-amber-700 mt-0.5">hourglass_empty</span>
                            <div>
                                <h3 class="font-headline text-base font-extrabold text-amber-900 mb-1">Waiting for Student Upload</h3>
                                <p class="text-sm font-bold text-amber-800 mb-4">The student must upload the checked APE documents from the student portal before clinic archive review can continue.</p>
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
                            <p class="clinic-label">Submitted File</p>
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4 rounded-xl bg-slate-50 border border-slate-100">
                                <div>
                                    <strong class="text-sm text-slate-800"><?= e($record['document_type'] ?: 'APE documents') ?></strong>
                                    <p class="text-xs font-bold text-slate-400 mb-0">Ready for clinic archive review</p>
                                </div>
                                <a href="<?= e($documentUrl) ?>" target="_blank" class="btn btn-sm btn-outline text-decoration-none">
                                    <span class="material-symbols-outlined text-[14px]">open_in_new</span> View File
                                </a>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-3">
                            <form method="post" class="ape-flow-action space-y-3">
                                <input type="hidden" name="action" value="approve_documents">
                                <h3 class="font-headline text-base font-extrabold text-[#17261d] mb-1">Archive Submission</h3>
                                <p class="text-xs font-bold text-slate-500 mb-0">Approve when the upload matches the checked hard-copy documents.</p>
                                <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Archive these documents?" data-confirm-message="This will approve the uploaded documents for clinic archive." data-confirm-toast="Archiving documents..."><span class="material-symbols-outlined text-[18px]">inventory_2</span> Archive Documents</button>
                            </form>
                            <form method="post" class="ape-flow-action muted space-y-3">
                                <input type="hidden" name="action" value="request_document_correction">
                                <label class="clinic-label">Correction Needed</label>
                                <textarea class="clinic-textarea" name="missing_items" rows="3" placeholder="What should the student correct or resubmit online?"><?= e($record['missing_items']) ?></textarea>
                                <button class="btn btn-outline w-full" style="color:#b45309;border-color:rgba(180,83,9,0.2);" data-confirm-submit data-confirm-type="danger" data-confirm-title="Request document resubmission?" data-confirm-message="This will send the record back to the student for correction." data-confirm-toast="Requesting resubmission..."><span class="material-symbols-outlined text-[18px]">edit_note</span> Request Resubmission</button>
                            </form>
                        </div>
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
                                <a href="<?= e($clearanceUrl) ?>" target="_blank" class="btn btn-sm btn-outline text-decoration-none border-amber-200 text-amber-700 hover:bg-amber-100">
                                    <span class="material-symbols-outlined text-[14px]">open_in_new</span> View Clearance
                                </a>
                            <?php else: ?>
                                <p class="text-sm font-bold text-amber-800 mb-0">Waiting for student upload.</p>
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
                                <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Approve this clearance?" data-confirm-message="This will approve the student's clearance document." data-confirm-toast="Approving clearance..."><span class="material-symbols-outlined text-[18px]">verified</span> Approve Clearance</button>
                            </form>
                            <form method="post" class="ape-flow-action muted space-y-3">
                                <input type="hidden" name="action" value="return_clearance">
                                <label class="clinic-label">Reason for Return</label>
                                <textarea class="clinic-textarea" name="missing_items" rows="3" placeholder="Reason for returning clearance..."><?= e($record['missing_items']) ?></textarea>
                                <button class="btn btn-outline w-full" style="color:#b45309;border-color:rgba(180,83,9,0.2);" data-confirm-submit data-confirm-type="danger" data-confirm-title="Return clearance for correction?" data-confirm-message="This will return the clearance document to the student for correction." data-confirm-toast="Returning clearance..."><span class="material-symbols-outlined text-[18px]">assignment_return</span> Return for Correction</button>
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
                                    <a class="btn btn-sm btn-outline text-decoration-none" href="<?= e(app_url($document['file_path'])) ?>" target="_blank">
                                        <span class="material-symbols-outlined text-[14px]">open_in_new</span> Open
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

        <section class="ape-flow-panel">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Clinical Findings</h2>
                    <p class="text-xs font-bold text-slate-500 mb-0">Individual examination findings recorded by authorized clinic staff.</p>
                </div>
                <span class="badge <?= $findings ? 'badge-high' : 'badge-completed' ?>"><?= count($findings) ?> finding(s)</span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_0.8fr] gap-4">
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
                <form method="post" class="ape-flow-action space-y-3">
                    <input type="hidden" name="action" value="add_finding">
                    <input class="clinic-input" name="finding_type" placeholder="Finding type, e.g. Vision" required>
                    <textarea class="clinic-textarea" name="finding_description" rows="4" placeholder="Finding description" required></textarea>
                    <select class="clinic-select" name="finding_result_status">
                        <option>Normal</option>
                        <option selected>With Finding</option>
                        <option>Referred</option>
                    </select>
                    <label class="flex items-center gap-3 text-sm font-bold text-slate-600">
                        <input type="checkbox" name="finding_follow_up" value="1"> Follow-up required
                    </label>
                    <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Add clinical finding?" data-confirm-message="This finding will become part of the patient's APE record." data-confirm-toast="Saving finding...">
                        <span class="material-symbols-outlined text-[18px]">medical_information</span> Add Finding
                    </button>
                </form>
            </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-[0.95fr_1.05fr] gap-4">
            <section class="ape-flow-panel">
                <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-4">Additional Notes</h2>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="save_notes">
                    <div>
                        <label class="clinic-label">Student-Visible Note</label>
                        <textarea class="clinic-textarea" name="student_visible_note" rows="3" placeholder="Notes the student can see..."><?= e($record['student_visible_note']) ?></textarea>
                    </div>
                    <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save these notes?" data-confirm-message="This will update the notes on this APE record." data-confirm-toast="Saving notes..."><span class="material-symbols-outlined text-[18px]">save</span> Save Notes</button>
                </form>
            </section>

            <section class="ape-flow-panel overflow-hidden">
                <div class="p-5 border-b border-slate-100">
                    <h2 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Activity History</h2>
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
