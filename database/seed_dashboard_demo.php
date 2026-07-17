<?php

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/../app/config/env.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../app/services/AlertWorkflow.php';
require_once __DIR__ . '/../app/services/AppointmentWorkflow.php';

ensure_ape_workflow_schema();
ensure_alert_workflow_schema();
ensure_appointment_schema();

$db = db();
$adminId = (int)($db->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
$demoToday = (string)$db->query('SELECT CURDATE()')->fetchColumn();

echo "Seeding realistic dashboard demo data...\n";

function demo_date(string $time): string
{
    global $demoToday;
    return $demoToday . ' ' . $time;
}

function demo_days_ago(int $days, string $time = '09:00:00'): string
{
    global $demoToday;
    return date('Y-m-d H:i:s', strtotime($demoToday . " {$time} -{$days} days"));
}

function patient_id_by_student(PDO $db, string $studentNumber): int
{
    $stmt = $db->prepare('SELECT id FROM patients WHERE student_number = ?');
    $stmt->execute([$studentNumber]);
    return (int)$stmt->fetchColumn();
}

function insert_once(PDO $db, string $existsSql, array $existsParams, string $insertSql, array $insertParams): bool
{
    $stmt = $db->prepare($existsSql);
    $stmt->execute($existsParams);
    if ((int)$stmt->fetchColumn() > 0) {
        return false;
    }

    $stmt = $db->prepare($insertSql);
    $stmt->execute($insertParams);
    return true;
}

$students = [
    ['26-01024', 'Sofia', 'L.', 'Bautista', 'Female', 'BS Psychology A', 'O+', 'None', 'Lorna Bautista', '0917-204-1188'],
    ['26-01041', 'Rhea', 'C.', 'Ilagan', 'Female', 'BS Nursing A', 'A+', 'Penicillin', 'Marites Ilagan', '0918-337-9021'],
    ['26-01058', 'Marco', 'T.', 'Villanueva', 'Male', 'BSIT C', 'B+', 'Dust mites', 'Ramon Villanueva', '0920-818-4432'],
    ['26-01073', 'Chloe', 'V.', 'Mendoza', 'Female', 'BS Biology A', 'AB+', 'None', 'Carina Mendoza', '0916-552-0114'],
    ['26-01089', 'Daniel', 'P.', 'Reyes', 'Male', 'BSEd English B', 'O-', 'Seafood', 'Paolo Reyes', '0919-214-7710'],
    ['26-01102', 'Jessa', 'M.', 'Ocampo', 'Female', 'BSBA Marketing D', 'A-', 'None', 'Joy Ocampo', '0995-310-2248'],
    ['26-01119', 'Kevin', 'R.', 'Navarro', 'Male', 'BS Criminology A', 'B-', 'None', 'Katrina Navarro', '0917-998-6612'],
    ['26-01136', 'Alyssa', 'D.', 'Santos', 'Female', 'BSCS B', 'O+', 'Latex', 'Diana Santos', '0927-430-1195'],
    ['26-01155', 'Miguel', 'A.', 'Flores', 'Male', 'BS Accountancy A', 'A+', 'None', 'Anton Flores', '0918-781-4403'],
    ['26-01178', 'Bianca', 'R.', 'Garcia', 'Female', 'BSTM C', 'B+', 'Pollen', 'Riza Garcia', '0916-773-9004'],
];

$patientStmt = $db->prepare("
    INSERT INTO patients (
        student_number, first_name, middle_name, last_name, birthdate, sex, course_section,
        blood_type, allergies, existing_conditions, guardian_name, guardian_contact, emergency_token
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        first_name = VALUES(first_name),
        middle_name = VALUES(middle_name),
        last_name = VALUES(last_name),
        sex = VALUES(sex),
        course_section = VALUES(course_section),
        blood_type = VALUES(blood_type),
        allergies = VALUES(allergies),
        existing_conditions = VALUES(existing_conditions),
        guardian_name = VALUES(guardian_name),
        guardian_contact = VALUES(guardian_contact)
");

$patientIds = [];
foreach ($students as $index => $student) {
    [$number, $first, $middle, $last, $sex, $course, $blood, $allergies, $guardian, $contact] = $student;
    $birthdate = date('Y-m-d', strtotime('-18 years -' . ($index * 37) . ' days'));
    $condition = match ($number) {
        '26-01058' => 'History of mild asthma; carries rescue inhaler.',
        '26-01089' => 'Previous allergic reaction to seafood.',
        '26-01178' => 'Seasonal allergic rhinitis.',
        default => 'None reported.',
    };
    $token = hash('sha256', 'cliniq-demo-' . $number);
    $patientStmt->execute([$number, $first, $middle, $last, $birthdate, $sex, $course, $blood, $allergies, $condition, $guardian, $contact, $token]);
    $patientIds[$number] = patient_id_by_student($db, $number);
}
echo "Patients ready: " . count($patientIds) . "\n";

$inventory = [
    ['Paracetamol 500mg', 'Analgesic', 86, 'tabs', 100, '+11 months'],
    ['Cetirizine 10mg', 'Antihistamine', 18, 'tabs', 40, '+20 days'],
    ['Oral Rehydration Salts', 'Electrolyte', 24, 'sachets', 30, '+14 months'],
    ['Salbutamol Nebule 2.5mg', 'Respiratory', 9, 'nebules', 20, '+5 months'],
    ['Ibuprofen 200mg', 'Analgesic', 55, 'tabs', 30, '+9 months'],
    ['Amoxicillin 500mg', 'Antibiotic', 36, 'capsules', 25, '+7 months'],
    ['Digital Thermometer', 'Equipment', 3, 'units', 1, null],
    ['Pulse Oximeter', 'Equipment', 2, 'units', 1, null],
    ['Wheelchair', 'Equipment', 1, 'unit', 1, null],
    ['Ice Packs', 'Equipment', 4, 'pcs', 5, null],
];

$inventoryExists = $db->prepare('SELECT id FROM inventory_items WHERE item_name = ? LIMIT 1');
$inventoryInsert = $db->prepare('INSERT INTO inventory_items (item_name, category, quantity, unit, reorder_level, expiration_date) VALUES (?, ?, ?, ?, ?, ?)');
$inventoryUpdate = $db->prepare('UPDATE inventory_items SET category = ?, quantity = ?, unit = ?, reorder_level = ?, expiration_date = ? WHERE item_name = ?');
foreach ($inventory as [$name, $category, $qty, $unit, $reorder, $expiryOffset]) {
    $expiry = $expiryOffset ? date('Y-m-d', strtotime($expiryOffset)) : null;
    $inventoryExists->execute([$name]);
    if ($inventoryExists->fetchColumn()) {
        $inventoryUpdate->execute([$category, $qty, $unit, $reorder, $expiry, $name]);
    } else {
        $inventoryInsert->execute([$name, $category, $qty, $unit, $reorder, $expiry]);
    }
}
$legacyInventorySamples = [
    'Ibuprofen 200mg',
    'Amoxicillin 500mg',
    'Loperamide 2mg',
    'Mefenamic Acid 500mg',
    'Salbutamol Nebule',
    'Band-Aids',
    'Gauze Pads',
    'Povidone Iodine 10% 120ml',
    'Surgical Tape',
    'Elastic Bandage 3in',
    'Digital Thermometer Probe Covers',
];
$deleteLegacyInventory = $db->prepare('DELETE FROM inventory_items WHERE item_name = ? AND archived_at IS NULL');
foreach ($legacyInventorySamples as $legacyName) {
    $deleteLegacyInventory->execute([$legacyName]);
}
echo "Inventory ready: " . count($inventory) . "\n";

$visits = [
    ['26-01024', '08:10:00', 'Fever with sore throat', 'Temperature 38.2 C, throat pain, mild body weakness', 38.2, '112/74', 92, 'Completed', 'Medical Consult', 'Given Paracetamol 500mg, advised oral fluids, mask use, and sent home with guardian notification.'],
    ['26-01058', '08:55:00', 'Wheezing after PE class', 'Shortness of breath, audible wheeze, no chest pain', 37.0, '118/78', 104, 'Active', 'Emergency', 'Nebulized Salbutamol given, monitored for 45 minutes, advised follow-up if symptoms recur.'],
    ['26-01119', '09:40:00', 'Right ankle sprain', 'Pain and swelling after basketball activity', 36.8, '120/80', 84, 'Completed', 'Wound Care', 'Cold compress applied, elastic bandage used, advised rest and elevation.'],
    ['26-01136', '10:25:00', 'Migraine episode', 'Headache with light sensitivity, no vomiting', 36.9, '110/70', 76, 'Active', 'Health Monitoring', 'Rested in observation area, hydration encouraged, parent informed.'],
    ['26-01089', '11:15:00', 'Seafood allergy concern', 'Itchy lips and scattered hives after lunch', 37.1, '116/72', 88, 'Unaddressed', 'Medical Consult', 'Visitor/patient self-registration. Awaiting clinic assessment.'],
    ['26-01155', '13:05:00', 'Minor hand laceration', 'Small cut from laboratory glassware, bleeding controlled', 36.7, '118/76', 78, 'Completed', 'Wound Care', 'Wound cleaned, gauze dressing applied, advised return for dressing check.'],
    ['26-01178', '14:20:00', 'Allergic rhinitis flare-up', 'Sneezing, watery eyes, nasal congestion', 36.6, '108/68', 74, 'Completed', 'Medical Consult', 'Cetirizine provided, advised avoiding dusty storage room.'],
];

$visitFind = $db->prepare('SELECT id FROM clinic_visits WHERE patient_id = ? AND chief_complaint = ? ORDER BY id DESC LIMIT 1');
$visitInsert = $db->prepare('INSERT INTO clinic_visits (patient_id, visit_datetime, chief_complaint, symptoms, temperature, blood_pressure, pulse_rate, status, visit_purpose, visit_source, action_taken, recorded_by, attended_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$visitUpdate = $db->prepare('UPDATE clinic_visits SET visit_datetime = ?, symptoms = ?, temperature = ?, blood_pressure = ?, pulse_rate = ?, status = ?, visit_purpose = ?, visit_source = ?, action_taken = ?, recorded_by = ?, attended_by = ? WHERE id = ?');
foreach ($visits as [$number, $time, $complaint, $symptoms, $temp, $bp, $pulse, $status, $purpose, $action]) {
    $visitFind->execute([$patientIds[$number], $complaint]);
    $visitId = $visitFind->fetchColumn();
    $attendedBy = in_array($status, ['Active', 'Completed'], true) ? $adminId : null;
    if ($visitId) {
        $visitUpdate->execute([demo_date($time), $symptoms, $temp, $bp, $pulse, $status, $purpose, 'Staff Recorded', $action, $adminId, $attendedBy, $visitId]);
    } else {
        $visitInsert->execute([$patientIds[$number], demo_date($time), $complaint, $symptoms, $temp, $bp, $pulse, $status, $purpose, 'Staff Recorded', $action, $adminId, $attendedBy]);
    }
}
echo "Today's visits ready: " . count($visits) . "\n";

$appointments = [
    ['26-01155', '09:30:00', 'Wound dressing re-check', 'Check hand laceration dressing before afternoon laboratory class.', 'Pending'],
    ['26-01058', '10:30:00', 'Asthma follow-up assessment', 'Review breathing status after morning PE-related wheezing.', 'Scheduled'],
    ['26-01073', '13:00:00', 'APE hard-copy document review', 'Review UHS medical record, consent form, and lab request form.', 'Scheduled'],
    ['26-01089', '14:00:00', 'Food allergy counseling', 'Discuss canteen exposure, medication instructions, and emergency warning signs.', 'Pending'],
    ['26-01102', '15:00:00', 'APE online submission review', 'Confirm uploaded APE files match checked hard copies.', 'Scheduled'],
];

$appointmentFind = $db->prepare('SELECT id FROM appointments WHERE patient_id = ? AND purpose = ? ORDER BY id DESC LIMIT 1');
$appointmentInsert = $db->prepare('INSERT INTO appointments (patient_id, appointment_datetime, purpose, status, notes) VALUES (?, ?, ?, ?, ?)');
$appointmentUpdate = $db->prepare('UPDATE appointments SET appointment_datetime = ?, status = ?, notes = ? WHERE id = ?');
foreach ($appointments as [$number, $time, $purpose, $notes, $status]) {
    $appointmentFind->execute([$patientIds[$number], $purpose]);
    $appointmentId = $appointmentFind->fetchColumn();
    if ($appointmentId) {
        $appointmentUpdate->execute([demo_date($time), $status, $notes, $appointmentId]);
    } else {
        $appointmentInsert->execute([$patientIds[$number], demo_date($time), $purpose, $status, $notes]);
    }
}
echo "Today's appointments ready: " . count($appointments) . "\n";

$alerts = [
    ['26-01058', 'Coach Marvin Dela Peña', 'PE Instructor', 'Gymnasium court', 'Student experiencing asthma symptoms', 'Student reported tightness of chest after shuttle run. Clinic assistance requested immediately.', 'Pending', '08:48:00'],
    ['26-01024', 'Ms. Teresa Mendoza', 'Library Staff', 'Library second floor', 'Student with fever and weakness', 'Student looked pale and requested help after feeling dizzy while studying.', 'Pending', '09:05:00'],
    ['26-01155', 'Mr. Allan Cruz', 'Laboratory Technician', 'Science Laboratory 2', 'Minor glassware cut reported', 'Student sustained a small hand cut while cleaning lab materials; bleeding controlled before clinic visit.', 'Resolved', '13:00:00'],
];

$alertFind = $db->prepare('SELECT id FROM nurse_alerts WHERE location = ? AND concern = ? ORDER BY id DESC LIMIT 1');
$alertInsert = $db->prepare('INSERT INTO nurse_alerts (patient_id, reporter_name, reporter_role, location, concern, details, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$alertUpdate = $db->prepare('UPDATE nurse_alerts SET patient_id = ?, reporter_name = ?, reporter_role = ?, details = ?, status = ?, created_at = ?, updated_at = ? WHERE id = ?');
foreach ($alerts as [$number, $reporter, $role, $location, $concern, $details, $status, $time]) {
    $alertFind->execute([$location, $concern]);
    $alertId = $alertFind->fetchColumn();
    if ($alertId) {
        $alertUpdate->execute([$patientIds[$number], $reporter, $role, $details, $status, demo_date($time), demo_date($time), $alertId]);
    } else {
        $alertInsert->execute([$patientIds[$number], $reporter, $role, $location, $concern, $details, $status, demo_date($time), demo_date($time)]);
    }
}
echo "Alerts ready: " . count($alerts) . "\n";

$alertRiskDemos = [
    [
        'Gymnasium court',
        'Student experiencing asthma symptoms',
        'Breathing difficulty',
        "Incident type: Breathing difficulty\nObserved condition: Dizzy or weak\nBreathing: Wheezing\nBleeding: None observed\nPain level: 4-6 - Moderate pain\nMobility: Needs assistance\nReporter notes: Student reported tightness of chest after shuttle run. Clinic assistance requested immediately.",
        'High',
        9,
        "Incident type involves breathing difficulty (+4)\nReporter observed dizziness or weakness (+1)\nWheezing reported (+3)\nStudent needs assistance moving (+1)",
        'Urgent nurse response needed. Go to the reported location, check vital signs, give appropriate first aid, monitor closely, and prepare referral if symptoms worsen.',
        'uploads/incidents/demo-asthma-alert.jpg',
    ],
    [
        'Library second floor',
        'Student with fever and weakness',
        'Fever or illness',
        "Incident type: Fever or illness\nObserved condition: Dizzy or weak\nBreathing: Normal\nBleeding: None observed\nPain level: 1-3 - Mild pain\nMobility: Can walk\nReporter notes: Student looked pale and requested help after feeling dizzy while studying.",
        'Moderate',
        2,
        "Incident type involves illness symptoms (+1)\nReporter observed dizziness or weakness (+1)",
        'Prompt clinic assessment needed. Assist the student to the clinic when safe, provide first aid, observe symptoms, and document the response.',
        null,
    ],
    [
        'Science Laboratory 2',
        'Minor glassware cut reported',
        'Bleeding or wound',
        "Incident type: Bleeding or wound\nObserved condition: Awake and responsive\nBreathing: Normal\nBleeding: Minor bleeding\nPain level: 1-3 - Mild pain\nMobility: Can walk\nReporter notes: Student sustained a small hand cut while cleaning lab materials; bleeding controlled before clinic visit.",
        'Moderate',
        4,
        "Incident type involves bleeding or wound care (+3)\nMinor bleeding reported (+1)",
        'Prompt clinic assessment needed. Assist the student to the clinic when safe, provide first aid, observe symptoms, and document the response.',
        null,
    ],
];
$alertRiskUpdate = $db->prepare('
    UPDATE nurse_alerts
    SET incident_type = ?, report_answers = ?, risk_level = ?, risk_score = ?, risk_reasons = ?, response_guidance = ?, photo_path = ?
    WHERE location = ? AND concern = ?
');
foreach ($alertRiskDemos as [$location, $concern, $incidentType, $reportAnswers, $riskLevel, $riskScore, $riskReasons, $responseGuidance, $photoPath]) {
    $alertRiskUpdate->execute([$incidentType, $reportAnswers, $riskLevel, $riskScore, $riskReasons, $responseGuidance, $photoPath, $location, $concern]);
}
echo "Alert risk classifier demo data ready: " . count($alertRiskDemos) . "\n";

$apeRecords = [
    ['26-01073', 'Not Checked', 'Registered', null, 'Pending', 0, 'Pending', 'UHS Medical Record and Dental Record pending hard-copy review.', null, 'Document review needed before student upload.', 2],
    ['26-01102', 'Pre-Verified', 'Submitted', '/uploads/ape/26-01102-ape-bundle.pdf', 'Pending', 0, 'Pending', 'Online files uploaded; verify against checked hard copies.', null, 'Consent, lab request, medical, dental, and referral forms uploaded.', 1],
    ['26-01024', 'Needs Correction', 'Submitted', null, 'Needs Correction', 0, 'Pending', 'Lab request form lacks physician signature.', 'Please return with signed lab request form before online submission.', 'Hard-copy requirements need correction.', 4],
    ['26-01058', 'Pre-Verified', 'Follow-up Required', '/uploads/ape/26-01058-ape-bundle.pdf', 'Verified', 1, 'For Follow-up', 'History of asthma noted during APE review. Needs pulmonary clearance after school clinic observation.', 'Submit pulmonary clearance or treatment note after follow-up consultation.', 'Pulmonary clearance required.', 5],
    ['26-01089', 'Pre-Verified', 'Follow-up Required', '/uploads/ape/26-01089-ape-bundle.pdf', 'Verified', 1, 'Submitted', 'Food allergy history documented. Student submitted allergist clearance for review.', 'Clearance submitted; wait for clinic approval.', 'Allergy clearance waiting for approval.', 1],
    ['26-01136', 'Pre-Verified', 'Cleared', '/uploads/ape/26-01136-ape-bundle.pdf', 'Verified', 0, 'Cleared', 'No significant findings. Fit to study.', 'APE completed and archived.', null, 0],
];

$apeExists = $db->prepare('SELECT id FROM ape_records WHERE patient_id = ? ORDER BY id DESC LIMIT 1');
$apeInsert = $db->prepare('
    INSERT INTO ape_records (
        patient_id, exam_date, document_type, requirement_status, workflow_status, document_path,
        verification_status, verified_by, clearance_status, clinical_remarks, student_visible_note,
        follow_up_required, missing_items, result_status, result_notes, created_at, updated_at
    ) VALUES (?, CURDATE(), "APE Form", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$apeUpdate = $db->prepare('
    UPDATE ape_records SET
        exam_date = CURDATE(),
        document_type = "APE Form",
        requirement_status = ?,
        workflow_status = ?,
        document_path = ?,
        verification_status = ?,
        verified_by = ?,
        clearance_status = ?,
        clinical_remarks = ?,
        student_visible_note = ?,
        follow_up_required = ?,
        missing_items = ?,
        result_status = ?,
        result_notes = ?,
        created_at = ?,
        updated_at = ?
    WHERE id = ?
');

foreach ($apeRecords as [$number, $requirement, $workflow, $path, $verification, $followUp, $clearance, $remarks, $studentNote, $missing, $daysWaiting]) {
    $patientId = $patientIds[$number];
    $verifiedBy = in_array($verification, ['Verified', 'Needs Correction'], true) ? $adminId : null;
    $resultStatus = $workflow === 'Cleared' ? 'Fit to Proceed' : ($followUp ? 'With Finding' : 'Pending');
    $resultNotes = $followUp ? 'Follow-up requirement tracked by clinic.' : ($workflow === 'Cleared' ? 'Cleared for academic activities.' : null);
    $createdAt = demo_days_ago(max($daysWaiting + 1, 1), '10:00:00');
    $updatedAt = demo_days_ago($daysWaiting, '10:30:00');

    $apeExists->execute([$patientId]);
    $apeId = $apeExists->fetchColumn();
    if ($apeId) {
        $apeUpdate->execute([$requirement, $workflow, $path, $verification, $verifiedBy, $clearance, $remarks, $studentNote, $followUp, $missing, $resultStatus, $resultNotes, $createdAt, $updatedAt, $apeId]);
    } else {
        $apeInsert->execute([$patientId, $requirement, $workflow, $path, $verification, $verifiedBy, $clearance, $remarks, $studentNote, $followUp, $missing, $resultStatus, $resultNotes, $createdAt, $updatedAt]);
        $apeId = $db->lastInsertId();
    }

    insert_once(
        $db,
        'SELECT COUNT(*) FROM ape_activity_logs WHERE ape_record_id = ? AND action_label = ?',
        [$apeId, 'Dashboard demo status prepared'],
        'INSERT INTO ape_activity_logs (ape_record_id, user_id, action_label, notes, created_at) VALUES (?, ?, ?, ?, ?)',
        [$apeId, $adminId, 'Dashboard demo status prepared', $remarks, $updatedAt]
    );
}
echo "APE workflow records ready: " . count($apeRecords) . "\n";

$referrals = [
    ['26-01058', 'City Health Office - Pulmonary Clinic', 'Asthma symptoms during PE; student advised pulmonary clearance for APE follow-up.', 'Pending', '-1 day'],
    ['26-01089', 'Allergy and Immunology Clinic', 'Food allergy history and recent hives after canteen exposure.', 'Pending', 'today'],
    ['26-01119', 'Partner Diagnostic Center', 'Right ankle sprain; X-ray advised only if swelling worsens within 24 hours.', 'Completed', '-3 days'],
];

foreach ($referrals as [$number, $facility, $reason, $status, $offset]) {
    $date = $offset === 'today' ? $demoToday : date('Y-m-d', strtotime($demoToday . ' ' . $offset));
    insert_once(
        $db,
        'SELECT COUNT(*) FROM referrals WHERE patient_id = ? AND referred_to = ? AND referral_date = ?',
        [$patientIds[$number], $facility, $date],
        'INSERT INTO referrals (patient_id, referral_date, referred_to, reason, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$patientIds[$number], $date, $facility, $reason, $status, $date . ' 11:30:00']
    );
}
echo "Referrals ready: " . count($referrals) . "\n";

echo "Dashboard demo data complete.\n";
