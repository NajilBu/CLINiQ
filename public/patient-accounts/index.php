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
            <span>Password: <code><?= e($createdAccount['temporary_password']) ?></code></span>
            <span class="badge badge-pending">Inactive</span>
        </div>
        <p class="text-xs font-bold text-emerald-800 mt-3 mb-0">Give the password privately to the account owner. They will use it on the login page and create their own password in the dashboard.</p>
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
                <div class="sm:col-span-2">
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
                        <th class="p-3">Password</th>
                        <th class="p-3">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bulkResults as $result): ?>
                        <tr class="border-t border-slate-100">
                            <td class="p-3"><?= e($result['row']) ?></td>
                            <td class="p-3 font-bold"><?= e($result['id_number']) ?></td>
                            <td class="p-3"><?= e($result['name']) ?></td>
                            <td class="p-3"><?= e(patient_account_type_label($result['type'])) ?></td>
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

<section class="clinic-card overflow-hidden" id="recentPatientAccounts">
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
                <?php foreach ($recentAccounts as $accountIndex => $account): ?>
                    <tr class="border-t border-slate-100" style="height: 49px<?= $accountIndex >= $recentAccountPageSize ? '; display: none' : '' ?>" data-recent-account-row>
                        <td class="p-3 font-bold"><?= e($account['id_number']) ?></td>
                        <td class="p-3"><?= e($account['full_name']) ?></td>
                        <td class="p-3"><?= e($account['patient_type']) ?></td>
                        <td class="p-3"><?= e($account['birthdate'] ?: '—') ?></td>
                        <td class="p-3"><span class="badge <?= e(status_badge_class($account['account_status'])) ?>"><?= e(ucfirst($account['account_status'])) ?></span></td>
                        <td class="p-3"><?= e(date('M d, Y', strtotime($account['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php for ($emptyRow = 0; $emptyRow < $recentAccountPageSize; $emptyRow++): ?>
                    <tr class="border-t border-slate-100" style="height: 49px<?= $emptyRow < max(0, $recentAccountPageSize - min($recentAccountPageSize, count($recentAccounts))) ? '' : '; display: none' ?>" aria-hidden="true" data-recent-account-empty-row>
                        <td colspan="6" class="p-3">&nbsp;</td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
    <nav class="pagination border-t border-slate-100" aria-label="Recent patient accounts pages">
        <button type="button" class="page-disabled" aria-label="Previous page" data-recent-account-previous disabled>‹</button>

        <?php for ($pageNumber = 1; $pageNumber <= $recentAccountPageCount; $pageNumber++): ?>
            <button type="button" class="<?= $pageNumber === 1 ? 'page-active' : '' ?>" data-recent-account-page="<?= $pageNumber ?>"<?= $pageNumber === 1 ? ' aria-current="page"' : '' ?>><?= $pageNumber ?></button>
        <?php endfor; ?>

        <button type="button" class="<?= $recentAccountPageCount === 1 ? 'page-disabled' : '' ?>" aria-label="Next page" data-recent-account-next<?= $recentAccountPageCount === 1 ? ' disabled' : '' ?>>›</button>
    </nav>
</section>

<script src="<?= app_url('assets/vendor/sheetjs/xlsx.full.min.js?v=0.20.3') ?>"></script>
<script>
function initRecentPatientAccountPagination() {
    const section = document.getElementById('recentPatientAccounts');
    if (!section || section.dataset.paginationReady === 'true') return;
    section.dataset.paginationReady = 'true';

    const pageSize = 10;
    const rows = Array.from(section.querySelectorAll('[data-recent-account-row]'));
    const emptyRows = Array.from(section.querySelectorAll('[data-recent-account-empty-row]'));
    const pageButtons = Array.from(section.querySelectorAll('[data-recent-account-page]'));
    const previousButton = section.querySelector('[data-recent-account-previous]');
    const nextButton = section.querySelector('[data-recent-account-next]');
    const pageCount = Math.max(1, Math.ceil(rows.length / pageSize));
    let currentPage = 1;

    function showPage(page) {
        currentPage = Math.max(1, Math.min(pageCount, page));
        const pageStart = (currentPage - 1) * pageSize;
        const visibleCount = Math.max(0, Math.min(pageSize, rows.length - pageStart));

        rows.forEach((row, index) => {
            row.hidden = index < pageStart || index >= pageStart + pageSize;
            row.style.display = row.hidden ? 'none' : '';
        });
        emptyRows.forEach((row, index) => {
            row.hidden = index >= pageSize - visibleCount;
            row.style.display = row.hidden ? 'none' : '';
        });
        pageButtons.forEach((button) => {
            const active = Number(button.dataset.recentAccountPage) === currentPage;
            button.classList.toggle('page-active', active);
            if (active) {
                button.setAttribute('aria-current', 'page');
            } else {
                button.removeAttribute('aria-current');
            }
        });

        previousButton.disabled = currentPage === 1;
        previousButton.classList.toggle('page-disabled', previousButton.disabled);
        nextButton.disabled = currentPage === pageCount;
        nextButton.classList.toggle('page-disabled', nextButton.disabled);
    }

    pageButtons.forEach((button) => {
        button.addEventListener('click', () => showPage(Number(button.dataset.recentAccountPage)));
    });
    previousButton.addEventListener('click', () => showPage(currentPage - 1));
    nextButton.addEventListener('click', () => showPage(currentPage + 1));
    showPage(1);
}

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
            <thead class="bg-slate-50"><tr><th class="p-2 text-left">ID</th><th class="p-2 text-left">Type</th><th class="p-2 text-left">Name</th><th class="p-2 text-left">Birthdate</th><th class="p-2 text-left">Sex</th></tr></thead>
            <tbody>${previewRows.map(row => `<tr class="border-t border-slate-100"><td class="p-2">${escapeHtml(row.id_number)}</td><td class="p-2">${escapeHtml(row.patient_type)}</td><td class="p-2">${escapeHtml([row.first_name,row.middle_name,row.last_name].filter(Boolean).join(' '))}</td><td class="p-2">${escapeHtml(row.birthdate)}</td><td class="p-2">${escapeHtml(row.sex)}</td></tr>`).join('')}</tbody>
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
        'Password': row.temporary_password,
        'Result': row.status === 'created' ? 'Created' : row.status,
    })));
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, sheet, 'Import Results');
    XLSX.writeFile(workbook, 'cliniq-patient-account-import-results.xlsx');
});
<?php endif; ?>
</script>

<?php render_footer(); ?>
