<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AlertWorkflow.php';
require_login();
ensure_alert_workflow_schema();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('
    SELECT a.*, p.first_name, p.last_name, p.student_number, p.course_section, u.name AS resolved_by_name
    FROM nurse_alerts a
    LEFT JOIN patients p ON p.id = a.patient_id
    LEFT JOIN users u ON u.id = a.resolved_by
    WHERE a.id = ?
    LIMIT 1
');
$stmt->execute([$id]);
$alert = $stmt->fetch();

if (!$alert) {
    render_header('Alert Not Found');
    ?>
    <div class="empty-state" style="min-height: 40vh;">
        <span class="material-symbols-outlined">notification_important</span>
        <p class="empty-state-title">Alert not found</p>
        <p class="empty-state-text">The alert report you're looking for does not exist.</p>
        <a href="index.php" class="btn btn-primary mt-4 text-decoration-none">Back to Alerts</a>
    </div>
    <?php
    render_footer();
    exit;
}

$patientName = trim(($alert['first_name'] ?? '') . ' ' . ($alert['last_name'] ?? ''));
$photoUrl = $alert['photo_path'] ? app_url($alert['photo_path']) : '';
$riskLevel = $alert['risk_level'] ?? 'Low';
$riskScore = (int) ($alert['risk_score'] ?? 0);
$riskReasons = trim((string) ($alert['risk_reasons'] ?? ''));
$responseGuidance = trim((string) ($alert['response_guidance'] ?? ''));
$reportAnswers = trim((string) ($alert['report_answers'] ?? ''));

set_page_back_link('index.php', 'Alert Queue');
render_header('Alert Report');
?>

<div class="dashboard-hero flex flex-col lg:flex-row lg:items-center justify-between gap-5 mb-8">
    <div class="flex items-start gap-4">
        <div class="w-14 h-14 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center shrink-0">
            <span class="material-symbols-outlined text-[28px]">notification_important</span>
        </div>
        <div>
            <p class="text-[11px] font-black text-red-600 uppercase tracking-widest mb-2">Emergency Alert Report</p>
            <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#17261d]"><?= e($alert['concern']) ?></h1>
            <p class="text-sm font-bold text-slate-500 mt-1">
                Submitted <?= e(date('M d, Y g:i A', strtotime($alert['created_at']))) ?> from <?= e($alert['location']) ?>
            </p>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-3">
        <span class="badge <?= e(risk_badge_class($riskLevel)) ?>"><?= e($riskLevel) ?> Risk</span>
        <span class="badge <?= e(status_badge_class($alert['status'])) ?>"><?= e($alert['status']) ?></span>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[1fr_0.85fr] gap-6">
    <section class="clinic-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
                <span class="material-symbols-outlined">report</span>
            </div>
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Submitted Report</h2>
                <p class="text-xs font-bold text-slate-500 m-0">Original alert details from the reporter.</p>
            </div>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="alert-report-field">
                <span class="clinic-label">Reporter</span>
                <strong><?= e($alert['reporter_name']) ?></strong>
            </div>
            <div class="alert-report-field">
                <span class="clinic-label">Role</span>
                <strong><?= e($alert['reporter_role'] ?: 'Not specified') ?></strong>
            </div>
            <div class="alert-report-field">
                <span class="clinic-label">Location</span>
                <strong><?= e($alert['location']) ?></strong>
            </div>
            <div class="alert-report-field">
                <span class="clinic-label">Linked Patient</span>
                <strong>
                    <?= e($patientName !== '' ? $patientName : 'Unlisted') ?>
                    <?php if ($alert['student_number']): ?>
                        <span class="text-slate-400">&bull;</span> <?= e($alert['student_number']) ?>
                    <?php endif; ?>
                </strong>
            </div>
            <?php if (!empty($alert['incident_type'])): ?>
                <div class="md:col-span-2 alert-report-field">
                    <span class="clinic-label">Incident Type</span>
                    <strong><?= e($alert['incident_type']) ?></strong>
                </div>
            <?php endif; ?>
            <?php if ($reportAnswers !== ''): ?>
                <div class="md:col-span-2">
                    <span class="clinic-label">Reporter Answers</span>
                    <div class="mt-2 rounded-2xl border border-red-100 bg-red-50/50 p-4 text-sm font-bold text-slate-700 whitespace-pre-wrap min-h-[6rem]"><?= e($reportAnswers) ?></div>
                </div>
            <?php endif; ?>
            <div class="md:col-span-2">
                <span class="clinic-label">Details</span>
                <div class="mt-2 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-700 whitespace-pre-wrap min-h-[7rem]"><?= e($alert['details'] ?: 'No additional details provided.') ?></div>
            </div>
            <?php if ($photoUrl): ?>
                <div class="md:col-span-2">
                    <span class="clinic-label">Photo Evidence</span>
                    <a href="<?= e($photoUrl) ?>" target="_blank" class="block mt-2 rounded-2xl overflow-hidden border border-slate-200 bg-slate-50">
                        <img src="<?= e($photoUrl) ?>" alt="Alert report photo" class="w-full max-h-[28rem] object-contain bg-slate-100">
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="space-y-6 self-start">
    <section class="clinic-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
                <span class="material-symbols-outlined">emergency</span>
            </div>
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Risk Classification</h2>
                <p class="text-xs font-bold text-slate-500 m-0">System guidance based on submitted incident answers.</p>
            </div>
        </div>
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <span class="badge <?= e(risk_badge_class($riskLevel)) ?>"><?= e($riskLevel) ?> Risk</span>
                <span class="text-xs font-black text-slate-400 uppercase tracking-widest">Score <?= e((string) $riskScore) ?></span>
            </div>
            <div>
                <span class="clinic-label">Suggested Response</span>
                <div class="mt-2 rounded-2xl border border-red-100 bg-red-50/70 p-4 text-sm font-bold text-red-800 leading-relaxed">
                    <?= e($responseGuidance !== '' ? $responseGuidance : incident_response_guidance($riskLevel)) ?>
                </div>
            </div>
            <div>
                <span class="clinic-label">Classification Basis</span>
                <div class="mt-2 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-xs font-bold text-slate-600 whitespace-pre-wrap leading-relaxed"><?= e($riskReasons !== '' ? $riskReasons : 'No urgent incident indicators were detected from the submitted answers.') ?></div>
            </div>
        </div>
    </section>

    <section class="clinic-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
                <span class="material-symbols-outlined">assignment</span>
            </div>
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Clinic Response</h2>
                <p class="text-xs font-bold text-slate-500 m-0">Staff actions and accident report.</p>
            </div>
        </div>
        <div class="p-6 space-y-4">
            <?php if ($alert['status'] === 'Pending'): ?>
                <p class="text-sm font-bold text-slate-600">Acknowledge this alert once clinic staff has started responding.</p>
                <div class="flex flex-wrap gap-3">
                    <form method="post" action="update.php">
                        <input type="hidden" name="id" value="<?= (int) $alert['id'] ?>">
                        <input type="hidden" name="status" value="In Progress">
                        <input type="hidden" name="redirect" value="view.php?id=<?= (int) $alert['id'] ?>">
                        <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Acknowledge this alert?" data-confirm-message="This will move the alert to In Progress." data-confirm-toast="Acknowledging alert...">
                            <span class="material-symbols-outlined text-[18px]">play_arrow</span>
                            Acknowledge
                        </button>
                    </form>
                    <form method="post" action="update.php">
                        <input type="hidden" name="id" value="<?= (int) $alert['id'] ?>">
                        <input type="hidden" name="status" value="Cancelled">
                        <input type="hidden" name="redirect" value="view.php?id=<?= (int) $alert['id'] ?>">
                        <button class="btn btn-ghost btn-cancel-icon" title="Cancel alert" aria-label="Cancel alert" data-confirm-submit data-confirm-type="danger" data-confirm-title="Cancel this alert?" data-confirm-message="This will mark the alert as Cancelled." data-confirm-toast="Cancelling alert...">
                            <span class="material-symbols-outlined">cancel</span>
                        </button>
                    </form>
                </div>
            <?php elseif ($alert['status'] === 'In Progress'): ?>
                <form method="post" action="update.php">
                    <input type="hidden" name="id" value="<?= (int) $alert['id'] ?>">
                    <input type="hidden" name="status" value="Resolved">
                    <input type="hidden" name="redirect" value="view.php?id=<?= (int) $alert['id'] ?>">
                    <label class="clinic-label">Accident / Response Report</label>
                    <textarea class="clinic-textarea" name="resolution_report" rows="8" required placeholder="Write what happened, how staff responded, treatment or referral made, and final outcome."><?= e($alert['resolution_report']) ?></textarea>
                    <div class="mt-4 flex flex-wrap justify-end gap-3">
                        <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Resolve this alert?" data-confirm-message="This will save the accident report and mark the alert as Resolved." data-confirm-toast="Resolving alert...">
                            <span class="material-symbols-outlined text-[18px]">check_circle</span>
                            Save Report & Resolve
                        </button>
                    </div>
                </form>
                <form method="post" action="update.php" class="pt-2">
                    <input type="hidden" name="id" value="<?= (int) $alert['id'] ?>">
                    <input type="hidden" name="status" value="Cancelled">
                    <input type="hidden" name="redirect" value="view.php?id=<?= (int) $alert['id'] ?>">
                    <button class="btn btn-ghost btn-cancel-icon" title="Cancel alert" aria-label="Cancel alert" data-confirm-submit data-confirm-type="danger" data-confirm-title="Cancel this alert?" data-confirm-message="This will mark the alert as Cancelled." data-confirm-toast="Cancelling alert...">
                        <span class="material-symbols-outlined">cancel</span>
                    </button>
                </form>
            <?php elseif ($alert['status'] === 'Resolved'): ?>
                <div>
                    <span class="clinic-label">Accident / Response Report</span>
                    <div class="mt-2 rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4 text-sm font-bold text-slate-700 whitespace-pre-wrap"><?= e($alert['resolution_report'] ?: 'No report recorded.') ?></div>
                    <p class="text-xs font-bold text-slate-400 mt-3 mb-0">
                        Resolved <?= $alert['resolved_at'] ? e(date('M d, Y g:i A', strtotime($alert['resolved_at']))) : '' ?>
                        <?= $alert['resolved_by_name'] ? 'by ' . e($alert['resolved_by_name']) : '' ?>
                    </p>
                </div>
            <?php else: ?>
                <p class="text-sm font-bold text-slate-600">This alert was cancelled.</p>
            <?php endif; ?>
        </div>
    </section>
    </div>
</div>

<?php render_footer(); ?>
