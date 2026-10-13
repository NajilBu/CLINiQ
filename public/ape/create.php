<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../../app/services/ApeCycleService.php';

require_login();
ensure_ape_workflow_schema();
ensure_ape_cycle_schema();

$apeUser = current_user() ?? [];
if (!in_array((string) ($apeUser['role'] ?? ''), ['admin', 'doctor', 'nurse'], true)) {
    flash_message('error', 'Only authorized clinic staff can open a staff APE record.');
    header('Location: index.php');
    exit;
}

$apeDb = auth_db();
$activeCycle = $apeDb->query("SELECT ape_cycle_id, academic_year FROM ape_cycles WHERE status = 'Active' ORDER BY started_at DESC LIMIT 1")->fetch() ?: null;
if ($activeCycle === null) {
    flash_message('error', 'Start an APE cycle before opening a Faculty or NTP APE record.');
    header('Location: index.php');
    exit;
}

$availablePatients = $apeDb->prepare("\n    SELECT pt.person_id AS id, p.id_number, p.first_name, p.last_name,\n           se.role_classification AS patient_type, COALESCE(ed.department_code, 'No department') AS department\n    FROM patients pt\n    JOIN people p ON p.id = pt.person_id\n    JOIN school_employees se ON se.person_id = p.id\n    LEFT JOIN departments ed ON ed.id = se.department_id\n    WHERE se.role_classification IN ('Faculty', 'Non-Teaching Personnel')\n      AND NOT EXISTS (\n          SELECT 1 FROM ape_records existing\n          WHERE existing.ape_cycle_id = ? AND existing.patient_id = pt.person_id\n      )\n    ORDER BY p.last_name, p.first_name\n");
$availablePatients->execute([(int) $activeCycle['ape_cycle_id']]);
$patients = $availablePatients->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $patientIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['patient_ids'] ?? [])),
            static fn(int $patientId): bool => $patientId > 0
        )));
        $staffPersonId = (int) ($apeUser['person_id'] ?? 0);
        if ($patientIds === [] || $staffPersonId <= 0) {
            throw new InvalidArgumentException('Choose at least one Faculty or NTP patient and use an active clinic account.');
        }

        $placeholders = implode(', ', array_fill(0, count($patientIds), '?'));
        $eligible = $apeDb->prepare("\n            SELECT pt.person_id FROM patients pt\n            JOIN school_employees se ON se.person_id = pt.person_id\n            WHERE pt.person_id IN ($placeholders)\n              AND se.role_classification IN ('Faculty', 'Non-Teaching Personnel')\n              AND NOT EXISTS (\n                  SELECT 1 FROM ape_records existing\n                  WHERE existing.ape_cycle_id = ? AND existing.patient_id = pt.person_id\n              )\n        ");
        $eligible->execute([...$patientIds, (int) $activeCycle['ape_cycle_id']]);
        $eligibleIds = array_map('intval', $eligible->fetchAll(PDO::FETCH_COLUMN));
        sort($eligibleIds);
        $requestedIds = $patientIds;
        sort($requestedIds);
        if ($eligibleIds !== $requestedIds) {
            throw new InvalidArgumentException('One or more selected people are no longer eligible or already have an APE record in this cycle. Refresh the list and try again.');
        }

        $apeDb->beginTransaction();
        $record = $apeDb->prepare("\n            INSERT INTO ape_records (ape_cycle_id, patient_id, academic_year, entry_mode, requirement_status, workflow_status, clearance_status, follow_up_required)\n            VALUES (?, ?, ?, 'Clinic Manual', 'Not Checked', 'Registered', 'Pending', 0)\n        ");
        $requirement = $apeDb->prepare("\n            INSERT INTO ape_requirements (ape_id, requirement_name, status, upload_group)\n            VALUES (?, 'Medical Certificate', 'Missing', 'initial')\n        ");
        foreach ($patientIds as $patientId) {
            $record->execute([(int) $activeCycle['ape_cycle_id'], $patientId, (string) $activeCycle['academic_year']]);
            $apeId = (int) $apeDb->lastInsertId();
            $requirement->execute([$apeId]);
            ape_log_activity($apeId, $staffPersonId, 'Opened staff APE record', 'Awaiting clinic-submitted medical certificate.');
        }
        $apeDb->commit();
        flash_message('success', count($patientIds) === 1
            ? 'Staff APE record opened. Attach the medical certificate to submit it.'
            : count($patientIds) . ' staff APE records opened. Attach each medical certificate from the Faculty & NTP queue.');
        header('Location: index.php?population=faculty_ntp');
        exit;
    } catch (Throwable $e) {
        if ($apeDb->inTransaction()) $apeDb->rollBack();
        flash_message('error', $e->getCode() === '23000'
            ? 'This person already has an APE record for the active academic year.'
            : ($e instanceof InvalidArgumentException ? $e->getMessage() : 'The staff APE record could not be opened. Please try again.'));
    }
}

set_page_back_link('index.php?population=faculty_ntp', 'Back');
render_header('Open Staff APE Record');
render_clinic_command_header(
    'APE Workflow',
    'Open Faculty / NTP APE',
    'Open a clinic-controlled APE record. The medical certificate is submitted separately; no schedule, examination, follow-up, or clearance decision is required.'
);
?>
<form class="clinic-card p-6 md:p-8" method="post">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div class="md:col-span-2">
            <label class="clinic-label" for="openStaffApePicker">Faculty / NTP patients</label>
            <button class="clinic-input w-full flex items-center justify-between gap-3 text-left" id="openStaffApePicker" type="button" aria-haspopup="dialog" aria-controls="staffApePickerModal">
                <span id="staffApePickerLabel">Select Faculty or NTP</span>
                <span class="material-symbols-outlined text-slate-500" aria-hidden="true">group_add</span>
            </button>
            <p class="mt-2 text-sm font-bold text-slate-500 mb-0"><span id="staffApeSelectedCount">0</span> selected. Staff with an APE record in this cycle are not shown.</p>
            <div id="staffApePatientInputs"></div>
        </div>
        <div><label class="clinic-label">Active APE cycle</label><div class="clinic-input bg-slate-50 text-slate-600"><?= e((string) $activeCycle['academic_year']) ?></div></div>
        <div><label class="clinic-label">Initial status</label><div class="clinic-input bg-slate-50 text-slate-600">Awaiting medical certificate</div></div>
    </div>
    <p class="mt-5 text-sm font-bold text-slate-500 mb-0">Only the clinic can submit or replace the certificate. The staff member will see a read-only status and their own latest certificate.</p>
    <div class="mt-3 min-h-12 rounded-xl border border-slate-200 bg-slate-50 p-3 flex flex-wrap items-center gap-2" id="selectedStaffApePills">
        <span class="text-sm font-bold text-slate-400">No staff selected.</span>
    </div>
    <div class="mt-8 flex flex-wrap gap-3">
        <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Open staff APE?" data-confirm-message="This opens a Faculty or NTP APE record for the active cycle without a schedule or examination." data-confirm-toast="Opening staff APE..."><span class="material-symbols-outlined text-[18px]">add</span> Open Staff APE</button>
        <a class="btn btn-ghost btn-cancel-icon text-decoration-none" href="index.php?population=faculty_ntp" title="Cancel" aria-label="Cancel"><span class="material-symbols-outlined">cancel</span></a>
    </div>
</form>
<div id="staffApePickerModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="staffApePickerTitle">
    <div class="modal-content bg-white rounded-[1.5rem] w-full max-w-3xl max-h-[calc(100vh-2rem)] overflow-hidden p-6 shadow-2xl border border-outline-variant/10">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <div class="w-10 h-10 rounded-xl bg-[var(--cliniq-surface-low)] text-[var(--cliniq-primary)] flex items-center justify-center mb-3"><span class="material-symbols-outlined text-[26px]">group_add</span></div>
                <h2 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1" id="staffApePickerTitle">Select Faculty and NTP</h2>
                <p class="text-sm font-bold text-slate-500 mb-0">Choose one or more people. Your selections stay selected while you search.</p>
            </div>
            <button type="button" class="btn btn-ghost justify-center px-3" id="closeStaffApePicker" aria-label="Close staff selection"><span class="material-symbols-outlined text-[20px]">close</span></button>
        </div>
        <label class="clinic-label" for="staffApePickerSearch">Search eligible staff</label>
        <input class="clinic-input mb-4" id="staffApePickerSearch" type="search" placeholder="Search name, ID, role, or department">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 max-h-[50vh] overflow-y-auto" id="staffApePickerList">
            <?php foreach ($patients as $patient): ?>
                <?php $patientLabel = $patient['last_name'] . ', ' . $patient['first_name'] . ' — ' . $patient['id_number']; ?>
                <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2 hover:bg-slate-50 cursor-pointer" data-staff-ape-option data-id="<?= (int) $patient['id'] ?>" data-name="<?= e($patientLabel) ?>" data-search="<?= e(strtolower($patientLabel . ' ' . $patient['patient_type'] . ' ' . $patient['department'])) ?>">
                    <input type="checkbox" class="w-4 h-4 accent-[var(--cliniq-primary)]">
                    <span class="min-w-0 flex-1"><strong class="block text-sm font-extrabold text-slate-800 truncate"><?= e($patientLabel) ?></strong><span class="block text-xs font-bold text-slate-500 truncate"><?= e($patient['patient_type'] . ' · ' . $patient['department']) ?></span></span>
                </label>
            <?php endforeach; ?>
            <?php if ($patients === []): ?><p class="text-sm font-bold text-slate-500 col-span-full">Every eligible Faculty and NTP patient already has an APE record for this cycle.</p><?php endif; ?>
        </div>
        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3 mt-5">
            <button type="button" class="btn btn-ghost justify-center text-slate-600" id="clearStaffApeSelection">Clear Selection</button>
            <button type="button" class="btn btn-primary justify-center" id="applyStaffApeSelection">Done</button>
        </div>
    </div>
</div>
<script>
(() => {
    const form = document.querySelector('form.clinic-card');
    const picker = document.getElementById('staffApePickerModal');
    const open = document.getElementById('openStaffApePicker');
    const close = document.getElementById('closeStaffApePicker');
    const apply = document.getElementById('applyStaffApeSelection');
    const clear = document.getElementById('clearStaffApeSelection');
    const search = document.getElementById('staffApePickerSearch');
    const rows = Array.from(document.querySelectorAll('[data-staff-ape-option]'));
    const selected = new Set();
    const inputs = document.getElementById('staffApePatientInputs');
    const pills = document.getElementById('selectedStaffApePills');
    const count = document.getElementById('staffApeSelectedCount');
    const label = document.getElementById('staffApePickerLabel');

    const render = () => {
        count.textContent = String(selected.size);
        label.textContent = selected.size === 0 ? 'Select Faculty or NTP' : `${selected.size} staff member${selected.size === 1 ? '' : 's'} selected`;
        inputs.replaceChildren(...Array.from(selected, (id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'patient_ids[]';
            input.value = id;
            return input;
        }));
        pills.replaceChildren();
        const chosen = rows.filter((row) => selected.has(row.dataset.id));
        if (chosen.length === 0) {
            pills.innerHTML = '<span class="text-sm font-bold text-slate-400">No staff selected.</span>';
            return;
        }
        chosen.forEach((row) => {
            const pill = document.createElement('span');
            pill.className = 'inline-flex max-w-full items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-extrabold text-emerald-800';
            pill.textContent = row.dataset.name;
            pills.append(pill);
        });
    };
    const filter = () => {
        const query = search.value.trim().toLowerCase();
        rows.forEach((row) => row.hidden = query !== '' && !row.dataset.search.includes(query));
    };

    rows.forEach((row) => row.querySelector('input').addEventListener('change', (event) => {
        if (event.currentTarget.checked) selected.add(row.dataset.id);
        else selected.delete(row.dataset.id);
        render();
    }));
    open.addEventListener('click', () => showModal('staffApePickerModal'));
    [close, apply].forEach((button) => button.addEventListener('click', () => closeModal('staffApePickerModal')));
    clear.addEventListener('click', () => {
        selected.clear();
        rows.forEach((row) => row.querySelector('input').checked = false);
        render();
    });
    search.addEventListener('input', filter);
    form.addEventListener('submit', (event) => {
        if (selected.size === 0) {
            event.preventDefault();
            open.focus();
            showToast('Select at least one Faculty or NTP patient.', 'warning');
        }
    });
})();
</script>
<?php render_footer(); ?>
