<?php
require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';

$profile = student_require_login();
if (($profile['account_type'] ?? '') !== 'student') {
    http_response_code(403);
    exit('APE self-service is available to students only. Faculty and Non-Teaching Personnel APEs are completed by clinic staff.');
}
$patientId = (int) $profile['person_id'];
ensure_ape_workflow_schema();
$apeRecord = ape_fetch_patient_record($patientId);
$uploadError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_ape_documents') {
    $storedFiles = [];
    $filesCommitted = false;
    try {
        if (!$apeRecord) {
            throw new RuntimeException('The clinic must create your APE record before you can upload documents.');
        }
        if (($apeRecord['clearance_status'] ?? '') === 'Cleared' || ($apeRecord['workflow_status'] ?? '') === 'Cleared') {
            throw new RuntimeException('Document upload is closed because this APE record is already completed.');
        }

        $uploadRequirements = ape_requirements_for_record((int) $apeRecord['ape_id']);
        $documentTypesByKey = [];
        $preserveFollowUpReview = [];
        foreach ($uploadRequirements as $uploadRequirement) {
            $documentTypesByKey['r' . (int) $uploadRequirement['requirement_id']] = $uploadRequirement['requirement_name'];
            if (!empty($uploadRequirement['checked_at']) || in_array($uploadRequirement['status'], ['Verified', 'Needs Correction'], true)) {
                $preserveFollowUpReview[$uploadRequirement['requirement_name']] = true;
            }
        }
        if (!$documentTypesByKey) {
            throw new RuntimeException('The clinic has not listed any APE requirements for upload yet.');
        }

        $latestExistingByType = [];
        foreach (ape_documents_for_record((int) $apeRecord['ape_id']) as $existingDocument) {
            $latestExistingByType[$existingDocument['document_type']] ??= $existingDocument;
        }
        $batchFiles = $_FILES['documents'] ?? [];
        $requiredDocumentKeys = [];
        foreach ($documentTypesByKey as $documentKey => $documentType) {
            $existing = $latestExistingByType[$documentType] ?? null;
            if (!$existing || ($existing['verification_status'] ?? '') === 'Needs Correction') {
                $requiredDocumentKeys[] = $documentKey;
            }
        }
        $missingDocuments = array_filter($requiredDocumentKeys, static function (string $documentKey) use ($batchFiles): bool {
            $errors = $batchFiles['error'][$documentKey] ?? [UPLOAD_ERR_NO_FILE];
            $errors = is_array($errors) ? $errors : [$errors];
            return !array_filter($errors, static fn($error): bool => (int) $error !== UPLOAD_ERR_NO_FILE);
        });
        if ($missingDocuments) {
            throw new InvalidArgumentException('Attach a file for every required APE document before submitting.');
        }
        foreach ($documentTypesByKey as $documentKey => $documentType) {
            $errors = $batchFiles['error'][$documentKey] ?? UPLOAD_ERR_NO_FILE;
            if (!is_array($errors)) {
                $errors = [$errors];
            }
            if (count(array_filter($errors, static fn($error): bool => (int) $error !== UPLOAD_ERR_NO_FILE)) === 0) {
                continue;
            }
            $latestExisting = $latestExistingByType[$documentType] ?? null;
            if ($latestExisting && ($latestExisting['verification_status'] ?? '') !== 'Needs Correction') {
                throw new RuntimeException($documentType . ' cannot be replaced while it is verified or under clinic review.');
            }
            foreach ($errors as $fileIndex => $error) {
                if ((int) $error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $file = [
                    'name' => $batchFiles['name'][$documentKey][$fileIndex] ?? '',
                    'type' => $batchFiles['type'][$documentKey][$fileIndex] ?? '',
                    'tmp_name' => $batchFiles['tmp_name'][$documentKey][$fileIndex] ?? '',
                    'error' => (int) $error,
                    'size' => $batchFiles['size'][$documentKey][$fileIndex] ?? 0,
                ];
                $storedFiles[] = [
                    'document_type' => $documentType,
                    'file' => ape_store_uploaded_file($file, 'patient-ape'),
                ];
            }
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
            INSERT INTO ape_requirements (ape_id, requirement_name, status, remarks, checked_by_person_id, checked_at, upload_group)
            VALUES (?, ?, 'Submitted', NULL, NULL, NULL, 'initial')
            ON DUPLICATE KEY UPDATE
                status = 'Submitted',
                remarks = NULL,
                checked_by_person_id = NULL,
                checked_at = NULL,
                upload_group = COALESCE(upload_group, 'initial')
        ");
        $updatedRequirements = [];
        foreach ($storedFiles as $storedUpload) {
            $documentType = $storedUpload['document_type'];
            $storedFile = $storedUpload['file'];
            $document->execute([
                (int) $apeRecord['ape_id'],
                $documentType,
                $storedFile['original_filename'],
                $storedFile['file_path'],
                $patientId,
            ]);
            if (!isset($updatedRequirements[$documentType])) {
                if (!isset($preserveFollowUpReview[$documentType])) {
                    $requirement->execute([(int) $apeRecord['ape_id'], $documentType]);
                }
                $updatedRequirements[$documentType] = true;
            }
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
            implode(', ', array_keys($updatedRequirements))
        );
        $apeDb->commit();
        $filesCommitted = true;
        header('Location: patient-ape-status.php?uploaded=' . count($storedFiles));
        exit;
    } catch (Throwable $e) {
        if (isset($apeDb) && $apeDb->inTransaction()) {
            $apeDb->rollBack();
        }
        if (!$filesCommitted) {
            foreach ($storedFiles as $storedUpload) {
                $storedFile = $storedUpload['file'] ?? [];
                if (!empty($storedFile['absolute_path']) && is_file($storedFile['absolute_path'])) {
                    unlink($storedFile['absolute_path']);
                }
            }
        }
        $uploadError = $e->getMessage();
    }
}

$apeRecord = ape_fetch_patient_record($patientId);
$requirements = $apeRecord ? ape_requirements_for_record((int) $apeRecord['ape_id']) : [];
$uploadedDocuments = $apeRecord ? ape_documents_for_record((int) $apeRecord['ape_id']) : [];
$findings = $apeRecord ? ape_findings_for_record((int) $apeRecord['ape_id']) : [];
$allActivities = $apeRecord ? ape_activities_for_patient_record((int) $apeRecord['ape_id'], $patientId, 200) : [];
$historyLimit = 5;
$historyTotalPages = max(1, (int) ceil(count($allActivities) / $historyLimit));
$historyPage = max(1, min($historyTotalPages, (int) ($_GET['ape_history_page'] ?? 1)));
$activities = array_slice($allActivities, ($historyPage - 1) * $historyLimit, $historyLimit);
$apeStatus = $apeRecord['workflow_status'] ?? 'Not Started';
$clearanceStatus = $apeRecord['clearance_status'] ?? 'Pending';
$studentNote = trim((string) ($apeRecord['patient_visible_note'] ?? ''));
$missingItems = trim((string) ($apeRecord['missing_items'] ?? ''));
$requirementStatus = $apeRecord['requirement_status'] ?? 'Not Checked';
$hasScheduledBatch = !empty($apeRecord['schedule_batch_id']) && ($apeRecord['batch_status'] ?? '') === 'Scheduled';
$actionNeeded = $clearanceStatus !== 'Cleared' && $apeStatus !== 'Not Started' && $hasScheduledBatch;
$batchScheduleLabel = $hasScheduledBatch
    ? date('F j, Y', strtotime((string) $apeRecord['batch_schedule_date'])) . ' at '
        . date('g:i A', strtotime((string) $apeRecord['batch_start_time'])) . '–'
        . date('g:i A', strtotime((string) $apeRecord['batch_end_time']))
    : '';
$requirementsVerified = $requirementStatus === 'Checked' || in_array($apeStatus, [
    'Requirements Checked',
    'Submitted',
    'Reviewed',
    'Scheduled',
    'Follow-up Required',
    'Cleared',
], true);
$requirementsNeedCorrection = $requirementStatus === 'Needs Correction';
$examCompleted = !empty($apeRecord['exam_date']);
$apeQueue = $apeRecord ? ape_record_queue($apeRecord) : 'examination';
$requiredRequirementNames = ape_upload_requirement_names($requirements);
$uploadedRequirementNames = [];
foreach ($uploadedDocuments as $uploadedDocument) {
    $uploadedRequirementNames[$uploadedDocument['document_type']] = true;
}
$allRequiredDocumentsUploaded = ape_initial_uploads_present($apeRecord ?? []);
$documentsAwaitingReview = $allRequiredDocumentsUploaded && (int) ($apeRecord['required_unverified_count'] ?? 0) > 0;
$digitalSubmissionComplete = ape_digital_submission_complete($apeRecord ?? []);
$canUploadDocuments = $apeRecord
    && $clearanceStatus !== 'Cleared'
    && $apeStatus !== 'Cleared';
$nextActionTitle = match (true) {
    $clearanceStatus === 'Cleared' => 'APE completed',
    $apeQueue === 'digital_submission' && !$allRequiredDocumentsUploaded => 'Upload APE documents',
    $apeQueue === 'digital_submission' && !$examCompleted => 'Attend your scheduled examination',
    $apeQueue === 'digital_submission' => 'Wait for clinic document review',
    $apeQueue === 'follow_up' && !ape_document_follow_up($apeRecord) && !ape_deferred_submission_complete($apeRecord) => 'Submit follow-up documents for archive review',
    $requirementsNeedCorrection => 'Return corrected hard-copy requirements',
    $apeStatus === 'Follow-up Required' => 'Complete the required follow-up',
    $apeQueue === 'examination' => ape_examination_is_available($apeRecord) ? 'Attend examination' : 'Wait for your APE schedule',
    $documentsAwaitingReview => 'Wait for clinic document review',
    $apeStatus === 'Reviewed' => 'Wait for the final clinical decision',
    default => 'Upload verified APE documents',
};
$nextActionCopy = match (true) {
    $clearanceStatus === 'Cleared' => 'Your APE record is already cleared by the clinic.',
    $apeQueue === 'digital_submission' && !$allRequiredDocumentsUploaded => $examCompleted
        ? 'Complete your regular document uploads within seven days of examination. Follow-up documents use their separately assigned return date.'
        : 'Upload any available APE documents now. Missing files will not prevent you from attending your assigned examination.',
    $apeQueue === 'digital_submission' && !$examCompleted => 'Your files are ready for clinic comparison. Attend the assigned examination even if clinic review is still pending.',
    $apeQueue === 'digital_submission' => 'Your regular documents are waiting for clinic archive review. Follow-up documents keep their separately assigned return date.',
    $apeQueue === 'follow_up' && !ape_document_follow_up($apeRecord) && !ape_deferred_submission_complete($apeRecord) => 'Your initial documents are archived. Upload the returned documents by their assigned due date, then wait for clinic archive review.',
    $requirementsNeedCorrection => $studentNote ?: 'Return the corrected hard-copy requirements requested by the clinic.',
    $apeStatus === 'Follow-up Required' => $studentNote ?: 'Complete the referral or other follow-up requested by the clinic.',
    $apeQueue === 'examination' => ape_examination_is_available($apeRecord)
        ? "Attend {$apeRecord['batch_name']} now and bring any available hard-copy requirements."
        : 'Your digital files may be complete, but the clinic must still assign or open your examination schedule.',
    $documentsAwaitingReview => 'Your documents were submitted and are waiting for clinic archive review.',
    $apeStatus === 'Reviewed' => $studentNote ?: 'Your examination and document archive are complete. The clinic will now record the final decision.',
    default => $studentNote ?: ($apeRecord ? 'Complete the current APE step shown below.' : 'No APE record has been opened by the clinic yet.'),
};
$currentStep = $apeRecord ? ape_record_step_index($apeRecord) + 1 : 1;
$apePercent = $clearanceStatus === 'Cleared' ? 100 : (($digitalSubmissionComplete ? 25 : 0) + ($examCompleted ? 25 : 0));
$showFindings = $examCompleted;
$showDocuments = (bool) $apeRecord;
$showActivity = (bool) $apeRecord;
$headerBadge = $clearanceStatus === 'Cleared' ? 'student-badge-success' : ($actionNeeded ? 'student-badge-warning' : 'student-badge-info');
$actionBadgeLabel = match (true) {
    $apeQueue === 'digital_submission' => $documentsAwaitingReview ? 'Under Clinic Review' : 'Digital Keeping',
    $apeQueue === 'follow_up' => 'Follow-up Required',
    $requirementsNeedCorrection => 'Correction Needed',
    $apeStatus === 'Follow-up Required' => 'Follow-up Required',
    $apeQueue === 'examination' => ape_examination_is_available($apeRecord) ? 'Examination Now' : 'Waiting for Schedule',
    $documentsAwaitingReview => 'Under Clinic Review',
    $apeStatus === 'Reviewed' => 'Final Decision Pending',
    default => 'Current Step',
};

$flowSteps = [
    [
        'number' => 1,
        'icon' => 'cloud_upload',
        'title' => 'Digital document keeping',
        'copy' => $digitalSubmissionComplete
            ? 'All regular documents are uploaded and approved.'
            : ($examCompleted
                ? 'Complete regular uploads within seven days of examination.'
                : 'Upload available documents now; incomplete files will not block examination.'),
        'done' => $digitalSubmissionComplete,
        'current' => $apeQueue === 'digital_submission' && !ape_examination_is_available($apeRecord ?? []),
    ],
    [
        'number' => 2,
        'icon' => 'stethoscope',
        'title' => 'Examination',
        'copy' => $examCompleted
            ? 'Clinic recorded your examination and checked the hard copies you presented.'
            : ($hasScheduledBatch ? "Attend {$apeRecord['batch_name']} on {$batchScheduleLabel}, even if uploads are incomplete." : 'Wait for the clinic to assign your examination batch.'),
        'done' => $examCompleted,
        'current' => !$examCompleted && $apeQueue === 'examination',
    ],
    [
        'number' => 3,
        'icon' => 'medical_services',
        'title' => 'Final Decision or Follow-up',
        'copy' => 'The clinic clears the record or requests treatment, clearance, or referral follow-up.',
        'done' => $clearanceStatus === 'Cleared',
        'current' => !$examCompleted ? false : in_array($apeQueue, ['final_decision', 'follow_up'], true),
    ],
    [
        'number' => 4,
        'icon' => 'verified_user',
        'title' => 'Completed APE',
        'copy' => 'Your patient clinic record is cleared.',
        'done' => $clearanceStatus === 'Cleared',
        'current' => false,
    ],
];

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
foreach ($requirements as $requirement) {
    $name = $requirement['requirement_name'];
    $icon = $documentIcons[$name] ?? 'upload_file';
    $documentKey = 'r' . (int) $requirement['requirement_id'];
    $uploadedDocument = $latestDocumentByType[$name] ?? null;
    if ($uploadedDocument) {
        $verification = $uploadedDocument['verification_status'];
        $documents[] = [
            'name' => $name,
            'key' => $documentKey,
            'document_id' => (int) ($uploadedDocument['document_id'] ?? 0),
            'preview_url' => 'patient-ape-document.php?id=' . (int) ($uploadedDocument['document_id'] ?? 0),
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
            'status' => 'Ready to Upload',
            'badge' => 'student-badge-warning',
            'detail' => $requirement['remarks'] ?? (($requirement['upload_group'] ?? '') === 'follow_up' ? 'Provide the requested follow-up or correction document by its assigned due date.' : 'Hard copy checked. Upload one or more digital copies for clinic record keeping.'),
            'action' => 'Upload',
            'button' => 'student-button',
            'disabled' => false,
        ];
    } else {
        $lockedDetail = match (true) {
            $requirementsNeedCorrection => 'Bring the corrected hard copy back to the clinic before continuing.',
            default => 'Document upload is locked at the current APE step.',
        };
        $documents[] = [
            'name' => $name,
            'key' => $documentKey,
            'icon' => $icon,
            'status' => $requirement['status'] ?? ($requirementsNeedCorrection ? 'Needs Correction' : 'Awaiting Clinic Check'),
            'badge' => $requirementsNeedCorrection ? 'student-badge-danger' : 'student-badge-info',
            'detail' => $requirement['remarks'] ?? $lockedDetail,
            'action' => 'Locked',
            'button' => 'student-button-secondary student-button-disabled',
            'disabled' => true,
        ];
    }
}
$requirementByName = array_column($requirements, null, 'requirement_name');
foreach ($documents as &$documentCard) {
    $uploadRequirement = $requirementByName[$documentCard['name']];
    $documentCard['upload_group'] = $uploadRequirement['upload_group'] ?? null;
    $documentCard['upload_due_date'] = $uploadRequirement['upload_due_date'] ?? null;
}
unset($documentCard);
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
    <div class="student-note student-note-success student-toast" data-student-toast role="status" aria-live="polite">
        <span class="material-symbols-outlined">check_circle</span>
        <div><?= (int) $_GET['uploaded'] ?> APE document(s) were uploaded together and are waiting for clinic verification.</div>
        <button type="button" class="student-toast-dismiss" aria-label="Dismiss confirmation"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
    </div>
<?php elseif ($uploadError !== ''): ?>
    <div class="student-note student-note-danger mb-4">
        <span class="material-symbols-outlined">error</span>
        <div><?= student_e($uploadError) ?></div>
    </div>
<?php endif; ?>

<?php if ($hasScheduledBatch): ?>
    <section class="student-action-card student-ape-batch-card mb-4">
        <div class="flex items-start gap-4">
            <span class="student-icon-box">
                <span class="material-symbols-outlined">event_available</span>
            </span>
            <div>
                <p class="student-eyebrow mb-1">Your Current APE Batch</p>
                <h2><?= student_e($apeRecord['batch_name']) ?></h2>
                <p><?= student_e($batchScheduleLabel) ?></p>
            </div>
        </div>
        <span class="student-badge student-badge-info"><?= student_e($apeRecord['batch_patient_category']) ?></span>
    </section>
<?php endif; ?>

<section class="student-action-card student-ape-next-action mb-4">
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
            <?= student_e($actionBadgeLabel) ?>
        </span>
    <?php endif; ?>
</section>

<div class="student-grid student-ape-layout">
    <section class="student-card student-span-5">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">APE Flow</h2>
                <p class="student-card-copy">What happens to your record</p>
            </div>
            <span class="student-badge <?= student_e($headerBadge) ?>"><?= student_e($apeRecord ? ape_record_stage_label($apeRecord) : $apeStatus) ?></span>
        </div>
        <?php if ($hasScheduledBatch): ?>
            <div class="student-ape-mobile-batch">
                <span class="material-symbols-outlined">event_available</span>
                <div>
                    <span class="student-eyebrow">APE batch</span>
                    <strong><?= student_e($apeRecord['batch_name']) ?></strong>
                    <span><?= student_e($batchScheduleLabel) ?></span>
                </div>
                <span class="student-badge student-badge-info"><?= student_e($apeRecord['batch_patient_category']) ?></span>
            </div>
        <?php endif; ?>
        <div class="student-card-pad">
            <div class="flex items-end justify-between mb-4">
                <span class="text-xs font-black text-slate-500 uppercase tracking-wider">Completion</span>
                <strong class="font-headline text-3xl font-black text-[#17261d]"><?= (int) $apePercent ?>%</strong>
            </div>
            <div class="w-full h-3 rounded-full bg-primary-fixed overflow-hidden mb-5">
                <div class="h-full bg-primary rounded-full" style="width: <?= (int) $apePercent ?>%;"></div>
            </div>
            <div class="student-ape-stepper" aria-label="APE progress steps">
                <?php foreach ($flowSteps as $step): ?>
                    <?php
                    $stepNumber = (int) $step['number'];
                    $isDone = (bool) ($step['done'] ?? false);
                    $isCurrent = (bool) ($step['current'] ?? false) && !$isDone;
                    $stepClass = $isDone ? 'is-done' : ($isCurrent ? 'is-current' : 'is-locked');
                    $badgeClass = $isDone ? 'student-badge-success' : ($isCurrent ? 'student-badge-warning' : 'student-badge-info');
                    $badgeLabel = $isDone ? 'Done' : ($isCurrent ? 'Current' : 'Next');
                    $stepTitle = $step['title'];
                    $stepCopy = $step['copy'];
                    ?>
                    <div class="student-ape-step <?= student_e($stepClass) ?>">
                        <span class="student-ape-step-rail" aria-hidden="true"></span>
                        <span class="student-ape-step-index">
                            <span class="material-symbols-outlined"><?= student_e($isDone ? 'check' : ($isCurrent ? 'pending_actions' : $step['icon'])) ?></span>
                        </span>
                        <div class="student-ape-step-body" data-mobile-step-label="<?= student_e($step['title']) ?>">
                            <div class="student-ape-step-top">
                                <span class="student-ape-step-count">Step <?= (int) $stepNumber ?> of 4</span>
                                <span class="student-badge <?= student_e($badgeClass) ?>"><?= student_e($badgeLabel) ?></span>
                            </div>
                            <strong><?= student_e($stepTitle) ?></strong>
                            <span><?= student_e($stepCopy) ?></span>
                            <?php if ($isCurrent && $canUploadDocuments): ?>
                                <a class="student-ape-step-action" data-mobile-open-panel="ape-documents-panel" href="#ape-documents-panel">Upload APE documents <span class="material-symbols-outlined">arrow_downward</span></a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <div class="student-span-7 grid gap-4 student-ape-secondary-panels">
    <?php if ($showFindings): ?>
    <details class="patient-mobile-panel" data-mobile-accordion>
    <summary>Clinic findings and follow-up</summary>
    <section class="student-card">
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
    </details>
    <?php endif; ?>

    </div>

    <?php if ($showDocuments): ?>
    <details class="patient-mobile-panel student-span-12" data-mobile-accordion id="ape-documents-panel">
    <summary>Required documents</summary>
    <section class="student-card">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Required Documents</h2>
                <p class="student-card-copy">Upload only documents already checked by the clinic. PDF, JPG/JPEG, and PNG files are allowed, up to 2 MB each.</p>
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
            <div class="student-document-mobile-list" aria-label="Required documents">
                <?php foreach ($documents as $doc): ?>
                    <details class="student-document-mobile-row">
                        <summary>
                            <span class="student-icon-box"><span class="material-symbols-outlined"><?= student_e($doc['icon']) ?></span></span>
                            <span class="student-document-mobile-copy">
                                <strong><?= student_e($doc['name']) ?></strong>
                                <span id="ape-mobile-row-state-<?= student_e($doc['key']) ?>" data-default-label="<?= student_e($doc['upload_due_date'] ? 'Due ' . date('M j, Y', strtotime($doc['upload_due_date'])) : '') ?>"<?= $doc['upload_due_date'] ? '' : ' class="hidden"' ?>><?= $doc['upload_due_date'] ? 'Due ' . student_e(date('M j, Y', strtotime($doc['upload_due_date']))) : '' ?></span>
                            </span>
                            <span class="student-badge <?= student_e($doc['badge']) ?>" id="ape-mobile-status-<?= student_e($doc['key']) ?>" data-default-status="<?= student_e($doc['status']) ?>" data-default-badge="<?= student_e($doc['badge']) ?>"><?= student_e($doc['status']) ?></span>
                            <span class="material-symbols-outlined student-document-mobile-chevron" aria-hidden="true">expand_more</span>
                        </summary>
                        <div class="student-document-mobile-detail">
                            <p><?= student_e($doc['detail']) ?></p>
                            <?php if (!$doc['disabled']): ?><p class="student-document-mobile-staged hidden" id="ape-mobile-file-name-<?= student_e($doc['key']) ?>"></p><?php endif; ?>
                            <?php if ($doc['disabled']): ?>
                                <?php if (!empty($doc['document_id'])): ?><a class="student-button-secondary text-decoration-none" href="<?= student_e($doc['preview_url']) ?>" data-file-preview data-preview-title="<?= student_e($doc['name']) ?>"><span class="material-symbols-outlined">visibility</span> View document</a><?php endif; ?>
                             <?php else: ?>
                                 <button class="<?= student_e($doc['button']) ?>" type="button" onclick="selectApeFile('<?= student_e($doc['key']) ?>')"><span class="material-symbols-outlined">upload</span> Choose file</button>
                                 <button class="student-button-secondary ape-mobile-remove-file hidden" id="ape-mobile-remove-<?= student_e($doc['key']) ?>" type="button" onclick="removeApeFile('<?= student_e($doc['key']) ?>')"><span class="material-symbols-outlined">delete</span> Remove selected file(s)</button>
                             <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
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
                            <p class="student-document-detail"><?= student_e($doc['detail']) ?></p>
                            <?php if ($doc['upload_due_date']): ?>
                                <p class="student-document-due font-bold"><?= $doc['upload_group'] === 'initial' ? 'Initial upload' : 'Follow-up / correction upload' ?> due <?= student_e(date('M j, Y', strtotime($doc['upload_due_date']))) ?></p>
                            <?php endif; ?>
                            <?php if (!$doc['disabled']): ?>
                                <p class="ape-staged-file-name hidden" id="ape-file-name-<?= student_e($doc['key']) ?>"></p>
                            <?php endif; ?>
                        </div>
                        <details class="patient-document-more">
                            <summary>Actions</summary>
                        <?php if ($doc['disabled']): ?>
                            <div class="student-appointment-actions">
                                <?php if (!empty($doc['document_id'])): ?>
                                    <a class="student-button-secondary text-decoration-none" href="<?= student_e($doc['preview_url']) ?>" data-file-preview data-preview-title="<?= student_e($doc['name']) ?>">
                                        <span class="material-symbols-outlined">visibility</span> Preview
                                    </a>
                                <?php endif; ?>
                                <button class="<?= student_e($doc['button']) ?>" type="button" disabled>
                                    <span class="material-symbols-outlined">lock</span>
                                    <?= student_e($doc['action']) ?>
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="student-appointment-actions ape-document-actions" data-document-key="<?= student_e($doc['key']) ?>">
                                <input class="hidden ape-document-input" type="file" name="documents[<?= student_e($doc['key']) ?>][]" id="ape-file-<?= student_e($doc['key']) ?>" accept=".pdf,.png,.jpg,.jpeg" data-document-name="<?= student_e($doc['name']) ?>" onchange="handleApeFileSelected(this)" multiple>
                                <button class="<?= student_e($doc['button']) ?> ape-select-file" type="button" onclick="selectApeFile('<?= student_e($doc['key']) ?>')">
                                    <span class="material-symbols-outlined">upload</span>
                                    <span><?= $doc['action'] === 'Replace' ? 'Select Replacement Files' : 'Select Files' ?></span>
                                </button>
                                <button class="student-button-secondary ape-remove-file hidden" type="button" onclick="removeApeFile('<?= student_e($doc['key']) ?>')">
                                    <span class="material-symbols-outlined">delete</span>
                                    Remove
                                </button>
                            </div>
                        <?php endif; ?>
                        </details>
                    </div>
                <?php endforeach; ?>
                <?php if (!$documents): ?>
                    <div class="student-note student-note-warning">
                        <span class="material-symbols-outlined">info</span>
                        <div>No APE requirements have been listed by the clinic yet.</div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($uploadableDocumentCount > 0): ?>
                <div class="ape-batch-submit-bar">
                    <div>
                        <strong id="ape-selected-summary">No files selected</strong>
                        <span>Select files first. You can change or remove them before submitting. Each PDF, JPG/JPEG, or PNG file must be 2 MB or smaller.</span>
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
    </details>
    <?php endif; ?>
</div>

<div id="ape-upload-confirm-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
    <div class="student-card w-full max-w-md p-6 shadow-xl">
        <div class="flex items-start gap-4 mb-6">
            <span class="student-icon-box">
                <span class="material-symbols-outlined">cloud_upload</span>
            </span>
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-2">Submit selected APE documents?</h2>
                <p class="text-sm font-bold text-slate-500 mb-0">The selected PDF/image files will be uploaded together and sent to the clinic for review.</p>
            </div>
        </div>
        <div class="flex flex-col sm:flex-row justify-end gap-3">
            <button type="button" class="student-button-secondary justify-center" id="ape-cancel-upload-confirm">Cancel</button>
            <button type="button" class="student-button justify-center" id="ape-confirm-upload-submit">
                <span class="material-symbols-outlined">check</span>
                Confirm Upload
            </button>
        </div>
    </div>
</div>

<?php if ($showActivity): ?>
<details class="patient-mobile-panel ape-activity-panel" data-mobile-accordion>
    <summary>APE activity timeline</summary>
<section class="student-card mt-4" id="ape-activity-timeline">
    <div class="student-card-header">
        <div>
            <h2 class="student-card-title">APE Activity Timeline</h2>
            <p class="student-card-copy">Actions recorded for this APE case.</p>
        </div>
        <span class="student-badge student-badge-info"><?= count($allActivities) ?> Event(s)</span>
    </div>
    <div class="student-card-pad grid gap-3" aria-live="polite">
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
        <?php if ($historyTotalPages > 1): ?>
            <nav class="student-pagination" aria-label="APE activity pages">
                <?php if ($historyPage > 1): ?>
                    <a
                        class="student-pagination-link student-pagination-arrow"
                        href="?ape_history_page=<?= (int) ($historyPage - 1) ?>#ape-activity-timeline"
                        data-ape-history-page="<?= (int) ($historyPage - 1) ?>"
                        aria-label="Previous activity page"
                    ><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></a>
                <?php else: ?>
                    <span class="student-pagination-link student-pagination-arrow is-disabled" aria-disabled="true">
                        <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                    </span>
                <?php endif; ?>
                <?php for ($page = 1; $page <= $historyTotalPages; $page++): ?>
                    <a
                        class="student-pagination-link <?= $page === $historyPage ? 'is-active' : '' ?>"
                        href="?ape_history_page=<?= (int) $page ?>#ape-activity-timeline"
                        data-ape-history-page="<?= (int) $page ?>"
                        <?= $page === $historyPage ? 'aria-current="page"' : '' ?>
                    ><?= (int) $page ?></a>
                <?php endfor; ?>
                <?php if ($historyPage < $historyTotalPages): ?>
                    <a
                        class="student-pagination-link student-pagination-arrow"
                        href="?ape_history_page=<?= (int) ($historyPage + 1) ?>#ape-activity-timeline"
                        data-ape-history-page="<?= (int) ($historyPage + 1) ?>"
                        aria-label="Next activity page"
                    ><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></a>
                <?php else: ?>
                    <span class="student-pagination-link student-pagination-arrow is-disabled" aria-disabled="true">
                        <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                    </span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</section>
</details>
<?php endif; ?>

<script>
    function selectApeFile(documentKey) {
        document.getElementById(`ape-file-${documentKey}`).click();
    }

    function handleApeFileSelected(input) {
        const maxFileSize = 2 * 1024 * 1024;
        const allowedTypes = { pdf: 'application/pdf', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png' };
        const selectedFiles = Array.from(input.files || []);
        const oversizedFile = selectedFiles.find((file) => file.size > maxFileSize);
        if (oversizedFile) {
            window.alert(`${oversizedFile.name} is larger than 2 MB. Please choose files that are 2 MB or smaller.`);
            input.value = '';
            updateApeFileRow(input);
            refreshApeBatchSummary();
            return;
        }
        const invalidFile = selectedFiles.find((file) => {
            const extension = file.name.split('.').pop()?.toLowerCase() || '';
            return !allowedTypes[extension] || (file.type && file.type !== allowedTypes[extension]);
        });
        if (invalidFile) {
            window.alert(`${invalidFile.name} is not a valid file type. Choose a PDF, JPG/JPEG, or PNG file.`);
            input.value = '';
            updateApeFileRow(input);
            refreshApeBatchSummary();
            return;
        }
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
        const mobileFilename = document.getElementById(`ape-mobile-file-name-${documentKey}`);
        const mobileRowState = document.getElementById(`ape-mobile-row-state-${documentKey}`);
        const mobileStatus = document.getElementById(`ape-mobile-status-${documentKey}`);
        const mobileRemoveButton = document.getElementById(`ape-mobile-remove-${documentKey}`);
        const selectLabel = actions.querySelector('.ape-select-file span:last-child');
        const removeButton = actions.querySelector('.ape-remove-file');
        const selectedFiles = input.files ? Array.from(input.files) : [];

        if (selectedFiles.length > 0) {
            filename.replaceChildren();
            const label = document.createElement('span');
            label.textContent = `Selected (${selectedFiles.length}):`;
            filename.appendChild(label);
            selectedFiles.forEach((file) => {
                const previewButton = document.createElement('button');
                previewButton.type = 'button';
                previewButton.className = 'ape-staged-file-preview';
                previewButton.dataset.filePreview = '';
                previewButton.dataset.previewUrl = URL.createObjectURL(file);
                previewButton.dataset.previewTitle = file.name;
                previewButton.dataset.previewType = file.type.startsWith('image/') ? 'image' : (file.type === 'application/pdf' ? 'pdf' : 'file');
                previewButton.textContent = file.name;
                filename.appendChild(previewButton);
            });
            filename.classList.remove('hidden');
            if (mobileFilename) { mobileFilename.textContent = `${selectedFiles.length} file${selectedFiles.length === 1 ? '' : 's'} selected`; mobileFilename.classList.remove('hidden'); }
            if (mobileRowState) { mobileRowState.textContent = mobileRowState.dataset.defaultLabel || ''; mobileRowState.classList.toggle('hidden', !mobileRowState.dataset.defaultLabel); mobileRowState.classList.remove('is-staged'); }
            if (mobileStatus) { mobileStatus.textContent = `${selectedFiles.length} file${selectedFiles.length === 1 ? '' : 's'} attached`; mobileStatus.className = 'student-badge student-badge-success'; }
            selectLabel.textContent = 'Change Files';
            removeButton.classList.remove('hidden');
            mobileRemoveButton?.classList.remove('hidden');
        } else {
            filename.replaceChildren();
            filename.classList.add('hidden');
            if (mobileFilename) { mobileFilename.textContent = ''; mobileFilename.classList.add('hidden'); }
            if (mobileRowState) { mobileRowState.textContent = mobileRowState.dataset.defaultLabel || ''; mobileRowState.classList.toggle('hidden', !mobileRowState.dataset.defaultLabel); mobileRowState.classList.remove('is-staged'); }
            if (mobileStatus) { mobileStatus.textContent = mobileStatus.dataset.defaultStatus || ''; mobileStatus.className = `student-badge ${mobileStatus.dataset.defaultBadge || ''}`; }
            selectLabel.textContent = 'Select Files';
            removeButton.classList.add('hidden');
            mobileRemoveButton?.classList.add('hidden');
        }
    }

    function refreshApeBatchSummary() {
        const selected = Array.from(document.querySelectorAll('.ape-document-input'))
            .filter((input) => input.files && input.files.length > 0);
        const count = selected.reduce((total, input) => total + input.files.length, 0);
        const submitButton = document.getElementById('ape-submit-all');
        const summary = document.getElementById('ape-selected-summary');
        const countLabel = document.getElementById('ape-selected-count');
        if (!submitButton || !summary || !countLabel) return;
        const submitBar = submitButton.closest('.ape-batch-submit-bar');

        const requiredInputs = Array.from(document.querySelectorAll('.ape-document-input'));
        const isComplete = requiredInputs.length > 0 && requiredInputs.every((input) => input.files && input.files.length > 0);
        submitButton.disabled = !isComplete;
        if (submitBar) submitBar.classList.toggle('is-staged', count > 0);
        summary.textContent = count === 0 ? 'Select every required document' : `${count} file${count === 1 ? '' : 's'} selected across ${requiredInputs.filter((input) => input.files && input.files.length > 0).length} of ${requiredInputs.length} requirements`;
        countLabel.textContent = count === 0 ? '' : `(${count})`;
    }

    document.querySelectorAll('.student-document-mobile-row').forEach((row) => {
        row.addEventListener('toggle', () => {
            if (!row.open) return;
            document.querySelectorAll('.student-document-mobile-row[open]').forEach((other) => {
                if (other !== row) other.open = false;
            });
        });
    });

    const apeUploadForm = document.getElementById('ape-batch-upload-form');
    const apeUploadConfirmModal = document.getElementById('ape-upload-confirm-modal');
    const apeCancelUploadConfirm = document.getElementById('ape-cancel-upload-confirm');
    const apeConfirmUploadSubmit = document.getElementById('ape-confirm-upload-submit');

    function openApeUploadConfirm() {
        if (!apeUploadConfirmModal) return;
        apeUploadConfirmModal.classList.remove('hidden');
        apeUploadConfirmModal.classList.add('flex');
        apeConfirmUploadSubmit?.focus();
    }

    function closeApeUploadConfirm() {
        if (!apeUploadConfirmModal) return;
        apeUploadConfirmModal.classList.add('hidden');
        apeUploadConfirmModal.classList.remove('flex');
    }

    apeCancelUploadConfirm?.addEventListener('click', closeApeUploadConfirm);
    apeUploadConfirmModal?.addEventListener('click', (event) => {
        if (event.target === apeUploadConfirmModal) {
            closeApeUploadConfirm();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeApeUploadConfirm();
        }
    });
    apeConfirmUploadSubmit?.addEventListener('click', () => {
        if (!apeUploadForm) return;
        apeUploadForm.dataset.uploadConfirmed = '1';
        closeApeUploadConfirm();
        if (typeof apeUploadForm.requestSubmit === 'function') {
            apeUploadForm.requestSubmit();
        } else {
            apeUploadForm.submit();
        }
    });

    apeUploadForm?.addEventListener('submit', (event) => {
        const submitButton = document.getElementById('ape-submit-all');
        if (!submitButton || submitButton.disabled) {
            event.preventDefault();
            return;
        }
        if (apeUploadForm.dataset.uploadConfirmed !== '1') {
            event.preventDefault();
            openApeUploadConfirm();
            return;
        }
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="material-symbols-outlined">progress_activity</span> Uploading Documents...';
    });

    let apeHistoryRequest = null;

    async function loadApeHistoryPage(url, updateBrowserHistory = true) {
        const timeline = document.getElementById('ape-activity-timeline');
        if (!timeline || timeline.classList.contains('is-loading')) return;

        if (apeHistoryRequest) {
            apeHistoryRequest.abort();
        }
        apeHistoryRequest = new AbortController();
        timeline.classList.add('is-loading');
        timeline.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: apeHistoryRequest.signal,
            });
            if (!response.ok) throw new Error('Unable to load the requested activity page.');

            const html = await response.text();
            const page = new DOMParser().parseFromString(html, 'text/html');
            const replacement = page.getElementById('ape-activity-timeline');
            if (!replacement) throw new Error('Activity timeline was missing from the response.');

            timeline.replaceWith(replacement);
            if (updateBrowserHistory) {
                const nextUrl = new URL(url, window.location.href);
                nextUrl.hash = 'ape-activity-timeline';
                window.history.pushState({ apeHistoryPage: true }, '', nextUrl);
            }

            replacement.querySelector('[aria-current="page"]')?.focus({ preventScroll: true });
        } catch (error) {
            if (error.name !== 'AbortError') {
                window.location.href = url;
            }
        } finally {
            const currentTimeline = document.getElementById('ape-activity-timeline');
            currentTimeline?.classList.remove('is-loading');
            currentTimeline?.removeAttribute('aria-busy');
            apeHistoryRequest = null;
        }
    }

    document.addEventListener('click', (event) => {
        const pageLink = event.target.closest('[data-ape-history-page]');
        if (!pageLink || !pageLink.closest('#ape-activity-timeline')) return;
        event.preventDefault();
        loadApeHistoryPage(pageLink.href);
    });

    window.addEventListener('popstate', () => {
        loadApeHistoryPage(window.location.href, false);
    });
</script>

<?php render_student_footer(); ?>
