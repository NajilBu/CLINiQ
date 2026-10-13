<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/data_normalization.php';
require_once __DIR__ . '/../helpers/patient_category.php';
require_once __DIR__ . '/AccountValidation.php';
require_once __DIR__ . '/SystemSettings.php';
require_once __DIR__ . '/CliniqInventoryWorkflow.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/PatientNotification.php';

function cliniq_visit_db(): PDO
{
    return auth_db();
}

function cliniq_visit_staff_person_id(?array $user = null): int
{
    $user ??= current_user();
    $personId = (int) ($user['person_id'] ?? 0);
    if ($personId < 1) {
        throw new RuntimeException('The logged-in staff account is not linked to Cliniq_db.');
    }

    $stmt = cliniq_visit_db()->prepare('SELECT 1 FROM clinic_staff WHERE person_id = ? LIMIT 1');
    $stmt->execute([$personId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('The logged-in account is not a clinic staff profile.');
    }

    return $personId;
}

function cliniq_visit_status(string $value, string $fallback = 'Active'): string
{
    $value = trim($value);
    return in_array($value, ['Unaddressed', 'Active', 'Completed', 'Cancelled'], true)
        ? $value
        : $fallback;
}

/** @return array<int,array<string,mixed>> */
function cliniq_visit_patients(string $search = '', int $limit = 500): array
{
    $limit = max(1, min(1000, $limit));
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = 'WHERE pe.id_number LIKE ? OR pe.first_name LIKE ? OR pe.last_name LIKE ?';
        $like = '%' . trim($search) . '%';
        $params = [$like, $like, $like];
    }

    $stmt = cliniq_visit_db()->prepare("
        SELECT
            pe.id,
            pe.id AS person_id,
            pe.id_number,
            pe.first_name,
            pe.middle_name,
            pe.last_name,
            pe.birthdate,
            pe.sex,
            CASE
                WHEN s.person_id IS NOT NULL THEN 'Student'
                WHEN se.person_id IS NOT NULL THEN se.role_classification
                WHEN cs.person_id IS NOT NULL THEN 'Staff'
                ELSE 'Patient'
            END AS patient_type,
            COALESCE(
                NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))) COLLATE utf8mb4_bin, _utf8mb4'' COLLATE utf8mb4_bin),
                ed.department_code,
                cd.department_code,
                'Patient'
            ) AS course_section
        FROM patients pt
        JOIN people pe ON pe.id = pt.person_id
        LEFT JOIN students s ON s.person_id = pe.id
        LEFT JOIN programs pr ON pr.id = s.program_id
        LEFT JOIN school_employees se ON se.person_id = pe.id
        LEFT JOIN departments ed ON ed.id = se.department_id
        LEFT JOIN clinic_staff cs ON cs.person_id = pe.id
        LEFT JOIN departments cd ON cd.id = cs.department_id
        {$where}
        ORDER BY pe.last_name, pe.first_name, pe.id_number
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    return array_map(static function (array $patient): array {
        $patient['patient_type_display'] = patient_category_label($patient['patient_type'] ?? '');
        return $patient;
    }, $stmt->fetchAll());
}

function cliniq_visit_patient_by_id_number(string $idNumber): ?array
{
    require_once __DIR__ . '/../helpers/student_id.php';
    [$preferredId, $alternateId] = visit_id_number_candidates($idNumber);
    $stmt = cliniq_visit_db()->prepare("
        SELECT pe.id AS person_id, pe.id_number, pe.first_name, pe.middle_name, pe.last_name,
               CASE
                   WHEN s.person_id IS NOT NULL THEN 'Student'
                   WHEN se.person_id IS NOT NULL THEN se.role_classification
                   WHEN cs.person_id IS NOT NULL THEN 'Staff'
                   ELSE 'Patient'
               END AS patient_type,
               s.year_level,
               COALESCE(
                   NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))) COLLATE utf8mb4_bin, _utf8mb4'' COLLATE utf8mb4_bin),
                   ed.department_code,
                   cd.department_code,
                   'Patient'
               ) AS course_section
        FROM patients pt
        JOIN people pe ON pe.id = pt.person_id
        LEFT JOIN students s ON s.person_id = pe.id
        LEFT JOIN programs pr ON pr.id = s.program_id
        LEFT JOIN school_employees se ON se.person_id = pe.id
        LEFT JOIN departments ed ON ed.id = se.department_id
        LEFT JOIN clinic_staff cs ON cs.person_id = pe.id
        LEFT JOIN departments cd ON cd.id = cs.department_id
        WHERE pe.id_number IN (?, ?)
        ORDER BY CASE WHEN pe.id_number = ? THEN 0 ELSE 1 END, pe.id
        LIMIT 1
    ");
    $stmt->execute([$preferredId, $alternateId, $preferredId]);
    $patient = $stmt->fetch() ?: null;
    if ($patient) {
        $patient['patient_type_display'] = patient_category_label($patient['patient_type'] ?? '');
    }

    return $patient;
}

function cliniq_visit_patient_exists(int $personId): bool
{
    $stmt = cliniq_visit_db()->prepare('SELECT 1 FROM patients WHERE person_id = ? LIMIT 1');
    $stmt->execute([$personId]);
    return (bool) $stmt->fetchColumn();
}

/** @return array<int,array{id:int,name:string}> */
function cliniq_visit_staff_members(): array
{
    return cliniq_visit_db()->query("
        SELECT cs.person_id AS id,
               TRIM(CONCAT_WS(' ', NULLIF(cs.honorific, ''), pe.first_name, pe.middle_name, pe.last_name)) AS name
        FROM clinic_staff cs
        JOIN people pe ON pe.id = cs.person_id
        ORDER BY pe.last_name, pe.first_name
    ")->fetchAll();
}

/** @return array<int,array{id:int,name:string,role:string}> */
function cliniq_visit_attending_clinicians(): array
{
    return cliniq_visit_db()->query("
        SELECT cs.person_id AS id,
               TRIM(CONCAT_WS(' ', NULLIF(cs.honorific, ''), pe.first_name, pe.middle_name, pe.last_name)) AS name,
               cs.staff_role AS role
        FROM clinic_staff cs
        JOIN people pe ON pe.id = cs.person_id
        JOIN accounts a ON a.person_id = cs.person_id
        WHERE cs.staff_role IN ('doctor', 'nurse')
          AND a.account_status = 'active'
        ORDER BY FIELD(cs.staff_role, 'doctor', 'nurse'), pe.last_name, pe.first_name
    ")->fetchAll();
}

function cliniq_visit_attending_clinician_id(int $personId): int
{
    if ($personId < 1) {
        throw new InvalidArgumentException('Select the doctor or nurse who attended the patient.');
    }

    $stmt = cliniq_visit_db()->prepare("
        SELECT 1
        FROM clinic_staff cs
        JOIN accounts a ON a.person_id = cs.person_id
        WHERE cs.person_id = ?
          AND cs.staff_role IN ('doctor', 'nurse')
          AND a.account_status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$personId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('Select an active doctor or nurse who attended the patient.');
    }

    return $personId;
}

function cliniq_visit_entry_has_content(array $entry): bool
{
    foreach (['symptoms', 'diagnosis', 'treatment', 'referral', 'remarks', 'amendment_reason'] as $key) {
        if (trim((string) ($entry[$key] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

function cliniq_visit_has_vitals(array $vitals): bool
{
    foreach (['temperature', 'blood_pressure', 'pulse_rate'] as $key) {
        if (trim((string) ($vitals[$key] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

function cliniq_visit_extract_patient_concerns(?string $value): string
{
    $text = str_replace('Visitor notes:', 'Patient Concerns:', trim((string) $value));
    if ($text === '') {
        return '';
    }

    if (preg_match('/^Patient Concerns:\s*(.+)$/mi', $text, $matches)) {
        return trim($matches[1]);
    }

    return $text;
}

function cliniq_visit_insert_entry(PDO $db, int $visitId, array $entry, ?int $staffPersonId): ?int
{
    if (!cliniq_visit_entry_has_content($entry)) {
        return null;
    }

    $stmt = $db->prepare('
        INSERT INTO visit_entries (
            visit_id, diagnosis, symptoms, treatment, referral, remarks,
            amendment_reason, addressed_by_person_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $visitId,
        trim((string) ($entry['diagnosis'] ?? '')) ?: null,
        trim((string) ($entry['symptoms'] ?? '')) ?: null,
        trim((string) ($entry['treatment'] ?? '')) ?: null,
        trim((string) ($entry['referral'] ?? '')) ?: null,
        trim((string) ($entry['remarks'] ?? '')) ?: null,
        trim((string) ($entry['amendment_reason'] ?? '')) ?: null,
        $staffPersonId ?: null,
    ]);
    return (int) $db->lastInsertId();
}

function cliniq_visit_save_primary_entry(PDO $db, int $visitId, array $entry, ?int $staffPersonId): ?int
{
    if (!cliniq_visit_entry_has_content($entry)) {
        return null;
    }

    $lookup = $db->prepare("
        SELECT ve.entry_id
        FROM visit_entries ve
        JOIN visits v ON v.visit_id = ve.visit_id
        WHERE ve.visit_id = ?
          AND v.visit_source = 'Self Logbook'
          AND ve.addressed_by_person_id IS NULL
          AND ve.diagnosis IS NULL
          AND ve.treatment IS NULL
          AND ve.referral IS NULL
          AND ve.remarks IS NULL
          AND ve.amendment_reason IS NULL
        ORDER BY ve.created_at ASC, ve.entry_id ASC
        LIMIT 1
    ");
    $lookup->execute([$visitId]);
    $existingEntryId = (int) ($lookup->fetchColumn() ?: 0);

    if ($existingEntryId < 1) {
        $lookup = $db->prepare("
            SELECT entry_id
            FROM visit_entries
            WHERE visit_id = ?
              AND (amendment_reason IS NULL OR amendment_reason = '')
            ORDER BY created_at DESC, entry_id DESC
            LIMIT 1
        ");
        $lookup->execute([$visitId]);
        $existingEntryId = (int) ($lookup->fetchColumn() ?: 0);
    }

    if ($existingEntryId < 1) {
        return cliniq_visit_insert_entry($db, $visitId, $entry, $staffPersonId);
    }

    $stmt = $db->prepare('
        UPDATE visit_entries
        SET diagnosis = COALESCE(NULLIF(?, \'\'), diagnosis),
            symptoms = COALESCE(NULLIF(?, \'\'), symptoms),
            treatment = COALESCE(NULLIF(?, \'\'), treatment),
            referral = COALESCE(NULLIF(?, \'\'), referral),
            remarks = COALESCE(NULLIF(?, \'\'), remarks),
            amendment_reason = COALESCE(NULLIF(?, \'\'), amendment_reason),
            addressed_by_person_id = COALESCE(addressed_by_person_id, ?)
        WHERE entry_id = ?
    ');
    $stmt->execute([
        trim((string) ($entry['diagnosis'] ?? '')) ?: null,
        trim((string) ($entry['symptoms'] ?? '')),
        trim((string) ($entry['treatment'] ?? '')) ?: null,
        trim((string) ($entry['referral'] ?? '')) ?: null,
        trim((string) ($entry['remarks'] ?? '')) ?: null,
        trim((string) ($entry['amendment_reason'] ?? '')) ?: null,
        $staffPersonId ?: null,
        $existingEntryId,
    ]);

    return $existingEntryId;
}

function cliniq_visit_insert_vitals(
    PDO $db,
    int $visitId,
    ?int $entryId,
    array $vitals,
    ?int $staffPersonId
): ?int {
    if (!cliniq_visit_has_vitals($vitals)) {
        return null;
    }

    $temperature = trim((string) ($vitals['temperature'] ?? ''));
    $pulseRate = trim((string) ($vitals['pulse_rate'] ?? ''));
    $stmt = $db->prepare('
        INSERT INTO vital_signs (
            visit_id, entry_id, temperature, blood_pressure, pulse_rate, measured_by_person_id
        ) VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $visitId,
        $entryId ?: null,
        $temperature !== '' ? $temperature : null,
        trim((string) ($vitals['blood_pressure'] ?? '')) ?: null,
        $pulseRate !== '' ? (int) $pulseRate : null,
        $staffPersonId ?: null,
    ]);
    return (int) $db->lastInsertId();
}

function cliniq_visit_create(array $visit, array $entry = [], array $vitals = [], array $dispensings = [], ?PDO $db = null): int
{
    $db ??= cliniq_visit_db();
    $startsTransaction = !$db->inTransaction();
    $patientPersonId = (int) ($visit['patient_person_id'] ?? 0);
    $patientPersonId = $patientPersonId > 0 ? $patientPersonId : null;
    $guestName = cliniq_normalize_person_name($visit['guest_name'] ?? '') ?: null;
    if ($patientPersonId === null && !array_key_exists('guest_name', $visit)) {
        throw new InvalidArgumentException('Select a patient that exists in Cliniq_db.');
    }
    if ($patientPersonId === null && $guestName === null) {
        throw new InvalidArgumentException('Visitor / guest name is required.');
    }
    if ($guestName !== null && !account_valid_person_name($guestName)) {
        throw new InvalidArgumentException('Visitor / guest name may contain only letters, spaces, apostrophes, periods, or hyphens.');
    }
    if ($patientPersonId !== null && !cliniq_visit_patient_exists($patientPersonId)) {
        throw new InvalidArgumentException('Select a patient that exists in Cliniq_db.');
    }

    $visitPurpose = trim((string) ($visit['visit_purpose'] ?? ''));
    $chiefComplaint = cliniq_assert_bounded_text($visit['chief_complaint'] ?? '', 'Chief complaint', 255, true);
    if ($visitPurpose !== '' && str_starts_with(strtolower($chiefComplaint), strtolower($visitPurpose . ' - '))) {
        $chiefComplaint = trim(mb_substr($chiefComplaint, mb_strlen($visitPurpose . ' - ')));
    }
    if ($chiefComplaint === '') {
        throw new InvalidArgumentException('Chief complaint is required.');
    }

    $appointmentId = (int) ($visit['appointment_id'] ?? 0);
    $appointmentId = $appointmentId > 0 ? $appointmentId : null;
    $visitSource = cliniq_assert_bounded_text($visit['visit_source'] ?? '', 'Visit source', 80) ?: 'Staff Recorded';
    $actionTaken = cliniq_assert_bounded_text($visit['action_taken'] ?? '', 'Action taken', 5000);
    if (($appointmentId !== null) !== ($visitSource === 'Appointment')) {
        throw new InvalidArgumentException('Appointment-linked visits must use the Appointment source.');
    }
    if ($appointmentId !== null) {
        $appointmentStmt = $db->prepare('SELECT patient_id FROM appointments WHERE appointment_id = ?');
        $appointmentStmt->execute([$appointmentId]);
        if ((int) $appointmentStmt->fetchColumn() !== $patientPersonId) {
            throw new InvalidArgumentException('The selected appointment does not belong to this patient.');
        }
    }

    $status = cliniq_visit_status((string) ($visit['status'] ?? 'Unaddressed'), 'Unaddressed');
    $markAddressed = !empty($visit['mark_addressed']) || in_array($status, ['Active', 'Completed'], true);
    $markCompleted = !empty($visit['mark_completed']) || $status === 'Completed';

    try {
        if ($startsTransaction) {
            $db->beginTransaction();
        }
        $stmt = $db->prepare('
            INSERT INTO visits (
                appointment_id, patient_person_id, guest_name, visit_datetime, addressed_at, completed_at,
                chief_complaint, status,
                visit_purpose, visit_source, action_taken,
                recorded_by_person_id, attended_by_person_id
            ) VALUES (?, ?, ?, COALESCE(?, NOW()), IF(?, NOW(), NULL), IF(?, NOW(), NULL), ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $appointmentId,
            $patientPersonId,
            $guestName,
            ($visit['visit_datetime'] ?? null) ?: null,
            $markAddressed ? 1 : 0,
            $markCompleted ? 1 : 0,
            $chiefComplaint,
            $status,
            $visitPurpose ?: null,
            $visitSource,
            $actionTaken ?: null,
            !empty($visit['recorded_by_person_id']) ? (int) $visit['recorded_by_person_id'] : null,
            !empty($visit['attended_by_person_id']) ? (int) $visit['attended_by_person_id'] : null,
        ]);
        $visitId = (int) $db->lastInsertId();
        $staffId = !empty($visit['attended_by_person_id'])
            ? (int) $visit['attended_by_person_id']
            : (!empty($visit['recorded_by_person_id']) ? (int) $visit['recorded_by_person_id'] : null);
        $entryId = cliniq_visit_insert_entry($db, $visitId, $entry, $staffId);
        if ($dispensings && !$entryId) {
            $entryId = cliniq_visit_insert_entry($db, $visitId, [
                'remarks' => 'Medicine dispensing recorded.',
            ], $staffId);
        }
        cliniq_visit_insert_vitals($db, $visitId, $entryId, $vitals, $staffId);
        cliniq_inventory_dispense_medicines($db, (int) $entryId, $dispensings, $staffId);
        if (in_array($status, ['Completed', 'Cancelled'], true)) {
            cliniq_visit_sync_linked_appointment_outcome($db, $visitId, $staffId);
        }
        if ($startsTransaction) {
            $db->commit();
        }
        if ($status === 'Completed') {
            try {
                if ($patientPersonId !== null) {
                    patient_notification_for_feedback_required($db, [
                        'patient_id' => $patientPersonId,
                        'visit_id' => $visitId,
                        'visit_purpose' => $visitPurpose,
                    ], $staffId);
                }
            } catch (Throwable $notificationError) {
                error_log('Unable to create completed-visit feedback notification: ' . $notificationError->getMessage());
            }
        }
        $auditActorId = !empty($visit['recorded_by_person_id']) ? (int) $visit['recorded_by_person_id'] : $staffId;
        audit_log_event('visits', 'visit_created', $auditActorId, 'staff', 'visit', $visitId, [
            'patient_person_id' => $patientPersonId,
            'guest_name' => $guestName,
            'attended_by_person_id' => $staffId,
            'source' => $visitSource,
            'appointment_id' => $appointmentId,
        ]);
        return $visitId;
    } catch (Throwable $e) {
        if ($startsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function cliniq_visit_sync_linked_appointment_outcome(PDO $db, int $visitId, ?int $staffPersonId = null): void
{
    $stmt = $db->prepare('
        SELECT a.appointment_id, a.patient_id, a.appointment_datetime, a.purpose, a.status,
               v.status AS visit_status, v.action_taken
        FROM visits v
        INNER JOIN appointments a ON a.appointment_id = v.appointment_id
        WHERE v.visit_id = ?
        FOR UPDATE
    ');
    $stmt->execute([$visitId]);
    $appointment = $stmt->fetch();
    if (!$appointment || !in_array((string) $appointment['status'], ['Scheduled', 'For Confirmation'], true)) {
        return;
    }

    $nextStatus = match ((string) $appointment['visit_status']) {
        'Completed' => 'Completed',
        'Cancelled' => 'Cancelled',
        default => null,
    };
    if ($nextStatus === null) {
        return;
    }

    $reason = $nextStatus === 'Cancelled'
        ? trim((string) ($appointment['action_taken'] ?? ''))
        : null;
    $update = $db->prepare('
        UPDATE appointments
        SET status = ?, cancellation_reason = ?, cancelled_by = ?, reviewed_by_person_id = ?
        WHERE appointment_id = ?
    ');
    $update->execute([
        $nextStatus,
        $reason ?: null,
        $nextStatus === 'Cancelled' ? 'Clinic' : null,
        $staffPersonId,
        (int) $appointment['appointment_id'],
    ]);

    $appointment['cancellation_reason'] = $reason ?: null;
    patient_notification_for_appointment($db, $appointment, $nextStatus, $staffPersonId);
    audit_log_event('appointments', 'appointment_' . strtolower($nextStatus) . '_from_visit', $staffPersonId, 'staff', 'appointment', (int) $appointment['appointment_id'], [
        'visit_id' => $visitId,
        'visit_status' => $appointment['visit_status'],
    ]);
}

function cliniq_visit_create_from_appointment(int $appointmentId, string $chiefComplaint, int $staffPersonId): int
{
    $db = cliniq_visit_db();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare('
            SELECT a.appointment_id, a.patient_id, a.appointment_datetime, a.purpose, a.status,
                   v.visit_id AS linked_visit_id
            FROM appointments a
            LEFT JOIN visits v ON v.appointment_id = a.appointment_id
            WHERE a.appointment_id = ?
            FOR UPDATE
        ');
        $stmt->execute([$appointmentId]);
        $appointment = $stmt->fetch();
        if (!$appointment || !appointment_can_check_in($appointment)) {
            throw new InvalidArgumentException('Only same-day appointments awaiting clinical check-in can be checked in.');
        }
        if (!empty($appointment['linked_visit_id'])) {
            throw new InvalidArgumentException('This appointment already has a linked clinical visit.');
        }

        $visitId = cliniq_visit_create([
            'appointment_id' => (int) $appointment['appointment_id'],
            'patient_person_id' => (int) $appointment['patient_id'],
            'visit_datetime' => (string) $appointment['appointment_datetime'],
            'chief_complaint' => $chiefComplaint,
            'status' => 'Active',
            'visit_purpose' => (string) $appointment['purpose'],
            'visit_source' => 'Appointment',
            'action_taken' => 'Appointment check-in. Clinical documentation in progress.',
            'recorded_by_person_id' => $staffPersonId,
        ], [], [], [], $db);
        $db->commit();

        return $visitId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function cliniq_visit_fetch(int $visitId): ?array
{
    $stmt = cliniq_visit_db()->prepare("
        SELECT
            v.visit_id AS id,
            v.patient_person_id AS patient_id,
            v.guest_name,
            v.visit_datetime,
            v.chief_complaint,
            le.symptoms,
            lv.temperature,
            lv.blood_pressure,
            lv.pulse_rate,
            NULL AS spo2,
            NULL AS respiratory_rate,
            v.status,
            v.visit_purpose,
            v.visit_source,
            v.action_taken,
            v.recorded_by_person_id AS recorded_by,
            v.attended_by_person_id AS attended_by,
            v.created_at,
            v.updated_at,
            pe.first_name,
            pe.middle_name,
            pe.last_name,
            pe.id_number,
            pe.birthdate,
            pe.sex,
            COALESCE(
                NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))), ''),
                ed.department_code,
                cd.department_code,
                'Patient'
            ) AS course_section,
            pt.blood_type,
            NULL AS allergies,
            NULL AS existing_conditions,
            pt.guardian_or_contact_name AS guardian_name,
            pt.guardian_or_contact_number AS guardian_contact,
            TRIM(CONCAT_WS(' ', NULLIF(recorded_staff.honorific, ''), rp.first_name, rp.middle_name, rp.last_name)) AS recorded_by_name,
            TRIM(CONCAT_WS(' ', NULLIF(attended_staff.honorific, ''), ap.first_name, ap.middle_name, ap.last_name)) AS attended_by_name
        FROM visits v
        LEFT JOIN patients pt ON pt.person_id = v.patient_person_id
        LEFT JOIN people pe ON pe.id = pt.person_id
        LEFT JOIN students s ON s.person_id = pe.id
        LEFT JOIN programs pr ON pr.id = s.program_id
        LEFT JOIN school_employees se ON se.person_id = pe.id
        LEFT JOIN departments ed ON ed.id = se.department_id
        LEFT JOIN clinic_staff cs ON cs.person_id = pe.id
        LEFT JOIN departments cd ON cd.id = cs.department_id
        LEFT JOIN people rp ON rp.id = v.recorded_by_person_id
        LEFT JOIN people ap ON ap.id = v.attended_by_person_id
        LEFT JOIN clinic_staff recorded_staff ON recorded_staff.person_id = rp.id
        LEFT JOIN clinic_staff attended_staff ON attended_staff.person_id = ap.id
        LEFT JOIN visit_entries le ON le.entry_id = (
            SELECT le2.entry_id FROM visit_entries le2
            WHERE le2.visit_id = v.visit_id
            ORDER BY le2.created_at DESC, le2.entry_id DESC LIMIT 1
        )
        LEFT JOIN vital_signs lv ON lv.vital_id = (
            SELECT lv2.vital_id FROM vital_signs lv2
            WHERE lv2.visit_id = v.visit_id
            ORDER BY lv2.measured_at DESC, lv2.vital_id DESC LIMIT 1
        )
        WHERE v.visit_id = ?
        LIMIT 1
    ");
    $stmt->execute([$visitId]);
    return $stmt->fetch() ?: null;
}

/** @return array<int,array<string,mixed>> */
function cliniq_visit_entries(int $visitId): array
{
    $stmt = cliniq_visit_db()->prepare("
        SELECT
            ve.entry_id AS id,
            ve.entry_id,
            ve.visit_id,
            ve.symptoms AS symptoms_note,
            ve.diagnosis,
            ve.treatment AS management_treatment,
            ve.referral AS referral_type,
            ve.remarks,
            ve.amendment_reason,
            NULL AS dispensed_inventory_item_id,
            NULL AS dispensed_quantity,
            ve.addressed_by_person_id AS created_by,
            ve.created_at,
            TRIM(CONCAT_WS(' ', NULLIF(creator_staff.honorific, ''), pe.first_name, pe.middle_name, pe.last_name)) AS created_by_name
        FROM visit_entries ve
        LEFT JOIN people pe ON pe.id = ve.addressed_by_person_id
        LEFT JOIN clinic_staff creator_staff ON creator_staff.person_id = pe.id
        WHERE ve.visit_id = ?
        ORDER BY ve.created_at DESC, ve.entry_id DESC
    ");
    $stmt->execute([$visitId]);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function cliniq_visit_timeline(int $patientPersonId, int $limit = 50): array
{
    $limit = max(1, min(250, $limit));
    $stmt = cliniq_visit_db()->prepare("
        SELECT
            v.visit_id AS id,
            v.patient_person_id AS patient_id,
            v.visit_datetime,
            v.chief_complaint,
            le.symptoms,
            lv.temperature,
            lv.blood_pressure,
            lv.pulse_rate,
            NULL AS spo2,
            v.status,
            v.visit_purpose,
            v.visit_source,
            v.action_taken,
            TRIM(CONCAT_WS(' ', NULLIF(recorded_staff.honorific, ''), rp.first_name, rp.middle_name, rp.last_name)) AS recorded_by_name,
            TRIM(CONCAT_WS(' ', NULLIF(attended_staff.honorific, ''), ap.first_name, ap.middle_name, ap.last_name)) AS attended_by_name
        FROM visits v
        LEFT JOIN people rp ON rp.id = v.recorded_by_person_id
        LEFT JOIN people ap ON ap.id = v.attended_by_person_id
        LEFT JOIN clinic_staff recorded_staff ON recorded_staff.person_id = rp.id
        LEFT JOIN clinic_staff attended_staff ON attended_staff.person_id = ap.id
        LEFT JOIN visit_entries le ON le.entry_id = (
            SELECT le2.entry_id FROM visit_entries le2
            WHERE le2.visit_id = v.visit_id
            ORDER BY le2.created_at DESC, le2.entry_id DESC LIMIT 1
        )
        LEFT JOIN vital_signs lv ON lv.vital_id = (
            SELECT lv2.vital_id FROM vital_signs lv2
            WHERE lv2.visit_id = v.visit_id
            ORDER BY lv2.measured_at DESC, lv2.vital_id DESC LIMIT 1
        )
        WHERE v.patient_person_id = ?
        ORDER BY FIELD(v.status, 'Unaddressed', 'Active', 'Completed', 'Cancelled'), v.visit_datetime DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$patientPersonId]);
    return $stmt->fetchAll();
}

if (!function_exists('visit_statuses')) {
    function visit_statuses(): array
    {
        return ['Unaddressed', 'Active', 'Completed', 'Cancelled'];
    }
}

if (!function_exists('visit_purposes')) {
    function visit_purposes(): array
    {
        return dropdown_options('visit_purpose');
    }
}

if (!function_exists('visit_sources')) {
    function visit_sources(): array
    {
        return ['Self Logbook', 'Staff Recorded', 'Nurse Emergency', 'Appointment'];
    }
}

if (!function_exists('visit_referral_options')) {
    function visit_referral_options(): array
    {
        return dropdown_options('referral_type');
    }
}

if (!function_exists('normalize_visit_purpose')) {
    function normalize_visit_purpose(?string $purpose, array $additionalAllowed = []): ?string
    {
        $purpose = trim((string) $purpose);
        if ($purpose === '') return null;
        if (!in_array($purpose, array_merge(visit_purposes(), $additionalAllowed), true)) {
            throw new InvalidArgumentException('Select an active visit purpose.');
        }
        return $purpose;
    }
}

if (!function_exists('normalize_visit_referral_type')) {
    function normalize_visit_referral_type(?string $referralType): string
    {
        $referralType = trim((string) $referralType);
        if ($referralType === '' || $referralType === 'None') return '';
        if (!in_array($referralType, visit_referral_options(), true)) {
            throw new InvalidArgumentException('Select an active referral type.');
        }
        return $referralType;
    }
}

if (!function_exists('visit_status_badge_class')) {
    function visit_status_badge_class(string $status): string
    {
        return match ($status) {
            'Completed' => 'badge-completed',
            'Active' => 'badge-in-progress',
            'Cancelled' => 'badge-critical',
            default => 'badge-pending',
        };
    }
}

if (!function_exists('visit_status_badge')) {
    function visit_status_badge(string $status): string
    {
        return '<span class="badge ' . visit_status_badge_class($status) . '">' . e($status) . '</span>';
    }
}

if (!function_exists('visit_source_badge')) {
    function visit_source_badge(string $source): string
    {
        return match ($source) {
            'Self Logbook' => '<span class="badge badge-completed">Self Logbook</span>',
            'Nurse Emergency' => '<span class="badge badge-critical">Nurse Emergency</span>',
            'Appointment' => '<span class="badge badge-in-progress">Appointment</span>',
            default => '<span class="badge badge-in-progress">Staff Recorded</span>',
        };
    }
}
