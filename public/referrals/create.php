<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$patients = db()->query('SELECT id, student_number, first_name, last_name FROM patients ORDER BY last_name, first_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = db()->prepare(
        'INSERT INTO referrals (patient_id, referral_date, referred_to, reason) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$_POST['patient_id'],
        $_POST['referral_date'] ?: date('Y-m-d'),
        trim($_POST['referred_to'] ?? ''),
        trim($_POST['reason'] ?? ''),
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
            <select class="clinic-select" name="patient_id" required>
                <option value="">Select patient</option>
                <?php foreach ($patients as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= e($p['last_name'] . ', ' . $p['first_name'] . ' - ' . $p['student_number']) ?></option>
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
