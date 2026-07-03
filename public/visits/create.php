<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/RiskClassifier.php';
require_login();

$patients = db()->query('SELECT id, student_number, first_name, last_name FROM patients ORDER BY last_name, first_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $risk = classify_patient_risk($_POST);
    $stmt = db()->prepare(
        'INSERT INTO clinic_visits (patient_id, visit_datetime, chief_complaint, symptoms, temperature, blood_pressure, pulse_rate, risk_level, risk_score, action_taken, recorded_by)
         VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $_POST['patient_id'],
        trim($_POST['chief_complaint'] ?? ''),
        trim($_POST['symptoms'] ?? ''),
        $_POST['temperature'] ?: null,
        trim($_POST['blood_pressure'] ?? ''),
        $_POST['pulse_rate'] ?: null,
        $risk['level'],
        $risk['score'],
        trim($_POST['action_taken'] ?? ''),
        current_user()['id'],
    ]);

    header('Location: index.php');
    exit;
}

render_header('Record Visit');
?>
<div>
    <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Record Clinic Visit</h1>
    <p class="text-sm font-bold text-slate-500 mt-1">Add triage notes and apply rule-based risk classification.</p>
</div>
<form class="clinic-card p-6 md:p-8" method="post">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div>
            <label class="clinic-label">Patient</label>
            <select class="clinic-select" name="patient_id" required>
                <option value="">Select patient</option>
                <?php foreach ($patients as $patient): ?>
                    <option value="<?= (int) $patient['id'] ?>"><?= e($patient['last_name'] . ', ' . $patient['first_name'] . ' - ' . $patient['student_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="clinic-label">Chief Complaint</label>
            <input class="clinic-input" name="chief_complaint" required>
        </div>
        <div class="md:col-span-2">
            <label class="clinic-label">Symptoms</label>
            <textarea class="clinic-textarea" name="symptoms" rows="3" placeholder="Example: fever, chest pain, difficulty breathing"></textarea>
        </div>
        <div>
            <label class="clinic-label">Temperature</label>
            <input class="clinic-input" name="temperature" type="number" step="0.1">
        </div>
        <div>
            <label class="clinic-label">Blood Pressure</label>
            <input class="clinic-input" name="blood_pressure">
        </div>
        <div>
            <label class="clinic-label">Pulse Rate</label>
            <input class="clinic-input" name="pulse_rate" type="number">
        </div>
        <div class="md:col-span-2">
            <label class="clinic-label">Action Taken</label>
            <textarea class="clinic-textarea" name="action_taken" rows="3"></textarea>
        </div>
    </div>
    <div class="mt-6">
        <button class="px-5 py-3 bg-primary text-white rounded-2xl text-sm font-bold shadow-lg shadow-primary/20">Save Visit</button>
    </div>
</form>
<?php render_footer(); ?>
