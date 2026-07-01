<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$alerts = db()->query('SELECT a.*, p.first_name, p.last_name FROM nurse_alerts a LEFT JOIN patients p ON p.id = a.patient_id ORDER BY a.created_at DESC LIMIT 100')->fetchAll();

render_header('Nurse Alerts');
?>
<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0">Nurse Alerts</h1>
    <a class="btn btn-danger" href="create.php">Submit Alert</a>
</div>
<section class="content-panel">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
            <tr>
                <th>Status</th>
                <th>Patient</th>
                <th>Reporter</th>
                <th>Location</th>
                <th>Concern</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($alerts as $alert): ?>
                <tr>
                    <td><span class="badge text-bg-warning"><?= e($alert['status']) ?></span></td>
                    <td><?= e(trim(($alert['first_name'] ?? '') . ' ' . ($alert['last_name'] ?? ''))) ?: 'Unlisted' ?></td>
                    <td><?= e($alert['reporter_name']) ?></td>
                    <td><?= e($alert['location']) ?></td>
                    <td><?= e($alert['concern']) ?></td>
                    <td><?= e($alert['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$alerts): ?>
                <tr><td colspan="6" class="text-secondary">No alerts yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
