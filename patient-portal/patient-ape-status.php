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
        foreach ($uploadRequirements as $uploadRequirement) {
            $documentTypesByKey['r' . (int) $uploadRequirement['requirement_id']] = $uploadRequirement['requirement_name'];
        }
        if (!$documentTypesByKey) {
            throw new RuntimeException('The clinic has not listed any APE requirements for upload yet.');
        }

        $latestExistingByType = [];
        foreach (ape_documents_for_record((int) $apeRecord['ape_id']) as $existingDocument) {
            $latestExistingByType[$existingDocument['document_type']] ??= $existingDocument;
        }
        $batchFiles = $_FILES['documents'] ?? [];
        $maxFilesPerRequirement = 3;
        foreach ($documentTypesByKey as $documentKey => $documentType) {
            $selectedErrors = $batchFiles['error'][$documentKey] ?? [];
            $selectedErrors = is_array($selectedErrors) ? $selectedErrors : [$selectedErrors];
            $selectedCount = count(array_filter($selectedErrors, static fn($error): bool => (int) $error !== UPLOAD_ERR_NO_FILE));
            if ($selectedCount > $maxFilesPerRequirement) {
                throw new InvalidArgumentException(sprintf(
                    '%s has %d files selected. A maximum of %d files is allowed per requirement; combine additional pages into one PDF before uploading.',
                    $documentType,
                    $selectedCount,
                    $maxFilesPerRequirement
                ));
            }
        }
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
                    'file' => ape_store_uploaded_file($file, (string) ($apeRecord['id_number'] ?? ''), $documentType),
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
                // A new file supersedes the returned version for review while
                // keeping the existing Follow-up group and audit history.
                $requirement->execute([(int) $apeRecord['ape_id'], $documentType]);
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
$missingDocumentCount = 0;
$missingDocumentNames = [];
$requirementStatus = $apeRecord['requirement_status'] ?? 'Not Checked';
$hasScheduledBatch = !empty($apeRecord['schedule_batch_id']) && ($apeRecord['batch_status'] ?? '') === 'Scheduled';
$actionNeeded = $clearanceStatus !== 'Cleared' && $apeStatus !== 'Not Started';
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
$documentsNeedCorrection = ($apeRecord['verification_status'] ?? '') === 'Needs Correction';
$studentProgress = ape_student_progress($apeRecord ?? []);
$actionableFollowUpUploads = array_values(array_filter($requirements, static function (array $requirement): bool {
    if (($requirement['upload_group'] ?? '') !== 'follow_up') {
        return false;
    }
    $latestStatus = $requirement['_latest_document']['verification_status'] ?? null;
    return $latestStatus === null || $latestStatus === 'Needs Correction';
}));
$actionablePostExamReplacements = array_values(array_filter($requirements, static function (array $requirement): bool {
    return ($requirement['_latest_document']['verification_status'] ?? null) === 'Needs Correction';
}));
$stepThreeDocumentUploadRequired = $examCompleted
    && $clearanceStatus !== 'Cleared'
    && $apeStatus !== 'Cleared'
    && ($actionableFollowUpUploads !== [] || $actionablePostExamReplacements !== []);
$studentDocumentsStillNeeded = $examCompleted
    && (!ape_initial_uploads_present($apeRecord ?? []) || ape_follow_up_document_request_active($apeRecord ?? []));
$canUploadDocuments = $apeRecord
    && $clearanceStatus !== 'Cleared'
    && $apeStatus !== 'Cleared';
$nextActionTitle = match (true) {
    $clearanceStatus === 'Cleared' => 'APE completed',
    $documentsNeedCorrection => 'Replace returned APE documents',
    $stepThreeDocumentUploadRequired => 'Submit required follow-up documents',
    $studentDocumentsStillNeeded => 'Submit outstanding APE documents',
    $studentProgress['active_step'] === 1 => 'Upload APE documents',
    $apeQueue === 'follow_up' && (int) ($apeRecord['deferred_requirement_count'] ?? 0) > 0 && !ape_deferred_submission_complete($apeRecord) => 'Submit follow-up documents for clinic review',
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
    $documentsNeedCorrection => $studentNote ?: 'The clinic returned one or more documents. Upload the requested replacement files to continue.',
    $stepThreeDocumentUploadRequired => $studentNote ?: 'The clinic requested one or more follow-up documents. Submit the required files below; your APE remains in Final Decision or Follow-up while the clinic reviews them.',
    $studentDocumentsStillNeeded => $studentNote ?: 'The clinic still needs one or more initial or follow-up documents. Upload the outstanding files below; your APE stays in Final Decision or Follow-up while the clinic reviews them.',
    $studentProgress['active_step'] === 1 => $studentNote ?: 'Complete your regular document uploads before the clinic can finish your APE decision.',
    $apeQueue === 'follow_up' && (int) ($apeRecord['deferred_requirement_count'] ?? 0) > 0 && !ape_deferred_submission_complete($apeRecord) => $studentNote ?: 'The clinic requested additional follow-up documents. Upload them below; your APE remains in Follow-up until the clinic reviews them.',
    $studentProgress['active_step'] === 3 => 'Your documents and examination are complete. The clinic is reviewing your record now; no action is needed from you.',
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
$currentStep = $studentProgress['active_step'];
$apePercent = $studentProgress['percent'];
$showFindings = $examCompleted;
$showDocuments = (bool) $apeRecord;
$showActivity = (bool) $apeRecord;
$headerBadge = $clearanceStatus === 'Cleared' ? 'student-badge-success' : ($actionNeeded ? 'student-badge-warning' : 'student-badge-info');
$actionBadgeLabel = match (true) {
    $documentsNeedCorrection => 'Correction Needed',
    $studentDocumentsStillNeeded => 'Documents Needed',
    $studentProgress['active_step'] === 1 => 'Digital Keeping',
    $apeQueue === 'follow_up' && (int) ($apeRecord['deferred_requirement_count'] ?? 0) > 0 && !ape_deferred_submission_complete($apeRecord) => 'Follow-up Documents Needed',
    $studentProgress['active_step'] === 3 => 'Under Clinic Review',
    $studentProgress['active_step'] === 4 => 'Completed',
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
        'copy' => $documentsNeedCorrection
            ? 'The clinic returned one or more documents. Upload the requested replacements to continue.'
            : ($studentProgress['steps'][1]['submitted']
                ? 'All regular documents are submitted. The clinic will review them before finalizing your APE.'
                : ($digitalSubmissionComplete
                    ? 'All regular documents are uploaded and approved.'
            : ($examCompleted
                ? 'Complete regular uploads within seven days of examination.'
                : 'Upload available documents now; incomplete files will not prevent attendance during your assigned examination schedule.'))),
        'done' => $studentProgress['steps'][1]['done'],
        'submitted' => $studentProgress['steps'][1]['submitted'],
        'current' => $studentProgress['steps'][1]['active'],
    ],
    [
        'number' => 2,
        'icon' => 'stethoscope',
        'title' => 'Examination',
        'copy' => $examCompleted
            ? 'Clinic recorded your examination and checked the hard copies you presented.'
            : "Attend {$apeRecord['batch_name']} on {$batchScheduleLabel}, even if uploads are incomplete.",
        'done' => $studentProgress['steps'][2]['done'],
        'submitted' => false,
        'current' => $studentProgress['steps'][2]['active'],
    ],
    [
        'number' => 3,
        'icon' => 'medical_services',
        'title' => 'Final Decision or Follow-up',
        'copy' => $studentProgress['steps'][3]['active']
            ? ($stepThreeDocumentUploadRequired
                ? 'The clinic requested document follow-up. Submit the required files below, then the clinic will review them.'
                : 'The clinic is reviewing your documents and examination. No action is needed from you right now.')
            : 'The clinic clears the record or requests treatment, clearance, or referral follow-up.',
        'done' => $studentProgress['steps'][3]['done'],
        'submitted' => false,
        'current' => $studentProgress['steps'][3]['active'],
    ],
    [
        'number' => 4,
        'icon' => 'verified_user',
        'title' => $studentProgress['steps'][4]['active'] && !$studentProgress['steps'][4]['done'] ? 'Follow-up clearance' : 'Completed APE',
        'copy' => $studentProgress['steps'][4]['active'] && !$studentProgress['steps'][4]['done']
            ? 'Complete the follow-up requirements requested by the clinic before final clearance.'
            : 'Your patient clinic record is cleared.',
        'done' => $studentProgress['steps'][4]['done'],
        'submitted' => false,
        'current' => $studentProgress['steps'][4]['active'],
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
        $fileExtension = strtolower(pathinfo((string) ($uploadedDocument['original_filename'] ?? ''), PATHINFO_EXTENSION));
        $previewType = in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true)
            ? 'image'
            : ($fileExtension === 'pdf' ? 'pdf' : 'file');
        $documents[] = [
            'name' => $name,
            'key' => $documentKey,
            'document_id' => (int) ($uploadedDocument['document_id'] ?? 0),
            'preview_url' => 'patient-ape-document.php?id=' . (int) ($uploadedDocument['document_id'] ?? 0),
            'preview_type' => $previewType,
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
$studentActionStatuses = ['Ready to Upload', 'Needs Correction'];
$studentActionDocuments = array_values(array_filter(
    $documents,
    static fn(array $document): bool => in_array((string) ($document['status'] ?? ''), $studentActionStatuses, true)
));
$missingDocumentCount = count($studentActionDocuments);
$missingDocumentNames = array_values(array_map(
    static fn(array $document): string => (string) ($document['name'] ?? ''),
    $studentActionDocuments
));
$missingItemsForDisplay = $missingDocumentNames !== []
    ? implode(', ', $missingDocumentNames)
    : $missingItems;
$uploadableDocumentCount = count(array_filter($documents, static fn(array $document): bool => !$document['disabled']));
render_student_header('APE Status', 'ape');
?>

<section class="student-page-header">
    <div>
        <p class="student-eyebrow">Annual Physical Examination</p>
        <h1 class="student-title">APE Status</h1>
        <p class="student-subtitle">Track your requirements, documents, and clinic updates.</p>
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

<section class="student-ape-desktop-summary" aria-label="APE status at a glance">
    <article class="student-card student-ape-summary-card">
        <span class="student-label">Completion</span>
        <strong class="student-ape-summary-value"><?= (int) $apePercent ?>%</strong>
        <div class="student-ape-summary-progress" aria-hidden="true"><span style="width: <?= (int) $apePercent ?>%;"></span></div>
        <span class="student-ape-summary-copy">Step <?= max(1, (int) $currentStep) ?> of 4</span>
    </article>
    <article class="student-card student-ape-summary-card">
        <span class="student-label">Batch schedule</span>
        <?php if ($hasScheduledBatch): ?>
            <strong class="student-ape-summary-value student-ape-summary-value-text"><?= student_e(date('M j, Y', strtotime((string) $apeRecord['batch_schedule_date']))) ?></strong>
            <span class="student-ape-summary-copy"><?= student_e(date('g:i A', strtotime((string) $apeRecord['batch_start_time']))) ?>–<?= student_e(date('g:i A', strtotime((string) $apeRecord['batch_end_time']))) ?> · <?= student_e($apeRecord['batch_patient_category']) ?></span>
        <?php else: ?>
            <strong class="student-ape-summary-value student-ape-summary-value-text">Not scheduled</strong>
            <span class="student-ape-summary-copy">The clinic will assign your examination schedule.</span>
        <?php endif; ?>
    </article>
</section>

<div class="student-grid student-ape-layout">
    <section class="student-card student-span-5 student-ape-flow-card">
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
            <div class="student-ape-flow-completion">
                <div class="flex items-end justify-between mb-4">
                    <span class="text-xs font-black text-slate-500 uppercase tracking-wider">Completion</span>
                    <strong class="font-headline text-3xl font-black text-[#17261d]"><?= (int) $apePercent ?>%</strong>
                </div>
                <div class="w-full h-3 rounded-full bg-primary-fixed overflow-hidden mb-5">
                    <div class="h-full bg-primary rounded-full" style="width: <?= (int) $apePercent ?>%;"></div>
                </div>
            </div>
            <div class="student-ape-stepper" aria-label="APE progress steps">
                <?php foreach ($flowSteps as $step): ?>
                    <?php
                    $stepNumber = (int) $step['number'];
                    $isDone = (bool) ($step['done'] ?? false);
                    $isSubmitted = (bool) ($step['submitted'] ?? false);
                    $isCurrent = (bool) ($step['current'] ?? false) && !$isDone;
                    $isInProgress = $isSubmitted && !$isDone;
                    $uploadsRemainOpen = $stepNumber === 1 && !$isDone && $studentProgress['active_step'] === 2;
                    $isActive = $isCurrent;
                    $stepClass = $isDone ? 'is-done' : ($isActive ? 'is-current' : 'is-locked');
                    $badgeClass = ($isDone || $isSubmitted) ? 'student-badge-success' : ($isActive ? 'student-badge-warning' : 'student-badge-info');
                    $badgeLabel = $isSubmitted ? 'Submitted' : ($isDone ? 'Done' : ($isActive ? ($stepNumber === 1 ? 'In Progress' : 'Current') : ($uploadsRemainOpen ? 'Uploads open' : 'Next')));
                    $stepTitle = $step['title'];
                    $stepCopy = $step['copy'];
                    ?>
                    <div class="student-ape-step <?= student_e($stepClass) ?>">
                        <span class="student-ape-step-rail" aria-hidden="true"></span>
                        <span class="student-ape-step-index">
                            <span class="material-symbols-outlined"><?= student_e($isDone ? 'check' : (($isCurrent || $isInProgress) ? 'pending_actions' : $step['icon'])) ?></span>
                        </span>
                        <div class="student-ape-step-body" data-mobile-step-label="<?= student_e($step['title']) ?>">
                            <div class="student-ape-step-top">
                                <span class="student-ape-step-count">Step <?= (int) $stepNumber ?> of 4</span>
                                <span class="student-badge <?= student_e($badgeClass) ?>"><?= student_e($badgeLabel) ?></span>
                            </div>
                            <strong><?= student_e($stepTitle) ?></strong>
                            <span><?= student_e($stepCopy) ?></span>
                            <?php if ($isActive && $stepNumber === 1 && $canUploadDocuments): ?>
                                <a class="student-ape-step-action" data-mobile-open-panel="ape-documents-panel" href="#ape-documents-panel">Upload APE documents <span class="material-symbols-outlined">arrow_downward</span></a>
                            <?php endif; ?>
                            <?php if ($isActive && $stepNumber === 3 && $stepThreeDocumentUploadRequired): ?>
                                <a class="student-ape-step-action" data-mobile-open-panel="ape-documents-panel" href="#ape-documents-panel">Submit required documents <span class="material-symbols-outlined">arrow_downward</span></a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <div class="student-span-7 grid gap-4 student-ape-secondary-panels">
    <?php if ($showDocuments): ?>
    <details class="patient-mobile-panel" data-mobile-accordion id="ape-documents-panel">
    <summary>Required documents</summary>
    <section class="student-card">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Required Documents</h2>
                <p class="student-card-copy">PDF, JPG/JPEG, or PNG · up to 2 MB per file</p>
            </div>
            <span class="student-badge <?= student_e($headerBadge) ?>"><?= student_e($clearanceStatus) ?></span>
        </div>
        <div class="student-card-pad">
            <form method="post" enctype="multipart/form-data" id="ape-batch-upload-form" data-no-loading>
                <input type="hidden" name="action" value="upload_ape_documents">
                <p class="ape-document-guidance"><span class="material-symbols-outlined" aria-hidden="true">lock</span><span>Upload clinic-requested files only. <a href="<?= student_e(student_legal_url('privacy')) ?>" target="_blank" rel="noopener" class="student-auth-link">Privacy Notice</a></span></p>
            <?php if ($missingDocumentCount > 0): ?>
                <details class="ape-missing-documents mb-4">
                    <summary><span class="material-symbols-outlined" aria-hidden="true">info</span><span><?= student_e((string) $missingDocumentCount) ?> document<?= $missingDocumentCount === 1 ? '' : 's' ?> still needed</span><span class="material-symbols-outlined ape-missing-documents-chevron" aria-hidden="true">expand_more</span></summary>
                    <p><?= student_e($missingItemsForDisplay) ?></p>
                </details>
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
                                <?php if (!empty($doc['document_id'])): ?><a class="student-button-secondary text-decoration-none" href="<?= student_e($doc['preview_url']) ?>" data-file-preview data-preview-type="<?= student_e($doc['preview_type']) ?>" data-preview-title="<?= student_e($doc['name']) ?>"><span class="material-symbols-outlined">visibility</span> View document</a><?php endif; ?>
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
                                <span class="student-badge <?= student_e($doc['badge']) ?>" id="ape-desktop-status-<?= student_e($doc['key']) ?>" data-default-status="<?= student_e($doc['status']) ?>" data-default-badge="<?= student_e($doc['badge']) ?>"><?= student_e($doc['status']) ?></span>
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
                                    <a class="student-button-secondary text-decoration-none" href="<?= student_e($doc['preview_url']) ?>" data-file-preview data-preview-type="<?= student_e($doc['preview_type']) ?>" data-preview-title="<?= student_e($doc['name']) ?>">
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
                        <span>Select up to 3 files per requirement. Images are optimized in your browser before upload; PDFs must be 2 MB or smaller.</span>
                    </div>
                    <button class="student-button" id="ape-submit-all" type="submit" disabled>
                        <span class="material-symbols-outlined">cloud_upload</span>
                        Submit All Documents <span id="ape-selected-count"></span>
                    </button>
                    <div id="ape-upload-progress" class="hidden w-full" aria-live="polite">
                        <div class="flex items-center justify-between gap-3 text-xs font-bold text-slate-500"><span id="ape-upload-progress-label">Preparing files…</span><span id="ape-upload-progress-percent">0%</span></div>
                        <progress id="ape-upload-progress-bar" class="w-full mt-2" max="100" value="0">0%</progress>
                    </div>
                </div>
            <?php endif; ?>
            </form>
        </div>
    </section>
    </details>
    <?php endif; ?>
    </div>
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
        <?php if ($showFindings): ?>
            <?php foreach ($findings as $finding): ?>
                <article class="student-document-card ape-timeline-clinical-entry <?= $finding['follow_up_required'] ? 'is-follow-up' : 'is-normal' ?>">
                    <span class="student-icon-box"><span class="material-symbols-outlined"><?= $finding['follow_up_required'] ? 'medical_information' : 'check_circle' ?></span></span>
                    <div class="student-document-meta">
                        <div class="ape-timeline-entry-heading">
                            <?php $findingDisplayResult = in_array($finding['result_status'], ['Normal', 'With Finding'], true) ? 'Examined' : $finding['result_status']; ?>
                            <h3><?= student_e($finding['finding_type'] . ' · ' . $findingDisplayResult) ?></h3>
                            <span class="student-badge <?= $finding['follow_up_required'] ? 'student-badge-warning' : 'student-badge-success' ?>"><?= $finding['follow_up_required'] ? 'Follow-up' : 'Clinical update' ?></span>
                        </div>
                        <p><?= student_e($finding['description']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$findings): ?>
                <article class="student-document-card ape-timeline-clinical-entry is-normal">
                    <span class="student-icon-box"><span class="material-symbols-outlined">check_circle</span></span>
                    <div class="student-document-meta"><h3>No active finding recorded</h3><p><?= student_e($studentNote ?: 'No additional patient instruction has been recorded.') ?></p></div>
                </article>
            <?php endif; ?>
        <?php endif; ?>
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
        const maxClientImageSize = 12 * 1024 * 1024;
        const maxFilesPerRequirement = 3;
        const allowedTypes = { pdf: 'application/pdf', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png' };
        const selectedFiles = Array.from(input.files || []);
        if (selectedFiles.length > maxFilesPerRequirement) {
            window.alert(`You selected ${selectedFiles.length} files for ${input.dataset.documentName || 'this requirement'}. A maximum of ${maxFilesPerRequirement} files is allowed. Please combine additional pages into one PDF before uploading.`);
            input.value = '';
            updateApeFileRow(input);
            refreshApeBatchSummary();
            return;
        }
        const oversizedFile = selectedFiles.find((file) => file.size > maxFileSize && !file.type.startsWith('image/'));
        if (oversizedFile) {
            window.alert(`${oversizedFile.name} is larger than 2 MB. Please choose files that are 2 MB or smaller.`);
            input.value = '';
            updateApeFileRow(input);
            refreshApeBatchSummary();
            return;
        }
        const oversizedImage = selectedFiles.find((file) => file.size > maxClientImageSize && file.type.startsWith('image/'));
        if (oversizedImage) {
            window.alert(`${oversizedImage.name} is too large to optimize safely in the browser. Please choose an image smaller than 12 MB.`);
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
        const selectedFiles = Array.from(input?.files || []);
        if (selectedFiles.length === 0) return;
        const documentName = input.dataset.documentName || 'this requirement';
        const fileLabel = `${selectedFiles.length} selected file${selectedFiles.length === 1 ? '' : 's'}`;
        if (!window.confirm(`Remove ${fileLabel} from ${documentName}?`)) return;
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
        const desktopStatus = document.getElementById(`ape-desktop-status-${documentKey}`);
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
            const removeFileButton = document.createElement('button');
            removeFileButton.type = 'button';
            removeFileButton.className = 'ape-staged-file-remove';
            removeFileButton.setAttribute('aria-label', `Remove selected file${selectedFiles.length === 1 ? '' : 's'}`);
            removeFileButton.title = 'Remove selected file(s)';
            removeFileButton.textContent = '×';
            removeFileButton.addEventListener('click', () => removeApeFile(documentKey));
            filename.appendChild(removeFileButton);
            filename.classList.remove('hidden');
            if (mobileFilename) { mobileFilename.textContent = `${selectedFiles.length} file${selectedFiles.length === 1 ? '' : 's'} selected`; mobileFilename.classList.remove('hidden'); }
            if (mobileRowState) { mobileRowState.textContent = mobileRowState.dataset.defaultLabel || ''; mobileRowState.classList.toggle('hidden', !mobileRowState.dataset.defaultLabel); mobileRowState.classList.remove('is-staged'); }
            if (mobileStatus) { mobileStatus.textContent = `${selectedFiles.length} file${selectedFiles.length === 1 ? '' : 's'} attached`; mobileStatus.className = 'student-badge student-badge-success'; }
            if (desktopStatus) { desktopStatus.textContent = `${selectedFiles.length} file${selectedFiles.length === 1 ? '' : 's'} attached`; desktopStatus.className = 'student-badge student-badge-success'; }
            selectLabel.textContent = 'Change Files';
            removeButton.classList.remove('hidden');
            mobileRemoveButton?.classList.remove('hidden');
        } else {
            filename.replaceChildren();
            filename.classList.add('hidden');
            if (mobileFilename) { mobileFilename.textContent = ''; mobileFilename.classList.add('hidden'); }
            if (mobileRowState) { mobileRowState.textContent = mobileRowState.dataset.defaultLabel || ''; mobileRowState.classList.toggle('hidden', !mobileRowState.dataset.defaultLabel); mobileRowState.classList.remove('is-staged'); }
            if (mobileStatus) { mobileStatus.textContent = mobileStatus.dataset.defaultStatus || ''; mobileStatus.className = `student-badge ${mobileStatus.dataset.defaultBadge || ''}`; }
            if (desktopStatus) { desktopStatus.textContent = desktopStatus.dataset.defaultStatus || ''; desktopStatus.className = `student-badge ${desktopStatus.dataset.defaultBadge || ''}`; }
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
    const apeUploadProgress = document.getElementById('ape-upload-progress');
    const apeUploadProgressBar = document.getElementById('ape-upload-progress-bar');
    const apeUploadProgressLabel = document.getElementById('ape-upload-progress-label');
    const apeUploadProgressPercent = document.getElementById('ape-upload-progress-percent');

    function setApeUploadProgress(percent, label) {
        const value = Math.max(0, Math.min(100, Math.round(percent)));
        if (apeUploadProgress) apeUploadProgress.classList.toggle('hidden', !label);
        if (apeUploadProgressBar) apeUploadProgressBar.value = value;
        if (apeUploadProgressLabel) apeUploadProgressLabel.textContent = label || '';
        if (apeUploadProgressPercent) apeUploadProgressPercent.textContent = `${value}%`;
    }

    function optimizeApeImage(file) {
        if (!file.type.startsWith('image/') || file.size <= 600 * 1024) return Promise.resolve(file);
        return new Promise((resolve) => {
            const objectUrl = URL.createObjectURL(file);
            const image = new Image();
            let settled = false;
            const finish = (result) => {
                if (settled) return;
                settled = true;
                window.clearTimeout(fallbackTimer);
                URL.revokeObjectURL(objectUrl);
                resolve(result);
            };
            const fallbackTimer = window.setTimeout(() => finish(file), 15000);
            image.onload = () => {
                const maxDimension = 1800;
                const scale = Math.min(1, maxDimension / Math.max(image.naturalWidth, image.naturalHeight));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
                canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
                const context = canvas.getContext('2d', { alpha: false });
                if (!context) {
                    finish(file);
                    return;
                }
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                canvas.toBlob((blob) => {
                    if (!blob || blob.size >= file.size) {
                        finish(file);
                        return;
                    }
                    const baseName = file.name.replace(/\.[^.]+$/, '') || 'ape-document';
                    finish(new File([blob], `${baseName}.jpg`, { type: 'image/jpeg', lastModified: file.lastModified }));
                }, 'image/jpeg', 0.82);
            };
            image.onerror = () => finish(file);
            image.src = objectUrl;
        });
    }

    async function prepareApeUploadFiles() {
        const inputs = Array.from(document.querySelectorAll('.ape-document-input'));
        let changed = false;
        const filesToPrepare = inputs.flatMap((input) => Array.from(input.files || []).map((file) => ({ input, file })));
        for (let fileIndex = 0; fileIndex < filesToPrepare.length; fileIndex += 1) {
            const { input, file } = filesToPrepare[fileIndex];
            setApeUploadProgress(
                5,
                `Preparing ${fileIndex + 1} of ${filesToPrepare.length}: ${file.name}`
            );
            const files = Array.from(input.files || []);
            const optimized = [];
            for (const candidate of files) {
                optimized.push(candidate === file ? await optimizeApeImage(candidate) : candidate);
            }
            if (optimized.some((candidate, index) => candidate !== files[index])) {
                const transfer = new DataTransfer();
                optimized.forEach((candidate) => transfer.items.add(candidate));
                input.files = transfer.files;
                updateApeFileRow(input);
                changed = true;
            }
        }
        if (changed) refreshApeBatchSummary();
    }

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

    apeUploadForm?.addEventListener('submit', async (event) => {
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
        event.preventDefault();
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="material-symbols-outlined">progress_activity</span> Uploading Documents...';
        try {
            if (!window.XMLHttpRequest || !window.FormData) {
                apeUploadForm.submit();
                return;
            }
            setApeUploadProgress(5, 'Optimizing images…');
            await prepareApeUploadFiles();
            const result = await new Promise((resolve, reject) => {
                const request = new XMLHttpRequest();
                request.open('POST', apeUploadForm.getAttribute('action') || window.location.href, true);
                request.timeout = 180000;
                request.upload.addEventListener('progress', (progressEvent) => {
                    if (!progressEvent.lengthComputable) return;
                    setApeUploadProgress(5 + (progressEvent.loaded / progressEvent.total) * 95, 'Uploading documents…');
                });
                request.addEventListener('load', () => {
                    if (request.status >= 200 && request.status < 400) {
                        resolve({ responseURL: request.responseURL, responseText: request.responseText });
                        return;
                    }
                    reject(new Error('The upload could not be completed.'));
                });
                request.addEventListener('error', () => reject(new Error('The upload connection was interrupted.')));
                request.addEventListener('timeout', () => reject(new Error('The upload took too long. Please try again on a stronger connection.')));
                request.send(new FormData(apeUploadForm));
            });
            if (!result.responseURL.includes('uploaded=')) {
                document.open();
                document.write(result.responseText);
                document.close();
                return;
            }
            setApeUploadProgress(100, 'Upload complete.');
            window.location.assign(result.responseURL || `${window.location.pathname}?uploaded=1`);
        } catch (error) {
            apeUploadForm.dataset.uploadConfirmed = '0';
            submitButton.disabled = false;
            submitButton.innerHTML = '<span class="material-symbols-outlined">cloud_upload</span> Try Upload Again';
            setApeUploadProgress(0, '');
            window.alert(error?.message || 'The upload could not be completed. Please try again.');
        }
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
