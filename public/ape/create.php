<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_login();
ensure_ape_workflow_schema();

$apeDb = auth_db();
$patients = $apeDb->query("
    SELECT pt.person_id AS id, p.id_number, p.first_name, p.last_name,
           COALESCE(
               NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))), ''),
               ed.department_code,
               'Patient'
           ) AS course_section
    FROM patients pt
    JOIN people p ON p.id = pt.person_id
    LEFT JOIN students s ON s.person_id = p.id
    LEFT JOIN programs pr ON pr.id = s.program_id
    LEFT JOIN school_employees se ON se.person_id = p.id
    LEFT JOIN departments ed ON ed.id = se.department_id
    ORDER BY p.last_name, p.first_name
")->fetchAll();
$appointmentOptions = $apeDb->query("
    SELECT a.appointment_id, a.patient_id, a.appointment_datetime, p.id_number, p.first_name, p.last_name
    FROM appointments a
    JOIN people p ON p.id = a.patient_id
    WHERE a.status = 'Scheduled'
    ORDER BY a.appointment_datetime DESC
    LIMIT 200
")->fetchAll();
$currentYear = (int) date('Y');
$academicYear = (int) date('n') >= 6
    ? $currentYear . '-' . ($currentYear + 1)
    : ($currentYear - 1) . '-' . $currentYear;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $storedFile = null;
    try {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $staffPersonId = (int) (current_user()['person_id'] ?? 0);
        $academicYear = trim((string) ($_POST['academic_year'] ?? ''));
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0) ?: null;
        $workflowStatus = (string) ($_POST['workflow_status'] ?? 'Registered');
        $requirementStatus = (string) ($_POST['requirement_status'] ?? 'Not Checked');
        $clearanceStatus = (string) ($_POST['clearance_status'] ?? 'Pending');
        $verificationStatus = (string) ($_POST['verification_status'] ?? 'Pending');
        $followUpRequired = isset($_POST['follow_up_required']) ? 1 : 0;
        $clinicalRemarks = trim((string) ($_POST['clinical_remarks'] ?? '')) ?: null;

        if ($patientId <= 0 || $staffPersonId <= 0 || $academicYear === '') {
            throw new InvalidArgumentException('Patient, academic year, and clinic staff identity are required.');
        }
        if (!preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $yearParts) || (int) $yearParts[2] !== (int) $yearParts[1] + 1) {
            throw new InvalidArgumentException('Academic year must use consecutive years, such as 2026-2027.');
        }
        if (!in_array($workflowStatus, ape_workflow_status_options(), true)) {
            throw new InvalidArgumentException('Choose a valid APE workflow status.');
        }
        if (!in_array($requirementStatus, ['Not Checked', 'Checked', 'Needs Correction'], true)) {
            throw new InvalidArgumentException('Choose a valid requirement status.');
        }
        if (!in_array($clearanceStatus, ['Pending', 'For Follow-up', 'Cleared'], true)) {
            throw new InvalidArgumentException('Choose a valid clearance status.');
        }
        if (!in_array($verificationStatus, ['Pending', 'Verified', 'Needs Correction'], true)) {
            throw new InvalidArgumentException('Choose a valid document verification status.');
        }
        if ($appointmentId !== null) {
            $appointmentCheck = $apeDb->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_id = ? AND patient_id = ? AND status = 'Scheduled'");
            $appointmentCheck->execute([$appointmentId, $patientId]);
            if ((int) $appointmentCheck->fetchColumn() !== 1) {
                throw new InvalidArgumentException('The selected appointment does not belong to this patient.');
            }
        }

        if (!empty($_FILES['document']['name'])) {
            $storedFile = ape_store_uploaded_file($_FILES['document'], 'ape');
        }
        if ($followUpRequired) {
            $workflowStatus = 'Follow-up Required';
            $clearanceStatus = 'For Follow-up';
        }

        $apeDb->beginTransaction();
        $stmt = $apeDb->prepare("
            INSERT INTO ape_records (
                patient_id, academic_year, exam_date, appointment_id,
                requirement_status, workflow_status, clearance_status,
                follow_up_required, clinical_remarks, patient_visible_note,
                reviewed_by_person_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $patientId,
            $academicYear,
            trim((string) ($_POST['exam_date'] ?? '')) ?: null,
            $appointmentId,
            $requirementStatus,
            $workflowStatus,
            $clearanceStatus,
            $followUpRequired,
            $clinicalRemarks,
            trim((string) ($_POST['patient_visible_note'] ?? '')) ?: null,
            $requirementStatus === 'Not Checked' ? null : $staffPersonId,
        ]);
        $apeId = (int) $apeDb->lastInsertId();

        ape_seed_default_requirements($apeId, $requirementStatus === 'Checked' ? 'Verified' : ($requirementStatus === 'Needs Correction' ? 'Needs Correction' : 'Missing'));
        if ($requirementStatus === 'Checked') {
            $checked = $apeDb->prepare('UPDATE ape_requirements SET checked_by_person_id = ?, checked_at = NOW() WHERE ape_id = ?');
            $checked->execute([$staffPersonId, $apeId]);
        }

        if ($storedFile !== null) {
            $document = $apeDb->prepare("
                INSERT INTO ape_documents (
                    ape_id, document_type, original_filename, file_path,
                    verification_status, uploaded_by_person_id,
                    verified_by_person_id, verified_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $document->execute([
                $apeId,
                trim((string) ($_POST['document_type'] ?? 'APE Form')) ?: 'APE Form',
                $storedFile['original_filename'],
                $storedFile['file_path'],
                $verificationStatus,
                $staffPersonId,
                $verificationStatus === 'Pending' ? null : $staffPersonId,
                $verificationStatus === 'Pending' ? null : date('Y-m-d H:i:s'),
            ]);
        }

        if ($followUpRequired && $clinicalRemarks) {
            $finding = $apeDb->prepare("
                INSERT INTO ape_findings (
                    ape_id, finding_type, description, result_status,
                    follow_up_required, recorded_by_person_id
                ) VALUES (?, 'General', ?, 'With Finding', 1, ?)
                ON DUPLICATE KEY UPDATE
                    finding_type = VALUES(finding_type),
                    description = VALUES(description),
                    result_status = VALUES(result_status),
                    follow_up_required = VALUES(follow_up_required),
                    recorded_by_person_id = VALUES(recorded_by_person_id),
                    recorded_at = CURRENT_TIMESTAMP
            ");
            $finding->execute([$apeId, $clinicalRemarks, $staffPersonId]);
        }

        ape_log_activity($apeId, $staffPersonId, 'Created APE record', 'Academic year ' . $academicYear);
        $apeDb->commit();
        flash_message('success', 'APE workflow record created successfully in Cliniq_db.');
        header('Location: view.php?id=' . $apeId);
        exit;
    } catch (Throwable $e) {
        if ($apeDb->inTransaction()) {
            $apeDb->rollBack();
        }
        if ($storedFile && is_file($storedFile['absolute_path'])) {
            unlink($storedFile['absolute_path']);
        }
        flash_message('error', $e->getCode() === '23000'
            ? 'This patient already has an APE record for that academic year.'
            : $e->getMessage());
    }
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
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-4">Patient & Status</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="md:col-span-2">
                    <label class="clinic-label">Patient</label>
                    <select class="clinic-select" name="patient_id" required>
                        <option value="">Select student</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= (int)$p['id'] ?>">
                                <?= e($p['last_name'] . ', ' . $p['first_name'] . ' - ' . $p['id_number'] . ($p['course_section'] ? ' (' . $p['course_section'] . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Academic Year</label>
                    <input class="clinic-input" name="academic_year" value="<?= e($academicYear) ?>" placeholder="2026-2027" required>
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
                <div class="md:col-span-2">
                    <label class="clinic-label">Related Scheduled Appointment (Optional)</label>
                    <select class="clinic-select" name="appointment_id">
                        <option value="">No linked appointment</option>
                        <?php foreach ($appointmentOptions as $appointment): ?>
                            <option value="<?= (int) $appointment['appointment_id'] ?>">
                                <?= e($appointment['last_name'] . ', ' . $appointment['first_name'] . ' - ' . $appointment['id_number'] . ' (' . date('M d, Y g:i A', strtotime($appointment['appointment_datetime'])) . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Exam Date</label>
                    <input class="clinic-input" name="exam_date" type="date">
                </div>
                <div>
                    <label class="clinic-label">Clearance Status</label>
                    <select class="clinic-select" name="clearance_status">
                        <option>Pending</option>
                        <option>For Follow-up</option>
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
                    <label class="clinic-label">Patient-Visible Note</label>
                    <textarea class="clinic-textarea" name="patient_visible_note" rows="4" placeholder="Example: Please submit follow-up clearance before APE can be completed."></textarea>
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
