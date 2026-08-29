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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_ape_vitals') {
    try {
        if (!$apeRecord) {
            throw new RuntimeException('The clinic must create your APE record before you can enter vitals.');
        }
        if (($apeRecord['patient_vitals_status'] ?? 'Not Started') === 'Confirmed') {
            throw new RuntimeException('Your vitals and BMI have already been confirmed.');
        }
        $height = (float) ($_POST['patient_height_cm'] ?? 0);
        $weight = (float) ($_POST['patient_weight_kg'] ?? 0);
        $temperature = (float) ($_POST['patient_temperature'] ?? 0);
        $bloodPressure = trim((string) ($_POST['patient_blood_pressure'] ?? ''));
        $pulseRate = (int) ($_POST['patient_pulse_rate'] ?? 0);
        if ($height < 30 || $height > 250 || $weight < 1 || $weight > 500) {
            throw new InvalidArgumentException('Enter a valid height and weight.');
        }
        if ($temperature < 30 || $temperature > 45) {
            throw new InvalidArgumentException('Enter a valid temperature between 30 and 45 °C.');
        }
        if (!preg_match('/^\d{2,3}\s*\/\s*\d{2,3}$/', $bloodPressure)) {
            throw new InvalidArgumentException('Enter blood pressure in the format 120/80.');
        }
        if ($pulseRate < 20 || $pulseRate > 250) {
            throw new InvalidArgumentException('Enter a valid pulse rate between 20 and 250 bpm.');
        }
        $bmi = round($weight / (($height / 100) ** 2), 2);
        $stmt = auth_db()->prepare("UPDATE ape_records SET patient_height_cm = ?, patient_weight_kg = ?, patient_bmi = ?, patient_temperature = ?, patient_blood_pressure = ?, patient_pulse_rate = ?, patient_vitals_status = 'Confirmed', patient_vitals_confirmed_at = NOW() WHERE ape_id = ? AND patient_id = ? AND patient_vitals_status = 'Not Started'");
        $stmt->execute([$height, $weight, $bmi, $temperature, $bloodPressure, $pulseRate, (int) $apeRecord['ape_id'], $patientId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The vitals could not be confirmed. Refresh and try again.');
        }
        ape_log_activity((int) $apeRecord['ape_id'], $patientId, 'Confirmed patient-entered vitals and BMI', 'Patient completed the vitals profile before clinic examination.');
        header('Location: patient-ape-status.php?vitals_confirmed=1');
        exit;
    } catch (Throwable $e) {
        $uploadError = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_ape_documents') {
    $storedFiles = [];
    try {
        if (!$apeRecord) {
            throw new RuntimeException('The clinic must create your APE record before you can upload documents.');
        }
        $requirementsVerifiedForUpload = ($apeRecord['requirement_status'] ?? '') === 'Pre-Verified'
            || in_array(($apeRecord['workflow_status'] ?? ''), ['Requirements Checked', 'Submitted', 'Reviewed', 'Scheduled', 'Exam Done', 'Follow-up Required'], true);
        if (!$requirementsVerifiedForUpload || empty($apeRecord['exam_date']) || ($apeRecord['clearance_status'] ?? '') === 'Cleared') {
            throw new RuntimeException('Document upload opens only after the clinical examination is complete.');
        }

        $latestExistingByType = [];
        foreach (ape_documents_for_record((int) $apeRecord['ape_id']) as $existingDocument) {
            $latestExistingByType[$existingDocument['document_type']] ??= $existingDocument;
        }
        $batchFiles = $_FILES['documents'] ?? [];
        foreach ($batchDocumentTypes as $documentKey => $documentType) {
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
            UPDATE ape_requirements
            SET status = 'Submitted', remarks = NULL, checked_by_person_id = NULL, checked_at = NULL
            WHERE ape_id = ? AND requirement_name = ?
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
        header('Location: patient-ape-status.php?uploaded=' . count($storedFiles));
        exit;
    } catch (Throwable $e) {
        if (isset($apeDb) && $apeDb->inTransaction()) {
            $apeDb->rollBack();
        }
        foreach ($storedFiles as $storedUpload) {
            $storedFile = $storedUpload['file'] ?? [];
            if (!empty($storedFile['absolute_path']) && is_file($storedFile['absolute_path'])) {
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
    'Follow-up Required',
    'Cleared',
], true);
$requirementsNeedCorrection = $requirementStatus === 'Needs Correction';
$examCompleted = !empty($apeRecord['exam_date']);
$patientVitalsConfirmed = ($apeRecord['patient_vitals_status'] ?? 'Not Started') === 'Confirmed';
$allRequiredDocumentsUploaded = (int) ($apeRecord['required_document_count'] ?? 0) >= count(ape_default_requirements());
$documentsAwaitingReview = $allRequiredDocumentsUploaded && (int) ($apeRecord['required_unverified_count'] ?? 0) > 0;
$canUploadDocuments = $requirementsVerified
    && !$requirementsNeedCorrection
    && $examCompleted
    && $clearanceStatus !== 'Cleared'
    && in_array($apeStatus, ['Requirements Checked', 'Exam Done', 'Submitted', 'Follow-up Required'], true);
$nextActionTitle = match (true) {
    $clearanceStatus === 'Cleared' => 'APE completed',
    $requirementsNeedCorrection => 'Return corrected hard-copy requirements',
    $apeStatus === 'Follow-up Required' => 'Complete the required follow-up',
    !$patientVitalsConfirmed => 'Complete your vitals and BMI',
    !$examCompleted || !$requirementsVerified => 'Attend examination',
    $documentsAwaitingReview => 'Wait for clinic document review',
    $apeStatus === 'Reviewed' => 'Wait for the final clinical decision',
    default => 'Upload verified APE documents',
};
$nextActionCopy = match (true) {
    $clearanceStatus === 'Cleared' => 'Your APE record is already cleared by the clinic.',
    $requirementsNeedCorrection => $studentNote ?: 'Return the corrected hard-copy requirements requested by the clinic.',
    $apeStatus === 'Follow-up Required' => $studentNote ?: 'Complete the referral or other follow-up requested by the clinic.',
    !$patientVitalsConfirmed => 'Enter and confirm your height, weight, BMI, and vital signs before visiting the clinic for examination.',
    !$examCompleted || !$requirementsVerified => $studentNote ?: 'Your vitals are confirmed. Visit the clinic for examination and hard-copy document review.',
    $documentsAwaitingReview => 'Your documents were submitted and are waiting for clinic archive review.',
    $apeStatus === 'Reviewed' => $studentNote ?: 'Your examination and document archive are complete. The clinic will now record the final decision.',
    default => $studentNote ?: ($apeRecord ? 'Complete the current APE step shown below.' : 'No APE record has been opened by the clinic yet.'),
};
$apePercent = match ($apeStatus) {
    'Registered' => 20,
    'Batch Assigned' => 20,
    'Exam Done' => 40,
    'Requirements Checked', 'Scheduled' => 60,
    'Submitted' => 80,
    'Reviewed', 'Follow-up Required' => 80,
    'Cleared' => 100,
    default => 0,
};
$headerBadge = $clearanceStatus === 'Cleared' ? 'student-badge-success' : ($actionNeeded ? 'student-badge-warning' : 'student-badge-info');
$actionBadgeLabel = match (true) {
    $requirementsNeedCorrection => 'Correction Needed',
    $apeStatus === 'Follow-up Required' => 'Follow-up Required',
    !$patientVitalsConfirmed => 'Vitals and BMI First',
    !$examCompleted || !$requirementsVerified => 'Examination',
    $documentsAwaitingReview => 'Under Clinic Review',
    $apeStatus === 'Reviewed' => 'Final Decision Pending',
    default => 'Current Step',
};
$currentStep = match (true) {
    $clearanceStatus === 'Cleared' => 5,
    $apeStatus === 'Follow-up Required' || $clearanceStatus === 'For Follow-up' => 4,
    $apeStatus === 'Reviewed' => 4,
    !$patientVitalsConfirmed => 1,
    !$examCompleted || !$requirementsVerified => 2,
    default => 3,
};

$flowSteps = [
    [
        'number' => 1,
        'icon' => 'monitor_heart',
        'title' => 'Patient vitals and BMI',
        'copy' => $patientVitalsConfirmed
            ? 'You confirmed your height, weight, BMI, and vital signs.'
            : 'Enter and confirm your vitals and BMI before visiting the clinic.',
    ],
    [
        'number' => 2,
        'icon' => 'task_alt',
        'title' => 'Examination',
        'copy' => $examCompleted && $requirementsVerified
            ? 'Clinic recorded your examination and checked your hard-copy requirements.'
            : ($patientVitalsConfirmed ? 'Visit the clinic for examination and bring your hard-copy requirements.' : 'Confirm your vitals and BMI before visiting the clinic.'),
    ],
    [
        'number' => 3,
        'icon' => 'cloud_upload',
        'title' => 'Digital document keeping',
        'copy' => $examCompleted
            ? 'Upload the examined and checked forms for the clinic digital record.'
            : 'This opens only after the clinic examination.',
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
            'status' => 'Ready to Upload',
            'badge' => 'student-badge-warning',
            'detail' => $requirement['remarks'] ?? 'Hard copy checked. Upload one or more digital copies for clinic record keeping.',
            'action' => 'Upload',
            'button' => 'student-button',
            'disabled' => false,
        ];
    } else {
        $lockedDetail = match (true) {
            $requirementsNeedCorrection => 'Bring the corrected hard copy back to the clinic before continuing.',
            !$patientVitalsConfirmed => 'Confirm your vitals and BMI before continuing.',
            !$examCompleted || !$requirementsVerified => 'Complete the clinic examination before uploading digital copies.',
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
$uploadableDocumentCount = count(array_filter($documents, static fn(array $document): bool => !$document['disabled']));
$canEnterPatientVitals = $apeRecord && !$patientVitalsConfirmed && $clearanceStatus !== 'Cleared';

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
<?php elseif (isset($_GET['vitals_confirmed'])): ?>
    <div class="student-note student-note-success mb-4">
        <span class="material-symbols-outlined">check_circle</span>
        <div>Your vitals and BMI were confirmed. You can now present your hard-copy APE requirements to the clinic.</div>
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
            <?= student_e($actionBadgeLabel) ?>
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
            <div class="w-full h-3 rounded-full bg-primary-fixed overflow-hidden mb-5">
                <div class="h-full bg-primary rounded-full" style="width: <?= (int) $apePercent ?>%;"></div>
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
                <p class="student-card-copy">Upload only documents already checked by the clinic. You can attach multiple files per document, up to 10 MB each.</p>
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
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($uploadableDocumentCount > 0): ?>
                <div class="ape-batch-submit-bar">
                    <div>
                        <strong id="ape-selected-summary">No files selected</strong>
                        <span>Select files first. You can change or remove them before submitting. Each file must be 10 MB or smaller.</span>
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

<?php if ($apeRecord): ?>
<section class="student-card mb-4">
    <div class="student-card-header">
        <div>
            <h2 class="student-card-title">Vitals and BMI</h2>
            <p class="student-card-copy">Enter these values before presenting your hard-copy APE requirements to the clinic.</p>
        </div>
        <span class="student-badge <?= $patientVitalsConfirmed ? 'student-badge-success' : 'student-badge-warning' ?>">
            <?= $patientVitalsConfirmed ? 'Confirmed' : 'Not Started' ?>
        </span>
    </div>
    <div class="student-card-pad">
        <?php if ($patientVitalsConfirmed): ?>
            <div class="student-note student-note-success mb-4"><span class="material-symbols-outlined">verified</span><div><strong>Patient-entered profile confirmed.</strong> Present your hard-copy requirements to the clinic. The clinic will record official examination findings separately.</div></div>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="ape-flow-field"><p class="clinic-label mb-1">Height</p><strong><?= student_e(number_format((float) $apeRecord['patient_height_cm'], 2)) ?> cm</strong></div>
                <div class="ape-flow-field"><p class="clinic-label mb-1">Weight</p><strong><?= student_e(number_format((float) $apeRecord['patient_weight_kg'], 2)) ?> kg</strong></div>
                <div class="ape-flow-field"><p class="clinic-label mb-1">BMI</p><strong><?= student_e(number_format((float) $apeRecord['patient_bmi'], 2)) ?></strong></div>
                <div class="ape-flow-field"><p class="clinic-label mb-1">Temperature</p><strong><?= student_e(number_format((float) $apeRecord['patient_temperature'], 1)) ?> °C</strong></div>
                <div class="ape-flow-field"><p class="clinic-label mb-1">Blood Pressure</p><strong><?= student_e($apeRecord['patient_blood_pressure']) ?></strong></div>
                <div class="ape-flow-field"><p class="clinic-label mb-1">Pulse Rate</p><strong><?= (int) $apeRecord['patient_pulse_rate'] ?> bpm</strong></div>
            </div>
        <?php elseif ($canEnterPatientVitals): ?>
            <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4" id="ape-vitals-form">
                <input type="hidden" name="action" value="confirm_ape_vitals">
                <div>
                    <label class="student-label" for="patient_height_cm">Height (cm)</label>
                    <input class="student-input" id="patient_height_cm" name="patient_height_cm" type="number" min="30" max="250" step="0.01" placeholder="e.g. 170" required>
                </div>
                <div>
                    <label class="student-label" for="patient_weight_kg">Weight (kg)</label>
                    <input class="student-input" id="patient_weight_kg" name="patient_weight_kg" type="number" min="1" max="500" step="0.01" placeholder="e.g. 60" required>
                </div>
                <div>
                    <label class="student-label" for="patient_bmi">BMI (calculated)</label>
                    <input class="student-input" id="patient_bmi" name="patient_bmi_display" type="text" placeholder="Enter height/weight" readonly>
                </div>
                <div>
                    <label class="student-label" for="patient_temperature">Temperature (°C)</label>
                    <input class="student-input" id="patient_temperature" name="patient_temperature" type="number" min="30" max="45" step="0.01" placeholder="e.g. 36.6" required>
                </div>
                <div>
                    <label class="student-label" for="patient_blood_pressure">Blood Pressure</label>
                    <input class="student-input" id="patient_blood_pressure" name="patient_blood_pressure" type="text" pattern="\d{2,3}\s*/\s*\d{2,3}" placeholder="e.g. 120/80" required>
                </div>
                <div>
                    <label class="student-label" for="patient_pulse_rate">Pulse Rate (bpm)</label>
                    <input class="student-input" id="patient_pulse_rate" name="patient_pulse_rate" type="number" min="20" max="250" placeholder="e.g. 72" required>
                </div>
                
                <div class="md:col-span-3 student-note student-note-warning">
                    <span class="material-symbols-outlined">info</span>
                    <div>Review your entries carefully. After confirmation, these values will be locked and shown to clinic staff.</div>
                </div>
                
                <button class="student-button md:col-span-3" type="submit" data-confirm-submit data-confirm-type="primary" data-confirm-title="Confirm vitals and BMI?" data-confirm-message="These values will be locked and shared with the clinic for review." data-confirm-toast="Confirming vitals and BMI...">
                    <span class="material-symbols-outlined">verified</span>
                    Confirm Vitals and BMI
                </button>
            </form>
        <?php else: ?>
            <div class="student-note student-note-info"><span class="material-symbols-outlined">info</span><div>This completed APE record predates the patient vitals profile step. No new patient-entered values are required.</div></div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

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
    const apeHeightInput = document.getElementById('patient_height_cm');
    const apeWeightInput = document.getElementById('patient_weight_kg');
    const apeBmiOutput = document.getElementById('patient_bmi');
    function updateApeBmi() {
        if (!apeHeightInput || !apeWeightInput || !apeBmiOutput) return;
        const height = Number(apeHeightInput.value);
        const weight = Number(apeWeightInput.value);
        apeBmiOutput.value = height > 0 && weight > 0 ? (weight / ((height / 100) ** 2)).toFixed(2) : '';
    }
    apeHeightInput?.addEventListener('input', updateApeBmi);
    apeWeightInput?.addEventListener('input', updateApeBmi);

    function selectApeFile(documentKey) {
        document.getElementById(`ape-file-${documentKey}`).click();
    }

    function handleApeFileSelected(input) {
        const maxFileSize = 10 * 1024 * 1024;
        const oversizedFile = Array.from(input.files || []).find((file) => file.size > maxFileSize);
        if (oversizedFile) {
            window.alert(`${oversizedFile.name} is larger than 10 MB. Please choose files that are 10 MB or smaller.`);
            input.value = '';
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
            selectLabel.textContent = 'Change Files';
            removeButton.classList.remove('hidden');
        } else {
            filename.replaceChildren();
            filename.classList.add('hidden');
            selectLabel.textContent = 'Select Files';
            removeButton.classList.add('hidden');
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

        submitButton.disabled = count === 0;
        summary.textContent = count === 0 ? 'No files selected' : `${count} file${count === 1 ? '' : 's'} ready to submit`;
        countLabel.textContent = count === 0 ? '' : `(${count})`;
    }

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
</script>

<?php render_student_footer(); ?>
