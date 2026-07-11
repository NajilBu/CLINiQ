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
$apePercent = match ($apeStatus) {
    'Registered' => 20,
    'Batch Assigned', 'Requirements Checked' => 40,
    'Submitted', 'Reviewed' => 60,
    'Scheduled', 'Exam Done', 'Follow-up Required' => 80,
    'Cleared' => 100,
    default => 0,
};
$headerBadge = $clearanceStatus === 'Cleared' ? 'student-badge-success' : ($actionNeeded ? 'student-badge-warning' : 'student-badge-info');

$documents = [
    [
        'name' => 'Lab Request Form',
        'status' => 'Approved',
        'badge' => 'student-badge-success',
        'detail' => 'Stored in your clinic record.',
        'action' => 'View',
        'button' => 'student-button-secondary',
        'icon' => 'description',
    ],
    [
        'name' => 'UHS Consent Form',
        'status' => 'Pending Review',
        'badge' => 'student-badge-warning',
        'detail' => 'Uploaded today. Waiting for clinic review.',
        'action' => 'Re-upload',
        'button' => 'student-button-secondary',
        'icon' => 'approval',
    ],
    [
        'name' => 'UHS Medical Record',
        'status' => 'Missing',
        'badge' => 'student-badge-danger',
        'detail' => 'Upload the checked hard-copy form after clinic verification.',
        'action' => 'Upload',
        'button' => 'student-button',
        'icon' => 'clinical_notes',
    ],
    [
        'name' => 'UHS Dental Record',
        'status' => 'Missing',
        'badge' => 'student-badge-danger',
        'detail' => 'Submit the checked dental record for digital keeping.',
        'action' => 'Upload',
        'button' => 'student-button',
        'icon' => 'dentistry',
    ],
    [
        'name' => 'Referral Form',
        'status' => 'Missing',
        'badge' => 'student-badge-danger',
        'detail' => 'Required if the clinic marked you for follow-up or treatment.',
        'action' => 'Upload',
        'button' => 'student-button',
        'icon' => 'send',
    ],
];

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
            <h2>Next action: <?= $clearanceStatus === 'Cleared' ? 'APE completed' : 'Continue your APE clearance' ?></h2>
            <p><?= student_e($studentNote) ?></p>
        </div>
    </div>
    <button class="student-button" type="button" onclick="triggerUpload('Missing APE documents')">
        Upload File
        <span class="material-symbols-outlined">upload</span>
    </button>
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
            <div class="student-progress-list">
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">fact_check</span>
                    <div>
                        <strong>Hard-copy review</strong>
                        <span>Clinic checked your physical documents and noted findings.</span>
                    </div>
                    <span class="student-badge student-badge-success">Done</span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">cloud_upload</span>
                    <div>
                        <strong>Digital document keeping</strong>
                        <span>Upload checked forms for the clinic's digital record.</span>
                    </div>
                    <span class="student-badge student-badge-warning">Now</span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">rate_review</span>
                    <div>
                        <strong>Clinic review</strong>
                        <span>Clinic approves files or asks for correction.</span>
                    </div>
                    <span class="student-badge student-badge-info">Next</span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">healing</span>
                    <div>
                        <strong>Follow-up, if required</strong>
                        <span>Treatment proof or clearance may be requested.</span>
                    </div>
                    <span class="student-badge student-badge-info">If Needed</span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">verified</span>
                    <div>
                        <strong>Completed APE</strong>
                        <span>Your student clinic record is cleared.</span>
                    </div>
                    <span class="student-badge student-badge-info">Final</span>
                </div>
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
                        <button class="<?= student_e($doc['button']) ?>" type="button" onclick="triggerUpload('<?= student_e($doc['name']) ?>')">
                            <span class="material-symbols-outlined"><?= $doc['action'] === 'View' ? 'visibility' : 'upload' ?></span>
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
