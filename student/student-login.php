<?php
require_once __DIR__ . '/includes/student-layout.php';

if (isset($_GET['logout'])) {
    student_logout();
}

$error = '';
$studentIdValue = '';
$clinicProfile = clinic_profile_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentIdValue = trim($_POST['student_id'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (!is_valid_student_id($studentIdValue)) {
        $error = 'Use the Student ID format ' . STUDENT_ID_FORMAT_LABEL . '.';
    } else {
        $patient = student_find_patient_by_number($studentIdValue);
        if ($patient === null) {
            $error = 'Student ID not found in the clinic records. Try 26-01024 for the seeded demo.';
        } elseif (!student_password_is_valid($patient, $password)) {
            $error = 'Invalid Student ID or password. Please try again.';
        } else {
            student_start_session();
            $_SESSION['student_patient_id'] = (int) $patient['id'];
            header('Location: student-dashboard.php');
            exit;
        }
    }
}

render_student_auth_header('Student Login');
?>

<?php render_cliniq_entry_header([
    'homeUrl' => '../public/index.php',
    'logoUrl' => '../public/assets/img/clinic-logo.png',
]); ?>

<main class="student-auth-wrap">
    <section class="student-auth-shell">
        <aside class="student-auth-side">
            <div>
                <a href="../public/index.php" class="student-brand-mark text-decoration-none" aria-label="Go to CLINiQ access portal">
                    <img src="../public/assets/img/clinic-logo.png" alt="<?= student_e($clinicProfile['department']) ?> logo">
                </a>
                <p class="student-auth-brand-line"><?= student_e($clinicProfile['system_name']) ?></p>
                <h1 class="student-auth-side-title">Student<br>Health Portal</h1>
                <p class="student-auth-side-copy">Track your APE status, upload documents, and book clinic appointments in one place.</p>
                <svg class="student-auth-pulse" viewBox="0 0 320 40" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 20 H100 L112 20 L120 4 L132 36 L142 20 L154 20 L162 12 L170 28 L178 20 L320 20"/>
                </svg>
            </div>
            <p class="student-auth-side-footnote">Access is limited to enrolled PLP students</p>
        </aside>

        <div class="student-auth-form-side">
            <p class="student-eyebrow">Welcome Back</p>
            <h2 class="student-card-title text-xl">Sign in to your clinic record</h2>
            <p class="student-card-copy mb-5">Access your APE status, document uploads, and appointment requests.</p>

            <div id="error-alert" class="student-note student-note-danger mb-4 <?= $error === '' ? 'hidden' : '' ?>">
                <span class="material-symbols-outlined">error</span>
                <div id="error-msg"><?= student_e($error !== '' ? $error : 'Invalid Student ID or password. Please try again.') ?></div>
            </div>

            <form method="POST" action="">
                <div class="student-field">
                    <label class="student-label" for="student-id">Student ID</label>
                    <input type="text" id="student-id" name="student_id" class="student-input" placeholder="00-00000" autocomplete="username" data-student-id-format value="<?= student_e($studentIdValue) ?>" required>
                </div>

                <div class="student-field">
                    <div class="flex items-center justify-between gap-3 mb-1">
                        <label class="student-label mb-0" for="password">Password</label>
                        <a href="student-forgot-password.php" class="student-auth-link text-[11px] text-decoration-none">Forgot password?</a>
                    </div>
                    <div class="relative">
                        <input type="password" id="password" name="password" class="student-input pr-14" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="student-toggle-pw" data-target="password">Show</button>
                    </div>
                </div>

                <button type="submit" class="student-button w-full">
                    Sign in
                    <span class="material-symbols-outlined">login</span>
                </button>
            </form>

            <hr class="student-auth-divider">
            <p class="text-center text-xs font-bold text-slate-500">
                No account yet?
                <a href="student-register.php" class="student-auth-link text-decoration-none">Register here.</a>
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
