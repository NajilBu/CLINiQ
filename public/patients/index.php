<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$patients = db()->query('SELECT * FROM patients ORDER BY last_name, first_name LIMIT 100')->fetchAll();

render_header('Patients');
?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Patient Records</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Manage student profiles, emergency tags, and staff-only health details.</p>
    </div>
    <a class="px-5 py-3 bg-primary text-white rounded-2xl flex items-center gap-2 text-sm font-bold shadow-lg shadow-primary/20 hover:bg-primary-container text-decoration-none" href="create.php">
        <span class="material-symbols-outlined text-[20px]">person_add</span>
        Add Patient
    </a>
</div>

<section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
    <div class="p-6 border-b border-slate-100">
        <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Patient Registry</h2>
        <p class="text-xs font-bold text-slate-500 mb-0">Public QR page is for reporting only; staff profile contains private health data.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
            <tr class="bg-slate-50 text-[10px] uppercase font-black text-slate-400">
                <th class="p-4">Student No.</th>
                <th class="p-4">Name</th>
                <th class="p-4">Course/Section</th>
                <th class="p-4">Guardian Contact</th>
                <th class="p-4">Emergency Access</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($patients as $patient): ?>
                <tr class="border-t border-slate-100">
                    <td class="p-4 text-sm font-bold text-slate-600"><?= e($patient['student_number']) ?></td>
                    <td class="p-4">
                        <strong class="text-sm text-slate-800"><?= e($patient['last_name'] . ', ' . $patient['first_name']) ?></strong>
                    </td>
                    <td class="p-4 text-sm font-bold text-slate-600"><?= e($patient['course_section']) ?></td>
                    <td class="p-4 text-sm font-bold text-slate-600"><?= e($patient['guardian_contact']) ?></td>
                    <td class="p-4">
                        <div class="flex flex-wrap gap-2">
                            <a class="px-3 py-2 rounded-xl bg-slate-100 text-primary text-xs font-black text-decoration-none" href="<?= app_url('emergency.php?token=' . $patient['emergency_token']) ?>" target="_blank">Public QR Page</a>
                            <a class="px-3 py-2 rounded-xl bg-red-50 text-red-700 text-xs font-black text-decoration-none" href="<?= app_url('patients/emergency_profile.php?id=' . (int) $patient['id']) ?>">Staff Profile</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$patients): ?>
                <tr><td colspan="5" class="p-6 text-sm font-bold text-slate-500">No patients yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
