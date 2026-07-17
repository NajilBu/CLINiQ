<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_login();
ensure_ape_workflow_schema();

$patients = db()->query('SELECT id, student_number, first_name, last_name, course_section FROM patients ORDER BY last_name, first_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $documentPath = null;

    if (!empty($_FILES['document']['name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = dirname(__DIR__, 2) . '/uploads/ape/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            $filename = 'ape_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            move_uploaded_file($_FILES['document']['tmp_name'], $uploadDir . $filename);
            $documentPath = 'uploads/ape/' . $filename;
        }
    }

    $workflowStatus = $_POST['workflow_status'] ?? 'Registered';
    if (($workflowStatus === 'Scheduled') && empty($_POST['appointment_datetime'])) {
        $workflowStatus = 'Reviewed';
    }

    $followUpRequired = isset($_POST['follow_up_required']) ? 1 : 0;
    if ($followUpRequired) {
        $workflowStatus = 'Follow-up Required';
    }

    $stmt = db()->prepare("
        INSERT INTO ape_records (
            patient_id,
            exam_date,
            document_type,
            requirement_status,
            workflow_status,
            document_path,
            extracted_text,
            verification_status,
            verified_by,
            appointment_datetime,
            appointment_location,
            clearance_status,
            clinical_remarks,
            student_visible_note,
            follow_up_required
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int)$_POST['patient_id'],
        $_POST['exam_date'] ?: null,
        trim($_POST['document_type'] ?? 'APE Form') ?: 'APE Form',
        $_POST['requirement_status'] ?? 'Not Checked',
        $workflowStatus,
        $documentPath,
        trim($_POST['extracted_text'] ?? ''),
        $_POST['verification_status'] ?? 'Pending',
        ($_POST['verification_status'] ?? 'Pending') === 'Pending' ? null : current_user()['id'],
        $_POST['appointment_datetime'] ?: null,
        trim($_POST['appointment_location'] ?? '') ?: null,
        $followUpRequired ? 'For Follow-up' : ($_POST['clearance_status'] ?? 'Pending'),
        trim($_POST['clinical_remarks'] ?? '') ?: null,
        trim($_POST['student_visible_note'] ?? '') ?: null,
        $followUpRequired,
    ]);

    flash_message('success', 'APE workflow record created successfully.');
    header('Location: index.php');
    exit;
}

set_page_back_link('index.php', 'Back');
render_header('Add APE Record');
?>
<?php render_clinic_command_header(
    'APE Workflow',
    'Add APE Record',
    'Create a staff-managed APE workflow entry and attach digital documents.'
); ?>

<form class="clinic-card p-6 md:p-8" method="post" enctype="multipart/form-data">
    <div class="grid grid-cols-1 xl:grid-cols-[1.1fr_0.9fr] gap-8">
        <section>
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-4">Student & Status</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="md:col-span-2">
                    <label class="clinic-label">Student</label>
                    <select class="clinic-select" name="patient_id" required>
                        <option value="">Select student</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= (int)$p['id'] ?>">
                                <?= e($p['last_name'] . ', ' . $p['first_name'] . ' - ' . $p['student_number'] . ($p['course_section'] ? ' (' . $p['course_section'] . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Workflow Status</label>
                    <select class="clinic-select" name="workflow_status">
                        <?php foreach (ape_workflow_status_options() as $step): ?>
                            <option value="<?= e($step) ?>" <?= $step === 'Registered' ? 'selected' : '' ?>><?= e($step) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Requirement Status</label>
                    <select class="clinic-select" name="requirement_status">
                        <?php foreach (ape_requirement_status_options() as $status): ?>
                            <option value="<?= e($status) ?>"><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Clinic Review Status</label>
                    <select class="clinic-select" name="verification_status">
                        <?php foreach (ape_verification_status_options() as $status): ?>
                            <option value="<?= e($status) ?>"><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </section>

        <section>
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-4">Appointment & Clearance</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="clinic-label">Appointment Date & Time</label>
                    <input class="clinic-input" name="appointment_datetime" type="datetime-local">
                </div>
                <div>
                    <label class="clinic-label">Location</label>
                    <input class="clinic-input" name="appointment_location" placeholder="City Hall / PLP Clinic">
                </div>
                <div>
                    <label class="clinic-label">Exam Date</label>
                    <input class="clinic-input" name="exam_date" type="date">
                </div>
                <div>
                    <label class="clinic-label">Clearance Status</label>
                    <select class="clinic-select" name="clearance_status">
                        <option>Pending</option>
                        <option>Submitted</option>
                        <option>Cleared</option>
                    </select>
                </div>
                <label class="md:col-span-2 flex items-center gap-3 rounded-2xl bg-amber-50 border border-amber-100 p-4 cursor-pointer">
                    <input class="rounded border-amber-300 text-primary" type="checkbox" name="follow_up_required" value="1">
                    <span>
                        <strong class="block text-sm text-amber-900">Follow-up required</strong>
                        <span class="block text-xs font-bold text-amber-700">Use for clinic-only treatment monitoring before final clearance.</span>
                    </span>
                </label>
            </div>
        </section>
    </div>

    <div class="mt-8 pt-8 border-t border-slate-100 grid grid-cols-1 xl:grid-cols-[0.9fr_1.1fr] gap-8">
        <section>
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-4">Digital Document</h2>
            <div class="grid grid-cols-1 gap-5">
                <div>
                    <label class="clinic-label">Document Type</label>
                    <select class="clinic-select" name="document_type">
                        <option>APE Form</option>
                        <option>Lab Result</option>
                        <option>Medical Certificate</option>
                        <option>Referral Form</option>
                        <option>Clearance</option>
                        <option>Other Supporting Document</option>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Document File (PDF/Image)</label>
                    <input class="clinic-input" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div>
                    <label class="clinic-label">Extracted Text / Manual Notes</label>
                    <textarea class="clinic-textarea" name="extracted_text" rows="5" placeholder="Paste OCR text or summarize the submitted document..."></textarea>
                </div>
            </div>
        </section>

        <section>
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-4">Clinic Remarks</h2>
            <div class="grid grid-cols-1 gap-5">
                <div>
                    <label class="clinic-label">Private Clinical Remarks</label>
                    <textarea class="clinic-textarea" name="clinical_remarks" rows="5" placeholder="Visible to authorized clinic staff only. Use for findings, treatment monitoring, and internal follow-up notes."></textarea>
                </div>
                <div>
                    <label class="clinic-label">Student-Visible Note</label>
                    <textarea class="clinic-textarea" name="student_visible_note" rows="4" placeholder="Example: Please submit follow-up clearance before APE can be completed."></textarea>
                </div>
            </div>
        </section>
    </div>

    <div class="mt-8 flex flex-wrap gap-3">
        <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save this APE record?" data-confirm-message="This will create the APE record for the selected patient." data-confirm-toast="Saving APE record...">
            <span class="material-symbols-outlined text-[18px]">save</span> Save APE Record
        </button>
        <a class="btn btn-ghost btn-cancel-icon text-decoration-none" href="index.php" title="Cancel" aria-label="Cancel">
            <span class="material-symbols-outlined">cancel</span>
        </a>
    </div>
</form>
<?php render_footer(); ?>
