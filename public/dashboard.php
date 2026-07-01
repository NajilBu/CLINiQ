<?php

require_once __DIR__ . '/../app/helpers/view.php';
require_login();

$counts = [
    'patients' => db()->query('SELECT COUNT(*) AS total FROM patients')->fetch()['total'] ?? 0,
    'pending_alerts' => db()->query("SELECT COUNT(*) AS total FROM nurse_alerts WHERE status = 'Pending'")->fetch()['total'] ?? 0,
    'visits_today' => db()->query('SELECT COUNT(*) AS total FROM clinic_visits WHERE DATE(visit_datetime) = CURDATE()')->fetch()['total'] ?? 0,
    'low_stock' => db()->query('SELECT COUNT(*) AS total FROM inventory_items WHERE quantity <= reorder_level')->fetch()['total'] ?? 0,
];

render_header('Dashboard');
?>
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-1">Clinic Dashboard</h1>
        <p class="text-secondary mb-0">Monitor clinic activity, emergency alerts, and student health records.</p>
    </div>
    <a class="btn btn-danger" href="<?= app_url('alerts/create.php') ?>">Submit Alert</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="metric">
            <span>Patients</span>
            <strong><?= (int) $counts['patients'] ?></strong>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric">
            <span>Pending Alerts</span>
            <strong id="pending-alert-count"><?= (int) $counts['pending_alerts'] ?></strong>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric">
            <span>Visits Today</span>
            <strong><?= (int) $counts['visits_today'] ?></strong>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric">
            <span>Low Stock</span>
            <strong><?= (int) $counts['low_stock'] ?></strong>
        </div>
    </div>
</div>

<section class="content-panel">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="h5 mb-0">Latest Nurse Alerts</h2>
        <a href="<?= app_url('alerts/index.php') ?>">View all</a>
    </div>
    <div id="latest-alerts" data-alert-feed="<?= app_url('api/alerts.php') ?>">
        <p class="text-secondary mb-0">Loading alerts...</p>
    </div>
</section>
<?php render_footer(); ?>
