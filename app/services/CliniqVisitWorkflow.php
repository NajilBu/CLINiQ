<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/CliniqInventoryWorkflow.php';

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
            COALESCE(
                NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))), ''),
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
    return $stmt->fetchAll();
}

function cliniq_visit_patient_by_id_number(string $idNumber): ?array
{
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
                   NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))), ''),
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
        WHERE pe.id_number = ?
        LIMIT 1
    ");
    $stmt->execute([strtoupper(trim($idNumber))]);
    return $stmt->fetch() ?: null;
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
               TRIM(CONCAT_WS(' ', pe.first_name, pe.middle_name, pe.last_name)) AS name
        FROM clinic_staff cs
        JOIN people pe ON pe.id = cs.person_id
        ORDER BY pe.last_name, pe.first_name
    ")->fetchAll();
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

function cliniq_visit_create(array $visit, array $entry = [], array $vitals = [], array $dispensings = []): int
{
    $db = cliniq_visit_db();
    $patientPersonId = (int) ($visit['patient_person_id'] ?? 0);
    if ($patientPersonId < 1 || !cliniq_visit_patient_exists($patientPersonId)) {
        throw new InvalidArgumentException('Select a patient that exists in Cliniq_db.');
    }

    $chiefComplaint = trim((string) ($visit['chief_complaint'] ?? ''));
    if ($chiefComplaint === '') {
        throw new InvalidArgumentException('Chief complaint is required.');
    }

    $status = cliniq_visit_status((string) ($visit['status'] ?? 'Unaddressed'), 'Unaddressed');
    $markAddressed = !empty($visit['mark_addressed']) || in_array($status, ['Active', 'Completed'], true);
    $markCompleted = !empty($visit['mark_completed']) || $status === 'Completed';

    try {
        $db->beginTransaction();
        $stmt = $db->prepare('
            INSERT INTO visits (
                patient_person_id, visit_datetime, addressed_at, completed_at,
                chief_complaint, status,
                visit_purpose, visit_source, action_taken,
                recorded_by_person_id, attended_by_person_id
            ) VALUES (?, COALESCE(?, NOW()), IF(?, NOW(), NULL), IF(?, NOW(), NULL), ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $patientPersonId,
            ($visit['visit_datetime'] ?? null) ?: null,
            $markAddressed ? 1 : 0,
            $markCompleted ? 1 : 0,
            $chiefComplaint,
            $status,
            trim((string) ($visit['visit_purpose'] ?? '')) ?: null,
            trim((string) ($visit['visit_source'] ?? '')) ?: 'Staff Recorded',
            trim((string) ($visit['action_taken'] ?? '')) ?: null,
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
            TRIM(CONCAT_WS(' ', rp.first_name, rp.middle_name, rp.last_name)) AS recorded_by_name,
            TRIM(CONCAT_WS(' ', ap.first_name, ap.middle_name, ap.last_name)) AS attended_by_name
        FROM visits v
        JOIN patients pt ON pt.person_id = v.patient_person_id
        JOIN people pe ON pe.id = pt.person_id
        LEFT JOIN students s ON s.person_id = pe.id
        LEFT JOIN programs pr ON pr.id = s.program_id
        LEFT JOIN school_employees se ON se.person_id = pe.id
        LEFT JOIN departments ed ON ed.id = se.department_id
        LEFT JOIN clinic_staff cs ON cs.person_id = pe.id
        LEFT JOIN departments cd ON cd.id = cs.department_id
        LEFT JOIN people rp ON rp.id = v.recorded_by_person_id
        LEFT JOIN people ap ON ap.id = v.attended_by_person_id
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
            TRIM(CONCAT_WS(' ', pe.first_name, pe.middle_name, pe.last_name)) AS created_by_name
        FROM visit_entries ve
        LEFT JOIN people pe ON pe.id = ve.addressed_by_person_id
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
            TRIM(CONCAT_WS(' ', rp.first_name, rp.middle_name, rp.last_name)) AS recorded_by_name,
            TRIM(CONCAT_WS(' ', ap.first_name, ap.middle_name, ap.last_name)) AS attended_by_name
        FROM visits v
        LEFT JOIN people rp ON rp.id = v.recorded_by_person_id
        LEFT JOIN people ap ON ap.id = v.attended_by_person_id
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
        return dropdown_options('visit_status');
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
        return dropdown_options('visit_source');
    }
}

if (!function_exists('visit_referral_options')) {
    function visit_referral_options(): array
    {
        return dropdown_options('referral_type');
    }
}

if (!function_exists('normalize_visit_purpose')) {
    function normalize_visit_purpose(?string $purpose): ?string
    {
        $purpose = trim((string) $purpose);
        return $purpose !== '' ? mb_substr($purpose, 0, 80) : null;
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
            default => '<span class="badge badge-in-progress">Staff Recorded</span>',
        };
    }
}
