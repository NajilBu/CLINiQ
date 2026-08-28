<?php

require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/helpers/brand.php';
require_once __DIR__ . '/../../app/helpers/student_id.php';
require_once __DIR__ . '/../../app/services/SystemSettings.php';

const STUDENT_DEMO_PASSWORD = 'student123';

function student_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function student_e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function student_nav_items(): array
{
    return [
        'dashboard' => [
            'label' => 'Dashboard',
            'url' => 'patient-dashboard.php',
            'icon' => 'dashboard',
        ],
        'ape' => [
            'label' => 'APE Status',
            'url' => 'patient-ape-status.php',
            'icon' => 'fact_check',
        ],
        'appointment' => [
            'label' => 'Appointments',
            'url' => 'patient-appointment.php',
            'icon' => 'event_available',
        ],
        'passport' => [
            'label' => 'Health Passport',
            'url' => 'patient-passport.php',
            'icon' => 'id_card',
        ],
    ];
}

function student_profile_from_identity(array $identity): array
{
    $fullName = trim(implode(' ', array_filter([
        $identity['first_name'] ?? '',
        $identity['middle_name'] ?? '',
        $identity['last_name'] ?? '',
    ])));
    $idNumber = (string) ($identity['id_number'] ?? '');
    $emailId = strtolower(str_replace(['-', ' '], '', $idNumber));

    return [
        'patient_id' => (int) ($identity['person_id'] ?? 0),
        'person_id' => (int) ($identity['person_id'] ?? 0),
        'account_id' => (int) ($identity['account_id'] ?? 0),
        'account_status' => (string) ($identity['account_status'] ?? ''),
        'first_registration' => !empty($identity['first_registration']),
        'account_type' => (string) ($identity['account_type'] ?? 'patient'),
        'has_clinical_record' => !empty($identity['has_clinical_record']),
        'name' => $fullName !== '' ? $fullName : 'Patient',
        'first_name' => (string) ($identity['first_name'] ?? 'Patient'),
        'student_id' => $idNumber,
        'course' => (string) (
            $identity['program']
            ?? $identity['faculty_department']
            ?? $identity['personnel_department']
            ?? 'Not recorded'
        ),
        'email' => ($emailId !== '' ? $emailId : 'patient') . '@plpasig.edu.ph',
        'birthdate' => $identity['birthdate'] ?? null,
        'sex' => $identity['sex'] ?? null,
        'blood_type' => $identity['blood_type'] ?? null,
        'allergies' => $identity['allergies'] ?? null,
        'existing_conditions' => $identity['existing_conditions'] ?? null,
        'medications' => $identity['medications'] ?? null,
        'emergency_instructions' => $identity['emergency_instructions'] ?? null,
        'guardian_name' => $identity['guardian_or_contact_name'] ?? null,
        'guardian_contact' => $identity['guardian_or_contact_number'] ?? null,
        'emergency_token' => $identity['emergency_token'] ?? null,
        'updated_at' => $identity['patient_updated_at'] ?? $identity['updated_at'] ?? null,
        'height_cm' => $identity['height_cm'] ?? null,
        'weight_kg' => $identity['weight_kg'] ?? null,
        'bmi' => $identity['bmi'] ?? null,
        'temperature' => $identity['temperature'] ?? null,
        'blood_pressure' => $identity['blood_pressure'] ?? null,
        'pulse_rate' => $identity['pulse_rate'] ?? null,
    ];
}

function student_current_profile(): ?array
{
    student_start_session();

    $personId = (int) ($_SESSION['patient_person_id'] ?? $_SESSION['student_person_id'] ?? 0);
    if ($personId <= 0) {
        return null;
    }

    static $profile = null;
    if (is_array($profile) && (int) ($profile['person_id'] ?? 0) === $personId) {
        return $profile;
    }

    ensure_patients_vitals_schema();

    $stmt = auth_db()->prepare('
        SELECT
            p.id AS person_id,
            p.id_number,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.birthdate,
            p.sex,
            p.updated_at,
            a.id AS account_id,
            a.account_status,
            a.password_hash,
            pr.program_code AS program,
            CASE WHEN se.role_classification = "Faculty" THEN ed.department_code END AS faculty_department,
            CASE WHEN se.role_classification = "School Personnel" THEN ed.department_code END AS personnel_department,
            pt.blood_type,
            pt.allergies,
            pt.existing_conditions,
            pt.medications,
            pt.emergency_instructions,
            pt.guardian_or_contact_name,
            pt.guardian_or_contact_number,
            pt.emergency_token,
            pt.updated_at AS patient_updated_at,
            pt.height_cm,
            pt.weight_kg,
            pt.bmi,
            vs.temperature,
            vs.blood_pressure,
            vs.pulse_rate,
            CASE WHEN pt.person_id IS NULL THEN 0 ELSE 1 END AS has_clinical_record,
            CASE
                WHEN s.person_id IS NOT NULL THEN "student"
                WHEN se.role_classification = "Faculty" THEN "faculty"
                WHEN se.role_classification = "School Personnel" THEN "school_personnel"
                ELSE "patient"
            END AS account_type
        FROM people p
        JOIN accounts a ON a.person_id = p.id
        LEFT JOIN students s ON s.person_id = p.id
        LEFT JOIN programs pr ON pr.id = s.program_id
        LEFT JOIN school_employees se ON se.person_id = p.id
        LEFT JOIN departments ed ON ed.id = se.department_id
        LEFT JOIN patients pt ON pt.person_id = p.id
        LEFT JOIN (
            SELECT vs1.*
            FROM vital_signs vs1
            INNER JOIN (
                SELECT patient_id, MAX(measured_at) AS max_measured_at
                FROM vital_signs
                WHERE patient_id IS NOT NULL
                GROUP BY patient_id
            ) vs2 ON vs1.patient_id = vs2.patient_id AND vs1.measured_at = vs2.max_measured_at
        ) vs ON vs.patient_id = p.id
        WHERE p.id = ?
        LIMIT 1
    ');
    $stmt->execute([$personId]);
    $identity = $stmt->fetch();

    $registration = first_registration_context();
    $isFirstRegistration = (
        $identity
        && first_registration_pending('patient')
        && (int) ($registration['person_id'] ?? 0) === (int) $identity['person_id']
        && (int) ($registration['account_id'] ?? 0) === (int) $identity['account_id']
        && $identity['account_status'] === 'inactive'
        && !empty($identity['password_hash'])
    );
    if (!$identity || ($identity['account_status'] !== 'active' && !$isFirstRegistration)) {
        unset(
            $_SESSION['patient_person_id'],
            $_SESSION['student_person_id'],
            $_SESSION['patient_account_id'],
            $_SESSION['student_account_id'],
            $_SESSION['first_registration']
        );
        return null;
    }
    $identity['first_registration'] = $isFirstRegistration;

    $profile = student_profile_from_identity($identity);
    return $profile;
}

function student_demo_profile(): array
{
    return student_current_profile() ?? [
        'patient_id' => 0,
        'name' => 'Sofia L. Bautista',
        'first_name' => 'Sofia',
        'student_id' => '26-01024',
        'course' => 'BS Psychology 1-2',
        'email' => '2601024@plpasig.edu.ph',
    ];
}

function student_require_login(): array
{
    $profile = student_current_profile();
    if ($profile === null) {
        // Allow re-enrollment sessions (school-year reset) through even while account is inactive.
        if (re_enrollment_pending()) {
            return [
                'patient_id'       => 0,
                'person_id'        => (int) (re_enrollment_context()['person_id'] ?? 0),
                'name'             => '',
                'first_name'       => '',
                'student_id'       => '',
                'course'           => '',
                'email'            => '',
                'account_status'   => 'inactive',
                'first_registration' => false,
                're_enrollment'    => true,
                'account_type'     => (string) (re_enrollment_context()['type'] ?? 'patient'),
            ];
        }
        header('Location: patient-login.php');
        exit;
    }

    if (!empty($profile['first_registration'])) {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($script, ['patient-dashboard.php', 'patient-login.php'], true)) {
            header('Location: patient-dashboard.php');
            exit;
        }
    }

    if (re_enrollment_pending()) {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($script, ['patient-dashboard.php', 'patient-login.php'], true)) {
            header('Location: patient-dashboard.php');
            exit;
        }
    }

    return $profile;
}

function student_logout(): void
{
    student_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function student_find_patient_by_number(string $studentNumber): ?array
{
    $stmt = auth_db()->prepare('
        SELECT
            a.id AS account_id,
            a.password_hash,
            a.account_status,
            a.activated_at,
            p.id AS person_id,
            p.id_number,
            p.first_name,
            p.middle_name,
            p.last_name,
            CASE
                WHEN s.person_id IS NOT NULL THEN "student"
                WHEN se.role_classification = "Faculty" THEN "faculty"
                WHEN se.role_classification = "School Personnel" THEN "school_personnel"
                ELSE "patient"
            END AS account_type
        FROM accounts a
        JOIN people p ON p.id = a.person_id
        LEFT JOIN students s ON s.person_id = p.id
        LEFT JOIN school_employees se ON se.person_id = p.id
        WHERE p.id_number = ?
          AND (
            EXISTS (SELECT 1 FROM students s WHERE s.person_id = p.id)
            OR EXISTS (SELECT 1 FROM school_employees se WHERE se.person_id = p.id)
            OR EXISTS (SELECT 1 FROM patients pt WHERE pt.person_id = p.id)
          )
        LIMIT 1
    ');
    $stmt->execute([$studentNumber]);
    $account = $stmt->fetch();
    if (!$account || $account['account_status'] === 'suspended') {
        return null;
    }

    $account['legacy_patient_id'] = (int) ($account['person_id'] ?? 0);
    return $account;
}

function student_password_is_valid(array $patient, string $password): bool
{
    $passwordHash = (string) ($patient['password_hash'] ?? '');
    return $passwordHash !== '' && password_verify($password, $passwordHash);
}

function student_record_successful_login(int $accountId): void
{
    $stmt = auth_db()->prepare('UPDATE accounts SET last_login_at = NOW() WHERE id = ?');
    $stmt->execute([$accountId]);
}

function render_student_header(string $title, string $active = ''): void
{
    $profile = student_require_login();
    $clinicProfile = clinic_profile_settings();
    $navItems = student_nav_items();
    if (!empty($profile['first_registration'])) {
        $navItems = array_intersect_key($navItems, ['dashboard' => true]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_patient_password') {
        student_start_session();
        try {
            $accountId = (int) ($profile['account_id'] ?? 0);
            $currPw = (string) ($_POST['current_password'] ?? '');
            $newPw  = (string) ($_POST['new_password'] ?? '');
            $confPw = (string) ($_POST['confirm_password'] ?? '');
            change_patient_password($accountId, $currPw, $newPw, $confPw);
            $_SESSION['student_flash_success'] = 'Your password has been changed successfully.';
        } catch (Throwable $e) {
            $_SESSION['student_flash_error'] = $e->getMessage();
        }
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'patient-dashboard.php'));
        exit;
    }

    student_start_session();
    $flashSuccess = $_SESSION['student_flash_success'] ?? null;
    $flashError   = $_SESSION['student_flash_error'] ?? null;
    unset($_SESSION['student_flash_success'], $_SESSION['student_flash_error']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= student_e($title) ?> | <?= student_e($clinicProfile['system_name']) ?> Patient Portal</title>
        <link href="../public/assets/vendor/fonts/inter-manrope.css?v=offline-1" rel="stylesheet">
        <link href="../public/assets/vendor/fonts/material-symbols.css?v=offline-1" rel="stylesheet">
        <script src="../public/assets/vendor/tailwind/tailwind-cdn.js?v=offline-1"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            primary: '#3F7D52',
                            'primary-fixed': '#e8f6ec',
                            'primary-container': '#23422C',
                            'on-primary': '#ffffff',
                            surface: '#f4fbf6',
                            'on-surface': '#17261d',
                            'surface-container-low': '#edf8f0',
                            'outline-variant': '#c7dccd',
                            brand: '#3F7D52',
                            'brand-dark': '#17261d'
                        },
                        fontFamily: {
                            headline: ['Manrope', 'sans-serif'],
                            body: ['Inter', 'sans-serif']
                        }
                    }
                }
            };
        </script>
        <link href="../public/assets/css/app.css?v=file-preview-2" rel="stylesheet">
        <link href="assets/css/patient.css?v=ape-batch-upload-1" rel="stylesheet">
    </head>
    <body class="student-body">
        <div class="student-shell">
            <header class="student-topbar">
                <a href="patient-dashboard.php" class="student-brand text-decoration-none">
                    <span class="student-brand-mark">
                        <img src="../public/assets/img/clinic-logo.png" alt="<?= student_e($clinicProfile['department']) ?> logo">
                    </span>
                    <span class="student-brand-copy">
                        <span class="student-brand-title"><?= student_e($clinicProfile['system_name']) ?></span>
                        <span class="student-brand-subtitle">Patient Health Portal</span>
                    </span>
                </a>

                <nav class="student-nav" aria-label="Patient navigation">
                    <?php foreach ($navItems as $key => $item): ?>
                        <a href="<?= student_e($item['url']) ?>" class="student-nav-link <?= $active === $key ? 'active' : '' ?> text-decoration-none">
                            <span class="material-symbols-outlined"><?= student_e($item['icon']) ?></span>
                            <?= student_e($item['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="student-profile-chip">
                    <div class="student-profile-text">
                        <strong><?= student_e($profile['name']) ?></strong>
                        <span><?= student_e($profile['student_id']) ?></span>
                    </div>
                    <div class="student-profile-actions">
                        <?php if (empty($profile['first_registration'])): ?>
                            <button type="button" onclick="document.getElementById('change-password-modal').classList.remove('hidden')" class="student-logout text-decoration-none" title="Change Password">
                                <span class="material-symbols-outlined">key</span>
                            </button>
                        <?php endif; ?>
                        <a href="patient-login.php?logout=1" onclick="localStorage.clear();" class="student-logout text-decoration-none" title="Logout">
                            <span class="material-symbols-outlined">logout</span>
                        </a>
                    </div>
                </div>
            </header>

            <main class="student-main">
                <?php if ($flashSuccess): ?>
                    <div class="student-note student-note-success mb-4 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined">check_circle</span>
                            <div><?= student_e($flashSuccess) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="student-note student-note-danger mb-4 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined">error</span>
                            <div><?= student_e($flashError) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (empty($profile['has_clinical_record'])): ?>
                    <div class="student-note student-note-warning mb-4">
                        <span class="material-symbols-outlined">info</span>
                        <div>
                            <strong>No clinical patient record yet.</strong>
                            Ask the clinic to create your patient record before using appointments, APE, or health-passport features.
                        </div>
                    </div>
                <?php endif; ?>
    <?php
}

function render_student_footer(): void
{
    ?>
            </main>
        </div>

        <!-- Change Password Modal -->
        <div id="change-password-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs hidden">
            <div class="student-card w-full max-w-md p-6 shadow-xl relative animate-in fade-in zoom-in duration-150">
                <div class="flex items-center justify-between pb-4 mb-4 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#3F7D52]">key</span>
                        <h3 class="font-headline text-lg font-extrabold text-[#17261d]">Change Password</h3>
                    </div>
                    <button type="button" onclick="document.getElementById('change-password-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <form method="post" autocomplete="off" class="space-y-4">
                    <input type="hidden" name="action" value="change_patient_password">

                    <div class="student-field">
                        <label class="student-label" for="modal_current_password">Current Password</label>
                        <div class="relative">
                            <input id="modal_current_password" name="current_password" class="student-input pr-10" type="password" required placeholder="Enter current password">
                            <button type="button" onclick="toggleModalPwVisibility('modal_current_password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold hover:text-slate-600">Show</button>
                        </div>
                    </div>

                    <div class="student-field">
                        <label class="student-label" for="modal_new_password">New Password</label>
                        <div class="relative">
                            <input id="modal_new_password" name="new_password" class="student-input pr-10" type="password" minlength="8" required placeholder="Enter new password (min. 8 chars)">
                            <button type="button" onclick="toggleModalPwVisibility('modal_new_password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold hover:text-slate-600">Show</button>
                        </div>
                    </div>

                    <div class="student-field">
                        <label class="student-label" for="modal_confirm_password">Confirm New Password</label>
                        <div class="relative">
                            <input id="modal_confirm_password" name="confirm_password" class="student-input pr-10" type="password" minlength="8" required placeholder="Confirm new password">
                            <button type="button" onclick="toggleModalPwVisibility('modal_confirm_password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold hover:text-slate-600">Show</button>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                        <button type="button" onclick="document.getElementById('change-password-modal').classList.add('hidden')" class="student-button bg-slate-100 text-slate-700 hover:bg-slate-200">
                            Cancel
                        </button>
                        <button type="submit" class="student-button">
                            Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function toggleModalPwVisibility(inputId, btn) {
                const inp = document.getElementById(inputId);
                if (inp) {
                    const isPw = inp.type === 'password';
                    inp.type = isPw ? 'text' : 'password';
                    btn.textContent = isPw ? 'Hide' : 'Show';
                }
            }
        </script>
        <script src="../public/assets/js/file-preview.js?v=ape-popup-2"></script>
    </body>
    </html>
    <?php
}

function render_student_auth_header(string $title): void
{
    $clinicProfile = clinic_profile_settings();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= student_e($title) ?> | <?= student_e($clinicProfile['system_name']) ?> Patient Portal</title>
        <link href="../public/assets/vendor/fonts/inter-manrope.css?v=offline-1" rel="stylesheet">
        <link href="../public/assets/vendor/fonts/material-symbols.css?v=offline-1" rel="stylesheet">
        <script src="../public/assets/vendor/tailwind/tailwind-cdn.js?v=offline-1"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            primary: '#3F7D52',
                            'primary-fixed': '#e8f6ec',
                            'primary-container': '#23422C',
                            'on-primary': '#ffffff',
                            surface: '#f4fbf6',
                            'on-surface': '#17261d',
                            'surface-container-low': '#edf8f0',
                            'outline-variant': '#c7dccd'
                        },
                        fontFamily: {
                            headline: ['Manrope', 'sans-serif'],
                            body: ['Inter', 'sans-serif']
                        }
                    }
                }
            };
        </script>
        <link href="../public/assets/css/app.css?v=file-preview-2" rel="stylesheet">
        <link href="assets/css/patient.css?v=ape-batch-upload-1" rel="stylesheet">
    </head>
    <body class="student-body student-auth-page">
    <?php
}

function render_student_auth_footer(): void
{
    ?>
    <script>
        function formatStudentId(value) {
            const digits = String(value || '').replace(/\D/g, '').slice(0, 7);
            if (digits.length <= 2) {
                return digits;
            }
            return `${digits.slice(0, 2)}-${digits.slice(2)}`;
        }

        function initStudentIdFormatting(root = document) {
            const scope = root.querySelectorAll ? root : document;
            scope.querySelectorAll('[data-legacy-student-id-format]').forEach((input) => {
                if (!(input instanceof HTMLInputElement) || input.dataset.studentIdFormatterReady === '1') {
                    return;
                }

                input.dataset.studentIdFormatterReady = '1';
                input.inputMode = 'numeric';
                input.maxLength = 8;
                input.pattern = '\\d{2}-\\d{5}';
                input.title = 'Use the format Enter ID number.';
                input.placeholder = input.placeholder || 'Enter ID number';
                input.addEventListener('input', () => {
                    input.value = formatStudentId(input.value);
                });
            });
        }

        initStudentIdFormatting();
    </script>
    </body>
    </html>
    <?php
}
