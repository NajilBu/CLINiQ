<?php

// CLI only: creates or refreshes the requested named student demo records.
if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/VisitWorkflow.php';
require_once __DIR__ . '/../app/services/AlertWorkflow.php';

$db = db();
ensure_visit_workflow_schema();
ensure_alert_workflow_schema();

$columns = $db->query('SHOW COLUMNS FROM patients')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('password_hash', $columns, true)) {
    $db->exec('ALTER TABLE patients ADD COLUMN password_hash VARCHAR(255) NULL AFTER student_number');
}

$adminId = (int) ($db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 1);
$passwordHash = password_hash('123123', PASSWORD_DEFAULT);

$students = [
    [
        'number' => '23-00211',
        'first' => 'Justine Angelo',
        'last' => 'Faustino',
        'birthdate' => '2004-08-15',
        'sex' => 'Male',
        'course' => 'BSIT D',
        'blood' => 'O+',
        'allergies' => 'None',
        'conditions' => 'None reported.',
        'guardian' => 'Maria Faustino',
        'contact' => '0917-230-0211',
        'visit' => ['Headache and eye strain', 'Mild headache after extended computer laboratory work', 'Completed'],
        'ape' => ['Pre-Verified', 'Cleared', 'Cleared', 'No significant findings. Fit to study.'],
        'appointment' => ['Eye strain follow-up', 'Scheduled'],
        'alert' => ['Justine reported headache during laboratory class', 'Resolved'],
        'referral' => ['PLP Guidance and Wellness Office', 'Routine wellness support and stress-management resources.', 'Completed'],
    ],
    [
        'number' => '23-00274',
        'first' => 'Jan Alain',
        'last' => 'Cainglet',
        'birthdate' => '2004-05-22',
        'sex' => 'Male',
        'course' => 'BSIT D',
        'blood' => 'A+',
        'allergies' => 'None',
        'conditions' => 'Occasional gastritis symptoms.',
        'guardian' => 'Analyn Cainglet',
        'contact' => '0917-230-0274',
        'visit' => ['Stomach discomfort', 'Mild abdominal discomfort after lunch, no vomiting', 'Completed'],
        'ape' => ['Not Checked', 'Registered', 'Pending', 'Waiting for hard-copy APE documents.'],
        'appointment' => ['Stomach discomfort follow-up', 'Pending'],
        'alert' => ['Jan Alain reported stomach discomfort after lunch', 'Resolved'],
        'referral' => ['University Guidance and Wellness Office', 'Wellness support resources after repeated stomach discomfort during exams.', 'Pending'],
    ],
    [
        'number' => '23-00262',
        'first' => 'Najil',
        'last' => 'Bumacod',
        'birthdate' => '2004-11-03',
        'sex' => 'Male',
        'course' => 'BSIT D',
        'blood' => 'B+',
        'allergies' => 'Dust mites',
        'conditions' => 'Seasonal allergic rhinitis.',
        'guardian' => 'Nadia Bumacod',
        'contact' => '0917-230-0262',
        'visit' => ['Allergic rhinitis flare-up', 'Sneezing, itchy eyes, nasal congestion after dusty classroom exposure', 'Active'],
        'ape' => ['Pre-Verified', 'Follow-up Required', 'For Follow-up', 'Allergic rhinitis history noted. Needs symptom monitoring.'],
        'appointment' => ['Allergy monitoring', 'Scheduled'],
        'alert' => ['Najil Bumacod has persistent allergy symptoms', 'Pending'],
        'referral' => ['Allergy and Immunology Clinic', 'Recurring allergic rhinitis symptoms after dust exposure.', 'Pending'],
    ],
];

$db->beginTransaction();
try {
    $findPatient = $db->prepare('SELECT id FROM patients WHERE student_number = ? LIMIT 1');
    $updatePatient = $db->prepare('
        UPDATE patients
        SET password_hash = ?, first_name = ?, middle_name = NULL, last_name = ?, birthdate = ?, sex = ?,
            course_section = ?, blood_type = ?, allergies = ?, existing_conditions = ?,
            emergency_instructions = ?, guardian_name = ?, guardian_contact = ?
        WHERE id = ?
    ');
    $insertPatient = $db->prepare('
        INSERT INTO patients (
            student_number, password_hash, first_name, last_name, birthdate, sex, course_section,
            blood_type, allergies, existing_conditions, emergency_instructions, guardian_name,
            guardian_contact, emergency_token
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $exists = static function (PDO $db, string $table, int $patientId): bool {
        $stmt = $db->prepare("SELECT 1 FROM {$table} WHERE patient_id = ? LIMIT 1");
        $stmt->execute([$patientId]);
        return (bool) $stmt->fetchColumn();
    };

    foreach ($students as $student) {
        $findPatient->execute([$student['number']]);
        $patientId = (int) $findPatient->fetchColumn();
        $emergencyInstructions = 'Contact the guardian for emergencies requiring outside referral.';

        if ($patientId > 0) {
            $updatePatient->execute([
                $passwordHash,
                $student['first'],
                $student['last'],
                $student['birthdate'],
                $student['sex'],
                $student['course'],
                $student['blood'],
                $student['allergies'],
                $student['conditions'],
                $emergencyInstructions,
                $student['guardian'],
                $student['contact'],
                $patientId,
            ]);
        } else {
            $insertPatient->execute([
                $student['number'],
                $passwordHash,
                $student['first'],
                $student['last'],
                $student['birthdate'],
                $student['sex'],
                $student['course'],
                $student['blood'],
                $student['allergies'],
                $student['conditions'],
                $emergencyInstructions,
                $student['guardian'],
                $student['contact'],
                hash('sha256', 'cliniq-' . $student['number']),
            ]);
            $patientId = (int) $db->lastInsertId();
        }

        if (!$exists($db, 'clinic_visits', $patientId)) {
            $stmt = $db->prepare('
                INSERT INTO clinic_visits (
                    patient_id, visit_datetime, chief_complaint, symptoms, temperature, blood_pressure,
                    pulse_rate, status, visit_purpose, visit_source, action_taken, recorded_by, attended_by
                ) VALUES (?, NOW(), ?, ?, 36.8, "118/76", 78, ?, "Medical Consult", "Staff Recorded", ?, ?, ?)
            ');
            $stmt->execute([
                $patientId,
                $student['visit'][0],
                $student['visit'][1],
                $student['visit'][2],
                'Demo care recorded for named student sample.',
                $adminId,
                $adminId,
            ]);
            $visitId = (int) $db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO visit_treatment_entries (
                    visit_id, symptoms_note, diagnosis, management_treatment, referral_type, remarks, created_by
                ) VALUES (?, ?, ?, ?, "None", "Named student sample treatment.", ?)
            ');
            $stmt->execute([
                $visitId,
                $student['visit'][1],
                $student['visit'][0],
                'Rest, hydration, monitoring, and follow-up guidance provided.',
                $adminId,
            ]);
        }

        if (!$exists($db, 'ape_records', $patientId)) {
            $stmt = $db->prepare('
                INSERT INTO ape_records (
                    patient_id, exam_date, document_type, requirement_status, workflow_status,
                    verification_status, verified_by, clearance_status, clinical_remarks,
                    student_visible_note, follow_up_required, result_status, result_notes
                ) VALUES (?, CURDATE(), "APE Form", ?, ?, "Verified", ?, ?, ?, ?, ?, ?, ?)
            ');
            $followUp = $student['ape'][2] === 'For Follow-up' ? 1 : 0;
            $stmt->execute([
                $patientId,
                $student['ape'][0],
                $student['ape'][1],
                $adminId,
                $student['ape'][2],
                $student['ape'][3],
                $student['ape'][3],
                $followUp,
                $followUp ? 'With Finding' : ($student['ape'][2] === 'Cleared' ? 'Fit to Proceed' : 'Pending'),
                $student['ape'][3],
            ]);
        }

        if (!$exists($db, 'appointments', $patientId)) {
            $stmt = $db->prepare('INSERT INTO appointments (patient_id, appointment_datetime, purpose, status, notes) VALUES (?, DATE_ADD(NOW(), INTERVAL 7 DAY), ?, ?, "Named student sample appointment.")');
            $stmt->execute([$patientId, $student['appointment'][0], $student['appointment'][1]]);
        }

        if (!$exists($db, 'nurse_alerts', $patientId)) {
            $stmt = $db->prepare('
                INSERT INTO nurse_alerts (
                    patient_id, reporter_name, reporter_role, location, concern, details, risk_level,
                    risk_score, response_guidance, status, resolution_report, resolved_by, resolved_at
                ) VALUES (?, "Demo Reporter", "Faculty", "Campus", ?, ?, "Low", 1, "Clinic assessment recommended.", ?, ?, ?, ?)
            ');
            $resolved = $student['alert'][1] === 'Resolved';
            $stmt->execute([
                $patientId,
                $student['alert'][0],
                $student['visit'][1],
                $student['alert'][1],
                $resolved ? 'Resolved as part of named student demo seed.' : null,
                $resolved ? $adminId : null,
                $resolved ? date('Y-m-d H:i:s') : null,
            ]);
        }

        if (!$exists($db, 'referrals', $patientId)) {
            $stmt = $db->prepare('INSERT INTO referrals (patient_id, referral_date, referred_to, reason, status) VALUES (?, CURDATE(), ?, ?, ?)');
            $stmt->execute([$patientId, $student['referral'][0], $student['referral'][1], $student['referral'][2]]);
        }

        if (!$exists($db, 'passport_access_logs', $patientId)) {
            $stmt = $db->prepare('INSERT INTO passport_access_logs (patient_id, ip_address, user_agent) VALUES (?, "127.0.0.1", "CLINiQ Demo Browser")');
            $stmt->execute([$patientId]);
        }
    }

    $db->commit();
    echo "Named student demo records are ready: Justine Angelo Faustino, Jan Alain Cainglet, and Najil Bumacod.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $error;
}
