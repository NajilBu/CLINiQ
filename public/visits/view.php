<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/VisitWorkflow.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_visit_workflow_schema();
ensure_inventory_workflow_schema();

$id = (int) ($_GET['id'] ?? 0);
$entryPoint = $_GET['from'] ?? 'logbook';
$entryPoint = $entryPoint === 'profile' ? 'profile' : 'logbook';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'intake';
    $returnFrom = $_POST['from'] ?? $entryPoint;
    $returnFrom = $returnFrom === 'profile' ? 'profile' : 'logbook';
    $returnTo = $_POST['return_to'] ?? 'view';

    $visitCheck = db()->prepare('SELECT * FROM clinic_visits WHERE id = ? LIMIT 1');
    $visitCheck->execute([$id]);
    $existingVisit = $visitCheck->fetch();

    if ($existingVisit && $mode === 'begin_visit' && ($existingVisit['status'] ?? 'Unaddressed') === 'Unaddressed') {
        $update = db()->prepare("UPDATE clinic_visits SET status = 'Active', recorded_by = ?, attended_by = ? WHERE id = ?");
        $update->execute([current_user()['id'], current_user()['id'], $id]);

        flash_message('success', 'Treatment session started.');
    } elseif ($existingVisit && $mode === 'no_show' && ($existingVisit['status'] ?? 'Unaddressed') === 'Unaddressed') {
        $update = db()->prepare("
            UPDATE clinic_visits
            SET status = 'Cancelled',
                action_taken = ?,
                recorded_by = ?
            WHERE id = ?
        ");
        $update->execute([
            trim($_POST['no_show_reason'] ?? '') ?: 'No show / patient did not proceed to nurse assessment.',
            current_user()['id'],
            $id,
        ]);

        flash_message('success', 'Visit marked as no show.');
    } elseif ($existingVisit && $mode === 'cancel_treatment' && ($existingVisit['status'] ?? '') === 'Active') {
        $update = db()->prepare("
            UPDATE clinic_visits
            SET status = 'Cancelled',
                action_taken = ?,
                attended_by = COALESCE(attended_by, ?)
            WHERE id = ?
        ");
        $update->execute([
            trim($_POST['cancel_reason'] ?? '') ?: 'Treatment cancelled by attending staff.',
            current_user()['id'],
            $id,
        ]);

        flash_message('success', 'Treatment cancelled.');
    } elseif ($existingVisit && $mode === 'end_visit' && ($existingVisit['status'] ?? '') === 'Active') {
        $finalRemarks = trim($_POST['final_remarks'] ?? '');
        $referralType = trim($_POST['referral_type'] ?? '');
        if ($referralType === 'None') {
            $referralType = '';
        }

        if ($finalRemarks !== '' || $referralType !== '') {
            $insert = db()->prepare(
                'INSERT INTO visit_treatment_entries (visit_id, referral_type, remarks, created_by)
                 VALUES (?, ?, ?, ?)'
            );
            $insert->execute([
                $id,
                $referralType ?: null,
                $finalRemarks ?: null,
                current_user()['id'],
            ]);
        }

        $update = db()->prepare('UPDATE clinic_visits SET status = ?, attended_by = COALESCE(attended_by, ?) WHERE id = ?');
        $update->execute(['Completed', current_user()['id'], $id]);

        flash_message('success', 'Patient visit ended.');
    } elseif ($existingVisit && in_array($mode, ['intake', 'save_treatment'], true) && in_array(($existingVisit['status'] ?? 'Unaddressed'), ['Unaddressed', 'Active'], true)) {
        $postedSymptoms = trim($_POST['symptoms'] ?? '');
        $symptoms = $postedSymptoms !== '' ? $postedSymptoms : trim((string) ($existingVisit['symptoms'] ?? ''));
        $temperature = $_POST['temperature'] ?: null;
        $bloodPressure = trim($_POST['blood_pressure'] ?? '');
        $pulseRate = $_POST['pulse_rate'] ?: null;
        $newStatus = 'Active';
        $purpose = normalize_visit_purpose($_POST['visit_purpose'] ?? null);
        $actionTaken = trim($_POST['action_taken'] ?? '');

        $db = db();
        try {
            $dispensingRequest = visit_dispensing_request($_POST);
            $db->beginTransaction();

            $update = $db->prepare("
                UPDATE clinic_visits
                SET symptoms = ?, temperature = ?, blood_pressure = ?, pulse_rate = ?,
                    status = ?, visit_purpose = ?, action_taken = ?, recorded_by = ?, attended_by = ?
                WHERE id = ?
            ");
            $update->execute([
                $symptoms ?: null,
                $temperature,
                $bloodPressure,
                $pulseRate,
                $newStatus,
                $purpose,
                $actionTaken ?: null,
                current_user()['id'],
                current_user()['id'],
                $id,
            ]);

            $borrower = visit_patient_borrower($db, (int) $existingVisit['patient_id']);
            $dispensed = process_visit_inventory_request($db, $dispensingRequest, $borrower['name'], $borrower['identifier']);
            $referralType = trim($_POST['referral_type'] ?? '');
            if ($referralType === 'None') {
                $referralType = '';
            }
            $remarks = trim($_POST['remarks'] ?? '');
            $entry = [
                'symptoms_note' => trim($_POST['follow_up_note'] ?? '') ?: $postedSymptoms,
                'diagnosis' => trim($_POST['diagnosis'] ?? ''),
                'management_treatment' => $actionTaken,
                'referral_type' => $referralType,
                'remarks' => $remarks,
            ];

            if (treatment_entry_has_content($entry) || $dispensed) {
                $insert = $db->prepare(
                    'INSERT INTO visit_treatment_entries (visit_id, symptoms_note, diagnosis, management_treatment, referral_type, remarks, dispensed_inventory_item_id, dispensed_quantity, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $firstDispensed = $dispensed[0] ?? [];
                $insert->execute([
                    $id,
                    $entry['symptoms_note'] ?: null,
                    $entry['diagnosis'] ?: null,
                    $entry['management_treatment'] ?: null,
                    $entry['referral_type'] ?: null,
                    $entry['remarks'] ?: null,
                    $firstDispensed['item_id'] ?? null,
                    $firstDispensed['quantity'] ?? null,
                    current_user()['id'],
                ]);
                save_visit_treatment_dispensings($db, (int) $db->lastInsertId(), $id, $dispensed);
            }

            $db->commit();
            flash_message('success', $dispensed ? 'Visit addressed and inventory updated.' : 'Visit addressed from the nurse station.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
        }
    } elseif ($existingVisit && $mode === 'append' && $returnFrom === 'profile') {
        $referralType = trim($_POST['referral_type'] ?? '');
        if ($referralType === 'None') {
            $referralType = '';
        }

        try {
            $dispensingRequest = visit_dispensing_request($_POST);
            $hasDispensing = !empty($dispensingRequest);
            $entry = [
                'symptoms_note' => trim($_POST['symptoms_note'] ?? ''),
                'diagnosis' => trim($_POST['diagnosis'] ?? ''),
                'management_treatment' => trim($_POST['management_treatment'] ?? ''),
                'referral_type' => $referralType,
                'remarks' => trim($_POST['remarks'] ?? ''),
                'amendment_reason' => trim($_POST['amendment_reason'] ?? ''),
            ];
            $newStatus = normalize_visit_status($_POST['status'] ?? null, $existingVisit['status'] ?: 'Active');
            $statusChanged = $newStatus !== ($existingVisit['status'] ?: 'Unaddressed');

            if ((treatment_entry_has_content($entry) || $statusChanged || $hasDispensing) && $entry['amendment_reason'] === '') {
                flash_message('warning', 'Enter the reason for the amendment before saving.');
            } elseif (treatment_entry_has_content($entry) || $statusChanged || $hasDispensing) {
                $db = db();
                $db->beginTransaction();
                $borrower = visit_patient_borrower($db, (int) $existingVisit['patient_id']);
                $dispensed = process_visit_inventory_request($db, $dispensingRequest, $borrower['name'], $borrower['identifier']);

                $insert = $db->prepare(
                    'INSERT INTO visit_treatment_entries (visit_id, symptoms_note, diagnosis, management_treatment, referral_type, remarks, amendment_reason, dispensed_inventory_item_id, dispensed_quantity, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $firstDispensed = $dispensed[0] ?? [];
                $insert->execute([
                    $id,
                    $entry['symptoms_note'] ?: null,
                    $entry['diagnosis'] ?: null,
                    $entry['management_treatment'] ?: null,
                    $entry['referral_type'] ?: null,
                    $entry['remarks'] ?: null,
                    $entry['amendment_reason'] ?: null,
                    $firstDispensed['item_id'] ?? null,
                    $firstDispensed['quantity'] ?? null,
                    current_user()['id'],
                ]);
                save_visit_treatment_dispensings($db, (int) $db->lastInsertId(), $id, $dispensed);

                $update = $db->prepare('UPDATE clinic_visits SET status = ?, attended_by = COALESCE(attended_by, ?) WHERE id = ?');
                $update->execute([$newStatus, current_user()['id'], $id]);
                $db->commit();
                flash_message('success', $dispensed ? 'Treatment information appended and inventory updated.' : (treatment_entry_has_content($entry) ? 'Treatment information appended.' : 'Visit status updated with amendment reason.'));
            } else {
                flash_message('warning', 'Add treatment details, dispense inventory, or change the visit status before saving.');
            }
        } catch (Throwable $e) {
            if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }
            flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
        }
    } else {
        flash_message('error', 'This action is not available for the current visit status.');
    }

    if ($returnTo === 'index') {
        header('Location: index.php');
    } else {
        header('Location: view.php?id=' . $id . '&from=' . urlencode($returnFrom));
    }
    exit;
}

$stmt = db()->prepare("
    SELECT v.*, p.first_name, p.middle_name, p.last_name, p.student_number, p.course_section, p.birthdate, p.sex,
           p.blood_type, p.allergies, p.existing_conditions, p.guardian_name, p.guardian_contact,
           u.name AS recorded_by_name, au.name AS attended_by_name
    FROM clinic_visits v
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users u ON u.id = v.recorded_by
    LEFT JOIN users au ON au.id = v.attended_by
    WHERE v.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$visit = $stmt->fetch();

if (!$visit) {
    render_header('Visit Not Found');
    ?>
    <div class="empty-state" style="min-height: 40vh;">
        <span class="material-symbols-outlined">clinical_notes</span>
        <p class="empty-state-title">Visit not found</p>
        <p class="empty-state-text">The clinic visit record you're looking for doesn't exist.</p>
        <a href="index.php" class="btn btn-primary mt-4 text-decoration-none">Back to Clinic Logbook</a>
    </div>
    <?php
    render_footer();
    exit;
}

$entriesStmt = db()->prepare("
    SELECT t.*, u.name AS created_by_name
    FROM visit_treatment_entries t
    LEFT JOIN users u ON u.id = t.created_by
    WHERE t.visit_id = ?
    ORDER BY t.created_at DESC, t.id DESC
");
$entriesStmt->execute([$id]);
$entries = $entriesStmt->fetchAll();
$entryDispensings = treatment_entry_dispensing_map(array_column($entries, 'id'));
$medicineInventory = visit_medicine_inventory_options();
$equipmentInventory = visit_equipment_inventory_options();

$fullName = trim($visit['first_name'] . ' ' . $visit['last_name']);
$status = $visit['status'] ?: 'Unaddressed';
$isProfileMode = $entryPoint === 'profile';
$showLogbookSheet = !$isProfileMode;
$canAddressFromLogbook = !$isProfileMode && $status === 'Unaddressed';
$canEndFromLogbook = !$isProfileMode && $status === 'Active';
$canTreatFromLogbook = !$isProfileMode && $status === 'Active';
$isReadOnlyLogbook = $showLogbookSheet && !$canAddressFromLogbook && !$canTreatFromLogbook;
$showBeginTreatmentModal = $canAddressFromLogbook && ($_GET['begin'] ?? '') === '1';
$existingActionForIntake = trim((string) $visit['action_taken']);
if (str_contains(strtolower($existingActionForIntake), 'awaiting')) {
    $existingActionForIntake = '';
}
$latestEntry = $entries[0] ?? [];
$sheetSymptoms = trim((string) ($latestEntry['symptoms_note'] ?? '')) ?: (trim((string) $visit['symptoms']) ?: 'No symptoms recorded.');
$sheetDiagnosis = trim((string) ($latestEntry['diagnosis'] ?? '')) ?: '';
$sheetManagement = trim((string) ($latestEntry['management_treatment'] ?? '')) ?: (trim((string) $visit['action_taken']) ?: '');
$sheetReferral = trim((string) ($latestEntry['referral_type'] ?? '')) ?: 'None';
$sheetRemarks = trim((string) ($latestEntry['remarks'] ?? '')) ?: '';
$sheetDispensedItemId = (int) ($latestEntry['dispensed_inventory_item_id'] ?? 0);
$sheetDispensedQuantity = (int) ($latestEntry['dispensed_quantity'] ?? 0);
$sheetDispensings = isset($latestEntry['id']) ? ($entryDispensings[(int) $latestEntry['id']] ?? []) : [];
$timeOfArrival = $visit['visit_datetime'] ? date('g:i A', strtotime($visit['visit_datetime'])) : 'Not recorded';
$submittedPurpose = trim((string) ($visit['visit_purpose'] ?? ''));
if ($submittedPurpose === '' && str_contains((string) $visit['chief_complaint'], ' - ')) {
    $submittedPurpose = trim(strstr((string) $visit['chief_complaint'], ' - ', true));
}
$submittedPurpose = $submittedPurpose !== '' ? $submittedPurpose : 'Medical Consultation';
$displayChiefComplaint = trim((string) $visit['chief_complaint']);
if (str_contains($displayChiefComplaint, ' - ')) {
    [$possiblePurpose, $possibleComplaint] = array_map('trim', explode(' - ', $displayChiefComplaint, 2));
    if (strcasecmp($possiblePurpose, $submittedPurpose) === 0 && $possibleComplaint !== '') {
        $displayChiefComplaint = $possibleComplaint;
    }
}
$registrationMeta = [];
foreach (preg_split('/\R/', (string) ($visit['symptoms'] ?? '')) as $line) {
    if (str_contains($line, ':')) {
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        $registrationMeta[$key] = $value;
    }
}
$courseDepartment = trim((string) ($registrationMeta['Course/Department'] ?? ''));
$yearLevel = trim((string) ($registrationMeta['Year Level'] ?? ''));
$courseParts = array_values(array_filter(array_map('trim', explode(' - ', (string) ($visit['course_section'] ?? '')))));
if ($courseDepartment === '' && count($courseParts) >= 3) {
    $courseDepartment = $courseParts[2];
}
if ($yearLevel === '' && count($courseParts) >= 2) {
    $yearLevel = $courseParts[1];
}
if ($courseDepartment === '') {
    $courseDepartment = $visit['course_section'] ?: 'Not specified';
}
if ($yearLevel === '') {
    $yearLevel = 'Not specified';
}
$handledByName = $visit['attended_by_name'] ?: ($canAddressFromLogbook ? current_user()['name'] : 'Not yet attended');
$logbookSymptomsValue = $isReadOnlyLogbook ? $sheetSymptoms : '';
$logbookDiagnosisValue = $isReadOnlyLogbook ? $sheetDiagnosis : '';
$logbookTreatmentValue = $isReadOnlyLogbook ? $sheetManagement : '';
$logbookReferralValue = $isReadOnlyLogbook ? $sheetReferral : 'None';
$logbookRemarksValue = $isReadOnlyLogbook ? $sheetRemarks : '';
$readOnlyAttr = $isReadOnlyLogbook ? ' readonly' : '';
$disabledAttr = $isReadOnlyLogbook ? ' disabled' : '';
$pageTitle = $canAddressFromLogbook ? 'Address Clinic Visit' : ($canEndFromLogbook ? 'End Clinic Visit' : 'Visit Treatment');
$visitBackUrl = $isProfileMode ? app_url('patients/view.php?id=' . (int) $visit['patient_id']) : 'index.php';
set_page_back_link($visitBackUrl, $isProfileMode ? 'Profile' : 'Logbook');
render_header($pageTitle);
?>

<?php if ($isProfileMode || $showLogbookSheet): ?>
<style>
    .record-sheet-field {
        width: 100%;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 0.625rem;
        background: #f8fafc;
        color: #0f172a;
        font-size: 0.8125rem;
        font-weight: 700;
        line-height: 1.35;
        height: 3.625rem;
        min-height: 3.625rem;
        padding: 0.75rem 1rem;
        box-sizing: border-box;
    }
    textarea.record-sheet-field {
        min-height: 7rem;
        height: auto;
        line-height: 1.35;
    }
    .record-sheet-field[readonly],
    .record-sheet-field:disabled {
        cursor: default;
        opacity: 1;
    }
    .record-sheet-display {
        width: 100%;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 0.625rem;
        background: #f8fafc;
        color: #0f172a;
        font-size: 0.8125rem;
        font-weight: 700;
        min-height: 3.625rem;
        padding: 0.75rem 1rem;
        line-height: 1.35;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        display: flex;
        align-items: center;
    }
    .amendment-mode .amendable-field {
        background: #fffbeb !important;
        border-color: #f59e0b !important;
        box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.12);
    }
    .amendment-only {
        display: none;
    }
    .amendment-mode .amendment-only {
        display: block;
    }
    .treatment-sheet {
        max-width: 64rem;
    }
    .treatment-sheet .clinic-card {
        border-radius: 0.75rem;
    }
    .treatment-sheet h2 {
        font-size: 1rem;
        letter-spacing: 0;
        min-height: 1.85rem;
    }
    .treatment-sheet h2 > .material-symbols-outlined {
        width: 1.85rem;
        height: 1.85rem;
        border-radius: 0.55rem;
        background: #ecfdf5;
        color: var(--cliniq-primary);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex: 0 0 auto;
    }
    .sheet-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        align-items: center;
        justify-content: flex-end;
    }
    .sheet-chip-button {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        border-radius: 999px;
        padding: 0.45rem 0.8rem;
        font-size: 0.62rem;
        line-height: 1;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        border: 1px solid #cfe6d6;
        background: #f0fdf4;
        color: var(--cliniq-primary);
        text-decoration: none;
        transition: background 0.16s ease, transform 0.16s ease;
    }
    .sheet-chip-button:hover {
        background: #dcfce7;
        transform: translateY(-1px);
    }
    .sheet-chip-button.danger {
        border-color: #fecaca;
        background: #fff1f2;
        color: #be123c;
    }
    .sheet-chip-button .material-symbols-outlined {
        font-size: 0.88rem;
    }
    .vitals-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem;
    }
    .vital-tile {
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 0.75rem;
        background: #f8fafc;
        padding: 1rem;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
    }
    .vital-label {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        color: #94a3b8;
        font-size: 0.68rem;
        font-weight: 900;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        margin-bottom: 0.75rem;
    }
    .vital-label .material-symbols-outlined {
        font-size: 1rem;
        color: #94a3b8;
    }
    .vital-value-wrap {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.75rem;
    }
    .vital-input {
        width: 100%;
        border: 1px solid #dbe3ee;
        border-radius: 0.5rem;
        background: #ffffff;
        color: #0f172a;
        font-size: 0.8125rem;
        font-weight: 800;
        line-height: 1.35;
        min-height: 2.45rem;
        padding: 0.55rem 0.8rem;
    }
    .vital-input[readonly] {
        cursor: default;
    }
    .vital-unit {
        color: #94a3b8;
        font-size: 0.68rem;
        font-weight: 900;
    }
    @media (max-width: 900px) {
        .vitals-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 640px) {
        .vitals-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
<?php endif; ?>

<?php if ($isProfileMode): ?>
<form id="profileTreatmentForm" method="post" class="treatment-sheet mx-auto space-y-5">
    <input type="hidden" name="mode" value="append">
    <input type="hidden" name="from" value="profile">

    <section class="clinic-card p-6 flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div class="flex items-center gap-4">
            <div class="avatar w-14 h-14 text-base <?= avatar_color($fullName) ?>"><?= initials($fullName) ?></div>
            <div>
                <h1 class="font-headline text-2xl md:text-3xl font-extrabold text-[#1c2a59] mb-1"><?= e($fullName) ?></h1>
                <div class="flex flex-wrap items-center gap-3 text-xs font-bold text-slate-500">
                    <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">badge</span><?= e($visit['student_number']) ?></span>
                    <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">water_drop</span>Blood Type: <?= e($visit['blood_type'] ?: '-') ?></span>
                </div>
            </div>
        </div>
        <div class="sheet-actions">
            <span class="badge <?= visit_status_badge_class($status) ?>"><?= e($status) ?></span>
            <button type="button" id="appendRecordBtn" class="sheet-chip-button" onclick="enableProfileAmendment()">
                <span class="material-symbols-outlined text-[16px]">add_notes</span>
                Append Information
            </button>
            <?php if ($status === 'Active'): ?>
                <button type="submit" name="mode" value="end_visit" class="sheet-chip-button danger" data-confirm-submit data-confirm-type="danger" data-confirm-title="End this visit?" data-confirm-message="This will mark the patient visit as completed. You can still append amendments from the student's profile later." data-confirm-toast="Ending visit...">
                    <span class="material-symbols-outlined">logout</span>
                    End Visit
                </button>
            <?php endif; ?>
        </div>
    </section>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <section class="clinic-card p-6 space-y-5">
            <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-1">
                <span class="material-symbols-outlined text-primary text-[19px]">clinical_notes</span>
                Visit Information
            </h2>
            <div>
                <label class="clinic-label">Purpose of Visit</label>
                <input class="record-sheet-field px-4" value="<?= e($submittedPurpose) ?>" readonly>
            </div>
            <div>
                <label class="clinic-label">Chief Complaint</label>
                <div class="record-sheet-display"><?= e($displayChiefComplaint) ?></div>
            </div>
            <div>
                <label class="clinic-label">Handled By / Staff</label>
                <input class="record-sheet-field px-4" value="<?= e($handledByName) ?>" readonly>
            </div>
        </section>

        <section class="clinic-card p-6 space-y-5">
            <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-1">
                <span class="material-symbols-outlined text-primary text-[19px]">person</span>
                Patient Information
            </h2>
            <div>
                <label class="clinic-label">Course / Department</label>
                <input class="record-sheet-field px-4" value="<?= e($courseDepartment) ?>" readonly>
            </div>
            <div>
                <label class="clinic-label">Year Level</label>
                <input class="record-sheet-field px-4" value="<?= e($yearLevel) ?>" readonly>
            </div>
            <div>
                <label class="clinic-label">Time of Arrival</label>
                <input class="record-sheet-field px-4" value="<?= e($timeOfArrival) ?>" readonly>
            </div>
        </section>
    </div>

    <section class="clinic-card p-6">
        <div class="flex items-center justify-between gap-3 mb-6">
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] flex items-center gap-3 m-0">
                <span class="material-symbols-outlined">monitor_heart</span>
                Vitals & Measurements
            </h2>
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Optional</span>
        </div>
        <div class="vitals-grid">
            <?php foreach ([
                ['favorite', 'BP', $visit['blood_pressure'] ?: '-', 'mmHg'],
                ['thermostat', 'Temp', $visit['temperature'] ?: '-', 'C'],
                ['ecg_heart', 'Heart', $visit['pulse_rate'] ? (string) $visit['pulse_rate'] : '-', 'BPM'],
                ['air', 'SpO2', $visit['spo2'] ?? '-', '%'],
                ['scale', 'Weight', $visit['weight_kg'] ?? '-', 'kg'],
                ['height', 'Height', $visit['height_cm'] ?? '-', 'cm'],
            ] as [$icon, $label, $value, $unit]): ?>
                <div class="vital-tile">
                    <div class="vital-label">
                        <span class="material-symbols-outlined"><?= e($icon) ?></span>
                        <?= e($label) ?>
                    </div>
                    <div class="vital-value-wrap">
                        <input class="vital-input" value="<?= e($value) ?>" readonly>
                        <span class="vital-unit"><?= e($unit) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="clinic-card p-6">
        <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-5">
            <span class="material-symbols-outlined text-primary text-[19px]">inventory_2</span>
            Inventory & Dispensing
        </h2>
        <?php if ($sheetDispensings): ?>
            <div class="mb-4 rounded-xl border border-slate-100 bg-slate-50 p-4">
                <p class="clinic-label mb-2">Previously Recorded Dispensing</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($sheetDispensings as $item): ?>
                        <span class="badge badge-in-progress"><?= e($item['item_type'] . ': ' . $item['item_name'] . ' - Qty: ' . (int) $item['quantity'] . ' ' . $item['unit']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="space-y-3" data-dispensing-list>
            <div class="grid grid-cols-1 md:grid-cols-[0.7fr_1.6fr_0.55fr_auto] gap-4 items-end" data-dispensing-row>
                <div>
                    <label class="clinic-label">Type</label>
                    <select class="record-sheet-field px-4 amendable-field js-dispensing-type" name="dispensing_type[]" disabled data-amendable>
                        <option value="Medicine">Medicine</option>
                        <option value="Equipment">Equipment</option>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Medicine / Equipment</label>
                    <select class="record-sheet-field px-4 amendable-field js-visit-inventory-item" name="dispensed_inventory_item_id[]" disabled data-amendable>
                        <option value="" data-type="Medicine">No item selected</option>
                        <?php foreach ($medicineInventory as $medicine): ?>
                            <option value="<?= (int) $medicine['id'] ?>" data-type="Medicine" <?= (int) $medicine['quantity'] <= 0 ? 'disabled' : '' ?>>
                                <?= e($medicine['item_name']) ?> (<?= (int) $medicine['quantity'] ?> <?= e($medicine['unit']) ?>)
                            </option>
                        <?php endforeach; ?>
                        <?php foreach ($equipmentInventory as $equipment): ?>
                            <option value="<?= (int) $equipment['id'] ?>" data-type="Equipment" <?= (int) $equipment['quantity'] <= 0 ? 'disabled' : '' ?>>
                                <?= e($equipment['item_name']) ?> (<?= (int) $equipment['quantity'] ?> <?= e($equipment['unit']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Quantity</label>
                    <input class="record-sheet-field px-4 amendable-field" name="dispensed_quantity[]" type="number" min="1" placeholder="0" readonly data-amendable>
                </div>
                <button type="button" class="btn btn-ghost js-remove-dispensing-row amendment-only" title="Remove item" aria-label="Remove item">
                    <span class="material-symbols-outlined text-[18px]">delete</span>
                </button>
            </div>
        </div>
        <button type="button" class="btn btn-outline mt-4 js-add-dispensing-row amendment-only">
            <span class="material-symbols-outlined text-[18px]">add</span>
            Add Item
        </button>
    </section>

    <section class="clinic-card p-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-5">
            <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 m-0">
                <span class="material-symbols-outlined text-primary text-[19px]">medical_information</span>
                Medical Record
            </h2>
            <div class="w-full md:w-52 amendment-only">
                <label class="clinic-label">Set Status</label>
                <select class="record-sheet-field px-4 amendable-field" name="status" disabled data-amendable>
                    <?php foreach (visit_statuses() as $option): ?>
                        <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="clinic-label">Symptoms</label>
                <textarea class="record-sheet-field p-4 amendable-field" name="symptoms_note" readonly data-amendable><?= e($sheetSymptoms) ?></textarea>
            </div>
            <div>
                <label class="clinic-label">Diagnosis</label>
                <textarea class="record-sheet-field p-4 amendable-field" name="diagnosis" readonly data-amendable><?= e($sheetDiagnosis) ?></textarea>
            </div>
            <div>
                <label class="clinic-label">Management / Treatment</label>
                <textarea class="record-sheet-field p-4 amendable-field" name="management_treatment" readonly data-amendable><?= e($sheetManagement) ?></textarea>
            </div>
            <div>
                <label class="clinic-label">Referral</label>
                <select class="record-sheet-field px-4 amendable-field" name="referral_type" disabled data-amendable>
                    <?php foreach (visit_referral_options() as $option): ?>
                        <option value="<?= e($option) ?>" <?= $sheetReferral === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="clinic-label">Remarks</label>
                <textarea class="record-sheet-field p-4 amendable-field" name="remarks" rows="4" placeholder="General remarks..." readonly data-amendable><?= e($sheetRemarks) ?></textarea>
            </div>
            <div class="md:col-span-3 amendment-only">
                <label class="clinic-label">Reason for Amendment</label>
                <textarea class="record-sheet-field p-4 amendable-field" name="amendment_reason" rows="3" placeholder="Explain why this information is being appended or amended..." disabled data-amendable data-required-amendment></textarea>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-between gap-3">
            <a href="<?= app_url('patients/view.php?id=' . (int) $visit['patient_id']) ?>" class="btn btn-ghost text-decoration-none">
                <span class="material-symbols-outlined text-[16px]">history</span>
                View History
            </a>
            <div class="flex flex-wrap gap-3">
                <button id="saveAmendmentBtn" class="btn btn-primary amendment-only" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save this amendment?" data-confirm-message="This will add a new append-only treatment entry and keep the original visit details unchanged." data-confirm-toast="Saving amendment...">
                    <span class="material-symbols-outlined text-[18px]">save</span>
                    Save Amendment
                </button>
            </div>
        </div>
    </section>

    <section class="clinic-card overflow-hidden">
        <div class="p-6 border-b border-slate-100">
            <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] m-0">Append History</h2>
            <p class="text-xs font-bold text-slate-500 m-0"><?= count($entries) ?> saved entr<?= count($entries) === 1 ? 'y' : 'ies' ?></p>
        </div>
        <?php if ($entries): ?>
            <div class="divide-y divide-outline-variant/10">
                <?php foreach ($entries as $entry): ?>
                    <?php $dispensedItems = $entryDispensings[(int) $entry['id']] ?? []; ?>
                    <article class="p-6">
                        <div class="flex flex-col md:flex-row md:items-start justify-between gap-3 mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-800 mb-1"><?= e($entry['created_by_name'] ?: 'Clinic Staff') ?></p>
                                <p class="text-xs font-bold text-slate-400 mb-0"><?= e(date('M d, Y g:i A', strtotime($entry['created_at']))) ?></p>
                            </div>
                            <?php if ($entry['referral_type']): ?>
                                <span class="badge badge-in-progress"><?= e($entry['referral_type']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ([
                                'symptoms_note' => 'Symptoms / Follow-up',
                                'diagnosis' => 'Diagnosis',
                                'management_treatment' => 'Management / Treatment',
                                'remarks' => 'Remarks',
                                'amendment_reason' => 'Reason for Amendment',
                            ] as $field => $label): ?>
                                <?php if (!empty($entry[$field])): ?>
                                    <div>
                                        <p class="clinic-label"><?= e($label) ?></p>
                                        <p class="text-sm font-bold text-slate-700 leading-relaxed"><?= nl2br(e($entry[$field])) ?></p>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($dispensedItems): ?>
                                <div>
                                    <p class="clinic-label mb-1">Dispensed Inventory</p>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($dispensedItems as $item): ?>
                                            <span class="badge badge-in-progress"><?= e($item['item_type'] . ': ' . $item['item_name'] . ' - Qty: ' . (int) $item['quantity'] . ' ' . $item['unit']) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-outlined">medical_services</span>
                <p class="empty-state-title">No appended treatment yet</p>
                <p class="empty-state-text">Amendments and appended treatment information will appear here.</p>
            </div>
        <?php endif; ?>
    </section>
</form>

<script>
    function enableProfileAmendment() {
        const form = document.getElementById('profileTreatmentForm');
        form.classList.add('amendment-mode');
        document.getElementById('appendRecordBtn')?.setAttribute('disabled', 'disabled');
        form.querySelectorAll('[data-amendable]').forEach((field) => {
            field.removeAttribute('readonly');
            field.removeAttribute('disabled');
        });
        form.querySelector('[data-required-amendment]')?.setAttribute('required', 'required');
    }
</script>

<?php render_footer(); exit; ?>
<?php endif; ?>

<?php if ($showLogbookSheet): ?>
<form id="logbookIntakeForm" method="post" class="treatment-sheet mx-auto space-y-5">
    <input type="hidden" name="mode" value="save_treatment">
    <input type="hidden" name="from" value="logbook">

    <section class="clinic-card p-6 flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div class="flex items-center gap-4">
            <div class="avatar w-14 h-14 text-base <?= avatar_color($fullName) ?>"><?= initials($fullName) ?></div>
            <div>
                <p class="clinic-label mb-1"><?= $isReadOnlyLogbook ? 'Visit Treatment Record' : 'Nurse Station Intake' ?></p>
                <h1 class="font-headline text-2xl md:text-3xl font-extrabold text-[#1c2a59] mb-1"><?= e($fullName) ?></h1>
                <div class="flex flex-wrap items-center gap-3 text-xs font-bold text-slate-500">
                    <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">badge</span><?= e($visit['student_number']) ?></span>
                    <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">water_drop</span>Blood Type: <?= e($visit['blood_type'] ?: '-') ?></span>
                </div>
            </div>
        </div>
        <div class="sheet-actions">
            <span class="badge <?= visit_status_badge_class($status) ?>"><?= e($status) ?></span>
            <?php if ($canAddressFromLogbook): ?>
                <span class="badge badge-active">Ready to Start</span>
                <button type="submit" form="beginTreatmentForm" class="sheet-chip-button" data-confirm-submit data-confirm-type="primary" data-confirm-title="Begin treatment?" data-confirm-message="This will mark the visit as Active because the nurse is now attending to the patient." data-confirm-toast="Starting treatment...">
                    <span class="material-symbols-outlined">play_arrow</span>
                    Begin Treatment
                </button>
                <button type="submit" form="logbookNoShowForm" class="sheet-chip-button danger" data-confirm-submit data-confirm-type="danger" data-confirm-title="Mark as no visit?" data-confirm-message="This will cancel the unaddressed logbook entry because the patient did not proceed to the nurse station." data-confirm-toast="Marking no visit...">
                    <span class="material-symbols-outlined">cancel</span>
                    No Visit
                </button>
            <?php elseif ($canTreatFromLogbook): ?>
                <button type="submit" form="logbookIntakeForm" class="sheet-chip-button" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save treatment?" data-confirm-message="This will save the current vitals, assessment, diagnosis, treatment, referral, and remarks for this active visit." data-confirm-toast="Saving treatment...">
                    <span class="material-symbols-outlined">save</span>
                    Save Treatment
                </button>
                <button type="submit" form="endLogbookVisitForm" class="sheet-chip-button danger" data-confirm-submit data-confirm-type="danger" data-confirm-title="End this visit?" data-confirm-message="This will mark the active visit as completed." data-confirm-toast="Ending visit...">
                    <span class="material-symbols-outlined">check_circle</span>
                    End Visit
                </button>
            <?php else: ?>
                <a href="index.php" class="sheet-chip-button">
                    <span class="material-symbols-outlined">list</span>
                    Logbook
                </a>
                <a href="<?= app_url('patients/view.php?id=' . (int) $visit['patient_id']) ?>" class="sheet-chip-button">
                    <span class="material-symbols-outlined">person</span>
                    Profile
                </a>
            <?php endif; ?>
        </div>
    </section>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <section class="clinic-card p-6 space-y-5">
            <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-1">
                <span class="material-symbols-outlined text-primary text-[19px]">clinical_notes</span>
                Visit Information
            </h2>
            <div>
                <label class="clinic-label">Purpose of Visit</label>
                <input type="hidden" name="visit_purpose" value="<?= e($submittedPurpose) ?>">
                <div class="record-sheet-display"><?= e($submittedPurpose) ?></div>
            </div>
            <div>
                <label class="clinic-label">Chief Complaint</label>
                <div class="record-sheet-display"><?= e($displayChiefComplaint) ?></div>
            </div>
            <div>
                <label class="clinic-label">Handled By / Staff</label>
                <div class="record-sheet-display"><?= e($handledByName) ?></div>
            </div>
        </section>

        <section class="clinic-card p-6 space-y-5">
            <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-1">
                <span class="material-symbols-outlined text-primary text-[19px]">person</span>
                Patient Information
            </h2>
            <div>
                <label class="clinic-label">Course / Department</label>
                <input class="record-sheet-field px-4" value="<?= e($courseDepartment) ?>" readonly>
            </div>
            <div>
                <label class="clinic-label">Year Level</label>
                <input class="record-sheet-field px-4" value="<?= e($yearLevel) ?>" readonly>
            </div>
            <div>
                <label class="clinic-label">Time of Arrival</label>
                <input class="record-sheet-field px-4" value="<?= e($timeOfArrival) ?>" readonly>
            </div>
        </section>
    </div>

    <section class="clinic-card p-6">
        <div class="flex items-center justify-between gap-3 mb-6">
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] flex items-center gap-3 m-0">
                <span class="material-symbols-outlined">monitor_heart</span>
                Vitals & Measurements
            </h2>
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Nurse Input</span>
        </div>
        <div class="vitals-grid">
            <div class="vital-tile">
                <div class="vital-label"><span class="material-symbols-outlined">favorite</span>BP</div>
                <div class="vital-value-wrap">
                    <input class="vital-input" name="blood_pressure" value="<?= e($visit['blood_pressure'] ?: '') ?>" placeholder="0"<?= $readOnlyAttr ?>>
                    <span class="vital-unit">mmHg</span>
                </div>
            </div>
            <div class="vital-tile">
                <div class="vital-label"><span class="material-symbols-outlined">thermostat</span>Temp</div>
                <div class="vital-value-wrap">
                    <input class="vital-input" name="temperature" type="number" step="0.1" value="<?= e($visit['temperature'] ? (string) $visit['temperature'] : '') ?>" placeholder="0"<?= $readOnlyAttr ?>>
                    <span class="vital-unit">C</span>
                </div>
            </div>
            <div class="vital-tile">
                <div class="vital-label"><span class="material-symbols-outlined">ecg_heart</span>Heart</div>
                <div class="vital-value-wrap">
                    <input class="vital-input" name="pulse_rate" type="number" value="<?= e($visit['pulse_rate'] ? (string) $visit['pulse_rate'] : '') ?>" placeholder="0"<?= $readOnlyAttr ?>>
                    <span class="vital-unit">BPM</span>
                </div>
            </div>
            <?php foreach ([
                ['air', 'SpO2', '%'],
                ['scale', 'Weight', 'kg'],
                ['height', 'Height', 'cm'],
            ] as [$icon, $label, $unit]): ?>
                <div class="vital-tile">
                    <div class="vital-label"><span class="material-symbols-outlined"><?= e($icon) ?></span><?= e($label) ?></div>
                    <div class="vital-value-wrap">
                        <input class="vital-input" placeholder="0"<?= $readOnlyAttr ?>>
                        <span class="vital-unit"><?= e($unit) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="clinic-card p-6">
        <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-5">
            <span class="material-symbols-outlined text-primary text-[19px]">inventory_2</span>
            Inventory & Dispensing
        </h2>
        <?php if ($isReadOnlyLogbook): ?>
            <?php if ($sheetDispensings): ?>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-4">
                    <p class="clinic-label mb-2">Recorded Dispensing</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($sheetDispensings as $item): ?>
                            <span class="badge badge-in-progress"><?= e($item['item_type'] . ': ' . $item['item_name'] . ' - Qty: ' . (int) $item['quantity'] . ' ' . $item['unit']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <p class="settings-help mb-0">No inventory was dispensed for this treatment entry.</p>
            <?php endif; ?>
        <?php else: ?>
            <div class="space-y-3" data-dispensing-list>
                <div class="grid grid-cols-1 md:grid-cols-[0.7fr_1.6fr_0.55fr_auto] gap-4 items-end" data-dispensing-row>
                    <div>
                        <label class="clinic-label">Type</label>
                        <select class="record-sheet-field px-4 js-dispensing-type" name="dispensing_type[]">
                            <option value="Medicine">Medicine</option>
                            <option value="Equipment">Equipment</option>
                        </select>
                    </div>
                    <div>
                        <label class="clinic-label">Medicine / Equipment</label>
                        <select class="record-sheet-field px-4 js-visit-inventory-item" name="dispensed_inventory_item_id[]">
                            <option value="" data-type="Medicine">No item selected</option>
                            <?php foreach ($medicineInventory as $medicine): ?>
                                <option value="<?= (int) $medicine['id'] ?>" data-type="Medicine" <?= (int) $medicine['quantity'] <= 0 ? 'disabled' : '' ?>>
                                    <?= e($medicine['item_name']) ?> (<?= (int) $medicine['quantity'] ?> <?= e($medicine['unit']) ?>)
                                </option>
                            <?php endforeach; ?>
                            <?php foreach ($equipmentInventory as $equipment): ?>
                                <option value="<?= (int) $equipment['id'] ?>" data-type="Equipment" <?= (int) $equipment['quantity'] <= 0 ? 'disabled' : '' ?>>
                                    <?= e($equipment['item_name']) ?> (<?= (int) $equipment['quantity'] ?> <?= e($equipment['unit']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="clinic-label">Quantity</label>
                        <input class="record-sheet-field px-4" name="dispensed_quantity[]" type="number" min="1" placeholder="0">
                    </div>
                    <button type="button" class="btn btn-ghost js-remove-dispensing-row" title="Remove item" aria-label="Remove item">
                        <span class="material-symbols-outlined text-[18px]">delete</span>
                    </button>
                </div>
            </div>
            <button type="button" class="btn btn-outline mt-4 js-add-dispensing-row">
                <span class="material-symbols-outlined text-[18px]">add</span>
                Add Item
            </button>
            <p class="settings-help mt-3 mb-0">Add multiple rows to dispense any combination of medicines and equipment.</p>
        <?php endif; ?>
    </section>

    <section class="clinic-card p-6">
        <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-5">
            <span class="material-symbols-outlined text-primary text-[19px]">medical_information</span>
            Medical Record
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="clinic-label">Nurse Symptoms / Assessment</label>
                <textarea class="record-sheet-field p-4" name="symptoms" placeholder="Confirm symptoms and add nurse observations..."<?= $readOnlyAttr ?>><?= e($logbookSymptomsValue) ?></textarea>
            </div>
            <div>
                <label class="clinic-label">Diagnosis</label>
                <textarea class="record-sheet-field p-4" name="diagnosis" placeholder="Clinical impression or diagnosis..."<?= $readOnlyAttr ?>><?= e($logbookDiagnosisValue) ?></textarea>
            </div>
            <div>
                <label class="clinic-label">Management / Treatment</label>
                <textarea class="record-sheet-field p-4" name="action_taken" placeholder="Treatment given, medication, monitoring, advice..."<?= $readOnlyAttr ?>><?= e($logbookTreatmentValue) ?></textarea>
            </div>
            <div>
                <label class="clinic-label">Referral</label>
                <select class="record-sheet-field px-4" name="referral_type"<?= $disabledAttr ?>>
                    <?php foreach (visit_referral_options() as $option): ?>
                        <option value="<?= e($option) ?>" <?= $logbookReferralValue === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="clinic-label">Remarks</label>
                <textarea class="record-sheet-field p-4" name="remarks" rows="4" placeholder="General remarks or follow-up instruction..."<?= $readOnlyAttr ?>><?= e($logbookRemarksValue) ?></textarea>
            </div>
        </div>
        <?php if ($canTreatFromLogbook): ?>
            <div class="mt-6 pt-5 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-end gap-3">
                <div class="flex flex-col sm:flex-row gap-3">
                    <button type="submit" form="cancelTreatmentForm" class="btn btn-danger justify-center" data-confirm-submit data-confirm-type="danger" data-confirm-title="Cancel this treatment?" data-confirm-message="This will cancel the active treatment session and mark the visit as Cancelled." data-confirm-toast="Cancelling treatment...">
                        <span class="material-symbols-outlined text-[18px]">cancel</span>
                        Cancel Treatment
                    </button>
                    <button class="btn btn-primary justify-center" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save treatment?" data-confirm-message="This will save the current vitals, assessment, diagnosis, treatment, referral, and remarks for this active visit." data-confirm-toast="Saving treatment...">
                        <span class="material-symbols-outlined text-[18px]">save</span>
                        Save Treatment
                    </button>
                </div>
            </div>
        <?php elseif ($isReadOnlyLogbook): ?>
            <div class="mt-6 pt-5 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-end gap-3">
                <a href="<?= app_url('patients/view.php?id=' . (int) $visit['patient_id']) ?>" class="btn btn-primary text-decoration-none justify-center">
                    <span class="material-symbols-outlined text-[18px]">person</span>
                    Open Student Profile
                </a>
            </div>
        <?php endif; ?>
    </section>
</form>

<form id="beginTreatmentForm" method="post" style="display:none;">
    <input type="hidden" name="mode" value="begin_visit">
    <input type="hidden" name="from" value="logbook">
</form>

<form id="endLogbookVisitForm" method="post" style="display:none;">
    <input type="hidden" name="mode" value="end_visit">
    <input type="hidden" name="from" value="logbook">
</form>

<form id="cancelTreatmentForm" method="post" style="display:none;">
    <input type="hidden" name="mode" value="cancel_treatment">
    <input type="hidden" name="from" value="logbook">
</form>

<form id="logbookNoShowForm" method="post" style="display:none;">
    <input type="hidden" name="mode" value="no_show">
    <input type="hidden" name="from" value="logbook">
</form>

<?php if ($showBeginTreatmentModal): ?>
<div class="modal-backdrop show" style="display:flex; align-items:center; justify-content:center;">
    <div class="modal-content bg-white rounded-xl w-full max-w-md p-8 shadow-2xl border border-outline-variant/10">
        <div class="w-12 h-12 rounded-xl bg-primary-fixed text-primary flex items-center justify-center mb-5">
            <span class="material-symbols-outlined">medical_services</span>
        </div>
        <h2 class="font-headline text-2xl font-extrabold text-[#1c2a59] mb-2">Begin Treatment?</h2>
        <p class="text-sm font-bold text-slate-500 leading-relaxed mb-6">
            This will mark the visit as Active now because the nurse is already attending to the patient while filling out the treatment sheet.
        </p>
        <div class="flex flex-col sm:flex-row gap-3">
            <a href="index.php" class="btn btn-ghost flex-1 text-decoration-none justify-center">Cancel</a>
            <button type="submit" form="beginTreatmentForm" class="btn btn-primary flex-1" data-confirm-submit data-confirm-type="primary" data-confirm-title="Begin treatment?" data-confirm-message="This will mark the visit as Active because the nurse is now attending to the patient." data-confirm-toast="Starting treatment...">
                <span class="material-symbols-outlined text-[18px]">play_arrow</span>
                Begin Treatment
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function syncDispensingRow(row) {
    const typeSelect = row.querySelector('.js-dispensing-type');
    const itemSelect = row.querySelector('.js-visit-inventory-item');
    if (!typeSelect || !itemSelect) return;

    const activeType = typeSelect.value || 'Medicine';
    let currentVisible = false;
    Array.from(itemSelect.options).forEach((option) => {
        const optionType = option.dataset.type || 'Medicine';
        const visible = option.value === '' || optionType === activeType;
        option.hidden = !visible;
        if (option.selected && visible) currentVisible = true;
    });
    if (!currentVisible) itemSelect.value = '';
}

function updateDispensingRemoveButtons(list) {
    const rows = list.querySelectorAll('[data-dispensing-row]');
    rows.forEach((row) => {
        const removeButton = row.querySelector('.js-remove-dispensing-row');
        if (removeButton) removeButton.disabled = rows.length === 1;
    });
}

document.querySelectorAll('[data-dispensing-list]').forEach((list) => {
    list.querySelectorAll('[data-dispensing-row]').forEach(syncDispensingRow);
    updateDispensingRemoveButtons(list);

    list.addEventListener('change', (event) => {
        const typeSelect = event.target.closest('.js-dispensing-type');
        if (typeSelect) syncDispensingRow(typeSelect.closest('[data-dispensing-row]'));
    });
});

document.addEventListener('click', (event) => {
    const addButton = event.target.closest('.js-add-dispensing-row');
    if (addButton) {
        const section = addButton.closest('section');
        const list = section?.querySelector('[data-dispensing-list]');
        const firstRow = list?.querySelector('[data-dispensing-row]');
        if (!list || !firstRow) return;

        const clone = firstRow.cloneNode(true);
        clone.querySelectorAll('select, input').forEach((field) => {
            field.value = '';
            if (field.classList.contains('js-dispensing-type')) field.value = 'Medicine';
            if (field.matches('[data-amendable]')) {
                field.removeAttribute('readonly');
                field.removeAttribute('disabled');
            }
        });
        list.appendChild(clone);
        syncDispensingRow(clone);
        updateDispensingRemoveButtons(list);
    }

    const removeButton = event.target.closest('.js-remove-dispensing-row');
    if (removeButton) {
        const row = removeButton.closest('[data-dispensing-row]');
        const list = row?.closest('[data-dispensing-list]');
        if (!row || !list || list.querySelectorAll('[data-dispensing-row]').length <= 1) return;
        row.remove();
        updateDispensingRemoveButtons(list);
    }
});
</script>

<?php render_footer(); exit; ?>
<?php endif; ?>
