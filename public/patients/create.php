<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'INSERT INTO patients (student_number, first_name, middle_name, last_name, birthdate, sex, course_section, blood_type, allergies, existing_conditions, emergency_instructions, guardian_name, guardian_contact, emergency_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        trim($_POST['student_number'] ?? ''),
        trim($_POST['first_name'] ?? ''),
        trim($_POST['middle_name'] ?? ''),
        trim($_POST['last_name'] ?? ''),
        $_POST['birthdate'] ?: null,
        $_POST['sex'] ?: null,
        trim($_POST['course_section'] ?? ''),
        trim($_POST['blood_type'] ?? ''),
        trim($_POST['allergies'] ?? ''),
        trim($_POST['existing_conditions'] ?? ''),
        trim($_POST['emergency_instructions'] ?? ''),
        trim($_POST['guardian_name'] ?? ''),
        trim($_POST['guardian_contact'] ?? ''),
        $token,
    ]);

    header('Location: index.php');
    exit;
}

render_header('Add Patient');
?>
<h1 class="h3 mb-4">Add Patient</h1>
<form class="content-panel" method="post">
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Student Number</label>
            <input class="form-control" name="student_number" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">First Name</label>
            <input class="form-control" name="first_name" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Middle Name</label>
            <input class="form-control" name="middle_name">
        </div>
        <div class="col-md-4">
            <label class="form-label">Last Name</label>
            <input class="form-control" name="last_name" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Birthdate</label>
            <input class="form-control" name="birthdate" type="date">
        </div>
        <div class="col-md-4">
            <label class="form-label">Sex</label>
            <select class="form-select" name="sex">
                <option value="">Select</option>
                <option>Male</option>
                <option>Female</option>
                <option>Other</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Course/Section</label>
            <input class="form-control" name="course_section">
        </div>
        <div class="col-md-6">
            <label class="form-label">Blood Type</label>
            <input class="form-control" name="blood_type">
        </div>
        <div class="col-md-6">
            <label class="form-label">Allergies</label>
            <textarea class="form-control" name="allergies" rows="3"></textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label">Existing Conditions</label>
            <textarea class="form-control" name="existing_conditions" rows="3"></textarea>
        </div>
        <div class="col-12">
            <label class="form-label">Emergency Instructions</label>
            <textarea class="form-control" name="emergency_instructions" rows="3"></textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label">Guardian Name</label>
            <input class="form-control" name="guardian_name">
        </div>
        <div class="col-md-6">
            <label class="form-label">Guardian Contact</label>
            <input class="form-control" name="guardian_contact">
        </div>
    </div>
    <div class="mt-4">
        <button class="btn btn-primary">Save Patient</button>
        <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
    </div>
</form>
<?php render_footer(); ?>
