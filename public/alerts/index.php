<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$alerts = db()->query('SELECT a.*, p.first_name, p.last_name FROM nurse_alerts a LEFT JOIN patients p ON p.id = a.patient_id ORDER BY a.created_at DESC LIMIT 100')->fetchAll();

render_header('Nurse Alerts');
?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Nurse Alerts</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Live emergency reports from staff and QR/NFC scans.</p>
    </div>
    <a class="px-5 py-3 bg-red-600 text-white rounded-2xl flex items-center gap-2 text-sm font-bold shadow-lg hover:bg-red-700 text-decoration-none" href="create.php">
        <span class="material-symbols-outlined text-[20px]">emergency_home</span>
        Submit Alert
    </a>
</div>
<section class="clinic-card overflow-hidden">
    <div class="p-6 border-b border-slate-100">
        <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Alert Queue</h2>
        <p class="text-xs font-bold text-slate-500 mb-0">Newest reports appear first.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
            <tr class="bg-slate-50 text-[10px] uppercase font-black text-slate-400">
                <th class="p-4">Status</th>
                <th class="p-4">Patient</th>
                <th class="p-4">Reporter</th>
                <th class="p-4">Location</th>
                <th class="p-4">Concern</th>
                <th class="p-4">Created</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($alerts as $alert): ?>
                <tr class="border-t border-slate-100">
                    <td class="p-4"><span class="px-3 py-1 rounded-full bg-amber-50 text-amber-700 text-xs font-black"><?= e($alert['status']) ?></span></td>
                    <td class="p-4 text-sm font-bold text-slate-700"><?= e(trim(($alert['first_name'] ?? '') . ' ' . ($alert['last_name'] ?? ''))) ?: 'Unlisted' ?></td>
                    <td class="p-4 text-sm font-bold text-slate-600"><?= e($alert['reporter_name']) ?></td>
                    <td class="p-4 text-sm font-bold text-slate-600"><?= e($alert['location']) ?></td>
                    <td class="p-4 text-sm font-bold text-slate-800"><?= e($alert['concern']) ?></td>
                    <td class="p-4 text-xs font-bold text-slate-500"><?= e($alert['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$alerts): ?>
                <tr><td colspan="6" class="p-6 text-sm font-bold text-slate-500">No alerts yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
