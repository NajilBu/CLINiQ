<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/VisitWorkflow.php';
require_login();
ensure_visit_workflow_schema();

$id = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM patients WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$patient = $stmt->fetch();

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

$fullName = trim($patient['first_name'] . ' ' . $patient['last_name']);

$visitStmt = db()->prepare("
    SELECT v.*, u.name AS recorded_by_name, au.name AS attended_by_name
    FROM clinic_visits v
    LEFT JOIN users u ON u.id = v.recorded_by
    LEFT JOIN users au ON au.id = v.attended_by
    WHERE v.patient_id = ?
    ORDER BY
        CASE v.status
            WHEN 'Unaddressed' THEN 0
            WHEN 'Active' THEN 1
            WHEN 'Completed' THEN 2
            WHEN 'Cancelled' THEN 3
            ELSE 4
        END,
        v.visit_datetime DESC
    LIMIT 50
");
$visitStmt->execute([$id]);
$visits = $visitStmt->fetchAll();

$apeStmt = db()->prepare("
    SELECT a.*, u.name AS verified_by_name
    FROM ape_records a
    LEFT JOIN users u ON u.id = a.verified_by
    WHERE a.patient_id = ?
    ORDER BY a.exam_date DESC
");
$apeStmt->execute([$id]);
$apeRecords = $apeStmt->fetchAll();

$refStmt = db()->prepare('SELECT * FROM referrals WHERE patient_id = ? ORDER BY referral_date DESC');
$refStmt->execute([$id]);
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
        || in_array(($visit['risk_level'] ?? ''), ['High', 'Critical'], true)
));
$birthdateLabel = $patient['birthdate'] ? date('F j, Y', strtotime($patient['birthdate'])) : 'Not specified';
$ageLabel = 'Not specified';
if ($patient['birthdate']) {
    $birthdate = new DateTime($patient['birthdate']);
    $ageLabel = $birthdate->diff(new DateTime())->y . ' years old';
}
$sexLabel = $patient['sex'] ?: 'Not specified';
$bloodTypeLabel = $patient['blood_type'] ?: 'Not specified';
$courseLabel = $patient['course_section'] ?: 'No course set';
$lastVisitLabel = $latestVisit ? date('M d, Y g:i A', strtotime($latestVisit['visit_datetime'])) : 'No visits yet';

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

    .patient-profile-event {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
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

    .patient-profile-event-icon {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.8rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #e8f6ec;
        color: #3f7d52;
        flex-shrink: 0;
    }

    .patient-profile-event-icon.alert {
        background: #fef2f2;
        color: #dc2626;
    }

    @media (max-width: 720px) {
        .patient-profile-event {
            grid-template-columns: auto minmax(0, 1fr);
        }

        .patient-profile-event-meta {
            grid-column: 2;
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
                        <?= e($patient['student_number']) ?> &bull; <?= e($courseLabel) ?>
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <a class="btn btn-ghost text-decoration-none" href="edit.php?id=<?= $id ?>">
                    <span class="material-symbols-outlined text-[18px]">edit</span>
                    Edit
                </a>
                <a class="btn btn-outline text-decoration-none" href="emergency_profile.php?id=<?= $id ?>">
                    <span class="material-symbols-outlined text-[18px]">emergency</span>
                    Emergency Profile
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
                    <span class="material-symbols-outlined text-red-600">medical_information</span>
                    <h3 class="font-headline text-lg font-extrabold text-slate-900 m-0">Health Alerts</h3>
                </div>
                <div class="grid grid-cols-1 gap-4">
                    <div class="patient-profile-note <?= trim((string) $patient['allergies']) !== '' ? 'warning' : '' ?>">
                        <span class="clinic-label">Critical Allergies</span>
                        <strong class="whitespace-pre-wrap"><?= e(trim((string) $patient['allergies']) !== '' ? $patient['allergies'] : 'None recorded') ?></strong>
                    </div>
                    <div class="patient-profile-note">
                        <span class="clinic-label">Existing Conditions</span>
                        <strong class="whitespace-pre-wrap"><?= e(trim((string) $patient['existing_conditions']) !== '' ? $patient['existing_conditions'] : 'None recorded') ?></strong>
                    </div>
                    <div class="patient-profile-note">
                        <span class="clinic-label">Emergency Instructions</span>
                        <strong class="whitespace-pre-wrap"><?= e(trim((string) $patient['emergency_instructions']) !== '' ? $patient['emergency_instructions'] : 'None recorded') ?></strong>
                    </div>
                </div>
            </div>
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
                    <p class="text-xs font-bold text-slate-500 m-0"><?= count($visits) ?> visit record(s), newest priority records shown first.</p>
                </div>
            </div>
            <a class="btn btn-sm btn-outline text-decoration-none" href="<?= app_url('visits/index.php?patient=' . $id) ?>">
                <span class="material-symbols-outlined text-[16px]">list</span>
                Clinic Logbook
            </a>
        </div>

        <?php if ($visits): ?>
            <div class="patient-profile-timeline divide-y divide-outline-variant/10">
                <?php foreach ($visits as $visit): ?>
                    <?php
                    $visitStatus = $visit['status'] ?? 'Unaddressed';
                    $visitRisk = $visit['risk_level'] ?? 'Low';
                    $visitPurpose = $visit['visit_purpose'] ?: 'General Visit';
                    $visitSource = $visit['visit_source'] ?: 'Staff Recorded';
                    $vitalBits = [];
                    if ($visit['temperature'] !== null && $visit['temperature'] !== '') {
                        $vitalBits[] = 'Temp ' . $visit['temperature'] . ' C';
                    }
                    if ($visit['blood_pressure']) {
                        $vitalBits[] = 'BP ' . $visit['blood_pressure'];
                    }
                    if ($visit['pulse_rate']) {
                        $vitalBits[] = 'Pulse ' . (int) $visit['pulse_rate'] . ' BPM';
                    }
                    if (($visit['spo2'] ?? '') !== '') {
                        $vitalBits[] = 'SpO2 ' . $visit['spo2'] . '%';
                    }
                    ?>
                    <a class="patient-profile-event" href="<?= e(app_url('visits/view.php?id=' . (int) $visit['id'] . '&from=profile')) ?>">
                        <div class="patient-profile-event-icon <?= in_array($visitRisk, ['High', 'Critical'], true) ? 'alert' : '' ?>">
                            <span class="material-symbols-outlined">medical_information</span>
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="badge <?= visit_status_badge_class($visitStatus) ?>"><?= e($visitStatus) ?></span>
                                <span class="badge <?= risk_badge_class($visitRisk) ?>"><?= e($visitRisk) ?> - <?= (int) ($visit['risk_score'] ?? 0) ?></span>
                                <span class="text-[11px] font-black uppercase tracking-widest text-slate-400"><?= e($visitPurpose) ?> / <?= e($visitSource) ?></span>
                            </div>
                            <h3 class="text-base font-extrabold text-slate-900 mb-1"><?= e($visit['chief_complaint'] ?: 'No complaint recorded') ?></h3>
                            <?php if ($visit['symptoms']): ?>
                                <p class="text-sm font-bold text-slate-500 mb-2 whitespace-pre-wrap"><?= e($visit['symptoms']) ?></p>
                            <?php endif; ?>
                            <?php if ($vitalBits): ?>
                                <p class="text-xs font-bold text-slate-500 mb-2"><?= e(implode(' / ', $vitalBits)) ?></p>
                            <?php endif; ?>
                            <div class="flex flex-wrap gap-2 text-xs font-bold text-slate-400">
                                <span>Recorded by <?= e($visit['recorded_by_name'] ?: 'System') ?></span>
                                <span>&bull;</span>
                                <span>Attended by <?= e($visit['attended_by_name'] ?: 'Not assigned') ?></span>
                            </div>
                            <?php if ($visit['action_taken']): ?>
                                <p class="text-xs font-extrabold text-primary mt-2 whitespace-pre-wrap"><?= e($visit['action_taken']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="patient-profile-event-meta text-right shrink-0">
                            <p class="text-xs font-extrabold text-slate-500 mb-0"><?= e(date('M d, Y', strtotime($visit['visit_datetime']))) ?></p>
                            <p class="text-[11px] font-bold text-slate-400 mb-2"><?= e(date('g:i A', strtotime($visit['visit_datetime']))) ?></p>
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

<?php render_footer(); ?>
