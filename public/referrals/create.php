<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$patients = auth_db()->query('
    SELECT pe.id AS person_id, pe.id_number, pe.first_name, pe.last_name
    FROM patients pt
    JOIN people pe ON pe.id = pt.person_id
    ORDER BY pe.last_name, pe.first_name
')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientPersonId = (int) ($_POST['patient_person_id'] ?? 0);
    $referredTo = trim((string) ($_POST['referred_to'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if ($patientPersonId < 1 || $referredTo === '' || $reason === '') {
        throw new InvalidArgumentException('Select a patient and complete the referral details.');
    }

    $referrerPersonId = (int) ((current_user()['person_id'] ?? 0));
    if ($referrerPersonId > 0) {
        $staffCheck = auth_db()->prepare('SELECT 1 FROM clinic_staff WHERE person_id = ?');
        $staffCheck->execute([$referrerPersonId]);
        if (!$staffCheck->fetchColumn()) {
            $referrerPersonId = 0;
        }
    }

    $stmt = auth_db()->prepare(
        'INSERT INTO referrals (patient_person_id, referral_date, referred_to, reason, referred_by_person_id) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $patientPersonId,
        $_POST['referral_date'] ?: date('Y-m-d'),
        $referredTo,
        $reason,
        $referrerPersonId ?: null,
    ]);

    flash_message('success', 'Referral created successfully.');
    header('Location: index.php');
    exit;
}

set_page_back_link('index.php', 'Back');
render_header('New Referral');
?>
<?php render_clinic_command_header(
    'External Care',
    'New Referral',
    'Refer a patient to an external facility or specialist.'
); ?>

<form class="clinic-card p-6 md:p-8" method="post">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div>
            <label class="clinic-label">Patient</label>
            <select class="clinic-select" name="patient_person_id" required>
                <option value="">Select patient</option>
                <?php foreach ($patients as $p): ?>
                    <option value="<?= (int)$p['person_id'] ?>"><?= e($p['last_name'] . ', ' . $p['first_name'] . ' - ' . $p['id_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="clinic-label">Referral Date</label>
            <input class="clinic-input" name="referral_date" type="date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div>
            <label class="clinic-label">Referred To</label>
            <input class="clinic-input" name="referred_to" required placeholder="Hospital, clinic, or specialist name">
        </div>
        <div class="md:col-span-2">
            <label class="clinic-label">Reason</label>
            <textarea class="clinic-textarea" name="reason" rows="4" required placeholder="Describe the reason for referral..."></textarea>
        </div>
    </div>
    <div class="mt-6 flex flex-wrap gap-3">
        <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Create this referral?" data-confirm-message="This will save a new referral record for the patient." data-confirm-toast="Creating referral...">
            <span class="material-symbols-outlined text-[18px]">send</span> Create Referral
        </button>
        <a class="btn btn-ghost btn-cancel-icon text-decoration-none" href="index.php" title="Cancel" aria-label="Cancel">
            <span class="material-symbols-outlined">cancel</span>
        </a>
    </div>
</form>
<?php render_footer(); ?>
