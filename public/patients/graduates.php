<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/GraduationService.php';
require_login();

$batchInput = trim((string) ($_GET['batch_year'] ?? ''));
$batchYear = preg_match('/^\d{4}$/', $batchInput) ? (int) $batchInput : null;
$search = trim((string) ($_GET['q'] ?? ''));
$batchOptions = graduation_batch_years();
$graduates = graduation_list($batchYear);
if ($search !== '') {
    $graduates = array_values(array_filter($graduates, static function (array $row) use ($search): bool {
        return stripos((string) $row['student_name'], $search) !== false
            || stripos((string) $row['id_number'], $search) !== false;
    }));
}

set_page_back_link('index.php', 'Patients');
render_header('Graduates');
render_clinic_command_header('Patient Registry', 'Graduates', 'Fourth-year students cleared for graduation and students marked graduated during the school-year cycle.');
?>
<section class="clinic-card p-6">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="font-headline text-xl font-extrabold mb-1">Graduation list</h2>
            <p class="text-sm text-slate-500 mb-0"><?= count($graduates) ?> student(s) shown. Clearance and graduation are separate decisions.</p>
        </div>
        <form method="get" class="flex flex-wrap items-end gap-3">
            <label class="clinic-label">Batch year
                <select class="clinic-select mt-1" name="batch_year">
                    <option value="">All batches</option>
                    <?php foreach ($batchOptions as $year): ?>
                        <option value="<?= (int) $year ?>" <?= $batchYear === $year ? 'selected' : '' ?>>Batch <?= (int) $year ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="clinic-label">Student
                <input class="clinic-input mt-1" name="q" value="<?= e($search) ?>" placeholder="Name or ID number">
            </label>
            <button class="btn btn-primary">Filter</button>
        </form>
    </div>
    <?php if (!$graduates): ?>
        <div class="empty-state"><span class="material-symbols-outlined">school</span><p class="empty-state-title">No students found</p><p class="empty-state-text">Try another batch or search term.</p></div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="clinic-table w-full">
                <thead><tr><th>ID Number</th><th>Student</th><th>Program</th><th>Batch</th><th>Status</th><th>Cleared on</th></tr></thead>
                <tbody>
                <?php foreach ($graduates as $row): ?>
                    <tr>
                        <td><?= e((string) $row['id_number']) ?></td>
                        <td><a href="view.php?id=<?= (int) $row['student_person_id'] ?>" class="font-bold text-primary"><?= e((string) $row['student_name']) ?></a></td>
                        <td><?= e((string) ($row['program_code'] ?: '—')) ?></td>
                        <td><?= (int) $row['batch_year'] ?></td>
                        <td><span class="badge <?= $row['status'] === 'Graduated' ? 'badge-completed' : 'badge-in-progress' ?>"><?= e((string) $row['status']) ?></span></td>
                        <td><?= $row['cleared_at'] ? e(date('M j, Y', strtotime((string) $row['cleared_at']))) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
