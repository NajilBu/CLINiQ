<?php
require_once __DIR__ . '/includes/patient-layout.php';

if (isset($_GET['logout'])) {
    student_logout();
    header('Location: patient-login.php?logged_out=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forget_device') {
    csrf_enforce_request();
    student_forget_device();
    $destination = ($_POST['return_to'] ?? '') === 'dashboard'
        ? 'patient-dashboard.php?device_forgotten=1'
        : 'patient-login.php?device_forgotten=1';
    header('Location: ' . $destination);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['logged_out'] ?? '') !== '1' && ($_GET['device_forgotten'] ?? '') !== '1' && student_restore_remembered_session()) {
    header('Location: patient-dashboard.php');
    exit;
}

$error = '';
$studentIdValue = '';
$clinicProfile = clinic_profile_settings();
$clinicLogoSrc = student_public_logo_src($clinicProfile);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentIdValue = normalize_id_number(trim($_POST['student_id'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    try {
        $db = auth_db();
        auth_throttle_assert_allowed($db, 'patient', $studentIdValue);

        if (!is_valid_id_number($studentIdValue)) {
            auth_throttle_record_failure($db, 'patient', $studentIdValue);
            $error = id_number_validation_message();
        } else {
            $patient = student_find_patient_by_number($studentIdValue);
            if ($patient === null) {
                auth_throttle_record_failure($db, 'patient', $studentIdValue);
                $error = 'Invalid ID number or password. Please try again.';
                audit_log_event('auth', 'student_login_failed', null, 'guest', 'account', null, ['id_number' => $studentIdValue], 'failure');
            } elseif (!student_password_is_valid($patient, $password)) {
                auth_throttle_record_failure($db, 'patient', $studentIdValue);
                $error = 'Invalid ID number or password. Please try again.';
                audit_log_event('auth', 'student_login_failed', (int) ($patient['person_id'] ?? 0) ?: null, 'guest', 'account', (int) ($patient['account_id'] ?? 0) ?: null, [], 'failure');
            } elseif ($patient['account_status'] === 'inactive') {
                auth_throttle_clear($db, 'patient', $studentIdValue);
                $wasActivated = !empty($patient['activated_at']);
                $schoolYearReset = (string) ($patient['status_reason'] ?? '') === 'New school year enrollment status required';
                $awaitingInitialActivation = (string) ($patient['status_reason'] ?? '') === 'Awaiting initial account activation';
                if ($wasActivated && $schoolYearReset && ($patient['account_type'] ?? '') === 'student') {
                    begin_re_enrollment($patient);
                } elseif (!$wasActivated && $awaitingInitialActivation) {
                    begin_first_registration($patient);
                } else {
                    $error = 'This account is inactive. Please contact the clinic for assistance.';
                }
                if ($error === '') {
                    csrf_rotate_token();
                    header('Location: patient-dashboard.php');
                    exit;
                }
            } else {
                auth_throttle_clear($db, 'patient', $studentIdValue);
                student_start_session();
                $_SESSION['patient_legacy_id'] = (int) $patient['legacy_patient_id'];
                $_SESSION['patient_account_id'] = (int) $patient['account_id'];
                $_SESSION['patient_person_id'] = (int) $patient['person_id'];
                student_record_successful_login((int) $patient['account_id']);
                if (!empty($_POST['remember_device'])) {
                    student_remember_device((int) $patient['account_id']);
                }
                audit_log_event('auth', 'student_login_success', (int) $patient['person_id'], 'student', 'person', (int) $patient['person_id']);
                csrf_rotate_token();
                header('Location: patient-dashboard.php');
                exit;
            }
        }
    } catch (LoginThrottleException $e) {
        $error = $e->getMessage();
    }
}

render_student_auth_header('Patient Login');
?>

<?php render_cliniq_entry_header([
    'homeUrl' => '../public/index.php',
    'logoUrl' => $clinicLogoSrc,
    'showBack' => false,
    'class' => 'cliniq-entry-header-mobile-hidden',
]); ?>

<main class="student-auth-wrap student-login-wrap">
    <section class="student-auth-shell">
        <aside class="student-auth-side">
            <div>
                <a href="../public/index.php" class="student-brand-mark text-decoration-none" aria-label="Go to CLINiQ access portal">
                    <img src="<?= student_e($clinicLogoSrc) ?>" alt="<?= student_e($clinicProfile['department']) ?> logo">
                </a>
                <p class="student-auth-brand-line"><?= student_e($clinicProfile['system_name']) ?></p>
                <p class="student-auth-department-line"><?= student_e($clinicProfile['department']) ?></p>
                <h1 class="student-auth-side-title">Patient Portal</h1>
                <p class="student-auth-side-copy">Track your APE status, upload documents, and book clinic appointments in one place.</p>
                <svg class="student-auth-pulse" viewBox="0 0 320 40" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 20 H100 L112 20 L120 4 L132 36 L142 20 L154 20 L162 12 L170 28 L178 20 L320 20"/>
                </svg>
            </div>
            <p class="student-auth-side-footnote">For students, faculty, and school personnel</p>
        </aside>

        <div class="student-auth-form-side">
            <p class="student-eyebrow">Welcome Back</p>
            <h2 class="student-card-title text-xl">Sign in to your clinic record</h2>
            <p class="student-card-copy mb-5">Enter the password provided by the clinic or the password you created after activation.</p>

            <?php if (($_GET['password_reset'] ?? '') === '1'): ?>
                <div class="student-note student-note-success student-toast" data-student-toast role="status" aria-live="polite">
                    <span class="material-symbols-outlined">check_circle</span>
                    <div>Password updated. You can now sign in with your new password.</div>
                    <button type="button" class="student-toast-dismiss" aria-label="Dismiss confirmation"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
                </div>
            <?php endif; ?>

            <?php if (($_GET['registered'] ?? '') === '1'): ?>
                <div class="student-note student-note-success student-toast" data-student-toast role="status" aria-live="polite">
                    <span class="material-symbols-outlined">check_circle</span>
                    <div>Your Applicant account was created. Sign in with your student number and password.</div>
                    <button type="button" class="student-toast-dismiss" aria-label="Dismiss confirmation"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
                </div>
            <?php endif; ?>

            <?php if (($_GET['device_forgotten'] ?? '') === '1'): ?>
                <div class="student-note student-note-success student-toast" data-student-toast role="status" aria-live="polite">
                    <span class="material-symbols-outlined">shield</span>
                    <div>This device has been forgotten. You will need to sign in again.</div>
                    <button type="button" class="student-toast-dismiss" aria-label="Dismiss confirmation"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
                </div>
            <?php endif; ?>

            <div id="error-alert" class="student-note student-note-danger mb-4 <?= $error === '' ? 'hidden' : '' ?>">
                <span class="material-symbols-outlined">error</span>
                <div id="error-msg"><?= student_e($error !== '' ? $error : 'Invalid ID Number or password. Please try again.') ?></div>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="_csrf" value="<?= student_e(csrf_token()) ?>">
                <div class="student-field">
                    <label class="student-label" for="id-number">ID Number</label>
                    <input type="text" id="id-number" name="student_id" class="student-input" placeholder="Enter ID number" autocomplete="username" data-id-number-format value="<?= student_e($studentIdValue) ?>" required>
                </div>

                <div class="student-field">
                    <div class="flex items-center justify-between gap-3 mb-1">
                        <label class="student-label mb-0" for="password">Password</label>
                        <a href="patient-forgot-password.php" class="student-auth-link text-[11px] text-decoration-none">Forgot password?</a>
                    </div>
                    <div class="relative">
                        <input type="password" id="password" name="password" class="student-input pr-14" placeholder="Enter password" autocomplete="current-password" required>
                        <button type="button" class="student-toggle-pw" data-target="password">Show</button>
                    </div>
                </div>

                <label class="student-remember-row">
                    <input type="checkbox" name="remember_device" value="1">
                    <span>Keep me signed in for 30 days</span>
                </label>

                <button type="submit" class="student-button w-full">
                    Sign in
                    <span class="material-symbols-outlined">login</span>
                </button>
            </form>

            <hr class="student-auth-divider">
            <p class="text-center text-xs font-bold text-slate-500">
                New student? <a href="patient-register.php?start=1" class="student-auth-link text-decoration-none">Create an Applicant account.</a>
            </p>
        </div>
    </section>
</main>

<script>
    document.querySelectorAll('.student-toggle-pw').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.target);
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            button.textContent = isPassword ? 'Hide' : 'Show';
        });
    });

    localStorage.removeItem('student_logged_in');
</script>

<?php render_student_auth_footer(); ?>
