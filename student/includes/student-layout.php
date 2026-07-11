<?php

require_once __DIR__ . '/../../app/helpers/brand.php';

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

function student_demo_profile(): array
{
    return [
        'name' => 'Juan dela Cruz',
        'student_id' => '23-00456',
        'course' => 'BSIT - 3rd Year',
        'email' => 'juandelacruz@plpasig.edu.ph',
    ];
}

function render_student_header(string $title, string $active = ''): void
{
    $profile = student_demo_profile();
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
        <link href="assets/css/student.css?v=control-font-1" rel="stylesheet">
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
                    <a href="student-login.php" onclick="localStorage.clear();" class="student-logout text-decoration-none" title="Logout">
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
        <script>
            if (!document.body.classList.contains('student-auth-page') && localStorage.getItem('student_logged_in') !== 'true') {
                window.location.href = 'student-login.php';
            }
        </script>
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
        <link href="assets/css/student.css?v=control-font-1" rel="stylesheet">
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
