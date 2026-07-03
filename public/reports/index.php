<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$stats = [
    'visits_today' => db()->query('SELECT COUNT(*) AS total FROM clinic_visits WHERE DATE(visit_datetime) = CURDATE()')->fetch()['total'] ?? 0,
    'visits_month' => db()->query('SELECT COUNT(*) AS total FROM clinic_visits WHERE YEAR(visit_datetime) = YEAR(CURDATE()) AND MONTH(visit_datetime) = MONTH(CURDATE())')->fetch()['total'] ?? 0,
    'alerts_pending' => db()->query("SELECT COUNT(*) AS total FROM nurse_alerts WHERE status = 'Pending'")->fetch()['total'] ?? 0,
    'low_stock' => db()->query('SELECT COUNT(*) AS total FROM inventory_items WHERE quantity <= reorder_level')->fetch()['total'] ?? 0,
];

$complaints = db()->query("
    SELECT chief_complaint, COUNT(*) AS total
    FROM clinic_visits
    GROUP BY chief_complaint
    ORDER BY total DESC, chief_complaint ASC
    LIMIT 8
")->fetchAll();

render_header('Reports');
?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Reports & Analytics</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Clinic summaries, visit trends, alerts, and inventory warnings.</p>
    </div>
    <button class="px-5 py-3 bg-primary text-white rounded-2xl flex items-center gap-2 text-sm font-bold shadow-lg shadow-primary/20" type="button">
        <span class="material-symbols-outlined text-[20px]">download</span>
        Export
    </button>
</div>

<div class="grid grid-cols-1 md:grid-cols-4 gap-6">
    <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm"><p class="text-[10px] font-black text-slate-400 uppercase">Visits Today</p><p class="text-3xl font-headline font-extrabold text-slate-800"><?= (int) $stats['visits_today'] ?></p></div>
    <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm"><p class="text-[10px] font-black text-slate-400 uppercase">Visits This Month</p><p class="text-3xl font-headline font-extrabold text-slate-800"><?= (int) $stats['visits_month'] ?></p></div>
    <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm"><p class="text-[10px] font-black text-slate-400 uppercase">Pending Alerts</p><p class="text-3xl font-headline font-extrabold text-slate-800"><?= (int) $stats['alerts_pending'] ?></p></div>
    <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm"><p class="text-[10px] font-black text-slate-400 uppercase">Low Stock</p><p class="text-3xl font-headline font-extrabold text-slate-800"><?= (int) $stats['low_stock'] ?></p></div>
</div>

<section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
    <div class="p-6 border-b border-slate-100">
        <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Common Complaints</h2>
        <p class="text-xs font-bold text-slate-500 mb-0">Most frequent recorded visit reasons.</p>
    </div>
    <div class="p-6 grid gap-3">
        <?php foreach ($complaints as $complaint): ?>
            <div class="flex items-center justify-between p-4 rounded-2xl bg-slate-50 border border-slate-100">
                <span class="text-sm font-bold text-slate-700"><?= e($complaint['chief_complaint']) ?></span>
                <strong class="text-primary"><?= (int) $complaint['total'] ?></strong>
            </div>
        <?php endforeach; ?>
        <?php if (!$complaints): ?>
            <p class="text-sm font-bold text-slate-500 mb-0">No report data yet.</p>
        <?php endif; ?>
    </div>
</section>
<?php render_footer(); ?>
