<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$patients = db()->query('SELECT * FROM patients ORDER BY last_name, first_name LIMIT 100')->fetchAll();

render_header('Patients');
?>
<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0">Patients</h1>
    <a class="btn btn-primary" href="create.php">Add Patient</a>
</div>

<section class="content-panel">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
            <tr>
                <th>Student No.</th>
                <th>Name</th>
                <th>Course/Section</th>
                <th>Guardian Contact</th>
                <th>Passport</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($patients as $patient): ?>
                <tr>
                    <td><?= e($patient['student_number']) ?></td>
                    <td><?= e($patient['last_name'] . ', ' . $patient['first_name']) ?></td>
                    <td><?= e($patient['course_section']) ?></td>
                    <td><?= e($patient['guardian_contact']) ?></td>
                    <td><a href="<?= app_url('emergency.php?token=' . $patient['emergency_token']) ?>" target="_blank">Open</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$patients): ?>
                <tr><td colspan="5" class="text-secondary">No patients yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
