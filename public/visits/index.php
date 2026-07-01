<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$visits = db()->query('SELECT v.*, p.first_name, p.last_name FROM clinic_visits v JOIN patients p ON p.id = v.patient_id ORDER BY v.visit_datetime DESC LIMIT 100')->fetchAll();

render_header('Clinic Visits');
?>
<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0">Clinic Visits</h1>
    <a class="btn btn-primary" href="create.php">Record Visit</a>
</div>
<section class="content-panel">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
            <tr>
                <th>Date/Time</th>
                <th>Patient</th>
                <th>Complaint</th>
                <th>Risk</th>
                <th>Action Taken</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($visits as $visit): ?>
                <tr>
                    <td><?= e($visit['visit_datetime']) ?></td>
                    <td><?= e($visit['first_name'] . ' ' . $visit['last_name']) ?></td>
                    <td><?= e($visit['chief_complaint']) ?></td>
                    <td><?= e($visit['risk_level']) ?> (<?= (int) $visit['risk_score'] ?>)</td>
                    <td><?= e($visit['action_taken']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$visits): ?>
                <tr><td colspan="5" class="text-secondary">No clinic visits yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
