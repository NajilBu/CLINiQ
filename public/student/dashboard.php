<?php

require_once __DIR__ . '/../app/helpers/view.php';
require_login();

$counts = [
    'patients' => db()->query('SELECT COUNT(*) AS total FROM patients')->fetch()['total'] ?? 0,
    'pending_alerts' => db()->query("SELECT COUNT(*) AS total FROM nurse_alerts WHERE status = 'Pending'")->fetch()['total'] ?? 0,
    'visits_today' => db()->query('SELECT COUNT(*) AS total FROM clinic_visits WHERE DATE(visit_datetime) = CURDATE()')->fetch()['total'] ?? 0,
    'low_stock' => db()->query('SELECT COUNT(*) AS total FROM inventory_items WHERE quantity <= reorder_level')->fetch()['total'] ?? 0,
    'active_visits' => db()->query("SELECT COUNT(*) AS total FROM clinic_visits WHERE DATE(visit_datetime) = CURDATE() AND risk_level IN ('Moderate','High','Critical')")->fetch()['total'] ?? 0,
];

$recentVisits = db()->query("
    SELECT v.*, p.first_name, p.last_name, p.student_number
    FROM clinic_visits v
    JOIN patients p ON p.id = v.patient_id
    ORDER BY v.visit_datetime DESC
    LIMIT 6
")->fetchAll();

$latestAlerts = db()->query("
    SELECT a.*, p.first_name, p.last_name
    FROM nurse_alerts a
    LEFT JOIN patients p ON p.id = a.patient_id
    WHERE a.status = 'Pending'
    ORDER BY a.created_at DESC
    LIMIT 5
")->fetchAll();

$stockWarnings = db()->query("
    SELECT *
    FROM inventory_items
    WHERE quantity <= reorder_level
    ORDER BY quantity ASC, item_name ASC
    LIMIT 3
")->fetchAll();

render_header('Dashboard');
?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Good day, Doctor!</h1>
        <p class="text-sm font-bold text-emerald-600 mt-1 flex items-center gap-1.5">
            <span class="material-symbols-outlined text-[16px]">check_circle</span>
            Clinic dashboard is connected to live PHP and MySQL records.
        </p>
    </div>
    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
        <div class="bg-amber-50 border border-amber-200 px-4 py-2 rounded-2xl flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center">
                <span class="material-symbols-outlined text-[18px]">inventory_2</span>
            </div>
            <div>
                <p class="text-[9px] font-black text-amber-800 uppercase">Stock Alert</p>
                <p class="text-[11px] font-bold text-amber-900"><?= (int) $counts['low_stock'] ?> low-stock item(s)</p>
            </div>
        </div>
        <a class="px-5 py-3 bg-red-600 text-white rounded-2xl flex items-center gap-2 text-sm font-bold shadow-lg hover:bg-red-700 text-decoration-none" href="<?= app_url('alerts/create.php') ?>">
            <span class="material-symbols-outlined text-[20px]">emergency_home</span>
            Submit Alert
        </a>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-4 gap-6">
    <a href="<?= app_url('visits/index.php') ?>" class="bg-white rounded-[2rem] p-8 border border-outline-variant/20 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all text-decoration-none">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase">Total Visits Today</p>
                <p class="font-headline text-4xl text-slate-800 font-extrabold leading-none mt-2"><?= (int) $counts['visits_today'] ?></p>
            </div>
            <div class="w-14 h-14 rounded-[1rem] bg-indigo-50 text-indigo-600 flex items-center justify-center">
                <span class="material-symbols-outlined text-3xl">today</span>
            </div>
        </div>
    </a>

    <a href="<?= app_url('patients/index.php') ?>" class="bg-white rounded-[2rem] p-8 border border-outline-variant/20 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all text-decoration-none">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase">Registered Patients</p>
                <p class="font-headline text-4xl text-slate-800 font-extrabold leading-none mt-2"><?= (int) $counts['patients'] ?></p>
            </div>
            <div class="w-14 h-14 rounded-[1rem] bg-blue-50 text-primary flex items-center justify-center">
                <span class="material-symbols-outlined text-3xl">personal_injury</span>
            </div>
        </div>
    </a>

    <a href="<?= app_url('alerts/index.php') ?>" class="bg-white rounded-[2rem] p-8 border border-outline-variant/20 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all text-decoration-none">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase">Pending Alerts</p>
                <p id="pending-alert-count" class="font-headline text-4xl text-slate-800 font-extrabold leading-none mt-2"><?= (int) $counts['pending_alerts'] ?></p>
            </div>
            <div class="w-14 h-14 rounded-[1rem] bg-red-50 text-red-600 flex items-center justify-center">
                <span class="material-symbols-outlined text-3xl">notification_important</span>
            </div>
        </div>
    </a>

    <a href="<?= app_url('inventory/index.php') ?>" class="bg-white rounded-[2rem] p-8 border border-outline-variant/20 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all text-decoration-none">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase">Stock Alerts</p>
                <p class="font-headline text-4xl text-slate-800 font-extrabold leading-none mt-2"><?= (int) $counts['low_stock'] ?></p>
            </div>
            <div class="w-14 h-14 rounded-[1rem] bg-amber-50 text-amber-600 flex items-center justify-center">
                <span class="material-symbols-outlined text-3xl">inventory_2</span>
            </div>
        </div>
    </a>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[1.35fr_0.65fr] gap-6">
    <section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#1c2a59]">Recent Clinic Activity</h2>
                <p class="text-xs font-bold text-slate-500">Latest visit records from the clinic logbook</p>
            </div>
            <a href="<?= app_url('visits/create.php') ?>" class="px-4 py-2 bg-primary text-white rounded-2xl text-sm font-bold text-decoration-none">Record Visit</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                <tr class="bg-slate-50 text-[10px] uppercase font-black text-slate-400">
                    <th class="p-4">Patient</th>
                    <th class="p-4">Complaint</th>
                    <th class="p-4">Risk</th>
                    <th class="p-4">Date</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentVisits as $visit): ?>
                    <tr class="border-t border-slate-100">
                        <td class="p-4">
                            <strong class="text-sm text-slate-800"><?= e($visit['first_name'] . ' ' . $visit['last_name']) ?></strong>
                            <div class="text-xs font-bold text-slate-400"><?= e($visit['student_number']) ?></div>
                        </td>
                        <td class="p-4 text-sm font-bold text-slate-600"><?= e($visit['chief_complaint']) ?></td>
                        <td class="p-4"><span class="px-3 py-1 rounded-full text-xs font-black <?= in_array($visit['risk_level'], ['High', 'Critical'], true) ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700' ?>"><?= e($visit['risk_level']) ?></span></td>
                        <td class="p-4 text-xs font-bold text-slate-500"><?= e($visit['visit_datetime']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentVisits): ?>
                    <tr><td class="p-6 text-sm font-bold text-slate-500" colspan="4">No clinic visits yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="space-y-6">
        <section class="bg-white rounded-[2rem] p-6 border border-outline-variant/20 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-headline text-lg font-extrabold text-[#1c2a59]">Live Alerts</h2>
                <a href="<?= app_url('alerts/index.php') ?>" class="text-xs font-black text-primary text-decoration-none">View all</a>
            </div>
            <div id="latest-alerts" data-alert-feed="<?= app_url('api/alerts.php') ?>">
                <?php if (!$latestAlerts): ?>
                    <p class="text-sm font-bold text-slate-500 mb-0">No pending alerts.</p>
                <?php endif; ?>
                <?php foreach ($latestAlerts as $alert): ?>
                    <div class="p-4 rounded-2xl bg-red-50 border border-red-100 mb-3">
                        <div class="flex items-start justify-between gap-3">
                            <strong class="text-sm text-red-900"><?= e($alert['concern']) ?></strong>
                            <span class="px-2 py-1 rounded-full bg-red-100 text-red-700 text-[10px] font-black"><?= e($alert['status']) ?></span>
                        </div>
                        <p class="text-xs font-bold text-red-700 mb-0 mt-1"><?= e($alert['location']) ?> · <?= e($alert['created_at']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="bg-white rounded-[2rem] p-6 border border-outline-variant/20 shadow-sm">
            <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] mb-4">Stock Watch</h2>
            <?php foreach ($stockWarnings as $item): ?>
                <div class="flex items-center gap-3 p-3 rounded-2xl bg-amber-50 border border-amber-100 mb-3">
                    <span class="w-9 h-9 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center material-symbols-outlined">pill</span>
                    <div>
                        <strong class="block text-sm text-amber-900"><?= e($item['item_name']) ?></strong>
                        <span class="text-xs font-bold text-amber-700"><?= (int) $item['quantity'] ?> <?= e($item['unit']) ?> left</span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$stockWarnings): ?>
                <p class="text-sm font-bold text-slate-500 mb-0">No low-stock items.</p>
            <?php endif; ?>
        </section>
    </aside>
</div>

<section class="bg-white rounded-[2rem] p-6 border border-outline-variant/20 shadow-sm">
    <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-4">Quick Actions</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <a class="px-4 py-4 rounded-2xl bg-primary text-white text-sm font-bold text-center text-decoration-none" href="<?= app_url('patients/create.php') ?>">Add Patient</a>
        <a class="px-4 py-4 rounded-2xl bg-primary text-white text-sm font-bold text-center text-decoration-none" href="<?= app_url('visits/create.php') ?>">Record Visit</a>
        <a class="px-4 py-4 rounded-2xl bg-red-600 text-white text-sm font-bold text-center text-decoration-none" href="<?= app_url('alerts/create.php') ?>">Submit Alert</a>
        <a class="px-4 py-4 rounded-2xl bg-slate-100 text-primary text-sm font-bold text-center text-decoration-none" href="<?= app_url('inventory/index.php') ?>">Inventory</a>
        <a class="px-4 py-4 rounded-2xl bg-slate-100 text-primary text-sm font-bold text-center text-decoration-none" href="<?= app_url('reports/index.php') ?>">Reports</a>
    </div>
</section>
<?php render_footer(); ?>
