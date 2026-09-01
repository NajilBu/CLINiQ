<?php
require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/PatientPasswordResetService.php';
$clinicProfile = clinic_profile_settings();
$clinicLogoSrc = student_public_logo_src($clinicProfile);
$error = '';
$submitted = false;
$idNumber = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idNumber = trim((string) ($_POST['id_number'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    if (!patient_password_reset_verify_csrf('request', (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'The form expired. Refresh the page and try again.';
    } elseif (!is_valid_id_number($idNumber)) {
        $error = id_number_validation_message();
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid school email address.';
    } else {
        try {
            request_patient_password_reset($idNumber, $email, $_SERVER['REMOTE_ADDR'] ?? null);
            $submitted = true;
        } catch (Throwable $exception) {
            error_log('[CLINiQ Password Reset] Request failed: ' . $exception->getMessage());
            $submitted = true;
        }
    }
}
render_student_auth_header('Recover Password');
?>

<?php render_cliniq_entry_header([
    'homeUrl' => '../public/index.php',
    'logoUrl' => $clinicLogoSrc,
]); ?>

<main class="student-auth-wrap">
    <section class="student-auth-shell">
        <aside class="student-auth-side">
            <div>
                <a href="../public/index.php" class="student-brand-mark text-decoration-none" aria-label="Go to CLINiQ access portal">
                    <img src="<?= student_e($clinicLogoSrc) ?>" alt="<?= student_e($clinicProfile['department']) ?> logo">
                </a>
                <p class="student-auth-brand-line"><?= student_e($clinicProfile['system_name']) ?></p>
                <h1 class="student-auth-side-title">Recover<br>Access</h1>
                <p class="student-auth-side-copy">Request recovery instructions for your patient portal account.</p>
                <svg class="student-auth-pulse" viewBox="0 0 320 40" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 20 H100 L112 20 L120 4 L132 36 L142 20 L154 20 L162 12 L170 28 L178 20 L320 20"/>
                </svg>
            </div>
            <p class="student-auth-side-footnote">Use your enrolled ID number and school email</p>
        </aside>

        <div class="student-auth-form-side">
            <p class="student-eyebrow">Account Help</p>
            <h2 class="student-card-title text-xl">Password recovery</h2>
            <p class="student-card-copy mb-5">Enter your ID number and school email. The clinic system will send recovery instructions.</p>

            <div class="student-note student-note-success mb-4 <?= $submitted ? '' : 'hidden' ?>">
                <span class="material-symbols-outlined">mark_email_read</span>
                <div>If the ID number and email match an active patient account, recovery instructions will arrive shortly. Check the spam folder as well.</div>
            </div>

            <div class="student-note student-note-danger mb-4 <?= $error === '' ? 'hidden' : '' ?>">
                <span class="material-symbols-outlined">error</span>
                <div><?= student_e($error) ?></div>
            </div>

            <form method="post" id="passwordRecoveryForm">
                <input type="hidden" name="csrf_token" value="<?= student_e(patient_password_reset_csrf_token('request')) ?>">
                <div class="student-field">
                    <label class="student-label" for="id-number">ID Number</label>
                    <input id="id-number" name="id_number" class="student-input" type="text" placeholder="Enter ID number" autocomplete="username" data-id-number-format value="<?= student_e($idNumber) ?>" required>
                </div>

                <div class="student-field">
                    <label class="student-label" for="email">School Email</label>
                    <input id="email" name="email" class="student-input" type="email" placeholder="lastname_firstname@plpasig.edu.ph" autocomplete="email" value="<?= student_e($email) ?>" required>
                </div>

                <button type="submit" class="student-button w-full" id="passwordRecoverySubmit">
                    <span data-recovery-button-label>Send Recovery Instructions</span>
                    <span class="material-symbols-outlined" data-recovery-button-icon>mail</span>
                </button>
            </form>

            <hr class="student-auth-divider">
            <p class="text-center text-xs font-bold text-slate-500">
                Remembered your password?
                <a href="patient-login.php" class="student-auth-link text-decoration-none">Back to login.</a>
            </p>
        </div>
    </section>
</main>

<script>
document.getElementById('passwordRecoveryForm')?.addEventListener('submit', () => {
    const button = document.getElementById('passwordRecoverySubmit');
    if (!button || button.disabled) return;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.querySelector('[data-recovery-button-label]').textContent = 'Sending…';
    button.querySelector('[data-recovery-button-icon]').textContent = 'hourglass_top';
});
</script>

<?php render_student_auth_footer(); ?>
