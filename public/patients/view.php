<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqPatientProfile.php';
require_once __DIR__ . '/../../app/services/CliniqVisitWorkflow.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$patient = cliniq_patient_profile_find($id);

if (!$patient) {
    render_header('Patient Not Found');
    ?>
    <div class="empty-state" style="min-height: 40vh;">
        <span class="material-symbols-outlined">person_off</span>
        <p class="empty-state-title">Patient not found</p>
        <p class="empty-state-text">The patient record you're looking for doesn't exist.</p>
        <a href="index.php" class="btn btn-primary mt-4 text-decoration-none">Back to Patients</a>
    </div>
    <?php
    render_footer();
    exit;
}

$fullName = trim(implode(' ', array_filter([
    $patient['first_name'] ?? '',
    $patient['middle_name'] ?? '',
    $patient['last_name'] ?? '',
])));
$visits = cliniq_patient_profile_history((int) $patient['person_id']);

ensure_ape_workflow_schema();
$apeRecords = ape_fetch_patient_records((int) $patient['person_id']);

$refStmt = auth_db()->prepare('
    SELECT r.*, TRIM(CONCAT_WS(" ", pe.first_name, pe.middle_name, pe.last_name)) AS referred_by_name
    FROM referrals r
    LEFT JOIN people pe ON pe.id = r.referred_by_person_id
    WHERE r.patient_person_id = ?
    ORDER BY r.referral_date DESC, r.referral_id DESC
');
$refStmt->execute([(int) $patient['person_id']]);
$referrals = $refStmt->fetchAll();

$latestVisit = null;
foreach ($visits as $visit) {
    if (!$latestVisit || strtotime($visit['visit_datetime']) > strtotime($latestVisit['visit_datetime'])) {
        $latestVisit = $visit;
    }
}
$attentionVisitCount = count(array_filter(
    $visits,
    fn(array $visit): bool => in_array(($visit['status'] ?? 'Unaddressed'), ['Unaddressed', 'Active'], true)
));
$birthdateLabel = $patient['birthdate'] ? date('F j, Y', strtotime($patient['birthdate'])) : 'Not specified';
$ageLabel = 'Not specified';
if ($patient['birthdate']) {
    $birthdate = new DateTime($patient['birthdate']);
    $ageLabel = $birthdate->diff(new DateTime())->y . ' years old';
}
$sexLabel = $patient['sex'] ?: 'Not specified';
$emailLabel = trim((string) ($patient['email'] ?? '')) !== '' ? trim((string) $patient['email']) : 'Not specified';
$bloodTypeLabel = $patient['blood_type'] ?: 'Not specified';
$courseLabel = $patient['course_section'] ?: $patient['patient_type'];
$lastVisitLabel = $latestVisit ? date('M d, Y g:i A', strtotime($latestVisit['visit_datetime'])) : 'No visits yet';
$affiliationLabel = 'Classification';
$affiliationValue = $patient['patient_type'];
$profileDetailLabel = 'Profile Details';
$profileDetailValue = 'No additional profile details recorded';
if ($patient['patient_type'] === 'Student') {
    $affiliationLabel = 'Program';
    $affiliationValue = trim(implode(' — ', array_filter([
        $patient['program_code'] ?? '',
        $patient['program_name'] ?? '',
    ]))) ?: 'Not specified';
    $profileDetailLabel = 'Year / Section / Academic Year';
    $profileDetailValue = trim(implode(' / ', array_filter([
        ($patient['year_level'] ?? '') !== '' ? 'Year ' . $patient['year_level'] : '',
        ($patient['section'] ?? '') !== '' ? 'Section ' . strtoupper((string) $patient['section']) : '',
        $patient['academic_year'] ?? '',
    ]))) ?: 'Not specified';
} elseif (in_array($patient['patient_type'], ['Faculty', 'School Personnel'], true)) {
    $affiliationLabel = 'Department';
    $affiliationValue = trim(implode(' — ', array_filter([
        $patient['employee_department_code'] ?? '',
        $patient['employee_department_name'] ?? '',
    ]))) ?: 'Not specified';
    $profileDetailLabel = 'Employment / Position';
    $profileDetailValue = trim(implode(' / ', array_filter([
        $patient['employment_type'] ?? '',
        $patient['employee_position_title'] ?? '',
    ]))) ?: 'Not specified';
} elseif ($patient['patient_type'] === 'Clinic Staff') {
    $affiliationLabel = 'Clinic Department';
    $affiliationValue = trim(implode(' — ', array_filter([
        $patient['staff_department_code'] ?? '',
        $patient['staff_department_name'] ?? '',
    ]))) ?: 'Not specified';
    $profileDetailLabel = 'Staff Role / Position';
    $profileDetailValue = trim(implode(' / ', array_filter([
        isset($patient['staff_role']) ? ucwords(str_replace('_', ' ', (string) $patient['staff_role'])) : '',
        $patient['staff_position_title'] ?? '',
    ]))) ?: 'Not specified';
}

render_header($fullName . ' - Patient Profile');
?>

<style>
    .patient-profile-shell {
        display: grid;
        gap: 1.5rem;
    }

    .patient-profile-card {
        border: 1px solid rgba(199, 220, 205, 0.72);
        border-radius: 1rem;
        background: #fff;
        box-shadow: 0 14px 30px rgba(15, 23, 42, 0.04);
    }

    .patient-profile-field {
        min-height: 4.15rem;
        border: 1px solid rgba(199, 220, 205, 0.75);
        border-radius: 0.8rem;
        background: #f8fbf9;
        padding: 0.85rem 1rem;
    }

    .patient-profile-field strong,
    .patient-profile-note strong {
        display: block;
        color: #0f172a;
        font-size: 0.93rem;
        line-height: 1.35;
        margin-top: 0.2rem;
    }

    .patient-profile-note {
        border: 1px solid rgba(199, 220, 205, 0.75);
        border-left: 4px solid #3f7d52;
        border-radius: 0.8rem;
        background: #fbfdfb;
        padding: 0.95rem 1rem;
    }

    .patient-profile-note.warning {
        border-left-color: #dc2626;
        background: #fffafa;
    }

    .patient-profile-timeline {
        position: relative;
    }

    .care-timeline-toolbar {
        display: grid;
        grid-template-columns: minmax(15rem, 36rem);
        gap: 0.75rem;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid rgba(226, 232, 240, 0.85);
        background: #fbfdfb;
    }

    .care-timeline-filter {
        min-width: 0;
    }

    .care-timeline-filter .clinic-input,
    .care-timeline-filter .clinic-select {
        min-height: 2.75rem;
    }

    .patient-profile-event {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: start;
        gap: 1rem;
        padding: 1.15rem 1.25rem;
        color: inherit;
        text-decoration: none;
        transition: background 0.18s ease, transform 0.18s ease;
    }

    .patient-profile-event:hover {
        background: #f8fbf9;
        transform: translateX(2px);
    }

    .patient-profile-event[hidden] {
        display: none;
    }

    .patient-profile-event.is-collapsed [data-care-details] {
        display: none;
    }

    .patient-profile-event-content {
        display: block;
        min-width: 0;
        color: #0f172a;
        overflow: visible;
    }

    .patient-profile-event-content h3 {
        display: block;
        color: #0f172a;
        line-height: 1.35;
        overflow-wrap: anywhere;
    }

    .patient-profile-event-meta {
        min-width: 7.5rem;
        justify-self: end;
        text-align: right;
        color: #475569;
    }

    .care-timeline-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.3rem;
        min-height: 2rem;
        border: 0;
        border-radius: 0.65rem;
        background: var(--cliniq-primary-fixed);
        color: var(--cliniq-primary-hover);
        padding: 0.35rem 0.55rem;
        font-size: 0.72rem;
        font-weight: 900;
        cursor: pointer;
        transition: background-color 0.16s ease, color 0.16s ease;
    }

    .care-timeline-toggle:hover {
        background: color-mix(in srgb, var(--cliniq-primary) 14%, #ffffff);
        color: var(--cliniq-primary-hover);
    }

    .care-timeline-toggle .material-symbols-outlined {
        font-size: 1.05rem;
        transition: transform 0.18s ease;
    }

    .patient-profile-event.is-collapsed .care-timeline-toggle .material-symbols-outlined {
        transform: rotate(180deg);
    }

    .care-timeline-pagination {
        min-height: 4.75rem;
        border-top: 1px solid rgba(226, 232, 240, 0.85);
    }

    .patient-profile-event-icon {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.8rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--cliniq-primary-fixed);
        color: var(--cliniq-primary);
        flex-shrink: 0;
    }

    .patient-profile-event-icon.alert {
        background: #fef2f2;
        color: #dc2626;
    }

    @media (max-width: 720px) {
        .care-timeline-toolbar {
            grid-template-columns: 1fr;
        }

        .patient-profile-event {
            grid-template-columns: auto minmax(0, 1fr);
        }

        .patient-profile-event-meta {
            grid-column: 2;
            justify-self: start;
            text-align: left;
        }
    }
</style>

<div class="patient-profile-shell">
    <section class="clinic-card p-5 md:p-6">
        <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-5">
            <div class="flex items-center gap-4 min-w-0">
                <div class="avatar w-16 h-16 text-xl <?= avatar_color($fullName) ?> shrink-0"><?= initials($fullName) ?></div>
                <div class="min-w-0">
                    <p class="text-[11px] font-black text-primary uppercase tracking-widest mb-1">Patient Profile</p>
                    <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#17261d] leading-tight m-0"><?= e($fullName) ?></h1>
                    <p class="text-sm font-bold text-slate-500 mt-1">
                        <?= e($patient['id_number']) ?> &bull; <?= e($courseLabel) ?>
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <a class="btn btn-outline text-decoration-none" href="edit.php?id=<?= $id ?>">
                    <span class="material-symbols-outlined text-[18px]">edit</span>
                    Edit Profile
                </a>
                <a class="btn btn-primary text-decoration-none" href="<?= app_url('visits/create.php?patient_id=' . $id) ?>">
                    <span class="material-symbols-outlined text-[18px]">add_notes</span>
                    Record Visit
                </a>
            </div>
        </div>
    </section>

    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="patient-profile-card p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-primary-fixed text-primary flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined">clinical_notes</span>
            </div>
            <div>
                <p class="clinic-label mb-1">Total Visits</p>
                <p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0"><?= count($visits) ?></p>
            </div>
        </div>
        <div class="patient-profile-card p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined">schedule</span>
            </div>
            <div class="min-w-0">
                <p class="clinic-label mb-1">Latest Visit</p>
                <p class="text-sm font-extrabold text-slate-800 leading-snug m-0"><?= e($lastVisitLabel) ?></p>
            </div>
        </div>
        <div class="patient-profile-card p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined">priority_high</span>
            </div>
            <div>
                <p class="clinic-label mb-1">Needs Attention</p>
                <p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0"><?= $attentionVisitCount ?></p>
            </div>
        </div>
        <div class="patient-profile-card p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined">folder_open</span>
            </div>
            <div>
                <p class="clinic-label mb-1">Documents</p>
                <p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0"><?= count($apeRecords) + count($referrals) ?></p>
            </div>
        </div>
    </section>

    <section class="clinic-card overflow-hidden">
        <div class="p-5 md:p-6 border-b border-slate-100 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined">health_and_safety</span>
                </div>
                <div>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Clinical Snapshot</h2>
                    <p class="text-xs font-bold text-slate-500 m-0">Core identity, emergency details, and health alerts.</p>
                </div>
            </div>
            <span class="badge badge-cancelled">
                <span class="material-symbols-outlined text-[14px]">lock</span>
                Private
            </span>
        </div>

        <div class="p-5 md:p-6 grid grid-cols-1 xl:grid-cols-[1.05fr_0.95fr] gap-5">
            <div class="patient-profile-card p-5">
                <div class="flex items-center gap-2 mb-5">
                    <span class="material-symbols-outlined text-primary">person</span>
                    <h3 class="font-headline text-lg font-extrabold text-slate-900 m-0">Patient Information</h3>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="patient-profile-field">
                        <span class="clinic-label">Birthdate</span>
                        <strong><?= e($birthdateLabel) ?></strong>
                    </div>
                    <div class="patient-profile-field">
                        <span class="clinic-label">Age</span>
                        <strong><?= e($ageLabel) ?></strong>
                    </div>
                    <div class="patient-profile-field">
                        <span class="clinic-label">Sex</span>
                        <strong><?= e($sexLabel) ?></strong>
                    </div>
                    <div class="patient-profile-field">
                        <span class="clinic-label">Blood Type</span>
                        <strong><?= e($bloodTypeLabel) ?></strong>
                    </div>
                    <div class="patient-profile-field md:col-span-2">
                        <span class="clinic-label">Email</span>
                        <strong><?= e($emailLabel) ?></strong>
                    </div>
                    <div class="patient-profile-field md:col-span-2">
                        <span class="clinic-label">Guardian</span>
                        <strong>
                            <?= e($patient['guardian_name'] ?: 'Not specified') ?>
                            <?php if ($patient['guardian_contact']): ?>
                                <span class="text-slate-400">&bull;</span> <?= e($patient['guardian_contact']) ?>
                            <?php endif; ?>
                        </strong>
                    </div>
                </div>
            </div>

            <div class="patient-profile-card p-5">
                <div class="flex items-center gap-2 mb-5">
                    <span class="material-symbols-outlined text-primary">badge</span>
                    <h3 class="font-headline text-lg font-extrabold text-slate-900 m-0">School / Employment Profile</h3>
                </div>
                <div class="grid grid-cols-1 gap-4">
                    <div class="patient-profile-note">
                        <span class="clinic-label">Patient Classification</span>
                        <strong><?= e($patient['patient_type']) ?></strong>
                    </div>
                    <div class="patient-profile-note">
                        <span class="clinic-label"><?= e($affiliationLabel) ?></span>
                        <strong><?= e($affiliationValue) ?></strong>
                    </div>
                    <div class="patient-profile-note">
                        <span class="clinic-label"><?= e($profileDetailLabel) ?></span>
                        <strong><?= e($profileDetailValue) ?></strong>
                    </div>
                    <div class="patient-profile-note">
                        <span class="clinic-label">Account Status / Emergency Instructions</span>
                        <strong><?= e(ucfirst((string) ($patient['account_status'] ?: 'Not specified'))) ?></strong>
                        <strong class="whitespace-pre-wrap text-slate-600"><?= e(trim((string) $patient['emergency_instructions']) !== '' ? $patient['emergency_instructions'] : 'No emergency instructions recorded') ?></strong>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($patient['height_cm']) || !empty($patient['weight_kg']) || !empty($patient['temperature']) || !empty($patient['blood_pressure']) || !empty($patient['pulse_rate'])): ?>
            <div class="patient-profile-card p-5 xl:col-span-2">
                <div class="flex items-center gap-2 mb-5">
                    <span class="material-symbols-outlined text-primary">monitor_heart</span>
                    <h3 class="font-headline text-lg font-extrabold text-slate-900 m-0">Standing Vitals & Measurements</h3>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
                    <?php if (!empty($patient['height_cm'])): ?>
                    <div class="patient-profile-field">
                        <span class="clinic-label">Height</span>
                        <strong><?= e($patient['height_cm']) ?> cm</strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($patient['weight_kg'])): ?>
                    <div class="patient-profile-field">
                        <span class="clinic-label">Weight</span>
                        <strong><?= e($patient['weight_kg']) ?> kg</strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($patient['bmi'])): ?>
                    <div class="patient-profile-field">
                        <span class="clinic-label">BMI</span>
                        <strong><?= e($patient['bmi']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($patient['temperature'])): ?>
                    <div class="patient-profile-field">
                        <span class="clinic-label">Temperature</span>
                        <strong><?= e(number_format((float) $patient['temperature'], 2)) ?> °C</strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($patient['blood_pressure'])): ?>
                    <div class="patient-profile-field">
                        <span class="clinic-label">Blood Pressure</span>
                        <strong><?= e($patient['blood_pressure']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($patient['pulse_rate'])): ?>
                    <div class="patient-profile-field">
                        <span class="clinic-label">Pulse Rate</span>
                        <strong><?= e($patient['pulse_rate']) ?> bpm</strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="clinic-card overflow-hidden">
        <div class="p-5 md:p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined">timeline</span>
                </div>
                <div>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Care Timeline</h2>
                    <p class="text-xs font-bold text-slate-500 m-0"><?= count($visits) ?> visit record(s), newest records shown first.</p>
                </div>
            </div>
            <a class="btn btn-sm btn-outline text-decoration-none" href="<?= app_url('visits/index.php?q=' . rawurlencode((string) $patient['id_number']) . '&status=all') ?>">
                <span class="material-symbols-outlined text-[16px]">list</span>
                Clinic Logbook
            </a>
        </div>

        <?php if ($visits): ?>
            <div class="care-timeline-toolbar" data-care-filters>
                <label class="care-timeline-filter">
                    <span class="clinic-label">Search records</span>
                    <input type="search" class="clinic-input" data-care-search placeholder="Complaint, diagnosis, treatment, medicine...">
                </label>
            </div>
            <div class="patient-profile-timeline divide-y divide-outline-variant/10" data-care-timeline>
                <?php foreach ($visits as $visitIndex => $visit): ?>
                    <?php
                    $visitStatus = $visit['status'] ?? 'Unaddressed';
                    $visitPurpose = $visit['visit_purpose'] ?: 'General Visit';
                    $visitSource = $visit['visit_source'] ?: 'Staff Recorded';
                    $isSelfLogbookVisit = strcasecmp((string) $visitSource, 'Self Logbook') === 0;
                    $latestSymptoms = $visit['entries'][0]['symptoms'] ?? '';
                    $allVisitVitals = $visit['vitals'] ?? [];
                    foreach ($visit['entries'] as $entry) {
                        $allVisitVitals = array_merge($allVisitVitals, $entry['vitals'] ?? []);
                    }
                    $vitalBits = [];
                    $latestVital = $allVisitVitals[0] ?? null;
                    if ($latestVital) {
                        if ($latestVital['temperature'] !== null && $latestVital['temperature'] !== '') {
                            $vitalBits[] = 'Temp ' . $latestVital['temperature'] . ' C';
                        }
                        if ($latestVital['blood_pressure']) {
                            $vitalBits[] = 'BP ' . $latestVital['blood_pressure'];
                        }
                        if ($latestVital['pulse_rate']) {
                            $vitalBits[] = 'Pulse ' . (int) $latestVital['pulse_rate'] . ' BPM';
                        }
                    }
                    $visitSearchText = strtolower(implode(' ', [
                        (string) ($visit['chief_complaint'] ?? ''),
                        (string) $visitPurpose,
                        (string) $visitSource,
                        (string) $visitStatus,
                        (string) ($visit['action_taken'] ?? ''),
                        (string) ($visit['recorded_by_name'] ?? ''),
                        (string) ($visit['attended_by_name'] ?? ''),
                        (string) json_encode($visit['entries'] ?? [], JSON_UNESCAPED_UNICODE),
                    ]));
                    ?>
                    <article class="patient-profile-event <?= $visitIndex === 0 ? '' : 'is-collapsed' ?>"
                             data-care-event
                             data-care-search-text="<?= e($visitSearchText) ?>"
                             data-care-status-value="<?= e(strtolower((string) $visitStatus)) ?>"
                             data-care-date-value="<?= e(date('Y-m-d', strtotime($visit['visit_datetime']))) ?>">
                        <div class="patient-profile-event-icon">
                            <span class="material-symbols-outlined">medical_information</span>
                        </div>
                        <div class="patient-profile-event-content">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="badge <?= visit_status_badge_class($visitStatus) ?>"><?= e($visitStatus) ?></span>
                                <span class="text-[11px] font-black uppercase tracking-widest text-slate-400"><?= e($visitPurpose) ?> / <?= e($visitSource) ?></span>
                            </div>
                            <h3 class="text-base font-extrabold text-slate-900 mb-1"><?= e($visit['chief_complaint'] ?: 'No complaint recorded') ?></h3>
                            <div data-care-details>
                            <?php if ($latestSymptoms): ?>
                                <p class="text-sm font-bold text-slate-500 mb-2 whitespace-pre-wrap"><?= e($latestSymptoms) ?></p>
                            <?php endif; ?>
                            <?php if ($vitalBits): ?>
                                <p class="text-xs font-bold text-slate-500 mb-2"><?= e(implode(' / ', $vitalBits)) ?></p>
                            <?php endif; ?>
                            <div class="flex flex-wrap gap-2 text-xs font-bold text-slate-400">
                                <span><?= $isSelfLogbookVisit ? 'Submitted by patient' : 'Recorded by ' . e($visit['recorded_by_name'] ?: 'System') ?></span>
                                <span>&bull;</span>
                                <span>Attended by <?= e($visit['attended_by_name'] ?: 'Not assigned') ?></span>
                            </div>
                            <?php if ($visit['entries']): ?>
                                <div class="grid gap-3 mt-4">
                                    <?php foreach ($visit['entries'] as $entry): ?>
                                        <?php
                                        $entrySymptomsValue = cliniq_visit_extract_patient_concerns((string) ($entry['symptoms'] ?? ''));
                                        $isPatientSubmittedEntry = $isSelfLogbookVisit
                                            && trim((string) ($entry['addressed_by_name'] ?? '')) === ''
                                            && $entrySymptomsValue !== '';
                                        ?>
                                        <section class="rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                                            <div class="flex flex-wrap items-center justify-end gap-2 mb-3">
                                                <p class="text-[11px] font-bold text-slate-400 m-0">
                                                    <?= e(date('M d, Y g:i A', strtotime($entry['created_at']))) ?>
                                                </p>
                                            </div>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                <?php foreach ([
                                                    ($isPatientSubmittedEntry ? 'Patient Concerns' : 'Symptoms') => $entrySymptomsValue,
                                                    'Diagnosis' => $entry['diagnosis'],
                                                    'Treatment' => $entry['treatment'],
                                                    'Referral' => $entry['referral'],
                                                    'Remarks' => $entry['remarks'],
                                                    'Amendment Reason' => $entry['amendment_reason'],
                                                ] as $entryLabel => $entryValue): ?>
                                                    <?php if (trim((string) $entryValue) !== ''): ?>
                                                        <div class="patient-profile-field">
                                                            <span class="clinic-label"><?= e($entryLabel) ?></span>
                                                            <strong class="whitespace-pre-wrap"><?= e($entryValue) ?></strong>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>

                                            <?php if ($entry['vitals']): ?>
                                                <div class="mt-3">
                                                    <p class="clinic-label mb-2">Vital Signs</p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <?php foreach ($entry['vitals'] as $vital): ?>
                                                            <?php
                                                            $entryVitalBits = [];
                                                            if ($vital['temperature'] !== null && $vital['temperature'] !== '') $entryVitalBits[] = 'Temp ' . $vital['temperature'] . ' C';
                                                            if ($vital['blood_pressure']) $entryVitalBits[] = 'BP ' . $vital['blood_pressure'];
                                                            if ($vital['pulse_rate']) $entryVitalBits[] = 'Pulse ' . (int) $vital['pulse_rate'] . ' BPM';
                                                            ?>
                                                            <span class="badge badge-pending"><?= e(implode(' / ', $entryVitalBits) ?: 'Vitals recorded') ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($entry['dispensings']): ?>
                                                <div class="mt-3">
                                                    <p class="clinic-label mb-2">Medicines Dispensed</p>
                                                    <div class="grid gap-2">
                                                        <?php foreach ($entry['dispensings'] as $dispensing): ?>
                                                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white border border-slate-200 px-3 py-2">
                                                                <strong class="text-sm text-slate-700"><?= e($dispensing['item_name']) ?> &bull; <?= (int) $dispensing['quantity'] ?> <?= e($dispensing['unit']) ?></strong>
                                                                <span class="text-[11px] font-bold text-slate-400"><?= e($dispensing['dispensed_by_name'] ?: 'Clinic staff') ?></span>
                                                                <?php if (trim((string) $dispensing['remarks']) !== ''): ?>
                                                                    <p class="w-full text-xs font-bold text-slate-500 m-0 whitespace-pre-wrap"><?= e($dispensing['remarks']) ?></p>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </section>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif (!$visit['vitals'] && !$visit['action_taken']): ?>
                                <div class="mt-3 rounded-xl border border-dashed border-slate-200 px-4 py-3 text-xs font-bold text-slate-400">
                                    No clinical entry or vital signs have been added to this visit yet.
                                </div>
                            <?php endif; ?>

                            <?php if ($visit['vitals']): ?>
                                <div class="mt-3">
                                    <p class="clinic-label mb-2">Visit-level Vital Signs</p>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($visit['vitals'] as $vital): ?>
                                            <?php
                                            $visitVitalBits = [];
                                            if ($vital['temperature'] !== null && $vital['temperature'] !== '') $visitVitalBits[] = 'Temp ' . $vital['temperature'] . ' C';
                                            if ($vital['blood_pressure']) $visitVitalBits[] = 'BP ' . $vital['blood_pressure'];
                                            if ($vital['pulse_rate']) $visitVitalBits[] = 'Pulse ' . (int) $vital['pulse_rate'] . ' BPM';
                                            ?>
                                            <span class="badge badge-pending"><?= e(implode(' / ', $visitVitalBits) ?: 'Vitals recorded') ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            </div>
                        </div>
                        <div class="patient-profile-event-meta">
                            <p class="text-xs font-extrabold text-slate-500 mb-0"><?= e(date('M d, Y', strtotime($visit['visit_datetime']))) ?></p>
                            <p class="text-[11px] font-bold text-slate-400 mb-2"><?= e(date('g:i A', strtotime($visit['visit_datetime']))) ?></p>
                            <button type="button" class="care-timeline-toggle mb-2" data-care-toggle aria-expanded="<?= $visitIndex === 0 ? 'true' : 'false' ?>">
                                <span data-care-toggle-label><?= $visitIndex === 0 ? 'Collapse' : 'Expand' ?></span>
                                <span class="material-symbols-outlined" aria-hidden="true">expand_less</span>
                            </button>
                            <a href="<?= e(app_url('visits/view.php?id=' . (int) $visit['id'] . '&from=profile')) ?>" class="text-xs font-black text-primary inline-flex items-center gap-1 text-decoration-none">
                                Open record
                                <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="hidden px-5 py-8 text-center" data-care-no-results>
                <span class="material-symbols-outlined text-4xl text-slate-300">search_off</span>
                <p class="text-sm font-extrabold text-slate-600 mt-2 mb-1">No matching visits</p>
                <p class="text-xs font-bold text-slate-400 m-0">Change or clear the Care Timeline search.</p>
            </div>
            <div class="care-timeline-pagination pagination justify-center" data-care-pagination aria-label="Care Timeline pages"></div>
        <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-outlined">clinical_notes</span>
                <p class="empty-state-title">No visits recorded</p>
                <p class="empty-state-text">This patient has no clinic visit history yet.</p>
            </div>
        <?php endif; ?>
    </section>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <section class="clinic-card overflow-hidden">
            <div class="p-5 md:p-6 border-b border-slate-100 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <span class="material-symbols-outlined">description</span>
                    </div>
                    <div>
                        <h2 class="font-headline text-lg font-extrabold text-[#17261d] m-0">APE Documents</h2>
                        <p class="text-xs font-bold text-slate-500 m-0">Annual physical exam records and clearance status.</p>
                    </div>
                </div>
                <span class="badge badge-pending"><?= count($apeRecords) ?> record(s)</span>
            </div>
            <?php if ($apeRecords): ?>
                <div class="divide-y divide-outline-variant/10">
                    <?php foreach ($apeRecords as $ape): ?>
                        <a href="<?= e(app_url('ape/view.php?id=' . (int) $ape['id'])) ?>" class="p-5 flex items-center justify-between gap-4 text-decoration-none hover:bg-slate-50/70 transition-colors">
                            <div class="min-w-0">
                                <p class="text-sm font-extrabold text-slate-800 mb-1"><?= e($ape['document_type'] ?: 'APE Form') ?></p>
                                <p class="text-xs font-bold text-slate-400 m-0">
                                    <?= $ape['exam_date'] ? e(date('M d, Y', strtotime($ape['exam_date']))) : 'No exam date' ?>
                                    <?php if ($ape['verified_by_name']): ?>
                                        &bull; Verified by <?= e($ape['verified_by_name']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <span class="badge <?= status_badge_class($ape['workflow_status'] ?: $ape['verification_status']) ?>">
                                    <?= e($ape['workflow_status'] ?: $ape['verification_status']) ?>
                                </span>
                                <span class="text-xs font-black text-primary inline-flex items-center gap-1">
                                    Open progress
                                    <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                                </span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-outlined">description</span>
                    <p class="empty-state-title">No APE documents</p>
                    <p class="empty-state-text">APE records will appear here once submitted.</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="clinic-card overflow-hidden">
            <div class="p-5 md:p-6 border-b border-slate-100 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                        <span class="material-symbols-outlined">send</span>
                    </div>
                    <div>
                        <h2 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Referrals</h2>
                        <p class="text-xs font-bold text-slate-500 m-0">Outside care requests and referral outcomes.</p>
                    </div>
                </div>
                <span class="badge badge-pending"><?= count($referrals) ?> record(s)</span>
            </div>
            <?php if ($referrals): ?>
                <div class="divide-y divide-outline-variant/10">
                    <?php foreach ($referrals as $ref): ?>
                        <div class="p-5 flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-sm font-extrabold text-slate-800 mb-1"><?= e($ref['referred_to']) ?></p>
                                <p class="text-xs font-bold text-slate-500 mb-1"><?= e($ref['reason']) ?></p>
                                <p class="text-xs font-bold text-slate-400 m-0"><?= e(date('M d, Y', strtotime($ref['referral_date']))) ?></p>
                            </div>
                            <span class="badge <?= status_badge_class($ref['status']) ?>"><?= e($ref['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-outlined">send</span>
                    <p class="empty-state-title">No referrals</p>
                    <p class="empty-state-text">Referral records will appear here when clinic staff creates one.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
(function () {
    const timeline = document.querySelector('[data-care-timeline]');
    if (!timeline) return;

    const pageSize = 5;
    const events = Array.from(timeline.querySelectorAll('[data-care-event]'));
    const searchInput = document.querySelector('[data-care-search]');
    const pagination = document.querySelector('[data-care-pagination]');
    const noResults = document.querySelector('[data-care-no-results]');
    let currentPage = 1;

    function normalized(value) {
        return String(value || '').trim().toLocaleLowerCase();
    }

    function matchingEvents() {
        const query = normalized(searchInput && searchInput.value);

        return events.filter((event) => {
            return !query || normalized(event.dataset.careSearchText).includes(query);
        });
    }

    function renderPagination(totalPages) {
        if (!pagination) return;

        let markup = `<button type="button" class="pagination-arrow${currentPage === 1 ? ' page-disabled' : ''}" data-care-page-action="previous" aria-label="Previous page" ${currentPage === 1 ? 'disabled' : ''}>&lsaquo;</button>`;
        for (let page = 1; page <= totalPages; page += 1) {
            const active = page === currentPage;
            markup += `<button type="button" data-care-page="${page}"${active ? ' class="page-active" aria-current="page"' : ''}>${page}</button>`;
        }
        markup += `<button type="button" class="pagination-arrow${currentPage === totalPages ? ' page-disabled' : ''}" data-care-page-action="next" aria-label="Next page" ${currentPage === totalPages ? 'disabled' : ''}>&rsaquo;</button>`;
        pagination.innerHTML = markup;
    }

    function applyTimelineFilters() {
        const matches = matchingEvents();
        const totalPages = Math.max(1, Math.ceil(matches.length / pageSize));
        currentPage = Math.min(Math.max(1, currentPage), totalPages);
        const visibleStart = (currentPage - 1) * pageSize;
        const visibleEvents = new Set(matches.slice(visibleStart, visibleStart + pageSize));

        events.forEach((event) => {
            event.hidden = !visibleEvents.has(event);
        });

        if (noResults) {
            noResults.classList.toggle('hidden', matches.length !== 0);
        }
        renderPagination(totalPages);
    }

    function resetToFirstPage() {
        currentPage = 1;
        applyTimelineFilters();
    }

    if (searchInput) {
        searchInput.addEventListener('input', resetToFirstPage);
    }

    timeline.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-care-toggle]');
        if (!toggle) return;

        const visit = toggle.closest('[data-care-event]');
        if (!visit) return;

        const collapsed = visit.classList.toggle('is-collapsed');
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        const label = toggle.querySelector('[data-care-toggle-label]');
        if (label) label.textContent = collapsed ? 'Expand' : 'Collapse';
    });

    if (pagination) {
        pagination.addEventListener('click', (event) => {
            const button = event.target.closest('button');
            if (!button || button.disabled) return;

            if (button.dataset.carePage) {
                currentPage = Number(button.dataset.carePage);
            } else if (button.dataset.carePageAction === 'previous') {
                currentPage = Math.max(1, currentPage - 1);
            } else if (button.dataset.carePageAction === 'next') {
                currentPage += 1;
            }
            applyTimelineFilters();
            timeline.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    applyTimelineFilters();
})();
</script>

<?php render_footer(); ?>
