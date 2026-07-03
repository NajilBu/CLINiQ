<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$visits = db()->query('SELECT v.*, p.first_name, p.last_name FROM clinic_visits v JOIN patients p ON p.id = v.patient_id ORDER BY v.visit_datetime DESC LIMIT 100')->fetchAll();

render_header('Clinic Visits');
?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Visit History</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Track arrivals, triage notes, risk classification, and actions taken.</p>
    </div>
    <a class="px-5 py-3 bg-primary text-white rounded-2xl flex items-center gap-2 text-sm font-bold shadow-lg shadow-primary/20 hover:bg-primary-container text-decoration-none" href="create.php">
        <span class="material-symbols-outlined text-[20px]">add_notes</span>
        Record Visit
    </a>
</div>
<section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
    <div class="p-6 border-b border-slate-100">
        <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Clinic Logbook</h2>
        <p class="text-xs font-bold text-slate-500 mb-0">Latest 100 clinic visits.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
            <tr class="bg-slate-50 text-[10px] uppercase font-black text-slate-400">
                <th class="p-4">Date/Time</th>
                <th class="p-4">Patient</th>
                <th class="p-4">Complaint</th>
                <th class="p-4">Risk</th>
                <th class="p-4">Action Taken</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($visits as $visit): ?>
                <tr class="border-t border-slate-100">
                    <td class="p-4 text-xs font-bold text-slate-500"><?= e($visit['visit_datetime']) ?></td>
                    <td class="p-4"><strong class="text-sm text-slate-800"><?= e($visit['first_name'] . ' ' . $visit['last_name']) ?></strong></td>
                    <td class="p-4 text-sm font-bold text-slate-600"><?= e($visit['chief_complaint']) ?></td>
                    <td class="p-4">
                        <span class="px-3 py-1 rounded-full text-xs font-black <?= in_array($visit['risk_level'], ['High', 'Critical'], true) ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700' ?>">
                            <?= e($visit['risk_level']) ?> · <?= (int) $visit['risk_score'] ?>
                        </span>
                    </td>
                    <td class="p-4 text-sm font-bold text-slate-600"><?= e($visit['action_taken']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$visits): ?>
                <tr><td colspan="5" class="p-6 text-sm font-bold text-slate-500">No clinic visits yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
