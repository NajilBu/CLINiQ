<?php

// CLI only: creates or refreshes the complete demo record for Justine Angelo Faustino.
if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

require_once __DIR__ . '/../app/config/database.php';

$db = db();
$columns = $db->query('SHOW COLUMNS FROM patients')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('password_hash', $columns, true)) {
    $db->exec('ALTER TABLE patients ADD COLUMN password_hash VARCHAR(255) NULL AFTER student_number');
}

$db->beginTransaction();
try {
    $passwordHash = password_hash('123123', PASSWORD_DEFAULT);
    $patient = $db->prepare('SELECT id FROM patients WHERE student_number = ? LIMIT 1');
    $patient->execute(['23-00211']);
    $patientId = (int) $patient->fetchColumn();

    if ($patientId > 0) {
        $stmt = $db->prepare('UPDATE patients SET password_hash=?, first_name=?, middle_name=?, last_name=?, birthdate=?, sex=?, course_section=?, blood_type=?, allergies=?, existing_conditions=?, emergency_instructions=?, guardian_name=?, guardian_contact=? WHERE id=?');
        $stmt->execute([$passwordHash, 'Justine Angelo', null, 'Faustino', '2004-08-15', 'Male', 'BSIT 3D', 'O+', 'None', 'None reported.', 'Contact the guardian for emergencies requiring outside referral.', 'Maria Faustino', '0917-230-0211', $patientId]);
    } else {
        $stmt = $db->prepare('INSERT INTO patients (student_number,password_hash,first_name,last_name,birthdate,sex,course_section,blood_type,allergies,existing_conditions,emergency_instructions,guardian_name,guardian_contact,emergency_token) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute(['23-00211', $passwordHash, 'Justine Angelo', 'Faustino', '2004-08-15', 'Male', 'BSIT 3D', 'O+', 'None', 'None reported.', 'Contact the guardian for emergencies requiring outside referral.', 'Maria Faustino', '0917-230-0211', hash('sha256', 'cliniq-23-00211')]);
        $patientId = (int) $db->lastInsertId();
    }

    $exists = static function (PDO $db, string $table, int $patientId): bool {
        $stmt = $db->prepare("SELECT 1 FROM {$table} WHERE patient_id = ? LIMIT 1");
        $stmt->execute([$patientId]);
        return (bool) $stmt->fetchColumn();
    };

    if (!$exists($db, 'clinic_visits', $patientId)) {
        $stmt = $db->prepare("INSERT INTO clinic_visits (patient_id,visit_datetime,chief_complaint,symptoms,temperature,blood_pressure,pulse_rate,risk_level,risk_score,risk_reasons,status,visit_purpose,visit_source,action_taken,recorded_by,attended_by) VALUES (?,NOW(),'Headache and eye strain','Mild headache after extended computer laboratory work',36.8,'118/76',78,'Low',1,'Mild headache without warning signs.','Completed','Medical Consult','Staff Recorded','Rest, hydration, and screen-break guidance provided.',1,1)");
        $stmt->execute([$patientId]);
        $visitId = (int) $db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO visit_treatment_entries (visit_id,symptoms_note,diagnosis,management_treatment,referral_type,remarks,created_by) VALUES (?,'Mild headache; vital signs within normal limits.','Tension headache and digital eye strain','Rested in clinic, hydrated, and advised regular screen breaks.','None','Return if symptoms persist or worsen.',1)");
        $stmt->execute([$visitId]);
    }

    if (!$exists($db, 'ape_records', $patientId)) {
        $stmt = $db->prepare("INSERT INTO ape_records (patient_id,exam_date,document_type,requirement_status,workflow_status,verification_status,verified_by,clearance_status,clinical_remarks,student_visible_note,follow_up_required,result_status,result_notes) VALUES (?,CURDATE(),'APE Form','Pre-Verified','Cleared','Verified',1,'Cleared','No significant findings. Fit to study.','APE requirements completed and cleared.',0,'Fit to Proceed','Cleared for academic activities.')");
        $stmt->execute([$patientId]);
    }

    if (!$exists($db, 'appointments', $patientId)) {
        $stmt = $db->prepare("INSERT INTO appointments (patient_id,appointment_datetime,purpose,status,notes) VALUES (?,DATE_ADD(NOW(), INTERVAL 7 DAY),'Routine health follow-up','Scheduled','Review general wellness and recurring eye strain prevention.')");
        $stmt->execute([$patientId]);
    }

    if (!$exists($db, 'nurse_alerts', $patientId)) {
        $stmt = $db->prepare("INSERT INTO nurse_alerts (patient_id,reporter_name,reporter_role,location,concern,details,status,resolution_report,resolved_by,resolved_at) VALUES (?,'Prof. Ramon Cruz','Faculty','Computer Laboratory 3','Student reported headache during laboratory class','Student was escorted to the clinic for assessment.','Resolved','Assessed in clinic; symptoms improved after rest and hydration.',1,NOW())");
        $stmt->execute([$patientId]);
    }

    if (!$exists($db, 'referrals', $patientId)) {
        $stmt = $db->prepare("INSERT INTO referrals (patient_id,referral_date,referred_to,reason,status) VALUES (?,CURDATE(),'PLP Guidance and Wellness Office','Routine wellness support and stress-management resources.','Completed')");
        $stmt->execute([$patientId]);
    }

    if (!$exists($db, 'passport_access_logs', $patientId)) {
        $stmt = $db->prepare("INSERT INTO passport_access_logs (patient_id,ip_address,user_agent) VALUES (?,'127.0.0.1','CLINiQ Demo Browser')");
        $stmt->execute([$patientId]);
    }

    $db->commit();
    echo "Justine's complete student record is ready.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $error;
}
