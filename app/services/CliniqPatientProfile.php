<?php

require_once __DIR__ . '/../config/database.php';

function cliniq_patient_profile_db(): PDO
{
    return auth_db();
}

function cliniq_patient_profile_select(): string
{
    return <<<'SQL'
        SELECT
            pe.id,
            pe.id AS person_id,
            pe.id_number,
            pe.first_name,
            pe.middle_name,
            pe.last_name,
            pe.birthdate,
            pe.sex,
            pt.blood_type,
            pt.emergency_instructions,
            pt.guardian_or_contact_name AS guardian_name,
            pt.guardian_or_contact_number AS guardian_contact,
            pt.emergency_token,
            pt.token_enabled,
            a.account_status,
            CASE
                WHEN s.person_id IS NOT NULL THEN 'Student'
                WHEN se.person_id IS NOT NULL THEN se.role_classification
                WHEN cs.person_id IS NOT NULL THEN 'Clinic Staff'
                ELSE 'Patient'
            END AS patient_type,
            pr.id AS program_id,
            pr.program_code,
            pr.program_name,
            pd.department_code AS program_department_code,
            pd.department_name AS program_department_name,
            s.year_level,
            s.section,
            s.academic_year,
            se.role_classification,
            se.employment_type,
            se.position_title AS employee_position_title,
            ed.department_code AS employee_department_code,
            ed.department_name AS employee_department_name,
            cs.staff_role,
            cs.position_title AS staff_position_title,
            cd.department_code AS staff_department_code,
            cd.department_name AS staff_department_name,
            COALESCE(
                NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))), ''),
                ed.department_code,
                cd.department_code,
                'Patient'
            ) AS course_section
        FROM patients pt
        JOIN people pe ON pe.id = pt.person_id
        LEFT JOIN accounts a ON a.person_id = pe.id
        LEFT JOIN students s ON s.person_id = pe.id
        LEFT JOIN programs pr ON pr.id = s.program_id
        LEFT JOIN departments pd ON pd.id = pr.department_id
        LEFT JOIN school_employees se ON se.person_id = pe.id
        LEFT JOIN departments ed ON ed.id = se.department_id
        LEFT JOIN clinic_staff cs ON cs.person_id = pe.id
        LEFT JOIN departments cd ON cd.id = cs.department_id
    SQL;
}

function cliniq_patient_profile_find(int $personId): ?array
{
    if ($personId < 1) {
        return null;
    }

    $stmt = cliniq_patient_profile_db()->prepare(
        cliniq_patient_profile_select() . ' WHERE pe.id = ? LIMIT 1'
    );
    $stmt->execute([$personId]);
    return $stmt->fetch() ?: null;
}

function cliniq_patient_profile_find_by_id_number(string $idNumber): ?array
{
    $idNumber = strtoupper(trim($idNumber));
    if ($idNumber === '') {
        return null;
    }

    $stmt = cliniq_patient_profile_db()->prepare(
        cliniq_patient_profile_select() . ' WHERE pe.id_number = ? LIMIT 1'
    );
    $stmt->execute([$idNumber]);
    return $stmt->fetch() ?: null;
}

function cliniq_patient_profile_count(string $search = ''): int
{
    $search = trim($search);
    if ($search === '') {
        return (int) cliniq_patient_profile_db()->query('SELECT COUNT(*) FROM patients')->fetchColumn();
    }

    $stmt = cliniq_patient_profile_db()->prepare('
        SELECT COUNT(*)
        FROM patients pt
        JOIN people pe ON pe.id = pt.person_id
        WHERE pe.id_number LIKE ? OR pe.first_name LIKE ? OR pe.middle_name LIKE ? OR pe.last_name LIKE ?
    ');
    $like = '%' . $search . '%';
    $stmt->execute([$like, $like, $like, $like]);
    return (int) $stmt->fetchColumn();
}

/** @return array<int,array<string,mixed>> */
function cliniq_patient_profile_list(string $search = '', int $limit = 25, int $offset = 0): array
{
    $limit = max(1, min(1000, $limit));
    $offset = max(0, $offset);
    $where = '';
    $params = [];
    $search = trim($search);
    if ($search !== '') {
        $where = 'WHERE pe.id_number LIKE ? OR pe.first_name LIKE ? OR pe.middle_name LIKE ? OR pe.last_name LIKE ?';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like];
    }

    $stmt = cliniq_patient_profile_db()->prepare(
        cliniq_patient_profile_select()
        . " {$where} ORDER BY pe.last_name, pe.first_name, pe.id_number LIMIT {$limit} OFFSET {$offset}"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function cliniq_patient_profile_history(int $personId, int $limit = 100): array
{
    if ($personId < 1) {
        return [];
    }

    $limit = max(1, min(250, $limit));
    $db = cliniq_patient_profile_db();
    $visitStmt = $db->prepare("
        SELECT
            v.*,
            v.visit_id AS id,
            TRIM(CONCAT_WS(' ', rp.first_name, rp.middle_name, rp.last_name)) AS recorded_by_name,
            TRIM(CONCAT_WS(' ', ap.first_name, ap.middle_name, ap.last_name)) AS attended_by_name
        FROM visits v
        LEFT JOIN people rp ON rp.id = v.recorded_by_person_id
        LEFT JOIN people ap ON ap.id = v.attended_by_person_id
        WHERE v.patient_person_id = ?
        ORDER BY v.visit_datetime DESC, v.visit_id DESC
        LIMIT {$limit}
    ");
    $visitStmt->execute([$personId]);
    $visits = $visitStmt->fetchAll();
    if (!$visits) {
        return [];
    }

    $visitIds = array_map(fn(array $visit): int => (int) $visit['visit_id'], $visits);
    $visitPlaceholders = implode(',', array_fill(0, count($visitIds), '?'));

    $entryStmt = $db->prepare("
        SELECT ve.*,
               TRIM(CONCAT_WS(' ', pe.first_name, pe.middle_name, pe.last_name)) AS addressed_by_name
        FROM visit_entries ve
        LEFT JOIN people pe ON pe.id = ve.addressed_by_person_id
        WHERE ve.visit_id IN ({$visitPlaceholders})
        ORDER BY ve.created_at DESC, ve.entry_id DESC
    ");
    $entryStmt->execute($visitIds);
    $entries = $entryStmt->fetchAll();
    $entryIds = array_map(fn(array $entry): int => (int) $entry['entry_id'], $entries);

    $vitalStmt = $db->prepare("
        SELECT vs.*,
               TRIM(CONCAT_WS(' ', pe.first_name, pe.middle_name, pe.last_name)) AS measured_by_name
        FROM vital_signs vs
        LEFT JOIN people pe ON pe.id = vs.measured_by_person_id
        WHERE vs.visit_id IN ({$visitPlaceholders})
        ORDER BY vs.measured_at DESC, vs.vital_id DESC
    ");
    $vitalStmt->execute($visitIds);
    $vitals = $vitalStmt->fetchAll();

    $dispensings = [];
    if ($entryIds) {
        $entryPlaceholders = implode(',', array_fill(0, count($entryIds), '?'));
        $dispensingStmt = $db->prepare("
            SELECT md.*, i.item_code, i.item_name, i.unit,
                   TRIM(CONCAT_WS(' ', pe.first_name, pe.middle_name, pe.last_name)) AS dispensed_by_name
            FROM medicine_dispensings md
            JOIN inventory_items i ON i.item_id = md.item_id
            LEFT JOIN people pe ON pe.id = md.dispensed_by_person_id
            WHERE md.entry_id IN ({$entryPlaceholders})
            ORDER BY md.dispensed_at DESC, md.dispensing_id DESC
        ");
        $dispensingStmt->execute($entryIds);
        $dispensings = $dispensingStmt->fetchAll();
    }

    $dispensingsByEntry = [];
    foreach ($dispensings as $dispensing) {
        $dispensingsByEntry[(int) $dispensing['entry_id']][] = $dispensing;
    }

    $vitalsByEntry = [];
    $visitLevelVitals = [];
    foreach ($vitals as $vital) {
        $entryId = (int) ($vital['entry_id'] ?? 0);
        if ($entryId > 0) {
            $vitalsByEntry[$entryId][] = $vital;
        } else {
            $visitLevelVitals[(int) $vital['visit_id']][] = $vital;
        }
    }

    $entriesByVisit = [];
    foreach ($entries as $entry) {
        $entryId = (int) $entry['entry_id'];
        $entry['vitals'] = $vitalsByEntry[$entryId] ?? [];
        $entry['dispensings'] = $dispensingsByEntry[$entryId] ?? [];
        $entriesByVisit[(int) $entry['visit_id']][] = $entry;
    }

    foreach ($visits as &$visit) {
        $visitId = (int) $visit['visit_id'];
        $visit['entries'] = $entriesByVisit[$visitId] ?? [];
        $visit['vitals'] = $visitLevelVitals[$visitId] ?? [];
    }
    unset($visit);

    return $visits;
}
