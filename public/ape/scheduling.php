<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeCycleService.php';

require_login();
ensure_ape_cycle_schema();

$user = current_user() ?? [];
$canManageApeSchedules = in_array($user['role'] ?? '', ['admin', 'doctor'], true);
if (!$canManageApeSchedules) {
    flash_message('error', 'Only administrators and doctors can manage APE scheduling.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $actorPersonId = (int) ($user['person_id'] ?? 0) ?: null;

    try {
        if ($action === 'create_ape_schedule_batch') {
            $batch = create_ape_schedule_batch($_POST, $actorPersonId);
            flash_message('success', sprintf(
                '%s created with %d assigned patient(s).',
                $batch['batch_name'],
                (int) $batch['assigned_count']
            ));
        } elseif ($action === 'cancel_ape_schedule_batch') {
            cancel_ape_schedule_batch(
                (int) ($_POST['batch_id'] ?? 0),
                (int) ($_POST['ape_cycle_id'] ?? 0),
                $actorPersonId
            );
            flash_message('success', 'The batch was cancelled and its patients are available for reassignment.');
        } else {
            throw new InvalidArgumentException('Choose a valid APE scheduling action.');
        }
    } catch (Throwable $e) {
        flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
    }

    header('Location: scheduling.php');
    exit;
}

$apeCurrentCycle = ape_cycle_current();
$hasActiveApeCycle = ($apeCurrentCycle['status'] ?? '') === 'Active';
$apeScheduleBatches = [];
$apeScheduleGroups = [];
if ($hasActiveApeCycle) {
    $cycleId = (int) $apeCurrentCycle['ape_cycle_id'];
    $apeScheduleBatches = ape_schedule_batches($cycleId);
    $apeScheduleGroups = ape_schedule_candidate_groups($cycleId);
}

set_page_back_link('index.php', 'APE');
render_header('APE Scheduling');

$headerActions = $hasActiveApeCycle
    ? '<button type="button" class="btn btn-primary justify-center" id="openApeBatchModalButton"'
        . ($apeScheduleGroups === [] ? ' disabled' : '') . '>'
        . '<span class="material-symbols-outlined text-[18px]">group_add</span>Create Batch</button>'
    : '';

render_clinic_command_header(
    'APE',
    'APE Scheduling',
    'Create examination batches by complete student section, department, or office.',
    $headerActions
);
?>

<?php if (!$hasActiveApeCycle): ?>
    <section class="clinic-card p-6 md:p-8">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Start an APE cycle first</h2>
                <p class="text-sm font-bold text-slate-500 mb-0">Batch scheduling becomes available after an active school-year APE cycle has been created.</p>
            </div>
            <a href="<?= e(app_url('settings/index.php?tab=ape-cycle')) ?>" class="btn btn-primary justify-center text-decoration-none shrink-0" data-no-ajax="true">
                <span class="material-symbols-outlined text-[18px]">event_repeat</span>
                Go to APE Cycle
            </a>
        </div>
    </section>
<?php else: ?>
    <section class="clinic-card overflow-hidden">
        <div class="p-6 border-b border-outline-variant flex flex-col md:flex-row md:items-start md:justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d]">Scheduled APE Batches</h2>
                    <span class="badge badge-in-progress"><?= count($apeScheduleBatches) ?> batch<?= count($apeScheduleBatches) === 1 ? '' : 'es' ?></span>
                </div>
                <p class="text-xs font-bold text-slate-500 mb-0">School Year <?= e($apeCurrentCycle['academic_year']) ?> &bull; Categories cannot overlap at the same date and time.</p>
            </div>
        </div>

        <?php
        $batchColumns = [
            ['headerName' => 'Batch', 'field' => 'batchHtml', 'cellRenderer' => 'html', 'sortField' => 'batchSort', 'minWidth' => 220],
            ['headerName' => 'Patient Category', 'field' => 'categoryHtml', 'cellRenderer' => 'html', 'sortField' => 'categorySort', 'minWidth' => 180],
            ['headerName' => 'Date and Time', 'field' => 'scheduleHtml', 'cellRenderer' => 'html', 'sortField' => 'scheduleSort', 'minWidth' => 240],
            ['headerName' => 'Assigned', 'field' => 'assignedHtml', 'cellRenderer' => 'html', 'sortField' => 'assignedSort', 'width' => 130],
            ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'sortField' => 'statusSort', 'width' => 140],
            ['headerName' => 'Actions', 'field' => 'actionHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 100, 'minWidth' => 90],
        ];
        $batchRows = [];
        foreach ($apeScheduleBatches as $batch) {
            $batchIsCancelled = ($batch['status'] ?? '') === 'Cancelled';
            $batchHasPassed = strtotime((string) $batch['schedule_date'] . ' ' . (string) $batch['end_time']) < time();
            $batchDisplayStatus = $batchIsCancelled ? 'Cancelled' : ($batchHasPassed ? 'Completed' : 'Scheduled');
            $batchStatusClass = $batchIsCancelled ? 'badge-pending' : ($batchHasPassed ? 'badge-completed' : 'badge-in-progress');
            $batchActions = '';
            if (!$batchIsCancelled && !$batchHasPassed) {
                $batchActions = '<form method="post" data-no-ajax="true">'
                    . '<input type="hidden" name="action" value="cancel_ape_schedule_batch">'
                    . '<input type="hidden" name="ape_cycle_id" value="' . (int) $apeCurrentCycle['ape_cycle_id'] . '">'
                    . '<input type="hidden" name="batch_id" value="' . (int) $batch['batch_id'] . '">'
                    . '<button class="btn btn-secondary btn-sm justify-center" data-confirm-submit data-confirm-type="danger" data-confirm-title="Cancel this APE batch?" data-confirm-message="Assigned patients will become available for another batch. Their APE records will not be deleted." data-confirm-toast="Cancelling batch...">'
                    . '<span class="material-symbols-outlined text-[16px]">event_busy</span>Cancel</button></form>';
            }
            $batchRows[] = [
                'batchSort' => $batch['batch_name'],
                'batchHtml' => '<strong class="text-sm text-slate-800 block">' . e($batch['batch_name']) . '</strong><span class="text-xs font-bold text-slate-400">Created ' . e(date('M j, Y', strtotime($batch['created_at']))) . '</span>',
                'categorySort' => $batch['patient_category'],
                'categoryHtml' => '<span class="badge badge-in-progress">' . e($batch['patient_category']) . '</span>',
                'scheduleSort' => (string) $batch['schedule_date'] . ' ' . (string) $batch['start_time'],
                'scheduleHtml' => '<strong class="text-sm text-slate-800 block">' . e(date('F j, Y', strtotime($batch['schedule_date']))) . '</strong><span class="text-xs font-bold text-slate-500">' . e(date('g:i A', strtotime($batch['start_time']))) . '–' . e(date('g:i A', strtotime($batch['end_time']))) . '</span>',
                'assignedSort' => (int) $batch['assigned_count'],
                'assignedHtml' => '<strong class="text-sm text-slate-800">' . (int) $batch['assigned_count'] . '</strong><span class="text-xs font-bold text-slate-400"> / ' . (int) $batch['capacity'] . '</span>',
                'statusSort' => $batchDisplayStatus,
                'statusHtml' => '<span class="badge ' . $batchStatusClass . '">' . e($batchDisplayStatus) . '</span>',
                'actionHtml' => row_actions_button('Batch actions', $batchActions),
            ];
        }
        render_ag_grid('apeSchedulingGrid', $batchColumns, $batchRows, [
            'pageSize' => 10,
            'pagination' => true,
            'paginationControls' => 'apeSchedulingPagination',
            'height' => 'compact',
            'emptyTitle' => 'No APE batches scheduled',
            'emptyText' => 'Create a patient batch to set examination dates and times.',
        ]);
        ?>
        <nav id="apeSchedulingPagination" class="pagination" aria-label="Scheduled APE batch pages"></nav>

        <?php if ($apeScheduleGroups === []): ?>
            <p class="px-6 pb-6 text-xs font-bold text-slate-500 mb-0">All eligible sections, departments, and offices are already assigned, or there are no eligible groups in this cycle.</p>
        <?php endif; ?>
    </section>

    <div id="apeBatchModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="apeBatchModalTitle">
        <div class="modal-content bg-white rounded-[1.5rem] w-full max-w-4xl p-7 shadow-2xl border border-outline-variant/10 max-h-[94vh] overflow-y-auto">
            <div class="flex items-start justify-between gap-4 mb-5">
                <div>
                    <div class="w-12 h-12 rounded-xl bg-[var(--cliniq-surface-low)] text-[var(--cliniq-primary)] flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-[26px]">groups</span>
                    </div>
                    <h3 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1" id="apeBatchModalTitle">Create APE Patient Batch</h3>
                    <p class="text-sm font-bold text-slate-500 mb-0">Choose one patient category, then select complete sections, departments, or offices. The list shows 5 groups per page.</p>
                </div>
                <button type="button" class="btn btn-secondary !p-3" id="closeApeBatchModalButton" aria-label="Close batch form">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form method="post" data-no-ajax="true" id="apeBatchForm" class="space-y-5">
                <input type="hidden" name="action" value="create_ape_schedule_batch">
                <input type="hidden" name="ape_cycle_id" value="<?= (int) $apeCurrentCycle['ape_cycle_id'] ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="clinic-label" for="apeBatchName">Batch Name</label>
                        <input class="clinic-input" id="apeBatchName" name="batch_name" maxlength="120" placeholder="Student Batch A" required>
                    </div>
                    <div>
                        <label class="clinic-label" for="apeBatchCategory">Patient Category</label>
                        <select class="clinic-select" id="apeBatchCategory" name="patient_category" required>
                            <?php foreach (ape_schedule_batch_categories() as $category): ?>
                                <option value="<?= e($category) ?>"><?= e($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="clinic-label" for="apeBatchDate">Exam Date</label>
                        <input class="clinic-input" id="apeBatchDate" name="schedule_date" type="date" min="<?= e($apeCurrentCycle['compliance_start']) ?>" max="<?= e($apeCurrentCycle['compliance_end']) ?>" required>
                    </div>
                    <div>
                        <label class="clinic-label" for="apeBatchCapacity">Patient Limit</label>
                        <input class="clinic-input" id="apeBatchCapacity" name="capacity" type="number" min="1" max="5000" value="50" required>
                        <p class="text-xs font-bold text-slate-500 mt-2 mb-0">The combined size of selected groups cannot exceed this limit.</p>
                    </div>
                    <div>
                        <label class="clinic-label" for="apeBatchStart">Start Time</label>
                        <input class="clinic-input" id="apeBatchStart" name="start_time" type="text" inputmode="text" maxlength="8" placeholder="8:00 AM" autocomplete="off" autocapitalize="characters" aria-describedby="apeBatchHours" required>
                    </div>
                    <div>
                        <label class="clinic-label" for="apeBatchEnd">End Time</label>
                        <input class="clinic-input" id="apeBatchEnd" name="end_time" type="text" inputmode="text" maxlength="8" placeholder="5:00 PM" autocomplete="off" autocapitalize="characters" aria-describedby="apeBatchHours" required>
                    </div>
                    <p class="md:col-span-2 text-xs font-bold text-slate-500 mb-0" id="apeBatchHours">Enter time using AM or PM. Clinic hours: 8:00 AM–5:00 PM. End time must be later than start time.</p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 space-y-4">
                    <div>
                        <label class="clinic-label" for="apeBatchSearch">Search Sections, Departments, or Offices</label>
                        <input class="clinic-input" id="apeBatchSearch" type="search" placeholder="Search a section, department, or office">
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs font-black uppercase tracking-wider text-slate-500 mb-0"><span id="apeBatchSelectedCount">0</span> / <span id="apeBatchLimitCount">50</span> patients selected</p>
                        <p class="text-xs font-bold text-slate-500 mb-0" id="apeBatchResultCount"></p>
                    </div>
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white" id="apeBatchCandidateList">
                        <?php foreach ($apeScheduleGroups as $group): ?>
                            <label class="flex items-center gap-3 px-4 py-3 border-b border-slate-100 last:border-0 cursor-pointer hover:bg-slate-50"
                                   data-ape-group
                                   data-category="<?= e($group['patient_category']) ?>"
                                   data-search="<?= e(strtolower($group['group_label'] . ' ' . $group['group_detail'])) ?>"
                                   data-patient-count="<?= (int) $group['patient_count'] ?>">
                                <input type="checkbox" name="group_keys[]" value="<?= e($group['group_key']) ?>" class="w-5 h-5 accent-[var(--cliniq-primary)]" data-ape-group-checkbox>
                                <span class="min-w-0 flex-1">
                                    <strong class="text-sm text-[#17261d] block truncate"><?= e($group['group_label']) ?></strong>
                                    <span class="text-xs font-bold text-slate-500"><?= e($group['group_detail']) ?></span>
                                </span>
                                <span class="text-right shrink-0">
                                    <span class="badge badge-in-progress"><?= e($group['patient_category']) ?></span>
                                    <strong class="block text-xs text-slate-600 mt-1"><?= (int) $group['patient_count'] ?> patient<?= (int) $group['patient_count'] === 1 ? '' : 's' ?></strong>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <div class="hidden px-4 py-8 text-center text-sm font-bold text-slate-500" id="apeBatchEmptyState">No available groups match this category and search.</div>
                    </div>
                    <nav class="flex items-center justify-center gap-2" aria-label="Batch group pages" id="apeBatchPagination">
                        <button type="button" class="btn btn-secondary !p-3" id="apeBatchPreviousPage" aria-label="Previous page"><span class="material-symbols-outlined">chevron_left</span></button>
                        <div class="flex items-center gap-2" id="apeBatchPagePills"></div>
                        <button type="button" class="btn btn-secondary !p-3" id="apeBatchNextPage" aria-label="Next page"><span class="material-symbols-outlined">chevron_right</span></button>
                    </nav>
                </div>

                <p class="hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-bold text-red-700" id="apeBatchFormError" role="alert"></p>
                <div class="grid grid-cols-2 gap-3">
                    <button type="button" class="btn btn-secondary justify-center" id="cancelApeBatchButton">Cancel</button>
                    <button type="submit" class="btn btn-primary justify-center">Create Batch</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (() => {
            const modal = document.getElementById('apeBatchModal');
            const openButton = document.getElementById('openApeBatchModalButton');
            const closeButton = document.getElementById('closeApeBatchModalButton');
            const cancelButton = document.getElementById('cancelApeBatchButton');
            const form = document.getElementById('apeBatchForm');
            const category = document.getElementById('apeBatchCategory');
            const search = document.getElementById('apeBatchSearch');
            const capacity = document.getElementById('apeBatchCapacity');
            const rows = Array.from(document.querySelectorAll('[data-ape-group]'));
            const checkboxes = Array.from(document.querySelectorAll('[data-ape-group-checkbox]'));
            const previous = document.getElementById('apeBatchPreviousPage');
            const next = document.getElementById('apeBatchNextPage');
            const pills = document.getElementById('apeBatchPagePills');
            const selectedCount = document.getElementById('apeBatchSelectedCount');
            const limitCount = document.getElementById('apeBatchLimitCount');
            const resultCount = document.getElementById('apeBatchResultCount');
            const emptyState = document.getElementById('apeBatchEmptyState');
            const error = document.getElementById('apeBatchFormError');
            const perPage = 5;
            let currentPage = 1;
            let previousCategory = category.value || 'Student';

            const activeRows = () => {
                const term = (search.value || '').trim().toLowerCase();
                return rows.filter((row) => row.dataset.category === category.value
                    && (!term || (row.dataset.search || '').includes(term)));
            };
            const selectedPatientTotal = () => checkboxes.reduce((total, box) => {
                if (!box.checked) return total;
                return total + Number(box.closest('[data-ape-group]')?.dataset.patientCount || 0);
            }, 0);
            const updateSelectedCount = () => {
                selectedCount.textContent = String(selectedPatientTotal());
                limitCount.textContent = String(Number(capacity.value || 0));
            };
            const render = () => {
                const matches = activeRows();
                const pageCount = Math.max(1, Math.ceil(matches.length / perPage));
                currentPage = Math.min(Math.max(1, currentPage), pageCount);
                const start = (currentPage - 1) * perPage;
                const visible = new Set(matches.slice(start, start + perPage));
                rows.forEach((row) => { row.style.display = visible.has(row) ? '' : 'none'; });
                emptyState.classList.toggle('hidden', matches.length !== 0);
                resultCount.textContent = `${matches.length} available group${matches.length === 1 ? '' : 's'}`;
                previous.disabled = currentPage <= 1;
                next.disabled = currentPage >= pageCount;
                pills.innerHTML = '';
                for (let page = 1; page <= pageCount; page += 1) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = String(page);
                    button.className = page === currentPage ? 'btn btn-primary !min-w-11 !p-3' : 'btn btn-secondary !min-w-11 !p-3';
                    button.setAttribute('aria-label', `Page ${page}`);
                    button.addEventListener('click', () => { currentPage = page; render(); });
                    pills.appendChild(button);
                }
                updateSelectedCount();
            };

            openButton?.addEventListener('click', () => {
                error.classList.add('hidden');
                render();
                showModal('apeBatchModal');
            });
            [closeButton, cancelButton].forEach((button) => button?.addEventListener('click', () => closeModal('apeBatchModal')));
            search.addEventListener('input', () => { currentPage = 1; render(); });
            category.addEventListener('change', () => {
                checkboxes.forEach((box) => {
                    if (box.closest('[data-ape-group]')?.dataset.category === previousCategory) box.checked = false;
                });
                previousCategory = category.value;
                search.value = '';
                currentPage = 1;
                render();
            });
            capacity.addEventListener('input', () => {
                updateSelectedCount();
                const overLimit = selectedPatientTotal() > Number(capacity.value || 0);
                error.textContent = overLimit ? 'The selected groups exceed the patient limit.' : '';
                error.classList.toggle('hidden', !overLimit);
            });
            checkboxes.forEach((box) => box.addEventListener('change', () => {
                const total = selectedPatientTotal();
                const limit = Number(capacity.value || 0);
                if (box.checked && total > limit) {
                    box.checked = false;
                    error.textContent = 'That complete group would exceed the patient limit.';
                    error.classList.remove('hidden');
                } else {
                    error.classList.add('hidden');
                }
                updateSelectedCount();
            }));
            previous.addEventListener('click', () => { if (currentPage > 1) { currentPage -= 1; render(); } });
            next.addEventListener('click', () => { currentPage += 1; render(); });
            const startField = document.getElementById('apeBatchStart');
            const endField = document.getElementById('apeBatchEnd');
            const parseBatchTime = (value) => {
                const match = String(value || '').trim().match(/^(0?[1-9]|1[0-2]):([0-5][0-9])\s*(AM|PM)$/i);
                if (!match) return null;
                let hour = Number(match[1]) % 12;
                if (match[3].toUpperCase() === 'PM') hour += 12;
                return (hour * 60) + Number(match[2]);
            };
            const formatBatchTime = (minutes) => {
                const hour24 = Math.floor(minutes / 60);
                const minute = minutes % 60;
                const hour12 = hour24 % 12 || 12;
                return `${hour12}:${String(minute).padStart(2, '0')} ${hour24 < 12 ? 'AM' : 'PM'}`;
            };
            const validateBatchTimes = () => {
                let message = '';
                [startField, endField].forEach((field) => {
                    const minutes = parseBatchTime(field.value);
                    let fieldMessage = '';
                    if (field.value && minutes === null) {
                        fieldMessage = 'Enter time in 12-hour format, such as 8:00 AM.';
                    } else if (minutes !== null && (minutes < 8 * 60 || minutes > 17 * 60)) {
                        fieldMessage = 'APE batch times must be between 8:00 AM and 5:00 PM.';
                    }
                    field.setCustomValidity(fieldMessage);
                    if (fieldMessage) message = fieldMessage;
                });
                const startMinutes = parseBatchTime(startField.value);
                const endMinutes = parseBatchTime(endField.value);
                if (!message && startMinutes !== null && endMinutes !== null && startMinutes >= endMinutes) {
                    message = 'The batch end time must be later than its start time.';
                    endField.setCustomValidity(message);
                }
                return message;
            };
            [startField, endField].forEach((field) => {
                field.addEventListener('input', validateBatchTimes);
                field.addEventListener('change', validateBatchTimes);
                field.addEventListener('blur', () => {
                    const minutes = parseBatchTime(field.value);
                    if (minutes !== null) field.value = formatBatchTime(minutes);
                    validateBatchTimes();
                });
            });
            validateBatchTimes();
            form.addEventListener('submit', (event) => {
                const selected = checkboxes.filter((box) => box.checked);
                const patientLimit = Number(capacity.value || 0);
                const patientTotal = selectedPatientTotal();
                const timeMessage = validateBatchTimes();
                let message = '';
                if (selected.length < 1) message = 'Select at least one section, department, or office for this batch.';
                else if (patientTotal > patientLimit) message = 'The selected groups exceed the patient limit.';
                else if (timeMessage) message = timeMessage;
                if (message) {
                    event.preventDefault();
                    error.textContent = message;
                    error.classList.remove('hidden');
                }
            });

            render();
        })();
    </script>
<?php endif; ?>

<?php render_footer(); ?>
