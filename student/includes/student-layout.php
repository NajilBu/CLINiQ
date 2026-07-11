<?php

require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/helpers/brand.php';

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
            'url' => 'student-dashboard.php',
            'icon' => 'dashboard',
        ],
        'ape' => [
            'label' => 'APE Status',
            'url' => 'student-ape-status.php',
            'icon' => 'fact_check',
        ],
        'appointment' => [
            'label' => 'Appointments',
            'url' => 'student-appointment.php',
            'icon' => 'event_available',
        ],
        'passport' => [
            'label' => 'Health Passport',
            'url' => 'student-passport.php',
            'icon' => 'id_card',
        ],
    ];
}

function student_profile_from_patient(array $patient): array
{
    $fullName = trim(implode(' ', array_filter([
        $patient['first_name'] ?? '',
        $patient['middle_name'] ?? '',
        $patient['last_name'] ?? '',
    ])));
    $studentId = (string) ($patient['student_number'] ?? '');
    $emailId = strtolower(str_replace('-', '', $studentId));

    return [
        'patient_id' => (int) ($patient['id'] ?? 0),
        'name' => $fullName !== '' ? $fullName : 'Student',
        'first_name' => (string) ($patient['first_name'] ?? 'Student'),
        'student_id' => $studentId,
        'course' => (string) ($patient['course_section'] ?? 'Not recorded'),
        'email' => ($emailId !== '' ? $emailId : 'student') . '@plpasig.edu.ph',
        'birthdate' => $patient['birthdate'] ?? null,
        'sex' => $patient['sex'] ?? null,
        'blood_type' => $patient['blood_type'] ?? null,
        'allergies' => $patient['allergies'] ?? null,
        'existing_conditions' => $patient['existing_conditions'] ?? null,
        'emergency_instructions' => $patient['emergency_instructions'] ?? null,
        'guardian_name' => $patient['guardian_name'] ?? null,
        'guardian_contact' => $patient['guardian_contact'] ?? null,
        'emergency_token' => $patient['emergency_token'] ?? null,
        'updated_at' => $patient['updated_at'] ?? null,
    ];
}

function student_current_profile(): ?array
{
    student_start_session();

    $patientId = (int) ($_SESSION['student_patient_id'] ?? 0);
    if ($patientId <= 0) {
        return null;
    }

    static $profile = null;
    if (is_array($profile) && (int) ($profile['patient_id'] ?? 0) === $patientId) {
        return $profile;
    }

    $stmt = db()->prepare('SELECT * FROM patients WHERE id = ? LIMIT 1');
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch();
    if (!$patient) {
        unset($_SESSION['student_patient_id']);
        return null;
    }

    $profile = student_profile_from_patient($patient);
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
        header('Location: student-login.php');
        exit;
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
    $stmt = db()->prepare('SELECT * FROM patients WHERE student_number = ? LIMIT 1');
    $stmt->execute([$studentNumber]);
    $patient = $stmt->fetch();

    return $patient ?: null;
}

function render_student_header(string $title, string $active = ''): void
{
    $profile = student_require_login();
    $navItems = student_nav_items();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= student_e($title) ?> | CLINiQ Student Portal</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@700;800&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
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
        <link href="../public/assets/css/app.css?v=cancel-icon-1" rel="stylesheet">
        <link href="assets/css/student.css?v=passport-dashboard-1" rel="stylesheet">
    </head>
    <body class="student-body">
        <div class="student-shell">
            <header class="student-topbar">
                <a href="student-dashboard.php" class="student-brand text-decoration-none">
                    <span class="student-brand-mark">
                        <img src="../public/assets/img/clinic-logo.png" alt="PLP Health Services Department logo">
                    </span>
                    <span class="student-brand-copy">
                        <span class="student-brand-title">CLINiQ</span>
                        <span class="student-brand-subtitle">Student Health Portal</span>
                    </span>
                </a>

                <nav class="student-nav" aria-label="Student navigation">
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
                    <a href="student-login.php?logout=1" onclick="localStorage.clear();" class="student-logout text-decoration-none" title="Logout">
                        <span class="material-symbols-outlined">logout</span>
                    </a>
                </div>
            </header>

            <main class="student-main">
    <?php
}

function render_student_footer(): void
{
    ?>
            </main>
        </div>
    </body>
    </html>
    <?php
}

function render_student_auth_header(string $title): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= student_e($title) ?> | CLINiQ Student Portal</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@700;800&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
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
        <link href="../public/assets/css/app.css?v=cancel-icon-1" rel="stylesheet">
        <link href="assets/css/student.css?v=passport-dashboard-1" rel="stylesheet">
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
            scope.querySelectorAll('[data-student-id-format], #student-id').forEach((input) => {
                if (!(input instanceof HTMLInputElement) || input.dataset.studentIdFormatterReady === '1') {
                    return;
                }

                input.dataset.studentIdFormatterReady = '1';
                input.inputMode = 'numeric';
                input.maxLength = 8;
                input.pattern = '\\d{2}-\\d{5}';
                input.title = 'Use the format 00-00000.';
                input.placeholder = input.placeholder || '00-00000';
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
