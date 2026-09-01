<?php
require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/PatientPasswordResetService.php';

$clinicProfile = clinic_profile_settings();
$clinicLogoSrc = student_public_logo_src($clinicProfile);
$token = strtolower(trim((string) ($_POST['token'] ?? $_GET['token'] ?? '')));
$error = '';
$validToken = patient_password_reset_token_is_valid($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!patient_password_reset_verify_csrf('complete', (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'The form expired. Refresh the reset link and try again.';
    } else {
        try {
            complete_patient_password_reset(
                $token,
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['password_confirmation'] ?? '')
            );
            header('Location: patient-login.php?password_reset=1');
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            $validToken = patient_password_reset_token_is_valid($token);
        }
    }
}

render_student_auth_header('Reset Password');
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
                <h1 class="student-auth-side-title">Secure<br>Password Reset</h1>
                <p class="student-auth-side-copy">Choose a new password for your patient health portal.</p>
                <svg class="student-auth-pulse" viewBox="0 0 320 40" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 20 H100 L112 20 L120 4 L132 36 L142 20 L154 20 L162 12 L170 28 L178 20 L320 20"/>
                </svg>
            </div>
            <p class="student-auth-side-footnote">One-time links expire after <?= CLINIQ_PATIENT_RESET_EXPIRY_MINUTES ?> minutes</p>
        </aside>

        <div class="student-auth-form-side">
            <p class="student-eyebrow">Account Help</p>
            <h2 class="student-card-title text-xl">Create a new password</h2>
            <p class="student-card-copy mb-5">Use at least 8 characters and include at least one number.</p>

            <?php if ($error !== ''): ?>
                <div class="student-note student-note-danger mb-4">
                    <span class="material-symbols-outlined">error</span>
                    <div><?= student_e($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($validToken): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= student_e(patient_password_reset_csrf_token('complete')) ?>">
                    <input type="hidden" name="token" value="<?= student_e($token) ?>">
                    <div class="student-field">
                        <label class="student-label" for="password">New Password</label>
                        <div class="relative">
                            <input id="password" name="password" class="student-input pr-14" type="password" minlength="8" autocomplete="new-password" required>
                            <button type="button" class="student-toggle-pw" data-target="password">Show</button>
                        </div>
                    </div>
                    <div class="student-field">
                        <label class="student-label" for="password-confirmation">Confirm New Password</label>
                        <div class="relative">
                            <input id="password-confirmation" name="password_confirmation" class="student-input pr-14" type="password" minlength="8" autocomplete="new-password" required>
                            <button type="button" class="student-toggle-pw" data-target="password-confirmation">Show</button>
                        </div>
                    </div>
                    <button type="submit" class="student-button w-full">
                        Update Password
                        <span class="material-symbols-outlined">lock_reset</span>
                    </button>
                </form>
            <?php else: ?>
                <div class="student-note student-note-danger mb-4">
                    <span class="material-symbols-outlined">link_off</span>
                    <div>This password reset link is invalid, expired, or already used.</div>
                </div>
                <a href="patient-forgot-password.php" class="student-button w-full text-decoration-none">
                    Request a New Link
                    <span class="material-symbols-outlined">mail</span>
                </a>
            <?php endif; ?>

            <hr class="student-auth-divider">
            <p class="text-center text-xs font-bold text-slate-500">
                <a href="patient-login.php" class="student-auth-link text-decoration-none">Back to login</a>
            </p>
        </div>
    </section>
</main>

<script>
document.querySelectorAll('.student-toggle-pw').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.target);
        const hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        button.textContent = hidden ? 'Hide' : 'Show';
    });
});
</script>

<?php render_student_auth_footer(); ?>
