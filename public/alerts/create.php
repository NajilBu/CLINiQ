<?php

require_once __DIR__ . '/../../app/helpers/view.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = db()->prepare(
        'INSERT INTO nurse_alerts (reporter_name, reporter_role, location, concern, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        trim($_POST['reporter_name'] ?? ''),
        trim($_POST['reporter_role'] ?? ''),
        trim($_POST['location'] ?? ''),
        trim($_POST['concern'] ?? ''),
        trim($_POST['details'] ?? ''),
    ]);

    header('Location: index.php');
    exit;
}

render_header('Submit Alert');
?>
<h1 class="h3 mb-4">Submit Emergency Alert</h1>
<form class="content-panel" method="post">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Reporter Name</label>
            <input class="form-control" name="reporter_name" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Reporter Role</label>
            <input class="form-control" name="reporter_role" placeholder="Student, Teacher, Staff">
        </div>
        <div class="col-md-6">
            <label class="form-label">Location</label>
            <input class="form-control" name="location" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Concern</label>
            <input class="form-control" name="concern" required>
        </div>
        <div class="col-12">
            <label class="form-label">Details</label>
            <textarea class="form-control" name="details" rows="4"></textarea>
        </div>
    </div>
    <div class="mt-4">
        <button class="btn btn-danger">Send Alert</button>
    </div>
</form>
<?php render_footer(); ?>
