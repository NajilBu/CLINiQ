<?php
require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';

$profile = student_require_login();
$patientId = (int) $profile['person_id'];
ensure_ape_workflow_schema();
$apeRecord = ape_fetch_patient_record($patientId);
$uploadError = '';

$batchDocumentTypes = [
    'lab_request_form' => 'Lab Request Form',
    'uhs_consent_form' => 'UHS Consent Form',
    'uhs_medical_record' => 'UHS Medical Record',
    'uhs_dental_record' => 'UHS Dental Record',
    'referral_form' => 'Referral Form',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_ape_documents') {
    $storedFiles = [];
    try {
        if (!$apeRecord) {
            throw new RuntimeException('The clinic must create your APE record before you can upload documents.');
        }
        $requirementsVerifiedForUpload = ($apeRecord['requirement_status'] ?? '') === 'Pre-Verified'
            || in_array(($apeRecord['workflow_status'] ?? ''), ['Requirements Checked', 'Submitted', 'Reviewed', 'Scheduled', 'Exam Done', 'Follow-up Required'], true);
        if (!$requirementsVerifiedForUpload || ($apeRecord['clearance_status'] ?? '') === 'Cleared') {
            throw new RuntimeException('Document upload is not available at the current APE step.');
        }

        $latestExistingByType = [];
        foreach (ape_documents_for_record((int) $apeRecord['ape_id']) as $existingDocument) {
            $latestExistingByType[$existingDocument['document_type']] ??= $existingDocument;
        }
        $batchFiles = $_FILES['documents'] ?? [];
        foreach ($batchDocumentTypes as $documentKey => $documentType) {
            $error = (int) ($batchFiles['error'][$documentKey] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $latestExisting = $latestExistingByType[$documentType] ?? null;
            if ($latestExisting && ($latestExisting['verification_status'] ?? '') !== 'Needs Correction') {
                throw new RuntimeException($documentType . ' cannot be replaced while it is verified or under clinic review.');
            }
            $file = [
                'name' => $batchFiles['name'][$documentKey] ?? '',
                'type' => $batchFiles['type'][$documentKey] ?? '',
                'tmp_name' => $batchFiles['tmp_name'][$documentKey] ?? '',
                'error' => $error,
                'size' => $batchFiles['size'][$documentKey] ?? 0,
            ];
            $storedFiles[$documentType] = ape_store_uploaded_file($file, 'patient-ape');
        }
        if (!$storedFiles) {
            throw new InvalidArgumentException('Select at least one APE document before submitting.');
        }

        $apeDb = auth_db();
        $apeDb->beginTransaction();
        $document = $apeDb->prepare("
            INSERT INTO ape_documents (
                ape_id, document_type, original_filename, file_path,
                verification_status, uploaded_by_person_id
            ) VALUES (?, ?, ?, ?, 'Pending', ?)
        ");
        $requirement = $apeDb->prepare("
            UPDATE ape_requirements
            SET status = 'Submitted', remarks = NULL, checked_by_person_id = NULL, checked_at = NULL
            WHERE ape_id = ? AND requirement_name = ?
        ");
        foreach ($storedFiles as $documentType => $storedFile) {
            $document->execute([
                (int) $apeRecord['ape_id'],
                $documentType,
                $storedFile['original_filename'],
                $storedFile['file_path'],
                $patientId,
            ]);
            $requirement->execute([(int) $apeRecord['ape_id'], $documentType]);
        }
        $nextWorkflowStatus = ($apeRecord['workflow_status'] ?? '') === 'Follow-up Required'
            ? 'Follow-up Required'
            : 'Submitted';
        $apeDb->prepare('UPDATE ape_records SET workflow_status = ? WHERE ape_id = ?')
            ->execute([$nextWorkflowStatus, (int) $apeRecord['ape_id']]);
        ape_log_activity(
            (int) $apeRecord['ape_id'],
            $patientId,
            'Uploaded APE documents',
            implode(', ', array_keys($storedFiles))
        );
        $apeDb->commit();
        header('Location: patient-ape-status.php?uploaded=' . count($storedFiles));
        exit;
    } catch (Throwable $e) {
        if (isset($apeDb) && $apeDb->inTransaction()) {
            $apeDb->rollBack();
        }
        foreach ($storedFiles as $storedFile) {
            if (is_file($storedFile['absolute_path'])) {
                unlink($storedFile['absolute_path']);
            }
        }
        $uploadError = $e->getMessage();
    }
}

$apeRecord = ape_fetch_patient_record($patientId);
$requirements = $apeRecord ? ape_requirements_for_record((int) $apeRecord['ape_id']) : [];
$uploadedDocuments = $apeRecord ? ape_documents_for_record((int) $apeRecord['ape_id']) : [];
$findings = $apeRecord ? ape_findings_for_record((int) $apeRecord['ape_id']) : [];
$activities = $apeRecord ? ape_activities_for_record((int) $apeRecord['ape_id'], 10) : [];
$apeStatus = $apeRecord['workflow_status'] ?? 'Not Started';
$clearanceStatus = $apeRecord['clearance_status'] ?? 'Pending';
$studentNote = trim((string) ($apeRecord['patient_visible_note'] ?? ''));
$missingItems = trim((string) ($apeRecord['missing_items'] ?? ''));
$actionNeeded = $clearanceStatus !== 'Cleared' && $apeStatus !== 'Not Started';
$requirementStatus = $apeRecord['requirement_status'] ?? 'Not Checked';
$requirementsVerified = $requirementStatus === 'Pre-Verified' || in_array($apeStatus, [
    'Requirements Checked',
    'Submitted',
    'Reviewed',
    'Scheduled',
    'Exam Done',
    'Follow-up Required',
    'Cleared',
], true);
$requirementsNeedCorrection = $requirementStatus === 'Needs Correction';
$canUploadDocuments = $requirementsVerified
    && !$requirementsNeedCorrection
    && $clearanceStatus !== 'Cleared'
    && in_array($apeStatus, ['Requirements Checked', 'Submitted', 'Follow-up Required'], true);
$nextActionTitle = match (true) {
    $clearanceStatus === 'Cleared' => 'APE completed',
    $requirementsNeedCorrection => 'Return corrected hard-copy requirements',
    !$requirementsVerified => 'Wait for clinic hard-copy verification',
    in_array($apeStatus, ['Reviewed', 'Scheduled'], true) => 'Attend your clinical examination',
    $apeStatus === 'Exam Done' => 'Wait for the final clinical decision',
    $apeStatus === 'Follow-up Required' => 'Complete the required follow-up',
    default => 'Upload verified APE documents',
};
$nextActionCopy = match (true) {
    $clearanceStatus === 'Cleared' => 'Your APE record is already cleared by the clinic.',
    $requirementsNeedCorrection => $studentNote ?: 'Return the corrected hard-copy requirements requested by the clinic.',
    !$requirementsVerified => 'The clinic must check your physical requirements first. Upload controls will appear after the clinic marks your requirements as verified.',
    in_array($apeStatus, ['Reviewed', 'Scheduled'], true) => $studentNote ?: 'Your uploaded documents are archived. Visit the clinic for your APE examination.',
    $apeStatus === 'Exam Done' => $studentNote ?: 'Your examination is recorded and awaiting the clinic\'s final clearance decision.',
    $apeStatus === 'Follow-up Required' => $studentNote ?: 'Complete the treatment, clearance, referral, or other follow-up requested by the clinic.',
    default => $studentNote ?: ($apeRecord ? 'Complete the current APE step shown below.' : 'No APE record has been opened by the clinic yet.'),
};
$apePercent = match ($apeStatus) {
    'Registered' => 20,
    'Batch Assigned', 'Requirements Checked' => 40,
    'Submitted', 'Reviewed' => 60,
    'Scheduled', 'Exam Done', 'Follow-up Required' => 80,
    'Cleared' => 100,
    default => 0,
};
$headerBadge = $clearanceStatus === 'Cleared' ? 'student-badge-success' : ($actionNeeded ? 'student-badge-warning' : 'student-badge-info');
$currentStep = match (true) {
    $clearanceStatus === 'Cleared' => 5,
    $apeStatus === 'Follow-up Required' || $clearanceStatus === 'For Follow-up' => 4,
    in_array($apeStatus, ['Reviewed', 'Scheduled', 'Exam Done'], true) => 3,
    $apeStatus === 'Submitted' => 2,
    $requirementsVerified => 2,
    default => 1,
};

$flowSteps = [
    [
        'number' => 1,
        'icon' => 'task_alt',
        'title' => 'Hard-copy review',
        'copy' => $requirementsVerified
            ? 'Clinic checked your physical requirements and noted findings.'
            : 'Bring your hard-copy requirements to the clinic for verification.',
    ],
    [
        'number' => 2,
        'icon' => 'cloud_upload',
        'title' => 'Digital document keeping',
        'copy' => $requirementsVerified
            ? 'Upload checked forms for the clinic digital record.'
            : 'This opens only after clinic hard-copy verification.',
    ],
    [
        'number' => 3,
        'icon' => 'manage_search',
        'title' => 'Clinical examination',
        'copy' => in_array($apeStatus, ['Reviewed', 'Scheduled', 'Exam Done', 'Follow-up Required', 'Cleared'], true)
            ? 'Clinic records your examination date, result, and findings.'
            : 'This begins after the clinic archives all required uploads.',
    ],
    [
        'number' => 4,
        'icon' => 'medical_services',
        'title' => 'Final decision or follow-up',
        'copy' => 'The clinic clears the record or requests treatment, clearance, or referral follow-up.',
    ],
    [
        'number' => 5,
        'icon' => 'verified_user',
        'title' => 'Completed APE',
        'copy' => 'Your patient clinic record is cleared.',
    ],
];

$requirementsByName = [];
foreach ($requirements as $requirement) {
    $requirementsByName[$requirement['requirement_name']] = $requirement;
}
$latestDocumentByType = [];
foreach ($uploadedDocuments as $uploadedDocument) {
    $latestDocumentByType[$uploadedDocument['document_type']] ??= $uploadedDocument;
}
$documentIcons = [
    'Lab Request Form' => 'description',
    'UHS Consent Form' => 'approval',
    'UHS Medical Record' => 'description',
    'UHS Dental Record' => 'assignment',
    'Referral Form' => 'send',
];
$documents = [];
foreach ($documentIcons as $name => $icon) {
    $documentKey = array_search($name, $batchDocumentTypes, true);
    $requirement = $requirementsByName[$name] ?? null;
    $uploadedDocument = $latestDocumentByType[$name] ?? null;
    if ($uploadedDocument) {
        $verification = $uploadedDocument['verification_status'];
        $documents[] = [
            'name' => $name,
            'key' => $documentKey,
            'icon' => $icon,
            'status' => $verification,
            'badge' => match ($verification) {
                'Verified' => 'student-badge-success',
                'Needs Correction' => 'student-badge-danger',
                default => 'student-badge-warning',
            },
            'detail' => match ($verification) {
                'Verified' => 'The clinic verified this uploaded document.',
                'Needs Correction' => $requirement['remarks'] ?? 'The clinic requested a corrected copy.',
                default => 'Uploaded and waiting for clinic verification.',
            },
            'action' => $verification === 'Needs Correction' ? 'Replace' : ($verification === 'Verified' ? 'Verified' : 'Under Review'),
            'button' => $verification === 'Needs Correction' ? 'student-button' : 'student-button-secondary student-button-disabled',
            'disabled' => $verification !== 'Needs Correction',
        ];
    } elseif ($canUploadDocuments) {
        $documents[] = [
            'name' => $name,
            'key' => $documentKey,
            'icon' => $icon,
            'status' => $requirement['status'] ?? 'Ready to Upload',
            'badge' => 'student-badge-warning',
            'detail' => $requirement['remarks'] ?? 'Upload the checked digital copy for clinic record keeping.',
            'action' => 'Upload',
            'button' => 'student-button',
            'disabled' => false,
        ];
    } else {
        $documents[] = [
            'name' => $name,
            'key' => $documentKey,
            'icon' => $icon,
            'status' => $requirement['status'] ?? ($requirementsNeedCorrection ? 'Needs Correction' : 'Awaiting Clinic Check'),
            'badge' => $requirementsNeedCorrection ? 'student-badge-danger' : 'student-badge-info',
            'detail' => $requirement['remarks'] ?? ($requirementsNeedCorrection
                ? 'Bring the corrected hard copy back to the clinic before uploading.'
                : 'Clinic staff must verify the hard copy before upload opens.'),
            'action' => 'Locked',
            'button' => 'student-button-secondary student-button-disabled',
            'disabled' => true,
        ];
    }
}
$uploadableDocumentCount = count(array_filter($documents, static fn(array $document): bool => !$document['disabled']));

render_student_header('APE Status', 'ape');
?>

<section class="student-page-header">
    <div>
        <p class="student-eyebrow">Annual Physical Examination</p>
        <h1 class="student-title">APE Status</h1>
        <p class="student-subtitle">Complete the documents requested by the clinic and monitor your clearance status.</p>
    </div>
    <span class="student-badge <?= student_e($headerBadge) ?>">
        <span class="material-symbols-outlined text-[14px]">pending_actions</span>
        <?= student_e($clearanceStatus) ?>
    </span>
</section>

<?php if (isset($_GET['uploaded'])): ?>
    <div class="student-note student-note-success mb-4">
        <span class="material-symbols-outlined">check_circle</span>
        <div><?= (int) $_GET['uploaded'] ?> APE document(s) were uploaded together and are waiting for clinic verification.</div>
    </div>
<?php elseif ($uploadError !== ''): ?>
    <div class="student-note student-note-danger mb-4">
        <span class="material-symbols-outlined">error</span>
        <div><?= student_e($uploadError) ?></div>
    </div>
<?php endif; ?>

<section class="student-action-card mb-4">
    <div class="flex items-start gap-4">
        <span class="student-icon-box">
            <span class="material-symbols-outlined">cloud_upload</span>
        </span>
        <div>
            <h2>Next action: <?= student_e($nextActionTitle) ?></h2>
            <p><?= student_e($nextActionCopy) ?></p>
        </div>
    </div>
    <?php if (!$canUploadDocuments): ?>
        <span class="student-badge <?= $requirementsNeedCorrection ? 'student-badge-danger' : 'student-badge-info' ?>">
            <?= $requirementsNeedCorrection ? 'Correction Needed' : 'Clinic Verification First' ?>
        </span>
    <?php endif; ?>
</section>

<div class="student-grid">
    <section class="student-card student-span-5">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">APE Flow</h2>
                <p class="student-card-copy">What happens to your record</p>
            </div>
            <span class="student-badge <?= student_e($headerBadge) ?>"><?= student_e($apeStatus) ?></span>
        </div>
        <div class="student-card-pad">
            <div class="flex items-end justify-between mb-4">
                <span class="text-xs font-black text-slate-500 uppercase tracking-wider">Completion</span>
                <strong class="font-headline text-3xl font-black text-[#17261d]"><?= (int) $apePercent ?>%</strong>
            </div>
            <div class="w-full h-3 rounded-full bg-[#edf8f0] overflow-hidden mb-5">
                <div class="h-full bg-[#3F7D52] rounded-full" style="width: <?= (int) $apePercent ?>%;"></div>
            </div>
            <div class="student-ape-stepper" aria-label="APE progress steps">
                <?php foreach ($flowSteps as $step): ?>
                    <?php
                    $stepNumber = (int) $step['number'];
                    $isDone = $stepNumber < $currentStep || ($currentStep === 5 && $stepNumber === 5);
                    $isCurrent = $stepNumber === $currentStep && !$isDone;
                    $stepClass = $isDone ? 'is-done' : ($isCurrent ? 'is-current' : 'is-locked');
                    $badgeClass = $isDone ? 'student-badge-success' : ($isCurrent ? 'student-badge-warning' : 'student-badge-info');
                    $badgeLabel = $isDone ? 'Done' : ($isCurrent ? 'Current' : ($stepNumber === 2 && !$requirementsVerified ? 'Locked' : 'Next'));
                    ?>
                    <div class="student-ape-step <?= student_e($stepClass) ?>">
                        <span class="student-ape-step-rail" aria-hidden="true"></span>
                        <span class="student-ape-step-index">
                            <span class="material-symbols-outlined"><?= student_e($isDone ? 'check' : $step['icon']) ?></span>
                        </span>
                        <div class="student-ape-step-body">
                            <div class="student-ape-step-top">
                                <span class="student-ape-step-count">Step <?= (int) $stepNumber ?> of 5</span>
                                <span class="student-badge <?= student_e($badgeClass) ?>"><?= student_e($badgeLabel) ?></span>
                            </div>
                            <strong><?= student_e($step['title']) ?></strong>
                            <span><?= student_e($step['copy']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="student-card student-span-7">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Required Documents</h2>
                <p class="student-card-copy">Upload only documents already checked by the clinic.</p>
            </div>
            <span class="student-badge <?= student_e($headerBadge) ?>"><?= student_e($clearanceStatus) ?></span>
        </div>
        <div class="student-card-pad">
            <form method="post" enctype="multipart/form-data" id="ape-batch-upload-form">
                <input type="hidden" name="action" value="upload_ape_documents">
            <?php if ($missingItems !== ''): ?>
                <div class="student-note student-note-warning mb-4">
                    <span class="material-symbols-outlined">info</span>
                    <div><strong>Clinic note:</strong> <?= student_e($missingItems) ?></div>
                </div>
            <?php endif; ?>
            <div class="student-document-list">
                <?php foreach ($documents as $doc): ?>
                    <div class="student-document-card">
                        <span class="student-icon-box">
                            <span class="material-symbols-outlined"><?= student_e($doc['icon']) ?></span>
                        </span>
                        <div class="student-document-meta">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3><?= student_e($doc['name']) ?></h3>
                                <span class="student-badge <?= student_e($doc['badge']) ?>"><?= student_e($doc['status']) ?></span>
                            </div>
                            <p><?= student_e($doc['detail']) ?></p>
                            <?php if (!$doc['disabled']): ?>
                                <p class="ape-staged-file-name hidden" id="ape-file-name-<?= student_e($doc['key']) ?>"></p>
                            <?php endif; ?>
                        </div>
                        <?php if ($doc['disabled']): ?>
                            <button class="<?= student_e($doc['button']) ?>" type="button" disabled>
                                <span class="material-symbols-outlined">lock</span>
                                <?= student_e($doc['action']) ?>
                            </button>
                        <?php else: ?>
                            <div class="student-appointment-actions ape-document-actions" data-document-key="<?= student_e($doc['key']) ?>">
                                <input class="hidden ape-document-input" type="file" name="documents[<?= student_e($doc['key']) ?>]" id="ape-file-<?= student_e($doc['key']) ?>" accept=".pdf,.png,.jpg,.jpeg" data-document-name="<?= student_e($doc['name']) ?>" onchange="handleApeFileSelected(this)">
                                <button class="<?= student_e($doc['button']) ?> ape-select-file" type="button" onclick="selectApeFile('<?= student_e($doc['key']) ?>')">
                                    <span class="material-symbols-outlined">upload</span>
                                    <span><?= $doc['action'] === 'Replace' ? 'Select Replacement' : 'Select File' ?></span>
                                </button>
                                <button class="student-button-secondary ape-remove-file hidden" type="button" onclick="removeApeFile('<?= student_e($doc['key']) ?>')">
                                    <span class="material-symbols-outlined">delete</span>
                                    Remove
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($uploadableDocumentCount > 0): ?>
                <div class="ape-batch-submit-bar">
                    <div>
                        <strong id="ape-selected-summary">No files selected</strong>
                        <span>Select the documents first. You can change or remove them before submitting.</span>
                    </div>
                    <button class="student-button" id="ape-submit-all" type="submit" disabled>
                        <span class="material-symbols-outlined">cloud_upload</span>
                        Submit All Documents <span id="ape-selected-count"></span>
                    </button>
                </div>
            <?php endif; ?>
            </form>
        </div>
    </section>
</div>

<div class="student-grid mt-4">
    <section class="student-card student-span-6">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Requirements Checklist</h2>
                <p class="student-card-copy">Current clinic status for every APE requirement.</p>
            </div>
            <span class="student-badge student-badge-info"><?= count($requirements) ?> Item(s)</span>
        </div>
        <div class="student-card-pad grid gap-3">
            <?php foreach ($requirements as $requirement): ?>
                <?php
                $requirementBadge = match ($requirement['status']) {
                    'Verified' => 'student-badge-success',
                    'Needs Correction' => 'student-badge-danger',
                    'Submitted' => 'student-badge-warning',
                    default => 'student-badge-info',
                };
                ?>
                <div class="student-document-card">
                    <span class="student-icon-box"><span class="material-symbols-outlined">checklist</span></span>
                    <div class="student-document-meta">
                        <h3><?= student_e($requirement['requirement_name']) ?></h3>
                        <p><?= student_e($requirement['remarks'] ?: ($requirement['checked_at'] ? 'Checked by the clinic.' : 'Waiting for completion.')) ?></p>
                    </div>
                    <span class="student-badge <?= student_e($requirementBadge) ?>"><?= student_e($requirement['status']) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (!$requirements): ?>
                <div class="student-note student-note-warning"><span class="material-symbols-outlined">info</span><div>No requirements have been listed yet.</div></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="student-card student-span-6">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Uploaded File History</h2>
                <p class="student-card-copy">All digital documents submitted for this APE.</p>
            </div>
            <span class="student-badge student-badge-info"><?= count($uploadedDocuments) ?> File(s)</span>
        </div>
        <div class="student-card-pad grid gap-3">
            <?php foreach ($uploadedDocuments as $uploadedDocument): ?>
                <?php
                $documentBadge = match ($uploadedDocument['verification_status']) {
                    'Verified' => 'student-badge-success',
                    'Needs Correction' => 'student-badge-danger',
                    default => 'student-badge-warning',
                };
                ?>
                <div class="student-document-card">
                    <span class="student-icon-box"><span class="material-symbols-outlined">description</span></span>
                    <div class="student-document-meta">
                        <h3><?= student_e($uploadedDocument['document_type']) ?></h3>
                        <p><?= student_e($uploadedDocument['original_filename'] ?: basename($uploadedDocument['file_path'])) ?> · <?= student_e(date('M d, Y g:i A', strtotime($uploadedDocument['uploaded_at']))) ?></p>
                    </div>
                    <div class="student-appointment-actions">
                        <span class="student-badge <?= student_e($documentBadge) ?>"><?= student_e($uploadedDocument['verification_status']) ?></span>
                        <a class="student-button-secondary text-decoration-none" href="../public/<?= student_e($uploadedDocument['file_path']) ?>" data-file-preview data-preview-title="<?= student_e($uploadedDocument['original_filename'] ?: $uploadedDocument['document_type']) ?>">Open</a>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$uploadedDocuments): ?>
                <div class="student-note student-note-warning"><span class="material-symbols-outlined">upload_file</span><div>No digital document has been uploaded.</div></div>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="student-card mt-4">
    <div class="student-card-header">
        <div>
            <h2 class="student-card-title">Clinic Findings and Follow-Up</h2>
            <p class="student-card-copy">Examination findings and follow-up instructions recorded for you.</p>
        </div>
        <span class="student-badge <?= student_e($headerBadge) ?>"><?= student_e($apeRecord['result_status'] ?? 'No Active Treatment') ?></span>
    </div>
    <div class="student-card-pad grid gap-3">
        <?php foreach ($findings as $finding): ?>
            <div class="student-note <?= $finding['follow_up_required'] ? 'student-note-warning' : 'student-note-success' ?>">
                <span class="material-symbols-outlined"><?= $finding['follow_up_required'] ? 'medical_information' : 'check_circle' ?></span>
                <div>
                    <strong><?= student_e($finding['finding_type'] . ' · ' . $finding['result_status']) ?></strong>
                    <?= student_e($finding['description']) ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$findings): ?>
            <div class="student-note student-note-success"><span class="material-symbols-outlined">check_circle</span><div><strong>No active finding recorded.</strong> <?= student_e($studentNote ?: 'No additional patient instruction has been recorded.') ?></div></div>
        <?php endif; ?>
    </div>
</section>

<section class="student-card mt-4">
    <div class="student-card-header">
        <div>
            <h2 class="student-card-title">APE Activity Timeline</h2>
            <p class="student-card-copy">Actions recorded for this APE case.</p>
        </div>
        <span class="student-badge student-badge-info"><?= count($activities) ?> Event(s)</span>
    </div>
    <div class="student-card-pad grid gap-3">
        <?php foreach ($activities as $activity): ?>
            <div class="student-document-card">
                <span class="student-icon-box"><span class="material-symbols-outlined">history</span></span>
                <div class="student-document-meta">
                    <h3><?= student_e($activity['action_label']) ?></h3>
                    <p><?= student_e(date('M d, Y g:i A', strtotime($activity['created_at']))) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$activities): ?>
            <div class="student-note student-note-warning"><span class="material-symbols-outlined">history</span><div>No APE activity has been recorded.</div></div>
        <?php endif; ?>
    </div>
</section>

<script>
    function selectApeFile(documentKey) {
        document.getElementById(`ape-file-${documentKey}`).click();
    }

    function handleApeFileSelected(input) {
        updateApeFileRow(input);
        refreshApeBatchSummary();
    }

    function removeApeFile(documentKey) {
        const input = document.getElementById(`ape-file-${documentKey}`);
        input.value = '';
        updateApeFileRow(input);
        refreshApeBatchSummary();
    }

    function updateApeFileRow(input) {
        const documentKey = input.id.replace('ape-file-', '');
        const actions = input.closest('.ape-document-actions');
        const filename = document.getElementById(`ape-file-name-${documentKey}`);
        const selectLabel = actions.querySelector('.ape-select-file span:last-child');
        const removeButton = actions.querySelector('.ape-remove-file');
        const selectedFile = input.files && input.files[0] ? input.files[0] : null;

        if (selectedFile) {
            filename.textContent = `Selected: ${selectedFile.name}`;
            filename.classList.remove('hidden');
            selectLabel.textContent = 'Change File';
            removeButton.classList.remove('hidden');
        } else {
            filename.textContent = '';
            filename.classList.add('hidden');
            selectLabel.textContent = 'Select File';
            removeButton.classList.add('hidden');
        }
    }

    function refreshApeBatchSummary() {
        const selected = Array.from(document.querySelectorAll('.ape-document-input'))
            .filter((input) => input.files && input.files.length > 0);
        const count = selected.length;
        const submitButton = document.getElementById('ape-submit-all');
        const summary = document.getElementById('ape-selected-summary');
        const countLabel = document.getElementById('ape-selected-count');
        if (!submitButton || !summary || !countLabel) return;

        submitButton.disabled = count === 0;
        summary.textContent = count === 0 ? 'No files selected' : `${count} file${count === 1 ? '' : 's'} ready to submit`;
        countLabel.textContent = count === 0 ? '' : `(${count})`;
    }

    document.getElementById('ape-batch-upload-form')?.addEventListener('submit', (event) => {
        const submitButton = document.getElementById('ape-submit-all');
        if (!submitButton || submitButton.disabled) {
            event.preventDefault();
            return;
        }
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="material-symbols-outlined">progress_activity</span> Uploading Documents...';
    });
</script>

<?php render_student_footer(); ?>
