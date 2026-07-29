<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/PatientAccountService.php';

require_login();
$user = current_user();
if (!can_manage_patient_accounts($user)) {
    http_response_code(403);
    flash_message('error', 'Only doctors and administrators can manage patient accounts.');
    header('Location: ' . app_url('dashboard.php'));
    exit;
}

$createdAccount = null;
$bulkResults = [];
$pageError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_individual') {
            $createdAccount = create_inactive_patient_account($_POST);
        } elseif ($action === 'bulk_import') {
            $payload = json_decode((string) ($_POST['bulk_payload'] ?? ''), true);
            if (!is_array($payload) || !array_is_list($payload)) {
                throw new InvalidArgumentException('Select a valid Excel workbook before importing.');
            }
            $bulkResults = create_bulk_inactive_patient_accounts($payload);
        }
    } catch (Throwable $e) {
        $pageError = $e->getMessage();
    }
}

$recentAccounts = recent_patient_accounts();

render_header('Patient Accounts');
render_clinic_command_header(
    'Account Administration',
    'Patient Accounts',
    'Create inactive patient accounts individually or import an official list from Excel.'
);
?>

<?php if ($pageError !== ''): ?>
    <div class="rounded-xl bg-red-50 border border-red-100 text-red-700 px-4 py-3 text-sm font-bold mb-5">
        <?= e($pageError) ?>
    </div>
<?php endif; ?>

<?php if ($createdAccount): ?>
    <section class="clinic-card p-5 mb-5 border-emerald-200 bg-emerald-50">
        <p class="text-xs font-black uppercase tracking-widest text-emerald-700 mb-2">Inactive account created</p>
        <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-2"><?= e($createdAccount['name']) ?></h2>
        <div class="flex flex-wrap gap-3 text-sm font-bold">
            <span>ID: <code><?= e($createdAccount['id_number']) ?></code></span>
            <span>Temporary password: <code><?= e($createdAccount['temporary_password']) ?></code></span>
            <span class="badge badge-pending">Inactive</span>
        </div>
        <p class="text-xs font-bold text-emerald-800 mt-3 mb-0">Give the temporary password privately to the account owner. It is not stored as readable text.</p>
    </section>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-5 mb-5">
    <section class="clinic-card p-5">
        <div class="flex items-start gap-3 mb-5">
            <span class="w-11 h-11 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
                <span class="material-symbols-outlined">person_add</span>
            </span>
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Individual Account</h2>
                <p class="text-xs font-bold text-slate-500 mb-0">Enter information from the clinic’s official list.</p>
            </div>
        </div>

        <form method="post" class="space-y-4" autocomplete="off">
            <input type="hidden" name="action" value="create_individual">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="clinic-label" for="id_number">ID Number</label>
                    <input class="clinic-input" id="id_number" name="id_number" placeholder="23-00262" required>
                </div>
                <div>
                    <label class="clinic-label" for="patient_type">Patient Type</label>
                    <select class="clinic-input" id="patient_type" name="patient_type" required>
                        <option value="student">Student</option>
                        <option value="faculty">Faculty</option>
                        <option value="patient">Other Patient</option>
                    </select>
                </div>
                <div>
                    <label class="clinic-label" for="first_name">First Name</label>
                    <input class="clinic-input" id="first_name" name="first_name" required>
                </div>
                <div>
                    <label class="clinic-label" for="middle_name">Middle Name</label>
                    <input class="clinic-input" id="middle_name" name="middle_name">
                </div>
                <div>
                    <label class="clinic-label" for="last_name">Last Name</label>
                    <input class="clinic-input" id="last_name" name="last_name" required>
                </div>
                <div>
                    <label class="clinic-label" for="birthdate">Birthdate</label>
                    <input class="clinic-input" id="birthdate" name="birthdate" type="date" required>
                </div>
                <div>
                    <label class="clinic-label" for="program_or_department">Program or Department</label>
                    <input class="clinic-input" id="program_or_department" name="program_or_department">
                </div>
                <div>
                    <label class="clinic-label" for="year_level_or_employment_type">Year Level or Employment Type</label>
                    <input class="clinic-input" id="year_level_or_employment_type" name="year_level_or_employment_type">
                </div>
                <div class="sm:col-span-2">
                    <label class="clinic-label" for="section_or_position">Section or Position</label>
                    <input class="clinic-input" id="section_or_position" name="section_or_position">
                </div>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-100 px-4 py-3 text-xs font-bold text-slate-600">
                Temporary password = last three ID digits repeated twice. Example: <code>23-00262 → 262262</code>.
            </div>
            <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary"
                data-confirm-title="Create inactive patient account?"
                data-confirm-message="The patient must register and replace the temporary password before signing in."
                data-confirm-toast="Creating patient account...">
                <span class="material-symbols-outlined">person_add</span>
                Create Inactive Account
            </button>
        </form>
    </section>

    <section class="clinic-card p-5">
        <div class="flex items-start justify-between gap-3 mb-5">
            <div class="flex items-start gap-3">
                <span class="w-11 h-11 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined">table_view</span>
                </span>
                <div>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Bulk Excel Import</h2>
                    <p class="text-xs font-bold text-slate-500 mb-0">Preview the first worksheet before importing.</p>
                </div>
            </div>
            <a class="btn btn-ghost text-decoration-none" href="<?= app_url('assets/templates/cliniq-patient-account-import.xlsx') ?>" download>
                <span class="material-symbols-outlined">download</span>
                Template
            </a>
        </div>

        <form method="post" id="bulkImportForm">
            <input type="hidden" name="action" value="bulk_import">
            <input type="hidden" name="bulk_payload" id="bulkPayload">
            <label class="clinic-label" for="excelFile">Excel Workbook (.xlsx)</label>
            <input class="clinic-input mb-4" id="excelFile" type="file" accept=".xlsx" required>
            <div id="excelMessage" class="rounded-xl bg-slate-50 border border-slate-100 px-4 py-3 text-xs font-bold text-slate-600 mb-4">
                Download the template, complete the first worksheet, then select it here.
            </div>
            <div id="excelPreview" class="overflow-x-auto mb-4"></div>
            <button class="btn btn-primary w-full" id="bulkImportButton" disabled
                data-confirm-submit data-confirm-type="primary"
                data-confirm-title="Import inactive patient accounts?"
                data-confirm-message="Valid Excel rows will be inserted into the new account database."
                data-confirm-toast="Importing patient accounts...">
                <span class="material-symbols-outlined">upload_file</span>
                Import Valid Rows
            </button>
        </form>
    </section>
</div>

<?php if ($bulkResults): ?>
    <?php
    $createdCount = count(array_filter($bulkResults, static fn(array $row): bool => $row['status'] === 'created'));
    $failedCount = count($bulkResults) - $createdCount;
    ?>
    <section class="clinic-card overflow-hidden mb-5">
        <div class="p-5 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Import Results</h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= $createdCount ?> created · <?= $failedCount ?> skipped or invalid</p>
            </div>
            <button class="btn btn-ghost" type="button" id="downloadImportResults">
                <span class="material-symbols-outlined">download</span>
                Download Results
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="p-3">Row</th>
                        <th class="p-3">ID Number</th>
                        <th class="p-3">Name</th>
                        <th class="p-3">Type</th>
                        <th class="p-3">Temporary Password</th>
                        <th class="p-3">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bulkResults as $result): ?>
                        <tr class="border-t border-slate-100">
                            <td class="p-3"><?= e($result['row']) ?></td>
                            <td class="p-3 font-bold"><?= e($result['id_number']) ?></td>
                            <td class="p-3"><?= e($result['name']) ?></td>
                            <td class="p-3"><?= e(ucfirst($result['type'])) ?></td>
                            <td class="p-3"><code><?= e($result['temporary_password'] ?: '—') ?></code></td>
                            <td class="p-3">
                                <span class="badge <?= $result['status'] === 'created' ? 'badge-completed' : 'badge-high' ?>">
                                    <?= e($result['status'] === 'created' ? 'Created' : $result['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="clinic-card overflow-hidden">
    <div class="p-5 border-b border-slate-100">
        <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Recent Patient Accounts</h2>
        <p class="text-xs font-bold text-slate-500 mb-0"><?= count($recentAccounts) ?> account(s) shown</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="p-3">ID Number</th>
                    <th class="p-3">Patient</th>
                    <th class="p-3">Type</th>
                    <th class="p-3">Birthdate</th>
                    <th class="p-3">Account Status</th>
                    <th class="p-3">Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentAccounts as $account): ?>
                    <tr class="border-t border-slate-100">
                        <td class="p-3 font-bold"><?= e($account['id_number']) ?></td>
                        <td class="p-3"><?= e($account['full_name']) ?></td>
                        <td class="p-3"><?= e($account['patient_type']) ?></td>
                        <td class="p-3"><?= e($account['birthdate'] ?: '—') ?></td>
                        <td class="p-3"><span class="badge <?= e(status_badge_class($account['account_status'])) ?>"><?= e(ucfirst($account['account_status'])) ?></span></td>
                        <td class="p-3"><?= e(date('M d, Y', strtotime($account['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script src="<?= app_url('assets/vendor/sheetjs/xlsx.full.min.js?v=0.20.3') ?>"></script>
<script>
const excelFile = document.getElementById('excelFile');
const excelMessage = document.getElementById('excelMessage');
const excelPreview = document.getElementById('excelPreview');
const bulkPayload = document.getElementById('bulkPayload');
const bulkImportButton = document.getElementById('bulkImportButton');
const requiredColumns = ['id_number', 'patient_type', 'first_name', 'last_name', 'birthdate'];

function normalizeHeader(value) {
    return String(value || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
}

excelFile?.addEventListener('change', async () => {
    bulkImportButton.disabled = true;
    bulkPayload.value = '';
    excelPreview.innerHTML = '';

    const file = excelFile.files?.[0];
    if (!file) return;
    if (!window.XLSX) {
        excelMessage.textContent = 'Excel reader is unavailable. Reload the page and try again.';
        return;
    }

    try {
        const workbook = XLSX.read(await file.arrayBuffer(), { type: 'array', cellDates: true });
        const worksheet = workbook.Sheets[workbook.SheetNames[0]];
        const sourceRows = XLSX.utils.sheet_to_json(worksheet, { defval: '', raw: false, dateNF: 'yyyy-mm-dd' });
        const rows = sourceRows
            .map(row => Object.fromEntries(Object.entries(row).map(([key, value]) => [normalizeHeader(key), String(value).trim()])))
            .filter(row => Object.values(row).some(value => value !== ''));

        if (!rows.length) throw new Error('The first worksheet contains no account rows.');
        if (rows.length > 500) throw new Error('The workbook exceeds the 500-row import limit.');

        const missing = requiredColumns.filter(column => !(column in rows[0]));
        if (missing.length) throw new Error('Missing required column(s): ' + missing.join(', '));

        bulkPayload.value = JSON.stringify(rows);
        bulkImportButton.disabled = false;
        excelMessage.textContent = `${rows.length} row(s) ready. Review the preview before importing.`;

        const previewRows = rows.slice(0, 20);
        excelPreview.innerHTML = `<table class="w-full text-xs">
            <thead class="bg-slate-50"><tr><th class="p-2 text-left">ID</th><th class="p-2 text-left">Type</th><th class="p-2 text-left">Name</th><th class="p-2 text-left">Birthdate</th></tr></thead>
            <tbody>${previewRows.map(row => `<tr class="border-t border-slate-100"><td class="p-2">${escapeHtml(row.id_number)}</td><td class="p-2">${escapeHtml(row.patient_type)}</td><td class="p-2">${escapeHtml([row.first_name,row.middle_name,row.last_name].filter(Boolean).join(' '))}</td><td class="p-2">${escapeHtml(row.birthdate)}</td></tr>`).join('')}</tbody>
        </table>${rows.length > 20 ? `<p class="text-xs font-bold text-slate-500 mt-2">Showing 20 of ${rows.length} rows.</p>` : ''}`;
    } catch (error) {
        excelMessage.textContent = error instanceof Error ? error.message : 'Unable to read the Excel workbook.';
    }
});

<?php if ($bulkResults): ?>
const importResults = <?= json_encode($bulkResults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
document.getElementById('downloadImportResults')?.addEventListener('click', () => {
    const sheet = XLSX.utils.json_to_sheet(importResults.map(row => ({
        'Row': row.row,
        'ID Number': row.id_number,
        'Name': row.name,
        'Patient Type': row.type,
        'Temporary Password': row.temporary_password,
        'Result': row.status === 'created' ? 'Created' : row.status,
    })));
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, sheet, 'Import Results');
    XLSX.writeFile(workbook, 'cliniq-patient-account-import-results.xlsx');
});
<?php endif; ?>
</script>

<?php render_footer(); ?>
