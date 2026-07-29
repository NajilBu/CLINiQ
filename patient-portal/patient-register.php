<?php
require_once __DIR__ . '/includes/patient-layout.php';

$error = '';
$success = '';
$idNumber = trim((string) ($_POST['id_number'] ?? ''));
$birthdate = trim((string) ($_POST['birthdate'] ?? ''));
$accountType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $temporaryPassword = (string) ($_POST['temporary_password'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!is_valid_id_number($idNumber)) {
        $error = id_number_validation_message();
    } elseif ($birthdate === '') {
        $error = 'Enter the birthdate recorded in the official roster.';
    } elseif ($temporaryPassword === '') {
        $error = 'Enter the temporary password provided by the clinic.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/\d/', $password)) {
        $error = 'Password must contain at least one number.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $authDb = auth_db();
        try {
            $authDb->beginTransaction();
            $stmt = $authDb->prepare('
                SELECT
                    p.id AS person_id,
                    p.birthdate,
                    a.id AS account_id,
                    a.account_status,
                    a.temporary_password_hash,
                    CASE
                        WHEN cs.person_id IS NOT NULL THEN "clinic_staff"
                        WHEN s.person_id IS NOT NULL THEN "student"
                        WHEN f.person_id IS NOT NULL THEN "faculty"
                        WHEN pt.person_id IS NOT NULL THEN "patient"
                        ELSE "unknown"
                    END AS account_type
                FROM people p
                JOIN accounts a ON a.person_id = p.id
                LEFT JOIN clinic_staff cs ON cs.person_id = p.id
                LEFT JOIN students s ON s.person_id = p.id
                LEFT JOIN faculty f ON f.person_id = p.id
                LEFT JOIN patients pt ON pt.person_id = p.id
                WHERE p.id_number = ?
                LIMIT 1
                FOR UPDATE
            ');
            $stmt->execute([$idNumber]);
            $account = $stmt->fetch();

            if (!$account) {
                throw new RuntimeException('ID number was not found in the official account list.');
            }
            if ($account['account_status'] !== 'inactive') {
                throw new RuntimeException(
                    $account['account_status'] === 'active'
                        ? 'This account is already registered.'
                        : 'This account is currently suspended.'
                );
            }
            if (empty($account['birthdate']) || $account['birthdate'] !== $birthdate) {
                throw new RuntimeException('The ID number and birthdate do not match the official record.');
            }
            if (
                empty($account['temporary_password_hash'])
                || !password_verify($temporaryPassword, $account['temporary_password_hash'])
            ) {
                throw new RuntimeException('The temporary password is incorrect. Contact the clinic if you need assistance.');
            }

            $update = $authDb->prepare('
                UPDATE accounts
                SET
                    password_hash = ?,
                    temporary_password_hash = NULL,
                    account_status = "active",
                    activated_at = NOW()
                WHERE id = ?
            ');
            $update->execute([
                password_hash($password, PASSWORD_DEFAULT),
                (int) $account['account_id'],
            ]);
            $authDb->commit();

            $accountType = (string) $account['account_type'];
            $success = match ($accountType) {
                'clinic_staff' => 'Account activated. You can now sign in through the Clinic Staff Portal using your ID number.',
                'student' => 'Account activated. You can now sign in through the Patient Portal.',
                'faculty' => 'Faculty account activated. You can now sign in through the Patient Portal.',
                default => 'Patient account activated successfully.',
            };
        } catch (Throwable $e) {
            if ($authDb->inTransaction()) {
                $authDb->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

render_student_auth_header('Account Registration');
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
                    <img src="../public/assets/img/clinic-logo.png" alt="PLP Health Services Department logo">
                </a>
                <p class="student-auth-brand-line">CLINiQ account activation</p>
                <h1 class="student-auth-side-title">Register your account</h1>
                <p class="student-auth-side-copy">Registration is available only to people already included in the official student, faculty, clinic staff, or patient list.</p>
            </div>
            <p class="student-auth-side-footnote">Use your ID number, birthdate, and clinic-provided temporary password</p>
        </aside>

        <div class="student-auth-form-side">
            <p class="student-eyebrow">Account registration</p>
            <h2 class="student-card-title text-xl">Activate your CLINiQ account</h2>
            <p class="student-card-copy mb-5">Verify the temporary credentials provided by the clinic, then create a secure password.</p>

            <?php if ($error !== ''): ?>
                <div class="student-note student-note-danger mb-4">
                    <span class="material-symbols-outlined">error</span>
                    <div><?= student_e($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="student-note student-note-success mb-4">
                    <span class="material-symbols-outlined">check_circle</span>
                    <div><?= student_e($success) ?></div>
                </div>
                <?php if ($accountType === 'clinic_staff'): ?>
                    <a href="../public/login.php" class="student-button w-full text-decoration-none justify-center">Go to Staff Login</a>
                <?php else: ?>
                    <a href="patient-login.php" class="student-button w-full text-decoration-none justify-center">Go to Patient Login</a>
                <?php endif; ?>
            <?php else: ?>
                <form method="post">
                    <div class="student-field">
                        <label class="student-label" for="id-number">ID Number</label>
                        <input id="id-number" name="id_number" class="student-input" type="text" value="<?= student_e($idNumber) ?>" placeholder="Enter ID number" autocomplete="username" required>
                    </div>
                    <div class="student-field">
                        <label class="student-label" for="birthdate">Birthdate</label>
                        <input id="birthdate" name="birthdate" class="student-input" type="date" value="<?= student_e($birthdate) ?>" required>
                    </div>
                    <div class="student-field">
                        <label class="student-label" for="temporary-password">Temporary Password</label>
                        <input id="temporary-password" name="temporary_password" class="student-input" type="password" inputmode="numeric" autocomplete="one-time-code" required>
                    </div>
                    <div class="student-field">
                        <label class="student-label" for="password">Create Password</label>
                        <input id="password" name="password" class="student-input" type="password" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div class="student-field">
                        <label class="student-label" for="confirm-password">Confirm Password</label>
                        <input id="confirm-password" name="confirm_password" class="student-input" type="password" minlength="8" autocomplete="new-password" required>
                    </div>
                    <p class="student-card-copy text-[11px] mb-3">Use at least 8 characters with at least one number.</p>
                    <button type="submit" class="student-button w-full">
                        Activate Account
                        <span class="material-symbols-outlined">person_add</span>
                    </button>
                </form>
            <?php endif; ?>

            <p class="text-center text-xs font-bold text-slate-500 mt-4">
                Already active?
                <a href="patient-login.php" class="student-auth-link text-decoration-none">Patient login</a>
                or
                <a href="../public/login.php" class="student-auth-link text-decoration-none">staff login</a>
            </p>
        </div>
    </section>
</main>

<?php render_student_auth_footer(); ?>
