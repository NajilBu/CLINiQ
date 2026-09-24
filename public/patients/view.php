<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqPatientProfile.php';
require_once __DIR__ . '/../../app/services/CliniqVisitWorkflow.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../../app/services/AlertWorkflow.php';
require_once __DIR__ . '/../../app/services/PatientEmail.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
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

$user = current_user() ?? [];
$canEmailPatient = in_array($user['role'] ?? '', ['admin', 'doctor', 'it_expert'], true)
    && filter_var(trim((string) ($patient['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$patientMailConfigured = mail_settings_configured();

$fullName = trim(implode(' ', array_filter([
    $patient['first_name'] ?? '',
    $patient['middle_name'] ?? '',
    $patient['last_name'] ?? '',
])));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_patient_email') {
    try {
        if (!$canEmailPatient) {
            throw new InvalidArgumentException('You do not have permission to email this patient, or the patient email is invalid.');
        }
        if (!$patientMailConfigured) {
            throw new InvalidArgumentException('Configure the clinic email before sending a message.');
        }

        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > 180) {
            throw new InvalidArgumentException('Enter a subject containing no more than 180 characters.');
        }
        if ($message === '' || mb_strlen($message) > 10000) {
            throw new InvalidArgumentException('Enter a message containing no more than 10,000 characters.');
        }

        $emailId = patient_email_dispatch_event([
            'patient_person_id' => (int) $patient['person_id'],
            'event_type' => 'manual_patient_email',
            'origin' => 'manual',
            'created_by_person_id' => (int) ($user['person_id'] ?? 0),
            'subject' => $subject,
            'message' => $message,
            'deliver_now' => true,
        ]);
        $result = $emailId > 0 ? auth_db()->prepare('SELECT status FROM email_queue WHERE id = ? LIMIT 1') : null;
        if ($result) { $result->execute([$emailId]); }
        $status = $result ? (string) $result->fetchColumn() : 'blocked';
        $sent = $status === 'sent';
        audit_log_event('email', $sent ? 'email_sent_manually' : 'email_manual_send_failed', (int) ($user['person_id'] ?? 0) ?: null, 'staff', 'patient', (int) $patient['person_id'], ['email_id' => $emailId], $sent ? 'success' : 'failure');
        flash_message(
            $sent ? 'success' : 'error',
            $sent ? 'Email sent to ' . $fullName . '.' : 'The email could not be delivered. Check the SMTP configuration.'
        );
    } catch (Throwable $e) {
        flash_message(
            $e instanceof InvalidArgumentException ? 'warning' : 'error',
            $e instanceof InvalidArgumentException ? $e->getMessage() : 'The email could not be sent.'
        );
    }

    header('Location: view.php?id=' . (int) $patient['person_id']);
    exit;
}

$visits = cliniq_patient_profile_history((int) $patient['person_id']);

ensure_ape_workflow_schema();
ensure_alert_workflow_schema();
$alertStmt = auth_db()->prepare('
    SELECT a.*,
           TRIM(CONCAT_WS(" ", rp.first_name, rp.middle_name, rp.last_name)) AS resolved_by_name
    FROM nurse_alerts a
    LEFT JOIN people rp ON rp.id = a.resolved_by
    WHERE a.patient_id = ?
    ORDER BY a.created_at DESC, a.id DESC
');
$alertStmt->execute([(int) $patient['person_id']]);
$alertReports = $alertStmt->fetchAll();
$apeRecords = ape_fetch_patient_records((int) $patient['person_id']);
$latestApeRecord = $apeRecords[0] ?? null;
$completedApeRecords = array_values(array_filter(
    $apeRecords,
    static fn(array $apeRecord): bool => ape_record_queue($apeRecord) === 'completed'
));
$documentCount = array_sum(array_map(
    static fn(array $apeRecord): int => (int) ($apeRecord['document_count'] ?? 0),
    $apeRecords
));

$refStmt = auth_db()->prepare('
    SELECT r.*, TRIM(CONCAT_WS(" ", pe.first_name, pe.middle_name, pe.last_name)) AS referred_by_name
    FROM referrals r
    LEFT JOIN people pe ON pe.id = r.referred_by_person_id
    WHERE r.patient_person_id = ?
    ORDER BY r.referral_date DESC, r.referral_id DESC
');
$refStmt->execute([(int) $patient['person_id']]);
$referrals = $refStmt->fetchAll();

ensure_appointment_schema();
$appointmentStmt = appointment_db()->prepare('
    SELECT appointment_id, appointment_datetime, purpose, status, notes, cancellation_reason, created_at
    FROM appointments
    WHERE patient_id = ?
    ORDER BY appointment_datetime ASC, created_at DESC
');
$appointmentStmt->execute([(int) $patient['person_id']]);
$appointments = $appointmentStmt->fetchAll();
$nowTimestamp = time();
$upcomingAppointments = [];
$appointmentHistory = [];
foreach ($appointments as $appointment) {
    $appointmentTimestamp = strtotime((string) ($appointment['appointment_datetime'] ?? '')) ?: 0;
    $isPastStatus = in_array((string) ($appointment['status'] ?? ''), ['Completed', 'Cancelled', 'No Show'], true);
    if (!$isPastStatus && $appointmentTimestamp >= $nowTimestamp) {
        $upcomingAppointments[] = $appointment;
    } else {
        $appointmentHistory[] = $appointment;
    }
}
usort($appointmentHistory, static function (array $left, array $right): int {
    return (strtotime((string) ($right['appointment_datetime'] ?? '')) ?: 0)
        <=> (strtotime((string) ($left['appointment_datetime'] ?? '')) ?: 0);
});
$nextAppointment = $upcomingAppointments[0] ?? null;

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
} elseif (in_array($patient['patient_type'], ['Faculty', 'Non-Teaching Personnel'], true)) {
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
$latestApeStoredResult = $latestApeRecord ? (string) ($latestApeRecord['result_status'] ?? 'Pending') : 'No APE recorded';
$latestApeResult = in_array($latestApeStoredResult, ['Normal', 'With Finding'], true) ? 'Examined' : $latestApeStoredResult;
$latestApeNotes = trim((string) ($latestApeRecord['result_notes'] ?? ''));
$latestApeDate = $latestApeRecord && !empty($latestApeRecord['exam_date'])
    ? date('M j, Y', strtotime((string) $latestApeRecord['exam_date']))
    : 'Not examined yet';
$apeWorkflowLabel = $latestApeRecord
    ? trim((string) ($latestApeRecord['clearance_status'] ?? $latestApeRecord['workflow_status'] ?? 'Pending'))
    : 'Pending';
$declaredHealthItems = [
    'Allergies' => trim((string) ($patient['allergies'] ?? '')),
    'Existing conditions' => trim((string) ($patient['existing_conditions'] ?? '')),
    'Medications' => trim((string) ($patient['medications'] ?? '')),
];
$hasDeclaredHealth = (bool) array_filter($declaredHealthItems);

render_header($fullName . ' - Patient Profile');
?>

<style>
    .patient-profile-shell {
        display: grid;
        gap: 1.5rem;
    }

    .patient-profile-tabs {
        display: flex;
        gap: 0.45rem;
        overflow-x: auto;
        padding: 0.35rem;
        border: 1px solid rgba(199, 220, 205, 0.75);
        border-radius: 1rem;
        background: #f8fbf9;
        scrollbar-width: thin;
    }

    .patient-profile-tab {
        flex: 0 0 auto;
        min-height: 2.7rem;
        padding: 0.65rem 1rem;
        border: 0;
        border-radius: 0.75rem;
        background: transparent;
        color: #64748b;
        font-size: 0.78rem;
        font-weight: 900;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        cursor: pointer;
    }

    .patient-profile-tab:hover,
    .patient-profile-tab:focus-visible {
        color: var(--cliniq-primary-hover);
        outline: 2px solid color-mix(in srgb, var(--cliniq-primary) 28%, transparent);
        outline-offset: 2px;
    }

    .patient-profile-tab[aria-selected="true"] {
        background: var(--cliniq-primary);
        color: #fff;
        box-shadow: 0 5px 12px rgba(63, 125, 82, 0.2);
    }

    [data-profile-panel][hidden] {
        display: none !important;
    }

    .profile-appointment-card {
        border: 1px solid rgba(199, 220, 205, 0.75);
        border-radius: 0.9rem;
        background: #fbfdfb;
        padding: 1rem;
    }

    .profile-appointment-card + .profile-appointment-card {
        border-top-left-radius: 0;
        border-top-right-radius: 0;
        margin-top: -1px;
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
                <?php $patientPhotoPath = profile_photo_normalize_path($patient['profile_photo_path'] ?? null); ?>
                <div class="avatar w-16 h-16 text-xl <?= avatar_color($fullName) ?> shrink-0 overflow-hidden">
                    <?php if ($patientPhotoPath !== null): ?>
                        <img class="profile-photo-cover" src="<?= e(app_url($patientPhotoPath)) ?>" alt="<?= e($fullName) ?> profile picture">
                    <?php else: ?>
                        <?= initials($fullName) ?>
                    <?php endif; ?>
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-black text-primary uppercase tracking-widest mb-1">Patient Profile</p>
                    <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#17261d] leading-tight m-0"><?= e($fullName) ?></h1>
                    <p class="text-sm font-bold text-slate-500 mt-1">
                        <?= e($patient['id_number']) ?> &bull; <?= e($courseLabel) ?>
                    </p>
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-bold text-slate-500">
                        <span><?= e($emailLabel) ?></span>
                        <span><?= e(ucfirst((string) ($patient['account_status'] ?: 'Status not specified'))) ?></span>
                        <span>Emergency: <?= e($patient['guardian_name'] ?: 'Not specified') ?></span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <a class="btn btn-outline text-decoration-none" href="edit.php?id=<?= $id ?>">
                    <span class="material-symbols-outlined text-[18px]">edit</span>
                    Edit Profile
                </a>
                <?php if ($canEmailPatient): ?>
                    <button type="button" class="btn btn-outline" id="openPatientEmailComposerButton" title="Email this patient">
                        <span class="material-symbols-outlined text-[18px]">mail</span>
                        Email Patient
                    </button>
                <?php endif; ?>
                <a class="btn btn-primary text-decoration-none" href="<?= app_url('visits/create.php?patient_id=' . $id) ?>">
                    <span class="material-symbols-outlined text-[18px]">add_notes</span>
                    Record Visit
                </a>
            </div>
        </div>
    </section>

    <nav class="patient-profile-tabs" aria-label="Student profile sections" role="tablist">
        <?php foreach ([
            'overview' => ['Overview', 'dashboard'],
            'appointments' => ['Appointments', 'event'],
            'clinical' => ['Clinical', 'medical_information'],
            'history' => ['History', 'history'],
        ] as $tabId => [$tabLabel, $tabIcon]): ?>
            <button type="button" class="patient-profile-tab" role="tab" id="profile-tab-<?= e($tabId) ?>" aria-controls="profile-panel-<?= e($tabId) ?>" aria-selected="<?= $tabId === 'overview' ? 'true' : 'false' ?>" tabindex="<?= $tabId === 'overview' ? '0' : '-1' ?>" data-profile-tab="<?= e($tabId) ?>">
                <span class="material-symbols-outlined text-[17px] align-middle mr-1" aria-hidden="true"><?= e($tabIcon) ?></span><?= e($tabLabel) ?>
            </button>
        <?php endforeach; ?>
    </nav>

    <section id="profile-panel-overview" role="tabpanel" aria-labelledby="profile-tab-overview" data-profile-panel="overview" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="patient-profile-card p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-primary-fixed text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined">clinical_notes</span></div>
            <div><p class="clinic-label mb-1">Total Visits</p><p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0"><?= count($visits) ?></p></div>
        </div>
        <div class="patient-profile-card p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0"><span class="material-symbols-outlined">schedule</span></div>
            <div class="min-w-0"><p class="clinic-label mb-1">Latest Visit</p><p class="text-sm font-extrabold text-slate-800 leading-snug m-0"><?= e($lastVisitLabel) ?></p></div>
        </div>
        <div class="patient-profile-card p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0"><span class="material-symbols-outlined">priority_high</span></div>
            <div><p class="clinic-label mb-1">Needs Attention</p><p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0"><?= $attentionVisitCount + count(array_filter($alertReports, static fn(array $alert): bool => ($alert['status'] ?? '') !== 'Resolved')) ?></p></div>
        </div>
        <div class="patient-profile-card p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0"><span class="material-symbols-outlined">folder_open</span></div>
            <div><p class="clinic-label mb-1">APE Documents</p><p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0"><?= $documentCount ?></p></div>
        </div>
        <div class="patient-profile-card p-5 sm:col-span-2 xl:col-span-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="patient-profile-note <?= $latestApeRecord ? '' : 'warning' ?>"><span class="clinic-label">APE status</span><strong><?= e($latestApeRecord ? $apeWorkflowLabel : 'No APE record') ?></strong></div>
                <div class="patient-profile-note"><span class="clinic-label">Upcoming appointment</span><strong><?= $nextAppointment ? e(date('M j, Y g:i A', strtotime($nextAppointment['appointment_datetime']))) : 'None scheduled' ?></strong></div>
                <div class="patient-profile-note"><span class="clinic-label">Current referrals</span><strong><?= count($referrals) ?> record<?= count($referrals) === 1 ? '' : 's' ?></strong></div>
            </div>
        </div>
    </section>

    <section id="profile-panel-appointments" role="tabpanel" aria-labelledby="profile-tab-appointments" data-profile-panel="appointments" hidden class="clinic-card overflow-hidden">
        <div class="p-5 md:p-6 border-b border-slate-100">
            <p class="clinic-label mb-1">Read-only schedule</p>
            <h2 class="font-headline text-2xl font-extrabold text-[#17261d] m-0">Appointments</h2>
            <p class="text-sm font-bold text-slate-500 mt-1 mb-0">View this student's clinic schedule. Appointment changes remain in the Appointments module.</p>
        </div>
        <div class="p-5 md:p-6">
            <h3 class="font-headline text-lg font-extrabold text-slate-900 mb-3">Next appointment</h3>
            <?php if ($nextAppointment): ?>
                <article class="profile-appointment-card mb-6">
                    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                        <div><p class="text-lg font-extrabold text-slate-900 mb-1"><?= e($nextAppointment['purpose']) ?></p><p class="text-sm font-bold text-slate-600 mb-1"><?= e(date('l, F j, Y · g:i A', strtotime($nextAppointment['appointment_datetime']))) ?></p><p class="text-xs font-bold text-slate-500 mb-0">Location: Clinic</p></div>
                        <span class="badge <?= e(appointment_status_badge_class((string) $nextAppointment['status'])) ?>"><?= e($nextAppointment['status']) ?></span>
                    </div>
                    <?php if (trim((string) ($nextAppointment['notes'] ?? '')) !== ''): ?><p class="text-sm text-slate-600 mt-4 mb-0"><strong>Notes:</strong> <?= e($nextAppointment['notes']) ?></p><?php endif; ?>
                </article>
            <?php else: ?>
                <div class="empty-state mb-6"><span class="material-symbols-outlined">event_busy</span><p class="empty-state-title">No upcoming appointments</p><p class="empty-state-text">There is no upcoming clinic appointment for this student.</p></div>
            <?php endif; ?>
            <h3 class="font-headline text-lg font-extrabold text-slate-900 mb-3">Appointment history</h3>
            <?php if ($appointmentHistory): ?>
                <div><?php foreach ($appointmentHistory as $appointment): ?><article class="profile-appointment-card"><div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3"><div><p class="text-sm font-extrabold text-slate-900 mb-1"><?= e($appointment['purpose']) ?></p><p class="text-xs font-bold text-slate-500 mb-0"><?= e(date('M j, Y · g:i A', strtotime($appointment['appointment_datetime']))) ?> · Clinic</p></div><span class="badge <?= e(appointment_status_badge_class((string) $appointment['status'])) ?>"><?= e($appointment['status']) ?></span></div><?php if ((string) ($appointment['status'] ?? '') === 'Cancelled' && trim((string) ($appointment['cancellation_reason'] ?? '')) !== ''): ?><p class="text-xs text-red-700 mt-3 mb-0"><strong>Cancellation reason:</strong> <?= e($appointment['cancellation_reason']) ?></p><?php endif; ?><?php if (trim((string) ($appointment['notes'] ?? '')) !== ''): ?><p class="text-xs text-slate-600 mt-3 mb-0"><strong>Notes:</strong> <?= e($appointment['notes']) ?></p><?php endif; ?></article><?php endforeach; ?></div>
            <?php else: ?><div class="empty-state"><span class="material-symbols-outlined">history</span><p class="empty-state-title">No appointment history</p><p class="empty-state-text">Past, cancelled, and missed appointments will appear here.</p></div><?php endif; ?>
        </div>
    </section>

    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 hidden" aria-hidden="true">
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
                <p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0"><?= $documentCount ?></p>
            </div>
        </div>
    </section>

    <section class="clinic-card overflow-hidden" data-profile-panel="history" hidden>
        <div class="p-4 md:p-5 border-b border-slate-100 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
                    <span class="material-symbols-outlined">notification_important</span>
                </div>
                <div>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Alert History</h2>
                    <p class="text-xs font-bold text-slate-500 m-0">Emergency and nurse alert reports linked to this patient.</p>
                </div>
            </div>
            <span class="badge <?= $alertReports ? 'badge-critical' : 'badge-completed' ?>"><?= count($alertReports) ?> report<?= count($alertReports) === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($alertReports): ?>
            <div class="divide-y divide-slate-100" data-alert-history>
                <?php foreach ($alertReports as $alert): ?>
                    <?php
                    $alertRecordId = (int) ($alert['id'] ?? $alert['alert_id'] ?? 0);
                    $alertStatus = trim((string) ($alert['status'] ?? 'Pending')) ?: 'Pending';
                    $alertRisk = trim((string) ($alert['risk_level'] ?? 'Not assessed')) ?: 'Not assessed';
                    $alertDate = (string) ($alert['created_at'] ?? '');
                    $alertTitle = trim((string) ($alert['concern'] ?? '')) ?: 'Emergency alert';
                    ?>
                    <article class="p-3 md:p-4 cursor-pointer" tabindex="0" data-alert-event
                             data-alert-date="<?= $alertDate !== '' ? e(date('M d, Y', strtotime($alertDate))) : 'Date not recorded' ?>"
                             data-alert-time="<?= $alertDate !== '' ? e(date('g:i A', strtotime($alertDate))) : '' ?>"
                             data-alert-reporter="<?= e(trim((string) ($alert['reporter_name'] ?? '')) ?: 'Clinic staff') ?>"
                             data-alert-resolver="<?= $alertStatus === 'Resolved' ? e(trim((string) ($alert['resolved_by_name'] ?? ''))) : '' ?>">
                        <button type="button" class="w-full rounded-2xl border border-slate-200 bg-white p-4 text-left transition hover:border-emerald-300 hover:bg-emerald-50/30 focus:outline-none focus:ring-2 focus:ring-emerald-500/30" data-alert-toggle aria-expanded="false" aria-controls="alert-details-<?= $alertRecordId ?>">
                            <span class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <span class="min-w-0">
                                    <span class="mb-2 flex flex-wrap items-center gap-2">
                                        <span class="badge <?= e(status_badge_class($alertStatus)) ?>"><?= e($alertStatus) ?></span>
                                        <span class="badge <?= $alertRisk === 'Critical' || $alertRisk === 'High' ? 'badge-critical' : 'badge-pending' ?>"><?= e($alertRisk) ?> risk</span>
                                        <?php if (trim((string) ($alert['incident_type'] ?? '')) !== ''): ?>
                                            <span class="text-[11px] font-black uppercase tracking-widest text-slate-400"><?= e($alert['incident_type']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <strong class="block truncate text-base font-extrabold text-slate-900"><?= e($alertTitle) ?></strong>
                                    <span class="mt-1 block text-sm font-bold text-slate-500"><?= e(trim((string) ($alert['location'] ?? '')) ?: 'Location not recorded') ?></span>
                                </span>
                                <span class="flex shrink-0 items-center gap-2 text-xs font-extrabold text-emerald-700">
                                    <span data-alert-toggle-label>View report</span>
                                    <span class="material-symbols-outlined transition-transform" data-alert-toggle-icon aria-hidden="true">expand_more</span>
                                </span>
                            </span>
                        </button>
                        <div id="alert-details-<?= $alertRecordId ?>" class="mt-3 hidden rounded-2xl bg-slate-50 p-4" data-alert-details>
                            <div class="mb-4 flex justify-end">
                                <a href="<?= e(app_url('alerts/view.php?id=' . $alertRecordId . '&from=profile')) ?>" class="btn btn-sm btn-outline text-decoration-none" data-alert-open-report>
                                    <span class="material-symbols-outlined text-[16px]">open_in_new</span>
                                    Open full report
                                </a>
                            </div>
                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                <div>
                                    <p class="clinic-label mb-2">Report details</p>
                                    <?php if (trim((string) ($alert['details'] ?? '')) !== ''): ?>
                                        <p class="whitespace-pre-wrap text-sm text-slate-600 mb-0"><?= e($alert['details']) ?></p>
                                    <?php else: ?>
                                        <p class="text-sm font-bold text-slate-400 mb-0">No additional details were recorded.</p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if (trim((string) ($alert['resolution_report'] ?? '')) !== ''): ?>
                                        <div class="patient-profile-note text-left">
                                            <span class="clinic-label">Resolution report</span>
                                            <strong class="whitespace-pre-wrap text-slate-600"><?= e($alert['resolution_report']) ?></strong>
                                        </div>
                                    <?php else: ?>
                                        <p class="clinic-label mb-2">Resolution report</p>
                                        <p class="text-sm font-bold text-slate-400 mb-0">No resolution has been recorded.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="care-timeline-pagination grid grid-cols-1 sm:grid-cols-[1fr_auto_1fr] items-center gap-3 px-4" data-alert-footer>
                <div class="text-center sm:text-left" data-alert-date-meta aria-live="polite"></div>
                <nav class="pagination justify-center" data-alert-pagination aria-label="Alert history pages"></nav>
                <div class="text-center sm:text-right" data-alert-meta aria-live="polite"></div>
            </div>
        <?php else: ?>
            <div class="empty-state py-8">
                <span class="material-symbols-outlined">notifications_off</span>
                <p class="empty-state-title">No alert reports</p>
                <p class="empty-state-text">No emergency or nurse alerts have been recorded for this patient.</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="clinic-card overflow-hidden" data-profile-panel="clinical" hidden>
        <div class="p-5 md:p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                    <span class="material-symbols-outlined">medical_information</span>
                </div>
                <div>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Health Summary</h2>
                    <p class="text-xs font-bold text-slate-500 m-0">Quick clinical view for authorized clinic staff.</p>
                </div>
            </div>
                    <span class="flex items-center gap-2">
                        <?php if ($latestApeRecord): ?><a class="text-xs font-black text-primary text-decoration-none" href="<?= e(app_url('ape/view.php?id=' . (int) $latestApeRecord['id'])) ?>">Open APE</a><?php endif; ?>
                        <span class="badge <?= $latestApeRecord ? ape_status_badge_class($latestApeResult) : 'badge-pending' ?>">
                            <?= e($latestApeRecord ? 'Latest APE · ' . $latestApeDate : 'No APE record') ?>
                        </span>
                    </span>
        </div>
        <div class="p-5 md:p-6 grid grid-cols-1 xl:grid-cols-2 gap-5">
            <div class="patient-profile-card p-5">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">assignment_turned_in</span>
                        <h3 class="font-headline text-lg font-extrabold text-slate-900 m-0">Latest APE Findings</h3>
                    </div>
                    <span class="badge <?= ape_status_badge_class($latestApeResult) ?>"><?= e($latestApeResult) ?></span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="patient-profile-note">
                        <span class="clinic-label">Examination date</span>
                        <strong><?= e($latestApeDate) ?></strong>
                    </div>
                    <div class="patient-profile-note">
                        <span class="clinic-label">Clearance / workflow</span>
                        <strong><?= e($apeWorkflowLabel !== '' ? $apeWorkflowLabel : 'Pending') ?></strong>
                    </div>
                    <div class="patient-profile-note sm:col-span-2 <?= $latestApeStoredResult === 'Referred' || $latestApeStoredResult === 'With Finding' ? 'warning' : '' ?>">
                        <span class="clinic-label">Finding / result notes</span>
                        <strong class="whitespace-pre-wrap text-slate-600"><?= e($latestApeNotes !== '' ? $latestApeNotes : 'No APE finding has been recorded.') ?></strong>
                    </div>
                    <?php if ($latestApeRecord): ?>
                    <div class="patient-profile-note sm:col-span-2">
                        <span class="clinic-label">Recorded vitals and BMI</span>
                        <strong class="text-slate-600">Height: <?= $latestApeRecord['patient_height_cm'] !== null ? e($latestApeRecord['patient_height_cm']) . ' cm' : '—' ?> · Weight: <?= $latestApeRecord['patient_weight_kg'] !== null ? e($latestApeRecord['patient_weight_kg']) . ' kg' : '—' ?> · BMI: <?= $latestApeRecord['patient_bmi'] !== null ? e($latestApeRecord['patient_bmi']) : '—' ?> · Temp: <?= $latestApeRecord['patient_temperature'] !== null ? e($latestApeRecord['patient_temperature']) . ' °C' : '—' ?> · BP: <?= e($latestApeRecord['patient_blood_pressure'] ?: '—') ?> · Pulse: <?= $latestApeRecord['patient_pulse_rate'] !== null ? e($latestApeRecord['patient_pulse_rate']) . ' bpm' : '—' ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="patient-profile-card p-5">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-primary">emergency</span>
                    <h3 class="font-headline text-lg font-extrabold text-slate-900 m-0">Declared Health Passport</h3>
                </div>
                <div class="grid grid-cols-1 gap-3">
                    <div class="patient-profile-note <?= $declaredHealthItems['Allergies'] !== '' ? 'warning' : '' ?>">
                        <span class="clinic-label">Allergies</span>
                        <strong class="whitespace-pre-wrap text-slate-600"><?= e($declaredHealthItems['Allergies'] !== '' ? $declaredHealthItems['Allergies'] : 'None declared') ?></strong>
                    </div>
                    <div class="patient-profile-note">
                        <span class="clinic-label">Doctor-confirmed medical conditions</span>
                        <strong class="whitespace-pre-wrap text-slate-600"><?= e($declaredHealthItems['Existing conditions'] !== '' ? $declaredHealthItems['Existing conditions'] : 'None declared') ?></strong>
                    </div>
                    <div class="patient-profile-note">
                        <span class="clinic-label">Current medications recorded during APE</span>
                        <strong class="whitespace-pre-wrap text-slate-600"><?= e($declaredHealthItems['Medications'] !== '' ? $declaredHealthItems['Medications'] : 'None declared') ?></strong>
                    </div>
                    <div class="patient-profile-note">
                        <span class="clinic-label">Clinic-recorded blood type</span>
                        <strong class="text-slate-600"><?= e($patient['blood_type'] ?: 'Not recorded') ?></strong>
                    </div>
                    <?php if (!$hasDeclaredHealth): ?>
                        <p class="text-xs font-bold text-amber-700 mb-0">The patient has not declared any health conditions, allergies, or medications in the passport.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="clinic-card overflow-hidden" data-profile-panel="clinical" hidden>
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

    <section class="clinic-card overflow-hidden" data-profile-panel="history" hidden>
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
                    <article class="patient-profile-event <?= $visitIndex === 0 ? '' : 'is-collapsed' ?> cursor-pointer"
                             tabindex="0"
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

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6" data-profile-panel="history" hidden>
        <section class="clinic-card overflow-hidden">
            <div class="p-5 md:p-6 border-b border-slate-100 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <span class="material-symbols-outlined">assignment_turned_in</span>
                    </div>
                    <div>
                        <h2 class="font-headline text-lg font-extrabold text-[#17261d] m-0">APE Records</h2>
                        <p class="text-xs font-bold text-slate-500 m-0">Completed annual physical examination history.</p>
                    </div>
                </div>
                <span class="badge badge-pending"><?= count($completedApeRecords) ?> record(s)</span>
            </div>
            <?php if ($completedApeRecords): ?>
                <div class="divide-y divide-outline-variant/10">
                    <?php foreach ($completedApeRecords as $ape): ?>
                        <?php
                        $completedAt = trim((string) ($ape['updated_at'] ?? ''));
                        if ($completedAt === '') {
                            $completedAt = trim((string) ($ape['exam_date'] ?? ''));
                        }
                        ?>
                        <a href="<?= e(app_url('ape/view.php?id=' . (int) $ape['id'])) ?>" class="p-5 flex items-center justify-between gap-4 text-decoration-none hover:bg-slate-50/70 transition-colors">
                            <div class="min-w-0">
                                <p class="text-sm font-extrabold text-slate-800 mb-1">Completed APE</p>
                                <p class="text-xs font-bold text-slate-400 m-0">
                                    <?= $completedAt !== '' ? 'Completed ' . e(date('M d, Y', strtotime($completedAt))) : 'Completion date not recorded' ?>
                                </p>
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <span class="badge badge-completed">Completed</span>
                                <span class="text-xs font-black text-primary inline-flex items-center gap-1">
                                    Open record
                                    <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                                </span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-outlined">assignment_turned_in</span>
                    <p class="empty-state-title">No completed APE records</p>
                    <p class="empty-state-text">An APE record will appear here after phase five is completed.</p>
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

<?php if ($canEmailPatient): ?>
    <div id="patientEmailComposerModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="patientEmailComposerTitle">
        <div class="modal-content bg-white rounded-[1.5rem] w-full max-w-2xl p-7 shadow-2xl border border-outline-variant/10">
            <div class="flex items-start justify-between gap-4 mb-6">
                <div>
                    <div class="w-12 h-12 rounded-xl bg-[var(--cliniq-surface-low)] text-[var(--cliniq-primary)] flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-[26px]">edit_square</span>
                    </div>
                    <h3 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1" id="patientEmailComposerTitle">Email Patient</h3>
                    <p class="text-sm font-bold text-slate-500 mb-0">Send a private message to this patient.</p>
                </div>
                <button type="button" class="btn btn-ghost justify-center px-3" id="closePatientEmailComposerButton" aria-label="Close email composer">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>

            <form method="post" data-no-ajax="true" class="space-y-5" id="patientEmailComposerForm">
                <input type="hidden" name="action" value="send_patient_email">

                <?php if (!$patientMailConfigured): ?>
                    <div class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                        <span class="material-symbols-outlined text-[18px] mt-0.5">warning</span>
                        <span>Configure Email in System Settings before sending this message.</span>
                    </div>
                <?php endif; ?>

                <div class="settings-field">
                    <span class="clinic-label">Recipient</span>
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 flex items-center gap-3">
                        <span class="material-symbols-outlined text-primary">person</span>
                        <span class="min-w-0">
                            <strong class="block text-sm font-extrabold text-slate-800 truncate"><?= e($fullName) ?></strong>
                            <span class="block text-xs font-bold text-slate-500 truncate"><?= e($emailLabel) ?></span>
                        </span>
                    </div>
                </div>

                <div class="settings-field">
                    <label class="clinic-label" for="patientEmailSubject">Subject</label>
                    <input class="settings-input" id="patientEmailSubject" name="subject" type="text" maxlength="180" required>
                </div>
                <div class="settings-field">
                    <label class="clinic-label" for="patientEmailMessage">Message</label>
                    <textarea class="settings-input min-h-48 resize-y" id="patientEmailMessage" name="message" maxlength="10000" placeholder="Write the email message..." required></textarea>
                </div>

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                    <button type="button" class="btn btn-secondary justify-center" id="cancelPatientEmailComposerButton">Cancel</button>
                    <button type="submit" class="btn btn-primary justify-center" <?= !$patientMailConfigured ? 'disabled' : '' ?>
                        title="<?= !$patientMailConfigured ? 'Configure email before sending' : 'Send this email' ?>"
                        data-confirm-submit data-confirm-type="primary" data-confirm-title="Send email to this patient?"
                        data-confirm-message="This message will be sent only to <?= e($fullName) ?>."
                        data-confirm-toast="Sending email...">
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        Send Email
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
(function () {
    const profileTabs = Array.from(document.querySelectorAll('[data-profile-tab]'));
    const profilePanels = Array.from(document.querySelectorAll('[data-profile-panel]'));
    const validProfileTabs = new Set(profileTabs.map((tab) => tab.dataset.profileTab));

    function activateProfileTab(tabName, updateUrl = true) {
        const activeName = validProfileTabs.has(tabName) ? tabName : 'overview';
        profileTabs.forEach((tab) => {
            const isActive = tab.dataset.profileTab === activeName;
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.tabIndex = isActive ? 0 : -1;
        });
        profilePanels.forEach((panel) => {
            panel.hidden = panel.dataset.profilePanel !== activeName;
        });
        if (updateUrl) {
            const url = new URL(window.location.href);
            url.hash = `profile-${activeName}`;
            window.history.replaceState({}, '', url);
        }
    }

    profileTabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activateProfileTab(tab.dataset.profileTab));
        tab.addEventListener('keydown', (event) => {
            if (!['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            let nextIndex = index;
            if (event.key === 'Home') nextIndex = 0;
            else if (event.key === 'End') nextIndex = profileTabs.length - 1;
            else if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (index + 1) % profileTabs.length;
            else nextIndex = (index - 1 + profileTabs.length) % profileTabs.length;
            const nextTab = profileTabs[nextIndex];
            nextTab.focus();
            activateProfileTab(nextTab.dataset.profileTab);
        });
    });

    const requestedTab = window.location.hash.replace('#profile-', '');
    activateProfileTab(validProfileTabs.has(requestedTab) ? requestedTab : 'overview', false);

    const openEmailComposer = document.getElementById('openPatientEmailComposerButton');
    const closeEmailComposer = document.getElementById('closePatientEmailComposerButton');
    const cancelEmailComposer = document.getElementById('cancelPatientEmailComposerButton');
    const patientEmailForm = document.getElementById('patientEmailComposerForm');

    openEmailComposer?.addEventListener('click', () => showModal('patientEmailComposerModal'));
    [closeEmailComposer, cancelEmailComposer].forEach((button) => {
        button?.addEventListener('click', () => closeModal('patientEmailComposerModal'));
    });
    patientEmailForm?.addEventListener('submit', () => {
        closeModal('patientEmailComposerModal');
    }, true);

    const alertHistory = document.querySelector('[data-alert-history]');
    const alertPagination = document.querySelector('[data-alert-pagination]');
    if (alertHistory && alertPagination) {
        const alertEvents = Array.from(alertHistory.querySelectorAll('[data-alert-event]'));
        const alertDateMeta = document.querySelector('[data-alert-date-meta]');
        const alertMeta = document.querySelector('[data-alert-meta]');
        let alertPage = 1;

        function renderAlertPagination(totalPages) {
            const windowStart = Math.floor((alertPage - 1) / 5) * 5 + 1;
            const windowEnd = Math.min(totalPages, windowStart + 4);
            let markup = `<button type="button" class="pagination-arrow${alertPage === 1 ? ' page-disabled' : ''}" data-alert-page-action="previous" aria-label="Previous alert page" ${alertPage === 1 ? 'disabled' : ''}>&lsaquo;</button>`;
            for (let page = windowStart; page <= windowEnd; page += 1) {
                markup += `<button type="button" data-alert-page="${page}"${page === alertPage ? ' class="page-active" aria-current="page"' : ''}>${page}</button>`;
            }
            markup += `<button type="button" class="pagination-arrow${alertPage === totalPages ? ' page-disabled' : ''}" data-alert-page-action="next" aria-label="Next alert page" ${alertPage === totalPages ? 'disabled' : ''}>&rsaquo;</button>`;
            alertPagination.innerHTML = markup;
        }

        function renderAlertPage() {
            const totalPages = Math.max(1, alertEvents.length);
            alertPage = Math.min(Math.max(1, alertPage), totalPages);
            alertEvents.forEach((event, index) => {
                event.hidden = index !== alertPage - 1;
            });
            const activeAlert = alertEvents[alertPage - 1];
            if (alertMeta && activeAlert) {
                const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
                const reporter = escapeHtml(activeAlert.dataset.alertReporter || 'Clinic staff');
                const resolver = escapeHtml(activeAlert.dataset.alertResolver);
                if (alertDateMeta) {
                    alertDateMeta.innerHTML = `<p class="text-xs font-extrabold text-slate-500 mb-1">${escapeHtml(activeAlert.dataset.alertDate || 'Date not recorded')}</p>`
                        + `<p class="text-[11px] font-bold text-slate-400 mb-0">${escapeHtml(activeAlert.dataset.alertTime || '')}</p>`;
                }
                if (alertMeta) {
                    alertMeta.innerHTML = `<p class="text-[11px] font-bold text-slate-400 mb-0">Reported by ${reporter}</p>`
                        + (resolver ? `<p class="text-[11px] font-bold text-slate-400 mb-0">Resolved by ${resolver}</p>` : '');
                }
            }
            renderAlertPagination(totalPages);
        }

        function toggleAlertReport(toggle) {
            const details = document.getElementById(toggle.getAttribute('aria-controls'));
            if (!details) return;
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            details.classList.toggle('hidden', expanded);
            const label = toggle.querySelector('[data-alert-toggle-label]');
            const icon = toggle.querySelector('[data-alert-toggle-icon]');
            if (label) label.textContent = expanded ? 'View report' : 'Hide report';
            if (icon) icon.style.transform = expanded ? '' : 'rotate(180deg)';
        }

        alertHistory.addEventListener('click', (event) => {
            const toggle = event.target.closest('[data-alert-toggle]');
            const alertEvent = event.target.closest('[data-alert-event]');
            if (toggle) {
                toggleAlertReport(toggle);
                return;
            }
            if (alertEvent && !event.target.closest('a, button, input, textarea, select')) {
                const reportToggle = alertEvent.querySelector('[data-alert-toggle]');
                if (reportToggle) toggleAlertReport(reportToggle);
            }
        });

        alertHistory.addEventListener('keydown', (event) => {
            if (!['Enter', ' '].includes(event.key) || event.target.closest('button, a, input, textarea, select')) return;
            const alertEvent = event.target.closest('[data-alert-event]');
            const reportToggle = alertEvent?.querySelector('[data-alert-toggle]');
            if (!reportToggle) return;
            event.preventDefault();
            toggleAlertReport(reportToggle);
        });

        alertPagination.addEventListener('click', (event) => {
            const pageButton = event.target.closest('[data-alert-page]');
            const actionButton = event.target.closest('[data-alert-page-action]');
            if (pageButton) {
                alertPage = Number(pageButton.dataset.alertPage) || 1;
            } else if (actionButton) {
                alertPage += actionButton.dataset.alertPageAction === 'next' ? 1 : -1;
            } else {
                return;
            }
            renderAlertPage();
        });

        renderAlertPage();
    }

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

    function toggleCareVisit(visit) {
        if (!visit) return;

        const collapsed = visit.classList.toggle('is-collapsed');
        const toggle = visit.querySelector('[data-care-toggle]');
        if (!toggle) return;
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        const label = toggle.querySelector('[data-care-toggle-label]');
        if (label) label.textContent = collapsed ? 'Expand' : 'Collapse';
    }

    timeline.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-care-toggle]');
        const visit = event.target.closest('[data-care-event]');
        if (!visit) return;
        if (toggle || !event.target.closest('a, button, input, textarea, select')) {
            toggleCareVisit(visit);
        }
    });

    timeline.addEventListener('keydown', (event) => {
        if (!['Enter', ' '].includes(event.key) || event.target.closest('button, a, input, textarea, select')) return;
        const visit = event.target.closest('[data-care-event]');
        if (!visit) return;
        event.preventDefault();
        toggleCareVisit(visit);
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
