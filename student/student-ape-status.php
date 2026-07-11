<?php
require_once __DIR__ . '/includes/student-layout.php';

$profile = student_require_login();
$patientId = (int) $profile['patient_id'];

$apeStmt = db()->prepare("
    SELECT *
    FROM ape_records
    WHERE patient_id = ?
    ORDER BY updated_at DESC, created_at DESC
    LIMIT 1
");
$apeStmt->execute([$patientId]);
$apeRecord = $apeStmt->fetch();
$apeStatus = $apeRecord['workflow_status'] ?? 'Not Started';
$clearanceStatus = $apeRecord['clearance_status'] ?? 'Pending';
$studentNote = $apeRecord['student_visible_note'] ?? 'No APE record has been opened by the clinic yet.';
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
$canUploadDocuments = $requirementsVerified && !$requirementsNeedCorrection && $clearanceStatus !== 'Cleared';
$nextActionTitle = match (true) {
    $clearanceStatus === 'Cleared' => 'APE completed',
    $requirementsNeedCorrection => 'Return corrected hard-copy requirements',
    !$requirementsVerified => 'Wait for clinic hard-copy verification',
    default => 'Upload verified APE documents',
};
$nextActionCopy = match (true) {
    $clearanceStatus === 'Cleared' => 'Your APE record is already cleared by the clinic.',
    $requirementsNeedCorrection => $studentNote,
    !$requirementsVerified => 'The clinic must check your physical requirements first. Upload controls will appear after the clinic marks your requirements as verified.',
    default => $studentNote,
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
    in_array($apeStatus, ['Submitted', 'Reviewed', 'Scheduled', 'Exam Done'], true) => 3,
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
        'title' => 'Clinic review',
        'copy' => 'Clinic reviews uploaded files and asks for correction if needed.',
    ],
    [
        'number' => 4,
        'icon' => 'medical_services',
        'title' => 'Follow-up, if required',
        'copy' => 'Treatment proof or clearance may be requested only when the clinic records findings.',
    ],
    [
        'number' => 5,
        'icon' => 'verified_user',
        'title' => 'Completed APE',
        'copy' => 'Your student clinic record is cleared.',
    ],
];

$documents = array_map(static function (array $doc) use ($canUploadDocuments, $requirementsNeedCorrection): array {
    if ($canUploadDocuments) {
        return $doc + [
            'status' => 'Ready to Upload',
            'badge' => 'student-badge-warning',
            'detail' => 'This requirement has been checked by the clinic. Upload the digital copy for record keeping.',
            'action' => 'Upload',
            'button' => 'student-button',
            'disabled' => false,
        ];
    }

    return $doc + [
        'status' => $requirementsNeedCorrection ? 'Needs Correction' : 'Awaiting Clinic Check',
        'badge' => $requirementsNeedCorrection ? 'student-badge-danger' : 'student-badge-info',
        'detail' => $requirementsNeedCorrection
            ? 'Bring the corrected hard copy back to the clinic before digital submission opens.'
            : 'Clinic staff must verify the hard copy before this can be uploaded.',
        'action' => 'Locked',
        'button' => 'student-button-secondary student-button-disabled',
        'disabled' => true,
    ];
}, [
    ['name' => 'Lab Request Form', 'icon' => 'description'],
    ['name' => 'UHS Consent Form', 'icon' => 'approval'],
    ['name' => 'UHS Medical Record', 'icon' => 'description'],
    ['name' => 'UHS Dental Record', 'icon' => 'assignment'],
    ['name' => 'Referral Form', 'icon' => 'send'],
]);

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
    <?php if ($canUploadDocuments): ?>
        <button class="student-button" type="button" onclick="triggerUpload('Verified APE documents')">
            Upload File
            <span class="material-symbols-outlined">upload</span>
        </button>
    <?php else: ?>
        <span class="student-badge <?= $requirementsNeedCorrection ? 'student-badge-danger' : 'student-badge-info' ?>">
            <?= $requirementsNeedCorrection ? 'Correction Needed' : 'Clinic Verification First' ?>
        </span>
    <?php endif; ?>
</section>

<input type="file" id="file-picker" accept=".pdf,.png,.jpg,.jpeg" class="hidden" onchange="handleFileSelected(this)">

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
                        </div>
                        <button class="<?= student_e($doc['button']) ?>" type="button" <?= $doc['disabled'] ? 'disabled' : "onclick=\"triggerUpload('" . student_e($doc['name']) . "')\"" ?>>
                            <span class="material-symbols-outlined"><?= $doc['disabled'] ? 'lock' : 'upload' ?></span>
                            <?= student_e($doc['action']) ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>

<section class="student-card mt-4">
    <div class="student-card-header">
        <div>
            <h2 class="student-card-title">Clinic Findings and Follow-Up</h2>
            <p class="student-card-copy">This appears when clinic staff marks something that needs attention.</p>
        </div>
        <span class="student-badge <?= student_e($headerBadge) ?>"><?= student_e($apeRecord['result_status'] ?? 'No Active Treatment') ?></span>
    </div>
    <div class="student-card-pad">
        <div class="student-note <?= $clearanceStatus === 'Cleared' ? 'student-note-success' : 'student-note-warning' ?>">
            <span class="material-symbols-outlined">info</span>
            <div>
                <strong><?= student_e($apeRecord['clinical_remarks'] ?? 'No active finding recorded.') ?></strong>
                <?= student_e($apeRecord['result_notes'] ?? 'If the clinic records a finding, upload treatment proof or clearance here before your APE can be completed.') ?>
            </div>
        </div>
    </div>
</section>

<script>
    let activeDocName = '';

    function triggerUpload(docName) {
        activeDocName = docName;
        document.getElementById('file-picker').click();
    }

    function handleFileSelected(input) {
        if (input.files && input.files.length > 0) {
            const file = input.files[0];
            alert(`Successfully selected "${file.name}" for ${activeDocName}. Backend upload can be connected to this control.`);
            input.value = '';
        }
    }
</script>

<?php render_student_footer(); ?>
