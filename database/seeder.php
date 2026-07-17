<?php

// CLI only
if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/../app/config/env.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AlertWorkflow.php';

echo "Starting CLINiQ Database Seeder...\n";

$db = db();
ensure_alert_workflow_schema();

// Clear existing data (optional, but good for a fresh seed. Let's not delete users.)
echo "Clearing existing data (except users)...\n";
$db->query("SET FOREIGN_KEY_CHECKS = 0;");
$db->query("TRUNCATE TABLE referrals;");
$db->query("TRUNCATE TABLE ape_records;");
$db->query("TRUNCATE TABLE clinic_visits;");
$db->query("TRUNCATE TABLE nurse_alerts;");
$db->query("TRUNCATE TABLE inventory_loans;");
$db->query("TRUNCATE TABLE inventory_items;");
$db->query("TRUNCATE TABLE patients;");
$db->query("SET FOREIGN_KEY_CHECKS = 1;");

// Generate Patients
echo "Seeding Patients...\n";
$firstNames = ['Juan', 'Maria', 'Jose', 'Ana', 'Pedro', 'Carmela', 'Miguel', 'Lourdes', 'Carlos', 'Teresa', 'Mark', 'Jessa', 'Kevin', 'Rhea', 'John'];
$lastNames = ['Dela Cruz', 'Garcia', 'Reyes', 'Ramos', 'Mendoza', 'Santos', 'Flores', 'Gonzales', 'Bautista', 'Villanueva', 'Cruz', 'Ocampo', 'Aquino', 'Navarro', 'Mercado'];
$courses = ['BSIT A', 'BSIT B', 'BSIT C', 'BSIT D', 'BSIT E', 'BSCS A', 'BSBA B', 'BSED C', 'BSN D'];
$bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$allergiesList = ['None', 'None', 'None', 'Peanuts', 'Penicillin', 'Dust Mites', 'Seafood', 'None', 'Latex'];

$patientIds = [];
$tokens = [];

for ($i = 0; $i < 30; $i++) {
    $fn = $firstNames[array_rand($firstNames)];
    $ln = $lastNames[array_rand($lastNames)];
    $mn = $lastNames[array_rand($lastNames)];
    $sex = in_array($fn, ['Maria', 'Ana', 'Carmela', 'Lourdes', 'Teresa', 'Jessa', 'Rhea']) ? 'Female' : 'Male';
    $year = rand(2000, 2005);
    $month = str_pad(rand(1, 12), 2, '0', STR_PAD_LEFT);
    $day = str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);
    
    $studentNum = rand(21, 26) . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
    $token = bin2hex(random_bytes(16));
    
    $stmt = $db->prepare("INSERT INTO patients (student_number, first_name, middle_name, last_name, birthdate, sex, course_section, blood_type, allergies, emergency_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $studentNum,
        $fn,
        substr($mn, 0, 1) . '.',
        $ln,
        "$year-$month-$day",
        $sex,
        $courses[array_rand($courses)],
        $bloodTypes[array_rand($bloodTypes)],
        $allergiesList[array_rand($allergiesList)],
        $token
    ]);
    
    $patientIds[] = $db->lastInsertId();
}

// Generate Inventory
echo "Seeding Inventory...\n";
$items = [
    ['Paracetamol 500mg', 'Analgesic', 86, 'tabs', 100, date('Y-m-d', strtotime('+11 months'))], // Low stock
    ['Cetirizine 10mg', 'Antihistamine', 18, 'tabs', 40, date('Y-m-d', strtotime('+20 days'))], // Low stock, expiring soon
    ['Oral Rehydration Salts', 'Electrolyte', 24, 'sachets', 30, date('Y-m-d', strtotime('+14 months'))], // Low stock
    ['Salbutamol Nebule 2.5mg', 'Respiratory', 9, 'nebules', 20, date('Y-m-d', strtotime('+5 months'))], // Low stock
    ['Ibuprofen 200mg', 'Analgesic', 55, 'tabs', 30, date('Y-m-d', strtotime('+9 months'))],
    ['Amoxicillin 500mg', 'Antibiotic', 36, 'capsules', 25, date('Y-m-d', strtotime('+7 months'))],
    ['Digital Thermometer', 'Equipment', 3, 'units', 1, null],
    ['Pulse Oximeter', 'Equipment', 2, 'units', 1, null],
    ['Wheelchair', 'Equipment', 1, 'unit', 1, null],
    ['Ice Packs', 'Equipment', 4, 'pcs', 5, null], // Low stock
];

foreach ($items as $item) {
    $stmt = $db->prepare("INSERT INTO inventory_items (item_name, category, quantity, unit, reorder_level, expiration_date) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute($item);
}

// Generate Visits
echo "Seeding Visits...\n";
$complaints = [
    ['Fever and headache', 'headache, fever', 38.5, '120/80', 85, 'Given Paracetamol 500mg. Advised to rest and drink plenty of fluids.'],
    ['Stomachache', 'abdominal pain', 37.0, '110/70', 75, 'Given Antacid. Advised to avoid spicy foods.'],
    ['Dizziness', 'dizziness, nausea', 36.8, '100/60', 65, 'Advised to rest for 30 minutes in the clinic bed.'],
    ['Sprained ankle', 'pain, swelling', 37.1, '125/82', 90, 'Applied cold compress. Wrapped with elastic bandage.'],
    ['Difficulty breathing', 'shortness of breath', 37.5, '130/85', 110, 'Administered Salbutamol via nebulizer. Monitored for 1 hour.'],
    ['Cut on finger', 'bleeding', 36.9, '115/75', 80, 'Cleaned with Povidone Iodine. Applied Band-Aid.'],
    ['Chest pain', 'chest pain, sweating', 37.2, '140/90', 105, 'Immediate assessment. Called emergency services.'],
    ['Allergic reaction', 'hives, itching', 37.3, '120/80', 88, 'Given Cetirizine 10mg. Observed for 30 minutes.']
];

// Get admin user ID for recorded_by
$adminId = $db->query("SELECT id FROM users LIMIT 1")->fetchColumn();

for ($i = 0; $i < 60; $i++) {
    $pid = $patientIds[array_rand($patientIds)];
    $c = $complaints[array_rand($complaints)];
    
    // Distribute visits over the last 6 months
    $daysAgo = rand(0, 180);
    $visitDate = date('Y-m-d H:i:s', strtotime("-$daysAgo days " . rand(8, 16) . ":" . str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT) . ":00"));
    
    if (in_array($c[0], ['Difficulty breathing', 'Chest pain'], true) && $daysAgo <= 14) {
        $visitStatus = 'Active';
    } elseif (rand(1, 18) === 1) {
        $visitStatus = 'Cancelled';
    } elseif (rand(1, 8) === 1) {
        $visitStatus = 'Unaddressed';
    } else {
        $visitStatus = 'Completed';
    }
    $visitPurpose = in_array($c[0], ['Sprained ankle', 'Cut on finger'], true) ? 'Wound Care' : 'Medical Consult';
    $attendedBy = in_array($visitStatus, ['Active', 'Completed'], true) ? $adminId : null;

    $stmt = $db->prepare("INSERT INTO clinic_visits (patient_id, visit_datetime, chief_complaint, symptoms, temperature, blood_pressure, pulse_rate, status, visit_purpose, visit_source, action_taken, recorded_by, attended_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $pid,
        $visitDate,
        $c[0],
        $c[1],
        $c[2],
        $c[3],
        $c[4],
        $visitStatus,
        $visitPurpose,
        'Staff Recorded',
        $c[5],
        $adminId,
        $attendedBy
    ]);
}

// Generate Alerts
echo "Seeding Alerts...\n";
$alerts = [
    ['Student fainted in the library', 'Library 2nd Floor', 'Student fainted'],
    ['Accident in the chemistry lab', 'Science Bldg Rm 302', 'Chemical spill on hand'],
    ['Teacher complaining of severe chest pain', 'Faculty Room', 'Chest pain'],
    ['Student hyperventilating', 'Gymnasium', 'Difficulty breathing'],
    ['Sports injury during PE class', 'Open Field', 'Suspected fractured arm']
];

$statuses = ['Pending', 'In Progress', 'Resolved', 'Cancelled'];

for ($i = 0; $i < 15; $i++) {
    $a = $alerts[array_rand($alerts)];
    $status = $statuses[array_rand($statuses)];
    
    // Bias towards older being resolved, newer being pending
    if (rand(1, 10) > 7) {
        $status = 'Pending';
        $daysAgo = rand(0, 1);
    } else {
        $daysAgo = rand(2, 30);
    }
    
    $createdAt = date('Y-m-d H:i:s', strtotime("-$daysAgo days " . rand(8, 16) . ":" . str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT) . ":00"));
    
    $answers = collect_incident_report_answers([
        'incident_type' => match ($a[2]) {
            'Difficulty breathing' => 'Breathing difficulty',
            'Chest pain' => 'Other concern',
            'Student fainted' => 'Fainting or unconscious',
            'Chemical spill on hand' => 'Bleeding or wound',
            default => 'Injury or fall',
        },
        'observed_condition' => str_contains(strtolower($a[2]), 'fainted') ? 'Unconscious' : 'Awake and responsive',
        'breathing_status' => str_contains(strtolower($a[2]), 'breathing') || str_contains(strtolower($a[2]), 'chest') ? 'Shortness of breath' : 'Normal',
        'bleeding_status' => str_contains(strtolower($a[2]), 'spill') ? 'Minor bleeding' : 'None observed',
        'pain_level' => str_contains(strtolower($a[2]), 'chest') || str_contains(strtolower($a[2]), 'fractured') ? '7-10 - Severe pain' : '4-6 - Moderate pain',
        'mobility_status' => str_contains(strtolower($a[2]), 'fractured') || str_contains(strtolower($a[2]), 'fainted') ? 'Cannot stand or walk' : 'Needs assistance',
        'notes' => $a[0],
        'concern' => $a[2],
    ]);
    $classification = classify_reported_incident($answers);
    $reportAnswers = incident_report_answers_text($answers);
    $stmt = $db->prepare("
        INSERT INTO nurse_alerts (
            reporter_name, reporter_role, location, concern, incident_type, details, report_answers,
            risk_level, risk_score, risk_reasons, response_guidance, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)],
        array_rand(['Student' => 1, 'Teacher' => 1, 'Staff' => 1]),
        $a[1],
        $a[0],
        $answers['incident_type'],
        $a[2],
        $reportAnswers,
        $classification['level'],
        $classification['score'],
        incident_risk_reasons_text($classification),
        $classification['guidance'],
        $status,
        $createdAt
    ]);
}

// Generate APE Records
echo "Seeding APE Records...\n";
$apeStatuses = ['Pending', 'Verified', 'Needs Correction'];
for ($i = 0; $i < 20; $i++) {
    $pid = $patientIds[array_rand($patientIds)];
    $daysAgo = rand(5, 90);
    $examDate = date('Y-m-d', strtotime("-$daysAgo days"));
    $status = $apeStatuses[array_rand($apeStatuses)];
    
    $text = "PLP CLINIC - ANNUAL PHYSICAL EXAMINATION\nName: [REDACTED]\nDate: $examDate\nHeight: 165cm\nWeight: 60kg\nBP: 120/80\nVision: 20/20\nFindings: Normal. Fit to enroll.";
    
    $stmt = $db->prepare("INSERT INTO ape_records (patient_id, exam_date, verification_status, extracted_text, verified_by, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $pid,
        $examDate,
        $status,
        $text,
        ($status === 'Verified' || $status === 'Needs Correction') ? $adminId : null,
        $examDate . ' 10:00:00'
    ]);
}

// Generate Referrals
echo "Seeding Referrals...\n";
$facilities = ['City General Hospital', 'Provincial Medical Center', 'Eye Specialist Clinic', 'Orthopedic Center'];
$reasons = ['Further evaluation for chest pain', 'Persistent high fever not responding to medication', 'Suspected fracture requiring X-Ray', 'Severe allergic reaction needing immediate ER care'];
$refStatuses = ['Pending', 'Completed', 'Cancelled'];

for ($i = 0; $i < 10; $i++) {
    $pid = $patientIds[array_rand($patientIds)];
    $daysAgo = rand(1, 60);
    $refDate = date('Y-m-d', strtotime("-$daysAgo days"));
    $status = $refStatuses[array_rand($refStatuses)];
    
    $stmt = $db->prepare("INSERT INTO referrals (patient_id, referral_date, referred_to, reason, status, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $pid,
        $refDate,
        $facilities[array_rand($facilities)],
        $reasons[array_rand($reasons)],
        $status,
        $refDate . ' 14:30:00'
    ]);
}

echo "Database seeded successfully!\n";

