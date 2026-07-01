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
<h1 class="h3 mb-4">Record Clinic Visit</h1>
<form class="content-panel" method="post">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Patient</label>
            <select class="form-select" name="patient_id" required>
                <option value="">Select patient</option>
                <?php foreach ($patients as $patient): ?>
                    <option value="<?= (int) $patient['id'] ?>"><?= e($patient['last_name'] . ', ' . $patient['first_name'] . ' - ' . $patient['student_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Chief Complaint</label>
            <input class="form-control" name="chief_complaint" required>
        </div>
        <div class="col-12">
            <label class="form-label">Symptoms</label>
            <textarea class="form-control" name="symptoms" rows="3" placeholder="Example: fever, chest pain, difficulty breathing"></textarea>
        </div>
        <div class="col-md-4">
            <label class="form-label">Temperature</label>
            <input class="form-control" name="temperature" type="number" step="0.1">
        </div>
        <div class="col-md-4">
            <label class="form-label">Blood Pressure</label>
            <input class="form-control" name="blood_pressure">
        </div>
        <div class="col-md-4">
            <label class="form-label">Pulse Rate</label>
            <input class="form-control" name="pulse_rate" type="number">
        </div>
        <div class="col-12">
            <label class="form-label">Action Taken</label>
            <textarea class="form-control" name="action_taken" rows="3"></textarea>
        </div>
    </div>
    <div class="mt-4">
        <button class="btn btn-primary">Save Visit</button>
    </div>
</form>
<?php render_footer(); ?>
