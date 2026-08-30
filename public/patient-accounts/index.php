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

$programOptions = patient_account_active_programs();
$departmentOptions = patient_account_active_departments();
$recentAccounts = recent_patient_accounts();
$recentAccountPageSize = 10;
$recentAccountPageCount = max(1, (int) ceil(count($recentAccounts) / $recentAccountPageSize));

render_header('Patient Accounts');
render_clinic_command_header(
    'Account Administration',
    'Patient Accounts',
    'Create inactive patient accounts individually or import an official list from Excel.'
);

$canManageSettings = in_array($user['role'] ?? '', ['admin', 'doctor', 'it_expert'], true);
$canManageApeCycles = in_array($user['role'] ?? '', ['admin', 'doctor'], true);
?>
<div class="settings-shell">
    <aside class="settings-tabs">
        <a href="<?= app_url('settings/index.php?tab=general') ?>" class="settings-tab-link text-decoration-none" data-no-ajax="true">
            <span class="material-symbols-outlined">corporate_fare</span>
            <span>Clinic Profile</span>
        </a>
        <a href="<?= app_url('settings/index.php?tab=account') ?>" class="settings-tab-link text-decoration-none" data-no-ajax="true">
            <span class="material-symbols-outlined">admin_panel_settings</span>
            <span>Staff Profiles</span>
        </a>
        <a href="<?= app_url('patient-accounts/index.php') ?>" class="settings-tab-link active text-decoration-none" data-no-ajax="true">
            <span class="material-symbols-outlined">manage_accounts</span>
            <span>Patient Accounts</span>
        </a>
        <?php if ($canManageApeCycles): ?>
            <a href="<?= app_url('settings/index.php?tab=ape-cycle') ?>" class="settings-tab-link text-decoration-none" data-no-ajax="true">
                <span class="material-symbols-outlined">event_repeat</span>
                <span>APE Cycle</span>
            </a>
        <?php endif; ?>
        <a href="<?= app_url('settings/index.php?tab=dropdowns') ?>" class="settings-tab-link text-decoration-none" data-no-ajax="true">
            <span class="material-symbols-outlined">list_alt</span>
            <span>Dropdowns</span>
        </a>
        <a href="<?= app_url('settings/index.php?tab=clinical') ?>" class="settings-tab-link text-decoration-none" data-no-ajax="true">
            <span class="material-symbols-outlined">emergency</span>
            <span>Incident Risk</span>
        </a>
        <a href="<?= app_url('settings/index.php?tab=maintenance') ?>" class="settings-tab-link text-decoration-none" data-no-ajax="true">
            <span class="material-symbols-outlined">restart_alt</span>
            <span>Maintenance</span>
        </a>
    </aside>

    <section class="settings-panel">
        <div class="settings-panel-body">

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
            <span>Password: <code><?= e($createdAccount['password']) ?></code></span>
            <span class="badge badge-pending">Inactive</span>
        </div>
        <p class="text-xs font-bold text-emerald-800 mt-3 mb-0">Give the password privately to the account owner. They will use it on the login page and create their own password in the dashboard.</p>
    </section>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
    <button type="button" class="clinic-card p-5 text-left transition hover:-translate-y-0.5 hover:shadow-lg" onclick="showModal('individualAccountModal')">
        <div class="flex items-start gap-3">
            <span class="w-11 h-11 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
                <span class="material-symbols-outlined">person_add</span>
            </span>
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Create Individual Account</h2>
                <p class="text-xs font-bold text-slate-500 mb-0">Enter information from the clinic’s official list.</p>
            </div>
        </div>
    </button>

    <button type="button" class="clinic-card p-5 text-left transition hover:-translate-y-0.5 hover:shadow-lg" onclick="showModal('bulkImportModal')">
        <div class="flex items-start gap-3">
            <span class="w-11 h-11 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
                <span class="material-symbols-outlined">table_view</span>
            </span>
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Bulk Excel Import</h2>
                <p class="text-xs font-bold text-slate-500 mb-0">Preview the first worksheet before importing.</p>
            </div>
        </div>
    </button>
</div>

<div class="modal-backdrop" id="individualAccountModal" aria-hidden="true">
    <div class="modal-content bg-white rounded-[2rem] p-6 w-full max-w-4xl shadow-2xl">
        <div class="flex items-start justify-between gap-3 mb-5">
            <div class="flex items-start gap-3">
                <span class="w-11 h-11 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined">person_add</span>
                </span>
                <div>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Create Individual Account</h2>
                    <p class="text-xs font-bold text-slate-500 mb-0">Enter information from the clinic’s official list.</p>
                </div>
            </div>
            <button type="button" class="btn btn-ghost" onclick="closeModal('individualAccountModal')" aria-label="Close individual account popup">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="post" class="space-y-4" autocomplete="off">
            <input type="hidden" name="action" value="create_individual">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="clinic-label" for="patient_type">Patient Type</label>
                    <select class="clinic-input" id="patient_type" name="patient_type" required>
                        <option value="student">Student</option>
                        <option value="faculty">Faculty</option>
                        <option value="school_personnel">School Personnel</option>
                    </select>
                </div>
                <div>
                    <label class="clinic-label" for="id_number">ID Number</label>
                    <input class="clinic-input uppercase" id="id_number" name="id_number" placeholder="23-00262" aria-describedby="id_number_hint" autocapitalize="characters" required>
                    <p class="text-[11px] font-bold text-slate-500 mt-1" id="id_number_hint">Student example: 23-00262</p>
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
                    <label class="clinic-label" for="sex">Sex</label>
                    <select class="clinic-input" id="sex" name="sex" required>
                        <option value="">Select sex</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="clinic-label" id="program_or_department_label" for="program_or_department">Program Code</label>
                    <select class="clinic-input" id="program_or_department" name="program_or_department" aria-describedby="program_or_department_hint" required>
                        <option value="">Select program</option>
                        <?php foreach ($programOptions as $program): ?>
                            <option value="<?= e($program['code']) ?>">
                                <?= e($program['code'] . ' — ' . $program['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[11px] font-bold text-slate-500 mt-1" id="program_or_department_hint">Select an active program from the database.</p>
                </div>
                <div>
                    <label class="clinic-label" id="year_level_or_employment_type_label" for="year_level_or_employment_type">Year Level</label>
                    <input class="clinic-input hidden" id="year_level_or_employment_type" name="year_level_or_employment_type" placeholder="Full-time" aria-describedby="year_level_or_employment_type_hint" disabled>
                    <select class="clinic-input" id="student_year_level" name="year_level_or_employment_type" aria-describedby="year_level_or_employment_type_hint" required>
                        <option value="">Select year level</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                    </select>
                    <select class="clinic-input hidden" id="faculty_employment_type" name="year_level_or_employment_type" aria-describedby="year_level_or_employment_type_hint" disabled>
                        <option value="">Select employment type</option>
                        <option value="Full-time">Full-time</option>
                        <option value="Part-time">Part-time</option>
                    </select>
                    <p class="text-[11px] font-bold text-slate-500 mt-1" id="year_level_or_employment_type_hint">Choose 1 to 4.</p>
                </div>
                <div>
                    <label class="clinic-label" id="section_or_position_label" for="student_section_code">Section Code</label>
                    <input class="clinic-input hidden" id="section_or_position" name="section_or_position" placeholder="Position" aria-describedby="section_or_position_hint" disabled>
                    <select class="clinic-input" id="student_section_code" name="section_or_position" aria-describedby="section_or_position_hint" required>
                        <option value="">Select section code</option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                        <option value="D">D</option>
                        <option value="E">E</option>
                    </select>
                    <p class="text-[11px] font-bold text-slate-500 mt-1" id="section_or_position_hint">Choose A to E. Program, year level, and section are stored separately.</p>
                </div>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-100 px-4 py-3 text-xs font-bold text-slate-600">
                Password = last three ID digits repeated twice. Example: <code>23-00262 → 262262</code>.
            </div>
            <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary"
                data-confirm-title="Create inactive patient account?"
                data-confirm-message="The patient will sign in with the provided password, then create their own password in the dashboard."
                data-confirm-toast="Creating patient account...">
                <span class="material-symbols-outlined">person_add</span>
                Create Inactive Account
            </button>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="bulkImportModal" aria-hidden="true">
    <div class="modal-content bg-white rounded-[2rem] p-6 w-full max-w-5xl shadow-2xl">
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
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-ghost text-decoration-none" href="<?= app_url('assets/templates/cliniq-patient-account-import.xlsx') ?>" download>
                    <span class="material-symbols-outlined">download</span>
                    Template
                </a>
                <button type="button" class="btn btn-ghost" onclick="closeModal('bulkImportModal')" aria-label="Close bulk import popup">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
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
    </div>
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
                        <th class="p-3">No.</th>
                        <th class="p-3">Source Row</th>
                        <th class="p-3">ID Number</th>
                        <th class="p-3">Name</th>
                        <th class="p-3">Type</th>
                        <th class="p-3">Password</th>
                        <th class="p-3">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bulkResults as $resultIndex => $result): ?>
                        <tr class="border-t border-slate-100">
                            <td class="p-3 font-bold text-slate-500"><?= $resultIndex + 1 ?></td>
                            <td class="p-3"><?= e($result['row']) ?></td>
                            <td class="p-3 font-bold"><?= e($result['id_number']) ?></td>
                            <td class="p-3"><?= e($result['name']) ?></td>
                            <td class="p-3"><?= e(patient_account_type_label($result['type'])) ?></td>
                            <td class="p-3"><code><?= e($result['password'] ?: '—') ?></code></td>
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

<section class="clinic-card overflow-hidden" id="recentPatientAccounts">
    <div class="p-5 border-b border-slate-100">
        <div class="flex flex-col xl:flex-row xl:items-end xl:justify-between gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Recent Patient Accounts</h2>
                <p class="text-xs font-bold text-slate-500 mb-0">
                    <span data-recent-account-count><?= count($recentAccounts) ?></span> account(s) shown
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-[minmax(18rem,1fr)_10rem_10rem] gap-3 w-full xl:max-w-4xl">
                <div>
                    <label class="clinic-label" for="recentAccountSearch">Search</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-[20px]">search</span>
                        <input class="clinic-input pl-10" id="recentAccountSearch" type="search" placeholder="Search ID, name, type..." data-recent-account-search>
                    </div>
                </div>
                <div>
                    <label class="clinic-label" for="recentAccountTypeFilter">Patient Type</label>
                    <select class="clinic-input" id="recentAccountTypeFilter" data-recent-account-type-filter>
                        <option value="">All</option>
                        <option value="student">Student</option>
                        <option value="faculty">Faculty</option>
                        <option value="school personnel">Personnel</option>
                    </select>
                </div>
                <div>
                    <label class="clinic-label" for="recentAccountStatusFilter">Account Status</label>
                    <select class="clinic-input" id="recentAccountStatusFilter" data-recent-account-status-filter>
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="p-3 text-[11px] uppercase tracking-widest">No.</th>
                    <th class="p-0">
                        <button type="button" class="sort-header w-full h-full p-3 flex items-center justify-between gap-2 text-left text-[11px] uppercase tracking-widest" data-recent-account-sort="id" data-sort-type="text">
                            ID No.
                            <span class="material-symbols-outlined sort-icon">unfold_more</span>
                        </button>
                    </th>
                    <th class="p-0">
                        <button type="button" class="sort-header w-full h-full p-3 flex items-center justify-between gap-2 text-left text-[11px] uppercase tracking-widest" data-recent-account-sort="patient" data-sort-type="text">
                            Patient
                            <span class="material-symbols-outlined sort-icon">unfold_more</span>
                        </button>
                    </th>
                    <th class="p-0">
                        <button type="button" class="sort-header w-full h-full p-3 flex items-center justify-between gap-2 text-left text-[11px] uppercase tracking-widest" data-recent-account-sort="type" data-sort-type="text">
                            Type
                            <span class="material-symbols-outlined sort-icon">unfold_more</span>
                        </button>
                    </th>
                    <th class="p-0">
                        <button type="button" class="sort-header w-full h-full p-3 flex items-center justify-between gap-2 text-left text-[11px] uppercase tracking-widest" data-recent-account-sort="birthdate" data-sort-type="date">
                            Birthdate
                            <span class="material-symbols-outlined sort-icon">unfold_more</span>
                        </button>
                    </th>
                    <th class="p-0">
                        <button type="button" class="sort-header w-full h-full p-3 flex items-center justify-between gap-2 text-left text-[11px] uppercase tracking-widest" data-recent-account-sort="status" data-sort-type="text">
                            Account Status
                            <span class="material-symbols-outlined sort-icon">unfold_more</span>
                        </button>
                    </th>
                    <th class="p-0">
                        <button type="button" class="sort-header w-full h-full p-3 flex items-center justify-between gap-2 text-left text-[11px] uppercase tracking-widest" data-recent-account-sort="created" data-sort-type="date">
                            Created
                            <span class="material-symbols-outlined sort-icon">unfold_more</span>
                        </button>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentAccounts as $accountIndex => $account): ?>
                    <?php
                    $accountSearchText = strtolower(trim(implode(' ', [
                        $account['id_number'] ?? '',
                        $account['full_name'] ?? '',
                        $account['patient_type'] ?? '',
                        $account['birthdate'] ?? '',
                        $account['account_status'] ?? '',
                        !empty($account['created_at']) ? date('M d, Y', strtotime($account['created_at'])) : '',
                    ])));
                    ?>
                    <tr class="border-t border-slate-100" style="height: 49px<?= $accountIndex >= $recentAccountPageSize ? '; display: none' : '' ?>"
                        data-recent-account-row
                        data-sort-id="<?= e(strtolower((string) $account['id_number'])) ?>"
                        data-sort-patient="<?= e(strtolower((string) $account['full_name'])) ?>"
                        data-sort-type="<?= e(strtolower((string) $account['patient_type'])) ?>"
                        data-sort-birthdate="<?= e((string) ($account['birthdate'] ?? '')) ?>"
                        data-sort-status="<?= e(strtolower((string) $account['account_status'])) ?>"
                        data-sort-created="<?= e((string) ($account['created_at'] ?? '')) ?>"
                        data-search-text="<?= e($accountSearchText) ?>"
                        data-patient-type="<?= e(strtolower((string) $account['patient_type'])) ?>"
                        data-account-status="<?= e(strtolower((string) $account['account_status'])) ?>">
                        <td class="p-3 font-bold text-slate-500" data-recent-account-number><?= $accountIndex + 1 ?></td>
                        <td class="p-3 font-bold"><?= e($account['id_number']) ?></td>
                        <td class="p-3"><?= e($account['full_name']) ?></td>
                        <td class="p-3"><?= e($account['patient_type']) ?></td>
                        <td class="p-3"><?= e($account['birthdate'] ?: '—') ?></td>
                        <td class="p-3"><span class="badge <?= e(status_badge_class($account['account_status'])) ?>"><?= e(ucfirst($account['account_status'])) ?></span></td>
                        <td class="p-3"><?= e(date('M d, Y', strtotime($account['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="border-t border-slate-100" style="height: 49px; display: none" data-recent-account-no-results>
                    <td colspan="7" class="p-3 text-center text-sm font-bold text-slate-500">No patient accounts match the current filters.</td>
                </tr>
                <?php for ($emptyRow = 0; $emptyRow < $recentAccountPageSize; $emptyRow++): ?>
                    <tr class="border-t border-slate-100" style="height: 49px<?= $emptyRow < max(0, $recentAccountPageSize - min($recentAccountPageSize, count($recentAccounts))) ? '' : '; display: none' ?>" aria-hidden="true" data-recent-account-empty-row>
                        <td colspan="7" class="p-3">&nbsp;</td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
    <nav class="pagination border-t border-slate-100" aria-label="Recent patient accounts pages">
        <button type="button" class="page-disabled" aria-label="Previous page" data-recent-account-previous disabled>‹</button>

        <span class="inline-flex items-center gap-1" data-recent-account-pages></span>

        <button type="button" class="<?= $recentAccountPageCount === 1 ? 'page-disabled' : '' ?>" aria-label="Next page" data-recent-account-next<?= $recentAccountPageCount === 1 ? ' disabled' : '' ?>>›</button>
    </nav>
</section>

        </div>
    </section>
</div>

<script src="<?= app_url('assets/vendor/sheetjs/xlsx.full.min.js?v=0.20.3') ?>"></script>
<script>
function initRecentPatientAccountPagination() {
    const section = document.getElementById('recentPatientAccounts');
    if (!section) return;
    if (section.dataset.paginationReady === 'true' && section.dataset.boundNodeCount === String(section.querySelectorAll('[data-recent-account-row]').length)) {
        return;
    }
    section.dataset.paginationReady = 'true';

    const pageSize = 10;
    const rows = Array.from(section.querySelectorAll('[data-recent-account-row]'));
    section.dataset.boundNodeCount = String(rows.length);
    const tableBody = section.querySelector('tbody');
    const emptyRows = Array.from(section.querySelectorAll('[data-recent-account-empty-row]'));
    const noResultsRow = section.querySelector('[data-recent-account-no-results]');
    const countLabel = section.querySelector('[data-recent-account-count]');
    const searchInput = section.querySelector('[data-recent-account-search]');
    const typeFilter = section.querySelector('[data-recent-account-type-filter]');
    const statusFilter = section.querySelector('[data-recent-account-status-filter]');
    const pageList = section.querySelector('[data-recent-account-pages]');
    const previousButton = section.querySelector('[data-recent-account-previous]');
    const nextButton = section.querySelector('[data-recent-account-next]');
    const sortButtons = Array.from(section.querySelectorAll('[data-recent-account-sort]'));
    let filteredRows = rows;
    let pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize));
    let currentPage = 1;
    let sortField = '';
    let sortDirection = 'asc';

    function compareRows(leftRow, rightRow) {
        if (sortField === '') return rows.indexOf(leftRow) - rows.indexOf(rightRow);

        const activeButton = sortButtons.find((button) => button.dataset.recentAccountSort === sortField);
        const sortType = activeButton?.dataset.sortType || 'text';
        const leftValue = leftRow.dataset[`sort${sortField.charAt(0).toUpperCase()}${sortField.slice(1)}`] || '';
        const rightValue = rightRow.dataset[`sort${sortField.charAt(0).toUpperCase()}${sortField.slice(1)}`] || '';
        let result = 0;

        if (sortType === 'number') {
            result = (Number(leftValue) || 0) - (Number(rightValue) || 0);
        } else if (sortType === 'date') {
            const leftTime = leftValue ? Date.parse(leftValue) : 0;
            const rightTime = rightValue ? Date.parse(rightValue) : 0;
            result = leftTime - rightTime;
        } else {
            result = leftValue.localeCompare(rightValue, undefined, { numeric: true, sensitivity: 'base' });
        }

        if (result === 0) {
            result = rows.indexOf(leftRow) - rows.indexOf(rightRow);
        }

        return sortDirection === 'asc' ? result : -result;
    }

    function syncSortHeaders() {
        const hasActiveFilter = Boolean(
            (searchInput?.value || '').trim() ||
            (typeFilter?.value || '').trim() ||
            (statusFilter?.value || '').trim()
        );
        section.classList.toggle('has-active-account-filter', hasActiveFilter);
        section.classList.toggle('has-active-account-sort', sortField !== '');
        sortButtons.forEach((button) => {
            const active = button.dataset.recentAccountSort === sortField;
            const icon = button.querySelector('.sort-icon');
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-sort', active ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none');
            if (icon) {
                icon.textContent = active ? (sortDirection === 'asc' ? 'arrow_upward' : 'arrow_downward') : 'unfold_more';
                icon.style.opacity = active ? '1' : '';
                icon.style.visibility = active ? 'visible' : '';
            }
        });
    }

    function buildPageButtons() {
        if (!pageList) return;
        pageList.innerHTML = '';
        for (let pageNumber = 1; pageNumber <= pageCount; pageNumber += 1) {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.recentAccountPage = String(pageNumber);
            button.textContent = String(pageNumber);
            button.addEventListener('click', () => showPage(pageNumber));
            pageList.appendChild(button);
        }
    }

    function syncPageButtons() {
        if (!pageList) return;
        Array.from(pageList.querySelectorAll('[data-recent-account-page]')).forEach((button) => {
            const active = Number(button.dataset.recentAccountPage) === currentPage;
            button.classList.toggle('page-active', active);
            if (active) {
                button.setAttribute('aria-current', 'page');
            } else {
                button.removeAttribute('aria-current');
            }
        });
    }

    function showPage(page) {
        currentPage = Math.max(1, Math.min(pageCount, page));
        const pageStart = (currentPage - 1) * pageSize;
        const visibleCount = Math.max(0, Math.min(pageSize, filteredRows.length - pageStart));
        const visibleRows = new Set(filteredRows.slice(pageStart, pageStart + pageSize));

        if (tableBody) {
            const filteredSet = new Set(filteredRows);
            const orderedRows = filteredRows.concat(rows.filter((row) => !filteredSet.has(row)));
            const insertionPoint = noResultsRow || emptyRows[0] || null;
            orderedRows.forEach((row) => tableBody.insertBefore(row, insertionPoint));
        }

        rows.forEach((row) => {
            row.hidden = !visibleRows.has(row);
            row.style.display = row.hidden ? 'none' : '';
        });
        filteredRows.forEach((row, index) => {
            const numberCell = row.querySelector('[data-recent-account-number]');
            if (numberCell) {
                numberCell.textContent = String(index + 1);
            }
        });
        if (noResultsRow) {
            noResultsRow.hidden = filteredRows.length > 0;
            noResultsRow.style.display = filteredRows.length > 0 ? 'none' : '';
        }
        emptyRows.forEach((row, index) => {
            row.hidden = index >= pageSize - visibleCount;
            row.style.display = row.hidden ? 'none' : '';
        });
        syncPageButtons();

        previousButton.disabled = currentPage === 1;
        previousButton.classList.toggle('page-disabled', previousButton.disabled);
        nextButton.disabled = currentPage === pageCount;
        nextButton.classList.toggle('page-disabled', nextButton.disabled);
    }

    function applyFilters() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const selectedType = (typeFilter?.value || '').trim().toLowerCase();
        const selectedStatus = (statusFilter?.value || '').trim().toLowerCase();

        filteredRows = rows.filter((row) => {
            const searchText = row.dataset.searchText || '';
            const patientType = row.dataset.patientType || '';
            const accountStatus = row.dataset.accountStatus || '';
            const matchesSearch = query === '' || searchText.includes(query);
            const matchesType = selectedType === '' || patientType === selectedType;
            const matchesStatus = selectedStatus === '' || accountStatus === selectedStatus;
            return matchesSearch && matchesType && matchesStatus;
        });
        filteredRows.sort(compareRows);

        pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        if (countLabel) {
            countLabel.textContent = String(filteredRows.length);
        }
        syncSortHeaders();
        buildPageButtons();
        showPage(1);
    }

    previousButton?.addEventListener('click', () => showPage(currentPage - 1));
    nextButton?.addEventListener('click', () => showPage(currentPage + 1));
    searchInput?.addEventListener('input', applyFilters);
    typeFilter?.addEventListener('change', applyFilters);
    statusFilter?.addEventListener('change', applyFilters);
    sortButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const nextField = button.dataset.recentAccountSort || '';
            if (sortField === nextField) {
                if (sortDirection === 'asc') {
                    sortDirection = 'desc';
                } else {
                    sortField = '';
                    sortDirection = 'asc';
                }
            } else {
                sortField = nextField;
                sortDirection = 'asc';
            }
            syncSortHeaders();
            applyFilters();
        });
    });
    syncSortHeaders();
    applyFilters();
}

window.initRecentPatientAccountPagination = initRecentPatientAccountPagination;
document.addEventListener('DOMContentLoaded', initRecentPatientAccountPagination);
document.addEventListener('cliniq:page-content-replaced', initRecentPatientAccountPagination);

const patientType = document.getElementById('patient_type');
const activePrograms = <?= json_encode($programOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const activeDepartments = <?= json_encode($departmentOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const accountFieldHints = {
    student: {
        idPlaceholder: '23-00262',
        idHint: 'Student example: 23-00262',
        programLabel: 'Program Code',
        programPlaceholder: 'Select program',
        programHint: 'Select an active program from the database.',
        yearLabel: 'Year Level',
        yearPlaceholder: '3',
        yearHint: 'Choose 1 to 4.',
        sectionLabel: 'Section Code',
        sectionPlaceholder: 'D',
        sectionHint: 'Choose A to E. Program, year level, and section are stored separately.',
    },
    faculty: {
        idPlaceholder: 'FAC-0001',
        idHint: 'Faculty example: FAC-0001',
        programLabel: 'Department',
        programPlaceholder: 'Select department',
        programHint: 'Select an active department from the database.',
        yearLabel: 'Employment Type',
        yearPlaceholder: 'Full-time',
        yearHint: 'Choose Full-time or Part-time.',
        sectionLabel: 'Title',
        sectionPlaceholder: 'DIT or MIT',
        sectionHint: 'Example: DIT or MIT',
    },
    school_personnel: {
        idPlaceholder: 'SP-0001',
        idHint: 'School personnel example: SP-0001',
        programLabel: 'Department',
        programPlaceholder: 'Select department',
        programHint: 'Select an active department from the database.',
        yearLabel: 'Employment Type',
        yearPlaceholder: 'Full-time',
        yearHint: 'Example: Full-time',
        sectionLabel: 'Position',
        sectionPlaceholder: 'Administrative Assistant',
        sectionHint: 'Example: Administrative Assistant',
    },
};

function updateProgramDepartmentOptions(select, options, placeholder) {
    const currentValue = String(select.value || '').toUpperCase();
    select.replaceChildren();

    const emptyOption = document.createElement('option');
    emptyOption.value = '';
    emptyOption.textContent = placeholder;
    select.appendChild(emptyOption);

    options.forEach(option => {
        const element = document.createElement('option');
        element.value = option.code;
        element.textContent = `${option.code} — ${option.name}`;
        select.appendChild(element);
    });

    select.value = options.some(option => option.code === currentValue)
        ? currentValue
        : '';
}

function updateIndividualAccountHints() {
    const hints = accountFieldHints[patientType?.value] || accountFieldHints.student;
    const studentSelected = patientType?.value === 'student';
    const facultySelected = patientType?.value === 'faculty';
    const programInput = document.getElementById('program_or_department');
    const employmentInput = document.getElementById('year_level_or_employment_type');
    const studentYearSelect = document.getElementById('student_year_level');
    const facultyEmploymentSelect = document.getElementById('faculty_employment_type');
    const employmentLabel = document.getElementById('year_level_or_employment_type_label');
    const sectionPositionInput = document.getElementById('section_or_position');
    const studentSectionSelect = document.getElementById('student_section_code');
    const sectionLabel = document.getElementById('section_or_position_label');
    const fieldMappings = [
        ['id_number', null, 'id_number_hint', hints.idPlaceholder, hints.idHint],
        ['program_or_department', 'program_or_department_label', 'program_or_department_hint', hints.programPlaceholder, hints.programHint, hints.programLabel],
    ];

    fieldMappings.forEach(([inputId, labelId, hintId, placeholder, hint, label]) => {
        const input = document.getElementById(inputId);
        const labelElement = labelId ? document.getElementById(labelId) : null;
        const hintElement = document.getElementById(hintId);
        if (input) input.placeholder = placeholder;
        if (labelElement && label) labelElement.textContent = label;
        if (hintElement) hintElement.textContent = hint;
    });

    if (programInput) {
        updateProgramDepartmentOptions(
            programInput,
            studentSelected ? activePrograms : activeDepartments,
            hints.programPlaceholder
        );
        programInput.required = true;
    }

    if (employmentInput && studentYearSelect && facultyEmploymentSelect && employmentLabel) {
        employmentInput.classList.toggle('hidden', studentSelected || facultySelected);
        employmentInput.disabled = studentSelected || facultySelected;
        studentYearSelect.classList.toggle('hidden', !studentSelected);
        studentYearSelect.disabled = !studentSelected;
        studentYearSelect.required = studentSelected;
        facultyEmploymentSelect.classList.toggle('hidden', !facultySelected);
        facultyEmploymentSelect.disabled = !facultySelected;
        facultyEmploymentSelect.required = facultySelected;
        employmentInput.placeholder = hints.yearPlaceholder;
        employmentLabel.textContent = hints.yearLabel;
        employmentLabel.htmlFor = studentSelected
            ? 'student_year_level'
            : (facultySelected ? 'faculty_employment_type' : 'year_level_or_employment_type');
    }

    const employmentHint = document.getElementById('year_level_or_employment_type_hint');
    if (employmentHint) employmentHint.textContent = hints.yearHint;

    if (sectionPositionInput && studentSectionSelect && sectionLabel) {
        sectionPositionInput.classList.toggle('hidden', studentSelected);
        sectionPositionInput.disabled = studentSelected;
        sectionPositionInput.placeholder = hints.sectionPlaceholder;
        studentSectionSelect.classList.toggle('hidden', !studentSelected);
        studentSectionSelect.disabled = !studentSelected;
        studentSectionSelect.required = studentSelected;
        sectionLabel.textContent = hints.sectionLabel;
        sectionLabel.htmlFor = studentSelected ? 'student_section_code' : 'section_or_position';
    }

    const sectionHint = document.getElementById('section_or_position_hint');
    if (sectionHint) sectionHint.textContent = hints.sectionHint;
    if (facultySelected && sectionPositionInput) {
        sectionPositionInput.value = sectionPositionInput.value.toUpperCase();
    }

    updateStudentSectionPreview();
}

patientType?.addEventListener('change', updateIndividualAccountHints);
updateIndividualAccountHints();

const idNumberInput = document.getElementById('id_number');
idNumberInput?.addEventListener('input', () => {
    idNumberInput.value = idNumberInput.value.toUpperCase();
});

const programOrDepartmentInput = document.getElementById('program_or_department');
programOrDepartmentInput?.addEventListener('change', updateStudentSectionPreview);

const sectionOrPositionInput = document.getElementById('section_or_position');
sectionOrPositionInput?.addEventListener('input', () => {
    if (patientType?.value === 'faculty') {
        sectionOrPositionInput.value = sectionOrPositionInput.value.toUpperCase();
    }
});

const studentYearLevel = document.getElementById('student_year_level');
const studentSectionCode = document.getElementById('student_section_code');

function updateStudentSectionPreview() {
    if (patientType?.value !== 'student') return;

    const program = String(document.getElementById('program_or_department')?.value || '').trim().toUpperCase();
    const year = String(document.getElementById('student_year_level')?.value || '').trim();
    const section = String(document.getElementById('student_section_code')?.value || '').trim().toUpperCase();
    const hint = document.getElementById('section_or_position_hint');
    if (!hint) return;

    hint.textContent = program && year && section
        ? `Program: ${program} · Year: ${year} · Section: ${section}`
        : 'Choose A to E. Program, year level, and section are stored separately.';
}

studentYearLevel?.addEventListener('change', updateStudentSectionPreview);
studentSectionCode?.addEventListener('change', updateStudentSectionPreview);

const excelFile = document.getElementById('excelFile');
const excelMessage = document.getElementById('excelMessage');
const excelPreview = document.getElementById('excelPreview');
const bulkPayload = document.getElementById('bulkPayload');
const bulkImportButton = document.getElementById('bulkImportButton');
const requiredColumns = ['id_number', 'patient_type', 'first_name', 'last_name', 'birthdate', 'sex'];

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
        const importHeaderAliases = {
            program_code_department: 'program_or_department',
            year_level_employment_type: 'year_level_or_employment_type',
            section_code_title_position: 'section_or_position',
            section_title_position: 'section_or_position',
            section_title_or_position: 'section_or_position',
            section_or_title: 'section_or_position',
        };
        const rows = sourceRows
            .map(row => Object.fromEntries(Object.entries(row).map(([key, value]) => {
                const normalizedKey = normalizeHeader(key);
                return [importHeaderAliases[normalizedKey] || normalizedKey, String(value).trim()];
            })))
            .map(row => {
                const importType = normalizeHeader(row.patient_type);
                return {
                    ...row,
                    id_number: String(row.id_number || '').toUpperCase(),
                    sex: String(row.sex || '').trim(),
                    program_or_department: String(row.program_or_department || '').toUpperCase(),
                    section_or_position: ['student', 'faculty'].includes(importType)
                        ? String(row.section_or_position || '').toUpperCase()
                        : String(row.section_or_position || ''),
                };
            })
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
            <thead class="bg-slate-50"><tr><th class="p-2 text-left">No.</th><th class="p-2 text-left">ID</th><th class="p-2 text-left">Type</th><th class="p-2 text-left">Name</th><th class="p-2 text-left">Birthdate</th><th class="p-2 text-left">Sex</th></tr></thead>
            <tbody>${previewRows.map((row, index) => `<tr class="border-t border-slate-100"><td class="p-2 font-bold text-slate-500">${index + 1}</td><td class="p-2">${escapeHtml(row.id_number)}</td><td class="p-2">${escapeHtml(row.patient_type)}</td><td class="p-2">${escapeHtml([row.first_name,row.middle_name,row.last_name].filter(Boolean).join(' '))}</td><td class="p-2">${escapeHtml(row.birthdate)}</td><td class="p-2">${escapeHtml(row.sex)}</td></tr>`).join('')}</tbody>
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
        'Password': row.password,
        'Result': row.status === 'created' ? 'Created' : row.status,
    })));
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, sheet, 'Import Results');
    XLSX.writeFile(workbook, 'cliniq-patient-account-import-results.xlsx');
});
<?php endif; ?>
</script>

<?php render_footer(); ?>
